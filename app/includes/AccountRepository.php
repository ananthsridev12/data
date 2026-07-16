<?php

/**
 * "Accounts" are not a stored entity -- they're leads grouped by email
 * domain (SUBSTRING_INDEX(email, '@', -1)), the same grouping already used
 * for wave-1 sending and dashboard filtering. Since every lead's email is
 * unique (see leads.uq_leads_email) and imports upsert on that email, a
 * domain's contact list here can never contain a duplicate persona --
 * re-importing/re-adding the same person just updates their one row.
 */
class AccountRepository
{
    private const PER_PAGE = 50;

    /**
     * @param array{q?:string} $filters q matches domain or company name
     * @return array{rows: array<int,array>, total: int, page: int, perPage: int, totalPages: int}
     */
    public static function search(PDO $db, array $filters, int $page = 1): array
    {
        $page = max(1, $page);
        $perPage = self::PER_PAGE;

        $where = 'WHERE l.deleted_at IS NULL';
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where .= " AND (SUBSTRING_INDEX(l.email, '@', -1) LIKE :q1 OR l.na_company_name LIKE :q2)";
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }

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
    public static function summary(PDO $db, string $domain): ?array
    {
        $stmt = $db->prepare(
            "SELECT
               SUBSTRING_INDEX(l.email, '@', -1) AS domain,
               MAX(l.na_company_name) AS company_name,
               COUNT(*) AS contact_count,
               MAX(sd.reason) AS suppressed_reason
             FROM leads l
             LEFT JOIN suppressed_domains sd ON sd.domain = SUBSTRING_INDEX(l.email, '@', -1)
             WHERE l.deleted_at IS NULL AND SUBSTRING_INDEX(l.email, '@', -1) = ?
             GROUP BY SUBSTRING_INDEX(l.email, '@', -1)"
        );
        $stmt->execute([$domain]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Every persona (lead) at this domain, for the account detail page.
     */
    public static function contactsForDomain(PDO $db, string $domain): array
    {
        $stmt = $db->prepare(
            "SELECT l.*, v.label AS vertical_label, s.label AS service_label
               FROM leads l
               LEFT JOIN verticals v ON v.id = l.vertical_id
               LEFT JOIN services s ON s.id = l.service_id
              WHERE l.deleted_at IS NULL AND SUBSTRING_INDEX(l.email, '@', -1) = ?
              ORDER BY l.first_name, l.last_name"
        );
        $stmt->execute([$domain]);
        return $stmt->fetchAll();
    }
}
