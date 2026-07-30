<?php
// SaleshandyClient::syncNextCampaign()/backfillNextCampaign()/
// syncFieldsForNextCampaign() and IcpRepository::runDistributionForNext()
// -- verifies the new company_id/ownerId restriction used by the manual
// "Run now" buttons (previously these swept EVERY company's campaigns/
// ICPs regardless of who clicked, a real cross-tenant bug for the
// manual endpoints specifically -- the scheduled cron still needs that
// unrestricted sweep, so it keeps passing no restriction at all).
// Rolled back at the end.
//
// Usage: php tests/round_robin_manual_run_scope_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';
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

    $mkUser = function (int $companyId, string $email) use ($db): int {
        $stmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $email, $email, 'x', ROLE_ADMIN]);
        return (int) $db->lastInsertId();
    };
    $userAId = $mkUser($companyAId, 'a@a.test');
    $userA2Id = $mkUser($companyAId, 'a2@a.test');
    $userBId = $mkUser($companyBId, 'b@b.test');

    $mkCampaign = function (int $companyId, int $ownerId, string $name) use ($db): int {
        $stmt = $db->prepare(
            "INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, saleshandy_sequence_id)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$companyId, $name, $ownerId, $ownerId, 'seq-' . $name]);
        return (int) $db->lastInsertId();
    };
    $campA1 = $mkCampaign($companyAId, $userAId, 'Company A, User A');
    $campA2 = $mkCampaign($companyAId, $userA2Id, 'Company A, User A2');
    $campB1 = $mkCampaign($companyBId, $userBId, 'Company B, User B');

    // --- syncNextCampaign(): with no restriction (the scheduled cron's
    // call shape), any company's campaign is fair game -- picking company
    // B's campaign is a legitimate possibility here, not a bug, since the
    // cron deliberately sweeps everyone. What matters is company A's own
    // manual run (below) can never land on it.
    $resultCompanyBOnly = SaleshandyClient::syncNextCampaign($db, $userBId, $companyBId);
    $assert($resultCompanyBOnly['campaign'] === 'Company B, User B', 'syncNextCampaign(companyId=B): only ever picks company B\'s campaign');

    // --- Company-restricted manual run: company A's click can only ever
    // land on company A's own campaigns, never company B's.
    $resultCompanyA = SaleshandyClient::syncNextCampaign($db, $userAId, $companyAId);
    $assert(in_array($resultCompanyA['campaign'], ['Company A, User A', 'Company A, User A2'], true), 'syncNextCampaign(companyId=A): only ever picks a company A campaign');

    // Reset the attempt timestamp so the next assertion isn't just seeing
    // round-robin naturally move on to the untouched campaign.
    $db->exec("UPDATE campaigns SET saleshandy_last_sync_attempt_at = NULL WHERE company_id = {$companyAId}");

    // --- Owner-restricted manual run (a Member/Team Lead's click):
    // restricted to exactly their own campaign, even though a same-
    // company campaign owned by someone else is also eligible.
    $resultOwnerA = SaleshandyClient::syncNextCampaign($db, $userAId, $companyAId, $userAId);
    $assert($resultOwnerA['campaign'] === 'Company A, User A', 'syncNextCampaign(companyId=A, ownerId=userA): only ever picks userA\'s own campaign');

    // --- backfillNextCampaign(): same two restrictions.
    $db->exec("UPDATE campaigns SET saleshandy_last_sync_attempt_at = NOW()"); // keep sync's picks out of backfill's own rotation logic
    $resultBackfillOwner = SaleshandyClient::backfillNextCampaign($db, $userA2Id, $companyAId, $userA2Id);
    $assert($resultBackfillOwner['campaign'] === 'Company A, User A2', 'backfillNextCampaign(companyId=A, ownerId=userA2): only ever picks userA2\'s own campaign');

    // --- syncFieldsForNextCampaign(): owner restriction on top of the
    // existing eligibleCompanyIds mechanism.
    $db->exec("INSERT INTO leads (company_id, owner_id, first_name, last_name, email) VALUES ({$companyAId}, {$userAId}, 'A', 'B', 'x@example.com')");
    $leadId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO lead_campaign_assignments (lead_id, campaign_id, status, assigned_by) VALUES ({$leadId}, {$campA2}, 'pushed', {$userA2Id})");
    $resultFieldSyncOwner = SaleshandyClient::syncFieldsForNextCampaign($db, $userA2Id, [$companyAId], 50, $userA2Id);
    $assert($resultFieldSyncOwner['campaign'] === 'Company A, User A2', 'syncFieldsForNextCampaign(eligibleCompanyIds=[A], ownerId=userA2): only ever picks userA2\'s own campaign');

    // --- IcpRepository::runDistributionForNext(): same two restrictions,
    // via the ICP ownership model (isFullyOwnedBySelf).
    $icpA1 = IcpRepository::create($db, ['name' => 'ICP A1', 'vertical_id' => null, 'role_group_id' => null, 'service_id' => null, 'country_group_id' => null, 'company_country' => '', 'industry' => '', 'seniority' => 'Manager', 'employee_count' => ''], $userAId, $companyAId);
    $scopeA = Scope::fromUser($db, ['id' => $userAId, 'company_id' => $companyAId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    IcpRepository::addLink($db, $icpA1, $campA1, $scopeA);

    $icpA2 = IcpRepository::create($db, ['name' => 'ICP A2', 'vertical_id' => null, 'role_group_id' => null, 'service_id' => null, 'country_group_id' => null, 'company_country' => '', 'industry' => '', 'seniority' => 'Manager', 'employee_count' => ''], $userA2Id, $companyAId);
    $scopeA2 = Scope::fromUser($db, ['id' => $userA2Id, 'company_id' => $companyAId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    IcpRepository::addLink($db, $icpA2, $campA2, $scopeA2);

    $icpB1 = IcpRepository::create($db, ['name' => 'ICP B1', 'vertical_id' => null, 'role_group_id' => null, 'service_id' => null, 'country_group_id' => null, 'company_country' => '', 'industry' => '', 'seniority' => 'Manager', 'employee_count' => ''], $userBId, $companyBId);
    $scopeB = Scope::fromUser($db, ['id' => $userBId, 'company_id' => $companyBId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    IcpRepository::addLink($db, $icpB1, $campB1, $scopeB);

    $icpResultOwner = IcpRepository::runDistributionForNext($db, $userA2Id, $companyAId, $userA2Id);
    $assert(str_contains($icpResultOwner['summary'], 'ICP A2'), 'runDistributionForNext(companyId=A, restrictToUserId=userA2): only ever picks userA2\'s own ICP');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll manual-run scoping checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
