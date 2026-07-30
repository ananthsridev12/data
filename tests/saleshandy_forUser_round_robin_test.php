<?php
// SaleshandyClient::forUser() resolution + the round-robin crons'
// skip-gracefully-if-owner-not-connected pattern (syncNextCampaign(),
// backfillNextCampaign(), syncFieldsForNextCampaign()): a campaign whose
// owner hasn't connected a Saleshandy key is skipped (ok=true, "skipped
// -- ..." summary) rather than failed, and its attempt timestamp is
// still stamped so round-robin rotation doesn't starve on it. Rolled
// back at the end.
//
// Usage: php tests/saleshandy_forUser_round_robin_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

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

    $mkUser = function (string $email, ?string $encryptedKey) use ($db, $companyId): int {
        $stmt = $db->prepare(
            'INSERT INTO users (company_id, name, email, password_hash, role, saleshandy_api_key, saleshandy_connected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, $email, $email, 'x', ROLE_ADMIN, $encryptedKey, $encryptedKey !== null ? date('Y-m-d H:i:s') : null]);
        return (int) $db->lastInsertId();
    };

    $connectedUserId = $mkUser('connected@a.test', SaleshandyKeyCipher::encrypt('fake-key-value'));
    $unconnectedUserId = $mkUser('unconnected@a.test', null);

    // --- forUser(): resolves a client for a connected user, throws a
    // clear exception for one with no key -- never a generic PDO/null
    // error, since that message is what a page or cron summary shows.
    $client = SaleshandyClient::forUser($db, $connectedUserId);
    $assert($client instanceof SaleshandyClient, 'forUser() resolves a client for a connected user');

    $threw = false;
    $message = '';
    try {
        SaleshandyClient::forUser($db, $unconnectedUserId);
    } catch (SaleshandyApiException $ex) {
        $threw = true;
        $message = $ex->getMessage();
    }
    $assert($threw, 'forUser() throws for a user with no key connected');
    $assert(str_contains($message, 'unconnected@a.test') || str_contains(strtolower($message), "hasn't connected"), 'forUser() exception names the disconnected owner');

    $mkCampaign = function (string $name, int $ownerId, string $extraCols = '', array $extraVals = []) use ($db, $companyId): int {
        $cols = 'company_id, name, created_by, saleshandy_account_owner_id, saleshandy_sequence_id' . ($extraCols !== '' ? ", {$extraCols}" : '');
        $placeholders = implode(',', array_fill(0, 5 + count($extraVals), '?'));
        $stmt = $db->prepare("INSERT INTO campaigns ({$cols}) VALUES ({$placeholders})");
        $stmt->execute(array_merge([$companyId, $name, $ownerId, $ownerId, 'seq-' . $name], $extraVals));
        return (int) $db->lastInsertId();
    };

    // Each round-robin method scans EVERY eligible campaign in the
    // database, not just the one just-inserted for that sub-test -- so
    // each block below un-links its campaign from Saleshandy right
    // after checking it, keeping it out of the next block's scan.

    // --- syncNextCampaign(): owner has no key -- skipped, not failed,
    // and the attempt timestamp is stamped so rotation moves on.
    $campId = $mkCampaign('Unconnected Sync', $unconnectedUserId);
    $result = SaleshandyClient::syncNextCampaign($db, $unconnectedUserId);
    $assert($result['ok'] === true, 'syncNextCampaign(): unconnected owner is skipped, not failed');
    $assert(str_contains($result['summary'], 'skipped'), 'syncNextCampaign(): summary says "skipped"');
    $stamped = (bool) $db->query("SELECT saleshandy_last_sync_attempt_at FROM campaigns WHERE id = {$campId}")->fetchColumn();
    $assert($stamped, 'syncNextCampaign(): attempt timestamp stamped even when skipped');
    $db->exec("UPDATE campaigns SET saleshandy_sequence_id = NULL WHERE id = {$campId}");

    // --- backfillNextCampaign(): same pattern.
    $campId2 = $mkCampaign('Unconnected Backfill', $unconnectedUserId);
    $result2 = SaleshandyClient::backfillNextCampaign($db, $unconnectedUserId);
    $assert($result2['ok'] === true, 'backfillNextCampaign(): unconnected owner is skipped, not failed');
    $assert(str_contains($result2['summary'], 'skipped'), 'backfillNextCampaign(): summary says "skipped"');
    $stamped2 = (bool) $db->query("SELECT saleshandy_backfill_last_attempt_at FROM campaigns WHERE id = {$campId2}")->fetchColumn();
    $assert($stamped2, 'backfillNextCampaign(): attempt timestamp stamped even when skipped');
    $db->exec("UPDATE campaigns SET saleshandy_sequence_id = NULL WHERE id = {$campId2}");

    // --- syncFieldsForNextCampaign(): same pattern, but only reachable
    // once there's at least one pushed assignment to sync -- with none,
    // it correctly reports "no pushed leads" before ever resolving a
    // client, so seed one pushed assignment first.
    $campId3 = $mkCampaign('Unconnected Fields', $unconnectedUserId);
    $db->exec("INSERT INTO leads (company_id, owner_id, first_name, last_name, email) VALUES ({$companyId}, {$unconnectedUserId}, 'A', 'B', 'a.b@example.com')");
    $leadId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO lead_campaign_assignments (lead_id, campaign_id, status, assigned_by) VALUES ({$leadId}, {$campId3}, 'pushed', {$unconnectedUserId})");
    $result3 = SaleshandyClient::syncFieldsForNextCampaign($db, $unconnectedUserId, null);
    $assert($result3['ok'] === true, 'syncFieldsForNextCampaign(): unconnected owner is skipped, not failed');
    $assert(str_contains($result3['summary'], 'skipped'), 'syncFieldsForNextCampaign(): summary says "skipped"');
    $stamped3 = (bool) $db->query("SELECT saleshandy_field_sync_last_attempt_at FROM campaigns WHERE id = {$campId3}")->fetchColumn();
    $assert($stamped3, 'syncFieldsForNextCampaign(): attempt timestamp stamped even when skipped');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll forUser()/round-robin skip checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
