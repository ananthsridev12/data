<?php

/**
 * Queries backing the ABM Visibility Report (public/abm_report.php) --
 * replicates the Summary funnel, Coverage-by-Vertical, and Sequences tabs
 * of a spreadsheet the user previously rebuilt by hand from Saleshandy
 * exports, now sourced live from `leads` + `lead_campaign_assignments`.
 *
 * IMPORTANT SCOPE NOTE: this only covers what's derivable from the single
 * row-per-lead-per-campaign data this app already stores (email_sent_at is
 * the lead's *first* send date, saleshandy_current_step is the *furthest*
 * step reached -- there is no per-day, per-step send-event history). That
 * means "Emails sent" and "Contacts" are the same count here (one row =
 * one lead = one campaign, not one row per individual step-send), and a
 * true Daily Activity / Weekly / per-Step breakdown (which need per-event
 * granularity) are a deliberately deferred follow-up needing a new
 * event-level table fed from SaleshandyClient::fetchSequenceActivity()'s
 * raw (pre-aggregation) rows -- not built here.
 */
class AbmVisibilityRepository
{
    private const ASSIGNMENT_JOIN = "
        LEFT JOIN (
            SELECT a1.* FROM lead_campaign_assignments a1
            INNER JOIN (SELECT lead_id, MAX(id) AS max_id FROM lead_campaign_assignments GROUP BY lead_id) latest
              ON latest.lead_id = a1.lead_id AND latest.max_id = a1.id
        ) a ON a.lead_id = l.id
    ";

    /** SQL fragment (no leading AND), true when this assignment counts as "in period" for the given filters. */
    private static function periodExpr(array $filters, array &$params): string
    {
        $clauses = ['a.email_sent = 1'];
        if (!empty($filters['date_from'])) {
            $clauses[] = 'a.email_sent_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $clauses[] = 'a.email_sent_at <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        if (!empty($filters['campaign_id'])) {
            $clauses[] = 'a.campaign_id = :campaign_id';
            $params['campaign_id'] = (int) $filters['campaign_id'];
        }
        return implode(' AND ', $clauses);
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
    public static function summary(PDO $db, array $filters): array
    {
        $params = [];
        $period = self::periodExpr($filters, $params);

        $inDatabase = (int) $db->query('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL')->fetchColumn();

        $sql = "SELECT
                   COUNT(*) AS contacted,
                   SUM(CASE WHEN a.delivery_status IS NULL OR a.delivery_status NOT IN ('" . implode("','", DELIVERY_STATUS_BOUNCE_VALUES) . "') THEN 1 ELSE 0 END) AS delivered,
                   SUM(CASE WHEN a.open_count > 0 THEN 1 ELSE 0 END) AS opened,
                   SUM(CASE WHEN a.delivery_status = 'Replied' THEN 1 ELSE 0 END) AS replied,
                   COUNT(DISTINCT a.email_sent_at) AS active_days
                 FROM leads l
                 " . self::ASSIGNMENT_JOIN . "
                 WHERE l.deleted_at IS NULL AND {$period}";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: ['contacted' => 0, 'delivered' => 0, 'opened' => 0, 'replied' => 0, 'active_days' => 0];

        $contacted = (int) $row['contacted'];
        $delivered = (int) $row['delivered'];
        $opened = (int) $row['opened'];
        $replied = (int) $row['replied'];

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
                'active_sending_days' => (int) $row['active_days'],
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
    public static function coverageByVertical(PDO $db, array $filters): array
    {
        $params = [];
        $period = self::periodExpr($filters, $params);

        $sql = "SELECT COALESCE(v.label, '(none)') AS grp,
                   COUNT(*) AS in_database,
                   SUM(CASE WHEN {$period} THEN 1 ELSE 0 END) AS contacted,
                   SUM(CASE WHEN {$period} AND a.open_count > 0 THEN 1 ELSE 0 END) AS opened
                 FROM leads l
                 " . self::ASSIGNMENT_JOIN . "
                 LEFT JOIN verticals v ON v.id = l.vertical_id
                 WHERE l.deleted_at IS NULL
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
     * One row per Saleshandy-linked campaign, sorted by volume. "Emails
     * sent" and "Contacts" are identical counts here -- see this class's
     * docblock on why a per-step breakdown isn't available yet.
     *
     * @return array<int,array{name:string,vertical_label:?string,emails_sent:int,contacts:int,opens:int,open_rate:float,bounces:int,bounce_rate:float,replies:int}>
     */
    public static function sequences(PDO $db, array $filters): array
    {
        $params = [];
        $period = self::periodExpr($filters, $params);

        $sql = "SELECT c.name, v.label AS vertical_label,
                   COUNT(*) AS emails_sent,
                   SUM(CASE WHEN a.open_count > 0 THEN 1 ELSE 0 END) AS opens,
                   SUM(CASE WHEN a.delivery_status IN ('" . implode("','", DELIVERY_STATUS_BOUNCE_VALUES) . "') THEN 1 ELSE 0 END) AS bounces,
                   SUM(CASE WHEN a.delivery_status = 'Replied' THEN 1 ELSE 0 END) AS replies
                 FROM lead_campaign_assignments a
                 JOIN campaigns c ON c.id = a.campaign_id
                 JOIN leads l ON l.id = a.lead_id
                 LEFT JOIN verticals v ON v.id = c.vertical_id
                 WHERE l.deleted_at IS NULL AND c.saleshandy_sequence_id IS NOT NULL AND {$period}
                 GROUP BY c.id, c.name, v.label
                 ORDER BY emails_sent DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $emailsSent = (int) $r['emails_sent'];
            $r['emails_sent'] = $emailsSent;
            $r['contacts'] = $emailsSent;
            $r['opens'] = (int) $r['opens'];
            $r['bounces'] = (int) $r['bounces'];
            $r['replies'] = (int) $r['replies'];
            $r['open_rate'] = $emailsSent > 0 ? $r['opens'] / $emailsSent : 0.0;
            $r['bounce_rate'] = $emailsSent > 0 ? $r['bounces'] / $emailsSent : 0.0;
        }
        unset($r);

        return $rows;
    }
}
