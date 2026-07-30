<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/AccountRepository.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$filters = ['q' => trim((string) ($_GET['q'] ?? ''))];
$page = max(1, (int) ($_GET['page'] ?? 1));

$result = AccountRepository::search(db(), $scope, $filters, $page);

$filterQuery = $_GET;
unset($filterQuery['page']);

render_header('Accounts');
?>
<h1 class="h4 mb-1">Accounts</h1>
<p class="text-muted">Companies, grouped automatically by your leads' email domain -- one account per domain, since each contact's company email already identifies who they work for.</p>

<div class="card filter-card mb-4">
  <div class="card-body">
    <form method="get" action="accounts.php" class="row g-2">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search company or domain" value="<?= e($filters['q']) ?>">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
      </div>
    </form>
  </div>
</div>

<div class="text-muted small mb-2"><?= number_format($result['total']) ?> account(s) (page <?= $result['page'] ?> of <?= $result['totalPages'] ?>)</div>

<div class="table-responsive card mb-3">
  <table class="table table-hover mb-0 align-middle">
    <thead>
      <tr>
        <th>Company</th>
        <th>Domain</th>
        <th>Contacts</th>
        <th></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($result['rows'] as $acct): ?>
      <tr>
        <td><?= e($acct['company_name'] ?? '') ?></td>
        <td><code><?= e($acct['domain']) ?></code></td>
        <td><?= (int) $acct['contact_count'] ?></td>
        <td>
          <?php if ($acct['suppressed_reason'] !== null): ?>
            <span class="badge bg-danger" title="<?= e($acct['suppressed_reason']) ?>">Suppressed</span>
          <?php endif; ?>
        </td>
        <td><a href="account_view.php?domain=<?= urlencode($acct['domain']) ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$result['rows']): ?>
      <tr><td colspan="5" class="text-center text-muted py-4">No accounts match this filter.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($result['totalPages'] > 1): ?>
<nav>
  <ul class="pagination">
    <?php for ($p = 1; $p <= $result['totalPages']; $p++): $q = $filterQuery; $q['page'] = $p; ?>
      <li class="page-item <?= $p === $result['page'] ? 'active' : '' ?>">
        <a class="page-link" href="accounts.php?<?= http_build_query($q) ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php render_footer(); ?>
