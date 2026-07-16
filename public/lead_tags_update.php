<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/TagRepository.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

csrf_verify();

$leadId = (int) ($_POST['lead_id'] ?? 0);
$returnTo = $_POST['return_to'] ?? 'dashboard.php';

if ($leadId <= 0) {
    flash_set('danger', 'Invalid update request.');
    header('Location: ' . $returnTo);
    exit;
}

$selectedIds = array_map('intval', $_POST['tag_ids'] ?? []);
$names = [];
if ($selectedIds) {
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $stmt = db()->prepare("SELECT name FROM tags WHERE id IN ({$placeholders})");
    $stmt->execute($selectedIds);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$newTags = array_filter(array_map('trim', explode(',', (string) ($_POST['new_tags'] ?? ''))));
$names = array_merge($names, $newTags);

TagRepository::setTagsForLead(db(), $leadId, $names);

flash_set('success', 'Tags updated.');
header('Location: ' . $returnTo);
exit;
