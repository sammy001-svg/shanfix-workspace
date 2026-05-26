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
    ['url'=>'records.php',       'icon'=>'fas fa-file-medical',        'label'=>'Medical Records'],
    ['url'=>'vitals.php',        'icon'=>'fas fa-heartbeat',           'label'=>'Vital Signs'],
    ['url'=>'lab.php',           'icon'=>'fas fa-flask',               'label'=>'Laboratory'],
    ['url'=>'pharmacy.php',      'icon'=>'fas fa-pills',               'label'=>'Pharmacy'],
    ['url'=>'nursing.php',       'icon'=>'fas fa-user-nurse',          'label'=>'Nursing'],
    ['url'=>'wards.php',         'icon'=>'fas fa-bed',                 'label'=>'Wards & Beds'],
    ['url'=>'admissions.php',    'icon'=>'fas fa-hospital-user',       'label'=>'Admissions (IPD)'],
    ['url'=>'emergency.php',     'icon'=>'fas fa-ambulance',           'label'=>'Emergency / Triage'],
    ['url'=>'billing.php',       'icon'=>'fas fa-file-invoice-dollar', 'label'=>'Billing'],
    ['url'=>'telemedicine.php',  'icon'=>'fas fa-video',               'label'=>'Telemedicine'],
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-users',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'AI Analytics'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

// Aggregated counts
$totalPatients = countRows('health_patients', 'org_id = ?', [$orgId]);
$totalDoctors  = countRows('health_doctors', "org_id = ? AND status = 'active'", [$orgId]);
$totalRecords  = countRows('health_records', 'org_id = ?', [$orgId]);
$totalAppts    = countRows('health_appointments', 'org_id = ?', [$orgId]);

// Gender demographic stats
$maleCount = countRows('health_patients', "org_id = ? AND gender = 'male'", [$orgId]);
$femaleCount = countRows('health_patients', "org_id = ? AND gender = 'female'", [$orgId]);
$otherCount = countRows('health_patients', "org_id = ? AND gender = 'other'", [$orgId]);

// Appointments status breakdown
$apptScheduled = countRows('health_appointments', "org_id = ? AND status = 'scheduled'", [$orgId]);
$apptCompleted = countRows('health_appointments', "org_id = ? AND status = 'completed'", [$orgId]);
$apptCancelled = countRows('health_appointments', "org_id = ? AND status = 'cancelled'", [$orgId]);
$apptNoShow    = countRows('health_appointments', "org_id = ? AND status = 'no_show'", [$orgId]);

// Highest / most frequent diagnosed pathologies
$frequentDiagnoses = [];
try {
    $stmt = $pdo->prepare("SELECT diagnosis, COUNT(*) AS count 
                           FROM health_records 
                           WHERE org_id = ? 
                           GROUP BY diagnosis 
                           ORDER BY count DESC 
                           LIMIT 5");
    $stmt->execute([$orgId]);
    $frequentDiagnoses = $stmt->fetchAll();
} catch (Exception $e) {}

// Upcoming follow-up alert roster
$followUpsList = [];
try {
    $stmt = $pdo->prepare("SELECT r.follow_up_date, r.diagnosis, 
                                  CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.phone
                           FROM health_records r
                           JOIN health_patients p ON r.patient_id = p.id
                           WHERE r.org_id = ? AND r.follow_up_date >= CURDATE()
                           ORDER BY r.follow_up_date ASC
                           LIMIT 5");
    $stmt->execute([$orgId]);
    $followUpsList = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Clinical Reports & Analytics</h4>
    <p class="text-muted mb-0">Review diagnostic frequencies, patient demographics, and consultation pipelines</p>
  </div>
  <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print Analytics Summary</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-procedures"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalPatients ?></div>
        <div class="stat-label">Registered Patients</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalAppts ?></div>
        <div class="stat-label">Total Appointments</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-file-medical"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalRecords ?></div>
        <div class="stat-label">Medical Charts Recorded</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-user-md"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalDoctors ?></div>
        <div class="stat-label">Active Practitioners</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Consultation Status Breakdown -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-calendar-check me-2 text-danger"></i>Consultation Scheduling Pipelines</h6></div>
      <div class="card-body">
        <div style="height:280px;"><canvas id="apptStatusChart"></canvas></div>
      </div>
    </div>
  </div>

  <!-- Demographics breakdown -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-venus-mars me-2 text-danger"></i>Patient Gender Demographics</h6></div>
      <div class="card-body d-flex flex-column justify-content-center">
        <div style="height:200px;"><canvas id="genderChart"></canvas></div>
        <div class="mt-3 text-center small text-muted">
          Male: <strong><?= $maleCount ?></strong> &nbsp;|&nbsp; 
          Female: <strong><?= $femaleCount ?></strong> &nbsp;|&nbsp;
          Other: <strong><?= $otherCount ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Top Documented Pathologies -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-file-prescription me-2 text-danger"></i>Frequent Clinical Diagnoses</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Diagnosed Pathology</th><th class="text-end">Record Count</th></tr>
            </thead>
            <tbody>
              <?php if (empty($frequentDiagnoses)): ?>
              <tr><td colspan="2" class="text-center text-muted py-4">No diagnostic metrics registered yet.</td></tr>
              <?php else: foreach ($frequentDiagnoses as $fd): ?>
              <tr>
                <td class="fw-semibold text-dark"><?= e($fd['diagnosis']) ?></td>
                <td class="text-end fw-bold text-danger"><?= $fd['count'] ?> cases</td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Upcoming patient follow ups -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-clock me-2 text-danger"></i>Patient Follow-up Checkup Alerts</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Patient Name</th><th>Reason / Pathology</th><th>Scheduled Checkup</th></tr>
            </thead>
            <tbody>
              <?php if (empty($followUpsList)): ?>
              <tr><td colspan="3" class="text-center text-muted py-4">No upcoming patient follow-ups scheduled.</td></tr>
              <?php else: foreach ($followUpsList as $fu): ?>
              <tr>
                <td>
                  <div class="fw-semibold text-dark"><?= e($fu['patient_name']) ?></div>
                  <small class="text-muted"><i class="fas fa-phone me-1 small"></i><?= e($fu['phone']) ?></small>
                </td>
                <td class="small fw-semibold text-dark"><?= e($fu['diagnosis']) ?></td>
                <td><span class="badge bg-danger small"><i class="far fa-calendar-alt me-1"></i><?= formatDate($fu['follow_up_date']) ?></span></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php
$statusLabels = json_encode(['Scheduled', 'Completed', 'Cancelled', 'No Show']);
$statusData   = json_encode([$apptScheduled, $apptCompleted, $apptCancelled, $apptNoShow]);

$genderLabels = json_encode(['Male Patients', 'Female Patients', 'Other']);
$genderData   = json_encode([$maleCount, $femaleCount, $otherCount]);

$extraJs = <<<JS
<script>
// Appointments pipeline status Chart
new Chart(document.getElementById('apptStatusChart'), {
  type: 'bar',
  data: {
    labels: {$statusLabels},
    datasets: [{
      label: 'Consultation Sessions',
      data: {$statusData},
      backgroundColor: ['#0dcaf0', '#198754', '#dc3545', '#6c757d'],
      borderRadius: 4
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 } }
    }
  }
});

// Demographics Doughnut Chart
new Chart(document.getElementById('genderChart'), {
  type: 'doughnut',
  data: {
    labels: {$genderLabels},
    datasets: [{
      data: {$genderData},
      backgroundColor: ['#0b5ed7', '#d63384', '#ffc107']
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' } }
  }
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
