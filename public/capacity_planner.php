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

$horizonDays = in_array((int) ($_GET['days'] ?? 14), [7, 14, 30], true) ? (int) $_GET['days'] : 14;
$plannedRates = [];
foreach ((array) ($_GET['rate'] ?? []) as $campaignId => $rate) {
    $plannedRates[(int) $campaignId] = max(0, (int) $rate);
}
$forecast = CapacityPlanner::forecast(db(), $scope, $horizonDays, $plannedRates);

$fmtDayLabel = static function (string $dateStr): string {
    static $today, $tomorrow;
    $today ??= date('Y-m-d');
    $tomorrow ??= date('Y-m-d', strtotime('+1 day'));
    if ($dateStr === $today) {
        return 'Today';
    }
    if ($dateStr === $tomorrow) {
        return 'Tomorrow';
    }
    return date('D, M j', strtotime($dateStr));
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

<h2 class="h5 mt-5 mb-3">Day-by-day forecast</h2>
<p class="text-muted">
  How many emails are actually due each day, and what's left over. "In-flight" is leads already enrolled --
  projected from their real enrollment date and how far they've gotten through the sequence. "New" is a
  what-if: leads not yet sent to Saleshandy, enrolled at the planned rate below (starting today), each with
  its own D1/D3/D7-style ripple projected forward. Only campaigns with synced step data (not highlighted
  yellow above) are included. In-flight numbers are only as fresh as each campaign's own last Saleshandy sync
  (its round-robin turn or a manual "Sync" click on Campaigns) -- see "Lead data: ..." on each campaign below;
  red means it's more than a day old.
</p>

<form method="get" action="capacity_planner.php" class="card mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
      <div>
        <label class="form-label small text-muted mb-1">Horizon</label>
        <select name="days" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ([7, 14, 30] as $d): ?>
            <option value="<?= $d ?>" <?= $horizonDays === $d ? 'selected' : '' ?>><?= $d ?> days</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="text-muted small flex-grow-1">
        Planned new leads/day per campaign (defaults to that campaign's own sustainable rate) --
        adjust and click Recalculate to try a different plan.
      </div>
    </div>
    <?php if ($forecast['campaigns']): ?>
    <div class="row g-2 mb-3">
      <?php foreach ($forecast['campaigns'] as $cid => $fc): ?>
        <?php if ($fc['not_started_backlog'] <= 0 || $fc['needs_sync']) continue; ?>
        <div class="col-md-4">
          <label class="form-label small mb-1"><?= e($fc['name']) ?> <span class="text-muted">(<?= $fc['not_started_backlog'] ?> waiting)</span></label>
          <input type="number" min="0" name="rate[<?= $cid ?>]" class="form-control form-control-sm" value="<?= (int) $fc['planned_rate'] ?>">
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm">Recalculate</button>
  </div>
</form>

<div class="table-responsive card mb-3">
  <table class="table table-sm mb-0 align-middle">
    <thead>
      <tr>
        <th>Day</th>
        <th>In-flight due</th>
        <th>New (planned)</th>
        <th>Total scheduled</th>
        <th>Capacity</th>
        <th>Balance</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($forecast['consolidated'] as $dateStr => $row): ?>
      <tr>
        <td><?= e($fmtDayLabel($dateStr)) ?></td>
        <td><?= $row['in_flight'] ?></td>
        <td><?= $row['new_cohort'] ?></td>
        <td><strong><?= $row['total'] ?></strong></td>
        <td><?= $row['capacity'] ?></td>
        <td class="<?= $row['balance'] < 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= $row['balance'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="text-muted small mb-4">
  Negative balance (red) means that day's projected sends exceed your daily capacity -- lower a planned rate above,
  or connect more email accounts.
</p>

<?php if ($forecast['campaigns']): ?>
<h3 class="h6 mb-2">Per-campaign breakdown</h3>
<p class="text-muted small">
  Capacity/balance aren't repeated per campaign here since accounts are pooled per owner and shared across their
  other campaigns too -- see the consolidated table above for the actual capacity check.
</p>
<?php foreach ($forecast['campaigns'] as $cid => $fc): ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><?= e($fc['name']) ?> <span class="text-muted small">-- <?= e($fc['owner_name'] ?? '--') ?></span></span>
      <span class="d-flex align-items-center gap-2">
        <?php
          $syncedAt = $fc['lead_data_synced_at'];
          $staleHours = $syncedAt ? (time() - strtotime($syncedAt)) / 3600 : null;
          $isStale = $staleHours === null || $staleHours > 24;
        ?>
        <span class="small <?= $isStale ? 'text-danger' : 'text-muted' ?>" title="When this campaign's per-lead progress (delivery status, current step) was last pulled from Saleshandy -- the 'Refresh from Saleshandy' button above doesn't update this, only the regular Sync/round-robin cron does.">
          Lead data: <?= $syncedAt ? e($fmtDate($syncedAt)) : 'never synced' ?>
        </span>
        <?php if ($fc['needs_sync']): ?><span class="badge bg-warning text-dark">Needs sync</span><?php endif; ?>
      </span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead>
          <tr>
            <th>Day</th>
            <th>In-flight due</th>
            <th>New (planned)</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($fc['by_date'] as $dateStr => $row): ?>
          <tr>
            <td><?= e($fmtDayLabel($dateStr)) ?></td>
            <td><?= $row['in_flight'] ?></td>
            <td><?= $row['new_cohort'] ?></td>
            <td><strong><?= $row['total'] ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<?php render_footer(); ?>
