<?php
$pageTitle = 'Team Management';
require_once __DIR__ . '/../includes/header-client.php';

// Only client_admin can manage team
if ($user['role'] !== 'client_admin') {
    setFlash('danger', 'Access denied. Only admins can manage team members.');
    redirect(APP_URL . '/client/index.php');
}

$orgId = (int)$user['org_id'];
$myId  = (int)$user['id'];

// ── Helper: save module grants for a staff user ────────────────────────────
function saveModuleGrants(PDO $pdo, int $uid, int $orgId, int $grantedBy, array $slugs): void {
    $pdo->prepare("DELETE FROM user_module_access WHERE user_id=? AND org_id=?")->execute([$uid, $orgId]);
    $ins = $pdo->prepare("INSERT IGNORE INTO user_module_access (user_id,org_id,module_slug,granted_by) VALUES (?,?,?,?)");
    foreach ($slugs as $slug) {
        $clean = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        if ($clean) $ins->execute([$uid, $orgId, $clean, $grantedBy]);
    }
}

// ── POST handler ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Add team member (with inline module grants) ────────────────────────
    if ($action === 'add_user') {
        $name    = sanitize($_POST['name']  ?? '');
        $email   = strtolower(trim($_POST['email'] ?? ''));
        $phone   = sanitize($_POST['phone'] ?? '');
        $role    = in_array($_POST['role'] ?? '', ['client_admin', 'staff']) ? $_POST['role'] : 'staff';
        $pwd     = $_POST['password'] ?? '';
        $modules = $_POST['modules'] ?? [];

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
                $newId = (int)$pdo->lastInsertId();
                // Grant module access for staff members
                if ($role === 'staff' && $newId) {
                    saveModuleGrants($pdo, $newId, $orgId, $myId, $modules);
                }
                logActivity('add_team_member', 'client', "Added: $name ($role)");
                $modCount = $role === 'staff' ? count($modules) : 'all';
                setFlash('success', "Team member '$name' added with access to {$modCount} module(s).");
            }
        }
        redirect(APP_URL . '/client/users.php');
    }

    // ── Edit team member (with inline module grants) ───────────────────────
    if ($action === 'edit_user') {
        $uid     = (int)($_POST['user_id'] ?? 0);
        $name    = sanitize($_POST['name']  ?? '');
        $phone   = sanitize($_POST['phone'] ?? '');
        $role    = in_array($_POST['role'] ?? '', ['client_admin', 'staff']) ? $_POST['role'] : 'staff';
        $modules = $_POST['modules'] ?? [];
        // Guard: cannot demote yourself
        if ($uid === $myId) $role = 'client_admin';
        $pdo->prepare("UPDATE users SET name=?,phone=?,role=? WHERE id=? AND org_id=? AND role!='super_admin'")
            ->execute([$name, $phone, $role, $uid, $orgId]);
        // Update module grants (always re-sync; clear for admins, set for staff)
        if ($role === 'staff') {
            saveModuleGrants($pdo, $uid, $orgId, $myId, $modules);
        } else {
            // Admins don't need explicit grants — clear any old ones
            $pdo->prepare("DELETE FROM user_module_access WHERE user_id=? AND org_id=?")->execute([$uid, $orgId]);
        }
        logActivity('edit_team_member', 'client', "Edited user #$uid");
        setFlash('success', 'Team member updated successfully.');
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
        if ($uid !== $myId) {
            $stmtCount  = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id=? AND role='client_admin' AND status='active'");
            $stmtCount->execute([$orgId]);
            $adminCount = (int)$stmtCount->fetchColumn();

            $stmtRole  = $pdo->prepare("SELECT role FROM users WHERE id=? AND org_id=?");
            $stmtRole->execute([$uid, $orgId]);
            $targetRole = $stmtRole->fetchColumn();

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
}

// ── Page Data ──────────────────────────────────────────────────────────────
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

// Org modules subscribed
$orgModules = getOrgModules($orgId);

// Group modules by category for the checklist
$modulesByCategory = [];
foreach ($orgModules as $mod) {
    $cat = !empty($mod['category']) ? $mod['category'] : 'General';
    $modulesByCategory[$cat][] = $mod;
}

// Existing per-staff grants: [user_id => [slug,...]]
$grantsRaw = [];
try {
    $g = $pdo->prepare("SELECT user_id, module_slug FROM user_module_access WHERE org_id=?");
    $g->execute([$orgId]);
    foreach ($g->fetchAll() as $row) {
        $grantsRaw[$row['user_id']][] = $row['module_slug'];
    }
} catch (Exception $e) {}

// Pass grants indexed by user_id as JSON for JS
$grantsJson = json_encode(array_map(fn($v) => array_values($v), $grantsRaw), JSON_THROW_ON_ERROR);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-users me-2 text-green"></i>Team Management</h4>
    <p class="text-muted mb-0">Manage staff accounts, roles, and module access permissions</p>
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
      <div class="progress-bar <?= $userCount / max(1,$maxUsers) >= 0.9 ? 'bg-danger' : '' ?>"
           style="width:<?= min(100, $userCount / max(1,$maxUsers) * 100) ?>%"></div>
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
  $isMe    = ((int)$m['id'] === $myId);
  $isAdmin = ($m['role'] === 'client_admin');
  $grants  = $grantsRaw[(int)$m['id']] ?? [];
  $avatarBg = $isAdmin ? 'var(--navy)' : 'var(--green)';
?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100 <?= $m['status'] !== 'active' ? 'opacity-75' : '' ?>">
    <div class="card-body">
      <!-- Avatar + name row -->
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
              <?= $isAdmin ? '<i class="fas fa-shield-alt me-1"></i>Admin' : '<i class="fas fa-user me-1"></i>Staff' ?>
            </span>
            <?= statusBadge($m['status']) ?>
            <?php if ($isMe): ?><span class="badge bg-success">You</span><?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Module access badges -->
      <div class="mb-3">
        <div class="small text-muted fw-semibold mb-1">Module Access</div>
        <?php if ($isAdmin): ?>
        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 small">
          <i class="fas fa-infinity me-1"></i>Full access — all modules
        </span>
        <?php elseif (empty($grants)): ?>
        <span class="badge bg-light text-muted border small">
          <i class="fas fa-lock me-1"></i>No modules assigned
        </span>
        <?php else: ?>
        <div class="d-flex flex-wrap gap-1">
          <?php foreach ($orgModules as $mod): if (in_array($mod['slug'], $grants)): ?>
          <span class="badge small" style="background:<?= e($mod['color']) ?>20;color:<?= e($mod['color']) ?>;border:1px solid <?= e($mod['color']) ?>50">
            <i class="<?= e($mod['icon']) ?> me-1" style="font-size:.6rem"></i><?= e($mod['name']) ?>
          </span>
          <?php endif; endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="text-muted small mb-3">
        <i class="fas fa-clock me-1"></i>Last login: <?= $m['last_login'] ? timeAgo($m['last_login']) : 'Never' ?>
      </div>

      <!-- Action buttons -->
      <?php if (!$isMe): ?>
      <div class="d-flex flex-wrap gap-1">
        <button class="btn btn-xs btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode([
                    'id'    => (int)$m['id'],
                    'name'  => $m['name'],
                    'phone' => $m['phone'] ?? '',
                    'role'  => $m['role'],
                    'grants'=> array_values($grants)
                ]), ENT_QUOTES) ?>)'>
          <i class="fas fa-edit me-1"></i>Edit
        </button>
        <button class="btn btn-xs btn-outline-secondary"
                onclick="openResetPwd(<?= $m['id'] ?>, '<?= e($m['name']) ?>')">
          <i class="fas fa-key me-1"></i>Password
        </button>
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

<!-- ═══════════════════════════════════════════════════════════════════════
     ADD TEAM MEMBER MODAL (with inline module permission checklist)
     ═════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="addModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header" style="background:var(--navy);color:#fff">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add Team Member</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_user">

        <div class="modal-body p-0">
          <div class="row g-0">

            <!-- Left panel: basic details -->
            <div class="col-lg-5 border-end p-4" style="background:#f8f9fa">
              <h6 class="fw-bold text-navy mb-3"><i class="fas fa-id-card me-2"></i>Member Details</h6>
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                  <input type="text" name="name" class="form-control" required maxlength="150" placeholder="Jane Doe">
                </div>
                <div class="col-12">
                  <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                  <input type="email" name="email" class="form-control" required placeholder="jane@company.com">
                </div>
                <div class="col-12">
                  <label class="form-label fw-semibold">Phone</label>
                  <input type="tel" name="phone" class="form-control" maxlength="25" placeholder="+254 7xx xxx xxx">
                </div>
                <div class="col-12">
                  <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                  <select name="role" class="form-select" id="addRole" onchange="onAddRoleChange()">
                    <option value="staff">Staff — access only granted modules</option>
                    <option value="client_admin">Admin — full access to all modules</option>
                  </select>
                  <div class="form-text" id="addRoleHint">Select which modules this staff member can access on the right.</div>
                </div>
                <div class="col-12">
                  <label class="form-label fw-semibold">Temporary Password <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="password" name="password" id="addPassword" class="form-control" required minlength="8"
                           placeholder="Minimum 8 characters">
                    <button type="button" class="btn btn-outline-secondary" tabindex="-1"
                            onclick="togglePwd('addPassword', this)">
                      <i class="fas fa-eye"></i>
                    </button>
                  </div>
                  <div class="form-text">They can change this after first login.</div>
                </div>
              </div>
            </div>

            <!-- Right panel: module permissions -->
            <div class="col-lg-7 p-4">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 class="fw-bold text-navy mb-0"><i class="fas fa-puzzle-piece me-2"></i>Module Permissions</h6>
                <div class="d-flex gap-2" id="addModuleActions">
                  <button type="button" class="btn btn-xs btn-outline-success" onclick="toggleAllChecks('addModuleGrid', true)">
                    <i class="fas fa-check-double me-1"></i>All
                  </button>
                  <button type="button" class="btn btn-xs btn-outline-danger" onclick="toggleAllChecks('addModuleGrid', false)">
                    <i class="fas fa-times me-1"></i>None
                  </button>
                </div>
              </div>

              <!-- Admin: full access notice -->
              <div id="addAdminNotice" class="d-none">
                <div class="alert alert-primary border-0 d-flex gap-3 align-items-center">
                  <i class="fas fa-shield-alt fa-2x text-primary"></i>
                  <div>
                    <div class="fw-semibold">Admin — Full Access</div>
                    <div class="small text-muted">Admins automatically have unrestricted access to all subscribed modules. No individual grants required.</div>
                  </div>
                </div>
              </div>

              <!-- Staff: module grid -->
              <div id="addModuleGrid">
                <?php if (empty($orgModules)): ?>
                <div class="text-center text-muted py-5">
                  <i class="fas fa-puzzle-piece fa-3x mb-3 d-block opacity-25"></i>
                  No active modules on your subscription.
                </div>
                <?php else: ?>

                <?php foreach ($modulesByCategory as $category => $mods): ?>
                <!-- Category header -->
                <div class="d-flex align-items-center gap-2 mb-2 <?= $category !== array_key_first($modulesByCategory) ? 'mt-3' : '' ?>">
                  <span class="small fw-bold text-uppercase text-muted" style="letter-spacing:.05em"><?= e($category) ?></span>
                  <hr class="flex-fill my-0">
                </div>
                <div class="row g-2 mb-1">
                  <?php foreach ($mods as $mod): ?>
                  <div class="col-sm-6">
                    <label class="d-block h-100 cursor-pointer module-perm-card"
                           for="add_<?= e($mod['slug']) ?>"
                           style="--mod-color:<?= e($mod['color']) ?>">
                      <div class="d-flex align-items-start gap-2 h-100">
                        <div class="module-perm-icon flex-shrink-0" style="background:<?= e($mod['color']) ?>20;color:<?= e($mod['color']) ?>">
                          <i class="<?= e($mod['icon']) ?>"></i>
                        </div>
                        <div class="flex-fill overflow-hidden">
                          <div class="fw-semibold small text-dark"><?= e($mod['name']) ?></div>
                          <?php if (!empty($mod['description'])): ?>
                          <div class="text-muted" style="font-size:.72rem;line-height:1.3">
                            <?= e(mb_strimwidth($mod['description'], 0, 72, '…')) ?>
                          </div>
                          <?php endif; ?>
                        </div>
                        <input class="form-check-input flex-shrink-0 mt-1" type="checkbox"
                               name="modules[]" value="<?= e($mod['slug']) ?>"
                               id="add_<?= e($mod['slug']) ?>">
                      </div>
                    </label>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <?php endif; ?>
              </div><!-- /addModuleGrid -->
            </div><!-- /right panel -->

          </div><!-- /row -->
        </div><!-- /modal-body -->

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-user-plus me-2"></i>Add Member &amp; Save Permissions
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     EDIT TEAM MEMBER MODAL (with inline module permission checklist)
     ═════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header" style="background:var(--navy);color:#fff">
        <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Team Member</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="user_id" id="editUserId">

        <div class="modal-body p-0">
          <div class="row g-0">

            <!-- Left: details -->
            <div class="col-lg-5 border-end p-4" style="background:#f8f9fa">
              <h6 class="fw-bold text-navy mb-3"><i class="fas fa-id-card me-2"></i>Member Details</h6>
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
                  <select name="role" id="editRole" class="form-select" onchange="onEditRoleChange()">
                    <option value="staff">Staff — access only granted modules</option>
                    <option value="client_admin">Admin — full access to all modules</option>
                  </select>
                </div>
              </div>
            </div>

            <!-- Right: module permissions -->
            <div class="col-lg-7 p-4">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 class="fw-bold text-navy mb-0"><i class="fas fa-puzzle-piece me-2"></i>Module Permissions</h6>
                <div class="d-flex gap-2" id="editModuleActions">
                  <button type="button" class="btn btn-xs btn-outline-success" onclick="toggleAllChecks('editModuleGrid', true)">
                    <i class="fas fa-check-double me-1"></i>All
                  </button>
                  <button type="button" class="btn btn-xs btn-outline-danger" onclick="toggleAllChecks('editModuleGrid', false)">
                    <i class="fas fa-times me-1"></i>None
                  </button>
                </div>
              </div>

              <div id="editAdminNotice" class="d-none">
                <div class="alert alert-primary border-0 d-flex gap-3 align-items-center">
                  <i class="fas fa-shield-alt fa-2x text-primary"></i>
                  <div>
                    <div class="fw-semibold">Admin — Full Access</div>
                    <div class="small text-muted">Admins automatically have unrestricted access to all subscribed modules. No individual grants required.</div>
                  </div>
                </div>
              </div>

              <div id="editModuleGrid">
                <?php if (empty($orgModules)): ?>
                <div class="text-center text-muted py-5">
                  <i class="fas fa-puzzle-piece fa-3x mb-3 d-block opacity-25"></i>
                  No active modules on your subscription.
                </div>
                <?php else: ?>

                <?php foreach ($modulesByCategory as $category => $mods): ?>
                <div class="d-flex align-items-center gap-2 mb-2 <?= $category !== array_key_first($modulesByCategory) ? 'mt-3' : '' ?>">
                  <span class="small fw-bold text-uppercase text-muted" style="letter-spacing:.05em"><?= e($category) ?></span>
                  <hr class="flex-fill my-0">
                </div>
                <div class="row g-2 mb-1">
                  <?php foreach ($mods as $mod): ?>
                  <div class="col-sm-6">
                    <label class="d-block h-100 cursor-pointer module-perm-card"
                           for="edit_<?= e($mod['slug']) ?>"
                           style="--mod-color:<?= e($mod['color']) ?>">
                      <div class="d-flex align-items-start gap-2 h-100">
                        <div class="module-perm-icon flex-shrink-0" style="background:<?= e($mod['color']) ?>20;color:<?= e($mod['color']) ?>">
                          <i class="<?= e($mod['icon']) ?>"></i>
                        </div>
                        <div class="flex-fill overflow-hidden">
                          <div class="fw-semibold small text-dark"><?= e($mod['name']) ?></div>
                          <?php if (!empty($mod['description'])): ?>
                          <div class="text-muted" style="font-size:.72rem;line-height:1.3">
                            <?= e(mb_strimwidth($mod['description'], 0, 72, '…')) ?>
                          </div>
                          <?php endif; ?>
                        </div>
                        <input class="form-check-input flex-shrink-0 mt-1" type="checkbox"
                               name="modules[]" value="<?= e($mod['slug']) ?>"
                               id="edit_<?= e($mod['slug']) ?>">
                      </div>
                    </label>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <?php endif; ?>
              </div><!-- /editModuleGrid -->
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-2"></i>Save Changes &amp; Permissions
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Reset Password Modal ────────────────────────────────────────────────── -->
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
          <div class="input-group">
            <input type="password" name="new_password" id="resetPassword" class="form-control" required minlength="8"
                   placeholder="Minimum 8 characters">
            <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('resetPassword', this)">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning"><i class="fas fa-key me-2"></i>Reset Password</button>
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

<!-- ── Styles ─────────────────────────────────────────────────────────────── -->
<style>
.module-perm-card {
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  padding: 10px 12px;
  cursor: pointer;
  transition: border-color .15s, box-shadow .15s, background .15s;
  background: #fff;
}
.module-perm-card:hover {
  border-color: var(--mod-color, #1A8A4E);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--mod-color, #1A8A4E) 12%, transparent);
}
.module-perm-card:has(input:checked) {
  border-color: var(--mod-color, #1A8A4E);
  background: color-mix(in srgb, var(--mod-color, #1A8A4E) 6%, #fff);
}
.module-perm-icon {
  width: 34px;
  height: 34px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: .9rem;
}
.cursor-pointer { cursor: pointer; }
</style>

<?php $extraJs = <<<'JS'
<script>
// ── Role toggle helpers ──────────────────────────────────────────────────────
function onAddRoleChange() {
    const isAdmin = document.getElementById('addRole').value === 'client_admin';
    document.getElementById('addAdminNotice').classList.toggle('d-none', !isAdmin);
    document.getElementById('addModuleGrid').classList.toggle('d-none', isAdmin);
    document.getElementById('addModuleActions').classList.toggle('d-none', isAdmin);
    document.getElementById('addRoleHint').textContent = isAdmin
        ? 'Admins automatically have full access to all modules.'
        : 'Select which modules this staff member can access on the right.';
}

function onEditRoleChange() {
    const isAdmin = document.getElementById('editRole').value === 'client_admin';
    document.getElementById('editAdminNotice').classList.toggle('d-none', !isAdmin);
    document.getElementById('editModuleGrid').classList.toggle('d-none', isAdmin);
    document.getElementById('editModuleActions').classList.toggle('d-none', isAdmin);
}

// ── Select all / none ────────────────────────────────────────────────────────
function toggleAllChecks(gridId, state) {
    document.querySelectorAll('#' + gridId + ' input[type=checkbox]').forEach(cb => cb.checked = state);
}

// ── Open edit modal ──────────────────────────────────────────────────────────
function openEdit(m) {
    document.getElementById('editUserId').value = m.id;
    document.getElementById('editName').value   = m.name  || '';
    document.getElementById('editPhone').value  = m.phone || '';
    document.getElementById('editRole').value   = m.role  || 'staff';

    // Tick granted slugs
    document.querySelectorAll('#editModuleGrid input[type=checkbox]').forEach(cb => {
        cb.checked = Array.isArray(m.grants) && m.grants.includes(cb.value);
    });

    // Show/hide panels based on role
    onEditRoleChange();

    new bootstrap.Modal(document.getElementById('editModal')).show();
}

// ── Reset password modal ─────────────────────────────────────────────────────
function openResetPwd(id, name) {
    document.getElementById('resetUserId').value            = id;
    document.getElementById('resetMemberName').textContent  = name;
    new bootstrap.Modal(document.getElementById('resetPwdModal')).show();
}

// ── Password show/hide ───────────────────────────────────────────────────────
function togglePwd(inputId, btn) {
    const inp = document.getElementById(inputId);
    const isText = inp.type === 'text';
    inp.type = isText ? 'password' : 'text';
    btn.querySelector('i').className = isText ? 'fas fa-eye' : 'fas fa-eye-slash';
}

// ── Delete confirmation ──────────────────────────────────────────────────────
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
