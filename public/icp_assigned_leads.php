<?php
/**
 * Every lead a distribution cron run has actually assigned via one ICP
 * segment (lead_campaign_assignments.icp_id, sql/028_icp_assignment_tracking.sql)
 * -- the "view the leads" counterpart to icp_report.php's aggregate
 * counts and icp_segments.php/icp_segment_detail.php's "N leads eligible
 * now" (which is the *upcoming* pool, not this page's *already assigned*
 * history). A lead added to one of this ICP's linked campaigns some
 * other way (manually, via a different ICP that shares the campaign)
 * never shows here -- icp_id is only ever set by WaveAssigner::assign()
 * when an ICP distribution run is what caused the assignment.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$icpId = (int) ($_GET['icp_id'] ?? 0);
$icp = IcpRepository::findVisible(db(), $scope, $icpId);
if (!$icp) {
    flash_set('danger', 'ICP segment not found.');
    header('Location: icp_segments.php');
    exit;
}

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

$whereClauses = ['a.icp_id = :icp_id', 'l.deleted_at IS NULL'];
$whereParams = ['icp_id' => $icpId];
if (!$scope->isAdmin()) {
    $whereClauses[] = 'c.saleshandy_account_owner_id = :owner_id';
    $whereParams['owner_id'] = $scope->userId;
}
$where = implode(' AND ', $whereClauses);

$countStmt = db()->prepare(
    "SELECT COUNT(*) FROM lead_campaign_assignments a
     JOIN leads l ON l.id = a.lead_id
     JOIN campaigns c ON c.id = a.campaign_id
    WHERE {$where}"
);
$countStmt->execute($whereParams);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT a.*, l.na_company_name, l.first_name, l.last_name, l.email, c.name AS campaign_name
       FROM lead_campaign_assignments a
       JOIN leads l ON l.id = a.lead_id
       JOIN campaigns c ON c.id = a.campaign_id
      WHERE {$where}
      ORDER BY a.assigned_at DESC
      LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($whereParams);
$assignments = $stmt->fetchAll();

render_header($icp['name'] . ' - Assigned Leads');
?>
<a href="icp_segment_detail.php?id=<?= (int) $icpId ?>" class="d-inline-flex align-items-center gap-1 text-decoration-none small text-muted mb-3">&larr; Back to "<?= e($icp['name']) ?>"</a>

<h1 class="h4 mb-1">Leads assigned via "<?= e($icp['name']) ?>"</h1>
<p class="text-muted">
  <?= number_format($total) ?> lead(s) an ICP distribution run has assigned via this segment (page <?= $page ?> of <?= $totalPages ?>).
  <?= info_icon('Only leads a distribution cron run actually assigned BECAUSE of this ICP -- not every lead sitting in one of its linked campaigns (a campaign can be linked to more than one ICP, and a lead added manually was never caused by this one). Assignments made before this tracking existed (sql/028) don\'t show here even though they exist in the campaign. See the ICP Report page for aggregate performance (pushed/delivered/bounced/opened/replied) across all your ICPs at once.') ?>
</p>

<div class="table-responsive card mb-3">
  <table class="table table-sm mb-0 align-middle">
    <thead>
      <tr><th>Company</th><th>Contact</th><th>Email</th><th>Campaign</th><th>Wave</th><th>Assigned</th><th>Imported</th><th>Email Sent</th><th>Delivery Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($assignments as $a): ?>
      <tr>
        <td><?= e($a['na_company_name']) ?></td>
        <td><a href="lead_view.php?id=<?= (int) $a['lead_id'] ?>"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></a></td>
        <td><?= e($a['email']) ?></td>
        <td><a href="campaign_leads.php?campaign_id=<?= (int) $a['campaign_id'] ?>"><?= e($a['campaign_name']) ?></a></td>
        <td><?php $waveBadge = ['active' => 'success', 'held' => 'warning', 'suppressed' => 'danger']; ?>
          <span class="badge bg-<?= $waveBadge[$a['wave_status']] ?>"><?= e($a['wave_status']) ?></span></td>
        <td class="small text-muted"><?= e($a['assigned_at']) ?></td>
        <td><?= in_array($a['status'], ['exported', 'pushed'], true) ? 'Yes' : 'No' ?></td>
        <td><?= $a['email_sent'] ? 'Yes (' . e($a['email_sent_at'] ?? '') . ')' : 'No' ?></td>
        <td><?= e($a['delivery_status'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$assignments): ?>
      <tr><td colspan="9" class="text-center text-muted py-4">No leads have been assigned via this ICP yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
<nav>
  <ul class="pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link" href="icp_assigned_leads.php?icp_id=<?= (int) $icpId ?>&page=<?= $p ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php render_footer(); ?>
