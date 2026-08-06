<?php
// AnalyticsRepository::pivotByDimension('country_group', ...) -- the new
// "Country Group" row dimension, added alongside the existing Company
// Country/Campaign/Vertical/Service ones. Verifies grouping by
// country_groups.label (not raw company_country text), leads with no
// group falling into '(none)', and the existing service_id filter still
// applying correctly on top of the new dimension. Rolled back at the end.
//
// Usage: php tests/analytics_country_group_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
require_once __DIR__ . '/../app/includes/AnalyticsRepository.php';

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Co A', 90)");
    $companyId = (int) $db->lastInsertId();

    $stmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$companyId, 'admin@a.test', 'admin@a.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO country_groups (company_id, code, label, countries) VALUES (?, ?, ?, ?)')
        ->execute([$companyId, 'AMER', 'Americas', 'United States, Canada']);
    $groupAmericasId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO country_groups (company_id, code, label, countries) VALUES (?, ?, ?, ?)')
        ->execute([$companyId, 'EU', 'Europe', 'Germany, France']);
    $groupEuropeId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO services (company_id, code, label) VALUES (?, ?, ?)')->execute([$companyId, 'SEO', 'SEO']);
    $serviceSeoId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO services (company_id, code, label) VALUES (?, ?, ?)')->execute([$companyId, 'PPC', 'PPC']);
    $servicePpcId = (int) $db->lastInsertId();

    $mkLead = function (string $email, ?int $countryGroupId, ?int $serviceId) use ($db, $companyId): int {
        $stmt = $db->prepare(
            'INSERT INTO leads (company_id, na_company_name, category, products, first_name, last_name, title, company_name_for_emails, email, industry, person_linkedin_url, website, company_linkedin_url, company_country, country_group_id, service_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, 'X Co', 'Cat', 'Prod', 'First', 'Last', 'Title', 'X Co', $email, 'Industry', 'https://linkedin.test', 'https://x.test', 'https://linkedin.test/co', 'US', $countryGroupId, $serviceId]);
        return (int) $db->lastInsertId();
    };

    // Americas/SEO x2, Americas/PPC x1, Europe/SEO x1, no-group/SEO x1.
    $mkLead('a1@x.test', $groupAmericasId, $serviceSeoId);
    $mkLead('a2@x.test', $groupAmericasId, $serviceSeoId);
    $mkLead('a3@x.test', $groupAmericasId, $servicePpcId);
    $mkLead('e1@x.test', $groupEuropeId, $serviceSeoId);
    $mkLead('n1@x.test', null, $serviceSeoId);

    $scope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);

    // --- No filter: 3 groups (Americas=3, Europe=1, (none)=1).
    $unfiltered = AnalyticsRepository::pivotByDimension($db, $scope, 'country_group', []);
    $byGrp = array_column($unfiltered['rows'], null, 'grp');
    $assert(count($unfiltered['rows']) === 3, 'Country Group dimension produces 3 rows: Americas, Europe, (none)');
    $assert(($byGrp['Americas']['prospects'] ?? null) === '3' || (int) ($byGrp['Americas']['prospects'] ?? 0) === 3, 'Americas groups all 3 of its leads together (got ' . ($byGrp['Americas']['prospects'] ?? 'missing') . ')');
    $assert((int) ($byGrp['Europe']['prospects'] ?? 0) === 1, 'Europe has exactly 1 lead');
    $assert((int) ($byGrp['(none)']['prospects'] ?? 0) === 1, 'The lead with no country_group_id falls into (none), not silently dropped');
    $assert($unfiltered['total']['prospects'] === 5, "Grand total across all groups is 5 (got {$unfiltered['total']['prospects']})");

    // --- service_id filter ('Service as filter'): only SEO leads --
    // Americas drops from 3 to 2, PPC-only Americas lead disappears entirely.
    $filtered = AnalyticsRepository::pivotByDimension($db, $scope, 'country_group', ['service_id' => $serviceSeoId]);
    $byGrpFiltered = array_column($filtered['rows'], null, 'grp');
    $assert((int) ($byGrpFiltered['Americas']['prospects'] ?? 0) === 2, "service_id filter narrows Americas from 3 to 2 (SEO leads only) (got " . ($byGrpFiltered['Americas']['prospects'] ?? 'missing') . ')');
    $assert($filtered['total']['prospects'] === 4, "Filtered grand total is 4 (5 leads minus the 1 PPC lead) (got {$filtered['total']['prospects']})");

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll AnalyticsRepository Country Group dimension checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
