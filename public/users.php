<?php
require_once __DIR__ . '/bootstrap.php';

$admin = require_admin();
$scope = Scope::fromUser(db(), $admin);

/** Only 'admin'/'team_lead'/'member' are ever accepted from a form -- anything else silently falls back to Member. */
$parseRole = static function (string $raw): string {
    return in_array($raw, [ROLE_ADMIN, ROLE_TEAM_LEAD, ROLE_MEMBER], true) ? $raw : ROLE_MEMBER;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim(strtolower((string) ($_POST['email'] ?? '')));
        $role = $parseRole((string) ($_POST['role'] ?? ''));
        $teamId = (int) ($_POST['team_id'] ?? 0) ?: null;

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'Please provide a name and a valid email.');
        } else {
            $token = bin2hex(random_bytes(32));
            try {
                $stmt = db()->prepare(
                    'INSERT INTO users (company_id, team_id, name, email, role, is_team_lead, is_active, invite_token, invite_expires_at)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))'
                );
                $stmt->execute([$scope->companyId, $teamId, $name, $email, $role, $role === ROLE_TEAM_LEAD ? 1 : 0, $token]);
                flash_set('success', "User {$name} added -- copy their signup link below and share it with them (valid 7 days).");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'A user with that email already exists.' : 'Could not create user.');
            }
        }
    } elseif ($action === 'regenerate_invite') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = bin2hex(random_bytes(32));
        db()->prepare('UPDATE users SET invite_token = ?, invite_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ? AND company_id = ? AND password_hash IS NULL')
            ->execute([$token, $id, $scope->companyId]);
        flash_set('success', 'New signup link generated below.');
    } elseif ($action === 'reset_password') {
        // For a user who already HAS a password (invite_token is
        // deliberately restricted to password_hash IS NULL, i.e. brand-
        // new signups only -- see find_user_by_invite_token()). Same
        // copy-a-link-and-share-it pattern, just the separate
        // reset_token/reset_expires_at pair (sql/046_password_reset.sql)
        // so this can never be confused with -- or accidentally clear --
        // a still-pending signup invite.
        $id = (int) ($_POST['id'] ?? 0);
        $token = bin2hex(random_bytes(32));
        db()->prepare('UPDATE users SET reset_token = ?, reset_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ? AND company_id = ?')
            ->execute([$token, $id, $scope->companyId]);
        flash_set('success', 'Password reset link generated below -- copy and share it with them (valid 7 days).');
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $admin['id']) {
            flash_set('danger', 'You cannot deactivate your own account.');
        } else {
            db()->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ? AND company_id = ?')->execute([$id, $scope->companyId]);
            flash_set('success', 'User status updated.');
        }
    } elseif ($action === 'change_role') {
        $id = (int) ($_POST['id'] ?? 0);
        $role = $parseRole((string) ($_POST['role'] ?? ''));
        if ($id === (int) $admin['id'] && $role !== ROLE_ADMIN) {
            flash_set('danger', 'You cannot remove your own admin role.');
        } else {
            db()->prepare('UPDATE users SET role = ?, is_team_lead = ? WHERE id = ? AND company_id = ?')
                ->execute([$role, $role === ROLE_TEAM_LEAD ? 1 : 0, $id, $scope->companyId]);
            flash_set('success', 'Role updated.');
        }
    } elseif ($action === 'change_team') {
        $id = (int) ($_POST['id'] ?? 0);
        $teamId = (int) ($_POST['team_id'] ?? 0) ?: null;
        // team_id is validated against this same company below, not
        // trusted from the form directly -- a team id from another
        // company would simply match zero rows in the subquery and no-op.
        db()->prepare(
            'UPDATE users SET team_id = ? WHERE id = ? AND company_id = ?
               AND (? IS NULL OR ? IN (SELECT id FROM teams WHERE company_id = ?))'
        )->execute([$teamId, $id, $scope->companyId, $teamId, $teamId, $scope->companyId]);
        flash_set('success', 'Team updated.');
    }

    header('Location: users.php');
    exit;
}

$users = db()->prepare(
    'SELECT u.id, u.name, u.email, u.role, u.is_active, u.last_login_at, u.created_at, u.team_id,
            u.password_hash IS NULL AS is_pending, u.invite_token, u.invite_expires_at,
            u.reset_token, u.reset_expires_at
       FROM users u WHERE u.company_id = ? ORDER BY u.created_at'
);
$users->execute([$scope->companyId]);
$users = $users->fetchAll();

$teamsStmt = db()->prepare('SELECT id, name FROM teams WHERE company_id = ? ORDER BY name');
$teamsStmt->execute([$scope->companyId]);
$teams = $teamsStmt->fetchAll();

$config = require __DIR__ . '/../app/config/config.php';
$appUrl = rtrim($config['app_url'], '/');

render_header('Users');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Users</h1>
</div>

<div class="card mb-4">
  <div class="card-header">Add user</div>
  <div class="card-body">
    <form method="post" action="users.php" class="row g-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-md-3">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Role</label>
        <select name="role" class="form-select">
          <option value="member">Member</option>
          <option value="team_lead">Team Lead</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Team</label>
        <select name="team_id" class="form-select">
          <option value="">(none)</option>
          <?php foreach ($teams as $t): ?>
            <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1 d-flex align-items-end">
        <button type="submit" class="btn btn-primary w-100">Add</button>
      </div>
    </form>
    <p class="text-muted small mb-0 mt-2">No password needed here -- adding a user generates a one-time signup link you copy and share, so they set their own password. Manage teams from <a href="company_profile.php">Company Profile</a>.</p>
  </div>
</div>

<table class="table table-striped bg-white">
  <thead>
    <tr><th>Name</th><th>Email</th><th>Role</th><th>Team</th><th>Status</th><th>Last login</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['name']) ?></td>
      <td><?= e($u['email']) ?></td>
      <td>
        <form method="post" action="users.php" class="d-inline-flex gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_role">
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="member" <?= $u['role'] === 'member' ? 'selected' : '' ?>>Member</option>
            <option value="team_lead" <?= $u['role'] === 'team_lead' ? 'selected' : '' ?>>Team Lead</option>
            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
          </select>
        </form>
      </td>
      <td>
        <form method="post" action="users.php" class="d-inline-flex gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_team">
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <select name="team_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">(none)</option>
            <?php foreach ($teams as $t): ?>
              <option value="<?= (int) $t['id'] ?>" <?= (int) $u['team_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td>
        <span class="badge bg-<?= $u['is_active'] ? 'success' : 'secondary' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span>
        <?php if ($u['is_pending']): ?>
          <span class="badge bg-warning">Pending signup</span>
        <?php endif; ?>
      </td>
      <td><?= e($u['last_login_at'] ?? 'Never') ?></td>
      <td>
        <form method="post" action="users.php" onsubmit="return confirm('Toggle active status for <?= e($u['name']) ?>?');" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_active">
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
        <?php if ($u['is_pending']): ?>
        <form method="post" action="users.php" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="regenerate_invite">
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary">New link</button>
        </form>
        <?php else: ?>
        <form method="post" action="users.php" class="d-inline" onsubmit="return confirm('Generate a password reset link for <?= e($u['name']) ?>? Their current password keeps working until they actually use the link to set a new one.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset_password">
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary">Reset password</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php if ($u['is_pending'] && $u['invite_token']): ?>
    <tr>
      <td colspan="7" class="small">
        Signup link (expires <?= e($u['invite_expires_at']) ?>):
        <input type="text" class="form-control form-control-sm d-inline-block" style="max-width: 480px;" readonly onclick="this.select()" value="<?= e($appUrl . '/signup.php?token=' . $u['invite_token']) ?>">
      </td>
    </tr>
    <?php endif; ?>
    <?php if ($u['reset_token']): ?>
    <tr>
      <td colspan="7" class="small">
        Password reset link (expires <?= e($u['reset_expires_at']) ?>):
        <input type="text" class="form-control form-control-sm d-inline-block" style="max-width: 480px;" readonly onclick="this.select()" value="<?= e($appUrl . '/password_reset.php?token=' . $u['reset_token']) ?>">
      </td>
    </tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody>
</table>
<?php render_footer(); ?>
