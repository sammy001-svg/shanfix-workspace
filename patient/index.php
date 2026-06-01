<?php
$pageTitle = 'My Dashboard';
require_once __DIR__ . '/../includes/header-patient.php';

// ── Dashboard data ────────────────────────────────────────────────
$upcomingAppts = $recentRecords = $recentLab = [];
$latestVitals  = [];
$pendingBills  = 0;
$activePrescriptions = 0;

// Next appointment
try {
    $s = $pdo->prepare("
        SELECT a.date, a.time, a.type, a.status,
               CONCAT(d.first_name,' ',d.last_name) AS doctor_name, d.specialization
        FROM health_appointments a
        LEFT JOIN health_doctors d ON a.doctor_id=d.id
        WHERE a.patient_id=? AND a.org_id=? AND a.date>=CURDATE() AND a.status='scheduled'
        ORDER BY a.date ASC, a.time ASC LIMIT 3
    ");
    $s->execute([$patientId, $orgId]);
    $upcomingAppts = $s->fetchAll();
} catch (Throwable $e) {}

// Recent records
try {
    $s = $pdo->prepare("
        SELECT r.diagnosis, r.treatment, r.created_at,
               CONCAT(d.first_name,' ',d.last_name) AS doctor_name
        FROM health_records r
        LEFT JOIN health_doctors d ON r.doctor_id=d.id
        WHERE r.patient_id=? AND r.org_id=?
        ORDER BY r.created_at DESC LIMIT 3
    ");
    $s->execute([$patientId, $orgId]);
    $recentRecords = $s->fetchAll();
} catch (Throwable $e) {}

// Latest vitals
try {
    $s = $pdo->prepare("SELECT * FROM health_vitals WHERE patient_id=? AND org_id=? ORDER BY recorded_at DESC LIMIT 1");
    $s->execute([$patientId, $orgId]);
    $latestVitals = $s->fetch() ?: [];
} catch (Throwable $e) {}

// Pending bills
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM health_bills WHERE patient_id=? AND org_id=? AND status NOT IN ('paid','cancelled')");
    $s->execute([$patientId, $orgId]);
    $pendingBills = (float)$s->fetchColumn();
} catch (Throwable $e) {}

// Recent lab results
try {
    $s = $pdo->prepare("
        SELECT lo.result, lo.status, lo.created_at, lt.name AS test_name
        FROM health_lab_orders lo
        JOIN health_lab_tests lt ON lo.test_id=lt.id
        WHERE lo.patient_id=? AND lo.org_id=? AND lo.status='completed'
        ORDER BY lo.created_at DESC LIMIT 3
    ");
    $s->execute([$patientId, $orgId]);
    $recentLab = $s->fetchAll();
} catch (Throwable $e) {}

// Active prescriptions
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM health_mar WHERE patient_id=? AND org_id=? AND status='active'");
    $s->execute([$patientId, $orgId]);
    $activePrescriptions = (int)$s->fetchColumn();
} catch (Throwable $e) {}
?>

<!-- Welcome -->
<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold fs-4 flex-shrink-0"
       style="width:56px;height:56px;background:var(--pat-red)">
    <?= strtoupper(substr($patName, 0, 1)) ?>
  </div>
  <div>
    <h4 class="mb-0 fw-bold">Hello, <?= e(explode(' ', $patName)[0]) ?> 👋</h4>
    <p class="text-muted mb-0 small">Welcome to your health dashboard — <?= date('l, d F Y') ?></p>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="pat-stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="pat-stat-icon" style="background:#fde8e8"><i class="fas fa-calendar-check" style="color:var(--pat-red)"></i></div>
        <div><div class="fs-3 fw-bold lh-1"><?= count($upcomingAppts) ?></div><div class="text-muted small">Upcoming Appts</div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="pat-stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="pat-stat-icon" style="background:#d4edda"><i class="fas fa-pills" style="color:#27ae60"></i></div>
        <div><div class="fs-3 fw-bold lh-1 text-success"><?= $activePrescriptions ?></div><div class="text-muted small">Active Meds</div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="pat-stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="pat-stat-icon" style="background:#fff3cd"><i class="fas fa-receipt" style="color:#f39c12"></i></div>
        <div><div class="fs-3 fw-bold lh-1 text-warning"><?= formatCurrency($pendingBills) ?></div><div class="text-muted small">Pending Bills</div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="pat-stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="pat-stat-icon" style="background:#cce5ff"><i class="fas fa-flask" style="color:#3498db"></i></div>
        <div><div class="fs-3 fw-bold lh-1 text-primary"><?= count($recentLab) ?></div><div class="text-muted small">Lab Results</div></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Left column -->
  <div class="col-lg-8">

    <!-- Upcoming appointments -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-calendar-check me-2 text-danger"></i>Upcoming Appointments</h6>
        <a href="<?= APP_URL ?>/patient/appointments.php" class="btn btn-sm btn-outline-danger">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($upcomingAppts)): ?>
        <div class="text-center py-4 text-muted small"><i class="fas fa-calendar-times d-block mb-1 fa-2x opacity-25"></i>No upcoming appointments</div>
        <?php else: foreach ($upcomingAppts as $a): ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
          <div class="text-center flex-shrink-0" style="width:48px">
            <div class="fw-bold text-danger" style="font-size:1.2rem"><?= date('d', strtotime($a['date'])) ?></div>
            <div class="text-muted small"><?= date('M', strtotime($a['date'])) ?></div>
          </div>
          <div class="flex-fill">
            <div class="fw-semibold small"><?= e($a['type']) ?></div>
            <div class="text-muted small"><i class="fas fa-user-md me-1"></i><?= e($a['doctor_name'] ?: 'Duty Physician') ?> &nbsp;·&nbsp; <i class="fas fa-clock me-1"></i><?= date('h:i A', strtotime($a['time'])) ?></div>
          </div>
          <span class="badge bg-info">Scheduled</span>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Recent records -->
    <div class="card border-0 shadow-sm">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-file-medical me-2 text-danger"></i>Recent Medical Records</h6>
        <a href="<?= APP_URL ?>/patient/records.php" class="btn btn-sm btn-outline-danger">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($recentRecords)): ?>
        <div class="text-center py-4 text-muted small"><i class="fas fa-file-medical d-block mb-1 fa-2x opacity-25"></i>No records yet</div>
        <?php else: foreach ($recentRecords as $r): ?>
        <div class="px-3 py-2 border-bottom">
          <div class="d-flex justify-content-between">
            <div class="fw-semibold small"><?= e(truncate($r['diagnosis'] ?: 'General Consultation', 60)) ?></div>
            <span class="text-muted small"><?= date('d M Y', strtotime($r['created_at'])) ?></span>
          </div>
          <div class="text-muted small"><i class="fas fa-user-md me-1"></i><?= e($r['doctor_name'] ?: '—') ?></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  </div><!-- /left -->

  <!-- Right column -->
  <div class="col-lg-4">

    <!-- Latest vitals -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-heartbeat me-2 text-danger"></i>Latest Vitals</h6>
        <a href="<?= APP_URL ?>/patient/vitals.php" class="btn btn-sm btn-outline-danger">History</a>
      </div>
      <div class="card-body">
        <?php if (empty($latestVitals)): ?>
        <div class="text-center text-muted small py-2"><i class="fas fa-heartbeat d-block mb-1 fa-2x opacity-25"></i>No vitals recorded</div>
        <?php else: ?>
        <div class="text-muted small mb-2"><i class="fas fa-clock me-1"></i><?= formatDate($latestVitals['recorded_at'] ?? '') ?></div>
        <?php $vitalItems = [
          ['Blood Pressure', ($latestVitals['bp_systolic'] ?? '') && ($latestVitals['bp_diastolic'] ?? '') ? $latestVitals['bp_systolic'].'/'.$latestVitals['bp_diastolic'].' mmHg' : '—', 'heart'],
          ['Pulse Rate',  !empty($latestVitals['pulse'])       ? $latestVitals['pulse'].' bpm'   : '—', 'heartbeat'],
          ['Temperature', !empty($latestVitals['temperature']) ? $latestVitals['temperature'].'°C': '—', 'thermometer-half'],
          ['Weight',      !empty($latestVitals['weight'])      ? $latestVitals['weight'].' kg'   : '—', 'weight'],
          ['SPO2',        !empty($latestVitals['spo2'])        ? $latestVitals['spo2'].'%'       : '—', 'lungs'],
        ]; ?>
        <?php foreach ($vitalItems as [$label, $val, $icon]): ?>
        <div class="d-flex justify-content-between py-1 border-bottom">
          <span class="small text-muted"><i class="fas fa-<?= $icon ?> me-1"></i><?= $label ?></span>
          <span class="small fw-semibold"><?= e($val) ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Lab results -->
    <div class="card border-0 shadow-sm">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-flask me-2 text-danger"></i>Recent Lab Results</h6>
        <a href="<?= APP_URL ?>/patient/lab-results.php" class="btn btn-sm btn-outline-danger">All</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($recentLab)): ?>
        <div class="text-center py-4 text-muted small"><i class="fas fa-flask d-block mb-1 fa-2x opacity-25"></i>No lab results yet</div>
        <?php else: foreach ($recentLab as $l): ?>
        <div class="px-3 py-2 border-bottom">
          <div class="fw-semibold small"><?= e($l['test_name']) ?></div>
          <div class="text-muted small"><?= e(truncate($l['result'] ?? '—', 50)) ?></div>
          <div class="text-muted" style="font-size:.68rem"><?= date('d M Y', strtotime($l['created_at'])) ?></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  </div><!-- /right -->
</div>

<?php require_once __DIR__ . '/../includes/footer-patient.php'; ?>
