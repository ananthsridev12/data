<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/CampaignAccess.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $verticalId = (int) ($_POST['vertical_id'] ?? 0) ?: null;
        $serviceId = (int) ($_POST['service_id'] ?? 0) ?: null;

        if ($name === '') {
            flash_set('danger', 'Campaign name is required.');
        } else {
            try {
                // The creator becomes the campaign's owner by default
                // (saleshandy_account_owner_id) -- every member can create
                // and own their own campaigns; an Admin can reassign
                // ownership later from this page's edit modal.
                db()->prepare('INSERT INTO campaigns (company_id, name, description, vertical_id, service_id, created_by, saleshandy_account_owner_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$scope->companyId, $name, $description ?: null, $verticalId, $serviceId, $user['id'], $user['id']]);
                flash_set('success', "Campaign \"{$name}\" created.");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'A campaign with that name already exists.' : 'Could not create campaign.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $campaign = CampaignAccess::loadVisible(db(), $scope, $id);
        if (!$campaign || !CampaignAccess::canMutate($scope, $campaign)) {
            flash_set('danger', 'Campaign not found.');
        } else {
            db()->prepare('UPDATE campaigns SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
            flash_set('success', 'Campaign status updated.');
        }
    } elseif ($action === 'rename') {
        $id = (int) ($_POST['id'] ?? 0);
        $campaign = CampaignAccess::loadVisible(db(), $scope, $id);
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $verticalId = (int) ($_POST['vertical_id'] ?? 0) ?: null;
        $serviceId = (int) ($_POST['service_id'] ?? 0) ?: null;

        if (!$campaign || !CampaignAccess::canMutate($scope, $campaign)) {
            flash_set('danger', 'Campaign not found.');
        } elseif ($name === '') {
            flash_set('danger', 'Campaign name is required.');
        } else {
            try {
                db()->prepare('UPDATE campaigns SET name = ?, description = ?, vertical_id = ?, service_id = ? WHERE id = ?')
                    ->execute([$name, $description ?: null, $verticalId, $serviceId, $id]);
                flash_set('success', "Campaign renamed to \"{$name}\".");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'A campaign with that name already exists.' : 'Could not rename campaign.');
            }
        }
    }

    header('Location: campaigns.php');
    exit;
}

$campaignClauses = ['c.company_id = :scope_company_id'];
$campaignParams = ['scope_company_id' => $scope->companyId];
ScopeFilter::applyOwnerScope($campaignClauses, $campaignParams, $scope, db(), 'c', 'saleshandy_account_owner_id');
$campaignsStmt = db()->prepare(
    "SELECT c.*, u.name AS created_by_name, v.label AS vertical_label, s.label AS service_label,
       owner.name AS owner_name,
       (SELECT COUNT(*) FROM lead_campaign_assignments a WHERE a.campaign_id = c.id) AS lead_count,
       (SELECT COUNT(*) FROM lead_campaign_assignments a WHERE a.campaign_id = c.id AND a.status = 'exported') AS exported_count
     FROM campaigns c
     JOIN users u ON u.id = c.created_by
     LEFT JOIN users owner ON owner.id = c.saleshandy_account_owner_id
     LEFT JOIN verticals v ON v.id = c.vertical_id
     LEFT JOIN services s ON s.id = c.service_id
     WHERE " . implode(' AND ', $campaignClauses) . '
     ORDER BY c.created_at DESC'
);
$campaignsStmt->execute($campaignParams);
$campaigns = $campaignsStmt->fetchAll();
$verticals = LeadRepository::activeLookupOptions(db(), $scope, 'verticals');
$services = LeadRepository::activeLookupOptions(db(), $scope, 'services');

// Touch/step notes already saved via Campaign Flow (campaign_flow.php) --
// shown inline as an accordion below, straight from our own DB, so
// browsing this list never makes a Saleshandy API call per campaign. A
// campaign with no saved notes yet just links to Campaign Flow instead
// of showing an (empty) accordion.
$stepNotesByCampaign = [];
$notesStmt = db()->query(
    'SELECT campaign_id, step_number, purpose FROM campaign_step_notes
      WHERE purpose IS NOT NULL AND purpose <> \'\' ORDER BY campaign_id, step_number'
);
foreach ($notesStmt->fetchAll() as $note) {
    $stepNotesByCampaign[(int) $note['campaign_id']][] = $note;
}

// Live Saleshandy sequence status (active/paused) used to be fetched here
// with a blocking listSequences() call on every page load -- moved to an
// async fetch (campaign_saleshandy_status.php + assets/js/app.js) that
// runs after the page has already rendered, so a slow/unreachable
// Saleshandy no longer holds up the whole page. "Checking..." badges below
// get patched in place once that call resolves; the JS itself no-ops if
// there are no `.sequence-status-badge` elements on the page at all.

render_header('Campaigns');
?>
<h1 class="h4 mb-3">Campaigns</h1>

<div class="card mb-4">
  <div class="card-header">New campaign</div>
  <div class="card-body">
    <form method="post" action="campaigns.php" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-md-3">
        <input type="text" name="name" class="form-control form-control-sm" placeholder="Campaign / Saleshandy sequence name" required>
      </div>
      <div class="col-md-3">
        <input type="text" name="description" class="form-control form-control-sm" placeholder="Description (optional)">
      </div>
      <div class="col-md-2">
        <select name="vertical_id" class="form-select form-select-sm">
          <option value="">Vertical...</option>
          <?php foreach ($verticals as $v): ?>
            <option value="<?= (int) $v['id'] ?>"><?= e($v['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="service_id" class="form-select form-select-sm">
          <option value="">Service pitched...</option>
          <?php foreach ($services as $s): ?>
            <option value="<?= (int) $s['id'] ?>"><?= e($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Create</button>
      </div>
    </form>
  </div>
</div>

<table class="table table-striped bg-white">
  <thead>
    <tr><th>Name</th><th>Owner</th><th>Service</th><th>Leads</th><th>Exported</th><th>Status</th><th>Saleshandy</th><th style="width: 220px;"></th></tr>
  </thead>
  <tbody>
  <?php foreach ($campaigns as $c): $canMutate = CampaignAccess::canMutate($scope, $c); ?>
    <tr>
      <td>
        <span title="Created by <?= e($c['created_by_name']) ?>"><?= e($c['name']) ?></span>
        <?php if ($c['description']): ?><div class="small text-muted"><?= e($c['description']) ?></div><?php endif; ?>
        <?php if (!$canMutate): ?><span class="badge bg-light text-muted border">View only</span><?php endif; ?>
      </td>
      <td><?= e($c['owner_name'] ?? '(unowned)') ?></td>
      <td><?= e($c['vertical_label'] ?? '') ?><?= $c['vertical_label'] && $c['service_label'] ? ' / ' : '' ?><?= e($c['service_label'] ?? '') ?></td>
      <td><a href="dashboard.php?campaign_id=<?= (int) $c['id'] ?>"><?= (int) $c['lead_count'] ?></a></td>
      <td><?= (int) $c['exported_count'] ?></td>
      <td><span class="badge bg-<?= $c['is_active'] ? 'success' : 'secondary' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
      <td>
        <?php if ($c['saleshandy_sequence_id'] && $c['saleshandy_step_id']): ?>
          <span class="badge bg-success">Linked</span>
        <?php elseif ($c['saleshandy_sequence_id']): ?>
          <span class="badge bg-warning">Sequence set, no step</span>
        <?php else: ?>
          <span class="badge bg-secondary">Not linked</span>
        <?php endif; ?>
        <?php if ($c['saleshandy_sequence_id']): ?>
          <span class="badge bg-light text-muted border sequence-status-badge" data-sequence-id="<?= e($c['saleshandy_sequence_id']) ?>">Checking&hellip;</span>
        <?php endif; ?>
        <?php if ($canMutate): ?>
          <a href="campaign_saleshandy_settings.php?campaign_id=<?= (int) $c['id'] ?>" class="small d-block">Configure</a>
        <?php endif; ?>
      </td>
      <td>
        <div class="d-flex gap-1">
          <a class="btn btn-sm btn-outline-primary" href="campaign_leads.php?campaign_id=<?= (int) $c['id'] ?>"><?= $canMutate ? 'Manage leads' : 'View leads' ?></a>
          <?php if (!$canMutate): ?>
            <?php // View-only: no flow config, no export, no edit/toggle for a campaign this user doesn't own. ?>
          <?php elseif (!empty($stepNotesByCampaign[$c['id']])): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#flowRow<?= (int) $c['id'] ?>">Touches</button>
          <?php else: ?>
            <a class="btn btn-sm btn-outline-secondary" href="campaign_flow.php?campaign_id=<?= (int) $c['id'] ?>">Configure flow</a>
          <?php endif; ?>
          <?php if ($canMutate): ?>
          <div class="dropdown">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false">&vellip;</button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="leads_export_csv.php?campaign_export=<?= (int) $c['id'] ?>">Export CSV</a></li>
              <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#renameCampaign<?= (int) $c['id'] ?>">Edit</button></li>
              <li>
                <form method="post" action="campaigns.php" onsubmit="return confirm('Toggle active status for <?= e($c['name']) ?>?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button type="submit" class="dropdown-item"><?= $c['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
              </li>
            </ul>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($canMutate): ?>
        <div class="modal fade" id="renameCampaign<?= (int) $c['id'] ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="post" action="campaigns.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <div class="modal-header">
                  <h5 class="modal-title">Edit campaign</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control form-control-sm" value="<?= e($c['name']) ?>" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control form-control-sm" value="<?= e($c['description'] ?? '') ?>">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Vertical</label>
                    <select name="vertical_id" class="form-select form-select-sm">
                      <option value="">--</option>
                      <?php foreach ($verticals as $v): ?>
                        <option value="<?= (int) $v['id'] ?>" <?= (int) $c['vertical_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['label']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Service pitched</label>
                    <select name="service_id" class="form-select form-select-sm">
                      <option value="">--</option>
                      <?php foreach ($services as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $c['service_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php if ($c['saleshandy_sequence_id']): ?>
                    <p class="text-muted small mb-0">This only renames the campaign here -- it doesn't rename the linked Saleshandy sequence.</p>
                  <?php endif; ?>
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
      </td>
    </tr>
    <?php if (!empty($stepNotesByCampaign[$c['id']])): ?>
    <tr class="collapse" id="flowRow<?= (int) $c['id'] ?>">
      <td colspan="8" class="bg-light">
        <div class="d-flex flex-wrap align-items-center gap-2 py-1">
          <?php foreach ($stepNotesByCampaign[$c['id']] as $i => $note): ?>
            <?php if ($i > 0): ?><span class="text-muted">&rarr;</span><?php endif; ?>
            <span class="badge bg-secondary">T<?= (int) $note['step_number'] ?></span>
            <span class="small"><?= e($note['purpose']) ?></span>
          <?php endforeach; ?>
          <a href="campaign_flow.php?campaign_id=<?= (int) $c['id'] ?>" class="small ms-2">Edit &raquo;</a>
        </div>
      </td>
    </tr>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$campaigns): ?>
    <tr><td colspan="8" class="text-center text-muted py-4">No campaigns yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php render_footer(); ?>
