<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';
require_once __DIR__ . '/../app/includes/CampaignAccess.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: campaigns.php');
    exit;
}

csrf_verify();

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$redirect = 'campaign_leads.php?campaign_id=' . $campaignId;

$campaign = CampaignAccess::loadVisible(db(), $scope, $campaignId);

if (!$campaign || !CampaignAccess::canMutate($scope, $campaign)) {
    flash_set('danger', 'Campaign not found.');
    header('Location: campaigns.php');
    exit;
}

if (!$campaign['saleshandy_sequence_id'] || !$campaign['saleshandy_step_id']) {
    flash_set('danger', 'This campaign is not linked to a Saleshandy sequence/step yet -- use "Configure" on the Campaigns page first.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $client = SaleshandyClient::forUser(db(), (int) $campaign['saleshandy_account_owner_id']);
} catch (SaleshandyApiException $ex) {
    flash_set('danger', 'Could not reach Saleshandy: ' . $ex->getMessage());
    header('Location: ' . $redirect);
    exit;
}

$includeRisky = !empty($_POST['include_risky']);
$result = $client->pushCampaignLeads(db(), $campaign, $includeRisky);

if ($result['verification_check_error']) {
    flash_set('info', 'Could not check email verification status (' . $result['verification_check_error'] . ') -- pushing without a verification filter this time.');
}
if ($result['pushed'] > 0) {
    $verificationNote = ($result['skipped_bad'] || $result['skipped_risky'])
        ? " ({$result['skipped_bad']} skipped -- bad email" . ($result['skipped_risky'] ? ", {$result['skipped_risky']} skipped -- risky email" : '') . ')'
        : '';
    flash_set('success', "{$result['pushed']} lead(s) queued for push to Saleshandy{$verificationNote} -- run \"Refresh statuses\" in a few minutes to confirm delivery.");
} elseif (!$result['errors']) {
    $note = ($result['skipped_bad'] || $result['skipped_risky'])
        ? "No leads left to push after verification filtering ({$result['skipped_bad']} bad, {$result['skipped_risky']} risky)."
        : 'No push-eligible leads for this campaign right now (already pushed, held for wave-1, or suppressed).';
    flash_set('info', $note);
}
if ($result['stale_labels']) {
    flash_set(
        'danger',
        'These enabled field mapping(s) were NOT sent, because their Saleshandy label no longer matches a real field there -- '
            . 'fix them on Saleshandy Field Mapping (fetch the field list, then re-pick the label): '
            . implode(', ', $result['stale_labels'])
    );
}
if ($result['errors']) {
    flash_set('danger', 'Some pushes failed: ' . implode('; ', array_unique($result['errors'])));
}

header('Location: ' . $redirect);
exit;
