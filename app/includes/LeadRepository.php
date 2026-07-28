<?php

require_once __DIR__ . '/WaveAssigner.php';

/**
 * Server-side filter/search/pagination for the leads dashboard. All
 * filter values are bound via PDO placeholders -- never string-interpolated
 * into SQL -- and only columns from FILTERABLE_COLUMNS below are ever
 * used to build a WHERE clause.
 */
class LeadRepository
{
    private const PER_PAGE = 50;

    /**
     * @param array<string,mixed> $filters q, company, domain, title, seniority,
     *   departments, industry, country, employee_count, vertical_id, service_id,
     *   imported_by (user id), campaign_id, hide_used_in_campaign (bool),
     *   show_suppressed (bool -- by default, leads on a suppressed domain
     *   are excluded entirely)
     * @return array{rows: array<int,array>, total: int, page: int, perPage: int, totalPages: int}
     */
    public static function search(PDO $db, array $filters, int $page = 1): array
    {
        $page = max(1, $page);
        $perPage = self::PER_PAGE;

        [$where, $params] = self::buildWhere($filters);

        $countSql = "SELECT COUNT(*) FROM leads l {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT l.*, v.code AS vertical_code, v.label AS vertical_label,
                  s.code AS service_code, s.label AS service_label,
                  rg.label AS role_group_label,
                  cg.label AS country_group_label,
                  ib.filename AS imported_filename, ib.started_at AS imported_at, iu.name AS imported_by_name,
                  (SELECT GROUP_CONCAT(c.name SEPARATOR ', ')
                     FROM lead_campaign_assignments a
                     JOIN campaigns c ON c.id = a.campaign_id
                    WHERE a.lead_id = l.id) AS used_in_campaigns,
                  (SELECT sd.reason FROM suppressed_domains sd
                    WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1)) AS suppressed_reason,
                  -- Which campaign(s) a *different* persona at this lead's domain is
                  -- currently pending in -- shares WaveAssigner::PENDING_ASSIGNMENT_SQL
                  -- so the two never drift apart.
                  (SELECT GROUP_CONCAT(DISTINCT c2.name SEPARATOR ', ')
                     FROM lead_campaign_assignments a2
                     JOIN leads l2 ON l2.id = a2.lead_id
                     JOIN campaigns c2 ON c2.id = a2.campaign_id
                    WHERE l2.deleted_at IS NULL AND a2.lead_id != l.id
                      AND SUBSTRING_INDEX(l2.email, '@', -1) = SUBSTRING_INDEX(l.email, '@', -1)
                      AND " . WaveAssigner::PENDING_ASSIGNMENT_SQL . "
                     ) AS pending_elsewhere_campaigns,
                  -- Which campaign(s) a *different* persona at this lead's domain is
                  -- in at all, any status (resolved or not) -- broader than
                  -- pending_elsewhere_campaigns above; a \"backup contact at an
                  -- account already in the pipeline\" indicator.
                  (SELECT GROUP_CONCAT(DISTINCT c3.name SEPARATOR ', ')
                     FROM lead_campaign_assignments a3
                     JOIN leads l3 ON l3.id = a3.lead_id
                     JOIN campaigns c3 ON c3.id = a3.campaign_id
                    WHERE l3.deleted_at IS NULL AND a3.lead_id != l.id
                      AND SUBSTRING_INDEX(l3.email, '@', -1) = SUBSTRING_INDEX(l.email, '@', -1)
                     ) AS account_used_elsewhere_campaigns
                FROM leads l
                LEFT JOIN verticals v ON v.id = l.vertical_id
                LEFT JOIN services s ON s.id = l.service_id
                LEFT JOIN role_groups rg ON rg.id = l.role_group_id
                LEFT JOIN country_groups cg ON cg.id = l.country_group_id
                LEFT JOIN import_batches ib ON ib.id = l.last_import_batch_id
                LEFT JOIN users iu ON iu.id = ib.uploaded_by
                {$where}
                ORDER BY l.id DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * @return array{0:string,1:array<string,mixed>} [WHERE clause string, bound params]
     */
    private static function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        $like = static function (string $col, string $value) use (&$clauses, &$params): void {
            $clauses[] = "l.{$col} LIKE :" . $col;
            $params[$col] = '%' . $value . '%';
        };
        // Multi-select filter: matches any of the given exact values (checkbox
        // dropdown filters on the dashboard / campaign lead-selection pages).
        // Also accepts a single scalar value for backward compatibility.
        $in = static function (string $col, array $values) use (&$clauses, &$params): void {
            $values = array_values(array_unique(array_filter(
                array_map(static fn($v) => trim((string) $v), $values),
                static fn($v) => $v !== ''
            )));
            if (!$values) {
                return;
            }
            $placeholders = [];
            foreach ($values as $i => $v) {
                $key = $col . '_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $v;
            }
            $clauses[] = "l.{$col} IN (" . implode(',', $placeholders) . ')';
        };

        if (!empty($filters['q'])) {
            $clauses[] = '(l.na_company_name LIKE :q1 OR l.title LIKE :q2 OR l.products LIKE :q3 OR l.keywords LIKE :q4)';
            $q = '%' . $filters['q'] . '%';
            $params['q1'] = $q;
            $params['q2'] = $q;
            $params['q3'] = $q;
            $params['q4'] = $q;
        }
        if (!empty($filters['company'])) {
            $like('na_company_name', $filters['company']);
        }
        if (!empty($filters['domain'])) {
            $clauses[] = "SUBSTRING_INDEX(l.email, '@', -1) LIKE :domain";
            $params['domain'] = '%' . $filters['domain'] . '%';
        }
        if (!empty($filters['title'])) {
            $in('title', (array) $filters['title']);
        }
        if (!empty($filters['seniority'])) {
            $in('seniority', (array) $filters['seniority']);
        }
        if (!empty($filters['departments'])) {
            $in('departments', (array) $filters['departments']);
        }
        if (!empty($filters['industry'])) {
            $in('industry', (array) $filters['industry']);
        }
        if (!empty($filters['country'])) {
            $in('country', (array) $filters['country']);
        }
        if (!empty($filters['employee_count'])) {
            $in('employee_count', (array) $filters['employee_count']);
        }
        if (!empty($filters['employee_count_range'])) {
            $in('employee_count_range', (array) $filters['employee_count_range']);
        }
        // 'none' is a sentinel (never a real id) for "no vertical/service
        // set" -- matches AnalyticsRepository::groupExpr()'s '(none)'
        // group, so an Analytics drill-through link can reproduce that
        // row's leads exactly.
        if (($filters['vertical_id'] ?? '') !== '') {
            if ($filters['vertical_id'] === 'none') {
                $clauses[] = 'l.vertical_id IS NULL';
            } else {
                $clauses[] = 'l.vertical_id = :vertical_id';
                $params['vertical_id'] = (int) $filters['vertical_id'];
            }
        }
        if (!empty($filters['imported_by'])) {
            $clauses[] = 'EXISTS (SELECT 1 FROM import_batches ib2 WHERE ib2.id = l.last_import_batch_id AND ib2.uploaded_by = :imported_by)';
            $params['imported_by'] = (int) $filters['imported_by'];
        }
        if (($filters['service_id'] ?? '') !== '') {
            if ($filters['service_id'] === 'none') {
                $clauses[] = 'l.service_id IS NULL';
            } else {
                $clauses[] = 'l.service_id = :service_id';
                $params['service_id'] = (int) $filters['service_id'];
            }
        }
        if (($filters['role_group_id'] ?? '') !== '') {
            if ($filters['role_group_id'] === 'none') {
                $clauses[] = 'l.role_group_id IS NULL';
            } else {
                $clauses[] = 'l.role_group_id = :role_group_id';
                $params['role_group_id'] = (int) $filters['role_group_id'];
            }
        }
        if (($filters['country_group_id'] ?? '') !== '') {
            if ($filters['country_group_id'] === 'none') {
                $clauses[] = 'l.country_group_id IS NULL';
            } else {
                $clauses[] = 'l.country_group_id = :country_group_id';
                $params['country_group_id'] = (int) $filters['country_group_id'];
            }
        }
        if (!empty($filters['campaign_id'])) {
            $campaignId = (int) $filters['campaign_id'];
            if (!empty($filters['hide_used_in_campaign'])) {
                $clauses[] = 'NOT EXISTS (SELECT 1 FROM lead_campaign_assignments a WHERE a.lead_id = l.id AND a.campaign_id = :hide_campaign_id)';
                $params['hide_campaign_id'] = $campaignId;
            }
        }
        // Company Country (distinct from the personal-contact 'country'
        // filter above) -- 'NA' is the same blank/unknown sentinel
        // AnalyticsRepository::groupExpr() displays for an empty value,
        // so a drill-through link can pass a row's group value straight
        // through unchanged.
        if (!empty($filters['company_country'])) {
            $values = array_values(array_unique(array_filter(
                array_map(static fn($v) => trim((string) $v), (array) $filters['company_country']),
                static fn($v) => $v !== ''
            )));
            if ($values) {
                $subClauses = [];
                $realValues = [];
                foreach ($values as $v) {
                    if ($v === 'NA') {
                        $subClauses[] = "(l.company_country IS NULL OR l.company_country = '')";
                    } else {
                        $realValues[] = $v;
                    }
                }
                if ($realValues) {
                    $placeholders = [];
                    foreach ($realValues as $i => $v) {
                        $key = 'company_country_' . $i;
                        $placeholders[] = ':' . $key;
                        $params[$key] = $v;
                    }
                    $subClauses[] = 'l.company_country IN (' . implode(',', $placeholders) . ')';
                }
                $clauses[] = '(' . implode(' OR ', $subClauses) . ')';
            }
        }
        // 'assigned_campaign_id': positive "show only leads currently
        // assigned to campaign X" filter, distinct from campaign_id above
        // (which only ever *excludes*, for the wave-safety candidate-
        // preview screens) -- 'none' means "no campaign assignment at
        // all", matching the By Campaign breakdown's "(Unassigned)" row.
        // Exists mainly so an Analytics drill-through link can reproduce
        // a By Campaign row's leads exactly.
        if (($filters['assigned_campaign_id'] ?? '') !== '') {
            if ($filters['assigned_campaign_id'] === 'none') {
                $clauses[] = 'NOT EXISTS (SELECT 1 FROM lead_campaign_assignments a WHERE a.lead_id = l.id)';
            } else {
                // Latest-assignment-only, same reasoning as imported/
                // email_sent below -- matches which single campaign
                // AnalyticsRepository's By Campaign breakdown attributes
                // this lead to.
                $clauses[] = "EXISTS (SELECT 1 FROM lead_campaign_assignments a WHERE a.lead_id = l.id AND a.campaign_id = :assigned_campaign_id AND a.id = (SELECT MAX(a2.id) FROM lead_campaign_assignments a2 WHERE a2.lead_id = l.id))";
                $params['assigned_campaign_id'] = (int) $filters['assigned_campaign_id'];
            }
        }
        // 'imported'/'email_sent' both check only the lead's *latest*
        // assignment row (highest id), not "any assignment ever" -- a
        // lead can have more than one historical row (e.g. re-assigned
        // after being removed from an earlier campaign), and checking
        // "any" would wrongly count a lead as imported/emailed off a
        // stale row even though its current assignment says otherwise.
        // Mirrors AnalyticsRepository::ASSIGNMENT_JOIN's same "latest
        // assignment per lead" dedup, so drill-through links from
        // Analytics reproduce its counts exactly.
        // 'imported' means status IN ('exported', 'pushed') -- a lead
        // counts as imported whether it left the system via a live
        // Saleshandy API push (campaign_saleshandy_push.php, status =
        // 'pushed') or a CSV export / manual "Mark as Imported" action
        // (leads_export_csv.php, campaign_assignment_update.php's
        // mark_imported action, CampaignHistoryImporter -- all set status
        // = 'exported'). Must match campaign_leads.php's own "Imported"
        // column definition exactly (see its imported_yes/imported_no
        // stats query) -- previously this checked 'pushed' only, so a
        // lead Campaign Leads called "Imported: Yes" could show up as
        // "No" here and in every Analytics count, which is exactly the
        // kind of cross-page inconsistency this filter must not have.
        $latestAssignment = '(SELECT MAX(a2.id) FROM lead_campaign_assignments a2 WHERE a2.lead_id = l.id)';
        if (($filters['imported'] ?? '') === '1') {
            $clauses[] = "EXISTS (SELECT 1 FROM lead_campaign_assignments a WHERE a.lead_id = l.id AND a.status IN ('exported', 'pushed') AND a.id = {$latestAssignment})";
        } elseif (($filters['imported'] ?? '') === '0') {
            $clauses[] = "NOT EXISTS (SELECT 1 FROM lead_campaign_assignments a WHERE a.lead_id = l.id AND a.status IN ('exported', 'pushed') AND a.id = {$latestAssignment})";
        }
        if (($filters['email_sent'] ?? '') === '1') {
            $clauses[] = "EXISTS (SELECT 1 FROM lead_campaign_assignments a WHERE a.lead_id = l.id AND a.email_sent = 1 AND a.id = {$latestAssignment})";
        } elseif (($filters['email_sent'] ?? '') === '0') {
            $clauses[] = "NOT EXISTS (SELECT 1 FROM lead_campaign_assignments a WHERE a.lead_id = l.id AND a.email_sent = 1 AND a.id = {$latestAssignment})";
        }

        if (empty($filters['show_suppressed'])) {
            $clauses[] = "NOT EXISTS (SELECT 1 FROM suppressed_domains sd WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1))";
        }

        // 'pending_elsewhere': '1' shows only leads whose account has a
        // *different* persona currently pending in another campaign (see
        // WaveAssigner::PENDING_ASSIGNMENT_SQL) -- '0' hides them, useful
        // on the campaign lead-selection screen to preview only what would
        // actually be assignable right now.
        if (($filters['pending_elsewhere'] ?? '') === '1') {
            $clauses[] = self::pendingElsewhereExistsClause();
        } elseif (($filters['pending_elsewhere'] ?? '') === '0') {
            $clauses[] = 'NOT ' . self::pendingElsewhereExistsClause();
        }

        // 'account_used_elsewhere': '1' shows only leads whose account has a
        // *different* persona in some campaign already, any status --
        // broader than pending_elsewhere (includes already-resolved
        // sends too), for finding backup contacts at accounts already in
        // the pipeline rather than only ones currently blocked.
        if (($filters['account_used_elsewhere'] ?? '') === '1') {
            $clauses[] = self::accountUsedElsewhereExistsClause();
        } elseif (($filters['account_used_elsewhere'] ?? '') === '0') {
            $clauses[] = 'NOT ' . self::accountUsedElsewhereExistsClause();
        }

        $clauses[] = 'l.deleted_at IS NULL';

        $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';
        return [$where, $params];
    }

    private static function pendingElsewhereExistsClause(): string
    {
        return "EXISTS (
            SELECT 1 FROM lead_campaign_assignments a2
            JOIN leads l2 ON l2.id = a2.lead_id
            WHERE l2.deleted_at IS NULL AND a2.lead_id != l.id
              AND SUBSTRING_INDEX(l2.email, '@', -1) = SUBSTRING_INDEX(l.email, '@', -1)
              AND " . WaveAssigner::PENDING_ASSIGNMENT_SQL . '
        )';
    }

    private static function accountUsedElsewhereExistsClause(): string
    {
        return "EXISTS (
            SELECT 1 FROM lead_campaign_assignments a3
            JOIN leads l3 ON l3.id = a3.lead_id
            WHERE l3.deleted_at IS NULL AND a3.lead_id != l.id
              AND SUBSTRING_INDEX(l3.email, '@', -1) = SUBSTRING_INDEX(l.email, '@', -1)
        )";
    }

    /**
     * Titles present among leads matching the given filters, with counts --
     * used for the campaign page's "pick allowed titles" manual persona
     * checklist. Ordered most-common first, capped to keep the checklist short.
     */
    public static function distinctTitlesForFilter(PDO $db, array $filters, int $limit = 40): array
    {
        [$where, $params] = self::buildWhere($filters);
        $titleClause = "l.title IS NOT NULL AND l.title <> ''";
        $where = $where === '' ? "WHERE {$titleClause}" : "{$where} AND {$titleClause}";

        $sql = "SELECT l.title, COUNT(*) AS lead_count FROM leads l {$where} GROUP BY l.title ORDER BY lead_count DESC, l.title LIMIT {$limit}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Distinct email-domain ("company") count among leads matching the
     * given filters -- shown on the campaign selection page alongside the
     * lead count, since wave-1 assigns one contact per domain.
     */
    public static function domainCountForFilter(PDO $db, array $filters): int
    {
        [$where, $params] = self::buildWhere($filters);
        $stmt = $db->prepare("SELECT COUNT(DISTINCT SUBSTRING_INDEX(l.email, '@', -1)) FROM leads l {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param int $limit caps the option list for high-cardinality free-text
     *   columns (e.g. title) used in checkbox-dropdown filters -- 0 means
     *   no cap.
     */
    public static function distinctValues(PDO $db, string $column, int $limit = 0): array
    {
        $allowed = ['seniority', 'industry', 'country', 'employee_count', 'employee_count_range', 'company_country', 'title', 'departments'];
        if (!in_array($column, $allowed, true)) {
            throw new InvalidArgumentException("Column not filterable: {$column}");
        }
        $limitSql = $limit > 0 ? " LIMIT {$limit}" : '';
        $stmt = $db->query("SELECT DISTINCT {$column} FROM leads WHERE {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column}{$limitSql}");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * All lead IDs matching the given filters, ignoring pagination. Used
     * for "assign/export everything matching this filter" bulk actions.
     */
    public static function matchingIds(PDO $db, array $filters): array
    {
        [$where, $params] = self::buildWhere($filters);
        $stmt = $db->prepare("SELECT l.id FROM leads l {$where}");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Just the count for the given filters -- COUNT(*) instead of
     * fetching every matching row's id like matchingIds() does, for
     * callers (e.g. icp_segments.php's "N leads eligible now") that only
     * need the number, not the actual IDs.
     */
    public static function matchingCount(PDO $db, array $filters): int
    {
        [$where, $params] = self::buildWhere($filters);
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads l {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Active rows from a lookup table (verticals/services/role_groups), for
     * filter dropdowns and inline-edit selects. Table name is never user
     * input.
     */
    public static function activeLookupOptions(PDO $db, string $table): array
    {
        if (!in_array($table, ['verticals', 'services', 'role_groups', 'country_groups'], true)) {
            throw new InvalidArgumentException("Not a lookup table: {$table}");
        }
        return $db->query("SELECT id, code, label FROM {$table} WHERE is_active = 1 ORDER BY label")->fetchAll();
    }

    public static function findByIds(PDO $db, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM leads WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }
}
