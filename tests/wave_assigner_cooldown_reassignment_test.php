<?php
// WaveAssigner::filterEligibleForCampaign()/assign() cooldown-aware
// "already elsewhere" check -- previously unconditional (ANY prior
// assignment to a different campaign blocked a lead forever), now
// mirrors LeadRepository::buildWhere()'s 'assignable_after_cooldown_days'
// filter exactly: a lead becomes reassignable to a different campaign
// once its latest assignment is resolved (not held, not still pending a
// delivery outcome) and older than the company's lead_cooldown_days.
// This is what actually makes "move a finished prospect to a new
// campaign" possible -- see also icp_cooldown_reassignment_test.php,
// which covers the matching side of the same rule. Rolled back at the
// end.
//
// Usage: php tests/wave_assigner_cooldown_reassignment_test.php

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Move Co', 30)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'admin@move.test', 'admin@move.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();

    $campStmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
    $campStmt->execute([$companyId, 'Old Campaign', $adminId, $adminId]);
    $campaignA = (int) $db->lastInsertId();
    $campStmt->execute([$companyId, 'New Campaign', $adminId, $adminId]);
    $campaignB = (int) $db->lastInsertId();

    $mkLead = function (string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, 'Acme', 'F', 'L', $email]);
        return (int) $db->lastInsertId();
    };

    // Each on its own domain -- sharing a domain would also trip the
    // separate "pending elsewhere at this account" wave-safety check
    // (WaveAssigner::pendingElsewhereCampaigns()), which isn't what this
    // test is verifying.
    $leadNeverAssigned = $mkLead('never@acme1.test');
    $leadResolvedPastCooldown = $mkLead('resolved-past@acme2.test');
    $leadResolvedWithinCooldown = $mkLead('resolved-within@acme3.test');
    $leadStillHeld = $mkLead('still-held@acme4.test');
    $leadStillPending = $mkLead('still-pending@acme5.test');
    $leadSuppressedDomain = $mkLead('resolved-past@suppressed.test');

    $insertAssignment = $db->prepare(
        'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, wave_status, bounce_status, delivery_status, assigned_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $daysAgo = static fn (int $days): string => (new DateTime("-{$days} days"))->format('Y-m-d H:i:s');

    $insertAssignment->execute([$leadResolvedPastCooldown, $campaignA, $adminId, 'active', 'delivered', 'Active', $daysAgo(40)]);
    $insertAssignment->execute([$leadResolvedWithinCooldown, $campaignA, $adminId, 'active', 'delivered', 'Active', $daysAgo(5)]);
    $insertAssignment->execute([$leadStillHeld, $campaignA, $adminId, 'held', 'pending', null, $daysAgo(40)]);
    $insertAssignment->execute([$leadStillPending, $campaignA, $adminId, 'active', 'pending', null, $daysAgo(40)]);
    $insertAssignment->execute([$leadSuppressedDomain, $campaignA, $adminId, 'active', 'delivered', 'Active', $daysAgo(40)]);

    $db->prepare('INSERT INTO suppressed_domains (company_id, domain, reason, suppressed_by) VALUES (?, ?, ?, ?)')
        ->execute([$companyId, 'suppressed.test', 'test fixture', $adminId]);

    $allLeadIds = [$leadNeverAssigned, $leadResolvedPastCooldown, $leadResolvedWithinCooldown, $leadStillHeld, $leadStillPending, $leadSuppressedDomain];

    // --- filterEligibleForCampaign() against campaign B, cooldown=30.
    $filtered = WaveAssigner::filterEligibleForCampaign($db, $allLeadIds, $campaignB, 30);

    $assert(in_array($leadNeverAssigned, $filtered['eligible'], true), 'A never-assigned lead is eligible for a different campaign');
    $assert(in_array($leadResolvedPastCooldown, $filtered['eligible'], true), 'A resolved assignment past the cooldown window is eligible for a different campaign');
    $assert(!in_array($leadResolvedWithinCooldown, $filtered['eligible'], true), 'A resolved assignment still inside the cooldown window is NOT eligible for a different campaign');
    $assert(!in_array($leadStillHeld, $filtered['eligible'], true), 'A still-held assignment is NOT eligible for a different campaign, even 40 days later');
    $assert(!in_array($leadStillPending, $filtered['eligible'], true), 'A still-pending assignment is NOT eligible for a different campaign, even 40 days later');
    $assert(!in_array($leadSuppressedDomain, $filtered['eligible'], true), 'A resolved + past-cooldown lead on a suppressed domain is still NOT eligible (suppressed, not elsewhere)');
    $assert($filtered['suppressed_count'] === 1, 'Suppressed-domain lead is counted as suppressed, not as already-elsewhere (got ' . $filtered['suppressed_count'] . ')');
    $assert($filtered['already_elsewhere_count'] === 3, '3 leads (within-cooldown, held, pending) counted as already-elsewhere-blocked (got ' . $filtered['already_elsewhere_count'] . ')');

    // --- Same set with cooldown=0: the within-cooldown lead becomes
    // eligible too (0-day cooldown means "resolved is enough"), held/
    // pending still are not -- proves cooldown days is actually being
    // read, not just gating on resolution status alone.
    $filteredNoCooldown = WaveAssigner::filterEligibleForCampaign($db, $allLeadIds, $campaignB, 0);
    $assert(in_array($leadResolvedWithinCooldown, $filteredNoCooldown['eligible'], true), 'With a 0-day cooldown, a resolved-5-days-ago lead is eligible (cooldown days is actually respected)');
    $assert(!in_array($leadStillHeld, $filteredNoCooldown['eligible'], true), 'Held is blocked regardless of cooldown days');
    $assert(!in_array($leadStillPending, $filteredNoCooldown['eligible'], true), 'Pending is blocked regardless of cooldown days');

    // --- assign(): actually moves the eligible lead into campaign B, and
    // its history in campaign A is untouched (never deleted, just no
    // longer the *latest* assignment).
    $stats = WaveAssigner::assign($db, $allLeadIds, $campaignB, $adminId, [], 30);
    $assert($stats['already_elsewhere_skipped'] === 3, 'assign() skip count matches filterEligibleForCampaign() (got ' . $stats['already_elsewhere_skipped'] . ')');
    $assert($stats['suppressed_skipped'] === 1, 'assign() suppressed count matches (got ' . $stats['suppressed_skipped'] . ')');

    $newAssignmentStmt = $db->prepare('SELECT id FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id = ?');
    $newAssignmentStmt->execute([$leadResolvedPastCooldown, $campaignB]);
    $assert((bool) $newAssignmentStmt->fetchColumn(), 'The resolved+cooled-down lead actually got a new assignment row in campaign B');

    $oldAssignmentStmt = $db->prepare('SELECT id FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id = ?');
    $oldAssignmentStmt->execute([$leadResolvedPastCooldown, $campaignA]);
    $assert((bool) $oldAssignmentStmt->fetchColumn(), 'Its original campaign A assignment row is kept as history, not deleted');

    $blockedNewAssignmentStmt = $db->prepare('SELECT id FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id = ?');
    $blockedNewAssignmentStmt->execute([$leadStillHeld, $campaignB]);
    $assert(!$blockedNewAssignmentStmt->fetchColumn(), 'The still-held lead did NOT get moved to campaign B');

    $assert($stats['reassigned_sent'] === 1, 'assign() counted exactly the resolved+cooled-down lead as reassigned_sent (got ' . $stats['reassigned_sent'] . ')');
    $assert($stats['leaders'] === 1, 'The never-assigned lead still went through normal wave-1 as a leader (got ' . $stats['leaders'] . ' leaders)');

    $reassignedRowStmt = $db->prepare('SELECT wave_status, wave_leader_id FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id = ?');
    $reassignedRowStmt->execute([$leadResolvedPastCooldown, $campaignB]);
    $reassignedRow = $reassignedRowStmt->fetch();
    $assert($reassignedRow && $reassignedRow['wave_status'] === 'active' && $reassignedRow['wave_leader_id'] === null, 'The reassigned lead\'s new row is active with no wave_leader_id -- bypassed wave-1, not silently made a "leader" of a group of one');

    // --- Two reassigned leads sharing the SAME domain: per the user's
    // explicit direction, wave-1 pacing doesn't apply between them either
    // -- both go active immediately, no held pairing, since each already
    // individually proved deliverable in campaign A. Mixed in the same
    // call with two genuinely fresh leads at a DIFFERENT shared domain,
    // to prove reassigned-bypass and normal wave-1 grouping coexist
    // correctly within one assign() call.
    $campStmt->execute([$companyId, 'Campaign C', $adminId, $adminId]);
    $campaignC = (int) $db->lastInsertId();

    $leadReassignedSharedA = $mkLead('teamA@shared-reassigned.test');
    $leadReassignedSharedB = $mkLead('teamB@shared-reassigned.test');
    $leadFreshSharedA = $mkLead('teamA@shared-fresh.test');
    $leadFreshSharedB = $mkLead('teamB@shared-fresh.test');

    $insertAssignment->execute([$leadReassignedSharedA, $campaignA, $adminId, 'active', 'delivered', 'Active', $daysAgo(40)]);
    $insertAssignment->execute([$leadReassignedSharedB, $campaignA, $adminId, 'active', 'delivered', 'Active', $daysAgo(40)]);

    $mixedStats = WaveAssigner::assign(
        $db,
        [$leadReassignedSharedA, $leadReassignedSharedB, $leadFreshSharedA, $leadFreshSharedB],
        $campaignC,
        $adminId,
        [],
        30
    );
    $assert($mixedStats['reassigned_sent'] === 2, 'Both reassigned leads at the shared domain went straight to active, no wave-1 pairing between them (got ' . $mixedStats['reassigned_sent'] . ')');
    $assert($mixedStats['leaders'] === 1 && $mixedStats['held'] === 1, 'The two FRESH leads at their own shared domain still get normal wave-1 leader/held pairing (got ' . $mixedStats['leaders'] . ' leaders, ' . $mixedStats['held'] . ' held)');
    $assert($mixedStats['domains'] === 1, 'domains count only reflects the fresh-lead domain group, not the bypassed reassigned pair (got ' . $mixedStats['domains'] . ')');

    $sharedReassignedStmt = $db->prepare('SELECT wave_status, wave_leader_id FROM lead_campaign_assignments WHERE lead_id IN (?, ?) AND campaign_id = ?');
    $sharedReassignedStmt->execute([$leadReassignedSharedA, $leadReassignedSharedB, $campaignC]);
    $bothActiveNoLeader = true;
    foreach ($sharedReassignedStmt->fetchAll() as $row) {
        if ($row['wave_status'] !== 'active' || $row['wave_leader_id'] !== null) {
            $bothActiveNoLeader = false;
        }
    }
    $assert($bothActiveNoLeader, 'Both reassigned leads at the shared domain are active with no wave_leader_id -- neither is held under the other');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll WaveAssigner cooldown-reassignment checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
