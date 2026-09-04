<?php
// RoleGroupClassifier::classify()'s opt-in match_departments/
// match_sub_departments (role_groups columns, sql/050) -- when a group
// has one of these on, its SAME keyword list is ALSO checked against a
// lead's departments/sub_departments value, not just title. Off by
// default (checked here too), so a keyword hitting a department value
// doesn't classify a lead unless that specific group opted in. No
// database needed -- classify() is a pure function over its arguments.
//
// Usage: php tests/role_group_department_matching_test.php

require_once __DIR__ . '/../app/includes/RoleGroupClassifier.php';

$failures = [];
$assert = static function (bool $cond, string $label) use (&$failures): void {
    echo ($cond ? "PASS" : "FAIL") . " -- {$label}\n";
    if (!$cond) {
        $failures[] = $label;
    }
};

// --- Group with match_departments OFF (default): a department-only hit
// must NOT classify, even though the keyword is present in the group.
$groupsDeptOff = [
    ['id' => 1, 'keywords' => 'Engineering', 'match_departments' => 0, 'match_sub_departments' => 0],
];
$resultDeptOff = RoleGroupClassifier::classify('Account Executive', $groupsDeptOff, 'Engineering & Technical', null);
$assert($resultDeptOff === null, 'match_departments OFF: a title that doesn\'t match, plus a department that WOULD match, does not classify (got ' . var_export($resultDeptOff, true) . ')');

// --- Same group, match_departments ON: now the department hit classifies.
$groupsDeptOn = [
    ['id' => 1, 'keywords' => 'Engineering', 'match_departments' => 1, 'match_sub_departments' => 0],
];
$resultDeptOn = RoleGroupClassifier::classify('Account Executive', $groupsDeptOn, 'Engineering & Technical', null);
$assert($resultDeptOn === 1, 'match_departments ON: department value containing the keyword classifies into that group (got ' . var_export($resultDeptOn, true) . ')');

// --- match_sub_departments follows the same pattern independently.
$groupsSubDeptOff = [
    ['id' => 2, 'keywords' => 'Backend', 'match_departments' => 0, 'match_sub_departments' => 0],
];
$resultSubDeptOff = RoleGroupClassifier::classify('Account Executive', $groupsSubDeptOff, null, 'Backend Development');
$assert($resultSubDeptOff === null, 'match_sub_departments OFF: a sub-department hit does not classify (got ' . var_export($resultSubDeptOff, true) . ')');

$groupsSubDeptOn = [
    ['id' => 2, 'keywords' => 'Backend', 'match_departments' => 0, 'match_sub_departments' => 1],
];
$resultSubDeptOn = RoleGroupClassifier::classify('Account Executive', $groupsSubDeptOn, null, 'Backend Development');
$assert($resultSubDeptOn === 2, 'match_sub_departments ON: sub-department value containing the keyword classifies into that group (got ' . var_export($resultSubDeptOn, true) . ')');

// --- Ordering: group order still governs, whether the winning hit comes
// from title or department -- an EARLIER group with a department-only
// hit wins over a LATER group with an exact title hit.
$groupsOrdering = [
    ['id' => 10, 'keywords' => 'Sales', 'match_departments' => 1, 'match_sub_departments' => 0],
    ['id' => 20, 'keywords' => 'Marketing', 'match_departments' => 0, 'match_sub_departments' => 0],
];
$resultOrdering = RoleGroupClassifier::classify('Marketing Manager', $groupsOrdering, 'Sales & Business Development', null);
$assert($resultOrdering === 10, 'The earlier group (id 10) wins via its department match, even though the later group (id 20) has an exact title match (got ' . var_export($resultOrdering, true) . ')');

// Reverse fixture: when the department doesn't match anything, the
// later group's title match still wins (department check is a no-op,
// not a blocker).
$resultOrderingReverse = RoleGroupClassifier::classify('Marketing Manager', $groupsOrdering, 'Finance', null);
$assert($resultOrderingReverse === 20, 'With no department hit, the later group\'s title match still wins normally (got ' . var_export($resultOrderingReverse, true) . ')');

// --- Blank title: only matches when department/sub-department matching
// is on AND its value hits -- confirms the early-exit guard in
// classify() was widened correctly, not just the per-group loop.
$groupsBlankTitle = [
    ['id' => 30, 'keywords' => 'Engineering', 'match_departments' => 1, 'match_sub_departments' => 0],
];
$resultBlankTitleMatches = RoleGroupClassifier::classify('', $groupsBlankTitle, 'Engineering & Technical', null);
$assert($resultBlankTitleMatches === 30, 'A blank title still classifies via a matching department when that group has match_departments on (got ' . var_export($resultBlankTitleMatches, true) . ')');

$resultBlankTitleNull = RoleGroupClassifier::classify(null, $groupsBlankTitle, null, null);
$assert($resultBlankTitleNull === null, 'A lead with no title, no department, and no sub-department at all still classifies to null (got ' . var_export($resultBlankTitleNull, true) . ')');

// --- Backward compatibility: calling with only the original two args
// (no departments/subDepartments) behaves exactly as before.
$groupsLegacyCall = [
    ['id' => 40, 'keywords' => 'Engineering'],
];
$resultLegacyCall = RoleGroupClassifier::classify('VP of Engineering', $groupsLegacyCall);
$assert($resultLegacyCall === 40, 'Calling classify() with only title+groups (no new args) still works exactly as before (got ' . var_export($resultLegacyCall, true) . ')');

if ($failures) {
    echo "\n" . count($failures) . " FAILURE(S):\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
} else {
    echo "\nAll RoleGroupClassifier department-matching checks passed.\n";
}

exit($failures ? 1 : 0);
