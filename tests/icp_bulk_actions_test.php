<?php
// IcpRepository's bulk-toggle methods (bulkSetAutoPush()/
// bulkSetRequireSequenceCompleted()/bulkSetAvoidRepeatService(), all
// thin wrappers over the shared bulkSetBoolColumn()) plus bulkDistribute()
// -- the "Bulk actions" bar on icp_segments.php, applying a toggle or a
// distribute-now run across multiple selected ICPs at once. Each id
// still goes through the same per-ICP ownership gate as the single-ICP
// actions (toggleActive()/runDistributionForIcp(), see
// icp_owner_scope_test.php and icp_distribution_run_one_test.php) -- an
// id the caller doesn't fully own, or that doesn't exist, is silently
// skipped/reported rather than failing the whole batch. Rolled back at
// the end.
//
// Usage: php tests/icp_bulk_actions_test.php

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Co A', 90)");
    $companyId = (int) $db->lastInsertId();

    $mkUser = function (string $role, string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $email, $email, 'x', $role]);
        return (int) $db->lastInsertId();
    };
    $adminId = $mkUser(ROLE_ADMIN, 'admin@bulkicp.test');
    $memberSoloId = $mkUser(ROLE_MEMBER, 'member-solo@bulkicp.test');
    $memberOtherId = $mkUser(ROLE_MEMBER, 'member-other@bulkicp.test');

    $adminScope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $memberSoloScope = Scope::fromUser($db, ['id' => $memberSoloId, 'company_id' => $companyId, 'role' => ROLE_MEMBER, 'team_id' => null]);

    $mkCampaign = function (int $ownerId, string $name) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$companyId, $name, $ownerId, $ownerId]);
        return (int) $db->lastInsertId();
    };
    $campSolo = $mkCampaign($memberSoloId, 'Solo Campaign');
    $campOther = $mkCampaign($memberOtherId, 'Other Member Campaign');

    $mkIcp = static function (int $userId) use ($db, $companyId): int {
        return IcpRepository::create($db, [
            'name' => 'ICP ' . uniqid(), 'vertical_id' => null, 'role_group_id' => null, 'service_id' => null,
            'country_group_id' => null, 'company_country' => '', 'industry' => '', 'seniority' => '', 'employee_count' => '',
            'auto_push_enabled' => false,
        ], $userId, $companyId);
    };

    // Fully owned by the solo member.
    $icpOwnedBySolo = $mkIcp($memberSoloId);
    IcpRepository::addLink($db, $icpOwnedBySolo, $campSolo, $memberSoloScope);

    // Owned by a different member entirely -- solo member has no stake.
    $icpOwnedByOther = $mkIcp($memberOtherId);
    IcpRepository::addLink($db, $icpOwnedByOther, $campOther, Scope::fromUser($db, ['id' => $memberOtherId, 'company_id' => $companyId, 'role' => ROLE_MEMBER, 'team_id' => null]));

    // --- bulkSetAutoPush(): a non-admin selecting both their own ICP and
    // someone else's -- only their own is updated, the other silently skipped.
    $updated = IcpRepository::bulkSetAutoPush($db, [$icpOwnedBySolo, $icpOwnedByOther], true, $memberSoloScope);
    $assert($updated === 1, "Solo member's bulk auto-push-on updates exactly their own ICP, skipping the other (got {$updated})");

    $ownFlag = (int) $db->query("SELECT auto_push_enabled FROM icp_segments WHERE id = {$icpOwnedBySolo}")->fetchColumn();
    $otherFlag = (int) $db->query("SELECT auto_push_enabled FROM icp_segments WHERE id = {$icpOwnedByOther}")->fetchColumn();
    $assert($ownFlag === 1, "The solo member's own ICP actually got auto_push_enabled = 1");
    $assert($otherFlag === 0, "The other member's ICP was untouched (still 0)");

    // --- Admin can bulk-toggle both, and turn it back off again.
    $updatedByAdmin = IcpRepository::bulkSetAutoPush($db, [$icpOwnedBySolo, $icpOwnedByOther], false, $adminScope);
    $assert($updatedByAdmin === 2, "Admin's bulk auto-push-off updates both ICPs regardless of campaign ownership (got {$updatedByAdmin})");
    $bothOffCount = (int) $db->query("SELECT COUNT(*) FROM icp_segments WHERE id IN ({$icpOwnedBySolo}, {$icpOwnedByOther}) AND auto_push_enabled = 0")->fetchColumn();
    $assert($bothOffCount === 2, 'Both ICPs are now auto_push_enabled = 0');

    // --- A nonexistent id mixed into the batch is just skipped, not fatal.
    $updatedWithBogus = IcpRepository::bulkSetAutoPush($db, [$icpOwnedBySolo, 999999], true, $adminScope);
    $assert($updatedWithBogus === 1, "A nonexistent id in the batch is skipped without error, real one still updates (got {$updatedWithBogus})");

    // --- bulkSetRequireSequenceCompleted(): same ownership gate, different column.
    $updatedSeq = IcpRepository::bulkSetRequireSequenceCompleted($db, [$icpOwnedBySolo, $icpOwnedByOther], true, $memberSoloScope);
    $assert($updatedSeq === 1, "Solo member's bulk require-sequence-completed-on updates exactly their own ICP (got {$updatedSeq})");
    $ownSeqFlag = (int) $db->query("SELECT require_sequence_completed FROM icp_segments WHERE id = {$icpOwnedBySolo}")->fetchColumn();
    $otherSeqFlag = (int) $db->query("SELECT require_sequence_completed FROM icp_segments WHERE id = {$icpOwnedByOther}")->fetchColumn();
    $assert($ownSeqFlag === 1, "The solo member's own ICP got require_sequence_completed = 1");
    $assert($otherSeqFlag === 0, "The other member's ICP was untouched (still 0)");

    // --- bulkSetAvoidRepeatService(): same ownership gate, different column.
    $updatedSvc = IcpRepository::bulkSetAvoidRepeatService($db, [$icpOwnedBySolo, $icpOwnedByOther], true, $memberSoloScope);
    $assert($updatedSvc === 1, "Solo member's bulk avoid-repeat-service-on updates exactly their own ICP (got {$updatedSvc})");
    $ownSvcFlag = (int) $db->query("SELECT avoid_repeat_service FROM icp_segments WHERE id = {$icpOwnedBySolo}")->fetchColumn();
    $otherSvcFlag = (int) $db->query("SELECT avoid_repeat_service FROM icp_segments WHERE id = {$icpOwnedByOther}")->fetchColumn();
    $assert($ownSvcFlag === 1, "The solo member's own ICP got avoid_repeat_service = 1");
    $assert($otherSvcFlag === 0, "The other member's ICP was untouched (still 0)");

    // --- Admin can bulk-toggle both flags back off across both ICPs.
    $updatedSeqOffByAdmin = IcpRepository::bulkSetRequireSequenceCompleted($db, [$icpOwnedBySolo, $icpOwnedByOther], false, $adminScope);
    $updatedSvcOffByAdmin = IcpRepository::bulkSetAvoidRepeatService($db, [$icpOwnedBySolo, $icpOwnedByOther], false, $adminScope);
    $assert($updatedSeqOffByAdmin === 2 && $updatedSvcOffByAdmin === 2, "Admin's bulk-off updates both ICPs for both flags regardless of campaign ownership (got {$updatedSeqOffByAdmin}, {$updatedSvcOffByAdmin})");

    // --- bulkDistribute(): aggregates per-ICP runDistributionForIcp()
    // results -- the solo member's fully-owned, 100%-split ICP succeeds;
    // the other member's ICP (not owned by the solo member) is rejected
    // and reported in $lines, but doesn't stop the batch.
    $bulkResult = IcpRepository::bulkDistribute($db, [$icpOwnedBySolo, $icpOwnedByOther], $memberSoloScope);
    $assert($bulkResult['ok_count'] === 1, "bulkDistribute() reports exactly 1 successful run out of 2 (got {$bulkResult['ok_count']})");
    $assert(count($bulkResult['lines']) === 2, 'bulkDistribute() reports one summary line per ICP attempted, including the rejected one');
    $joinedLines = implode(' ', $bulkResult['lines']);
    $assert(str_contains($joinedLines, 'only distribute'), "The rejected ICP's line explains why (got: {$joinedLines})");

    // --- Admin bulk-distributing the same pair: both succeed.
    $bulkResultAdmin = IcpRepository::bulkDistribute($db, [$icpOwnedBySolo, $icpOwnedByOther], $adminScope);
    $assert($bulkResultAdmin['ok_count'] === 2, "Admin's bulkDistribute() succeeds on both ICPs (got {$bulkResultAdmin['ok_count']})");

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll ICP bulk-actions checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
