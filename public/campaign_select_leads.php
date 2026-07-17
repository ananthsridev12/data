<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';

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
        'employee_count' => (array) ($rawFilters['employee_count'] ?? []),
        'vertical_id' => $rawFilters['vertical_id'] ?? '',
        'service_id' => $rawFilters['service_id'] ?? '',
        'campaign_id' => $campaignId,
        'hide_used_in_campaign' => !empty($rawFilters['hide_used_in_campaign']),
    ];
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
    'employee_count' => $multiParam('employee_count'),
    'vertical_id' => trim((string) ($_GET['vertical_id'] ?? '')),
    'service_id' => trim((string) ($_GET['service_id'] ?? '')),
    'campaign_id' => $campaignId,
    'hide_used_in_campaign' => !isset($_GET['hide_used_in_campaign']) || $_GET['hide_used_in_campaign'] === '1',
];

$leadCount = count(LeadRepository::matchingIds(db(), $filters));
$domainCount = LeadRepository::domainCountForFilter(db(), $filters);
$titles = LeadRepository::distinctTitlesForFilter(db(), $filters);

$titleOptions = LeadRepository::distinctValues(db(), 'title', 1000);
$seniorities = LeadRepository::distinctValues(db(), 'seniority');
$departmentOptions = LeadRepository::distinctValues(db(), 'departments');
$industries = LeadRepository::distinctValues(db(), 'industry');
$countries = LeadRepository::distinctValues(db(), 'country');
$employeeCounts = LeadRepository::distinctValues(db(), 'employee_count');
$verticals = LeadRepository::activeLookupOptions(db(), 'verticals');
$services = LeadRepository::activeLookupOptions(db(), 'services');

render_header('Select leads');
?>
<h1 class="h4 mb-1">Add leads to "<?= e($campaign['name']) ?>"</h1>
<p class="text-muted"><a href="campaign_leads.php?campaign_id=<?= (int) $campaignId ?>">&laquo; Back to this campaign</a></p>

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
    <div class="col-md-4 form-check d-flex align-items-center">
      <input class="form-check-input me-2" type="checkbox" name="hide_used_in_campaign" value="1" id="hideUsed" <?= $filters['hide_used_in_campaign'] ? 'checked' : '' ?>>
      <label class="form-check-label" for="hideUsed">Only leads not already in this campaign</label>
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-primary btn-sm w-100">Update preview</button>
    </div>
  </div>
</form>

<div class="alert alert-info">
  <strong><?= number_format($leadCount) ?></strong> lead(s) across <strong><?= number_format($domainCount) ?></strong> compan(y/ies) match this filter.
</div>

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
<script src="assets/js/app.js"></script>
<?php render_footer(); ?>
