<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/RoleGroupClassifier.php';
require_once __DIR__ . '/../app/includes/EmployeeCountRangeClassifier.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $data = [
            'name' => $name,
            'role_group_id' => (int) ($_POST['role_group_id'] ?? 0) ?: null,
            'vertical_id' => (int) ($_POST['vertical_id'] ?? 0) ?: null,
            'service_id' => (int) ($_POST['service_id'] ?? 0) ?: null,
            'country_group_id' => (int) ($_POST['country_group_id'] ?? 0) ?: null,
            'company_country' => implode(', ', (array) ($_POST['company_country'] ?? [])),
            'industry' => implode(', ', (array) ($_POST['industry'] ?? [])),
            'seniority' => implode(', ', (array) ($_POST['seniority'] ?? [])),
            'employee_count' => implode(', ', (array) ($_POST['employee_count'] ?? [])),
            'auto_push_enabled' => !empty($_POST['auto_push_enabled']),
            'require_sequence_completed' => !empty($_POST['require_sequence_completed']),
            'avoid_repeat_service' => !empty($_POST['avoid_repeat_service']),
            'exclude_previously_used' => !empty($_POST['exclude_previously_used']),
        ];

        $hasAnyCriterion = $data['role_group_id'] || $data['vertical_id'] || $data['service_id'] || $data['country_group_id']
            || $data['company_country'] !== '' || $data['industry'] !== '' || $data['seniority'] !== '' || $data['employee_count'] !== '';

        if ($name === '') {
            flash_set('danger', 'A name is required.');
        } elseif (!$hasAnyCriterion) {
            flash_set('danger', 'Pick at least one match criterion (Role Group, Vertical, Service, Country Group, or one of the other fields) -- an ICP with nothing set would match every lead in the database.');
        } else {
            try {
                $newId = IcpRepository::create(db(), $data, $user['id'], $scope->companyId);
                flash_set('success', "\"{$name}\" created -- now link 2 or more campaigns to it with a percentage split.");
                header('Location: icp_segment_detail.php?id=' . $newId);
                exit;
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'That name already exists.' : 'Could not save ICP segment.');
            }
        }
    } elseif ($action === 'toggle_active') {
        IcpRepository::toggleActive(db(), (int) ($_POST['id'] ?? 0), $scope);
        flash_set('success', 'Status updated.');
    }

    header('Location: icp_segments.php');
    exit;
}

$icps = IcpRepository::list(db(), $scope);
$roleGroups = LeadRepository::activeLookupOptions(db(), $scope, 'role_groups');
$verticals = LeadRepository::activeLookupOptions(db(), $scope, 'verticals');
$services = LeadRepository::activeLookupOptions(db(), $scope, 'services');
$countryGroups = LeadRepository::activeLookupOptions(db(), $scope, 'country_groups');

// Vertical/Service/Country Group filter for the list below -- narrows
// which ICPs are shown (and which sections get built, see $icpGroups
// below) without touching the "Personas without an ICP yet" card above,
// which is a company-wide gap report independent of this filter.
$filterVerticalId = trim((string) ($_GET['vertical_id'] ?? ''));
$filterServiceId = trim((string) ($_GET['service_id'] ?? ''));
$filterCountryGroupId = trim((string) ($_GET['country_group_id'] ?? ''));
if ($filterVerticalId !== '') {
    $icps = array_values(array_filter($icps, static fn (array $i): bool => (int) $i['vertical_id'] === (int) $filterVerticalId));
}
if ($filterServiceId !== '') {
    $icps = array_values(array_filter($icps, static fn (array $i): bool => (int) $i['service_id'] === (int) $filterServiceId));
}
if ($filterCountryGroupId !== '') {
    $icps = array_values(array_filter($icps, static fn (array $i): bool => (int) $i['country_group_id'] === (int) $filterCountryGroupId));
}

$companyCountries = LeadRepository::distinctValues(db(), $scope, 'company_country');
$industries = LeadRepository::distinctValues(db(), $scope, 'industry');
$seniorities = LeadRepository::distinctValues(db(), $scope, 'seniority');
$employeeCountRanges = EmployeeCountRangeClassifier::allLabels();

// Just the "N leads eligible now" count per ICP -- link/percentage data
// no longer needs a per-ICP query here (IcpRepository::links()) since
// campaign linking moved to icp_segment_detail.php; link_count and
// percentage_total for the badges below already come back on each row
// from IcpRepository::list() itself.
$matchingCountByIcp = [];
foreach ($icps as $icp) {
    // Same filters + cooldown-based assignability scoping the distribution
    // cron itself uses (IcpRepository::toFilters()) -- never-assigned
    // leads plus previously-assigned ones whose latest assignment is both
    // resolved and past the company's lead_cooldown_days -- so this is
    // exactly the pool the next cron run would pick up and split, not
    // "every lead that could ever match this ICP".
    $matchingCountByIcp[(int) $icp['id']] = LeadRepository::matchingCount(db(), $scope, IcpRepository::toFilters($icp, $scope));
}

// Active Role Groups (personas) with zero ICPs referencing them at all
// yet (not just zero *active* ones -- even an unfinished draft ICP still
// counts as "started"), most classified leads first -- so the gap most
// worth closing shows up on top instead of just a flat A-Z list.
$unmappedPersonasStmt = db()->prepare(
    "SELECT rg.id, rg.label,
        (SELECT COUNT(*) FROM leads l WHERE l.role_group_id = rg.id AND l.deleted_at IS NULL AND l.company_id = ?) AS lead_count
       FROM role_groups rg
      WHERE rg.is_active = 1 AND rg.company_id = ?
        AND NOT EXISTS (SELECT 1 FROM icp_segments icp WHERE icp.role_group_id = rg.id AND icp.company_id = ?)
      ORDER BY lead_count DESC, rg.label"
);
$unmappedPersonasStmt->execute([$scope->companyId, $scope->companyId, $scope->companyId]);
$unmappedPersonas = $unmappedPersonasStmt->fetchAll();

$activeIcpCount = count(array_filter($icps, static fn (array $i): bool => (bool) $i['is_active']));

// Sections the list below into "Country Group - Vertical - Service"
// groups (e.g. "AMERICAS - DT - CPQ"), using each lookup's short code
// (not its full label) so headers stay compact -- falls back to
// whichever of the three are set, or "Uncategorized" if an ICP has none
// (kept last, everything else alphabetical) -- an ICP with no Vertical/
// Service/Country Group is still valid (it can target purely by Role
// Group/country/size, see the match-criteria validation above), so it
// still needs a home in the list.
$icpGroups = [];
foreach ($icps as $icp) {
    $cg = $icp['country_group_code'] ?: $icp['country_group_label'];
    $v = $icp['vertical_code'] ?: $icp['vertical_label'];
    $s = $icp['service_code'] ?: $icp['service_label'];
    $parts = array_filter([$cg, $v && $s ? $v . ' - ' . $s : ($v ?: $s)]);
    $groupLabel = $parts ? implode(' - ', $parts) : 'Uncategorized';
    $icpGroups[$groupLabel][] = $icp;
}
$uncategorizedGroup = $icpGroups['Uncategorized'] ?? null;
unset($icpGroups['Uncategorized']);
ksort($icpGroups, SORT_NATURAL | SORT_FLAG_CASE);
if ($uncategorizedGroup !== null) {
    $icpGroups['Uncategorized'] = $uncategorizedGroup;
}

render_header('ICP Segments');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div style="max-width: 760px;">
    <h1 class="h4 mb-1">ICP Segments</h1>
    <p class="text-muted mb-0">
      Define a buyer persona once, link it to 2+ campaigns with a percentage split, and the distribution cron
      keeps feeding newly-matching leads into the right test automatically.
      <?= info_icon('Match criteria: company country, vertical, service, seniority, employee count, and optionally one Role Group. Role Group is optional -- leave it as "Any persona" to target purely by country/industry/size; at least one criterion is still required so an ICP can\'t accidentally match every lead in the database. Click into an ICP to manage its full details and campaign links.') ?>
    </p>
  </div>
  <div class="d-flex gap-2 flex-shrink-0">
    <span class="badge rounded-pill bg-success-subtle text-success-emphasis px-3 py-2"><?= $activeIcpCount ?> active</span>
    <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis px-3 py-2"><?= count($icps) ?> total</span>
  </div>
</div>

<div class="card icp-card mb-4">
  <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">Cron status &amp; manual sync moved</div>
      <div class="small text-muted">
        The 4 "Run now" cron buttons, plus individual sync/backfill/push per campaign and per ICP, now live on
        <a href="sync_center.php">Sync Center</a>.
      </div>
    </div>
    <a href="sync_center.php" class="btn btn-outline-primary btn-sm flex-shrink-0">Open Sync Center</a>
  </div>
</div>

<?php if ($unmappedPersonas): ?>
<div class="card icp-card mb-4">
  <div class="card-header bg-warning-subtle d-flex align-items-center gap-2">
    <span class="fw-semibold">Personas without an ICP yet</span>
    <?= info_icon('Active Role Groups that have no ICP built for them at all, most classified leads first. These personas are being recognized on import but nothing is targeting them via a campaign split -- click "Create ICP" to jump to the Add form with this persona pre-selected.') ?>
  </div>
  <div class="list-group list-group-flush" style="max-height: 320px; overflow-y: auto;">
    <?php foreach ($unmappedPersonas as $p): ?>
      <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
        <div>
          <div class="fw-semibold"><?= e($p['label']) ?></div>
          <div class="small text-muted"><?= number_format($p['lead_count']) ?> lead(s) classified</div>
        </div>
        <a href="#addIcpForm" class="btn btn-sm btn-outline-warning rounded-pill px-3 flex-shrink-0" onclick="document.getElementById('addIcpRoleGroup').value='<?= (int) $p['id'] ?>'; document.getElementById('addIcpName').focus();">Create ICP</a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card icp-card mb-4" id="addIcpForm">
  <div class="card-header fw-semibold">New ICP segment</div>
  <div class="card-body">
    <form method="post" action="icp_segments.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div class="row g-3 align-items-end mb-4">
        <div class="col-md-4">
          <label class="form-label small text-muted mb-1">Name</label>
          <input type="text" name="name" id="addIcpName" class="form-control" placeholder="e.g. Healthcare CFOs" required>
        </div>
        <div class="col-md-4">
          <label class="form-label small text-muted mb-1">Role Group (persona)</label>
          <select name="role_group_id" id="addIcpRoleGroup" class="form-select">
            <option value="">Any persona</option>
            <?php foreach ($roleGroups as $rg): ?>
              <option value="<?= (int) $rg['id'] ?>"><?= e($rg['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 form-check form-switch pt-2 ps-5">
          <input class="form-check-input" type="checkbox" role="switch" name="auto_push_enabled" value="1" id="autoPushNew">
          <label class="form-check-label small" for="autoPushNew">Auto-push to Saleshandy after assignment</label>
        </div>
        <div class="col-md-2 form-check form-switch pt-2 ps-5">
          <input class="form-check-input" type="checkbox" role="switch" name="require_sequence_completed" value="1" id="requireSeqCompletedNew">
          <label class="form-check-label small" for="requireSeqCompletedNew">Only reassign leads whose prior sequence fully completed <?= info_icon('When re-matching a previously-assigned lead (after cooldown), also require that its last campaign\'s sequence actually finished -- current step reached the sequence\'s real total, with no reply -- not just that the assignment is resolved and past cooldown. Off by default (broader reassignment); turn this on for an ICP meant specifically to catch "finished with silence" leads for a follow-up push.') ?></label>
        </div>
        <div class="col-md-2 form-check form-switch pt-2 ps-5">
          <input class="form-check-input" type="checkbox" role="switch" name="avoid_repeat_service" value="1" id="avoidRepeatServiceNew">
          <label class="form-check-label small" for="avoidRepeatServiceNew">Don't re-pitch the same service <?= info_icon('When re-matching a previously-assigned lead, skip any of this ICP\'s linked campaigns that pitch the same Service as a campaign that lead has already been through -- even if resolved and past cooldown. Prevents an ICP that links several same-service sequence variants (e.g. two different sequences pitching the same product) from re-sending a lead into another same-service campaign. Off by default; a brand-new lead with no prior campaign history is never affected.') ?></label>
        </div>
        <div class="col-md-2 form-check form-switch pt-2 ps-5">
          <input class="form-check-input" type="checkbox" role="switch" name="exclude_previously_used" value="1" id="excludePreviouslyUsedNew">
          <label class="form-check-label small" for="excludePreviouslyUsedNew">Only match leads never used in any campaign <?= info_icon('Switches off reassignment entirely for this ICP -- only a lead with NO assignment history at all qualifies, no matter how cleanly or how long ago any prior campaign resolved. Stronger than "Sequence completed only" and "Don\'t re-pitch the same service", which just narrow WHICH previously-assigned leads re-qualify -- this excludes them all. Off by default (broader reassignment, today\'s baseline).') ?></label>
        </div>
      </div>

      <div class="icp-section-label mb-2">Match criteria <span class="fw-normal text-muted" style="text-transform: none; letter-spacing: normal;">(at least one required)</span></div>
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Vertical</label>
          <select name="vertical_id" class="form-select">
            <option value="">Any</option>
            <?php foreach ($verticals as $v): ?>
              <option value="<?= (int) $v['id'] ?>"><?= e($v['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Service</label>
          <select name="service_id" class="form-select">
            <option value="">Any</option>
            <?php foreach ($services as $s): ?>
              <option value="<?= (int) $s['id'] ?>"><?= e($s['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Country Group</label>
          <select name="country_group_id" class="form-select">
            <option value="">Any</option>
            <?php foreach ($countryGroups as $cg): ?>
              <option value="<?= (int) $cg['id'] ?>"><?= e($cg['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Company Country</label>
          <?php render_multiselect_filter('company_country', 'Company Country', $companyCountries, []); ?>
        </div>
      </div>
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label small text-muted mb-1">Industry</label>
          <?php render_multiselect_filter('industry', 'Industry', $industries, []); ?>
        </div>
        <div class="col-md-4">
          <label class="form-label small text-muted mb-1">Seniority</label>
          <?php render_multiselect_filter('seniority', 'Seniority', $seniorities, []); ?>
        </div>
        <div class="col-md-4">
          <label class="form-label small text-muted mb-1">Employee Count</label>
          <?php render_multiselect_filter('employee_count', 'Employee Count', $employeeCountRanges, []); ?>
        </div>
      </div>

      <button type="submit" class="btn btn-primary px-4">Create ICP</button>
    </form>
  </div>
</div>

<div class="card icp-card mb-4">
  <div class="card-body">
    <form method="get" action="icp_segments.php" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Vertical</label>
        <select name="vertical_id" class="form-select form-select-sm">
          <option value="">Vertical (all)</option>
          <?php foreach ($verticals as $v): ?>
            <option value="<?= (int) $v['id'] ?>" <?= $filterVerticalId === (string) $v['id'] ? 'selected' : '' ?>><?= e($v['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Service</label>
        <select name="service_id" class="form-select form-select-sm">
          <option value="">Service (all)</option>
          <?php foreach ($services as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $filterServiceId === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Country Group</label>
        <select name="country_group_id" class="form-select form-select-sm">
          <option value="">Country group (all)</option>
          <?php foreach ($countryGroups as $cg): ?>
            <option value="<?= (int) $cg['id'] ?>" <?= $filterCountryGroupId === (string) $cg['id'] ? 'selected' : '' ?>><?= e($cg['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-auto">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      </div>
      <?php if ($filterVerticalId !== '' || $filterServiceId !== '' || $filterCountryGroupId !== ''): ?>
      <div class="col-md-auto">
        <a href="icp_segments.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($icps): ?>
<div class="card icp-card mb-4">
  <div class="card-header fw-semibold">Bulk actions</div>
  <div class="card-body">
    <p class="text-muted small mb-2">
      Check ICP segments below (or use "Select all shown"), then apply.
      <?= info_icon('An ICP you don\'t fully own (Team Lead/Member only) is silently skipped rather than blocking the rest of the batch. "Distribute now" runs the same assignment (and auto-push, if that ICP has it on) as each ICP\'s own "Sync/Push now" button, one ICP at a time.') ?>
    </p>
    <form method="post" action="icp_bulk_action.php" id="icpBulkForm">
      <?= csrf_field() ?>
      <div class="form-check mb-2">
        <input type="checkbox" class="form-check-input" id="icpSelectAll">
        <label class="form-check-label small" for="icpSelectAll">Select all shown</label>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="small text-muted" style="min-width: 190px;">Auto-push to Saleshandy</span>
        <button type="submit" name="action" value="bulk_auto_push_on" class="btn btn-outline-primary btn-sm">Turn ON</button>
        <button type="submit" name="action" value="bulk_auto_push_off" class="btn btn-outline-secondary btn-sm">Turn OFF</button>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="small text-muted" style="min-width: 190px;">Only reassign leads whose prior sequence fully completed <?= info_icon('When re-matching a previously-assigned lead (after cooldown), also require that its last campaign\'s sequence actually finished -- current step reached the sequence\'s real total, with no reply -- not just that the assignment is resolved and past cooldown. Off by default (broader reassignment); turn this on for an ICP meant specifically to catch "finished with silence" leads for a follow-up push.') ?></span>
        <button type="submit" name="action" value="bulk_require_sequence_completed_on" class="btn btn-outline-primary btn-sm">Turn ON</button>
        <button type="submit" name="action" value="bulk_require_sequence_completed_off" class="btn btn-outline-secondary btn-sm">Turn OFF</button>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="small text-muted" style="min-width: 190px;">Don't re-pitch the same service <?= info_icon('When re-matching a previously-assigned lead, skip any of this ICP\'s linked campaigns that pitch the same Service as a campaign that lead has already been through -- even if resolved and past cooldown. Prevents an ICP that links several same-service sequence variants (e.g. two different sequences pitching the same product) from re-sending a lead into another same-service campaign. Off by default; a brand-new lead with no prior campaign history is never affected.') ?></span>
        <button type="submit" name="action" value="bulk_avoid_repeat_service_on" class="btn btn-outline-primary btn-sm">Turn ON</button>
        <button type="submit" name="action" value="bulk_avoid_repeat_service_off" class="btn btn-outline-secondary btn-sm">Turn OFF</button>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <span class="small text-muted" style="min-width: 190px;">Only match leads never used in any campaign <?= info_icon('Switches off reassignment entirely for this ICP -- only a lead with NO assignment history at all qualifies, no matter how cleanly or how long ago any prior campaign resolved. Stronger than "Sequence completed only" and "Don\'t re-pitch the same service", which just narrow WHICH previously-assigned leads re-qualify -- this excludes them all. Off by default (broader reassignment, today\'s baseline).') ?></span>
        <button type="submit" name="action" value="bulk_exclude_previously_used_on" class="btn btn-outline-primary btn-sm">Turn ON</button>
        <button type="submit" name="action" value="bulk_exclude_previously_used_off" class="btn btn-outline-secondary btn-sm">Turn OFF</button>
      </div>
      <button type="submit" name="action" value="bulk_distribute" class="btn btn-primary btn-sm">Distribute now</button>
    </form>
  </div>
</div>
<script>
document.getElementById('icpSelectAll').addEventListener('change', function () {
  document.querySelectorAll('.icp-bulk-checkbox').forEach(function (cb) { cb.checked = this.checked; }, this);
});
</script>
<?php endif; ?>

<?php foreach ($icpGroups as $groupLabel => $groupIcps): ?>
<h2 class="h6 text-uppercase text-muted mb-2 mt-4"><?= e($groupLabel) ?> <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis"><?= count($groupIcps) ?></span></h2>
<div class="list-group mb-4">
<?php foreach ($groupIcps as $icp):
    $total = (int) $icp['percentage_total'];
    $ready = $total === 100;
?>
  <div class="list-group-item icp-card d-flex flex-wrap justify-content-between align-items-center gap-3 py-3">
    <div class="flex-shrink-0 pt-1">
      <input type="checkbox" name="icp_ids[]" value="<?= (int) $icp['id'] ?>" class="icp-bulk-checkbox form-check-input" form="icpBulkForm">
    </div>
    <div class="flex-grow-1" style="min-width: 260px;">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <a href="icp_segment_detail.php?id=<?= (int) $icp['id'] ?>" class="fw-semibold text-decoration-none"><?= e($icp['name']) ?></a>
        <span class="badge rounded-pill bg-<?= $icp['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $icp['is_active'] ? 'success' : 'secondary' ?>-emphasis"><?= $icp['is_active'] ? 'Active' : 'Inactive' ?></span>
        <?php if ($icp['is_active']): ?>
          <span class="badge rounded-pill bg-<?= $ready ? 'success' : 'warning' ?>-subtle text-<?= $ready ? 'success' : 'warning' ?>-emphasis"><?= $ready ? 'Ready &middot; ' . $total . '%' : 'Not running &middot; ' . $total . '%' ?></span>
        <?php endif; ?>
        <?php if ($icp['auto_push_enabled']): ?><span class="icp-chip icp-chip-accent"><span class="icp-chip-label">Saleshandy</span>Auto-push on</span><?php endif; ?>
        <?php if ($icp['require_sequence_completed']): ?><span class="icp-chip icp-chip-accent"><span class="icp-chip-label">Reassign</span>Sequence completed only</span><?php endif; ?>
        <?php if ($icp['avoid_repeat_service']): ?><span class="icp-chip icp-chip-accent"><span class="icp-chip-label">Reassign</span>No same-service repeats</span><?php endif; ?>
        <?php if ($icp['exclude_previously_used']): ?><span class="icp-chip icp-chip-accent"><span class="icp-chip-label">Reassign</span>Never-used leads only</span><?php endif; ?>
      </div>
      <div class="small text-muted">
        <?= e($icp['role_group_label'] ?: 'Any persona') ?>
        &middot; <?= (int) $icp['link_count'] ?> campaign(s) linked
        &middot; <?= number_format($matchingCountByIcp[(int) $icp['id']]) ?> lead(s) eligible now
        <?php if ($matchingCountByIcp[(int) $icp['id']] > 0): ?>
          (<a href="dashboard.php?<?= e(http_build_query(IcpRepository::toDashboardQueryParams($icp, $scope))) ?>">view</a>)
        <?php endif; ?>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 flex-shrink-0">
      <form method="post" action="icp_distribution_run_one.php" class="d-inline" title="Assign eligible leads and, if auto-push is on, push them to Saleshandy now, for just this ICP.">
        <?= csrf_field() ?>
        <input type="hidden" name="icp_id" value="<?= (int) $icp['id'] ?>">
        <input type="hidden" name="redirect_to" value="icp_segments">
        <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3" <?= $ready ? '' : 'disabled' ?>>Sync/Push now</button>
      </form>
      <form method="post" action="icp_segments.php" class="d-inline" onsubmit="return confirm('Toggle active status for <?= e($icp['name']) ?>?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle_active">
        <input type="hidden" name="id" value="<?= (int) $icp['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><?= $icp['is_active'] ? 'Deactivate' : 'Activate' ?></button>
      </form>
      <a href="icp_segment_detail.php?id=<?= (int) $icp['id'] ?>" class="btn btn-sm btn-primary rounded-pill px-3">Manage</a>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
<!-- /.icpGroups -->
<?php if (!$icps && ($filterVerticalId !== '' || $filterServiceId !== '' || $filterCountryGroupId !== '')): ?>
  <div class="card icp-card">
    <div class="card-body text-center py-5">
      <p class="text-muted mb-0">No ICP segments match this filter. <a href="icp_segments.php">Reset the filter</a> to see everything.</p>
    </div>
  </div>
<?php elseif (!$icps): ?>
  <div class="card icp-card">
    <div class="card-body text-center py-5">
      <p class="text-muted mb-0">No ICP segments yet -- create one above to start auto-distributing leads across campaigns.</p>
    </div>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
