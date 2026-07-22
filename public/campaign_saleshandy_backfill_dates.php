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
    $stats = $client->backfillHistoricalDates(db(), $campaign, $admin['id']);
    $message = "Checked {$stats['checked']} lead(s) against Saleshandy's full history: "
        . "{$stats['email_date_fixed']} Email Date(s) fixed, {$stats['pushed_at_fixed']} First Pushed date(s) backfilled, "
        . "{$stats['delivery_status_fixed']} delivery status(es) corrected ({$stats['bounced']} bounced, {$stats['replied']} replied).";
    if ($stats['released'] > 0) {
        $message .= " {$stats['released']} held lead(s) released -- their wave-1 leader's delivery was confirmed by this backfill.";
    }
    flash_set('success', $message);
} catch (SaleshandyApiException $ex) {
    flash_set('danger', 'Could not backfill from Saleshandy: ' . $ex->getMessage());
}

header('Location: ' . $redirect);
exit;
