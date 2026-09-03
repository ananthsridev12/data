<?php
/**
 * Bulk actions across multiple selected ICP segments at once, from
 * icp_segments.php's "Bulk actions" bar -- toggle auto-push, "only
 * reassign leads whose prior sequence fully completed", "don't re-pitch
 * the same service", or "only match leads never used in any campaign"
 * on/off, or trigger a distribute-now run, for every checked ICP in one
 * submit instead of clicking each one individually. Each ICP is still
 * gated through IcpRepository's normal per-ICP ownership/100%-links
 * checks (bulkSetBoolColumn() and its bulkSetAutoPush()/
 * bulkSetRequireSequenceCompleted()/bulkSetAvoidRepeatService()/
 * bulkSetExcludePreviouslyUsed() wrappers, and bulkDistribute() ->
 * runDistributionForIcp()), so a Team Lead/Member selecting a teammate's
 * ICP just has that one silently skipped rather than the whole batch
 * failing.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';
require_once __DIR__ . '/../app/includes/CronRunLog.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: icp_segments.php');
    exit;
}

csrf_verify();

$action = $_POST['action'] ?? '';
$icpIds = array_map('intval', (array) ($_POST['icp_ids'] ?? []));
$icpIds = array_values(array_filter($icpIds));

if (!$icpIds) {
    flash_set('danger', 'No ICP segments selected.');
    header('Location: icp_segments.php');
    exit;
}

// action => [repository method, human label for the flash message].
$boolToggles = [
    'bulk_auto_push_on' => ['bulkSetAutoPush', true, 'Auto-push'],
    'bulk_auto_push_off' => ['bulkSetAutoPush', false, 'Auto-push'],
    'bulk_require_sequence_completed_on' => ['bulkSetRequireSequenceCompleted', true, '"Only reassign leads whose prior sequence fully completed"'],
    'bulk_require_sequence_completed_off' => ['bulkSetRequireSequenceCompleted', false, '"Only reassign leads whose prior sequence fully completed"'],
    'bulk_avoid_repeat_service_on' => ['bulkSetAvoidRepeatService', true, '"Don\'t re-pitch the same service"'],
    'bulk_avoid_repeat_service_off' => ['bulkSetAvoidRepeatService', false, '"Don\'t re-pitch the same service"'],
    'bulk_exclude_previously_used_on' => ['bulkSetExcludePreviouslyUsed', true, '"Only match leads never used in any campaign"'],
    'bulk_exclude_previously_used_off' => ['bulkSetExcludePreviouslyUsed', false, '"Only match leads never used in any campaign"'],
];

if (isset($boolToggles[$action])) {
    [$method, $enabled, $label] = $boolToggles[$action];
    $updated = call_user_func([IcpRepository::class, $method], db(), $icpIds, $enabled, $scope);
    $skipped = count($icpIds) - $updated;
    $message = "{$label} turned " . ($enabled ? 'ON' : 'OFF') . " for {$updated} ICP(s).";
    if ($skipped > 0) {
        $message .= " {$skipped} skipped (not found, or not fully owned by you).";
    }
    flash_set($updated > 0 ? 'success' : 'danger', $message);
} elseif ($action === 'bulk_distribute') {
    $result = IcpRepository::bulkDistribute(db(), $icpIds, $scope);
    $summary = "Bulk distribute: {$result['ok_count']}/" . count($icpIds) . ' ICP(s) run, '
        . "{$result['total_assigned']} lead(s) assigned, {$result['total_pushed']} lead(s) auto-pushed.";
    CronRunLog::record(db(), 'icp_distribution', 'manual', $summary);
    $flashMessage = $summary;
    if ($result['lines']) {
        $flashMessage .= ' -- ' . implode(' | ', array_map('trim', $result['lines']));
    }
    flash_set($result['ok_count'] > 0 ? 'success' : 'danger', $flashMessage);
} else {
    flash_set('danger', 'Unknown bulk action.');
}

header('Location: icp_segments.php');
exit;
