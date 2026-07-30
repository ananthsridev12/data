<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'connect') {
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        if ($apiKey === '') {
            flash_set('danger', 'Please paste an API key.');
        } else {
            try {
                // Validated live, on the pasted key directly -- never saved
                // until we've confirmed Saleshandy actually accepts it, so a
                // typo'd/expired key can't silently overwrite a working one.
                $testClient = new SaleshandyClient($apiKey);
                $testClient->listFields();

                $encrypted = SaleshandyKeyCipher::encrypt($apiKey);
                db()->prepare('UPDATE users SET saleshandy_api_key = ?, saleshandy_connected_at = NOW() WHERE id = ?')
                    ->execute([$encrypted, $user['id']]);
                flash_set('success', 'Saleshandy account connected -- campaigns you own will now push/sync through this account.');
            } catch (SaleshandyApiException $ex) {
                flash_set('danger', 'Could not connect: ' . $ex->getMessage());
            }
        }
    } elseif ($action === 'disconnect') {
        db()->prepare('UPDATE users SET saleshandy_api_key = NULL, saleshandy_connected_at = NULL WHERE id = ?')
            ->execute([$user['id']]);
        flash_set('success', 'Saleshandy account disconnected. Campaigns you own will stop pushing/syncing until you reconnect.');
    }

    header('Location: saleshandy_connect.php');
    exit;
}

$stmt = db()->prepare('SELECT saleshandy_api_key IS NOT NULL AS connected, saleshandy_connected_at FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$status = $stmt->fetch();

render_header('Connect Saleshandy');
?>
<h1 class="h4 mb-1">Connect Saleshandy</h1>
<p class="text-muted">
  Link your own personal Saleshandy account. Any campaign you own pushes/syncs/pulls through this key -- not
  a shared company key -- so you (and no one else) need to connect once here to make your own campaigns work.
</p>

<div class="card mb-4" style="max-width: 640px;">
  <div class="card-body">
    <?php if ($status['connected']): ?>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <span class="badge bg-success">Connected</span>
          <span class="text-muted small ms-2">since <?= e($status['saleshandy_connected_at']) ?></span>
        </div>
        <form method="post" action="saleshandy_connect.php" onsubmit="return confirm('Disconnect your Saleshandy account? Any campaign you own will stop pushing/syncing until you reconnect.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="disconnect">
          <button type="submit" class="btn btn-sm btn-outline-danger">Disconnect</button>
        </form>
      </div>
      <p class="text-muted small mb-3">To switch to a different Saleshandy account, paste its key below -- this replaces the one currently connected.</p>
    <?php else: ?>
      <div class="mb-3"><span class="badge bg-secondary">Not connected</span></div>
    <?php endif; ?>

    <form method="post" action="saleshandy_connect.php" class="d-flex gap-2 align-items-start">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="connect">
      <div class="flex-grow-1">
        <input type="password" name="api_key" class="form-control" placeholder="Paste your Saleshandy API key" autocomplete="off" required>
        <div class="form-text">Generate one under Saleshandy -> Settings -> API. Tested live before saving -- nothing is stored if it doesn't work.</div>
      </div>
      <button type="submit" class="btn btn-primary flex-shrink-0"><?= $status['connected'] ? 'Reconnect' : 'Connect' ?></button>
    </form>
  </div>
</div>
<?php render_footer(); ?>
