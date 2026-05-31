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
    ['url'=>'telemedicine.php',  'icon'=>'fas fa-video',               'label'=>'Telemedicine'],
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-users',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'AI Analytics'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
];

// ── AJAX: fetch surgery record ────────────────────────────────────
if (isset($_GET['fetch'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_surgeries WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── AJAX: fetch theatre record ────────────────────────────────────
if (isset($_GET['fetch_theatre'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_theatre'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_theatres WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── POST handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $uid    = (int)$user['id'];
    $action = $_POST['action'] ?? '';

    // ── Schedule / update a surgery ──────────────────────────────
    if ($action === 'save_surgery') {
        $id            = (int)($_POST['id'] ?? 0);
        $patientId     = (int)($_POST['patient_id'] ?? 0);
        $admissionId   = (int)($_POST['admission_id'] ?? 0) ?: null;
        $theatreId     = (int)($_POST['theatre_id']  ?? 0) ?: null;
        $surgeonId     = (int)($_POST['surgeon_id']  ?? 0) ?: null;
        $anaestId      = (int)($_POST['anaesthetist_id'] ?? 0) ?: null;
        $procedure     = sanitize($_POST['procedure_name'] ?? '');
        $procType      = in_array($_POST['procedure_type'] ?? '', ['elective','emergency','urgent']) ? $_POST['procedure_type'] : 'elective';
        $priority      = in_array($_POST['priority'] ?? '', ['routine','urgent','emergency']) ? $_POST['priority'] : 'routine';
        $scheduledAt   = $_POST['scheduled_at'] ?? date('Y-m-d H:i:s');
        $estMin        = max(1, (int)($_POST['estimated_duration_min'] ?? 60));
        $anaesthType   = in_array($_POST['anaesthesia_type'] ?? '', ['general','local','regional','spinal','epidural','sedation']) ? $_POST['anaesthesia_type'] : 'general';
        $preOpNotes    = sanitize($_POST['pre_op_notes'] ?? '');

        if (!$patientId || !$procedure) {
            setFlash('error', 'Patient and procedure name are required.');
            redirect('surgery.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE health_surgeries SET patient_id=?,admission_id=?,theatre_id=?,surgeon_id=?,anaesthetist_id=?,procedure_name=?,procedure_type=?,priority=?,scheduled_at=?,estimated_duration_min=?,anaesthesia_type=?,pre_op_notes=? WHERE id=? AND org_id=?")
                ->execute([$patientId,$admissionId,$theatreId,$surgeonId,$anaestId,$procedure,$procType,$priority,$scheduledAt,$estMin,$anaesthType,$preOpNotes,$id,$orgId]);
            setFlash('success', 'Surgery record updated.');
        } else {
            $yr     = date('Y');
            $seqSt  = $pdo->prepare("SELECT COUNT(*)+1 FROM health_surgeries WHERE org_id=? AND YEAR(created_at)=?");
            $seqSt->execute([$orgId, $yr]);
            $surgNo = 'SURG-' . $yr . '-' . str_pad((int)$seqSt->fetchColumn(), 4, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO health_surgeries (org_id,surgery_no,patient_id,admission_id,theatre_id,surgeon_id,anaesthetist_id,procedure_name,procedure_type,priority,scheduled_at,estimated_duration_min,anaesthesia_type,pre_op_notes,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'scheduled',?)")
                ->execute([$orgId,$surgNo,$patientId,$admissionId,$theatreId,$surgeonId,$anaestId,$procedure,$procType,$priority,$scheduledAt,$estMin,$anaesthType,$preOpNotes,$uid]);
            setFlash('success', "Surgery scheduled — Ref: {$surgNo}");
        }
        logActivity($id > 0 ? 'update' : 'create', 'health', 'Surgery ' . ($id > 0 ? "updated #$id" : "scheduled: $procedure"));
        redirect('surgery.php');
    }

    // ── Update status + clinical notes ────────────────────────────
    if ($action === 'update_status') {
        $id           = (int)($_POST['id'] ?? 0);
        $validStatuses = ['scheduled','pre-op','in-progress','recovery','completed','cancelled','postponed'];
        $status       = in_array($_POST['status'] ?? '', $validStatuses) ? $_POST['status'] : '';
        $preOp        = sanitize($_POST['pre_op_notes']    ?? '');
        $intraOp      = sanitize($_POST['intra_op_notes']  ?? '');
        $postOp       = sanitize($_POST['post_op_notes']   ?? '');
        $complications= sanitize($_POST['complications']   ?? '');
        $bloodUnits   = max(0, (float)($_POST['blood_units_used'] ?? 0));
        $actualStart  = !empty($_POST['actual_start']) ? $_POST['actual_start'] : null;
        $actualEnd    = !empty($_POST['actual_end'])   ? $_POST['actual_end']   : null;

        if ($id && $status) {
            $pdo->prepare("UPDATE health_surgeries SET status=?,pre_op_notes=?,intra_op_notes=?,post_op_notes=?,complications=?,blood_units_used=?,actual_start=?,actual_end=? WHERE id=? AND org_id=?")
                ->execute([$status,$preOp,$intraOp,$postOp,$complications,$bloodUnits,$actualStart,$actualEnd,$id,$orgId]);

            // Theatre status tracking
            if ($status === 'in-progress') {
                $pdo->prepare("UPDATE health_theatres t JOIN health_surgeries s ON s.theatre_id=t.id SET t.status='in-use' WHERE s.id=? AND t.org_id=?")
                    ->execute([$id, $orgId]);
            } elseif (in_array($status, ['completed','cancelled','postponed'])) {
                $pdo->prepare("UPDATE health_theatres t JOIN health_surgeries s ON s.theatre_id=t.id SET t.status='available' WHERE s.id=? AND t.org_id=? AND t.status='in-use'")
                    ->execute([$id, $orgId]);
            }
            setFlash('success', 'Surgery record updated.');
        }
        logActivity('update', 'health', "Surgery #$id status → $status");
        redirect('surgery.php');
    }

    // ── Cancel surgery ────────────────────────────────────────────
    if ($action === 'cancel_surgery') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE health_surgeries SET status='cancelled' WHERE id=? AND org_id=? AND status IN ('scheduled','pre-op','postponed')")
                ->execute([$id, $orgId]);
            setFlash('success', 'Surgery cancelled.');
        }
        redirect('surgery.php');
    }

    // ── Save / update theatre ─────────────────────────────────────
    if ($action === 'save_theatre') {
        $id     = (int)($_POST['theatre_id'] ?? 0);
        $name   = sanitize($_POST['name'] ?? '');
        $ttype  = in_array($_POST['theatre_type'] ?? '', ['general','orthopaedic','cardiac','obstetric','neurology','ophthalmic','ent','emergency','endoscopy','other']) ? $_POST['theatre_type'] : 'general';
        $cap    = max(1, (int)($_POST['capacity'] ?? 1));
        $tstatus= in_array($_POST['tstatus'] ?? '', ['available','maintenance','inactive']) ? $_POST['tstatus'] : 'available';
        $notes  = sanitize($_POST['notes'] ?? '');

        if (!$name) { setFlash('error', 'Theatre name is required.'); redirect('surgery.php?tab=theatres'); }

        if ($id > 0) {
            $pdo->prepare("UPDATE health_theatres SET name=?,theatre_type=?,capacity=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$name,$ttype,$cap,$tstatus,$notes,$id,$orgId]);
            setFlash('success', 'Theatre updated.');
        } else {
            $pdo->prepare("INSERT INTO health_theatres (org_id,name,theatre_type,capacity,status,notes) VALUES (?,?,?,?,?,?)")
                ->execute([$orgId,$name,$ttype,$cap,$tstatus,$notes]);
            setFlash('success', "Theatre '{$name}' added.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'health', 'Theatre ' . ($id > 0 ? "updated #$id" : "added: $name"));
        redirect('surgery.php?tab=theatres');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Auto-create tables on first use ───────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_theatres` (
        `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `org_id`       INT UNSIGNED NOT NULL,
        `name`         VARCHAR(100) NOT NULL,
        `theatre_type` ENUM('general','orthopaedic','cardiac','obstetric','neurology','ophthalmic','ent','emergency','endoscopy','other') NOT NULL DEFAULT 'general',
        `status`       ENUM('available','in-use','maintenance','inactive') NOT NULL DEFAULT 'available',
        `capacity`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `notes`        TEXT,
        `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_th_org (org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_surgeries` (
        `id`                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `org_id`                 INT UNSIGNED NOT NULL,
        `surgery_no`             VARCHAR(30) NOT NULL,
        `patient_id`             INT UNSIGNED NOT NULL,
        `admission_id`           INT UNSIGNED DEFAULT NULL,
        `theatre_id`             INT UNSIGNED DEFAULT NULL,
        `surgeon_id`             INT UNSIGNED DEFAULT NULL,
        `anaesthetist_id`        INT UNSIGNED DEFAULT NULL,
        `procedure_name`         VARCHAR(200) NOT NULL,
        `procedure_type`         ENUM('elective','emergency','urgent') NOT NULL DEFAULT 'elective',
        `priority`               ENUM('routine','urgent','emergency') NOT NULL DEFAULT 'routine',
        `scheduled_at`           DATETIME NOT NULL,
        `estimated_duration_min` SMALLINT UNSIGNED DEFAULT 60,
        `actual_start`           DATETIME DEFAULT NULL,
        `actual_end`             DATETIME DEFAULT NULL,
        `status`                 ENUM('scheduled','pre-op','in-progress','recovery','completed','cancelled','postponed') NOT NULL DEFAULT 'scheduled',
        `anaesthesia_type`       ENUM('general','local','regional','spinal','epidural','sedation') DEFAULT 'general',
        `pre_op_notes`           TEXT,
        `intra_op_notes`         TEXT,
        `post_op_notes`          TEXT,
        `complications`          TEXT,
        `blood_units_used`       DECIMAL(5,2) DEFAULT 0.00,
        `created_by`             INT UNSIGNED DEFAULT NULL,
        `created_at`             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at`             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sg_org (org_id),
        INDEX idx_sg_patient (patient_id),
        INDEX idx_sg_sched (scheduled_at),
        INDEX idx_sg_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$activeTab = ($_GET['tab'] ?? '') === 'theatres' ? 'theatres' : 'schedule';
$today     = date('Y-m-d');

// ── Stats ─────────────────────────────────────────────────────────
$statToday = $statInProg = $statDone = $statAvailTheatres = 0;
try {
    $sr = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(status = 'in-progress') AS inprog,
        SUM(status = 'completed' AND DATE(COALESCE(actual_end, scheduled_at)) = ?) AS done_today
        FROM health_surgeries WHERE org_id=? AND DATE(scheduled_at)=?");
    $sr->execute([$today, $orgId, $today]);
    $srow = $sr->fetch();
    $statToday  = (int)($srow['total']     ?? 0);
    $statInProg = (int)($srow['inprog']    ?? 0);
    $statDone   = (int)($srow['done_today']?? 0);
} catch (Throwable $e) {}
try {
    $tr = $pdo->prepare("SELECT COUNT(*) FROM health_theatres WHERE org_id=? AND status='available'");
    $tr->execute([$orgId]);
    $statAvailTheatres = (int)$tr->fetchColumn();
} catch (Throwable $e) {}

// ── Surgeries list ────────────────────────────────────────────────
$surgeries = [];
try {
    $sq = $pdo->prepare("
        SELECT s.*,
            CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
            t.name AS theatre_name, t.theatre_type,
            CONCAT(d1.first_name,' ',d1.last_name) AS surgeon_name, d1.specialization AS surgeon_spec,
            CONCAT(d2.first_name,' ',d2.last_name) AS anaest_name,
            a.admission_no
        FROM health_surgeries s
        JOIN  health_patients  p  ON s.patient_id       = p.id
        LEFT JOIN health_theatres  t  ON s.theatre_id       = t.id
        LEFT JOIN health_doctors   d1 ON s.surgeon_id       = d1.id
        LEFT JOIN health_doctors   d2 ON s.anaesthetist_id  = d2.id
        LEFT JOIN health_admissions a ON s.admission_id     = a.id
        WHERE s.org_id=?
        ORDER BY s.scheduled_at DESC
        LIMIT 200
    ");
    $sq->execute([$orgId]);
    $surgeries = $sq->fetchAll();
} catch (Throwable $e) {}

// ── Theatres list ─────────────────────────────────────────────────
$theatres = [];
try {
    $tq = $pdo->prepare("
        SELECT t.*,
            SUM(s.status = 'in-progress') AS live_count,
            COUNT(s.id)                   AS total_surgeries
        FROM health_theatres t
        LEFT JOIN health_surgeries s ON s.theatre_id=t.id
        WHERE t.org_id=?
        GROUP BY t.id
        ORDER BY t.name
    ");
    $tq->execute([$orgId]);
    $theatres = $tq->fetchAll();
} catch (Throwable $e) {}

// ── Lookup lists for dropdowns ────────────────────────────────────
$patients    = [];
$doctors     = [];
$admittedPts = [];
try {
    $pq = $pdo->prepare("SELECT id, patient_no, first_name, last_name FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
    $pq->execute([$orgId]);
    $patients = $pq->fetchAll();
} catch (Throwable $e) {}
try {
    $dq = $pdo->prepare("SELECT id, first_name, last_name, specialization FROM health_doctors WHERE org_id=? AND status='active' ORDER BY first_name");
    $dq->execute([$orgId]);
    $doctors = $dq->fetchAll();
} catch (Throwable $e) {}
try {
    $aq = $pdo->prepare("SELECT a.id, a.admission_no, CONCAT(p.first_name,' ',p.last_name) AS patient_name FROM health_admissions a JOIN health_patients p ON a.patient_id=p.id WHERE a.org_id=? AND a.status='admitted' ORDER BY a.admitted_at DESC");
    $aq->execute([$orgId]);
    $admittedPts = $aq->fetchAll();
} catch (Throwable $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><i class="fas fa-syringe me-2" style="color:<?= $moduleColor ?>"></i>Surgery &amp; Theatre Management</h4>
    <p class="text-muted mb-0 small">Schedule procedures, manage operating theatres, and track surgical outcomes</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#surgeryModal" onclick="openAddSurgery()">
    <i class="fas fa-plus me-2"></i>Schedule Surgery
  </button>
</div>

<?php flash(); ?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#fff3cd">
          <i class="fas fa-calendar-day" style="color:#f39c12;font-size:1.3rem"></i>
        </div>
        <div>
          <div class="fs-3 fw-bold lh-1"><?= $statToday ?></div>
          <div class="text-muted small">Today's Surgeries</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#fde8e8">
          <i class="fas fa-spinner" style="color:#e74c3c;font-size:1.3rem"></i>
        </div>
        <div>
          <div class="fs-3 fw-bold lh-1 text-danger"><?= $statInProg ?></div>
          <div class="text-muted small">In Progress</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#d4edda">
          <i class="fas fa-check-circle" style="color:#27ae60;font-size:1.3rem"></i>
        </div>
        <div>
          <div class="fs-3 fw-bold lh-1 text-success"><?= $statDone ?></div>
          <div class="text-muted small">Completed Today</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#cce5ff">
          <i class="fas fa-door-open" style="color:#3498db;font-size:1.3rem"></i>
        </div>
        <div>
          <div class="fs-3 fw-bold lh-1 text-primary"><?= $statAvailTheatres ?></div>
          <div class="text-muted small">Theatres Available</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'schedule' ? 'active' : '' ?>" href="?tab=schedule">
      <i class="fas fa-list-alt me-1"></i>Surgery Schedule
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'theatres' ? 'active' : '' ?>" href="?tab=theatres">
      <i class="fas fa-clinic-medical me-1"></i>Theatres
      <span class="badge bg-secondary ms-1"><?= count($theatres) ?></span>
    </a>
  </li>
</ul>

<?php if ($activeTab === 'schedule'): ?>
<!-- Surgery Schedule Table -->
<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Surgery Ref</th>
            <th>Patient</th>
            <th>Procedure</th>
            <th>Theatre</th>
            <th>Surgeon</th>
            <th>Scheduled</th>
            <th>Est. Duration</th>
            <th>Priority</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($surgeries)): ?>
          <tr>
            <td colspan="10" class="text-center py-5 text-muted">
              <i class="fas fa-syringe fa-2x mb-2 d-block opacity-25"></i>
              No surgeries scheduled yet. Click <strong>Schedule Surgery</strong> to begin.
            </td>
          </tr>
          <?php else: foreach ($surgeries as $s):
            $statusMap = [
                'scheduled'   => ['info',      'Scheduled'],
                'pre-op'      => ['warning',    'Pre-Op'],
                'in-progress' => ['danger',     'In Progress'],
                'recovery'    => ['primary',    'Recovery'],
                'completed'   => ['success',    'Completed'],
                'cancelled'   => ['secondary',  'Cancelled'],
                'postponed'   => ['dark',       'Postponed'],
            ];
            $priorityMap = [
                'routine'   => ['secondary', 'Routine'],
                'urgent'    => ['warning',   'Urgent'],
                'emergency' => ['danger',    'Emergency'],
            ];
            [$sBg, $sLabel]  = $statusMap[$s['status']]   ?? ['secondary', ucfirst($s['status'])];
            [$pBg, $pLabel]  = $priorityMap[$s['priority']] ?? ['secondary', ucfirst($s['priority'])];
            $isActive = in_array($s['status'], ['scheduled','pre-op','postponed']);
          ?>
          <tr>
            <td>
              <span class="fw-bold text-dark"><?= e($s['surgery_no']) ?></span>
              <?php if ($s['admission_no']): ?>
              <br><small class="text-muted">IPD: <?= e($s['admission_no']) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold"><?= e($s['patient_name']) ?></div>
              <small class="text-muted"><?= e($s['patient_no']) ?></small>
            </td>
            <td>
              <div class="fw-semibold small"><?= e($s['procedure_name']) ?></div>
              <span class="badge bg-light text-dark border" style="font-size:.68rem"><?= ucfirst($s['procedure_type']) ?></span>
            </td>
            <td>
              <?php if ($s['theatre_name']): ?>
              <div class="small fw-semibold"><?= e($s['theatre_name']) ?></div>
              <small class="text-muted text-capitalize"><?= str_replace('-', ' ', $s['theatre_type'] ?? '') ?></small>
              <?php else: ?>
              <span class="text-muted small">— Unassigned</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($s['surgeon_name'] && trim($s['surgeon_name']) !== ' '): ?>
              <div class="small fw-semibold"><?= e($s['surgeon_name']) ?></div>
              <small class="text-muted"><?= e($s['surgeon_spec'] ?? '') ?></small>
              <?php else: ?>
              <span class="text-muted small">— TBA</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="small fw-semibold"><?= date('d M Y', strtotime($s['scheduled_at'])) ?></div>
              <small class="text-muted"><?= date('h:i A', strtotime($s['scheduled_at'])) ?></small>
            </td>
            <td class="small text-center">
              <?php $h = intdiv($s['estimated_duration_min'], 60); $m = $s['estimated_duration_min'] % 60; ?>
              <?= $h ? "{$h}h " : '' ?><?= $m ? "{$m}m" : '' ?>
            </td>
            <td><span class="badge bg-<?= $pBg ?>"><?= $pLabel ?></span></td>
            <td><span class="badge bg-<?= $sBg ?>"><?= $sLabel ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openNotes(<?= $s['id'] ?>)" title="View / Update Notes">
                  <i class="fas fa-notes-medical"></i>
                </button>
                <?php if ($isActive): ?>
                <button class="btn btn-outline-warning" onclick="openEditSurgery(<?= $s['id'] ?>)" title="Edit Schedule">
                  <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-outline-danger" onclick="cancelSurgery(<?= $s['id'] ?>, '<?= e($s['surgery_no']) ?>')" title="Cancel Surgery">
                  <i class="fas fa-ban"></i>
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php else: // ── Theatres Tab ─────────────────────────────────── ?>
<div class="d-flex justify-content-end mb-3">
  <button class="btn text-white btn-sm" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#theatreModal" onclick="openAddTheatre()">
    <i class="fas fa-plus me-1"></i>Add Theatre
  </button>
</div>
<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Theatre Name</th>
            <th>Type</th>
            <th>Capacity</th>
            <th>Total Surgeries</th>
            <th>Status</th>
            <th>Notes</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($theatres)): ?>
          <tr>
            <td colspan="7" class="text-center py-5 text-muted">
              <i class="fas fa-clinic-medical fa-2x mb-2 d-block opacity-25"></i>
              No theatres configured. Click <strong>Add Theatre</strong> to set up operating rooms.
            </td>
          </tr>
          <?php else: foreach ($theatres as $t):
            $tStatusMap = ['available'=>['success','Available'], 'in-use'=>['danger','In Use'], 'maintenance'=>['warning','Maintenance'], 'inactive'=>['secondary','Inactive']];
            [$tBg, $tLabel] = $tStatusMap[$t['status']] ?? ['secondary', ucfirst($t['status'])];
          ?>
          <tr>
            <td class="fw-bold"><?= e($t['name']) ?></td>
            <td><span class="badge bg-light text-dark border text-capitalize"><?= e(str_replace('-', ' ', $t['theatre_type'])) ?></span></td>
            <td class="text-center"><?= (int)$t['capacity'] ?></td>
            <td class="text-center"><?= (int)$t['total_surgeries'] ?></td>
            <td>
              <span class="badge bg-<?= $tBg ?>"><?= $tLabel ?></span>
              <?php if ((int)$t['live_count'] > 0): ?>
              <span class="badge bg-danger ms-1 blink-badge">LIVE</span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= e(truncate($t['notes'] ?? '', 50)) ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary" onclick="openEditTheatre(<?= $t['id'] ?>)" title="Edit Theatre">
                <i class="fas fa-edit"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>


<!-- ── Schedule Surgery Modal ──────────────────────────────────── -->
<div class="modal fade" id="surgeryModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save_surgery"><input type="hidden" name="id" id="surgId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="surgModalTitle"><i class="fas fa-syringe me-2"></i>Schedule Surgery</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
            <select name="patient_id" id="surgPatient" class="form-select select2-enable" required>
              <option value="">— select patient —</option>
              <?php foreach ($patients as $pt): ?>
              <option value="<?= $pt['id'] ?>"><?= e($pt['first_name'].' '.$pt['last_name']) ?> (<?= e($pt['patient_no']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Link to Admission (IPD)</label>
            <select name="admission_id" id="surgAdmission" class="form-select">
              <option value="">— outpatient / none —</option>
              <?php foreach ($admittedPts as $ap): ?>
              <option value="<?= $ap['id'] ?>"><?= e($ap['admission_no']) ?> — <?= e($ap['patient_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Procedure Name <span class="text-danger">*</span></label>
            <input type="text" name="procedure_name" id="surgProcedure" class="form-control" required placeholder="e.g. Appendectomy, Caesarean Section, Total Knee Replacement">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Procedure Type</label>
            <select name="procedure_type" id="surgProcType" class="form-select">
              <option value="elective">Elective</option>
              <option value="urgent">Urgent</option>
              <option value="emergency">Emergency</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Priority</label>
            <select name="priority" id="surgPriority" class="form-select">
              <option value="routine">Routine</option>
              <option value="urgent">Urgent</option>
              <option value="emergency">Emergency</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Anaesthesia Type</label>
            <select name="anaesthesia_type" id="surgAnaesthType" class="form-select">
              <option value="general">General</option>
              <option value="local">Local</option>
              <option value="regional">Regional</option>
              <option value="spinal">Spinal</option>
              <option value="epidural">Epidural</option>
              <option value="sedation">Sedation</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Operating Theatre</label>
            <select name="theatre_id" id="surgTheatre" class="form-select">
              <option value="">— assign later —</option>
              <?php foreach ($theatres as $th): ?>
              <option value="<?= $th['id'] ?>"><?= e($th['name']) ?> (<?= ucfirst($th['theatre_type']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Estimated Duration (minutes)</label>
            <input type="number" name="estimated_duration_min" id="surgDuration" class="form-control" value="60" min="5" step="5">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Surgeon</label>
            <select name="surgeon_id" id="surgSurgeon" class="form-select">
              <option value="">— select surgeon —</option>
              <?php foreach ($doctors as $d): ?>
              <option value="<?= $d['id'] ?>"><?= e($d['first_name'].' '.$d['last_name']) ?><?= $d['specialization'] ? ' ('.e($d['specialization']).')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Anaesthetist</label>
            <select name="anaesthetist_id" id="surgAnaest" class="form-select">
              <option value="">— select anaesthetist —</option>
              <?php foreach ($doctors as $d): ?>
              <option value="<?= $d['id'] ?>"><?= e($d['first_name'].' '.$d['last_name']) ?><?= $d['specialization'] ? ' ('.e($d['specialization']).')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Scheduled Date &amp; Time <span class="text-danger">*</span></label>
            <input type="datetime-local" name="scheduled_at" id="surgScheduledAt" class="form-control" required>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Pre-Op Notes</label>
            <textarea name="pre_op_notes" id="surgPreOp" class="form-control" rows="3" placeholder="Pre-operative instructions, patient preparation, allergies to note, consent status…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save</button>
      </div>
      </form>
    </div>
  </div>
</div>


<!-- ── Clinical Notes / Status Modal ──────────────────────────── -->
<div class="modal fade" id="notesModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" id="notesId" value="0">
      <div class="modal-header text-white" style="background:#2c3e50">
        <h5 class="modal-title" id="notesTitle"><i class="fas fa-notes-medical me-2"></i>Surgery Notes &amp; Status</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Surgery summary strip -->
        <div class="alert alert-light border mb-3 py-2 small" id="notesSummary"></div>

        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="notesStatus" class="form-select">
              <option value="scheduled">Scheduled</option>
              <option value="pre-op">Pre-Op</option>
              <option value="in-progress">In Progress</option>
              <option value="recovery">Recovery</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
              <option value="postponed">Postponed</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Actual Start</label>
            <input type="datetime-local" name="actual_start" id="notesActualStart" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Actual End</label>
            <input type="datetime-local" name="actual_end" id="notesActualEnd" class="form-control">
          </div>
        </div>

        <!-- Tabbed notes -->
        <ul class="nav nav-tabs nav-sm mb-3" id="notesTabs">
          <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#ntPreOp"><i class="fas fa-clipboard-list me-1"></i>Pre-Op</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#ntIntra"><i class="fas fa-procedures me-1"></i>Intra-Op</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#ntPost"><i class="fas fa-heart-pulse me-1 fa-heart"></i>Post-Op</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#ntCompl"><i class="fas fa-exclamation-triangle me-1 text-warning"></i>Complications</a></li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="ntPreOp">
            <textarea name="pre_op_notes" id="notesPreOp" class="form-control" rows="5" placeholder="Consent status, allergies, NPO status, pre-op medications, patient condition on arrival…"></textarea>
          </div>
          <div class="tab-pane fade" id="ntIntra">
            <textarea name="intra_op_notes" id="notesIntraOp" class="form-control" rows="5" placeholder="Intra-operative findings, technique used, instruments, implants, swab counts…"></textarea>
          </div>
          <div class="tab-pane fade" id="ntPost">
            <textarea name="post_op_notes" id="notesPostOp" class="form-control" rows="5" placeholder="Recovery room observations, vital signs, post-op medications, discharge criteria met…"></textarea>
          </div>
          <div class="tab-pane fade" id="ntCompl">
            <textarea name="complications" id="notesComplications" class="form-control" rows="4" placeholder="Document any intra- or post-operative complications…"></textarea>
            <div class="mt-3">
              <label class="form-label fw-semibold">Blood Units Used (units)</label>
              <input type="number" name="blood_units_used" id="notesBlood" class="form-control" style="max-width:180px" value="0" min="0" step="0.5">
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Update Record</button>
      </div>
      </form>
    </div>
  </div>
</div>


<!-- ── Theatre Modal ───────────────────────────────────────────── -->
<div class="modal fade" id="theatreModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save_theatre"><input type="hidden" name="theatre_id" id="theatreId" value="0">
      <div class="modal-header text-white" style="background:#2c3e50">
        <h5 class="modal-title" id="theatreModalTitle"><i class="fas fa-clinic-medical me-2"></i>Add Theatre</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Theatre Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="theatreName" class="form-control" required placeholder="e.g. Theatre 1 — General Surgery">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Theatre Type</label>
            <select name="theatre_type" id="theatreType" class="form-select">
              <option value="general">General Surgery</option>
              <option value="orthopaedic">Orthopaedic</option>
              <option value="cardiac">Cardiac</option>
              <option value="obstetric">Obstetric / Gynae</option>
              <option value="neurology">Neurology</option>
              <option value="ophthalmic">Ophthalmic</option>
              <option value="ent">ENT</option>
              <option value="emergency">Emergency / Trauma</option>
              <option value="endoscopy">Endoscopy</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Capacity (tables)</label>
            <input type="number" name="capacity" id="theatreCapacity" class="form-control" value="1" min="1">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Status</label>
            <select name="tstatus" id="theatreStatus" class="form-select">
              <option value="available">Available</option>
              <option value="maintenance">Maintenance</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" id="theatreNotes" class="form-control" rows="2" placeholder="Equipment notes, location, special capabilities…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-dark"><i class="fas fa-save me-1"></i>Save Theatre</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- Cancel form -->
<form method="POST" id="cancelForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="cancel_surgery">
  <input type="hidden" name="id" id="cancelId">
</form>

<style>
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }
.blink-badge { animation: blink 1.2s infinite; }
</style>

<?php
$extraJs = <<<'JS'
<script>
// ── Schedule Surgery ──────────────────────────────────────────────
function openAddSurgery() {
  document.getElementById('surgModalTitle').innerHTML = '<i class="fas fa-syringe me-2"></i>Schedule Surgery';
  document.getElementById('surgId').value = '0';
  document.getElementById('surgPatient').value    = '';
  document.getElementById('surgAdmission').value  = '';
  document.getElementById('surgProcedure').value  = '';
  document.getElementById('surgProcType').value   = 'elective';
  document.getElementById('surgPriority').value   = 'routine';
  document.getElementById('surgAnaesthType').value= 'general';
  document.getElementById('surgTheatre').value    = '';
  document.getElementById('surgDuration').value   = '60';
  document.getElementById('surgSurgeon').value    = '';
  document.getElementById('surgAnaest').value     = '';
  document.getElementById('surgPreOp').value      = '';
  const now = new Date();
  now.setMinutes(0,0,0);
  now.setDate(now.getDate() + 1);
  document.getElementById('surgScheduledAt').value = now.toISOString().slice(0,16);
}

function openEditSurgery(id) {
  fetch('surgery.php?fetch=' + id)
    .then(r => r.json())
    .then(s => {
      document.getElementById('surgModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Surgery';
      document.getElementById('surgId').value             = s.id;
      document.getElementById('surgPatient').value        = s.patient_id    || '';
      document.getElementById('surgAdmission').value      = s.admission_id  || '';
      document.getElementById('surgProcedure').value      = s.procedure_name|| '';
      document.getElementById('surgProcType').value       = s.procedure_type|| 'elective';
      document.getElementById('surgPriority').value       = s.priority      || 'routine';
      document.getElementById('surgAnaesthType').value    = s.anaesthesia_type||'general';
      document.getElementById('surgTheatre').value        = s.theatre_id    || '';
      document.getElementById('surgDuration').value       = s.estimated_duration_min||60;
      document.getElementById('surgSurgeon').value        = s.surgeon_id    || '';
      document.getElementById('surgAnaest').value         = s.anaesthetist_id||'';
      document.getElementById('surgScheduledAt').value    = s.scheduled_at  ? s.scheduled_at.replace(' ','T').substring(0,16) : '';
      document.getElementById('surgPreOp').value          = s.pre_op_notes  || '';
      new bootstrap.Modal(document.getElementById('surgeryModal')).show();
    });
}

// ── Clinical Notes ────────────────────────────────────────────────
function openNotes(id) {
  fetch('surgery.php?fetch=' + id)
    .then(r => r.json())
    .then(s => {
      document.getElementById('notesId').value           = s.id;
      document.getElementById('notesStatus').value       = s.status          || 'scheduled';
      document.getElementById('notesActualStart').value  = s.actual_start    ? s.actual_start.replace(' ','T').substring(0,16) : '';
      document.getElementById('notesActualEnd').value    = s.actual_end      ? s.actual_end.replace(' ','T').substring(0,16)   : '';
      document.getElementById('notesPreOp').value        = s.pre_op_notes    || '';
      document.getElementById('notesIntraOp').value      = s.intra_op_notes  || '';
      document.getElementById('notesPostOp').value       = s.post_op_notes   || '';
      document.getElementById('notesComplications').value= s.complications   || '';
      document.getElementById('notesBlood').value        = s.blood_units_used|| '0';
      document.getElementById('notesTitle').innerHTML    = '<i class="fas fa-notes-medical me-2"></i>' + (s.surgery_no || 'Surgery') + ' — Notes & Status';
      const dt = s.scheduled_at ? new Date(s.scheduled_at.replace(' ','T')) : null;
      const dtStr = dt ? dt.toLocaleDateString('en-KE',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
      document.getElementById('notesSummary').innerHTML =
        '<strong>' + (s.procedure_name||'—') + '</strong> &nbsp;|&nbsp; '
        + '<i class="fas fa-calendar-alt me-1"></i>' + dtStr
        + (s.estimated_duration_min ? ' &nbsp;(est. ' + s.estimated_duration_min + ' min)' : '');
      // Reset to Pre-Op tab
      document.querySelector('#notesTabs .nav-link.active')?.classList.remove('active','show');
      document.querySelector('#notesTabs .nav-link')?.classList.add('active','show');
      document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show','active'));
      document.getElementById('ntPreOp')?.classList.add('show','active');
      new bootstrap.Modal(document.getElementById('notesModal')).show();
    });
}

// ── Cancel Surgery ────────────────────────────────────────────────
function cancelSurgery(id, ref) {
  Swal.fire({
    title: 'Cancel Surgery?',
    text: ref + ' will be marked as Cancelled. This cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, cancel it',
    cancelButtonText: 'Keep'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('cancelId').value = id;
      document.getElementById('cancelForm').submit();
    }
  });
}

// ── Theatre ───────────────────────────────────────────────────────
function openAddTheatre() {
  document.getElementById('theatreModalTitle').innerHTML = '<i class="fas fa-clinic-medical me-2"></i>Add Theatre';
  document.getElementById('theatreId').value       = '0';
  document.getElementById('theatreName').value     = '';
  document.getElementById('theatreType').value     = 'general';
  document.getElementById('theatreCapacity').value = '1';
  document.getElementById('theatreStatus').value   = 'available';
  document.getElementById('theatreNotes').value    = '';
}

function openEditTheatre(id) {
  fetch('surgery.php?fetch_theatre=' + id)
    .then(r => r.json())
    .then(t => {
      document.getElementById('theatreModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Theatre';
      document.getElementById('theatreId').value       = t.id;
      document.getElementById('theatreName').value     = t.name          || '';
      document.getElementById('theatreType').value     = t.theatre_type  || 'general';
      document.getElementById('theatreCapacity').value = t.capacity      || 1;
      document.getElementById('theatreStatus').value   = t.status === 'in-use' ? 'available' : (t.status || 'available');
      document.getElementById('theatreNotes').value    = t.notes         || '';
      new bootstrap.Modal(document.getElementById('theatreModal')).show();
    });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
