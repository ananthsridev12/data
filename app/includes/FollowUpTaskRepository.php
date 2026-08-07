<?php

require_once __DIR__ . '/ScopeFilter.php';

/**
 * Auto-generates "go follow up manually" tasks (a LinkedIn connection
 * request) from a campaign's own engagement thresholds -- opens, clicks,
 * and/or a positive reply -- and provides the scoped listing/status-change
 * methods behind public/tasks.php.
 *
 * Status flow: pending -> in_progress (optional, "I've started working
 * this") -> connection_sent (the LinkedIn request went out -- either
 * auto-marked when the task's Profile link is clicked, see
 * markConnectionSentIfEarlier(), or via the manual "Mark connection sent"
 * button for when that automatic tracking doesn't fire) ->
 * connection_accepted (terminal). skipped is reachable from any non-
 * terminal state, for opting out instead of following up.
 *
 * One open task per lead+campaign: "open" means anything before the two
 * terminal states (connection_accepted/skipped) -- a lead that keeps
 * engaging while a task is still open gets that task's flags updated and
 * `reengaged_at` bumped instead of a second task (enforced in
 * generateForCampaign() in application code, not a DB constraint, since
 * MySQL/MariaDB has no partial-unique-index support to express "unique
 * while not yet terminal").
 */
class FollowUpTaskRepository
{
    private const OPEN_STATUSES = ['pending', 'in_progress', 'connection_sent'];
    private const TERMINAL_STATUSES = ['connection_accepted', 'skipped'];
    private const ALL_STATUSES = ['pending', 'in_progress', 'connection_sent', 'connection_accepted', 'skipped'];

    /**
     * Evaluates campaign.followup_open_threshold/followup_click_threshold/
     * followup_on_positive_reply against every assignment on this campaign
     * and creates/bumps tasks for anyone newly qualifying. Called from
     * SaleshandyClient::syncCampaign()/backfillHistoricalDates(), same
     * "runs at the end of every sync" placement as releaseResolvedWaveLeaders().
     *
     * @param array<string,mixed> $campaign a row from `campaigns`
     * @return int how many tasks were created or bumped (re-engaged)
     */
    public static function generateForCampaign(PDO $db, array $campaign): int
    {
        $openThreshold = $campaign['followup_open_threshold'] !== null ? (int) $campaign['followup_open_threshold'] : null;
        $clickThreshold = $campaign['followup_click_threshold'] !== null ? (int) $campaign['followup_click_threshold'] : null;
        $onPositiveReply = (bool) $campaign['followup_on_positive_reply'];
        if ($openThreshold === null && $clickThreshold === null && !$onPositiveReply) {
            return 0; // no rules configured for this campaign -- cheap early exit
        }

        $conditions = [];
        $params = ['campaign_id' => $campaign['id']];
        if ($openThreshold !== null) {
            $conditions[] = 'a.open_count >= :open_threshold';
            $params['open_threshold'] = $openThreshold;
        }
        if ($clickThreshold !== null) {
            $conditions[] = 'a.click_count >= :click_threshold';
            $params['click_threshold'] = $clickThreshold;
        }
        if ($onPositiveReply) {
            $conditions[] = "a.reply_sentiment = 'Positive'";
        }

        $stmt = $db->prepare(
            "SELECT a.id AS assignment_id, a.lead_id, a.open_count, a.click_count, a.reply_sentiment
               FROM lead_campaign_assignments a
              WHERE a.campaign_id = :campaign_id AND (" . implode(' OR ', $conditions) . ')'
        );
        $stmt->execute($params);
        $qualifying = $stmt->fetchAll();
        if (!$qualifying) {
            return 0;
        }

        $openStatusPlaceholders = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
        $findExisting = $db->prepare(
            "SELECT id, flag_opens, flag_clicks, flag_reply FROM follow_up_tasks
              WHERE lead_id = ? AND campaign_id = ? AND status IN ({$openStatusPlaceholders})
              LIMIT 1"
        );
        $insert = $db->prepare(
            'INSERT INTO follow_up_tasks (company_id, lead_id, campaign_id, assignment_id, assigned_to, flag_opens, flag_clicks, flag_reply)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $bump = $db->prepare(
            'UPDATE follow_up_tasks SET flag_opens = flag_opens OR ?, flag_clicks = flag_clicks OR ?, flag_reply = flag_reply OR ?, reengaged_at = NOW() WHERE id = ?'
        );

        $touched = 0;
        foreach ($qualifying as $row) {
            $flagOpens = $openThreshold !== null && (int) $row['open_count'] >= $openThreshold;
            $flagClicks = $clickThreshold !== null && (int) $row['click_count'] >= $clickThreshold;
            $flagReply = $onPositiveReply && $row['reply_sentiment'] === 'Positive';

            $findExisting->execute(array_merge([$row['lead_id'], $campaign['id']], self::OPEN_STATUSES));
            $existing = $findExisting->fetch();

            if ($existing) {
                $newOpens = $flagOpens && !$existing['flag_opens'];
                $newClicks = $flagClicks && !$existing['flag_clicks'];
                $newReply = $flagReply && !$existing['flag_reply'];
                if ($newOpens || $newClicks || $newReply) {
                    $bump->execute([$flagOpens ? 1 : 0, $flagClicks ? 1 : 0, $flagReply ? 1 : 0, $existing['id']]);
                    $touched++;
                }
                continue;
            }

            $insert->execute([
                $campaign['company_id'],
                $row['lead_id'],
                $campaign['id'],
                $row['assignment_id'],
                $campaign['saleshandy_account_owner_id'],
                $flagOpens ? 1 : 0,
                $flagClicks ? 1 : 0,
                $flagReply ? 1 : 0,
            ]);
            $touched++;
        }

        return $touched;
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public static function listForUser(PDO $db, Scope $scope, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::buildWhere($scope, $db, $filters);

        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM follow_up_tasks t
               JOIN leads l ON l.id = t.lead_id
              WHERE {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT t.*, l.na_company_name, l.first_name, l.last_name, l.email, l.person_linkedin_url,
                    c.name AS campaign_name, u.name AS assigned_to_name
               FROM follow_up_tasks t
               JOIN leads l ON l.id = t.lead_id
               JOIN campaigns c ON c.id = t.campaign_id
               LEFT JOIN users u ON u.id = t.assigned_to
              WHERE {$where}
              ORDER BY COALESCE(t.reengaged_at, t.created_at) DESC
              LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    public static function matchingCount(PDO $db, Scope $scope, array $filters): int
    {
        [$where, $params] = self::buildWhere($scope, $db, $filters);
        $stmt = $db->prepare("SELECT COUNT(*) FROM follow_up_tasks t JOIN leads l ON l.id = t.lead_id WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function buildWhere(Scope $scope, PDO $db, array $filters): array
    {
        $clauses = [];
        $params = [];
        ScopeFilter::apply($clauses, $params, $scope, 't');
        ScopeFilter::applyOwnerScope($clauses, $params, $scope, $db, 't', 'assigned_to');

        if (!empty($filters['status']) && in_array($filters['status'], self::ALL_STATUSES, true)) {
            $clauses[] = 't.status = :status';
            $params['status'] = $filters['status'];
        } elseif (empty($filters['status'])) {
            // Default view: hide the terminal statuses so the list is "what's left to do".
            $openPlaceholders = [];
            foreach (self::OPEN_STATUSES as $i => $s) {
                $key = "default_open_status_{$i}";
                $openPlaceholders[] = ":{$key}";
                $params[$key] = $s;
            }
            $clauses[] = 't.status IN (' . implode(',', $openPlaceholders) . ')';
        }
        if (!empty($filters['campaign_id'])) {
            $clauses[] = 't.campaign_id = :campaign_id';
            $params['campaign_id'] = (int) $filters['campaign_id'];
        }
        if (!empty($filters['signal']) && in_array($filters['signal'], ['opens', 'clicks', 'reply'], true)) {
            $clauses[] = "t.flag_{$filters['signal']} = 1";
        }
        if (!empty($filters['assigned_to'])) {
            $clauses[] = 't.assigned_to = :assigned_to';
            $params['assigned_to'] = (int) $filters['assigned_to'];
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Loads a task scoped to the acting company and enforces row-level
     * visibility, same pattern as CampaignAccess::loadVisible() --
     * Admin sees every task in the company; Team Lead/Member only tasks
     * assigned to someone in their visibleOwnerIds() set. A task outside
     * that set is treated identically to "not found", so a guessed id
     * can never be used to read or mutate someone else's task.
     */
    public static function loadVisible(PDO $db, Scope $scope, int $taskId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM follow_up_tasks WHERE id = ? AND company_id = ?');
        $stmt->execute([$taskId, $scope->companyId]);
        $task = $stmt->fetch();
        if (!$task) {
            return null;
        }
        if ($scope->isAdmin()) {
            return $task;
        }
        $visibleOwnerIds = $scope->visibleOwnerIds($db);
        if ($visibleOwnerIds !== null && !in_array((int) $task['assigned_to'], $visibleOwnerIds, true)) {
            return null;
        }
        return $task;
    }

    public static function setStatus(PDO $db, Scope $scope, int $taskId, string $status, int $userId): bool
    {
        if (!in_array($status, self::ALL_STATUSES, true)) {
            return false;
        }
        $isTerminal = in_array($status, self::TERMINAL_STATUSES, true);
        $completedAt = $isTerminal ? 'NOW()' : 'NULL';
        $completedBy = $isTerminal ? $userId : null;
        $stmt = $db->prepare(
            "UPDATE follow_up_tasks SET status = ?, completed_at = {$completedAt}, completed_by = ? WHERE id = ? AND company_id = ?"
        );
        $stmt->execute([$status, $completedBy, $taskId, $scope->companyId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Auto-advances a task to Connection Sent the moment its LinkedIn
     * Profile link is clicked -- only if it's still earlier in the flow
     * (pending/in_progress), so a background click on an already-sent or
     * already-accepted task (e.g. someone re-opens the link to double-check
     * the profile) never regresses it. Silent no-op, not a full status
     * change, for a fire-and-forget background call from the task list's
     * onclick handler -- see tasks.php's `action=mark_connection_sent_click`.
     */
    public static function markConnectionSentIfEarlier(PDO $db, Scope $scope, int $taskId): bool
    {
        $stmt = $db->prepare(
            "UPDATE follow_up_tasks SET status = 'connection_sent'
              WHERE id = ? AND company_id = ? AND status IN ('pending', 'in_progress')"
        );
        $stmt->execute([$taskId, $scope->companyId]);
        return $stmt->rowCount() > 0;
    }

    public static function updateNotes(PDO $db, Scope $scope, int $taskId, string $notes): bool
    {
        $stmt = $db->prepare('UPDATE follow_up_tasks SET notes = ? WHERE id = ? AND company_id = ?');
        $stmt->execute([$notes, $taskId, $scope->companyId]);
        return $stmt->rowCount() > 0;
    }
}
