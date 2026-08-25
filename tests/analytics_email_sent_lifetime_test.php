<?php
// AnalyticsRepository::pivotByDimension()'s email_sent/email_not_sent/
// sequence_completed, and LeadRepository::buildWhere()'s matching
// 'email_sent'/'sequence_completed' drill-through filters -- both used
// to only look at a lead's *latest* campaign assignment (same "latest
// assignment per lead" join as 'imported'). That's correct for
// 'imported' (current standing), but wrong for these two: once a lead
// has genuinely been emailed, or has genuinely completed a sequence,
// that's a permanent fact -- not something that should look "undone"
// just because cooldown-based reassignment (WaveAssigner) moved them to
// a new campaign that hasn't sent yet. This is exactly the account-wide
// undercount discovered by comparing against Saleshandy's own "Total
// Contacted" number. Fixed to check ANY assignment ever. Rolled back at
// the end.
//
// Usage: php tests/analytics_email_sent_lifetime_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
require_once __DIR__ . '/../app/includes/AnalyticsRepository.php';
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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Lifetime Co', 0)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'Admin', 'admin@lifetime.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();
    $scope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);

    $campStmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, saleshandy_step_count) VALUES (?, ?, ?, ?, ?)');
    $campStmt->execute([$companyId, 'Old Campaign', $adminId, $adminId, 3]);
    $oldCampaignId = (int) $db->lastInsertId();
    $campStmt->execute([$companyId, 'New Campaign', $adminId, $adminId, 5]);
    $newCampaignId = (int) $db->lastInsertId();

    $mkLead = function (string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, 'Acme', 'F', 'L', $email]);
        return (int) $db->lastInsertId();
    };
    $mkAssignment = function (int $leadId, int $campaignId, string $deliveryStatus, int $currentStep, int $emailSent) use ($db, $adminId): int {
        $stmt = $db->prepare(
            "INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, delivery_status, saleshandy_current_step, email_sent, wave_status)
             VALUES (?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->execute([$leadId, $campaignId, $adminId, $deliveryStatus, $currentStep, $emailSent]);
        return (int) $db->lastInsertId();
    };

    // Reassigned lead: fully completed the Old Campaign's 3-step
    // sequence (confirmed sent), then reassigned into New Campaign
    // (fresh push, not yet sent/synced there).
    $reassignedLeadId = $mkLead('reassigned@lifetime.test');
    $mkAssignment($reassignedLeadId, $oldCampaignId, 'Active', 3, 1);
    $mkAssignment($reassignedLeadId, $newCampaignId, 'Waiting', 0, 0);

    // Control: never reassigned, never sent at all.
    $neverSentLeadId = $mkLead('neversent@lifetime.test');
    $mkAssignment($neverSentLeadId, $newCampaignId, 'Waiting', 0, 0);

    // Control: never reassigned, sent and completed in their only campaign.
    $singleCampaignLeadId = $mkLead('single@lifetime.test');
    $mkAssignment($singleCampaignLeadId, $oldCampaignId, 'Active', 3, 1);

    $filters = ['vertical_id' => '', 'service_id' => '', 'industry' => '', 'created_from' => '', 'created_to' => '', 'email_sent_from' => '', 'email_sent_to' => ''];
    $pivot = AnalyticsRepository::pivotByDimension($db, $scope, 'company_country', $filters);
    $total = $pivot['total'];

    $assert($total['email_sent'] === 2, "reassigned + single-campaign leads count as email_sent (lifetime, not just latest assignment) -- got {$total['email_sent']}");
    $assert($total['email_not_sent'] === 1, "email_not_sent is 1 -- only the genuinely-never-sent lead, NOT the reassigned lead (their fresh not-yet-sent new assignment no longer masks their real prior send) -- got {$total['email_not_sent']}");
    $assert($total['sequence_completed'] === 2, "2 leads (reassigned + single-campaign) count as sequence_completed lifetime -- got {$total['sequence_completed']}");

    $emailSentIds = LeadRepository::matchingIds($db, $scope, ['email_sent' => '1']);
    $assert(in_array($reassignedLeadId, $emailSentIds, true), "drill-through filter email_sent=1 includes the reassigned lead");
    $assert(in_array($singleCampaignLeadId, $emailSentIds, true), "drill-through filter email_sent=1 includes the single-campaign lead");
    $assert(!in_array($neverSentLeadId, $emailSentIds, true), "drill-through filter email_sent=1 correctly EXCLUDES the never-sent lead");

    $emailNotSentIds = LeadRepository::matchingIds($db, $scope, ['email_sent' => '0']);
    $assert($emailNotSentIds === [$neverSentLeadId], 'email_sent=0 returns exactly the never-sent lead');

    $sequenceCompletedIds = LeadRepository::matchingIds($db, $scope, ['sequence_completed' => '1']);
    $assert(in_array($reassignedLeadId, $sequenceCompletedIds, true), 'sequence_completed=1 includes the reassigned lead (completed their OLD campaign)');
    $assert(!in_array($neverSentLeadId, $sequenceCompletedIds, true), 'sequence_completed=1 excludes the never-sent lead');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll email_sent/sequence_completed lifetime checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
