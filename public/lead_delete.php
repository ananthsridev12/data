<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

csrf_verify();

$action = $_POST['action'] ?? '';

if ($action === 'delete') {
    $leadId = (int) ($_POST['lead_id'] ?? 0);
    db()->prepare('UPDATE leads SET deleted_at = NOW(), deleted_by = ? WHERE id = ?')->execute([$admin['id'], $leadId]);
    flash_set('success', 'Lead deleted (hidden -- its campaign history is kept, and this can be undone from Deleted Leads).');
    header('Location: dashboard.php');
    exit;
}

if ($action === 'restore') {
    $leadId = (int) ($_POST['lead_id'] ?? 0);
    db()->prepare('UPDATE leads SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')->execute([$leadId]);
    flash_set('success', 'Lead restored.');
    header('Location: ' . ($_POST['return_to'] ?? 'dashboard.php'));
    exit;
}

if ($action === 'bulk_delete') {
    $rawFilters = $_POST['filter'] ?? [];
    $filters = [
        'q' => $rawFilters['q'] ?? '',
        'company' => $rawFilters['company'] ?? '',
        'domain' => $rawFilters['domain'] ?? '',
        'title' => (array) ($rawFilters['title'] ?? []),
        'seniority' => (array) ($rawFilters['seniority'] ?? []),
        'departments' => (array) ($rawFilters['departments'] ?? []),
        'industry' => (array) ($rawFilters['industry'] ?? []),
        'country' => (array) ($rawFilters['country'] ?? []),
        'employee_count_range' => (array) ($rawFilters['employee_count_range'] ?? []),
        'vertical_id' => $rawFilters['vertical_id'] ?? '',
        'service_id' => $rawFilters['service_id'] ?? '',
        'imported_by' => $rawFilters['imported_by'] ?? '',
        'campaign_id' => $rawFilters['campaign_id'] ?? '',
        'hide_used_in_campaign' => !empty($rawFilters['hide_used_in_campaign']),
        'show_suppressed' => !empty($rawFilters['show_suppressed']),
    ];
    $leadIds = LeadRepository::matchingIds(db(), $filters);

    if (!$leadIds) {
        flash_set('danger', 'No leads matched this filter.');
        header('Location: dashboard.php');
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
    $stmt = db()->prepare("UPDATE leads SET deleted_at = NOW(), deleted_by = ? WHERE id IN ({$placeholders})");
    $stmt->execute(array_merge([$admin['id']], $leadIds));

    flash_set('success', count($leadIds) . ' lead(s) deleted (hidden -- can be undone from Deleted Leads).');
    header('Location: dashboard.php');
    exit;
}

flash_set('danger', 'Unknown action.');
header('Location: dashboard.php');
exit;
