<?php
require_once __DIR__ . '/bootstrap.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$invitee = find_user_by_invite_token($token);
$error = null;

if (!$invitee) {
    $error = 'This signup link is invalid or has expired. Ask your admin to generate a new one.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        complete_signup($invitee['id'], $password);
        flash_set('success', "Welcome, {$invitee['name']}! Your account is ready.");
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
<title>Set up your account - Lead Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width: 400px; margin-top: 8rem;">
  <h1 class="h4 mb-3 text-center">Set up your account</h1>
  <div class="card">
    <div class="card-body">
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
      <?php endif; ?>
      <?php if ($invitee): ?>
        <p class="text-muted">Welcome, <?= e($invitee['name']) ?> (<?= e($invitee['email']) ?>). Choose a password to finish setting up your account.</p>
        <form method="post" action="signup.php">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input type="password" class="form-control" id="password" name="password" minlength="8" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label" for="password_confirm">Confirm password</label>
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
