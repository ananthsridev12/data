<?php
// SaleshandyClient::pullNewProspects() -- pulling in prospects that are
// already enrolled in a Saleshandy sequence but don't exist as a `leads`
// row locally yet. Its INSERT INTO leads used to omit company_id, which
// is NOT NULL with a FK (sql/034_multi_tenant_tighten.sql) -- so any real
// prospect pull hit an uncaught PDOException (HTTP 500 on
// cron_saleshandy_sync.php and the manual "Import from Saleshandy"
// button) the moment it needed to create a lead row. This proves the fix:
// a genuinely-new prospect gets a company-scoped lead row, and an
// existing lead in the SAME company is reused rather than duplicated. It
// also proves the lookup is company-scoped -- a same-email lead
// belonging to a DIFFERENT company must not be reused (would silently mix
// tenants). Rolled back at the end.
//
// Usage: php tests/saleshandy_pull_new_prospects_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

// Overrides the real HTTP call with a single canned page of consolidated-
// stats activity, so this test never touches the network.
class FakeSaleshandyClient extends SaleshandyClient
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    protected function request(string $method, string $path, array $params = []): array
    {
        return ['payload' => ['data' => $this->rows, 'hasMore' => false]];
    }
}

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Pull Co A', 30)");
    $companyA = (int) $db->lastInsertId();
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Pull Co B', 30)");
    $companyB = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyA, 'Admin A', 'admin@pulla.test', 'x', ROLE_ADMIN]);
    $adminA = (int) $db->lastInsertId();

    $campStmt = $db->prepare(
        'INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, saleshandy_sequence_id, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $campStmt->execute([$companyA, 'Pull-in campaign', $adminA, $adminA, 'seq-123']);
    $campaignId = (int) $db->lastInsertId();
    $campaign = $db->query("SELECT * FROM campaigns WHERE id = {$campaignId}")->fetch();

    // A lead with the same email already exists, but under company B --
    // must NOT be reused for company A's pull-in.
    $mkLead = function (int $companyId, string $email, string $company = 'Acme') use ($db): int {
        $stmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $company, 'F', 'L', $email]);
        return (int) $db->lastInsertId();
    };
    $crossTenantLeadId = $mkLead($companyB, 'shared@pull.test', 'Other Co');

    // A lead already existing under company A -- must be reused, not
    // duplicated.
    $existingLeadId = $mkLead($companyA, 'existing@pull.test');

    $client = new FakeSaleshandyClient('fake-key');
    $sentAt = time() - 3600;
    $client->rows = [
        [
            'Recipient Email' => 'shared@pull.test',
            'Recipient name' => 'Shared Prospect',
            'Email Sent At' => date('Y-m-d\TH:i:s\Z', $sentAt),
            'Replied' => 'No', 'Bounced' => 'No', 'Unsubscribed' => 'No',
            'Step Number' => 1,
        ],
        [
            'Recipient Email' => 'existing@pull.test',
            'Recipient name' => 'Existing Prospect',
            'Email Sent At' => date('Y-m-d\TH:i:s\Z', $sentAt),
            'Replied' => 'No', 'Bounced' => 'No', 'Unsubscribed' => 'No',
            'Step Number' => 1,
        ],
        [
            'Recipient Email' => 'brandnew@pull.test',
            'Recipient name' => 'Brand New Prospect',
            'Email Sent At' => date('Y-m-d\TH:i:s\Z', $sentAt),
            'Replied' => 'No', 'Bounced' => 'No', 'Unsubscribed' => 'No',
            'Step Number' => 1,
        ],
    ];

    $stats = $client->pullNewProspects($db, $campaign, $adminA);

    $assert($stats['leads_created'] === 2, "leads_created is 2 (brand-new + cross-tenant same-email, not the same-company existing one) -- got {$stats['leads_created']}");
    $assert($stats['assignments_created'] === 3, "assignments_created is 3 -- got {$stats['assignments_created']}");

    $findInCompanyA = $db->prepare('SELECT id, company_id FROM leads WHERE email = ? AND company_id = ?');

    $findInCompanyA->execute(['brandnew@pull.test', $companyA]);
    $newLead = $findInCompanyA->fetch();
    $assert($newLead !== false, 'A brand-new prospect got a leads row created under the pulling company (no uncaught PDOException)');
    $assert($newLead && (int) $newLead['company_id'] === $companyA, 'The new lead row carries the correct company_id');

    $findInCompanyA->execute(['existing@pull.test', $companyA]);
    $reusedLead = $findInCompanyA->fetch();
    $assert($reusedLead && (int) $reusedLead['id'] === $existingLeadId, 'An already-present same-company lead is reused, not duplicated');

    $findInCompanyA->execute(['shared@pull.test', $companyA]);
    $companyASharedLead = $findInCompanyA->fetch();
    $assert($companyASharedLead !== false, 'A same-email lead belonging to a different company does NOT block a new company-A lead row from being created');
    $assert($companyASharedLead && (int) $companyASharedLead['id'] !== $crossTenantLeadId, 'The new company-A lead is a distinct row from company B\'s lead with the same email (no cross-tenant reuse)');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll pullNewProspects() checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
