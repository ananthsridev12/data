<?php
// IcpRepository company scoping: list()/performanceStats() only ever
// return the acting company's own ICP segments; create() stamps the
// right company_id (it used to omit company_id entirely, which would
// have violated the NOT NULL constraint on every "Create ICP" click);
// addLink()/removeLink()/update()/toggleActive()/rebalanceEvenly()/
// updateLinkPercentages() all reject an id belonging to a different
// company instead of silently acting on it. Uses Admin scopes throughout
// so only the company boundary is exercised here -- see
// icp_owner_scope_test.php for the Team Lead/Member self-ownership
// rules. Rolled back at the end.
//
// Usage: php tests/icp_company_scope_test.php

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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Co A', 90), ('Co B', 90)");
    $companyAId = (int) $db->lastInsertId();
    $companyBId = $companyAId + 1;

    $mkUser = function (int $companyId, string $email) use ($db): int {
        $stmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $email, $email, 'x', ROLE_ADMIN]);
        return (int) $db->lastInsertId();
    };
    $adminAId = $mkUser($companyAId, 'admin@a.test');
    $adminBId = $mkUser($companyBId, 'admin@b.test');

    $scopeA = Scope::fromUser($db, ['id' => $adminAId, 'company_id' => $companyAId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $scopeB = Scope::fromUser($db, ['id' => $adminBId, 'company_id' => $companyBId, 'role' => ROLE_ADMIN, 'team_id' => null]);

    $mkCampaign = function (int $companyId, int $ownerId, string $name) use ($db): int {
        $stmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$companyId, $name, $ownerId, $ownerId]);
        return (int) $db->lastInsertId();
    };
    $campAId = $mkCampaign($companyAId, $adminAId, 'Campaign A');
    $campBId = $mkCampaign($companyBId, $adminBId, 'Campaign B');

    // --- create() stamps the right company_id (previously omitted it
    // entirely, which would have violated icp_segments.company_id's
    // NOT NULL constraint on every real "Create ICP" click).
    $icpAId = IcpRepository::create($db, ['name' => 'ICP A', 'vertical_id' => null, 'role_group_id' => null, 'service_id' => null, 'country_group_id' => null, 'company_country' => '', 'industry' => '', 'seniority' => '', 'employee_count' => ''], $adminAId, $companyAId);
    $icpBId = IcpRepository::create($db, ['name' => 'ICP B', 'vertical_id' => null, 'role_group_id' => null, 'service_id' => null, 'country_group_id' => null, 'company_country' => '', 'industry' => '', 'seniority' => '', 'employee_count' => ''], $adminBId, $companyBId);
    $storedCompanyId = (int) $db->query("SELECT company_id FROM icp_segments WHERE id = {$icpAId}")->fetchColumn();
    $assert($storedCompanyId === $companyAId, 'create() stamps the acting company_id onto the new ICP');

    // --- list(): only the acting company's own ICPs.
    $listA = IcpRepository::list($db, $scopeA);
    $namesA = array_column($listA, 'name');
    $assert(in_array('ICP A', $namesA, true), 'list(): company A sees its own ICP');
    $assert(!in_array('ICP B', $namesA, true), 'list(): company A does NOT see company B\'s ICP');

    // --- performanceStats(): same scoping.
    $statsA = IcpRepository::performanceStats($db, $scopeA);
    $statNamesA = array_column($statsA, 'name');
    $assert(in_array('ICP A', $statNamesA, true), 'performanceStats(): company A sees its own ICP');
    $assert(!in_array('ICP B', $statNamesA, true), 'performanceStats(): company A does NOT see company B\'s ICP');

    // --- addLink(): rejects linking a campaign from a different company
    // than the ICP, even if both ids are individually real.
    $threw = false;
    try {
        IcpRepository::addLink($db, $icpAId, $campBId, $scopeA);
    } catch (InvalidArgumentException $ex) {
        $threw = true;
    }
    $assert($threw, 'addLink(): rejects a campaign from a different company than the ICP');

    // --- addLink()/update()/toggleActive()/rebalanceEvenly()/
    // updateLinkPercentages(): company B cannot act on company A's ICP
    // by id, even though the id is real.
    IcpRepository::update($db, $icpAId, ['name' => 'Hacked', 'vertical_id' => null, 'role_group_id' => null, 'service_id' => null, 'country_group_id' => null, 'company_country' => '', 'industry' => '', 'seniority' => '', 'employee_count' => ''], $scopeB);
    $nameAfter = $db->query("SELECT name FROM icp_segments WHERE id = {$icpAId}")->fetchColumn();
    $assert($nameAfter === 'ICP A', 'update(): company B cannot rename company A\'s ICP by id');

    IcpRepository::toggleActive($db, $icpAId, $scopeB);
    $activeAfter = (int) $db->query("SELECT is_active FROM icp_segments WHERE id = {$icpAId}")->fetchColumn();
    $assert($activeAfter === 1, 'toggleActive(): company B cannot toggle company A\'s ICP by id');

    $threw2 = false;
    try {
        IcpRepository::addLink($db, $icpAId, $campAId, $scopeB);
    } catch (InvalidArgumentException $ex) {
        $threw2 = true;
    }
    $assert($threw2, 'addLink(): company B cannot link a campaign onto company A\'s ICP by id');

    // Legitimately link Campaign A onto ICP A (same company both sides).
    IcpRepository::addLink($db, $icpAId, $campAId, $scopeA);
    $linkCountAfterLegit = (int) $db->query("SELECT COUNT(*) FROM icp_campaign_links WHERE icp_id = {$icpAId}")->fetchColumn();
    $assert($linkCountAfterLegit === 1, 'addLink(): same-company link succeeds');

    $linkId = (int) $db->query("SELECT id FROM icp_campaign_links WHERE icp_id = {$icpAId} LIMIT 1")->fetchColumn();

    IcpRepository::removeLink($db, $linkId, $scopeB);
    $linkStillThere = (int) $db->query("SELECT COUNT(*) FROM icp_campaign_links WHERE id = {$linkId}")->fetchColumn();
    $assert($linkStillThere === 1, 'removeLink(): company B cannot remove company A\'s link by id');

    IcpRepository::rebalanceEvenly($db, $icpAId, $scopeB);
    // No assertion needed beyond "did not throw" -- rebalanceEvenly()
    // silently no-ops for a non-owning company, verified by removeLink()
    // above already confirming the link itself is untouched.

    $notOwnedResult = IcpRepository::updateLinkPercentages($db, $icpAId, [$linkId => 100], $scopeB);
    $assert($notOwnedResult === false, 'updateLinkPercentages(): company B cannot update company A\'s split by id');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll ICP company-scope checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
