<?php

require_once __DIR__ . '/RoleGroupClassifier.php';
require_once __DIR__ . '/WaveAssigner.php';
require_once __DIR__ . '/LeadRepository.php';
require_once __DIR__ . '/Scope.php';
require_once __DIR__ . '/SaleshandyClient.php';

/**
 * CRUD + matching/splitting logic for ICP (Ideal Customer Profile) segments
 * -- see sql/025_icp_segments.sql and public/icp_distribution_cron.php.
 */
class IcpRepository
{
    /**
     * Every ICP visible to this scope, with joined labels + linked-campaign
     * summary. Admin sees every ICP in the company. Team Lead and Member
     * are both scoped to campaigns they PERSONALLY own (not team-pooled --
     * an explicit choice, since an ICP can auto-push into any campaign it
     * links, and pooling would let a Team Lead build an ICP that pushes
     * into a teammate's Saleshandy account without that teammate's say):
     * an ICP is visible if they created it (covers a fresh, still-empty
     * ICP) or they own at least one of its linked campaigns.
     * link_count/percentage_total still reflect the WHOLE ICP (every
     * link, not just the viewer's own) so a partially-visible ICP's
     * overall split still makes sense on screen -- individual link rows
     * are filtered separately, see links().
     *
     * @return array<int,array<string,mixed>>
     */
    public static function list(PDO $db, Scope $scope): array
    {
        [$clauses, $params] = self::visibilityClause($scope);
        $rows = self::selectWhere($db, implode(' AND ', $clauses), $params);

        foreach ($rows as &$r) {
            $r['link_count'] = (int) $r['link_count'];
            $r['percentage_total'] = (int) $r['percentage_total'];
        }
        unset($r);

        return $rows;
    }

    /**
     * Single ICP by id, subject to the exact same visibility rule as
     * list() (see its docblock) -- for the per-ICP detail page
     * (icp_segment_detail.php), so a Team Lead/Member typing another
     * team's ICP id into the URL gets a clean "not found" instead of a
     * page full of a segment they were never shown on the list.
     *
     * @return array<string,mixed>|null
     */
    public static function findVisible(PDO $db, Scope $scope, int $id): ?array
    {
        [$clauses, $params] = self::visibilityClause($scope);
        $clauses[] = 'icp.id = :id';
        $params['id'] = $id;
        $rows = self::selectWhere($db, implode(' AND ', $clauses), $params);
        if (!$rows) {
            return null;
        }
        $row = $rows[0];
        $row['link_count'] = (int) $row['link_count'];
        $row['percentage_total'] = (int) $row['percentage_total'];
        return $row;
    }

    /** @return array{0:array<int,string>,1:array<string,mixed>} */
    private static function visibilityClause(Scope $scope): array
    {
        $clauses = ['icp.company_id = :company_id'];
        $params = ['company_id' => $scope->companyId];
        if (!$scope->isAdmin()) {
            $clauses[] = '(icp.created_by = :user_id OR EXISTS (
                SELECT 1 FROM icp_campaign_links vl JOIN campaigns vc ON vc.id = vl.campaign_id
                 WHERE vl.icp_id = icp.id AND vc.saleshandy_account_owner_id = :user_id2
            ))';
            $params['user_id'] = $scope->userId;
            $params['user_id2'] = $scope->userId;
        }
        return [$clauses, $params];
    }

    /** @return array<int,array<string,mixed>> */
    private static function selectWhere(PDO $db, string $where, array $params): array
    {
        $stmt = $db->prepare(
            "SELECT icp.*, rg.label AS role_group_label, v.code AS vertical_code, v.label AS vertical_label,
                    s.code AS service_code, s.label AS service_label, cg.label AS country_group_label,
                    (SELECT COUNT(*) FROM icp_campaign_links l WHERE l.icp_id = icp.id) AS link_count,
                    (SELECT COALESCE(SUM(l.percentage), 0) FROM icp_campaign_links l WHERE l.icp_id = icp.id) AS percentage_total
               FROM icp_segments icp
               LEFT JOIN role_groups rg ON rg.id = icp.role_group_id
               LEFT JOIN verticals v ON v.id = icp.vertical_id
               LEFT JOIN services s ON s.id = icp.service_id
               LEFT JOIN country_groups cg ON cg.id = icp.country_group_id
              WHERE {$where}
              ORDER BY icp.name"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Whether every campaign currently linked to this ICP is personally
     * owned by $userId -- vacuously true for an ICP with zero links (a
     * fresh one its creator hasn't linked anything to yet). The gate for
     * every ICP-mutating action a non-admin (Team Lead or Member) takes:
     * they may only ever touch an ICP that's still "fully theirs".
     */
    private static function isFullyOwnedBySelf(PDO $db, int $icpId, int $userId): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM icp_campaign_links l JOIN campaigns c ON c.id = l.campaign_id
              WHERE l.icp_id = ? AND c.saleshandy_account_owner_id != ?'
        );
        $stmt->execute([$icpId, $userId]);
        return (int) $stmt->fetchColumn() === 0;
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

    /**
     * @param Scope|null $scope pass to filter down to only the campaigns
     *   the scope personally owns (Admin: unfiltered, same as omitting
     *   it) -- used for display on a partially-visible ICP, where a
     *   Team Lead/Member should only ever see their own slice of a
     *   split that also touches a teammate's campaign.
     * @return array<int,array{id:int,campaign_id:int,campaign_name:string,percentage:int,owner_id:int,owner_name:string}>
     */
    public static function links(PDO $db, int $icpId, ?Scope $scope = null): array
    {
        $ownerClause = '';
        $params = [$icpId];
        if ($scope !== null && !$scope->isAdmin()) {
            $ownerClause = ' AND c.saleshandy_account_owner_id = ?';
            $params[] = $scope->userId;
        }
        $stmt = $db->prepare(
            "SELECT l.id, l.campaign_id, c.name AS campaign_name, l.percentage,
                    c.saleshandy_account_owner_id AS owner_id, u.name AS owner_name
               FROM icp_campaign_links l
               JOIN campaigns c ON c.id = l.campaign_id
               LEFT JOIN users u ON u.id = c.saleshandy_account_owner_id
              WHERE l.icp_id = ?{$ownerClause}
              ORDER BY l.percentage DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function linksSumTo100(PDO $db, int $icpId): bool
    {
        $stmt = $db->prepare('SELECT COALESCE(SUM(percentage), 0) FROM icp_campaign_links WHERE icp_id = ?');
        $stmt->execute([$icpId]);
        return (int) $stmt->fetchColumn() === 100;
    }

    /** @param array<string,mixed> $data */
    public static function create(PDO $db, array $data, int $userId, int $companyId): int
    {
        $stmt = $db->prepare(
            'INSERT INTO icp_segments
                (company_id, name, role_group_id, vertical_id, service_id, country_group_id, company_country, industry, seniority, employee_count, auto_push_enabled, require_sequence_completed, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $companyId, $data['name'], $data['role_group_id'] ?: null, $data['vertical_id'] ?: null, $data['service_id'] ?: null, $data['country_group_id'] ?: null,
            $data['company_country'] ?: null, $data['industry'] ?: null, $data['seniority'] ?: null, $data['employee_count'] ?: null,
            !empty($data['auto_push_enabled']) ? 1 : 0, !empty($data['require_sequence_completed']) ? 1 : 0, $userId,
        ]);
        return (int) $db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(PDO $db, int $id, array $data, Scope $scope): void
    {
        if (!$scope->isAdmin() && !self::isFullyOwnedBySelf($db, $id, $scope->userId)) {
            return;
        }
        $stmt = $db->prepare(
            'UPDATE icp_segments
                SET name = ?, role_group_id = ?, vertical_id = ?, service_id = ?, country_group_id = ?,
                    company_country = ?, industry = ?, seniority = ?, employee_count = ?, auto_push_enabled = ?,
                    require_sequence_completed = ?
              WHERE id = ? AND company_id = ?'
        );
        $stmt->execute([
            $data['name'], $data['role_group_id'] ?: null, $data['vertical_id'] ?: null, $data['service_id'] ?: null, $data['country_group_id'] ?: null,
            $data['company_country'] ?: null, $data['industry'] ?: null, $data['seniority'] ?: null, $data['employee_count'] ?: null,
            !empty($data['auto_push_enabled']) ? 1 : 0, !empty($data['require_sequence_completed']) ? 1 : 0, $id, $scope->companyId,
        ]);
    }

    public static function toggleActive(PDO $db, int $id, Scope $scope): void
    {
        if (!$scope->isAdmin() && !self::isFullyOwnedBySelf($db, $id, $scope->userId)) {
            return;
        }
        $db->prepare('UPDATE icp_segments SET is_active = NOT is_active WHERE id = ? AND company_id = ?')->execute([$id, $scope->companyId]);
    }

    /**
     * Links a campaign at a placeholder 0% and immediately rebalances
     * every link on this ICP to an even split -- so an admin never has
     * to hand-pick a percentage that happens to make the total land on
     * 100 (1 link -> 100%, 2 -> 50/50, 3 -> 34/33/33, etc). Both the ICP
     * and the campaign must belong to the acting scope's own company --
     * rejects otherwise, since an ICP and a campaign from different
     * companies can never legitimately be linked (see sql/032's note on
     * icp_segments.company_id needing to match every linked campaign's).
     * A non-admin (Team Lead or Member) may only link a campaign they
     * personally own, and only onto an ICP that's still fully theirs --
     * never a teammate's campaign, and never an ICP that already touches
     * one (see isFullyOwnedBySelf()).
     */
    public static function addLink(PDO $db, int $icpId, int $campaignId, Scope $scope): void
    {
        $matchStmt = $db->prepare(
            'SELECT icp.company_id = ? AND c.company_id = ? AS same_company, c.saleshandy_account_owner_id AS campaign_owner_id
               FROM icp_segments icp, campaigns c WHERE icp.id = ? AND c.id = ?'
        );
        $matchStmt->execute([$scope->companyId, $scope->companyId, $icpId, $campaignId]);
        $row = $matchStmt->fetch();
        if (!$row || !$row['same_company']) {
            throw new InvalidArgumentException('That campaign belongs to a different company than this ICP segment.');
        }
        if (!$scope->isAdmin()) {
            if ((int) $row['campaign_owner_id'] !== $scope->userId) {
                throw new InvalidArgumentException('You can only link campaigns you own.');
            }
            if (!self::isFullyOwnedBySelf($db, $icpId, $scope->userId)) {
                throw new InvalidArgumentException('This ICP already includes a campaign you don\'t own -- ask an admin to change its links.');
            }
        }

        $stmt = $db->prepare('INSERT INTO icp_campaign_links (icp_id, campaign_id, percentage) VALUES (?, ?, 0)');
        $stmt->execute([$icpId, $campaignId]);
        self::rebalanceEvenly($db, $icpId, $scope);
    }

    /** Removes a link, then rebalances whatever campaigns remain back to an even split. */
    public static function removeLink(PDO $db, int $linkId, Scope $scope): void
    {
        $stmt = $db->prepare(
            'SELECT l.icp_id FROM icp_campaign_links l JOIN icp_segments icp ON icp.id = l.icp_id WHERE l.id = ? AND icp.company_id = ?'
        );
        $stmt->execute([$linkId, $scope->companyId]);
        $icpId = $stmt->fetchColumn();

        if ($icpId === false) {
            return;
        }
        if (!$scope->isAdmin() && !self::isFullyOwnedBySelf($db, (int) $icpId, $scope->userId)) {
            return;
        }

        $db->prepare('DELETE FROM icp_campaign_links WHERE id = ?')->execute([$linkId]);
        self::rebalanceEvenly($db, (int) $icpId, $scope);
    }

    /**
     * Resets every link on an ICP to an even percentage split summing to
     * exactly 100 (any remainder from an uneven division goes to the
     * first links, by id order). Called automatically by addLink()/
     * removeLink() so the total is always valid without an admin doing
     * the arithmetic; also callable directly (an "Auto-split evenly"
     * button) to discard a manual custom split, or to fix an ICP whose
     * links don't currently sum to 100 for any other reason.
     */
    public static function rebalanceEvenly(PDO $db, int $icpId, Scope $scope): void
    {
        $ownStmt = $db->prepare('SELECT 1 FROM icp_segments WHERE id = ? AND company_id = ?');
        $ownStmt->execute([$icpId, $scope->companyId]);
        if (!$ownStmt->fetchColumn()) {
            return;
        }
        if (!$scope->isAdmin() && !self::isFullyOwnedBySelf($db, $icpId, $scope->userId)) {
            return;
        }

        $stmt = $db->prepare('SELECT id FROM icp_campaign_links WHERE icp_id = ? ORDER BY id');
        $stmt->execute([$icpId]);
        $linkIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $count = count($linkIds);
        if ($count === 0) {
            return;
        }

        $base = intdiv(100, $count);
        $remainder = 100 % $count;

        $update = $db->prepare('UPDATE icp_campaign_links SET percentage = ? WHERE id = ?');
        foreach ($linkIds as $i => $linkId) {
            $update->execute([$base + ($i < $remainder ? 1 : 0), $linkId]);
        }
    }

    /**
     * Applies an admin-chosen custom split (e.g. 70/30 weighting for
     * A/B testing) instead of the even default -- rejects the whole
     * update (no writes at all) unless every currently-linked campaign
     * is present, each value is 1-100, and they sum to exactly 100, so
     * an ICP can never be left half-updated or not summing to 100.
     *
     * @param array<int,int> $percentagesByLinkId link_id => percentage
     */
    public static function updateLinkPercentages(PDO $db, int $icpId, array $percentagesByLinkId, Scope $scope): bool
    {
        $ownStmt = $db->prepare('SELECT 1 FROM icp_segments WHERE id = ? AND company_id = ?');
        $ownStmt->execute([$icpId, $scope->companyId]);
        if (!$ownStmt->fetchColumn()) {
            return false;
        }
        if (!$scope->isAdmin() && !self::isFullyOwnedBySelf($db, $icpId, $scope->userId)) {
            return false;
        }

        $stmt = $db->prepare('SELECT id FROM icp_campaign_links WHERE icp_id = ?');
        $stmt->execute([$icpId]);
        $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (!$existingIds || count($existingIds) !== count($percentagesByLinkId)) {
            return false;
        }

        $sum = 0;
        foreach ($existingIds as $id) {
            if (!array_key_exists($id, $percentagesByLinkId)) {
                return false;
            }
            $pct = (int) $percentagesByLinkId[$id];
            if ($pct < 1 || $pct > 100) {
                return false;
            }
            $sum += $pct;
        }
        if ($sum !== 100) {
            return false;
        }

        $update = $db->prepare('UPDATE icp_campaign_links SET percentage = ? WHERE id = ? AND icp_id = ?');
        foreach ($percentagesByLinkId as $linkId => $pct) {
            $update->execute([(int) $pct, (int) $linkId, $icpId]);
        }
        return true;
    }

    /**
     * One row per ICP with Saleshandy performance aggregated across every
     * lead this ICP's own distribution cron run(s) actually assigned
     * (lead_campaign_assignments.icp_id, sql/028) -- deliberately NOT
     * "every lead in this ICP's linked campaigns", since a campaign can
     * be linked to more than one ICP (verified safe elsewhere in this
     * app) and a lead added to it manually was never caused by this ICP.
     * Assignments made before icp_id tracking existed have icp_id = NULL
     * and so are invisible here even if they're sitting in a linked
     * campaign -- only newly-made assignments are attributed.
     *
     * "Pending push" mirrors SaleshandyClient::pushCampaignLeads()'s
     * exact eligibility check (wave_status active, not yet pushed, lead
     * not domain-suppressed, not soft-deleted) -- i.e. what the next
     * auto-push cron run (or a manual "Push to Saleshandy" click) would
     * actually pick up right now.
     *
     * Visibility and per-row scoping follow list(): Admin sees every ICP
     * and its full stats; a non-admin only sees ICPs they created or own
     * a link on, and the campaign_names / aggregated counts are limited
     * to campaigns they personally own -- a teammate's slice of a shared
     * ICP never shows up in their numbers.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function performanceStats(PDO $db, Scope $scope): array
    {
        $isAdmin = $scope->isAdmin();
        $campaignNamesOwnerClause = $isAdmin ? '' : ' AND c.saleshandy_account_owner_id = :owner_id_names';
        $assignmentOwnerClause = $isAdmin ? '' : ' AND EXISTS (SELECT 1 FROM campaigns oc WHERE oc.id = a.campaign_id AND oc.saleshandy_account_owner_id = :owner_id_assign)';
        $visibilityClause = 'icp.company_id = :company_id';
        if (!$isAdmin) {
            $visibilityClause .= ' AND (icp.created_by = :owner_id_visible OR EXISTS (
                SELECT 1 FROM icp_campaign_links vl JOIN campaigns vc ON vc.id = vl.campaign_id
                 WHERE vl.icp_id = icp.id AND vc.saleshandy_account_owner_id = :owner_id_visible2
            ))';
        }

        $stmt = $db->prepare(
            "SELECT icp.id, icp.name, icp.is_active, icp.auto_push_enabled,
                    (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
                       FROM icp_campaign_links lk JOIN campaigns c ON c.id = lk.campaign_id
                      WHERE lk.icp_id = icp.id{$campaignNamesOwnerClause}) AS campaign_names,
                    COUNT(a.id) AS leads_assigned,
                    SUM(CASE WHEN a.wave_status = 'active' AND a.status != 'pushed'
                              AND NOT EXISTS (SELECT 1 FROM suppressed_domains sd WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1) AND sd.company_id = l.company_id)
                             THEN 1 ELSE 0 END) AS pending_push,
                    SUM(CASE WHEN a.status = 'pushed' THEN 1 ELSE 0 END) AS pushed,
                    SUM(CASE WHEN a.email_sent = 1 THEN 1 ELSE 0 END) AS emails_sent,
                    SUM(CASE WHEN a.bounce_status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN a.bounce_status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
                    SUM(CASE WHEN a.open_count > 0 THEN 1 ELSE 0 END) AS opened,
                    SUM(CASE WHEN a.reply_sentiment IS NOT NULL THEN 1 ELSE 0 END) AS replied
               FROM icp_segments icp
               LEFT JOIN lead_campaign_assignments a ON a.icp_id = icp.id{$assignmentOwnerClause}
               LEFT JOIN leads l ON l.id = a.lead_id
              WHERE (l.deleted_at IS NULL OR a.id IS NULL) AND {$visibilityClause}
              GROUP BY icp.id
              ORDER BY icp.name"
        );
        $params = ['company_id' => $scope->companyId];
        if (!$isAdmin) {
            $params['owner_id_names'] = $scope->userId;
            $params['owner_id_assign'] = $scope->userId;
            $params['owner_id_visible'] = $scope->userId;
            $params['owner_id_visible2'] = $scope->userId;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            foreach (['leads_assigned', 'pending_push', 'pushed', 'emails_sent', 'delivered', 'bounced', 'opened', 'replied'] as $col) {
                $r[$col] = (int) $r[$col];
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * Maps an icp_segments row to the exact filter shape
     * LeadRepository::buildWhere()/matchingIds() already accepts --
     * comma-lists parsed the same way RoleGroupClassifier::parseKeywords()
     * already splits role_groups.keywords, so no new parsing logic.
     *
     * Scoped via 'assignable_after_cooldown_days' (LeadRepository.php,
     * next to assigned_campaign_id) rather than a flat "never assigned"
     * check -- a lead is eligible if it's never been assigned to any
     * campaign, OR its latest assignment is both resolved (not held, not
     * still pending -- see WaveAssigner::PENDING_ASSIGNMENT_SQL) and older
     * than $scope's company's lead_cooldown_days. A suppressed-domain
     * lead is excluded regardless, via buildWhere()'s show_suppressed
     * default.
     *
     * If icp_segments.require_sequence_completed is on (opt-in, see
     * sql/043_icp_require_sequence_completed.sql), a previously-assigned
     * lead must ALSO have actually finished its last campaign's sequence
     * (saleshandy_current_step >= that campaign's saleshandy_step_count,
     * delivery_status still 'Active' -- see
     * public/campaign_leads.php's "Sequence completed" filter for the
     * same rule applied manually) before it re-qualifies -- narrower
     * than the plain cooldown rule, not a replacement for it, so a lead
     * still needs the cooldown window to pass too.
     *
     * @param array<string,mixed> $icp a row from icp_segments
     * @return array<string,mixed>
     */
    public static function toFilters(array $icp, Scope $scope): array
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
            'country_group_id' => $icp['country_group_id'] ?: '',
            'assignable_after_cooldown_days' => $scope->leadCooldownDays,
            'require_sequence_completed_if_reassigning' => !empty($icp['require_sequence_completed']),
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

    /**
     * The distribution pass, round-robin style: processes just ONE
     * active ICP (with links summing to 100%) per call -- whichever has
     * gone longest without an attempt (oldest last_distribution_attempt_at,
     * NULL/never-attempted treated as oldest) -- instead of looping
     * every eligible ICP in a single request. An ICP with auto-push
     * enabled and several linked campaigns makes real Saleshandy API
     * calls per campaign it's linked to, so looping many such ICPs in
     * one request carries the same slow/timeout risk a many-campaign
     * Saleshandy sync does (see SaleshandyClient::syncNextCampaign()).
     *
     * Ordered by "last ATTEMPT" (updated unconditionally below, success
     * or failure) rather than anything tied to success, so a
     * persistently failing ICP can't get retried on every single call
     * forever and starve every other ICP from ever being processed.
     *
     * Auto-pushes for any ICP with auto_push_enabled=1 -- attempted for
     * EVERY linked campaign of that ICP, regardless of whether this call
     * assigned anything new to it, since pushCampaignLeads() re-checks
     * "who in this campaign is wave-1-active and not yet pushed" from
     * scratch every time, so it naturally sweeps up a lead that was HELD
     * earlier and only just got released (e.g. by the Saleshandy sync
     * cron resolving its wave-1 leader as delivered). Each linked
     * campaign's push uses that campaign's own owner's Saleshandy
     * account (SaleshandyClient::forUser()) -- an ICP can link campaigns
     * owned by different members, so there's no single shared client for
     * the whole ICP; a campaign whose owner hasn't connected a key is
     * skipped, not failed.
     *
     * @param int|null $companyId restrict to this company's ICPs (the
     *   scheduled cron passes null -- it deliberately sweeps every
     *   company in rotation; the manual "Run now" button always passes
     *   the clicking user's own company, so a click can never touch
     *   another tenant's data).
     * @param int|null $restrictToUserId restrict to ICPs this user could
     *   also mutate (see isFullyOwnedBySelf()) -- a Team Lead/Member's
     *   manual "Run now" click only ever distributes into their own
     *   campaigns, same rule as every other ICP action they take. Admin
     *   passes null, unrestricted within their company.
     * @return array{summary:string,lines:array<int,string>}
     */
    public static function runDistributionForNext(PDO $db, int $systemUserId, ?int $companyId = null, ?int $restrictToUserId = null): array
    {
        $icp = self::nextForDistribution($db, $companyId, $restrictToUserId);
        if (!$icp) {
            $summary = $restrictToUserId !== null
                ? 'No active ICP of yours has campaign links summing to 100% -- nothing to do.'
                : 'No active ICP segments with campaign links summing to 100% -- nothing to do.';
            return ['summary' => $summary, 'lines' => []];
        }

        $result = self::processIcp($db, $icp, $systemUserId);
        $db->prepare('UPDATE icp_segments SET last_distribution_attempt_at = NOW() WHERE id = ?')->execute([(int) $icp['id']]);

        $summary = "\"{$icp['name']}\": " . ($result['had_matches']
            ? "{$result['assigned']} lead(s) assigned" . ($icp['auto_push_enabled'] ? ", {$result['pushed']} lead(s) auto-pushed" : '')
            : 'no new matching leads');

        return ['summary' => $summary, 'lines' => $result['lines']];
    }

    /**
     * Manually distribute ONE specific ICP by id, instead of round-robin
     * "whichever is due next" -- the individual-action counterpart to
     * runDistributionForNext(), for Sync Center's per-ICP "Distribute
     * now" button. Same ownership/100%-links gating as every other
     * ICP-mutating action (isFullyOwnedBySelf()).
     *
     * @return array{ok:bool,summary:string,lines:array<int,string>}
     */
    public static function runDistributionForIcp(PDO $db, Scope $scope, int $icpId): array
    {
        $stmt = $db->prepare('SELECT * FROM icp_segments WHERE id = ? AND company_id = ?');
        $stmt->execute([$icpId, $scope->companyId]);
        $icp = $stmt->fetch();
        if (!$icp) {
            return ['ok' => false, 'summary' => 'ICP segment not found.', 'lines' => []];
        }
        if (!$scope->isAdmin() && !self::isFullyOwnedBySelf($db, $icpId, $scope->userId)) {
            return ['ok' => false, 'summary' => 'You can only distribute an ICP made up entirely of your own campaigns.', 'lines' => []];
        }
        if (!self::linksSumTo100($db, $icpId)) {
            return ['ok' => false, 'summary' => "\"{$icp['name']}\": campaign link percentages don't sum to 100% -- fix the split first.", 'lines' => []];
        }

        $result = self::processIcp($db, $icp, $scope->userId);
        $db->prepare('UPDATE icp_segments SET last_distribution_attempt_at = NOW() WHERE id = ?')->execute([$icpId]);

        $summary = "\"{$icp['name']}\": " . ($result['had_matches']
            ? "{$result['assigned']} lead(s) assigned" . ($icp['auto_push_enabled'] ? ", {$result['pushed']} lead(s) auto-pushed" : '')
            : 'no new matching leads');

        return ['ok' => true, 'summary' => $summary, 'lines' => $result['lines']];
    }

    /**
     * The active ICP (with links summing to 100%) that's gone longest
     * without a distribution attempt -- skips (without touching its
     * attempt timestamp) any active ICP whose links don't currently sum
     * to 100%, since that's a config problem to fix, not something worth
     * spending an attempt slot retrying every call. Also skips (without
     * touching the timestamp) any ICP outside $companyId/$restrictToUserId
     * when given, for the same reason -- it's not eligible for this
     * caller, not a config problem for anyone to fix.
     */
    private static function nextForDistribution(PDO $db, ?int $companyId = null, ?int $restrictToUserId = null): ?array
    {
        $clauses = ['is_active = 1'];
        $params = [];
        if ($companyId !== null) {
            $clauses[] = 'company_id = ?';
            $params[] = $companyId;
        }
        $stmt = $db->prepare(
            'SELECT * FROM icp_segments WHERE ' . implode(' AND ', $clauses) . '
              ORDER BY (last_distribution_attempt_at IS NOT NULL), last_distribution_attempt_at ASC'
        );
        $stmt->execute($params);
        $icps = $stmt->fetchAll();

        foreach ($icps as $icp) {
            if (!self::linksSumTo100($db, (int) $icp['id'])) {
                continue;
            }
            if ($restrictToUserId !== null && !self::isFullyOwnedBySelf($db, (int) $icp['id'], $restrictToUserId)) {
                continue;
            }
            return $icp;
        }
        return null;
    }

    /** @return array{lines:array<int,string>,had_matches:bool,assigned:int,pushed:int} */
    private static function processIcp(PDO $db, array $icp, int $systemUserId): array
    {
        $lines = [];
        $assigned = 0;
        $pushed = 0;
        $hadMatches = false;

        try {
            $links = self::links($db, (int) $icp['id']);
            // The cron processes ICPs across every company in rotation --
            // icp_segments.company_id (not the cron's own caller identity)
            // is what determines which tenant's leads this particular
            // ICP is allowed to match against.
            $scope = Scope::fromUser($db, [
                'id' => $systemUserId,
                'company_id' => (int) $icp['company_id'],
                'role' => ROLE_ADMIN,
                'team_id' => null,
            ]);
            $filters = self::toFilters($icp, $scope);
            $matchingIds = LeadRepository::matchingIds($db, $scope, $filters);

            $buckets = [];
            $titlePriority = [];
            if ($matchingIds) {
                $hadMatches = true;
                shuffle($matchingIds);

                $roleGroupStmt = $db->prepare('SELECT keywords FROM role_groups WHERE id = ?');
                $roleGroupStmt->execute([$icp['role_group_id']]);
                $titlePriority = RoleGroupClassifier::parseKeywords((string) $roleGroupStmt->fetchColumn());

                $buckets = self::splitLeadIds($matchingIds, $links);
                $lines[] = "\"{$icp['name']}\": " . count($matchingIds) . ' matching lead(s) split across ' . count($links) . ' campaign(s):';
            } else {
                $lines[] = "\"{$icp['name']}\": no new matching leads.";
            }

            foreach ($links as $link) {
                $bucket = $buckets[(int) $link['campaign_id']] ?? [];
                if ($bucket) {
                    $stats = WaveAssigner::assign($db, $bucket, (int) $link['campaign_id'], $systemUserId, $titlePriority, $scope->leadCooldownDays, (int) $icp['id']);
                    $assigned += $stats['leaders'] + $stats['held'] + $stats['reassigned_sent'];
                    $lines[] = "  - \"{$link['campaign_name']}\" ({$link['percentage']}%): {$stats['leaders']} leader(s), {$stats['held']} held, "
                        . "{$stats['reassigned_sent']} sent directly (previously proven), "
                        . "{$stats['suppressed_skipped']} suppressed, {$stats['already_elsewhere_skipped']} already elsewhere, "
                        . "{$stats['pending_elsewhere_skipped']} pending elsewhere";
                    if ($stats['pending_elsewhere_skipped'] > 0) {
                        // Which OTHER campaign(s) each blocked account's
                        // pending, unconfirmed persona is currently sitting
                        // in -- same detail lead_bulk_campaign_add.php/
                        // campaign_select_leads.php already show, so it's
                        // not just a bare count here either. These leads
                        // aren't lost: WaveAssigner re-checks this fresh
                        // every run, so once that other campaign's send
                        // resolves (delivered/bounced/replied), the next
                        // distribution attempt picks them up automatically.
                        $parts = [];
                        foreach ($stats['pending_elsewhere_campaigns'] as $blockingCampaignName => $count) {
                            $parts[] = "{$count} in \"{$blockingCampaignName}\"";
                        }
                        $lines[] = '    (pending elsewhere: ' . implode(', ', $parts) . ')';
                    }
                } elseif ($matchingIds) {
                    $lines[] = "  - \"{$link['campaign_name']}\" ({$link['percentage']}%): 0 lead(s)";
                }

                if ($icp['auto_push_enabled']) {
                    $campStmt = $db->prepare('SELECT * FROM campaigns WHERE id = ?');
                    $campStmt->execute([(int) $link['campaign_id']]);
                    $campaign = $campStmt->fetch();
                    if ($campaign) {
                        try {
                            $client = SaleshandyClient::forUser($db, (int) $campaign['saleshandy_account_owner_id']);
                            $pushResult = $client->pushCampaignLeads($db, $campaign, false);
                            $pushed += $pushResult['pushed'];
                            if ($pushResult['pushed'] > 0 || $pushResult['errors']) {
                                $lines[] = "    auto-push (\"{$link['campaign_name']}\"): {$pushResult['pushed']} pushed, {$pushResult['skipped_bad']} bad, {$pushResult['skipped_risky']} risky";
                                if ($pushResult['errors']) {
                                    $lines[] = '    auto-push errors: ' . implode('; ', $pushResult['errors']);
                                }
                            }
                        } catch (SaleshandyApiException $ex) {
                            $lines[] = "    auto-push skipped (\"{$link['campaign_name']}\"): {$ex->getMessage()}";
                        }
                    }
                }
            }
        } catch (Throwable $ex) {
            $lines[] = "\"{$icp['name']}\": FAILED -- {$ex->getMessage()}";
        }

        return ['lines' => $lines, 'had_matches' => $hadMatches, 'assigned' => $assigned, 'pushed' => $pushed];
    }
}
