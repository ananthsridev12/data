<?php
// Tenant-isolation check for the multi-tenant retrofit (see
// sql/032-034_multi_tenant_*.sql and app/includes/Scope.php /
// ScopeFilter.php). Seeds two companies with overlapping-looking lead
// data (same email, different company_id -- legal under the new
// composite uq_leads_company_email key) inside a transaction, runs
// every LeadRepository read method scoped to company A, and asserts
// zero company-B rows are ever visible. Rolled back at the end -- run
// this against a real dev DB (config.php), never production.
//
// Usage: php tests/tenant_isolation_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

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
    // --- Seed two companies, each with their own admin and their own
    // vertical/role_group, plus leads sharing the same email address
    // across companies (only legal now that uq_leads_email became
    // uq_leads_company_email).
    // MySQL's LAST_INSERT_ID() after a multi-row INSERT returns the id
    // of the *first* generated row, not the last -- companyBId is
    // derived by offset, not a second lastInsertId() call (which would
    // just return the same cached value again).
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Tenant A', 30), ('Tenant B', 60)");
    $companyAId = (int) $db->lastInsertId();
    $companyBId = $companyAId + 1;

    $db->prepare("INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, 'Admin A', 'admin-a@tenant-a.test', 'x', 'admin')")
        ->execute([$companyAId]);
    $userAId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, 'Admin B', 'admin-b@tenant-b.test', 'x', 'admin')")
        ->execute([$companyBId]);
    $userBId = (int) $db->lastInsertId();

    $scopeA = Scope::fromUser($db, ['id' => $userAId, 'company_id' => $companyAId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $scopeB = Scope::fromUser($db, ['id' => $userBId, 'company_id' => $companyBId, 'role' => ROLE_ADMIN, 'team_id' => null]);

    $db->prepare("INSERT INTO verticals (company_id, code, label) VALUES (?, 'SAAS', 'SaaS')")->execute([$companyAId]);
    $verticalAId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO verticals (company_id, code, label) VALUES (?, 'SAAS', 'SaaS')")->execute([$companyBId]);
    $verticalBId = (int) $db->lastInsertId();

    // Same email, same title, same everything except which company they
    // belong to -- the point of this test is that identical-looking data
    // in two tenants never mixes.
    $db->prepare(
        "INSERT INTO leads (company_id, na_company_name, first_name, last_name, email, title, industry, vertical_id)
         VALUES (?, 'Shared Corp', 'Overlap', 'Person', 'overlap@shared-corp.test', 'VP Sales', 'Widgets', ?)"
    )->execute([$companyAId, $verticalAId]);
    $leadAId = (int) $db->lastInsertId();

    $db->prepare(
        "INSERT INTO leads (company_id, na_company_name, first_name, last_name, email, title, industry, vertical_id)
         VALUES (?, 'Shared Corp', 'Overlap', 'Person', 'overlap@shared-corp.test', 'VP Sales', 'Widgets', ?)"
    )->execute([$companyBId, $verticalBId]);
    $leadBId = (int) $db->lastInsertId();

    // A second, non-overlapping lead in company B only, so "company A
    // sees nothing from B" isn't trivially true just because B is empty.
    $db->prepare(
        "INSERT INTO leads (company_id, na_company_name, first_name, last_name, email, title)
         VALUES (?, 'Only In B', 'Solo', 'Person', 'solo@only-in-b.test', 'CTO')"
    )->execute([$companyBId]);
    $leadBOnlyId = (int) $db->lastInsertId();

    // --- search(): company A's unfiltered-by-anything-but-scope search
    // must contain leadAId, never leadBId or leadBOnlyId.
    $resultA = LeadRepository::search($db, $scopeA, ['company' => 'Shared Corp'], 1);
    $idsA = array_column($resultA['rows'], 'id');
    $assert(in_array($leadAId, $idsA, true), 'search(): company A sees its own overlapping lead');
    $assert(!in_array($leadBId, $idsA, true), 'search(): company A does NOT see company B\'s overlapping lead');

    $resultB = LeadRepository::search($db, $scopeB, ['company' => 'Shared Corp'], 1);
    $idsB = array_column($resultB['rows'], 'id');
    $assert(in_array($leadBId, $idsB, true), 'search(): company B sees its own overlapping lead');
    $assert(!in_array($leadAId, $idsB, true), 'search(): company B does NOT see company A\'s overlapping lead');

    // --- matchingIds() / matchingCount(): broad, filter-free query --
    // must never leak the other company's rows even with zero filters.
    $allIdsA = LeadRepository::matchingIds($db, $scopeA, []);
    $assert(in_array($leadAId, $allIdsA, true), 'matchingIds(): company A includes its own lead');
    $assert(!in_array($leadBId, $allIdsA, true), 'matchingIds(): company A excludes company B\'s overlapping lead');
    $assert(!in_array($leadBOnlyId, $allIdsA, true), 'matchingIds(): company A excludes company B\'s solo lead');

    $countB = LeadRepository::matchingCount($db, $scopeB, []);
    $assert($countB === 2, 'matchingCount(): company B sees exactly its own 2 leads (' . $countB . ' seen)');

    // --- findByIds(): must refuse to return a row across the company
    // boundary even when the exact id is supplied directly.
    $foundCrossTenant = LeadRepository::findByIds($db, $scopeA, [$leadBId, $leadBOnlyId]);
    $assert($foundCrossTenant === [], 'findByIds(): company A cannot fetch company B\'s lead ids by guessing them');

    $foundOwn = LeadRepository::findByIds($db, $scopeA, [$leadAId]);
    $assert(count($foundOwn) === 1 && (int) $foundOwn[0]['id'] === $leadAId, 'findByIds(): company A can fetch its own lead id');

    // --- distinctValues(): dropdown option lists must not surface the
    // other tenant's data either.
    $titlesA = LeadRepository::distinctValues($db, $scopeA, 'title');
    $titlesB = LeadRepository::distinctValues($db, $scopeB, 'title');
    $assert(in_array('VP Sales', $titlesA, true), 'distinctValues(): company A sees its own title');
    $assert(in_array('VP Sales', $titlesB, true) && in_array('CTO', $titlesB, true), 'distinctValues(): company B sees both its titles');
    // Both tenants happen to share the string "VP Sales" as a value --
    // that's expected (it's not tenant-identifying on its own); the real
    // assertion is company A never sees "CTO", which only exists in B.
    $assert(!in_array('CTO', $titlesA, true), 'distinctValues(): company A does not see a title that only exists in company B');

    // --- activeLookupOptions(): verticals list must be per-company even
    // though both companies used the identical code 'SAAS'.
    $verticalsA = LeadRepository::activeLookupOptions($db, $scopeA, 'verticals');
    $verticalIdsA = array_column($verticalsA, 'id');
    $assert(in_array($verticalAId, $verticalIdsA, true), 'activeLookupOptions(): company A sees its own vertical');
    $assert(!in_array($verticalBId, $verticalIdsA, true), 'activeLookupOptions(): company A does not see company B\'s same-code vertical');

    // --- domainCountForFilter(): distinct-domain count must be scoped too.
    $domainCountA = LeadRepository::domainCountForFilter($db, $scopeA, []);
    $assert($domainCountA === 1, 'domainCountForFilter(): company A counts only its own 1 domain (' . $domainCountA . ' seen)');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll tenant-isolation checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
