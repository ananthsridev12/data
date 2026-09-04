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
$groupsStmt = $db->prepare('SELECT id, keywords, match_departments, match_sub_departments FROM role_groups WHERE is_active = 1 AND company_id = ? ORDER BY id');
$groupsStmt->execute([$scope->companyId]);
$groups = $groupsStmt->fetchAll();

$checked = 0;
$changed = 0;
$nowUnclassified = 0;

$updateStmt = $db->prepare('UPDATE leads SET role_group_id = ? WHERE id = ?');
// Widened from "title IS NOT NULL AND title != ''" -- a lead with a
// blank title but a populated department/sub-department can now classify
// once a group opts into match_departments/match_sub_departments, so it
// must be reachable here too, not just leads with a title at all.
$stmt = $db->prepare(
    "SELECT id, title, departments, sub_departments, role_group_id FROM leads
      WHERE company_id = ? AND deleted_at IS NULL
        AND ((title IS NOT NULL AND title != '') OR (departments IS NOT NULL AND departments != '') OR (sub_departments IS NOT NULL AND sub_departments != ''))"
);
$stmt->execute([$scope->companyId]);

while ($lead = $stmt->fetch()) {
    $checked++;
    $newGroupId = RoleGroupClassifier::classify($lead['title'], $groups, $lead['departments'], $lead['sub_departments']);
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
