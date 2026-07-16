<?php

/**
 * Domain-safe "wave 1" campaign assignment: given a pool of lead IDs,
 * groups them by email domain and assigns only one contact per domain
 * (the "leader") to the campaign now; the rest are recorded as "held"
 * against that leader so they're reserved but not exportable/emailable
 * until the leader's outcome is known (see WaveAssigner::release() /
 * ::suppress(), called from campaign_wave_update.php and bounce_import.php).
 */
class WaveAssigner
{
    public const BOUNCE_TYPES = ['Hard Bounce', 'Soft Bounce', 'Spam Complaint', 'Invalid Address', 'Other'];

    // Seniority fallback rank used when no title-priority keyword matches
    // any lead at a domain -- lower number wins. Unlisted values sort last.
    private const SENIORITY_RANK = [
        'c-level' => 0,
        'owner' => 0,
        'founder' => 0,
        'vp' => 1,
        'director' => 2,
        'manager' => 3,
        'senior' => 4,
        'entry' => 5,
    ];

    /**
     * @param int[] $leadIds
     * @return array{eligible:int[], suppressed_count:int}
     */
    public static function filterSuppressed(PDO $db, array $leadIds): array
    {
        if (!$leadIds) {
            return ['eligible' => [], 'suppressed_count' => 0];
        }
        $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
        $stmt = $db->prepare(
            "SELECT l.id FROM leads l
              WHERE l.id IN ({$placeholders})
                AND NOT EXISTS (
                    SELECT 1 FROM suppressed_domains sd
                     WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1)
                )"
        );
        $stmt->execute($leadIds);
        $eligible = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return ['eligible' => $eligible, 'suppressed_count' => count($leadIds) - count($eligible)];
    }

    /**
     * @param int[] $leadIds
     * @param string[] $titlePriority ordered, case-insensitive substring keywords (e.g. ["VP Engineering", "CTO"])
     * @return array{leaders:int, held:int, suppressed_skipped:int, already_in_campaign:int, domains:int}
     */
    public static function assign(PDO $db, array $leadIds, int $campaignId, int $userId, array $titlePriority): array
    {
        $stats = ['leaders' => 0, 'held' => 0, 'suppressed_skipped' => 0, 'already_in_campaign' => 0, 'domains' => 0];
        if (!$leadIds) {
            return $stats;
        }

        $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
        $stmt = $db->prepare(
            "SELECT l.id, l.email, l.title, l.seniority
               FROM leads l
              WHERE l.id IN ({$placeholders})
                AND NOT EXISTS (
                    SELECT 1 FROM suppressed_domains sd
                     WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1)
                )"
        );
        $stmt->execute($leadIds);
        $eligible = $stmt->fetchAll();
        $stats['suppressed_skipped'] = count($leadIds) - count($eligible);

        $byDomain = [];
        foreach ($eligible as $lead) {
            $domain = strtolower(substr(strrchr($lead['email'], '@'), 1));
            $byDomain[$domain][] = $lead;
        }
        $stats['domains'] = count($byDomain);

        $insertStmt = $db->prepare(
            'INSERT IGNORE INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, wave_status, wave_leader_id) VALUES (?, ?, ?, ?, ?)'
        );
        $findStmt = $db->prepare('SELECT id FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id = ?');

        foreach ($byDomain as $domainLeads) {
            $leader = self::pickLeader($domainLeads, $titlePriority);
            $others = array_filter($domainLeads, static fn($l) => $l['id'] !== $leader['id']);

            $insertStmt->execute([$leader['id'], $campaignId, $userId, 'active', null]);
            if ($insertStmt->rowCount() === 1) {
                $leaderAssignmentId = (int) $db->lastInsertId();
                $stats['leaders']++;
            } else {
                $stats['already_in_campaign']++;
                $findStmt->execute([$leader['id'], $campaignId]);
                $leaderAssignmentId = (int) $findStmt->fetchColumn();
            }

            foreach ($others as $lead) {
                $insertStmt->execute([$lead['id'], $campaignId, $userId, 'held', $leaderAssignmentId]);
                if ($insertStmt->rowCount() === 1) {
                    $stats['held']++;
                } else {
                    $stats['already_in_campaign']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Wave-1 delivered: release every held lead under this leader so they
     * become normally assignable/exportable/emailable.
     */
    public static function release(PDO $db, int $leaderAssignmentId): int
    {
        $db->prepare("UPDATE lead_campaign_assignments SET bounce_status = 'delivered' WHERE id = ?")
            ->execute([$leaderAssignmentId]);
        $stmt = $db->prepare("UPDATE lead_campaign_assignments SET wave_status = 'active' WHERE wave_leader_id = ?");
        $stmt->execute([$leaderAssignmentId]);
        return $stmt->rowCount();
    }

    /**
     * Wave-1 bounced: suppress the held leads under this leader for this
     * campaign, and add the whole domain to the global suppression list
     * so it's excluded from every future campaign/import too.
     */
    public static function suppress(PDO $db, int $leaderAssignmentId, int $userId, string $reason = 'Wave-1 bounce', ?string $bounceType = null): int
    {
        $leadStmt = $db->prepare(
            'SELECT l.email FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id WHERE a.id = ?'
        );
        $leadStmt->execute([$leaderAssignmentId]);
        $email = $leadStmt->fetchColumn();

        if ($email) {
            self::suppressDomainOf($db, $email, $userId, $reason, $bounceType);
        }

        $db->prepare("UPDATE lead_campaign_assignments SET bounce_status = 'bounced', bounce_type = ? WHERE id = ?")
            ->execute([$bounceType, $leaderAssignmentId]);
        $stmt = $db->prepare("UPDATE lead_campaign_assignments SET wave_status = 'suppressed' WHERE wave_leader_id = ?");
        $stmt->execute([$leaderAssignmentId]);
        return $stmt->rowCount();
    }

    /**
     * Bounce-report import path: given a bounced email address (not
     * necessarily a wave-1 leader), suppress its domain globally and, if
     * that email happens to be a pending wave-1 leader in some campaign,
     * cascade-suppress its held group there too.
     *
     * @return array{domain:string, cascaded:int}
     */
    public static function suppressByEmail(PDO $db, string $email, int $userId, string $reason, ?string $bounceType = null): array
    {
        $domain = self::suppressDomainOf($db, $email, $userId, $reason, $bounceType);

        $leaderStmt = $db->prepare(
            "SELECT a.id FROM lead_campaign_assignments a
               JOIN leads l ON l.id = a.lead_id
              WHERE l.email = ? AND a.wave_leader_id IS NULL AND a.bounce_status = 'pending'"
        );
        $leaderStmt->execute([strtolower(trim($email))]);
        $leaderIds = array_map('intval', $leaderStmt->fetchAll(PDO::FETCH_COLUMN));

        $cascaded = 0;
        foreach ($leaderIds as $leaderId) {
            $db->prepare("UPDATE lead_campaign_assignments SET bounce_status = 'bounced', bounce_type = ? WHERE id = ?")
                ->execute([$bounceType, $leaderId]);
            $stmt = $db->prepare("UPDATE lead_campaign_assignments SET wave_status = 'suppressed' WHERE wave_leader_id = ?");
            $stmt->execute([$leaderId]);
            $cascaded += $stmt->rowCount();
        }

        return ['domain' => $domain, 'cascaded' => $cascaded];
    }

    /**
     * Auto-release path for the campaign paste-bounces flow: every wave-1
     * leader in this campaign still pending (i.e. not in the just-bounced
     * set) is treated as delivered and has its held group released.
     *
     * @param int[] $excludeLeaderAssignmentIds leader assignment ids just marked bounced (skip these)
     * @return array{released_leaders:int, released_held:int}
     */
    public static function releaseAllPendingInCampaign(PDO $db, int $campaignId, array $excludeLeaderAssignmentIds): array
    {
        $exclude = $excludeLeaderAssignmentIds ? array_map('intval', $excludeLeaderAssignmentIds) : [0];
        $placeholders = implode(',', array_fill(0, count($exclude), '?'));

        $stmt = $db->prepare(
            "SELECT id FROM lead_campaign_assignments
              WHERE campaign_id = ? AND wave_leader_id IS NULL AND bounce_status = 'pending'
                AND id NOT IN ({$placeholders})
                AND EXISTS (SELECT 1 FROM lead_campaign_assignments h WHERE h.wave_leader_id = lead_campaign_assignments.id)"
        );
        $stmt->execute(array_merge([$campaignId], $exclude));
        $leaderIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $releasedHeld = 0;
        foreach ($leaderIds as $leaderId) {
            $releasedHeld += self::release($db, $leaderId);
        }

        return ['released_leaders' => count($leaderIds), 'released_held' => $releasedHeld];
    }

    private static function suppressDomainOf(PDO $db, string $email, int $userId, string $reason, ?string $bounceType = null): string
    {
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        $db->prepare(
            'INSERT INTO suppressed_domains (domain, reason, bounce_type, suppressed_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), bounce_type = VALUES(bounce_type)'
        )->execute([$domain, $reason, $bounceType, $userId]);
        return $domain;
    }

    /**
     * @param array<int,array{id:int,email:string,title:?string,seniority:?string}> $domainLeads
     */
    private static function pickLeader(array $domainLeads, array $titlePriority): array
    {
        foreach ($titlePriority as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }
            foreach ($domainLeads as $lead) {
                if ($lead['title'] !== null && stripos($lead['title'], $keyword) !== false) {
                    return $lead;
                }
            }
        }

        usort($domainLeads, static function ($a, $b) {
            $rankA = self::SENIORITY_RANK[strtolower((string) $a['seniority'])] ?? 99;
            $rankB = self::SENIORITY_RANK[strtolower((string) $b['seniority'])] ?? 99;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            return $a['id'] <=> $b['id'];
        });

        return $domainLeads[0];
    }
}
