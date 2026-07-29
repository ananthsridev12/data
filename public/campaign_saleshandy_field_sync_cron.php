<?php
/**
 * Keeps already-pushed leads' Saleshandy custom fields (Vertical,
 * Service, etc.) current without waiting for a manual "Sync fields"
 * bulk action -- processes ONE campaign per hit
 * (SaleshandyClient::syncFieldsForNextCampaign(), round-robin, batching
 * up to 50 leads at a time within that campaign), same pattern as
 * cron_saleshandy_sync.php / campaign_saleshandy_backfill_cron.php.
 *
 * Unlike those two, this is OPT-IN per company: it does nothing for a
 * company until an admin checks "Enable automatic Saleshandy field-sync
 * cron" on Company Profile (companies.saleshandy_field_sync_cron_enabled)
 * -- field-syncing can mean a lot of Saleshandy API calls for a large
 * campaign, so it shouldn't start firing the moment this cron URL is
 * added to cPanel without an explicit decision to turn it on.
 *
 * Intended to be hit by a cPanel Cron Job (e.g.
 * `wget -q -O /dev/null "https://yoursite.com/campaign_saleshandy_field_sync_cron.php?token=..."`),
 * not a logged-in browser -- same shared-secret token auth as the other
 * three cron scripts, reusing the same config value.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';
require_once __DIR__ . '/../app/includes/CronRunLog.php';

header('Content-Type: text/plain');

$config = require __DIR__ . '/../app/config/config.php';
$expectedToken = $config['saleshandy']['cron_token'] ?? '';
$givenToken = $_GET['token'] ?? '';

if ($expectedToken === '' || !hash_equals($expectedToken, (string) $givenToken)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$eligibleCompanyIds = array_map('intval', db()->query(
    'SELECT id FROM companies WHERE saleshandy_field_sync_cron_enabled = 1'
)->fetchAll(PDO::FETCH_COLUMN));

if (!$eligibleCompanyIds) {
    $message = 'Field-sync cron is not enabled for any company -- nothing to do. Enable it on Company Profile.';
    echo "{$message}\n";
    // Logged even though there's no real work -- otherwise "Last run" on
    // the ICP Segments cron-status card can't distinguish "the cPanel job
    // never actually fired" from "it fired but is turned off," which is
    // exactly the ambiguity CronRunLog exists to resolve.
    CronRunLog::record(db(), 'saleshandy_field_sync', 'cron', $message);
    exit;
}

$systemUserId = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if (!$systemUserId) {
    echo "No admin user found to attribute sync actions to -- aborting.\n";
    exit;
}

try {
    $client = SaleshandyClient::fromConfig($config);
} catch (SaleshandyApiException $ex) {
    echo "Saleshandy not configured: {$ex->getMessage()}\n";
    CronRunLog::record(db(), 'saleshandy_field_sync', 'cron', 'Failed: ' . $ex->getMessage());
    exit;
}

$result = $client->syncFieldsForNextCampaign(db(), $systemUserId, $eligibleCompanyIds);
echo "{$result['summary']}\n";

CronRunLog::record(db(), 'saleshandy_field_sync', 'cron', $result['summary']);
