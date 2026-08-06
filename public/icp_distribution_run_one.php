<?php
/**
 * Distributes ONE specific ICP segment by id -- the individual-action
 * counterpart to icp_distribution_run.php's round-robin "next due" pick.
 * See app/includes/IcpRepository.php's runDistributionForIcp().
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';
require_once __DIR__ . '/../app/includes/CronRunLog.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: sync_center.php');
    exit;
}

csrf_verify();

$icpId = (int) ($_POST['icp_id'] ?? 0);
$redirect = ($_POST['redirect_to'] ?? '') === 'icp_segments' ? 'icp_segments.php' : 'sync_center.php';

$result = IcpRepository::runDistributionForIcp(db(), $scope, $icpId);
CronRunLog::record(db(), 'icp_distribution', 'manual', $result['summary']);
flash_set($result['ok'] ? 'success' : 'danger', 'ICP distribution: ' . $result['summary']);

header('Location: ' . $redirect);
exit;
