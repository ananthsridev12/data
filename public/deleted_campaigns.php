<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/ScopeFilter.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$deletedClauses = ['c.company_id = :scope_company_id', 'c.deleted_at IS NOT NULL'];
$deletedParams = ['scope_company_id' => $scope->companyId];
ScopeFilter::applyOwnerScope($deletedClauses, $deletedParams, $scope, db(), 'c', 'saleshandy_account_owner_id');

$stmt = db()->prepare(
    'SELECT c.id, c.name, c.deleted_at, deleter.name AS deleted_by_name, owner.name AS owner_name
       FROM campaigns c
       LEFT JOIN users deleter ON deleter.id = c.deleted_by
       LEFT JOIN users owner ON owner.id = c.saleshandy_account_owner_id
      WHERE ' . implode(' AND ', $deletedClauses) . '
      ORDER BY c.deleted_at DESC'
);
$stmt->execute($deletedParams);
$campaigns = $stmt->fetchAll();

render_header('Deleted Campaigns');
?>
<h1 class="h4 mb-1">Deleted campaigns</h1>
<p class="text-muted">
  <a href="campaigns.php">&laquo; Back to campaigns</a>
</p>
<p class="text-muted"><?= number_format(count($campaigns)) ?> campaign(s) deleted. Deleting hides a campaign everywhere but keeps its lead/history data -- restore brings it back into normal view.</p>

<div class="table-responsive card mb-3">
  <table class="table table-sm mb-0 align-middle">
    <thead><tr><th>Name</th><th>Owner</th><th>Deleted</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($campaigns as $c): ?>
      <tr>
        <td><?= e($c['name']) ?></td>
        <td><?= e($c['owner_name'] ?? '(unowned)') ?></td>
        <td class="small text-muted"><?= e($c['deleted_at']) ?> by <?= e($c['deleted_by_name'] ?? '') ?></td>
        <td>
          <form method="post" action="campaign_delete.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="campaign_id" value="<?= (int) $c['id'] ?>">
            <input type="hidden" name="return_to" value="deleted">
            <button type="submit" class="btn btn-sm btn-outline-success">Restore</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$campaigns): ?>
      <tr><td colspan="4" class="text-center text-muted py-4">No deleted campaigns.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
