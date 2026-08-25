<?php
// SaleshandyClient::backfillHistoricalDates() -- its per-row
// delivery_status/current_step/email_sent update used to be gated
// behind "only if delivery_status or current_step actually changed", so
// a row whose status/step already matched what Saleshandy reports (e.g.
// already correct from an earlier sync or pullNewProspects()) but whose
// own email_sent flag was somehow still 0 stayed stuck at 0 forever --
// backfill would skip it entirely, never realizing Saleshandy DID
// confirm the send. Fixed to always apply the email_sent = email_sent
// OR ? merge whenever Saleshandy reports a real "Email Sent At" for
// that row, same as syncCampaign()'s own per-row update. Rolled back at
// the end.
//
// Usage: php tests/saleshandy_backfill_email_sent_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

class FakeBackfillClient extends SaleshandyClient
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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Backfill Co', 30)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'Admin', 'admin@backfill.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();

    $campStmt = $db->prepare(
        'INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, saleshandy_sequence_id, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $campStmt->execute([$companyId, 'Backfill Campaign', $adminId, $adminId, 'seq-bf']);
    $campaignId = (int) $db->lastInsertId();
    $campaign = $db->query("SELECT * FROM campaigns WHERE id = {$campaignId}")->fetch();

    $leadStmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)');
    $leadStmt->execute([$companyId, 'Acme', 'F', 'L', 'stuck@backfill.test']);
    $leadId = (int) $db->lastInsertId();

    // Already has the CORRECT delivery_status/current_step (as if a
    // prior sync already got those right), but email_sent is somehow
    // still 0 -- exactly the stuck case this fix targets.
    $assignStmt = $db->prepare(
        "INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, delivery_status, saleshandy_current_step, email_sent)
         VALUES (?, ?, ?, 'Active', 2, 0)"
    );
    $assignStmt->execute([$leadId, $campaignId, $adminId]);
    $assignmentId = (int) $db->lastInsertId();

    $client = new FakeBackfillClient('fake-key');
    $sentAt = time() - 86400;
    $client->rows = [
        [
            'Recipient Email' => 'stuck@backfill.test',
            'Recipient name' => 'Stuck Prospect',
            'Email Sent At' => date('Y-m-d\TH:i:s\Z', $sentAt),
            'Replied' => 'No', 'Bounced' => 'No', 'Unsubscribed' => 'No',
            'Step Number' => 2,
        ],
    ];

    $stats = $client->backfillHistoricalDates($db, $campaign, $adminId);

    $assert($stats['checked'] === 1, "backfill checked exactly 1 row -- got {$stats['checked']}");
    $assert($stats['delivery_status_fixed'] === 0, "delivery_status_fixed is 0 -- status/step genuinely didn't change (that part of the fix's premise) -- got {$stats['delivery_status_fixed']}");

    $row = $db->query("SELECT email_sent, delivery_status, saleshandy_current_step FROM lead_campaign_assignments WHERE id = {$assignmentId}")->fetch();
    $assert((int) $row['email_sent'] === 1, "email_sent is now 1 even though delivery_status/step didn't change -- got {$row['email_sent']}");
    $assert($row['delivery_status'] === 'Active', 'delivery_status is still correctly Active');
    $assert((int) $row['saleshandy_current_step'] === 2, 'saleshandy_current_step is still correctly 2');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll backfill email_sent checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
