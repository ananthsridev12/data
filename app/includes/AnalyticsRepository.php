<?php

require_once __DIR__ . '/ScopeFilter.php';

/**
 * Country/campaign/vertical/service pivot reporting for the Analytics
 * page -- one row per group value, with self-consistent counts
 * (imported + not_imported = prospects; email_sent + email_not_sent =
 * prospects) so the numbers can be sanity-checked at a glance instead of
 * needing to be cross-referenced across several near-duplicate tables.
 *
 * A lead has at most one *current* campaign assignment in practice
 * (WaveAssigner enforces one-campaign-ever; a removed, not-yet-pushed
 * assignment is hard-deleted before a lead can be reassigned -- see
 * campaign_assignment_update.php's remove_from_campaign action). The
 * "latest assignment per lead" derived table below is a defensive
 * belt-and-braces against that invariant rather than a load-bearing
 * assumption: if it were ever violated, this still picks one row per
 * lead deterministically instead of silently fanning out the counts.
 */
class AnalyticsRepository
{
    public const GROUP_DIMENSIONS = [
        'company_country' => 'Company Country',
        'country_group' => 'Country Group',
        'campaign' => 'Campaign',
        'vertical' => 'Vertical',
        'service' => 'Service',
    ];

    private const ASSIGNMENT_JOIN = "
        LEFT JOIN (
            SELECT a1.* FROM lead_campaign_assignments a1
            INNER JOIN (SELECT lead_id, MAX(id) AS max_id FROM lead_campaign_assignments GROUP BY lead_id) latest
              ON latest.lead_id = a1.lead_id AND latest.max_id = a1.id
        ) a ON a.lead_id = l.id
        LEFT JOIN campaigns c ON c.id = a.campaign_id
        LEFT JOIN verticals v ON v.id = l.vertical_id
        LEFT JOIN services s ON s.id = l.service_id
        LEFT JOIN country_groups cg ON cg.id = l.country_group_id
    ";

    /**
     * One consolidated table for a single dimension: Prospects / Linked to
     * a campaign / Imported to Saleshandy / Not Imported (broken down by
     * WHY) / Email Sent / Email Not Sent, each row's Imported+Not Imported
     * (and Email Sent+Email Not Sent) always adding back up to Prospects.
     *
     * "Not imported" is further split into 5 mutually-exclusive reasons
     * (summing back to not_imported exactly), each row's assignment
     * falling into exactly one bucket:
     *  - not_imported_no_campaign: never assigned to any campaign at all.
     *  - not_imported_suppressed: assigned, but held back -- its domain
     *    bounced (wave_status = 'suppressed').
     *  - not_imported_held: assigned, but waiting on its wave-1 leader's
     *    delivery to be confirmed first (wave_status = 'held').
     *  - not_imported_no_sequence: assigned to a campaign that isn't
     *    linked to a Saleshandy sequence yet, so pushing it isn't even
     *    possible until that's configured.
     *  - not_imported_queued: assigned, wave-active, campaign linked --
     *    genuinely just hasn't been pushed yet (nobody's clicked "Push"
     *    for it, or it's mid-verification-filter).
     *
     * @param array<string,mixed> $filters campaign_id, vertical_id, service_id, industry,
     *   created_from, created_to (leads.created_at date range, Y-m-d),
     *   email_sent_from, email_sent_to (assignment email_sent_at date range, Y-m-d)
     * @return array{
     *   rows: array<int,array{grp:string,prospects:int,linked_to_campaign:int,imported:int,not_imported:int,not_imported_no_campaign:int,not_imported_suppressed:int,not_imported_held:int,not_imported_no_sequence:int,not_imported_queued:int,email_sent:int,email_not_sent:int,sequence_completed:int}>,
     *   total: array{prospects:int,linked_to_campaign:int,imported:int,not_imported:int,not_imported_no_campaign:int,not_imported_suppressed:int,not_imported_held:int,not_imported_no_sequence:int,not_imported_queued:int,email_sent:int,email_not_sent:int,sequence_completed:int}
     * }
     */
    public static function pivotByDimension(PDO $db, Scope $scope, string $groupBy, array $filters): array
    {
        $groupExpr = self::groupExpr($groupBy);
        [$clauses, $params] = self::buildBaseClauses($filters);
        ScopeFilter::apply($clauses, $params, $scope);
        ScopeFilter::applyOwnerScope($clauses, $params, $scope, $db);
        $where = 'WHERE ' . implode(' AND ', $clauses);

        // 'imported'/'linked_to_campaign'/not_imported_* describe CURRENT
        // standing -- whether this lead's latest (i.e. current) campaign
        // assignment has been pushed -- so they stay scoped to the
        // "latest assignment" join (`a`/`c`) below. 'imported' = status
        // IN ('exported', 'pushed') -- must match LeadRepository::
        // buildWhere()'s 'imported' filter exactly (see its comment) so a
        // Dashboard drill-through link reproduces the exact count it was
        // clicked from, and so this matches campaign_leads.php's own
        // "Imported" column definition.
        //
        // 'email_sent'/'sequence_completed' are different in kind: once
        // true, they're permanently true FACTS about a lead, not current
        // standing -- so unlike the above, these check EVERY assignment
        // the lead has ever had, not just the latest one. This matters a
        // lot now that cooldown-based reassignment (WaveAssigner) lets a
        // lead move to a NEW campaign once its prior one resolves: that
        // new assignment correctly starts at email_sent=0 until its own
        // send is confirmed, but scoping this to "latest assignment only"
        // made a lead who was genuinely emailed (and even completed a
        // full sequence) in an EARLIER campaign look like they'd never
        // been contacted at all the moment they got reassigned -- a real
        // account-wide undercount discovered by comparing this page's
        // totals against Saleshandy's own "Total Contacted" number, which
        // has no such per-campaign blind spot. Must stay in sync with
        // LeadRepository::buildWhere()'s 'email_sent'/'sequence_completed'
        // filters (same "any assignment" EXISTS checks) so a drill-through
        // link reproduces the exact count it was clicked from.
        $sql = "SELECT {$groupExpr} AS grp,
                   COUNT(*) AS prospects,
                   SUM(CASE WHEN a.campaign_id IS NOT NULL THEN 1 ELSE 0 END) AS linked_to_campaign,
                   SUM(CASE WHEN a.status IN ('exported', 'pushed') THEN 1 ELSE 0 END) AS imported,
                   SUM(CASE WHEN a.status IS NULL OR a.status NOT IN ('exported', 'pushed') THEN 1 ELSE 0 END) AS not_imported,
                   SUM(CASE WHEN a.campaign_id IS NULL THEN 1 ELSE 0 END) AS not_imported_no_campaign,
                   SUM(CASE WHEN a.campaign_id IS NOT NULL AND a.status NOT IN ('exported', 'pushed') AND a.wave_status = 'suppressed' THEN 1 ELSE 0 END) AS not_imported_suppressed,
                   SUM(CASE WHEN a.campaign_id IS NOT NULL AND a.status NOT IN ('exported', 'pushed') AND a.wave_status = 'held' THEN 1 ELSE 0 END) AS not_imported_held,
                   SUM(CASE WHEN a.campaign_id IS NOT NULL AND a.status NOT IN ('exported', 'pushed') AND a.wave_status = 'active' AND c.saleshandy_sequence_id IS NULL THEN 1 ELSE 0 END) AS not_imported_no_sequence,
                   SUM(CASE WHEN a.campaign_id IS NOT NULL AND a.status NOT IN ('exported', 'pushed') AND a.wave_status = 'active' AND c.saleshandy_sequence_id IS NOT NULL THEN 1 ELSE 0 END) AS not_imported_queued,
                   SUM(CASE WHEN EXISTS (SELECT 1 FROM lead_campaign_assignments ae WHERE ae.lead_id = l.id AND ae.email_sent = 1) THEN 1 ELSE 0 END) AS email_sent,
                   SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM lead_campaign_assignments ae WHERE ae.lead_id = l.id AND ae.email_sent = 1) THEN 1 ELSE 0 END) AS email_not_sent,
                   SUM(CASE WHEN EXISTS (
                         SELECT 1 FROM lead_campaign_assignments asq
                         JOIN campaigns csq ON csq.id = asq.campaign_id
                        WHERE asq.lead_id = l.id AND asq.delivery_status = 'Active'
                          AND csq.saleshandy_step_count IS NOT NULL
                          AND asq.saleshandy_current_step >= csq.saleshandy_step_count
                       ) THEN 1 ELSE 0 END) AS sequence_completed
                 FROM leads l "
                . self::ASSIGNMENT_JOIN .
                " {$where}
                 GROUP BY grp
                 ORDER BY grp";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $total = [
            'prospects' => 0, 'linked_to_campaign' => 0, 'imported' => 0, 'not_imported' => 0,
            'not_imported_no_campaign' => 0, 'not_imported_suppressed' => 0, 'not_imported_held' => 0,
            'not_imported_no_sequence' => 0, 'not_imported_queued' => 0,
            'email_sent' => 0, 'email_not_sent' => 0, 'sequence_completed' => 0,
        ];
        foreach ($rows as &$r) {
            foreach ($total as $key => $_) {
                $r[$key] = (int) $r[$key];
                $total[$key] += $r[$key];
            }
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Per-campaign outreach funnel: Prospects / Contacted (at least the
     * first email sent) / a cumulative sent count for each sequence step
     * reached so far / Replies. Local-DB only, same as everything else
     * here -- no live Saleshandy call.
     *
     * Step counts are cumulative ("reached step N"), derived from
     * lead_campaign_assignments.saleshandy_current_step -- the furthest
     * step Saleshandy has reported an actual send for (see
     * SaleshandyClient::syncCampaign()). Sequence steps fire in order, so
     * "reached step 3" implies steps 1 and 2 were sent too; this is the
     * standard way outreach tools report a step funnel (fewer sent at each
     * later step as people reply/bounce/pause along the way). The step
     * count is per campaign -- a 3-touch campaign simply never has a
     * step 4 key.
     *
     * @return array<int,array{
     *   id:int,name:string,vertical_label:?string,service_label:?string,
     *   prospects:int,contacted:int,replies:int,first_email_date:?string,
     *   steps:array<int,int>
     * }>
     */
    public static function campaignFunnel(PDO $db, Scope $scope): array
    {
        $clauses = [];
        $params = [];
        ScopeFilter::apply($clauses, $params, $scope, 'c');
        // Campaign-level ownership (not lead ownership) -- "their
        // campaigns" per this method's caller (Analytics), consistent
        // with how Campaigns page access is scoped: Admin sees every
        // company campaign, Team Lead sees their team's owned campaigns
        // pooled, Member sees only their own.
        ScopeFilter::applyOwnerScope($clauses, $params, $scope, $db, 'c', 'saleshandy_account_owner_id', 'scope_campaign_owner');
        $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

        $stmt = $db->prepare(
            "SELECT c.id, c.name, v.label AS vertical_label, s.label AS service_label,
               (SELECT COUNT(*) FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id
                 WHERE a.campaign_id = c.id AND l.deleted_at IS NULL) AS prospects,
               (SELECT COUNT(*) FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id
                 WHERE a.campaign_id = c.id AND l.deleted_at IS NULL AND a.email_sent = 1) AS contacted,
               (SELECT COUNT(*) FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id
                 WHERE a.campaign_id = c.id AND l.deleted_at IS NULL AND a.delivery_status = 'Replied') AS replies,
               (SELECT MIN(a.email_sent_at) FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id
                 WHERE a.campaign_id = c.id AND l.deleted_at IS NULL AND a.email_sent = 1) AS first_email_date
             FROM campaigns c
             LEFT JOIN verticals v ON v.id = c.vertical_id
             LEFT JOIN services s ON s.id = c.service_id
             {$where}
             ORDER BY c.created_at DESC"
        );
        $stmt->execute($params);
        $campaigns = $stmt->fetchAll();

        // Pooled across every campaign company-wide (not just the scoped
        // set above) -- harmless: only entries whose campaign_id matches
        // one of $campaigns (already scoped) ever get looked up below, so
        // an out-of-scope campaign's step data is fetched but never read.
        $stepCounts = $db->query(
            "SELECT a.campaign_id, a.saleshandy_current_step AS step, COUNT(*) AS cnt
               FROM lead_campaign_assignments a
               JOIN leads l ON l.id = a.lead_id
              WHERE l.deleted_at IS NULL AND a.saleshandy_current_step IS NOT NULL
              GROUP BY a.campaign_id, a.saleshandy_current_step"
        )->fetchAll();

        // Raw counts are "exactly reached this step as their furthest so
        // far" -- turn into "reached at least step N" (cumulative) per
        // campaign, descending so each step picks up everyone at a later
        // step too.
        $rawByCampaign = [];
        $maxStepByCampaign = [];
        foreach ($stepCounts as $row) {
            $cid = (int) $row['campaign_id'];
            $step = (int) $row['step'];
            $rawByCampaign[$cid][$step] = (int) $row['cnt'];
            $maxStepByCampaign[$cid] = max($maxStepByCampaign[$cid] ?? 0, $step);
        }

        foreach ($campaigns as &$c) {
            $cid = (int) $c['id'];
            $maxStep = $maxStepByCampaign[$cid] ?? 0;
            $steps = [];
            $cumulative = 0;
            for ($n = $maxStep; $n >= 1; $n--) {
                $cumulative += $rawByCampaign[$cid][$n] ?? 0;
                $steps[$n] = $cumulative;
            }
            ksort($steps);
            $c['steps'] = $steps;
        }
        unset($c);

        return $campaigns;
    }

    /**
     * @return array{0:array<int,string>,1:array<string,mixed>} [WHERE clauses, bound params]
     */
    private static function buildBaseClauses(array $filters): array
    {
        $clauses = ['l.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['campaign_id'])) {
            $clauses[] = 'a.campaign_id = :campaign_id';
            $params['campaign_id'] = (int) $filters['campaign_id'];
        }
        if (!empty($filters['vertical_id'])) {
            $clauses[] = 'l.vertical_id = :vertical_id';
            $params['vertical_id'] = (int) $filters['vertical_id'];
        }
        if (!empty($filters['service_id'])) {
            $clauses[] = 'l.service_id = :service_id';
            $params['service_id'] = (int) $filters['service_id'];
        }
        if (!empty($filters['industry'])) {
            $clauses[] = 'l.industry = :industry';
            $params['industry'] = (string) $filters['industry'];
        }
        if (!empty($filters['created_from'])) {
            $clauses[] = 'l.created_at >= :created_from';
            $params['created_from'] = $filters['created_from'] . ' 00:00:00';
        }
        if (!empty($filters['created_to'])) {
            $clauses[] = 'l.created_at <= :created_to';
            $params['created_to'] = $filters['created_to'] . ' 23:59:59';
        }
        // Restricting by email-sent date naturally zeroes out rows with no
        // (or no matching) email_sent_at -- expected when this filter is
        // actively narrowing to "emails sent in this window", not a bug.
        if (!empty($filters['email_sent_from'])) {
            $clauses[] = 'a.email_sent_at >= :email_sent_from';
            $params['email_sent_from'] = $filters['email_sent_from'];
        }
        if (!empty($filters['email_sent_to'])) {
            $clauses[] = 'a.email_sent_at <= :email_sent_to';
            $params['email_sent_to'] = $filters['email_sent_to'];
        }

        return [$clauses, $params];
    }

    /** Whitelisted SQL fragments only -- $groupBy is never interpolated directly. */
    private static function groupExpr(string $groupBy): string
    {
        return match ($groupBy) {
            'campaign' => "COALESCE(c.name, '(Unassigned)')",
            'vertical' => "COALESCE(v.label, '(none)')",
            'service' => "COALESCE(s.label, '(none)')",
            'country_group' => "COALESCE(cg.label, '(none)')",
            default => "COALESCE(NULLIF(l.company_country, ''), 'NA')",
        };
    }
}
