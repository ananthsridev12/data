<?php
// CapacityPlanner::plan() -- pure DB-logic math (no live Saleshandy calls,
// same as CapacityPlanner::refreshOwner()/refreshCampaign() which aren't
// exercised here -- they're a thin wrapper around
// SaleshandyClient::listEmailAccounts()/listSequenceSteps() already
// covered by SaleshandyClient's own request-shape correctness, not by
// duplicating a live API call in this test):
//   - capacity = active accounts x assumed daily limit, summed per owner
//   - backlog only counts NULL/Active/Paused delivery_status, not
//     Replied/Bounced
//   - max-new-leads/day and days-to-clear-backlog arithmetic for a
//     single-campaign owner (no backlog-share ambiguity to account for)
//   - Member scope only sees their own campaign, per the same
//     ScopeFilter::applyOwnerScope() rule Campaigns already uses
// Rolled back at the end.
//
// Usage: php tests/capacity_planner_test.php

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

    $mkUser = function (string $role, string $email, int $activeAccounts) use ($db, $companyId): int {
        $stmt = $db->prepare(
            'INSERT INTO users (company_id, name, email, password_hash, role, saleshandy_connected_at, saleshandy_active_email_accounts, saleshandy_capacity_synced_at)
             VALUES (?, ?, ?, ?, ?, NOW(), ?, NOW())'
        );
        $stmt->execute([$companyId, $email, $email, 'x', $role, $activeAccounts]);
        return (int) $db->lastInsertId();
    };
    // Admin: 2 active accounts @ 25/day = 50/day capacity.
    $adminId = $mkUser(ROLE_ADMIN, 'admin@a.test', 2);
    // Member: 1 active account @ 25/day = 25/day capacity.
    $memberId = $mkUser(ROLE_MEMBER, 'member@a.test', 1);

    $mkCampaign = function (int $ownerId, string $name, int $stepCount, int $cadenceDays) use ($db, $companyId): int {
        $stmt = $db->prepare(
            'INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, saleshandy_sequence_id, saleshandy_step_count, saleshandy_cadence_days, saleshandy_capacity_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$companyId, $name, $ownerId, $ownerId, 'seq-' . $name, $stepCount, $cadenceDays]);
        return (int) $db->lastInsertId();
    };
    // Admin's campaign: 4 steps, 12-day cadence.
    $adminCampaignId = $mkCampaign($adminId, 'Admin Campaign', 4, 12);
    // Member's campaign: 2 steps, 6-day cadence.
    $memberCampaignId = $mkCampaign($memberId, 'Member Campaign', 2, 6);

    $mkLead = function (string $email) use ($db, $companyId): int {
        $stmt = $db->prepare(
            'INSERT INTO leads (company_id, na_company_name, category, products, first_name, last_name, title, company_name_for_emails, email, industry, person_linkedin_url, website, company_linkedin_url, company_country)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, 'X Co', 'Cat', 'Prod', 'First', 'Last', 'Title', 'X Co', $email, 'Industry', 'https://linkedin.test', 'https://x.test', 'https://linkedin.test/co', 'US']);
        return (int) $db->lastInsertId();
    };
    $mkAssignment = function (int $leadId, int $campaignId, int $assignerId, ?string $deliveryStatus) use ($db): void {
        $stmt = $db->prepare(
            'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, delivery_status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$leadId, $campaignId, $assignerId, $deliveryStatus]);
    };

    // Admin's campaign: 40 leads still in-flight (NULL/Active/Paused) +
    // 10 finished (Replied/Bounced) -- backlog should be 40, not 50.
    for ($i = 0; $i < 10; $i++) {
        $mkAssignment($mkLead("admin-null-{$i}@x.test"), $adminCampaignId, $adminId, null);
    }
    for ($i = 0; $i < 20; $i++) {
        $mkAssignment($mkLead("admin-active-{$i}@x.test"), $adminCampaignId, $adminId, 'Active');
    }
    for ($i = 0; $i < 10; $i++) {
        $mkAssignment($mkLead("admin-paused-{$i}@x.test"), $adminCampaignId, $adminId, 'Paused');
    }
    $mkAssignment($mkLead('admin-replied@x.test'), $adminCampaignId, $adminId, 'Replied');
    for ($i = 0; $i < 9; $i++) {
        $mkAssignment($mkLead("admin-bounced-{$i}@x.test"), $adminCampaignId, $adminId, 'Bounced');
    }

    // Member's campaign: 10 in-flight, 0 finished.
    for ($i = 0; $i < 10; $i++) {
        $mkAssignment($mkLead("member-{$i}@x.test"), $memberCampaignId, $memberId, 'Active');
    }

    $adminScope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $memberScope = Scope::fromUser($db, ['id' => $memberId, 'company_id' => $companyId, 'role' => ROLE_MEMBER, 'team_id' => null]);

    $adminPlan = CapacityPlanner::plan($db, $adminScope);

    // --- Total capacity: (2 accounts x 25) + (1 account x 25) = 75/day.
    $assert($adminPlan['summary']['total_daily_capacity'] === 75, 'Total daily capacity sums (active accounts x assumed limit) across every owner');

    // --- Total backlog: 40 (admin, in-flight only) + 10 (member) = 50.
    $assert($adminPlan['summary']['total_backlog'] === 50, 'Backlog counts only NULL/Active/Paused assignments, excluding Replied/Bounced');

    $byName = [];
    foreach ($adminPlan['campaigns'] as $c) {
        $byName[$c['name']] = $c;
    }

    // --- Admin's campaign is the only one on its owner's account, so its
    // rate is simply that owner's full capacity / its own step count:
    // 50/day capacity / 4 steps = 12.5 new leads/day; 40 backlog / 12.5 = 3.2 days to clear; + 12-day cadence = 15.2 days to finish.
    $adminRow = $byName['Admin Campaign'];
    $assert(abs($adminRow['max_new_leads_per_day'] - 12.5) < 0.01, "Admin campaign's max new leads/day is capacity/steps = 12.5 (got {$adminRow['max_new_leads_per_day']})");
    $assert(abs($adminRow['days_to_clear_backlog'] - 3.2) < 0.01, "Admin campaign's days to clear backlog is 40/12.5 = 3.2 (got {$adminRow['days_to_clear_backlog']})");
    $assert(abs($adminRow['days_to_finish'] - 15.2) < 0.01, "Admin campaign's days to finish is 3.2 + 12-day cadence = 15.2 (got {$adminRow['days_to_finish']})");

    // --- Member's campaign: 25/day capacity / 2 steps = 12.5 new leads/day; 10 backlog / 12.5 = 0.8 days; + 6-day cadence = 6.8.
    $memberRow = $byName['Member Campaign'];
    $assert(abs($memberRow['max_new_leads_per_day'] - 12.5) < 0.01, "Member campaign's max new leads/day is 25/2 = 12.5 (got {$memberRow['max_new_leads_per_day']})");
    $assert(abs($memberRow['days_to_finish'] - 6.8) < 0.01, "Member campaign's days to finish is 0.8 + 6-day cadence = 6.8 (got {$memberRow['days_to_finish']})");

    $assert($adminRow['needs_sync'] === false, "A campaign with synced step data and a synced owner doesn't need re-sync");

    // --- Role scoping: a Member only sees their own campaign, same rule
    // ScopeFilter::applyOwnerScope() already applies to Campaigns.
    $memberPlan = CapacityPlanner::plan($db, $memberScope);
    $memberPlanNames = array_column($memberPlan['campaigns'], 'name');
    $assert($memberPlanNames === ['Member Campaign'], 'Member scope only sees their own campaign, not the Admin\'s');
    $assert($memberPlan['summary']['total_daily_capacity'] === 25, 'Member scope\'s total capacity only counts their own account');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll CapacityPlanner checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
