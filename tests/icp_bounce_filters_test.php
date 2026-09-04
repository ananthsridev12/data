<?php
// IcpRepository::toFilters()'s bounce_status_filter/bounce_type_filter/
// delivery_status_filter (icp_segments columns, sql/049) -- optional ICP
// match criteria mapped onto LeadRepository::buildWhere()'s
// 'bounce_status'/'bounce_type'/'delivery_status' filters (see
// lead_bounce_filters_test.php for the underlying matching behavior).
// Covers persistence (create()/update()) and the toFilters() mapping
// itself; not the SQL matching logic, which lead_bounce_filters_test.php
// already covers directly. Rolled back at the end.
//
// Usage: php tests/icp_bounce_filters_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';

$failures = [];
$assert = static function (bool $cond, string $label) use (&$failures): void {
    echo ($cond ? "PASS" : "FAIL") . " -- {$label}\n";
    if (!$cond) {
        $failures[] = $label;
    }
};

$db = db();
$db->beginTransaction();

try {
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Icp Bounce Filter Co', 0)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'admin@icpbouncefilter.test', 'admin@icpbouncefilter.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();
    $scope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);

    // --- create() persists all three, and a bounce filter alone counts
    // as a valid match criterion (this page-level "hasAnyCriterion" logic
    // lives in icp_segments.php/icp_segment_detail.php, not here, but
    // create() itself has no such gate -- just confirming the columns
    // round-trip).
    $icpId = IcpRepository::create($db, [
        'name' => 'Bounce Filter ICP', 'vertical_id' => null, 'role_group_id' => null, 'service_id' => null,
        'country_group_id' => null, 'company_country' => '', 'industry' => '', 'seniority' => '', 'employee_count' => '',
        'bounce_status_filter' => 'delivered', 'bounce_type_filter' => 'Hard Bounce', 'delivery_status_filter' => 'Active, Replied',
    ], $adminId, $companyId);

    $row = $db->query("SELECT bounce_status_filter, bounce_type_filter, delivery_status_filter FROM icp_segments WHERE id = {$icpId}")->fetch();
    $assert($row['bounce_status_filter'] === 'delivered', "create() persists bounce_status_filter (got '{$row['bounce_status_filter']}')");
    $assert($row['bounce_type_filter'] === 'Hard Bounce', "create() persists bounce_type_filter (got '{$row['bounce_type_filter']}')");
    $assert($row['delivery_status_filter'] === 'Active, Replied', "create() persists delivery_status_filter (got '{$row['delivery_status_filter']}')");

    // --- toFilters() maps them onto LeadRepository's filter shape.
    $icpRow = IcpRepository::findVisible($db, $scope, $icpId);
    $filters = IcpRepository::toFilters($icpRow, $scope);
    $assert($filters['bounce_status'] === 'delivered', "toFilters() maps bounce_status_filter -> bounce_status (got '{$filters['bounce_status']}')");
    $assert($filters['bounce_type'] === 'Hard Bounce', "toFilters() maps bounce_type_filter -> bounce_type (got '{$filters['bounce_type']}')");
    $assert($filters['delivery_status'] === ['Active', 'Replied'], 'toFilters() parses delivery_status_filter into an array, same convention as industry/seniority (got ' . json_encode($filters['delivery_status']) . ')');

    // --- update() clears them back to blank.
    IcpRepository::update($db, $icpId, [
        'name' => 'Bounce Filter ICP', 'vertical_id' => null, 'role_group_id' => null, 'service_id' => null,
        'country_group_id' => null, 'company_country' => '', 'industry' => '', 'seniority' => '', 'employee_count' => '',
        'bounce_status_filter' => '', 'bounce_type_filter' => '', 'delivery_status_filter' => '',
    ], $scope);
    $rowAfter = $db->query("SELECT bounce_status_filter, bounce_type_filter, delivery_status_filter FROM icp_segments WHERE id = {$icpId}")->fetch();
    $assert($rowAfter['bounce_status_filter'] === null, 'update() clears bounce_status_filter back to NULL');
    $assert($rowAfter['bounce_type_filter'] === null, 'update() clears bounce_type_filter back to NULL');
    $assert($rowAfter['delivery_status_filter'] === null, 'update() clears delivery_status_filter back to NULL');

    $icpRowCleared = IcpRepository::findVisible($db, $scope, $icpId);
    $filtersCleared = IcpRepository::toFilters($icpRowCleared, $scope);
    $assert($filtersCleared['bounce_status'] === '', "toFilters() maps a cleared bounce_status_filter to '' (got '{$filtersCleared['bounce_status']}')");
    $assert($filtersCleared['bounce_type'] === '', "toFilters() maps a cleared bounce_type_filter to '' (got '{$filtersCleared['bounce_type']}')");
    $assert($filtersCleared['delivery_status'] === [], 'toFilters() maps a cleared delivery_status_filter to an empty array');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll ICP bounce-filter persistence/mapping checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
