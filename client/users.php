<?php
// ── Bootstrap (no HTML yet — POST handlers must redirect before output) ──
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
sendSecurityHeaders();
requireClientAdmin();
$user = currentUser();

// Only client_admin can manage team
if ($user['role'] !== 'client_admin') {
    setFlash('danger', 'Access denied. Only admins can manage team members.');
    redirect(APP_URL . '/client/index.php');
}

$orgId = (int)$user['org_id'];
$myId  = (int)$user['id'];

// ── Helper: save module grants + roles for a staff user ──────────────────
function saveModuleGrants(PDO $pdo, int $uid, int $orgId, int $grantedBy, array $slugs, array $moduleRoles = []): void {
    // Clear old grants (throws if user_module_access doesn't exist — caller should catch)
    $pdo->prepare("DELETE FROM user_module_access WHERE user_id=? AND org_id=?")->execute([$uid, $orgId]);

    if (empty($slugs)) {
        try { saveUserModuleRoles($pdo, $uid, $orgId, $grantedBy, []); } catch (Throwable $e) {}
        return;
    }

    // Detect whether module_role column exists by trying to prepare with it first
    $withRoleCol = true;
    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO user_module_access (user_id,org_id,module_slug,granted_by,module_role) VALUES (?,?,?,?,?)");
    } catch (Throwable $e) {
        $withRoleCol = false;
        $ins = $pdo->prepare("INSERT IGNORE INTO user_module_access (user_id,org_id,module_slug,granted_by) VALUES (?,?,?,?)");
    }

    $rolesMap = [];
    foreach ($slugs as $slug) {
        $clean = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        if (!$clean) continue;
        $roleKey        = $moduleRoles[$clean] ?? 'staff';
        $rolesMap[$clean] = $roleKey;
        try {
            if ($withRoleCol) {
                $ins->execute([$uid, $orgId, $clean, $grantedBy, $roleKey]);
            } else {
                $ins->execute([$uid, $orgId, $clean, $grantedBy]);
            }
        } catch (Throwable $e) {
            error_log('[saveModuleGrants insert] ' . $e->getMessage());
        }
    }

    // Save per-module role keys (user_module_roles table — silently skip if missing)
    try {
        saveUserModuleRoles($pdo, $uid, $orgId, $grantedBy, $rolesMap);
    } catch (Throwable $e) {
        error_log('[saveModuleGrants roles] ' . $e->getMessage());
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
        $role        = in_array($_POST['role'] ?? '', ['client_admin', 'staff']) ? $_POST['role'] : 'staff';
        $pwd         = $_POST['password'] ?? '';
        $modules     = $_POST['modules'] ?? [];
        $moduleRoles = $_POST['module_roles'] ?? []; // [slug => role_key]

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
                $branchId = (int)($_POST['branch_id'] ?? 0) ?: null;
                $pdo->prepare("INSERT INTO users (org_id,name,email,password,phone,role,branch_id,status) VALUES (?,?,?,?,?,?,?,'active')")
                    ->execute([$orgId, $name, $email, password_hash($pwd, PASSWORD_BCRYPT), $phone, $role, $branchId]);
                $newId = (int)$pdo->lastInsertId();
                $grantsOk = true;
                // Grant module access + roles for staff members
                if ($role === 'staff' && $newId) {
                    try {
                        saveModuleGrants($pdo, $newId, $orgId, $myId, $modules, $moduleRoles);
                    } catch (Throwable $e) {
                        $grantsOk = false;
                        error_log('[users add grants] ' . $e->getMessage());
                    }
                }
                logActivity('add_team_member', 'client', "Added: $name ($role)");
                $modCount = $role === 'staff' ? count($modules) : 'all';
                if (!$grantsOk) {
                    setFlash('warning', "Team member <strong>$name</strong> was created but module permissions could not be saved. Run the staff &amp; module-roles database migrations, then edit the member to reassign modules.");
                } else {
                    setFlash('success', "Team member <strong>$name</strong> added with access to {$modCount} module(s).");
                }

                // Send welcome email to new team member
                try {
                    $orgName  = $user['org_name'];
                    // Use org-specific portal URL for the invitation link
                    $orgSlugForEmail = null;
                    try {
                        $slugQ = $pdo->prepare("SELECT slug FROM organizations WHERE id=? LIMIT 1");
                        $slugQ->execute([$orgId]);
                        $orgSlugForEmail = $slugQ->fetchColumn() ?: null;
                    } catch (Exception $e) {}
                    $loginUrl  = $orgSlugForEmail
                        ? APP_URL . '/auth/org-login.php?org=' . rawurlencode($orgSlugForEmail)
                        : APP_URL . '/auth/login.php';
                    $roleLabel = $role === 'client_admin' ? 'Administrator' : 'Staff';
                    $modLine  = $role === 'client_admin'
                        ? '<p>As an <strong>Administrator</strong>, you have full access to all modules on the workspace.</p>'
                        : ($modCount > 0
                            ? "<p>You have been granted access to <strong>{$modCount} module(s)</strong>. Log in to see your workspace.</p>"
                            : '<p>Your workspace access is being set up — your administrator will grant module access shortly.</p>');
                    $body = "
                    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
                      <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
                        <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
                      </div>
                      <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
                        <h2 style='color:#0B2D4E;margin-top:0'>You've been added to " . htmlspecialchars($orgName) . "</h2>
                        <p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
                        <p><strong>" . htmlspecialchars($user['name']) . "</strong> has added you to the <strong>" . htmlspecialchars($orgName) . "</strong> workspace on " . APP_NAME . " as <strong>{$roleLabel}</strong>.</p>
                        {$modLine}
                        <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Email</td><td style='padding:8px;border:1px solid #eee;font-weight:600'>" . htmlspecialchars($email) . "</td></tr>
                          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Temporary Password</td><td style='padding:8px;border:1px solid #eee;font-weight:600'>" . htmlspecialchars($pwd) . "</td></tr>
                        </table>
                        <p style='color:#e67e22;font-size:.85rem;background:#fff9f0;border:1px solid #f6c06e;padding:10px 14px;border-radius:8px'>
                          ⚠ Please log in and change your password immediately via your Profile settings.
                        </p>
                        <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;margin:16px 0;font-size:.85rem;color:#166534'>
                          <strong>Important:</strong> Bookmark this link — it is your organization's dedicated login portal.
                        </div>
                        <div style='text-align:center;margin:24px 0'>
                          <a href='{$loginUrl}' style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
                            Sign In to Your Workspace Portal →
                          </a>
                        </div>
                        <hr style='border:none;border-top:1px solid #eee;margin:24px 0'>
                        <p style='color:#999;font-size:.8rem;margin:0'>&copy; " . date('Y') . " " . APP_NAME . "</p>
                      </div>
                    </div>";
                    mailer()->send($email, "You've been added to {$orgName} — " . APP_NAME, $body);
                } catch (Exception $e) {
                    error_log('[users] Staff welcome email failed: ' . $e->getMessage());
                }
            }
        }
        redirect(APP_URL . '/client/users.php');
    }

    // ── Edit team member (with inline module grants) ───────────────────────
    if ($action === 'edit_user') {
        $uid     = (int)($_POST['user_id'] ?? 0);
        $name    = sanitize($_POST['name']  ?? '');
        $phone   = sanitize($_POST['phone'] ?? '');
        $role        = in_array($_POST['role'] ?? '', ['client_admin', 'staff']) ? $_POST['role'] : 'staff';
        $modules     = $_POST['modules'] ?? [];
        $moduleRoles = $_POST['module_roles'] ?? [];
        // Guard: cannot demote yourself
        if ($uid === $myId) $role = 'client_admin';
        $editBranchId = (int)($_POST['branch_id'] ?? 0) ?: null;
        $pdo->prepare("UPDATE users SET name=?,phone=?,role=?,branch_id=? WHERE id=? AND org_id=? AND role!='super_admin'")
            ->execute([$name, $phone, $role, $editBranchId, $uid, $orgId]);
        // Update module grants + roles (always re-sync; clear for admins, set for staff)
        $editGrantsOk = true;
        try {
            if ($role === 'staff') {
                saveModuleGrants($pdo, $uid, $orgId, $myId, $modules, $moduleRoles);
            } else {
                // Admins don't need explicit grants — clear any old ones
                $pdo->prepare("DELETE FROM user_module_access WHERE user_id=? AND org_id=?")->execute([$uid, $orgId]);
                try { saveUserModuleRoles($pdo, $uid, $orgId, $myId, []); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {
            $editGrantsOk = false;
            error_log('[users edit grants] ' . $e->getMessage());
        }
        logActivity('edit_team_member', 'client', "Edited user #$uid");
        if (!$editGrantsOk) {
            setFlash('warning', 'Profile updated but module permissions could not be saved. Run the staff &amp; module-roles database migrations, then try again.');
        } else {
            setFlash('success', 'Team member and module permissions updated successfully.');
        }
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

// ── Render page (HTML starts here) ──────────────────────────────
$pageTitle = 'Team Management';
require_once __DIR__ . '/../includes/header-client.php';

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

// Org branches (for user assignment)
$orgBranches = getOrgBranches($orgId);

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

// Per-user module role assignments: [user_id => [slug => role_key]]
$rolesRaw = getOrgUserModuleRoles($orgId);

// Build JS payload: {user_id: {grants:[...], roles:{slug:role_key}}}
$userPermissionsJson = json_encode(array_reduce(
    array_unique(array_merge(array_keys($grantsRaw), array_keys($rolesRaw))),
    function($carry, $uid) use ($grantsRaw, $rolesRaw) {
        $carry[$uid] = [
            'grants' => array_values($grantsRaw[$uid] ?? []),
            'roles'  => $rolesRaw[$uid] ?? [],
        ];
        return $carry;
    },
    []
));

// Pre-build module roles definitions for JS (roles per module)
$allModuleRoleDefs = getModuleRoleDefinitions();
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

<!-- ── Org Portal URL card ──────────────────────────────────────────── -->
<?php
$__orgSlugForPortal = null;
try {
    $__sq = $pdo->prepare("SELECT slug FROM organizations WHERE id=? LIMIT 1");
    $__sq->execute([$orgId]);
    $__orgSlugForPortal = $__sq->fetchColumn() ?: null;
} catch (Exception $e) {}
if ($__orgSlugForPortal):
    $__portalLink = APP_URL . '/auth/org-login.php?org=' . rawurlencode($__orgSlugForPortal);
?>
<div class="card mb-4 border-0" style="background:linear-gradient(135deg,#f0fdf4,#eff6ff);border-left:4px solid #1A8A4E!important;border:1px solid #bbf7d0">
  <div class="card-body py-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div class="d-flex align-items-center justify-content-center rounded-3 flex-shrink-0 text-white"
           style="width:40px;height:40px;background:linear-gradient(135deg,#1A8A4E,#22a860);font-size:.9rem">
        <i class="fas fa-link"></i>
      </div>
      <div class="flex-fill">
        <div class="fw-700 text-navy small">Team Login Portal URL</div>
        <div class="text-muted" style="font-size:.73rem">Share this link with your staff so they can sign in directly to your workspace. Staff cannot use the main OrbitDesk login page.</div>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <code class="px-3 py-2 rounded small" style="background:#fff;border:1.5px solid #d1fae5;color:#0B2D4E;font-size:.75rem;max-width:340px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" id="portalUrlText"><?= htmlspecialchars($__portalLink, ENT_QUOTES) ?></code>
        <button class="btn btn-sm btn-success" onclick="copyPortalLink()" id="copyPortalBtn" style="white-space:nowrap">
          <i class="fas fa-copy me-1" id="copyPortalIcon"></i><span id="copyPortalText">Copy Link</span>
        </button>
        <a href="<?= htmlspecialchars($__portalLink, ENT_QUOTES) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="white-space:nowrap">
          <i class="fas fa-external-link-alt me-1"></i>Open
        </a>
      </div>
    </div>
  </div>
</div>
<script>
function copyPortalLink() {
  var url  = document.getElementById('portalUrlText').textContent.trim();
  var btn  = document.getElementById('copyPortalBtn');
  var ico  = document.getElementById('copyPortalIcon');
  var txt  = document.getElementById('copyPortalText');
  navigator.clipboard.writeText(url).then(function() {
    btn.classList.remove('btn-success'); btn.classList.add('btn-dark');
    ico.className = 'fas fa-check me-1'; txt.textContent = 'Copied!';
    setTimeout(function(){
      btn.classList.remove('btn-dark'); btn.classList.add('btn-success');
      ico.className = 'fas fa-copy me-1'; txt.textContent = 'Copy Link';
    }, 2000);
  }).catch(function() {
    var ta = document.createElement('textarea'); ta.value = url;
    ta.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(ta);
    ta.select(); try { document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(ta); txt.textContent = 'Copied!';
    setTimeout(function(){ txt.textContent = 'Copy Link'; }, 2000);
  });
}
</script>
<?php endif; ?>

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
          <?php foreach ($orgModules as $mod): if (in_array($mod['slug'], $grants)):
            $userRole    = $rolesRaw[(int)$m['id']][$mod['slug']] ?? 'staff';
            $roleDefs    = getModuleRoles($mod['slug']);
            $roleName    = $roleDefs[$userRole]['name'] ?? ucfirst($userRole);
            $roleColor   = $roleDefs[$userRole]['color'] ?? $mod['color'];
          ?>
          <div class="d-flex align-items-center gap-1 rounded px-2 py-1 mb-1"
               style="background:<?= e($mod['color']) ?>15;border:1px solid <?= e($mod['color']) ?>40;font-size:.72rem">
            <i class="<?= e($mod['icon']) ?>" style="color:<?= e($mod['color']) ?>;font-size:.65rem"></i>
            <span class="fw-semibold" style="color:<?= e($mod['color']) ?>"><?= e($mod['name']) ?></span>
            <span class="text-muted">·</span>
            <span style="color:<?= e($roleColor) ?>;font-size:.68rem"><?= e($roleName) ?></span>
          </div>
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
                    'id'        => (int)$m['id'],
                    'name'      => $m['name'],
                    'phone'     => $m['phone'] ?? '',
                    'role'      => $m['role'],
                    'branch_id' => $m['branch_id'] ?? null,
                    'grants'    => array_values($grants),
                    'roles'     => $rolesRaw[(int)$m['id']] ?? new stdClass(),
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
                <?php if (!empty($orgBranches)): ?>
                <div class="col-12">
                  <label class="form-label fw-semibold">Assign to Branch</label>
                  <select name="branch_id" class="form-select">
                    <option value="">All Branches (no restriction)</option>
                    <?php foreach ($orgBranches as $__br): ?>
                    <option value="<?= $__br['id'] ?>"><?= e($__br['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text">Staff see only data for their assigned branch.</div>
                </div>
                <?php endif; ?>
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

              <!-- Staff: module grid with role selectors -->
              <div id="addModuleGrid">
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
                <?php foreach ($mods as $mod):
                  $modRoles = getModuleRoles($mod['slug']);
                  $firstRoleKey = array_key_first($modRoles);
                ?>
                <div class="module-perm-card mb-2" id="addCard_<?= e($mod['slug']) ?>"
                     style="--mod-color:<?= e($mod['color']) ?>">
                  <div class="d-flex align-items-center gap-3">
                    <div class="form-check mb-0">
                      <input class="form-check-input module-cb" type="checkbox"
                             name="modules[]" value="<?= e($mod['slug']) ?>"
                             id="add_<?= e($mod['slug']) ?>"
                             onchange="toggleRoleRow(this,'addRole_<?= e($mod['slug']) ?>')">
                    </div>
                    <div class="module-perm-icon flex-shrink-0" style="background:<?= e($mod['color']) ?>20;color:<?= e($mod['color']) ?>">
                      <i class="<?= e($mod['icon']) ?>"></i>
                    </div>
                    <div class="flex-fill">
                      <label for="add_<?= e($mod['slug']) ?>" class="fw-semibold small mb-0 cursor-pointer"><?= e($mod['name']) ?></label>
                    </div>
                    <div class="flex-shrink-0 text-muted" style="font-size:.7rem">
                      <?= count($modRoles) ?> role<?= count($modRoles) !== 1 ? 's' : '' ?>
                    </div>
                  </div>
                  <!-- Role cards — revealed when module is ticked -->
                  <div id="addRole_<?= e($mod['slug']) ?>" class="role-cards-section" style="display:none">
                    <div class="role-cards-grid">
                      <?php foreach ($modRoles as $rKey => $rDef):
                        $rColor = $rDef['color'] ?? $mod['color'];
                        $rIcon  = $rDef['icon']  ?? 'fa-user';
                        $rDesc  = $rDef['desc']  ?? '';
                      ?>
                      <label class="rc-label" style="--rc-color:<?= e($rColor) ?>">
                        <input type="radio" name="module_roles[<?= e($mod['slug']) ?>]"
                               value="<?= e($rKey) ?>" class="rc-radio"
                               <?= $rKey === $firstRoleKey ? 'checked' : '' ?>>
                        <div class="rc-inner">
                          <div class="rc-icon-box" style="background:<?= e($rColor) ?>20;color:<?= e($rColor) ?>">
                            <i class="fas <?= e($rIcon) ?>"></i>
                          </div>
                          <div class="rc-name"><?= e($rDef['name']) ?></div>
                          <?php if ($rDesc): ?><div class="rc-desc"><?= e($rDesc) ?></div><?php endif; ?>
                        </div>
                      </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
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
                <?php if (!empty($orgBranches)): ?>
                <div class="col-12">
                  <label class="form-label fw-semibold">Assign to Branch</label>
                  <select name="branch_id" id="editBranchId" class="form-select">
                    <option value="">All Branches (no restriction)</option>
                    <?php foreach ($orgBranches as $__br): ?>
                    <option value="<?= $__br['id'] ?>"><?= e($__br['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>
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
                <?php foreach ($mods as $mod):
                  $modRoles = getModuleRoles($mod['slug']);
                  $firstRoleKey = array_key_first($modRoles);
                ?>
                <div class="module-perm-card mb-2" id="editCard_<?= e($mod['slug']) ?>"
                     style="--mod-color:<?= e($mod['color']) ?>">
                  <div class="d-flex align-items-center gap-3">
                    <div class="form-check mb-0">
                      <input class="form-check-input module-cb" type="checkbox"
                             name="modules[]" value="<?= e($mod['slug']) ?>"
                             id="edit_<?= e($mod['slug']) ?>"
                             onchange="toggleRoleRow(this,'editRole_<?= e($mod['slug']) ?>')">
                    </div>
                    <div class="module-perm-icon flex-shrink-0" style="background:<?= e($mod['color']) ?>20;color:<?= e($mod['color']) ?>">
                      <i class="<?= e($mod['icon']) ?>"></i>
                    </div>
                    <div class="flex-fill">
                      <label for="edit_<?= e($mod['slug']) ?>" class="fw-semibold small mb-0 cursor-pointer"><?= e($mod['name']) ?></label>
                    </div>
                    <div class="flex-shrink-0 text-muted" style="font-size:.7rem">
                      <?= count($modRoles) ?> role<?= count($modRoles) !== 1 ? 's' : '' ?>
                    </div>
                  </div>
                  <!-- Role cards — revealed when module is ticked -->
                  <div id="editRole_<?= e($mod['slug']) ?>" class="role-cards-section" style="display:none">
                    <div class="role-cards-grid">
                      <?php foreach ($modRoles as $rKey => $rDef):
                        $rColor = $rDef['color'] ?? $mod['color'];
                        $rIcon  = $rDef['icon']  ?? 'fa-user';
                        $rDesc  = $rDef['desc']  ?? '';
                      ?>
                      <label class="rc-label" style="--rc-color:<?= e($rColor) ?>">
                        <input type="radio" name="module_roles[<?= e($mod['slug']) ?>]"
                               value="<?= e($rKey) ?>" class="rc-radio"
                               <?= $rKey === $firstRoleKey ? 'checked' : '' ?>>
                        <div class="rc-inner">
                          <div class="rc-icon-box" style="background:<?= e($rColor) ?>20;color:<?= e($rColor) ?>">
                            <i class="fas <?= e($rIcon) ?>"></i>
                          </div>
                          <div class="rc-name"><?= e($rDef['name']) ?></div>
                          <?php if ($rDesc): ?><div class="rc-desc"><?= e($rDesc) ?></div><?php endif; ?>
                        </div>
                      </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
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
/* ── Module permission card ───────────────────────────────── */
.module-perm-card {
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  padding: 10px 12px;
  transition: border-color .15s, box-shadow .15s, background .15s;
  background: #fff;
}
.module-perm-card:hover {
  border-color: var(--mod-color, #1A8A4E);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--mod-color, #1A8A4E) 12%, transparent);
}
.module-perm-card:has(input[type=checkbox]:checked) {
  border-color: var(--mod-color, #1A8A4E);
  background: color-mix(in srgb, var(--mod-color, #1A8A4E) 5%, #fff);
}
.module-perm-icon {
  width: 34px; height: 34px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: .9rem;
}
.cursor-pointer { cursor: pointer; }

/* ── Role cards grid ──────────────────────────────────────── */
.role-cards-section {
  padding-top: 10px;
  padding-left: 46px; /* align under the module label */
}
.role-cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
  gap: 7px;
}
.rc-label {
  display: block;
  cursor: pointer;
  position: relative;
}
.rc-radio {
  position: absolute;
  opacity: 0;
  width: 0; height: 0;
  pointer-events: none;
}
.rc-inner {
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  padding: 10px 6px;
  text-align: center;
  transition: border-color .15s, background .15s, box-shadow .15s;
  background: #fff;
  height: 100%;
}
.rc-label:hover .rc-inner {
  border-color: var(--rc-color, #1A8A4E);
  background: color-mix(in srgb, var(--rc-color, #1A8A4E) 5%, #fff);
}
.rc-label:has(input:checked) .rc-inner {
  border-color: var(--rc-color, #1A8A4E);
  background: color-mix(in srgb, var(--rc-color, #1A8A4E) 10%, #fff);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--rc-color, #1A8A4E) 30%, transparent);
}
.rc-icon-box {
  width: 32px; height: 32px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 6px;
  font-size: .82rem;
}
.rc-name {
  font-size: .72rem;
  font-weight: 700;
  color: #0B2D4E;
  margin-bottom: 2px;
  line-height: 1.2;
}
.rc-desc {
  font-size: .62rem;
  color: #64748b;
  line-height: 1.3;
}
@media (max-width: 480px) {
  .role-cards-section { padding-left: 0; }
  .role-cards-grid { grid-template-columns: repeat(2, 1fr); }
}

/* Fix: <form> wraps modal-body + modal-footer, breaking Bootstrap's
   modal-dialog-scrollable flex layout. */
#addModal  .modal-content > form,
#editModal .modal-content > form {
  flex: 1; min-height: 0;
  display: flex; flex-direction: column; overflow: hidden;
}
#addModal  .modal-content > form > .modal-body,
#editModal .modal-content > form > .modal-body {
  flex: 1; min-height: 0; overflow-y: auto;
}
</style>

<?php $extraJs = <<<'JS'
<script>
// ── System-role toggle (admin vs staff) ──────────────────────────────────────
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

// ── Show/hide role-cards section when module checkbox is ticked ──────────────
function toggleRoleRow(cb, roleRowId) {
    const row = document.getElementById(roleRowId);
    if (!row) return;
    row.style.display = cb.checked ? '' : 'none';
    // Auto-select the first role card if nothing is selected yet
    if (cb.checked) {
        const radios = row.querySelectorAll('input[type="radio"]');
        const anyChecked = Array.from(radios).some(r => r.checked);
        if (!anyChecked && radios.length > 0) radios[0].checked = true;
    }
}

// ── Select all / none ────────────────────────────────────────────────────────
function toggleAllChecks(gridId, state) {
    document.querySelectorAll('#' + gridId + ' input[type=checkbox]').forEach(cb => {
        cb.checked = state;
        const prefix = gridId.startsWith('edit') ? 'edit' : 'add';
        toggleRoleRow(cb, prefix + 'Role_' + cb.value);
    });
}

// ── Open edit modal ──────────────────────────────────────────────────────────
function openEdit(m) {
    document.getElementById('editUserId').value = m.id;
    document.getElementById('editName').value   = m.name  || '';
    document.getElementById('editPhone').value  = m.phone || '';
    document.getElementById('editRole').value   = m.role  || 'staff';
    const brSel = document.getElementById('editBranchId');
    if (brSel) brSel.value = m.branch_id || '';

    // Tick granted modules, reveal role cards, restore saved role selection
    document.querySelectorAll('#editModuleGrid input[type=checkbox]').forEach(cb => {
        const slug    = cb.value;
        const granted = Array.isArray(m.grants) && m.grants.includes(slug);
        cb.checked    = granted;
        toggleRoleRow(cb, 'editRole_' + slug);

        // Restore saved role via radio button
        if (granted && m.roles && m.roles[slug]) {
            const saved  = m.roles[slug];
            const radio  = document.querySelector(
                '#editModal input[type="radio"][name="module_roles[' + slug + ']"][value="' + saved + '"]'
            );
            if (radio) radio.checked = true;
        }
    });

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
