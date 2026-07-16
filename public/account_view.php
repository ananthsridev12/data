<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/AccountRepository.php';

$user = require_login();

$domain = trim((string) ($_GET['domain'] ?? ''));
$account = $domain !== '' ? AccountRepository::summary(db(), $domain) : null;

if (!$account) {
    flash_set('danger', 'Account not found.');
    header('Location: accounts.php');
    exit;
}

$contacts = AccountRepository::contactsForDomain(db(), $domain);

render_header('Account');
?>
<h1 class="h4 mb-1"><?= e($account['company_name'] ?? $account['domain']) ?></h1>
<p class="text-muted">
  <code><?= e($account['domain']) ?></code> -- <?= (int) $account['contact_count'] ?> contact(s)
  <?php if ($account['suppressed_reason'] !== null): ?>
    -- <span class="badge bg-danger" title="<?= e($account['suppressed_reason']) ?>">Domain suppressed</span>
  <?php endif; ?>
  -- <a href="accounts.php">Back to Accounts</a>
</p>

<div class="table-responsive card mb-3">
  <table class="table table-hover mb-0 align-middle">
    <thead>
      <tr>
        <th>Name</th>
        <th>Title</th>
        <th>Email</th>
        <th>Vertical</th>
        <th>Service</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($contacts as $c): ?>
      <tr>
        <td><?= e($c['first_name'] . ' ' . $c['last_name']) ?></td>
        <td><?= e($c['title']) ?></td>
        <td><?= e($c['email']) ?></td>
        <td><?= e($c['vertical_label'] ?? '') ?></td>
        <td><?= e($c['service_label'] ?? '') ?></td>
        <td><a href="lead_view.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary">View profile</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$contacts): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">No contacts found for this account.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
