<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/RoleGroupClassifier.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code = trim((string) ($_POST['code'] ?? ''));
        $label = trim((string) ($_POST['label'] ?? ''));
        $keywords = trim((string) ($_POST['keywords'] ?? ''));

        if ($code === '' || $label === '') {
            flash_set('danger', 'Both a code and a name are required.');
        } else {
            try {
                db()->prepare('INSERT INTO role_groups (company_id, code, label, keywords) VALUES (?, ?, ?, ?)')
                    ->execute([$scope->companyId, $code, $label, $keywords !== '' ? $keywords : null]);
                flash_set('success', "\"{$label}\" added.");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'That code already exists.' : 'Could not add role group.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        $keywords = trim((string) ($_POST['keywords'] ?? ''));

        if ($label === '') {
            flash_set('danger', 'Name is required.');
        } else {
            db()->prepare('UPDATE role_groups SET label = ?, keywords = ? WHERE id = ? AND company_id = ?')
                ->execute([$label, $keywords !== '' ? $keywords : null, $id, $scope->companyId]);
            flash_set('success', "\"{$label}\" updated -- run \"Reclassify all leads now\" below to apply keyword changes to existing leads.");
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE role_groups SET is_active = NOT is_active WHERE id = ? AND company_id = ?')->execute([$id, $scope->companyId]);
        flash_set('success', 'Status updated.');
    } elseif ($action === 'add_keyword') {
        // One-click helper for the "Unclassified titles" list below --
        // appends the exact title text as a new keyword rather than
        // requiring copy/paste into the Edit modal's textarea. Does not
        // reclassify by itself (same as editing keywords directly) --
        // "Reclassify all leads now" still applies it.
        $id = (int) ($_POST['id'] ?? 0);
        $keyword = trim((string) ($_POST['keyword'] ?? ''));
        $stmt = db()->prepare('SELECT label, keywords FROM role_groups WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $scope->companyId]);
        $rg = $stmt->fetch();
        if (!$rg || $keyword === '') {
            flash_set('danger', 'Could not add keyword.');
        } else {
            $existing = RoleGroupClassifier::parseKeywords($rg['keywords'] ?? '');
            if (in_array($keyword, $existing, true)) {
                flash_set('info', "\"{$keyword}\" is already a keyword on \"{$rg['label']}\".");
            } else {
                $existing[] = $keyword;
                db()->prepare('UPDATE role_groups SET keywords = ? WHERE id = ? AND company_id = ?')
                    ->execute([implode(', ', $existing), $id, $scope->companyId]);
                flash_set('success', "\"{$keyword}\" added to \"{$rg['label']}\" -- run \"Reclassify all leads now\" below to apply it.");
            }
        }
    } elseif ($action === 'import_csv') {
        // Bulk create/update role groups from a CSV, one row per group --
        // same fgetcsv()/header-detection pattern as bounce_import.php.
        // Upserts by Code: an existing code's Name/Keywords are
        // overwritten with the file's values (not merged), a new code
        // is inserted active. Does not reclassify by itself -- run
        // "Reclassify all leads now" below afterward, same as any other
        // keyword change.
        if (empty($_FILES['role_groups_file']) || $_FILES['role_groups_file']['error'] !== UPLOAD_ERR_OK) {
            flash_set('danger', 'Please choose a CSV file to import.');
            header('Location: role_groups.php');
            exit;
        }

        $handle = fopen($_FILES['role_groups_file']['tmp_name'], 'r');
        if ($handle === false) {
            flash_set('danger', 'Could not read the uploaded file.');
            header('Location: role_groups.php');
            exit;
        }

        $header = fgetcsv($handle);
        $codeCol = null;
        $labelCol = null;
        $keywordsCol = null;
        if ($header !== false) {
            foreach ($header as $i => $col) {
                $colName = strtolower(trim((string) $col));
                if ($colName === 'code') {
                    $codeCol = $i;
                } elseif (in_array($colName, ['name', 'label'], true)) {
                    $labelCol = $i;
                } elseif ($colName === 'keywords') {
                    $keywordsCol = $i;
                }
            }
        }

        if ($codeCol === null || $labelCol === null) {
            flash_set('danger', 'Could not find both a "Code" and a "Name" column in that file -- check the header row.');
            fclose($handle);
            header('Location: role_groups.php');
            exit;
        }

        $findStmt = db()->prepare('SELECT id FROM role_groups WHERE code = ? AND company_id = ?');
        $insertStmt = db()->prepare('INSERT INTO role_groups (company_id, code, label, keywords) VALUES (?, ?, ?, ?)');
        $updateStmt = db()->prepare('UPDATE role_groups SET label = ?, keywords = ? WHERE id = ? AND company_id = ?');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $code = trim((string) ($row[$codeCol] ?? ''));
            $label = trim((string) ($row[$labelCol] ?? ''));
            $keywords = $keywordsCol !== null ? trim((string) ($row[$keywordsCol] ?? '')) : '';

            if ($code === '' || $label === '') {
                $skipped++;
                continue;
            }

            $findStmt->execute([$code, $scope->companyId]);
            $existingId = $findStmt->fetchColumn();
            if ($existingId) {
                $updateStmt->execute([$label, $keywords !== '' ? $keywords : null, $existingId, $scope->companyId]);
                $updated++;
            } else {
                $insertStmt->execute([$scope->companyId, $code, $label, $keywords !== '' ? $keywords : null]);
                $created++;
            }
        }
        fclose($handle);

        flash_set(
            'success',
            "Import complete: {$created} role group(s) created, {$updated} updated" . ($skipped > 0 ? ", {$skipped} row(s) skipped (missing code or name)" : '')
                . ' -- run "Reclassify all leads now" below to apply any keyword changes to leads already in the system.'
        );
    }

    header('Location: role_groups.php');
    exit;
}

$roleGroupsStmt = db()->prepare('SELECT * FROM role_groups WHERE company_id = ? ORDER BY label');
$roleGroupsStmt->execute([$scope->companyId]);
$roleGroups = $roleGroupsStmt->fetchAll();
$activeRoleGroups = array_values(array_filter($roleGroups, static fn (array $rg): bool => (bool) $rg['is_active']));
$unclassifiedCountStmt = db()->prepare("SELECT COUNT(*) FROM leads WHERE company_id = ? AND deleted_at IS NULL AND role_group_id IS NULL AND title IS NOT NULL AND title != ''");
$unclassifiedCountStmt->execute([$scope->companyId]);
$unclassifiedCount = (int) $unclassifiedCountStmt->fetchColumn();
// Distinct unclassified titles, most common first -- so mapping effort
// goes to the titles affecting the most leads first, and the raw text
// is right there to copy/pick into an active group's keywords via the
// one-click "Add as keyword" action below, instead of just a count.
//
// role_group_id IS NULL alone isn't enough: a title already covered by
// an active group's keywords still has role_group_id = NULL on rows
// imported/edited before "Reclassify all leads now" was last run, which
// would otherwise make an already-mapped title keep reappearing here
// until that button is clicked. Re-run the real classifier against each
// candidate and only list the ones that genuinely don't match anything --
// using the same id-ordered group list (first match wins) that
// LeadImporter/lead_reclassify_roles.php actually classify with, not the
// label-ordered $activeRoleGroups used for this page's own display.
$classifyOrderRoleGroupsStmt = db()->prepare('SELECT id, keywords FROM role_groups WHERE is_active = 1 AND company_id = ? ORDER BY id');
$classifyOrderRoleGroupsStmt->execute([$scope->companyId]);
$classifyOrderRoleGroups = $classifyOrderRoleGroupsStmt->fetchAll();
$unclassifiedTitlesCandidatesStmt = db()->prepare(
    "SELECT title, COUNT(*) AS cnt FROM leads
      WHERE company_id = ? AND deleted_at IS NULL AND role_group_id IS NULL AND title IS NOT NULL AND title != ''
      GROUP BY title ORDER BY cnt DESC, title"
);
$unclassifiedTitlesCandidatesStmt->execute([$scope->companyId]);
$unclassifiedTitlesCandidates = $unclassifiedTitlesCandidatesStmt->fetchAll();
$unclassifiedTitles = [];
foreach ($unclassifiedTitlesCandidates as $row) {
    if (RoleGroupClassifier::classify($row['title'], $classifyOrderRoleGroups) === null) {
        $unclassifiedTitles[] = $row;
        if (count($unclassifiedTitles) >= 200) {
            break;
        }
    }
}
// Every distinct title already in the leads table -- lets an admin pick
// from real data via the checkbox dropdown below instead of guessing
// keyword phrases blind. Same source/widget already used for Dashboard's
// Title filter.
$titleOptions = LeadRepository::distinctValues(db(), $scope, 'title', 1000);

render_header('Role Groups');
?>
<h1 class="h4 mb-3">Role Groups</h1>
<p class="text-muted">
  Consolidates messy free-text job titles (e.g. "VP of Engineering", "SVP Eng Ops", "Director, Platform
  Engineering") into a small set of named targeting buckets. Each group has an ordered, comma-separated,
  case-insensitive keyword list -- a lead's <code>title</code> is matched against each active group's
  keywords in order, first match wins (same matching style as the wave-1 leader title-priority list).
  Leads are auto-classified on import; edit a group's keywords and click "Reclassify all leads now" below
  to re-apply the updated rules to leads already in the system.
</p>
<p class="text-muted small">
  <strong>Matching is substring-based, not whole-word</strong> -- a short/ambiguous keyword can match inside
  an unrelated title (e.g. "CTO" also matches inside "Director", since "di<u>recto</u>r" contains "cto").
  Prefer fuller phrases ("Chief Technology Officer" rather than "CTO") to avoid surprises, and check the
  Dashboard's Role Group filter after adding a new keyword to spot-check what actually matched.
</p>

<div class="card mb-4">
  <div class="card-header">Add a role group</div>
  <div class="card-body">
    <form method="post" action="role_groups.php" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-md-2">
        <input type="text" name="code" class="form-control form-control-sm" placeholder="Code, e.g. ENG_LEAD" required>
      </div>
      <div class="col-md-3">
        <input type="text" name="label" class="form-control form-control-sm" placeholder="Name, e.g. Engineering Leadership" required>
      </div>
      <div class="col-md-4">
        <textarea name="keywords" class="form-control form-control-sm" rows="1" placeholder="Keywords, comma-separated, e.g. VP Engineering, CTO, Head of Engineering"></textarea>
      </div>
      <div class="col-md-2">
        <?php render_multiselect_filter('title_picker_new', 'Pick from existing titles', $titleOptions, []); ?>
      </div>
      <div class="col-md-1">
        <button type="submit" class="btn btn-primary btn-sm w-100">Add</button>
      </div>
    </form>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Import role groups from a file</div>
  <div class="card-body">
    <p class="text-muted small mb-2">
      A CSV with a header row containing <strong>Code</strong>, <strong>Name</strong> (or "Label"), and
      optionally <strong>Keywords</strong> (comma-separated, inside one quoted cell -- e.g.
      <code>"VP Engineering, CTO, Head of Engineering"</code>). One row per role group. An existing
      code's Name/Keywords are <strong>overwritten</strong> by the file's values; a new code is created
      active. Doesn't reclassify existing leads by itself -- run "Reclassify all leads now" below afterward.
    </p>
    <form method="post" action="role_groups.php" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import_csv">
      <input type="file" name="role_groups_file" class="form-control form-control-sm" accept=".csv" required style="max-width: 320px;">
      <button type="submit" class="btn btn-outline-primary btn-sm">Import</button>
      <a class="small" download="role_groups_template.csv" href="data:text/csv;charset=utf-8,Code%2CName%2CKeywords%0AENG_LEAD%2CEngineering%20Leadership%2C%22VP%20Engineering%2C%20CTO%2C%20Head%20of%20Engineering%22%0A">Download a template</a>
    </form>
  </div>
</div>

<table class="table table-striped bg-white">
  <thead><tr><th>Code</th><th>Name</th><th>Keywords</th><th>Status</th><th style="width: 220px;"></th></tr></thead>
  <tbody>
  <?php foreach ($roleGroups as $rg): ?>
    <tr>
      <td><code><?= e($rg['code']) ?></code></td>
      <td><?= e($rg['label']) ?></td>
      <td class="small text-muted"><?= e($rg['keywords'] ?? '') ?></td>
      <td><span class="badge bg-<?= $rg['is_active'] ? 'success' : 'secondary' ?>"><?= $rg['is_active'] ? 'Active' : 'Inactive' ?></span></td>
      <td>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editRoleGroup<?= (int) $rg['id'] ?>">Edit</button>
        <form method="post" action="role_groups.php" class="d-inline" onsubmit="return confirm('Toggle active status for <?= e($rg['label']) ?>?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_active">
          <input type="hidden" name="id" value="<?= (int) $rg['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $rg['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
        <div class="modal fade" id="editRoleGroup<?= (int) $rg['id'] ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="post" action="role_groups.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $rg['id'] ?>">
                <div class="modal-header">
                  <h5 class="modal-title">Edit "<?= e($rg['label']) ?>"</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="label" class="form-control form-control-sm" value="<?= e($rg['label']) ?>" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Keywords <span class="text-muted small">(comma-separated, order matters -- first match wins)</span></label>
                    <textarea name="keywords" class="form-control form-control-sm" rows="3"><?= e($rg['keywords'] ?? '') ?></textarea>
                  </div>
                  <div class="mb-3">
                    <label class="form-label small text-muted mb-0">Pick from existing titles</label>
                    <?php render_multiselect_filter('title_picker_' . (int) $rg['id'], 'Existing titles', $titleOptions, RoleGroupClassifier::parseKeywords($rg['keywords'] ?? '')); ?>
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
  <?php if (!$roleGroups): ?>
    <tr><td colspan="5" class="text-center text-muted py-4">No role groups yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<div class="card mb-4">
  <div class="card-header">
    Unclassified titles
    <?= info_icon('Every distinct title currently NOT matching any active role group\'s keywords, most common first. Pick an active group and click "Add" to append that exact title as a new keyword -- then run "Reclassify all leads now" below to apply it to these leads.') ?>
  </div>
  <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
    <table class="table table-sm mb-0">
      <thead class="sticky-top bg-white"><tr><th>Title</th><th class="text-end">Leads</th><th style="width: 320px;">Add as keyword to&hellip;</th></tr></thead>
      <tbody>
        <?php foreach ($unclassifiedTitles as $row): ?>
          <tr>
            <td><?= e($row['title']) ?></td>
            <td class="text-end"><?= number_format($row['cnt']) ?></td>
            <td>
              <?php if ($activeRoleGroups): ?>
                <form method="post" action="role_groups.php" class="d-flex gap-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="add_keyword">
                  <input type="hidden" name="keyword" value="<?= e($row['title']) ?>">
                  <select name="id" class="form-select form-select-sm" required>
                    <option value="">Pick a role group...</option>
                    <?php foreach ($activeRoleGroups as $rg): ?>
                      <option value="<?= (int) $rg['id'] ?>"><?= e($rg['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Add</button>
                </form>
              <?php else: ?>
                <span class="text-muted small">No active role groups yet.</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$unclassifiedTitles): ?>
          <tr><td colspan="3" class="text-center text-muted py-3">Every title is currently classified.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($unclassifiedTitles) === 200): ?>
    <div class="card-body py-2 text-muted small border-top">Showing the 200 most common unclassified titles -- there may be more further down the tail.</div>
  <?php endif; ?>
</div>

<div class="card mb-4 border-info">
  <div class="card-header">Reclassify existing leads</div>
  <div class="card-body d-flex flex-wrap gap-2 align-items-center">
    <form method="post" action="lead_reclassify_roles.php" onsubmit="return confirm('Re-check every lead\'s title against the current active role group keywords? This may take a moment for a large leads table.');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-sm btn-outline-info">Reclassify all leads now</button>
    </form>
    <span class="text-muted small">
      <?= number_format($unclassifiedCount) ?> lead(s) with a title currently unclassified. New leads are
      auto-classified on import; run this after adding/editing keyword rules to re-apply them to leads
      already in the system.
    </span>
  </div>
</div>

<?php render_footer(); ?>
