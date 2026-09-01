<?php
// WaveAssigner::filterEligibleForCampaign()/assign()'s opt-in
// $avoidRepeatService parameter (icp_segments.avoid_repeat_service, see
// sql/047_icp_avoid_repeat_service.sql) -- when true, a lead with prior
// assignment history is excluded from a target campaign whose service_id
// matches the service_id of any campaign that lead has already been
// assigned to, regardless of cooldown/resolved status. Off (the default)
// preserves today's behavior exactly. A brand-new lead with no prior
// assignment history at all is never affected either way. Rolled back at
// the end.
//
// Usage: php tests/wave_assigner_avoid_repeat_service_test.php

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Repeat Service Co', 0)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'admin@repeatsvc.test', 'admin@repeatsvc.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();

    $svcStmt = $db->prepare('INSERT INTO services (company_id, code, label) VALUES (?, ?, ?)');
    $svcStmt->execute([$companyId, 'CPQ', 'CPQ']);
    $serviceCpq = (int) $db->lastInsertId();
    $svcStmt->execute([$companyId, 'ERP', 'ERP']);
    $serviceErp = (int) $db->lastInsertId();

    $campStmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, service_id) VALUES (?, ?, ?, ?, ?)');
    $campStmt->execute([$companyId, 'C1-CPQ', $adminId, $adminId, $serviceCpq]);
    $campaignCpq1 = (int) $db->lastInsertId();
    $campStmt->execute([$companyId, 'C2-CPQ', $adminId, $adminId, $serviceCpq]);
    $campaignCpq2 = (int) $db->lastInsertId();
    $campStmt->execute([$companyId, 'ERP-Campaign', $adminId, $adminId, $serviceErp]);
    $campaignErp = (int) $db->lastInsertId();
    $campStmt2 = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
    $campStmt2->execute([$companyId, 'No-Service-Campaign', $adminId, $adminId]);
    $campaignNoService = (int) $db->lastInsertId();

    $mkLead = function (string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, 'Acme', 'F', 'L', $email]);
        return (int) $db->lastInsertId();
    };
    $insertAssignment = $db->prepare(
        'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, wave_status, bounce_status, delivery_status, assigned_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $daysAgo = static fn (int $days): string => (new DateTime("-{$days} days"))->format('Y-m-d H:i:s');

    // Went through C1-CPQ (service CPQ), resolved long ago.
    $leadPreviouslyCpq = $mkLead('previously-cpq@acme1.test');
    $insertAssignment->execute([$leadPreviouslyCpq, $campaignCpq1, $adminId, 'active', 'delivered', 'Active', $daysAgo(90)]);

    // Went through ERP-Campaign (service ERP), resolved long ago.
    $leadPreviouslyErp = $mkLead('previously-erp@acme2.test');
    $insertAssignment->execute([$leadPreviouslyErp, $campaignErp, $adminId, 'active', 'delivered', 'Active', $daysAgo(90)]);

    // Never assigned to anything before.
    $leadFresh = $mkLead('fresh@acme3.test');

    $allLeadIds = [$leadPreviouslyCpq, $leadPreviouslyErp, $leadFresh];

    // --- avoidRepeatService = false (default): today's behavior, nothing new excluded.
    $filteredOff = WaveAssigner::filterEligibleForCampaign($db, $allLeadIds, $campaignCpq2, 0);
    $assert(in_array($leadPreviouslyCpq, $filteredOff['eligible'], true), 'Off: a lead previously in another same-service campaign is still eligible (today\'s unchanged default behavior)');
    $assert($filteredOff['same_service_skipped_count'] === 0, 'Off: same_service_skipped_count is 0 when the flag is off (got ' . $filteredOff['same_service_skipped_count'] . ')');

    // --- avoidRepeatService = true, target campaign C2-CPQ (same service as C1-CPQ).
    $filteredOn = WaveAssigner::filterEligibleForCampaign($db, $allLeadIds, $campaignCpq2, 0, true);
    $assert(!in_array($leadPreviouslyCpq, $filteredOn['eligible'], true), 'On: a lead previously in a same-service (CPQ) campaign is excluded from another CPQ campaign');
    $assert(in_array($leadPreviouslyErp, $filteredOn['eligible'], true), 'On: a lead previously in a DIFFERENT-service (ERP) campaign is still eligible for a CPQ campaign');
    $assert(in_array($leadFresh, $filteredOn['eligible'], true), 'On: a brand-new lead with no prior history is unaffected');
    $assert($filteredOn['same_service_skipped_count'] === 1, 'On: exactly 1 lead skipped for same-service (got ' . $filteredOn['same_service_skipped_count'] . ')');

    // --- Target campaign has no service_id set at all -- the check is a no-op (no service to compare against).
    $filteredNoServiceTarget = WaveAssigner::filterEligibleForCampaign($db, $allLeadIds, $campaignNoService, 0, true);
    $assert(in_array($leadPreviouslyCpq, $filteredNoServiceTarget['eligible'], true), 'On, but target campaign has no service_id: nobody is excluded on service grounds');
    $assert($filteredNoServiceTarget['same_service_skipped_count'] === 0, 'On, but target campaign has no service_id: same_service_skipped_count is 0 (got ' . $filteredNoServiceTarget['same_service_skipped_count'] . ')');

    // --- assign(): same exclusion actually applies end-to-end, and the excluded lead gets no new row in campaign C2-CPQ.
    $stats = WaveAssigner::assign($db, $allLeadIds, $campaignCpq2, $adminId, [], 0, null, true);
    $assert($stats['same_service_skipped'] === 1, 'assign() reports same_service_skipped matching filterEligibleForCampaign() (got ' . $stats['same_service_skipped'] . ')');

    $blockedStmt = $db->prepare('SELECT id FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id = ?');
    $blockedStmt->execute([$leadPreviouslyCpq, $campaignCpq2]);
    $assert(!$blockedStmt->fetchColumn(), 'The same-service-blocked lead did NOT get a new assignment row in campaign C2-CPQ');

    $allowedStmt = $db->prepare('SELECT id FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id = ?');
    $allowedStmt->execute([$leadPreviouslyErp, $campaignCpq2]);
    $assert((bool) $allowedStmt->fetchColumn(), 'The different-service lead DID get a new assignment row in campaign C2-CPQ');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll WaveAssigner avoid_repeat_service checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
