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
    ['url'=>'surgery.php',       'icon'=>'fas fa-syringe',             'label'=>'Surgery / Theatre'],
    ['url'=>'emergency.php',     'icon'=>'fas fa-ambulance',           'label'=>'Emergency / Triage'],
    ['url'=>'billing.php',       'icon'=>'fas fa-file-invoice-dollar', 'label'=>'Billing'],
    ['url'=>'timeline.php',      'icon'=>'fas fa-history',             'label'=>'Patient Timeline'],
    ['url'=>'prescription.php',  'icon'=>'fas fa-prescription',        'label'=>'Prescriptions'],
    ['url'=>'telemedicine.php',  'icon'=>'fas fa-video',               'label'=>'Telemedicine'],
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-users',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'AI Analytics'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$orgId     = (int)$user['org_id'];
$patientId = (int)($_GET['patient_id'] ?? 0);
$search    = sanitize($_GET['search'] ?? '');

// Recent patients (shown when no patient selected)
$recentPatients = [];
$selectedPatient = null;

try {
    if ($patientId) {
        $stmt = $pdo->prepare("SELECT * FROM health_patients WHERE id=? AND org_id=?");
        $stmt->execute([$patientId, $orgId]);
        $selectedPatient = $stmt->fetch();
    } elseif ($search) {
        $stmt = $pdo->prepare("SELECT * FROM health_patients WHERE org_id=? AND (CONCAT(first_name,' ',last_name) LIKE ? OR patient_no LIKE ?) ORDER BY first_name LIMIT 20");
        $stmt->execute([$orgId, "%$search%", "%$search%"]);
        $recentPatients = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT p.*, MAX(a.appointment_date) AS last_visit FROM health_patients p LEFT JOIN health_appointments a ON p.id=a.patient_id AND a.org_id=? GROUP BY p.id ORDER BY last_visit DESC LIMIT 10");
        $stmt->execute([$orgId]);
        $recentPatients = $stmt->fetchAll();
    }
} catch (Exception $e) {}

// Collect timeline events for selected patient
$events = [];
if ($selectedPatient) {
    $pid = $selectedPatient['id'];

    // Appointments
    try {
        $stmt = $pdo->prepare("SELECT 'appointment' AS event_type, appointment_date AS event_date, CONCAT('Appointment: ',reason) AS title, CONCAT('Doctor: ',IFNULL(doctor_name,'N/A'),' | Status: ',status) AS detail, id FROM health_appointments WHERE patient_id=? AND org_id=? ORDER BY appointment_date DESC");
        $stmt->execute([$pid, $orgId]);
        foreach ($stmt->fetchAll() as $r) $events[] = $r;
    } catch (Exception $e) {}

    // Medical records
    try {
        $stmt = $pdo->prepare("SELECT 'record' AS event_type, record_date AS event_date, CONCAT('Diagnosis: ',IFNULL(diagnosis,'General')) AS title, CONCAT('Treatment: ',IFNULL(treatment,'—'),'  | Notes: ',IFNULL(notes,'—')) AS detail, id FROM health_records WHERE patient_id=? AND org_id=? ORDER BY record_date DESC");
        $stmt->execute([$pid, $orgId]);
        foreach ($stmt->fetchAll() as $r) $events[] = $r;
    } catch (Exception $e) {}

    // Vitals
    try {
        $stmt = $pdo->prepare("SELECT 'vitals' AS event_type, recorded_at AS event_date, 'Vital Signs Recorded' AS title, CONCAT('BP:',IFNULL(blood_pressure,'—'),' | Temp:',IFNULL(temperature,'—'),'°C | Pulse:',IFNULL(pulse,'—'),' | Wt:',IFNULL(weight,'—'),'kg') AS detail, id FROM health_vitals WHERE patient_id=? AND org_id=? ORDER BY recorded_at DESC");
        $stmt->execute([$pid, $orgId]);
        foreach ($stmt->fetchAll() as $r) $events[] = $r;
    } catch (Exception $e) {}

    // Lab results
    try {
        $stmt = $pdo->prepare("SELECT 'lab' AS event_type, requested_date AS event_date, CONCAT('Lab: ',test_name) AS title, CONCAT('Result: ',IFNULL(result,'Pending'),' | Status: ',status) AS detail, id FROM health_lab WHERE patient_id=? AND org_id=? ORDER BY requested_date DESC");
        $stmt->execute([$pid, $orgId]);
        foreach ($stmt->fetchAll() as $r) $events[] = $r;
    } catch (Exception $e) {}

    // Pharmacy
    try {
        $stmt = $pdo->prepare("SELECT 'pharmacy' AS event_type, dispense_date AS event_date, CONCAT('Pharmacy: ',medicine_name) AS title, CONCAT('Dosage: ',IFNULL(dosage,'—'),' | Qty: ',IFNULL(quantity,'—')) AS detail, id FROM health_pharmacy WHERE patient_id=? AND org_id=? ORDER BY dispense_date DESC");
        $stmt->execute([$pid, $orgId]);
        foreach ($stmt->fetchAll() as $r) $events[] = $r;
    } catch (Exception $e) {}

    // Admissions
    try {
        $stmt = $pdo->prepare("SELECT 'admission' AS event_type, admission_date AS event_date, CONCAT('Admitted: Ward ',IFNULL(ward_name,ward_id)) AS title, CONCAT('Discharged: ',IFNULL(discharge_date,'Active'),' | Diagnosis: ',IFNULL(admitting_diagnosis,'—')) AS detail, id FROM health_admissions WHERE patient_id=? AND org_id=? ORDER BY admission_date DESC");
        $stmt->execute([$pid, $orgId]);
        foreach ($stmt->fetchAll() as $r) $events[] = $r;
    } catch (Exception $e) {}

    // Sort all events by date desc
    usort($events, fn($a, $b) => strcmp($b['event_date'] ?? '', $a['event_date'] ?? ''));
}

$eventConfig = [
    'appointment' => ['color'=>'#3498db', 'icon'=>'fas fa-calendar-check', 'label'=>'Appointment'],
    'record'      => ['color'=>'#e74c3c', 'icon'=>'fas fa-file-medical',   'label'=>'Medical Record'],
    'vitals'      => ['color'=>'#e67e22', 'icon'=>'fas fa-heartbeat',      'label'=>'Vitals'],
    'lab'         => ['color'=>'#9b59b6', 'icon'=>'fas fa-flask',          'label'=>'Lab Result'],
    'pharmacy'    => ['color'=>'#1abc9c', 'icon'=>'fas fa-pills',          'label'=>'Prescription'],
    'admission'   => ['color'=>'#e74c3c', 'icon'=>'fas fa-hospital-user',  'label'=>'Admission'],
];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-history me-2" style="color:<?= $moduleColor ?>"></i>Patient Medical Timeline</h4>
    <p class="text-muted mb-0">Complete chronological history of patient clinical events</p>
  </div>
</div>

<!-- Patient Search -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
      <div class="flex-fill" style="min-width:220px">
        <input type="text" name="search" class="form-control" placeholder="Search by patient name or patient number…" value="<?= e($search) ?>">
      </div>
      <button class="btn btn-outline-secondary"><i class="fas fa-search me-1"></i>Search</button>
      <?php if ($patientId || $search): ?><a href="timeline.php" class="btn btn-link text-muted">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<?php if ($selectedPatient): ?>
<!-- Patient Summary Card -->
<?php
$age = '';
if (!empty($selectedPatient['date_of_birth'])) {
    $age = (int)((time() - strtotime($selectedPatient['date_of_birth'])) / 31536000) . ' yrs';
}
?>
<div class="card mb-4" style="border-left:4px solid <?= $moduleColor ?>">
  <div class="card-body">
    <div class="d-flex align-items-center gap-4 flex-wrap">
      <div class="d-flex align-items-center justify-content-center text-white fw-bold fs-4 rounded-circle flex-shrink-0"
           style="width:60px;height:60px;background:<?= $moduleColor ?>">
        <?= strtoupper(substr($selectedPatient['first_name']??'P',0,1) . substr($selectedPatient['last_name']??'',0,1)) ?>
      </div>
      <div class="flex-fill">
        <h5 class="fw-bold mb-1"><?= e(($selectedPatient['first_name']??'') . ' ' . ($selectedPatient['last_name']??'')) ?></h5>
        <div class="d-flex flex-wrap gap-3 small text-muted">
          <span><i class="fas fa-id-card me-1"></i><?= e($selectedPatient['patient_no'] ?? '—') ?></span>
          <?php if ($age): ?><span><i class="fas fa-birthday-cake me-1"></i><?= $age ?></span><?php endif; ?>
          <?php if (!empty($selectedPatient['blood_group'])): ?><span><i class="fas fa-tint me-1 text-danger"></i><?= e($selectedPatient['blood_group']) ?></span><?php endif; ?>
          <?php if (!empty($selectedPatient['phone'])): ?><span><i class="fas fa-phone me-1"></i><?= e($selectedPatient['phone']) ?></span><?php endif; ?>
          <?php if (!empty($selectedPatient['allergies'])): ?><span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Allergies: <?= e(truncate($selectedPatient['allergies'],40)) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="d-flex gap-2">
        <span class="badge bg-info"><?= count($events) ?> events</span>
        <a href="records.php?patient_id=<?= $patientId ?>" class="btn btn-sm btn-outline-secondary">
          <i class="fas fa-file-medical me-1"></i>Records
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Timeline -->
<?php if (empty($events)): ?>
<div class="text-center text-muted py-5">
  <i class="fas fa-history fa-3x d-block mb-3 opacity-25"></i>
  <div>No clinical events recorded for this patient yet.</div>
</div>
<?php else: ?>
<div class="timeline-container" style="padding-left:20px">
  <?php $lastDate = null; foreach ($events as $ev):
    $cfg   = $eventConfig[$ev['event_type']] ?? ['color'=>'#95a5a6','icon'=>'fas fa-circle','label'=>ucfirst($ev['event_type'])];
    $evDate = substr($ev['event_date'] ?? '', 0, 10);
    $showDate = $evDate !== $lastDate;
    $lastDate = $evDate;
  ?>
  <?php if ($showDate): ?>
  <div class="timeline-date-marker" style="margin:20px 0 8px -20px">
    <span class="badge bg-light text-dark border fw-semibold px-3 py-2 small">
      <i class="fas fa-calendar me-1"></i><?= formatDate($evDate) ?>
    </span>
  </div>
  <?php endif; ?>
  <div class="timeline-item d-flex gap-3 mb-3" style="position:relative">
    <div class="timeline-icon flex-shrink-0 d-flex align-items-center justify-content-center text-white rounded-circle"
         style="width:36px;height:36px;background:<?= $cfg['color'] ?>;margin-top:2px;z-index:1">
      <i class="<?= $cfg['icon'] ?>" style="font-size:.8rem"></i>
    </div>
    <div class="timeline-content card flex-fill" style="border-left:3px solid <?= $cfg['color'] ?>">
      <div class="card-body py-2 px-3">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div>
            <span class="badge mb-1" style="background:<?= $cfg['color'] ?>20;color:<?= $cfg['color'] ?>;border:1px solid <?= $cfg['color'] ?>40;font-size:.68rem"><?= $cfg['label'] ?></span>
            <div class="fw-semibold small"><?= e($ev['title']) ?></div>
            <div class="text-muted" style="font-size:.78rem"><?= e($ev['detail']) ?></div>
          </div>
          <div class="text-muted flex-shrink-0" style="font-size:.7rem;white-space:nowrap">
            <?= $ev['event_date'] ? date('H:i', strtotime($ev['event_date'])) : '' ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif (!empty($recentPatients)): ?>
<!-- Patient list (search results or recent) -->
<div class="card">
  <div class="card-header fw-semibold small"><?= $search ? 'Search Results' : 'Recent Patients' ?></div>
  <div class="row g-3 card-body">
    <?php foreach ($recentPatients as $p): ?>
    <div class="col-md-6 col-lg-4">
      <a href="?patient_id=<?= $p['id'] ?>" class="text-decoration-none">
        <div class="card h-100 border hover-shadow">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center text-white fw-bold rounded-circle flex-shrink-0"
                 style="width:44px;height:44px;background:<?= $moduleColor ?>">
              <?= strtoupper(substr($p['first_name']??'P',0,1).substr($p['last_name']??'',0,1)) ?>
            </div>
            <div>
              <div class="fw-semibold small"><?= e(($p['first_name']??'').' '.($p['last_name']??'')) ?></div>
              <div class="text-muted" style="font-size:.75rem"><?= e($p['patient_no']??'') ?></div>
              <?php if (!empty($p['last_visit'])): ?>
              <div class="text-muted" style="font-size:.7rem"><i class="fas fa-clock me-1"></i>Last visit: <?= formatDate($p['last_visit']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-history fa-3x d-block mb-3 opacity-25"></i>
    <div class="fw-semibold mb-1">Search for a patient to view their timeline</div>
    <div class="small">Enter a name or patient number in the search bar above.</div>
  </div>
</div>
<?php endif; ?>

<style>
.timeline-container::before { content:''; position:absolute; left:38px; top:0; bottom:0; width:2px; background:#e2e8f0; }
.timeline-container { position:relative; }
.hover-shadow:hover { box-shadow:0 4px 16px rgba(0,0,0,.1); transform:translateY(-1px); transition:all .15s; }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
