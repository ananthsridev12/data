<?php

require_once __DIR__ . '/ScopeFilter.php';

/**
 * Every method takes a required Scope: company scope always applies, and
 * role-based owner scope means "your Reports" -- lead-centric methods
 * (summary/coverageByVertical/repliesByOutcome) scope by leads.owner_id,
 * campaign-centric methods (sequences, and everything reading
 * saleshandy_send_events, which has no reliable per-row lead_id -- see
 * sql/024_send_events.sql) scope by campaigns.saleshandy_account_owner_id,
 * same split as AnalyticsRepository::campaignFunnel().
 *
 * Queries backing the Reports module (public/reports.php) -- replicates a
 * spreadsheet the user previously rebuilt by hand from Saleshandy exports,
 * now sourced live from this app's own data.
 *
 * Two different granularities feed this class, matching what's actually
 * available:
 *  - summary()/coverageByVertical()/repliesByOutcome() read `leads` +
 *    `lead_campaign_assignments` -- one row per lead per campaign (it only
 *    ever tracks one lead's *first* send date and *furthest* step reached,
 *    not individual step-send events), so wherever those methods report a
 *    single "contacted"/"reached" figure it's really a distinct-lead count.
 *  - dailyActivity()/weeklyActivity()/stepsRaw()/sequences() read the newer
 *    `saleshandy_send_events` table (sql/024_send_events.sql) for their
 *    "Emails sent" figure, which *does* have per-day, per-step granularity
 *    -- fed from SaleshandyClient::fetchSequenceActivity()'s raw
 *    (pre-aggregation) rows via persistSendEvents(), so a sequence with
 *    follow-up steps legitimately shows more emails sent than contacts
 *    reached. This table only starts filling in from the first post-deploy
 *    sync/backfill onward, so sequences() falls back to the old
 *    assignment-based (one-per-lead) count for any campaign with no rows
 *    there yet, rather than showing zero -- dateBounds() returns nulls
 *    project-wide until the first backfill runs.
 *
 * summary()/accountsSummary()/coverageByVertical()/repliesByOutcome()
 * check ANY of a lead's assignments, not just their current/latest one
 * (see stageExpr()) -- these are lifetime/cumulative funnels ("has this
 * lead ever been contacted/delivered/opened/replied"), so a lead
 * reassigned to a new campaign (WaveAssigner's cooldown-based
 * reassignment) whose fresh assignment hasn't sent yet must never make
 * an earlier real contact look like it never happened. Fixed alongside
 * the same undercount in AnalyticsRepository -- this class keeps its
 * own separate query logic, so it needed the identical fix applied
 * separately. sequences() is unaffected: it already reads the raw,
 * unambiguous per-campaign row directly (a lead has at most one row per
 * campaign), so "which assignment" was never a question there.
 */
class ReportsRepository
{
    /**
     * SQL fragment (no leading AND), true when THIS row counts as "in
     * period" for the given filters -- for sequences(), the only
     * remaining caller: it already joins the raw, unambiguous per-
     * campaign lead_campaign_assignments row directly (a lead has at
     * most one row per campaign, so there's no "which assignment"
     * ambiguity to resolve there the way stageExpr() below has to for
     * the account-wide/lead-centric methods). $suffix must be unique per
     * call within a single query -- PDO's real (non-emulated) prepared
     * statements reject the same named placeholder appearing twice in
     * one statement.
     */
    private static function periodExpr(array $filters, array &$params, string $suffix = ''): string
    {
        $clauses = ['a.email_sent = 1'];
        foreach (self::periodClauses($filters, $params, 'a', $suffix) as $c) {
            $clauses[] = $c;
        }
        return implode(' AND ', $clauses);
    }

    /**
     * date_from/date_to/campaign_id clauses (no leading AND) scoping
     * WHICH of a lead's assignments count as "in period" for a report --
     * shared building block for stageExpr() (an EXISTS check) and
     * repliesByOutcome() (a correlated subquery that also needs to read
     * a column off the matching row, not just check existence). $suffix
     * must be unique per call within a single query -- PDO's real
     * (non-emulated) prepared statements reject the same named
     * placeholder appearing twice in one statement.
     */
    private static function periodClauses(array $filters, array &$params, string $alias, string $suffix): array
    {
        $clauses = [];
        if (!empty($filters['date_from'])) {
            $clauses[] = "{$alias}.email_sent_at >= :date_from{$suffix}";
            $params["date_from{$suffix}"] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $clauses[] = "{$alias}.email_sent_at <= :date_to{$suffix}";
            $params["date_to{$suffix}"] = $filters['date_to'];
        }
        if (!empty($filters['campaign_id'])) {
            $clauses[] = "{$alias}.campaign_id = :campaign_id{$suffix}";
            $params["campaign_id{$suffix}"] = (int) $filters['campaign_id'];
        }
        return $clauses;
    }

    /**
     * EXISTS check: true when ANY of this lead's assignments (not just
     * their current/latest one) satisfies a report stage's condition,
     * scoped by the same date_from/date_to/campaign_id filters as
     * everywhere else in this class. $extraCondition (referencing the
     * same alias) is ANDed in so the whole "is this a hit" check runs
     * against the SAME matching row, not mixed across different
     * assignments (e.g. "delivered" needs the row that was actually
     * sent, not any row with a non-bounce status regardless of whether
     * it was ever sent).
     *
     * Checking ANY assignment instead of only the latest matters once a
     * lead can be reassigned to a new campaign (WaveAssigner's cooldown-
     * based reassignment): this funnel is a lifetime/cumulative report
     * ("has this lead ever been contacted/delivered/opened/replied"),
     * not a snapshot of current standing, so a fresh not-yet-sent
     * reassignment must never make an earlier real contact look like it
     * never happened -- the same undercount found and fixed in
     * AnalyticsRepository, duplicated here since this class keeps its
     * own separate query logic. This also fixes a second, related bug:
     * filtering by campaign_id used to mean "is this campaign the
     * lead's LATEST assignment AND does it match" -- so a lead contacted
     * via campaign X who'd since moved on to campaign Y would show as 0
     * for campaign X's own report, even though X's send genuinely
     * happened. Now it correctly means "does this lead's assignment TO
     * that specific campaign match", regardless of what their latest
     * assignment happens to be.
     */
    private static function stageExpr(array $filters, array &$params, string $alias, string $suffix, string $extraCondition = ''): string
    {
        $clauses = ["{$alias}.lead_id = l.id", "{$alias}.email_sent = 1"];
        if ($extraCondition !== '') {
            // Parenthesized -- $extraCondition can itself contain an OR
            // (e.g. "delivery_status IS NULL OR delivery_status NOT IN
            // (...)" for the "delivered" stage), and AND binds tighter
            // than OR in SQL: appended unparenthesized, that OR's right
            // side would apply to the WHOLE WHERE clause instead of just
            // this condition, dropping the "{$alias}.lead_id = l.id"
            // correlation entirely and matching almost every lead.
            $clauses[] = "({$extraCondition})";
        }
        $clauses = array_merge($clauses, self::periodClauses($filters, $params, $alias, $suffix));
        return "EXISTS (SELECT 1 FROM lead_campaign_assignments {$alias} WHERE " . implode(' AND ', $clauses) . ')';
    }

    /**
     * Headline metrics + the 5-stage funnel (Contacts in database ->
     * Contacted at least once -> Delivered (not bounced) -> Opened ->
     * Replied). "Contacts in database" is every non-deleted lead,
     * unscoped by period; every other stage is period-scoped.
     *
     * @param array{date_from?:?string,date_to?:?string,campaign_id?:?int} $filters
     * @return array{
     *   headline: array{contacts_in_database:int,contacts_reached:int,unique_opens:int,replies:int,active_sending_days:int},
     *   funnel: array<int,array{stage:string,count:int,pct_of_database:float,pct_of_previous:float}>
     * }
     */
    public static function summary(PDO $db, Scope $scope, array $filters): array
    {
        $params = [];

        $inDbClauses = ['l.deleted_at IS NULL'];
        $inDbParams = [];
        ScopeFilter::apply($inDbClauses, $inDbParams, $scope, 'l', 'scope_company_id_db');
        ScopeFilter::applyOwnerScope($inDbClauses, $inDbParams, $scope, $db, 'l', 'owner_id', 'scope_owner_db');
        $inDbWhere = implode(' AND ', $inDbClauses);
        $inDbStmt = $db->prepare("SELECT COUNT(*) FROM leads l WHERE {$inDbWhere}");
        $inDbStmt->execute($inDbParams);
        $inDatabase = (int) $inDbStmt->fetchColumn();

        $scopeClauses = ['l.deleted_at IS NULL'];
        ScopeFilter::apply($scopeClauses, $params, $scope);
        ScopeFilter::applyOwnerScope($scopeClauses, $params, $scope, $db);
        $scopeWhere = implode(' AND ', $scopeClauses);

        $contactedExpr = self::stageExpr($filters, $params, 'ac', '_contacted');
        $deliveredExpr = self::stageExpr($filters, $params, 'ad', '_delivered', "ad.delivery_status IS NULL OR ad.delivery_status NOT IN ('" . implode("','", DELIVERY_STATUS_BOUNCE_VALUES) . "')");
        $openedExpr = self::stageExpr($filters, $params, 'ao', '_opened', 'ao.open_count > 0');
        $repliedExpr = self::stageExpr($filters, $params, 'ar', '_replied', "ar.delivery_status = 'Replied'");

        $sql = "SELECT
                   SUM(CASE WHEN {$contactedExpr} THEN 1 ELSE 0 END) AS contacted,
                   SUM(CASE WHEN {$deliveredExpr} THEN 1 ELSE 0 END) AS delivered,
                   SUM(CASE WHEN {$openedExpr} THEN 1 ELSE 0 END) AS opened,
                   SUM(CASE WHEN {$repliedExpr} THEN 1 ELSE 0 END) AS replied
                 FROM leads l
                 WHERE {$scopeWhere}";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: ['contacted' => 0, 'delivered' => 0, 'opened' => 0, 'replied' => 0];

        $contacted = (int) $row['contacted'];
        $delivered = (int) $row['delivered'];
        $opened = (int) $row['opened'];
        $replied = (int) $row['replied'];

        // Active sending days: distinct dates across every qualifying
        // assignment row DIRECTLY, not deduped to one row per lead like
        // the stage counts above -- a date something was sent on is a
        // fact about that send event, independent of whether the lead
        // later got reassigned to a different campaign.
        $daysClauses = ['a.email_sent = 1', 'l.deleted_at IS NULL'];
        $daysParams = [];
        foreach (self::periodClauses($filters, $daysParams, 'a', '_days') as $c) {
            $daysClauses[] = $c;
        }
        ScopeFilter::apply($daysClauses, $daysParams, $scope, 'l', 'scope_company_id_days');
        ScopeFilter::applyOwnerScope($daysClauses, $daysParams, $scope, $db, 'l', 'owner_id', 'scope_owner_days');
        $daysStmt = $db->prepare(
            'SELECT COUNT(DISTINCT a.email_sent_at) FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id WHERE '
            . implode(' AND ', $daysClauses)
        );
        $daysStmt->execute($daysParams);
        $activeDays = (int) $daysStmt->fetchColumn();

        $pct = static fn (int $count, int $base): float => $base > 0 ? $count / $base : 0.0;

        $funnel = [
            ['stage' => 'Contacts in database', 'count' => $inDatabase, 'pct_of_database' => $pct($inDatabase, $inDatabase), 'pct_of_previous' => $pct($inDatabase, $inDatabase)],
            ['stage' => 'Contacted at least once', 'count' => $contacted, 'pct_of_database' => $pct($contacted, $inDatabase), 'pct_of_previous' => $pct($contacted, $inDatabase)],
            ['stage' => 'Delivered (not bounced)', 'count' => $delivered, 'pct_of_database' => $pct($delivered, $inDatabase), 'pct_of_previous' => $pct($delivered, $contacted)],
            ['stage' => 'Opened', 'count' => $opened, 'pct_of_database' => $pct($opened, $inDatabase), 'pct_of_previous' => $pct($opened, $delivered)],
            ['stage' => 'Replied', 'count' => $replied, 'pct_of_database' => $pct($replied, $inDatabase), 'pct_of_previous' => $pct($replied, $opened)],
        ];

        return [
            'headline' => [
                'contacts_in_database' => $inDatabase,
                'contacts_reached' => $contacted,
                'unique_opens' => $opened,
                'replies' => $replied,
                'active_sending_days' => $activeDays,
            ],
            'funnel' => $funnel,
        ];
    }

    /**
     * The same 5-stage funnel as summary(), but rolled up to ACCOUNTS
     * (company/domain) rather than individual persona -- same grouping
     * AccountRepository/public/accounts.php already use (email domain,
     * not a stored entity). An account counts as reached a stage the
     * moment any one of its personas does -- e.g. "Accounts contacted"
     * is domains with at least one contacted lead, not domains where
     * every lead was contacted. "Accounts in database" is unscoped by
     * period, like summary()'s "Contacts in database"; every other
     * figure is period-scoped. "Accounts available" (headline only) is
     * in_database minus contacted -- domains nobody's reached out to yet.
     *
     * @param array{date_from?:?string,date_to?:?string,campaign_id?:?int} $filters
     * @return array{
     *   headline: array{accounts_in_database:int,accounts_contacted:int,accounts_available:int,accounts_suppressed:int},
     *   funnel: array<int,array{stage:string,count:int,pct_of_database:float,pct_of_previous:float}>
     * }
     */
    public static function accountsSummary(PDO $db, Scope $scope, array $filters): array
    {
        $params = [];
        $contactedExpr = self::stageExpr($filters, $params, 'ac', '_acct_c');
        $deliveredExpr = self::stageExpr($filters, $params, 'ad', '_acct_d', "ad.delivery_status IS NULL OR ad.delivery_status NOT IN ('" . implode("','", DELIVERY_STATUS_BOUNCE_VALUES) . "')");
        $openedExpr = self::stageExpr($filters, $params, 'ao', '_acct_o', 'ao.open_count > 0');
        $repliedExpr = self::stageExpr($filters, $params, 'ar', '_acct_r', "ar.delivery_status = 'Replied'");
        $scopeClauses = ['l.deleted_at IS NULL'];
        ScopeFilter::apply($scopeClauses, $params, $scope);
        ScopeFilter::applyOwnerScope($scopeClauses, $params, $scope, $db);
        $scopeWhere = implode(' AND ', $scopeClauses);

        $sql = "SELECT
                   COUNT(*) AS total_accounts,
                   SUM(CASE WHEN contacted_leads > 0 THEN 1 ELSE 0 END) AS contacted_accounts,
                   SUM(CASE WHEN delivered_leads > 0 THEN 1 ELSE 0 END) AS delivered_accounts,
                   SUM(CASE WHEN opened_leads > 0 THEN 1 ELSE 0 END) AS opened_accounts,
                   SUM(CASE WHEN replied_leads > 0 THEN 1 ELSE 0 END) AS replied_accounts,
                   SUM(CASE WHEN suppressed_reason IS NOT NULL THEN 1 ELSE 0 END) AS suppressed_accounts
                 FROM (
                   SELECT SUBSTRING_INDEX(l.email, '@', -1) AS domain,
                          SUM(CASE WHEN {$contactedExpr} THEN 1 ELSE 0 END) AS contacted_leads,
                          SUM(CASE WHEN {$deliveredExpr} THEN 1 ELSE 0 END) AS delivered_leads,
                          SUM(CASE WHEN {$openedExpr} THEN 1 ELSE 0 END) AS opened_leads,
                          SUM(CASE WHEN {$repliedExpr} THEN 1 ELSE 0 END) AS replied_leads,
                          MAX(sd.reason) AS suppressed_reason
                     FROM leads l
                     LEFT JOIN suppressed_domains sd ON sd.domain = SUBSTRING_INDEX(l.email, '@', -1) AND sd.company_id = l.company_id
                    WHERE {$scopeWhere}
                    GROUP BY SUBSTRING_INDEX(l.email, '@', -1)
                 ) t";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [
            'total_accounts' => 0, 'contacted_accounts' => 0, 'delivered_accounts' => 0,
            'opened_accounts' => 0, 'replied_accounts' => 0, 'suppressed_accounts' => 0,
        ];

        $inDatabase = (int) $row['total_accounts'];
        $contacted = (int) $row['contacted_accounts'];
        $delivered = (int) $row['delivered_accounts'];
        $opened = (int) $row['opened_accounts'];
        $replied = (int) $row['replied_accounts'];
        $suppressed = (int) $row['suppressed_accounts'];

        $pct = static fn (int $count, int $base): float => $base > 0 ? $count / $base : 0.0;

        $funnel = [
            ['stage' => 'Accounts in database', 'count' => $inDatabase, 'pct_of_database' => $pct($inDatabase, $inDatabase), 'pct_of_previous' => $pct($inDatabase, $inDatabase)],
            ['stage' => 'Accounts contacted', 'count' => $contacted, 'pct_of_database' => $pct($contacted, $inDatabase), 'pct_of_previous' => $pct($contacted, $inDatabase)],
            ['stage' => 'Accounts delivered (not bounced)', 'count' => $delivered, 'pct_of_database' => $pct($delivered, $inDatabase), 'pct_of_previous' => $pct($delivered, $contacted)],
            ['stage' => 'Accounts opened', 'count' => $opened, 'pct_of_database' => $pct($opened, $inDatabase), 'pct_of_previous' => $pct($opened, $delivered)],
            ['stage' => 'Accounts replied', 'count' => $replied, 'pct_of_database' => $pct($replied, $inDatabase), 'pct_of_previous' => $pct($replied, $opened)],
        ];

        return [
            'headline' => [
                'accounts_in_database' => $inDatabase,
                'accounts_contacted' => $contacted,
                'accounts_available' => max(0, $inDatabase - $contacted),
                'accounts_suppressed' => $suppressed,
            ],
            'funnel' => $funnel,
        ];
    }

    /**
     * In database / Contacted / Not contacted / Opened / Coverage % per
     * vertical, plus a TOTAL row. Only verticals with at least one lead
     * appear (same "only show what's actually populated" convention as
     * AnalyticsRepository::pivotByDimension()).
     *
     * @return array{rows:array<int,array{grp:string,in_database:int,contacted:int,not_contacted:int,opened:int,coverage_pct:float}>,total:array{in_database:int,contacted:int,not_contacted:int,opened:int,coverage_pct:float}}
     */
    public static function coverageByVertical(PDO $db, Scope $scope, array $filters): array
    {
        $params = [];
        $contactedExpr = self::stageExpr($filters, $params, 'ac', '_c');
        $openedExpr = self::stageExpr($filters, $params, 'ao', '_o', 'ao.open_count > 0');
        $scopeClauses = ['l.deleted_at IS NULL'];
        ScopeFilter::apply($scopeClauses, $params, $scope);
        ScopeFilter::applyOwnerScope($scopeClauses, $params, $scope, $db);
        $scopeWhere = implode(' AND ', $scopeClauses);

        $sql = "SELECT COALESCE(v.label, '(none)') AS grp,
                   COUNT(*) AS in_database,
                   SUM(CASE WHEN {$contactedExpr} THEN 1 ELSE 0 END) AS contacted,
                   SUM(CASE WHEN {$openedExpr} THEN 1 ELSE 0 END) AS opened
                 FROM leads l
                 LEFT JOIN verticals v ON v.id = l.vertical_id
                 WHERE {$scopeWhere}
                 GROUP BY grp
                 ORDER BY grp";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $total = ['in_database' => 0, 'contacted' => 0, 'opened' => 0];
        foreach ($rows as &$r) {
            $r['in_database'] = (int) $r['in_database'];
            $r['contacted'] = (int) $r['contacted'];
            $r['opened'] = (int) $r['opened'];
            $r['not_contacted'] = $r['in_database'] - $r['contacted'];
            $r['coverage_pct'] = $r['in_database'] > 0 ? $r['contacted'] / $r['in_database'] : 0.0;
            $total['in_database'] += $r['in_database'];
            $total['contacted'] += $r['contacted'];
            $total['opened'] += $r['opened'];
        }
        unset($r);
        $total['not_contacted'] = $total['in_database'] - $total['contacted'];
        $total['coverage_pct'] = $total['in_database'] > 0 ? $total['contacted'] / $total['in_database'] : 0.0;

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * One row per Saleshandy-linked campaign, sorted by volume. "Contacts"
     * is the distinct-lead count (one row per lead in
     * lead_campaign_assignments). "Emails sent" is the *real* total send
     * count from saleshandy_send_events (so a sequence with follow-up
     * steps correctly shows more emails sent than contacts reached) --
     * except for a campaign with no rows there yet (not backfilled since
     * sql/024), where it falls back to the same distinct-lead count as
     * Contacts, same as before this table existed.
     *
     * @return array<int,array{name:string,vertical_label:?string,emails_sent:int,contacts:int,opens:int,open_rate:float,bounces:int,bounce_rate:float,replies:int}>
     */
    public static function sequences(PDO $db, Scope $scope, array $filters): array
    {
        $params = [];
        $period = self::periodExpr($filters, $params);
        $eventsPeriod = self::eventsPeriodExpr($filters, $params);
        $scopeClauses = [];
        ScopeFilter::apply($scopeClauses, $params, $scope, 'c');
        ScopeFilter::applyOwnerScope($scopeClauses, $params, $scope, $db, 'c', 'saleshandy_account_owner_id', 'scope_campaign_owner');
        $scopeWhere = $scopeClauses ? (' AND ' . implode(' AND ', $scopeClauses)) : '';

        $sql = "SELECT c.name, v.label AS vertical_label,
                   COUNT(*) AS contacts,
                   COALESCE(MAX(ev.emails_sent), COUNT(*)) AS emails_sent,
                   SUM(CASE WHEN a.open_count > 0 THEN 1 ELSE 0 END) AS opens,
                   SUM(CASE WHEN a.delivery_status IN ('" . implode("','", DELIVERY_STATUS_BOUNCE_VALUES) . "') THEN 1 ELSE 0 END) AS bounces,
                   SUM(CASE WHEN a.delivery_status = 'Replied' THEN 1 ELSE 0 END) AS replies
                 FROM lead_campaign_assignments a
                 JOIN campaigns c ON c.id = a.campaign_id
                 JOIN leads l ON l.id = a.lead_id
                 LEFT JOIN verticals v ON v.id = c.vertical_id
                 LEFT JOIN (
                     SELECT e.campaign_id, COUNT(*) AS emails_sent
                       FROM saleshandy_send_events e
                      WHERE {$eventsPeriod}
                      GROUP BY e.campaign_id
                 ) ev ON ev.campaign_id = c.id
                 WHERE l.deleted_at IS NULL AND c.saleshandy_sequence_id IS NOT NULL AND {$period}{$scopeWhere}
                 GROUP BY c.id, c.name, v.label
                 ORDER BY emails_sent DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $contacts = (int) $r['contacts'];
            $emailsSent = (int) $r['emails_sent'];
            $r['contacts'] = $contacts;
            $r['emails_sent'] = $emailsSent;
            $r['opens'] = (int) $r['opens'];
            $r['bounces'] = (int) $r['bounces'];
            $r['replies'] = (int) $r['replies'];
            $r['open_rate'] = $emailsSent > 0 ? $r['opens'] / $emailsSent : 0.0;
            $r['bounce_rate'] = $emailsSent > 0 ? $r['bounces'] / $emailsSent : 0.0;
        }
        unset($r);

        return $rows;
    }

    /**
     * Replies broken down by Saleshandy's own outcome sentiment
     * (Positive/Negative/Neutral/Uncategorized -- see
     * lead_campaign_assignments.reply_sentiment), plus a count for replies
     * that predate this feature and have no sentiment recorded yet ("Not
     * yet categorized" -- resolves itself as campaigns get re-synced).
     * Uses the same base query/period definition as summary()'s "Replied"
     * figure (stageExpr(), ANY assignment not just the latest), so these
     * counts always add up to exactly that number. Reads the sentiment
     * off each lead's MOST RECENT qualifying reply -- a lead can only
     * have one row per campaign, but could in principle have qualifying
     * replies in more than one (rare), so this picks one deterministically
     * rather than double-counting or picking arbitrarily.
     *
     * @return array<int,array{outcome:string,count:int}>
     */
    public static function repliesByOutcome(PDO $db, Scope $scope, array $filters): array
    {
        $params = [];
        $repliedExpr = self::stageExpr($filters, $params, 'ar', '_outcome', "ar.delivery_status = 'Replied'");
        $scopeClauses = ['l.deleted_at IS NULL'];
        ScopeFilter::apply($scopeClauses, $params, $scope);
        ScopeFilter::applyOwnerScope($scopeClauses, $params, $scope, $db);
        $scopeWhere = implode(' AND ', $scopeClauses);

        $pickClauses = array_merge(
            ["a2.lead_id = l.id", "a2.delivery_status = 'Replied'"],
            self::periodClauses($filters, $params, 'a2', '_outcome_pick')
        );
        $pickWhere = implode(' AND ', $pickClauses);

        $sql = "SELECT COALESCE(NULLIF((
                     SELECT a2.reply_sentiment FROM lead_campaign_assignments a2
                      WHERE {$pickWhere}
                      ORDER BY a2.id DESC LIMIT 1
                   ), ''), 'Not yet categorized') AS outcome,
                   COUNT(*) AS cnt
                 FROM leads l
                 WHERE {$scopeWhere} AND {$repliedExpr}
                 GROUP BY outcome
                 ORDER BY cnt DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn (array $r): array => ['outcome' => $r['outcome'], 'count' => (int) $r['cnt']],
            $stmt->fetchAll()
        );
    }

    /** SQL fragment (no leading AND) scoping saleshandy_send_events to the given filters. */
    private static function eventsPeriodExpr(array $filters, array &$params): string
    {
        $clauses = ['1=1'];
        if (!empty($filters['date_from'])) {
            $clauses[] = 'e.sent_date >= :ev_date_from';
            $params['ev_date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $clauses[] = 'e.sent_date <= :ev_date_to';
            $params['ev_date_to'] = $filters['date_to'];
        }
        if (!empty($filters['campaign_id'])) {
            $clauses[] = 'e.campaign_id = :ev_campaign_id';
            $params['ev_campaign_id'] = (int) $filters['campaign_id'];
        }
        return implode(' AND ', $clauses);
    }

    /**
     * saleshandy_send_events has no reliable per-row owner of its own
     * (lead_id is nullable -- an event for an email not yet locally
     * matched still gets recorded, see sql/024_send_events.sql) but every
     * row does always have a campaign_id, so every method reading this
     * table scopes via a join to campaigns instead -- same
     * campaigns.saleshandy_account_owner_id ownership as
     * AnalyticsRepository::campaignFunnel() / sequences() above, so "your
     * campaigns' activity" means the same thing everywhere in this app.
     *
     * @return array{0:string,1:string} [JOIN clause, scope WHERE fragment (no leading AND)]
     */
    private static function eventsScopeJoin(PDO $db, Scope $scope, array &$params): array
    {
        $clauses = [];
        ScopeFilter::apply($clauses, $params, $scope, 'c');
        ScopeFilter::applyOwnerScope($clauses, $params, $scope, $db, 'c', 'saleshandy_account_owner_id', 'scope_campaign_owner');
        $join = 'JOIN campaigns c ON c.id = e.campaign_id';
        $where = $clauses ? implode(' AND ', $clauses) : '1=1';
        return [$join, $where];
    }

    /**
     * Earliest/latest sent_date with any recorded send event -- used to
     * default the Daily/Weekly/Steps filter range to "the whole available
     * period". Returns nulls if saleshandy_send_events is still empty
     * (e.g. before the first post-deploy sync/backfill has run), or if
     * nothing in scope has any recorded event yet.
     *
     * @return array{min:?string,max:?string}
     */
    public static function dateBounds(PDO $db, Scope $scope): array
    {
        $params = [];
        [$join, $scopeWhere] = self::eventsScopeJoin($db, $scope, $params);
        $stmt = $db->prepare(
            "SELECT MIN(e.sent_date) AS min_date, MAX(e.sent_date) AS max_date
               FROM saleshandy_send_events e {$join} WHERE {$scopeWhere}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return ['min' => $row['min_date'] ?? null, 'max' => $row['max_date'] ?? null];
    }

    /**
     * One row per active day (days with zero sends simply produce no
     * group -- no calendar generation needed), plus a TOTAL row appended.
     * "Opened"/"Bounced" count distinct recipients per day, not raw event
     * counts (a recipient can appear once per step per day at most, per
     * this table's unique key, so this only matters if a lead legitimately
     * received two different steps on the same calendar day).
     *
     * Open Count/Last Opened At are Saleshandy's cumulative snapshot as of
     * the fetch that recorded each row, not a full per-day open log -- see
     * SaleshandyClient::fetchSequenceActivity()'s docblock. A contact who
     * opened on an earlier day and again more recently will show their
     * open attributed to the day of that most recent fetch's snapshot,
     * not necessarily the exact day they first opened.
     *
     * @return array<int,array{date:string,emails_sent:int,contacts:int,opened:int,bounced:int,open_rate:float,bounce_rate:float}>
     */
    public static function dailyActivity(PDO $db, Scope $scope, array $filters): array
    {
        $params = [];
        $period = self::eventsPeriodExpr($filters, $params);
        [$join, $scopeWhere] = self::eventsScopeJoin($db, $scope, $params);

        $sql = "SELECT e.sent_date AS date,
                   COUNT(*) AS emails_sent,
                   COUNT(DISTINCT e.recipient_email) AS contacts,
                   SUM(CASE WHEN e.open_count > 0 THEN 1 ELSE 0 END) AS opened,
                   SUM(e.bounced) AS bounced
                 FROM saleshandy_send_events e {$join}
                 WHERE {$period} AND {$scopeWhere}
                 GROUP BY e.sent_date
                 ORDER BY e.sent_date";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $total = ['emails_sent' => 0, 'opened' => 0, 'bounced' => 0];
        foreach ($rows as &$r) {
            $r['emails_sent'] = (int) $r['emails_sent'];
            $r['contacts'] = (int) $r['contacts'];
            $r['opened'] = (int) $r['opened'];
            $r['bounced'] = (int) $r['bounced'];
            $r['open_rate'] = $r['emails_sent'] > 0 ? $r['opened'] / $r['emails_sent'] : 0.0;
            $r['bounce_rate'] = $r['emails_sent'] > 0 ? $r['bounced'] / $r['emails_sent'] : 0.0;
            $total['emails_sent'] += $r['emails_sent'];
            $total['opened'] += $r['opened'];
            $total['bounced'] += $r['bounced'];
        }
        unset($r);
        if ($rows) {
            $rows[] = [
                'date' => 'TOTAL', 'emails_sent' => $total['emails_sent'], 'contacts' => null,
                'opened' => $total['opened'], 'bounced' => $total['bounced'],
                'open_rate' => $total['emails_sent'] > 0 ? $total['opened'] / $total['emails_sent'] : 0.0,
                'bounce_rate' => $total['emails_sent'] > 0 ? $total['bounced'] / $total['emails_sent'] : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * One row per Monday-anchored week, plus TOTAL. Same Open Count
     * snapshot caveat as dailyActivity().
     *
     * @return array<int,array{week_start:string,emails_sent:int,opened:int,replied:int,active_days:int,open_rate:float,emails_per_active_day:float}>
     */
    public static function weeklyActivity(PDO $db, Scope $scope, array $filters): array
    {
        $params = [];
        $period = self::eventsPeriodExpr($filters, $params);
        [$join, $scopeWhere] = self::eventsScopeJoin($db, $scope, $params);

        // WEEKDAY() returns 0=Monday..6=Sunday, so subtracting it from the
        // date always lands on that date's Monday -- a timezone-independent
        // way to bucket by week without a calendar table.
        $sql = "SELECT DATE_SUB(e.sent_date, INTERVAL WEEKDAY(e.sent_date) DAY) AS week_start,
                   COUNT(*) AS emails_sent,
                   SUM(CASE WHEN e.open_count > 0 THEN 1 ELSE 0 END) AS opened,
                   SUM(e.replied) AS replied,
                   COUNT(DISTINCT e.sent_date) AS active_days
                 FROM saleshandy_send_events e {$join}
                 WHERE {$period} AND {$scopeWhere}
                 GROUP BY week_start
                 ORDER BY week_start";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $total = ['emails_sent' => 0, 'opened' => 0, 'replied' => 0, 'active_days' => 0];
        foreach ($rows as &$r) {
            $r['emails_sent'] = (int) $r['emails_sent'];
            $r['opened'] = (int) $r['opened'];
            $r['replied'] = (int) $r['replied'];
            $r['active_days'] = (int) $r['active_days'];
            $r['open_rate'] = $r['emails_sent'] > 0 ? $r['opened'] / $r['emails_sent'] : 0.0;
            $r['emails_per_active_day'] = $r['active_days'] > 0 ? $r['emails_sent'] / $r['active_days'] : 0.0;
            $total['emails_sent'] += $r['emails_sent'];
            $total['opened'] += $r['opened'];
            $total['replied'] += $r['replied'];
            $total['active_days'] += $r['active_days'];
        }
        unset($r);
        if ($rows) {
            $rows[] = [
                'week_start' => 'TOTAL', 'emails_sent' => $total['emails_sent'], 'opened' => $total['opened'],
                'replied' => $total['replied'], 'active_days' => $total['active_days'],
                'open_rate' => $total['emails_sent'] > 0 ? $total['opened'] / $total['emails_sent'] : 0.0,
                'emails_per_active_day' => $total['active_days'] > 0 ? $total['emails_sent'] / $total['active_days'] : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * RAW per-step counts (Step 1..N), pooled across every campaign in the
     * filtered period -- explicitly NOT cumulative, unlike
     * AnalyticsRepository::campaignFunnel()'s "reached step N" numbers.
     * Matches the original spreadsheet's flat Step 1..7 list, which also
     * pooled every sequence together rather than reporting per-sequence.
     *
     * @return array<int,array{step_number:int,emails_sent:int,opens:int,replies:int,open_rate:float}>
     */
    public static function stepsRaw(PDO $db, Scope $scope, array $filters): array
    {
        $params = [];
        $period = self::eventsPeriodExpr($filters, $params);
        [$join, $scopeWhere] = self::eventsScopeJoin($db, $scope, $params);

        $sql = "SELECT e.step_number,
                   COUNT(*) AS emails_sent,
                   SUM(CASE WHEN e.open_count > 0 THEN 1 ELSE 0 END) AS opens,
                   SUM(e.replied) AS replies
                 FROM saleshandy_send_events e {$join}
                 WHERE {$period} AND {$scopeWhere}
                 GROUP BY e.step_number
                 ORDER BY e.step_number";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['step_number'] = (int) $r['step_number'];
            $r['emails_sent'] = (int) $r['emails_sent'];
            $r['opens'] = (int) $r['opens'];
            $r['replies'] = (int) $r['replies'];
            $r['open_rate'] = $r['emails_sent'] > 0 ? $r['opens'] / $r['emails_sent'] : 0.0;
        }
        unset($r);

        return $rows;
    }
}
