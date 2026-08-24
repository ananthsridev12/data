<?php
// WaveAssigner::previewReleaseByCurrentBounceSettings() /
// releaseByCurrentBounceSettings() -- bounce_settings.php's "Release
// now" button. Unchecking a bounce type there only governs FUTURE
// bounces; this is the retroactive cleanup for whatever it already
// blocked: (1) suppressed_domains rows recorded under that now-not-
// suppressing type are deleted, (2) held (wave_status = 'suppressed')
// groups whose leader bounced with that type go back to 'active'. A
// domain/held group tied to a bounce type still configured to suppress,
// or with no recognized bounce_type, must be left untouched -- and the
// leader's own bounce_status must never be overwritten (it genuinely did
// bounce; only the account-wide/held-group side effect is undone).
// Rolled back at the end.
//
// Usage: php tests/wave_assigner_release_by_bounce_settings_test.php

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Release Co', 30)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'Admin', 'admin@release.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();

    // Current settings: Soft Bounce no longer suppresses; Hard Bounce still does.
    $db->exec("INSERT INTO bounce_type_suppression_settings (company_id, bounce_type, suppresses) VALUES ({$companyId}, 'Soft Bounce', 0)");
    $db->exec("INSERT INTO bounce_type_suppression_settings (company_id, bounce_type, suppresses) VALUES ({$companyId}, 'Hard Bounce', 1)");

    $campStmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
    $campStmt->execute([$companyId, 'Release Campaign', $adminId, $adminId]);
    $campaignId = (int) $db->lastInsertId();

    $mkLead = function (string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, 'Acme', 'F', 'L', $email]);
        return (int) $db->lastInsertId();
    };
    $mkAssignment = function (int $leadId, string $waveStatus, ?int $waveLeaderId, string $bounceStatus, ?string $bounceType) use ($db, $companyId, $campaignId, $adminId): int {
        $stmt = $db->prepare(
            'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, wave_status, wave_leader_id, bounce_status, bounce_type)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$leadId, $campaignId, $adminId, $waveStatus, $waveLeaderId, $bounceStatus, $bounceType]);
        return (int) $db->lastInsertId();
    };

    // Domain 1 (soft-bounce.test): leader soft-bounced (now a non-
    // suppressing type) with a held teammate under it.
    $softLeaderLeadId = $mkLead('leader@soft-bounce.test');
    $softLeaderAssignmentId = $mkAssignment($softLeaderLeadId, 'active', null, 'bounced', 'Soft Bounce');
    $softHeldLeadId = $mkLead('held@soft-bounce.test');
    $mkAssignment($softHeldLeadId, 'suppressed', $softLeaderAssignmentId, 'pending', null);
    $db->prepare('INSERT INTO suppressed_domains (company_id, domain, reason, bounce_type, suppressed_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$companyId, 'soft-bounce.test', 'Wave-1 bounce', 'Soft Bounce', $adminId]);

    // Domain 2 (hard-bounce.test): leader hard-bounced (still a
    // suppressing type) -- must NOT be released.
    $hardLeaderLeadId = $mkLead('leader@hard-bounce.test');
    $hardLeaderAssignmentId = $mkAssignment($hardLeaderLeadId, 'active', null, 'bounced', 'Hard Bounce');
    $hardHeldLeadId = $mkLead('held@hard-bounce.test');
    $mkAssignment($hardHeldLeadId, 'suppressed', $hardLeaderAssignmentId, 'pending', null);
    $db->prepare('INSERT INTO suppressed_domains (company_id, domain, reason, bounce_type, suppressed_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$companyId, 'hard-bounce.test', 'Wave-1 bounce', 'Hard Bounce', $adminId]);

    // Domain 3 (manual.test): manually suppressed, no recorded bounce
    // type at all -- must never be released by this bulk action.
    $db->prepare('INSERT INTO suppressed_domains (company_id, domain, reason, bounce_type, suppressed_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$companyId, 'manual.test', 'Manual block', null, $adminId]);

    // --- Preview: only domain 1's release should show up.
    $preview = WaveAssigner::previewReleaseByCurrentBounceSettings($db, $companyId);
    $assert($preview['domains_count'] === 1, "preview finds exactly 1 releasable domain -- got {$preview['domains_count']}");
    $assert($preview['held_count'] === 1, "preview finds exactly 1 releasable held prospect -- got {$preview['held_count']}");
    $assert($preview['domains'] === ['soft-bounce.test'], 'preview names the soft-bounce domain specifically');
    $assert(WaveAssigner::previewReleaseByCurrentBounceSettings($db, $companyId) == $preview, 'preview is read-only -- calling it again returns the same result');

    // --- Execute.
    $result = WaveAssigner::releaseByCurrentBounceSettings($db, $companyId);
    $assert($result['domains_released'] === 1, "release reports 1 domain released -- got {$result['domains_released']}");
    $assert($result['held_reactivated'] === 1, "release reports 1 held prospect reactivated -- got {$result['held_reactivated']}");

    $softDomainGone = (int) $db->query("SELECT COUNT(*) FROM suppressed_domains WHERE company_id = {$companyId} AND domain = 'soft-bounce.test'")->fetchColumn();
    $assert($softDomainGone === 0, 'soft-bounce.test was actually removed from suppressed_domains');

    $hardDomainStillThere = (int) $db->query("SELECT COUNT(*) FROM suppressed_domains WHERE company_id = {$companyId} AND domain = 'hard-bounce.test'")->fetchColumn();
    $assert($hardDomainStillThere === 1, 'hard-bounce.test (still a suppressing type) was left alone');

    $manualDomainStillThere = (int) $db->query("SELECT COUNT(*) FROM suppressed_domains WHERE company_id = {$companyId} AND domain = 'manual.test'")->fetchColumn();
    $assert($manualDomainStillThere === 1, 'manual.test (no recorded bounce_type) was left alone');

    $softHeldStatus = $db->query("SELECT wave_status FROM lead_campaign_assignments WHERE lead_id = {$softHeldLeadId}")->fetchColumn();
    $assert($softHeldStatus === 'active', "the soft-bounce domain's held teammate is now 'active' -- got '{$softHeldStatus}'");

    $hardHeldStatus = $db->query("SELECT wave_status FROM lead_campaign_assignments WHERE lead_id = {$hardHeldLeadId}")->fetchColumn();
    $assert($hardHeldStatus === 'suppressed', "the hard-bounce domain's held teammate is STILL 'suppressed' -- got '{$hardHeldStatus}'");

    $leaderRow = $db->query("SELECT bounce_status, bounce_type FROM lead_campaign_assignments WHERE id = {$softLeaderAssignmentId}")->fetch();
    $assert($leaderRow['bounce_status'] === 'bounced', 'the soft-bounce LEADER\'s own bounce_status is untouched -- still \'bounced\' (it genuinely did bounce)');
    $assert($leaderRow['bounce_type'] === 'Soft Bounce', 'the leader\'s own bounce_type record is untouched');

    // --- Idempotent: running it again now finds nothing left to release.
    $secondPreview = WaveAssigner::previewReleaseByCurrentBounceSettings($db, $companyId);
    $assert($secondPreview['domains_count'] === 0 && $secondPreview['held_count'] === 0, 'a second run finds nothing left to release (idempotent)');
    $secondResult = WaveAssigner::releaseByCurrentBounceSettings($db, $companyId);
    $assert($secondResult['domains_released'] === 0 && $secondResult['held_reactivated'] === 0, 'a second execute() is a safe no-op');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll release-by-bounce-settings checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
