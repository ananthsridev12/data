<?php
/**
 * Manual "Run now" equivalent of campaign_saleshandy_backfill_cron.php --
 * processes ONE not-yet-backfilled campaign (round-robin, same as the
 * cron) on demand instead of waiting for the next scheduled tick.
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

$result = SaleshandyClient::backfillNextCampaign(db(), $admin['id']);
CronRunLog::record(db(), 'saleshandy_backfill', 'manual', $result['summary']);
flash_set($result['ok'] ? 'success' : 'danger', 'Saleshandy backfill: ' . $result['summary']);

header('Location: icp_segments.php');
exit;
