<?php

require_once __DIR__ . '/RoleGroupClassifier.php';
require_once __DIR__ . '/WaveAssigner.php';
require_once __DIR__ . '/LeadRepository.php';

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
                    cg.label AS country_group_label,
                    (SELECT COUNT(*) FROM icp_campaign_links l WHERE l.icp_id = icp.id) AS link_count,
                    (SELECT COALESCE(SUM(l.percentage), 0) FROM icp_campaign_links l WHERE l.icp_id = icp.id) AS percentage_total
               FROM icp_segments icp
               LEFT JOIN role_groups rg ON rg.id = icp.role_group_id
               LEFT JOIN verticals v ON v.id = icp.vertical_id
               LEFT JOIN services s ON s.id = icp.service_id
               LEFT JOIN country_groups cg ON cg.id = icp.country_group_id
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
                (name, role_group_id, vertical_id, service_id, country_group_id, company_country, industry, seniority, employee_count, auto_push_enabled, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'], $data['role_group_id'] ?: null, $data['vertical_id'] ?: null, $data['service_id'] ?: null, $data['country_group_id'] ?: null,
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
                SET name = ?, role_group_id = ?, vertical_id = ?, service_id = ?, country_group_id = ?,
                    company_country = ?, industry = ?, seniority = ?, employee_count = ?, auto_push_enabled = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $data['name'], $data['role_group_id'] ?: null, $data['vertical_id'] ?: null, $data['service_id'] ?: null, $data['country_group_id'] ?: null,
            $data['company_country'] ?: null, $data['industry'] ?: null, $data['seniority'] ?: null, $data['employee_count'] ?: null,
            !empty($data['auto_push_enabled']) ? 1 : 0, $id,
        ]);
    }

    public static function toggleActive(PDO $db, int $id): void
    {
        $db->prepare('UPDATE icp_segments SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
    }

    /**
     * Links a campaign at a placeholder 0% and immediately rebalances
     * every link on this ICP to an even split -- so an admin never has
     * to hand-pick a percentage that happens to make the total land on
     * 100 (1 link -> 100%, 2 -> 50/50, 3 -> 34/33/33, etc).
     */
    public static function addLink(PDO $db, int $icpId, int $campaignId): void
    {
        $stmt = $db->prepare('INSERT INTO icp_campaign_links (icp_id, campaign_id, percentage) VALUES (?, ?, 0)');
        $stmt->execute([$icpId, $campaignId]);
        self::rebalanceEvenly($db, $icpId);
    }

    /** Removes a link, then rebalances whatever campaigns remain back to an even split. */
    public static function removeLink(PDO $db, int $linkId): void
    {
        $stmt = $db->prepare('SELECT icp_id FROM icp_campaign_links WHERE id = ?');
        $stmt->execute([$linkId]);
        $icpId = $stmt->fetchColumn();

        $db->prepare('DELETE FROM icp_campaign_links WHERE id = ?')->execute([$linkId]);

        if ($icpId !== false) {
            self::rebalanceEvenly($db, (int) $icpId);
        }
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
    public static function rebalanceEvenly(PDO $db, int $icpId): void
    {
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
    public static function updateLinkPercentages(PDO $db, int $icpId, array $percentagesByLinkId): bool
    {
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
     * @return array<int,array<string,mixed>>
     */
    public static function performanceStats(PDO $db): array
    {
        $rows = $db->query(
            "SELECT icp.id, icp.name, icp.is_active, icp.auto_push_enabled,
                    (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
                       FROM icp_campaign_links lk JOIN campaigns c ON c.id = lk.campaign_id
                      WHERE lk.icp_id = icp.id) AS campaign_names,
                    COUNT(a.id) AS leads_assigned,
                    SUM(CASE WHEN a.wave_status = 'active' AND a.status != 'pushed'
                              AND NOT EXISTS (SELECT 1 FROM suppressed_domains sd WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1))
                             THEN 1 ELSE 0 END) AS pending_push,
                    SUM(CASE WHEN a.status = 'pushed' THEN 1 ELSE 0 END) AS pushed,
                    SUM(CASE WHEN a.email_sent = 1 THEN 1 ELSE 0 END) AS emails_sent,
                    SUM(CASE WHEN a.bounce_status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN a.bounce_status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
                    SUM(CASE WHEN a.open_count > 0 THEN 1 ELSE 0 END) AS opened,
                    SUM(CASE WHEN a.reply_sentiment IS NOT NULL THEN 1 ELSE 0 END) AS replied
               FROM icp_segments icp
               LEFT JOIN lead_campaign_assignments a ON a.icp_id = icp.id
               LEFT JOIN leads l ON l.id = a.lead_id
              WHERE l.deleted_at IS NULL OR a.id IS NULL
              GROUP BY icp.id
              ORDER BY icp.name"
        )->fetchAll();

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
            'country_group_id' => $icp['country_group_id'] ?: '',
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
     * Pass a non-null $client to also auto-push for any ICP with
     * auto_push_enabled=1 -- attempted for EVERY linked campaign of that
     * ICP, regardless of whether this call assigned anything new to it,
     * since pushCampaignLeads() re-checks "who in this campaign is
     * wave-1-active and not yet pushed" from scratch every time, so it
     * naturally sweeps up a lead that was HELD earlier and only just got
     * released (e.g. by the Saleshandy sync cron resolving its wave-1
     * leader as delivered).
     *
     * @return array{summary:string,lines:array<int,string>}
     */
    public static function runDistributionForNext(PDO $db, ?SaleshandyClient $client, int $systemUserId): array
    {
        $icp = self::nextForDistribution($db);
        if (!$icp) {
            return ['summary' => 'No active ICP segments with campaign links summing to 100% -- nothing to do.', 'lines' => []];
        }

        $result = self::processIcp($db, $icp, $client, $systemUserId);
        $db->prepare('UPDATE icp_segments SET last_distribution_attempt_at = NOW() WHERE id = ?')->execute([(int) $icp['id']]);

        $summary = "\"{$icp['name']}\": " . ($result['had_matches']
            ? "{$result['assigned']} lead(s) assigned" . ($client !== null ? ", {$result['pushed']} lead(s) auto-pushed" : '')
            : 'no new matching leads');

        return ['summary' => $summary, 'lines' => $result['lines']];
    }

    /**
     * The active ICP (with links summing to 100%) that's gone longest
     * without a distribution attempt -- skips (without touching its
     * attempt timestamp) any active ICP whose links don't currently sum
     * to 100%, since that's a config problem to fix, not something worth
     * spending an attempt slot retrying every call.
     */
    private static function nextForDistribution(PDO $db): ?array
    {
        $icps = $db->query(
            'SELECT * FROM icp_segments WHERE is_active = 1
              ORDER BY (last_distribution_attempt_at IS NOT NULL), last_distribution_attempt_at ASC'
        )->fetchAll();

        foreach ($icps as $icp) {
            if (self::linksSumTo100($db, (int) $icp['id'])) {
                return $icp;
            }
        }
        return null;
    }

    /** @return array{lines:array<int,string>,had_matches:bool,assigned:int,pushed:int} */
    private static function processIcp(PDO $db, array $icp, ?SaleshandyClient $client, int $systemUserId): array
    {
        $lines = [];
        $assigned = 0;
        $pushed = 0;
        $hadMatches = false;

        try {
            $links = self::links($db, (int) $icp['id']);
            $filters = self::toFilters($icp);
            $matchingIds = LeadRepository::matchingIds($db, $filters);

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
                    $stats = WaveAssigner::assign($db, $bucket, (int) $link['campaign_id'], $systemUserId, $titlePriority, (int) $icp['id']);
                    $assigned += $stats['leaders'] + $stats['held'];
                    $lines[] = "  - \"{$link['campaign_name']}\" ({$link['percentage']}%): {$stats['leaders']} leader(s), {$stats['held']} held, "
                        . "{$stats['suppressed_skipped']} suppressed, {$stats['already_elsewhere_skipped']} already elsewhere, "
                        . "{$stats['pending_elsewhere_skipped']} pending elsewhere";
                } elseif ($matchingIds) {
                    $lines[] = "  - \"{$link['campaign_name']}\" ({$link['percentage']}%): 0 lead(s)";
                }

                if ($icp['auto_push_enabled'] && $client !== null) {
                    $campStmt = $db->prepare('SELECT * FROM campaigns WHERE id = ?');
                    $campStmt->execute([(int) $link['campaign_id']]);
                    $campaign = $campStmt->fetch();
                    if ($campaign) {
                        try {
                            $pushResult = $client->pushCampaignLeads($db, $campaign, false);
                            $pushed += $pushResult['pushed'];
                            if ($pushResult['pushed'] > 0 || $pushResult['errors']) {
                                $lines[] = "    auto-push (\"{$link['campaign_name']}\"): {$pushResult['pushed']} pushed, {$pushResult['skipped_bad']} bad, {$pushResult['skipped_risky']} risky";
                                if ($pushResult['errors']) {
                                    $lines[] = '    auto-push errors: ' . implode('; ', $pushResult['errors']);
                                }
                            }
                        } catch (SaleshandyApiException $ex) {
                            $lines[] = "    auto-push FAILED (\"{$link['campaign_name']}\"): {$ex->getMessage()}";
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
