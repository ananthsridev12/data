<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/ScopeFilter.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

$deletedClauses = ['l.company_id = :scope_company_id', 'l.deleted_at IS NOT NULL'];
$deletedParams = ['scope_company_id' => $scope->companyId];
ScopeFilter::applyOwnerScope($deletedClauses, $deletedParams, $scope, db(), 'l');
$deletedWhere = implode(' AND ', $deletedClauses);

$countStmt = db()->prepare("SELECT COUNT(*) FROM leads l WHERE {$deletedWhere}");
$countStmt->execute($deletedParams);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT l.id, l.na_company_name, l.first_name, l.last_name, l.email, l.deleted_at, u.name AS deleted_by_name
       FROM leads l
       LEFT JOIN users u ON u.id = l.deleted_by
      WHERE {$deletedWhere}
      ORDER BY l.deleted_at DESC
      LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($deletedParams);
$leads = $stmt->fetchAll();

render_header('Deleted leads');
?>
<h1 class="h4 mb-3">Deleted leads</h1>
<p class="text-muted"><?= number_format($total) ?> lead(s) deleted (page <?= $page ?> of <?= $totalPages ?>). Deleting hides a lead everywhere but keeps its campaign/import history -- restore brings it back into normal view.</p>

<div class="table-responsive card mb-3">
  <table class="table table-sm mb-0 align-middle">
    <thead><tr><th>Company</th><th>Name</th><th>Email</th><th>Deleted</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($leads as $l): ?>
      <tr>
        <td><?= e($l['na_company_name']) ?></td>
        <td><a href="lead_view.php?id=<?= (int) $l['id'] ?>"><?= e($l['first_name'] . ' ' . $l['last_name']) ?></a></td>
        <td><?= e($l['email']) ?></td>
        <td class="small text-muted"><?= e($l['deleted_at']) ?> by <?= e($l['deleted_by_name'] ?? '') ?></td>
        <td>
          <form method="post" action="lead_delete.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="lead_id" value="<?= (int) $l['id'] ?>">
            <input type="hidden" name="return_to" value="deleted_leads.php?page=<?= (int) $page ?>">
            <button type="submit" class="btn btn-sm btn-outline-success">Restore</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$leads): ?>
      <tr><td colspan="5" class="text-center text-muted py-4">No deleted leads.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
<nav>
  <ul class="pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link" href="deleted_leads.php?page=<?= $p ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>
<?php render_footer(); ?>
