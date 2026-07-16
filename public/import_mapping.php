<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadImporter.php';
require_once __DIR__ . '/../app/includes/ImportMapper.php';

$admin = require_admin();
$config = require __DIR__ . '/../app/config/config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

$batchId = (int) ($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM import_batches WHERE id = ?');
$stmt->execute([$batchId]);
$batch = $stmt->fetch();

if (!$batch) {
    flash_set('danger', 'Import batch not found.');
    header('Location: import.php');
    exit;
}

if (in_array($batch['status'], ['completed', 'failed', 'partial'], true)) {
    flash_set('info', "\"{$batch['filename']}\" has already been processed.");
    header('Location: import_history.php');
    exit;
}

$sourcePath = $uploadsDir . '/' . $batch['stored_path'];
$formError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_mapping') {
    csrf_verify();

    $headerAndSamples = LeadImporter::detectHeaderAndSamples($sourcePath, $batch['file_type'], 0);
    $headerKeys = ImportMapper::buildHeaderKeys($headerAndSamples['headers']);
    $posted = $_POST['mapping'] ?? [];

    $mappingByKey = [];
    $usedColumns = [];
    $duplicateColumn = null;

    $targetLabels = array_merge(
        array_map(static fn(array $f) => $f['label'], LEAD_FIELDS),
        array_map(static fn(array $f) => $f['label'], LOOKUP_FIELDS)
    );

    foreach ($headerKeys as $i => $key) {
        $col = trim((string) ($posted[$i] ?? ''));
        $col = $col === '' ? null : $col;
        if ($col !== null && !isset($targetLabels[$col])) {
            $col = null;
        }
        if ($col !== null) {
            if (isset($usedColumns[$col])) {
                $duplicateColumn = $targetLabels[$col];
            }
            $usedColumns[$col] = true;
        }
        $mappingByKey[$key] = $col;
    }

    $missingRequired = [];
    foreach (lead_required_fields() as $field) {
        if (empty($usedColumns[$field])) {
            $missingRequired[] = LEAD_FIELDS[$field]['label'];
        }
    }

    if ($duplicateColumn) {
        $formError = "\"{$duplicateColumn}\" is mapped from more than one column -- choose only one source column per field.";
    } elseif ($missingRequired) {
        $formError = 'These required fields must be mapped: ' . implode(', ', $missingRequired);
    }

    if ($formError === null) {
        $mappingJson = json_encode($mappingByKey, JSON_UNESCAPED_UNICODE);

        $saveTemplate = !empty($_POST['save_template']);
        $templateName = trim((string) ($_POST['template_name'] ?? ''));
        if ($saveTemplate && $templateName !== '') {
            $tplStmt = db()->prepare(
                'INSERT INTO import_field_mappings (name, mapping_json, created_by, last_used_at) VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE mapping_json = VALUES(mapping_json), last_used_at = NOW()'
            );
            $tplStmt->execute([$templateName, $mappingJson, $admin['id']]);
        }

        $total = LeadImporter::streamToCache(
            $sourcePath,
            $batch['file_type'],
            $uploadsDir . '/cache/batch_' . $batchId . '.ndjson',
            $uploadsDir . '/cache/batch_' . $batchId . '.offsets.json'
        );

        db()->prepare('UPDATE import_batches SET mapping_json = ?, total_rows = ?, next_offset = 0, status = ? WHERE id = ?')
            ->execute([$mappingJson, $total, $total > 0 ? 'processing' : 'completed', $batchId]);

        if ($total === 0) {
            db()->prepare('UPDATE import_batches SET finished_at = NOW() WHERE id = ?')->execute([$batchId]);
            flash_set('info', "\"{$batch['filename']}\" had no data rows to import.");
            header('Location: import_history.php');
            exit;
        }

        header('Location: import_mapping.php?batch_id=' . $batchId);
        exit;
    }
}

// Refresh in case status changed (e.g. we just flipped it to 'processing' above without redirecting on error path -- re-fetch to be safe).
$stmt->execute([$batchId]);
$batch = $stmt->fetch();

// Precompute mapping-screen data (and any flash_set() for a matched template)
// *before* render_header(), since render_header() drains the flash queue.
if ($batch['status'] !== 'processing') {
    $headerAndSamples = LeadImporter::detectHeaderAndSamples($sourcePath, $batch['file_type'], 3);
    $headers = $headerAndSamples['headers'];
    $samples = $headerAndSamples['samples'];
    $headerKeys = ImportMapper::buildHeaderKeys($headers);

    $suggestion = ImportMapper::suggestMapping($headers);
    $template = ImportMapper::findMatchingTemplate(db(), $headerKeys);
    if ($template) {
        $suggestion = $template['mapping'];
        flash_set('info', 'Auto-filled from saved mapping "' . $template['name'] . '". Review and adjust if needed.');
    }

    $postedMapping = $_POST['mapping'] ?? null;
}

render_header('Map columns');

if ($batch['status'] === 'processing') {
    ?>
    <h1 class="h4 mb-3">Importing "<?= e($batch['filename']) ?>"</h1>
    <div class="card">
      <div class="card-body">
        <div class="progress mb-3" style="height: 1.5rem;">
          <div id="importProgressBar" class="progress-bar" role="progressbar" style="width: 0%">0%</div>
        </div>
        <div id="importStatusText" class="text-muted small mb-3">Starting…</div>
        <div id="importDone" class="d-none">
          <a href="import_history.php" class="btn btn-primary">View import summary</a>
          <a href="import.php" class="btn btn-outline-secondary">Import another file</a>
        </div>
      </div>
    </div>
    <script>
      window.SH_IMPORT_BATCH_ID = <?= (int) $batchId ?>;
      window.SH_IMPORT_TOTAL_ROWS = <?= (int) $batch['total_rows'] ?>;
      window.SH_IMPORT_CSRF = <?= json_encode(csrf_token()) ?>;
    </script>
    <script src="assets/js/app.js"></script>
    <?php
    render_footer();
    exit;
}
?>
<h1 class="h4 mb-1">Map columns for "<?= e($batch['filename']) ?>"</h1>
<p class="text-muted">Match each detected column to a lead field. Required fields are marked with *. Vertical/Service values must match a code or name already in <a href="lists.php">Lists</a> -- unrecognized values will be skipped as row errors.</p>

<?php if ($formError): ?>
  <div class="alert alert-danger"><?= e($formError) ?></div>
<?php endif; ?>

<form method="post" action="import_mapping.php">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="confirm_mapping">
  <input type="hidden" name="batch_id" value="<?= (int) $batchId ?>">

  <div class="table-responsive card mb-3">
    <table class="table mb-0 align-middle">
      <thead>
        <tr>
          <th style="width: 25%">Detected column</th>
          <th style="width: 30%">Sample value</th>
          <th style="width: 45%">Maps to</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($headers as $i => $header): $key = $headerKeys[$i]; ?>
        <tr>
          <td><code><?= e($header !== '' ? $header : '(blank header)') ?></code></td>
          <td class="text-muted small">
            <?= e($samples[0][$i] ?? '') ?>
          </td>
          <td>
            <?php $chosen = $postedMapping[$i] ?? ($suggestion[$key] ?? null); ?>
            <select name="mapping[<?= $i ?>]" class="form-select form-select-sm">
              <option value="">-- Not mapped / ignore --</option>
              <optgroup label="Required fields">
                <?php foreach (LEAD_FIELDS as $col => $meta): if (!$meta['required']) continue; ?>
                  <option value="<?= e($col) ?>" <?= $chosen === $col ? 'selected' : '' ?>><?= e($meta['label']) ?> *</option>
                <?php endforeach; ?>
              </optgroup>
              <optgroup label="Optional fields">
                <?php foreach (LEAD_FIELDS as $col => $meta): if ($meta['required']) continue; ?>
                  <option value="<?= e($col) ?>" <?= $chosen === $col ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <optgroup label="Classification (from your Lists)">
                <?php foreach (LOOKUP_FIELDS as $col => $meta): ?>
                  <option value="<?= e($col) ?>" <?= $chosen === $col ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="save_template" value="1" id="saveTemplate">
        <label class="form-check-label" for="saveTemplate">Save this mapping as a reusable template</label>
      </div>
      <input type="text" name="template_name" class="form-control form-control-sm mt-2" placeholder="Template name, e.g. &quot;Apollo export&quot;" style="max-width: 320px;">
    </div>
  </div>

  <button type="submit" class="btn btn-primary">Confirm mapping &amp; start import</button>
  <a href="import.php" class="btn btn-outline-secondary">Cancel</a>
</form>
<?php render_footer(); ?>
