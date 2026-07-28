<?php
/**
 * Manual "Run now" equivalent of cron_saleshandy_sync.php -- runs the
 * exact same combined sync (status/opens/bounces/replies) + pull
 * (new-prospect import) loop across every Saleshandy-linked campaign,
 * on demand instead of only via the scheduled cron hit. Distinct from
 * reports_sync.php's "Fetch to update" button, which deliberately only
 * runs the sync half (mirroring a campaign's own "Refresh statuses"
 * button) -- this one is the full 1:1 manual substitute for the cron.
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
    $result = $client->syncAllLinkedCampaigns(db(), $admin['id']);
    CronRunLog::record(db(), 'saleshandy_sync', 'manual', $result['summary']);
    flash_set('success', 'Saleshandy sync: ' . $result['summary']);
} catch (SaleshandyApiException $ex) {
    // Logged too (not just flashed) -- otherwise clicking "Run now" with
    // a missing/bad API key silently leaves "Last run" stuck on "Never
    // run yet" even though an attempt genuinely just happened.
    CronRunLog::record(db(), 'saleshandy_sync', 'manual', 'Failed: ' . $ex->getMessage());
    flash_set('danger', 'Could not connect to Saleshandy: ' . $ex->getMessage());
}

header('Location: icp_segments.php');
exit;
