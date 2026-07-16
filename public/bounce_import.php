<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';

$admin = require_admin();

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (($_POST['action'] ?? '') === 'unsuppress') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM suppressed_domains WHERE id = ?')->execute([$id]);
        flash_set('success', 'Domain removed from the suppression list.');
        header('Location: bounce_import.php');
        exit;
    }

    if (empty($_FILES['bounce_file']) || $_FILES['bounce_file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('danger', 'Please choose a CSV file exported from Saleshandy\'s bounce report.');
        header('Location: bounce_import.php');
        exit;
    }

    $tmpPath = $_FILES['bounce_file']['tmp_name'];
    $handle = fopen($tmpPath, 'r');
    if ($handle === false) {
        flash_set('danger', 'Could not read the uploaded file.');
        header('Location: bounce_import.php');
        exit;
    }

    $header = fgetcsv($handle);
    $emailCol = null;
    if ($header !== false) {
        foreach ($header as $i => $col) {
            if (strtolower(trim((string) $col)) === 'email') {
                $emailCol = $i;
                break;
            }
        }
        // Single-column file with no recognizable "Email" header: treat
        // column 0 as the email list (and don't discard row 1 as a header
        // unless it actually looks like one).
        if ($emailCol === null && count($header) === 1) {
            $emailCol = 0;
            if (filter_var($header[0], FILTER_VALIDATE_EMAIL)) {
                rewind($handle);
            }
        }
    }

    $processed = 0;
    $suppressedDomains = [];
    $cascaded = 0;
    $skipped = 0;

    if ($emailCol === null) {
        flash_set('danger', 'Could not find an "Email" column in that file.');
    } else {
        while (($row = fgetcsv($handle)) !== false) {
            $email = strtolower(trim((string) ($row[$emailCol] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }
            $result = WaveAssigner::suppressByEmail(db(), $email, $admin['id'], 'Bounce report import');
            $suppressedDomains[$result['domain']] = true;
            $cascaded += $result['cascaded'];
            $processed++;
        }

        $results = [
            'processed' => $processed,
            'domains' => count($suppressedDomains),
            'cascaded' => $cascaded,
            'skipped' => $skipped,
        ];
        flash_set(
            'success',
            "{$processed} bounced email(s) processed -- " . count($suppressedDomains) . ' domain(s) suppressed, '
                . "{$cascaded} held lead(s) cascade-suppressed." . ($skipped > 0 ? " {$skipped} row(s) skipped (not a valid email)." : '')
        );
    }

    fclose($handle);
    header('Location: bounce_import.php');
    exit;
}

$suppressedDomains = db()->query(
    'SELECT sd.*, u.name AS suppressed_by_name FROM suppressed_domains sd JOIN users u ON u.id = sd.suppressed_by ORDER BY sd.suppressed_at DESC LIMIT 200'
)->fetchAll();

render_header('Bounce import');
?>
<h1 class="h4 mb-3">Bounce report import</h1>
<p class="text-muted">Upload the bounce export from Saleshandy (a CSV with an "Email" column, or a single-column list of bounced addresses). Every email in the file is treated as bounced: its domain is added to the global suppression list, and if it was a pending wave-1 contact, the rest of its held group is suppressed too.</p>

<div class="card mb-4">
  <div class="card-body">
    <form method="post" action="bounce_import.php" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
      <?= csrf_field() ?>
      <input type="file" name="bounce_file" class="form-control form-control-sm" accept=".csv" required style="max-width: 320px;">
      <button type="submit" class="btn btn-primary btn-sm">Process bounces</button>
    </form>
  </div>
</div>

<h2 class="h6">Suppressed domains (<?= count($suppressedDomains) ?> shown, most recent first)</h2>
<div class="table-responsive card">
  <table class="table table-sm mb-0">
    <thead><tr><th>Domain</th><th>Reason</th><th>Suppressed by</th><th>Date</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($suppressedDomains as $d): ?>
      <tr>
        <td><code><?= e($d['domain']) ?></code></td>
        <td><?= e($d['reason'] ?? '') ?></td>
        <td><?= e($d['suppressed_by_name']) ?></td>
        <td class="small text-muted"><?= e($d['suppressed_at']) ?></td>
        <td>
          <form method="post" action="bounce_import.php" onsubmit="return confirm('Remove <?= e($d['domain']) ?> from the suppression list?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unsuppress">
            <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Un-suppress</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$suppressedDomains): ?>
      <tr><td colspan="5" class="text-center text-muted py-3">No domains suppressed yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
