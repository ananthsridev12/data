<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';
require_once __DIR__ . '/../app/includes/CampaignAccess.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$campaignId = (int) ($_GET['campaign_id'] ?? $_POST['campaign_id'] ?? 0);
$campaign = CampaignAccess::loadVisible(db(), $scope, $campaignId);

if (!$campaign) {
    flash_set('danger', 'Campaign not found.');
    header('Location: campaigns.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Viewing the flow is fine for anyone in scope (Team Lead browsing
    // their team's campaign); saving notes is a mutate action.
    if (!CampaignAccess::canMutate($scope, $campaign)) {
        flash_set('danger', 'Campaign not found.');
        header('Location: campaigns.php');
        exit;
    }

    // One combined save -- purpose[step_number] => text, for whichever
    // steps were on screen. Blank clears that step's note rather than
    // leaving a stale one behind.
    $purposes = (array) ($_POST['purpose'] ?? []);
    $upsert = db()->prepare(
        'INSERT INTO campaign_step_notes (company_id, campaign_id, step_number, purpose) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE purpose = VALUES(purpose)'
    );
    $saved = 0;
    foreach ($purposes as $stepNumber => $purpose) {
        $stepNumber = (int) $stepNumber;
        $purpose = trim((string) $purpose);
        if ($stepNumber < 1) {
            continue;
        }
        $upsert->execute([$scope->companyId, $campaignId, $stepNumber, $purpose !== '' ? $purpose : null]);
        $saved++;
    }
    flash_set('success', "Saved notes for {$saved} step(s).");
    header('Location: campaign_flow.php?campaign_id=' . $campaignId);
    exit;
}

$steps = [];
$apiError = null;
if ($campaign['saleshandy_sequence_id']) {
    try {
        $client = SaleshandyClient::forUser(db(), (int) $campaign['saleshandy_account_owner_id']);
        $steps = $client->listSequenceSteps($campaign['saleshandy_sequence_id']);
        usort($steps, static fn(array $a, array $b) => ($a['number'] ?? 0) <=> ($b['number'] ?? 0));
    } catch (SaleshandyApiException $ex) {
        $apiError = $ex->getMessage();
    }
}

$notesStmt = db()->prepare('SELECT step_number, purpose FROM campaign_step_notes WHERE campaign_id = ?');
$notesStmt->execute([$campaignId]);
$purposeByStep = array_column($notesStmt->fetchAll(), 'purpose', 'step_number');

render_header('Campaign flow');
?>
<h1 class="h4 mb-1">Flow for "<?= e($campaign['name']) ?>"</h1>
<p class="text-muted">
  <a href="campaigns.php">&laquo; Back to campaigns</a> --
  <a href="campaign_leads.php?campaign_id=<?= (int) $campaignId ?>">Manage leads</a>
</p>

<?php if (!$campaign['saleshandy_sequence_id']): ?>
  <div class="alert alert-warning">
    This campaign isn't linked to a Saleshandy sequence yet -- <a href="campaign_saleshandy_settings.php?campaign_id=<?= (int) $campaignId ?>">link one first</a> to pull in its real steps.
  </div>
<?php elseif ($apiError): ?>
  <div class="alert alert-danger">Could not reach Saleshandy: <?= e($apiError) ?></div>
<?php elseif (!$steps): ?>
  <div class="alert alert-info">This sequence has no steps yet.</div>
<?php else: ?>
  <p class="text-muted small">
    Real step structure (number, type, timing) is pulled live from Saleshandy -- Purpose is your own note on what
    each step is for (e.g. "Pain point", "Tool intro"); Saleshandy has no such field, this is saved here only.
  </p>
  <form method="post" action="campaign_flow.php">
    <?= csrf_field() ?>
    <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
    <div class="d-flex flex-wrap align-items-stretch gap-2 mb-3">
      <?php foreach ($steps as $i => $s): $number = (int) ($s['number'] ?? ($i + 1)); ?>
        <?php if ($i > 0): ?>
          <div class="d-flex align-items-center text-muted">&rarr;</div>
        <?php endif; ?>
        <div class="card" style="width: 200px;">
          <div class="card-header py-1 text-center">
            <strong>T<?= $number ?></strong>
          </div>
          <div class="card-body p-2">
            <div class="small text-muted mb-1">
              <?= e($s['type'] ?? '') ?><?php if (isset($s['relativeDays'])): ?> &middot; day <?= (int) $s['relativeDays'] ?><?php endif; ?>
              <?php if (!empty($s['status'])): ?><br>Status: <?= e($s['status']) ?><?php endif; ?>
            </div>
            <input
              type="text"
              name="purpose[<?= $number ?>]"
              class="form-control form-control-sm"
              placeholder="What's this step for?"
              value="<?= e($purposeByStep[$number] ?? '') ?>"
            >
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-sm btn-primary">Save notes</button>
  </form>
<?php endif; ?>

<?php render_footer(); ?>
