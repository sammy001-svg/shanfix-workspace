<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header-client.php';

$orgId = (int)$user['org_id'];

// Fetch organization info
$orgRow = $pdo->prepare("SELECT * FROM organizations WHERE id=?");
$orgRow->execute([$orgId]);
$org = $orgRow->fetch();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name  = sanitize($_POST['name']  ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        if ($name) {
            $pdo->prepare("UPDATE users SET name=?, phone=? WHERE id=?")->execute([$name, $phone, $user['id']]);
            $_SESSION['user_name'] = $name;
            setFlash('success', 'Profile updated successfully.');
        }
    } elseif (isset($_POST['update_org'])) {
        $orgName = sanitize($_POST['org_name'] ?? '');
        $orgEmail= trim($_POST['org_email'] ?? '');
        $orgPhone= sanitize($_POST['org_phone'] ?? '');
        $orgCity = sanitize($_POST['org_city'] ?? '');
        if ($orgName) {
            $pdo->prepare("UPDATE organizations SET name=?, email=?, phone=?, city=? WHERE id=?")->execute([$orgName, $orgEmail, $orgPhone, $orgCity, $orgId]);
            $_SESSION['org_name'] = $orgName;
            setFlash('success', 'Organization info updated.');
        }
    } elseif (isset($_POST['change_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new1    = $_POST['new_password']     ?? '';
        $new2    = $_POST['new_password2']    ?? '';
        $userRow = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $userRow->execute([$user['id']]);
        $dbHash = $userRow->fetchColumn();
        if (!password_verify($current, $dbHash)) {
            setFlash('danger', 'Current password is incorrect.');
        } elseif (strlen($new1) < 8) {
            setFlash('danger', 'New password must be at least 8 characters.');
        } elseif ($new1 !== $new2) {
            setFlash('danger', 'New passwords do not match.');
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new1, PASSWORD_BCRYPT), $user['id']]);
            setFlash('success', 'Password changed successfully.');
        }
    }
    redirect(APP_URL . '/client/profile.php');
}
?>

<div class="page-header">
  <h4><i class="fas fa-user-circle me-2 text-green"></i>My Profile</h4>
</div>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card text-center mb-3">
      <div class="card-body py-4">
        <div style="width:80px;height:80px;background:var(--navy);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;color:white;font-weight:700;margin:0 auto 1rem">
          <?= strtoupper(substr($user['name'], 0, 2)) ?>
        </div>
        <h5 class="fw-700 text-navy"><?= e($user['name']) ?></h5>
        <p class="text-muted small mb-1"><?= e($user['email']) ?></p>
        <span class="badge bg-success"><?= ucfirst(str_replace('_',' ',$user['role'])) ?></span>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="fas fa-building text-green me-2"></i>Organization</div>
      <div class="card-body">
        <div class="mb-2">
          <div class="fw-600 text-navy"><?= e($org['name'] ?? '') ?></div>
          <div class="text-muted small"><?= e($org['city'] ?? '') ?><?= $org['city'] ? ', ' : '' ?><?= e($org['country'] ?? 'Kenya') ?></div>
        </div>
        <div class="text-muted small"><?= e($org['email'] ?? '') ?></div>
        <div class="text-muted small"><?= e($org['phone'] ?? '') ?></div>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <!-- Personal Info -->
    <div class="card mb-3">
      <div class="card-header"><i class="fas fa-user text-green me-2"></i>Personal Information</div>
      <div class="card-body">
        <form method="POST" class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <input type="text" name="name" class="form-control" value="<?= e($user['name']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email Address</label>
            <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input type="tel" name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>">
          </div>
          <div class="col-12">
            <button type="submit" name="update_profile" class="btn btn-primary">
              <i class="fas fa-save me-2"></i>Update Profile
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Organization Info -->
    <div class="card mb-3">
      <div class="card-header"><i class="fas fa-building text-green me-2"></i>Organization Information</div>
      <div class="card-body">
        <form method="POST" class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Organization Name</label>
            <input type="text" name="org_name" class="form-control" value="<?= e($org['name'] ?? '') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Business Email</label>
            <input type="email" name="org_email" class="form-control" value="<?= e($org['email'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input type="tel" name="org_phone" class="form-control" value="<?= e($org['phone'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">City</label>
            <input type="text" name="org_city" class="form-control" value="<?= e($org['city'] ?? '') ?>">
          </div>
          <div class="col-12">
            <button type="submit" name="update_org" class="btn btn-primary">
              <i class="fas fa-save me-2"></i>Update Organization
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Change Password -->
    <div class="card">
      <div class="card-header"><i class="fas fa-lock text-green me-2"></i>Change Password</div>
      <div class="card-body">
        <form method="POST" class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Current Password</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>
          <div class="col-md-6"></div>
          <div class="col-md-6">
            <label class="form-label">New Password</label>
            <input type="password" name="new_password" class="form-control" required minlength="8">
          </div>
          <div class="col-md-6">
            <label class="form-label">Confirm New Password</label>
            <input type="password" name="new_password2" class="form-control" required>
          </div>
          <div class="col-12">
            <button type="submit" name="change_password" class="btn btn-warning">
              <i class="fas fa-key me-2"></i>Change Password
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
