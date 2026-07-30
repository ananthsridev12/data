<?php
/**
 * One-off backfill: encrypts the existing global Saleshandy API key from
 * app/config/config.php (saleshandy.api_key) onto the single member's
 * users.saleshandy_api_key row that sql/033_multi_tenant_backfill.sql
 * already consolidated every pre-existing campaign's ownership onto (see
 * that file's "Saleshandy key handoff" comment). Run this once, after
 * migration 033 has been applied, before removing saleshandy.api_key
 * from config.php -- it's what lets the per-member Saleshandy key system
 * pick up right where the old single global key left off, with zero
 * interruption to already-linked campaigns.
 *
 * Usage: php tools/backfill_saleshandy_key.php [user_id_or_email]
 *
 * The target user is resolved, in order:
 *   1. The CLI argument, if given (a numeric users.id or an email).
 *   2. Otherwise, campaigns.saleshandy_account_owner_id -- if every
 *      existing campaign points to the same single owner (the normal
 *      post-033 state), that owner is used automatically.
 *
 * Safe to re-run: it validates the key live against Saleshandy before
 * writing anything, and always asks before overwriting a key the target
 * user has already connected themselves.
 */

require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/SaleshandyKeyCipher.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

$config = require __DIR__ . '/../app/config/config.php';
$apiKey = trim((string) ($config['saleshandy']['api_key'] ?? ''));

if ($apiKey === '') {
    fwrite(STDERR, "No saleshandy.api_key set in app/config/config.php -- nothing to migrate.\n");
    exit(1);
}

$db = db();

$arg = $argv[1] ?? null;
if ($arg !== null) {
    if (ctype_digit($arg)) {
        $userId = (int) $arg;
    } else {
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$arg]);
        $userId = (int) $stmt->fetchColumn();
        if (!$userId) {
            fwrite(STDERR, "No user found with email \"{$arg}\".\n");
            exit(1);
        }
    }
} else {
    $owners = array_map('intval', $db->query(
        'SELECT DISTINCT saleshandy_account_owner_id FROM campaigns WHERE saleshandy_account_owner_id IS NOT NULL'
    )->fetchAll(PDO::FETCH_COLUMN));

    if (count($owners) === 0) {
        fwrite(STDERR, "No campaign has an owner set yet -- run sql/033_multi_tenant_backfill.sql first, or pass a user id/email explicitly.\n");
        exit(1);
    }
    if (count($owners) > 1) {
        fwrite(STDERR, "Campaigns point to more than one owner (" . implode(', ', $owners) . ") -- pass the intended user id/email explicitly:\n");
        fwrite(STDERR, "  php tools/backfill_saleshandy_key.php <user_id_or_email>\n");
        exit(1);
    }
    $userId = $owners[0];
}

$userStmt = $db->prepare('SELECT id, name, email, saleshandy_api_key FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

if (!$user) {
    fwrite(STDERR, "No user found with id {$userId}.\n");
    exit(1);
}

if ($user['saleshandy_api_key'] !== null) {
    fwrite(STDOUT, "{$user['name']} ({$user['email']}) already has a Saleshandy key connected. Overwrite it with the config.php key? [y/N] ");
    $answer = trim((string) fgets(STDIN));
    if (strtolower($answer) !== 'y') {
        fwrite(STDOUT, "Aborted -- no changes made.\n");
        exit(0);
    }
}

try {
    $testClient = new SaleshandyClient($apiKey);
    $testClient->listFields();
} catch (SaleshandyApiException $ex) {
    fwrite(STDERR, "Could not validate the config.php Saleshandy key: {$ex->getMessage()}\n");
    exit(1);
}

$encrypted = SaleshandyKeyCipher::encrypt($apiKey);
$db->prepare('UPDATE users SET saleshandy_api_key = ?, saleshandy_connected_at = NOW() WHERE id = ?')
    ->execute([$encrypted, $userId]);

fwrite(STDOUT, "Done -- {$user['name']} ({$user['email']}) is now connected using the key from config.php.\n");
fwrite(STDOUT, "Once you've confirmed campaigns still sync correctly, remove saleshandy.api_key from app/config/config.php.\n");
