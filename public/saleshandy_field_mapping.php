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
    <span class="text-muted small">Labels must match Saleshandy's exactly (spaces and capitalization included) -- use this to check spelling.</span>
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
      <?php foreach (LEAD_FIELDS as $key => $meta): $row = $mappingByKey[$key] ?? null; ?>
        <tr>
          <td><input type="checkbox" name="enabled[<?= e($key) ?>]" value="1" <?= ($row && $row['enabled']) ? 'checked' : '' ?>></td>
          <td><?= e($meta['label']) ?></td>
          <td><input type="text" name="label[<?= e($key) ?>]" class="form-control form-control-sm" value="<?= e($row['saleshandy_label'] ?? '') ?>" placeholder="e.g. Company"></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach (LOOKUP_FIELDS as $key => $meta): $row = $mappingByKey[$key] ?? null; ?>
        <tr>
          <td><input type="checkbox" name="enabled[<?= e($key) ?>]" value="1" <?= ($row && $row['enabled']) ? 'checked' : '' ?>></td>
          <td><?= e($meta['label']) ?></td>
          <td><input type="text" name="label[<?= e($key) ?>]" class="form-control form-control-sm" value="<?= e($row['saleshandy_label'] ?? '') ?>" placeholder="e.g. Industry"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <button type="submit" class="btn btn-primary btn-sm">Save mapping</button>
</form>
<?php render_footer(); ?>
