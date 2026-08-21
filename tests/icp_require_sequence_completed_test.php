<?php
// IcpRepository::toFilters()'s opt-in 'require_sequence_completed_if_reassigning'
// (icp_segments.require_sequence_completed, sql/043) -- narrows the
// existing cooldown-based reassignment rule (see
// icp_cooldown_reassignment_test.php) to additionally require that a
// previously-assigned lead's prior campaign sequence actually finished
// (current_step reached the campaign's real total, no reply), not just
// that the assignment is resolved and past cooldown. Off by default, so
// existing ICPs are unaffected -- this test proves both states. Rolled
// back at the end.
//
// Usage: php tests/icp_require_sequence_completed_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Seq Co', 30)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'admin@seq.test', 'admin@seq.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();

    $scope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);

    $campStmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, saleshandy_step_count) VALUES (?, ?, ?, ?, ?)');
    $campStmt->execute([$companyId, 'Known 3-step campaign', $adminId, $adminId, 3]);
    $campaignKnownSteps = (int) $db->lastInsertId();
    $campStmt->execute([$companyId, 'Unknown step-count campaign', $adminId, $adminId, null]);
    $campaignUnknownSteps = (int) $db->lastInsertId();

    $mkLead = function (string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, 'Acme', 'F', 'L', $email]);
        return (int) $db->lastInsertId();
    };

    $leadNeverAssigned = $mkLead('never@seq1.test');
    $leadFinishedNoReply = $mkLead('finished@seq2.test');
    $leadMidwayNoReply = $mkLead('midway@seq3.test');
    $leadUnknownStepCount = $mkLead('unknown-steps@seq4.test');

    $insertAssignment = $db->prepare(
        'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, wave_status, bounce_status, delivery_status, saleshandy_current_step, assigned_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $daysAgo = static fn (int $days): string => (new DateTime("-{$days} days"))->format('Y-m-d H:i:s');

    // Resolved + past cooldown (40 days ago, cooldown is 30), current_step
    // reached the campaign's real total of 3 -- actually finished.
    $insertAssignment->execute([$leadFinishedNoReply, $campaignKnownSteps, $adminId, 'active', 'delivered', 'Active', 3, $daysAgo(40)]);
    // Same resolved + past-cooldown shape, but only 2 of 3 steps in --
    // still mid-sequence.
    $insertAssignment->execute([$leadMidwayNoReply, $campaignKnownSteps, $adminId, 'active', 'delivered', 'Active', 2, $daysAgo(40)]);
    // Resolved + past cooldown, but its campaign's step count is unknown
    // -- can't confirm completion either way, so this must NOT count as
    // completed (conservative default, not "assume yes").
    $insertAssignment->execute([$leadUnknownStepCount, $campaignUnknownSteps, $adminId, 'active', 'delivered', 'Active', 5, $daysAgo(40)]);

    $icpRowBase = [
        'company_country' => '', 'industry' => '', 'seniority' => '', 'employee_count' => '',
        'vertical_id' => null, 'service_id' => null, 'role_group_id' => null, 'country_group_id' => null,
    ];

    // --- Off (default): unaffected by step count -- both resolved+
    // cooled-down leads (finished or not) are eligible, same as before
    // this feature existed.
    $filtersOff = IcpRepository::toFilters($icpRowBase + ['require_sequence_completed' => 0], $scope);
    $assert($filtersOff['require_sequence_completed_if_reassigning'] === false, 'toFilters() carries require_sequence_completed=0 as false');
    $matchingOff = LeadRepository::matchingIds($db, $scope, $filtersOff);
    $assert(in_array($leadNeverAssigned, $matchingOff, true), 'Off: never-assigned lead is eligible');
    $assert(in_array($leadFinishedNoReply, $matchingOff, true), 'Off: finished-sequence lead is eligible (unchanged baseline)');
    $assert(in_array($leadMidwayNoReply, $matchingOff, true), 'Off: midway-sequence lead is STILL eligible (the flag is off, cooldown alone governs)');
    $assert(in_array($leadUnknownStepCount, $matchingOff, true), 'Off: unknown-step-count lead is eligible (the flag is off, doesn\'t care about step count)');

    // --- On: only never-assigned or genuinely-finished leads qualify.
    $filtersOn = IcpRepository::toFilters($icpRowBase + ['require_sequence_completed' => 1], $scope);
    $assert($filtersOn['require_sequence_completed_if_reassigning'] === true, 'toFilters() carries require_sequence_completed=1 as true');
    $matchingOn = LeadRepository::matchingIds($db, $scope, $filtersOn);
    $assert(in_array($leadNeverAssigned, $matchingOn, true), 'On: never-assigned lead is still eligible (nothing to check completion against)');
    $assert(in_array($leadFinishedNoReply, $matchingOn, true), 'On: a lead that actually finished its sequence with no reply IS eligible');
    $assert(!in_array($leadMidwayNoReply, $matchingOn, true), 'On: a lead only 2/3 through its sequence is NOT eligible, even resolved + past cooldown');
    $assert(!in_array($leadUnknownStepCount, $matchingOn, true), 'On: a lead whose campaign step count is unknown is NOT eligible (conservative default, not assumed complete)');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll ICP require_sequence_completed checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
