<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/RoleGroupClassifier.php';
require_once __DIR__ . '/../app/includes/EmployeeCountRangeClassifier.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
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
        ];

        $hasAnyCriterion = $data['role_group_id'] || $data['vertical_id'] || $data['service_id'] || $data['country_group_id']
            || $data['company_country'] !== '' || $data['industry'] !== '' || $data['seniority'] !== '' || $data['employee_count'] !== '';

        if ($name === '') {
            flash_set('danger', 'A name is required.');
        } elseif (!$hasAnyCriterion) {
            flash_set('danger', 'Pick at least one match criterion (Role Group, Vertical, Service, Country Group, or one of the other fields) -- an ICP with nothing set would match every lead in the database.');
        } else {
            try {
                if ($action === 'create') {
                    IcpRepository::create(db(), $data, $admin['id']);
                    flash_set('success', "\"{$name}\" created -- now link 2 or more campaigns to it below with a percentage split.");
                } else {
                    IcpRepository::update(db(), (int) ($_POST['id'] ?? 0), $data);
                    flash_set('success', "\"{$name}\" updated.");
                }
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'That name already exists.' : 'Could not save ICP segment.');
            }
        }
    } elseif ($action === 'toggle_active') {
        IcpRepository::toggleActive(db(), (int) ($_POST['id'] ?? 0));
        flash_set('success', 'Status updated.');
    } elseif ($action === 'add_link') {
        $icpId = (int) ($_POST['icp_id'] ?? 0);
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        if (!$campaignId) {
            flash_set('danger', 'Pick a campaign to link.');
        } else {
            try {
                IcpRepository::addLink(db(), $icpId, $campaignId);
                flash_set('success', 'Campaign linked -- percentages auto-split evenly across all linked campaigns.');
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'That campaign is already linked to this ICP.' : 'Could not link campaign.');
            }
        }
    } elseif ($action === 'remove_link') {
        IcpRepository::removeLink(db(), (int) ($_POST['link_id'] ?? 0));
        flash_set('success', 'Link removed -- remaining campaigns auto-split evenly.');
    } elseif ($action === 'rebalance') {
        IcpRepository::rebalanceEvenly(db(), (int) ($_POST['icp_id'] ?? 0));
        flash_set('success', 'Split reset to an even percentage across all linked campaigns.');
    } elseif ($action === 'update_split') {
        $icpId = (int) ($_POST['icp_id'] ?? 0);
        $percentages = array_map('intval', (array) ($_POST['percentage'] ?? []));
        if (IcpRepository::updateLinkPercentages(db(), $icpId, $percentages)) {
            flash_set('success', 'Custom split saved.');
        } else {
            flash_set('danger', 'Could not save split -- percentages must be 1-100 each and sum to exactly 100.');
        }
    }

    header('Location: icp_segments.php');
    exit;
}

$icps = IcpRepository::list(db());
$roleGroups = LeadRepository::activeLookupOptions(db(), 'role_groups');
$verticals = LeadRepository::activeLookupOptions(db(), 'verticals');
$services = LeadRepository::activeLookupOptions(db(), 'services');
$countryGroups = LeadRepository::activeLookupOptions(db(), 'country_groups');
$campaigns = db()->query("SELECT id, name FROM campaigns WHERE saleshandy_sequence_id IS NOT NULL ORDER BY name")->fetchAll();

$companyCountries = LeadRepository::distinctValues(db(), 'company_country');
$industries = LeadRepository::distinctValues(db(), 'industry');
$seniorities = LeadRepository::distinctValues(db(), 'seniority');
$employeeCountRanges = EmployeeCountRangeClassifier::allLabels();

$linksByIcp = [];
$matchingCountByIcp = [];
foreach ($icps as $icp) {
    $linksByIcp[(int) $icp['id']] = IcpRepository::links(db(), (int) $icp['id']);
    // Same filters + "never assigned to any campaign before" scoping the
    // distribution cron itself uses (IcpRepository::toFilters()) -- so
    // this is exactly the pool the next cron run would pick up and split,
    // not "every lead that could ever match this ICP".
    $matchingCountByIcp[(int) $icp['id']] = LeadRepository::matchingCount(db(), IcpRepository::toFilters($icp));
}

// Active Role Groups (personas) with zero ICPs referencing them at all
// yet (not just zero *active* ones -- even an unfinished draft ICP still
// counts as "started"), most classified leads first -- so the gap most
// worth closing shows up on top instead of just a flat A-Z list.
$unmappedPersonas = db()->query(
    "SELECT rg.id, rg.label,
        (SELECT COUNT(*) FROM leads l WHERE l.role_group_id = rg.id AND l.deleted_at IS NULL) AS lead_count
       FROM role_groups rg
      WHERE rg.is_active = 1
        AND NOT EXISTS (SELECT 1 FROM icp_segments icp WHERE icp.role_group_id = rg.id)
      ORDER BY lead_count DESC, rg.label"
)->fetchAll();

$activeIcpCount = count(array_filter($icps, static fn (array $i): bool => (bool) $i['is_active']));

// Cycled per linked-campaign row so the split preview bar and its rows
// below share a color, in a fixed order (not randomized/hashed) so a
// campaign's color stays stable across page loads within one ICP.
$splitPalette = ['bg-primary', 'bg-info', 'bg-warning', 'bg-success', 'bg-danger', 'bg-secondary'];

/** Renders one "Label: value" pill in the criteria row below an ICP's name. */
$renderChip = static function (string $label, string $value, bool $accent = false): string {
    return '<span class="icp-chip' . ($accent ? ' icp-chip-accent' : '') . '"><span class="icp-chip-label">' . e($label) . '</span>' . e($value) . '</span>';
};

render_header('ICP Segments');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div style="max-width: 760px;">
    <h1 class="h4 mb-1">ICP Segments</h1>
    <p class="text-muted mb-0">
      Define a buyer persona once, link it to 2+ campaigns with a percentage split, and the distribution cron
      keeps feeding newly-matching leads into the right test automatically.
      <?= info_icon('Match criteria: company country, vertical, service, seniority, employee count, and optionally one Role Group. Role Group is optional -- leave it as "Any persona" to target purely by country/industry/size; at least one criterion is still required so an ICP can\'t accidentally match every lead in the database. The split is automatic: link one campaign and it gets 100%; add a second or third and every linked campaign instantly re-splits evenly (50/50, then 34/33/33). Want an uneven weighting instead (e.g. 70/30)? Edit the percentages and click "Save split" -- "Auto-split evenly" resets it back any time.') ?>
    </p>
  </div>
  <div class="d-flex gap-2 flex-shrink-0">
    <span class="badge rounded-pill bg-success-subtle text-success-emphasis px-3 py-2"><?= $activeIcpCount ?> active</span>
    <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis px-3 py-2"><?= count($icps) ?> total</span>
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
        <div class="col-md-4 form-check form-switch pt-2 ps-5">
          <input class="form-check-input" type="checkbox" role="switch" name="auto_push_enabled" value="1" id="autoPushNew">
          <label class="form-check-label small" for="autoPushNew">Auto-push to Saleshandy after assignment</label>
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

<?php foreach ($icps as $icp):
    $links = $linksByIcp[(int) $icp['id']];
    $total = $icp['percentage_total'];
    $ready = $total === 100;
?>
  <div class="card icp-card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold"><?= e($icp['name']) ?></span>
        <span class="badge rounded-pill bg-<?= $icp['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $icp['is_active'] ? 'success' : 'secondary' ?>-emphasis"><?= $icp['is_active'] ? 'Active' : 'Inactive' ?></span>
        <?php if ($icp['is_active']): ?>
          <span class="badge rounded-pill bg-<?= $ready ? 'success' : 'warning' ?>-subtle text-<?= $ready ? 'success' : 'warning' ?>-emphasis"><?= $ready ? 'Ready &middot; ' . $total . '%' : 'Not running &middot; ' . $total . '%' ?></span>
        <?php endif; ?>
      </div>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editIcp<?= (int) $icp['id'] ?>">Edit</button>
        <form method="post" action="icp_segments.php" class="d-inline" onsubmit="return confirm('Toggle active status for <?= e($icp['name']) ?>?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_active">
          <input type="hidden" name="id" value="<?= (int) $icp['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><?= $icp['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
      </div>
    </div>
    <div class="card-body">
      <div class="d-flex align-items-baseline gap-2 mb-3">
        <span class="fs-4 fw-bold"><?= number_format($matchingCountByIcp[(int) $icp['id']]) ?></span>
        <span class="text-muted small">
          lead(s) eligible right now
          <?= info_icon('Leads matching this ICP\'s current criteria that have never been assigned to any campaign before -- exactly the pool the next distribution cron run would pick up and split across the linked campaigns. Leads already assigned here or to any other campaign are intentionally excluded, since the cron never reconsiders them.') ?>
        </span>
      </div>
      <div class="d-flex flex-wrap gap-2 mb-4">
        <?= $renderChip('Persona', $icp['role_group_label'] ?: 'Any persona') ?>
        <?php if ($icp['vertical_label']): ?><?= $renderChip('Vertical', $icp['vertical_label']) ?><?php endif; ?>
        <?php if ($icp['service_label']): ?><?= $renderChip('Service', $icp['service_label']) ?><?php endif; ?>
        <?php if ($icp['country_group_label']): ?><?= $renderChip('Country Group', $icp['country_group_label']) ?><?php endif; ?>
        <?php if ($icp['company_country']): ?><?= $renderChip('Country', $icp['company_country']) ?><?php endif; ?>
        <?php if ($icp['industry']): ?><?= $renderChip('Industry', $icp['industry']) ?><?php endif; ?>
        <?php if ($icp['seniority']): ?><?= $renderChip('Seniority', $icp['seniority']) ?><?php endif; ?>
        <?php if ($icp['employee_count']): ?><?= $renderChip('Size', $icp['employee_count']) ?><?php endif; ?>
        <?php if ($icp['auto_push_enabled']): ?><?= $renderChip('Saleshandy', 'Auto-push on', true) ?><?php endif; ?>
      </div>

      <?php if ($links): ?>
      <div class="mb-2">
        <div class="d-flex justify-content-between small text-muted mb-1">
          <span>Campaign split</span>
          <span><?= $total ?>%</span>
        </div>
        <div class="progress" style="height: 8px;">
          <?php foreach ($links as $i => $link): ?>
            <div class="progress-bar <?= $splitPalette[$i % count($splitPalette)] ?>" role="progressbar"
                 style="width: <?= (int) $link['percentage'] ?>%" title="<?= e($link['campaign_name']) ?>: <?= (int) $link['percentage'] ?>%"></div>
          <?php endforeach; ?>
        </div>
      </div>

      <form method="post" action="icp_segments.php" class="icp-split-form mb-2" data-icp-id="<?= (int) $icp['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_split">
        <input type="hidden" name="icp_id" value="<?= (int) $icp['id'] ?>">
        <div class="icp-split-rows mb-2">
          <?php foreach ($links as $i => $link): ?>
            <div class="d-flex align-items-center gap-2 py-1">
              <span class="icp-swatch <?= $splitPalette[$i % count($splitPalette)] ?>"></span>
              <span class="flex-grow-1"><?= e($link['campaign_name']) ?></span>
              <input type="number" name="percentage[<?= (int) $link['id'] ?>]" value="<?= (int) $link['percentage'] ?>"
                     min="1" max="100" class="form-control form-control-sm icp-split-input text-end" style="width: 72px;">
              <span class="text-muted small" style="width: 12px;">%</span>
              <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeIcpLink(<?= (int) $icp['id'] ?>, <?= (int) $link['id'] ?>)">Remove</button>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="d-flex align-items-center gap-2 mb-3">
          <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3">Save split</button>
          <span class="small icp-split-total" data-expected="100">Total: <?= (int) $icp['percentage_total'] ?>%</span>
        </div>
      </form>
      <form method="post" action="icp_segments.php" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="rebalance">
        <input type="hidden" name="icp_id" value="<?= (int) $icp['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill px-3 mb-3">Auto-split evenly</button>
      </form>
      <form method="post" action="icp_segments.php" id="removeLinkForm<?= (int) $icp['id'] ?>" class="d-none">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_link">
        <input type="hidden" name="link_id" id="removeLinkId<?= (int) $icp['id'] ?>" value="">
      </form>
      <?php else: ?>
      <p class="text-muted small">No campaigns linked yet.</p>
      <?php endif; ?>
      <form method="post" action="icp_segments.php" class="d-flex gap-2 align-items-end icp-add-link-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_link">
        <input type="hidden" name="icp_id" value="<?= (int) $icp['id'] ?>">
        <select name="campaign_id" class="form-select form-select-sm" required>
          <option value="">Link a campaign&hellip;</option>
          <?php foreach ($campaigns as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline-primary btn-sm rounded-pill px-3 text-nowrap">Add link</button>
      </form>
    </div>
  </div>

  <div class="modal fade" id="editIcp<?= (int) $icp['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <form method="post" action="icp_segments.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int) $icp['id'] ?>">
          <div class="modal-header">
            <h5 class="modal-title">Edit "<?= e($icp['name']) ?>"</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body row g-3">
            <div class="col-md-6">
              <label class="form-label small text-muted">Name</label>
              <input type="text" name="name" class="form-control" value="<?= e($icp['name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Role Group (persona)</label>
              <select name="role_group_id" class="form-select">
                <option value="">Any persona</option>
                <?php foreach ($roleGroups as $rg): ?>
                  <option value="<?= (int) $rg['id'] ?>" <?= (int) $icp['role_group_id'] === (int) $rg['id'] ? 'selected' : '' ?>><?= e($rg['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 icp-section-label mt-2">Match criteria</div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Vertical</label>
              <select name="vertical_id" class="form-select">
                <option value="">Any</option>
                <?php foreach ($verticals as $v): ?>
                  <option value="<?= (int) $v['id'] ?>" <?= (int) $icp['vertical_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Service</label>
              <select name="service_id" class="form-select">
                <option value="">Any</option>
                <?php foreach ($services as $s): ?>
                  <option value="<?= (int) $s['id'] ?>" <?= (int) $icp['service_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Country Group</label>
              <select name="country_group_id" class="form-select">
                <option value="">Any</option>
                <?php foreach ($countryGroups as $cg): ?>
                  <option value="<?= (int) $cg['id'] ?>" <?= (int) $icp['country_group_id'] === (int) $cg['id'] ? 'selected' : '' ?>><?= e($cg['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted mb-1">Company Country</label>
              <?php render_multiselect_filter('company_country', 'Company Country', $companyCountries, RoleGroupClassifier::parseKeywords($icp['company_country'] ?? '')); ?>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted mb-1">Industry</label>
              <?php render_multiselect_filter('industry', 'Industry', $industries, RoleGroupClassifier::parseKeywords($icp['industry'] ?? '')); ?>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted mb-1">Seniority</label>
              <?php render_multiselect_filter('seniority', 'Seniority', $seniorities, RoleGroupClassifier::parseKeywords($icp['seniority'] ?? '')); ?>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted mb-1">Employee Count</label>
              <?php render_multiselect_filter('employee_count', 'Employee Count', $employeeCountRanges, RoleGroupClassifier::parseKeywords($icp['employee_count'] ?? '')); ?>
            </div>
            <div class="col-12 form-check form-switch mt-2">
              <input class="form-check-input" type="checkbox" role="switch" name="auto_push_enabled" value="1" id="autoPush<?= (int) $icp['id'] ?>" <?= $icp['auto_push_enabled'] ? 'checked' : '' ?>>
              <label class="form-check-label small" for="autoPush<?= (int) $icp['id'] ?>">Auto-push to Saleshandy after assignment (otherwise assignment only -- push manually per campaign as usual)</label>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$icps): ?>
  <div class="card icp-card">
    <div class="card-body text-center py-5">
      <p class="text-muted mb-0">No ICP segments yet -- create one above to start auto-distributing leads across campaigns.</p>
    </div>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
