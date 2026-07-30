<?php
// CampaignAccess::loadVisible()/canMutate() -- Admin sees/manages every
// campaign in the company; Team Lead can VIEW their team's pooled
// campaigns but only MUTATE ones they personally own; Member can
// view/mutate only campaigns they personally own. Cross-company access
// is indistinguishable from "not found". Rolled back at the end.
//
// Usage: php tests/campaign_access_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
require_once __DIR__ . '/../app/includes/CampaignAccess.php';

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

    $db->exec("INSERT INTO teams (company_id, name) VALUES ({$companyAId}, 'Team ABM')");
    $teamId = (int) $db->lastInsertId();

    $mkUser = function (int $companyId, string $role, ?int $teamId, string $email) use ($db): int {
        $stmt = $db->prepare('INSERT INTO users (company_id, team_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $teamId, $email, $email, 'x', $role]);
        return (int) $db->lastInsertId();
    };
    $adminId = $mkUser($companyAId, ROLE_ADMIN, null, 'admin@a.test');
    $teamLeadId = $mkUser($companyAId, ROLE_TEAM_LEAD, $teamId, 'lead@a.test');
    $memberOnTeamId = $mkUser($companyAId, ROLE_MEMBER, $teamId, 'member-team@a.test');
    $memberSoloId = $mkUser($companyAId, ROLE_MEMBER, null, 'member-solo@a.test');
    $otherCompanyAdminId = $mkUser($companyBId, ROLE_ADMIN, null, 'admin@b.test');

    $mkCampaign = function (int $companyId, int $ownerId, string $name) use ($db): int {
        $stmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$companyId, $name, $ownerId, $ownerId]);
        return (int) $db->lastInsertId();
    };
    $campOwnedByTeamMember = $mkCampaign($companyAId, $memberOnTeamId, 'Team Member Campaign');
    $campOwnedBySolo = $mkCampaign($companyAId, $memberSoloId, 'Solo Campaign');
    $campOwnedByOtherCompany = $mkCampaign($companyBId, $otherCompanyAdminId, 'Other Co Campaign');

    $adminScope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyAId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $teamLeadScope = Scope::fromUser($db, ['id' => $teamLeadId, 'company_id' => $companyAId, 'role' => ROLE_TEAM_LEAD, 'team_id' => $teamId]);
    $memberOnTeamScope = Scope::fromUser($db, ['id' => $memberOnTeamId, 'company_id' => $companyAId, 'role' => ROLE_MEMBER, 'team_id' => $teamId]);
    $memberSoloScope = Scope::fromUser($db, ['id' => $memberSoloId, 'company_id' => $companyAId, 'role' => ROLE_MEMBER, 'team_id' => null]);

    // --- Admin: sees and can mutate every campaign in the company.
    $c = CampaignAccess::loadVisible($db, $adminScope, $campOwnedBySolo);
    $assert($c !== null, 'Admin can load a campaign they don\'t personally own');
    $assert(CampaignAccess::canMutate($adminScope, $c), 'Admin can mutate a campaign they don\'t personally own');

    // --- Admin cannot even see a campaign in a different company.
    $assert(CampaignAccess::loadVisible($db, $adminScope, $campOwnedByOtherCompany) === null, 'Admin cannot load a campaign from a different company');

    // --- Team Lead: can VIEW their teammate's campaign, but NOT mutate it.
    $c = CampaignAccess::loadVisible($db, $teamLeadScope, $campOwnedByTeamMember);
    $assert($c !== null, 'Team Lead can view their team member\'s campaign');
    $assert(!CampaignAccess::canMutate($teamLeadScope, $c), 'Team Lead cannot mutate their team member\'s campaign');

    // --- Team Lead cannot see a campaign owned by someone outside their team.
    $assert(CampaignAccess::loadVisible($db, $teamLeadScope, $campOwnedBySolo) === null, 'Team Lead cannot view a campaign owned outside their team');

    // --- Member (on team): can view/mutate only their own campaign, not their team lead's or teammate's.
    $c = CampaignAccess::loadVisible($db, $memberOnTeamScope, $campOwnedByTeamMember);
    $assert($c !== null && CampaignAccess::canMutate($memberOnTeamScope, $c), 'Member can view and mutate their own campaign');
    $assert(CampaignAccess::loadVisible($db, $memberOnTeamScope, $campOwnedBySolo) === null, 'Member cannot view a campaign owned by someone outside their team');

    // --- Member with no team: only their own.
    $c = CampaignAccess::loadVisible($db, $memberSoloScope, $campOwnedBySolo);
    $assert($c !== null && CampaignAccess::canMutate($memberSoloScope, $c), 'Solo member can view and mutate their own campaign');
    $assert(CampaignAccess::loadVisible($db, $memberSoloScope, $campOwnedByTeamMember) === null, 'Solo member cannot view another member\'s campaign');

    // --- Guessing a nonexistent id behaves identically to guessing a real-but-invisible one.
    $assert(CampaignAccess::loadVisible($db, $memberSoloScope, 999999) === null, 'Nonexistent campaign id returns null, same as an invisible one');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll campaign-access checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
