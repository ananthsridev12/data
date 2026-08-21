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
// $result['lines'] carries the per-campaign breakdown AND any auto-push
// errors (e.g. Saleshandy rate-limit failures, stale field mappings) --
// previously computed here and then silently discarded, leaving no way
// to tell "assigned but not pushed" apart from "actually pushed
// everything eligible" without digging through server logs.
$flashMessage = 'ICP distribution: ' . $result['summary'];
if ($result['lines']) {
    $flashMessage .= ' -- ' . implode(' ', array_map('trim', $result['lines']));
}
flash_set($result['ok'] ? 'success' : 'danger', $flashMessage);

header('Location: ' . $redirect);
exit;
