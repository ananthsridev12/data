<?php
/**
 * Manual "Run now" equivalent of cron_saleshandy_sync.php -- processes
 * ONE campaign (round-robin, same as the cron) on demand instead of
 * waiting for the next scheduled tick. Distinct from reports_sync.php's
 * "Fetch to update" button, which loops every linked campaign's sync
 * half in one request (mirroring a campaign's own "Refresh statuses"
 * button) -- fine occasionally by hand, but not what this button or the
 * cron do by default, since that loop is what risked a slow/timing-out
 * page load with more than a handful of linked campaigns.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';
require_once __DIR__ . '/../app/includes/CronRunLog.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: icp_segments.php');
    exit;
}

csrf_verify();

$config = require __DIR__ . '/../app/config/config.php';

try {
    $client = SaleshandyClient::fromConfig($config);
    $result = $client->syncNextCampaign(db(), $admin['id']);
    CronRunLog::record(db(), 'saleshandy_sync', 'manual', $result['summary']);
    flash_set($result['ok'] ? 'success' : 'danger', 'Saleshandy sync: ' . $result['summary']);
} catch (SaleshandyApiException $ex) {
    // Logged too (not just flashed) -- otherwise clicking "Run now" with
    // a missing/bad API key silently leaves "Last run" stuck on "Never
    // run yet" even though an attempt genuinely just happened.
    CronRunLog::record(db(), 'saleshandy_sync', 'manual', 'Failed: ' . $ex->getMessage());
    flash_set('danger', 'Could not connect to Saleshandy: ' . $ex->getMessage());
}

header('Location: icp_segments.php');
exit;
