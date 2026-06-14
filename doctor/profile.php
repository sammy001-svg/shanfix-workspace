<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header-doctor.php';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone       = sanitize($_POST['phone'] ?? '');
    $specialization = sanitize($_POST['specialization'] ?? '');
    $qualification  = sanitize($_POST['qualification'] ?? '');

    try {
        $pdo->prepare("UPDATE health_doctors SET phone=?,specialization=?,qualification=? WHERE id=? AND org_id=?")
            ->execute([$phone, $specialization, $qualification, $docId, $docOrgId]);
        $_SESSION['doc_specialty'] = $specialization;
        $docSpecialty = $specialization;
        setFlash('success', 'Profile updated successfully.');
    } catch (Throwable $e) {
        setFlash('error', 'Could not save changes.');
    }
    redirect(APP_URL . '/doctor/profile.php');
}

// Load full doctor record
$doctor = [];
try {
    $s = $pdo->prepare("SELECT d.*, u.email, u.last_login FROM health_doctors d LEFT JOIN users u ON u.id=d.user_id WHERE d.id=? AND d.org_id=? LIMIT 1");
    $s->execute([$docId, $docOrgId]);
    $doctor = $s->fetch() ?: [];
} catch (Throwable $e) {}

// Stats
$totalAppts = $totalPatients = $totalRx = $totalRecords = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM health_appointments WHERE doctor_id=? AND org_id=?");
    $s->execute([$docId, $docOrgId]); $totalAppts = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) FROM health_appointments WHERE doctor_id=? AND org_id=?");
    $s->execute([$docId, $docOrgId]); $totalPatients = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM health_prescriptions WHERE doctor_id=? AND org_id=?");
    $s->execute([$docId, $docOrgId]); $totalRx = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE doctor_id=? AND org_id=?");
    $s->execute([$docId, $docOrgId]); $totalRecords = (int)$s->fetchColumn();
} catch (Throwable $e) {}
?>

<div class="row g-4">
  <!-- Left: info + edit -->
  <div class="col-lg-4">
    <!-- Avatar card -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body text-center py-4">
        <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle fw-bold text-white"
             style="width:80px;height:80px;font-size:1.8rem;background:linear-gradient(135deg,var(--doc-blue),var(--doc-blue-dark))">
          <?= strtoupper(substr($doctor['first_name'] ?? 'D', 0, 1)) ?>
        </div>
        <div class="fw-bold fs-5"><?= e($docName) ?></div>
        <?php if ($docSpecialty): ?>
        <div class="text-muted small"><?= e($docSpecialty) ?></div>
        <?php endif; ?>
        <div class="mt-2">
          <span class="badge bg-success-subtle text-success border border-success-subtle">
            <i class="fas fa-circle me-1" style="font-size:.5rem"></i>Active
          </span>
        </div>
        <?php if (!empty($doctor['last_login'])): ?>
        <div class="text-muted mt-2" style="font-size:.75rem">
          Last login: <?= date('d M Y H:i', strtotime($doctor['last_login'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Stats -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 py-2 px-3">
        <div class="fw-semibold small">Clinical Summary</div>
      </div>
      <div class="card-body py-2 px-3">
        <?php foreach ([
            ['fas fa-calendar-check','Total Appointments',$totalAppts,'var(--doc-blue)'],
            ['fas fa-procedures','Total Patients',$totalPatients,'#1a8a4e'],
            ['fas fa-prescription','Prescriptions Written',$totalRx,'#7c3aed'],
            ['fas fa-file-medical','Medical Records',$totalRecords,'#0891b2'],
        ] as [$icon, $label, $val, $color]): ?>
        <div class="d-flex align-items-center justify-content-between py-2 border-bottom border-light">
          <div class="d-flex align-items-center gap-2">
            <i class="<?= $icon ?> small" style="color:<?= $color ?>;width:14px"></i>
            <span class="small text-muted"><?= $label ?></span>
          </div>
          <span class="fw-bold small"><?= $val ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Right: edit form -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 py-3 px-4">
        <h6 class="fw-bold mb-0">Profile Information</h6>
        <div class="text-muted small">Update your contact details and specialization</div>
      </div>
      <div class="card-body px-4">
        <form method="POST">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">First Name</label>
              <input type="text" class="form-control form-control-sm" value="<?= e($doctor['first_name'] ?? '') ?>" disabled>
              <div class="form-text">Contact admin to change your name.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Last Name</label>
              <input type="text" class="form-control form-control-sm" value="<?= e($doctor['last_name'] ?? '') ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Email Address</label>
              <input type="email" class="form-control form-control-sm" value="<?= e($doctor['email'] ?? '') ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Phone</label>
              <input type="text" name="phone" class="form-control form-control-sm"
                     value="<?= e($doctor['phone'] ?? '') ?>" placeholder="+254 7XX XXX XXX">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Specialization</label>
              <input type="text" name="specialization" class="form-control form-control-sm"
                     value="<?= e($doctor['specialization'] ?? '') ?>"
                     placeholder="e.g., General Practitioner, Paediatrics">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Qualification</label>
              <input type="text" name="qualification" class="form-control form-control-sm"
                     value="<?= e($doctor['qualification'] ?? '') ?>"
                     placeholder="e.g., MBChB, FRCP, MMed">
            </div>
          </div>
          <div class="mt-4">
            <button type="submit" class="btn btn-primary btn-sm px-4">
              <i class="fas fa-save me-1"></i>Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Password notice -->
    <div class="alert alert-info mt-3 d-flex gap-2 align-items-start" style="font-size:.85rem">
      <i class="fas fa-info-circle mt-1"></i>
      <div>To change your password, contact the clinic administrator. They can reset your credentials from the admin panel.</div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer-doctor.php'; ?>
