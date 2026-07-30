<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';
require_once __DIR__ . '/../app/includes/SaleshandyCampaignFetcher.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$owners = SaleshandyCampaignFetcher::browsableOwners(db(), $scope);
$ownersById = array_column($owners, null, 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $ownerId = (int) ($_POST['owner_id'] ?? 0);
    if (!isset($ownersById[$ownerId])) {
        flash_set('danger', 'Pick a connected Saleshandy account to import from.');
        header('Location: campaign_saleshandy_fetch.php');
        exit;
    }

    $selectedIds = array_values(array_unique(array_filter(array_map('trim', (array) ($_POST['sequence_ids'] ?? [])))));
    if (!$selectedIds) {
        flash_set('danger', 'No sequences were checked.');
        header('Location: campaign_saleshandy_fetch.php?owner_id=' . $ownerId);
        exit;
    }

    try {
        $client = SaleshandyClient::forUser(db(), $ownerId);
        // Re-fetch rather than trusting titles posted from the form --
        // the campaign name written to the database always reflects
        // what's actually in Saleshandy right now, not whatever a
        // tampered request claimed.
        $sequencesById = array_column($client->listSequences(), null, 'id');
    } catch (SaleshandyApiException $ex) {
        flash_set('danger', 'Could not reach Saleshandy: ' . $ex->getMessage());
        header('Location: campaign_saleshandy_fetch.php?owner_id=' . $ownerId);
        exit;
    }

    $imported = 0;
    $failed = [];
    foreach ($selectedIds as $sequenceId) {
        if (!isset($sequencesById[$sequenceId])) {
            continue; // no longer exists / not actually this owner's -- silently skip
        }
        $result = SaleshandyCampaignFetcher::importSequence(
            db(), $client, $sequenceId, $sequencesById[$sequenceId]['title'],
            $scope->companyId, $ownerId, $user['id']
        );
        if ($result['ok']) {
            $imported++;
        } else {
            $failed[] = $result['message'];
        }
    }

    if ($imported > 0) {
        flash_set('success', "{$imported} campaign(s) imported from Saleshandy.");
    }
    if ($failed) {
        flash_set('danger', implode(' ', $failed));
    }

    header('Location: campaigns.php');
    exit;
}

if (!$owners) {
    render_header('Fetch from Saleshandy');
    ?>
    <h1 class="h4 mb-1">Fetch from Saleshandy</h1>
    <p class="text-muted"><a href="campaigns.php">&laquo; Back to campaigns</a></p>
    <div class="alert alert-info">
      You haven't connected a Saleshandy account yet -- connect one on the
      <a href="saleshandy_connect.php">Connect Saleshandy</a> page first.
    </div>
    <?php
    render_footer();
    exit;
}

$ownerId = (int) ($_GET['owner_id'] ?? 0);
if (!isset($ownersById[$ownerId])) {
    $ownerId = isset($ownersById[$user['id']]) ? $user['id'] : (int) $owners[0]['id'];
}

$hideLinked = ($_GET['hide_linked'] ?? '1') !== '0';
$statusFilter = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';

$sequences = [];
$apiError = null;
try {
    $client = SaleshandyClient::forUser(db(), $ownerId);
    $sequences = $client->listSequences();
} catch (SaleshandyApiException $ex) {
    $apiError = $ex->getMessage();
}

$linkedMap = SaleshandyCampaignFetcher::alreadyLinkedMap(db(), $scope->companyId);

$visibleSequences = array_values(array_filter($sequences, static function (array $seq) use ($linkedMap, $hideLinked, $statusFilter): bool {
    if ($hideLinked && isset($linkedMap[$seq['id']])) {
        return false;
    }
    if ($statusFilter === 'active' && !$seq['active']) {
        return false;
    }
    if ($statusFilter === 'inactive' && $seq['active']) {
        return false;
    }
    return true;
}));

render_header('Fetch from Saleshandy');
?>
<h1 class="h4 mb-1">Fetch from Saleshandy</h1>
<p class="text-muted">
  Browse sequences that already exist in a connected Saleshandy account and pull selected ones in as campaigns here,
  instead of creating a campaign by hand and separately linking it. The step is auto-picked as each sequence's first
  step (same as linking one manually) -- change it under "Change step" on the campaign afterward if needed.
  <a href="campaigns.php">&laquo; Back to campaigns</a>
</p>

<?php if ($apiError): ?>
  <div class="alert alert-danger">Could not reach Saleshandy: <?= e($apiError) ?></div>
<?php endif; ?>

<form method="get" action="campaign_saleshandy_fetch.php" class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-3 align-items-end">
    <?php if (count($owners) > 1): ?>
    <div>
      <label class="form-label small text-muted mb-1">Saleshandy account</label>
      <select name="owner_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($owners as $o): ?>
          <option value="<?= (int) $o['id'] ?>" <?= (int) $o['id'] === $ownerId ? 'selected' : '' ?>><?= e($o['name']) ?><?= (int) $o['id'] === $user['id'] ? ' (you)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php else: ?>
      <input type="hidden" name="owner_id" value="<?= (int) $ownerId ?>">
    <?php endif; ?>
    <div>
      <label class="form-label small text-muted mb-1">Status</label>
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active only</option>
        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive only</option>
      </select>
    </div>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="hide_linked" id="hideLinked" value="1" <?= $hideLinked ? 'checked' : '' ?> onchange="this.form.submit()">
      <label class="form-check-label small" for="hideLinked">Hide sequences already imported</label>
      <?php if (!$hideLinked): ?><input type="hidden" name="hide_linked" value="0"><?php endif; ?>
    </div>
  </div>
</form>

<form method="post" action="campaign_saleshandy_fetch.php">
  <?= csrf_field() ?>
  <input type="hidden" name="owner_id" value="<?= (int) $ownerId ?>">
  <div class="table-responsive card mb-3">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th style="width:5%"><input type="checkbox" onclick="document.querySelectorAll('.seq-check').forEach(c => c.checked = this.checked)"></th>
          <th>Sequence</th>
          <th>Status</th>
          <th>Local campaign</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($visibleSequences as $seq): $linkedName = $linkedMap[$seq['id']] ?? null; ?>
        <tr>
          <td>
            <?php if (!$linkedName): ?>
              <input class="form-check-input seq-check" type="checkbox" name="sequence_ids[]" value="<?= e($seq['id']) ?>">
            <?php endif; ?>
          </td>
          <td><?= e($seq['title']) ?></td>
          <td><span class="badge bg-<?= $seq['active'] ? 'success' : 'secondary' ?>"><?= $seq['active'] ? 'Active' : 'Inactive' ?></span></td>
          <td class="small text-muted"><?= $linkedName ? 'Already imported as "' . e($linkedName) . '"' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$visibleSequences && !$apiError): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">No sequences match this filter.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <button type="submit" class="btn btn-primary btn-sm">Import selected as campaigns</button>
</form>

<?php render_footer(); ?>
