<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/RoleGroupClassifier.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

$admin = require_admin();

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
                db()->prepare('INSERT INTO role_groups (code, label, keywords) VALUES (?, ?, ?)')
                    ->execute([$code, $label, $keywords !== '' ? $keywords : null]);
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
            db()->prepare('UPDATE role_groups SET label = ?, keywords = ? WHERE id = ?')
                ->execute([$label, $keywords !== '' ? $keywords : null, $id]);
            flash_set('success', "\"{$label}\" updated -- run \"Reclassify all leads now\" below to apply keyword changes to existing leads.");
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE role_groups SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    } elseif ($action === 'add_keyword') {
        // One-click helper for the "Unclassified titles" list below --
        // appends the exact title text as a new keyword rather than
        // requiring copy/paste into the Edit modal's textarea. Does not
        // reclassify by itself (same as editing keywords directly) --
        // "Reclassify all leads now" still applies it.
        $id = (int) ($_POST['id'] ?? 0);
        $keyword = trim((string) ($_POST['keyword'] ?? ''));
        $stmt = db()->prepare('SELECT label, keywords FROM role_groups WHERE id = ?');
        $stmt->execute([$id]);
        $rg = $stmt->fetch();
        if (!$rg || $keyword === '') {
            flash_set('danger', 'Could not add keyword.');
        } else {
            $existing = RoleGroupClassifier::parseKeywords($rg['keywords'] ?? '');
            if (in_array($keyword, $existing, true)) {
                flash_set('info', "\"{$keyword}\" is already a keyword on \"{$rg['label']}\".");
            } else {
                $existing[] = $keyword;
                db()->prepare('UPDATE role_groups SET keywords = ? WHERE id = ?')
                    ->execute([implode(', ', $existing), $id]);
                flash_set('success', "\"{$keyword}\" added to \"{$rg['label']}\" -- run \"Reclassify all leads now\" below to apply it.");
            }
        }
    }

    header('Location: role_groups.php');
    exit;
}

$roleGroups = db()->query('SELECT * FROM role_groups ORDER BY label')->fetchAll();
$activeRoleGroups = array_values(array_filter($roleGroups, static fn (array $rg): bool => (bool) $rg['is_active']));
$unclassifiedCount = (int) db()->query("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND role_group_id IS NULL AND title IS NOT NULL AND title != ''")->fetchColumn();
// Distinct unclassified titles, most common first -- so mapping effort
// goes to the titles affecting the most leads first, and the raw text
// is right there to copy/pick into an active group's keywords via the
// one-click "Add as keyword" action below, instead of just a count.
$unclassifiedTitles = db()->query(
    "SELECT title, COUNT(*) AS cnt FROM leads
      WHERE deleted_at IS NULL AND role_group_id IS NULL AND title IS NOT NULL AND title != ''
      GROUP BY title ORDER BY cnt DESC, title LIMIT 200"
)->fetchAll();
// Every distinct title already in the leads table -- lets an admin pick
// from real data via the checkbox dropdown below instead of guessing
// keyword phrases blind. Same source/widget already used for Dashboard's
// Title filter.
$titleOptions = LeadRepository::distinctValues(db(), 'title', 1000);

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
