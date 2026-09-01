<?php
// IcpRepository::toFilters()'s opt-in 'avoid_repeat_service_icp_id' (icp_
// segments.avoid_repeat_service, sql/047) as applied by LeadRepository::
// buildWhere() -- makes the "N lead(s) eligible now" count itself reflect
// the same-service exclusion, not just the per-campaign check WaveAssigner
// applies at actual assignment time (see wave_assigner_avoid_repeat_
// service_test.php for that half). A lead is excluded from the count only
// if it's blocked from EVERY ONE of the ICP's currently-linked campaigns
// (same service_id as a campaign it's already been through) -- a lead
// blocked from some but not all linked campaigns still counts, since
// there's still somewhere it could actually land. Off by default, and a
// no-op while the ICP has zero links (so the setup-time audience preview
// isn't zeroed out before any campaign is linked). Rolled back at the end.
//
// Usage: php tests/icp_avoid_repeat_service_matching_test.php

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Repeat Service Match Co', 0)");
    $companyId = (int) $db->lastInsertId();

    $adminStmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $adminStmt->execute([$companyId, 'admin@repeatsvcmatch.test', 'admin@repeatsvcmatch.test', 'x', ROLE_ADMIN]);
    $adminId = (int) $db->lastInsertId();
    $scope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);

    $svcStmt = $db->prepare('INSERT INTO services (company_id, code, label) VALUES (?, ?, ?)');
    $svcStmt->execute([$companyId, 'CPQ2', 'CPQ']);
    $serviceCpq = (int) $db->lastInsertId();
    $svcStmt->execute([$companyId, 'ERP2', 'ERP']);
    $serviceErp = (int) $db->lastInsertId();

    $campStmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, service_id) VALUES (?, ?, ?, ?, ?)');
    $campStmt->execute([$companyId, 'CPQ Old (unlinked)', $adminId, $adminId, $serviceCpq]);
    $campaignCpqOld = (int) $db->lastInsertId();
    $campStmt->execute([$companyId, 'ERP Old (unlinked)', $adminId, $adminId, $serviceErp]);
    $campaignErpOld = (int) $db->lastInsertId();
    $campStmt->execute([$companyId, 'C1-CPQ (linked)', $adminId, $adminId, $serviceCpq]);
    $campaignCpqLinked1 = (int) $db->lastInsertId();
    $campStmt->execute([$companyId, 'C2-CPQ (linked)', $adminId, $adminId, $serviceCpq]);
    $campaignCpqLinked2 = (int) $db->lastInsertId();
    $campStmt->execute([$companyId, 'ERP (linked)', $adminId, $adminId, $serviceErp]);
    $campaignErpLinked = (int) $db->lastInsertId();

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

    $leadNeverAssigned = $mkLead('never@repeatsvcmatch1.test');
    $leadPreviouslyCpq = $mkLead('previously-cpq@repeatsvcmatch2.test');
    $insertAssignment->execute([$leadPreviouslyCpq, $campaignCpqOld, $adminId, 'active', 'delivered', 'Active', $daysAgo(90)]);
    $leadPreviouslyErp = $mkLead('previously-erp@repeatsvcmatch3.test');
    $insertAssignment->execute([$leadPreviouslyErp, $campaignErpOld, $adminId, 'active', 'delivered', 'Active', $daysAgo(90)]);

    $icpId = IcpRepository::create($db, [
        'name' => 'Avoid Repeat Service ICP', 'vertical_id' => null, 'role_group_id' => null, 'service_id' => null,
        'country_group_id' => null, 'company_country' => '', 'industry' => '', 'seniority' => '', 'employee_count' => '',
        'avoid_repeat_service' => true,
    ], $adminId, $companyId);

    // --- Zero links yet: the filter must be a no-op (setup-time preview
    // shouldn't be zeroed out before anything is linked).
    $icpRowNoLinks = IcpRepository::findVisible($db, $scope, $icpId);
    $assert((int) $icpRowNoLinks['link_count'] === 0, 'Fixture ICP starts with 0 links');
    $filtersNoLinks = IcpRepository::toFilters($icpRowNoLinks, $scope);
    $assert($filtersNoLinks['avoid_repeat_service_icp_id'] === null, 'toFilters(): avoid_repeat_service_icp_id is null while the ICP has zero links, even with the flag on');
    $matchingNoLinks = LeadRepository::matchingIds($db, $scope, $filtersNoLinks);
    $assert(in_array($leadPreviouslyCpq, $matchingNoLinks, true), 'With zero links, the same-service-history lead is still counted (no campaigns to compare against yet)');

    // --- Link BOTH CPQ campaigns (same service) -- a lead with prior CPQ
    // history is now blocked from every linked campaign, so it should
    // drop out of the eligible count entirely.
    IcpRepository::addLink($db, $icpId, $campaignCpqLinked1, $scope);
    IcpRepository::addLink($db, $icpId, $campaignCpqLinked2, $scope);
    $icpRowTwoCpqLinks = IcpRepository::findVisible($db, $scope, $icpId);
    $assert((int) $icpRowTwoCpqLinks['link_count'] === 2, 'Fixture ICP now has 2 links');
    $filtersTwoCpqLinks = IcpRepository::toFilters($icpRowTwoCpqLinks, $scope);
    $assert($filtersTwoCpqLinks['avoid_repeat_service_icp_id'] === $icpId, 'toFilters(): avoid_repeat_service_icp_id is set once the ICP has links and the flag is on');
    $matchingTwoCpqLinks = LeadRepository::matchingIds($db, $scope, $filtersTwoCpqLinks);
    $assert(in_array($leadNeverAssigned, $matchingTwoCpqLinks, true), 'Never-assigned lead is unaffected, still eligible');
    $assert(!in_array($leadPreviouslyCpq, $matchingTwoCpqLinks, true), 'Lead with prior CPQ history is excluded -- blocked from BOTH linked CPQ campaigns');
    $assert(in_array($leadPreviouslyErp, $matchingTwoCpqLinks, true), 'Lead with prior ERP (different service) history is still eligible -- not blocked from either linked CPQ campaign');

    // --- Add a THIRD linked campaign with a DIFFERENT service (ERP) --
    // the CPQ-history lead is now eligible again, since there's at least
    // one linked campaign (the ERP one) it isn't blocked from.
    IcpRepository::addLink($db, $icpId, $campaignErpLinked, $scope);
    $icpRowThreeLinks = IcpRepository::findVisible($db, $scope, $icpId);
    $assert((int) $icpRowThreeLinks['link_count'] === 3, 'Fixture ICP now has 3 links (2 CPQ + 1 ERP)');
    $filtersThreeLinks = IcpRepository::toFilters($icpRowThreeLinks, $scope);
    $matchingThreeLinks = LeadRepository::matchingIds($db, $scope, $filtersThreeLinks);
    $assert(in_array($leadPreviouslyCpq, $matchingThreeLinks, true), 'Once a different-service campaign is also linked, the CPQ-history lead is eligible again (at least one linked campaign it is not blocked from)');

    // --- Same 3-link ICP, but with the flag OFF: unaffected regardless
    // (today's baseline behavior).
    $icpRowFlagOff = $icpRowThreeLinks;
    $icpRowFlagOff['avoid_repeat_service'] = 0;
    $filtersFlagOff = IcpRepository::toFilters($icpRowFlagOff, $scope);
    $assert($filtersFlagOff['avoid_repeat_service_icp_id'] === null, 'toFilters(): avoid_repeat_service_icp_id is null when the flag itself is off');
    $matchingFlagOff = LeadRepository::matchingIds($db, $scope, $filtersFlagOff);
    $assert(in_array($leadPreviouslyCpq, $matchingFlagOff, true), 'Flag off: the CPQ-history lead is eligible regardless of linked campaigns (unchanged baseline)');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll ICP avoid_repeat_service matching (eligible-count) checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
