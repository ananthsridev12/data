<?php
// CapacityPlanner::forecast() -- pure DB-logic math (no live Saleshandy
// calls, same rationale as capacity_planner_test.php):
//   - in-flight leads project their REMAINING steps only, from real
//     saleshandy_pushed_at (start date) + saleshandy_current_step
//     (how far they've gotten) against the campaign's actual per-step
//     schedule, not from every step
//   - an overdue remaining step (due date before today, per our last
//     sync) is bucketed into today rather than dropped or backdated
//   - planned new-lead cohorts enroll at the given daily rate, draining
//     the not-yet-pushed backlog, each cohort's own step ripple
//     projected from its own (simulated) enrollment day
//   - a campaign with no synced step schedule is excluded from
//     projection and flagged needs_sync, not silently treated as 0 steps
// Rolled back at the end.
//
// Usage: php tests/capacity_forecast_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
require_once __DIR__ . '/../app/includes/CapacityPlanner.php';

$failures = [];
$assert = static function (bool $cond, string $label) use (&$failures): void {
    echo ($cond ? "PASS" : "FAIL") . " -- {$label}\n";
    if (!$cond) {
        $failures[] = $label;
    }
};

$db = db();
$db->beginTransaction();

try {
    $db->exec("INSERT INTO companies (name, lead_cooldown_days, assumed_daily_send_limit) VALUES ('Co A', 90, 25)");
    $companyId = (int) $db->lastInsertId();

    $stmt = $db->prepare(
        'INSERT INTO users (company_id, name, email, password_hash, role, saleshandy_connected_at, saleshandy_active_email_accounts, saleshandy_capacity_synced_at)
         VALUES (?, ?, ?, ?, ?, NOW(), ?, NOW())'
    );
    $stmt->execute([$companyId, 'admin@a.test', 'admin@a.test', 'x', ROLE_ADMIN, 2]); // 2 x 25 = 50/day
    $adminId = (int) $db->lastInsertId();

    $today = new DateTimeImmutable('today');
    $fmt = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d');

    $mkCampaign = function (string $name, ?array $schedule) use ($db, $companyId, $adminId): int {
        $stmt = $db->prepare(
            'INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, saleshandy_sequence_id, saleshandy_step_count, saleshandy_cadence_days, saleshandy_step_schedule_json, saleshandy_capacity_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $companyId, $name, $adminId, $adminId, 'seq-' . $name,
            $schedule ? count($schedule) : null,
            $schedule ? max(array_column($schedule, 'days')) : null,
            $schedule ? json_encode($schedule) : null,
        ]);
        return (int) $db->lastInsertId();
    };
    $mkLead = function (string $email) use ($db, $companyId): int {
        $stmt = $db->prepare(
            'INSERT INTO leads (company_id, na_company_name, category, products, first_name, last_name, title, company_name_for_emails, email, industry, person_linkedin_url, website, company_linkedin_url, company_country)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, 'X Co', 'Cat', 'Prod', 'First', 'Last', 'Title', 'X Co', $email, 'Industry', 'https://linkedin.test', 'https://x.test', 'https://linkedin.test/co', 'US']);
        return (int) $db->lastInsertId();
    };
    $mkAssignment = function (int $leadId, int $campaignId, ?string $deliveryStatus, ?string $pushedAt, ?int $currentStep, ?string $syncedAt = null) use ($db, $adminId): void {
        $stmt = $db->prepare(
            'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, delivery_status, saleshandy_pushed_at, saleshandy_current_step, saleshandy_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$leadId, $campaignId, $adminId, $deliveryStatus, $pushedAt, $currentStep, $syncedAt]);
    };

    // --- Campaign 1: D0/D2/D6 schedule, in-flight projection only.
    $schedule1 = [['number' => 1, 'days' => 0], ['number' => 2, 'days' => 2], ['number' => 3, 'days' => 6]];
    $camp1 = $mkCampaign('In-Flight Campaign', $schedule1);

    // Lead X: enrolled today, step 1 already sent -- remaining: step2 due today+2, step3 due today+6.
    // Synced 2 hours ago -- the freshest of camp1's two assignments below.
    $mkAssignment($mkLead('x@x.test'), $camp1, 'Active', $fmt($today) . ' 09:00:00', 1, date('Y-m-d H:i:s', strtotime('-2 hours')));
    // Lead Y: enrolled 10 days ago, only step1 sent -- step2 due 10 days ago + 2 = 8 days overdue -> clamped to today.
    // step3 due 10 days ago + 6 = 4 days overdue -> also clamped to today.
    $mkAssignment($mkLead('y@x.test'), $camp1, 'Active', $fmt($today->modify('-10 day')) . ' 09:00:00', 1);
    // Finished lead (Replied) -- must NOT contribute any projected touches.
    $mkAssignment($mkLead('finished@x.test'), $camp1, 'Replied', $fmt($today->modify('-10 day')) . ' 09:00:00', 1);

    $scope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $forecast1 = CapacityPlanner::forecast($db, $scope, 14, [$camp1 => 0]); // no new-cohort noise for this campaign

    $byDate1 = $forecast1['campaigns'][$camp1]['by_date'];
    $assert($byDate1[$fmt($today)]['in_flight'] === 2, "Two overdue touches (Lead Y's step2+step3) both clamp into today (got {$byDate1[$fmt($today)]['in_flight']})");
    $assert($byDate1[$fmt($today->modify('+2 day'))]['in_flight'] === 1, "Lead X's step2 (D+2) lands on today+2, not before");
    $assert($byDate1[$fmt($today->modify('+6 day'))]['in_flight'] === 1, "Lead X's step3 (D+6) lands on today+6");
    $totalInFlight1 = array_sum(array_column($byDate1, 'in_flight'));
    $assert($totalInFlight1 === 4, "Exactly 4 projected touches total (2 remaining x 2 in-flight leads), Replied lead contributes none (got {$totalInFlight1})");

    // --- lead_data_synced_at: MAX(saleshandy_synced_at) across the
    // campaign's assignments, surfaced so a stale in-flight projection
    // (this page's own "Refresh from Saleshandy" never touches this
    // column) is visibly flagged rather than silently trusted.
    $syncedAt1 = $forecast1['campaigns'][$camp1]['lead_data_synced_at'];
    $assert($syncedAt1 !== null && abs(strtotime($syncedAt1) - strtotime('-2 hours')) < 60, "lead_data_synced_at picks up Lead X's ~2-hours-ago sync timestamp (got " . var_export($syncedAt1, true) . ')');

    // --- Campaign 2: D0/D3 schedule, new-lead-cohort planning only
    // (no in-flight leads) -- rate 2/day, backlog of 4 drains over 2 days.
    $schedule2 = [['number' => 1, 'days' => 0], ['number' => 2, 'days' => 3]];
    $camp2 = $mkCampaign('New-Cohort Campaign', $schedule2);
    for ($i = 0; $i < 4; $i++) {
        $mkAssignment($mkLead("cohort-{$i}@x.test"), $camp2, null, null, null); // not yet pushed
    }

    $forecast2 = CapacityPlanner::forecast($db, $scope, 14, [$camp2 => 2]);
    $byDate2 = $forecast2['campaigns'][$camp2]['by_date'];
    $assert($byDate2[$fmt($today)]['new_cohort'] === 2, "Day-0 cohort (2 leads) gets its D0 touch today");
    $assert($byDate2[$fmt($today->modify('+1 day'))]['new_cohort'] === 2, "Day-1 cohort (remaining 2 leads) gets its D0 touch tomorrow");
    $assert($byDate2[$fmt($today->modify('+3 day'))]['new_cohort'] === 2, "Day-0 cohort's D3 touch lands 3 days after IT enrolled (today+3)");
    $assert($byDate2[$fmt($today->modify('+4 day'))]['new_cohort'] === 2, "Day-1 cohort's D3 touch lands 3 days after IT enrolled (today+4, not today+3)");
    $assert($byDate2[$fmt($today->modify('+2 day'))]['new_cohort'] === 0, "No cohort touch lands on today+2 -- backlog fully drained after 2 days, no 3rd cohort");
    $assert($forecast2['campaigns'][$camp2]['not_started_backlog'] === 4, "not_started_backlog reflects the 4 not-yet-pushed leads before simulation");
    $assert($forecast2['campaigns'][$camp2]['lead_data_synced_at'] === null, "lead_data_synced_at is null when no assignment has ever been synced (not-yet-pushed leads)");

    // --- A campaign with no synced schedule is excluded from projection
    // and flagged, not silently treated as zero steps.
    $camp3 = $mkCampaign('Unsynced Campaign', null);
    $mkAssignment($mkLead('unsynced@x.test'), $camp3, 'Active', $fmt($today) . ' 09:00:00', 0);
    $forecast3 = CapacityPlanner::forecast($db, $scope, 7, []);
    $assert($forecast3['campaigns'][$camp3]['needs_sync'] === true, 'A campaign with no synced step schedule is flagged needs_sync');
    $totalUnsynced = array_sum(array_column($forecast3['campaigns'][$camp3]['by_date'], 'total'));
    $assert($totalUnsynced === 0, 'An unsynced campaign contributes zero projected touches rather than guessing');

    // --- Consolidated capacity/balance arithmetic.
    $assert($forecast1['consolidated'][$fmt($today)]['capacity'] === 50, 'Consolidated capacity is 2 accounts x 25/day = 50, constant across the horizon');
    $expectedTodayTotal = $byDate1[$fmt($today)]['total'];
    $assert(
        $forecast1['consolidated'][$fmt($today)]['balance'] === 50 - $expectedTodayTotal,
        "Consolidated balance is capacity minus total scheduled (got {$forecast1['consolidated'][$fmt($today)]['balance']})"
    );

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll CapacityPlanner::forecast() checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
