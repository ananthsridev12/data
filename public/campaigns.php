<?php
require_once __DIR__ . '/bootstrap.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($name === '') {
            flash_set('danger', 'Campaign name is required.');
        } else {
            try {
                db()->prepare('INSERT INTO campaigns (name, description, created_by) VALUES (?, ?, ?)')
                    ->execute([$name, $description ?: null, $admin['id']]);
                flash_set('success', "Campaign \"{$name}\" created.");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'A campaign with that name already exists.' : 'Could not create campaign.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE campaigns SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Campaign status updated.');
    }

    header('Location: campaigns.php');
    exit;
}

$campaigns = db()->query(
    "SELECT c.*, u.name AS created_by_name,
       (SELECT COUNT(*) FROM lead_campaign_assignments a WHERE a.campaign_id = c.id) AS lead_count,
       (SELECT COUNT(*) FROM lead_campaign_assignments a WHERE a.campaign_id = c.id AND a.status = 'exported') AS exported_count
     FROM campaigns c
     JOIN users u ON u.id = c.created_by
     ORDER BY c.created_at DESC"
)->fetchAll();

render_header('Campaigns');
?>
<h1 class="h4 mb-3">Campaigns</h1>

<div class="card mb-4">
  <div class="card-header">New campaign</div>
  <div class="card-body">
    <form method="post" action="campaigns.php" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-md-4">
        <input type="text" name="name" class="form-control form-control-sm" placeholder="Campaign / Saleshandy sequence name" required>
      </div>
      <div class="col-md-5">
        <input type="text" name="description" class="form-control form-control-sm" placeholder="Description (optional)">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Create</button>
      </div>
    </form>
  </div>
</div>

<table class="table table-striped bg-white">
  <thead>
    <tr><th>Name</th><th>Description</th><th>Leads assigned</th><th>Exported</th><th>Created by</th><th>Status</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($campaigns as $c): ?>
    <tr>
      <td><?= e($c['name']) ?></td>
      <td><?= e($c['description'] ?? '') ?></td>
      <td><a href="dashboard.php?campaign_id=<?= (int) $c['id'] ?>"><?= (int) $c['lead_count'] ?></a></td>
      <td><?= (int) $c['exported_count'] ?></td>
      <td><?= e($c['created_by_name']) ?></td>
      <td><span class="badge bg-<?= $c['is_active'] ? 'success' : 'secondary' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
      <td class="d-flex gap-1">
        <a class="btn btn-sm btn-outline-secondary" href="campaign_leads.php?campaign_id=<?= (int) $c['id'] ?>">Manage leads</a>
        <a class="btn btn-sm btn-outline-secondary" href="leads_export_csv.php?campaign_export=<?= (int) $c['id'] ?>">Export CSV</a>
        <form method="post" action="campaigns.php" onsubmit="return confirm('Toggle active status for <?= e($c['name']) ?>?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_active">
          <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $c['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$campaigns): ?>
    <tr><td colspan="7" class="text-center text-muted py-4">No campaigns yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php render_footer(); ?>
