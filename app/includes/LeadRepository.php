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
     * @param array<string,mixed> $filters q, company, title, seniority,
     *   departments, industry, country, employee_count, campaign_id,
     *   hide_used_in_campaign (bool)
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

        $sql = "SELECT l.*,
                  (SELECT GROUP_CONCAT(c.name SEPARATOR ', ')
                     FROM lead_campaign_assignments a
                     JOIN campaigns c ON c.id = a.campaign_id
                    WHERE a.lead_id = l.id) AS used_in_campaigns
                FROM leads l
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
        if (!empty($filters['campaign_id'])) {
            $campaignId = (int) $filters['campaign_id'];
            if (!empty($filters['hide_used_in_campaign'])) {
                $clauses[] = 'NOT EXISTS (SELECT 1 FROM lead_campaign_assignments a WHERE a.lead_id = l.id AND a.campaign_id = :hide_campaign_id)';
                $params['hide_campaign_id'] = $campaignId;
            }
        }

        $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';
        return [$where, $params];
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
