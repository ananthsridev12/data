<?php
/**
 * Manual "Run now" equivalent of campaign_saleshandy_field_sync_cron.php --
 * processes ONE campaign (round-robin, same as the cron) on demand.
 * Unlike the scheduled cron, this ignores the per-company
 * saleshandy_field_sync_cron_enabled toggle -- an explicit click is its
 * own consent, same as every other "Run now" button in this app.
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
    $result = $client->syncFieldsForNextCampaign(db(), $admin['id'], null);
    CronRunLog::record(db(), 'saleshandy_field_sync', 'manual', $result['summary']);
    flash_set($result['ok'] ? 'success' : 'danger', 'Saleshandy field sync: ' . $result['summary']);
} catch (SaleshandyApiException $ex) {
    CronRunLog::record(db(), 'saleshandy_field_sync', 'manual', 'Failed: ' . $ex->getMessage());
    flash_set('danger', 'Could not connect to Saleshandy: ' . $ex->getMessage());
}

header('Location: icp_segments.php');
exit;
