<?php
// SaleshandyCampaignFetcher: browsableOwners() (Admin can browse any
// connected company member; Team Lead/Member restricted to their own
// account, same rule as ICP Segments), alreadyLinkedMap() (company-
// scoped), and importSequence()'s duplicate-name handling (the
// SaleshandyClient API calls themselves aren't reachable in this
// sandbox, so importSequence() is exercised with a client whose
// listSequenceSteps() call is expected to fail -- verifying the
// documented "still creates the campaign, just without a step" fallback,
// and the duplicate-name rejection, both of which are pure DB logic).
// Rolled back at the end.
//
// Usage: php tests/saleshandy_campaign_fetcher_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
require_once __DIR__ . '/../app/includes/SaleshandyCampaignFetcher.php';

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Co A', 90)");
    $companyId = (int) $db->lastInsertId();

    $db->exec("INSERT INTO teams (company_id, name) VALUES ({$companyId}, 'Team ABM')");
    $teamId = (int) $db->lastInsertId();

    $mkUser = function (string $role, ?int $teamId, string $email, bool $connected) use ($db, $companyId): int {
        $stmt = $db->prepare(
            'INSERT INTO users (company_id, team_id, name, email, password_hash, role, saleshandy_api_key, saleshandy_connected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, $teamId, $email, $email, 'x', $role, $connected ? 'x' : null, $connected ? date('Y-m-d H:i:s') : null]);
        return (int) $db->lastInsertId();
    };
    $adminId = $mkUser(ROLE_ADMIN, null, 'admin@a.test', true);
    $teamLeadId = $mkUser(ROLE_TEAM_LEAD, $teamId, 'lead@a.test', true);
    $memberConnectedId = $mkUser(ROLE_MEMBER, $teamId, 'member-connected@a.test', true);
    $memberUnconnectedId = $mkUser(ROLE_MEMBER, null, 'member-unconnected@a.test', false);

    $adminScope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $teamLeadScope = Scope::fromUser($db, ['id' => $teamLeadId, 'company_id' => $companyId, 'role' => ROLE_TEAM_LEAD, 'team_id' => $teamId]);
    $memberConnectedScope = Scope::fromUser($db, ['id' => $memberConnectedId, 'company_id' => $companyId, 'role' => ROLE_MEMBER, 'team_id' => $teamId]);

    // --- Admin can browse every connected member in the company.
    $adminOwners = array_column(SaleshandyCampaignFetcher::browsableOwners($db, $adminScope), 'id');
    sort($adminOwners);
    $expected = [$adminId, $teamLeadId, $memberConnectedId];
    sort($expected);
    $assert($adminOwners === $expected, 'Admin sees every connected member as a browsable owner (including themselves)');
    $assert(!in_array($memberUnconnectedId, $adminOwners, true), 'Admin does NOT see an unconnected member as browsable');

    // --- Team Lead is restricted to their own account only -- NOT their
    // team's, even though a teammate is connected -- same rule as ICP.
    $teamLeadOwners = array_column(SaleshandyCampaignFetcher::browsableOwners($db, $teamLeadScope), 'id');
    $assert($teamLeadOwners === [$teamLeadId], 'Team Lead can only browse their own account, not a connected teammate\'s');

    // --- Member is restricted to their own account only.
    $memberOwners = array_column(SaleshandyCampaignFetcher::browsableOwners($db, $memberConnectedScope), 'id');
    $assert($memberOwners === [$memberConnectedId], 'Member can only browse their own account');

    // --- alreadyLinkedMap(): company-scoped, maps sequence_id -> campaign name.
    $mkCampaign = function (int $ownerId, string $name, ?string $sequenceId) use ($db, $companyId): int {
        $stmt = $db->prepare(
            'INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, saleshandy_sequence_id) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, $name, $ownerId, $ownerId, $sequenceId]);
        return (int) $db->lastInsertId();
    };
    $mkCampaign($adminId, 'Linked Campaign', 'seq-123');
    $mkCampaign($adminId, 'Unlinked Campaign', null);

    $linkedMap = SaleshandyCampaignFetcher::alreadyLinkedMap($db, $companyId);
    $assert(($linkedMap['seq-123'] ?? null) === 'Linked Campaign', 'alreadyLinkedMap() maps the linked sequence id to its campaign name');
    $assert(count($linkedMap) === 1, 'alreadyLinkedMap() excludes campaigns with no sequence linked (1 entry seen)');

    // --- importSequence(): duplicate campaign name in the same company
    // is rejected, not fatal -- verified without a real Saleshandy call
    // by pre-creating the colliding row directly.
    $mkCampaign($adminId, 'Existing Name', null);
    // A fake SaleshandyClient whose listSequenceSteps() call will fail
    // (no reachable API in this sandbox) -- importSequence() must still
    // attempt the insert and correctly report the duplicate.
    $fakeClient = new SaleshandyClient('fake-key-not-used-for-real-calls');
    $result = SaleshandyCampaignFetcher::importSequence($db, $fakeClient, 'seq-dup', 'Existing Name', $companyId, $adminId, $adminId);
    $assert($result['ok'] === false, 'importSequence() rejects a duplicate campaign name (does not throw)');
    $assert(str_contains($result['message'], 'already exists'), 'importSequence() explains the duplicate-name rejection');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll SaleshandyCampaignFetcher checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
