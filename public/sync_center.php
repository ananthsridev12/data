<?php
/**
 * Every "Run now"/manual Saleshandy action in one place: the 4 round-
 * robin crons (each processes whichever campaign/ICP has gone longest
 * without an attempt), plus an individual action per campaign and per
 * ICP segment for when you want to trigger a SPECIFIC one right now
 * instead of waiting for its turn in rotation. The individual actions
 * reuse the exact same endpoints as each campaign's own detail page
 * (campaign_saleshandy_sync.php etc.) via a redirect_to=sync_center
 * override, and IcpRepository::runDistributionForIcp() for a single ICP
 * (the individual-action counterpart to runDistributionForNext()).
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';
require_once __DIR__ . '/../app/includes/CronRunLog.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$lastSyncRun = CronRunLog::lastRun(db(), 'saleshandy_sync');
$lastDistributionRun = CronRunLog::lastRun(db(), 'icp_distribution');
$lastBackfillRun = CronRunLog::lastRun(db(), 'saleshandy_backfill');
$lastFieldSyncRun = CronRunLog::lastRun(db(), 'saleshandy_field_sync');
$fieldSyncEnabledStmt = db()->prepare('SELECT saleshandy_field_sync_cron_enabled FROM companies WHERE id = ?');
$fieldSyncEnabledStmt->execute([$scope->companyId]);
$fieldSyncEnabledForCompany = (bool) $fieldSyncEnabledStmt->fetchColumn();

/** "How long ago" string from a MySQL DATETIME. */
$timeAgo = static function (string $mysqlDatetime): string {
    $diff = time() - strtotime($mysqlDatetime);
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' min ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' hr ago';
    }
    return floor($diff / 86400) . ' day(s) ago';
};

// Same visibility rule as every other mutate action in this app: Admin
// sees every campaign in the company, Team Lead/Member only their own
// (not their whole team's -- Sync Center only ever lists things you can
// personally trigger an action on).
$campaignClauses = ['c.company_id = :company_id', 'c.saleshandy_sequence_id IS NOT NULL'];
$campaignParams = ['company_id' => $scope->companyId];
if (!$scope->isAdmin()) {
    $campaignClauses[] = 'c.saleshandy_account_owner_id = :owner_id';
    $campaignParams['owner_id'] = $scope->userId;
}
$campaignsStmt = db()->prepare(
    'SELECT c.id, c.name, c.is_active, c.saleshandy_last_synced_at, c.saleshandy_backfilled_at, owner.name AS owner_name
       FROM campaigns c
       LEFT JOIN users owner ON owner.id = c.saleshandy_account_owner_id
      WHERE ' . implode(' AND ', $campaignClauses) . '
      ORDER BY owner.name, c.name'
);
$campaignsStmt->execute($campaignParams);
$campaigns = $campaignsStmt->fetchAll();

// IcpRepository::list() already applies the right visibility rule
// (created-by-self OR has a self-owned campaign linked, for non-admins)
// -- an ICP that isn't fully self-owned still shows here, but its
// "Distribute now" click will come back with a clear "not fully yours"
// message rather than silently doing nothing (same as clicking Edit/
// Toggle on a partially-shared ICP elsewhere in this app).
$icps = IcpRepository::list(db(), $scope);

render_header('Sync Center');
?>
<h1 class="h4 mb-1">Sync Center</h1>
<p class="text-muted mb-4">
  Every manual Saleshandy sync/backfill/push action in one place -- the 4 round-robin "Run now" crons below, plus
  an individual action per campaign or ICP segment for when you want to trigger a specific one right now instead
  of waiting for its turn in rotation.
</p>

<div class="card mb-4">
  <div class="card-header fw-semibold">Cron status (round-robin -- next due)</div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Each run (scheduled or "Run now") processes just <strong>one</strong> campaign/ICP -- whichever has gone
      longest without an attempt -- rather than looping everything at once, so a single run never risks timing
      out with many campaigns/ICPs. Successive runs rotate through all of them automatically.
      <?php if (!$scope->isAdmin()): ?>
        "Run now" only ever picks among your own campaigns/ICPs -- it can't touch a teammate's.
      <?php endif; ?>
    </p>
    <div class="row g-3">
      <div class="col-md-4 d-flex justify-content-between align-items-start gap-2">
        <div>
          <div class="fw-semibold">Saleshandy sync</div>
          <?php if ($lastSyncRun): ?>
            <div class="small text-muted">
              Last run <?= $timeAgo($lastSyncRun['ran_at']) ?> (<?= e($lastSyncRun['triggered_by']) ?>)
              <?php if ($lastSyncRun['summary']): ?><br><?= e($lastSyncRun['summary']) ?><?php endif; ?>
            </div>
          <?php else: ?>
            <div class="small text-muted">Never run yet -- set up the cron job, or run it now.</div>
          <?php endif; ?>
        </div>
        <form method="post" action="saleshandy_sync_run.php" class="flex-shrink-0">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3 text-nowrap">Run now</button>
        </form>
      </div>
      <div class="col-md-4 d-flex justify-content-between align-items-start gap-2">
        <div>
          <div class="fw-semibold">ICP distribution</div>
          <?php if ($lastDistributionRun): ?>
            <div class="small text-muted">
              Last run <?= $timeAgo($lastDistributionRun['ran_at']) ?> (<?= e($lastDistributionRun['triggered_by']) ?>)
              <?php if ($lastDistributionRun['summary']): ?><br><?= e($lastDistributionRun['summary']) ?><?php endif; ?>
            </div>
          <?php else: ?>
            <div class="small text-muted">Never run yet -- set up the cron job, or run it now.</div>
          <?php endif; ?>
        </div>
        <form method="post" action="icp_distribution_run.php" class="flex-shrink-0">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3 text-nowrap">Run now</button>
        </form>
      </div>
      <div class="col-md-4 d-flex justify-content-between align-items-start gap-2">
        <div>
          <div class="fw-semibold">Saleshandy backfill</div>
          <?php if ($lastBackfillRun): ?>
            <div class="small text-muted">
              Last run <?= $timeAgo($lastBackfillRun['ran_at']) ?> (<?= e($lastBackfillRun['triggered_by']) ?>)
              <?php if ($lastBackfillRun['summary']): ?><br><?= e($lastBackfillRun['summary']) ?><?php endif; ?>
            </div>
          <?php else: ?>
            <div class="small text-muted">Never run yet -- set up the cron job, or run it now. Optional: only useful once, per campaign, for older history predating this app.</div>
          <?php endif; ?>
        </div>
        <form method="post" action="campaign_saleshandy_backfill_run.php" class="flex-shrink-0">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3 text-nowrap">Run now</button>
        </form>
      </div>
      <div class="col-md-4 d-flex justify-content-between align-items-start gap-2">
        <div>
          <div class="fw-semibold">Saleshandy field sync</div>
          <?php if (!$fieldSyncEnabledForCompany): ?>
            <div class="small text-muted">
              Scheduled cron is off for your company --
              <?= $scope->isAdmin() ? 'enable it on <a href="company_profile.php">Company Profile</a>, or ' : '' ?>use "Run now" any time regardless.
            </div>
          <?php endif; ?>
          <?php if ($lastFieldSyncRun): ?>
            <div class="small text-muted">
              Last run <?= $timeAgo($lastFieldSyncRun['ran_at']) ?> (<?= e($lastFieldSyncRun['triggered_by']) ?>)
              <?php if ($lastFieldSyncRun['summary']): ?><br><?= e($lastFieldSyncRun['summary']) ?><?php endif; ?>
            </div>
          <?php else: ?>
            <div class="small text-muted">Never run yet -- set up the cron job, or run it now. Keeps already-pushed leads' custom fields (Vertical, Service, etc.) current on Saleshandy.</div>
          <?php endif; ?>
        </div>
        <form method="post" action="campaign_saleshandy_field_sync_run.php" class="flex-shrink-0">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3 text-nowrap">Run now</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header fw-semibold">Campaigns -- sync individually</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th>Campaign</th>
          <th>Owner</th>
          <th>Last synced</th>
          <th>Last backfilled</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($campaigns as $c): ?>
        <tr class="<?= $c['is_active'] ? '' : 'text-muted' ?>">
          <td><?= e($c['name']) ?><?= $c['is_active'] ? '' : ' <span class="badge bg-secondary">Inactive</span>' ?></td>
          <td><?= e($c['owner_name'] ?? '--') ?></td>
          <td class="small text-muted"><?= $c['saleshandy_last_synced_at'] ? e($timeAgo($c['saleshandy_last_synced_at'])) : 'never' ?></td>
          <td class="small text-muted"><?= $c['saleshandy_backfilled_at'] ? e($timeAgo($c['saleshandy_backfilled_at'])) : 'never' ?></td>
          <td class="text-end">
            <div class="d-flex gap-1 justify-content-end flex-wrap">
              <form method="post" action="campaign_saleshandy_sync.php">
                <?= csrf_field() ?>
                <input type="hidden" name="campaign_id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="redirect_to" value="sync_center">
                <button type="submit" class="btn btn-sm btn-outline-primary">Sync now</button>
              </form>
              <form method="post" action="campaign_saleshandy_import.php" onsubmit="return confirm('Pull in any prospects from this Saleshandy sequence not yet assigned to this campaign here?');">
                <?= csrf_field() ?>
                <input type="hidden" name="campaign_id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="redirect_to" value="sync_center">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Pull new</button>
              </form>
              <form method="post" action="campaign_saleshandy_push.php" onsubmit="return confirm('Push currently-eligible leads for this campaign to Saleshandy?');">
                <?= csrf_field() ?>
                <input type="hidden" name="campaign_id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="redirect_to" value="sync_center">
                <button type="submit" class="btn btn-sm btn-primary">Push</button>
              </form>
              <form method="post" action="campaign_saleshandy_backfill_dates.php" onsubmit="return confirm('Re-check this campaign against Saleshandy\'s full 2-year history? Slower than Sync now -- run once, not routinely.');">
                <?= csrf_field() ?>
                <input type="hidden" name="campaign_id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="redirect_to" value="sync_center">
                <button type="submit" class="btn btn-sm btn-outline-warning">Backfill</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$campaigns): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No Saleshandy-linked campaigns yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header fw-semibold">ICP segments -- distribute individually</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th>ICP segment</th>
          <th>Status</th>
          <th>Links</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($icps as $icp): ?>
        <tr>
          <td><?= e($icp['name']) ?></td>
          <td><span class="badge bg-<?= $icp['is_active'] ? 'success' : 'secondary' ?>"><?= $icp['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <td class="small text-muted"><?= (int) $icp['link_count'] ?> campaign(s), <?= (int) $icp['percentage_total'] ?>% split</td>
          <td class="text-end">
            <form method="post" action="icp_distribution_run_one.php">
              <?= csrf_field() ?>
              <input type="hidden" name="icp_id" value="<?= (int) $icp['id'] ?>">
              <input type="hidden" name="redirect_to" value="sync_center">
              <button type="submit" class="btn btn-sm btn-outline-primary">Distribute now</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$icps): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">No ICP segments yet -- create one on <a href="icp_segments.php">ICP Segments</a>.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
