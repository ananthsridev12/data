<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/RoleGroupClassifier.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $data = [
            'name' => $name,
            'role_group_id' => (int) ($_POST['role_group_id'] ?? 0) ?: null,
            'vertical_id' => (int) ($_POST['vertical_id'] ?? 0) ?: null,
            'service_id' => (int) ($_POST['service_id'] ?? 0) ?: null,
            'company_country' => implode(', ', (array) ($_POST['company_country'] ?? [])),
            'industry' => implode(', ', (array) ($_POST['industry'] ?? [])),
            'seniority' => implode(', ', (array) ($_POST['seniority'] ?? [])),
            'employee_count' => implode(', ', (array) ($_POST['employee_count'] ?? [])),
            'auto_push_enabled' => !empty($_POST['auto_push_enabled']),
        ];

        if ($name === '') {
            flash_set('danger', 'A name is required.');
        } elseif (!$data['role_group_id']) {
            flash_set('danger', 'Pick a Role Group -- an ICP represents one buyer persona.');
        } else {
            try {
                if ($action === 'create') {
                    IcpRepository::create(db(), $data, $admin['id']);
                    flash_set('success', "\"{$name}\" created -- now link 2 or more campaigns to it below with a percentage split.");
                } else {
                    IcpRepository::update(db(), (int) ($_POST['id'] ?? 0), $data);
                    flash_set('success', "\"{$name}\" updated.");
                }
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'That name already exists.' : 'Could not save ICP segment.');
            }
        }
    } elseif ($action === 'toggle_active') {
        IcpRepository::toggleActive(db(), (int) ($_POST['id'] ?? 0));
        flash_set('success', 'Status updated.');
    } elseif ($action === 'add_link') {
        $icpId = (int) ($_POST['icp_id'] ?? 0);
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        $percentage = (int) ($_POST['percentage'] ?? 0);
        if (!$campaignId || $percentage < 1 || $percentage > 100) {
            flash_set('danger', 'Pick a campaign and a percentage between 1 and 100.');
        } else {
            try {
                IcpRepository::addLink(db(), $icpId, $campaignId, $percentage);
                flash_set('success', 'Campaign linked.');
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'That campaign is already linked to this ICP.' : 'Could not link campaign.');
            }
        }
    } elseif ($action === 'remove_link') {
        IcpRepository::removeLink(db(), (int) ($_POST['link_id'] ?? 0));
        flash_set('success', 'Link removed.');
    }

    header('Location: icp_segments.php');
    exit;
}

$icps = IcpRepository::list(db());
$roleGroups = LeadRepository::activeLookupOptions(db(), 'role_groups');
$verticals = LeadRepository::activeLookupOptions(db(), 'verticals');
$services = LeadRepository::activeLookupOptions(db(), 'services');
$campaigns = db()->query("SELECT id, name FROM campaigns WHERE saleshandy_sequence_id IS NOT NULL ORDER BY name")->fetchAll();

$companyCountries = LeadRepository::distinctValues(db(), 'company_country');
$industries = LeadRepository::distinctValues(db(), 'industry');
$seniorities = LeadRepository::distinctValues(db(), 'seniority');
$employeeCounts = LeadRepository::distinctValues(db(), 'employee_count');

$linksByIcp = [];
foreach ($icps as $icp) {
    $linksByIcp[(int) $icp['id']] = IcpRepository::links(db(), (int) $icp['id']);
}

// Active Role Groups (personas) with zero ICPs referencing them at all
// yet (not just zero *active* ones -- even an unfinished draft ICP still
// counts as "started"), most classified leads first -- so the gap most
// worth closing shows up on top instead of just a flat A-Z list.
$unmappedPersonas = db()->query(
    "SELECT rg.id, rg.label,
        (SELECT COUNT(*) FROM leads l WHERE l.role_group_id = rg.id AND l.deleted_at IS NULL) AS lead_count
       FROM role_groups rg
      WHERE rg.is_active = 1
        AND NOT EXISTS (SELECT 1 FROM icp_segments icp WHERE icp.role_group_id = rg.id)
      ORDER BY lead_count DESC, rg.label"
)->fetchAll();

render_header('ICP Segments');
?>
<h1 class="h4 mb-3">ICP Segments</h1>
<p class="text-muted">
  A named, reusable match rule for one buyer persona (company country, vertical, service, seniority, employee
  count, and one Role Group) linked to 2 or more campaigns with a percentage split -- for A/B testing the same
  persona across campaigns. A cron job (<code>icp_distribution_cron.php</code>) periodically finds newly
  matching, not-yet-assigned leads and distributes them across the linked campaigns according to the weights.
  An ICP only runs once its linked campaigns' percentages sum to exactly 100 -- one still short of 100% is
  simply skipped by the cron until fixed, not treated as an error.
</p>

<?php if ($unmappedPersonas): ?>
<div class="card mb-4 border-warning">
  <div class="card-header">
    Personas without an ICP yet
    <?= info_icon('Active Role Groups that have no ICP built for them at all, most classified leads first. These personas are being recognized on import but nothing is targeting them via a campaign split -- click "Create ICP" to jump to the Add form with this persona pre-selected.') ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Role Group (persona)</th><th class="text-end">Leads classified</th><th style="width: 140px;"></th></tr></thead>
      <tbody>
        <?php foreach ($unmappedPersonas as $p): ?>
          <tr>
            <td><?= e($p['label']) ?></td>
            <td class="text-end"><?= number_format($p['lead_count']) ?></td>
            <td><a href="#addIcpForm" class="btn btn-sm btn-outline-warning" onclick="document.getElementById('addIcpRoleGroup').value='<?= (int) $p['id'] ?>'; document.getElementById('addIcpName').focus();">Create ICP</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card mb-4" id="addIcpForm">
  <div class="card-header">Add an ICP segment</div>
  <div class="card-body">
    <form method="post" action="icp_segments.php" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-md-3">
        <input type="text" name="name" id="addIcpName" class="form-control form-control-sm" placeholder="Name, e.g. Healthcare CFOs" required>
      </div>
      <div class="col-md-2">
        <select name="role_group_id" id="addIcpRoleGroup" class="form-select form-select-sm" required>
          <option value="">Role Group (persona)...</option>
          <?php foreach ($roleGroups as $rg): ?>
            <option value="<?= (int) $rg['id'] ?>"><?= e($rg['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="vertical_id" class="form-select form-select-sm">
          <option value="">Vertical (any)</option>
          <?php foreach ($verticals as $v): ?>
            <option value="<?= (int) $v['id'] ?>"><?= e($v['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="service_id" class="form-select form-select-sm">
          <option value="">Service (any)</option>
          <?php foreach ($services as $s): ?>
            <option value="<?= (int) $s['id'] ?>"><?= e($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 form-check pt-2">
        <input class="form-check-input" type="checkbox" name="auto_push_enabled" value="1" id="autoPushNew">
        <label class="form-check-label small" for="autoPushNew">Auto-push to Saleshandy after assignment</label>
      </div>
      <div class="col-md-2">
        <?php render_multiselect_filter('company_country', 'Company Country', $companyCountries, []); ?>
      </div>
      <div class="col-md-2">
        <?php render_multiselect_filter('industry', 'Industry', $industries, []); ?>
      </div>
      <div class="col-md-2">
        <?php render_multiselect_filter('seniority', 'Seniority', $seniorities, []); ?>
      </div>
      <div class="col-md-2">
        <?php render_multiselect_filter('employee_count', 'Employee Count', $employeeCounts, []); ?>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Add</button>
      </div>
    </form>
  </div>
</div>

<?php foreach ($icps as $icp):
    $links = $linksByIcp[(int) $icp['id']];
    $total = $icp['percentage_total'];
    $ready = $total === 100;
?>
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>
        <?= e($icp['name']) ?>
        <span class="badge bg-<?= $icp['is_active'] ? 'success' : 'secondary' ?> ms-1"><?= $icp['is_active'] ? 'Active' : 'Inactive' ?></span>
        <?php if ($icp['is_active']): ?>
          <span class="badge bg-<?= $ready ? 'success' : 'warning text-dark' ?> ms-1"><?= $ready ? 'Ready -- ' . $total . '%' : 'Not running -- links sum to ' . $total . '%, needs 100%' ?></span>
        <?php endif; ?>
      </span>
      <span>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editIcp<?= (int) $icp['id'] ?>">Edit</button>
        <form method="post" action="icp_segments.php" class="d-inline" onsubmit="return confirm('Toggle active status for <?= e($icp['name']) ?>?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_active">
          <input type="hidden" name="id" value="<?= (int) $icp['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $icp['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
      </span>
    </div>
    <div class="card-body">
      <p class="small text-muted mb-2">
        Persona: <strong><?= e($icp['role_group_label'] ?? 'Unclassified') ?></strong>
        <?php if ($icp['vertical_label']): ?> | Vertical: <?= e($icp['vertical_label']) ?><?php endif; ?>
        <?php if ($icp['service_label']): ?> | Service: <?= e($icp['service_label']) ?><?php endif; ?>
        <?php if ($icp['company_country']): ?> | Country: <?= e($icp['company_country']) ?><?php endif; ?>
        <?php if ($icp['industry']): ?> | Industry: <?= e($icp['industry']) ?><?php endif; ?>
        <?php if ($icp['seniority']): ?> | Seniority: <?= e($icp['seniority']) ?><?php endif; ?>
        <?php if ($icp['employee_count']): ?> | Size: <?= e($icp['employee_count']) ?><?php endif; ?>
        <?php if ($icp['auto_push_enabled']): ?> | <span class="badge bg-info text-dark">Auto-push enabled</span><?php endif; ?>
      </p>

      <table class="table table-sm mb-2">
        <thead><tr><th>Linked campaign</th><th class="text-end">Percentage</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($links as $link): ?>
            <tr>
              <td><?= e($link['campaign_name']) ?></td>
              <td class="text-end"><?= (int) $link['percentage'] ?>%</td>
              <td>
                <form method="post" action="icp_segments.php" class="d-inline" onsubmit="return confirm('Remove this campaign link?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="remove_link">
                  <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$links): ?>
            <tr><td colspan="3" class="text-center text-muted py-2">No campaigns linked yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <form method="post" action="icp_segments.php" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_link">
        <input type="hidden" name="icp_id" value="<?= (int) $icp['id'] ?>">
        <div class="col-md-6">
          <select name="campaign_id" class="form-select form-select-sm" required>
            <option value="">Link a campaign...</option>
            <?php foreach ($campaigns as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <input type="number" name="percentage" class="form-control form-control-sm" placeholder="% of matches" min="1" max="100" required>
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-outline-primary btn-sm w-100">Add link</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal fade" id="editIcp<?= (int) $icp['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <form method="post" action="icp_segments.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int) $icp['id'] ?>">
          <div class="modal-header">
            <h5 class="modal-title">Edit "<?= e($icp['name']) ?>"</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body row g-2">
            <div class="col-md-6">
              <label class="form-label small">Name</label>
              <input type="text" name="name" class="form-control form-control-sm" value="<?= e($icp['name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Role Group (persona)</label>
              <select name="role_group_id" class="form-select form-select-sm" required>
                <option value="">...</option>
                <?php foreach ($roleGroups as $rg): ?>
                  <option value="<?= (int) $rg['id'] ?>" <?= (int) $icp['role_group_id'] === (int) $rg['id'] ? 'selected' : '' ?>><?= e($rg['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Vertical</label>
              <select name="vertical_id" class="form-select form-select-sm">
                <option value="">Any</option>
                <?php foreach ($verticals as $v): ?>
                  <option value="<?= (int) $v['id'] ?>" <?= (int) $icp['vertical_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Service</label>
              <select name="service_id" class="form-select form-select-sm">
                <option value="">Any</option>
                <?php foreach ($services as $s): ?>
                  <option value="<?= (int) $s['id'] ?>" <?= (int) $icp['service_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small mb-0">Company Country</label>
              <?php render_multiselect_filter('company_country', 'Company Country', $companyCountries, RoleGroupClassifier::parseKeywords($icp['company_country'] ?? '')); ?>
            </div>
            <div class="col-md-6">
              <label class="form-label small mb-0">Industry</label>
              <?php render_multiselect_filter('industry', 'Industry', $industries, RoleGroupClassifier::parseKeywords($icp['industry'] ?? '')); ?>
            </div>
            <div class="col-md-6">
              <label class="form-label small mb-0">Seniority</label>
              <?php render_multiselect_filter('seniority', 'Seniority', $seniorities, RoleGroupClassifier::parseKeywords($icp['seniority'] ?? '')); ?>
            </div>
            <div class="col-md-6">
              <label class="form-label small mb-0">Employee Count</label>
              <?php render_multiselect_filter('employee_count', 'Employee Count', $employeeCounts, RoleGroupClassifier::parseKeywords($icp['employee_count'] ?? '')); ?>
            </div>
            <div class="col-12 form-check">
              <input class="form-check-input" type="checkbox" name="auto_push_enabled" value="1" id="autoPush<?= (int) $icp['id'] ?>" <?= $icp['auto_push_enabled'] ? 'checked' : '' ?>>
              <label class="form-check-label small" for="autoPush<?= (int) $icp['id'] ?>">Auto-push to Saleshandy after assignment (otherwise assignment only -- push manually per campaign as usual)</label>
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
<?php endforeach; ?>
<?php if (!$icps): ?>
  <p class="text-muted text-center py-4">No ICP segments yet.</p>
<?php endif; ?>

<?php render_footer(); ?>
