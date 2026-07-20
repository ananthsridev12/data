<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/TagRepository.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

csrf_verify();

$returnTo = (string) ($_POST['return_to'] ?? 'dashboard.php');
$tagName = trim((string) ($_POST['tag_name'] ?? ''));

if ($tagName === '') {
    flash_set('danger', 'Enter a tag name to apply.');
    header('Location: ' . $returnTo);
    exit;
}

// Superset of both callers' filter shapes (Dashboard and Add Leads to
// Campaign) -- reads whichever keys the posting form actually sent, same
// as lead_delete.php's bulk_delete action.
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
    'employee_count' => (array) ($rawFilters['employee_count'] ?? []),
    'vertical_id' => $rawFilters['vertical_id'] ?? '',
    'service_id' => $rawFilters['service_id'] ?? '',
    'imported_by' => $rawFilters['imported_by'] ?? '',
    'campaign_id' => $rawFilters['campaign_id'] ?? '',
    'hide_used_in_campaign' => !empty($rawFilters['hide_used_in_campaign']),
    'show_suppressed' => !empty($rawFilters['show_suppressed']),
    'pending_elsewhere' => $rawFilters['pending_elsewhere'] ?? '',
    'account_used_elsewhere' => $rawFilters['account_used_elsewhere'] ?? '',
];

$leadIds = LeadRepository::matchingIds(db(), $filters);

if (!$leadIds) {
    flash_set('danger', 'No leads matched this filter.');
    header('Location: ' . $returnTo);
    exit;
}

$tagged = TagRepository::addTagToLeadIds(db(), $leadIds, $tagName);
$alreadyTagged = count($leadIds) - $tagged;

$message = "\"{$tagName}\" added to {$tagged} lead(s) matching this filter.";
if ($alreadyTagged > 0) {
    $message .= " {$alreadyTagged} already had it.";
}
flash_set('success', $message);
header('Location: ' . $returnTo);
exit;
