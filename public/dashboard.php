<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/ColumnPreferences.php';
require_once __DIR__ . '/../app/includes/TagRepository.php';
require_once __DIR__ . '/../app/includes/EmployeeCountRangeClassifier.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$columns = ColumnPreferences::getForUser(db(), $user['id'], 'dashboard');

$multiParam = static function (string $key): array {
    return array_values(array_filter(array_map('trim', (array) ($_GET[$key] ?? []))));
};

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'company' => trim((string) ($_GET['company'] ?? '')),
    'title' => $multiParam('title'),
    'seniority' => $multiParam('seniority'),
    'departments' => $multiParam('departments'),
    'industry' => $multiParam('industry'),
    'country' => $multiParam('country'),
    'employee_count_range' => $multiParam('employee_count_range'),
    'domain' => trim((string) ($_GET['domain'] ?? '')),
    'vertical_id' => trim((string) ($_GET['vertical_id'] ?? '')),
    'service_id' => trim((string) ($_GET['service_id'] ?? '')),
    'role_group_id' => trim((string) ($_GET['role_group_id'] ?? '')),
    'country_group_id' => trim((string) ($_GET['country_group_id'] ?? '')),
    'imported_by' => trim((string) ($_GET['imported_by'] ?? '')),
    'campaign_id' => trim((string) ($_GET['campaign_id'] ?? '')),
    'hide_used_in_campaign' => !empty($_GET['hide_used_in_campaign']),
    'show_suppressed' => !empty($_GET['show_suppressed']),
    // assigned_campaign_id/imported/email_sent/sequence_completed aren't
    // exposed on this page's own filter form -- they exist so an
    // Analytics drill-through link (see analytics.php) can land here with
    // the exact slice it showed a number for. Still fully valid to type
    // into the URL by hand.
    'company_country' => $multiParam('company_country'),
    'assigned_campaign_id' => trim((string) ($_GET['assigned_campaign_id'] ?? '')),
    'imported' => trim((string) ($_GET['imported'] ?? '')),
    'email_sent' => trim((string) ($_GET['email_sent'] ?? '')),
    'sequence_completed' => trim((string) ($_GET['sequence_completed'] ?? '')),
];
$page = max(1, (int) ($_GET['page'] ?? 1));

// A completely unfiltered load would COUNT(*) and render page 1 of the
// *entire* leads table on every single visit -- slow on a large table,
// and "every lead in the system" is rarely what's actually wanted.
// Same protective gate as campaign_select_leads.php: require at least
// one real filter before running the query, prompting instead. A
// campaign_id alone (without hide_used_in_campaign, which currently
// makes it a no-op) still counts as "real" -- picking a campaign in the
// dropdown is clearly an intentional filter attempt even though it
// doesn't yet narrow anything by itself.
$hasRealFilter = $filters['q'] !== '' || $filters['company'] !== '' || $filters['domain'] !== ''
    || $filters['title'] || $filters['seniority'] || $filters['departments']
    || $filters['industry'] || $filters['country'] || $filters['employee_count_range']
    || $filters['vertical_id'] !== '' || $filters['service_id'] !== '' || $filters['role_group_id'] !== '' || $filters['country_group_id'] !== '' || $filters['imported_by'] !== ''
    || $filters['campaign_id'] !== '' || $filters['company_country']
    || $filters['assigned_campaign_id'] !== '' || $filters['imported'] !== '' || $filters['email_sent'] !== '' || $filters['sequence_completed'] !== '';

if ($hasRealFilter) {
    $result = LeadRepository::search(db(), $scope, $filters, $page);
} else {
    $result = ['rows' => [], 'total' => 0, 'page' => 1, 'perPage' => 0, 'totalPages' => 1];
}

$titleOptions = LeadRepository::distinctValues(db(), $scope, 'title', 1000);
$seniorities = LeadRepository::distinctValues(db(), $scope, 'seniority');
$departmentOptions = LeadRepository::distinctValues(db(), $scope, 'departments');
$industries = LeadRepository::distinctValues(db(), $scope, 'industry');
$countries = LeadRepository::distinctValues(db(), $scope, 'country');
$companyCountries = LeadRepository::distinctValues(db(), $scope, 'company_country');
$employeeCountRanges = EmployeeCountRangeClassifier::allLabels();
$verticals = LeadRepository::activeLookupOptions(db(), $scope, 'verticals');
$services = LeadRepository::activeLookupOptions(db(), $scope, 'services');
$roleGroups = LeadRepository::activeLookupOptions(db(), $scope, 'role_groups');
$countryGroups = LeadRepository::activeLookupOptions(db(), $scope, 'country_groups');
// Pre-existing bug fixed in passing: this used to have no company_id
// scoping at all (a cross-tenant leak, showing every company's active
// campaign names in this dropdown) -- scoped now, and also excludes
// soft-deleted campaigns (sql/045_campaign_soft_delete.sql) so a deleted
// one can't be picked as a filter here either.
$campaignsFilterStmt = db()->prepare('SELECT id, name FROM campaigns WHERE company_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY name');
$campaignsFilterStmt->execute([$scope->companyId]);
$campaigns = $campaignsFilterStmt->fetchAll();
$importers = db()->query(
    "SELECT DISTINCT u.id, u.name FROM users u JOIN import_batches ib ON ib.uploaded_by = u.id ORDER BY u.name"
)->fetchAll();
$existingTags = TagRepository::all(db(), $scope->companyId);

// Rebuild the current filter query string (minus `page`) for pagination links
// and for the "export/assign everything matching this filter" hidden fields.
$filterQuery = $_GET;
unset($filterQuery['page']);
$returnTo = 'dashboard.php' . ($filterQuery ? '?' . http_build_query($filterQuery + ['page' => $page]) : '');

render_header('Dashboard');
?>
<h1 class="h4 mb-3">Leads</h1>
<datalist id="existingTagNames">
  <?php foreach ($existingTags as $t): ?>
    <option value="<?= e($t['name']) ?>">
  <?php endforeach; ?>
</datalist>

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
        <?php render_multiselect_filter('title', 'Title', $titleOptions, $filters['title']); ?>
      </div>
      <div class="col-md-2">
        <?php render_multiselect_filter('seniority', 'Seniority', $seniorities, $filters['seniority']); ?>
      </div>
      <div class="col-md-2">
        <?php render_multiselect_filter('departments', 'Department', $departmentOptions, $filters['departments']); ?>
      </div>
      <div class="col-md-1">
        <?php render_multiselect_filter('employee_count_range', 'Size', $employeeCountRanges, $filters['employee_count_range']); ?>
      </div>

      <div class="col-md-2">
        <?php render_multiselect_filter('industry', 'Industry', $industries, $filters['industry']); ?>
      </div>
      <div class="col-md-2">
        <?php render_multiselect_filter('country', 'Country', $countries, $filters['country']); ?>
      </div>
      <div class="col-md-2">
        <?php render_multiselect_filter('company_country', 'Company Country', $companyCountries, $filters['company_country']); ?>
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
        <select name="role_group_id" class="form-select form-select-sm">
          <option value="">Role Group (all)</option>
          <?php foreach ($roleGroups as $rg): ?>
            <option value="<?= (int) $rg['id'] ?>" <?= (string) $filters['role_group_id'] === (string) $rg['id'] ? 'selected' : '' ?>><?= e($rg['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="country_group_id" class="form-select form-select-sm">
          <option value="">Country Group (all)</option>
          <?php foreach ($countryGroups as $cg): ?>
            <option value="<?= (int) $cg['id'] ?>" <?= (string) $filters['country_group_id'] === (string) $cg['id'] ? 'selected' : '' ?>><?= e($cg['label']) ?></option>
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
      <div class="col-md-2">
        <select name="imported" class="form-select form-select-sm">
          <option value="">Imported to Saleshandy (all)</option>
          <option value="1" <?= $filters['imported'] === '1' ? 'selected' : '' ?>>Yes</option>
          <option value="0" <?= $filters['imported'] === '0' ? 'selected' : '' ?>>No</option>
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

<?php if (!$hasRealFilter): ?>
<div class="alert alert-warning">
  Apply at least one filter above (company, domain, title, industry, country, etc.) to load leads -- this page
  won't scan and display the whole leads table unfiltered.
</div>
<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-2">
  <div class="text-muted small"><?= number_format($result['total']) ?> leads match this filter (page <?= $result['page'] ?> of <?= $result['totalPages'] ?>)</div>
  <a href="column_settings.php?page=dashboard&return_to=<?= urlencode($returnTo) ?>" class="btn btn-sm btn-outline-secondary">Manage columns</a>
</div>

<?php
$renderCell = static function (string $key, array $lead) {
    switch ($key) {
        case 'company': ?><td><?= e($lead['na_company_name']) ?></td><?php break;
        case 'name': ?><td><?= e($lead['first_name'] . ' ' . $lead['last_name']) ?></td><?php break;
        case 'title': ?><td><?= e($lead['title']) ?></td><?php break;
        case 'email': ?><td><?= e($lead['email']) ?></td><?php break;
        case 'industry': ?><td><?= e($lead['industry']) ?></td><?php break;
        case 'country': ?><td><?= e($lead['country']) ?></td><?php break;
        case 'seniority': ?><td><?= e($lead['seniority']) ?></td><?php break;
        case 'vertical': ?><td><?= e($lead['vertical_code'] ?? '') ?></td><?php break;
        case 'service': ?><td><?= e($lead['service_code'] ?? '') ?></td><?php break;
        case 'role_group': ?><td><?= e($lead['role_group_label'] ?? '') ?></td><?php break;
        case 'country_group': ?><td><?= e($lead['country_group_label'] ?? '') ?></td><?php break;
        case 'imported_by': ?>
            <td class="small text-muted" title="<?= e($lead['imported_filename'] ?? '') . ' -- ' . e($lead['imported_at'] ?? '') ?>"><?= e($lead['imported_by_name'] ?? '') ?></td>
            <?php break;
        case 'used_in': ?>
            <td>
              <?php if ($lead['used_in_campaigns']): ?>
                <span class="badge badge-used" title="This lead already belongs to: <?= e($lead['used_in_campaigns']) ?>">Used in <?= e($lead['used_in_campaigns']) ?></span>
              <?php endif; ?>
              <?php if ($lead['suppressed_reason'] !== null): ?>
                <span class="badge bg-danger" title="<?= e($lead['suppressed_reason']) ?>">Suppressed</span>
              <?php endif; ?>
              <?php if ($lead['pending_elsewhere_campaigns']): ?>
                <span class="badge bg-warning text-dark" title="Another persona at this account is pending delivery in: <?= e($lead['pending_elsewhere_campaigns']) ?> -- can't be added to a new campaign until that resolves.">Account Delivery Checking (Pending)</span>
              <?php endif; ?>
            </td>
            <?php break;
        case 'email_verification': ?>
            <td><?= render_verification_badge($lead['email_verification_status']) ?></td>
            <?php break;
        case 'service_pitch_sequence': ?>
            <td class="small"><?= $lead['service_pitch_sequence'] ? e($lead['service_pitch_sequence']) : '' ?></td>
            <?php break;
    }
};
$visibleColumns = array_values(array_filter($columns, static fn(array $c) => $c['visible']));
?>

<div class="table-responsive card mb-3">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th><input type="checkbox" class="selectAllInTable"></th>
          <?php foreach ($visibleColumns as $col): ?>
            <th><?= e($col['label']) ?></th>
          <?php endforeach; ?>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($result['rows'] as $lead): ?>
        <tr>
          <td><input type="checkbox" name="lead_ids[]" value="<?= (int) $lead['id'] ?>" class="lead-checkbox" form="bulkAddCampaignForm"></td>
          <?php foreach ($visibleColumns as $col): ?>
            <?php $renderCell($col['key'], $lead); ?>
          <?php endforeach; ?>
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
                      <div class="mb-3">
                        <label class="form-label">Role Group <span class="text-muted small">(auto-classified from title on import; override here if needed)</span></label>
                        <select name="role_group_id" class="form-select form-select-sm">
                          <option value="">-- Unclassified --</option>
                          <?php foreach ($roleGroups as $rg): ?>
                            <option value="<?= (int) $rg['id'] ?>" <?= (int) $lead['role_group_id'] === (int) $rg['id'] ? 'selected' : '' ?>><?= e($rg['label']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="mb-3">
                        <label class="form-label">Country Group <span class="text-muted small">(auto-classified from Company Country on import; override here if needed)</span></label>
                        <select name="country_group_id" class="form-select form-select-sm">
                          <option value="">-- Unmapped --</option>
                          <?php foreach ($countryGroups as $cg): ?>
                            <option value="<?= (int) $cg['id'] ?>" <?= (int) $lead['country_group_id'] === (int) $cg['id'] ? 'selected' : '' ?>><?= e($cg['label']) ?></option>
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
        <tr><td colspan="<?= count($visibleColumns) + 2 ?>" class="text-center text-muted py-4">No leads match this filter.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <form method="post" action="lead_bulk_campaign_add.php" id="bulkAddCampaignForm" class="d-flex gap-2 align-items-center"
            onsubmit="return confirm('Add every checked lead to this campaign?');">
        <?= csrf_field() ?>
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <select name="campaign_id" class="form-select form-select-sm" style="max-width: 240px;" required>
          <option value="">Add checked leads to campaign...</option>
          <?php foreach ($campaigns as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-outline-primary">Add checked leads</button>
      </form>
      <?= info_icon('Tick boxes in the table above (or use the header checkbox to select every lead on this page), pick a campaign, then click Add. Same wave-1 domain-safety gate as Campaigns → Add leads to this campaign applies: if two checked leads share a company, one becomes the wave-1 leader and the other is held pending that outcome. For larger, filter-driven picks with title-priority control, use "Add leads to this campaign" from the campaign itself instead.') ?>
    </div>
  </div>

<div class="d-flex gap-2 mb-4 align-items-start flex-wrap">
  <form method="get" action="leads_export_csv.php">
    <?php render_hidden_filter_fields($filterQuery); ?>
    <button type="submit" class="btn btn-sm btn-outline-secondary">Export all <?= (int) $result['total'] ?> matching leads as CSV</button>
  </form>
  <form method="post" action="lead_bulk_tag.php" class="d-flex gap-1"
        onsubmit="return confirm('Add this tag to all <?= (int) $result['total'] ?> leads matching the current filter?');">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
    <?php render_hidden_filter_fields($filterQuery, 'filter'); ?>
    <input type="text" name="tag_name" class="form-control form-control-sm" list="existingTagNames" placeholder="Tag name" required style="max-width: 160px;">
    <button type="submit" class="btn btn-sm btn-outline-secondary">Tag all <?= (int) $result['total'] ?> matching leads</button>
  </form>
  <?php if ($user['role'] === ROLE_ADMIN): ?>
  <form method="post" action="lead_delete.php" onsubmit="return confirm('Delete ALL <?= (int) $result['total'] ?> leads matching the current filter? They will be hidden but restorable from Deleted Leads.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_delete">
    <?php render_hidden_filter_fields($filterQuery, 'filter'); ?>
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
<?php endif; // $hasRealFilter ?>

<?php render_footer(); ?>
