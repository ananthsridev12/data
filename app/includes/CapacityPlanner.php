<?php

require_once __DIR__ . '/SaleshandyClient.php';
require_once __DIR__ . '/ScopeFilter.php';

/**
 * "Given the campaigns/steps/email accounts we have, how much sending
 * capacity do we have and how long will our current backlog take?"
 * See public/capacity_planner.php.
 *
 * Model: Saleshandy sends per EMAIL ACCOUNT, capped at
 * companies.assumed_daily_send_limit per account per day -- Saleshandy's
 * API exposes no actual per-account limit (see sql/036_capacity_planner.sql),
 * so this is a company-configurable assumption, not a pulled value. A
 * campaign's accounts are whichever ones belong to its
 * saleshandy_account_owner_id -- account-to-sequence attachment isn't
 * tracked locally, so capacity is pooled per owner and split across that
 * owner's own live campaigns, proportional to each campaign's share of
 * the owner's backlog. At steady state, sends/day = new leads
 * enrolled/day x steps/lead, so:
 *   max new leads/day (owner)    = owner's daily capacity / weighted-avg steps
 *   max new leads/day (campaign) = owner rate x (campaign backlog / owner backlog)
 *   days to clear backlog        = campaign backlog / that rate
 *   days to fully finish         = days to clear backlog + campaign's cadence
 *
 * "Backlog" = leads not yet finished with the sequence (delivery_status
 * NULL/not-yet-pushed, 'Active', or 'Paused') -- Replied/Bounced/etc.
 * leads no longer consume future send capacity.
 */
final class CapacityPlanner
{
    /** Refreshes one member's active-email-account count from Saleshandy. */
    public static function refreshOwner(PDO $db, int $ownerId): void
    {
        $client = SaleshandyClient::forUser($db, $ownerId);
        $active = 0;
        foreach ($client->listEmailAccounts() as $account) {
            if ((int) ($account['status'] ?? -1) === 1) {
                $active++;
            }
        }
        $db->prepare('UPDATE users SET saleshandy_active_email_accounts = ?, saleshandy_capacity_synced_at = NOW() WHERE id = ?')
            ->execute([$active, $ownerId]);
    }

    /** Refreshes one campaign's step count/cadence from its linked sequence. */
    public static function refreshCampaign(PDO $db, SaleshandyClient $client, array $campaign): void
    {
        $steps = $client->listSequenceSteps($campaign['saleshandy_sequence_id']);
        $cadenceDays = 0;
        foreach ($steps as $step) {
            $cadenceDays = max($cadenceDays, (int) ($step['relativeDays'] ?? 0));
        }
        $db->prepare('UPDATE campaigns SET saleshandy_step_count = ?, saleshandy_cadence_days = ?, saleshandy_capacity_synced_at = NOW() WHERE id = ?')
            ->execute([count($steps), $cadenceDays, $campaign['id']]);
    }

    /**
     * Refreshes every role-visible connected owner's account count and
     * every role-visible live linked campaign's step data, in one pass --
     * driven by the "Refresh from Saleshandy" button on the planner page.
     *
     * @return array{synced_owners:int,synced_campaigns:int,errors:string[]}
     */
    public static function refreshAll(PDO $db, Scope $scope): array
    {
        $ownerIds = $scope->visibleOwnerIds($db);
        if ($ownerIds !== null && !$ownerIds) {
            return ['synced_owners' => 0, 'synced_campaigns' => 0, 'errors' => []];
        }

        $ownerClauses = ['company_id = :company_id', 'saleshandy_connected_at IS NOT NULL'];
        $ownerParams = ['company_id' => $scope->companyId];
        if ($ownerIds !== null) {
            $placeholders = [];
            foreach ($ownerIds as $i => $id) {
                $key = "owner_{$i}";
                $placeholders[] = ":{$key}";
                $ownerParams[$key] = $id;
            }
            $ownerClauses[] = 'id IN (' . implode(',', $placeholders) . ')';
        }
        $ownersStmt = $db->prepare('SELECT id, name FROM users WHERE ' . implode(' AND ', $ownerClauses));
        $ownersStmt->execute($ownerParams);
        $owners = $ownersStmt->fetchAll();

        $errors = [];
        $clientsByOwner = [];
        foreach ($owners as $owner) {
            try {
                self::refreshOwner($db, (int) $owner['id']);
                $clientsByOwner[(int) $owner['id']] = SaleshandyClient::forUser($db, (int) $owner['id']);
            } catch (SaleshandyApiException $ex) {
                $errors[] = "{$owner['name']}: {$ex->getMessage()}";
            }
        }

        if (!$clientsByOwner) {
            return ['synced_owners' => count($clientsByOwner), 'synced_campaigns' => 0, 'errors' => $errors];
        }

        $ownerIdList = implode(',', array_map('intval', array_keys($clientsByOwner)));
        $campaignsStmt = $db->prepare(
            "SELECT id, name, saleshandy_sequence_id, saleshandy_account_owner_id FROM campaigns
              WHERE company_id = ? AND is_active = 1 AND saleshandy_sequence_id IS NOT NULL
                AND saleshandy_account_owner_id IN ({$ownerIdList})"
        );
        $campaignsStmt->execute([$scope->companyId]);

        $syncedCampaigns = 0;
        foreach ($campaignsStmt->fetchAll() as $campaign) {
            $client = $clientsByOwner[(int) $campaign['saleshandy_account_owner_id']];
            try {
                self::refreshCampaign($db, $client, $campaign);
                $syncedCampaigns++;
            } catch (SaleshandyApiException $ex) {
                $errors[] = "{$campaign['name']}: {$ex->getMessage()}";
            }
        }

        return ['synced_owners' => count($clientsByOwner), 'synced_campaigns' => $syncedCampaigns, 'errors' => $errors];
    }

    /** @return array{summary:array,campaigns:array<int,array>} */
    public static function plan(PDO $db, Scope $scope): array
    {
        $limitStmt = $db->prepare('SELECT assumed_daily_send_limit FROM companies WHERE id = ?');
        $limitStmt->execute([$scope->companyId]);
        $assumedDailyLimit = (int) ($limitStmt->fetchColumn() ?: 30);

        $clauses = ['c.company_id = :company_id', 'c.is_active = 1', 'c.saleshandy_sequence_id IS NOT NULL'];
        $params = ['company_id' => $scope->companyId];
        ScopeFilter::applyOwnerScope($clauses, $params, $scope, $db, 'c', 'saleshandy_account_owner_id');

        $stmt = $db->prepare(
            "SELECT c.id, c.name, c.saleshandy_account_owner_id, c.saleshandy_step_count, c.saleshandy_cadence_days, c.saleshandy_capacity_synced_at,
                    owner.name AS owner_name, owner.saleshandy_active_email_accounts, owner.saleshandy_capacity_synced_at AS owner_synced_at,
                    (SELECT COUNT(*) FROM lead_campaign_assignments a WHERE a.campaign_id = c.id
                       AND (a.delivery_status IS NULL OR a.delivery_status IN ('Active', 'Paused'))) AS backlog_count
             FROM campaigns c
             LEFT JOIN users owner ON owner.id = c.saleshandy_account_owner_id
             WHERE " . implode(' AND ', $clauses) . '
             ORDER BY owner.name, c.name'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Group by owner to pool capacity + split it by backlog share.
        $byOwner = [];
        foreach ($rows as $row) {
            $ownerId = $row['saleshandy_account_owner_id'] !== null ? (int) $row['saleshandy_account_owner_id'] : 0;
            $byOwner[$ownerId][] = $row;
        }

        $campaigns = [];
        $totalCapacity = 0;
        $totalBacklog = 0;
        $totalMaxNewLeadsPerDay = 0.0;

        foreach ($byOwner as $ownerCampaigns) {
            $activeAccounts = (int) ($ownerCampaigns[0]['saleshandy_active_email_accounts'] ?? 0);
            $ownerSyncedAt = $ownerCampaigns[0]['owner_synced_at'];
            $ownerCapacity = $activeAccounts * $assumedDailyLimit;
            $totalCapacity += $ownerCapacity;

            $ownerBacklog = (int) array_sum(array_column($ownerCampaigns, 'backlog_count'));
            // Weighted-avg steps across this owner's campaigns, weighted by
            // backlog -- a campaign with a bigger backlog dominates how many
            // steps a "typical" new lead here will need.
            $weightedStepSum = 0;
            foreach ($ownerCampaigns as $c) {
                $weightedStepSum += (int) $c['backlog_count'] * (int) ($c['saleshandy_step_count'] ?? 0);
            }
            $avgSteps = ($ownerBacklog > 0 && $weightedStepSum > 0) ? $weightedStepSum / $ownerBacklog : null;
            $ownerMaxNewLeadsPerDay = $avgSteps ? $ownerCapacity / $avgSteps : null;

            foreach ($ownerCampaigns as $c) {
                $backlog = (int) $c['backlog_count'];
                $steps = $c['saleshandy_step_count'] !== null ? (int) $c['saleshandy_step_count'] : null;
                $cadence = $c['saleshandy_cadence_days'] !== null ? (int) $c['saleshandy_cadence_days'] : null;
                $share = $ownerBacklog > 0 ? $backlog / $ownerBacklog : 0;
                $maxNewLeadsPerDay = $ownerMaxNewLeadsPerDay !== null ? $ownerMaxNewLeadsPerDay * $share : null;
                $daysToClearBacklog = ($maxNewLeadsPerDay !== null && $maxNewLeadsPerDay > 0) ? $backlog / $maxNewLeadsPerDay : null;
                $daysToFinish = ($daysToClearBacklog !== null && $cadence !== null) ? $daysToClearBacklog + $cadence : null;

                $totalBacklog += $backlog;
                if ($maxNewLeadsPerDay !== null) {
                    $totalMaxNewLeadsPerDay += $maxNewLeadsPerDay;
                }

                $campaigns[] = [
                    'id' => (int) $c['id'],
                    'name' => $c['name'],
                    'owner_name' => $c['owner_name'],
                    'active_email_accounts' => $activeAccounts,
                    'step_count' => $steps,
                    'cadence_days' => $cadence,
                    'backlog_count' => $backlog,
                    'max_new_leads_per_day' => $maxNewLeadsPerDay,
                    'days_to_clear_backlog' => $daysToClearBacklog,
                    'days_to_finish' => $daysToFinish,
                    'capacity_synced_at' => $c['saleshandy_capacity_synced_at'],
                    'owner_synced_at' => $ownerSyncedAt,
                    'needs_sync' => $steps === null || $ownerSyncedAt === null,
                ];
            }
        }

        return [
            'summary' => [
                'assumed_daily_send_limit' => $assumedDailyLimit,
                'total_daily_capacity' => $totalCapacity,
                'total_backlog' => $totalBacklog,
                'total_max_new_leads_per_day' => $totalMaxNewLeadsPerDay > 0 ? $totalMaxNewLeadsPerDay : null,
                'total_days_to_clear_backlog' => $totalMaxNewLeadsPerDay > 0 ? $totalBacklog / $totalMaxNewLeadsPerDay : null,
            ],
            'campaigns' => $campaigns,
        ];
    }
}
