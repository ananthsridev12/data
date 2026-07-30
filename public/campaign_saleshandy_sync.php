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

if (!$campaign) {
    flash_set('danger', 'Campaign not found.');
    header('Location: campaigns.php');
    exit;
}

if (!$campaign['saleshandy_sequence_id']) {
    flash_set('danger', 'This campaign is not linked to a Saleshandy sequence yet.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $client = SaleshandyClient::forUser(db(), (int) $campaign['saleshandy_account_owner_id']);
    $stats = $client->syncCampaign(db(), $campaign, $user['id']);
    $message = "Synced from Saleshandy: {$stats['matched']} lead(s) updated ({$stats['bounced']} bounced, {$stats['replied']} replied).";
    if ($stats['released'] > 0) {
        $message .= " {$stats['released']} held lead(s) released -- their wave-1 leader's delivery was confirmed by this sync.";
    }
    if ($stats['verified'] > 0) {
        $message .= " {$stats['verified']} lead(s)' email verification status refreshed.";
    }
    flash_set('success', $message);
    if (!empty($stats['verification_error'])) {
        flash_set('danger', 'Email verification check failed: ' . $stats['verification_error']);
    }
} catch (SaleshandyApiException $ex) {
    flash_set('danger', 'Could not sync from Saleshandy: ' . $ex->getMessage());
}

header('Location: ' . $redirect);
exit;
