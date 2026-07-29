<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadImporter.php';
require_once __DIR__ . '/../app/includes/ImportMapper.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/TagRepository.php';

$admin = require_admin();
$scope = Scope::fromUser(db(), $admin);
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

$customFieldRows = db()->query('SELECT field_key, label FROM custom_fields WHERE is_active = 1 ORDER BY label')->fetchAll();
$customFieldLabels = array_column($customFieldRows, 'label', 'field_key');

$lookupOptions = [
    'vertical' => LeadRepository::activeLookupOptions(db(), $scope, 'verticals'),
    'service' => LeadRepository::activeLookupOptions(db(), $scope, 'services'),
];
$campaignOptions = db()->query('SELECT id, name FROM campaigns WHERE is_active = 1 ORDER BY name')->fetchAll();
$allTags = TagRepository::all(db());

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
        array_map(static fn(array $f) => $f['label'], LOOKUP_FIELDS),
        $customFieldLabels,
        ['tags' => 'Tags']
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

    $postedDefaults = $_POST['defaults'] ?? [];
    $defaults = [];
    foreach ($targetLabels as $targetKey => $label) {
        if ($targetKey === 'tags') {
            continue; // handled separately below -- multi-valued, not a single default string
        }
        $value = trim((string) ($postedDefaults[$targetKey] ?? ''));
        if ($value !== '') {
            $defaults[$targetKey] = $value;
        }
    }

    // Tags for this whole import: existing tags picked from the multi-select plus any typed as new.
    $defaultTagIds = array_map('intval', $_POST['defaults_tags_ids'] ?? []);
    $defaultTagNames = [];
    if ($defaultTagIds) {
        $placeholders = implode(',', array_fill(0, count($defaultTagIds), '?'));
        $tagNameStmt = db()->prepare("SELECT name FROM tags WHERE id IN ({$placeholders})");
        $tagNameStmt->execute($defaultTagIds);
        $defaultTagNames = $tagNameStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $newDefaultTags = array_filter(array_map('trim', explode(',', (string) ($_POST['defaults_tags_new'] ?? ''))));
    $defaultTagNames = array_merge($defaultTagNames, $newDefaultTags);
    if ($defaultTagNames) {
        $defaults['tags'] = implode(',', $defaultTagNames);
    }

    $missingRequired = [];
    foreach (lead_required_fields() as $field) {
        if (empty($usedColumns[$field]) && empty($defaults[$field])) {
            $missingRequired[] = LEAD_FIELDS[$field]['label'];
        }
    }

    if ($duplicateColumn) {
        $formError = "\"{$duplicateColumn}\" is mapped from more than one column -- choose only one source column per field.";
    } elseif ($missingRequired) {
        $formError = 'These required fields must be mapped from a column or given a default value: ' . implode(', ', $missingRequired);
    }

    $assignCampaignId = (int) ($_POST['assign_campaign_id'] ?? 0);
    if ($assignCampaignId > 0) {
        $campaignCheck = db()->prepare('SELECT id FROM campaigns WHERE id = ? AND is_active = 1');
        $campaignCheck->execute([$assignCampaignId]);
        if (!$campaignCheck->fetch()) {
            $assignCampaignId = 0;
        }
    }

    if ($formError === null) {
        $mappingJson = json_encode($mappingByKey, JSON_UNESCAPED_UNICODE);
        $batchMapping = ['mapping' => $mappingByKey, 'defaults' => $defaults];
        if ($assignCampaignId > 0) {
            $batchMapping['assign_campaign_id'] = $assignCampaignId;
        }
        $batchMappingJson = json_encode($batchMapping, JSON_UNESCAPED_UNICODE);

        $templateName = trim((string) ($_POST['template_name'] ?? ''));
        $templateSaved = false;
        if ($templateName !== '') {
            $tplStmt = db()->prepare(
                'INSERT INTO import_field_mappings (name, mapping_json, created_by, last_used_at) VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE mapping_json = VALUES(mapping_json), last_used_at = NOW()'
            );
            $tplStmt->execute([$templateName, $mappingJson, $admin['id']]);
            $templateSaved = true;
        }

        $total = LeadImporter::streamToCache(
            $sourcePath,
            $batch['file_type'],
            $uploadsDir . '/cache/batch_' . $batchId . '.ndjson',
            $uploadsDir . '/cache/batch_' . $batchId . '.offsets.json'
        );

        db()->prepare('UPDATE import_batches SET mapping_json = ?, total_rows = ?, next_offset = 0, status = ? WHERE id = ?')
            ->execute([$batchMappingJson, $total, $total > 0 ? 'processing' : 'completed', $batchId]);

        if ($templateSaved) {
            flash_set('success', "Saved mapping template \"{$templateName}\".");
        }

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
    $postedDefaults = $_POST['defaults'] ?? [];

    $savedTemplates = db()->query('SELECT id, name, mapping_json FROM import_field_mappings ORDER BY name')->fetchAll();
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

<?php if ($savedTemplates): ?>
<div class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-2 align-items-center">
    <label for="loadTemplateSelect" class="form-label small mb-0">Load a saved template:</label>
    <select id="loadTemplateSelect" class="form-select form-select-sm" style="max-width: 320px;">
      <option value="">-- choose a template --</option>
      <?php foreach ($savedTemplates as $tpl): ?>
        <option value="<?= (int) $tpl['id'] ?>"><?= e($tpl['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <span class="text-muted small">Applies its mapping to any column below whose header text matches. Columns not covered by the template are left as-is.</span>
  </div>
</div>
<script>
  window.SH_SAVED_TEMPLATES = <?= json_encode(array_map(static fn(array $t) => [
      'id' => (int) $t['id'],
      'mapping' => json_decode($t['mapping_json'], true) ?: [],
  ], $savedTemplates), JSON_UNESCAPED_UNICODE) ?>;
</script>
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
            <select name="mapping[<?= $i ?>]" class="form-select form-select-sm" data-header-key="<?= e($key) ?>">
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
              <?php if ($customFieldLabels): ?>
              <optgroup label="Custom Fields">
                <?php foreach ($customFieldLabels as $col => $label): ?>
                  <option value="<?= e($col) ?>" <?= $chosen === $col ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <optgroup label="Tags">
                <option value="tags" <?= $chosen === 'tags' ? 'selected' : '' ?>>Tags (comma/semicolon-separated)</option>
              </optgroup>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card mb-3">
    <div class="card-header">Default values</div>
    <div class="card-body">
      <p class="text-muted small">Used for a field when this file has no column mapped to it, or a row's cell is blank. Can satisfy a required field marked * above too. Leave blank to skip. Fields you've already mapped to a column above are hidden here automatically.</p>
      <div class="row" id="defaultValuesRow">
        <?php foreach (LEAD_FIELDS as $key => $meta): ?>
          <div class="col-md-4 mb-2" id="default-row-<?= e($key) ?>" data-default-row="<?= e($key) ?>">
            <label class="form-label small mb-0"><?= e($meta['label']) ?><?= !empty($meta['required']) ? ' *' : '' ?></label>
            <input type="text" name="defaults[<?= e($key) ?>]" class="form-control form-control-sm" value="<?= e($postedDefaults[$key] ?? '') ?>">
          </div>
        <?php endforeach; ?>
        <?php foreach (LOOKUP_FIELDS as $key => $meta): ?>
          <div class="col-md-4 mb-2" id="default-row-<?= e($key) ?>" data-default-row="<?= e($key) ?>">
            <label class="form-label small mb-0"><?= e($meta['label']) ?></label>
            <select name="defaults[<?= e($key) ?>]" class="form-select form-select-sm">
              <option value="">-- none --</option>
              <?php foreach ($lookupOptions[$key] as $opt): ?>
                <option value="<?= e($opt['label']) ?>" <?= ($postedDefaults[$key] ?? '') === $opt['label'] ? 'selected' : '' ?>><?= e($opt['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
        <?php foreach ($customFieldLabels as $key => $label): ?>
          <div class="col-md-4 mb-2" id="default-row-<?= e($key) ?>" data-default-row="<?= e($key) ?>">
            <label class="form-label small mb-0"><?= e($label) ?></label>
            <input type="text" name="defaults[<?= e($key) ?>]" class="form-control form-control-sm" value="<?= e($postedDefaults[$key] ?? '') ?>">
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card mb-3" id="default-row-tags" data-default-row="tags">
    <div class="card-header">Tags for this import (optional)</div>
    <div class="card-body">
      <p class="text-muted small">Applied to every lead imported or updated by this file, in addition to any tags mapped from a column above -- tags accumulate rather than replace, so a re-imported lead keeps tags it already had.</p>
      <?php if ($allTags): ?>
      <div class="mb-2">
        <label class="form-label small mb-0">Existing tags (ctrl/cmd-click to select multiple)</label>
        <select name="defaults_tags_ids[]" class="form-select form-select-sm" multiple size="4">
          <?php foreach ($allTags as $t): ?>
            <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <label class="form-label small mb-0">New tags (comma-separated)</label>
      <input type="text" name="defaults_tags_new" class="form-control form-control-sm" placeholder="e.g. Hot Lead, Q3 Push">
    </div>
  </div>

  <?php if ($campaignOptions): ?>
  <div class="card mb-3">
    <div class="card-header">Assign to campaign (optional)</div>
    <div class="card-body">
      <p class="text-muted small">Every lead successfully imported or updated by this file will also be assigned to the chosen campaign, same as using "Add to campaign" from the dashboard.</p>
      <select name="assign_campaign_id" class="form-select form-select-sm" style="max-width: 320px;">
        <option value="">-- Don't assign to a campaign --</option>
        <?php foreach ($campaignOptions as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) ($_POST['assign_campaign_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-body">
      <label class="form-label small mb-0" for="templateNameInput">Save this mapping as a reusable template (optional)</label>
      <input type="text" name="template_name" id="templateNameInput" class="form-control form-control-sm mt-1" placeholder="Template name, e.g. &quot;Apollo export&quot; -- leave blank to skip" style="max-width: 320px;" value="<?= e($_POST['template_name'] ?? '') ?>">
      <p class="text-muted small mb-0 mt-1">Enter a name to save (or update) a reusable template with this exact column mapping, so next time a similarly-shaped file can auto-fill or be loaded from the picker above.</p>
    </div>
  </div>

  <button type="submit" class="btn btn-primary">Confirm mapping &amp; start import</button>
  <a href="import.php" class="btn btn-outline-secondary">Cancel</a>
</form>
<script>
  (function () {
    var mappingSelects = document.querySelectorAll('select[name^="mapping["]');
    var defaultRows = document.querySelectorAll('[data-default-row]');

    function refresh() {
      var mappedKeys = {};
      mappingSelects.forEach(function (sel) {
        if (sel.value) {
          mappedKeys[sel.value] = true;
        }
      });
      defaultRows.forEach(function (row) {
        row.style.display = mappedKeys[row.getAttribute('data-default-row')] ? 'none' : '';
      });
    }

    mappingSelects.forEach(function (sel) {
      sel.addEventListener('change', refresh);
    });
    refresh();

    var loadTemplateSelect = document.getElementById('loadTemplateSelect');
    if (loadTemplateSelect && window.SH_SAVED_TEMPLATES) {
      var templatesById = {};
      window.SH_SAVED_TEMPLATES.forEach(function (t) {
        templatesById[t.id] = t.mapping;
      });

      loadTemplateSelect.addEventListener('change', function () {
        var mapping = templatesById[loadTemplateSelect.value];
        if (!mapping) {
          return;
        }
        mappingSelects.forEach(function (sel) {
          var headerKey = sel.getAttribute('data-header-key');
          if (Object.prototype.hasOwnProperty.call(mapping, headerKey) && mapping[headerKey]) {
            var target = mapping[headerKey];
            var hasOption = Array.prototype.some.call(sel.options, function (o) { return o.value === target; });
            if (hasOption) {
              sel.value = target;
            }
          }
        });
        refresh();
      });
    }
  })();
</script>
<?php render_footer(); ?>
