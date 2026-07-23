<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/ReportsRepository.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

$user = require_login();

$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'campaign_id' => trim((string) ($_GET['campaign_id'] ?? '')),
];

$campaigns = db()->query("SELECT id, name FROM campaigns WHERE saleshandy_sequence_id IS NOT NULL ORDER BY name")->fetchAll();

$summary = ReportsRepository::summary(db(), $filters);
$coverage = ReportsRepository::coverageByVertical(db(), $filters);
$sequences = ReportsRepository::sequences(db(), $filters);
$repliesByOutcome = ReportsRepository::repliesByOutcome(db(), $filters);
$daily = ReportsRepository::dailyActivity(db(), $filters);
$weekly = ReportsRepository::weeklyActivity(db(), $filters);
$steps = ReportsRepository::stepsRaw(db(), $filters);
$eventBounds = ReportsRepository::dateBounds(db());

$pct = static function (float $v): string {
    return number_format($v * 100, 1) . '%';
};

render_header('Reports');
?>
<h1 class="h4 mb-3">Reports</h1>

<?php if ($user['role'] === ROLE_ADMIN): ?>
<form method="post" action="reports_sync.php" class="mb-3">
  <?= csrf_field() ?>
  <button type="submit" class="btn btn-primary btn-sm">Fetch to update from Saleshandy</button>
  <span class="text-muted small ms-2">Runs an incremental sync across every Saleshandy-linked campaign, same as each campaign's own "Refresh statuses" button.</span>
</form>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header">
    About this report
    <?= info_icon('Summary/Coverage/Sequences read one row per lead per campaign, so "Emails sent" and "Contacts" are the same number there. Daily Activity/Weekly/Steps read a separate per-send-event log that only starts filling in from the first sync or "Fetch to update" after this feature was added -- if those sections look empty or too low, click "Fetch to update" above (or a campaign\'s own "Backfill dates & status") to populate history. "Opened" everywhere reflects Saleshandy\'s own cumulative open count as of the last fetch, not a full per-day open log -- exact for "opened at all", approximate for which exact day.') ?>
  </div>
</div>

<?php if (!$eventBounds['min']): ?>
<div class="alert alert-warning">
  No send-event history recorded yet, so Daily Activity, Weekly, and Steps below are empty. Click <strong>Fetch to update from Saleshandy</strong> above (admin only), or run a campaign's own "Backfill dates &amp; status", to populate them.
</div>
<?php endif; ?>

<form method="get" action="reports.php" class="card filter-card mb-4">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-4">
      <label class="form-label small mb-0">Campaign</label>
      <select name="campaign_id" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach ($campaigns as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (string) $filters['campaign_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-5">
      <label class="form-label small mb-0">Email sent between</label>
      <div class="d-flex gap-1">
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filters['date_from']) ?>">
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filters['date_to']) ?>">
      </div>
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
    </div>
    <?php if (array_filter($filters)): ?>
      <div class="col-md-1">
        <a href="reports.php" class="btn btn-outline-secondary btn-sm w-100">Clear</a>
      </div>
    <?php endif; ?>
  </div>
</form>

<div class="card mb-4">
  <div class="card-header">Summary</div>
  <div class="card-body">
    <div class="row text-center mb-3">
      <div class="col"><div class="h4 mb-0"><?= number_format($summary['headline']['contacts_in_database']) ?></div><div class="text-muted small">Contacts in database</div></div>
      <div class="col"><div class="h4 mb-0"><?= number_format($summary['headline']['contacts_reached']) ?></div><div class="text-muted small">Contacts reached</div></div>
      <div class="col"><div class="h4 mb-0"><?= number_format($summary['headline']['unique_opens']) ?></div><div class="text-muted small">Unique opens</div></div>
      <div class="col"><div class="h4 mb-0"><?= number_format($summary['headline']['replies']) ?></div><div class="text-muted small">Replies</div></div>
      <div class="col"><div class="h4 mb-0"><?= number_format($summary['headline']['active_sending_days']) ?></div><div class="text-muted small">Active sending days</div></div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead><tr><th>Stage</th><th class="text-end">Contacts</th><th class="text-end">% of database</th><th class="text-end">% of previous</th></tr></thead>
        <tbody>
          <?php foreach ($summary['funnel'] as $i => $stage): ?>
            <tr>
              <td><?= $i + 1 ?>. <?= e($stage['stage']) ?></td>
              <td class="text-end"><?= number_format($stage['count']) ?></td>
              <td class="text-end"><?= $pct($stage['pct_of_database']) ?></td>
              <td class="text-end"><?= $pct($stage['pct_of_previous']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">
    Replies by outcome
    <?= info_icon('Saleshandy\'s own "Current Sentiment" categorization of each reply (Positive/Negative/Neutral/Uncategorized). "Not yet categorized" means the reply predates this feature or the campaign hasn\'t been re-synced since -- re-run "Fetch to update" to fill it in.') ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>Outcome</th><th class="text-end">Replies</th></tr></thead>
      <tbody>
        <?php foreach ($repliesByOutcome as $row): ?>
          <tr>
            <td><?= e($row['outcome']) ?></td>
            <td class="text-end"><?= number_format($row['count']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$repliesByOutcome): ?>
          <tr><td colspan="2" class="text-center text-muted py-3">No replies in this range.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Coverage: database built vs. contacted, by Vertical</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr><th>Vertical</th><th class="text-end">In database</th><th class="text-end">Contacted</th><th class="text-end">Not contacted</th><th class="text-end">Opened</th><th class="text-end">Coverage %</th></tr>
      </thead>
      <tbody>
        <?php foreach ($coverage['rows'] as $row): ?>
          <tr>
            <td><?= e($row['grp']) ?></td>
            <td class="text-end"><?= number_format($row['in_database']) ?></td>
            <td class="text-end"><?= number_format($row['contacted']) ?></td>
            <td class="text-end"><?= number_format($row['not_contacted']) ?></td>
            <td class="text-end"><?= number_format($row['opened']) ?></td>
            <td class="text-end"><?= $pct($row['coverage_pct']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$coverage['rows']): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">No leads yet.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($coverage['rows']): ?>
      <tfoot>
        <tr class="fw-bold table-light">
          <td>TOTAL</td>
          <td class="text-end"><?= number_format($coverage['total']['in_database']) ?></td>
          <td class="text-end"><?= number_format($coverage['total']['contacted']) ?></td>
          <td class="text-end"><?= number_format($coverage['total']['not_contacted']) ?></td>
          <td class="text-end"><?= number_format($coverage['total']['opened']) ?></td>
          <td class="text-end"><?= $pct($coverage['total']['coverage_pct']) ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Sequence performance</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr><th>Sequence</th><th>Vertical</th><th class="text-end">Emails sent</th><th class="text-end">Contacts</th><th class="text-end">Opens</th><th class="text-end">Open rate</th><th class="text-end">Bounces</th><th class="text-end">Bounce rate</th><th class="text-end">Replies</th></tr>
      </thead>
      <tbody>
        <?php foreach ($sequences as $row): ?>
          <tr>
            <td><?= e($row['name']) ?></td>
            <td><?= e($row['vertical_label'] ?? '') ?></td>
            <td class="text-end"><?= number_format($row['emails_sent']) ?></td>
            <td class="text-end"><?= number_format($row['contacts']) ?></td>
            <td class="text-end"><?= number_format($row['opens']) ?></td>
            <td class="text-end"><?= $pct($row['open_rate']) ?></td>
            <td class="text-end"><?= number_format($row['bounces']) ?></td>
            <td class="text-end"><?= $pct($row['bounce_rate']) ?></td>
            <td class="text-end"><?= number_format($row['replies']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$sequences): ?>
          <tr><td colspan="9" class="text-center text-muted py-3">No Saleshandy-linked campaigns with activity in this range.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Daily sending activity</div>
  <?php if (count($daily) > 1): ?>
    <div class="p-3" style="height: 260px;">
      <canvas id="chartDaily"></canvas>
    </div>
    <script type="application/json" id="chartdata-daily"><?= json_encode(array_slice($daily, 0, -1), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr><th>Date</th><th class="text-end">Emails sent</th><th class="text-end">Contacts</th><th class="text-end">Opened</th><th class="text-end">Bounced</th><th class="text-end">Open rate</th><th class="text-end">Bounce rate</th></tr>
      </thead>
      <tbody>
        <?php foreach ($daily as $row): $isTotal = $row['date'] === 'TOTAL'; ?>
          <tr class="<?= $isTotal ? 'fw-bold table-light' : '' ?>">
            <td><?= $isTotal ? 'TOTAL' : e(date('Y-m-d', strtotime($row['date']))) ?></td>
            <td class="text-end"><?= number_format($row['emails_sent']) ?></td>
            <td class="text-end"><?= $row['contacts'] === null ? '' : number_format($row['contacts']) ?></td>
            <td class="text-end"><?= number_format($row['opened']) ?></td>
            <td class="text-end"><?= number_format($row['bounced']) ?></td>
            <td class="text-end"><?= $pct($row['open_rate']) ?></td>
            <td class="text-end"><?= $pct($row['bounce_rate']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$daily): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">No send events recorded yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">
    Weekly rhythm
    <?= info_icon('Volume against open rate, to show whether quality held as sending scaled. Week starts Monday.') ?>
  </div>
  <?php if (count($weekly) > 1): ?>
    <div class="p-3" style="height: 260px;">
      <canvas id="chartWeekly"></canvas>
    </div>
    <script type="application/json" id="chartdata-weekly"><?= json_encode(array_slice($weekly, 0, -1), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr><th>Week starting</th><th class="text-end">Emails sent</th><th class="text-end">Opened</th><th class="text-end">Replied</th><th class="text-end">Active days</th><th class="text-end">Open rate</th><th class="text-end">Emails/active day</th></tr>
      </thead>
      <tbody>
        <?php foreach ($weekly as $row): $isTotal = $row['week_start'] === 'TOTAL'; ?>
          <tr class="<?= $isTotal ? 'fw-bold table-light' : '' ?>">
            <td><?= $isTotal ? 'TOTAL' : e(date('Y-m-d', strtotime($row['week_start']))) ?></td>
            <td class="text-end"><?= number_format($row['emails_sent']) ?></td>
            <td class="text-end"><?= number_format($row['opened']) ?></td>
            <td class="text-end"><?= number_format($row['replied']) ?></td>
            <td class="text-end"><?= number_format($row['active_days']) ?></td>
            <td class="text-end"><?= $pct($row['open_rate']) ?></td>
            <td class="text-end"><?= number_format($row['emails_per_active_day'], 1) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$weekly): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">No send events recorded yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">
    Steps &amp; drop-off
    <?= info_icon('RAW per-step counts pooled across every sequence -- NOT cumulative, unlike Analytics\' "reached step N" numbers. Shows where follow-up momentum is lost.') ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr><th>Step</th><th class="text-end">Emails sent</th><th class="text-end">Opens</th><th class="text-end">Replies</th><th class="text-end">Open rate</th></tr>
      </thead>
      <tbody>
        <?php foreach ($steps as $row): ?>
          <tr>
            <td>Step <?= (int) $row['step_number'] ?></td>
            <td class="text-end"><?= number_format($row['emails_sent']) ?></td>
            <td class="text-end"><?= number_format($row['opens']) ?></td>
            <td class="text-end"><?= number_format($row['replies']) ?></td>
            <td class="text-end"><?= $pct($row['open_rate']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$steps): ?>
          <tr><td colspan="5" class="text-center text-muted py-3">No send events recorded yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="assets/js/reports_charts.js"></script>
<?php render_footer(); ?>
