<?php
// ReportsRepository::accountsSummary() -- the account (company/domain)
// rollup of the same funnel summary() already computes per-lead. An
// account (domain) counts as reached a stage the moment ANY ONE of its
// personas does, not all of them. Fixture: 4 domains --
//   domainA: 2 contacted leads, one opened, none bounced/replied
//   domainB: 1 contacted lead, bounced (also company-suppressed)
//   domainC: 1 lead, never contacted at all
//   domainD: 1 contacted lead, replied + opened
// Rolled back at the end.
//
// Usage: php tests/reports_accounts_summary_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
require_once __DIR__ . '/../app/includes/ReportsRepository.php';

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

    $mkLead = function (string $email) use ($db, $companyId): int {
        $stmt = $db->prepare(
            'INSERT INTO leads (company_id, na_company_name, category, products, first_name, last_name, title, company_name_for_emails, email, industry, person_linkedin_url, website, company_linkedin_url, company_country)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, 'X Co', 'Cat', 'Prod', 'First', 'Last', 'Title', 'X Co', $email, 'Industry', 'https://linkedin.test', 'https://x.test', 'https://linkedin.test/co', 'US']);
        return (int) $db->lastInsertId();
    };
    $campId = (function () use ($db, $companyId, $adminId): int {
        $stmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$companyId, 'Camp', $adminId, $adminId]);
        return (int) $db->lastInsertId();
    })();
    $mkAssignment = function (int $leadId, bool $emailSent, ?string $deliveryStatus, int $openCount) use ($db, $campId, $adminId): void {
        $stmt = $db->prepare(
            'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, email_sent, email_sent_at, delivery_status, open_count) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$leadId, $campId, $adminId, $emailSent ? 1 : 0, $emailSent ? date('Y-m-d') : null, $deliveryStatus, $openCount]);
    };

    // domainA: 2 contacted leads, one opened, none bounced/replied.
    $mkAssignment($mkLead('one@domain-a.test'), true, null, 1);
    $mkAssignment($mkLead('two@domain-a.test'), true, null, 0);
    // domainB: 1 contacted lead, bounced.
    $mkAssignment($mkLead('x@domain-b.test'), true, 'Hard Bounced', 0);
    // domainC: 1 lead, never contacted (no assignment row at all).
    $mkLead('y@domain-c.test');
    // domainD: 1 contacted lead, replied + opened.
    $mkAssignment($mkLead('z@domain-d.test'), true, 'Replied', 1);

    // Company suppresses domain-b (bounced).
    $db->prepare('INSERT INTO suppressed_domains (company_id, domain, reason, suppressed_by) VALUES (?, ?, ?, ?)')
        ->execute([$companyId, 'domain-b.test', 'Hard bounce', $adminId]);

    $scope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $result = ReportsRepository::accountsSummary($db, $scope, []);
    $h = $result['headline'];

    $assert($h['accounts_in_database'] === 4, "4 distinct accounts (domains) in database (got {$h['accounts_in_database']})");
    $assert($h['accounts_contacted'] === 3, "3 accounts have at least one contacted lead -- A, B, D, not C (got {$h['accounts_contacted']})");
    $assert($h['accounts_available'] === 1, "1 account (domain-c, never contacted) is available (got {$h['accounts_available']})");
    $assert($h['accounts_suppressed'] === 1, "1 account (domain-b) is suppressed (got {$h['accounts_suppressed']})");

    $byStage = [];
    foreach ($result['funnel'] as $row) {
        $byStage[$row['stage']] = $row['count'];
    }
    $assert($byStage['Accounts in database'] === 4, 'Funnel stage 1 matches headline in-database count');
    $assert($byStage['Accounts contacted'] === 3, 'Funnel stage 2 matches headline contacted count');
    $assert($byStage['Accounts delivered (not bounced)'] === 2, "Delivered = A and D (both have a non-bounced contacted lead), not B (fully bounced) (got {$byStage['Accounts delivered (not bounced)']})");
    $assert($byStage['Accounts opened'] === 2, "Opened = A and D (each has >=1 opened lead) (got {$byStage['Accounts opened']})");
    $assert($byStage['Accounts replied'] === 1, "Replied = D only (got {$byStage['Accounts replied']})");

    // --- date_from/date_to filters: periodExpr() is invoked 4 times in
    // this one query (contacted/delivered/opened/replied) -- each call
    // must use a distinct placeholder suffix, or PDO's real (non-
    // emulated) prepared statements throw "Invalid parameter number"
    // the moment a real filter value is bound (this exact regression
    // shipped once and was caught by an HTTP smoke test, not this file).
    $threw = false;
    try {
        $filtered = ReportsRepository::accountsSummary($db, $scope, ['date_from' => date('Y-m-d', strtotime('-1 year')), 'date_to' => date('Y-m-d', strtotime('+1 year'))]);
    } catch (Throwable $ex) {
        $threw = true;
    }
    $assert(!$threw, 'accountsSummary() with date_from/date_to filters does not throw "Invalid parameter number"');
    $assert(isset($filtered) && $filtered['headline']['accounts_contacted'] === 3, 'Filtered call still returns the same contacted count when the range covers everything');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll ReportsRepository::accountsSummary() checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
