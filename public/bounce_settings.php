<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'release') {
        // Admin-only: touches suppressed_domains/wave_status account-wide,
        // across every campaign, not just ones this user owns.
        if (!$scope->isAdmin()) {
            flash_set('danger', 'Only an admin can release previously-suppressed prospects.');
        } else {
            $result = WaveAssigner::releaseByCurrentBounceSettings(db(), $scope->companyId);
            if ($result['domains_released'] === 0 && $result['held_reactivated'] === 0) {
                flash_set('success', 'Nothing to release -- no domain/held prospect is currently suppressed under a bounce type your settings no longer flag.');
            } else {
                flash_set('success', "{$result['domains_released']} domain(s) released from the global suppression list, {$result['held_reactivated']} held prospect(s) reactivated across their campaigns.");
            }
        }
        header('Location: bounce_settings.php');
        exit;
    }

    $checked = array_flip($_POST['suppresses'] ?? []);
    $bounceTypesStmt = db()->prepare('SELECT bounce_type FROM bounce_type_suppression_settings WHERE company_id = ?');
    $bounceTypesStmt->execute([$scope->companyId]);
    $stmt = db()->prepare('UPDATE bounce_type_suppression_settings SET suppresses = ? WHERE bounce_type = ? AND company_id = ?');
    foreach ($bounceTypesStmt->fetchAll(PDO::FETCH_COLUMN) as $bounceType) {
        $stmt->execute([isset($checked[$bounceType]) ? 1 : 0, $bounceType, $scope->companyId]);
    }

    flash_set(
        'success',
        'Bounce suppression settings saved. This only governs bounces from now on -- '
        . 'it does not retroactively release anything already suppressed under the old settings; '
        . 'use "Release now" below for that.'
    );
    header('Location: bounce_settings.php');
    exit;
}

$settingsStmt = db()->prepare('SELECT bounce_type, suppresses FROM bounce_type_suppression_settings WHERE company_id = ? ORDER BY bounce_type');
$settingsStmt->execute([$scope->companyId]);
$settings = $settingsStmt->fetchAll();

$releasePreview = WaveAssigner::previewReleaseByCurrentBounceSettings(db(), $scope->companyId);

render_header('Bounce settings');
?>
<h1 class="h4 mb-1">Bounce settings</h1>
<p class="text-muted">
  Controls which bounce types cause the <strong>whole domain</strong> to be added to the global suppression list --
  blocking every persona at that company from being added to any campaign (see
  <a href="bounce_import.php">Bounce report import</a>, the campaign bounce-paste box, and Saleshandy status sync).
  A bounce type left unchecked here still gets recorded on that one lead's assignment, but the rest of the account
  stays assignable elsewhere.
</p>

<form method="post" action="bounce_settings.php">
  <?= csrf_field() ?>
  <div class="card mb-4">
    <div class="card-body">
      <table class="table table-sm mb-0">
        <thead><tr><th>Bounce type</th><th>Suppresses the whole domain</th></tr></thead>
        <tbody>
        <?php foreach ($settings as $s): ?>
          <tr>
            <td><?= e($s['bounce_type']) ?></td>
            <td>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="suppresses[]" value="<?= e($s['bounce_type']) ?>"
                       id="bt_<?= md5($s['bounce_type']) ?>" <?= $s['suppresses'] ? 'checked' : '' ?>>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <button type="submit" class="btn btn-primary btn-sm">Save</button>
</form>

<div class="card mb-4">
  <div class="card-header fw-semibold">Release previously-suppressed prospects</div>
  <div class="card-body">
    <p class="text-muted small">
      Saving the settings above only changes how <strong>future</strong> bounces are handled -- it does not
      retroactively touch a domain or held prospect that was already suppressed while a bounce type was still
      checked. If you've since unchecked a type (e.g. Soft Bounce), use this to release whatever it's currently
      blocking, account-wide, across every campaign.
      <?= info_icon('Two things happen for every domain/held prospect suppressed under a bounce type NOT currently checked above: (1) the domain is removed from the global suppression list, so every persona there becomes assignable to new campaigns again; (2) any prospect held back in a campaign specifically because that domain\'s wave-1 leader bounced with that type goes back to active in that campaign. The leader\'s own bounce record is left as-is -- it genuinely did bounce, only the account-wide/held-group side effect is undone. A domain suppressed for a type still checked, or with no recorded bounce type, is never touched.') ?>
    </p>
    <?php if ($releasePreview['domains_count'] === 0 && $releasePreview['held_count'] === 0): ?>
      <p class="mb-0"><span class="badge bg-secondary">Nothing to release</span> No domain or held prospect is currently suppressed under a bounce type left unchecked above.</p>
    <?php else: ?>
      <p class="mb-3">
        <span class="badge bg-warning text-dark"><?= number_format($releasePreview['domains_count']) ?> domain(s)</span>
        <span class="badge bg-warning text-dark"><?= number_format($releasePreview['held_count']) ?> held prospect(s)</span>
        would be released right now, based on your current settings above.
      </p>
      <?php if ($releasePreview['domains']): ?>
        <p class="small text-muted mb-3">
          Domain(s): <?= e(implode(', ', array_slice($releasePreview['domains'], 0, 20))) ?><?= count($releasePreview['domains']) > 20 ? ' and ' . (count($releasePreview['domains']) - 20) . ' more...' : '' ?>
        </p>
      <?php endif; ?>
      <?php if ($scope->isAdmin()): ?>
        <form method="post" action="bounce_settings.php" onsubmit="return confirm('Release <?= (int) $releasePreview['domains_count'] ?> domain(s) and reactivate <?= (int) $releasePreview['held_count'] ?> held prospect(s) across every campaign? This cannot be undone with one click -- a released domain/prospect would need to be manually re-suppressed if this turns out to be wrong.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="release">
          <button type="submit" class="btn btn-outline-danger btn-sm">Release now</button>
        </form>
      <?php else: ?>
        <p class="small text-muted mb-0">Only an admin can run this.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php render_footer(); ?>
