<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/CountryGroupClassifier.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: country_groups.php');
    exit;
}

csrf_verify();

$db = db();

$groupsStmt = $db->prepare('SELECT id, countries FROM country_groups WHERE is_active = 1 AND company_id = ? ORDER BY id');
$groupsStmt->execute([$scope->companyId]);
$groups = $groupsStmt->fetchAll();

$checked = 0;
$changed = 0;
$nowUnmapped = 0;

$updateStmt = $db->prepare('UPDATE leads SET country_group_id = ? WHERE id = ?');
$stmt = $db->prepare("SELECT id, company_country, country_group_id FROM leads WHERE company_id = ? AND deleted_at IS NULL AND company_country IS NOT NULL AND company_country != ''");
$stmt->execute([$scope->companyId]);

while ($lead = $stmt->fetch()) {
    $checked++;
    $newGroupId = CountryGroupClassifier::classify($lead['company_country'], $groups);
    $oldGroupId = $lead['country_group_id'] !== null ? (int) $lead['country_group_id'] : null;

    if ($newGroupId !== $oldGroupId) {
        $updateStmt->execute([$newGroupId, $lead['id']]);
        $changed++;
        if ($newGroupId === null) {
            $nowUnmapped++;
        }
    }
}

$message = "{$checked} lead(s) checked, {$changed} country group assignment(s) changed.";
if ($nowUnmapped > 0) {
    $message .= " {$nowUnmapped} no longer match any active group's country list.";
}
flash_set('success', $message);

header('Location: country_groups.php');
exit;
