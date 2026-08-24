<?php
/**
 * Soft delete/restore for campaigns -- mirrors lead_delete.php's pattern.
 * A hard delete would cascade-remove a campaign's lead-assignment/
 * history data (see sql/045_campaign_soft_delete.sql), so this only ever
 * sets/clears deleted_at -- the campaign is hidden from campaigns.php and
 * every picker that goes through it, but nothing about it is actually
 * destroyed, and it's restorable from Deleted Campaigns at any time.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/CampaignAccess.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: campaigns.php');
    exit;
}

csrf_verify();

$action = $_POST['action'] ?? '';
$returnTo = ($_POST['return_to'] ?? '') === 'deleted' ? 'deleted_campaigns.php' : 'campaigns.php';

if ($action === 'delete' || $action === 'bulk_delete') {
    $ids = $action === 'delete'
        ? [(int) ($_POST['campaign_id'] ?? 0)]
        : array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['campaign_ids'] ?? [])))));

    if (!$ids) {
        flash_set('danger', 'Pick at least one campaign to delete.');
        header('Location: ' . $returnTo);
        exit;
    }

    $deleted = 0;
    foreach ($ids as $id) {
        $campaign = CampaignAccess::loadVisible(db(), $scope, $id);
        if (!$campaign || !CampaignAccess::canMutate($scope, $campaign) || $campaign['deleted_at'] !== null) {
            continue;
        }
        db()->prepare('UPDATE campaigns SET deleted_at = NOW(), deleted_by = ? WHERE id = ?')->execute([$user['id'], $id]);
        $deleted++;
    }

    if ($deleted > 0) {
        flash_set('success', ($deleted === 1 ? '1 campaign' : "{$deleted} campaigns") . ' deleted (hidden -- lead/history data is kept, and this can be undone from Deleted Campaigns).');
    } else {
        flash_set('danger', 'No campaigns could be deleted.');
    }
    header('Location: ' . $returnTo);
    exit;
}

if ($action === 'restore') {
    $id = (int) ($_POST['campaign_id'] ?? 0);
    $campaign = CampaignAccess::loadVisible(db(), $scope, $id);
    if (!$campaign || !CampaignAccess::canMutate($scope, $campaign) || $campaign['deleted_at'] === null) {
        flash_set('danger', 'Campaign not found.');
    } else {
        db()->prepare('UPDATE campaigns SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')->execute([$id]);
        flash_set('success', 'Campaign restored.');
    }
    header('Location: ' . $returnTo);
    exit;
}

flash_set('danger', 'Unknown action.');
header('Location: ' . $returnTo);
exit;
