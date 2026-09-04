<?php
// LeadRepository::buildWhere()'s new 'bounce_status'/'bounce_type'/
// 'delivery_status' filters -- all checked against a lead's CURRENT
// (latest) assignment only, same "current standing, not lifetime
// history" reasoning as the existing 'imported'/'assigned_campaign_id'
// filters: a lead reassigned into a fresh campaign must show that NEW
// assignment's (unbounced) status, not a stale bounce carried over from
// an earlier, resolved campaign. Rolled back at the end.
//
// Usage: php tests/lead_bounce_filters_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Bounce Filter Co', 0)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'admin@bouncefilter.test', 'admin@bouncefilter.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();
    $scope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);

    $campStmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
    $campStmt->execute([$companyId, 'Old Campaign', $adminId, $adminId]);
    $oldCampaignId = (int) $db->lastInsertId();
    $campStmt->execute([$companyId, 'New Campaign', $adminId, $adminId]);
    $newCampaignId = (int) $db->lastInsertId();

    $mkLead = function (string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, 'Acme', 'F', 'L', $email]);
        return (int) $db->lastInsertId();
    };
    $insertAssignment = $db->prepare(
        'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, bounce_status, bounce_type, delivery_status, assigned_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $daysAgo = static fn (int $days): string => (new DateTime("-{$days} days"))->format('Y-m-d H:i:s');

    $leadNeverAssigned = $mkLead('never@bouncefilter1.test');

    $leadPending = $mkLead('pending@bouncefilter2.test');
    $insertAssignment->execute([$leadPending, $oldCampaignId, $adminId, 'pending', null, null, $daysAgo(1)]);

    $leadDelivered = $mkLead('delivered@bouncefilter3.test');
    $insertAssignment->execute([$leadDelivered, $oldCampaignId, $adminId, 'delivered', null, 'Active', $daysAgo(1)]);

    $leadBouncedHard = $mkLead('bounced@bouncefilter4.test');
    $insertAssignment->execute([$leadBouncedHard, $oldCampaignId, $adminId, 'bounced', 'Hard Bounce', 'Hard Bounced', $daysAgo(1)]);

    // Reassigned: bounced in its OLD campaign, but its LATEST (new
    // campaign) assignment is a fresh, unconfirmed 'pending' with no
    // bounce_type/delivery_status yet -- filters must reflect the NEW
    // row, not the old bounce.
    $leadReassigned = $mkLead('reassigned@bouncefilter5.test');
    $insertAssignment->execute([$leadReassigned, $oldCampaignId, $adminId, 'bounced', 'Hard Bounce', 'Hard Bounced', $daysAgo(90)]);
    $insertAssignment->execute([$leadReassigned, $newCampaignId, $adminId, 'pending', null, null, $daysAgo(1)]);

    // --- bounce_status
    $matchingPending = LeadRepository::matchingIds($db, $scope, ['bounce_status' => 'pending']);
    sort($matchingPending);
    $expectedPending = [$leadPending, $leadReassigned];
    sort($expectedPending);
    $assert($matchingPending === $expectedPending, 'bounce_status=pending matches the pending lead AND the reassigned lead\'s NEW pending row, not its old bounce');

    $matchingDelivered = LeadRepository::matchingIds($db, $scope, ['bounce_status' => 'delivered']);
    $assert($matchingDelivered === [$leadDelivered], 'bounce_status=delivered matches only the delivered lead');

    $matchingBounced = LeadRepository::matchingIds($db, $scope, ['bounce_status' => 'bounced']);
    $assert($matchingBounced === [$leadBouncedHard], 'bounce_status=bounced matches only the genuinely-currently-bounced lead, NOT the reassigned lead (its latest row is pending)');

    $matchingNoneStatus = LeadRepository::matchingIds($db, $scope, ['bounce_status' => 'none']);
    $assert($matchingNoneStatus === [$leadNeverAssigned], 'bounce_status=none matches only the never-assigned lead');

    // --- bounce_type
    $matchingHardBounce = LeadRepository::matchingIds($db, $scope, ['bounce_type' => 'Hard Bounce']);
    $assert($matchingHardBounce === [$leadBouncedHard], 'bounce_type=Hard Bounce matches only the currently-bounced lead, NOT the reassigned lead');

    $matchingNoneType = LeadRepository::matchingIds($db, $scope, ['bounce_type' => 'none']);
    sort($matchingNoneType);
    $expectedNoneType = [$leadNeverAssigned, $leadPending, $leadDelivered, $leadReassigned];
    sort($expectedNoneType);
    $assert($matchingNoneType === $expectedNoneType, 'bounce_type=none matches every lead whose CURRENT assignment (or lack of one) has no bounce_type recorded, including the reassigned lead');

    // --- delivery_status (multi-select IN match)
    $matchingActive = LeadRepository::matchingIds($db, $scope, ['delivery_status' => ['Active']]);
    $assert($matchingActive === [$leadDelivered], 'delivery_status=[Active] matches only the delivered lead');

    $matchingHardBounced = LeadRepository::matchingIds($db, $scope, ['delivery_status' => ['Hard Bounced']]);
    $assert($matchingHardBounced === [$leadBouncedHard], 'delivery_status=[Hard Bounced] matches only the currently-bounced lead, NOT the reassigned lead (its latest delivery_status is NULL)');

    $matchingMulti = LeadRepository::matchingIds($db, $scope, ['delivery_status' => ['Active', 'Hard Bounced']]);
    sort($matchingMulti);
    $expectedMulti = [$leadDelivered, $leadBouncedHard];
    sort($expectedMulti);
    $assert($matchingMulti === $expectedMulti, 'delivery_status=[Active, Hard Bounced] matches both (IN semantics)');

    // --- No filter set at all: everyone still matches (filters are opt-in, additive).
    $matchingAll = LeadRepository::matchingIds($db, $scope, []);
    $assert(count($matchingAll) === 5, 'With no bounce filters set, all 5 fixture leads still match (got ' . count($matchingAll) . ')');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll LeadRepository bounce-filter checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
