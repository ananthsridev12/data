<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

csrf_verify();

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$isWave = isset($_POST['wave_mode']);
$mode = $isWave ? $_POST['wave_mode'] : ($_POST['mode'] ?? 'checked');

$campStmt = db()->prepare('SELECT id, name FROM campaigns WHERE id = ? AND is_active = 1');
$campStmt->execute([$campaignId]);
$campaign = $campStmt->fetch();

if (!$campaign) {
    flash_set('danger', 'Please choose a valid campaign.');
    header('Location: dashboard.php');
    exit;
}

if ($mode === 'filter') {
    $rawFilters = $_POST['filter'] ?? [];
    $filters = [
        'q' => $rawFilters['q'] ?? '',
        'company' => $rawFilters['company'] ?? '',
        'domain' => $rawFilters['domain'] ?? '',
        'title' => $rawFilters['title'] ?? '',
        'seniority' => $rawFilters['seniority'] ?? '',
        'departments' => $rawFilters['departments'] ?? '',
        'industry' => $rawFilters['industry'] ?? '',
        'country' => $rawFilters['country'] ?? '',
        'employee_count' => $rawFilters['employee_count'] ?? '',
        'vertical_id' => $rawFilters['vertical_id'] ?? '',
        'service_id' => $rawFilters['service_id'] ?? '',
        'campaign_id' => $rawFilters['campaign_id'] ?? '',
        'hide_used_in_campaign' => !empty($rawFilters['hide_used_in_campaign']),
    ];
    $leadIds = LeadRepository::matchingIds(db(), $filters);
} else {
    $leadIds = array_map('intval', $_POST['lead_ids'] ?? []);
}

if (!$leadIds) {
    flash_set('danger', 'No leads were selected.');
    header('Location: dashboard.php');
    exit;
}

if ($isWave) {
    $titlePriority = array_filter(array_map('trim', explode(',', (string) ($_POST['title_priority'] ?? ''))));
    $stats = WaveAssigner::assign(db(), $leadIds, $campaignId, $user['id'], $titlePriority);

    $message = "{$stats['leaders']} wave-1 contact(s) assigned across {$stats['domains']} companies (1 per domain), "
        . "{$stats['held']} held pending that outcome.";
    if ($stats['suppressed_skipped'] > 0) {
        $message .= " {$stats['suppressed_skipped']} skipped (suppressed domain).";
    }
    if ($stats['already_in_campaign'] > 0) {
        $message .= " {$stats['already_in_campaign']} were already in this campaign.";
    }
    flash_set('success', $message);
    header('Location: dashboard.php');
    exit;
}

$filtered = WaveAssigner::filterSuppressed(db(), $leadIds);
$leadIds = $filtered['eligible'];

if (!$leadIds) {
    flash_set('danger', 'All selected leads are on suppressed domains.');
    header('Location: dashboard.php');
    exit;
}

$insert = db()->prepare(
    'INSERT IGNORE INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by) VALUES (?, ?, ?)'
);

$assigned = 0;
$alreadyAssigned = 0;

db()->beginTransaction();
foreach ($leadIds as $leadId) {
    $insert->execute([$leadId, $campaignId, $user['id']]);
    if ($insert->rowCount() === 1) {
        $assigned++;
    } else {
        $alreadyAssigned++;
    }
}
db()->commit();

$message = "{$assigned} lead(s) assigned to \"{$campaign['name']}\".";
if ($alreadyAssigned > 0) {
    $message .= " {$alreadyAssigned} were already assigned to this campaign and were skipped.";
}
if ($filtered['suppressed_count'] > 0) {
    $message .= " {$filtered['suppressed_count']} were skipped (suppressed domain).";
}
flash_set('success', $message);
header('Location: dashboard.php');
exit;
