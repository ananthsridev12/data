<?php

require_once __DIR__ . '/ScopeFilter.php';

/**
 * "Accounts" are not a stored entity -- they're leads grouped by email
 * domain (SUBSTRING_INDEX(email, '@', -1)), the same grouping already used
 * for wave-1 sending and dashboard filtering. Since every lead's email is
 * unique (see leads.uq_leads_email) and imports upsert on that email, a
 * domain's contact list here can never contain a duplicate persona --
 * re-importing/re-adding the same person just updates their one row.
 *
 * Every method takes a required Scope, same as LeadRepository -- company
 * scope always applies, and role-based owner scope (Scope::visibleOwnerIds())
 * means a domain's contact list/count reflects only the leads the acting
 * user can see there, not every company-mate's contacts at that domain
 * too. Two Members can each have their own contacts at the same company
 * domain and see two different, non-overlapping "accounts" for it.
 */
class AccountRepository
{
    private const PER_PAGE = 50;

    /**
     * @param array{q?:string} $filters q matches domain or company name
     * @return array{rows: array<int,array>, total: int, page: int, perPage: int, totalPages: int}
     */
    public static function search(PDO $db, Scope $scope, array $filters, int $page = 1): array
    {
        $page = max(1, $page);
        $perPage = self::PER_PAGE;

        $clauses = ['l.deleted_at IS NULL'];
        $params = [];
        ScopeFilter::apply($clauses, $params, $scope);
        ScopeFilter::applyOwnerScope($clauses, $params, $scope, $db);

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $clauses[] = "(SUBSTRING_INDEX(l.email, '@', -1) LIKE :q1 OR l.na_company_name LIKE :q2)";
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }
        $where = 'WHERE ' . implode(' AND ', $clauses);

        $countSql = "SELECT COUNT(*) FROM (
            SELECT SUBSTRING_INDEX(l.email, '@', -1) AS domain FROM leads l {$where} GROUP BY domain
        ) t";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        // GROUP BY / must reference the expression, not the "domain" alias --
        // suppressed_domains has its own real `domain` column once joined,
        // and MySQL/MariaDB resolves an ambiguous GROUP BY alias to a real
        // column over a SELECT alias, silently grouping by sd.domain
        // instead (collapsing every non-suppressed lead into one NULL group).
        $sql = "SELECT
                  SUBSTRING_INDEX(l.email, '@', -1) AS domain,
                  MAX(l.na_company_name) AS company_name,
                  COUNT(*) AS contact_count,
                  MAX(sd.reason) AS suppressed_reason
                FROM leads l
                LEFT JOIN suppressed_domains sd ON sd.domain = SUBSTRING_INDEX(l.email, '@', -1)
                {$where}
                GROUP BY SUBSTRING_INDEX(l.email, '@', -1)
                ORDER BY company_name ASC
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
     * @return ?array{domain:string, company_name:?string, contact_count:int, suppressed_reason:?string}
     */
    public static function summary(PDO $db, Scope $scope, string $domain): ?array
    {
        $clauses = ['l.deleted_at IS NULL', "SUBSTRING_INDEX(l.email, '@', -1) = :domain"];
        $params = ['domain' => $domain];
        ScopeFilter::apply($clauses, $params, $scope);
        ScopeFilter::applyOwnerScope($clauses, $params, $scope, $db);
        $where = implode(' AND ', $clauses);

        $stmt = $db->prepare(
            "SELECT
               SUBSTRING_INDEX(l.email, '@', -1) AS domain,
               MAX(l.na_company_name) AS company_name,
               COUNT(*) AS contact_count,
               MAX(sd.reason) AS suppressed_reason
             FROM leads l
             LEFT JOIN suppressed_domains sd ON sd.domain = SUBSTRING_INDEX(l.email, '@', -1)
             WHERE {$where}
             GROUP BY SUBSTRING_INDEX(l.email, '@', -1)"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Every persona (lead) at this domain the acting user can see, for the
     * account detail page.
     */
    public static function contactsForDomain(PDO $db, Scope $scope, string $domain): array
    {
        $clauses = ['l.deleted_at IS NULL', "SUBSTRING_INDEX(l.email, '@', -1) = :domain"];
        $params = ['domain' => $domain];
        ScopeFilter::apply($clauses, $params, $scope);
        ScopeFilter::applyOwnerScope($clauses, $params, $scope, $db);
        $where = implode(' AND ', $clauses);

        $stmt = $db->prepare(
            "SELECT l.*, v.label AS vertical_label, s.label AS service_label
               FROM leads l
               LEFT JOIN verticals v ON v.id = l.vertical_id
               LEFT JOIN services s ON s.id = l.service_id
              WHERE {$where}
              ORDER BY l.first_name, l.last_name"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
