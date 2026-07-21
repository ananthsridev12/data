<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';
require_once __DIR__ . '/../app/includes/TagRepository.php';

$user = require_login();

$campaignId = (int) ($_GET['campaign_id'] ?? $_POST['campaign_id'] ?? 0);
$campStmt = db()->prepare('SELECT * FROM campaigns WHERE id = ?');
$campStmt->execute([$campaignId]);
$campaign = $campStmt->fetch();

if (!$campaign) {
    flash_set('danger', 'Campaign not found.');
    header('Location: campaigns.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select') {
    csrf_verify();

    $rawFilters = $_POST['filter'] ?? [];
    $filters = [
        'q' => $rawFilters['q'] ?? '',
        'company' => $rawFilters['company'] ?? '',
        'domain' => $rawFilters['domain'] ?? '',
        'title' => (array) ($rawFilters['title'] ?? []),
        'seniority' => (array) ($rawFilters['seniority'] ?? []),
        'departments' => (array) ($rawFilters['departments'] ?? []),
        'industry' => (array) ($rawFilters['industry'] ?? []),
        'country' => (array) ($rawFilters['country'] ?? []),
        'company_country' => (array) ($rawFilters['company_country'] ?? []),
        'employee_count' => (array) ($rawFilters['employee_count'] ?? []),
        'vertical_id' => $rawFilters['vertical_id'] ?? '',
        'service_id' => $rawFilters['service_id'] ?? '',
        'campaign_id' => $campaignId,
        'hide_used_in_campaign' => !empty($rawFilters['hide_used_in_campaign']),
        'pending_elsewhere' => $rawFilters['pending_elsewhere'] ?? '',
        'account_used_elsewhere' => $rawFilters['account_used_elsewhere'] ?? '',
    ];

    // Same reasoning as the GET-side gate below: without at least one
    // real narrowing filter, this would run an unfiltered scan and (in
    // "all" mode especially, which has no per-company limit) could
    // assign every eligible lead in the whole system to this campaign
    // from a single click. Require a filter rather than allow that.
    $hasRealFilter = $filters['q'] !== '' || $filters['company'] !== '' || $filters['domain'] !== ''
        || $filters['title'] || $filters['seniority'] || $filters['departments']
        || $filters['industry'] || $filters['country'] || $filters['company_country'] || $filters['employee_count']
        || $filters['vertical_id'] !== '' || $filters['service_id'] !== ''
        || $filters['pending_elsewhere'] !== '' || $filters['account_used_elsewhere'] !== '';
    if (!$hasRealFilter) {
        flash_set('danger', 'Apply at least one filter before saving a selection -- an unfiltered selection would match every lead in the system.');
        header('Location: campaign_select_leads.php?campaign_id=' . $campaignId);
        exit;
    }

    $leadIds = LeadRepository::matchingIds(db(), $filters);

    if (!$leadIds) {
        flash_set('danger', 'No leads match this filter.');
        header('Location: campaign_select_leads.php?campaign_id=' . $campaignId);
        exit;
    }

    $selectMode = $_POST['select_mode'] ?? 'wave_auto';

    // "2 in "DM-DT-ESI-US-01", 1 in "Persona Test Campaign"" -- so a skip
    // is never just a bare count, the admin can see exactly where each
    // account's other persona is already pending.
    $describePendingElsewhere = static function (array $campaignCounts): string {
        $parts = [];
        foreach ($campaignCounts as $campaignName => $count) {
            $parts[] = "{$count} in \"{$campaignName}\"";
        }
        return implode(', ', $parts);
    };

    if ($selectMode === 'all') {
        $filtered = WaveAssigner::filterEligibleForCampaign(db(), $leadIds, $campaignId);
        $insert = db()->prepare('INSERT IGNORE INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by) VALUES (?, ?, ?)');
        $assigned = 0;
        $already = 0;
        db()->beginTransaction();
        foreach ($filtered['eligible'] as $leadId) {
            $insert->execute([$leadId, $campaignId, $user['id']]);
            if ($insert->rowCount() === 1) {
                $assigned++;
            } else {
                $already++;
            }
        }
        db()->commit();
        $message = "{$assigned} lead(s) assigned (no per-company limit).";
        if ($already > 0) {
            $message .= " {$already} were already in this campaign.";
        }
        if ($filtered['suppressed_count'] > 0) {
            $message .= " {$filtered['suppressed_count']} skipped (suppressed domain).";
        }
        if ($filtered['already_elsewhere_count'] > 0) {
            $message .= " {$filtered['already_elsewhere_count']} skipped (already assigned to a different campaign -- a lead can only belong to one).";
        }
        if ($filtered['pending_elsewhere_count'] > 0) {
            $message .= " {$filtered['pending_elsewhere_count']} skipped (their account already has a persona pending delivery in another campaign: "
                . $describePendingElsewhere($filtered['pending_elsewhere_campaigns']) . ').';
        }
    } else {
        if ($selectMode === 'wave_manual') {
            $titlePriority = array_values(array_filter(array_map('trim', $_POST['allowed_titles'] ?? [])));
            if (!$titlePriority) {
                flash_set('danger', 'Check at least one title for manual persona selection, or switch to auto/all.');
                header('Location: campaign_select_leads.php?campaign_id=' . $campaignId);
                exit;
            }
        } else {
            $titlePriority = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['title_priority'] ?? '')))));
        }

        $stats = WaveAssigner::assign(db(), $leadIds, $campaignId, $user['id'], $titlePriority);
        $message = "{$stats['leaders']} wave-1 contact(s) selected across {$stats['domains']} companies, "
            . "{$stats['held']} held pending that outcome.";
        if ($stats['suppressed_skipped'] > 0) {
            $message .= " {$stats['suppressed_skipped']} skipped (suppressed domain).";
        }
        if ($stats['already_elsewhere_skipped'] > 0) {
            $message .= " {$stats['already_elsewhere_skipped']} skipped (already assigned to a different campaign -- a lead can only belong to one).";
        }
        if ($stats['pending_elsewhere_skipped'] > 0) {
            $message .= " {$stats['pending_elsewhere_skipped']} skipped (their account already has a persona pending delivery in another campaign: "
                . $describePendingElsewhere($stats['pending_elsewhere_campaigns']) . ').';
        }
        if ($stats['already_in_campaign'] > 0) {
            $message .= " {$stats['already_in_campaign']} were already in this campaign.";
        }
    }

    flash_set('success', $message);
    header('Location: campaign_leads.php?campaign_id=' . $campaignId);
    exit;
}

// GET: show the filter + selection-mode form.
$multiParam = static function (string $key): array {
    return array_values(array_filter(array_map('trim', (array) ($_GET[$key] ?? []))));
};

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'company' => trim((string) ($_GET['company'] ?? '')),
    'domain' => trim((string) ($_GET['domain'] ?? '')),
    'title' => $multiParam('title'),
    'seniority' => $multiParam('seniority'),
    'departments' => $multiParam('departments'),
    'industry' => $multiParam('industry'),
    'country' => $multiParam('country'),
    'company_country' => $multiParam('company_country'),
    'employee_count' => $multiParam('employee_count'),
    'vertical_id' => trim((string) ($_GET['vertical_id'] ?? '')),
    'service_id' => trim((string) ($_GET['service_id'] ?? '')),
    'campaign_id' => $campaignId,
    'hide_used_in_campaign' => !isset($_GET['hide_used_in_campaign']) || $_GET['hide_used_in_campaign'] === '1',
    'pending_elsewhere' => trim((string) ($_GET['pending_elsewhere'] ?? '')),
    'account_used_elsewhere' => trim((string) ($_GET['account_used_elsewhere'] ?? '')),
];

// A completely unfiltered load (first click into this page) would run
// matchingIds()/domainCountForFilter()/search() over the whole leads
// table -- slow, and mostly useless since "every lead in the system"
// isn't a real candidate pool for one campaign. Require at least one
// real narrowing filter before running any of that; hide_used_in_campaign
// doesn't count since it's on by default and doesn't meaningfully shrink
// an otherwise-unfiltered scan.
$hasRealFilter = $filters['q'] !== '' || $filters['company'] !== '' || $filters['domain'] !== ''
    || $filters['title'] || $filters['seniority'] || $filters['departments']
    || $filters['industry'] || $filters['country'] || $filters['company_country'] || $filters['employee_count']
    || $filters['vertical_id'] !== '' || $filters['service_id'] !== ''
    || $filters['pending_elsewhere'] !== '' || $filters['account_used_elsewhere'] !== '';

if ($hasRealFilter) {
    $leadCount = count(LeadRepository::matchingIds(db(), $filters));
    $domainCount = LeadRepository::domainCountForFilter(db(), $filters);
    $titles = LeadRepository::distinctTitlesForFilter(db(), $filters);
    $previewPage = max(1, (int) ($_GET['page'] ?? 1));
    $preview = LeadRepository::search(db(), $filters, $previewPage);
} else {
    $leadCount = null;
    $domainCount = null;
    $titles = [];
    $preview = ['rows' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1];
}

$titleOptions = LeadRepository::distinctValues(db(), 'title', 1000);
$seniorities = LeadRepository::distinctValues(db(), 'seniority');
$departmentOptions = LeadRepository::distinctValues(db(), 'departments');
$industries = LeadRepository::distinctValues(db(), 'industry');
$countries = LeadRepository::distinctValues(db(), 'country');
$companyCountries = LeadRepository::distinctValues(db(), 'company_country');
$employeeCounts = LeadRepository::distinctValues(db(), 'employee_count');
$verticals = LeadRepository::activeLookupOptions(db(), 'verticals');
$services = LeadRepository::activeLookupOptions(db(), 'services');
$existingTags = TagRepository::all(db());

render_header('Select leads');
?>
<h1 class="h4 mb-1">Add leads to "<?= e($campaign['name']) ?>"</h1>
<p class="text-muted"><a href="campaign_leads.php?campaign_id=<?= (int) $campaignId ?>">&laquo; Back to this campaign</a></p>
<datalist id="existingTagNames">
  <?php foreach ($existingTags as $t): ?>
    <option value="<?= e($t['name']) ?>">
  <?php endforeach; ?>
</datalist>

<form method="get" action="campaign_select_leads.php" class="card filter-card mb-4">
  <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
  <div class="card-body row g-2">
    <div class="col-md-3">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Search company, title, products, keywords" value="<?= e($filters['q']) ?>">
    </div>
    <div class="col-md-2">
      <input type="text" name="company" class="form-control form-control-sm" placeholder="Company" value="<?= e($filters['company']) ?>">
    </div>
    <div class="col-md-2">
      <input type="text" name="domain" class="form-control form-control-sm" placeholder="Email domain" value="<?= e($filters['domain']) ?>">
    </div>
    <div class="col-md-2">
      <?php render_multiselect_filter('title', 'Title', $titleOptions, $filters['title']); ?>
    </div>
    <div class="col-md-2">
      <?php render_multiselect_filter('seniority', 'Seniority', $seniorities, $filters['seniority']); ?>
    </div>
    <div class="col-md-2">
      <?php render_multiselect_filter('departments', 'Department', $departmentOptions, $filters['departments']); ?>
    </div>
    <div class="col-md-2">
      <?php render_multiselect_filter('industry', 'Industry', $industries, $filters['industry']); ?>
    </div>
    <div class="col-md-2">
      <?php render_multiselect_filter('country', 'Country', $countries, $filters['country']); ?>
    </div>
    <div class="col-md-2">
      <?php render_multiselect_filter('company_country', 'Company Country', $companyCountries, $filters['company_country']); ?>
    </div>
    <div class="col-md-1">
      <?php render_multiselect_filter('employee_count', 'Size', $employeeCounts, $filters['employee_count']); ?>
    </div>
    <div class="col-md-2">
      <select name="vertical_id" class="form-select form-select-sm">
        <option value="">Vertical (all)</option>
        <?php foreach ($verticals as $v): ?>
          <option value="<?= (int) $v['id'] ?>" <?= (string) $filters['vertical_id'] === (string) $v['id'] ? 'selected' : '' ?>><?= e($v['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="service_id" class="form-select form-select-sm">
        <option value="">Service (all)</option>
        <?php foreach ($services as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (string) $filters['service_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small text-muted mb-0">
        Pending elsewhere
        <?= info_icon('Checks every campaign a same-company contact is in -- including this one. "Blocked" leads have a different contact at their company still awaiting a delivered/bounced outcome anywhere, so they\'d be held for wave-1 safety if added now.') ?>
      </label>
      <select name="pending_elsewhere" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="0" <?= $filters['pending_elsewhere'] === '0' ? 'selected' : '' ?>>No (hide blocked leads)</option>
        <option value="1" <?= $filters['pending_elsewhere'] === '1' ? 'selected' : '' ?>>Yes (show only blocked leads)</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small text-muted mb-0">
        Account used elsewhere
        <?= info_icon('Checks every campaign a same-company contact is in -- including this one, and any outcome (not just pending). Use "Yes" to find backup contacts at companies you\'re already engaging anywhere; "No" for genuinely untouched companies.') ?>
      </label>
      <select name="account_used_elsewhere" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="1" <?= $filters['account_used_elsewhere'] === '1' ? 'selected' : '' ?>>Yes (backup contacts at engaged accounts)</option>
        <option value="0" <?= $filters['account_used_elsewhere'] === '0' ? 'selected' : '' ?>>No (accounts never touched)</option>
      </select>
    </div>
    <div class="col-md-4 form-check d-flex align-items-center">
      <input class="form-check-input me-2" type="checkbox" name="hide_used_in_campaign" value="1" id="hideUsed" <?= $filters['hide_used_in_campaign'] ? 'checked' : '' ?>>
      <label class="form-check-label" for="hideUsed">Only leads not already in this campaign</label>
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-primary btn-sm w-100">Update preview</button>
    </div>
  </div>
</form>

<?php if (!$hasRealFilter): ?>
<div class="alert alert-warning">
  Apply at least one filter above (company, domain, title, industry, country, etc.) to preview and select leads --
  this page won't scan the whole leads table unfiltered, since "every lead in the system" is almost never the
  actual candidate pool for one campaign.
</div>
<?php else: ?>
<div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
  <span><strong><?= number_format($leadCount) ?></strong> lead(s) across <strong><?= number_format($domainCount) ?></strong> compan(y/ies) match this filter.</span>
  <form method="post" action="lead_bulk_tag.php" class="d-flex gap-1"
        onsubmit="return confirm('Add this tag to all <?= (int) $leadCount ?> leads matching the current filter?');">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="campaign_select_leads.php?<?= http_build_query(array_merge($filters, ['campaign_id' => $campaignId])) ?>">
    <?php render_hidden_filter_fields($filters, 'filter'); ?>
    <input type="text" name="tag_name" class="form-control form-control-sm" list="existingTagNames" placeholder="Tag name" required style="max-width: 160px;">
    <button type="submit" class="btn btn-sm btn-outline-secondary">Tag all <?= (int) $leadCount ?> matching leads</button>
  </form>
</div>

<?php
// Built by hand rather than a blind array_filter(): hide_used_in_campaign
// must always be included explicitly as '1'/'0' -- omitting it when false
// would silently flip back to the default (true) on the next page, since
// the GET reader treats an *absent* param as "checked".
$previewFilterQuery = [];
foreach ($filters as $k => $v) {
    if ($k === 'campaign_id') {
        continue;
    }
    if ($k === 'hide_used_in_campaign') {
        $previewFilterQuery[$k] = $v ? '1' : '0';
        continue;
    }
    if ($v === '' || $v === []) {
        continue;
    }
    $previewFilterQuery[$k] = $v;
}
$previewFilterQuery['campaign_id'] = $campaignId;
?>
<div class="table-responsive card mb-4">
  <table class="table table-sm table-hover mb-0 align-middle">
    <thead>
      <tr><th>Company</th><th>Contact</th><th>Email</th><th>Title</th><th>Seniority</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php foreach ($preview['rows'] as $lead): ?>
        <tr>
          <td><?= e($lead['na_company_name']) ?></td>
          <td><?= e($lead['first_name'] . ' ' . $lead['last_name']) ?></td>
          <td class="small"><?= e($lead['email']) ?></td>
          <td><?= e($lead['title']) ?></td>
          <td><?= e($lead['seniority']) ?></td>
          <td>
            <?php if ($lead['used_in_campaigns']): ?>
              <span class="badge badge-used" title="<?= e($lead['used_in_campaigns']) ?>">Used</span>
            <?php endif; ?>
            <?php if ($lead['suppressed_reason'] !== null): ?>
              <span class="badge bg-danger" title="<?= e($lead['suppressed_reason']) ?>">Suppressed</span>
            <?php endif; ?>
            <?php if ($lead['pending_elsewhere_campaigns']): ?>
              <span class="badge bg-warning text-dark" title="Another persona at this account is pending delivery in: <?= e($lead['pending_elsewhere_campaigns']) ?> (campaign name(s) listed here may include this campaign itself, not just other ones).">Pending elsewhere</span>
            <?php elseif ($lead['account_used_elsewhere_campaigns']): ?>
              <span class="badge bg-info text-dark" title="Another persona at this account is already in: <?= e($lead['account_used_elsewhere_campaigns']) ?> (campaign name(s) listed here may include this campaign itself, not just other ones).">Account used elsewhere</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$preview['rows']): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No leads match this filter.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($preview['totalPages'] > 1): ?>
<nav class="mb-4">
  <ul class="pagination">
    <?php for ($p = 1; $p <= $preview['totalPages']; $p++): $q = $previewFilterQuery; $q['page'] = $p; ?>
      <li class="page-item <?= $p === $preview['page'] ? 'active' : '' ?>">
        <a class="page-link" href="campaign_select_leads.php?<?= http_build_query($q) ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>
<?php endif; // $hasRealFilter ?>

<form method="post" action="campaign_select_leads.php">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="select">
  <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
  <?php render_hidden_filter_fields($filters, 'filter'); ?>

  <div class="card mb-3">
    <div class="card-header">1 contact per company (wave 1) -- auto by title priority</div>
    <div class="card-body">
      <div class="form-check">
        <input class="form-check-input" type="radio" name="select_mode" value="wave_auto" id="modeAuto" checked>
        <label class="form-check-label" for="modeAuto">Type a title priority list</label>
      </div>
      <input type="text" name="title_priority" class="form-control form-control-sm mt-2" style="max-width: 420px;"
             placeholder="e.g. VP Engineering, CTO, Director of Engineering">
      <div class="form-text">First matching title per company wins; falls back to seniority (C-Level &gt; VP &gt; Director &gt; Manager) if nothing matches.</div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">1 contact per company (wave 1) -- manual title checklist</div>
    <div class="card-body">
      <div class="form-check mb-2">
        <input class="form-check-input" type="radio" name="select_mode" value="wave_manual" id="modeManual">
        <label class="form-check-label" for="modeManual">Check off which titles count as the target persona</label>
      </div>
      <div class="row">
        <?php foreach ($titles as $t): ?>
          <div class="col-md-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="allowed_titles[]" value="<?= e($t['title']) ?>" id="title<?= (int) $t['lead_count'] . '_' . md5($t['title']) ?>">
              <label class="form-check-label small" for="title<?= (int) $t['lead_count'] . '_' . md5($t['title']) ?>"><?= e($t['title']) ?> <span class="text-muted">(<?= (int) $t['lead_count'] ?>)</span></label>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$titles): ?>
          <p class="text-muted small mb-0">No titles found for the current filter.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header">All matching leads -- no per-company limit</div>
    <div class="card-body">
      <div class="form-check">
        <input class="form-check-input" type="radio" name="select_mode" value="all" id="modeAll">
        <label class="form-check-label" for="modeAll">Assign every matching lead (skip the wave-1 safety mechanic)</label>
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary" onclick="return confirm('Add these leads to &quot;<?= e($campaign['name']) ?>&quot;?');">Save selection</button>
  <a href="campaign_leads.php?campaign_id=<?= (int) $campaignId ?>" class="btn btn-outline-secondary">Cancel</a>
</form>
<?php render_footer(); ?>
