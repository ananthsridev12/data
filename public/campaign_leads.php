<?php
require_once __DIR__ . '/bootstrap.php';

$user = require_login();

$campaignId = (int) ($_GET['campaign_id'] ?? 0);
$campStmt = db()->prepare('SELECT * FROM campaigns WHERE id = ?');
$campStmt->execute([$campaignId]);
$campaign = $campStmt->fetch();

if (!$campaign) {
    flash_set('danger', 'Campaign not found.');
    header('Location: campaigns.php');
    exit;
}

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

$countStmt = db()->prepare('SELECT COUNT(*) FROM lead_campaign_assignments WHERE campaign_id = ?');
$countStmt->execute([$campaignId]);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT a.*, l.na_company_name, l.first_name, l.last_name, l.email
       FROM lead_campaign_assignments a
       JOIN leads l ON l.id = a.lead_id
      WHERE a.campaign_id = :campaign_id
      ORDER BY a.assigned_at DESC
      LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute(['campaign_id' => $campaignId]);
$assignments = $stmt->fetchAll();

$waveStmt = db()->prepare(
    "SELECT a.id AS leader_assignment_id, a.bounce_status, a.assigned_at,
            l.na_company_name, l.first_name, l.last_name, l.email,
            (SELECT COUNT(*) FROM lead_campaign_assignments h WHERE h.wave_leader_id = a.id) AS held_count,
            (SELECT COUNT(*) FROM lead_campaign_assignments h WHERE h.wave_leader_id = a.id AND h.wave_status = 'held') AS still_held
       FROM lead_campaign_assignments a
       JOIN leads l ON l.id = a.lead_id
      WHERE a.campaign_id = :campaign_id
        AND EXISTS (SELECT 1 FROM lead_campaign_assignments h2 WHERE h2.wave_leader_id = a.id)
      ORDER BY a.assigned_at DESC"
);
$waveStmt->execute(['campaign_id' => $campaignId]);
$waveGroups = $waveStmt->fetchAll();

render_header('Campaign leads');
?>
<h1 class="h4 mb-1">Leads assigned to "<?= e($campaign['name']) ?>"</h1>
<p class="text-muted"><?= number_format($total) ?> lead(s) assigned (page <?= $page ?> of <?= $totalPages ?>)</p>

<?php if ($waveGroups): ?>
<div class="card mb-4 border-warning">
  <div class="card-header">Wave 1 groups awaiting a delivered/bounced decision</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>Company</th><th>Wave-1 contact</th><th>Held</th><th>Outcome</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($waveGroups as $w): ?>
        <tr>
          <td><?= e($w['na_company_name']) ?></td>
          <td><?= e($w['first_name'] . ' ' . $w['last_name']) ?> (<?= e($w['email']) ?>)</td>
          <td><?= (int) $w['held_count'] ?> (<?= (int) $w['still_held'] ?> still held)</td>
          <td>
            <?php
            $bounceBadge = ['pending' => 'secondary', 'delivered' => 'success', 'bounced' => 'danger'];
            ?>
            <span class="badge bg-<?= $bounceBadge[$w['bounce_status']] ?>"><?= e($w['bounce_status']) ?></span>
          </td>
          <td>
            <?php if ($w['still_held'] > 0): ?>
            <form method="post" action="campaign_wave_update.php" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
              <input type="hidden" name="leader_assignment_id" value="<?= (int) $w['leader_assignment_id'] ?>">
              <button type="submit" name="action" value="release" class="btn btn-sm btn-outline-success">Delivered -- release held</button>
              <button type="submit" name="action" value="suppress" class="btn btn-sm btn-outline-danger" onclick="return confirm('Bounced -- suppress this domain everywhere? This affects all campaigns and future imports too.');">Bounced -- suppress domain</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<form method="post" action="campaign_assignment_update.php">
  <?= csrf_field() ?>
  <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
  <input type="hidden" name="page" value="<?= (int) $page ?>">

  <div class="table-responsive card mb-3">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAllOnPage"></th>
          <th>Company</th><th>Contact</th><th>Email</th><th>Wave</th><th>Assigned</th>
          <th>Imported to Saleshandy</th><th>Email Sent</th><th>Email Date</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($assignments as $a): ?>
        <tr>
          <td><input type="checkbox" name="assignment_ids[]" value="<?= (int) $a['id'] ?>" class="lead-checkbox"></td>
          <td><?= e($a['na_company_name']) ?></td>
          <td><?= e($a['first_name'] . ' ' . $a['last_name']) ?></td>
          <td><?= e($a['email']) ?></td>
          <td>
            <?php $waveBadge = ['active' => 'success', 'held' => 'warning', 'suppressed' => 'danger']; ?>
            <span class="badge bg-<?= $waveBadge[$a['wave_status']] ?>"><?= e($a['wave_status']) ?></span>
          </td>
          <td class="small text-muted"><?= e($a['assigned_at']) ?></td>
          <td>
            <?php if (in_array($a['status'], ['exported', 'pushed'], true)): ?>
              <span class="badge bg-success">Yes</span>
              <span class="small text-muted d-block"><?= e($a['exported_at'] ?? '') ?></span>
            <?php else: ?>
              <span class="badge bg-secondary">No</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($a['email_sent']): ?>
              <span class="badge bg-success">Yes</span>
            <?php else: ?>
              <span class="badge bg-secondary">No</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($a['email_sent_at'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$assignments): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No leads assigned to this campaign yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card mb-4">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <button type="submit" name="action" value="mark_imported" class="btn btn-sm btn-outline-primary">Mark checked as Imported to Saleshandy</button>
      <span class="text-muted small">Email sent date:</span>
      <input type="date" name="email_sent_at" class="form-control form-control-sm" style="max-width: 160px;" value="<?= e(date('Y-m-d')) ?>">
      <button type="submit" name="action" value="mark_email_sent" class="btn btn-sm btn-outline-primary">Mark checked as Email Sent</button>
    </div>
  </div>
</form>

<?php if ($totalPages > 1): ?>
<nav>
  <ul class="pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link" href="campaign_leads.php?campaign_id=<?= (int) $campaignId ?>&page=<?= $p ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<script src="assets/js/app.js"></script>
<?php render_footer(); ?>
