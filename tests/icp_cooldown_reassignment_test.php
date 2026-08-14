<?php
// LeadRepository's 'assignable_after_cooldown_days' filter (fed by
// IcpRepository::toFilters()) -- replaces the old permanent
// "assigned_campaign_id = 'none'" exclusion. A lead is eligible for ICP
// matching if it's never been assigned to any campaign, OR its *latest*
// assignment is both resolved (not held, not still pending a delivery
// outcome -- see WaveAssigner::PENDING_ASSIGNMENT_SQL) and older than the
// company's lead_cooldown_days. Suppressed-domain leads stay excluded via
// buildWhere()'s existing show_suppressed default, cooldown or not.
// Rolled back at the end.
//
// Usage: php tests/icp_cooldown_reassignment_test.php

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Cooldown Co', 30)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'admin@cooldown.test', 'admin@cooldown.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();

    $scope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $assert($scope->leadCooldownDays === 30, 'Sanity check: Scope picked up the company\'s 30-day cooldown');

    $campStmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
    $campStmt->execute([$companyId, 'Old Campaign', $adminId, $adminId]);
    $campaignId = (int) $db->lastInsertId();

    $mkLead = function (string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, 'Acme', 'F', 'L', $email]);
        return (int) $db->lastInsertId();
    };

    // A lead with zero assignment history at all.
    $leadNeverAssigned = $mkLead('never@acme.test');

    // A lead assigned + resolved (delivered, not held) 40 days ago -- past
    // the 30-day cooldown, should be reassignable.
    $leadResolvedPastCooldown = $mkLead('resolved-past@acme.test');
    // A lead assigned + resolved the same way, but only 5 days ago --
    // still inside the cooldown window, should NOT be reassignable yet.
    $leadResolvedWithinCooldown = $mkLead('resolved-within@acme.test');
    // A lead still held under a wave-1 leader, assigned 40 days ago --
    // wave-safety hold outranks cooldown, must stay excluded regardless
    // of how old the assignment is.
    $leadStillHeld = $mkLead('still-held@acme.test');
    // A lead whose send outcome is still unconfirmed (bounce_status
    // 'pending', no delivery_status yet), assigned 40 days ago -- same
    // "unresolved outranks cooldown" rule as held.
    $leadStillPending = $mkLead('still-pending@acme.test');
    // A lead resolved + past cooldown, but its domain is suppressed --
    // must stay excluded no matter what the assignment history says.
    $leadSuppressedDomain = $mkLead('resolved-past@suppressed.test');

    $insertAssignment = $db->prepare(
        'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, wave_status, bounce_status, delivery_status, assigned_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $daysAgo = static fn (int $days): string => (new DateTime("-{$days} days"))->format('Y-m-d H:i:s');

    $insertAssignment->execute([$leadResolvedPastCooldown, $campaignId, $adminId, 'active', 'delivered', 'Active', $daysAgo(40)]);
    $insertAssignment->execute([$leadResolvedWithinCooldown, $campaignId, $adminId, 'active', 'delivered', 'Active', $daysAgo(5)]);
    $insertAssignment->execute([$leadStillHeld, $campaignId, $adminId, 'held', 'pending', null, $daysAgo(40)]);
    $insertAssignment->execute([$leadStillPending, $campaignId, $adminId, 'active', 'pending', null, $daysAgo(40)]);
    $insertAssignment->execute([$leadSuppressedDomain, $campaignId, $adminId, 'active', 'delivered', 'Active', $daysAgo(40)]);

    $db->prepare('INSERT INTO suppressed_domains (company_id, domain, reason, suppressed_by) VALUES (?, ?, ?, ?)')
        ->execute([$companyId, 'suppressed.test', 'test fixture', $adminId]);

    // Exercise the real production path: an ICP row with no criteria (so
    // it matches purely on assignability) through toFilters() -> matchingIds().
    $icpRow = [
        'company_country' => '', 'industry' => '', 'seniority' => '', 'employee_count' => '',
        'vertical_id' => null, 'service_id' => null, 'role_group_id' => null, 'country_group_id' => null,
    ];
    $filters = IcpRepository::toFilters($icpRow, $scope);
    $assert($filters['assignable_after_cooldown_days'] === 30, 'toFilters() carries the scope\'s cooldown days into the filter');

    $matching = LeadRepository::matchingIds($db, $scope, $filters);

    $assert(in_array($leadNeverAssigned, $matching, true), 'A never-assigned lead is eligible');
    $assert(in_array($leadResolvedPastCooldown, $matching, true), 'A resolved assignment past the cooldown window is eligible again');
    $assert(!in_array($leadResolvedWithinCooldown, $matching, true), 'A resolved assignment still inside the cooldown window is NOT eligible');
    $assert(!in_array($leadStillHeld, $matching, true), 'A still-held wave-1 assignment is NOT eligible, even 40 days later');
    $assert(!in_array($leadStillPending, $matching, true), 'A still-pending (unconfirmed) assignment is NOT eligible, even 40 days later');
    $assert(!in_array($leadSuppressedDomain, $matching, true), 'A resolved + past-cooldown lead on a suppressed domain is still NOT eligible');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll ICP cooldown-reassignment checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
