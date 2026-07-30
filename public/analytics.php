<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/AnalyticsRepository.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$filters = [
    'campaign_id' => trim((string) ($_GET['campaign_id'] ?? '')),
    'vertical_id' => trim((string) ($_GET['vertical_id'] ?? '')),
    'service_id' => trim((string) ($_GET['service_id'] ?? '')),
    'industry' => trim((string) ($_GET['industry'] ?? '')),
    'created_from' => trim((string) ($_GET['created_from'] ?? '')),
    'created_to' => trim((string) ($_GET['created_to'] ?? '')),
    'email_sent_from' => trim((string) ($_GET['email_sent_from'] ?? '')),
    'email_sent_to' => trim((string) ($_GET['email_sent_to'] ?? '')),
];

$campaignClauses = ['c.company_id = :scope_company_id'];
$campaignParams = ['scope_company_id' => $scope->companyId];
ScopeFilter::applyOwnerScope($campaignClauses, $campaignParams, $scope, db(), 'c', 'saleshandy_account_owner_id');
$campaignsStmt = db()->prepare('SELECT c.id, c.name FROM campaigns c WHERE ' . implode(' AND ', $campaignClauses) . ' ORDER BY c.name');
$campaignsStmt->execute($campaignParams);
$campaigns = $campaignsStmt->fetchAll();
$verticals = LeadRepository::activeLookupOptions(db(), $scope, 'verticals');
$services = LeadRepository::activeLookupOptions(db(), $scope, 'services');
$industries = LeadRepository::distinctValues(db(), $scope, 'industry');

$campaignFunnel = AnalyticsRepository::campaignFunnel(db(), $scope);
$funnelMaxStep = 0;
foreach ($campaignFunnel as $cf) {
    if ($cf['steps']) {
        $funnelMaxStep = max($funnelMaxStep, max(array_keys($cf['steps'])));
    }
}

// All four breakdowns shown together, same filters applied to each --
// no "group by" dropdown to click through, so country/vertical/service/
// campaign are all visible on one screen at once.
$sections = [];
foreach (AnalyticsRepository::GROUP_DIMENSIONS as $key => $label) {
    $sections[$key] = ['label' => $label, 'pivot' => AnalyticsRepository::pivotByDimension(db(), $scope, $key, $filters)];
}

$filterQuery = $_GET;

// All four sections share identical Grand Totals by construction (same
// filtered dataset, just grouped differently) -- reuse one for the two
// top summary charts instead of computing anything new.
$overallTotal = $sections['company_country']['pivot']['total'];

// Every number in every breakdown table links to the Dashboard, pre-
// filtered to exactly the leads that number counts -- so "12 imported in
// Germany" is a click away from the actual list, not just a count.
// Resolving a row's *label* (all AnalyticsRepository::pivotByDimension()
// gives us) back to an id for the vertical_id/service_id/
// assigned_campaign_id filters LeadRepository actually needs.
$campaignIdByName = array_column($campaigns, 'id', 'name');
$verticalIdByLabel = array_column($verticals, 'id', 'label');
$serviceIdByLabel = array_column($services, 'id', 'label');

/**
 * @param string $sectionKey one of AnalyticsRepository::GROUP_DIMENSIONS' keys
 * @param ?string $grp a row's group label, or null for the Grand Total row
 *   (no dimension-specific filter -- just this section's other active filters)
 * @param ?string $metricKey 'imported' or 'email_sent', or null for the
 *   Prospects column (no extra metric filter)
 */
$drillLink = function (string $sectionKey, ?string $grp, ?string $metricKey, ?string $metricValue) use ($filters, $campaignIdByName, $verticalIdByLabel, $serviceIdByLabel): string {
    // Analytics counts every non-deleted lead, suppressed domains
    // included -- Dashboard's own default filter *hides* suppressed
    // leads, so without this override a drill-through link would show
    // fewer leads than the number just clicked.
    $params = ['show_suppressed' => '1'];

    // Carry over Analytics' own active filters first...
    if ($filters['campaign_id'] !== '') {
        $params['assigned_campaign_id'] = $filters['campaign_id'];
    }
    if ($filters['vertical_id'] !== '') {
        $params['vertical_id'] = $filters['vertical_id'];
    }
    if ($filters['service_id'] !== '') {
        $params['service_id'] = $filters['service_id'];
    }
    if ($filters['industry'] !== '') {
        $params['industry'] = [$filters['industry']];
    }

    // ...then overlay this row's own dimension, which always wins since
    // it's what actually produced this specific number.
    if ($grp !== null) {
        switch ($sectionKey) {
            case 'company_country':
                $params['company_country'] = [$grp];
                break;
            case 'campaign':
                $params['assigned_campaign_id'] = $campaignIdByName[$grp] ?? 'none';
                break;
            case 'vertical':
                $params['vertical_id'] = $verticalIdByLabel[$grp] ?? 'none';
                break;
            case 'service':
                $params['service_id'] = $serviceIdByLabel[$grp] ?? 'none';
                break;
        }
    }

    if ($metricKey !== null) {
        $params[$metricKey] = $metricValue;
    }

    // A Grand Total "Prospects" link with no other Analytics filters
    // active ends up as just show_suppressed=1 -- not itself "real" by
    // Dashboard's own gate, so it lands on the "apply a filter" prompt
    // rather than an unfiltered dump. That's by design: "every lead in
    // the system" is exactly the load Dashboard's gate exists to avoid.
    return 'dashboard.php?' . http_build_query($params);
};

render_header('Analytics');
?>
<h1 class="h4 mb-3">Analytics</h1>

<div class="card mb-4">
  <div class="card-header">
    Campaign outreach funnel
    <?= info_icon('Prospects: everyone assigned to this campaign. Contacted: had at least the first email sent. Step N sent: reached that step or later (cumulative -- since sequence steps fire in order, "reached step 3" means steps 1 and 2 went out too). Replies: currently marked Replied. All local-DB counts, not a live Saleshandy call.') ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th>S No</th>
          <th>Campaign</th>
          <th>Vertical</th>
          <th>Service pitched</th>
          <th class="text-end">Prospects</th>
          <th class="text-end">Contacted</th>
          <?php for ($n = 1; $n <= $funnelMaxStep; $n++): ?>
            <th class="text-end">Step <?= $n ?> sent</th>
          <?php endfor; ?>
          <th class="text-end">Replies</th>
          <th>First email date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($campaignFunnel as $i => $c): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= e($c['name']) ?></td>
            <td><?= e($c['vertical_label'] ?? '') ?></td>
            <td><?= e($c['service_label'] ?? '') ?></td>
            <td class="text-end"><?= number_format((int) $c['prospects']) ?></td>
            <td class="text-end"><?= number_format((int) $c['contacted']) ?></td>
            <?php for ($n = 1; $n <= $funnelMaxStep; $n++): ?>
              <td class="text-end"><?= isset($c['steps'][$n]) ? number_format($c['steps'][$n]) : '' ?></td>
            <?php endfor; ?>
            <td class="text-end"><?= number_format((int) $c['replies']) ?></td>
            <td><?= $c['first_email_date'] ? e(date('l, F j, Y', strtotime($c['first_email_date']))) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$campaignFunnel): ?>
          <tr><td colspan="<?= 8 + $funnelMaxStep ?>" class="text-center text-muted py-3">No campaigns yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($overallTotal['prospects'] > 0): ?>
<div class="row mb-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">Imported vs. not imported</div>
      <div class="card-body" style="max-height: 260px;">
        <canvas id="chartOverallImported"></canvas>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">Email sent vs. not sent</div>
      <div class="card-body" style="max-height: 260px;">
        <canvas id="chartOverallEmail"></canvas>
      </div>
    </div>
  </div>
</div>
<script type="application/json" id="chartdata-overall"><?= json_encode($overallTotal, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>

<form method="get" action="analytics.php" class="card filter-card mb-4">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small mb-0">Campaign</label>
      <select name="campaign_id" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach ($campaigns as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (string) $filters['campaign_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-0">Vertical</label>
      <select name="vertical_id" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach ($verticals as $v): ?>
          <option value="<?= (int) $v['id'] ?>" <?= (string) $filters['vertical_id'] === (string) $v['id'] ? 'selected' : '' ?>><?= e($v['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-0">Service</label>
      <select name="service_id" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach ($services as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (string) $filters['service_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-0">Industry</label>
      <select name="industry" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach ($industries as $ind): ?>
          <option value="<?= e($ind) ?>" <?= $filters['industry'] === $ind ? 'selected' : '' ?>><?= e($ind) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label small mb-0">Lead imported between</label>
      <div class="d-flex gap-1">
        <input type="date" name="created_from" class="form-control form-control-sm" value="<?= e($filters['created_from']) ?>">
        <input type="date" name="created_to" class="form-control form-control-sm" value="<?= e($filters['created_to']) ?>">
      </div>
    </div>
    <div class="col-md-4">
      <label class="form-label small mb-0">Email sent between</label>
      <div class="d-flex gap-1">
        <input type="date" name="email_sent_from" class="form-control form-control-sm" value="<?= e($filters['email_sent_from']) ?>">
        <input type="date" name="email_sent_to" class="form-control form-control-sm" value="<?= e($filters['email_sent_to']) ?>">
      </div>
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
    </div>
    <?php if (array_filter($filterQuery)): ?>
      <div class="col-md-2">
        <a href="analytics.php" class="btn btn-outline-secondary btn-sm w-100">Clear filters</a>
      </div>
    <?php endif; ?>
  </div>
</form>

<?php foreach ($sections as $key => $section): $pivot = $section['pivot']; ?>
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>By <?= e($section['label']) ?></span>
      <ul class="nav nav-pills nav-sm" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active py-0 px-2" data-bs-toggle="tab" data-bs-target="#table-<?= e($key) ?>" type="button" role="tab">Table</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link py-0 px-2" data-bs-toggle="tab" data-bs-target="#chart-<?= e($key) ?>" type="button" role="tab" data-chart-key="<?= e($key) ?>">Chart</button>
        </li>
      </ul>
    </div>
    <div class="tab-content">
      <div class="tab-pane fade show active" id="table-<?= e($key) ?>" role="tabpanel">
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead>
              <tr>
                <th><?= e($section['label']) ?></th>
                <th class="text-end">Prospects</th>
                <th class="text-end">Imported to Saleshandy</th>
                <th class="text-end">Not imported</th>
                <th class="text-end">Email sent</th>
                <th class="text-end">Email not sent</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pivot['rows'] as $row): ?>
                <tr>
                  <td><?= e($row['grp']) ?></td>
                  <td class="text-end"><a href="<?= e($drillLink($key, $row['grp'], null, null)) ?>"><?= number_format((int) $row['prospects']) ?></a></td>
                  <td class="text-end"><a href="<?= e($drillLink($key, $row['grp'], 'imported', '1')) ?>"><?= number_format((int) $row['imported']) ?></a></td>
                  <td class="text-end"><a href="<?= e($drillLink($key, $row['grp'], 'imported', '0')) ?>"><?= number_format((int) $row['not_imported']) ?></a></td>
                  <td class="text-end"><a href="<?= e($drillLink($key, $row['grp'], 'email_sent', '1')) ?>"><?= number_format((int) $row['email_sent']) ?></a></td>
                  <td class="text-end"><a href="<?= e($drillLink($key, $row['grp'], 'email_sent', '0')) ?>"><?= number_format((int) $row['email_not_sent']) ?></a></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$pivot['rows']): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No leads match this filter.</td></tr>
              <?php endif; ?>
            </tbody>
            <?php if ($pivot['rows']): ?>
            <tfoot>
              <tr class="fw-bold table-light">
                <td>Grand Total</td>
                <td class="text-end"><a href="<?= e($drillLink($key, null, null, null)) ?>"><?= number_format($pivot['total']['prospects']) ?></a></td>
                <td class="text-end"><a href="<?= e($drillLink($key, null, 'imported', '1')) ?>"><?= number_format($pivot['total']['imported']) ?></a></td>
                <td class="text-end"><a href="<?= e($drillLink($key, null, 'imported', '0')) ?>"><?= number_format($pivot['total']['not_imported']) ?></a></td>
                <td class="text-end"><a href="<?= e($drillLink($key, null, 'email_sent', '1')) ?>"><?= number_format($pivot['total']['email_sent']) ?></a></td>
                <td class="text-end"><a href="<?= e($drillLink($key, null, 'email_sent', '0')) ?>"><?= number_format($pivot['total']['email_not_sent']) ?></a></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>
      <div class="tab-pane fade" id="chart-<?= e($key) ?>" role="tabpanel">
        <?php if ($pivot['rows']): ?>
          <div class="p-3" style="height: <?= max(220, count($pivot['rows']) * 32) ?>px;">
            <canvas id="canvas-<?= e($key) ?>"></canvas>
          </div>
          <script type="application/json" id="chartdata-<?= e($key) ?>"><?= json_encode($pivot['rows'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <?php else: ?>
          <p class="text-center text-muted py-3 mb-0">No leads match this filter.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="assets/js/analytics_charts.js"></script>
<?php render_footer(); ?>
