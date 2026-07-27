<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/EmployeeCountRangeClassifier.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: lists.php');
    exit;
}

csrf_verify();

$db = db();

$checked = 0;
$changed = 0;

$updateStmt = $db->prepare('UPDATE leads SET employee_count_range = ? WHERE id = ?');
$stmt = $db->query("SELECT id, employee_count, employee_count_range FROM leads WHERE deleted_at IS NULL AND employee_count IS NOT NULL AND employee_count != ''");

while ($lead = $stmt->fetch()) {
    $checked++;
    $newRange = EmployeeCountRangeClassifier::classify($lead['employee_count']);
    if ($newRange !== $lead['employee_count_range']) {
        $updateStmt->execute([$newRange, $lead['id']]);
        $changed++;
    }
}

flash_set('success', "{$checked} lead(s) checked, {$changed} employee count range(s) updated.");

header('Location: lists.php');
exit;
