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

if ($leadId <= 0) {
    flash_set('danger', 'Invalid update request.');
    header('Location: ' . $returnTo);
    exit;
}

$verticalId = ($_POST['vertical_id'] ?? '') !== '' ? (int) $_POST['vertical_id'] : null;
$serviceId = ($_POST['service_id'] ?? '') !== '' ? (int) $_POST['service_id'] : null;
$roleGroupId = ($_POST['role_group_id'] ?? '') !== '' ? (int) $_POST['role_group_id'] : null;
$countryGroupId = ($_POST['country_group_id'] ?? '') !== '' ? (int) $_POST['country_group_id'] : null;

foreach (['vertical_id' => ['verticals', $verticalId], 'service_id' => ['services', $serviceId], 'role_group_id' => ['role_groups', $roleGroupId], 'country_group_id' => ['country_groups', $countryGroupId]] as [$table, $valueId]) {
    if ($valueId === null) {
        continue;
    }
    $check = db()->prepare("SELECT id FROM {$table} WHERE id = ? AND is_active = 1");
    $check->execute([$valueId]);
    if (!$check->fetch()) {
        flash_set('danger', 'One of the selected values is not a valid, active list entry.');
        header('Location: ' . $returnTo);
        exit;
    }
}

db()->prepare('UPDATE leads SET vertical_id = ?, service_id = ?, role_group_id = ?, country_group_id = ? WHERE id = ?')
    ->execute([$verticalId, $serviceId, $roleGroupId, $countryGroupId, $leadId]);

flash_set('success', 'Lead updated.');
header('Location: ' . $returnTo);
exit;
