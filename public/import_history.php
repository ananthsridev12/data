<?php
require_once __DIR__ . '/bootstrap.php';

require_admin();

$batches = db()->query(
    "SELECT b.*, u.name AS uploaded_by_name
     FROM import_batches b
     JOIN users u ON u.id = b.uploaded_by
     ORDER BY b.started_at DESC"
)->fetchAll();

$errorsFor = isset($_GET['errors_for']) ? (int) $_GET['errors_for'] : null;
$rowErrors = [];
if ($errorsFor) {
    $stmt = db()->prepare('SELECT * FROM import_row_errors WHERE import_batch_id = ? ORDER BY row_num LIMIT 500');
    $stmt->execute([$errorsFor]);
    $rowErrors = $stmt->fetchAll();
}

$statusColors = [
    'mapping_pending' => 'secondary',
    'processing' => 'info',
    'completed' => 'success',
    'partial' => 'warning',
    'failed' => 'danger',
];

render_header('Import history');
?>
<h1 class="h4 mb-3">Import history</h1>

<div class="table-responsive card mb-4">
  <table class="table mb-0 align-middle">
    <thead>
      <tr>
        <th>File</th><th>Uploaded by</th><th>Status</th><th>New</th><th>Updated</th><th>Skipped</th><th>Errors</th><th>Started</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
      <tr>
        <td><?= e($b['filename']) ?></td>
        <td><?= e($b['uploaded_by_name']) ?></td>
        <td><span class="badge bg-<?= $statusColors[$b['status']] ?? 'secondary' ?>"><?= e($b['status']) ?></span></td>
        <td><?= (int) $b['inserted_count'] ?></td>
        <td><?= (int) $b['updated_count'] ?></td>
        <td><?= (int) $b['skipped_count'] ?></td>
        <td><?= (int) $b['error_count'] ?></td>
        <td class="small text-muted"><?= e($b['started_at']) ?></td>
        <td>
          <?php if (in_array($b['status'], ['mapping_pending', 'processing'], true)): ?>
            <a class="btn btn-sm btn-outline-primary" href="import_mapping.php?batch_id=<?= (int) $b['id'] ?>">Continue</a>
          <?php elseif ((int) $b['error_count'] > 0): ?>
            <a class="btn btn-sm btn-outline-secondary" href="import_history.php?errors_for=<?= (int) $b['id'] ?>#errors">View errors</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$batches): ?>
      <tr><td colspan="9" class="text-center text-muted py-4">No imports yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($errorsFor): ?>
<div id="errors" class="card">
  <div class="card-header">Row errors for batch #<?= $errorsFor ?> (showing up to 500)</div>
  <div class="table-responsive">
    <table class="table mb-0 small">
      <thead><tr><th>Row</th><th>Email</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($rowErrors as $err): ?>
        <tr>
          <td><?= (int) $err['row_num'] ?></td>
          <td><?= e($err['email'] ?? '') ?></td>
          <td><?= e($err['reason']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rowErrors): ?>
        <tr><td colspan="3" class="text-center text-muted py-3">No errors recorded.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
