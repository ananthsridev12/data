<?php

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
    ";

    /**
     * One consolidated table for a single dimension: Prospects / Imported
     * to Saleshandy / Not Imported / Email Sent / Email Not Sent, each
     * row's Imported+Not Imported (and Email Sent+Email Not Sent) always
     * adding back up to Prospects -- "not imported"/"email not sent" both
     * mean "everyone else", not a narrower in-flight-pipeline subset, so
     * the numbers need no extra explanation.
     *
     * @param array<string,mixed> $filters campaign_id, vertical_id, service_id, industry,
     *   created_from, created_to (leads.created_at date range, Y-m-d),
     *   email_sent_from, email_sent_to (assignment email_sent_at date range, Y-m-d)
     * @return array{
     *   rows: array<int,array{grp:string,prospects:int,imported:int,not_imported:int,email_sent:int,email_not_sent:int}>,
     *   total: array{prospects:int,imported:int,not_imported:int,email_sent:int,email_not_sent:int}
     * }
     */
    public static function pivotByDimension(PDO $db, string $groupBy, array $filters): array
    {
        $groupExpr = self::groupExpr($groupBy);
        [$clauses, $params] = self::buildBaseClauses($filters);
        $where = 'WHERE ' . implode(' AND ', $clauses);

        // 'imported' = status IN ('exported', 'pushed') -- must match
        // LeadRepository::buildWhere()'s 'imported' filter exactly (see
        // its comment) so a Dashboard drill-through link reproduces the
        // exact count it was clicked from, and so this matches
        // campaign_leads.php's own "Imported" column definition.
        $sql = "SELECT {$groupExpr} AS grp,
                   COUNT(*) AS prospects,
                   SUM(CASE WHEN a.status IN ('exported', 'pushed') THEN 1 ELSE 0 END) AS imported,
                   SUM(CASE WHEN a.status IS NULL OR a.status NOT IN ('exported', 'pushed') THEN 1 ELSE 0 END) AS not_imported,
                   SUM(CASE WHEN a.email_sent = 1 THEN 1 ELSE 0 END) AS email_sent,
                   SUM(CASE WHEN a.email_sent IS NULL OR a.email_sent = 0 THEN 1 ELSE 0 END) AS email_not_sent
                 FROM leads l "
                . self::ASSIGNMENT_JOIN .
                " {$where}
                 GROUP BY grp
                 ORDER BY grp";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $total = ['prospects' => 0, 'imported' => 0, 'not_imported' => 0, 'email_sent' => 0, 'email_not_sent' => 0];
        foreach ($rows as $r) {
            $total['prospects'] += (int) $r['prospects'];
            $total['imported'] += (int) $r['imported'];
            $total['not_imported'] += (int) $r['not_imported'];
            $total['email_sent'] += (int) $r['email_sent'];
            $total['email_not_sent'] += (int) $r['email_not_sent'];
        }
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
    public static function campaignFunnel(PDO $db): array
    {
        $campaigns = $db->query(
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
             ORDER BY c.created_at DESC"
        )->fetchAll();

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
            default => "COALESCE(NULLIF(l.company_country, ''), 'NA')",
        };
    }
}
