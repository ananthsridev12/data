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
$leaderAssignmentId = (int) ($_POST['leader_assignment_id'] ?? 0);
$action = $_POST['action'] ?? '';
$redirect = 'campaign_leads.php?campaign_id=' . $campaignId;

if (!$campaignId || !$leaderAssignmentId) {
    flash_set('danger', 'Invalid request.');
    header('Location: ' . $redirect);
    exit;
}

// Confirm the leader assignment actually belongs to this campaign before acting on it.
$check = db()->prepare('SELECT id FROM lead_campaign_assignments WHERE id = ? AND campaign_id = ?');
$check->execute([$leaderAssignmentId, $campaignId]);
if (!$check->fetch()) {
    flash_set('danger', 'That wave-1 contact was not found in this campaign.');
    header('Location: ' . $redirect);
    exit;
}

if ($action === 'release') {
    $count = WaveAssigner::release(db(), $leaderAssignmentId);
    flash_set('success', "{$count} held lead(s) released and are now active in this campaign.");
} elseif ($action === 'suppress') {
    $bounceType = $_POST['bounce_type'] ?? '';
    if (!in_array($bounceType, WaveAssigner::BOUNCE_TYPES, true)) {
        $bounceType = null;
    }
    $result = WaveAssigner::suppress(db(), $leaderAssignmentId, $user['id'], 'Wave-1 bounce', $bounceType);
    $message = "{$result['held_suppressed']} held lead(s) suppressed.";
    $message .= $result['domain_suppressed']
        ? ' Their domain has been added to the global suppression list.'
        : ' This bounce type is configured not to suppress the whole domain (see Bounce Settings), so other personas there remain assignable.';
    flash_set('success', $message);
} else {
    flash_set('danger', 'Unknown action.');
}

header('Location: ' . $redirect);
exit;
