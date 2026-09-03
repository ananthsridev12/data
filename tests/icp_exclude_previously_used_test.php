<?php
// IcpRepository::toFilters()'s opt-in 'assigned_campaign_id' = 'none'
// wiring (icp_segments.exclude_previously_used, sql/048) -- switches off
// cooldown-based reassignment entirely for an ICP: only a lead with NO
// assignment history at all qualifies, regardless of how cleanly or how
// long ago any prior campaign resolved. The ICP-level counterpart to
// campaign_select_leads.php's "Hide leads already used in ANY campaign"
// checkbox, reusing the exact same underlying LeadRepository filter. Off
// by default, so existing ICPs keep today's broader cooldown-based
// reassignment behavior. Rolled back at the end.
//
// Usage: php tests/icp_exclude_previously_used_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Exclude Prev Used Co', 0)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'admin@excludeprevused.test', 'admin@excludeprevused.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();
    $scope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);

    $campStmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
    $campStmt->execute([$companyId, 'Old Campaign', $adminId, $adminId]);
    $oldCampaignId = (int) $db->lastInsertId();

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

    $leadNeverAssigned = $mkLead('never@excludeprevused1.test');
    $leadResolvedPastCooldown = $mkLead('resolved-past@excludeprevused2.test');
    $insertAssignment->execute([$leadResolvedPastCooldown, $oldCampaignId, $adminId, 'active', 'delivered', 'Active', $daysAgo(365)]);

    $icpRowBase = [
        'company_country' => '', 'industry' => '', 'seniority' => '', 'employee_count' => '',
        'vertical_id' => null, 'service_id' => null, 'role_group_id' => null, 'country_group_id' => null,
    ];

    // --- Off (default): today's baseline -- resolved+cooled-down lead
    // (0-day cooldown here) is a normal reassignment candidate.
    $filtersOff = IcpRepository::toFilters($icpRowBase + ['exclude_previously_used' => 0], $scope);
    $assert($filtersOff['assigned_campaign_id'] === '', "toFilters() carries exclude_previously_used=0 as an empty assigned_campaign_id (got '{$filtersOff['assigned_campaign_id']}')");
    $matchingOff = LeadRepository::matchingIds($db, $scope, $filtersOff);
    $assert(in_array($leadNeverAssigned, $matchingOff, true), 'Off: never-assigned lead is eligible');
    $assert(in_array($leadResolvedPastCooldown, $matchingOff, true), 'Off: resolved+past-cooldown lead is STILL eligible (unchanged baseline reassignment)');

    // --- On: only the never-assigned lead qualifies, resolved-history
    // lead is excluded entirely regardless of cooldown.
    $filtersOn = IcpRepository::toFilters($icpRowBase + ['exclude_previously_used' => 1], $scope);
    $assert($filtersOn['assigned_campaign_id'] === 'none', "toFilters() carries exclude_previously_used=1 as assigned_campaign_id='none' (got '{$filtersOn['assigned_campaign_id']}')");
    $matchingOn = LeadRepository::matchingIds($db, $scope, $filtersOn);
    $assert(in_array($leadNeverAssigned, $matchingOn, true), 'On: never-assigned lead is still eligible (nothing to exclude it for)');
    $assert(!in_array($leadResolvedPastCooldown, $matchingOn, true), 'On: resolved+past-cooldown lead is EXCLUDED -- reassignment switched off entirely for this ICP');

    // --- create()/update() actually persist the column.
    $icpId = IcpRepository::create($db, $icpRowBase + ['name' => 'Exclude Prev Used ICP', 'exclude_previously_used' => true], $adminId, $companyId);
    $savedOn = (int) $db->query("SELECT exclude_previously_used FROM icp_segments WHERE id = {$icpId}")->fetchColumn();
    $assert($savedOn === 1, 'create() persists exclude_previously_used = 1');

    IcpRepository::update($db, $icpId, $icpRowBase + ['name' => 'Exclude Prev Used ICP', 'exclude_previously_used' => false], $scope);
    $savedOff = (int) $db->query("SELECT exclude_previously_used FROM icp_segments WHERE id = {$icpId}")->fetchColumn();
    $assert($savedOff === 0, 'update() persists exclude_previously_used = 0');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll ICP exclude_previously_used checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
