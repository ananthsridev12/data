<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/RoleGroupClassifier.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: role_groups.php');
    exit;
}

csrf_verify();

$db = db();

// Ordered id ASC -- matches RoleGroupClassifier's documented "caller
// controls ordering" contract; simplest stable order, first active group
// with a keyword hit wins.
$groupsStmt = $db->prepare('SELECT id, keywords FROM role_groups WHERE is_active = 1 AND company_id = ? ORDER BY id');
$groupsStmt->execute([$scope->companyId]);
$groups = $groupsStmt->fetchAll();

$checked = 0;
$changed = 0;
$nowUnclassified = 0;

$updateStmt = $db->prepare('UPDATE leads SET role_group_id = ? WHERE id = ?');
$stmt = $db->prepare("SELECT id, title, role_group_id FROM leads WHERE company_id = ? AND deleted_at IS NULL AND title IS NOT NULL AND title != ''");
$stmt->execute([$scope->companyId]);

while ($lead = $stmt->fetch()) {
    $checked++;
    $newGroupId = RoleGroupClassifier::classify($lead['title'], $groups);
    $oldGroupId = $lead['role_group_id'] !== null ? (int) $lead['role_group_id'] : null;

    if ($newGroupId !== $oldGroupId) {
        $updateStmt->execute([$newGroupId, $lead['id']]);
        $changed++;
        if ($newGroupId === null) {
            $nowUnclassified++;
        }
    }
}

$message = "{$checked} lead(s) checked, {$changed} role group assignment(s) changed.";
if ($nowUnclassified > 0) {
    $message .= " {$nowUnclassified} no longer match any active group's keywords.";
}
flash_set('success', $message);

header('Location: role_groups.php');
exit;
