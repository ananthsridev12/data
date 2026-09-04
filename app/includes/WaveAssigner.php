<?php

/**
 * Domain-safe "wave 1" campaign assignment: given a pool of lead IDs,
 * groups them by email domain and assigns only one contact per domain
 * (the "leader") to the campaign now; the rest are recorded as "held"
 * against that leader so they're reserved but not exportable/emailable
 * until the leader's outcome is known (see WaveAssigner::release() /
 * ::suppress(), called from campaign_wave_update.php and bounce_import.php).
 *
 * That grouping only applies to a lead with NO prior assignment history --
 * one already reassigned from an earlier, resolved campaign (see
 * filterEligibleForCampaign()'s cooldown rule) skips it entirely and goes
 * straight to active: its email already proved deliverable, so there's
 * nothing left for a leader/held hold to protect against for that lead.
 */
class WaveAssigner
{
    // Fallback seed list ONLY -- for a company whose
    // bounce_type_suppression_settings has never been populated (e.g. a
    // brand-new tenant created after sql/016 ran). The real, authoritative,
    // admin-editable list is per-company in that table (see
    // bounce_settings.php) and now has more values than this original
    // 5-item set (e.g. "Bounced", "Hard Bounced", "Block Bounced", "All
    // Bounced", "Soft Bounced" -- Saleshandy's own raw delivery_status
    // bounce variants, added later without this constant being updated to
    // match). Use listBounceTypes() below everywhere a dropdown or
    // validation allowlist needs "every bounce type this company
    // recognizes" -- never reference this constant directly outside of it.
    public const BOUNCE_TYPES = ['Hard Bounce', 'Soft Bounce', 'Spam Complaint', 'Invalid Address', 'Other'];

    /**
     * The full, authoritative, per-company list of bounce types -- what
     * every "Bounce Type" dropdown/validation allowlist should actually
     * use, not the narrower BOUNCE_TYPES constant above. Reads
     * bounce_type_suppression_settings (the same admin-editable list
     * bounce_settings.php manages), falling back to BOUNCE_TYPES only if
     * that company has no rows there at all yet.
     *
     * @return string[]
     */
    public static function listBounceTypes(PDO $db, int $companyId): array
    {
        $stmt = $db->prepare('SELECT bounce_type FROM bounce_type_suppression_settings WHERE company_id = ? ORDER BY bounce_type');
        $stmt->execute([$companyId]);
        $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $types ?: self::BOUNCE_TYPES;
    }

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
     * $avoidRepeatService (icp_segments.avoid_repeat_service, opt-in, see
     * sql/047_icp_avoid_repeat_service.sql) adds one more exclusion on top
     * of everything above: a lead that has ANY prior assignment (any
     * campaign, any time) whose campaign shares $campaignId's own
     * service_id is dropped from eligible entirely, regardless of
     * cooldown/resolved status. A lead with no prior history, or whose
     * prior campaigns are all a different service (or have no service_id
     * set), is unaffected. Off by default -- existing ICPs keep today's
     * behavior.
     *
     * @param int[] $leadIds
     * @return array{eligible:int[], reassigned_ids:int[], suppressed_count:int, already_elsewhere_count:int, pending_elsewhere_count:int, pending_elsewhere_campaigns:array<string,int>, same_service_skipped_count:int}
     */
    public static function filterEligibleForCampaign(PDO $db, array $leadIds, int $campaignId, int $cooldownDays, bool $avoidRepeatService = false): array
    {
        $stats = [
            'eligible' => [], 'reassigned_ids' => [], 'suppressed_count' => 0, 'already_elsewhere_count' => 0,
            'pending_elsewhere_count' => 0, 'pending_elsewhere_campaigns' => [], 'same_service_skipped_count' => 0,
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

        if ($avoidRepeatService && $stats['eligible']) {
            $svcStmt = $db->prepare('SELECT service_id FROM campaigns WHERE id = ?');
            $svcStmt->execute([$campaignId]);
            $serviceId = (int) $svcStmt->fetchColumn();
            if ($serviceId) {
                $eligiblePlaceholders = implode(',', array_fill(0, count($stats['eligible']), '?'));
                $sameServiceStmt = $db->prepare(
                    "SELECT DISTINCT a2.lead_id FROM lead_campaign_assignments a2
                       JOIN campaigns c2 ON c2.id = a2.campaign_id
                      WHERE a2.lead_id IN ({$eligiblePlaceholders}) AND a2.campaign_id != ? AND c2.service_id = ?"
                );
                $sameServiceStmt->execute(array_merge($stats['eligible'], [$campaignId, $serviceId]));
                $sameServiceIds = array_map('intval', $sameServiceStmt->fetchAll(PDO::FETCH_COLUMN));
                $stats['same_service_skipped_count'] = count($sameServiceIds);
                $stats['eligible'] = array_values(array_diff($stats['eligible'], $sameServiceIds));
            }
        }

        // Which of the eligible leads have ANY prior assignment history at
        // all (across any campaign) -- i.e. already went through a
        // campaign before and only just cleared the resolved+cooldown
        // check above, as opposed to being assigned for the very first
        // time. assign() uses this to skip wave-1 domain pacing for a
        // reassigned lead entirely: its email already proved deliverable
        // in an earlier campaign, so there's nothing left for a leader/
        // held hold to protect against for that lead specifically.
        if ($stats['eligible']) {
            $ePlaceholders = implode(',', array_fill(0, count($stats['eligible']), '?'));
            $priorStmt = $db->prepare("SELECT DISTINCT lead_id FROM lead_campaign_assignments WHERE lead_id IN ({$ePlaceholders})");
            $priorStmt->execute($stats['eligible']);
            $stats['reassigned_ids'] = array_map('intval', $priorStmt->fetchAll(PDO::FETCH_COLUMN));
        }

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
     * @param bool $avoidRepeatService icp_segments.avoid_repeat_service -- see filterEligibleForCampaign()
     * @return array{leaders:int, held:int, reassigned_sent:int, suppressed_skipped:int, already_elsewhere_skipped:int, pending_elsewhere_skipped:int, pending_elsewhere_campaigns:array<string,int>, same_service_skipped:int, already_in_campaign:int, domains:int}
     */
    public static function assign(PDO $db, array $leadIds, int $campaignId, int $userId, array $titlePriority, int $cooldownDays, ?int $icpId = null, bool $avoidRepeatService = false): array
    {
        $stats = [
            'leaders' => 0, 'held' => 0, 'reassigned_sent' => 0, 'suppressed_skipped' => 0,
            'already_elsewhere_skipped' => 0, 'pending_elsewhere_skipped' => 0, 'pending_elsewhere_campaigns' => [],
            'same_service_skipped' => 0, 'already_in_campaign' => 0, 'domains' => 0,
        ];
        if (!$leadIds) {
            return $stats;
        }

        $filtered = self::filterEligibleForCampaign($db, $leadIds, $campaignId, $cooldownDays, $avoidRepeatService);
        $stats['suppressed_skipped'] = $filtered['suppressed_count'];
        $stats['already_elsewhere_skipped'] = $filtered['already_elsewhere_count'];
        $stats['pending_elsewhere_skipped'] = $filtered['pending_elsewhere_count'];
        $stats['pending_elsewhere_campaigns'] = $filtered['pending_elsewhere_campaigns'];
        $stats['same_service_skipped'] = $filtered['same_service_skipped_count'];
        if (!$filtered['eligible']) {
            return $stats;
        }

        $placeholders = implode(',', array_fill(0, count($filtered['eligible']), '?'));
        $stmt = $db->prepare("SELECT l.id, l.email, l.title, l.seniority FROM leads l WHERE l.id IN ({$placeholders})");
        $stmt->execute($filtered['eligible']);
        $eligible = $stmt->fetchAll();

        $insertStmt = $db->prepare(
            'INSERT IGNORE INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, icp_id, wave_status, wave_leader_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $findStmt = $db->prepare('SELECT id FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id = ?');

        // A lead with ANY prior assignment history already proved its
        // email deliverable in an earlier campaign -- wave-1's leader/
        // held pacing exists to protect an *unproven* address from
        // looking like a blast to a company, which no longer applies
        // here, so these skip domain grouping entirely and go straight
        // to active. This is independent per lead: two reassigned leads
        // at the same domain both go active immediately; a genuinely
        // fresh (never-before-assigned) lead at that same domain, in the
        // same batch, still goes through normal wave-1 grouping below --
        // reassignment status doesn't change the SAFETY of a lead that
        // has never been sent before.
        $reassignedLookup = array_flip($filtered['reassigned_ids']);
        $freshLeads = [];
        foreach ($eligible as $lead) {
            if (isset($reassignedLookup[(int) $lead['id']])) {
                $insertStmt->execute([$lead['id'], $campaignId, $userId, $icpId, 'active', null]);
                if ($insertStmt->rowCount() === 1) {
                    $stats['reassigned_sent']++;
                } else {
                    $stats['already_in_campaign']++;
                }
            } else {
                $freshLeads[] = $lead;
            }
        }

        $byDomain = [];
        foreach ($freshLeads as $lead) {
            $domain = strtolower(substr(strrchr($lead['email'], '@'), 1));
            $byDomain[$domain][] = $lead;
        }
        $stats['domains'] = count($byDomain);

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
     * bounce_settings.php only governs FUTURE bounce events -- unchecking
     * a bounce type there doesn't retroactively touch a domain/held group
     * already suppressed under the old setting. This finds exactly what
     * a "release now" click would affect for the company's CURRENT
     * settings, without changing anything: every suppressed_domains row
     * whose recorded bounce_type is no longer configured to suppress, and
     * every currently-held (wave_status = 'suppressed') group whose
     * wave-1 leader's own bounce is that same now-not-suppressing type.
     * A domain/held group suppressed for a bounce type still checked
     * (e.g. Hard Bounce), or with no recognized bounce_type at all (e.g.
     * manually suppressed), is never included.
     *
     * @return array{domains:array<int,string>,leader_assignment_ids:array<int,int>}
     */
    private static function findReleasableByCurrentBounceSettings(PDO $db, int $companyId): array
    {
        $notSuppressingStmt = $db->prepare(
            'SELECT bounce_type FROM bounce_type_suppression_settings WHERE company_id = ? AND suppresses = 0'
        );
        $notSuppressingStmt->execute([$companyId]);
        $notSuppressingTypes = $notSuppressingStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$notSuppressingTypes) {
            return ['domains' => [], 'leader_assignment_ids' => []];
        }
        $placeholders = implode(',', array_fill(0, count($notSuppressingTypes), '?'));

        $domainsStmt = $db->prepare(
            "SELECT domain FROM suppressed_domains WHERE company_id = ? AND bounce_type IN ({$placeholders})"
        );
        $domainsStmt->execute(array_merge([$companyId], $notSuppressingTypes));
        $domains = $domainsStmt->fetchAll(PDO::FETCH_COLUMN);

        $leadersStmt = $db->prepare(
            "SELECT a.id FROM lead_campaign_assignments a
               JOIN leads l ON l.id = a.lead_id
              WHERE l.company_id = ? AND a.wave_leader_id IS NULL
                AND a.bounce_status = 'bounced' AND a.bounce_type IN ({$placeholders})
                AND EXISTS (SELECT 1 FROM lead_campaign_assignments h WHERE h.wave_leader_id = a.id AND h.wave_status = 'suppressed')"
        );
        $leadersStmt->execute(array_merge([$companyId], $notSuppressingTypes));
        $leaderIds = array_map('intval', $leadersStmt->fetchAll(PDO::FETCH_COLUMN));

        return ['domains' => $domains, 'leader_assignment_ids' => $leaderIds];
    }

    /**
     * Read-only preview of releaseByCurrentBounceSettings() -- exactly
     * what a "Release now" click would affect, without touching anything,
     * so bounce_settings.php can show real counts before the admin
     * commits to running it.
     *
     * @return array{domains_count:int,held_count:int,domains:array<int,string>}
     */
    public static function previewReleaseByCurrentBounceSettings(PDO $db, int $companyId): array
    {
        $found = self::findReleasableByCurrentBounceSettings($db, $companyId);

        $heldCount = 0;
        if ($found['leader_assignment_ids']) {
            $placeholders = implode(',', array_fill(0, count($found['leader_assignment_ids']), '?'));
            $countStmt = $db->prepare(
                "SELECT COUNT(*) FROM lead_campaign_assignments WHERE wave_leader_id IN ({$placeholders}) AND wave_status = 'suppressed'"
            );
            $countStmt->execute($found['leader_assignment_ids']);
            $heldCount = (int) $countStmt->fetchColumn();
        }

        return [
            'domains_count' => count($found['domains']),
            'held_count' => $heldCount,
            'domains' => $found['domains'],
        ];
    }

    /**
     * Bulk "settings changed" cleanup, for the "Release now" button on
     * bounce_settings.php: releases every domain/held group
     * previewReleaseByCurrentBounceSettings() found. Two independent
     * effects, exactly reversing what suppress()'s cascade did for that
     * bounce type:
     *   - suppressed_domains rows are deleted outright -- every persona
     *     at that domain becomes assignable to new campaigns again.
     *   - each matching leader's held group (wave_status = 'suppressed')
     *     goes back to 'active', so those specific prospects become
     *     exportable/pushable in their own campaign again.
     * Deliberately does NOT touch the leader's own bounce_status (stays
     * 'bounced') or wave_status -- that lead genuinely did bounce; only
     * *other* prospects blocked as a side effect of that bounce type
     * being configured to suppress are released. Unlike release(), which
     * is for "this bounce report was wrong, treat as delivered" and
     * overwrites bounce_status to 'delivered' -- not appropriate here,
     * since the bounce itself isn't in question, only whether it should
     * have cascaded this broadly.
     *
     * @return array{domains_released:int,held_reactivated:int,released_domains:array<int,string>}
     */
    public static function releaseByCurrentBounceSettings(PDO $db, int $companyId): array
    {
        $found = self::findReleasableByCurrentBounceSettings($db, $companyId);

        if ($found['domains']) {
            $placeholders = implode(',', array_fill(0, count($found['domains']), '?'));
            $db->prepare("DELETE FROM suppressed_domains WHERE company_id = ? AND domain IN ({$placeholders})")
                ->execute(array_merge([$companyId], $found['domains']));
        }

        $heldReactivated = 0;
        if ($found['leader_assignment_ids']) {
            $reactivateStmt = $db->prepare(
                "UPDATE lead_campaign_assignments SET wave_status = 'active' WHERE wave_leader_id = ? AND wave_status = 'suppressed'"
            );
            foreach ($found['leader_assignment_ids'] as $leaderId) {
                $reactivateStmt->execute([$leaderId]);
                $heldReactivated += $reactivateStmt->rowCount();
            }
        }

        return [
            'domains_released' => count($found['domains']),
            'held_reactivated' => $heldReactivated,
            'released_domains' => $found['domains'],
        ];
    }

    /**
     * Wave-1 bounced: suppress the held leads under this leader for this
     * campaign, and -- if this bounce type is configured to (see
     * bounceTypeSuppresses()) -- add the whole domain to the global
     * suppression list so it's excluded from every future campaign/import
     * too.
     *
     * $bouncedAt (sql/051_bounced_at.sql): when the bounce actually
     * happened, if known (e.g. Saleshandy's own "Bounced At" timestamp --
     * see SaleshandyClient::fetchSequenceActivity()). Defaults to NOW()
     * for callers with no real per-event timestamp to draw on (manual
     * Bounce Import/paste-bounces/per-leader "Bounced" button) -- still
     * better than leaving the column blank.
     *
     * @return array{held_suppressed:int, domain_suppressed:bool}
     */
    public static function suppress(PDO $db, int $leaderAssignmentId, int $userId, int $companyId, string $reason = 'Wave-1 bounce', ?string $bounceType = null, ?string $bouncedAt = null): array
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

        $db->prepare("UPDATE lead_campaign_assignments SET bounce_status = 'bounced', bounce_type = ?, bounced_at = ? WHERE id = ?")
            ->execute([$bounceType, $bouncedAt ?? date('Y-m-d H:i:s'), $leaderAssignmentId]);
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
     * $bouncedAt: see suppress()'s docblock -- same reasoning, defaults
     * to NOW() when the caller has no real per-event timestamp.
     *
     * @return array{domain:string, cascaded:int, suppressed:bool}
     */
    public static function suppressByEmail(PDO $db, string $email, int $userId, int $companyId, string $reason, ?string $bounceType = null, ?string $bouncedAt = null): array
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
        $bouncedAt = $bouncedAt ?? date('Y-m-d H:i:s');
        foreach ($leaderIds as $leaderId) {
            $db->prepare("UPDATE lead_campaign_assignments SET bounce_status = 'bounced', bounce_type = ?, bounced_at = ? WHERE id = ?")
                ->execute([$bounceType, $bouncedAt, $leaderId]);
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
