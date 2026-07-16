<?php
require_once __DIR__ . '/bootstrap.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim(strtolower((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $role = ($_POST['role'] ?? '') === ROLE_ADMIN ? ROLE_ADMIN : ROLE_MEMBER;

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            flash_set('danger', 'Please provide a name, a valid email, and a password of at least 8 characters.');
        } else {
            try {
                $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 1)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                flash_set('success', "User {$name} created.");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'A user with that email already exists.' : 'Could not create user.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $admin['id']) {
            flash_set('danger', 'You cannot deactivate your own account.');
        } else {
            db()->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
            flash_set('success', 'User status updated.');
        }
    } elseif ($action === 'change_role') {
        $id = (int) ($_POST['id'] ?? 0);
        $role = ($_POST['role'] ?? '') === ROLE_ADMIN ? ROLE_ADMIN : ROLE_MEMBER;
        if ($id === (int) $admin['id'] && $role !== ROLE_ADMIN) {
            flash_set('danger', 'You cannot remove your own admin role.');
        } else {
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
            flash_set('success', 'Role updated.');
        }
    }

    header('Location: users.php');
    exit;
}

$users = db()->query('SELECT id, name, email, role, is_active, last_login_at, created_at FROM users ORDER BY created_at')->fetchAll();

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
      <div class="col-md-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" minlength="8" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Role</label>
        <select name="role" class="form-select">
          <option value="member">Member</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div class="col-md-1 d-flex align-items-end">
        <button type="submit" class="btn btn-primary w-100">Add</button>
      </div>
    </form>
  </div>
</div>

<table class="table table-striped bg-white">
  <thead>
    <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr>
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
            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
          </select>
        </form>
      </td>
      <td><span class="badge bg-<?= $u['is_active'] ? 'success' : 'secondary' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
      <td><?= e($u['last_login_at'] ?? 'Never') ?></td>
      <td>
        <form method="post" action="users.php" onsubmit="return confirm('Toggle active status for <?= e($u['name']) ?>?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_active">
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php render_footer(); ?>
