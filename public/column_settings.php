<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/ColumnPreferences.php';

$user = require_login();

$page = $_GET['page'] ?? $_POST['page'] ?? '';
if (!isset(ColumnPreferences::PAGES[$page])) {
    flash_set('danger', 'Unknown page.');
    header('Location: dashboard.php');
    exit;
}

$pageLabels = ['dashboard' => 'Dashboard', 'campaign_leads' => 'Campaign leads'];
$returnTo = $_GET['return_to'] ?? $_POST['return_to'] ?? ($page === 'dashboard' ? 'dashboard.php' : 'campaigns.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $key = $_POST['key'] ?? '';

    if ($action === 'toggle') {
        ColumnPreferences::toggle(db(), $user['id'], $page, $key);
    } elseif ($action === 'move_up') {
        ColumnPreferences::move(db(), $user['id'], $page, $key, 'up');
    } elseif ($action === 'move_down') {
        ColumnPreferences::move(db(), $user['id'], $page, $key, 'down');
    } elseif ($action === 'reset') {
        ColumnPreferences::resetToDefault(db(), $user['id'], $page);
    }

    header('Location: column_settings.php?page=' . urlencode($page) . '&return_to=' . urlencode($returnTo));
    exit;
}

$columns = ColumnPreferences::getForUser(db(), $user['id'], $page);

render_header('Manage columns');
?>
<h1 class="h4 mb-1">Manage columns -- <?= e($pageLabels[$page] ?? $page) ?></h1>
<p class="text-muted">Choose which columns show and in what order. This is saved to your account, so it's just for you.</p>

<div class="card mb-3" style="max-width: 500px;">
  <table class="table table-sm mb-0 align-middle">
    <thead><tr><th>Show</th><th>Column</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($columns as $i => $col): ?>
      <tr>
        <td>
          <form method="post" action="column_settings.php">
            <?= csrf_field() ?>
            <input type="hidden" name="page" value="<?= e($page) ?>">
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="key" value="<?= e($col['key']) ?>">
            <input type="checkbox" class="form-check-input" onchange="this.form.submit()" <?= $col['visible'] ? 'checked' : '' ?>>
          </form>
        </td>
        <td><?= e($col['label']) ?></td>
        <td class="text-end">
          <form method="post" action="column_settings.php" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="page" value="<?= e($page) ?>">
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
            <input type="hidden" name="action" value="move_up">
            <input type="hidden" name="key" value="<?= e($col['key']) ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button>
          </form>
          <form method="post" action="column_settings.php" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="page" value="<?= e($page) ?>">
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
            <input type="hidden" name="action" value="move_down">
            <input type="hidden" name="key" value="<?= e($col['key']) ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === count($columns) - 1 ? 'disabled' : '' ?>>&darr;</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<form method="post" action="column_settings.php" class="d-inline">
  <?= csrf_field() ?>
  <input type="hidden" name="page" value="<?= e($page) ?>">
  <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
  <input type="hidden" name="action" value="reset">
  <button type="submit" class="btn btn-sm btn-outline-secondary">Reset to default</button>
</form>
<a href="<?= e($returnTo) ?>" class="btn btn-sm btn-primary">Done</a>
<?php render_footer(); ?>
