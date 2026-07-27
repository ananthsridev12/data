<?php
require_once __DIR__ . '/bootstrap.php';

$admin = require_admin();

$tables = ['vertical' => 'verticals', 'service' => 'services'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $type = $_POST['type'] ?? '';
    $table = $tables[$type] ?? null;
    $action = $_POST['action'] ?? '';

    if ($table === null) {
        flash_set('danger', 'Invalid list type.');
    } elseif ($action === 'create') {
        $code = trim((string) ($_POST['code'] ?? ''));
        $label = trim((string) ($_POST['label'] ?? ''));

        if ($code === '' || $label === '') {
            flash_set('danger', 'Both a code and a name are required.');
        } else {
            try {
                db()->prepare("INSERT INTO {$table} (code, label) VALUES (?, ?)")->execute([$code, $label]);
                flash_set('success', "\"{$label}\" added.");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'That code already exists.' : 'Could not add entry.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare("UPDATE {$table} SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        flash_set('success', 'Status updated.');
    }

    header('Location: lists.php');
    exit;
}

$verticals = db()->query('SELECT * FROM verticals ORDER BY label')->fetchAll();
$services = db()->query('SELECT * FROM services ORDER BY label')->fetchAll();

render_header('Lists');

$renderTable = static function (string $type, string $title, array $rows): void {
    ?>
    <div class="col-md-6">
      <div class="card mb-4">
        <div class="card-header"><?= e($title) ?></div>
        <div class="card-body">
          <form method="post" action="lists.php" class="row g-2 mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="hidden" name="action" value="create">
            <div class="col-5">
              <input type="text" name="code" class="form-control form-control-sm" placeholder="Code, e.g. DT" required>
            </div>
            <div class="col-5">
              <input type="text" name="label" class="form-control form-control-sm" placeholder="Name" required>
            </div>
            <div class="col-2">
              <button type="submit" class="btn btn-primary btn-sm w-100">Add</button>
            </div>
          </form>
          <table class="table table-sm mb-0">
            <thead><tr><th>Code</th><th>Name</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><code><?= e($row['code']) ?></code></td>
                <td><?= e($row['label']) ?></td>
                <td><span class="badge bg-<?= $row['is_active'] ? 'success' : 'secondary' ?>"><?= $row['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td>
                  <form method="post" action="lists.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="type" value="<?= e($type) ?>">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $row['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">None yet.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
};
?>
<h1 class="h4 mb-3">Lists</h1>
<p class="text-muted">Vertical and Service values leads can be tagged with -- used for import mapping, dashboard filters, and inline editing. Deactivating an entry hides it from new selections without touching leads already tagged with it.</p>
<div class="row">
  <?php $renderTable('vertical', 'Verticals', $verticals); ?>
  <?php $renderTable('service', 'Services', $services); ?>
</div>

<div class="card mb-4">
  <div class="card-header">
    Employee Count Ranges
    <?= info_icon('leads.employee_count keeps the exact number as imported; leads.employee_count_range buckets it into a standard band (1-10, 11-50, 51-200, 201-500, 501-1,000, 1,001-5,000, 5,001-10,000, 10,001+) for filtering and ICP criteria, where the exact number is too granular to be a useful checkbox list. Computed automatically on import -- this button only re-applies it to leads already in the system (e.g. after a data fix, or the first time after this feature was added).') ?>
  </div>
  <div class="card-body d-flex flex-wrap gap-2 align-items-center">
    <form method="post" action="lead_recalculate_employee_ranges.php" onsubmit="return confirm('Recalculate the employee count range for every lead with an employee count? This may take a moment for a large leads table.');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-sm btn-outline-secondary">Recalculate employee count ranges</button>
    </form>
    <span class="text-muted small">Fixed bands, not admin-editable -- see <code>EmployeeCountRangeClassifier.php</code>.</span>
  </div>
</div>

<?php render_footer(); ?>
