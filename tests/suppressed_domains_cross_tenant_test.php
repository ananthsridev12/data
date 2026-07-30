<?php
// Regression test for a cross-tenant leak: suppressed_domains has a
// (company_id, domain) unique key -- two companies can independently
// suppress the same domain string -- but 7 read sites checked "is this
// domain suppressed" with no company_id filter at all, so company A
// suppressing a domain silently suppressed/hid company B's leads at
// that same domain too (AccountRepository, IcpRepository, LeadImporter,
// WaveAssigner, SaleshandyClient, LeadRepository x2, lead_view.php).
// Exercises the 3 sites reachable without a live Saleshandy call or a
// full HTTP request: AccountRepository, WaveAssigner, LeadRepository.
// Rolled back at the end.
//
// Usage: php tests/suppressed_domains_cross_tenant_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
require_once __DIR__ . '/../app/includes/AccountRepository.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Co A', 90), ('Co B', 90)");
    $companyAId = (int) $db->lastInsertId();
    $companyBId = $companyAId + 1;

    $mkUser = function (int $companyId, string $email) use ($db): int {
        $stmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $email, $email, 'x', ROLE_ADMIN]);
        return (int) $db->lastInsertId();
    };
    $userAId = $mkUser($companyAId, 'admin@a.test');
    $userBId = $mkUser($companyBId, 'admin@b.test');

    $mkLead = function (int $companyId, string $email) use ($db): int {
        $stmt = $db->prepare(
            'INSERT INTO leads (company_id, na_company_name, category, products, first_name, last_name, title, company_name_for_emails, email, industry, person_linkedin_url, website, company_linkedin_url, company_country)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, 'X Co', 'Cat', 'Prod', 'First', 'Last', 'Title', 'X Co', $email, 'Industry', 'https://linkedin.test', 'https://x.test', 'https://linkedin.test/co', 'US']);
        return (int) $db->lastInsertId();
    };
    // Same domain, two different companies -- the crux of the test.
    $leadAId = $mkLead($companyAId, 'contact@shared-vendor.test');
    $leadBId = $mkLead($companyBId, 'contact@shared-vendor.test');

    // Only company A suppresses this domain.
    $db->prepare('INSERT INTO suppressed_domains (company_id, domain, reason, suppressed_by) VALUES (?, ?, ?, ?)')
        ->execute([$companyAId, 'shared-vendor.test', 'Hard bounce', $userAId]);

    $scopeA = Scope::fromUser($db, ['id' => $userAId, 'company_id' => $companyAId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $scopeB = Scope::fromUser($db, ['id' => $userBId, 'company_id' => $companyBId, 'role' => ROLE_ADMIN, 'team_id' => null]);

    // --- AccountRepository::summary(): company A's own account shows
    // suppressed; company B's account at the SAME domain must not.
    $summaryA = AccountRepository::summary($db, $scopeA, 'shared-vendor.test');
    $summaryB = AccountRepository::summary($db, $scopeB, 'shared-vendor.test');
    $assert($summaryA !== null && $summaryA['suppressed_reason'] === 'Hard bounce', "Company A's own account correctly shows suppressed (got " . var_export($summaryA['suppressed_reason'] ?? null, true) . ')');
    $assert($summaryB !== null && $summaryB['suppressed_reason'] === null, "Company B's account at the same domain is NOT suppressed by company A's row (got " . var_export($summaryB['suppressed_reason'] ?? null, true) . ')');

    // --- AccountRepository::search(): same check via the list query.
    $searchB = AccountRepository::search($db, $scopeB, ['q' => 'shared-vendor']);
    $assert(count($searchB['rows']) === 1 && $searchB['rows'][0]['suppressed_reason'] === null, "AccountRepository::search() also shows company B's account as unsuppressed");

    // --- WaveAssigner::filterEligibleForCampaign(): company B's lead at
    // the shared domain must remain eligible, not knocked out by
    // company A's suppression.
    $campBId = (function () use ($db, $companyBId, $userBId): int {
        $stmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$companyBId, 'Company B Campaign', $userBId, $userBId]);
        return (int) $db->lastInsertId();
    })();
    $eligibility = WaveAssigner::filterEligibleForCampaign($db, [$leadBId], $campBId);
    $assert($eligibility['suppressed_count'] === 0, "Company B's lead at the shared-but-only-A-suppressed domain is NOT flagged suppressed (got {$eligibility['suppressed_count']})");
    $assert(in_array($leadBId, $eligibility['eligible'], true), "Company B's lead remains eligible for its own company's campaign");

    // --- Company A's own lead at that domain IS correctly knocked out
    // (proves the fix didn't just turn the check off entirely).
    $campAId = (function () use ($db, $companyAId, $userAId): int {
        $stmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$companyAId, 'Company A Campaign', $userAId, $userAId]);
        return (int) $db->lastInsertId();
    })();
    $eligibilityA = WaveAssigner::filterEligibleForCampaign($db, [$leadAId], $campAId);
    $assert($eligibilityA['suppressed_count'] === 1, "Company A's own lead at its own suppressed domain IS still correctly excluded (got {$eligibilityA['suppressed_count']})");

    // --- LeadRepository::search(): default (hide suppressed) view for
    // company B must still include its own lead at the shared domain.
    $leadsB = LeadRepository::search($db, $scopeB, []);
    $foundB = false;
    foreach ($leadsB['rows'] as $r) {
        if ((int) $r['id'] === $leadBId) {
            $foundB = true;
        }
    }
    $assert($foundB, "LeadRepository::search() (default, hides suppressed) still shows company B's lead -- not hidden by company A's suppression");

    $leadsA = LeadRepository::search($db, $scopeA, []);
    $foundA = false;
    foreach ($leadsA['rows'] as $r) {
        if ((int) $r['id'] === $leadAId) {
            $foundA = true;
        }
    }
    $assert(!$foundA, "LeadRepository::search() (default, hides suppressed) correctly hides company A's own suppressed lead");

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll suppressed_domains cross-tenant checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
