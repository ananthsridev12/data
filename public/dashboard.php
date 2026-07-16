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
    'imported_by' => trim((string) ($_GET['imported_by'] ?? '')),
    'campaign_id' => trim((string) ($_GET['campaign_id'] ?? '')),
    'hide_used_in_campaign' => !empty($_GET['hide_used_in_campaign']),
    'show_suppressed' => !empty($_GET['show_suppressed']),
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
$importers = db()->query(
    "SELECT DISTINCT u.id, u.name FROM users u JOIN import_batches ib ON ib.uploaded_by = u.id ORDER BY u.name"
)->fetchAll();

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
      <div class="col-md-2">
        <select name="imported_by" class="form-select form-select-sm">
          <option value="">Imported by (all)</option>
          <?php foreach ($importers as $imp): ?>
            <option value="<?= (int) $imp['id'] ?>" <?= (string) $filters['imported_by'] === (string) $imp['id'] ? 'selected' : '' ?>><?= e($imp['name']) ?></option>
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
      <div class="col-md-3 form-check d-flex align-items-center">
        <input class="form-check-input me-2" type="checkbox" name="show_suppressed" value="1" id="showSuppressed" <?= $filters['show_suppressed'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="showSuppressed">Show suppressed (bounced-domain) leads</label>
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

<div class="table-responsive card mb-3">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Company</th><th>Name</th><th>Title</th><th>Email</th><th>Industry</th><th>Country</th><th>Seniority</th><th>Vertical</th><th>Service</th><th>Imported by</th><th>Used in</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($result['rows'] as $lead): ?>
        <tr>
          <td><?= e($lead['na_company_name']) ?></td>
          <td><?= e($lead['first_name'] . ' ' . $lead['last_name']) ?></td>
          <td><?= e($lead['title']) ?></td>
          <td><?= e($lead['email']) ?></td>
          <td><?= e($lead['industry']) ?></td>
          <td><?= e($lead['country']) ?></td>
          <td><?= e($lead['seniority']) ?></td>
          <td><?= e($lead['vertical_code'] ?? '') ?></td>
          <td><?= e($lead['service_code'] ?? '') ?></td>
          <td class="small text-muted" title="<?= e($lead['imported_filename'] ?? '') . ' -- ' . e($lead['imported_at'] ?? '') ?>"><?= e($lead['imported_by_name'] ?? '') ?></td>
          <td>
            <?php if ($lead['used_in_campaigns']): ?>
              <span class="badge badge-used" title="<?= e($lead['used_in_campaigns']) ?>">Used</span>
            <?php endif; ?>
            <?php if ($lead['suppressed_reason'] !== null): ?>
              <span class="badge bg-danger" title="<?= e($lead['suppressed_reason']) ?>">Suppressed</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="lead_view.php?id=<?= (int) $lead['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editLead<?= (int) $lead['id'] ?>">Edit</button>
            <?php if ($user['role'] === ROLE_ADMIN): ?>
            <form method="post" action="lead_delete.php" class="d-inline" onsubmit="return confirm('Delete this lead? It will be hidden everywhere but its campaign history is kept, and this can be undone.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
            <?php endif; ?>
            <div class="modal fade" id="editLead<?= (int) $lead['id'] ?>" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog">
                <div class="modal-content">
                  <form method="post" action="lead_update.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                    <div class="modal-header">
                      <h5 class="modal-title"><?= e($lead['first_name'] . ' ' . $lead['last_name']) ?> -- <?= e($lead['na_company_name']) ?></h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                      <div class="mb-3">
                        <label class="form-label">Vertical</label>
                        <select name="vertical_id" class="form-select form-select-sm">
                          <option value="">--</option>
                          <?php foreach ($verticals as $v): ?>
                            <option value="<?= (int) $v['id'] ?>" <?= (int) $lead['vertical_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['label']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="mb-3">
                        <label class="form-label">Service</label>
                        <select name="service_id" class="form-select form-select-sm">
                          <option value="">--</option>
                          <?php foreach ($services as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= (int) $lead['service_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$result['rows']): ?>
        <tr><td colspan="12" class="text-center text-muted py-4">No leads match this filter.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <p class="text-muted small mb-4">To assign leads to a campaign, open the campaign from <a href="campaigns.php">Campaigns</a> and use "Add leads to this campaign" -- filtering and persona selection now happen there so the whole wave-1/bounce workflow lives in one place.</p>

<div class="d-flex gap-2 mb-4">
  <form method="get" action="leads_export_csv.php">
    <?php foreach ($filterQuery as $k => $v): if (is_array($v)) continue; ?>
      <input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>">
    <?php endforeach; ?>
    <button type="submit" class="btn btn-sm btn-outline-secondary">Export all <?= (int) $result['total'] ?> matching leads as CSV</button>
  </form>
  <?php if ($user['role'] === ROLE_ADMIN): ?>
  <form method="post" action="lead_delete.php" onsubmit="return confirm('Delete ALL <?= (int) $result['total'] ?> leads matching the current filter? They will be hidden but restorable from Deleted Leads.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_delete">
    <?php foreach ($filterQuery as $k => $v): if (is_array($v)) continue; ?>
      <input type="hidden" name="filter[<?= e($k) ?>]" value="<?= e((string) $v) ?>">
    <?php endforeach; ?>
    <button type="submit" class="btn btn-sm btn-outline-danger">Delete all <?= (int) $result['total'] ?> matching leads</button>
  </form>
  <?php endif; ?>
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
