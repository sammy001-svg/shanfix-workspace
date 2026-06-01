<?php
$moduleSlug  = 'health';
$moduleName  = 'Health & Clinic';
$moduleIcon  = 'fas fa-heartbeat';
$moduleColor = '#e74c3c';
$moduleNav   = [
    ['url'=>'index.php',         'icon'=>'fas fa-tachometer-alt',      'label'=>'Dashboard'],
    ['url'=>'patients.php',      'icon'=>'fas fa-procedures',          'label'=>'Patients'],
    ['url'=>'appointments.php',  'icon'=>'fas fa-calendar-check',      'label'=>'Appointments'],
    ['url'=>'doctors.php',       'icon'=>'fas fa-user-md',             'label'=>'Doctors'],
    ['url'=>'staff.php',         'icon'=>'fas fa-id-badge',            'label'=>'Clinical Staff'],
    ['url'=>'records.php',       'icon'=>'fas fa-file-medical',        'label'=>'Medical Records'],
    ['url'=>'vitals.php',        'icon'=>'fas fa-heartbeat',           'label'=>'Vital Signs'],
    ['url'=>'lab.php',           'icon'=>'fas fa-flask',               'label'=>'Laboratory'],
    ['url'=>'pharmacy.php',      'icon'=>'fas fa-pills',               'label'=>'Pharmacy'],
    ['url'=>'nursing.php',       'icon'=>'fas fa-user-nurse',          'label'=>'Nursing'],
    ['url'=>'wards.php',         'icon'=>'fas fa-bed',                 'label'=>'Wards & Beds'],
    ['url'=>'admissions.php',    'icon'=>'fas fa-hospital-user',       'label'=>'Admissions (IPD)'],
    ['url'=>'surgery.php',       'icon'=>'fas fa-syringe',             'label'=>'Surgery / Theatre'],
    ['url'=>'emergency.php',     'icon'=>'fas fa-ambulance',           'label'=>'Emergency / Triage'],
    ['url'=>'billing.php',       'icon'=>'fas fa-file-invoice-dollar', 'label'=>'Billing'],
    ['url'=>'telemedicine.php',  'icon'=>'fas fa-video',               'label'=>'Telemedicine'],
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-users',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'AI Analytics'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId   = (int)$user['org_id'];
$bWhere  = branchWhere('branch_id');
$bWhereA = branchWhere('a.branch_id');
$bParams = branchParams();

$totalPatients      = countRows('health_patients',     'org_id = ?' . $bWhere, array_merge([$orgId], $bParams));
$todayAppointments  = countRows('health_appointments', 'org_id = ? AND date = CURDATE()' . $bWhere, array_merge([$orgId], $bParams));
$totalDoctors       = countRows('health_doctors',      'org_id = ? AND status = ?', [$orgId, 'active']);
$totalRecords       = countRows('health_records',      'org_id = ?', [$orgId]);

// Today's appointments
$appointments = [];
try {
    $stmt = $pdo->prepare("SELECT a.*,
                                  CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                                  CONCAT(d.first_name, ' ', d.last_name) AS doctor_name
                           FROM health_appointments a
                           JOIN health_patients p ON a.patient_id = p.id
                           LEFT JOIN health_doctors d ON a.doctor_id = d.id
                           WHERE a.org_id=? AND a.date=CURDATE()" . $bWhereA . "
                           ORDER BY a.time ASC
                           LIMIT 10");
    $stmt->execute(array_merge([$orgId], $bParams));
    $appointments = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage clinical patients, appointments schedules, and electronic medical records</p>
  </div>
  <a href="appointments.php" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-plus me-2"></i>New Appointment</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-procedures"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalPatients ?></div><div class="stat-label">Total Patients</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $todayAppointments ?></div><div class="stat-label">Today's Appointments</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-user-md"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalDoctors ?></div><div class="stat-label">Active Doctors</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-file-medical"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalRecords ?></div><div class="stat-label">Medical Records</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-calendar-check me-2" style="color:<?= $moduleColor ?>"></i>Today's Scheduled Consultations — <?= date('d M Y') ?></h6>
    <a href="appointments.php" class="btn btn-sm btn-outline-danger">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="apptTable">
        <thead class="table-light">
          <tr><th>Consultation Time</th><th>Patient</th><th>Assigned Doctor</th><th>Complaint / Reason</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php if (empty($appointments)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-calendar-day fa-2x mb-2 d-block"></i>No consultations scheduled for today.</td></tr>
          <?php else: foreach ($appointments as $a): ?>
          <tr>
            <td class="fw-semibold text-dark"><i class="far fa-clock me-1"></i><?= date('h:i A', strtotime($a['time'] ?? 'now')) ?></td>
            <td class="fw-bold text-dark"><?= e($a['patient_name'] ?? '—') ?></td>
            <td><?= e($a['doctor_name'] ?? 'External Practitioner') ?></td>
            <td><span class="text-dark small fw-semibold"><?= e($a['complaint'] ?? 'Routine Checkup') ?></span></td>
            <td>
              <?php
              $statuses = ['scheduled' => 'info', 'completed' => 'success', 'cancelled' => 'danger', 'no_show' => 'secondary'];
              $bg = $statuses[$a['status']] ?? 'info';
              ?>
              <span class="badge bg-<?= $bg ?>"><?= ucfirst($a['status'] ?? 'scheduled') ?></span>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#apptTable").DataTable({pageLength:10,order:[[0,"asc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
