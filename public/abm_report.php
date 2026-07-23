<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/AbmVisibilityRepository.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

$user = require_login();

$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'campaign_id' => trim((string) ($_GET['campaign_id'] ?? '')),
];

$campaigns = db()->query("SELECT id, name FROM campaigns WHERE saleshandy_sequence_id IS NOT NULL ORDER BY name")->fetchAll();

$summary = AbmVisibilityRepository::summary(db(), $filters);
$coverage = AbmVisibilityRepository::coverageByVertical(db(), $filters);
$sequences = AbmVisibilityRepository::sequences(db(), $filters);

$pct = static function (float $v): string {
    return number_format($v * 100, 1) . '%';
};

render_header('ABM Visibility Report');
?>
<h1 class="h4 mb-3">ABM Visibility Report</h1>

<?php if ($user['role'] === ROLE_ADMIN): ?>
<form method="post" action="abm_report_sync.php" class="mb-3">
  <?= csrf_field() ?>
  <button type="submit" class="btn btn-primary btn-sm">Fetch to update from Saleshandy</button>
  <span class="text-muted small ms-2">Runs an incremental sync across every Saleshandy-linked campaign, same as each campaign's own "Refresh statuses" button.</span>
</form>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header">
    About this report
    <?= info_icon('This app does not yet store a per-day, per-follow-up-step send log -- only each lead\'s first send date and furthest sequence step reached. So "Emails sent" and "Contacts" read as the same number here (one lead = one count), and there is no Daily Activity / Weekly / per-Step breakdown yet -- that needs a follow-up piece of work to track individual send events. "Opened" reflects Saleshandy\'s own cumulative open count as of the last sync, not a per-day open log.') ?>
  </div>
</div>

<form method="get" action="abm_report.php" class="card filter-card mb-4">
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
        <a href="abm_report.php" class="btn btn-outline-secondary btn-sm w-100">Clear</a>
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

<?php render_footer(); ?>
