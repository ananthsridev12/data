<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: campaigns.php');
    exit;
}

csrf_verify();

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$redirect = 'campaign_leads.php?campaign_id=' . $campaignId;

$campStmt = db()->prepare('SELECT name FROM campaigns WHERE id = ?');
$campStmt->execute([$campaignId]);
$campaignName = $campStmt->fetchColumn();

if (!$campaignName) {
    flash_set('danger', 'Campaign not found.');
    header('Location: campaigns.php');
    exit;
}

$bounceType = $_POST['bounce_type'] ?? '';
if (!in_array($bounceType, WaveAssigner::BOUNCE_TYPES, true)) {
    $bounceType = null;
}

$rawEmails = (string) ($_POST['emails'] ?? '');
$candidates = preg_split('/[\s,;]+/', $rawEmails, -1, PREG_SPLIT_NO_EMPTY);

$processed = 0;
$invalid = 0;
$domains = [];
$cascaded = 0;

foreach ($candidates as $candidate) {
    $email = strtolower(trim($candidate));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $invalid++;
        continue;
    }
    $result = WaveAssigner::suppressByEmail(db(), $email, $user['id'], "Campaign bounce: {$campaignName}", $bounceType);
    $domains[$result['domain']] = true;
    $cascaded += $result['cascaded'];
    $processed++;
}

$released = WaveAssigner::releaseAllPendingInCampaign(db(), $campaignId, []);

if ($processed === 0 && $released['released_leaders'] === 0) {
    flash_set('danger', 'No valid email addresses were found to process.');
    header('Location: ' . $redirect);
    exit;
}

$message = "{$processed} bounced email(s) processed -- " . count($domains) . " domain(s) suppressed"
    . ($cascaded > 0 ? ", {$cascaded} held lead(s) cascade-suppressed" : '') . '. '
    . "{$released['released_leaders']} other pending compan(y/ies) released as the next batch ({$released['released_held']} lead(s)).";
if ($invalid > 0) {
    $message .= " {$invalid} entr(y/ies) were not valid email addresses and were skipped.";
}

flash_set('success', $message);
header('Location: ' . $redirect);
exit;
