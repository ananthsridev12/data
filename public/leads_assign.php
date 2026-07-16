<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

csrf_verify();

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$mode = $_POST['mode'] ?? 'checked';

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
        'title' => $rawFilters['title'] ?? '',
        'seniority' => $rawFilters['seniority'] ?? '',
        'departments' => $rawFilters['departments'] ?? '',
        'industry' => $rawFilters['industry'] ?? '',
        'country' => $rawFilters['country'] ?? '',
        'employee_count' => $rawFilters['employee_count'] ?? '',
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
flash_set('success', $message);
header('Location: dashboard.php');
exit;
