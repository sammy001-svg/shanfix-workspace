<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header-patient.php';

$errors = [];
$success = '';

// ── Update profile ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['update_profile'])) {
    verifyCsrf();
    $phone   = sanitize($_POST['phone']             ?? '');
    $address = sanitize($_POST['address']           ?? '');
    $emerName= sanitize($_POST['emergency_contact'] ?? '');
    $emerPh  = sanitize($_POST['emergency_phone']   ?? '');
    $insProvider = sanitize($_POST['insurance_provider'] ?? '');
    $insNo   = sanitize($_POST['insurance_no']      ?? '');

    $pdo->prepare("UPDATE health_patients SET phone=?,address=?,emergency_contact=?,emergency_phone=?,insurance_provider=?,insurance_no=? WHERE id=? AND org_id=?")
        ->execute([$phone,$address,$emerName,$emerPh,$insProvider,$insNo,$patientId,$orgId]);
    // Sync phone in users table
    $pdo->prepare("UPDATE users SET phone=? WHERE id=? AND org_id=?")->execute([$phone, $_SESSION['user_id'], $orgId]);
    setFlash('success', 'Profile updated successfully.');
    redirect(APP_URL . '/patient/profile.php');
}

// ── Change password ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['change_password'])) {
    verifyCsrf();
    $current = $_POST['current_password'] ?? '';
    $newPwd  = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $userRow = $pdo->prepare("SELECT password FROM users WHERE id=? AND org_id=?");
    $userRow->execute([(int)$_SESSION['user_id'], $orgId]);
    $userRow = $userRow->fetch();

    if (!$userRow || !password_verify($current, $userRow['password'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($newPwd) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    } elseif ($newPwd !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $pdo->prepare("UPDATE users SET password=? WHERE id=? AND org_id=?")
            ->execute([password_hash($newPwd, PASSWORD_BCRYPT, ['cost'=>12]), (int)$_SESSION['user_id'], $orgId]);
        setFlash('success', 'Password changed successfully.');
        redirect(APP_URL . '/patient/profile.php');
    }
}

$patient = [];
try {
    $s = $pdo->prepare("SELECT * FROM health_patients WHERE id=? AND org_id=?");
    $s->execute([$patientId, $orgId]);
    $patient = $s->fetch() ?: [];
} catch (Throwable $e) {}
?>

<div class="mb-4">
  <h5 class="fw-bold mb-0"><i class="fas fa-user-circle me-2 text-danger"></i>My Profile</h5>
  <p class="text-muted small mb-0">Your personal details and account settings</p>
</div>

<?= flashAlert() ?>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
  <!-- Profile form -->
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-bold"><i class="fas fa-id-card me-2 text-danger"></i>Personal Information</div>
      <div class="card-body">
        <!-- Read-only info -->
        <div class="row g-3 mb-3 pb-3 border-bottom">
          <div class="col-6"><div class="text-muted small">Full Name</div><div class="fw-semibold"><?= e(($patient['first_name'] ?? '').' '.($patient['last_name'] ?? '')) ?></div></div>
          <div class="col-6"><div class="text-muted small">Patient No</div><div class="fw-semibold font-monospace"><?= e($patient['patient_no'] ?? '—') ?></div></div>
          <div class="col-6"><div class="text-muted small">Date of Birth</div><div class="fw-semibold"><?= formatDate($patient['dob'] ?? null) ?></div></div>
          <div class="col-6"><div class="text-muted small">Gender</div><div class="fw-semibold"><?= ucfirst($patient['gender'] ?? '—') ?></div></div>
          <div class="col-6"><div class="text-muted small">Blood Group</div><div class="fw-semibold"><?= $patient['blood_group'] ? '<span class="badge bg-danger">'.$patient['blood_group'].'</span>' : '—' ?></div></div>
          <div class="col-6"><div class="text-muted small">Email</div><div class="fw-semibold small"><?= e($patient['email'] ?? '—') ?></div></div>
          <?php if ($patient['allergies']): ?>
          <div class="col-12"><div class="text-muted small">Known Allergies</div><span class="badge bg-warning text-dark"><?= e($patient['allergies']) ?></span></div>
          <?php endif; ?>
          <?php if ($patient['chronic_conditions']): ?>
          <div class="col-12"><div class="text-muted small">Chronic Conditions</div><span class="badge bg-info"><?= e($patient['chronic_conditions']) ?></span></div>
          <?php endif; ?>
        </div>

        <!-- Editable fields -->
        <form method="POST">
          <?= csrfField() ?><input type="hidden" name="update_profile" value="1">
          <div class="row g-3">
            <div class="col-12"><label class="form-label fw-semibold">Phone Number</label><input type="tel" name="phone" class="form-control" value="<?= e($patient['phone'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label fw-semibold">Residential Address</label><textarea name="address" class="form-control" rows="2"><?= e($patient['address'] ?? '') ?></textarea></div>
            <div class="col-6"><label class="form-label fw-semibold">Emergency Contact</label><input type="text" name="emergency_contact" class="form-control" value="<?= e($patient['emergency_contact'] ?? '') ?>"></div>
            <div class="col-6"><label class="form-label fw-semibold">Emergency Phone</label><input type="tel" name="emergency_phone" class="form-control" value="<?= e($patient['emergency_phone'] ?? '') ?>"></div>
            <div class="col-6"><label class="form-label fw-semibold">Insurance Provider</label><input type="text" name="insurance_provider" class="form-control" value="<?= e($patient['insurance_provider'] ?? '') ?>"></div>
            <div class="col-6"><label class="form-label fw-semibold">Insurance Policy No</label><input type="text" name="insurance_no" class="form-control" value="<?= e($patient['insurance_no'] ?? '') ?>"></div>
            <div class="col-12">
              <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save Changes</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Change password -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-bold"><i class="fas fa-lock me-2 text-danger"></i>Change Password</div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?><input type="hidden" name="change_password" value="1">
          <div class="row g-3">
            <div class="col-12"><label class="form-label fw-semibold">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
            <div class="col-12"><label class="form-label fw-semibold">New Password</label><input type="password" name="new_password" class="form-control" required minlength="8"><div class="form-text">Minimum 8 characters</div></div>
            <div class="col-12"><label class="form-label fw-semibold">Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required></div>
            <div class="col-12"><button type="submit" class="btn btn-outline-danger"><i class="fas fa-key me-1"></i>Change Password</button></div>
          </div>
        </form>
      </div>
    </div>

    <!-- Quick links -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-header fw-bold"><i class="fas fa-link me-2 text-danger"></i>Quick Access</div>
      <div class="list-group list-group-flush">
        <?php
        $links = [
          [APP_URL.'/patient/appointments.php', 'fas fa-calendar-check', 'Book an Appointment'],
          [APP_URL.'/patient/lab-results.php',  'fas fa-flask',          'View Lab Results'],
          [APP_URL.'/patient/bills.php',         'fas fa-receipt',        'Check My Bills'],
          [APP_URL.'/patient/records.php',       'fas fa-file-medical',   'Medical History'],
        ];
        foreach ($links as [$href, $icon, $label]):
        ?>
        <a href="<?= $href ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2 small">
          <i class="<?= $icon ?> text-danger" style="width:16px"></i><?= $label ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer-patient.php'; ?>
