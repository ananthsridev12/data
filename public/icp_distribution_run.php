<?php
/**
 * Manual "Run now" equivalent of icp_distribution_cron.php -- processes
 * ONE ICP (round-robin, same as the cron) on demand instead of waiting
 * for the next scheduled tick, so an admin doesn't have to wait to see
 * it work while testing, or to force a sweep right after fixing an
 * ICP's percentage split.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';
require_once __DIR__ . '/../app/includes/CronRunLog.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: icp_segments.php');
    exit;
}

csrf_verify();

$config = require __DIR__ . '/../app/config/config.php';
$systemUserId = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();

$client = null;
try {
    $client = SaleshandyClient::fromConfig($config);
} catch (SaleshandyApiException $ex) {
    flash_set('info', 'Saleshandy not configured -- auto-push ICPs will be skipped this run (' . $ex->getMessage() . ').');
}

$result = IcpRepository::runDistributionForNext(db(), $client, $systemUserId);
CronRunLog::record(db(), 'icp_distribution', 'manual', $result['summary']);
flash_set('success', 'ICP distribution: ' . $result['summary']);

header('Location: icp_segments.php');
exit;
