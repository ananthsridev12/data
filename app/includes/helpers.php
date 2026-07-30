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

/**
 * @return array<int,array{href:string,label:string}> every nav link the
 *   current user can see, in display order -- shared by the sidebar
 *   markup below and anything else that needs "what pages exist" (kept
 *   here rather than inlined in render_header() so it's one list to
 *   update when a page is added, not two).
 */
function nav_links(array $user): array
{
    $links = [
        ['href' => 'dashboard.php', 'label' => 'Dashboard'],
        ['href' => 'campaigns.php', 'label' => 'Campaigns'],
        ['href' => 'accounts.php', 'label' => 'Accounts'],
        ['href' => 'analytics.php', 'label' => 'Analytics'],
        ['href' => 'reports.php', 'label' => 'Reports'],
    ];
    if ($user['role'] === ROLE_ADMIN) {
        $links = array_merge($links, [
            ['href' => 'import.php', 'label' => 'Import'],
            ['href' => 'import_history.php', 'label' => 'Import History'],
            ['href' => 'lists.php', 'label' => 'Lists'],
            ['href' => 'role_groups.php', 'label' => 'Role Groups'],
            ['href' => 'country_groups.php', 'label' => 'Country Groups'],
            ['href' => 'icp_segments.php', 'label' => 'ICP Segments'],
            ['href' => 'icp_report.php', 'label' => 'ICP Report'],
            ['href' => 'tags.php', 'label' => 'Tags'],
            ['href' => 'custom_fields.php', 'label' => 'Custom Fields'],
            ['href' => 'bounce_import.php', 'label' => 'Bounces'],
            ['href' => 'bounce_settings.php', 'label' => 'Bounce Settings'],
            ['href' => 'saleshandy_field_mapping.php', 'label' => 'SH Field Mapping'],
            ['href' => 'import_campaign_history.php', 'label' => 'Backfill History'],
            ['href' => 'deleted_leads.php', 'label' => 'Deleted Leads'],
            ['href' => 'users.php', 'label' => 'Users'],
            ['href' => 'company_profile.php', 'label' => 'Company Profile'],
        ]);
    }
    return $links;
}

function render_header(string $title): void
{
    $user = current_user();
    $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - Lead Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
<script>
// Applied before first paint (blocking, in <head>) so there's no flash of
// the wrong theme/sidebar-state on load -- an external/deferred script
// would run too late, after Bootstrap's default (light) styles already
// painted. Both preferences are per-browser (localStorage), not tied to
// the user's account.
(function () {
  var theme = localStorage.getItem('theme');
  if (theme !== 'dark' && theme !== 'light') {
    theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  document.documentElement.setAttribute('data-bs-theme', theme);
  if (localStorage.getItem('sidebarCollapsed') === '1') {
    document.documentElement.classList.add('sidebar-collapsed');
  }
})();
</script>
</head>
<body>
<?php if ($user): ?>
<div class="app-shell">
  <nav class="sidebar" id="appSidebar">
    <div class="sidebar-header">
      <a class="sidebar-brand" href="dashboard.php">Lead Dashboard</a>
      <button class="btn btn-sm btn-outline-secondary sidebar-toggle" id="sidebarToggle" type="button" aria-label="Collapse sidebar" title="Collapse sidebar">&laquo;</button>
    </div>
    <div class="sidebar-links">
      <?php foreach (nav_links($user) as $link): ?>
        <a class="sidebar-link<?= $link['href'] === $currentPage ? ' active' : '' ?>" href="<?= e($link['href']) ?>"><?= e($link['label']) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="sidebar-footer">
      <button class="btn btn-sm btn-outline-secondary w-100 mb-2" id="themeToggle" type="button">
        <span class="theme-toggle-label">Toggle theme</span>
      </button>
      <div class="small text-muted mb-2"><?= e($user['name']) ?> (<?= e($user['role']) ?>)</div>
      <a class="btn btn-outline-secondary btn-sm w-100 mb-1" href="change_password.php">Change password</a>
      <a class="btn btn-outline-secondary btn-sm w-100" href="logout.php">Log out</a>
    </div>
  </nav>
  <button class="btn btn-sm btn-outline-secondary sidebar-open-btn" id="sidebarOpenBtn" type="button" aria-label="Open menu" title="Open menu">&#9776;</button>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <div class="main-content">
<?php endif; ?>
<div class="container-fluid px-4 py-4">
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
    $user = current_user();
    ?>
</div>
<?php if ($user): ?>
  </div>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php
}
