<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/config/constants.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

function slugify_field_key(string $label): string
{
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    return trim($slug, '_');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $key = slugify_field_key($label);
        $reserved = array_merge(array_keys(LEAD_FIELDS), array_keys(LOOKUP_FIELDS));

        if ($label === '' || $key === '') {
            flash_set('danger', 'Please provide a field name.');
        } elseif (in_array($key, $reserved, true)) {
            flash_set('danger', "\"{$label}\" collides with a built-in field name -- choose a different name.");
        } else {
            try {
                db()->prepare('INSERT INTO custom_fields (company_id, field_key, label, created_by) VALUES (?, ?, ?, ?)')
                    ->execute([$scope->companyId, $key, $label, $user['id']]);
                flash_set('success', "Custom field \"{$label}\" added.");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'A field with that name already exists.' : 'Could not create field.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE custom_fields SET is_active = NOT is_active WHERE id = ? AND company_id = ?')->execute([$id, $scope->companyId]);
        flash_set('success', 'Field status updated.');
    }

    header('Location: custom_fields.php');
    exit;
}

$fieldsStmt = db()->prepare('SELECT * FROM custom_fields WHERE company_id = ? ORDER BY label');
$fieldsStmt->execute([$scope->companyId]);
$fields = $fieldsStmt->fetchAll();

render_header('Custom fields');
?>
<h1 class="h4 mb-3">Custom fields</h1>
<p class="text-muted">Free-text fields beyond the built-in set. Once created, a custom field appears in the import mapping screen (under "Custom Fields"), and can be viewed/edited on each lead's detail page.</p>

<div class="card mb-4">
  <div class="card-header">Add a custom field</div>
  <div class="card-body">
    <form method="post" action="custom_fields.php" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-md-6">
        <input type="text" name="label" class="form-control form-control-sm" placeholder="Field name, e.g. &quot;Lead Source&quot;" required>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Add</button>
      </div>
    </form>
  </div>
</div>

<table class="table table-striped bg-white">
  <thead><tr><th>Field name</th><th>Key</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($fields as $f): ?>
    <tr>
      <td><?= e($f['label']) ?></td>
      <td><code><?= e($f['field_key']) ?></code></td>
      <td><span class="badge bg-<?= $f['is_active'] ? 'success' : 'secondary' ?>"><?= $f['is_active'] ? 'Active' : 'Inactive' ?></span></td>
      <td>
        <form method="post" action="custom_fields.php" onsubmit="return confirm('Toggle active status for <?= e($f['label']) ?>?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_active">
          <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $f['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$fields): ?>
    <tr><td colspan="4" class="text-center text-muted py-4">No custom fields yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php render_footer(); ?>
