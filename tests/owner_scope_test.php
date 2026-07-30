<?php
// Role-based row visibility within a single company (see Scope::visibleOwnerIds()
// / ScopeFilter::applyOwnerScope()): Admin sees every lead in the company,
// Team Lead sees their whole team's owned leads pooled together, Member
// sees only their own -- strict, with NO fallback for unowned/other-owner
// leads (confirmed decision, not a bug). Seeds one company with two teams
// and an unowned lead, runs LeadRepository through each role, and asserts
// the exact visible set. Rolled back at the end.
//
// Usage: php tests/owner_scope_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
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
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Test Co', 90)");
    $companyId = (int) $db->lastInsertId();

    $db->exec("INSERT INTO teams (company_id, name) VALUES ({$companyId}, 'Team ABM')");
    $teamAbmId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO teams (company_id, name) VALUES ({$companyId}, 'Team Other')");
    $teamOtherId = (int) $db->lastInsertId();

    $mkUser = function (string $role, ?int $teamId, string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO users (company_id, team_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $teamId, $email, $email, 'x', $role]);
        return (int) $db->lastInsertId();
    };

    $adminId = $mkUser(ROLE_ADMIN, null, 'admin@testco.test');
    $teamLeadId = $mkUser(ROLE_TEAM_LEAD, $teamAbmId, 'lead@testco.test');
    $memberOnAbmId = $mkUser(ROLE_MEMBER, $teamAbmId, 'member-abm@testco.test');
    $memberOtherTeamId = $mkUser(ROLE_MEMBER, $teamOtherId, 'member-other@testco.test');
    $memberNoTeamId = $mkUser(ROLE_MEMBER, null, 'member-noteam@testco.test');

    $mkLead = function (?int $ownerId, string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO leads (company_id, na_company_name, first_name, last_name, email, owner_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, 'Co', 'F', 'L', $email, $ownerId]);
        return (int) $db->lastInsertId();
    };

    $leadOwnedByTeamLead = $mkLead($teamLeadId, 'owned-by-lead@x.test');
    $leadOwnedByAbmMember = $mkLead($memberOnAbmId, 'owned-by-abm-member@x.test');
    $leadOwnedByOtherTeamMember = $mkLead($memberOtherTeamId, 'owned-by-other-team@x.test');
    $leadUnowned = $mkLead(null, 'unowned@x.test');

    $adminScope = Scope::fromUser($db, ['id' => $adminId, 'company_id' => $companyId, 'role' => ROLE_ADMIN, 'team_id' => null]);
    $teamLeadScope = Scope::fromUser($db, ['id' => $teamLeadId, 'company_id' => $companyId, 'role' => ROLE_TEAM_LEAD, 'team_id' => $teamAbmId]);
    $memberScope = Scope::fromUser($db, ['id' => $memberOnAbmId, 'company_id' => $companyId, 'role' => ROLE_MEMBER, 'team_id' => $teamAbmId]);
    $memberNoTeamScope = Scope::fromUser($db, ['id' => $memberNoTeamId, 'company_id' => $companyId, 'role' => ROLE_MEMBER, 'team_id' => null]);

    // --- Admin: sees everything, including the unowned lead.
    $adminIds = LeadRepository::matchingIds($db, $adminScope, []);
    $assert(
        in_array($leadOwnedByTeamLead, $adminIds, true) && in_array($leadOwnedByAbmMember, $adminIds, true)
        && in_array($leadOwnedByOtherTeamMember, $adminIds, true) && in_array($leadUnowned, $adminIds, true),
        'Admin sees every lead in the company, including the unowned one'
    );

    // --- Team Lead (Team ABM): sees their own + their ABM teammate's leads,
    // pooled -- NOT the other team's lead, NOT the unowned lead (strict).
    $teamLeadIds = LeadRepository::matchingIds($db, $teamLeadScope, []);
    $assert(in_array($leadOwnedByTeamLead, $teamLeadIds, true), 'Team Lead sees their own owned lead');
    $assert(in_array($leadOwnedByAbmMember, $teamLeadIds, true), 'Team Lead sees their ABM teammate\'s owned lead (pooled)');
    $assert(!in_array($leadOwnedByOtherTeamMember, $teamLeadIds, true), 'Team Lead does NOT see the other team\'s lead');
    $assert(!in_array($leadUnowned, $teamLeadIds, true), 'Team Lead does NOT see the unowned lead (strict, no fallback)');

    // --- Member (Team ABM): sees ONLY their own, not even their team lead's.
    $memberIds = LeadRepository::matchingIds($db, $memberScope, []);
    $assert($memberIds === [$leadOwnedByAbmMember], 'Member sees exactly their own lead and nothing else (' . json_encode($memberIds) . ' seen)');

    // --- Member with no team: still just their own (none in this fixture).
    $memberNoTeamIds = LeadRepository::matchingIds($db, $memberNoTeamScope, []);
    $assert($memberNoTeamIds === [], 'Member with no team and no owned leads sees nothing (not an error, not everything)');

    // --- findByIds(): Member cannot fetch a teammate's lead id by guessing it directly.
    $found = LeadRepository::findByIds($db, $memberScope, [$leadOwnedByTeamLead, $leadOwnedByOtherTeamMember]);
    $assert($found === [], 'findByIds(): Member cannot fetch leads outside their own ownership by id');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll owner-scope checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
