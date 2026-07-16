<?php

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
                  ib.filename AS imported_filename, ib.started_at AS imported_at, iu.name AS imported_by_name,
                  (SELECT GROUP_CONCAT(c.name SEPARATOR ', ')
                     FROM lead_campaign_assignments a
                     JOIN campaigns c ON c.id = a.campaign_id
                    WHERE a.lead_id = l.id) AS used_in_campaigns,
                  (SELECT sd.reason FROM suppressed_domains sd
                    WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1)) AS suppressed_reason
                FROM leads l
                LEFT JOIN verticals v ON v.id = l.vertical_id
                LEFT JOIN services s ON s.id = l.service_id
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
        $exact = static function (string $col, string $value) use (&$clauses, &$params): void {
            $clauses[] = "l.{$col} = :" . $col;
            $params[$col] = $value;
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
            $like('title', $filters['title']);
        }
        if (!empty($filters['seniority'])) {
            $exact('seniority', $filters['seniority']);
        }
        if (!empty($filters['departments'])) {
            $like('departments', $filters['departments']);
        }
        if (!empty($filters['industry'])) {
            $exact('industry', $filters['industry']);
        }
        if (!empty($filters['country'])) {
            $exact('country', $filters['country']);
        }
        if (!empty($filters['employee_count'])) {
            $exact('employee_count', $filters['employee_count']);
        }
        if (!empty($filters['vertical_id'])) {
            $clauses[] = 'l.vertical_id = :vertical_id';
            $params['vertical_id'] = (int) $filters['vertical_id'];
        }
        if (!empty($filters['imported_by'])) {
            $clauses[] = 'EXISTS (SELECT 1 FROM import_batches ib2 WHERE ib2.id = l.last_import_batch_id AND ib2.uploaded_by = :imported_by)';
            $params['imported_by'] = (int) $filters['imported_by'];
        }
        if (!empty($filters['service_id'])) {
            $clauses[] = 'l.service_id = :service_id';
            $params['service_id'] = (int) $filters['service_id'];
        }
        if (!empty($filters['campaign_id'])) {
            $campaignId = (int) $filters['campaign_id'];
            if (!empty($filters['hide_used_in_campaign'])) {
                $clauses[] = 'NOT EXISTS (SELECT 1 FROM lead_campaign_assignments a WHERE a.lead_id = l.id AND a.campaign_id = :hide_campaign_id)';
                $params['hide_campaign_id'] = $campaignId;
            }
        }

        if (empty($filters['show_suppressed'])) {
            $clauses[] = "NOT EXISTS (SELECT 1 FROM suppressed_domains sd WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1))";
        }

        $clauses[] = 'l.deleted_at IS NULL';

        $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';
        return [$where, $params];
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

    public static function distinctValues(PDO $db, string $column): array
    {
        $allowed = ['seniority', 'industry', 'country', 'employee_count', 'company_country'];
        if (!in_array($column, $allowed, true)) {
            throw new InvalidArgumentException("Column not filterable: {$column}");
        }
        $stmt = $db->query("SELECT DISTINCT {$column} FROM leads WHERE {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column}");
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
     * Active rows from a lookup table (verticals/services), for filter
     * dropdowns and inline-edit selects. Table name is never user input.
     */
    public static function activeLookupOptions(PDO $db, string $table): array
    {
        if (!in_array($table, ['verticals', 'services'], true)) {
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
