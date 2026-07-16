<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

$user = require_login();

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'company' => trim((string) ($_GET['company'] ?? '')),
    'title' => trim((string) ($_GET['title'] ?? '')),
    'seniority' => trim((string) ($_GET['seniority'] ?? '')),
    'departments' => trim((string) ($_GET['departments'] ?? '')),
    'industry' => trim((string) ($_GET['industry'] ?? '')),
    'country' => trim((string) ($_GET['country'] ?? '')),
    'employee_count' => trim((string) ($_GET['employee_count'] ?? '')),
    'domain' => trim((string) ($_GET['domain'] ?? '')),
    'vertical_id' => trim((string) ($_GET['vertical_id'] ?? '')),
    'service_id' => trim((string) ($_GET['service_id'] ?? '')),
    'campaign_id' => trim((string) ($_GET['campaign_id'] ?? '')),
    'hide_used_in_campaign' => !empty($_GET['hide_used_in_campaign']),
];
$page = max(1, (int) ($_GET['page'] ?? 1));

$result = LeadRepository::search(db(), $filters, $page);

$seniorities = LeadRepository::distinctValues(db(), 'seniority');
$industries = LeadRepository::distinctValues(db(), 'industry');
$countries = LeadRepository::distinctValues(db(), 'country');
$employeeCounts = LeadRepository::distinctValues(db(), 'employee_count');
$verticals = LeadRepository::activeLookupOptions(db(), 'verticals');
$services = LeadRepository::activeLookupOptions(db(), 'services');
$campaigns = db()->query('SELECT id, name FROM campaigns WHERE is_active = 1 ORDER BY name')->fetchAll();

// Rebuild the current filter query string (minus `page`) for pagination links
// and for the "export/assign everything matching this filter" hidden fields.
$filterQuery = $_GET;
unset($filterQuery['page']);
$returnTo = 'dashboard.php' . ($filterQuery ? '?' . http_build_query($filterQuery + ['page' => $page]) : '');

render_header('Dashboard');
?>
<h1 class="h4 mb-3">Leads</h1>

<div class="card filter-card mb-4">
  <div class="card-body">
    <form method="get" action="dashboard.php" class="row g-2">
      <div class="col-md-3">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search company, title, products, keywords" value="<?= e($filters['q']) ?>">
      </div>
      <div class="col-md-2">
        <input type="text" name="company" class="form-control form-control-sm" placeholder="Company" value="<?= e($filters['company']) ?>">
      </div>
      <div class="col-md-2">
        <input type="text" name="domain" class="form-control form-control-sm" placeholder="Email domain, e.g. acme.com" value="<?= e($filters['domain']) ?>">
      </div>
      <div class="col-md-2">
        <input type="text" name="title" class="form-control form-control-sm" placeholder="Title" value="<?= e($filters['title']) ?>">
      </div>
      <div class="col-md-2">
        <select name="seniority" class="form-select form-select-sm">
          <option value="">Seniority (all)</option>
          <?php foreach ($seniorities as $v): ?>
            <option value="<?= e($v) ?>" <?= $filters['seniority'] === $v ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <input type="text" name="departments" class="form-control form-control-sm" placeholder="Department" value="<?= e($filters['departments']) ?>">
      </div>
      <div class="col-md-1">
        <select name="employee_count" class="form-select form-select-sm">
          <option value="">Size</option>
          <?php foreach ($employeeCounts as $v): ?>
            <option value="<?= e($v) ?>" <?= $filters['employee_count'] === $v ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <select name="industry" class="form-select form-select-sm">
          <option value="">Industry (all)</option>
          <?php foreach ($industries as $v): ?>
            <option value="<?= e($v) ?>" <?= $filters['industry'] === $v ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="country" class="form-select form-select-sm">
          <option value="">Country (all)</option>
          <?php foreach ($countries as $v): ?>
            <option value="<?= e($v) ?>" <?= $filters['country'] === $v ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="vertical_id" class="form-select form-select-sm">
          <option value="">Vertical (all)</option>
          <?php foreach ($verticals as $v): ?>
            <option value="<?= (int) $v['id'] ?>" <?= (string) $filters['vertical_id'] === (string) $v['id'] ? 'selected' : '' ?>><?= e($v['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="service_id" class="form-select form-select-sm">
          <option value="">Service (all)</option>
          <?php foreach ($services as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= (string) $filters['service_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="campaign_id" class="form-select form-select-sm">
          <option value="">Filter by campaign...</option>
          <?php foreach ($campaigns as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (string) $filters['campaign_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 form-check d-flex align-items-center">
        <input class="form-check-input me-2" type="checkbox" name="hide_used_in_campaign" value="1" id="hideUsed" <?= $filters['hide_used_in_campaign'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="hideUsed">Hide leads already used in selected campaign</label>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
      </div>
    </form>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
  <div class="text-muted small"><?= number_format($result['total']) ?> leads match this filter (page <?= $result['page'] ?> of <?= $result['totalPages'] ?>)</div>
</div>

<form method="post" action="leads_assign.php" id="leadsForm">
  <?= csrf_field() ?>
  <?php foreach ($filterQuery as $k => $v): if (is_array($v)) continue; ?>
    <input type="hidden" name="filter[<?= e($k) ?>]" value="<?= e((string) $v) ?>">
  <?php endforeach; ?>

  <div class="table-responsive card mb-3">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAllOnPage"></th>
          <th>Company</th><th>Name</th><th>Title</th><th>Email</th><th>Industry</th><th>Country</th><th>Seniority</th><th>Vertical</th><th>Service</th><th>Used in</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($result['rows'] as $lead): ?>
        <tr>
          <td><input type="checkbox" name="lead_ids[]" value="<?= (int) $lead['id'] ?>" class="lead-checkbox"></td>
          <td><?= e($lead['na_company_name']) ?></td>
          <td><?= e($lead['first_name'] . ' ' . $lead['last_name']) ?></td>
          <td><?= e($lead['title']) ?></td>
          <td><?= e($lead['email']) ?></td>
          <td><?= e($lead['industry']) ?></td>
          <td><?= e($lead['country']) ?></td>
          <td><?= e($lead['seniority']) ?></td>
          <td>
            <form method="post" action="lead_update.php">
              <?= csrf_field() ?>
              <input type="hidden" name="field" value="vertical">
              <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
              <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
              <select name="value_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">--</option>
                <?php foreach ($verticals as $v): ?>
                  <option value="<?= (int) $v['id'] ?>" <?= (int) $lead['vertical_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['code']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td>
            <form method="post" action="lead_update.php">
              <?= csrf_field() ?>
              <input type="hidden" name="field" value="service">
              <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
              <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
              <select name="value_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">--</option>
                <?php foreach ($services as $s): ?>
                  <option value="<?= (int) $s['id'] ?>" <?= (int) $lead['service_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['code']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td>
            <?php if ($lead['used_in_campaigns']): ?>
              <span class="badge badge-used" title="<?= e($lead['used_in_campaigns']) ?>">Used</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$result['rows']): ?>
        <tr><td colspan="11" class="text-center text-muted py-4">No leads match this filter.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card mb-4">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <select name="campaign_id" class="form-select form-select-sm" style="max-width: 260px;" required>
        <option value="">Choose a campaign...</option>
        <?php foreach ($campaigns as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" name="mode" value="checked" class="btn btn-sm btn-primary">Assign checked leads to campaign</button>
      <button type="submit" name="mode" value="filter" class="btn btn-sm btn-outline-primary" onclick="return confirm('Assign ALL <?= (int) $result['total'] ?> leads matching the current filter to this campaign?');">Assign all <?= (int) $result['total'] ?> matching leads</button>
      <?php if (!$campaigns): ?><span class="text-muted small">No campaigns yet -- create one on the <a href="campaigns.php">Campaigns</a> page.</span><?php endif; ?>
    </div>
  </div>
</form>

<div class="d-flex gap-2 mb-4">
  <form method="get" action="leads_export_csv.php">
    <?php foreach ($filterQuery as $k => $v): if (is_array($v)) continue; ?>
      <input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>">
    <?php endforeach; ?>
    <button type="submit" class="btn btn-sm btn-outline-secondary">Export all <?= (int) $result['total'] ?> matching leads as CSV</button>
  </form>
</div>

<?php if ($result['totalPages'] > 1): ?>
<nav>
  <ul class="pagination">
    <?php for ($p = 1; $p <= $result['totalPages']; $p++): $q = $filterQuery; $q['page'] = $p; ?>
      <li class="page-item <?= $p === $result['page'] ? 'active' : '' ?>">
        <a class="page-link" href="dashboard.php?<?= http_build_query($q) ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<script src="assets/js/app.js"></script>
<?php render_footer(); ?>
