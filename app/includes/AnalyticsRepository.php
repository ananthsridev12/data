<?php

/**
 * Country/campaign/vertical/service/industry pivot reporting for the
 * Analytics page -- "how many prospects, how many pushed to Saleshandy,
 * how many actually emailed, broken down by X" across four fixed slices
 * of the lead base (all leads, leads queued but not yet pushed, leads
 * already pushed, leads not yet emailed).
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
        'industry' => 'Industry',
    ];

    public const SLICES = [
        'all' => 'All Data',
        'not_imported' => 'Saleshandy - Not Imported',
        'imported' => 'Saleshandy - Imported',
        'emails_not_sent' => 'Emails Not Sent',
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
     * @param array<string,mixed> $filters campaign_id, vertical_id, service_id, industry,
     *   created_from, created_to (leads.created_at date range, Y-m-d),
     *   email_sent_from, email_sent_to (assignment email_sent_at date range, Y-m-d)
     * @return array<string,array{label:string, rows: array<int,array{grp:string,prospects:int,imported:int,email_sent:int}>, total: array{prospects:int,imported:int,email_sent:int}}>
     */
    public static function countryPivot(PDO $db, string $groupBy, array $filters): array
    {
        $groupExpr = self::groupExpr($groupBy);
        [$baseClauses, $params] = self::buildBaseClauses($filters);

        $results = [];
        foreach (self::SLICES as $slice => $label) {
            $clauses = $baseClauses;
            $sliceCondition = self::sliceCondition($slice);
            if ($sliceCondition !== null) {
                $clauses[] = $sliceCondition;
            }
            $where = 'WHERE ' . implode(' AND ', $clauses);

            $sql = "SELECT {$groupExpr} AS grp,
                       COUNT(*) AS prospects,
                       SUM(CASE WHEN a.status = 'pushed' THEN 1 ELSE 0 END) AS imported,
                       SUM(CASE WHEN a.email_sent = 1 THEN 1 ELSE 0 END) AS email_sent
                     FROM leads l "
                    . self::ASSIGNMENT_JOIN .
                    " {$where}
                     GROUP BY grp
                     ORDER BY grp";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $total = ['prospects' => 0, 'imported' => 0, 'email_sent' => 0];
            foreach ($rows as $r) {
                $total['prospects'] += (int) $r['prospects'];
                $total['imported'] += (int) $r['imported'];
                $total['email_sent'] += (int) $r['email_sent'];
            }
            $results[$slice] = ['label' => $label, 'rows' => $rows, 'total' => $total];
        }
        return $results;
    }

    /**
     * Per-campaign report: Vertical / Service Pitched / Prospects / First
     * Email Date -- the "S No, Campaign ID, Vertical, Service Pitched,
     * Prospects, First Email Date" summary table.
     *
     * @return array<int,array{id:int,name:string,vertical_label:?string,service_label:?string,prospects:int,first_email_date:?string}>
     */
    public static function campaignSummary(PDO $db): array
    {
        return $db->query(
            "SELECT c.id, c.name, v.label AS vertical_label, s.label AS service_label,
               (SELECT COUNT(*) FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id
                 WHERE a.campaign_id = c.id AND l.deleted_at IS NULL) AS prospects,
               (SELECT MIN(a.email_sent_at) FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id
                 WHERE a.campaign_id = c.id AND l.deleted_at IS NULL AND a.email_sent = 1) AS first_email_date
             FROM campaigns c
             LEFT JOIN verticals v ON v.id = c.vertical_id
             LEFT JOIN services s ON s.id = c.service_id
             ORDER BY c.created_at DESC"
        )->fetchAll();
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

    private static function sliceCondition(string $slice): ?string
    {
        return match ($slice) {
            'not_imported' => "a.id IS NOT NULL AND a.status != 'pushed'",
            'imported' => "a.status = 'pushed'",
            'emails_not_sent' => '(a.email_sent IS NULL OR a.email_sent = 0)',
            default => null,
        };
    }

    /** Whitelisted SQL fragments only -- $groupBy is never interpolated directly. */
    private static function groupExpr(string $groupBy): string
    {
        return match ($groupBy) {
            'campaign' => "COALESCE(c.name, '(Unassigned)')",
            'vertical' => "COALESCE(v.label, '(none)')",
            'service' => "COALESCE(s.label, '(none)')",
            'industry' => "COALESCE(NULLIF(l.industry, ''), '(none)')",
            default => "COALESCE(NULLIF(l.company_country, ''), 'NA')",
        };
    }
}
