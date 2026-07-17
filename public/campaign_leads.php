<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';
require_once __DIR__ . '/../app/includes/ColumnPreferences.php';

$user = require_login();

$columns = ColumnPreferences::getForUser(db(), $user['id'], 'campaign_leads');

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
    "SELECT a.*, l.na_company_name, l.first_name, l.last_name, l.email, u.name AS assigned_by_name
       FROM lead_campaign_assignments a
       JOIN leads l ON l.id = a.lead_id
       JOIN users u ON u.id = a.assigned_by
      WHERE a.campaign_id = :campaign_id
      ORDER BY a.assigned_at DESC
      LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute(['campaign_id' => $campaignId]);
$assignments = $stmt->fetchAll();

$waveStmt = db()->prepare(
    "SELECT a.id AS leader_assignment_id, a.bounce_status, a.bounce_type, a.assigned_at,
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
<p class="text-muted">
  <?= number_format($total) ?> lead(s) assigned (page <?= $page ?> of <?= $totalPages ?>) --
  <a href="campaign_select_leads.php?campaign_id=<?= (int) $campaignId ?>">Add leads to this campaign</a> --
  <a href="column_settings.php?page=campaign_leads&return_to=<?= urlencode('campaign_leads.php?campaign_id=' . $campaignId) ?>">Manage columns</a>
</p>

<?php if ($campaign['saleshandy_sequence_id'] && $campaign['saleshandy_step_id']): ?>
<div class="card mb-4">
  <div class="card-body d-flex flex-wrap gap-2 align-items-center">
    <form method="post" action="campaign_saleshandy_push.php" onsubmit="return confirm('Push currently-eligible leads for this campaign to Saleshandy?');">
      <?= csrf_field() ?>
      <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
      <button type="submit" class="btn btn-sm btn-primary">Push to Saleshandy</button>
    </form>
    <form method="post" action="campaign_saleshandy_sync.php">
      <?= csrf_field() ?>
      <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
      <button type="submit" class="btn btn-sm btn-outline-primary">Refresh statuses from Saleshandy</button>
    </form>
    <form method="post" action="campaign_saleshandy_import.php" onsubmit="return confirm('Pull in any prospects from this Saleshandy sequence that aren\'t assigned to this campaign here yet? New leads will only have an email and name -- no company, title, etc.');">
      <?= csrf_field() ?>
      <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
      <button type="submit" class="btn btn-sm btn-outline-secondary">Import from Saleshandy</button>
    </form>
    <span class="text-muted small">
      Push only sends leads currently eligible under the wave-1 domain-safety gate.
      <?= $campaign['saleshandy_last_synced_at'] ? 'Last synced ' . e($campaign['saleshandy_last_synced_at']) . '.' : 'Never synced yet.' ?>
    </span>
  </div>
</div>
<?php endif; ?>

<div class="card mb-4 border-danger">
  <div class="card-header">Paste bounced emails</div>
  <div class="card-body">
    <form method="post" action="campaign_bounce_paste.php">
      <?= csrf_field() ?>
      <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
      <textarea name="emails" class="form-control form-control-sm mb-2" rows="3" placeholder="Paste bounced email addresses -- one per line, or comma/space separated"></textarea>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <select name="bounce_type" class="form-select form-select-sm" style="max-width: 220px;">
          <option value="">Bounce type (optional)</option>
          <?php foreach (WaveAssigner::BOUNCE_TYPES as $bt): ?>
            <option value="<?= e($bt) ?>"><?= e($bt) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Suppress these domains and release every other pending company in this campaign as the next batch?');">Process bounces &amp; release the rest</button>
        <span class="text-muted small">Suppresses the pasted emails' domains everywhere, then auto-releases every other still-pending company in this campaign.</span>
      </div>
    </form>
  </div>
</div>

<?php if ($waveGroups): ?>
<div class="card mb-4 border-warning">
  <div class="card-header">Wave 1 groups awaiting a delivered/bounced decision</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>Company</th><th>Wave-1 contact</th><th>Held</th><th>Outcome</th><th>Bounce type</th><th></th></tr></thead>
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
          <td class="small text-muted"><?= e($w['bounce_type'] ?? '') ?></td>
          <td>
            <?php if ($w['still_held'] > 0): ?>
            <form method="post" action="campaign_wave_update.php" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
              <input type="hidden" name="leader_assignment_id" value="<?= (int) $w['leader_assignment_id'] ?>">
              <select name="bounce_type" class="form-select form-select-sm d-inline-block mb-1" style="max-width: 160px;">
                <option value="">Bounce type...</option>
                <?php foreach (WaveAssigner::BOUNCE_TYPES as $bt): ?>
                  <option value="<?= e($bt) ?>"><?= e($bt) ?></option>
                <?php endforeach; ?>
              </select><br>
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

  <?php
  $renderAssignmentCell = static function (string $key, array $a) {
      switch ($key) {
          case 'company': ?><td><?= e($a['na_company_name']) ?></td><?php break;
          case 'contact': ?><td><?= e($a['first_name'] . ' ' . $a['last_name']) ?></td><?php break;
          case 'email': ?><td><?= e($a['email']) ?></td><?php break;
          case 'wave': ?>
              <td>
                <?php $waveBadge = ['active' => 'success', 'held' => 'warning', 'suppressed' => 'danger']; ?>
                <span class="badge bg-<?= $waveBadge[$a['wave_status']] ?>"><?= e($a['wave_status']) ?></span>
              </td>
              <?php break;
          case 'assigned': ?><td class="small text-muted"><?= e($a['assigned_at']) ?> by <?= e($a['assigned_by_name']) ?></td><?php break;
          case 'imported': ?>
              <td>
                <?php if (in_array($a['status'], ['exported', 'pushed'], true)): ?>
                  <span class="badge bg-success">Yes</span>
                  <span class="small text-muted d-block"><?= e($a['exported_at'] ?? '') ?></span>
                <?php else: ?>
                  <span class="badge bg-secondary">No</span>
                <?php endif; ?>
              </td>
              <?php break;
          case 'email_sent': ?>
              <td>
                <?php if ($a['email_sent']): ?>
                  <span class="badge bg-success">Yes</span>
                <?php else: ?>
                  <span class="badge bg-secondary">No</span>
                <?php endif; ?>
              </td>
              <?php break;
          case 'email_date': ?><td class="small"><?= e($a['email_sent_at'] ?? '') ?></td><?php break;
          case 'delivery_status': ?>
              <td>
                <?php if ($a['delivery_status']):
                  $deliveryBadge = in_array($a['delivery_status'], DELIVERY_STATUS_BOUNCE_VALUES, true) ? 'danger'
                      : ($a['delivery_status'] === 'Active' ? 'success' : ($a['delivery_status'] === 'Replied' ? 'primary' : ($a['delivery_status'] === 'Paused' ? 'warning' : 'secondary')));
                ?>
                  <span class="badge bg-<?= $deliveryBadge ?>"><?= e($a['delivery_status']) ?></span>
                <?php else: ?>
                  <span class="text-muted small">--</span>
                <?php endif; ?>
              </td>
              <?php break;
      }
  };
  $visibleAssignmentColumns = array_values(array_filter($columns, static fn(array $c) => $c['visible']));
  ?>
  <div class="table-responsive card mb-3">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAllOnPage"></th>
          <?php foreach ($visibleAssignmentColumns as $col): ?>
            <th><?= e($col['label']) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($assignments as $a): ?>
        <tr>
          <td><input type="checkbox" name="assignment_ids[]" value="<?= (int) $a['id'] ?>" class="lead-checkbox"></td>
          <?php foreach ($visibleAssignmentColumns as $col): ?>
            <?php $renderAssignmentCell($col['key'], $a); ?>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$assignments): ?>
        <tr><td colspan="<?= count($visibleAssignmentColumns) + 1 ?>" class="text-center text-muted py-4">No leads assigned to this campaign yet.</td></tr>
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

  <div class="card mb-4">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted small">Delivery status:</span>
      <select name="delivery_status" id="deliveryStatusSelect" class="form-select form-select-sm" style="max-width: 180px;">
        <option value="">-- choose --</option>
        <?php foreach (DELIVERY_STATUSES as $ds): ?>
          <option value="<?= e($ds) ?>" data-bounce="<?= in_array($ds, DELIVERY_STATUS_BOUNCE_VALUES, true) ? '1' : '0' ?>"><?= e($ds) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" name="action" value="set_delivery_status" class="btn btn-sm btn-outline-primary" id="setDeliveryStatusBtn">Apply to checked</button>
      <span class="text-muted small">A bounced status also suppresses that lead's domain everywhere, same as Bounce Import.</span>
    </div>
  </div>
</form>
<script>
  document.getElementById('setDeliveryStatusBtn').addEventListener('click', function (e) {
    var sel = document.getElementById('deliveryStatusSelect');
    var opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.bounce === '1') {
      if (!confirm('This will suppress the domain(s) of every checked lead everywhere (all campaigns and future imports). Continue?')) {
        e.preventDefault();
      }
    }
  });
</script>

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
