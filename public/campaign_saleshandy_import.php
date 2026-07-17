<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: campaigns.php');
    exit;
}

csrf_verify();

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$redirect = 'campaign_leads.php?campaign_id=' . $campaignId;

$stmt = db()->prepare('SELECT * FROM campaigns WHERE id = ?');
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch();

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

$config = require __DIR__ . '/../app/config/config.php';
try {
    $client = SaleshandyClient::fromConfig($config);
    $stats = $client->pullNewProspects(db(), $campaign, $admin['id']);
    $message = "Imported from Saleshandy: {$stats['leads_created']} new lead(s), {$stats['assignments_created']} new assignment(s) "
        . "({$stats['already_present']} were already here). Saleshandy returned activity for {$stats['distinct_prospects_found']} "
        . 'distinct prospect(s) in this sequence.';
    if ($stats['distinct_prospects_found'] > 0) {
        $message .= ' If that number is lower than what Saleshandy shows as this sequence\'s prospect count, the difference is '
            . "almost always prospects who haven't been sent an email yet (still queued/not contacted) -- Saleshandy's API only "
            . 'exposes prospects with at least one send/activity event, not the full enrolled list.';
    }
    flash_set('success', $message);
} catch (SaleshandyApiException $ex) {
    flash_set('danger', 'Could not import from Saleshandy: ' . $ex->getMessage());
}

header('Location: ' . $redirect);
exit;
