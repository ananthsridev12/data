<?php
/**
 * Bulk-sets Vertical / Service / Country Group across several campaigns
 * at once, from the checkbox picker on campaigns.php -- the same three
 * single-select classification fields the per-campaign create/edit form
 * already has (see sql/018_campaign_vertical_service.sql,
 * sql/044_campaign_country_group.sql), just applied to many campaigns in
 * one submit instead of editing each one individually.
 *
 * Each of the three fields is independently one of:
 *   ''      -- don't change this field for the selected campaigns
 *   'clear' -- clear it (set to NULL/"Any")
 *   <id>    -- set it to that lookup row
 * so leaving a field on its default doesn't accidentally wipe it.
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

$campaignIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['campaign_ids'] ?? [])))));

$setClauses = [];
$setParams = [];
foreach (['vertical_id', 'service_id', 'country_group_id'] as $field) {
    $raw = trim((string) ($_POST[$field] ?? ''));
    if ($raw === '') {
        continue; // don't change
    }
    $setClauses[] = "{$field} = ?";
    $setParams[] = $raw === 'clear' ? null : (int) $raw;
}

if (!$campaignIds) {
    flash_set('danger', 'Pick at least one campaign to update.');
    header('Location: campaigns.php');
    exit;
}

if (!$setClauses) {
    flash_set('danger', 'Pick at least one field to bulk-update (Vertical, Service, or Country Group) -- "Don\'t change" on all three does nothing.');
    header('Location: campaigns.php');
    exit;
}

$updateStmt = db()->prepare('UPDATE campaigns SET ' . implode(', ', $setClauses) . ' WHERE id = ?');

$updated = 0;
foreach ($campaignIds as $id) {
    $campaign = CampaignAccess::loadVisible(db(), $scope, $id);
    if (!$campaign || !CampaignAccess::canMutate($scope, $campaign) || $campaign['deleted_at'] !== null) {
        continue;
    }
    $updateStmt->execute([...$setParams, $id]);
    $updated++;
}

if ($updated > 0) {
    flash_set('success', ($updated === 1 ? '1 campaign' : "{$updated} campaigns") . ' updated.');
} else {
    flash_set('danger', 'No campaigns could be updated.');
}

header('Location: campaigns.php');
exit;
