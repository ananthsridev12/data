<?php
// FollowUpTaskRepository::generateForCampaign() -- creates a task once a
// lead crosses a campaign's own open/click/positive-reply thresholds,
// bumps (not duplicates) an existing open task when a new signal fires,
// and starts a fresh task once the previous one is already done/skipped.
// Also checks loadVisible()'s row-scoping matches CampaignAccess's rule.
//
// Usage: php tests/follow_up_task_generation_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/Scope.php';
require_once __DIR__ . '/../app/includes/FollowUpTaskRepository.php';

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

    $db->exec("INSERT INTO teams (company_id, name) VALUES ({$companyId}, 'Team ABM')");
    $teamId = (int) $db->lastInsertId();

    $mkUser = function (string $role, ?int $teamId, string $email) use ($db, $companyId): int {
        $stmt = $db->prepare('INSERT INTO users (company_id, team_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $teamId, $email, $email, 'x', $role]);
        return (int) $db->lastInsertId();
    };
    $ownerId = $mkUser(ROLE_MEMBER, $teamId, 'owner@a.test');
    $teamLeadId = $mkUser(ROLE_TEAM_LEAD, $teamId, 'lead@a.test');
    $outsiderId = $mkUser(ROLE_MEMBER, null, 'outsider@a.test');

    $stmt = $db->prepare(
        'INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, followup_open_threshold, followup_click_threshold, followup_on_positive_reply)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$companyId, 'Campaign A', $ownerId, $ownerId, 3, 2, 1]);
    $campaignId = (int) $db->lastInsertId();

    $stmt = $db->prepare('INSERT INTO leads (company_id, owner_id, na_company_name, first_name, last_name, email) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$companyId, $ownerId, 'Acme Co', 'Bob', 'Lewis', 'bob.lewis@acme.test']);
    $leadId = (int) $db->lastInsertId();

    $stmt = $db->prepare(
        'INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, open_count, click_count, reply_sentiment) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$leadId, $campaignId, $ownerId, 2, 0, null]);
    $assignmentId = (int) $db->lastInsertId();

    $campStmt = $db->prepare('SELECT * FROM campaigns WHERE id = ?');
    $reload = static function () use ($campStmt, $campaignId): array {
        $campStmt->execute([$campaignId]);
        return $campStmt->fetch();
    };

    // --- Below every threshold: no task yet.
    $created = FollowUpTaskRepository::generateForCampaign($db, $reload());
    $assert($created === 0, 'No task created while below every threshold');
    $countStmt = $db->prepare('SELECT COUNT(*) FROM follow_up_tasks WHERE campaign_id = ?');
    $countStmt->execute([$campaignId]);
    $assert((int) $countStmt->fetchColumn() === 0, 'follow_up_tasks stays empty while below every threshold');

    // --- Crosses the open-count threshold: one task created, assigned to the campaign owner.
    $db->prepare('UPDATE lead_campaign_assignments SET open_count = 3 WHERE id = ?')->execute([$assignmentId]);
    $created = FollowUpTaskRepository::generateForCampaign($db, $reload());
    $assert($created === 1, 'Crossing the open-count threshold creates exactly one task');
    $taskStmt = $db->prepare('SELECT * FROM follow_up_tasks WHERE lead_id = ? AND campaign_id = ?');
    $taskStmt->execute([$leadId, $campaignId]);
    $task = $taskStmt->fetch();
    $assert($task !== false, 'The created task is findable by lead+campaign');
    $assert((int) $task['assigned_to'] === $ownerId, 'Task is assigned to the campaign owner');
    $assert((int) $task['flag_opens'] === 1, 'Task is flagged for the opens signal');
    $assert((int) $task['flag_clicks'] === 0 && (int) $task['flag_reply'] === 0, 'Task is NOT yet flagged for clicks/reply');
    $assert($task['status'] === 'pending', 'New task starts as pending');
    $assert($task['reengaged_at'] === null, 'New task has no reengaged_at yet');
    $firstTaskId = (int) $task['id'];

    // --- Re-running with no new engagement: idempotent, no duplicate, no bump.
    $created = FollowUpTaskRepository::generateForCampaign($db, $reload());
    $assert($created === 0, 'Re-running with unchanged engagement creates/bumps nothing');
    $countStmt->execute([$campaignId]);
    $assert((int) $countStmt->fetchColumn() === 1, 'Still exactly one task after re-running unchanged');

    // --- Crosses the click threshold too: bumps the SAME task (re-engaged), not a second one.
    $db->prepare('UPDATE lead_campaign_assignments SET click_count = 2 WHERE id = ?')->execute([$assignmentId]);
    $created = FollowUpTaskRepository::generateForCampaign($db, $reload());
    $assert($created === 1, 'A newly-crossed signal on an existing task counts as one bump');
    $countStmt->execute([$campaignId]);
    $assert((int) $countStmt->fetchColumn() === 1, 'Still exactly one task -- clicks bumped the existing one, no duplicate');
    $taskStmt->execute([$leadId, $campaignId]);
    $task = $taskStmt->fetch();
    $assert((int) $task['id'] === $firstTaskId, 'The bumped row is the same task, same id');
    $assert((int) $task['flag_clicks'] === 1, 'Task is now also flagged for clicks');
    $assert($task['reengaged_at'] !== null, 'reengaged_at is set once a new signal fires on an existing task');

    // --- A positive reply also bumps the same task.
    $db->prepare("UPDATE lead_campaign_assignments SET reply_sentiment = 'Positive' WHERE id = ?")->execute([$assignmentId]);
    FollowUpTaskRepository::generateForCampaign($db, $reload());
    $taskStmt->execute([$leadId, $campaignId]);
    $task = $taskStmt->fetch();
    $assert((int) $task['flag_reply'] === 1, 'Task is now also flagged for the positive reply');

    // --- Once the task is done, further engagement starts a FRESH task instead of reopening it.
    FollowUpTaskRepository::setStatus($db, Scope::fromUser($db, ['id' => $ownerId, 'company_id' => $companyId, 'role' => ROLE_MEMBER, 'team_id' => $teamId]), $firstTaskId, 'done', $ownerId);
    $db->prepare('UPDATE lead_campaign_assignments SET open_count = 5 WHERE id = ?')->execute([$assignmentId]);
    // Reset reply_sentiment away from Positive so only the (already-satisfied) open threshold is newly re-checked --
    // otherwise this assertion can't tell "found existing done task, ignored it" apart from "reply flag stayed off".
    $created = FollowUpTaskRepository::generateForCampaign($db, $reload());
    $assert($created === 1, 'Engagement after the prior task is done creates a new task, not a reopen');
    $countStmt->execute([$campaignId]);
    $assert((int) $countStmt->fetchColumn() === 2, 'Two tasks now exist: the done one and the fresh one');

    // --- No rules configured on a campaign: generateForCampaign() is a no-op, not an error.
    $stmt = $db->prepare('INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$companyId, 'No Rules Campaign', $ownerId, $ownerId]);
    $noRulesCampaignId = (int) $db->lastInsertId();
    $stmt = $db->prepare('INSERT INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by, open_count, click_count) VALUES (?, ?, ?, 999, 999)');
    $stmt->execute([$leadId, $noRulesCampaignId, $ownerId]);
    $noRulesCampStmt = $db->prepare('SELECT * FROM campaigns WHERE id = ?');
    $noRulesCampStmt->execute([$noRulesCampaignId]);
    $created = FollowUpTaskRepository::generateForCampaign($db, $noRulesCampStmt->fetch());
    $assert($created === 0, 'A campaign with no thresholds configured never creates a task, even with huge counts');

    // --- Visibility (loadVisible()): same rule as CampaignAccess -- Team Lead sees a teammate's
    // task, an outsider (different team, no team) does not.
    $teamLeadScope = Scope::fromUser($db, ['id' => $teamLeadId, 'company_id' => $companyId, 'role' => ROLE_TEAM_LEAD, 'team_id' => $teamId]);
    $outsiderScope = Scope::fromUser($db, ['id' => $outsiderId, 'company_id' => $companyId, 'role' => ROLE_MEMBER, 'team_id' => null]);
    $assert(FollowUpTaskRepository::loadVisible($db, $teamLeadScope, $firstTaskId) !== null, 'Team Lead can view a task assigned to their teammate');
    $assert(FollowUpTaskRepository::loadVisible($db, $outsiderScope, $firstTaskId) === null, 'A member outside the team cannot view/mutate that task');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll follow-up task generation checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
