<?php

require_once __DIR__ . '/RoleGroupClassifier.php';

/**
 * CRUD + matching/splitting logic for ICP (Ideal Customer Profile) segments
 * -- see sql/025_icp_segments.sql and public/icp_distribution_cron.php.
 */
class IcpRepository
{
    /** @return array<int,array<string,mixed>> every ICP, with joined labels + linked-campaign summary. */
    public static function list(PDO $db): array
    {
        $rows = $db->query(
            "SELECT icp.*, rg.label AS role_group_label, v.label AS vertical_label, s.label AS service_label,
                    (SELECT COUNT(*) FROM icp_campaign_links l WHERE l.icp_id = icp.id) AS link_count,
                    (SELECT COALESCE(SUM(l.percentage), 0) FROM icp_campaign_links l WHERE l.icp_id = icp.id) AS percentage_total
               FROM icp_segments icp
               LEFT JOIN role_groups rg ON rg.id = icp.role_group_id
               LEFT JOIN verticals v ON v.id = icp.vertical_id
               LEFT JOIN services s ON s.id = icp.service_id
              ORDER BY icp.name"
        )->fetchAll();

        foreach ($rows as &$r) {
            $r['link_count'] = (int) $r['link_count'];
            $r['percentage_total'] = (int) $r['percentage_total'];
        }
        unset($r);

        return $rows;
    }

    public static function find(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM icp_segments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function activeWithValidLinks(PDO $db): array
    {
        $icps = $db->query('SELECT * FROM icp_segments WHERE is_active = 1')->fetchAll();
        $valid = [];
        foreach ($icps as $icp) {
            if (self::linksSumTo100($db, (int) $icp['id'])) {
                $valid[] = $icp;
            }
        }
        return $valid;
    }

    /** @return array<int,array{id:int,campaign_id:int,campaign_name:string,percentage:int}> */
    public static function links(PDO $db, int $icpId): array
    {
        $stmt = $db->prepare(
            'SELECT l.id, l.campaign_id, c.name AS campaign_name, l.percentage
               FROM icp_campaign_links l
               JOIN campaigns c ON c.id = l.campaign_id
              WHERE l.icp_id = ?
              ORDER BY l.percentage DESC'
        );
        $stmt->execute([$icpId]);
        return $stmt->fetchAll();
    }

    public static function linksSumTo100(PDO $db, int $icpId): bool
    {
        $stmt = $db->prepare('SELECT COALESCE(SUM(percentage), 0) FROM icp_campaign_links WHERE icp_id = ?');
        $stmt->execute([$icpId]);
        return (int) $stmt->fetchColumn() === 100;
    }

    /** @param array<string,mixed> $data */
    public static function create(PDO $db, array $data, int $userId): int
    {
        $stmt = $db->prepare(
            'INSERT INTO icp_segments
                (name, role_group_id, vertical_id, service_id, company_country, industry, seniority, employee_count, auto_push_enabled, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'], $data['role_group_id'] ?: null, $data['vertical_id'] ?: null, $data['service_id'] ?: null,
            $data['company_country'] ?: null, $data['industry'] ?: null, $data['seniority'] ?: null, $data['employee_count'] ?: null,
            !empty($data['auto_push_enabled']) ? 1 : 0, $userId,
        ]);
        return (int) $db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(PDO $db, int $id, array $data): void
    {
        $stmt = $db->prepare(
            'UPDATE icp_segments
                SET name = ?, role_group_id = ?, vertical_id = ?, service_id = ?,
                    company_country = ?, industry = ?, seniority = ?, employee_count = ?, auto_push_enabled = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $data['name'], $data['role_group_id'] ?: null, $data['vertical_id'] ?: null, $data['service_id'] ?: null,
            $data['company_country'] ?: null, $data['industry'] ?: null, $data['seniority'] ?: null, $data['employee_count'] ?: null,
            !empty($data['auto_push_enabled']) ? 1 : 0, $id,
        ]);
    }

    public static function toggleActive(PDO $db, int $id): void
    {
        $db->prepare('UPDATE icp_segments SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
    }

    public static function addLink(PDO $db, int $icpId, int $campaignId, int $percentage): void
    {
        $stmt = $db->prepare('INSERT INTO icp_campaign_links (icp_id, campaign_id, percentage) VALUES (?, ?, ?)');
        $stmt->execute([$icpId, $campaignId, $percentage]);
    }

    public static function removeLink(PDO $db, int $linkId): void
    {
        $db->prepare('DELETE FROM icp_campaign_links WHERE id = ?')->execute([$linkId]);
    }

    /**
     * Maps an icp_segments row to the exact filter shape
     * LeadRepository::buildWhere()/matchingIds() already accepts --
     * comma-lists parsed the same way RoleGroupClassifier::parseKeywords()
     * already splits role_groups.keywords, so no new parsing logic. Always
     * scoped to leads with zero assignment history (assigned_campaign_id
     * = 'none', LeadRepository.php:236-238) -- the cron only ever wants
     * genuinely fresh leads, never ones WaveAssigner would just filter
     * back out anyway.
     *
     * @param array<string,mixed> $icp a row from icp_segments
     * @return array<string,mixed>
     */
    public static function toFilters(array $icp): array
    {
        return [
            'company_country' => RoleGroupClassifier::parseKeywords($icp['company_country'] ?? ''),
            'industry' => RoleGroupClassifier::parseKeywords($icp['industry'] ?? ''),
            'seniority' => RoleGroupClassifier::parseKeywords($icp['seniority'] ?? ''),
            // icp_segments.employee_count stores range-band text (e.g.
            // "51-200"), not raw exact numbers -- mapped onto
            // LeadRepository's employee_count_range filter key, which
            // matches against leads.employee_count_range.
            'employee_count_range' => RoleGroupClassifier::parseKeywords($icp['employee_count'] ?? ''),
            'vertical_id' => $icp['vertical_id'] ?: '',
            'service_id' => $icp['service_id'] ?: '',
            'role_group_id' => $icp['role_group_id'] ?: '',
            'assigned_campaign_id' => 'none',
        ];
    }

    /**
     * Pure function: splits a shuffled lead-ID pool into one bucket per
     * link, proportional to each link's percentage. The last link (by
     * the order given) absorbs any rounding remainder, so every ID lands
     * in exactly one bucket -- no lead lost, none duplicated, regardless
     * of how evenly the percentages divide the pool size.
     *
     * Caller is responsible for shuffling $leadIds first if randomizing
     * which leads land in which bucket is desired (this function itself
     * is deterministic given its inputs, to keep it easily testable).
     *
     * @param int[] $leadIds
     * @param array<int,array{campaign_id:int,percentage:int}> $links
     * @return array<int,int[]> campaign_id => lead IDs
     */
    public static function splitLeadIds(array $leadIds, array $links): array
    {
        $buckets = [];
        foreach ($links as $link) {
            $buckets[(int) $link['campaign_id']] = [];
        }
        if (!$leadIds || !$links) {
            return $buckets;
        }

        $total = count($leadIds);
        $cumulativePct = 0;
        $offset = 0;
        $lastIndex = count($links) - 1;
        foreach ($links as $i => $link) {
            $cumulativePct += (int) $link['percentage'];
            $endIndex = ($i === $lastIndex) ? $total : (int) round($total * $cumulativePct / 100);
            $endIndex = max($offset, min($total, $endIndex));
            $buckets[(int) $link['campaign_id']] = array_slice($leadIds, $offset, $endIndex - $offset);
            $offset = $endIndex;
        }

        return $buckets;
    }
}
