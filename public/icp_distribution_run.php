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

$systemUserId = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();

$result = IcpRepository::runDistributionForNext(db(), $systemUserId);
CronRunLog::record(db(), 'icp_distribution', 'manual', $result['summary']);
flash_set('success', 'ICP distribution: ' . $result['summary']);

header('Location: icp_segments.php');
exit;
