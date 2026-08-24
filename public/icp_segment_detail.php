<?php
/**
 * Full single-ICP management page: edit match criteria, and link/split/
 * unlink campaigns -- the "open one ICP segment" counterpart to
 * icp_segments.php's list. Reuses IcpRepository's exact ownership/
 * visibility rules (see IcpRepository::findVisible()), so a Team Lead or
 * Member landing here on another team's ICP id sees the same "not found"
 * they'd get if it just weren't in their list.
 */
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
    // Every action below belongs to exactly one ICP -- redirect back to
    // that same ICP's page afterward, whichever field the action posts
    // its id under.
    $redirectIcpId = (int) ($_POST['id'] ?? $_POST['icp_id'] ?? 0);

    if ($action === 'update') {
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
        ];

        $hasAnyCriterion = $data['role_group_id'] || $data['vertical_id'] || $data['service_id'] || $data['country_group_id']
            || $data['company_country'] !== '' || $data['industry'] !== '' || $data['seniority'] !== '' || $data['employee_count'] !== '';

        if ($name === '') {
            flash_set('danger', 'A name is required.');
        } elseif (!$hasAnyCriterion) {
            flash_set('danger', 'Pick at least one match criterion (Role Group, Vertical, Service, Country Group, or one of the other fields) -- an ICP with nothing set would match every lead in the database.');
        } else {
            try {
                IcpRepository::update(db(), (int) ($_POST['id'] ?? 0), $data, $scope);
                flash_set('success', "\"{$name}\" updated.");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'That name already exists.' : 'Could not save ICP segment.');
            }
        }
    } elseif ($action === 'toggle_active') {
        IcpRepository::toggleActive(db(), (int) ($_POST['id'] ?? 0), $scope);
        flash_set('success', 'Status updated.');
    } elseif ($action === 'add_links') {
        $icpId = (int) ($_POST['icp_id'] ?? 0);
        $campaignIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['campaign_ids'] ?? [])))));
        if (!$campaignIds) {
            flash_set('danger', 'Pick at least one campaign to link.');
        } else {
            $linkedCount = 0;
            $errors = [];
            foreach ($campaignIds as $campaignId) {
                try {
                    IcpRepository::addLink(db(), $icpId, $campaignId, $scope);
                    $linkedCount++;
                } catch (PDOException $ex) {
                    $errors[] = str_contains($ex->getMessage(), 'Duplicate') ? 'That campaign is already linked to this ICP.' : 'Could not link campaign.';
                } catch (InvalidArgumentException $ex) {
                    $errors[] = $ex->getMessage();
                }
            }
            if ($linkedCount > 0) {
                flash_set('success', $linkedCount === 1 ? '1 campaign linked -- percentages auto-split evenly across all linked campaigns.' : "{$linkedCount} campaigns linked -- percentages auto-split evenly across all linked campaigns.");
            }
            if ($errors) {
                flash_set('danger', implode(' ', array_unique($errors)));
            }
        }
    } elseif ($action === 'remove_link') {
        IcpRepository::removeLink(db(), (int) ($_POST['link_id'] ?? 0), $scope);
        flash_set('success', 'Link removed -- remaining campaigns auto-split evenly.');
    } elseif ($action === 'rebalance') {
        IcpRepository::rebalanceEvenly(db(), (int) ($_POST['icp_id'] ?? 0), $scope);
        flash_set('success', 'Split reset to an even percentage across all linked campaigns.');
    } elseif ($action === 'update_split') {
        $icpId = (int) ($_POST['icp_id'] ?? 0);
        $percentages = array_map('intval', (array) ($_POST['percentage'] ?? []));
        if (IcpRepository::updateLinkPercentages(db(), $icpId, $percentages, $scope)) {
            flash_set('success', 'Custom split saved.');
        } else {
            flash_set('danger', 'Could not save split -- percentages must be 1-100 each and sum to exactly 100.');
        }
    }

    header('Location: icp_segment_detail.php?id=' . $redirectIcpId);
    exit;
}

$icpId = (int) ($_GET['id'] ?? 0);
$icp = IcpRepository::findVisible(db(), $scope, $icpId);
if (!$icp) {
    flash_set('danger', 'ICP segment not found.');
    header('Location: icp_segments.php');
    exit;
}

$links = IcpRepository::links(db(), $icpId, $scope);
$total = (int) $icp['percentage_total'];
$ready = $total === 100;

// Same filters + cooldown-based assignability scoping the distribution
// cron itself uses (IcpRepository::toFilters()) -- see icp_segments.php
// for the full explanation.
$matchingCount = LeadRepository::matchingCount(db(), $scope, IcpRepository::toFilters($icp, $scope));

$roleGroups = LeadRepository::activeLookupOptions(db(), $scope, 'role_groups');
$verticals = LeadRepository::activeLookupOptions(db(), $scope, 'verticals');
$services = LeadRepository::activeLookupOptions(db(), $scope, 'services');
$countryGroups = LeadRepository::activeLookupOptions(db(), $scope, 'country_groups');
$companyCountries = LeadRepository::distinctValues(db(), $scope, 'company_country');
$industries = LeadRepository::distinctValues(db(), $scope, 'industry');
$seniorities = LeadRepository::distinctValues(db(), $scope, 'seniority');
$employeeCountRanges = EmployeeCountRangeClassifier::allLabels();

// The "link a campaign" picker only ever offers campaigns this scope is
// actually allowed to link (see IcpRepository::addLink()) -- Admin sees
// every linkable campaign in the company, a Team Lead/Member sees only
// their own, so nothing shown here can ever be rejected on submit.
// Already-linked campaigns are excluded outright (checking them again
// would just surface a "That campaign is already linked" error per
// selection). Grouped by the campaign's own Country Group and Service
// (campaigns.country_group_id/service_id, see
// sql/044_campaign_country_group.sql / sql/018_campaign_vertical_service.sql)
// into checkbox sections, with a trailing "Uncategorized" section for
// campaigns with neither set.
$alreadyLinkedCampaignIds = array_map('intval', array_column($links, 'campaign_id'));
$campaignsClauses = ['c.company_id = :scope_company_id', 'c.saleshandy_sequence_id IS NOT NULL'];
$campaignsParams = ['scope_company_id' => $scope->companyId];
if (!$scope->isAdmin()) {
    $campaignsClauses[] = 'c.saleshandy_account_owner_id = :scope_user_id';
    $campaignsParams['scope_user_id'] = $scope->userId;
}
if ($alreadyLinkedCampaignIds) {
    $excludeNames = [];
    foreach ($alreadyLinkedCampaignIds as $i => $excludeId) {
        $excludeNames[] = ":exclude_linked_{$i}";
        $campaignsParams["exclude_linked_{$i}"] = $excludeId;
    }
    $campaignsClauses[] = 'c.id NOT IN (' . implode(',', $excludeNames) . ')';
}
$campaignsStmt = db()->prepare(
    'SELECT c.id, c.name, cg.code AS country_group_code, cg.label AS country_group_label, s.label AS service_label
       FROM campaigns c
       LEFT JOIN country_groups cg ON cg.id = c.country_group_id
       LEFT JOIN services s ON s.id = c.service_id
      WHERE ' . implode(' AND ', $campaignsClauses) . '
      ORDER BY (cg.label IS NULL), cg.label, (s.label IS NULL), s.label, c.name'
);
$campaignsStmt->execute($campaignsParams);
$campaignsByGroup = [];
foreach ($campaignsStmt->fetchAll() as $c) {
    $cg = $c['country_group_code'] ?: $c['country_group_label'];
    $s = $c['service_label'];
    $parts = array_filter([$cg, $s]);
    $groupLabel = $parts ? implode(' - ', $parts) : 'Uncategorized';
    $campaignsByGroup[$groupLabel][] = $c;
}

// Cycled per linked-campaign row so the split preview bar and its rows
// below share a color, in a fixed order (not randomized/hashed) so a
// campaign's color stays stable across page loads.
$splitPalette = ['bg-primary', 'bg-info', 'bg-warning', 'bg-success', 'bg-danger', 'bg-secondary'];

render_header($icp['name'] . ' - ICP Segment');
?>
<a href="icp_segments.php" class="d-inline-flex align-items-center gap-1 text-decoration-none small text-muted mb-3">&larr; Back to ICP Segments</a>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
      <h1 class="h4 mb-0"><?= e($icp['name']) ?></h1>
      <span class="badge rounded-pill bg-<?= $icp['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $icp['is_active'] ? 'success' : 'secondary' ?>-emphasis"><?= $icp['is_active'] ? 'Active' : 'Inactive' ?></span>
      <?php if ($icp['is_active']): ?>
        <span class="badge rounded-pill bg-<?= $ready ? 'success' : 'warning' ?>-subtle text-<?= $ready ? 'success' : 'warning' ?>-emphasis"><?= $ready ? 'Ready &middot; ' . $total . '%' : 'Not running &middot; ' . $total . '%' ?></span>
      <?php endif; ?>
    </div>
    <div class="text-muted small">
      <span class="fs-5 fw-bold text-body"><?= number_format($matchingCount) ?></span> lead(s) eligible right now
      <?= info_icon('Leads matching this ICP\'s current criteria that are assignable right now -- either never assigned to any campaign before, or previously assigned but with that assignment resolved (not held, not still pending a delivery outcome) and past your company\'s cooldown period.'
          . ($icp['require_sequence_completed'] ? ' "Only reassign leads whose prior sequence fully completed" is ON for this ICP, so a previously-assigned lead must ALSO have gone through every step of its prior campaign\'s sequence with no reply -- not just resolved+cooled-down.' : '')
          . ' Leads still held/pending elsewhere, or on a suppressed domain, are excluded. Exactly the pool the next distribution cron run would pick up and split across the linked campaigns.') ?>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2 flex-shrink-0">
    <form method="post" action="icp_distribution_run_one.php" class="d-inline" title="Assign eligible leads and, if auto-push is on, push them to Saleshandy now, for just this ICP.">
      <?= csrf_field() ?>
      <input type="hidden" name="icp_id" value="<?= (int) $icp['id'] ?>">
      <input type="hidden" name="redirect_to" value="icp_segments">
      <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3" <?= $ready ? '' : 'disabled' ?>>Sync/Push now</button>
    </form>
    <form method="post" action="icp_segment_detail.php" class="d-inline" onsubmit="return confirm('Toggle active status for <?= e($icp['name']) ?>?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_active">
      <input type="hidden" name="id" value="<?= (int) $icp['id'] ?>">
      <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><?= $icp['is_active'] ? 'Deactivate' : 'Activate' ?></button>
    </form>
  </div>
</div>

<div class="card icp-card mb-4">
  <div class="card-header fw-semibold">Details &amp; match criteria</div>
  <div class="card-body">
    <form method="post" action="icp_segment_detail.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= (int) $icp['id'] ?>">

      <div class="row g-3 align-items-end mb-4">
        <div class="col-md-4">
          <label class="form-label small text-muted mb-1">Name</label>
          <input type="text" name="name" class="form-control" value="<?= e($icp['name']) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label small text-muted mb-1">Role Group (persona)</label>
          <select name="role_group_id" class="form-select">
            <option value="">Any persona</option>
            <?php foreach ($roleGroups as $rg): ?>
              <option value="<?= (int) $rg['id'] ?>" <?= (int) $icp['role_group_id'] === (int) $rg['id'] ? 'selected' : '' ?>><?= e($rg['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 form-check form-switch pt-2 ps-5">
          <input class="form-check-input" type="checkbox" role="switch" name="auto_push_enabled" value="1" id="autoPushEdit" <?= $icp['auto_push_enabled'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="autoPushEdit">Auto-push to Saleshandy after assignment</label>
        </div>
        <div class="col-md-2 form-check form-switch pt-2 ps-5">
          <input class="form-check-input" type="checkbox" role="switch" name="require_sequence_completed" value="1" id="requireSeqCompletedEdit" <?= $icp['require_sequence_completed'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="requireSeqCompletedEdit">Only reassign leads whose prior sequence fully completed <?= info_icon('When re-matching a previously-assigned lead (after cooldown), also require that its last campaign\'s sequence actually finished -- current step reached the sequence\'s real total, with no reply -- not just that the assignment is resolved and past cooldown. Off by default (broader reassignment); turn this on for an ICP meant specifically to catch "finished with silence" leads for a follow-up push.') ?></label>
        </div>
      </div>

      <div class="icp-section-label mb-2">Match criteria <span class="fw-normal text-muted" style="text-transform: none; letter-spacing: normal;">(at least one required)</span></div>
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Vertical</label>
          <select name="vertical_id" class="form-select">
            <option value="">Any</option>
            <?php foreach ($verticals as $v): ?>
              <option value="<?= (int) $v['id'] ?>" <?= (int) $icp['vertical_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Service</label>
          <select name="service_id" class="form-select">
            <option value="">Any</option>
            <?php foreach ($services as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= (int) $icp['service_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Country Group</label>
          <select name="country_group_id" class="form-select">
            <option value="">Any</option>
            <?php foreach ($countryGroups as $cg): ?>
              <option value="<?= (int) $cg['id'] ?>" <?= (int) $icp['country_group_id'] === (int) $cg['id'] ? 'selected' : '' ?>><?= e($cg['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Company Country</label>
          <?php render_multiselect_filter('company_country', 'Company Country', $companyCountries, RoleGroupClassifier::parseKeywords($icp['company_country'] ?? '')); ?>
        </div>
      </div>
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label small text-muted mb-1">Industry</label>
          <?php render_multiselect_filter('industry', 'Industry', $industries, RoleGroupClassifier::parseKeywords($icp['industry'] ?? '')); ?>
        </div>
        <div class="col-md-4">
          <label class="form-label small text-muted mb-1">Seniority</label>
          <?php render_multiselect_filter('seniority', 'Seniority', $seniorities, RoleGroupClassifier::parseKeywords($icp['seniority'] ?? '')); ?>
        </div>
        <div class="col-md-4">
          <label class="form-label small text-muted mb-1">Employee Count</label>
          <?php render_multiselect_filter('employee_count', 'Employee Count', $employeeCountRanges, RoleGroupClassifier::parseKeywords($icp['employee_count'] ?? '')); ?>
        </div>
      </div>

      <button type="submit" class="btn btn-primary px-4">Save changes</button>
    </form>
  </div>
</div>

<div class="card icp-card mb-4">
  <div class="card-header fw-semibold">Linked campaigns &amp; split</div>
  <div class="card-body">
    <?php if ($links): ?>
    <div class="mb-3">
      <div class="d-flex justify-content-between small text-muted mb-1">
        <span>Campaign split</span>
        <span><?= $total ?>%</span>
      </div>
      <div class="progress" style="height: 10px;">
        <?php foreach ($links as $i => $link): ?>
          <div class="progress-bar <?= $splitPalette[$i % count($splitPalette)] ?>" role="progressbar"
               style="width: <?= (int) $link['percentage'] ?>%" title="<?= e($link['campaign_name']) ?>: <?= (int) $link['percentage'] ?>%"></div>
        <?php endforeach; ?>
      </div>
    </div>

    <form method="post" action="icp_segment_detail.php" class="icp-split-form mb-2" data-icp-id="<?= (int) $icp['id'] ?>">
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
        <span class="small icp-split-total" data-expected="100">Total: <?= $total ?>%</span>
      </div>
    </form>
    <form method="post" action="icp_segment_detail.php" class="d-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="rebalance">
      <input type="hidden" name="icp_id" value="<?= (int) $icp['id'] ?>">
      <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill px-3 mb-3">Auto-split evenly</button>
    </form>
    <form method="post" action="icp_segment_detail.php" id="removeLinkForm<?= (int) $icp['id'] ?>" class="d-none">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="remove_link">
      <input type="hidden" name="icp_id" value="<?= (int) $icp['id'] ?>">
      <input type="hidden" name="link_id" id="removeLinkId<?= (int) $icp['id'] ?>" value="">
    </form>
    <?php else: ?>
    <p class="text-muted small">No campaigns linked yet. Link one campaign and it gets 100%; add a second or third and every
      linked campaign instantly re-splits evenly (50/50, then 34/33/33). Want an uneven weighting instead (e.g. 70/30)?
      Edit the percentages above and click "Save split" once you have 2+ linked.</p>
    <?php endif; ?>
    <?php if ($campaignsByGroup): ?>
    <form method="post" action="icp_segment_detail.php" class="icp-add-links-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_links">
      <input type="hidden" name="icp_id" value="<?= (int) $icp['id'] ?>">
      <div class="small text-muted mb-2">Link one or more campaigns -- check as many as you want, then click "Link selected".</div>
      <div class="row g-3 mb-2" style="max-height: 320px; overflow-y: auto;">
        <?php foreach ($campaignsByGroup as $groupLabel => $group): ?>
          <div class="col-md-4">
            <div class="fw-semibold small text-uppercase text-muted mb-1"><?= e($groupLabel) ?></div>
            <?php foreach ($group as $c): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="campaign_ids[]" value="<?= (int) $c['id'] ?>" id="linkCampaign<?= (int) $icp['id'] ?>_<?= (int) $c['id'] ?>">
                <label class="form-check-label small" for="linkCampaign<?= (int) $icp['id'] ?>_<?= (int) $c['id'] ?>"><?= e($c['name']) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn-outline-primary btn-sm rounded-pill px-3">Link selected campaigns</button>
    </form>
    <?php else: ?>
    <p class="text-muted small mb-0">No more campaigns available to link<?= $links ? ' -- every eligible campaign is already linked to this ICP.' : '.' ?></p>
    <?php endif; ?>
  </div>
</div>

<?php render_footer(); ?>
