<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/EmailReportRepository.php';
require_once __DIR__ . '/../app/includes/SmtpMailer.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

$smtpStmt = db()->prepare('SELECT smtp_password IS NOT NULL AS connected FROM users WHERE id = ?');
$smtpStmt->execute([$user['id']]);
$smtpConnected = (bool) $smtpStmt->fetchColumn();

/** @return string[] emails, deduplicated, invalid entries dropped */
function parse_recipients(string $raw): array
{
    $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];
    $valid = array_filter($parts, static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false);
    return array_values(array_unique($valid));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = $_POST['do'] ?? '';

    if ($do === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $campaignIds = array_map('intval', $_POST['campaign_ids'] ?? []);
        $metrics = array_values(array_intersect($_POST['metrics'] ?? [], array_keys(EmailReportRepository::METRICS)));

        if ($name === '' || !$campaignIds || !$metrics) {
            flash_set('danger', 'Please give the report a name and select at least one campaign and one metric.');
            header('Location: email_reports.php?' . ($id ? "edit={$id}" : 'new=1'));
            exit;
        }

        if ($id) {
            $ok = EmailReportRepository::update(db(), $scope, $id, $name, $campaignIds, $metrics);
            flash_set($ok ? 'success' : 'danger', $ok ? 'Report saved.' : 'Report not found.');
        } else {
            $id = EmailReportRepository::create(db(), $scope, $name, $campaignIds, $metrics);
            flash_set('success', 'Report created.');
        }
        header('Location: email_reports.php?edit=' . $id);
        exit;
    }

    if ($do === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $ok = EmailReportRepository::delete(db(), $scope, $id);
        flash_set($ok ? 'success' : 'danger', $ok ? 'Report deleted.' : 'Report not found.');
        header('Location: email_reports.php');
        exit;
    }

    if ($do === 'send') {
        $id = (int) ($_POST['id'] ?? 0);
        $report = EmailReportRepository::loadVisible(db(), $scope, $id);
        $recipients = parse_recipients((string) ($_POST['recipients'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));

        if (!$report) {
            flash_set('danger', 'Report not found.');
        } elseif (!$smtpConnected) {
            flash_set('danger', 'Connect an email account first.');
        } elseif (!$recipients) {
            flash_set('danger', 'Enter at least one valid recipient email address.');
        } elseif ($subject === '') {
            flash_set('danger', 'Enter a subject.');
        } else {
            $rows = EmailReportRepository::campaignMetrics(db(), $scope, $report['campaign_ids']);
            $html = EmailReportRepository::composeHtml($rows, $report['metrics'], $subject);
            try {
                SmtpMailer::forUser(db(), $user['id'])->send($recipients, $subject, $html);
                flash_set('success', 'Report sent to ' . implode(', ', $recipients) . '.');
            } catch (SmtpException $ex) {
                flash_set('danger', 'Could not send: ' . $ex->getMessage());
            }
        }
        header('Location: email_reports.php');
        exit;
    }

    if ($do === 'preview') {
        // Falls through to the GET-rendering branch below with $_POST's
        // selections used in place of a saved report -- lets the builder
        // form show a live preview before the report is ever saved.
    } else {
        header('Location: email_reports.php');
        exit;
    }
}

$campaignClauses = ['c.company_id = :scope_company_id', 'c.saleshandy_sequence_id IS NOT NULL'];
$campaignParams = ['scope_company_id' => $scope->companyId];
ScopeFilter::applyOwnerScope($campaignClauses, $campaignParams, $scope, db(), 'c', 'saleshandy_account_owner_id');
$campaignsStmt = db()->prepare('SELECT c.id, c.name FROM campaigns c WHERE ' . implode(' AND ', $campaignClauses) . ' ORDER BY c.name');
$campaignsStmt->execute($campaignParams);
$campaignOptions = $campaignsStmt->fetchAll();

$editId = (int) ($_GET['edit'] ?? 0);
$isBuilder = $editId > 0 || isset($_GET['new']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'preview');

if ($isBuilder) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form = [
            'id' => (int) ($_POST['id'] ?? 0),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'campaign_ids' => array_map('intval', $_POST['campaign_ids'] ?? []),
            'metrics' => array_values(array_intersect($_POST['metrics'] ?? [], array_keys(EmailReportRepository::METRICS))),
        ];
    } elseif ($editId) {
        $existing = EmailReportRepository::loadVisible(db(), $scope, $editId);
        if (!$existing) {
            flash_set('danger', 'Report not found.');
            header('Location: email_reports.php');
            exit;
        }
        $form = ['id' => $editId, 'name' => $existing['name'], 'campaign_ids' => $existing['campaign_ids'], 'metrics' => $existing['metrics']];
    } else {
        $form = ['id' => 0, 'name' => '', 'campaign_ids' => [], 'metrics' => ['prospects', 'contacted', 'open_rate']];
    }

    $hasSelection = (bool) ($form['campaign_ids'] && $form['metrics']);
    $previewRows = $hasSelection ? EmailReportRepository::campaignMetrics(db(), $scope, $form['campaign_ids']) : [];
    $previewHtml = $hasSelection ? EmailReportRepository::composeHtml($previewRows, $form['metrics'], $form['name'] ?: 'Preview') : null;

    render_header($form['id'] ? 'Edit Email Report' : 'New Email Report');
    ?>
    <h1 class="h4 mb-1"><?= $form['id'] ? 'Edit' : 'New' ?> Email Report</h1>
    <p class="text-muted">Pick the campaigns and metric columns this report should include -- not every campaign or column is required.</p>
    <a href="email_reports.php" class="btn btn-sm btn-outline-secondary mb-3">&larr; Back to reports</a>

    <form method="post" action="email_reports.php" class="card mb-4">
      <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
        <div class="mb-3">
          <label class="form-label small mb-0">Report name</label>
          <input type="text" name="name" class="form-control" style="max-width: 420px;" value="<?= e($form['name']) ?>" placeholder="e.g. Weekly digest for leadership" required>
        </div>

        <div class="mb-3">
          <label class="form-label small mb-1 d-block">Campaigns</label>
          <div class="border rounded p-2" style="max-height: 220px; overflow-y: auto; max-width: 480px;">
            <?php foreach ($campaignOptions as $c): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="campaign_ids[]" value="<?= (int) $c['id'] ?>" id="camp_<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $form['campaign_ids'], true) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="camp_<?= (int) $c['id'] ?>"><?= e($c['name']) ?></label>
              </div>
            <?php endforeach; ?>
            <?php if (!$campaignOptions): ?>
              <div class="text-muted small">No Saleshandy-linked campaigns visible to you yet.</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label small mb-1 d-block">Metrics</label>
          <div class="d-flex flex-wrap gap-3">
            <?php foreach (EmailReportRepository::METRICS as $key => $label): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="metrics[]" value="<?= e($key) ?>" id="metric_<?= e($key) ?>" <?= in_array($key, $form['metrics'], true) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="metric_<?= e($key) ?>"><?= e($label) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <button type="submit" name="do" value="preview" class="btn btn-sm btn-outline-secondary">Preview</button>
        <button type="submit" name="do" value="save" class="btn btn-sm btn-primary">Save report</button>
      </div>
    </form>

    <?php if ($previewHtml !== null): ?>
      <div class="card mb-4">
        <div class="card-header">Preview</div>
        <div class="card-body table-responsive"><?= $previewHtml ?></div>
      </div>
    <?php else: ?>
      <div class="alert alert-info">Select at least one campaign and one metric, then click Preview.</div>
    <?php endif; ?>
    <?php
    render_footer();
    exit;
}

$reports = EmailReportRepository::listForUser(db(), $scope);

render_header('Email Reports');
?>
<h1 class="h4 mb-1">Email Reports</h1>
<p class="text-muted">Build a custom campaign report -- pick which campaigns and metrics matter -- and send it by email through your own connected mailbox.</p>

<?php if (!$smtpConnected): ?>
<div class="alert alert-warning">
  You haven't connected an email account yet, so reports can be built but not sent. <a href="connect_email.php">Connect one here</a>.
</div>
<?php endif; ?>

<a href="email_reports.php?new=1" class="btn btn-primary btn-sm mb-3">+ New report</a>

<div class="table-responsive card mb-3">
  <table class="table table-hover mb-0 align-middle">
    <thead>
      <tr><th>Name</th><th>Campaigns</th><th>Metrics</th><th>Created by</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($reports as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td class="small text-muted"><?= count($r['campaign_ids']) ?></td>
          <td class="small text-muted"><?= e(implode(', ', array_map(static fn (string $k) => EmailReportRepository::METRICS[$k] ?? $k, $r['metrics']))) ?></td>
          <td class="small text-muted"><?= e($r['created_by_name'] ?? '') ?></td>
          <td class="text-end">
            <a href="email_reports.php?edit=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sendModal<?= (int) $r['id'] ?>" <?= $smtpConnected ? '' : 'disabled' ?>>Send</button>
            <form method="post" action="email_reports.php" class="d-inline" onsubmit="return confirm('Delete this report definition?');">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
          </td>
        </tr>

        <div class="modal fade" id="sendModal<?= (int) $r['id'] ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog">
            <form method="post" action="email_reports.php" class="modal-content">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="send">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <div class="modal-header">
                <h5 class="modal-title">Send "<?= e($r['name']) ?>"</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="mb-2">
                  <label class="form-label small mb-0">Recipients</label>
                  <textarea name="recipients" class="form-control form-control-sm" rows="2" placeholder="one@example.com, two@example.com" required></textarea>
                </div>
                <div class="mb-2">
                  <label class="form-label small mb-0">Subject</label>
                  <input type="text" name="subject" class="form-control form-control-sm" value="<?= e($r['name']) ?>" required>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Send now</button>
              </div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$reports): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No saved reports yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
