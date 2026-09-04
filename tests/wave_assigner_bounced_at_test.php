<?php
// WaveAssigner::suppress()/suppressByEmail()'s new optional $bouncedAt
// param (lead_campaign_assignments.bounced_at, sql/051) -- records WHEN
// a lead actually bounced, not just that it did. Confirmed against a
// real live Saleshandy account that /analytics/consolidated-stats
// returns a "Bounced At" timestamp per bounce that this app previously
// discarded entirely; SaleshandyClient now threads it through here.
// Defaults to NOW() (within this test's own tolerance window) for
// callers with no real per-event timestamp (manual Bounce Import,
// paste-bounces, per-leader "Bounced" button). Rolled back at the end.
//
// Usage: php tests/wave_assigner_bounced_at_test.php

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Bounced At Co', 0)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'admin@bouncedat.test', 'admin@bouncedat.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();

    $campStmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
    $campStmt->execute([$companyId, 'Campaign', $adminId, $adminId]);
    $campaignId = (int) $db->lastInsertId();

    $mkLead = function (string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, 'Acme', 'F', 'L', $email]);
        return (int) $db->lastInsertId();
    };

    // --- suppress(): explicit $bouncedAt is used verbatim.
    $leadA = $mkLead('leader-a@bouncedat1.test');
    $assignStmt = $db->prepare("INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, wave_status, bounce_status) VALUES (?, ?, ?, 'active', 'pending')");
    $assignStmt->execute([$leadA, $campaignId, $adminId]);
    $leaderAssignmentIdA = (int) $db->lastInsertId();

    $explicitTimestamp = '2026-01-15 09:30:00';
    WaveAssigner::suppress($db, $leaderAssignmentIdA, $adminId, $companyId, 'Test bounce', 'Hard Bounce', $explicitTimestamp);
    $storedA = $db->query("SELECT bounced_at FROM lead_campaign_assignments WHERE id = {$leaderAssignmentIdA}")->fetchColumn();
    $assert($storedA === $explicitTimestamp, "suppress() with an explicit \$bouncedAt stores it verbatim (got '{$storedA}')");

    // --- suppress(): omitted $bouncedAt defaults to NOW() (within a
    // generous tolerance window for test execution time).
    $leadB = $mkLead('leader-b@bouncedat2.test');
    $assignStmt->execute([$leadB, $campaignId, $adminId]);
    $leaderAssignmentIdB = (int) $db->lastInsertId();

    $before = time();
    WaveAssigner::suppress($db, $leaderAssignmentIdB, $adminId, $companyId, 'Test bounce', 'Hard Bounce');
    $after = time();
    $storedB = $db->query("SELECT bounced_at FROM lead_campaign_assignments WHERE id = {$leaderAssignmentIdB}")->fetchColumn();
    $storedBTs = strtotime((string) $storedB);
    $assert($storedBTs !== false && $storedBTs >= $before - 2 && $storedBTs <= $after + 2, "suppress() with no \$bouncedAt defaults to approximately NOW() (got '{$storedB}')");

    // --- suppressByEmail(): explicit $bouncedAt cascades to the leader row it finds.
    $leadC = $mkLead('leader-c@bouncedat3.test');
    $assignStmt->execute([$leadC, $campaignId, $adminId]);
    $leaderAssignmentIdC = (int) $db->lastInsertId();

    $explicitTimestamp2 = '2026-02-20 14:00:00';
    WaveAssigner::suppressByEmail($db, 'leader-c@bouncedat3.test', $adminId, $companyId, 'Test bounce', 'Soft Bounce', $explicitTimestamp2);
    $storedC = $db->query("SELECT bounced_at FROM lead_campaign_assignments WHERE id = {$leaderAssignmentIdC}")->fetchColumn();
    $assert($storedC === $explicitTimestamp2, "suppressByEmail() with an explicit \$bouncedAt stores it on the leader row it finds (got '{$storedC}')");

    // --- suppressByEmail(): omitted $bouncedAt defaults to NOW().
    $leadD = $mkLead('leader-d@bouncedat4.test');
    $assignStmt->execute([$leadD, $campaignId, $adminId]);
    $leaderAssignmentIdD = (int) $db->lastInsertId();

    $before2 = time();
    WaveAssigner::suppressByEmail($db, 'leader-d@bouncedat4.test', $adminId, $companyId, 'Test bounce', 'Soft Bounce');
    $after2 = time();
    $storedD = $db->query("SELECT bounced_at FROM lead_campaign_assignments WHERE id = {$leaderAssignmentIdD}")->fetchColumn();
    $storedDTs = strtotime((string) $storedD);
    $assert($storedDTs !== false && $storedDTs >= $before2 - 2 && $storedDTs <= $after2 + 2, "suppressByEmail() with no \$bouncedAt defaults to approximately NOW() (got '{$storedD}')");

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll WaveAssigner bounced_at checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
