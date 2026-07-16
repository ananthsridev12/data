<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadImporter.php';
require_once __DIR__ . '/../app/includes/ImportMapper.php';

$admin = require_admin();
$config = require __DIR__ . '/../app/config/config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

const MAX_UPLOAD_BYTES = 25 * 1024 * 1024; // 25MB per file

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (empty($_FILES['lead_files']) || empty($_FILES['lead_files']['name'][0])) {
        flash_set('danger', 'Please choose at least one .xlsx or .csv file.');
        header('Location: import.php');
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $created = [];
    $failed = [];
    $fileCount = count($_FILES['lead_files']['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        $originalName = $_FILES['lead_files']['name'][$i];
        $tmpPath = $_FILES['lead_files']['tmp_name'][$i];
        $error = $_FILES['lead_files']['error'][$i];
        $size = $_FILES['lead_files']['size'][$i];

        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmpPath)) {
            $failed[] = "{$originalName}: upload failed.";
            continue;
        }
        if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
            $failed[] = "{$originalName}: file is empty or exceeds the 25MB limit.";
            continue;
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = finfo_file($finfo, $tmpPath);

        $xlsxMimes = [
            'application/zip',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/octet-stream',
        ];

        if ($ext === 'xlsx' && in_array($mime, $xlsxMimes, true)) {
            $fileType = 'xlsx';
        } elseif ($ext === 'csv' && in_array($mime, ['text/plain', 'text/csv', 'application/csv'], true)) {
            $fileType = 'csv';
        } else {
            $failed[] = "{$originalName}: only .xlsx or .csv files are accepted (detected type: {$mime}).";
            continue;
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destination = $uploadsDir . '/' . $storedName;

        if (!move_uploaded_file($tmpPath, $destination)) {
            $failed[] = "{$originalName}: could not save the uploaded file.";
            continue;
        }
        chmod($destination, 0640);

        $stmt = db()->prepare(
            'INSERT INTO import_batches (filename, stored_path, file_type, uploaded_by, status) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$originalName, $storedName, $fileType, $admin['id'], 'mapping_pending']);
        $created[] = (int) db()->lastInsertId();
    }
    finfo_close($finfo);

    foreach ($failed as $msg) {
        flash_set('danger', $msg);
    }
    if ($created) {
        flash_set('success', count($created) . ' file(s) uploaded. Review the column mapping for each below.');
        header('Location: import_mapping.php?batch_id=' . $created[0]);
        exit;
    }

    header('Location: import.php');
    exit;
}

$pending = db()->query(
    "SELECT id, filename, file_type, status, started_at FROM import_batches WHERE status IN ('mapping_pending', 'processing') ORDER BY started_at DESC"
)->fetchAll();

render_header('Import');
?>
<h1 class="h4 mb-3">Import leads</h1>

<div class="card mb-4">
  <div class="card-body">
    <form method="post" action="import.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Excel (.xlsx) or CSV files</label>
        <input type="file" class="form-control" name="lead_files[]" accept=".xlsx,.csv" multiple required>
        <div class="form-text">You'll map each file's columns to lead fields on the next screen before it's imported. Max 25MB per file.</div>
      </div>
      <button type="submit" class="btn btn-primary">Upload</button>
    </form>
  </div>
</div>

<?php if ($pending): ?>
<div class="card">
  <div class="card-header">Uploads awaiting mapping or still processing</div>
  <ul class="list-group list-group-flush">
    <?php foreach ($pending as $b): ?>
      <li class="list-group-item d-flex justify-content-between align-items-center">
        <span><?= e($b['filename']) ?> <span class="badge bg-secondary"><?= e($b['status']) ?></span></span>
        <a class="btn btn-sm btn-outline-primary" href="import_mapping.php?batch_id=<?= (int) $b['id'] ?>">Continue</a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php render_footer(); ?>
