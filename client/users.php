<?php
$pageTitle = 'Team Management';
require_once __DIR__ . '/../includes/header-client.php';

$orgId = (int)$user['org_id'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $name    = sanitize($_POST['name']    ?? '');
        $email   = trim($_POST['email']       ?? '');
        $phone   = sanitize($_POST['phone']   ?? '');
        $role    = in_array($_POST['role']??'', ['client_admin','staff']) ? $_POST['role'] : 'staff';
        $password= $_POST['password']         ?? '';

        if (!$name || !$email || !$password) {
            setFlash('danger','Name, email, and password are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('danger','Invalid email address.');
        } elseif (strlen($password) < 8) {
            setFlash('danger','Password must be at least 8 characters.');
        } else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                setFlash('danger','Email already exists in the system.');
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO users (org_id,name,email,password,phone,role,status) VALUES (?,?,?,?,?,?,'active')")
                    ->execute([$orgId, $name, $email, $hash, $phone, $role]);
                logActivity('add_team_member','client',"Added team member: $name");
                setFlash('success',"Team member '$name' added successfully.");

                // Send welcome email (non-fatal if SMTP not configured)
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    mailer()->sendWelcome($email, $name, $user['org_name']);
                } catch (Exception $ex) {
                    error_log('[users] Welcome email failed: ' . $ex->getMessage());
                }
            }
        }
        redirect(APP_URL . '/client/users.php');
    }

    if ($action === 'deactivate') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid !== $user['id']) {
            $pdo->prepare("UPDATE users SET status='inactive' WHERE id=? AND org_id=? AND role!='super_admin'")->execute([$uid, $orgId]);
            setFlash('success','Team member deactivated.');
        }
        redirect(APP_URL . '/client/users.php');
    }

    if ($action === 'reactivate') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("UPDATE users SET status='active' WHERE id=? AND org_id=?")->execute([$uid, $orgId]);
        setFlash('success','Team member reactivated.');
        redirect(APP_URL . '/client/users.php');
    }

    if ($action === 'reset_password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pwd = $_POST['new_password'] ?? '';
        if (strlen($pwd) >= 8) {
            $pdo->prepare("UPDATE users SET password=? WHERE id=? AND org_id=?")->execute([password_hash($pwd,PASSWORD_BCRYPT),$uid,$orgId]);
            setFlash('success','Password reset successfully.');
        } else {
            setFlash('danger','Password must be at least 8 characters.');
        }
        redirect(APP_URL . '/client/users.php');
    }
}

// Fetch team members
$members = $pdo->prepare("SELECT * FROM users WHERE org_id=? AND role != 'super_admin' ORDER BY role,name");
$members->execute([$orgId]);
$members = $members->fetchAll();

// Get subscription limits
$sub = getOrgSubscription($orgId);
$planStmt = $pdo->prepare("SELECT max_users FROM subscription_plans WHERE id=?");
$planStmt->execute([$sub['plan_id'] ?? 0]);
$plan = $planStmt->fetch();
$maxUsers = $plan['max_users'] ?? 5;
$currentCount = count($members);
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-users me-2 text-green"></i>Team Management</h4>
    <p class="text-muted mb-0">Manage your team members and their roles</p>
  </div>
  <?php if ($currentCount < $maxUsers): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
    <i class="fas fa-user-plus me-2"></i>Add Team Member
  </button>
  <?php else: ?>
  <a href="<?= APP_URL ?>/client/billing.php" class="btn btn-outline-warning">
    <i class="fas fa-arrow-up me-2"></i>Upgrade to Add More
  </a>
  <?php endif; ?>
</div>

<!-- Usage bar -->
<div class="card mb-4">
  <div class="card-body py-3">
    <div class="d-flex justify-content-between mb-1">
      <span class="small fw-600 text-navy">Team Members</span>
      <span class="small text-muted"><?= $currentCount ?> / <?= $maxUsers ?> used</span>
    </div>
    <div class="progress">
      <div class="progress-bar <?= ($currentCount/$maxUsers) >= 0.9 ? 'bg-danger' : '' ?>"
           style="width:<?= min(100, ($currentCount/$maxUsers)*100) ?>%"></div>
    </div>
    <?php if ($currentCount >= $maxUsers): ?>
    <div class="small text-danger mt-1"><i class="fas fa-exclamation-triangle me-1"></i>User limit reached. <a href="<?= APP_URL ?>/client/billing.php">Upgrade your plan</a> to add more members.</div>
    <?php endif; ?>
  </div>
</div>

<!-- Team grid -->
<div class="row g-3">
  <?php foreach($members as $m): ?>
  <div class="col-md-6 col-lg-4">
    <div class="card <?= $m['status'] !== 'active' ? 'opacity-60' : '' ?>">
      <div class="card-body">
        <div class="d-flex align-items-start gap-3">
          <div class="avatar-sm" style="width:48px;height:48px;font-size:.85rem;flex-shrink:0;border-radius:12px;
               background:<?= $m['role']==='client_admin' ? 'var(--navy)' : 'var(--green)' ?>">
            <?= strtoupper(substr($m['name'],0,2)) ?>
          </div>
          <div class="flex-1 overflow-hidden">
            <div class="fw-700 text-navy text-truncate"><?= e($m['name']) ?></div>
            <div class="text-muted small text-truncate"><?= e($m['email']) ?></div>
            <div class="d-flex gap-1 mt-1">
              <span class="badge <?= $m['role']==='client_admin' ? 'bg-primary' : 'bg-secondary' ?>">
                <?= $m['role']==='client_admin' ? 'Admin' : 'Staff' ?>
              </span>
              <?= statusBadge($m['status']) ?>
              <?php if ($m['id'] === $user['id']): ?><span class="badge bg-success">You</span><?php endif; ?>
            </div>
          </div>
        </div>
        <?php if ($m['id'] !== $user['id']): ?>
        <hr class="my-2">
        <div class="d-flex gap-1 flex-wrap">
          <!-- Reset password -->
          <button class="btn btn-xs btn-outline-secondary" onclick="resetMemberPwd(<?= $m['id'] ?>, '<?= e($m['name']) ?>')">
            <i class="fas fa-key me-1"></i>Reset PWD
          </button>
          <!-- Toggle status -->
          <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="<?= $m['status']==='active' ? 'deactivate' : 'reactivate' ?>">
            <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
            <button type="submit" class="btn btn-xs <?= $m['status']==='active' ? 'btn-outline-warning' : 'btn-outline-success' ?>">
              <i class="fas fa-<?= $m['status']==='active' ? 'pause' : 'play' ?> me-1"></i>
              <?= $m['status']==='active' ? 'Deactivate' : 'Reactivate' ?>
            </button>
          </form>
        </div>
        <?php endif; ?>
        <div class="text-muted small mt-2">
          Last login: <?= $m['last_login'] ? timeAgo($m['last_login']) : 'Never' ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Add Member Modal -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add Team Member</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_user">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Full Name *</label>
            <input type="text" name="name" class="form-control" required placeholder="Team member's name">
          </div>
          <div class="mb-3">
            <label class="form-label">Email Address *</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="tel" name="phone" class="form-control" placeholder="+254 700 000 000">
          </div>
          <div class="mb-3">
            <label class="form-label">Role *</label>
            <select name="role" class="form-select" required>
              <option value="staff">Staff Member (limited access)</option>
              <option value="client_admin">Admin (full access)</option>
            </select>
            <div class="form-text">Staff members have read-only access to most areas. Admins can add/edit/delete records.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Temporary Password *</label>
            <input type="password" name="password" class="form-control" required minlength="8" placeholder="They can change it later">
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

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPwdModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-key me-2"></i>Reset Password — <span id="memberName"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" id="resetMemberId">
        <div class="modal-body">
          <label class="form-label">New Password *</label>
          <input type="password" name="new_password" class="form-control" required minlength="8">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning"><i class="fas fa-key me-2"></i>Reset Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function resetMemberPwd(id, name) {
  document.getElementById("resetMemberId").value = id;
  document.getElementById("memberName").textContent = name;
  new bootstrap.Modal(document.getElementById("resetPwdModal")).show();
}
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
