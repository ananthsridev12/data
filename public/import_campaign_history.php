<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/CampaignHistoryImporter.php';

$admin = require_admin();

$stats = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (empty($_FILES['history_file']) || $_FILES['history_file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('danger', 'Please choose a CSV file.');
        header('Location: import_campaign_history.php');
        exit;
    }

    $handle = fopen($_FILES['history_file']['tmp_name'], 'r');
    if ($handle === false) {
        flash_set('danger', 'Could not read the uploaded file.');
        header('Location: import_campaign_history.php');
        exit;
    }

    $stats = CampaignHistoryImporter::import($handle, db(), $admin['id']);
    fclose($handle);

    flash_set(
        'success',
        "{$stats['processed']} row(s) processed -- {$stats['vertical_updated']} Vertical update(s), {$stats['service_updated']} Service update(s), "
            . "{$stats['campaigns_created']} campaign(s) created, {$stats['assignments_created']} new assignment(s), "
            . "{$stats['marked_imported']} marked Imported to Saleshandy, {$stats['marked_email_sent']} marked Email Sent. "
            . "{$stats['lead_not_found']} row(s) had no matching lead."
    );
}
?>
<?php render_header('Import campaign history'); ?>
<h1 class="h4 mb-3">Backfill campaign history</h1>
<p class="text-muted">
  Upload a CSV shaped like your tracking sheet. Only <strong>Email</strong> is required -- every other column
  is optional and only applied when present in a given row: <code>Vertical</code>, <code>Service</code>,
  <code>Campaign ID</code> (creates the campaign if it doesn't exist yet), <code>Imported Saleshandy</code>
  (TRUE/FALSE/Yes/No), <code>Email Sent</code> (same), <code>Email Date</code>. Rows whose email doesn't match
  an existing lead are skipped and reported below; Vertical/Service values that don't match anything in
  <a href="lists.php">Lists</a> are also skipped (with the rest of that row's fields still applied).
</p>

<div class="card mb-4">
  <div class="card-body">
    <form method="post" action="import_campaign_history.php" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
      <?= csrf_field() ?>
      <input type="file" name="history_file" class="form-control form-control-sm" accept=".csv" required style="max-width: 320px;">
      <button type="submit" class="btn btn-primary btn-sm">Process file</button>
    </form>
  </div>
</div>

<?php if ($stats && $stats['skipped_notes']): ?>
<div class="card">
  <div class="card-header">Notes (<?= count($stats['skipped_notes']) ?>)</div>
  <ul class="list-group list-group-flush">
    <?php foreach (array_slice($stats['skipped_notes'], 0, 300) as $note): ?>
      <li class="list-group-item small"><?= e($note) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php render_footer(); ?>
