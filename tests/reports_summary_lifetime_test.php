<?php
// ReportsRepository::summary()/coverageByVertical()/repliesByOutcome() --
// same bug as AnalyticsRepository's account-wide "Email sent" (fixed
// separately, this class keeps its own query logic): the funnel used to
// only check a lead's LATEST campaign assignment. Once a lead can be
// reassigned to a new campaign (WaveAssigner's cooldown-based
// reassignment), that made a lead genuinely contacted/delivered/opened/
// replied in an EARLIER campaign look like it never happened the moment
// they got reassigned to a fresh (not-yet-sent) one. Also covers a
// second bug found in the same rewrite: an unparenthesized OR inside the
// "delivered" stage's extra condition broke SQL operator precedence,
// making EVERY lead count as delivered regardless of their own status.
// Rolled back at the end.
//
// Usage: php tests/reports_summary_lifetime_test.php

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Reports Lifetime Co', 0)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'Admin', 'admin@reportslifetime.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();
    $scope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);

    $vertStmt = $db->prepare('INSERT INTO verticals (company_id, code, label) VALUES (?, ?, ?)');
    $vertStmt->execute([$companyId, 'V1', 'Vertical One']);
    $verticalId = (int) $db->lastInsertId();

    $campStmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
    $campStmt->execute([$companyId, 'Old Campaign', $adminId, $adminId]);
    $oldCampaignId = (int) $db->lastInsertId();
    $campStmt->execute([$companyId, 'New Campaign', $adminId, $adminId]);
    $newCampaignId = (int) $db->lastInsertId();

    $mkLead = function (string $email) use ($db, $companyId, $verticalId): int {
        $stmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email, vertical_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, 'Acme', 'F', 'L', $email, $verticalId]);
        return (int) $db->lastInsertId();
    };
    $mkAssignment = function (int $leadId, int $campaignId, bool $emailSent, ?string $deliveryStatus, int $openCount, ?string $sentiment = null) use ($db, $adminId): void {
        $stmt = $db->prepare(
            'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, email_sent, email_sent_at, delivery_status, open_count, reply_sentiment)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$leadId, $campaignId, $adminId, $emailSent ? 1 : 0, $emailSent ? date('Y-m-d') : null, $deliveryStatus, $openCount, $sentiment]);
    };

    // Reassigned lead: contacted, delivered, opened, AND replied in the
    // Old Campaign -- then reassigned into New Campaign (fresh push, not
    // yet sent/synced there). Must still show up in every stage.
    $reassignedLeadId = $mkLead('reassigned@reportslifetime.test');
    $mkAssignment($reassignedLeadId, $oldCampaignId, true, 'Replied', 1, 'Positive');
    $mkAssignment($reassignedLeadId, $newCampaignId, false, null, 0);

    // Control: never contacted at all.
    $neverContactedLeadId = $mkLead('never@reportslifetime.test');
    $mkAssignment($neverContactedLeadId, $newCampaignId, false, null, 0);

    // Control: contacted but bounced (must NOT count as delivered) --
    // this is also the case that exposed the operator-precedence bug
    // (an unparenthesized OR made every lead count as delivered).
    $bouncedLeadId = $mkLead('bounced@reportslifetime.test');
    $mkAssignment($bouncedLeadId, $oldCampaignId, true, 'Hard Bounced', 0);

    $result = ReportsRepository::summary($db, $scope, []);
    $h = $result['headline'];

    $assert($h['contacts_in_database'] === 3, "3 leads in database -- got {$h['contacts_in_database']}");
    $assert($h['contacts_reached'] === 2, "2 leads ever contacted (reassigned + bounced), NOT the never-contacted one -- got {$h['contacts_reached']}");
    $assert($h['unique_opens'] === 1, "1 lead ever opened (the reassigned one, from their OLD campaign) -- got {$h['unique_opens']}");
    $assert($h['replies'] === 1, "1 lead ever replied (the reassigned one) -- got {$h['replies']}");

    $byStage = [];
    foreach ($result['funnel'] as $row) {
        $byStage[$row['stage']] = $row['count'];
    }
    $assert($byStage['Delivered (not bounced)'] === 1, "Delivered = only the reassigned lead (bounced lead correctly excluded, and NOT every lead -- regression check for the operator-precedence bug) -- got {$byStage['Delivered (not bounced)']}");

    $coverage = ReportsRepository::coverageByVertical($db, $scope, []);
    $vertRow = null;
    foreach ($coverage['rows'] as $row) {
        if ($row['grp'] === 'Vertical One') {
            $vertRow = $row;
        }
    }
    $assert($vertRow !== null, 'coverageByVertical() has a row for Vertical One');
    $assert($vertRow && $vertRow['contacted'] === 2, "coverageByVertical() also counts the reassigned lead as contacted (lifetime) -- got " . ($vertRow['contacted'] ?? 'n/a'));

    $outcomes = ReportsRepository::repliesByOutcome($db, $scope, []);
    $outcomeCounts = [];
    foreach ($outcomes as $o) {
        $outcomeCounts[$o['outcome']] = $o['count'];
    }
    $assert(($outcomeCounts['Positive'] ?? 0) === 1, "repliesByOutcome() counts the reassigned lead's OLD-campaign reply sentiment -- got " . ($outcomeCounts['Positive'] ?? 0));

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll ReportsRepository lifetime checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
