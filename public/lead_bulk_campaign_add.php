<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

csrf_verify();

$returnTo = (string) ($_POST['return_to'] ?? 'dashboard.php');
$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$leadIds = array_values(array_unique(array_map('intval', (array) ($_POST['lead_ids'] ?? []))));

if (!$campaignId) {
    flash_set('danger', 'Pick a campaign to add the checked leads to.');
    header('Location: ' . $returnTo);
    exit;
}

if (!$leadIds) {
    flash_set('danger', 'No leads were checked.');
    header('Location: ' . $returnTo);
    exit;
}

$campStmt = db()->prepare('SELECT id, name FROM campaigns WHERE id = ?');
$campStmt->execute([$campaignId]);
$campaign = $campStmt->fetch();

if (!$campaign) {
    flash_set('danger', 'Campaign not found.');
    header('Location: ' . $returnTo);
    exit;
}

// Same wave-1 domain-safety gate as the main Add Leads to Campaign flow
// (WaveAssigner::assign()) -- if two checked leads share a company, one
// becomes the wave-1 leader and the other is held pending that outcome.
// No title-priority list here (this is a hand-picked selection, not a
// filtered bulk pick), so the leader falls back to seniority ranking.
$stats = WaveAssigner::assign(db(), $leadIds, $campaignId, $user['id'], []);

$message = "{$stats['leaders']} lead(s) added to \"{$campaign['name']}\" across {$stats['domains']} compan(y/ies), "
    . "{$stats['held']} held pending a wave-1 outcome.";
if ($stats['suppressed_skipped'] > 0) {
    $message .= " {$stats['suppressed_skipped']} skipped (suppressed domain).";
}
if ($stats['already_elsewhere_skipped'] > 0) {
    $message .= " {$stats['already_elsewhere_skipped']} skipped (already assigned to a different campaign -- a lead can only belong to one).";
}
if ($stats['pending_elsewhere_skipped'] > 0) {
    $parts = [];
    foreach ($stats['pending_elsewhere_campaigns'] as $campaignName => $count) {
        $parts[] = "{$count} in \"{$campaignName}\"";
    }
    $message .= " {$stats['pending_elsewhere_skipped']} skipped (their account already has a persona pending delivery elsewhere: " . implode(', ', $parts) . ').';
}
if ($stats['already_in_campaign'] > 0) {
    $message .= " {$stats['already_in_campaign']} were already in this campaign.";
}

flash_set('success', $message);
header('Location: ' . $returnTo);
exit;
