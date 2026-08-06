<?php
// IcpRepository::runDistributionForIcp() -- the individual "Distribute
// now" action on Sync Center, distinct from the round-robin
// runDistributionForNext(). Same ownership/100%-links gating as every
// other ICP-mutating action (see tests/icp_owner_scope_test.php for the
// underlying isFullyOwnedBySelf() rule this reuses). Rolled back at the
// end.
//
// Usage: php tests/icp_distribution_run_one_test.php

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Co A', 90), ('Co B', 90)");
    $companyAId = (int) $db->lastInsertId();
    $companyBId = $companyAId + 1;

    $mkUser = function (string $role, int $companyId, string $email) use ($db): int {
        $stmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $email, $email, 'x', $role]);
        return (int) $db->lastInsertId();
    };
    $adminId = $mkUser(ROLE_ADMIN, $companyAId, 'admin@a.test');
    $memberSoloId = $mkUser(ROLE_MEMBER, $companyAId, 'member-solo@a.test');
    $memberOtherId = $mkUser(ROLE_MEMBER, $companyAId, 'member-other@a.test');
    $memberBId = $mkUser(ROLE_MEMBER, $companyBId, 'member-b@b.test');

    $adminScope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyAId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $memberSoloScope = Scope::fromUser($db, ['id' => $memberSoloId, 'company_id' => $companyAId, 'role' => ROLE_MEMBER, 'team_id' => null]);
    $memberBScope = Scope::fromUser($db, ['id' => $memberBId, 'company_id' => $companyBId, 'role' => ROLE_MEMBER, 'team_id' => null]);

    $mkCampaign = function (int $companyId, int $ownerId, string $name) use ($db): int {
        $stmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$companyId, $name, $ownerId, $ownerId]);
        return (int) $db->lastInsertId();
    };
    $campSolo = $mkCampaign($companyAId, $memberSoloId, 'Solo Campaign');
    $campOther = $mkCampaign($companyAId, $memberOtherId, 'Other Member Campaign');

    $mkIcp = static function (int $companyId, int $userId) use ($db): int {
        return IcpRepository::create($db, [
            'name' => 'ICP ' . uniqid(), 'vertical_id' => null, 'role_group_id' => null, 'service_id' => null,
            'country_group_id' => null, 'company_country' => '', 'industry' => '', 'seniority' => '', 'employee_count' => '',
        ], $userId, $companyId);
    };

    // --- Solo member's own ICP, single link -- auto-splits to 100%,
    // fully owned -- should succeed.
    $icpFullyOwned = $mkIcp($companyAId, $memberSoloId);
    IcpRepository::addLink($db, $icpFullyOwned, $campSolo, $memberSoloScope);
    $result1 = IcpRepository::runDistributionForIcp($db, $memberSoloScope, $icpFullyOwned);
    $assert($result1['ok'] === true, 'A fully-owned, 100%-split ICP distributes successfully for its own Member (got ' . var_export($result1['ok'], true) . ': ' . $result1['summary'] . ')');

    // --- Same ICP, but Admin adds a teammate's campaign to it -- Member
    // loses full ownership, distribution must now be rejected for them.
    IcpRepository::addLink($db, $icpFullyOwned, $campOther, $adminScope);
    $result2 = IcpRepository::runDistributionForIcp($db, $memberSoloScope, $icpFullyOwned);
    $assert($result2['ok'] === false, 'Once a teammate\'s campaign is linked, the original Member can no longer distribute it');
    $assert(str_contains($result2['summary'], 'only distribute'), 'Rejection message explains the ownership rule (got: ' . $result2['summary'] . ')');

    // --- Admin can distribute the same (now mixed-owner) ICP regardless.
    $result3 = IcpRepository::runDistributionForIcp($db, $adminScope, $icpFullyOwned);
    $assert($result3['ok'] === true, 'Admin can distribute any ICP in the company regardless of campaign ownership');

    // --- Links not summing to 100%: insert a lopsided split directly
    // (bypassing addLink()'s auto-balance) to construct the case.
    $icpBadSplit = $mkIcp($companyAId, $memberSoloId);
    $db->prepare('INSERT INTO icp_campaign_links (icp_id, campaign_id, percentage) VALUES (?, ?, ?)')->execute([$icpBadSplit, $campSolo, 50]);
    $result4 = IcpRepository::runDistributionForIcp($db, $memberSoloScope, $icpBadSplit);
    $assert($result4['ok'] === false, 'An ICP whose links don\'t sum to 100% is rejected, not silently distributed');
    $assert(str_contains($result4['summary'], "don't sum to 100%"), 'Rejection message explains the percentage problem (got: ' . $result4['summary'] . ')');

    // --- Cross-tenant: company B's member cannot distribute company A's ICP by id.
    $result5 = IcpRepository::runDistributionForIcp($db, $memberBScope, $icpFullyOwned);
    $assert($result5['ok'] === false && str_contains($result5['summary'], 'not found'), "Company B cannot distribute company A's ICP by id -- treated as not found (got: {$result5['summary']})");

    // --- Nonexistent id.
    $result6 = IcpRepository::runDistributionForIcp($db, $adminScope, 999999);
    $assert($result6['ok'] === false && str_contains($result6['summary'], 'not found'), 'A nonexistent ICP id is reported as not found');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll runDistributionForIcp() checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
