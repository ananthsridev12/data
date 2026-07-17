<?php
require_once __DIR__ . '/bootstrap.php';

$user = require_login();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['new_password_confirm'] ?? '');

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $currentHash = $stmt->fetchColumn();

    if (!$currentHash || !password_verify($current, $currentHash)) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        flash_set('success', 'Password changed.');
        header('Location: dashboard.php');
        exit;
    }
}

render_header('Change password');
?>
<h1 class="h4 mb-3">Change password</h1>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width: 420px;">
  <div class="card-body">
    <form method="post" action="change_password.php">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="current_password">Current password</label>
        <input type="password" class="form-control" id="current_password" name="current_password" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label" for="new_password">New password</label>
        <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="new_password_confirm">Confirm new password</label>
        <input type="password" class="form-control" id="new_password_confirm" name="new_password_confirm" minlength="8" required>
      </div>
      <button type="submit" class="btn btn-primary">Change password</button>
    </form>
  </div>
</div>
<?php render_footer(); ?>
