<?php
// WaveAssigner::suppressByEmail()/suppress() -- verifies the fix for a
// critical bug where suppressDomainOf() never set suppressed_domains
// .company_id (NOT NULL), so every bounce-processing action (bounce CSV
// import, campaign bounce paste, delivery-status changes, Saleshandy
// sync/pull/backfill, campaign history import) would have thrown
// outright. Also verifies bounce-type suppression settings and
// suppressed domains stay scoped per company. Rolled back at the end.
//
// Usage: php tests/bounce_suppression_company_scope_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
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

    // --- suppressByEmail(): used to throw outright (missing company_id
    // on the suppressed_domains INSERT) -- this is the core regression
    // check for the fix.
    $threw = false;
    try {
        $result = WaveAssigner::suppressByEmail($db, 'bounced@shared-domain.test', $userAId, $companyAId, 'Test bounce', 'Hard Bounce');
    } catch (Throwable $ex) {
        $threw = true;
    }
    $assert(!$threw, 'suppressByEmail() no longer throws (used to fail on missing suppressed_domains.company_id)');
    $assert(isset($result) && $result['suppressed'] === true, 'suppressByEmail() reports the domain as suppressed');

    $domainRow = $db->query(
        "SELECT company_id FROM suppressed_domains WHERE domain = 'shared-domain.test' AND company_id = {$companyAId}"
    )->fetch();
    $assert($domainRow !== false, 'suppressed_domains row was actually written with the right company_id');

    // --- Same domain, different company -- must be independently
    // suppressible (proves the (company_id, domain) key, not (domain) alone).
    $threw2 = false;
    try {
        WaveAssigner::suppressByEmail($db, 'bounced@shared-domain.test', $userBId, $companyBId, 'Test bounce', 'Hard Bounce');
    } catch (Throwable $ex) {
        $threw2 = true;
    }
    $assert(!$threw2, 'suppressByEmail() for the same domain in a different company also succeeds');
    $bothRows = (int) $db->query("SELECT COUNT(*) FROM suppressed_domains WHERE domain = 'shared-domain.test'")->fetchColumn();
    $assert($bothRows === 2, 'the same domain has independent suppression rows per company (2 seen)');

    // --- bounceTypeSuppresses(): company A's setting doesn't leak into
    // company B's. (New companies aren't auto-seeded with settings rows
    // yet -- insert one directly for this check, same shape as the
    // migration seed.)
    $db->exec("INSERT INTO bounce_type_suppression_settings (company_id, bounce_type, suppresses) VALUES ({$companyAId}, 'Soft Bounce', 0)");
    $db->exec("INSERT INTO bounce_type_suppression_settings (company_id, bounce_type, suppresses) VALUES ({$companyBId}, 'Soft Bounce', 1)");
    $assert(WaveAssigner::bounceTypeSuppresses($db, 'Soft Bounce', $companyAId) === false, 'company A\'s "don\'t suppress" setting is respected');
    $assert(WaveAssigner::bounceTypeSuppresses($db, 'Soft Bounce', $companyBId) === true, 'company B\'s own (default) setting is unaffected by company A\'s change');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll bounce-suppression company-scope checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
