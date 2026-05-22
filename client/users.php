<?php
$pageTitle = 'Team Management';
require_once __DIR__ . '/../includes/header-client.php';

// Only client_admin can manage team
if ($user['role'] !== 'client_admin') {
    setFlash('danger', 'Access denied. Only admins can manage team members.');
    redirect(APP_URL . '/client/index.php');
}

$orgId    = (int)$user['org_id'];
$myId     = (int)$user['id'];

// ── POST handler ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $name  = sanitize($_POST['name']  ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = sanitize($_POST['phone'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['client_admin', 'staff']) ? $_POST['role'] : 'staff';
        $pwd   = $_POST['password'] ?? '';

        if (!$name || !$email || !$pwd) {
            setFlash('danger', 'Name, email, and password are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('danger', 'Invalid email address.');
        } elseif (strlen($pwd) < 8) {
            setFlash('danger', 'Password must be at least 8 characters.');
        } else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                setFlash('danger', 'That email is already registered in the system.');
            } else {
                $pdo->prepare("INSERT INTO users (org_id,name,email,password,phone,role,status) VALUES (?,?,?,?,?,?,'active')")
                    ->execute([$orgId, $name, $email, password_hash($pwd, PASSWORD_BCRYPT), $phone, $role]);
                logActivity('add_team_member', 'client', "Added: $name ($role)");
                setFlash('success', "Team member '$name' added.");
            }
        }
        redirect(APP_URL . '/client/users.php');
    }

    if ($action === 'edit_user') {
        $uid   = (int)($_POST['user_id'] ?? 0);
        $name  = sanitize($_POST['name']  ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['client_admin', 'staff']) ? $_POST['role'] : 'staff';
        // Guard: cannot demote yourself
        if ($uid === $myId) $role = 'client_admin';
        $pdo->prepare("UPDATE users SET name=?,phone=?,role=? WHERE id=? AND org_id=? AND role!='super_admin'")
            ->execute([$name, $phone, $role, $uid, $orgId]);
        logActivity('edit_team_member', 'client', "Edited user #$uid");
        setFlash('success', 'Team member updated.');
        redirect(APP_URL . '/client/users.php');
    }

    if ($action === 'reset_password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pwd = $_POST['new_password'] ?? '';
        if (strlen($pwd) < 8) {
            setFlash('danger', 'Password must be at least 8 characters.');
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=? AND org_id=?")
                ->execute([password_hash($pwd, PASSWORD_BCRYPT), $uid, $orgId]);
            logActivity('reset_password', 'client', "Reset password for user #$uid");
            setFlash('success', 'Password reset successfully.');
        }
        redirect(APP_URL . '/client/users.php');
    }

    if ($action === 'deactivate') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid !== $myId) {
            $pdo->prepare("UPDATE users SET status='inactive' WHERE id=? AND org_id=? AND role!='super_admin'")
                ->execute([$uid, $orgId]);
            logActivity('deactivate_user', 'client', "Deactivated user #$uid");
            setFlash('success', 'Team member deactivated.');
        }
        redirect(APP_URL . '/client/users.php');
    }

    if ($action === 'reactivate') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("UPDATE users SET status='active' WHERE id=? AND org_id=?")
            ->execute([$uid, $orgId]);
        logActivity('reactivate_user', 'client', "Reactivated user #$uid");
        setFlash('success', 'Team member reactivated.');
        redirect(APP_URL . '/client/users.php');
    }

    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        // Guard: can't delete yourself; can't delete last admin
        if ($uid !== $myId) {
            $adminCount = (int)$pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id=? AND role='client_admin' AND status='active'")->execute([$orgId]) ? $pdo->query("SELECT COUNT(*) FROM users WHERE org_id=$orgId AND role='client_admin' AND status='active'")->fetchColumn() : 2;
            $targetRole = $pdo->prepare("SELECT role FROM users WHERE id=? AND org_id=?")->execute([$uid, $orgId]) ? $pdo->query("SELECT role FROM users WHERE id=$uid AND org_id=$orgId")->fetchColumn() : '';
            if ($targetRole === 'client_admin' && $adminCount <= 1) {
                setFlash('danger', 'Cannot delete the last admin account.');
            } else {
                $pdo->prepare("DELETE FROM users WHERE id=? AND org_id=? AND role!='super_admin'")->execute([$uid, $orgId]);
                logActivity('delete_team_member', 'client', "Deleted user #$uid");
                setFlash('success', 'Team member removed.');
            }
        }
        redirect(APP_URL . '/client/users.php');
    }

    if ($action === 'save_modules') {
        $uid     = (int)($_POST['user_id'] ?? 0);
        $granted = $_POST['modules'] ?? [];
        // Verify user belongs to org
        $chk = $pdo->prepare("SELECT id FROM users WHERE id=? AND org_id=? AND role='staff'");
        $chk->execute([$uid, $orgId]);
        if ($chk->fetch()) {
            // Remove existing grants for this user
            $pdo->prepare("DELETE FROM user_module_access WHERE user_id=? AND org_id=?")->execute([$uid, $orgId]);
            // Insert new grants
            $ins = $pdo->prepare("INSERT IGNORE INTO user_module_access (user_id,org_id,module_slug,granted_by) VALUES (?,?,?,?)");
            foreach ($granted as $slug) {
                $ins->execute([$uid, $orgId, preg_replace('/[^a-z0-9_-]/', '', $slug), $myId]);
            }
            logActivity('update_module_access', 'client', "Updated module access for user #$uid");
            setFlash('success', 'Module permissions updated.');
        }
        redirect(APP_URL . '/client/users.php');
    }
}

// ── Data ───────────────────────────────────────────────────────
$members = $pdo->prepare("SELECT * FROM users WHERE org_id=? AND role!='super_admin' ORDER BY role, name");
$members->execute([$orgId]);
$members = $members->fetchAll();

// Plan user limit
$sub      = getOrgSubscription($orgId);
$planRow  = $pdo->prepare("SELECT max_users FROM subscription_plans WHERE id=?");
$planRow->execute([$sub['plan_id'] ?? 0]);
$plan     = $planRow->fetch();
$maxUsers = (int)($plan['max_users'] ?? 5);
$userCount = count($members);

// Org modules (for permissions modal)
$orgModules = getOrgModules($orgId);

// Existing per-staff grants: [user_id => [slug,...]]
$grantsRaw = [];
try {
    $g = $pdo->prepare("SELECT user_id, module_slug FROM user_module_access WHERE org_id=?");
    $g->execute([$orgId]);
    foreach ($g->fetchAll() as $row) {
        $grantsRaw[$row['user_id']][] = $row['module_slug'];
    }
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-users me-2 text-green"></i>Team Management</h4>
    <p class="text-muted mb-0">Manage staff accounts, roles, and module access</p>
  </div>
  <?php if ($userCount < $maxUsers): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
    <i class="fas fa-user-plus me-2"></i>Add Team Member
  </button>
  <?php else: ?>
  <a href="<?= APP_URL ?>/client/billing.php" class="btn btn-outline-warning">
    <i class="fas fa-arrow-up me-2"></i>Upgrade Plan to Add More
  </a>
  <?php endif; ?>
</div>

<!-- Usage bar -->
<div class="card mb-4">
  <div class="card-body py-3">
    <div class="d-flex justify-content-between mb-1">
      <span class="small fw-semibold text-navy">Team Capacity</span>
      <span class="small text-muted"><?= $userCount ?> / <?= $maxUsers ?> seats used</span>
    </div>
    <div class="progress" style="height:8px">
      <div class="progress-bar <?= ($userCount / max(1,$maxUsers)) >= 0.9 ? 'bg-danger' : '' ?>"
           style="width:<?= min(100, ($userCount / max(1,$maxUsers)) * 100) ?>%"></div>
    </div>
    <?php if ($userCount >= $maxUsers): ?>
    <div class="small text-danger mt-1">
      <i class="fas fa-exclamation-triangle me-1"></i>Seat limit reached.
      <a href="<?= APP_URL ?>/client/billing.php">Upgrade your plan</a> to add more members.
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Team grid -->
<div class="row g-3">
<?php foreach ($members as $m):
  $isMe      = ((int)$m['id'] === $myId);
  $isAdmin   = ($m['role'] === 'client_admin');
  $grants    = $grantsRaw[(int)$m['id']] ?? [];
  $avatarBg  = $isAdmin ? 'var(--navy)' : 'var(--green)';
?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100 <?= $m['status'] !== 'active' ? 'opacity-75' : '' ?>">
    <div class="card-body">
      <div class="d-flex align-items-start gap-3 mb-3">
        <div class="flex-shrink-0 d-flex align-items-center justify-content-center fw-700 text-white"
             style="width:48px;height:48px;border-radius:12px;background:<?= $avatarBg ?>;font-size:.85rem">
          <?= strtoupper(substr($m['name'], 0, 2)) ?>
        </div>
        <div class="flex-fill overflow-hidden">
          <div class="fw-700 text-truncate"><?= e($m['name']) ?></div>
          <div class="text-muted small text-truncate"><?= e($m['email']) ?></div>
          <div class="d-flex flex-wrap gap-1 mt-1">
            <span class="badge <?= $isAdmin ? 'bg-primary' : 'bg-secondary' ?>">
              <?= $isAdmin ? 'Admin' : 'Staff' ?>
            </span>
            <?= statusBadge($m['status']) ?>
            <?php if ($isMe): ?><span class="badge bg-success">You</span><?php endif; ?>
          </div>
        </div>
      </div>

      <?php if (!$isAdmin && !empty($orgModules)): ?>
      <div class="mb-2">
        <div class="small text-muted mb-1">Module Access</div>
        <div class="d-flex flex-wrap gap-1">
          <?php if (empty($grants)): ?>
          <span class="badge bg-light text-muted border">No modules granted</span>
          <?php else: foreach ($orgModules as $mod): if (in_array($mod['slug'], $grants)): ?>
          <span class="badge" style="background:<?= e($mod['color']) ?>20;color:<?= e($mod['color']) ?>;border:1px solid <?= e($mod['color']) ?>40;font-size:.68rem">
            <?= e($mod['name']) ?>
          </span>
          <?php endif; endforeach; endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="text-muted small mb-3">
        <i class="fas fa-clock me-1"></i>Last login: <?= $m['last_login'] ? timeAgo($m['last_login']) : 'Never' ?>
      </div>

      <?php if (!$isMe): ?>
      <div class="d-flex flex-wrap gap-1">
        <button class="btn btn-xs btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode(['id'=>$m['id'],'name'=>$m['name'],'phone'=>$m['phone']??'','role'=>$m['role']]), ENT_QUOTES) ?>)'>
          <i class="fas fa-edit me-1"></i>Edit
        </button>
        <button class="btn btn-xs btn-outline-secondary"
                onclick="openResetPwd(<?= $m['id'] ?>, '<?= e($m['name']) ?>')">
          <i class="fas fa-key me-1"></i>Password
        </button>
        <?php if (!$isAdmin && !empty($orgModules)): ?>
        <button class="btn btn-xs btn-outline-info"
                onclick='openModules(<?= htmlspecialchars(json_encode(['id'=>$m['id'],'name'=>$m['name'],'grants'=>$grants]), ENT_QUOTES) ?>)'>
          <i class="fas fa-puzzle-piece me-1"></i>Modules
        </button>
        <?php endif; ?>
        <form method="POST" class="d-inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $m['status'] === 'active' ? 'deactivate' : 'reactivate' ?>">
          <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
          <button type="submit" class="btn btn-xs <?= $m['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?>">
            <i class="fas fa-<?= $m['status'] === 'active' ? 'pause' : 'play' ?> me-1"></i>
            <?= $m['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?>
          </button>
        </form>
        <button class="btn btn-xs btn-outline-danger" onclick="confirmDelete(<?= $m['id'] ?>, '<?= e($m['name']) ?>')">
          <i class="fas fa-trash"></i>
        </button>
      </div>
      <?php else: ?>
      <div class="small text-muted"><i class="fas fa-info-circle me-1"></i>Your own account — manage via Profile.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if (empty($members)): ?>
<div class="col-12 text-center text-muted py-5">
  <i class="fas fa-users fa-3x mb-3 d-block"></i>No team members yet. Add your first member above.
</div>
<?php endif; ?>
</div>

<!-- ── Add Member Modal ───────────────────────────────────────── -->
<div class="modal fade" id="addModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:#fff">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add Team Member</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_user">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required maxlength="150" placeholder="Jane Doe">
            </div>
            <div class="col-md-7">
              <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required placeholder="jane@company.com">
            </div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">Phone</label>
              <input type="tel" name="phone" class="form-control" maxlength="25" placeholder="+254 7xx xxx xxx">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
              <select name="role" class="form-select">
                <option value="staff">Staff — access only granted modules</option>
                <option value="client_admin">Admin — full access to all modules</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Temporary Password <span class="text-danger">*</span></label>
              <input type="password" name="password" class="form-control" required minlength="8"
                     placeholder="Minimum 8 characters — they can change it">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus me-2"></i>Add Member</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Edit Member Modal ──────────────────────────────────────── -->
<div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:#fff">
        <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Team Member</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="user_id" id="editUserId">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="editName" class="form-control" required maxlength="150">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Phone</label>
              <input type="tel" name="phone" id="editPhone" class="form-control" maxlength="25">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Role</label>
              <select name="role" id="editRole" class="form-select">
                <option value="staff">Staff</option>
                <option value="client_admin">Admin</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Reset Password Modal ───────────────────────────────────── -->
<div class="modal fade" id="resetPwdModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:#fff">
        <h5 class="modal-title"><i class="fas fa-key me-2"></i>Reset Password — <span id="resetMemberName"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" id="resetUserId">
        <div class="modal-body">
          <label class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
          <input type="password" name="new_password" class="form-control" required minlength="8" placeholder="Minimum 8 characters">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning"><i class="fas fa-key me-2"></i>Reset Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Module Access Modal ────────────────────────────────────── -->
<div class="modal fade" id="modulesModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:#fff">
        <h5 class="modal-title"><i class="fas fa-puzzle-piece me-2"></i>Module Access — <span id="moduleMemberName"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_modules">
        <input type="hidden" name="user_id" id="moduleUserId">
        <div class="modal-body">
          <p class="small text-muted mb-3">Select which modules this staff member can access. Admins always have full access.</p>
          <?php if (empty($orgModules)): ?>
          <div class="text-center text-muted py-3"><i class="fas fa-puzzle-piece fa-2x mb-2 d-block"></i>No active modules on your subscription.</div>
          <?php else: ?>
          <div class="row g-2" id="moduleCheckboxes">
            <?php foreach ($orgModules as $mod): ?>
            <div class="col-12">
              <div class="form-check border rounded p-2 ps-4 module-check-row" style="border-color:<?= e($mod['color']) ?>40!important">
                <input class="form-check-input" type="checkbox" name="modules[]"
                       value="<?= e($mod['slug']) ?>" id="mod_<?= e($mod['slug']) ?>">
                <label class="form-check-label d-flex align-items-center gap-2 w-100" for="mod_<?= e($mod['slug']) ?>">
                  <i class="<?= e($mod['icon']) ?>" style="color:<?= e($mod['color']) ?>;width:16px;text-align:center"></i>
                  <span class="fw-semibold small"><?= e($mod['name']) ?></span>
                </label>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-xs btn-outline-secondary" onclick="toggleAllModules(true)">Select All</button>
            <button type="button" class="btn btn-xs btn-outline-secondary" onclick="toggleAllModules(false)">Deselect All</button>
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Permissions</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Hidden delete form -->
<form method="POST" id="deleteForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete_user">
  <input type="hidden" name="user_id" id="deleteUserId">
</form>

<?php $extraJs = <<<'JS'
<script>
function openEdit(m) {
  document.getElementById('editUserId').value  = m.id;
  document.getElementById('editName').value    = m.name  || '';
  document.getElementById('editPhone').value   = m.phone || '';
  document.getElementById('editRole').value    = m.role  || 'staff';
  new bootstrap.Modal(document.getElementById('editModal')).show();
}
function openResetPwd(id, name) {
  document.getElementById('resetUserId').value     = id;
  document.getElementById('resetMemberName').textContent = name;
  new bootstrap.Modal(document.getElementById('resetPwdModal')).show();
}
function openModules(m) {
  document.getElementById('moduleUserId').value          = m.id;
  document.getElementById('moduleMemberName').textContent = m.name;
  // Tick granted slugs
  document.querySelectorAll('#moduleCheckboxes input[type=checkbox]').forEach(cb => {
    cb.checked = m.grants && m.grants.includes(cb.value);
  });
  new bootstrap.Modal(document.getElementById('modulesModal')).show();
}
function toggleAllModules(state) {
  document.querySelectorAll('#moduleCheckboxes input[type=checkbox]').forEach(cb => cb.checked = state);
}
function confirmDelete(id, name) {
  Swal.fire({
    title: 'Remove Team Member?',
    text: name + ' will be permanently removed from the team.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Yes, remove'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('deleteUserId').value = id;
      document.getElementById('deleteForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
