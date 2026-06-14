<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header-doctor.php';

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

// KPIs
$apptToday = $apptPending = $patientsThisMonth = $rxThisMonth = 0;
$todayList = [];

try {
    // Today's appointments
    $s = $pdo->prepare("SELECT COUNT(*) FROM health_appointments WHERE doctor_id=? AND org_id=? AND DATE(appointment_date)=?");
    $s->execute([$docId, $docOrgId, $today]); $apptToday = (int)$s->fetchColumn();

    // Pending appointments today
    $s = $pdo->prepare("SELECT COUNT(*) FROM health_appointments WHERE doctor_id=? AND org_id=? AND DATE(appointment_date)=? AND status='scheduled'");
    $s->execute([$docId, $docOrgId, $today]); $apptPending = (int)$s->fetchColumn();

    // Unique patients this month via appointments
    $s = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) FROM health_appointments WHERE doctor_id=? AND org_id=? AND appointment_date >= ?");
    $s->execute([$docId, $docOrgId, $monthStart]); $patientsThisMonth = (int)$s->fetchColumn();

    // Prescriptions written this month
    $s = $pdo->prepare("SELECT COUNT(*) FROM health_prescriptions WHERE doctor_id=? AND org_id=? AND prescription_date >= ?");
    $s->execute([$docId, $docOrgId, $monthStart]); $rxThisMonth = (int)$s->fetchColumn();

    // Today's appointment list
    $s = $pdo->prepare("
        SELECT a.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no
        FROM health_appointments a
        JOIN health_patients p ON p.id=a.patient_id
        WHERE a.doctor_id=? AND a.org_id=? AND DATE(a.appointment_date)=?
        ORDER BY a.appointment_date ASC
        LIMIT 20
    ");
    $s->execute([$docId, $docOrgId, $today]);
    $todayList = $s->fetchAll();
} catch (Throwable $e) {}

$statusColors = ['scheduled'=>'primary','completed'=>'success','cancelled'=>'secondary','no_show'=>'warning'];
?>

<div class="row g-3 mb-4">
  <!-- KPI cards -->
  <div class="col-6 col-lg-3">
    <div class="doc-stat-card">
      <div class="d-flex align-items-start justify-content-between">
        <div>
          <div class="text-muted small">Today's Appointments</div>
          <div class="fw-bold fs-4"><?= $apptToday ?></div>
        </div>
        <div class="doc-stat-icon" style="background:#e8f0f8;color:#1a4e7c">
          <i class="fas fa-calendar-check"></i>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="doc-stat-card">
      <div class="d-flex align-items-start justify-content-between">
        <div>
          <div class="text-muted small">Pending Today</div>
          <div class="fw-bold fs-4 <?= $apptPending > 0 ? 'text-warning' : '' ?>"><?= $apptPending ?></div>
        </div>
        <div class="doc-stat-icon" style="background:#fff8e6;color:#e67e22">
          <i class="fas fa-clock"></i>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="doc-stat-card">
      <div class="d-flex align-items-start justify-content-between">
        <div>
          <div class="text-muted small">Patients This Month</div>
          <div class="fw-bold fs-4"><?= $patientsThisMonth ?></div>
        </div>
        <div class="doc-stat-icon" style="background:#e8f8f0;color:#1a8a4e">
          <i class="fas fa-procedures"></i>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="doc-stat-card">
      <div class="d-flex align-items-start justify-content-between">
        <div>
          <div class="text-muted small">Prescriptions Written</div>
          <div class="fw-bold fs-4"><?= $rxThisMonth ?></div>
        </div>
        <div class="doc-stat-icon" style="background:#f3e8ff;color:#7c3aed">
          <i class="fas fa-prescription"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Today's queue -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between py-3 px-4">
    <div>
      <h6 class="fw-bold mb-0"><i class="fas fa-list-ul me-2" style="color:var(--doc-blue)"></i>Today's Patient Queue</h6>
      <div class="text-muted small"><?= date('l, d F Y') ?></div>
    </div>
    <a href="<?= APP_URL ?>/doctor/appointments.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <?php if (empty($todayList)): ?>
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-calendar-check fa-2x mb-3 d-block opacity-25"></i>
    <p class="mb-0">No appointments scheduled for today.</p>
  </div>
  <?php else: ?>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>#</th><th>Patient</th><th>Time</th><th>Reason</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($todayList as $i => $a):
            $bg = $statusColors[$a['status']] ?? 'secondary';
          ?>
          <tr>
            <td class="text-muted small"><?= $i+1 ?></td>
            <td>
              <div class="fw-semibold"><?= e($a['patient_name']) ?></div>
              <div class="text-muted small">#<?= e($a['patient_no'] ?? $a['patient_id']) ?></div>
            </td>
            <td class="small fw-semibold"><?= date('H:i', strtotime($a['appointment_date'])) ?></td>
            <td class="small text-muted"><?= e($a['reason'] ?? '—') ?></td>
            <td><span class="badge bg-<?= $bg ?>"><?= ucfirst(str_replace('_',' ',$a['status'])) ?></span></td>
            <td>
              <a href="<?= APP_URL ?>/doctor/records.php?patient_id=<?= $a['patient_id'] ?>&appt_id=<?= $a['id'] ?>"
                 class="btn btn-xs btn-outline-primary">
                <i class="fas fa-file-medical me-1"></i>Consult
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Quick links -->
<div class="row g-3">
  <div class="col-sm-6 col-lg-3">
    <a href="<?= APP_URL ?>/doctor/appointments.php" class="text-decoration-none">
      <div class="doc-stat-card text-center">
        <i class="fas fa-calendar-alt fa-2x mb-2" style="color:var(--doc-blue)"></i>
        <div class="fw-semibold small">My Appointments</div>
      </div>
    </a>
  </div>
  <div class="col-sm-6 col-lg-3">
    <a href="<?= APP_URL ?>/doctor/records.php" class="text-decoration-none">
      <div class="doc-stat-card text-center">
        <i class="fas fa-file-medical fa-2x mb-2 text-success"></i>
        <div class="fw-semibold small">Write Record</div>
      </div>
    </a>
  </div>
  <div class="col-sm-6 col-lg-3">
    <a href="<?= APP_URL ?>/doctor/prescriptions.php?new=1" class="text-decoration-none">
      <div class="doc-stat-card text-center">
        <i class="fas fa-prescription fa-2x mb-2" style="color:#7c3aed"></i>
        <div class="fw-semibold small">New Prescription</div>
      </div>
    </a>
  </div>
  <div class="col-sm-6 col-lg-3">
    <a href="<?= APP_URL ?>/doctor/patients.php" class="text-decoration-none">
      <div class="doc-stat-card text-center">
        <i class="fas fa-procedures fa-2x mb-2 text-info"></i>
        <div class="fw-semibold small">My Patients</div>
      </div>
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer-doctor.php'; ?>
