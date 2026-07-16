<?php
require_once __DIR__ . '/bootstrap.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

csrf_verify();

$leadId = (int) ($_POST['lead_id'] ?? 0);
$returnTo = $_POST['return_to'] ?? 'dashboard.php';
$values = $_POST['values'] ?? [];

if ($leadId <= 0 || !is_array($values)) {
    flash_set('danger', 'Invalid update request.');
    header('Location: ' . $returnTo);
    exit;
}

$fields = db()->query('SELECT id, field_key FROM custom_fields WHERE is_active = 1')->fetchAll();
$fieldIds = array_column($fields, 'id', 'field_key');

$upsert = db()->prepare(
    'INSERT INTO lead_custom_values (lead_id, custom_field_id, value) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE value = VALUES(value)'
);
$delete = db()->prepare('DELETE FROM lead_custom_values WHERE lead_id = ? AND custom_field_id = ?');

foreach ($values as $key => $value) {
    if (!isset($fieldIds[$key])) {
        continue; // unknown/inactive field, ignore
    }
    $value = trim((string) $value);
    if ($value === '') {
        $delete->execute([$leadId, $fieldIds[$key]]);
    } else {
        $upsert->execute([$leadId, $fieldIds[$key], $value]);
    }
}

flash_set('success', 'Custom fields updated.');
header('Location: ' . $returnTo);
exit;
