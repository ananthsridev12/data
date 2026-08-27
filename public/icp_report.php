<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$icps = IcpRepository::performanceStats(db(), $scope);

$pct = static function (int $numerator, int $denominator): string {
    if ($denominator === 0) {
        return '--';
    }
    return number_format($numerator * 100 / $denominator, 1) . '%';
};

render_header('ICP Report');
?>
<h1 class="h4 mb-3">ICP Report</h1>
<p class="text-muted">
  Saleshandy performance per ICP segment -- how many leads each ICP's distribution cron run(s) have assigned,
  how many are still waiting to be pushed, and how they performed once pushed (delivered/bounced/opened/replied).
</p>

<div class="card mb-4">
  <div class="card-header">
    About this report
    <?= info_icon('Counts only leads a distribution cron run actually assigned via this ICP (lead_campaign_assignments.icp_id) -- not every lead sitting in one of its linked campaigns, since a campaign can be linked to more than one ICP and a lead added manually was never caused by this one. Assignments made before this tracking was added show as 0 here even though they exist in the campaign. "Pending push" mirrors the exact eligibility check the auto-push cron and the manual "Push to Saleshandy" button both use (wave-1 active, not yet pushed, not domain-suppressed) -- it\'s literally what the next run would pick up.') ?>
  </div>
  <div class="card-body small text-muted">
    <strong>How auto-push actually works:</strong> when "Auto-push to Saleshandy" is checked on an ICP, the
    <code>icp_distribution_cron.php</code> run that assigns new leads to a campaign immediately calls the same
    push logic as the "Push to Saleshandy" button for that campaign right after -- it re-queries the campaign
    for every lead that's wave-1-active and not yet pushed (that's "pending data": nothing more than "not yet
    marked <code>status = 'pushed'</code>"), so it also catches anything left over from a previous run that
    failed partway, not just leads from the current run. If auto-push is off, leads still get assigned by the
    cron, but pushing stays a manual click per campaign.
  </div>
</div>

<div class="table-responsive">
<table class="table table-sm table-striped bg-white">
  <thead>
    <tr>
      <th>ICP</th>
      <th>Linked campaigns</th>
      <th>Auto-push</th>
      <th class="text-end">Leads assigned</th>
      <th class="text-end">Pending push</th>
      <th class="text-end">Pushed</th>
      <th class="text-end">Emails sent</th>
      <th class="text-end">Delivered</th>
      <th class="text-end">Bounced</th>
      <th class="text-end">Bounce rate</th>
      <th class="text-end">Opened</th>
      <th class="text-end">Open rate</th>
      <th class="text-end">Replied</th>
      <th class="text-end">Reply rate</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($icps as $icp): ?>
      <tr>
        <td>
          <?= e($icp['name']) ?>
          <span class="badge bg-<?= $icp['is_active'] ? 'success' : 'secondary' ?> ms-1"><?= $icp['is_active'] ? 'Active' : 'Inactive' ?></span>
        </td>
        <td class="small text-muted"><?= e($icp['campaign_names'] ?? '') ?: '<span class="text-muted">None linked</span>' ?></td>
        <td><?= $icp['auto_push_enabled'] ? '<span class="badge bg-info text-dark">On</span>' : '<span class="text-muted small">Off</span>' ?></td>
        <td class="text-end">
          <?php if ($icp['leads_assigned'] > 0): ?>
            <a href="icp_assigned_leads.php?icp_id=<?= (int) $icp['id'] ?>"><?= number_format($icp['leads_assigned']) ?></a>
          <?php else: ?>
            0
          <?php endif; ?>
        </td>
        <td class="text-end"><?= $icp['pending_push'] > 0 ? '<strong>' . number_format($icp['pending_push']) . '</strong>' : '0' ?></td>
        <td class="text-end"><?= number_format($icp['pushed']) ?></td>
        <td class="text-end"><?= number_format($icp['emails_sent']) ?></td>
        <td class="text-end"><?= number_format($icp['delivered']) ?></td>
        <td class="text-end"><?= number_format($icp['bounced']) ?></td>
        <td class="text-end"><?= $pct($icp['bounced'], $icp['pushed']) ?></td>
        <td class="text-end"><?= number_format($icp['opened']) ?></td>
        <td class="text-end"><?= $pct($icp['opened'], $icp['delivered']) ?></td>
        <td class="text-end"><?= number_format($icp['replied']) ?></td>
        <td class="text-end"><?= $pct($icp['replied'], $icp['delivered']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$icps): ?>
      <tr><td colspan="14" class="text-center text-muted py-4">No ICP segments yet -- create one on the <a href="icp_segments.php">ICP Segments</a> page.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>

<?php render_footer(); ?>
