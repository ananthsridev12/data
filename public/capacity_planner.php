<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/CapacityPlanner.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $result = CapacityPlanner::refreshAll(db(), $scope);
    $msg = "Synced {$result['synced_owners']} connected account(s) and {$result['synced_campaigns']} campaign(s).";
    if ($result['errors']) {
        $msg .= ' Some items failed: ' . implode(' ', $result['errors']);
        flash_set('warning', $msg);
    } else {
        flash_set('success', $msg);
    }

    header('Location: capacity_planner.php');
    exit;
}

$plan = CapacityPlanner::plan(db(), $scope);
$summary = $plan['summary'];
$campaigns = $plan['campaigns'];

$fmtNum = static fn(?float $n): string => $n === null ? '--' : number_format($n, $n == floor($n) ? 0 : 1);
$fmtDate = static function (?string $dt): string {
    return $dt ? date('M j, g:ia', strtotime($dt)) : 'never';
};

render_header('Capacity Planner');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0">Capacity Planner</h1>
  <form method="post" action="capacity_planner.php">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline-secondary btn-sm">Refresh from Saleshandy</button>
  </form>
</div>
<p class="text-muted">
  Estimates how many new leads/day your connected Saleshandy accounts can safely take on, and how long your current
  backlog will take to clear, based on your live campaigns, their sequence steps, and connected email accounts.
  Numbers below are cached locally -- click "Refresh from Saleshandy" to pull the latest step counts and
  account counts. <?= $scope->isAdmin() ? 'The assumed daily send limit per account is set on <a href="company_profile.php">Company Profile</a>.' : '' ?>
</p>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-muted small">Daily send capacity</div>
        <div class="h4 mb-0"><?= (int) $summary['total_daily_capacity'] ?></div>
        <div class="text-muted small">emails/day @ <?= (int) $summary['assumed_daily_send_limit'] ?>/account</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-muted small">Current backlog</div>
        <div class="h4 mb-0"><?= (int) $summary['total_backlog'] ?></div>
        <div class="text-muted small">leads not yet finished</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-muted small">Max new leads/day</div>
        <div class="h4 mb-0"><?= e($fmtNum($summary['total_max_new_leads_per_day'])) ?></div>
        <div class="text-muted small">across all live campaigns</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-muted small">Days to clear backlog</div>
        <div class="h4 mb-0"><?= e($fmtNum($summary['total_days_to_clear_backlog'])) ?></div>
        <div class="text-muted small">at current pace, if nothing new is added</div>
      </div>
    </div>
  </div>
</div>

<div class="table-responsive card">
  <table class="table table-sm mb-0 align-middle">
    <thead>
      <tr>
        <th>Campaign</th>
        <th>Owner</th>
        <th>Accounts</th>
        <th>Steps</th>
        <th>Cadence</th>
        <th>Backlog</th>
        <th>Max new/day</th>
        <th>Days to clear</th>
        <th>Days to finish</th>
        <th>Last synced</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($campaigns as $c): ?>
      <tr class="<?= $c['needs_sync'] ? 'table-warning' : '' ?>">
        <td><?= e($c['name']) ?></td>
        <td><?= e($c['owner_name'] ?? '--') ?></td>
        <td><?= $c['active_email_accounts'] ?></td>
        <td><?= $c['step_count'] ?? '--' ?></td>
        <td><?= $c['cadence_days'] !== null ? $c['cadence_days'] . 'd' : '--' ?></td>
        <td><?= $c['backlog_count'] ?></td>
        <td><?= e($fmtNum($c['max_new_leads_per_day'])) ?></td>
        <td><?= e($fmtNum($c['days_to_clear_backlog'])) ?></td>
        <td><?= e($fmtNum($c['days_to_finish'])) ?></td>
        <td class="small text-muted"><?= e($fmtDate($c['capacity_synced_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$campaigns): ?>
      <tr><td colspan="10" class="text-center text-muted py-4">No live campaigns linked to Saleshandy yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<p class="text-muted small mt-2">
  Rows highlighted in yellow haven't been synced yet (or their owner hasn't connected Saleshandy) -- click
  "Refresh from Saleshandy" above to pull step/account data for them.
</p>

<?php render_footer(); ?>
