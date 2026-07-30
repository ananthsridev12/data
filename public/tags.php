<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/TagRepository.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash_set('danger', 'A tag name is required.');
        } else {
            try {
                TagRepository::findOrCreateByName(db(), $name, $scope->companyId);
                flash_set('success', "Tag \"{$name}\" added.");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'That tag already exists.' : 'Could not add tag.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM tags WHERE id = ? AND company_id = ?')->execute([$id, $scope->companyId]);
        flash_set('success', 'Tag deleted.');
    } elseif ($action === 'sync') {
        try {
            $client = SaleshandyClient::forUser(db(), $user['id']);
            $count = TagRepository::syncFromSaleshandy(db(), $client->listTags(), $scope->companyId);
            flash_set('success', "Synced {$count} tag(s) from Saleshandy.");
        } catch (SaleshandyApiException $ex) {
            flash_set('danger', 'Could not sync tags from Saleshandy: ' . $ex->getMessage());
        }
    }

    header('Location: tags.php');
    exit;
}

$tagsStmt = db()->prepare(
    'SELECT t.*, (SELECT COUNT(*) FROM lead_tags lt WHERE lt.tag_id = t.id) AS lead_count FROM tags t WHERE t.company_id = ? ORDER BY t.name'
);
$tagsStmt->execute([$scope->companyId]);
$tags = $tagsStmt->fetchAll();

render_header('Tags');
?>
<h1 class="h4 mb-3">Tags</h1>
<p class="text-muted">Tags leads can be labeled with -- used during import, on a lead's detail page, and when pushing to Saleshandy (Saleshandy tags stay in sync with these). Deleting a tag removes it from every lead that had it.</p>

<div class="card mb-4">
  <div class="card-body d-flex flex-wrap gap-2 align-items-center">
    <form method="post" action="tags.php" class="d-flex gap-2 align-items-center">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <input type="text" name="name" class="form-control form-control-sm" placeholder="New tag name" required style="max-width: 220px;">
      <button type="submit" class="btn btn-primary btn-sm">Add</button>
    </form>
    <form method="post" action="tags.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sync">
      <button type="submit" class="btn btn-outline-secondary btn-sm">Sync from Saleshandy</button>
    </form>
  </div>
</div>

<div class="table-responsive card">
  <table class="table table-sm mb-0">
    <thead><tr><th>Name</th><th>Leads tagged</th><th>Saleshandy tag id</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tags as $t): ?>
      <tr>
        <td><?= e($t['name']) ?></td>
        <td><?= (int) $t['lead_count'] ?></td>
        <td class="small text-muted"><?= e($t['saleshandy_tag_id'] ?? '') ?></td>
        <td>
          <form method="post" action="tags.php" onsubmit="return confirm('Delete tag &quot;<?= e($t['name']) ?>&quot;? It will be removed from <?= (int) $t['lead_count'] ?> lead(s).');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$tags): ?>
      <tr><td colspan="4" class="text-center text-muted py-3">No tags yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
