<?php

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function flash_set(string $type, string $message): void
{
    auth_boot();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_take(): array
{
    auth_boot();
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function render_header(string $title): void
{
    $user = current_user();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - Lead Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php">Lead Dashboard</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="accounts.php">Accounts</a></li>
        <?php if ($user['role'] === ROLE_ADMIN): ?>
        <li class="nav-item"><a class="nav-link" href="import.php">Import</a></li>
        <li class="nav-item"><a class="nav-link" href="import_history.php">Import History</a></li>
        <li class="nav-item"><a class="nav-link" href="campaigns.php">Campaigns</a></li>
        <li class="nav-item"><a class="nav-link" href="lists.php">Lists</a></li>
        <li class="nav-item"><a class="nav-link" href="tags.php">Tags</a></li>
        <li class="nav-item"><a class="nav-link" href="custom_fields.php">Custom Fields</a></li>
        <li class="nav-item"><a class="nav-link" href="bounce_import.php">Bounces</a></li>
        <li class="nav-item"><a class="nav-link" href="saleshandy_field_mapping.php">SH Field Mapping</a></li>
        <li class="nav-item"><a class="nav-link" href="import_campaign_history.php">Backfill History</a></li>
        <li class="nav-item"><a class="nav-link" href="deleted_leads.php">Deleted Leads</a></li>
        <li class="nav-item"><a class="nav-link" href="users.php">Users</a></li>
        <?php endif; ?>
      </ul>
      <span class="navbar-text me-3"><?= e($user['name']) ?> (<?= e($user['role']) ?>)</span>
      <a class="btn btn-outline-light btn-sm me-2" href="change_password.php">Change password</a>
      <a class="btn btn-outline-light btn-sm" href="logout.php">Log out</a>
    </div>
  </div>
</nav>
<?php endif; ?>
<div class="container-fluid px-4">
<?php foreach (flash_take() as $flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endforeach; ?>
<?php
}

function render_footer(): void
{
    ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
