<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header-admin.php';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name  = sanitize($_POST['name']  ?? '');
        $email = trim($_POST['email']     ?? '');
        $phone = sanitize($_POST['phone'] ?? '');

        if (!$name || !$email) {
            setFlash('danger', 'Name and email are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('danger', 'Invalid email address.');
        } else {
            // Check email uniqueness (excluding self)
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");
            $chk->execute([$email, $user['id']]);
            if ($chk->fetch()) {
                setFlash('danger', 'Email is already in use by another account.');
            } else {
                $pdo->prepare("UPDATE users SET name=?, email=?, phone=? WHERE id=?")
                    ->execute([$name, $email, $phone, $user['id']]);
                // Update session
                $_SESSION['name']  = $name;
                $_SESSION['email'] = $email;
                logActivity('update', 'admin', 'Updated admin profile');
                setFlash('success', 'Profile updated successfully.');
            }
        }
        redirect(APP_URL . '/admin/profile.php');
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($new) < 8) {
            setFlash('danger', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            setFlash('danger', 'New passwords do not match.');
        } else {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id=?");
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($current, $row['password'])) {
                setFlash('danger', 'Current password is incorrect.');
            } else {
                $pdo->prepare("UPDATE users SET password=? WHERE id=?")
                    ->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
                logActivity('security', 'admin', 'Changed own password');
                setFlash('success', 'Password changed successfully.');
            }
        }
        redirect(APP_URL . '/admin/profile.php');
    }
}

// Fetch fresh user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

// Recent activity
$actStmt = $pdo->prepare("SELECT * FROM activity_log WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$actStmt->execute([$user['id']]);
$recentActivity = $actStmt->fetchAll();
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-user-circle me-2 text-green"></i>My Profile</h4>
    <p class="text-muted mb-0">Manage your administrator account</p>
  </div>
</div>

<div class="row g-4">
  <!-- Left: Avatar & Info -->
  <div class="col-lg-4">
    <div class="card text-center mb-4">
      <div class="card-body py-4">
        <div class="avatar-xl mx-auto mb-3" style="width:80px;height:80px;font-size:1.8rem;border-radius:20px;background:var(--navy)">
          <?= strtoupper(substr($profile['name'], 0, 2)) ?>
        </div>
        <h5 class="fw-700 text-navy mb-1"><?= e($profile['name']) ?></h5>
        <span class="badge bg-danger">Super Admin</span>
        <hr>
        <div class="text-start small">
          <div class="d-flex gap-2 mb-2">
            <i class="fas fa-envelope text-muted mt-1"></i>
            <span><?= e($profile['email']) ?></span>
          </div>
          <?php if ($profile['phone']): ?>
          <div class="d-flex gap-2 mb-2">
            <i class="fas fa-phone text-muted mt-1"></i>
            <span><?= e($profile['phone']) ?></span>
          </div>
          <?php endif; ?>
          <div class="d-flex gap-2 mb-2">
            <i class="fas fa-calendar text-muted mt-1"></i>
            <span>Joined <?= formatDate($profile['created_at'] ?? '') ?></span>
          </div>
          <div class="d-flex gap-2">
            <i class="fas fa-clock text-muted mt-1"></i>
            <span>Last login: <?= $profile['last_login'] ? timeAgo($profile['last_login']) : 'N/A' ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent Activity -->
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="fas fa-history me-2"></i>Recent Activity</h6>
      </div>
      <div class="card-body p-0">
        <?php if (empty($recentActivity)): ?>
        <div class="p-3 text-center text-muted small">No activity yet.</div>
        <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach($recentActivity as $act): ?>
          <div class="list-group-item px-3 py-2">
            <div class="small fw-600"><?= e($act['action']) ?> <span class="badge bg-secondary ms-1"><?= e($act['module']) ?></span></div>
            <div class="text-muted" style="font-size:.78rem"><?= e($act['description']) ?></div>
            <div class="text-muted" style="font-size:.72rem"><?= timeAgo($act['created_at']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: Edit Forms -->
  <div class="col-lg-8">
    <!-- Profile Update -->
    <div class="card mb-4">
      <div class="card-header">
        <h6 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Profile Information</h6>
      </div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_profile">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name *</label>
              <input type="text" name="name" class="form-control" value="<?= e($profile['name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email Address *</label>
              <input type="email" name="email" class="form-control" value="<?= e($profile['email']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone Number</label>
              <input type="tel" name="phone" class="form-control" value="<?= e($profile['phone'] ?? '') ?>" placeholder="+254 700 000 000">
            </div>
            <div class="col-md-6">
              <label class="form-label">Role</label>
              <input type="text" class="form-control" value="Super Administrator" disabled>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Change Password -->
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="fas fa-lock me-2"></i>Change Password</h6>
      </div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Current Password *</label>
              <input type="password" name="current_password" class="form-control" required placeholder="Enter your current password">
            </div>
            <div class="col-md-6">
              <label class="form-label">New Password *</label>
              <input type="password" name="new_password" class="form-control" required minlength="8" placeholder="At least 8 characters">
            </div>
            <div class="col-md-6">
              <label class="form-label">Confirm New Password *</label>
              <input type="password" name="confirm_password" class="form-control" required minlength="8" placeholder="Repeat new password">
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-warning"><i class="fas fa-key me-2"></i>Change Password</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
