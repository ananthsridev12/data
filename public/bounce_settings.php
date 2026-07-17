<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $checked = array_flip($_POST['suppresses'] ?? []);
    $stmt = db()->prepare('UPDATE bounce_type_suppression_settings SET suppresses = ? WHERE bounce_type = ?');
    foreach (db()->query('SELECT bounce_type FROM bounce_type_suppression_settings')->fetchAll(PDO::FETCH_COLUMN) as $bounceType) {
        $stmt->execute([isset($checked[$bounceType]) ? 1 : 0, $bounceType]);
    }

    flash_set('success', 'Bounce suppression settings saved.');
    header('Location: bounce_settings.php');
    exit;
}

$settings = db()->query('SELECT bounce_type, suppresses FROM bounce_type_suppression_settings ORDER BY bounce_type')->fetchAll();

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
<?php render_footer(); ?>
