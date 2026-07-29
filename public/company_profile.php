<?php
require_once __DIR__ . '/bootstrap.php';

$admin = require_admin();
$scope = Scope::fromUser(db(), $admin);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_company') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $cooldownDays = (int) ($_POST['lead_cooldown_days'] ?? 0);
        if ($name === '') {
            flash_set('danger', 'Company name is required.');
        } elseif ($cooldownDays < 1) {
            flash_set('danger', 'Cooldown days must be at least 1.');
        } else {
            db()->prepare('UPDATE companies SET name = ?, lead_cooldown_days = ? WHERE id = ?')
                ->execute([$name, $cooldownDays, $scope->companyId]);
            flash_set('success', 'Company profile updated.');
        }
    } elseif ($action === 'create_team') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash_set('danger', 'Team name is required.');
        } else {
            try {
                db()->prepare('INSERT INTO teams (company_id, name) VALUES (?, ?)')->execute([$scope->companyId, $name]);
                flash_set('success', "Team \"{$name}\" created -- assign members to it from the Users page.");
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'A team with that name already exists.' : 'Could not create team.');
            }
        }
    } elseif ($action === 'rename_team') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash_set('danger', 'Team name is required.');
        } else {
            try {
                db()->prepare('UPDATE teams SET name = ? WHERE id = ? AND company_id = ?')->execute([$name, $id, $scope->companyId]);
                flash_set('success', 'Team renamed.');
            } catch (PDOException $ex) {
                flash_set('danger', str_contains($ex->getMessage(), 'Duplicate') ? 'A team with that name already exists.' : 'Could not rename team.');
            }
        }
    } elseif ($action === 'delete_team') {
        $id = (int) ($_POST['id'] ?? 0);
        // fk_users_team is ON DELETE SET NULL -- members of the deleted
        // team aren't removed from the company, they just become
        // unassigned to any team (same state as a brand-new user before
        // being placed on one), not deactivated or orphaned.
        db()->prepare('DELETE FROM teams WHERE id = ? AND company_id = ?')->execute([$id, $scope->companyId]);
        flash_set('success', 'Team deleted -- its members are now unassigned to any team.');
    }

    header('Location: company_profile.php');
    exit;
}

$companyStmt = db()->prepare('SELECT id, name, lead_cooldown_days FROM companies WHERE id = ?');
$companyStmt->execute([$scope->companyId]);
$company = $companyStmt->fetch();

$teamsStmt = db()->prepare('SELECT id, name FROM teams WHERE company_id = ? ORDER BY name');
$teamsStmt->execute([$scope->companyId]);
$teams = $teamsStmt->fetchAll();

$membersStmt = db()->prepare(
    'SELECT id, name, email, role, team_id FROM users WHERE company_id = ? ORDER BY name'
);
$membersStmt->execute([$scope->companyId]);
$membersByTeam = [];
$unassigned = [];
foreach ($membersStmt->fetchAll() as $u) {
    if ($u['team_id'] === null) {
        $unassigned[] = $u;
    } else {
        $membersByTeam[(int) $u['team_id']][] = $u;
    }
}

$roleLabel = static function (string $role): string {
    return ['admin' => 'Admin', 'team_lead' => 'Team Lead', 'member' => 'Member'][$role] ?? $role;
};

render_header('Company Profile');
?>
<h1 class="h4 mb-3">Company Profile</h1>

<div class="card mb-4">
  <div class="card-header">Profile</div>
  <div class="card-body">
    <form method="post" action="company_profile.php" class="row g-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_company">
      <div class="col-md-6">
        <label class="form-label">Company name</label>
        <input type="text" name="name" class="form-control" value="<?= e($company['name']) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Lead cool-off (days)</label>
        <input type="number" name="lead_cooldown_days" class="form-control" min="1" value="<?= (int) $company['lead_cooldown_days'] ?>" required>
        <div class="form-text">How long a lead must sit idle before it's eligible for a different campaign or a different owner again.</div>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="submit" class="btn btn-primary w-100">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Add a team</div>
  <div class="card-body">
    <form method="post" action="company_profile.php" class="row g-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_team">
      <div class="col-md-6">
        <input type="text" name="name" class="form-control" placeholder="Team name" required>
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-primary w-100">Create team</button>
      </div>
    </form>
    <p class="text-muted small mb-0 mt-2">Assign members to a team (and pick who's Team Lead) from <a href="users.php">Users</a>.</p>
  </div>
</div>

<?php if (!$teams): ?>
  <p class="text-muted">No teams yet -- create one above.</p>
<?php endif; ?>

<?php foreach ($teams as $team): ?>
  <?php $members = $membersByTeam[(int) $team['id']] ?? []; ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <form method="post" action="company_profile.php" class="d-flex align-items-center gap-2 flex-grow-1">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="rename_team">
        <input type="hidden" name="id" value="<?= (int) $team['id'] ?>">
        <input type="text" name="name" class="form-control form-control-sm" style="max-width: 260px;" value="<?= e($team['name']) ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary">Rename</button>
      </form>
      <form method="post" action="company_profile.php" onsubmit="return confirm('Delete team \'<?= e($team['name']) ?>\'? Members keep their accounts but become unassigned to any team.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_team">
        <input type="hidden" name="id" value="<?= (int) $team['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
      </form>
    </div>
    <div class="card-body">
      <?php if (!$members): ?>
        <p class="text-muted small mb-0">No members yet.</p>
      <?php else: ?>
        <ul class="list-unstyled mb-0">
          <?php foreach ($members as $m): ?>
            <li><?= e($m['name']) ?> <span class="text-muted">(<?= e($m['email']) ?>)</span> -- <span class="badge bg-<?= $m['role'] === 'admin' ? 'primary' : ($m['role'] === 'team_lead' ? 'info text-dark' : 'secondary') ?>"><?= e($roleLabel($m['role'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($unassigned): ?>
  <div class="card mb-3">
    <div class="card-header">Not on a team</div>
    <div class="card-body">
      <ul class="list-unstyled mb-0">
        <?php foreach ($unassigned as $m): ?>
          <li><?= e($m['name']) ?> <span class="text-muted">(<?= e($m['email']) ?>)</span> -- <span class="badge bg-<?= $m['role'] === 'admin' ? 'primary' : ($m['role'] === 'team_lead' ? 'info text-dark' : 'secondary') ?>"><?= e($roleLabel($m['role'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
