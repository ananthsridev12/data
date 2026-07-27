<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/CountryGroupClassifier.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code = trim((string) ($_POST['code'] ?? ''));
        $label = trim((string) ($_POST['label'] ?? ''));
        $countries = trim((string) ($_POST['countries'] ?? ''));

        if ($code === '' || $label === '') {
            flash_set('danger', 'Both a code and a name are required.');
        } else {
            try {
                db()->prepare('INSERT INTO country_groups (code, label, countries) VALUES (?, ?, ?)')
                    ->execute([$code, $label, $countries !== '' ? $countries : null]);
                flash_set('success', "\"{$label}\" added.");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'That code already exists.' : 'Could not add country group.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        $countries = trim((string) ($_POST['countries'] ?? ''));

        if ($label === '') {
            flash_set('danger', 'Name is required.');
        } else {
            db()->prepare('UPDATE country_groups SET label = ?, countries = ? WHERE id = ?')
                ->execute([$label, $countries !== '' ? $countries : null, $id]);
            flash_set('success', "\"{$label}\" updated -- run \"Reclassify all leads now\" below to apply country list changes to existing leads.");
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE country_groups SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    } elseif ($action === 'add_country') {
        // One-click helper for the "Unmapped countries" list below --
        // appends the exact company_country text as a new entry rather
        // than requiring copy/paste into the Edit modal's textarea. Does
        // not reclassify by itself -- "Reclassify all leads now" still
        // applies it.
        $id = (int) ($_POST['id'] ?? 0);
        $country = trim((string) ($_POST['country'] ?? ''));
        $stmt = db()->prepare('SELECT label, countries FROM country_groups WHERE id = ?');
        $stmt->execute([$id]);
        $cg = $stmt->fetch();
        if (!$cg || $country === '') {
            flash_set('danger', 'Could not add country.');
        } else {
            $existing = CountryGroupClassifier::parseCountries($cg['countries'] ?? '');
            if (in_array($country, $existing, true)) {
                flash_set('info', "\"{$country}\" is already listed on \"{$cg['label']}\".");
            } else {
                $existing[] = $country;
                db()->prepare('UPDATE country_groups SET countries = ? WHERE id = ?')
                    ->execute([implode(', ', $existing), $id]);
                flash_set('success', "\"{$country}\" added to \"{$cg['label']}\" -- run \"Reclassify all leads now\" below to apply it.");
            }
        }
    }

    header('Location: country_groups.php');
    exit;
}

$countryGroups = db()->query('SELECT * FROM country_groups ORDER BY label')->fetchAll();
$activeCountryGroups = array_values(array_filter($countryGroups, static fn (array $cg): bool => (bool) $cg['is_active']));
$unmappedCount = (int) db()->query("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND country_group_id IS NULL AND company_country IS NOT NULL AND company_country != ''")->fetchColumn();
// Distinct unmapped company_country values, most common first -- so
// mapping effort goes to the countries affecting the most leads first.
//
// country_group_id IS NULL alone isn't enough: a country already listed
// on an active group still has country_group_id = NULL on rows
// imported/edited before "Reclassify all leads now" was last run, which
// would otherwise make an already-mapped country keep reappearing here
// until that button is clicked. Re-run the real classifier against each
// candidate and only list the ones that genuinely don't match anything --
// using the same id-ordered group list (first match wins) that
// LeadImporter/lead_reclassify_country_groups.php actually classify
// with, not the label-ordered $activeCountryGroups used for display.
$classifyOrderCountryGroups = db()->query('SELECT id, countries FROM country_groups WHERE is_active = 1 ORDER BY id')->fetchAll();
$unmappedCountriesCandidates = db()->query(
    "SELECT company_country, COUNT(*) AS cnt FROM leads
      WHERE deleted_at IS NULL AND country_group_id IS NULL AND company_country IS NOT NULL AND company_country != ''
      GROUP BY company_country ORDER BY cnt DESC, company_country"
)->fetchAll();
$unmappedCountries = [];
foreach ($unmappedCountriesCandidates as $row) {
    if (CountryGroupClassifier::classify($row['company_country'], $classifyOrderCountryGroups) === null) {
        $unmappedCountries[] = $row;
        if (count($unmappedCountries) >= 200) {
            break;
        }
    }
}

render_header('Country Groups');
?>
<h1 class="h4 mb-3">Country Groups</h1>
<p class="text-muted">
  Buckets <code>Company Country</code> into a small set of named regions (e.g. for grouping by rough send-time
  region/timezone) instead of one checkbox per exact country string. Each group has a comma-separated country
  list -- a lead's <code>company_country</code> is matched <strong>exactly</strong> (case-insensitive, whole
  value, not a substring) against each active group's list, first match wins. Leads are auto-classified on
  import; edit a group's country list and click "Reclassify all leads now" below to re-apply the updated rules
  to leads already in the system.
</p>
<p class="text-muted small">
  <strong>Matching is exact, not substring</strong> -- unlike Role Groups, a country value must match a listed
  entry exactly (e.g. "USA" will NOT match a list that only contains "United States"). List every spelling
  variant you expect to see (the "Unmapped countries" list below shows you exactly what's actually in your
  data, so you don't have to guess).
</p>

<div class="card mb-4">
  <div class="card-header">Add a country group</div>
  <div class="card-body">
    <form method="post" action="country_groups.php" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-md-2">
        <input type="text" name="code" class="form-control form-control-sm" placeholder="Code, e.g. AMERICAS" required>
      </div>
      <div class="col-md-3">
        <input type="text" name="label" class="form-control form-control-sm" placeholder="Name, e.g. Americas" required>
      </div>
      <div class="col-md-6">
        <textarea name="countries" class="form-control form-control-sm" rows="1" placeholder="Countries, comma-separated, e.g. United States, USA, Canada, Brazil"></textarea>
      </div>
      <div class="col-md-1">
        <button type="submit" class="btn btn-primary btn-sm w-100">Add</button>
      </div>
    </form>
  </div>
</div>

<table class="table table-striped bg-white">
  <thead><tr><th>Code</th><th>Name</th><th>Countries</th><th>Status</th><th style="width: 220px;"></th></tr></thead>
  <tbody>
  <?php foreach ($countryGroups as $cg): ?>
    <tr>
      <td><code><?= e($cg['code']) ?></code></td>
      <td><?= e($cg['label']) ?></td>
      <td class="small text-muted"><?= e($cg['countries'] ?? '') ?></td>
      <td><span class="badge bg-<?= $cg['is_active'] ? 'success' : 'secondary' ?>"><?= $cg['is_active'] ? 'Active' : 'Inactive' ?></span></td>
      <td>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editCountryGroup<?= (int) $cg['id'] ?>">Edit</button>
        <form method="post" action="country_groups.php" class="d-inline" onsubmit="return confirm('Toggle active status for <?= e($cg['label']) ?>?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_active">
          <input type="hidden" name="id" value="<?= (int) $cg['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $cg['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
        <div class="modal fade" id="editCountryGroup<?= (int) $cg['id'] ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="post" action="country_groups.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $cg['id'] ?>">
                <div class="modal-header">
                  <h5 class="modal-title">Edit "<?= e($cg['label']) ?>"</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="label" class="form-control form-control-sm" value="<?= e($cg['label']) ?>" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Countries <span class="text-muted small">(comma-separated, exact match)</span></label>
                    <textarea name="countries" class="form-control form-control-sm" rows="3"><?= e($cg['countries'] ?? '') ?></textarea>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                  <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$countryGroups): ?>
    <tr><td colspan="5" class="text-center text-muted py-4">No country groups yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<div class="card mb-4">
  <div class="card-header">
    Unmapped countries
    <?= info_icon('Every distinct Company Country value currently NOT matching any active group\'s country list, most common first. Pick an active group and click "Add" to append that exact value -- then run "Reclassify all leads now" below to apply it to these leads.') ?>
  </div>
  <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
    <table class="table table-sm mb-0">
      <thead class="sticky-top bg-white"><tr><th>Company Country</th><th class="text-end">Leads</th><th style="width: 320px;">Add to&hellip;</th></tr></thead>
      <tbody>
        <?php foreach ($unmappedCountries as $row): ?>
          <tr>
            <td><?= e($row['company_country']) ?></td>
            <td class="text-end"><?= number_format($row['cnt']) ?></td>
            <td>
              <?php if ($activeCountryGroups): ?>
                <form method="post" action="country_groups.php" class="d-flex gap-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="add_country">
                  <input type="hidden" name="country" value="<?= e($row['company_country']) ?>">
                  <select name="id" class="form-select form-select-sm" required>
                    <option value="">Pick a country group...</option>
                    <?php foreach ($activeCountryGroups as $cg): ?>
                      <option value="<?= (int) $cg['id'] ?>"><?= e($cg['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Add</button>
                </form>
              <?php else: ?>
                <span class="text-muted small">No active country groups yet.</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$unmappedCountries): ?>
          <tr><td colspan="3" class="text-center text-muted py-3">Every country is currently mapped.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($unmappedCountries) === 200): ?>
    <div class="card-body py-2 text-muted small border-top">Showing the 200 most common unmapped countries -- there may be more further down the tail.</div>
  <?php endif; ?>
</div>

<div class="card mb-4 border-info">
  <div class="card-header">Reclassify existing leads</div>
  <div class="card-body d-flex flex-wrap gap-2 align-items-center">
    <form method="post" action="lead_reclassify_country_groups.php" onsubmit="return confirm('Re-check every lead\'s Company Country against the current active country group lists? This may take a moment for a large leads table.');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-sm btn-outline-info">Reclassify all leads now</button>
    </form>
    <span class="text-muted small">
      <?= number_format($unmappedCount) ?> lead(s) with a Company Country currently unmapped. New leads are
      auto-classified on import; run this after adding/editing country lists to re-apply them to leads
      already in the system.
    </span>
  </div>
</div>

<?php render_footer(); ?>
