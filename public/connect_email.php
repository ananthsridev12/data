<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SmtpMailer.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'connect') {
        $host = trim((string) ($_POST['smtp_host'] ?? ''));
        $port = (int) ($_POST['smtp_port'] ?? 0);
        $encryption = (string) ($_POST['smtp_encryption'] ?? 'tls');
        $username = trim((string) ($_POST['smtp_username'] ?? ''));
        $password = (string) ($_POST['smtp_password'] ?? '');
        $fromEmail = trim((string) ($_POST['smtp_from_email'] ?? ''));
        $fromName = trim((string) ($_POST['smtp_from_name'] ?? ''));

        if (!in_array($encryption, ['none', 'ssl', 'tls'], true)) {
            $encryption = 'tls';
        }

        if ($host === '' || $port <= 0 || $fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'Please fill in host, port, and a valid "from" email address.');
        } else {
            // Same "test live before saving" pattern as saleshandy_connect.php --
            // authenticates against the real server before anything is
            // written, so a typo'd host/password can't silently overwrite
            // a working connection.
            $testMailer = new SmtpMailer($host, $port, $encryption, $username, $password, $fromEmail, $fromName);
            try {
                $testMailer->testConnection();

                $encrypted = EmailAccountCipher::encrypt($password);
                db()->prepare(
                    'UPDATE users SET smtp_host = ?, smtp_port = ?, smtp_encryption = ?, smtp_username = ?,
                        smtp_password = ?, smtp_from_email = ?, smtp_from_name = ?, smtp_connected_at = NOW()
                      WHERE id = ?'
                )->execute([$host, $port, $encryption, $username, $encrypted, $fromEmail, $fromName, $user['id']]);
                flash_set('success', 'Email account connected -- you can now send reports through it.');
            } catch (SmtpException $ex) {
                flash_set('danger', 'Could not connect: ' . $ex->getMessage());
            }
        }
    } elseif ($action === 'disconnect') {
        db()->prepare(
            'UPDATE users SET smtp_host = NULL, smtp_port = NULL, smtp_encryption = \'tls\', smtp_username = NULL,
                smtp_password = NULL, smtp_from_email = NULL, smtp_from_name = NULL, smtp_connected_at = NULL
              WHERE id = ?'
        )->execute([$user['id']]);
        flash_set('success', 'Email account disconnected. You will not be able to send reports until you reconnect.');
    }

    header('Location: connect_email.php');
    exit;
}

$stmt = db()->prepare(
    'SELECT smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_from_email, smtp_from_name, smtp_connected_at
       FROM users WHERE id = ?'
);
$stmt->execute([$user['id']]);
$status = $stmt->fetch();
$connected = $status['smtp_connected_at'] !== null;

render_header('Connect Email');
?>
<h1 class="h4 mb-1">Connect Email</h1>
<p class="text-muted">
  Connect your own SMTP mailbox so reports built on the <a href="email_reports.php">Email Reports</a> page send
  through your own account -- not a shared company mailbox. Tested live before saving; nothing is stored if it
  doesn't work.
</p>

<div class="card mb-4" style="max-width: 640px;">
  <div class="card-body">
    <?php if ($connected): ?>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <span class="badge bg-success">Connected</span>
          <span class="text-muted small ms-2"><?= e($status['smtp_username'] ?: $status['smtp_from_email']) ?> since <?= e($status['smtp_connected_at']) ?></span>
        </div>
        <form method="post" action="connect_email.php" onsubmit="return confirm('Disconnect your email account? You will not be able to send reports until you reconnect.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="disconnect">
          <button type="submit" class="btn btn-sm btn-outline-danger">Disconnect</button>
        </form>
      </div>
      <p class="text-muted small mb-3">To switch accounts or fix a setting, fill in the form below -- this replaces the connection currently in place.</p>
    <?php else: ?>
      <div class="mb-3"><span class="badge bg-secondary">Not connected</span></div>
    <?php endif; ?>

    <form method="post" action="connect_email.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="connect">
      <div class="row g-2">
        <div class="col-md-8">
          <label class="form-label small mb-0">SMTP host</label>
          <input type="text" name="smtp_host" class="form-control form-control-sm" placeholder="smtp.gmail.com" value="<?= e($status['smtp_host'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label small mb-0">Port</label>
          <input type="number" name="smtp_port" class="form-control form-control-sm" placeholder="587" value="<?= e((string) ($status['smtp_port'] ?? '587')) ?>" min="1" max="65535" required>
        </div>
        <div class="col-md-4">
          <label class="form-label small mb-0">Encryption</label>
          <select name="smtp_encryption" class="form-select form-select-sm">
            <?php $enc = $status['smtp_encryption'] ?? 'tls'; ?>
            <option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
            <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>>SSL/TLS (465)</option>
            <option value="none" <?= $enc === 'none' ? 'selected' : '' ?>>None</option>
          </select>
        </div>
        <div class="col-md-8"></div>
        <div class="col-md-6">
          <label class="form-label small mb-0">Username</label>
          <input type="text" name="smtp_username" class="form-control form-control-sm" autocomplete="off" value="<?= e($status['smtp_username'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label small mb-0">Password</label>
          <input type="password" name="smtp_password" class="form-control form-control-sm" autocomplete="off" required>
          <div class="form-text">Always re-entered, never pre-filled -- tested live against the server on every save, and never shown back to you.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label small mb-0">From email</label>
          <input type="email" name="smtp_from_email" class="form-control form-control-sm" value="<?= e($status['smtp_from_email'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label small mb-0">From name</label>
          <input type="text" name="smtp_from_name" class="form-control form-control-sm" value="<?= e($status['smtp_from_name'] ?? '') ?>" placeholder="e.g. your own name">
        </div>
      </div>
      <div class="form-text mb-2">Most providers (Gmail, Outlook/Microsoft 365, etc.) require an app-specific password, not your regular login password.</div>
      <button type="submit" class="btn btn-primary mt-2"><?= $connected ? 'Reconnect' : 'Connect' ?></button>
    </form>
  </div>
</div>
<?php render_footer(); ?>
