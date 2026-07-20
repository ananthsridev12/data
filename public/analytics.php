<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/AnalyticsRepository.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

$user = require_login();

$filters = [
    'campaign_id' => trim((string) ($_GET['campaign_id'] ?? '')),
    'vertical_id' => trim((string) ($_GET['vertical_id'] ?? '')),
    'service_id' => trim((string) ($_GET['service_id'] ?? '')),
    'industry' => trim((string) ($_GET['industry'] ?? '')),
    'created_from' => trim((string) ($_GET['created_from'] ?? '')),
    'created_to' => trim((string) ($_GET['created_to'] ?? '')),
    'email_sent_from' => trim((string) ($_GET['email_sent_from'] ?? '')),
    'email_sent_to' => trim((string) ($_GET['email_sent_to'] ?? '')),
];

$campaigns = db()->query('SELECT id, name FROM campaigns ORDER BY name')->fetchAll();
$verticals = LeadRepository::activeLookupOptions(db(), 'verticals');
$services = LeadRepository::activeLookupOptions(db(), 'services');
$industries = LeadRepository::distinctValues(db(), 'industry');

$campaignSummary = AnalyticsRepository::campaignSummary(db());

// All four breakdowns shown together, same filters applied to each --
// no "group by" dropdown to click through, so country/vertical/service/
// campaign are all visible on one screen at once.
$sections = [];
foreach (AnalyticsRepository::GROUP_DIMENSIONS as $key => $label) {
    $sections[$key] = ['label' => $label, 'pivot' => AnalyticsRepository::pivotByDimension(db(), $key, $filters)];
}

$filterQuery = $_GET;

render_header('Analytics');
?>
<h1 class="h4 mb-3">Analytics</h1>

<div class="card mb-4">
  <div class="card-header">Campaign summary</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>S No</th><th>Campaign</th><th>Vertical</th><th>Service pitched</th><th>Prospects</th><th>First email date</th></tr></thead>
      <tbody>
        <?php foreach ($campaignSummary as $i => $c): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= e($c['name']) ?></td>
            <td><?= e($c['vertical_label'] ?? '') ?></td>
            <td><?= e($c['service_label'] ?? '') ?></td>
            <td><?= number_format((int) $c['prospects']) ?></td>
            <td><?= $c['first_email_date'] ? e(date('l, F j, Y', strtotime($c['first_email_date']))) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$campaignSummary): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">No campaigns yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<form method="get" action="analytics.php" class="card filter-card mb-4">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small mb-0">Campaign</label>
      <select name="campaign_id" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach ($campaigns as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (string) $filters['campaign_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-0">Vertical</label>
      <select name="vertical_id" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach ($verticals as $v): ?>
          <option value="<?= (int) $v['id'] ?>" <?= (string) $filters['vertical_id'] === (string) $v['id'] ? 'selected' : '' ?>><?= e($v['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-0">Service</label>
      <select name="service_id" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach ($services as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (string) $filters['service_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-0">Industry</label>
      <select name="industry" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach ($industries as $ind): ?>
          <option value="<?= e($ind) ?>" <?= $filters['industry'] === $ind ? 'selected' : '' ?>><?= e($ind) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label small mb-0">Lead imported between</label>
      <div class="d-flex gap-1">
        <input type="date" name="created_from" class="form-control form-control-sm" value="<?= e($filters['created_from']) ?>">
        <input type="date" name="created_to" class="form-control form-control-sm" value="<?= e($filters['created_to']) ?>">
      </div>
    </div>
    <div class="col-md-4">
      <label class="form-label small mb-0">Email sent between</label>
      <div class="d-flex gap-1">
        <input type="date" name="email_sent_from" class="form-control form-control-sm" value="<?= e($filters['email_sent_from']) ?>">
        <input type="date" name="email_sent_to" class="form-control form-control-sm" value="<?= e($filters['email_sent_to']) ?>">
      </div>
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
    </div>
    <?php if (array_filter($filterQuery)): ?>
      <div class="col-md-2">
        <a href="analytics.php" class="btn btn-outline-secondary btn-sm w-100">Clear filters</a>
      </div>
    <?php endif; ?>
  </div>
</form>

<?php foreach ($sections as $section): $pivot = $section['pivot']; ?>
  <div class="card mb-4">
    <div class="card-header">By <?= e($section['label']) ?></div>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead>
          <tr>
            <th><?= e($section['label']) ?></th>
            <th class="text-end">Prospects</th>
            <th class="text-end">Imported to Saleshandy</th>
            <th class="text-end">Not imported</th>
            <th class="text-end">Email sent</th>
            <th class="text-end">Email not sent</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pivot['rows'] as $row): ?>
            <tr>
              <td><?= e($row['grp']) ?></td>
              <td class="text-end"><?= number_format((int) $row['prospects']) ?></td>
              <td class="text-end"><?= number_format((int) $row['imported']) ?></td>
              <td class="text-end"><?= number_format((int) $row['not_imported']) ?></td>
              <td class="text-end"><?= number_format((int) $row['email_sent']) ?></td>
              <td class="text-end"><?= number_format((int) $row['email_not_sent']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$pivot['rows']): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">No leads match this filter.</td></tr>
          <?php endif; ?>
        </tbody>
        <?php if ($pivot['rows']): ?>
        <tfoot>
          <tr class="fw-bold table-light">
            <td>Grand Total</td>
            <td class="text-end"><?= number_format($pivot['total']['prospects']) ?></td>
            <td class="text-end"><?= number_format($pivot['total']['imported']) ?></td>
            <td class="text-end"><?= number_format($pivot['total']['not_imported']) ?></td>
            <td class="text-end"><?= number_format($pivot['total']['email_sent']) ?></td>
            <td class="text-end"><?= number_format($pivot['total']['email_not_sent']) ?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<?php render_footer(); ?>
