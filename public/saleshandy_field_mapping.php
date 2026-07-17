<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'fetch_fields') {
        $config = require __DIR__ . '/../app/config/config.php';
        try {
            $client = SaleshandyClient::fromConfig($config);
            $_SESSION['saleshandy_known_fields'] = $client->listFields();
            flash_set('success', 'Fetched field list from Saleshandy -- exact labels are shown below for reference.');
        } catch (SaleshandyApiException $ex) {
            flash_set('danger', 'Could not fetch fields from Saleshandy: ' . $ex->getMessage());
        }
    } elseif ($action === 'save_mappings') {
        $enabled = $_POST['enabled'] ?? [];
        $labels = $_POST['label'] ?? [];

        $upsert = db()->prepare(
            'INSERT INTO saleshandy_field_mappings (lead_field_key, saleshandy_label, enabled) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE saleshandy_label = VALUES(saleshandy_label), enabled = VALUES(enabled)'
        );
        $delete = db()->prepare('DELETE FROM saleshandy_field_mappings WHERE lead_field_key = ?');

        $targetKeys = array_unique(array_merge(array_keys(LEAD_FIELDS), array_keys(LOOKUP_FIELDS)));
        foreach ($targetKeys as $key) {
            $label = trim((string) ($labels[$key] ?? ''));
            if ($label === '') {
                $delete->execute([$key]);
                continue;
            }
            $upsert->execute([$key, $label, !empty($enabled[$key]) ? 1 : 0]);
        }
        flash_set('success', 'Saleshandy field mapping saved.');
    }

    header('Location: saleshandy_field_mapping.php');
    exit;
}

$mappingRows = db()->query('SELECT lead_field_key, saleshandy_label, enabled FROM saleshandy_field_mappings')->fetchAll();
$mappingByKey = array_column($mappingRows, null, 'lead_field_key');
$knownFields = $_SESSION['saleshandy_known_fields'] ?? [];
$knownFieldLabels = array_values(array_unique(array_filter(array_map(
    static fn(array $f) => trim((string) ($f['label'] ?? '')),
    $knownFields
))));
sort($knownFieldLabels);

render_header('Saleshandy field mapping');
?>
<h1 class="h4 mb-1">Saleshandy field mapping</h1>
<p class="text-muted">Choose which fields get sent when you push leads to Saleshandy, and what Saleshandy field label each one maps to. First Name, Last Name, and Email are always sent (Saleshandy requires them) and aren't listed here. Unchecked or blank-label fields are simply left out of the push.</p>

<div class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-2 align-items-center">
    <form method="post" action="saleshandy_field_mapping.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="fetch_fields">
      <button type="submit" class="btn btn-outline-secondary btn-sm">Fetch field list from Saleshandy</button>
    </form>
    <span class="text-muted small">
      <?= $knownFieldLabels ? 'Pick from the dropdowns below.' : "Labels must match Saleshandy's exactly (spaces and capitalization included) -- fetch the list to get dropdowns instead of typing them." ?>
    </span>
  </div>
  <?php if ($knownFields): ?>
  <div class="card-body pt-0">
    <div class="small text-muted">Known Saleshandy field labels:</div>
    <?php foreach ($knownFields as $f): ?>
      <span class="badge bg-light text-dark border me-1 mb-1"><?= e($f['label'] ?? '') ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<form method="post" action="saleshandy_field_mapping.php">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_mappings">
  <div class="table-responsive card mb-3">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th style="width:5%">Send</th><th style="width:35%">Our field</th><th>Saleshandy label</th></tr></thead>
      <tbody>
      <?php
      $renderLabelField = static function (string $key, ?string $current) use ($knownFieldLabels) {
          if (!$knownFieldLabels) {
              ?><input type="text" name="label[<?= e($key) ?>]" class="form-control form-control-sm" value="<?= e($current ?? '') ?>" placeholder="e.g. Company"><?php
              return;
          }
          // Keep a previously-saved label selectable even if it's since
          // disappeared from Saleshandy's list, so saving the form again
          // doesn't silently wipe out a mapping that's otherwise still valid.
          $options = $knownFieldLabels;
          if ($current !== null && $current !== '' && !in_array($current, $options, true)) {
              $options[] = $current;
              sort($options);
          }
          ?>
          <select name="label[<?= e($key) ?>]" class="form-select form-select-sm">
            <option value="">-- don't send --</option>
            <?php foreach ($options as $label): ?>
              <option value="<?= e($label) ?>" <?= $current === $label ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php
      };
      ?>
      <?php foreach (LEAD_FIELDS as $key => $meta): $row = $mappingByKey[$key] ?? null; ?>
        <tr>
          <td><input type="checkbox" name="enabled[<?= e($key) ?>]" value="1" <?= ($row && $row['enabled']) ? 'checked' : '' ?>></td>
          <td><?= e($meta['label']) ?></td>
          <td><?php $renderLabelField($key, $row['saleshandy_label'] ?? null); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach (LOOKUP_FIELDS as $key => $meta): $row = $mappingByKey[$key] ?? null; ?>
        <tr>
          <td><input type="checkbox" name="enabled[<?= e($key) ?>]" value="1" <?= ($row && $row['enabled']) ? 'checked' : '' ?>></td>
          <td><?= e($meta['label']) ?></td>
          <td><?php $renderLabelField($key, $row['saleshandy_label'] ?? null); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <button type="submit" class="btn btn-primary btn-sm">Save mapping</button>
</form>
<?php render_footer(); ?>
