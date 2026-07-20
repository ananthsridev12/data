<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

$admin = require_admin();

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
                db()->prepare('INSERT INTO campaigns (name, description, vertical_id, service_id, created_by) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$name, $description ?: null, $verticalId, $serviceId, $admin['id']]);
                flash_set('success', "Campaign \"{$name}\" created.");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'A campaign with that name already exists.' : 'Could not create campaign.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE campaigns SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Campaign status updated.');
    } elseif ($action === 'rename') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $verticalId = (int) ($_POST['vertical_id'] ?? 0) ?: null;
        $serviceId = (int) ($_POST['service_id'] ?? 0) ?: null;

        if ($name === '') {
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

$campaigns = db()->query(
    "SELECT c.*, u.name AS created_by_name, v.label AS vertical_label, s.label AS service_label,
       (SELECT COUNT(*) FROM lead_campaign_assignments a WHERE a.campaign_id = c.id) AS lead_count,
       (SELECT COUNT(*) FROM lead_campaign_assignments a WHERE a.campaign_id = c.id AND a.status = 'exported') AS exported_count
     FROM campaigns c
     JOIN users u ON u.id = c.created_by
     LEFT JOIN verticals v ON v.id = c.vertical_id
     LEFT JOIN services s ON s.id = c.service_id
     ORDER BY c.created_at DESC"
)->fetchAll();
$verticals = LeadRepository::activeLookupOptions(db(), 'verticals');
$services = LeadRepository::activeLookupOptions(db(), 'services');

// Live Saleshandy sequence status (active/paused) for linked campaigns --
// one listSequences() call covers every linked campaign on this page, so
// it's cheap even with many campaigns. Left empty (badge just omitted) if
// Saleshandy isn't configured or the call fails -- this page must never
// break because of it.
$liveSequenceActive = [];
if (array_filter($campaigns, static fn(array $c) => $c['saleshandy_sequence_id'])) {
    try {
        $config = require __DIR__ . '/../app/config/config.php';
        $client = SaleshandyClient::fromConfig($config);
        foreach ($client->listSequences() as $seq) {
            $liveSequenceActive[$seq['id']] = (bool) ($seq['active'] ?? false);
        }
    } catch (SaleshandyApiException $ex) {
        // Not configured or unreachable -- badges just won't show, page still works.
    }
}

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
    <tr><th>Name</th><th>Description</th><th>Vertical</th><th>Service pitched</th><th>Leads assigned</th><th>Exported</th><th>Created by</th><th>Status</th><th>Saleshandy</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($campaigns as $c): ?>
    <tr>
      <td><?= e($c['name']) ?></td>
      <td><?= e($c['description'] ?? '') ?></td>
      <td><?= e($c['vertical_label'] ?? '') ?></td>
      <td><?= e($c['service_label'] ?? '') ?></td>
      <td><a href="dashboard.php?campaign_id=<?= (int) $c['id'] ?>"><?= (int) $c['lead_count'] ?></a></td>
      <td><?= (int) $c['exported_count'] ?></td>
      <td><?= e($c['created_by_name']) ?></td>
      <td><span class="badge bg-<?= $c['is_active'] ? 'success' : 'secondary' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
      <td>
        <?php if ($c['saleshandy_sequence_id'] && $c['saleshandy_step_id']): ?>
          <span class="badge bg-success">Linked</span>
        <?php elseif ($c['saleshandy_sequence_id']): ?>
          <span class="badge bg-warning">Sequence set, no step</span>
        <?php else: ?>
          <span class="badge bg-secondary">Not linked</span>
        <?php endif; ?>
        <?php if ($c['saleshandy_sequence_id'] && array_key_exists($c['saleshandy_sequence_id'], $liveSequenceActive)): ?>
          <?php if ($liveSequenceActive[$c['saleshandy_sequence_id']]): ?>
            <span class="badge bg-success">Sequence active</span>
          <?php else: ?>
            <span class="badge bg-danger" title="This sequence is paused/archived on Saleshandy -- pushing here won't do anything until it's reactivated there.">Sequence paused</span>
          <?php endif; ?>
        <?php elseif ($c['saleshandy_sequence_id']): ?>
          <span class="badge bg-light text-muted border" title="Could not reach Saleshandy to check live status.">Status unknown</span>
        <?php endif; ?>
        <a href="campaign_saleshandy_settings.php?campaign_id=<?= (int) $c['id'] ?>" class="small d-block">Configure</a>
      </td>
      <td class="d-flex gap-1">
        <a class="btn btn-sm btn-outline-secondary" href="campaign_leads.php?campaign_id=<?= (int) $c['id'] ?>">Manage leads</a>
        <a class="btn btn-sm btn-outline-secondary" href="leads_export_csv.php?campaign_export=<?= (int) $c['id'] ?>">Export CSV</a>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#renameCampaign<?= (int) $c['id'] ?>">Edit</button>
        <form method="post" action="campaigns.php" onsubmit="return confirm('Toggle active status for <?= e($c['name']) ?>?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_active">
          <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $c['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
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
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$campaigns): ?>
    <tr><td colspan="10" class="text-center text-muted py-4">No campaigns yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php render_footer(); ?>
