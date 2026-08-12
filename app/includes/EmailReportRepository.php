<?php

require_once __DIR__ . '/ScopeFilter.php';

/**
 * A user's saved "email report" definitions -- which campaigns and which
 * metric columns to include -- plus the query that actually computes those
 * metrics and the HTML table used both for on-page preview and the sent
 * email (same markup either way, so preview never lies about what gets
 * sent). Visibility follows the same row-scoping rule as
 * FollowUpTaskRepository::loadVisible() (Admin sees the whole company,
 * Team Lead their team's, Member only their own), scoped by created_by
 * instead of assigned_to.
 *
 * Unlike ReportsRepository::sequences(), this always reports on every
 * assignment ever made to a selected campaign (not just ones with
 * email_sent = 1) so "Prospects" can mean the full target list, with
 * "Contacted" as the narrower already-emailed subset -- see METRICS.
 */
class EmailReportRepository
{
    /** key => column label, in the order checkboxes/columns are offered. */
    public const METRICS = [
        'prospects' => 'Prospects',
        'contacted' => 'Contacted',
        'coverage_pct' => 'Coverage %',
        'emails_sent' => 'Emails sent',
        'opens' => 'Opens',
        'open_rate' => 'Open rate',
        'bounces' => 'Bounces',
        'bounce_rate' => 'Bounce rate',
        'replies' => 'Replies',
        'reply_rate' => 'Reply rate',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function listForUser(PDO $db, Scope $scope): array
    {
        $clauses = [];
        $params = [];
        ScopeFilter::apply($clauses, $params, $scope, 'er');
        ScopeFilter::applyOwnerScope($clauses, $params, $scope, $db, 'er', 'created_by');

        $stmt = $db->prepare(
            "SELECT er.*, u.name AS created_by_name FROM email_reports er
               LEFT JOIN users u ON u.id = er.created_by
              WHERE " . implode(' AND ', $clauses) . '
              ORDER BY er.name'
        );
        $stmt->execute($params);
        return array_map(self::decodeRow(...), $stmt->fetchAll());
    }

    public static function loadVisible(PDO $db, Scope $scope, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM email_reports WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $scope->companyId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (!$scope->isAdmin()) {
            $visibleOwnerIds = $scope->visibleOwnerIds($db);
            if ($visibleOwnerIds !== null && !in_array((int) $row['created_by'], $visibleOwnerIds, true)) {
                return null;
            }
        }
        return self::decodeRow($row);
    }

    /**
     * @param int[] $campaignIds
     * @param string[] $metrics must be keys of self::METRICS
     */
    public static function create(PDO $db, Scope $scope, string $name, array $campaignIds, array $metrics): int
    {
        $stmt = $db->prepare(
            'INSERT INTO email_reports (company_id, created_by, name, campaign_ids, metrics) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $scope->companyId,
            $scope->userId,
            $name,
            json_encode(array_values(array_map('intval', $campaignIds))),
            json_encode(array_values($metrics)),
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * @param int[] $campaignIds
     * @param string[] $metrics must be keys of self::METRICS
     */
    public static function update(PDO $db, Scope $scope, int $id, string $name, array $campaignIds, array $metrics): bool
    {
        if (self::loadVisible($db, $scope, $id) === null) {
            return false;
        }
        $stmt = $db->prepare('UPDATE email_reports SET name = ?, campaign_ids = ?, metrics = ? WHERE id = ? AND company_id = ?');
        $stmt->execute([
            $name,
            json_encode(array_values(array_map('intval', $campaignIds))),
            json_encode(array_values($metrics)),
            $id,
            $scope->companyId,
        ]);
        return true;
    }

    public static function delete(PDO $db, Scope $scope, int $id): bool
    {
        if (self::loadVisible($db, $scope, $id) === null) {
            return false;
        }
        $db->prepare('DELETE FROM email_reports WHERE id = ? AND company_id = ?')->execute([$id, $scope->companyId]);
        return true;
    }

    private static function decodeRow(array $row): array
    {
        $row['campaign_ids'] = array_map('intval', json_decode((string) $row['campaign_ids'], true) ?: []);
        $row['metrics'] = json_decode((string) $row['metrics'], true) ?: [];
        return $row;
    }

    /**
     * Per-campaign metrics for exactly the given campaign ids -- silently
     * drops any id outside $scope's visibility (ScopeFilter on 'c'), so a
     * tampered campaign_ids list can never pull another team's numbers.
     * "Emails sent" prefers the real per-step total from
     * saleshandy_send_events, falling back to the Contacted count for a
     * campaign whose events haven't backfilled yet -- same reasoning as
     * ReportsRepository::sequences().
     *
     * @param int[] $campaignIds
     * @return array<int,array{campaign_id:int,name:string,vertical_label:?string,prospects:int,contacted:int,coverage_pct:float,emails_sent:int,opens:int,open_rate:float,bounces:int,bounce_rate:float,replies:int,reply_rate:float}>
     */
    public static function campaignMetrics(PDO $db, Scope $scope, array $campaignIds): array
    {
        $campaignIds = array_values(array_unique(array_map('intval', $campaignIds)));
        if (!$campaignIds) {
            return [];
        }

        $params = [];
        $idPlaceholders = [];
        foreach ($campaignIds as $i => $id) {
            $key = "cid_{$i}";
            $idPlaceholders[] = ":{$key}";
            $params[$key] = $id;
        }

        $scopeClauses = [];
        ScopeFilter::apply($scopeClauses, $params, $scope, 'c');
        ScopeFilter::applyOwnerScope($scopeClauses, $params, $scope, $db, 'c', 'saleshandy_account_owner_id', 'scope_campaign_owner');
        $scopeWhere = $scopeClauses ? (' AND ' . implode(' AND ', $scopeClauses)) : '';

        $sql = "SELECT c.id AS campaign_id, c.name, v.label AS vertical_label,
                   COUNT(*) AS prospects,
                   SUM(CASE WHEN a.email_sent = 1 THEN 1 ELSE 0 END) AS contacted,
                   SUM(CASE WHEN a.email_sent = 1 AND a.open_count > 0 THEN 1 ELSE 0 END) AS opens,
                   SUM(CASE WHEN a.email_sent = 1 AND a.delivery_status IN ('" . implode("','", DELIVERY_STATUS_BOUNCE_VALUES) . "') THEN 1 ELSE 0 END) AS bounces,
                   SUM(CASE WHEN a.email_sent = 1 AND a.delivery_status = 'Replied' THEN 1 ELSE 0 END) AS replies,
                   COALESCE(MAX(ev.emails_sent), SUM(CASE WHEN a.email_sent = 1 THEN 1 ELSE 0 END)) AS emails_sent
                 FROM lead_campaign_assignments a
                 JOIN campaigns c ON c.id = a.campaign_id
                 JOIN leads l ON l.id = a.lead_id
                 LEFT JOIN verticals v ON v.id = c.vertical_id
                 LEFT JOIN (
                     SELECT campaign_id, COUNT(*) AS emails_sent
                       FROM saleshandy_send_events
                      GROUP BY campaign_id
                 ) ev ON ev.campaign_id = c.id
                 WHERE l.deleted_at IS NULL AND c.id IN (" . implode(',', $idPlaceholders) . "){$scopeWhere}
                 GROUP BY c.id, c.name, v.label
                 ORDER BY c.name";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $prospects = (int) $r['prospects'];
            $contacted = (int) $r['contacted'];
            $emailsSent = (int) $r['emails_sent'];
            $opens = (int) $r['opens'];
            $bounces = (int) $r['bounces'];
            $replies = (int) $r['replies'];

            $r['campaign_id'] = (int) $r['campaign_id'];
            $r['prospects'] = $prospects;
            $r['contacted'] = $contacted;
            $r['coverage_pct'] = $prospects > 0 ? $contacted / $prospects : 0.0;
            $r['emails_sent'] = $emailsSent;
            $r['opens'] = $opens;
            $r['open_rate'] = $emailsSent > 0 ? $opens / $emailsSent : 0.0;
            $r['bounces'] = $bounces;
            $r['bounce_rate'] = $emailsSent > 0 ? $bounces / $emailsSent : 0.0;
            $r['replies'] = $replies;
            $r['reply_rate'] = $emailsSent > 0 ? $replies / $emailsSent : 0.0;
        }
        unset($r);

        return $rows;
    }

    /**
     * Renders $rows (from campaignMetrics()) as a self-contained HTML
     * table restricted to $metricKeys, for both the on-page preview and
     * the actual email body -- identical markup either way.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string[] $metricKeys must be keys of self::METRICS
     */
    public static function composeHtml(array $rows, array $metricKeys, string $title): string
    {
        $metricKeys = array_values(array_intersect($metricKeys, array_keys(self::METRICS)));
        $pctKeys = ['coverage_pct', 'open_rate', 'bounce_rate', 'reply_rate'];
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<div style="font-family: Arial, Helvetica, sans-serif;">';
        $html .= '<h2 style="margin:0 0 12px;">' . $esc($title) . '</h2>';
        $html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#ddd;font-size:13px;">';
        $html .= '<thead><tr style="background:#f2f2f2;"><th align="left">Sequence</th><th align="left">Vertical</th>';
        foreach ($metricKeys as $key) {
            $html .= '<th align="right">' . $esc(self::METRICS[$key]) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if (!$rows) {
            $html .= '<tr><td colspan="' . (2 + count($metricKeys)) . '" align="center" style="color:#888;">No data for the selected campaigns.</td></tr>';
        }
        foreach ($rows as $row) {
            $html .= '<tr><td>' . $esc((string) $row['name']) . '</td><td>' . $esc((string) ($row['vertical_label'] ?? '')) . '</td>';
            foreach ($metricKeys as $key) {
                $value = $row[$key] ?? 0;
                $display = in_array($key, $pctKeys, true)
                    ? number_format(((float) $value) * 100, 1) . '%'
                    : number_format((int) $value);
                $html .= '<td align="right">' . $esc($display) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }
}
