<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

$admin = require_admin();

$campaignId = (int) ($_GET['campaign_id'] ?? $_POST['campaign_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM campaigns WHERE id = ?');
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch();

if (!$campaign) {
    flash_set('danger', 'Campaign not found.');
    header('Location: campaigns.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_sequence') {
        $sequenceId = trim((string) ($_POST['sequence_id'] ?? ''));
        $sequenceName = trim((string) ($_POST['sequence_name'] ?? ''));
        if ($sequenceId === '') {
            flash_set('danger', 'Please choose a sequence.');
        } else {
            db()->prepare('UPDATE campaigns SET saleshandy_sequence_id = ?, saleshandy_step_id = NULL WHERE id = ?')
                ->execute([$sequenceId, $campaignId]);
            flash_set('success', "Linked to Saleshandy sequence \"{$sequenceName}\" -- now choose a step.");
        }
    } elseif ($action === 'set_step') {
        $stepId = trim((string) ($_POST['step_id'] ?? ''));
        if ($stepId === '') {
            flash_set('danger', 'Please choose a step.');
        } else {
            db()->prepare('UPDATE campaigns SET saleshandy_step_id = ? WHERE id = ?')->execute([$stepId, $campaignId]);
            flash_set('success', 'Step saved -- this campaign is ready to push to Saleshandy.');
        }
    } elseif ($action === 'unlink') {
        db()->prepare('UPDATE campaigns SET saleshandy_sequence_id = NULL, saleshandy_step_id = NULL WHERE id = ?')->execute([$campaignId]);
        flash_set('success', 'Unlinked from Saleshandy.');
    }

    header('Location: campaign_saleshandy_settings.php?campaign_id=' . $campaignId);
    exit;
}

$sequences = [];
$steps = [];
$apiError = null;
$config = require __DIR__ . '/../app/config/config.php';

try {
    $client = SaleshandyClient::fromConfig($config);
    if (!$campaign['saleshandy_sequence_id']) {
        $sequences = $client->listSequences();
    } elseif (!$campaign['saleshandy_step_id']) {
        $steps = $client->listSequenceSteps($campaign['saleshandy_sequence_id']);
    }
} catch (SaleshandyApiException $ex) {
    $apiError = $ex->getMessage();
}

render_header('Saleshandy settings');
?>
<h1 class="h4 mb-1">Saleshandy settings for "<?= e($campaign['name']) ?>"</h1>
<p class="text-muted"><a href="campaigns.php">&laquo; Back to campaigns</a></p>

<?php if ($apiError): ?>
  <div class="alert alert-danger">Could not reach Saleshandy: <?= e($apiError) ?> -- check <code>saleshandy.api_key</code> in <code>app/config/config.php</code> (see README-DEPLOY.md).</div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <p>
      Sequence: <strong><?= e($campaign['saleshandy_sequence_id'] ?? 'not linked') ?></strong><br>
      Step: <strong><?= e($campaign['saleshandy_step_id'] ?? 'not chosen') ?></strong>
    </p>
    <?php if ($campaign['saleshandy_sequence_id']): ?>
      <form method="post" action="campaign_saleshandy_settings.php" onsubmit="return confirm('Unlink this campaign from Saleshandy?');">
        <?= csrf_field() ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
        <input type="hidden" name="action" value="unlink">
        <button type="submit" class="btn btn-sm btn-outline-danger">Unlink</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$apiError && !$campaign['saleshandy_sequence_id'] && $sequences): ?>
<div class="card mb-3">
  <div class="card-header">Step 1: choose a sequence</div>
  <div class="card-body">
    <form method="post" action="campaign_saleshandy_settings.php" class="d-flex gap-2 align-items-center">
      <?= csrf_field() ?>
      <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
      <input type="hidden" name="action" value="set_sequence">
      <select name="sequence_id" id="sequenceSelect" class="form-select form-select-sm" style="max-width: 360px;" onchange="document.getElementById('sequenceNameInput').value = this.options[this.selectedIndex].dataset.name;" required>
        <option value="">-- choose a sequence --</option>
        <?php foreach ($sequences as $seq): ?>
          <option value="<?= e($seq['id']) ?>" data-name="<?= e($seq['title']) ?>" <?= $seq['title'] === $campaign['name'] ? 'selected' : '' ?>><?= e($seq['title']) ?><?= $seq['active'] ? '' : ' (inactive)' ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="sequence_name" id="sequenceNameInput" value="">
      <button type="submit" class="btn btn-primary btn-sm">Save</button>
    </form>
  </div>
</div>
<?php elseif (!$apiError && $campaign['saleshandy_sequence_id'] && !$campaign['saleshandy_step_id'] && $steps): ?>
<div class="card mb-3">
  <div class="card-header">Step 2: choose a step</div>
  <div class="card-body">
    <form method="post" action="campaign_saleshandy_settings.php" class="d-flex gap-2 align-items-center">
      <?= csrf_field() ?>
      <input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>">
      <input type="hidden" name="action" value="set_step">
      <select name="step_id" class="form-select form-select-sm" style="max-width: 360px;" required>
        <option value="">-- choose a step --</option>
        <?php foreach ($steps as $s): ?>
          <option value="<?= e($s['id']) ?>">Step <?= (int) ($s['number'] ?? 0) ?> -- <?= e($s['type'] ?? '') ?><?= !empty($s['status']) ? ' (' . e($s['status']) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Save</button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
