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
     * SQL fragment (for a `lead_campaign_assignments` row aliased `a2`)
     * matching an assignment that's still "pending" in the sense used by
     * pendingElsewhereCampaigns()/filterEligibleForCampaign() below: sent
     * to (or earmarked for) a domain, with no confirmation yet that it
     * went out without bouncing.
     *
     * Written as the *negation* of "confirmed resolved" rather than
     * enumerating waiting-states directly, because wave_status/bounce_status
     * default to ('active','pending') on every row regardless of whether it
     * ever went through the wave-1 mechanic -- checking for that default
     * can't distinguish "genuinely still waiting" from "never touched by
     * wave-1 at all, but delivery_status already says otherwise" (a row
     * pushed straight to Saleshandy without a wave-1 leader/held pairing
     * keeps that default forever). Resolved means: wave-1 explicitly
     * marked "Delivered", or a Saleshandy-synced delivery_status of
     * Active/Replied/Paused (sent, and not currently flagged as a
     * bounce). A confirmed bounce isn't handled here at all: it already
     * suppresses the whole domain via suppressed_domains, which
     * filterEligibleForCampaign() checks first.
     *
     * Public so LeadRepository can reuse it verbatim (dashboard's "Pending
     * elsewhere" badge/filter) instead of a second hand-kept-in-sync copy.
     */
    public const PENDING_ASSIGNMENT_SQL = "(
        a2.bounce_status != 'delivered'
        AND (a2.delivery_status IS NULL OR a2.delivery_status NOT IN ('Active', 'Replied', 'Paused'))
    )";

    /**
     * A lead may only ever belong to one campaign AT A TIME -- once its
     * email is assigned anywhere, it's excluded from being assigned to a
     * *different* campaign (re-selecting it into the same campaign it's
     * already in is unaffected, that's just a no-op INSERT IGNORE)
     * unless its *latest* assignment (across every campaign, not just
     * $campaignId) is both resolved -- not still 'held' (wave-1 safety
     * hold), not still "pending" per PENDING_ASSIGNMENT_SQL (no
     * confirmed send outcome yet) -- and older than $cooldownDays. This
     * mirrors LeadRepository::buildWhere()'s 'assignable_after_cooldown_days'
     * filter (see IcpRepository::toFilters()) exactly, so a lead an ICP
     * finds "eligible" during matching doesn't then get silently
     * rejected here as "already elsewhere" when the assignment step
     * actually runs -- the two checks must never drift apart.
     *
     * Combined here with two account-wide checks every assignment entry
     * point needs: if even one persona at a domain bounced (per the
     * configured bounce-type severity, see bounceTypeSuppresses()), no
     * persona at that domain can be added to any campaign; and if a
     * *different* persona at the same domain is currently pending in
     * another campaign (assigned/pushed there but not yet confirmed sent
     * without bouncing -- see PENDING_ASSIGNMENT_SQL), this lead is held
     * back too, so the same account never has two simultaneous,
     * unconfirmed sends in flight across different campaigns. Once that
     * other persona's send is confirmed (or it's marked bounced, which
     * suppresses the domain instead), this lead becomes assignable again.
     *
     * @param int[] $leadIds
     * @return array{eligible:int[], suppressed_count:int, already_elsewhere_count:int, pending_elsewhere_count:int, pending_elsewhere_campaigns:array<string,int>}
     */
    public static function filterEligibleForCampaign(PDO $db, array $leadIds, int $campaignId, int $cooldownDays): array
    {
        $stats = [
            'eligible' => [], 'suppressed_count' => 0, 'already_elsewhere_count' => 0,
            'pending_elsewhere_count' => 0, 'pending_elsewhere_campaigns' => [],
        ];
        if (!$leadIds) {
            return $stats;
        }
        $leadIds = array_values(array_unique(array_map('intval', $leadIds)));

        $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
        $suppressedStmt = $db->prepare(
            "SELECT l.id FROM leads l
              WHERE l.id IN ({$placeholders})
                AND EXISTS (
                    SELECT 1 FROM suppressed_domains sd
                     WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1) AND sd.company_id = l.company_id
                )"
        );
        $suppressedStmt->execute($leadIds);
        $suppressedIds = array_map('intval', $suppressedStmt->fetchAll(PDO::FETCH_COLUMN));
        $stats['suppressed_count'] = count($suppressedIds);

        $remaining = array_values(array_diff($leadIds, $suppressedIds));
        if (!$remaining) {
            return $stats;
        }

        $rPlaceholders = implode(',', array_fill(0, count($remaining), '?'));
        $elsewhereStmt = $db->prepare(
            "SELECT DISTINCT a2.lead_id FROM lead_campaign_assignments a2
              WHERE a2.lead_id IN ({$rPlaceholders}) AND a2.campaign_id != ?
                AND a2.id = (SELECT MAX(a3.id) FROM lead_campaign_assignments a3 WHERE a3.lead_id = a2.lead_id)
                AND NOT (
                    a2.wave_status != 'held'
                    AND NOT " . self::PENDING_ASSIGNMENT_SQL . "
                    AND DATE_ADD(a2.assigned_at, INTERVAL ? DAY) <= NOW()
                )"
        );
        $elsewhereStmt->execute(array_merge($remaining, [$campaignId], [$cooldownDays]));
        $elsewhereIds = array_map('intval', $elsewhereStmt->fetchAll(PDO::FETCH_COLUMN));
        $stats['already_elsewhere_count'] = count($elsewhereIds);

        $remaining2 = array_values(array_diff($remaining, $elsewhereIds));
        if (!$remaining2) {
            return $stats;
        }

        $pending = self::pendingElsewhereCampaigns($db, $remaining2, $campaignId);
        $stats['pending_elsewhere_count'] = count($pending);
        foreach ($pending as $campaignNames) {
            foreach ($campaignNames as $campaignName) {
                $stats['pending_elsewhere_campaigns'][$campaignName] = ($stats['pending_elsewhere_campaigns'][$campaignName] ?? 0) + 1;
            }
        }

        $stats['eligible'] = array_values(array_diff($remaining2, array_keys($pending)));
        return $stats;
    }

    /**
     * For each of the given lead ids, the distinct campaign name(s) a
     * *different* persona at the same email domain is currently pending
     * in (per PENDING_ASSIGNMENT_SQL), excluding $campaignId itself.
     * Leads with no such match are simply absent from the result.
     *
     * @param int[] $leadIds
     * @return array<int,array<int,string>> lead id => campaign names blocking it
     */
    public static function pendingElsewhereCampaigns(PDO $db, array $leadIds, int $campaignId): array
    {
        if (!$leadIds) {
            return [];
        }
        $leadIds = array_values(array_unique(array_map('intval', $leadIds)));

        $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
        $leadRowsStmt = $db->prepare("SELECT id, email FROM leads WHERE id IN ({$placeholders})");
        $leadRowsStmt->execute($leadIds);
        $domainByLead = [];
        foreach ($leadRowsStmt->fetchAll() as $row) {
            $domainByLead[(int) $row['id']] = strtolower(substr(strrchr($row['email'], '@'), 1));
        }
        $domains = array_values(array_unique($domainByLead));
        if (!$domains) {
            return [];
        }

        $dPlaceholders = implode(',', array_fill(0, count($domains), '?'));
        $pendingStmt = $db->prepare(
            "SELECT SUBSTRING_INDEX(l2.email, '@', -1) AS domain, c.name AS campaign_name
               FROM lead_campaign_assignments a2
               JOIN leads l2 ON l2.id = a2.lead_id
               JOIN campaigns c ON c.id = a2.campaign_id
              WHERE SUBSTRING_INDEX(l2.email, '@', -1) IN ({$dPlaceholders})
                AND a2.campaign_id != ?
                AND l2.deleted_at IS NULL
                AND " . self::PENDING_ASSIGNMENT_SQL
        );
        $pendingStmt->execute(array_merge($domains, [$campaignId]));

        $campaignsByDomain = [];
        foreach ($pendingStmt->fetchAll() as $row) {
            $campaignsByDomain[$row['domain']][$row['campaign_name']] = true;
        }

        $result = [];
        foreach ($domainByLead as $leadId => $domain) {
            if (isset($campaignsByDomain[$domain])) {
                $result[$leadId] = array_keys($campaignsByDomain[$domain]);
            }
        }
        return $result;
    }

    /**
     * @param int[] $leadIds
     * @param string[] $titlePriority ordered, case-insensitive substring keywords (e.g. ["VP Engineering", "CTO"])
     * @param int $cooldownDays the acting company's lead_cooldown_days (Scope::$leadCooldownDays) -- see filterEligibleForCampaign()
     * @return array{leaders:int, held:int, suppressed_skipped:int, already_elsewhere_skipped:int, pending_elsewhere_skipped:int, pending_elsewhere_campaigns:array<string,int>, already_in_campaign:int, domains:int}
     */
    public static function assign(PDO $db, array $leadIds, int $campaignId, int $userId, array $titlePriority, int $cooldownDays, ?int $icpId = null): array
    {
        $stats = [
            'leaders' => 0, 'held' => 0, 'suppressed_skipped' => 0,
            'already_elsewhere_skipped' => 0, 'pending_elsewhere_skipped' => 0, 'pending_elsewhere_campaigns' => [],
            'already_in_campaign' => 0, 'domains' => 0,
        ];
        if (!$leadIds) {
            return $stats;
        }

        $filtered = self::filterEligibleForCampaign($db, $leadIds, $campaignId, $cooldownDays);
        $stats['suppressed_skipped'] = $filtered['suppressed_count'];
        $stats['already_elsewhere_skipped'] = $filtered['already_elsewhere_count'];
        $stats['pending_elsewhere_skipped'] = $filtered['pending_elsewhere_count'];
        $stats['pending_elsewhere_campaigns'] = $filtered['pending_elsewhere_campaigns'];
        if (!$filtered['eligible']) {
            return $stats;
        }

        $placeholders = implode(',', array_fill(0, count($filtered['eligible']), '?'));
        $stmt = $db->prepare("SELECT l.id, l.email, l.title, l.seniority FROM leads l WHERE l.id IN ({$placeholders})");
        $stmt->execute($filtered['eligible']);
        $eligible = $stmt->fetchAll();

        $byDomain = [];
        foreach ($eligible as $lead) {
            $domain = strtolower(substr(strrchr($lead['email'], '@'), 1));
            $byDomain[$domain][] = $lead;
        }
        $stats['domains'] = count($byDomain);

        $insertStmt = $db->prepare(
            'INSERT IGNORE INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, icp_id, wave_status, wave_leader_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $findStmt = $db->prepare('SELECT id FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id = ?');

        foreach ($byDomain as $domainLeads) {
            $leader = self::pickLeader($domainLeads, $titlePriority);
            $others = array_filter($domainLeads, static fn($l) => $l['id'] !== $leader['id']);

            $insertStmt->execute([$leader['id'], $campaignId, $userId, $icpId, 'active', null]);
            if ($insertStmt->rowCount() === 1) {
                $leaderAssignmentId = (int) $db->lastInsertId();
                $stats['leaders']++;
            } else {
                $stats['already_in_campaign']++;
                $findStmt->execute([$leader['id'], $campaignId]);
                $leaderAssignmentId = (int) $findStmt->fetchColumn();
            }

            foreach ($others as $lead) {
                $insertStmt->execute([$lead['id'], $campaignId, $userId, $icpId, 'held', $leaderAssignmentId]);
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
     * Whether a given bounce type should trigger the global, account-wide
     * domain suppression (blocking every persona at that domain from ever
     * being added to any campaign) -- admin-configurable per bounce type
     * via bounce_settings.php / bounce_type_suppression_settings. An
     * unspecified or unrecognized bounce type defaults to "suppresses",
     * matching this app's original (pre-setting) behavior.
     */
    public static function bounceTypeSuppresses(PDO $db, ?string $bounceType, int $companyId): bool
    {
        $bounceType = trim((string) $bounceType);
        if ($bounceType === '') {
            return true;
        }
        $stmt = $db->prepare('SELECT suppresses FROM bounce_type_suppression_settings WHERE bounce_type = ? AND company_id = ?');
        $stmt->execute([$bounceType, $companyId]);
        $value = $stmt->fetchColumn();
        return $value === false ? true : (bool) $value;
    }

    /**
     * Wave-1 bounced: suppress the held leads under this leader for this
     * campaign, and -- if this bounce type is configured to (see
     * bounceTypeSuppresses()) -- add the whole domain to the global
     * suppression list so it's excluded from every future campaign/import
     * too.
     *
     * @return array{held_suppressed:int, domain_suppressed:bool}
     */
    public static function suppress(PDO $db, int $leaderAssignmentId, int $userId, int $companyId, string $reason = 'Wave-1 bounce', ?string $bounceType = null): array
    {
        $leadStmt = $db->prepare(
            'SELECT l.email FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id WHERE a.id = ?'
        );
        $leadStmt->execute([$leaderAssignmentId]);
        $email = $leadStmt->fetchColumn();

        $domainSuppressed = false;
        if ($email && self::bounceTypeSuppresses($db, $bounceType, $companyId)) {
            self::suppressDomainOf($db, $email, $userId, $companyId, $reason, $bounceType);
            $domainSuppressed = true;
        }

        $db->prepare("UPDATE lead_campaign_assignments SET bounce_status = 'bounced', bounce_type = ? WHERE id = ?")
            ->execute([$bounceType, $leaderAssignmentId]);
        $stmt = $db->prepare("UPDATE lead_campaign_assignments SET wave_status = 'suppressed' WHERE wave_leader_id = ?");
        $stmt->execute([$leaderAssignmentId]);
        return ['held_suppressed' => $stmt->rowCount(), 'domain_suppressed' => $domainSuppressed];
    }

    /**
     * Bounce-report import path: given a bounced email address (not
     * necessarily a wave-1 leader), suppress its domain globally -- if
     * this bounce type is configured to, see bounceTypeSuppresses() -- and,
     * if that email happens to be a pending wave-1 leader in some
     * campaign, cascade-suppress its held group there too.
     *
     * @return array{domain:string, cascaded:int, suppressed:bool}
     */
    public static function suppressByEmail(PDO $db, string $email, int $userId, int $companyId, string $reason, ?string $bounceType = null): array
    {
        $suppressed = self::bounceTypeSuppresses($db, $bounceType, $companyId);
        $domain = $suppressed
            ? self::suppressDomainOf($db, $email, $userId, $companyId, $reason, $bounceType)
            : strtolower(substr(strrchr($email, '@'), 1));

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

        return ['domain' => $domain, 'cascaded' => $cascaded, 'suppressed' => $suppressed];
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

    private static function suppressDomainOf(PDO $db, string $email, int $userId, int $companyId, string $reason, ?string $bounceType = null): string
    {
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        $db->prepare(
            'INSERT INTO suppressed_domains (company_id, domain, reason, bounce_type, suppressed_by) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), bounce_type = VALUES(bounce_type)'
        )->execute([$companyId, $domain, $reason, $bounceType, $userId]);
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
