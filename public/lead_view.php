<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/TagRepository.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$leadId = (int) ($_GET['id'] ?? 0);

$whereClauses = ['l.id = :lead_id', 'l.company_id = :scope_company_id'];
$whereParams = ['lead_id' => $leadId, 'scope_company_id' => $scope->companyId];
ScopeFilter::applyOwnerScope($whereClauses, $whereParams, $scope, db());
$where = implode(' AND ', $whereClauses);

$stmt = db()->prepare(
    "SELECT l.*, v.label AS vertical_label, s.label AS service_label,
            ib.filename AS imported_filename, ib.started_at AS imported_at, iu.name AS imported_by_name,
            du.name AS deleted_by_name,
            (SELECT sd.reason FROM suppressed_domains sd WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1) AND sd.company_id = l.company_id) AS suppressed_reason
       FROM leads l
       LEFT JOIN verticals v ON v.id = l.vertical_id
       LEFT JOIN services s ON s.id = l.service_id
       LEFT JOIN import_batches ib ON ib.id = l.last_import_batch_id
       LEFT JOIN users iu ON iu.id = ib.uploaded_by
       LEFT JOIN users du ON du.id = l.deleted_by
      WHERE {$where}"
);
$stmt->execute($whereParams);
$lead = $stmt->fetch();

if (!$lead) {
    flash_set('danger', 'Lead not found.');
    header('Location: dashboard.php');
    exit;
}

$assignStmt = db()->prepare(
    "SELECT a.*, c.name AS campaign_name, u.name AS assigned_by_name
       FROM lead_campaign_assignments a
       JOIN campaigns c ON c.id = a.campaign_id
       JOIN users u ON u.id = a.assigned_by
      WHERE a.lead_id = ?
      ORDER BY a.assigned_at DESC"
);
$assignStmt->execute([$leadId]);
$assignments = $assignStmt->fetchAll();

$customStmt = db()->prepare(
    "SELECT cf.id, cf.field_key, cf.label, lcv.value
       FROM custom_fields cf
       LEFT JOIN lead_custom_values lcv ON lcv.custom_field_id = cf.id AND lcv.lead_id = ?
      WHERE cf.is_active = 1 AND cf.company_id = ?
      ORDER BY cf.label"
);
$customStmt->execute([$leadId, $scope->companyId]);
$customFieldValues = $customStmt->fetchAll();

$allTags = TagRepository::all(db(), $scope->companyId);
$leadTagIds = TagRepository::tagIdsForLead(db(), $leadId);
$leadTagNames = TagRepository::namesForLead(db(), $leadId);

render_header('Lead detail');

$field = static function (string $label, ?string $value) {
    if ($value === null || $value === '') {
        return;
    }
    ?>
    <div class="col-md-4 mb-2">
      <div class="small text-muted"><?= e($label) ?></div>
      <div><?= e($value) ?></div>
    </div>
    <?php
};
?>
<p class="text-muted"><a href="dashboard.php">&laquo; Back to dashboard</a></p>
<div class="d-flex justify-content-between align-items-start mb-3">
  <h1 class="h4 mb-0"><?= e($lead['first_name'] . ' ' . $lead['last_name']) ?> -- <?= e($lead['na_company_name']) ?></h1>
  <div>
    <?php if ($lead['deleted_at']): ?>
      <span class="badge bg-secondary">Deleted <?= e($lead['deleted_at']) ?> by <?= e($lead['deleted_by_name'] ?? '') ?></span>
      <?php if ($user['role'] === ROLE_ADMIN): ?>
      <form method="post" action="lead_delete.php" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-success">Restore</button>
      </form>
      <?php endif; ?>
    <?php elseif ($user['role'] === ROLE_ADMIN): ?>
      <form method="post" action="lead_delete.php" class="d-inline" onsubmit="return confirm('Delete this lead? It will be hidden everywhere but its campaign history is kept, and this can be undone.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger">Delete lead</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($lead['suppressed_reason'] !== null): ?>
  <div class="alert alert-danger">This lead's domain is suppressed: <?= e($lead['suppressed_reason']) ?></div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    Details
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editLeadModal">Edit Vertical/Service</button>
  </div>
  <div class="card-body row">
    <?php
    $field('Email', $lead['email']);
    $field('Title', $lead['title']);
    $field('Seniority', $lead['seniority']);
    $field('Departments', $lead['departments']);
    $field('Sub Departments', $lead['sub_departments']);
    $field('Category', $lead['category']);
    $field('Products', $lead['products']);
    $field('Vertical', $lead['vertical_label']);
    $field('Service', $lead['service_label']);
    $field('Industry', $lead['industry']);
    $field('Keywords', $lead['keywords']);
    $field('# Employees', $lead['employee_count']);
    $field('Person LinkedIn', $lead['person_linkedin_url']);
    $field('City', $lead['city']);
    $field('State', $lead['state']);
    $field('Country', $lead['country']);
    ?>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Company</div>
  <div class="card-body row">
    <?php
    $field('Company Name for Emails', $lead['company_name_for_emails']);
    $field('Website', $lead['website']);
    $field('Company LinkedIn', $lead['company_linkedin_url']);
    $field('Facebook', $lead['facebook_url']);
    $field('Twitter', $lead['twitter_url']);
    $field('Company Address', $lead['company_address']);
    $field('Company City', $lead['company_city']);
    $field('Company State', $lead['company_state']);
    $field('Company Country', $lead['company_country']);
    $field('Company Phone', $lead['company_phone']);
    $field('Technologies', $lead['technologies']);
    $field('Annual Revenue', $lead['annual_revenue']);
    $field('Total Funding', $lead['total_funding']);
    $field('Latest Funding', $lead['latest_funding']);
    $field('Latest Funding Amount', $lead['latest_funding_amount']);
    $field('Last Raised At', $lead['last_raised_at']);
    ?>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Import</div>
  <div class="card-body row">
    <?php
    $field('Imported from file', $lead['imported_filename']);
    $field('Imported by', $lead['imported_by_name']);
    $field('Imported at', $lead['imported_at']);
    $field('Created', $lead['created_at']);
    $field('Last updated', $lead['updated_at']);
    ?>
  </div>
</div>

<?php if ($customFieldValues): ?>
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    Custom Fields
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editCustomFieldsModal">Edit</button>
  </div>
  <div class="card-body row">
    <?php $anySet = false; foreach ($customFieldValues as $cf): if ($cf['value'] !== null && $cf['value'] !== '') { $anySet = true; } $field($cf['label'], $cf['value']); endforeach; ?>
    <?php if (!$anySet): ?>
      <p class="text-muted small mb-0">No values set yet.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    Tags
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editTagsModal">Edit</button>
  </div>
  <div class="card-body">
    <?php if ($leadTagNames): ?>
      <?php foreach ($leadTagNames as $name): ?>
        <span class="badge bg-secondary me-1"><?= e($name) ?></span>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="text-muted small mb-0">No tags set yet.</p>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Campaign history</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Campaign</th><th>Wave</th><th>Assigned</th><th>Imported to Saleshandy</th><th>Email Sent</th><th>Bounce</th></tr></thead>
      <tbody>
      <?php foreach ($assignments as $a): ?>
        <tr>
          <td><a href="campaign_leads.php?campaign_id=<?= (int) $a['campaign_id'] ?>"><?= e($a['campaign_name']) ?></a></td>
          <td><?php $waveBadge = ['active' => 'success', 'held' => 'warning', 'suppressed' => 'danger']; ?>
            <span class="badge bg-<?= $waveBadge[$a['wave_status']] ?>"><?= e($a['wave_status']) ?></span></td>
          <td class="small text-muted"><?= e($a['assigned_at']) ?> by <?= e($a['assigned_by_name']) ?></td>
          <td><?= in_array($a['status'], ['exported', 'pushed'], true) ? 'Yes (' . e($a['exported_at'] ?? '') . ')' : 'No' ?></td>
          <td><?= $a['email_sent'] ? 'Yes (' . e($a['email_sent_at'] ?? '') . ')' : 'No' ?></td>
          <td><?= e($a['bounce_status']) ?><?= $a['bounce_type'] ? ' -- ' . e($a['bounce_type']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$assignments): ?>
        <tr><td colspan="6" class="text-center text-muted py-3">Not assigned to any campaign yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="editLeadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="lead_update.php">
        <?= csrf_field() ?>
        <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
        <input type="hidden" name="return_to" value="lead_view.php?id=<?= (int) $lead['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title">Edit Vertical/Service</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Vertical</label>
            <select name="vertical_id" class="form-select form-select-sm">
              <option value="">--</option>
              <?php foreach (LeadRepository::activeLookupOptions(db(), $scope, 'verticals') as $v): ?>
                <option value="<?= (int) $v['id'] ?>" <?= (int) $lead['vertical_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Service</label>
            <select name="service_id" class="form-select form-select-sm">
              <option value="">--</option>
              <?php foreach (LeadRepository::activeLookupOptions(db(), $scope, 'services') as $s): ?>
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
<?php if ($customFieldValues): ?>
<div class="modal fade" id="editCustomFieldsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="lead_custom_update.php">
        <?= csrf_field() ?>
        <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
        <input type="hidden" name="return_to" value="lead_view.php?id=<?= (int) $lead['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title">Edit Custom Fields</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php foreach ($customFieldValues as $cf): ?>
            <div class="mb-3">
              <label class="form-label"><?= e($cf['label']) ?></label>
              <input type="text" name="values[<?= e($cf['field_key']) ?>]" class="form-control form-control-sm" value="<?= e($cf['value'] ?? '') ?>">
            </div>
          <?php endforeach; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="modal fade" id="editTagsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="lead_tags_update.php">
        <?= csrf_field() ?>
        <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
        <input type="hidden" name="return_to" value="lead_view.php?id=<?= (int) $lead['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title">Edit Tags</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($allTags): ?>
          <div class="mb-3">
            <label class="form-label">Existing tags (ctrl/cmd-click to select multiple)</label>
            <select name="tag_ids[]" class="form-select form-select-sm" multiple size="6">
              <?php foreach ($allTags as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $leadTagIds, true) ? 'selected' : '' ?>><?= e($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">New tags (comma-separated)</label>
            <input type="text" name="new_tags" class="form-control form-control-sm" placeholder="e.g. Hot Lead, Q3 Push">
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
<?php render_footer(); ?>
