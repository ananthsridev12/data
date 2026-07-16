<?php
require_once __DIR__ . '/bootstrap.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

csrf_verify();

$leadId = (int) ($_POST['lead_id'] ?? 0);
$field = $_POST['field'] ?? '';
$valueId = $_POST['value_id'] !== '' ? (int) $_POST['value_id'] : null;

$columns = ['vertical' => 'vertical_id', 'service' => 'service_id'];
$tables = ['vertical' => 'verticals', 'service' => 'services'];

if ($leadId <= 0 || !isset($columns[$field])) {
    flash_set('danger', 'Invalid update request.');
    header('Location: dashboard.php');
    exit;
}

if ($valueId !== null) {
    $check = db()->prepare("SELECT id FROM {$tables[$field]} WHERE id = ? AND is_active = 1");
    $check->execute([$valueId]);
    if (!$check->fetch()) {
        flash_set('danger', 'That value is not a valid, active list entry.');
        header('Location: ' . ($_POST['return_to'] ?? 'dashboard.php'));
        exit;
    }
}

$column = $columns[$field];
db()->prepare("UPDATE leads SET {$column} = ? WHERE id = ?")->execute([$valueId, $leadId]);

header('Location: ' . ($_POST['return_to'] ?? 'dashboard.php'));
exit;
