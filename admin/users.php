<?php
$pageTitle = 'User Management';
require_once __DIR__ . '/../includes/header-admin.php';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $name    = sanitize($_POST['name']    ?? '');
        $email   = trim($_POST['email']       ?? '');
        $phone   = sanitize($_POST['phone']   ?? '');
        $role    = in_array($_POST['role'] ?? '', ['client_admin','staff']) ? $_POST['role'] : 'staff';
        $orgId   = (int)($_POST['org_id']     ?? 0);
        $password= $_POST['password']         ?? '';

        if (!$name || !$email || !$password || !$orgId) {
            setFlash('danger', 'All required fields must be filled.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('danger', 'Invalid email address.');
        } else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                setFlash('danger', 'Email already exists.');
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
                $pdo->prepare("INSERT INTO users (org_id,name,email,password,phone,role,status) VALUES (?,?,?,?,?,?,'active')")
                    ->execute([$orgId, $name, $email, $hash, $phone, $role]);
                logActivity('add_user','admin',"Created user: $name ($email)");
                setFlash('success', "User '$name' created successfully.");
            }
        }
        redirect(APP_URL . '/admin/users.php');
    }

    if ($action === 'toggle_status') {
        $uid    = (int)($_POST['user_id'] ?? 0);
        $status = $_POST['status'] === 'active' ? 'inactive' : 'active';
        $pdo->prepare("UPDATE users SET status=? WHERE id=? AND role!='super_admin'")->execute([$status, $uid]);
        setFlash('success', 'User status updated.');
        redirect(APP_URL . '/admin/users.php');
    }

    if ($action === 'reset_password') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $pwd  = $_POST['new_password']  ?? '';
        if (strlen($pwd) < 8) { setFlash('danger','Password must be at least 8 characters.'); }
        else {
            $hash = password_hash($pwd, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password=? WHERE id=? AND role!='super_admin'")->execute([$hash,$uid]);
            setFlash('success','Password reset successfully.');
        }
        redirect(APP_URL . '/admin/users.php');
    }
}

// Fetch all users with org info
$users = $pdo->query("
    SELECT u.*, o.name as org_name
    FROM users u
    LEFT JOIN organizations o ON u.org_id = o.id
    ORDER BY u.created_at DESC
")->fetchAll();

$orgs = $pdo->query("SELECT id, name FROM organizations WHERE status='active' ORDER BY name")->fetchAll();
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-users me-2 text-green"></i>User Management</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="index.php">Dashboard</a></li><li class="breadcrumb-item active">Users</li></ol></nav>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
    <i class="fas fa-user-plus me-2"></i>Add User
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <?php
  $uStats = [
    ['Total Users',        countRows('users','role != ?',['super_admin']),    'navy','fas fa-users','navy-bg'],
    ['Client Admins',      countRows('users',"role='client_admin'"),          'green','fas fa-user-tie','green-bg'],
    ['Staff Members',      countRows('users',"role='staff'"),                 'warning','fas fa-user','warning-bg'],
    ['Inactive',           countRows('users',"status='inactive' AND role!='super_admin'"),'danger','fas fa-user-slash','danger-bg'],
  ];
  foreach($uStats as $s): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card <?= $s[2] ?>">
      <div class="stat-icon <?= $s[4] ?>"><i class="<?= $s[3] ?>"></i></div>
      <div><div class="stat-value"><?= $s[1] ?></div><div class="stat-label"><?= $s[0] ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-center">
      <div class="col-md-3"><input type="text" class="form-control form-control-sm" id="userSearch" placeholder="Search by name or email..."></div>
      <div class="col-md-2">
        <select class="form-select form-select-sm" id="roleFilter">
          <option value="">All Roles</option>
          <option value="client_admin">Client Admin</option>
          <option value="staff">Staff</option>
          <option value="super_admin">Super Admin</option>
        </select>
      </div>
      <div class="col-md-2">
        <select class="form-select form-select-sm" id="statusFilter">
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
    </div>
  </div>
</div>

<!-- Users table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 data-table" id="usersTable">
        <thead>
          <tr><th>#</th><th>User</th><th>Organization</th><th>Role</th><th>Phone</th><th>Last Login</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach($users as $i => $u): ?>
          <?php if($u['role'] === 'super_admin') continue; ?>
          <tr>
            <td class="text-muted small"><?= $i+1 ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="avatar-sm" style="background:<?= $u['role']==='client_admin' ? 'var(--navy)' : 'var(--green)' ?>;font-size:.65rem">
                  <?= strtoupper(substr($u['name'],0,2)) ?>
                </div>
                <div>
                  <div class="fw-600"><?= e($u['name']) ?></div>
                  <div class="text-muted small"><?= e($u['email']) ?></div>
                </div>
              </div>
            </td>
            <td class="small"><?= e($u['org_name'] ?? '—') ?></td>
            <td>
              <?php $roleColors = ['client_admin'=>'primary','staff'=>'secondary','super_admin'=>'danger']; ?>
              <span class="badge bg-<?= $roleColors[$u['role']] ?? 'secondary' ?>"><?= ucwords(str_replace('_',' ',$u['role'])) ?></span>
            </td>
            <td class="small"><?= e($u['phone'] ?? '—') ?></td>
            <td class="small text-muted"><?= $u['last_login'] ? timeAgo($u['last_login']) : 'Never' ?></td>
            <td><?= statusBadge($u['status']) ?></td>
            <td class="small text-muted"><?= formatDate($u['created_at']) ?></td>
            <td>
              <div class="d-flex gap-1">
                <!-- Toggle status -->
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="toggle_status">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <input type="hidden" name="status" value="<?= $u['status'] ?>">
                  <button type="submit" class="btn btn-xs <?= $u['status']==='active' ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                          title="<?= $u['status']==='active' ? 'Deactivate' : 'Activate' ?>">
                    <i class="fas fa-<?= $u['status']==='active' ? 'pause' : 'play' ?>"></i>
                  </button>
                </form>
                <!-- Reset password -->
                <button class="btn btn-xs btn-outline-secondary" title="Reset password"
                        onclick="resetPassword(<?= $u['id'] ?>, '<?= e($u['name']) ?>')">
                  <i class="fas fa-key"></i>
                </button>
                <!-- Edit -->
                <button class="btn btn-xs btn-outline-primary" title="Edit"
                        onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)">
                  <i class="fas fa-edit"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_user">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name *</label>
              <input type="text" name="name" class="form-control" required placeholder="John Doe">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email Address *</label>
              <input type="email" name="email" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input type="tel" name="phone" class="form-control" placeholder="+254 700 000 000">
            </div>
            <div class="col-md-6">
              <label class="form-label">Password *</label>
              <input type="password" name="password" class="form-control" required placeholder="Min. 8 characters">
            </div>
            <div class="col-md-6">
              <label class="form-label">Role *</label>
              <select name="role" class="form-select" required>
                <option value="client_admin">Client Admin</option>
                <option value="staff">Staff Member</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Organization *</label>
              <select name="org_id" class="form-select" required>
                <option value="">Select organization...</option>
                <?php foreach($orgs as $o): ?><option value="<?= $o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Create User</button>
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
        <h5 class="modal-title"><i class="fas fa-key me-2"></i>Reset Password — <span id="resetUserName"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" id="resetUserId">
        <div class="modal-body">
          <label class="form-label">New Password *</label>
          <input type="password" name="new_password" class="form-control" required minlength="8" placeholder="Min. 8 characters">
          <div class="form-text">The user will need to use this new password to log in.</div>
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
function resetPassword(id, name) {
  document.getElementById("resetUserId").value = id;
  document.getElementById("resetUserName").textContent = name;
  new bootstrap.Modal(document.getElementById("resetPwdModal")).show();
}
function editUser(u) {
  // Could open an edit modal — simplified here
  alert("Edit user: " + u.name + "\\nFeature: Edit name, phone, role and org assignment.");
}
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
