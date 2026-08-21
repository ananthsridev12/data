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

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: icp_segments.php');
    exit;
}

csrf_verify();

$result = IcpRepository::runDistributionForNext(db(), $user['id'], $scope->companyId, $scope->isAdmin() ? null : $scope->userId);
CronRunLog::record(db(), 'icp_distribution', 'manual', $result['summary']);
// $result['lines'] carries the per-campaign breakdown AND any auto-push
// errors (e.g. Saleshandy rate-limit failures, stale field mappings) --
// previously computed here and then silently discarded.
$flashMessage = 'ICP distribution: ' . $result['summary'];
if ($result['lines']) {
    $flashMessage .= ' -- ' . implode(' ', array_map('trim', $result['lines']));
}
flash_set('success', $flashMessage);

header('Location: icp_segments.php');
exit;
