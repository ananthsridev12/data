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

    /**
     * Refreshes one campaign's step count/cadence/per-step schedule from
     * its linked sequence. Only automated EMAIL steps (executionType 1)
     * count -- a LinkedIn/call/WhatsApp/custom (task-based) step in the
     * same sequence doesn't consume email-account send capacity, so
     * including it would inflate the step count and skew every
     * downstream capacity estimate.
     */
    public static function refreshCampaign(PDO $db, SaleshandyClient $client, array $campaign): void
    {
        $allSteps = $client->listSequenceSteps($campaign['saleshandy_sequence_id']);
        $emailSteps = array_values(array_filter($allSteps, static fn(array $s) => (int) ($s['executionType'] ?? 1) === 1));
        usort($emailSteps, static fn(array $a, array $b) => ($a['number'] ?? 0) <=> ($b['number'] ?? 0));

        // Step NUMBER is kept alongside its day-offset, not just a plain
        // offset list -- lead_campaign_assignments.saleshandy_current_step
        // refers to Saleshandy's own step numbering, which can have gaps
        // once non-email steps are filtered out of this array (see
        // CapacityPlanner::forecast()).
        $schedule = array_map(
            static fn(array $s) => ['number' => (int) ($s['number'] ?? 0), 'days' => (int) ($s['relativeDays'] ?? 0)],
            $emailSteps
        );
        $cadenceDays = $schedule ? max(array_column($schedule, 'days')) : 0;

        $db->prepare(
            'UPDATE campaigns SET saleshandy_step_count = ?, saleshandy_cadence_days = ?, saleshandy_step_schedule_json = ?, saleshandy_capacity_synced_at = NOW() WHERE id = ?'
        )->execute([count($schedule), $cadenceDays, json_encode($schedule), $campaign['id']]);
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

    /**
     * Day-by-day send forecast for the next $days days (today included):
     * how many emails are actually due to go out each day, and the
     * resulting capacity balance. Two sources feed each day's total:
     *
     *  - IN-FLIGHT leads already enrolled (delivery_status Active/Paused)
     *    -- their remaining touches are projected from real data
     *    (saleshandy_pushed_at as the sequence start date,
     *    saleshandy_current_step as how far they've gotten) against the
     *    campaign's actual per-step schedule. A touch that's already
     *    overdue (before today, still unsent per our last sync) is
     *    bucketed into today rather than a past date, since it's about
     *    to go out.
     *  - PLANNED new-lead cohorts -- a what-if: leads not yet pushed
     *    (delivery_status NULL) enrolled at $plannedDailyIntakeByCampaignId
     *    leads/day starting today (defaulting to that campaign's own
     *    plan()-computed sustainable rate), each cohort's own full step
     *    ripple projected the same way from its (simulated) start date.
     *
     * Capacity is the same pooled-per-owner total plan() already
     * computes, shown constant across every day in the horizon --
     * Saleshandy doesn't expose any day-of-week variation to plan
     * around.
     *
     * @param array<int,int> $plannedDailyIntakeByCampaignId campaign_id => leads/day to enroll starting today
     * @return array{dates:string[],consolidated:array<string,array>,campaigns:array<int,array>}
     */
    public static function forecast(PDO $db, Scope $scope, int $days, array $plannedDailyIntakeByCampaignId = []): array
    {
        $today = new DateTimeImmutable('today');
        $horizonEnd = $today->modify('+' . ($days - 1) . ' day');
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = $today->modify("+{$i} day")->format('Y-m-d');
        }

        $plan = self::plan($db, $scope);
        $totalDailyCapacity = $plan['summary']['total_daily_capacity'];

        $campaignMeta = [];
        foreach ($plan['campaigns'] as $c) {
            $campaignMeta[$c['id']] = [
                'name' => $c['name'],
                'owner_name' => $c['owner_name'],
                'suggested_rate' => $c['max_new_leads_per_day'] !== null ? (int) floor($c['max_new_leads_per_day']) : 0,
                'schedule' => null,
                'not_started_backlog' => 0,
            ];
        }

        $emptyDay = static fn(): array => ['in_flight' => 0, 'new_cohort' => 0];
        $byDateConsolidated = array_combine($dates, array_map($emptyDay, $dates));
        $byCampaignDate = [];
        foreach (array_keys($campaignMeta) as $id) {
            $byCampaignDate[$id] = array_combine($dates, array_map($emptyDay, $dates));
        }

        if (!$campaignMeta) {
            $consolidated = [];
            foreach ($dates as $d) {
                $consolidated[$d] = ['in_flight' => 0, 'new_cohort' => 0, 'total' => 0, 'capacity' => $totalDailyCapacity, 'balance' => $totalDailyCapacity];
            }
            return ['dates' => $dates, 'consolidated' => $consolidated, 'campaigns' => []];
        }

        $idList = implode(',', array_map('intval', array_keys($campaignMeta)));

        $schedStmt = $db->query("SELECT id, saleshandy_step_schedule_json FROM campaigns WHERE id IN ({$idList})");
        foreach ($schedStmt->fetchAll() as $row) {
            $decoded = $row['saleshandy_step_schedule_json'] !== null ? json_decode((string) $row['saleshandy_step_schedule_json'], true) : null;
            $campaignMeta[(int) $row['id']]['schedule'] = is_array($decoded) && $decoded ? $decoded : null;
        }

        $backlogStmt = $db->query(
            "SELECT campaign_id, SUM(CASE WHEN delivery_status IS NULL THEN 1 ELSE 0 END) AS not_started
               FROM lead_campaign_assignments WHERE campaign_id IN ({$idList}) GROUP BY campaign_id"
        );
        foreach ($backlogStmt->fetchAll() as $row) {
            $campaignMeta[(int) $row['campaign_id']]['not_started_backlog'] = (int) $row['not_started'];
        }

        // The in-flight projection below trusts saleshandy_current_step/
        // saleshandy_pushed_at as of our LAST sync of this campaign
        // specifically (its own regular round-robin turn or a manual
        // "Sync" click on Campaigns) -- not this page's own "Refresh from
        // Saleshandy" button, which only ever touches step schedules and
        // account counts, never per-lead progress. Surfaced so a campaign
        // that hasn't synced in a while shows an honest, visibly stale
        // forecast instead of a silently wrong one.
        $syncStmt = $db->query(
            "SELECT campaign_id, MAX(saleshandy_synced_at) AS last_synced
               FROM lead_campaign_assignments WHERE campaign_id IN ({$idList}) GROUP BY campaign_id"
        );
        foreach ($syncStmt->fetchAll() as $row) {
            $campaignMeta[(int) $row['campaign_id']]['lead_data_synced_at'] = $row['last_synced'];
        }

        // --- In-flight leads: project remaining touches from real
        // pushed_at + current_step + this campaign's actual step schedule.
        $inFlightStmt = $db->query(
            "SELECT campaign_id, saleshandy_pushed_at, saleshandy_current_step
                 FROM lead_campaign_assignments
                WHERE campaign_id IN ({$idList}) AND delivery_status IN ('Active', 'Paused') AND saleshandy_pushed_at IS NOT NULL"
        );
        foreach ($inFlightStmt->fetchAll() as $row) {
            $campaignId = (int) $row['campaign_id'];
            $schedule = $campaignMeta[$campaignId]['schedule'];
            if (!$schedule) {
                continue; // not synced yet -- can't project without real step offsets
            }
            $startDate = new DateTimeImmutable(date('Y-m-d', strtotime((string) $row['saleshandy_pushed_at'])));
            $currentStep = (int) ($row['saleshandy_current_step'] ?? 0);
            foreach ($schedule as $step) {
                if ((int) $step['number'] <= $currentStep) {
                    continue; // already sent
                }
                $due = $startDate->modify('+' . (int) $step['days'] . ' day');
                if ($due < $today) {
                    $due = $today; // overdue per our last sync -- about to go out
                }
                if ($due > $horizonEnd) {
                    continue;
                }
                $dateKey = $due->format('Y-m-d');
                $byDateConsolidated[$dateKey]['in_flight']++;
                $byCampaignDate[$campaignId][$dateKey]['in_flight']++;
            }
        }

        // --- Planned new-lead cohorts: simulate enrolling
        // min(rate, remaining not-yet-pushed backlog) leads each day,
        // starting today, projecting each cohort's own full step ripple.
        foreach ($campaignMeta as $campaignId => $meta) {
            $schedule = $meta['schedule'];
            if (!$schedule) {
                continue;
            }
            $rate = $plannedDailyIntakeByCampaignId[$campaignId] ?? max(0, $meta['suggested_rate']);
            $remaining = $meta['not_started_backlog'];
            if ($rate <= 0 || $remaining <= 0) {
                continue;
            }
            foreach ($dates as $dateStr) {
                if ($remaining <= 0) {
                    break;
                }
                $enrolled = min($rate, $remaining);
                $remaining -= $enrolled;
                $cohortStart = new DateTimeImmutable($dateStr);
                foreach ($schedule as $step) {
                    $due = $cohortStart->modify('+' . (int) $step['days'] . ' day');
                    if ($due > $horizonEnd) {
                        continue;
                    }
                    $dateKey = $due->format('Y-m-d');
                    $byDateConsolidated[$dateKey]['new_cohort'] += $enrolled;
                    $byCampaignDate[$campaignId][$dateKey]['new_cohort'] += $enrolled;
                }
            }
        }

        $consolidated = [];
        foreach ($dates as $dateStr) {
            $inFlight = $byDateConsolidated[$dateStr]['in_flight'];
            $newCohort = $byDateConsolidated[$dateStr]['new_cohort'];
            $consolidated[$dateStr] = [
                'in_flight' => $inFlight,
                'new_cohort' => $newCohort,
                'total' => $inFlight + $newCohort,
                'capacity' => $totalDailyCapacity,
                'balance' => $totalDailyCapacity - ($inFlight + $newCohort),
            ];
        }

        $campaignsOut = [];
        foreach ($campaignMeta as $id => $meta) {
            $rows = [];
            foreach ($dates as $dateStr) {
                $inFlight = $byCampaignDate[$id][$dateStr]['in_flight'];
                $newCohort = $byCampaignDate[$id][$dateStr]['new_cohort'];
                $rows[$dateStr] = ['in_flight' => $inFlight, 'new_cohort' => $newCohort, 'total' => $inFlight + $newCohort];
            }
            $campaignsOut[$id] = [
                'name' => $meta['name'],
                'owner_name' => $meta['owner_name'],
                'suggested_rate' => max(0, $meta['suggested_rate']),
                'planned_rate' => $plannedDailyIntakeByCampaignId[$id] ?? max(0, $meta['suggested_rate']),
                'not_started_backlog' => $meta['not_started_backlog'],
                'needs_sync' => $meta['schedule'] === null,
                'lead_data_synced_at' => $meta['lead_data_synced_at'] ?? null,
                'by_date' => $rows,
            ];
        }

        return ['dates' => $dates, 'consolidated' => $consolidated, 'campaigns' => $campaignsOut];
    }
}
