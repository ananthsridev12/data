<?php
// AnalyticsRepository::pivotByDimension() -- the new linked_to_campaign
// count and the 5-way "why not imported" reason breakdown
// (no_campaign/suppressed/held/no_sequence/queued), which must always
// sum back to exactly not_imported. Fixture: 7 leads, one per bucket
// (plus 2 imported), all in the same Vertical group so a single row's
// numbers can be checked directly. Rolled back at the end.
//
// Usage: php tests/analytics_not_imported_reasons_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
require_once __DIR__ . '/../app/includes/AnalyticsRepository.php';

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

    $db->prepare('INSERT INTO verticals (company_id, code, label) VALUES (?, ?, ?)')->execute([$companyId, 'SEO', 'SEO']);
    $verticalId = (int) $db->lastInsertId();

    $mkCampaign = function (string $name, ?string $sequenceId) use ($db, $companyId, $adminId): int {
        $stmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, saleshandy_sequence_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $name, $adminId, $adminId, $sequenceId]);
        return (int) $db->lastInsertId();
    };
    $campLinked = $mkCampaign('Linked Campaign', 'seq-123');
    $campUnlinked = $mkCampaign('Unlinked Campaign', null);

    $mkLead = function (string $email) use ($db, $companyId, $verticalId): int {
        $stmt = $db->prepare(
            'INSERT INTO leads (company_id, na_company_name, category, products, first_name, last_name, title, company_name_for_emails, email, industry, person_linkedin_url, website, company_linkedin_url, company_country, vertical_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, 'X Co', 'Cat', 'Prod', 'First', 'Last', 'Title', 'X Co', $email, 'Industry', 'https://linkedin.test', 'https://x.test', 'https://linkedin.test/co', 'US', $verticalId]);
        return (int) $db->lastInsertId();
    };
    $mkAssignment = function (?int $leadId, ?int $campaignId, string $status, string $waveStatus) use ($db, $adminId): void {
        if ($leadId === null || $campaignId === null) {
            return; // Lead A: no assignment row at all
        }
        $stmt = $db->prepare(
            'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, status, wave_status) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$leadId, $campaignId, $adminId, $status, $waveStatus]);
    };

    $leadA = $mkLead('a-no-campaign@x.test');
    // no assignment for Lead A

    $leadB = $mkLead('b-suppressed@x.test');
    $mkAssignment($leadB, $campLinked, 'assigned', 'suppressed');

    $leadC = $mkLead('c-held@x.test');
    $mkAssignment($leadC, $campLinked, 'assigned', 'held');

    $leadD = $mkLead('d-no-sequence@x.test');
    $mkAssignment($leadD, $campUnlinked, 'assigned', 'active');

    $leadE = $mkLead('e-queued@x.test');
    $mkAssignment($leadE, $campLinked, 'assigned', 'active');

    $leadF = $mkLead('f-pushed@x.test');
    $mkAssignment($leadF, $campLinked, 'pushed', 'active');

    $leadG = $mkLead('g-exported@x.test');
    $mkAssignment($leadG, $campLinked, 'exported', 'active');

    $adminScope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $result = AnalyticsRepository::pivotByDimension($db, $adminScope, 'vertical', []);
    $row = null;
    foreach ($result['rows'] as $r) {
        if ($r['grp'] === 'SEO') {
            $row = $r;
        }
    }
    if ($row === null) {
        $assert(false, 'The SEO vertical row exists in the results');
    } else {
        $assert($row['prospects'] === 7, "7 prospects total (got {$row['prospects']})");
        $assert($row['linked_to_campaign'] === 6, "6 leads linked to a campaign -- everyone except Lead A (got {$row['linked_to_campaign']})");
        $assert($row['imported'] === 2, "2 leads imported (F pushed, G exported) (got {$row['imported']})");
        $assert($row['not_imported'] === 5, "5 leads not imported (A-E) (got {$row['not_imported']})");
        $assert($row['not_imported_no_campaign'] === 1, "1 lead has no campaign at all (Lead A) (got {$row['not_imported_no_campaign']})");
        $assert($row['not_imported_suppressed'] === 1, "1 lead held back by domain suppression (Lead B) (got {$row['not_imported_suppressed']})");
        $assert($row['not_imported_held'] === 1, "1 lead waiting on its wave-1 leader (Lead C) (got {$row['not_imported_held']})");
        $assert($row['not_imported_no_sequence'] === 1, "1 lead's campaign isn't linked to Saleshandy yet (Lead D) (got {$row['not_imported_no_sequence']})");
        $assert($row['not_imported_queued'] === 1, "1 lead is genuinely just queued, nothing blocking it (Lead E) (got {$row['not_imported_queued']})");

        $reasonSum = $row['not_imported_no_campaign'] + $row['not_imported_suppressed'] + $row['not_imported_held']
            + $row['not_imported_no_sequence'] + $row['not_imported_queued'];
        $assert($reasonSum === $row['not_imported'], "The 5 reason buckets sum to exactly not_imported, no double-counting or gaps (got {$reasonSum} vs {$row['not_imported']})");
    }

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll not-imported-reasons checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
