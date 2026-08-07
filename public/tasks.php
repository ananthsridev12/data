<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/FollowUpTaskRepository.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $taskId = (int) ($_POST['task_id'] ?? 0);

    // Fire-and-forget background call from the LinkedIn "Profile" link's
    // onclick handler -- no flash message, no redirect, since the browser
    // is already navigating to LinkedIn in a new tab and never sees this
    // response. A task that isn't visible/already past pending/in_progress
    // is silently ignored, not an error, since a click firing twice (or
    // after the task was already advanced by hand) is expected, not a bug.
    if ($action === 'mark_connection_sent_click') {
        $task = FollowUpTaskRepository::loadVisible(db(), $scope, $taskId);
        if ($task) {
            FollowUpTaskRepository::markConnectionSentIfEarlier(db(), $scope, $taskId);
        }
        http_response_code(204);
        exit;
    }

    $task = FollowUpTaskRepository::loadVisible(db(), $scope, $taskId);

    if (!$task) {
        flash_set('danger', 'Task not found.');
    } elseif ($action === 'set_status') {
        $status = (string) ($_POST['status'] ?? '');
        if (FollowUpTaskRepository::setStatus(db(), $scope, $taskId, $status, $user['id'])) {
            flash_set('success', 'Task updated.');
        } else {
            flash_set('danger', 'Could not update task.');
        }
    } elseif ($action === 'save_notes') {
        FollowUpTaskRepository::updateNotes(db(), $scope, $taskId, trim((string) ($_POST['notes'] ?? '')));
        flash_set('success', 'Notes saved.');
    }

    header('Location: tasks.php?' . http_build_query($_GET));
    exit;
}

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'campaign_id' => trim((string) ($_GET['campaign_id'] ?? '')),
    'signal' => trim((string) ($_GET['signal'] ?? '')),
    'assigned_to' => trim((string) ($_GET['assigned_to'] ?? '')),
];

$total = FollowUpTaskRepository::matchingCount(db(), $scope, $filters);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$result = FollowUpTaskRepository::listForUser(db(), $scope, $filters, $perPage, ($page - 1) * $perPage);
$tasks = $result['rows'];

// Filter dropdown options -- both scoped to exactly what this Scope can
// see, matching listForUser()'s own visibility rule (a Member shouldn't
// be offered a campaign/assignee filter option that would only ever
// return zero rows for them).
$campaignOptionClauses = [];
$campaignOptionParams = [];
ScopeFilter::apply($campaignOptionClauses, $campaignOptionParams, $scope, 't');
ScopeFilter::applyOwnerScope($campaignOptionClauses, $campaignOptionParams, $scope, db(), 't', 'assigned_to');
$campaignOptionsStmt = db()->prepare(
    'SELECT DISTINCT c.id, c.name FROM follow_up_tasks t JOIN campaigns c ON c.id = t.campaign_id
      WHERE ' . implode(' AND ', $campaignOptionClauses) . ' ORDER BY c.name'
);
$campaignOptionsStmt->execute($campaignOptionParams);
$campaignOptions = $campaignOptionsStmt->fetchAll();

$assignedToOptions = [];
if ($scope->isTeamLeadOrAbove()) {
    $userOptionClauses = ['company_id = :scope_company_id'];
    $userOptionParams = ['scope_company_id' => $scope->companyId];
    $visibleOwnerIds = $scope->visibleOwnerIds(db());
    if ($visibleOwnerIds !== null) {
        $placeholders = [];
        foreach ($visibleOwnerIds as $i => $id) {
            $key = "uid_{$i}";
            $placeholders[] = ":{$key}";
            $userOptionParams[$key] = $id;
        }
        $userOptionClauses[] = 'id IN (' . implode(',', $placeholders) . ')';
    }
    $userOptionsStmt = db()->prepare('SELECT id, name FROM users WHERE ' . implode(' AND ', $userOptionClauses) . ' ORDER BY name');
    $userOptionsStmt->execute($userOptionParams);
    $assignedToOptions = $userOptionsStmt->fetchAll();
}

render_header('Follow-up Tasks');
?>
<h1 class="h4 mb-1">Follow-up Tasks</h1>
<p class="text-muted">
  <?= number_format($total) ?> task(s) match<?= array_filter($filters) ? ' this filter' : '' ?> (page <?= $page ?> of <?= $totalPages ?>) --
  auto-created when a lead crosses a campaign's Email opens/Link clicks/Positive reply thresholds
  (<a href="campaigns.php">set per campaign</a> under Saleshandy settings).
</p>

<div class="card mb-3">
  <div class="card-header">Filters</div>
  <div class="card-body">
    <form method="get" action="tasks.php" class="d-flex flex-wrap gap-2 align-items-center">
      <select name="status" class="form-select form-select-sm" style="max-width: 200px;">
        <option value="">Open (not yet resolved)</option>
        <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
        <option value="in_progress" <?= $filters['status'] === 'in_progress' ? 'selected' : '' ?>>In progress</option>
        <option value="connection_sent" <?= $filters['status'] === 'connection_sent' ? 'selected' : '' ?>>Connection sent</option>
        <option value="connection_accepted" <?= $filters['status'] === 'connection_accepted' ? 'selected' : '' ?>>Connection accepted</option>
        <option value="skipped" <?= $filters['status'] === 'skipped' ? 'selected' : '' ?>>Skipped</option>
      </select>
      <select name="campaign_id" class="form-select form-select-sm" style="max-width: 220px;">
        <option value="">Campaign (all)</option>
        <?php foreach ($campaignOptions as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (string) $filters['campaign_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="signal" class="form-select form-select-sm" style="max-width: 190px;">
        <option value="">Triggered by (all)</option>
        <option value="opens" <?= $filters['signal'] === 'opens' ? 'selected' : '' ?>>Email opens</option>
        <option value="clicks" <?= $filters['signal'] === 'clicks' ? 'selected' : '' ?>>Link clicks</option>
        <option value="reply" <?= $filters['signal'] === 'reply' ? 'selected' : '' ?>>Positive reply</option>
      </select>
      <?php if ($assignedToOptions): ?>
      <select name="assigned_to" class="form-select form-select-sm" style="max-width: 190px;">
        <option value="">Assigned to (all)</option>
        <?php foreach ($assignedToOptions as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= (string) $filters['assigned_to'] === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button type="submit" class="btn btn-sm btn-primary">Filter</button>
      <?php if (array_filter($filters)): ?>
        <a href="tasks.php" class="btn btn-sm btn-outline-secondary">Clear filters</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="table-responsive card mb-3">
  <table class="table table-hover mb-0 align-middle">
    <thead>
      <tr>
        <th>Company / Contact</th>
        <th>Email</th>
        <th>LinkedIn</th>
        <th>Campaign</th>
        <th>Triggered by</th>
        <th>Assigned to</th>
        <th>Status</th>
        <th>Notes</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tasks as $t): ?>
        <tr>
          <td>
            <?= e($t['na_company_name']) ?><br>
            <span class="small text-muted"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></span>
          </td>
          <td class="small"><?= e($t['email']) ?></td>
          <td>
            <?php if (!empty($t['person_linkedin_url'])): ?>
              <a href="<?= e($t['person_linkedin_url']) ?>" target="_blank" rel="noopener" onclick="markConnectionSentOnClick(<?= (int) $t['id'] ?>)">Profile</a>
            <?php else: ?>
              <span class="text-muted small">--</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($t['campaign_name']) ?></td>
          <td>
            <?php if ($t['flag_opens']): ?><span class="badge bg-primary me-1">Opened</span><?php endif; ?>
            <?php if ($t['flag_clicks']): ?><span class="badge bg-info me-1">Clicked</span><?php endif; ?>
            <?php if ($t['flag_reply']): ?><span class="badge bg-success me-1">Positive reply</span><?php endif; ?>
            <?php if ($t['reengaged_at']): ?><span class="badge bg-warning text-dark d-block mt-1">Re-engaged <?= e($t['reengaged_at']) ?></span><?php endif; ?>
          </td>
          <td class="small text-muted"><?= e($t['assigned_to_name'] ?? 'Unassigned') ?></td>
          <td>
            <?php
            $statusBadge = ['pending' => 'secondary', 'in_progress' => 'primary', 'connection_sent' => 'info', 'connection_accepted' => 'success', 'skipped' => 'dark'];
            $statusLabel = ['pending' => 'Pending', 'in_progress' => 'In progress', 'connection_sent' => 'Connection sent', 'connection_accepted' => 'Connection accepted', 'skipped' => 'Skipped'];
            ?>
            <span class="badge bg-<?= $statusBadge[$t['status']] ?>"><?= e($statusLabel[$t['status']]) ?></span>
          </td>
          <td>
            <form method="post" action="tasks.php?<?= http_build_query($_GET) ?>" class="d-flex gap-1">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_notes">
              <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
              <input type="text" name="notes" class="form-control form-control-sm" style="min-width: 160px;" value="<?= e($t['notes'] ?? '') ?>" placeholder="e.g. sent connection request">
              <button type="submit" class="btn btn-sm btn-outline-secondary">Save</button>
            </form>
          </td>
          <td>
            <form method="post" action="tasks.php?<?= http_build_query($_GET) ?>" class="d-flex flex-wrap gap-1">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
              <?php if ($t['status'] === 'pending'): ?>
                <button type="submit" name="status" value="in_progress" class="btn btn-sm btn-outline-primary">Start</button>
              <?php endif; ?>
              <?php if (in_array($t['status'], ['pending', 'in_progress'], true)): ?>
                <button type="submit" name="status" value="connection_sent" class="btn btn-sm btn-outline-info">Mark connection sent</button>
                <button type="submit" name="status" value="skipped" class="btn btn-sm btn-outline-dark">Skip</button>
              <?php elseif ($t['status'] === 'connection_sent'): ?>
                <button type="submit" name="status" value="connection_accepted" class="btn btn-sm btn-outline-success">Mark accepted</button>
                <button type="submit" name="status" value="skipped" class="btn btn-sm btn-outline-dark">Skip</button>
              <?php else: ?>
                <button type="submit" name="status" value="pending" class="btn btn-sm btn-outline-secondary">Reopen</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tasks): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No tasks.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
<nav>
  <ul class="pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): $q = array_filter($filters) + ['page' => $p]; ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link" href="tasks.php?<?= http_build_query($q) ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<script>
  // Fired by the LinkedIn "Profile" link's onclick -- auto-advances a task
  // to "Connection sent" the moment you actually open the profile, without
  // blocking the link's own navigation (target="_blank" + no preventDefault,
  // this is a fire-and-forget background call). No-ops server-side if the
  // task is already past pending/in_progress, or if this fails outright
  // (e.g. offline) -- the manual "Mark connection sent" button on the row
  // is always there as a fallback either way.
  function markConnectionSentOnClick(taskId) {
    var body = new URLSearchParams();
    body.set('csrf_token', <?= json_encode(csrf_token()) ?>);
    body.set('action', 'mark_connection_sent_click');
    body.set('task_id', taskId);
    fetch('tasks.php', { method: 'POST', body: body, keepalive: true }).catch(function () {});
  }
</script>

<?php render_footer(); ?>
