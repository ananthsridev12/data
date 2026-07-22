<?php

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function info_icon(string $text): string
{
    return '<span class="text-muted" data-bs-toggle="tooltip" data-bs-placement="top" title="' . e($text) . '" style="cursor: help;">&#9432;</span>';
}

/**
 * Saleshandy email verification status badge -- Bad (never send)/Risky
 * (deliverability uncertain)/Verified (safe to send), or "Not checked
 * yet" if this lead hasn't been pushed to Saleshandy yet (verification
 * only becomes meaningful post-import, see
 * SaleshandyClient::refreshVerificationStatus()). Requires
 * SaleshandyClient.php to classify the raw status; loaded lazily here so
 * pages that don't otherwise need the class don't have to require it
 * just to render this one badge.
 */
function render_verification_badge(?string $rawStatus): string
{
    if ($rawStatus === null || $rawStatus === '') {
        return '<span class="badge bg-light text-muted border">Not checked yet</span>';
    }
    if (!class_exists('SaleshandyClient')) {
        require_once __DIR__ . '/SaleshandyClient.php';
    }
    $tier = SaleshandyClient::classifyVerificationTier($rawStatus);
    $badge = ['bad' => 'danger', 'risky' => 'warning', 'good' => 'success'][$tier] ?? 'secondary';
    $label = ['bad' => 'Bad', 'risky' => 'Risky', 'good' => 'Verified'][$tier] ?? e($rawStatus);
    $textClass = $tier === 'risky' ? ' text-dark' : '';
    return '<span class="badge bg-' . $badge . $textClass . '" title="' . e($rawStatus) . '">' . $label . '</span>';
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
        <li class="nav-item"><a class="nav-link" href="analytics.php">Analytics</a></li>
        <?php if ($user['role'] === ROLE_ADMIN): ?>
        <li class="nav-item"><a class="nav-link" href="import.php">Import</a></li>
        <li class="nav-item"><a class="nav-link" href="import_history.php">Import History</a></li>
        <li class="nav-item"><a class="nav-link" href="campaigns.php">Campaigns</a></li>
        <li class="nav-item"><a class="nav-link" href="lists.php">Lists</a></li>
        <li class="nav-item"><a class="nav-link" href="role_groups.php">Role Groups</a></li>
        <li class="nav-item"><a class="nav-link" href="tags.php">Tags</a></li>
        <li class="nav-item"><a class="nav-link" href="custom_fields.php">Custom Fields</a></li>
        <li class="nav-item"><a class="nav-link" href="bounce_import.php">Bounces</a></li>
        <li class="nav-item"><a class="nav-link" href="bounce_settings.php">Bounce Settings</a></li>
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

/**
 * Multi-select checkbox filter, rendered as a Bootstrap dropdown button so
 * a filter row stays compact. Submits as name="{$name}[]" (one value per
 * checked box) -- LeadRepository's buildWhere() matches any of them.
 *
 * @param array<int,string> $options distinct values to offer
 * @param array<int,string> $selected currently-checked values
 */
function render_multiselect_filter(string $name, string $label, array $options, array $selected): void
{
    $selected = array_map('strval', $selected);
    $checkedCount = count(array_intersect($selected, array_map('strval', $options)));
    ?>
    <div class="multiselect-filter">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 text-start ms-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
        <?= e($label) ?><?= $checkedCount > 0 ? ' (' . $checkedCount . ')' : ' (all)' ?>
      </button>
      <div class="dropdown-menu p-2 ms-menu">
        <?php if (count($options) > 8): ?>
        <input type="text" class="form-control form-control-sm mb-2 ms-search" placeholder="Search&hellip;">
        <?php endif; ?>
        <div class="ms-options">
          <?php foreach ($options as $opt): $id = 'ms_' . $name . '_' . md5($opt); ?>
            <div class="form-check ms-option">
              <input class="form-check-input" type="checkbox" name="<?= e($name) ?>[]" value="<?= e($opt) ?>" id="<?= e($id) ?>" <?= in_array($opt, $selected, true) ? 'checked' : '' ?>>
              <label class="form-check-label small ms-option-label" for="<?= e($id) ?>"><?= e($opt) ?></label>
            </div>
          <?php endforeach; ?>
          <?php if (!$options): ?>
            <div class="text-muted small px-1">No values yet.</div>
          <?php endif; ?>
        </div>
        <?php if ($options): ?>
        <hr class="my-2">
        <div class="d-flex gap-3">
          <button type="button" class="btn btn-sm btn-link p-0 ms-select-all">Select all</button>
          <button type="button" class="btn btn-sm btn-link p-0 ms-clear">Clear</button>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

/**
 * Re-emits a filter array (which may include array-valued multi-select
 * filters and/or booleans) as hidden form fields, so a filter/selection
 * can be resubmitted intact to another endpoint. Pass $namePrefix = 'filter'
 * to emit filter[key]/filter[key][] (matching a $_POST['filter'][...]
 * reader); leave it '' to emit bare key/key[] fields (matching a plain
 * $_GET reader).
 */
function render_hidden_filter_fields(array $filterValues, string $namePrefix = ''): void
{
    foreach ($filterValues as $k => $v) {
        $name = $namePrefix === '' ? $k : "{$namePrefix}[{$k}]";
        if (is_array($v)) {
            foreach ($v as $vv) {
                if ($vv === '' || $vv === null) {
                    continue;
                }
                ?><input type="hidden" name="<?= e($name) ?>[]" value="<?= e((string) $vv) ?>"><?php
            }
            continue;
        }
        if (is_bool($v)) {
            $v = $v ? '1' : '';
        }
        ?><input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $v) ?>"><?php
    }
}

function render_footer(): void
{
    ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php
}
