<?php
require_once __DIR__ . '/bootstrap.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$account = find_user_by_reset_token($token);
$error = null;

if (!$account) {
    $error = 'This password reset link is invalid or has expired. Ask your admin to generate a new one.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        complete_password_reset($account['id'], $password);
        flash_set('success', "Password reset, {$account['name']} -- you're logged in.");
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset password - Lead Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width: 400px; margin-top: 8rem;">
  <h1 class="h4 mb-3 text-center">Reset password</h1>
  <div class="card">
    <div class="card-body">
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
      <?php endif; ?>
      <?php if ($account): ?>
        <p class="text-muted">Resetting the password for <?= e($account['name']) ?> (<?= e($account['email']) ?>). Choose a new password.</p>
        <form method="post" action="password_reset.php">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="mb-3">
            <label class="form-label" for="password">New password</label>
            <input type="password" class="form-control" id="password" name="password" minlength="8" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label" for="password_confirm">Confirm new password</label>
            <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" required>
          </div>
          <button type="submit" class="btn btn-primary w-100">Set password &amp; log in</button>
        </form>
      <?php else: ?>
        <p class="text-muted mb-0"><a href="login.php">Back to login</a></p>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
