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
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-heart',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'Analytics & AI'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
];

// ── AJAX: fetch consult ───────────────────────────────────────────
if (isset($_GET['fetch'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_teleconsults WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── AJAX: fetch ePrescription ─────────────────────────────────────
if (isset($_GET['fetch_rx'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_rx'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_eprescriptions WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        $rx = $st->fetch(PDO::FETCH_ASSOC);
        $ist = $pdo->prepare("SELECT * FROM health_eprescription_items WHERE prescription_id=?");
        $ist->execute([$id]);
        $items = $ist->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['rx'=>$rx,'items'=>$items]);
    } catch (Exception $e) { echo json_encode(['rx'=>null,'items'=>[]]); }
    exit;
}

// ── AJAX: print ePrescription ─────────────────────────────────────
if (isset($_GET['print_rx'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['print_rx'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("
            SELECT r.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no, p.dob, p.gender, p.phone,
                   CONCAT(d.first_name,' ',d.last_name) AS doctor_name, d.specialization
            FROM health_eprescriptions r
            LEFT JOIN health_patients p ON p.id=r.patient_id
            LEFT JOIN health_doctors d ON d.id=r.doctor_id
            WHERE r.id=? AND r.org_id=?
        ");
        $st->execute([$id, $orgId]);
        $rx = $st->fetch(PDO::FETCH_ASSOC);
        $ist = $pdo->prepare("SELECT * FROM health_eprescription_items WHERE prescription_id=?");
        $ist->execute([$id]);
        $items = $ist->fetchAll(PDO::FETCH_ASSOC);
        $orgSt = $pdo->prepare("SELECT name FROM organizations WHERE id=?");
        $orgSt->execute([$orgId]);
        $orgName = $orgSt->fetchColumn() ?: 'Hospital';
        echo json_encode(['rx'=>$rx,'items'=>$items,'org_name'=>$orgName]);
    } catch (Exception $e) { echo json_encode(['rx'=>null,'items'=>[]]); }
    exit;
}

// ── POST handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $uid   = (int)$user['id'];
    $action = $_POST['action'] ?? '';

    // ── Schedule teleconsult ──────────────────────────────────────
    if ($action === 'schedule') {
        $patientId   = (int)($_POST['patient_id']    ?? 0);
        $doctorId    = (int)($_POST['doctor_id']     ?? 0) ?: null;
        $platform    = in_array($_POST['platform'] ?? '', ['jitsi','zoom','teams','whatsapp','phone','other']) ? $_POST['platform'] : 'jitsi';
        $scheduledAt = sanitize($_POST['scheduled_at'] ?? date('Y-m-d H:i:s'));
        $duration    = (int)($_POST['duration_mins'] ?? 30);
        $complaint   = sanitize($_POST['chief_complaint'] ?? '');
        $meetingLink = sanitize($_POST['meeting_link'] ?? '');

        if (!$patientId) { setFlash('error','Patient required.'); redirect('telemedicine.php'); }

        // Auto-generate Jitsi link
        if ($platform === 'jitsi' && !$meetingLink) {
            $meetingId   = 'orbitdesk-' . $orgId . '-' . bin2hex(random_bytes(6));
            $meetingLink = 'https://meet.jit.si/' . $meetingId;
        } else {
            $meetingId = '';
        }

        $yr    = date('Y');
        $cntSt = $pdo->prepare("SELECT COUNT(*)+1 FROM health_teleconsults WHERE org_id=? AND YEAR(created_at)=?");
        $cntSt->execute([$orgId,$yr]);
        $seq   = str_pad((int)$cntSt->fetchColumn(),4,'0',STR_PAD_LEFT);
        $consultNo = 'TC-'.$yr.'-'.$seq;

        $pdo->prepare("INSERT INTO health_teleconsults (org_id,consult_no,patient_id,doctor_id,platform,meeting_link,meeting_id,scheduled_at,duration_mins,chief_complaint,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,'scheduled',?,?)")
            ->execute([$orgId,$consultNo,$patientId,$doctorId,$platform,$meetingLink,$meetingId,$scheduledAt,$duration,$complaint,$uid]);
        setFlash('success',"Teleconsult {$consultNo} scheduled.");
        redirect('telemedicine.php');
    }

    // ── Update consult status ─────────────────────────────────────
    if ($action === 'update_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['scheduled','in_waiting_room','in_progress','completed','cancelled','no_show']) ? $_POST['status'] : '';
        $notes  = sanitize($_POST['notes'] ?? '');
        if ($id && $status) {
            $startedAt = $status === 'in_progress' ? ', started_at=NOW()' : '';
            $endedAt   = in_array($status,['completed','cancelled','no_show']) ? ', ended_at=NOW()' : '';
            $pdo->prepare("UPDATE health_teleconsults SET status=?,notes=? {$startedAt}{$endedAt} WHERE id=? AND org_id=?")
                ->execute([$status,$notes,$id,$orgId]);
            setFlash('success','Consult updated.');
        }
        redirect('telemedicine.php');
    }

    // ── Create ePrescription ──────────────────────────────────────
    if ($action === 'create_rx') {
        $patientId   = (int)($_POST['patient_id']     ?? 0);
        $doctorId    = (int)($_POST['doctor_id']      ?? 0) ?: null;
        $consultId   = (int)($_POST['teleconsult_id'] ?? 0) ?: null;
        $diagnosis   = sanitize($_POST['diagnosis']   ?? '');
        $instructions= sanitize($_POST['instructions']?? '');
        $validUntil  = $_POST['valid_until']          ?? date('Y-m-d', strtotime('+30 days'));

        $medNames  = $_POST['med_name']     ?? [];
        $doses     = $_POST['med_dose']     ?? [];
        $routes    = $_POST['med_route']    ?? [];
        $freqs     = $_POST['med_freq']     ?? [];
        $durs      = $_POST['med_duration'] ?? [];
        $qtys      = $_POST['med_qty']      ?? [];
        $medInstr  = $_POST['med_instructions'] ?? [];

        if (!$patientId || empty(array_filter($medNames))) { setFlash('error','Patient and at least one medication required.'); redirect('telemedicine.php?tab=eprescriptions'); }

        $yr    = date('Y');
        $cntSt = $pdo->prepare("SELECT COUNT(*)+1 FROM health_eprescriptions WHERE org_id=? AND YEAR(created_at)=?");
        $cntSt->execute([$orgId,$yr]);
        $seq   = str_pad((int)$cntSt->fetchColumn(),4,'0',STR_PAD_LEFT);
        $rxNo  = 'RXe-'.$yr.'-'.$seq;
        $qrToken = bin2hex(random_bytes(16));

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO health_eprescriptions (org_id,rx_no,patient_id,doctor_id,teleconsult_id,diagnosis,instructions,qr_token,status,valid_until) VALUES (?,?,?,?,?,?,?,?,'active',?)")
                ->execute([$orgId,$rxNo,$patientId,$doctorId,$consultId,$diagnosis,$instructions,$qrToken,$validUntil]);
            $rxId = (int)$pdo->lastInsertId();

            $ist = $pdo->prepare("INSERT INTO health_eprescription_items (prescription_id,medicine_name,dose,route,frequency,duration,quantity,instructions) VALUES (?,?,?,?,?,?,?,?)");
            for ($i = 0; $i < count($medNames); $i++) {
                $mname = sanitize($medNames[$i] ?? '');
                if (!$mname) continue;
                $ist->execute([$rxId,$mname,sanitize($doses[$i]??''),sanitize($routes[$i]??'oral'),sanitize($freqs[$i]??''),(int)($qtys[$i]??1),sanitize($durs[$i]??''),sanitize($medInstr[$i]??'')]);
            }
            $pdo->commit();
            setFlash('success',"ePrescription {$rxNo} created.");
        } catch (Exception $ex) {
            $pdo->rollBack();
            setFlash('error','Failed: '.$ex->getMessage());
        }
        redirect('telemedicine.php?tab=eprescriptions');
    }
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['consults','eprescriptions','queue']) ? $_GET['tab'] : 'consults';

// ── Patients & Doctors ────────────────────────────────────────────
$patientsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, patient_no FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
$patientsSt->execute([$orgId]);
$patients = $patientsSt->fetchAll(PDO::FETCH_ASSOC);

$doctorsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name FROM health_doctors WHERE org_id=? AND status='active' ORDER BY first_name");
$doctorsSt->execute([$orgId]);
$doctors = $doctorsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Teleconsults ──────────────────────────────────────────────────
$filterStatus = sanitize($_GET['status'] ?? '');
$filterDate   = sanitize($_GET['date']   ?? '');
$filterPid    = (int)($_GET['patient_id'] ?? 0);

$tcWhere  = "t.org_id=?";
$tcParams = [$orgId];
if ($filterStatus) { $tcWhere .= " AND t.status=?";              $tcParams[] = $filterStatus; }
if ($filterDate)   { $tcWhere .= " AND DATE(t.scheduled_at)=?";  $tcParams[] = $filterDate; }
if ($filterPid)    { $tcWhere .= " AND t.patient_id=?";          $tcParams[] = $filterPid; }

$tcSt = $pdo->prepare("
    SELECT t.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no, p.phone AS patient_phone,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM health_teleconsults t
    LEFT JOIN health_patients p ON p.id=t.patient_id
    LEFT JOIN health_doctors d ON d.id=t.doctor_id
    WHERE {$tcWhere}
    ORDER BY t.scheduled_at DESC LIMIT 200
");
$tcSt->execute($tcParams);
$consults = $tcSt->fetchAll(PDO::FETCH_ASSOC);

// ── Virtual waiting room ──────────────────────────────────────────
$waitRoomSt = $pdo->prepare("
    SELECT t.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM health_teleconsults t
    LEFT JOIN health_patients p ON p.id=t.patient_id
    LEFT JOIN health_doctors d ON d.id=t.doctor_id
    WHERE t.org_id=? AND t.status IN ('scheduled','in_waiting_room') AND DATE(t.scheduled_at)=CURDATE()
    ORDER BY t.scheduled_at ASC
");
$waitRoomSt->execute([$orgId]);
$waitRoom = $waitRoomSt->fetchAll(PDO::FETCH_ASSOC);

// ── ePrescriptions ────────────────────────────────────────────────
$rxPid  = (int)($_GET['rx_patient'] ?? 0);
$rxDate = sanitize($_GET['rx_date'] ?? '');
$rxWhere  = "r.org_id=?";
$rxParams = [$orgId];
if ($rxPid)  { $rxWhere .= " AND r.patient_id=?";       $rxParams[] = $rxPid; }
if ($rxDate) { $rxWhere .= " AND DATE(r.created_at)=?"; $rxParams[] = $rxDate; }

$rxSt = $pdo->prepare("
    SELECT r.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
           (SELECT COUNT(*) FROM health_eprescription_items WHERE prescription_id=r.id) AS item_count
    FROM health_eprescriptions r
    LEFT JOIN health_patients p ON p.id=r.patient_id
    LEFT JOIN health_doctors d ON d.id=r.doctor_id
    WHERE {$rxWhere}
    ORDER BY r.created_at DESC LIMIT 200
");
$rxSt->execute($rxParams);
$prescriptions = $rxSt->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────
$todaySt = $pdo->prepare("SELECT COUNT(*) FROM health_teleconsults WHERE org_id=? AND DATE(scheduled_at)=CURDATE()");
$todaySt->execute([$orgId]);
$todayConsults = (int)$todaySt->fetchColumn();

$waitingSt = $pdo->prepare("SELECT COUNT(*) FROM health_teleconsults WHERE org_id=? AND status IN ('scheduled','in_waiting_room') AND DATE(scheduled_at)=CURDATE()");
$waitingSt->execute([$orgId]);
$waitingCount = (int)$waitingSt->fetchColumn();

$completedSt = $pdo->prepare("SELECT COUNT(*) FROM health_teleconsults WHERE org_id=? AND status='completed' AND DATE(scheduled_at)=CURDATE()");
$completedSt->execute([$orgId]);
$completedToday = (int)$completedSt->fetchColumn();

$activeRxSt = $pdo->prepare("SELECT COUNT(*) FROM health_eprescriptions WHERE org_id=? AND status='active'");
$activeRxSt->execute([$orgId]);
$activeRx = (int)$activeRxSt->fetchColumn();

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="container-fluid py-4">

  <?php flash(); ?>

  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h4 class="mb-0 fw-bold"><i class="fas fa-video me-2 text-danger"></i>Telemedicine</h4>
      <small class="text-muted">Virtual consultations, ePrescriptions & virtual waiting room</small>
    </div>
    <div class="d-flex gap-2">
      <?php if ($tab === 'consults'): ?>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#scheduleModal">
          <i class="fas fa-plus me-1"></i>Schedule Consult
        </button>
      <?php elseif ($tab === 'eprescriptions'): ?>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rxModal">
          <i class="fas fa-plus me-1"></i>New ePrescription
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stats -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-primary fs-3 fw-bold"><?= $todayConsults ?></div>
        <small class="text-muted">Consults Today</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3 <?= $waitingCount > 0 ? 'border-warning' : '' ?>">
        <div class="text-warning fs-3 fw-bold"><?= $waitingCount ?></div>
        <small class="text-muted">In Queue / Waiting</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-success fs-3 fw-bold"><?= $completedToday ?></div>
        <small class="text-muted">Completed Today</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-info fs-3 fw-bold"><?= $activeRx ?></div>
        <small class="text-muted">Active ePrescriptions</small>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab==='consults'        ?'active':'' ?>" href="?tab=consults"><i class="fas fa-video me-1"></i>Consultations</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='queue'           ?'active':'' ?>" href="?tab=queue"><i class="fas fa-users me-1"></i>Virtual Waiting Room <span class="badge bg-warning text-dark ms-1"><?= $waitingCount ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='eprescriptions'  ?'active':'' ?>" href="?tab=eprescriptions"><i class="fas fa-file-prescription me-1"></i>ePrescriptions</a></li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: CONSULTATIONS
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'consults'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="consults">
        <div class="col-12 col-md-3">
          <select name="patient_id" class="form-select form-select-sm select2" onchange="this.form.submit()">
            <option value="">All Patients</option>
            <?php foreach ($patients as $p): ?>
              <option value="<?= $p['id'] ?>" <?= $filterPid==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Status</option>
            <option value="scheduled"       <?= $filterStatus==='scheduled'       ?'selected':'' ?>>Scheduled</option>
            <option value="in_waiting_room" <?= $filterStatus==='in_waiting_room' ?'selected':'' ?>>Waiting Room</option>
            <option value="in_progress"     <?= $filterStatus==='in_progress'     ?'selected':'' ?>>In Progress</option>
            <option value="completed"       <?= $filterStatus==='completed'       ?'selected':'' ?>>Completed</option>
            <option value="no_show"         <?= $filterStatus==='no_show'         ?'selected':'' ?>>No Show</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDate) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-auto"><a href="?tab=consults" class="btn btn-outline-secondary btn-sm">Clear</a></div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="consultsTable">
          <thead class="table-light">
            <tr><th>Consult No</th><th>Patient</th><th>Doctor</th><th>Platform</th><th>Scheduled</th><th>Duration</th><th>Status</th><th>Meeting Link</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (empty($consults)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No teleconsults found.</td></tr>
          <?php else: foreach ($consults as $c):
            $stBadge = match($c['status']) {
                'scheduled'       => 'warning text-dark',
                'in_waiting_room' => 'info text-dark',
                'in_progress'     => 'primary',
                'completed'       => 'success',
                'cancelled'       => 'secondary',
                'no_show'         => 'danger',
                default           => 'light text-dark'
            };
            $platIcon = match($c['platform']) {
                'zoom'      => 'fas fa-video text-primary',
                'teams'     => 'fab fa-microsoft text-primary',
                'whatsapp'  => 'fab fa-whatsapp text-success',
                'phone'     => 'fas fa-phone text-success',
                default     => 'fas fa-video text-danger'
            };
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($c['consult_no']) ?></strong></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($c['patient_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($c['patient_no']) ?></small>
              </td>
              <td><small><?= htmlspecialchars($c['doctor_name'] ?: '—') ?></small></td>
              <td><i class="<?= $platIcon ?> me-1"></i><?= ucfirst($c['platform']) ?></td>
              <td><small><?= date('d M Y H:i', strtotime($c['scheduled_at'])) ?></small></td>
              <td><?= $c['duration_mins'] ?> min</td>
              <td><span class="badge bg-<?= $stBadge ?>"><?= ucwords(str_replace('_',' ',$c['status'])) ?></span></td>
              <td>
                <?php if ($c['meeting_link']): ?>
                  <a href="<?= htmlspecialchars($c['meeting_link']) ?>" target="_blank" class="btn btn-outline-success btn-sm"><i class="fas fa-external-link-alt me-1"></i>Join</a>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td>
                <div class="btn-group btn-group-sm">
                  <?php if ($c['status'] === 'scheduled'): ?>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="id" value="<?= $c['id'] ?>">
                      <input type="hidden" name="status" value="in_waiting_room">
                      <button type="submit" class="btn btn-outline-info btn-sm" title="Move to Waiting Room"><i class="fas fa-users"></i></button>
                    </form>
                  <?php endif; ?>
                  <?php if (in_array($c['status'],['scheduled','in_waiting_room'])): ?>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="id" value="<?= $c['id'] ?>">
                      <input type="hidden" name="status" value="in_progress">
                      <button type="submit" class="btn btn-outline-primary btn-sm" title="Start Consult"><i class="fas fa-play"></i></button>
                    </form>
                  <?php endif; ?>
                  <?php if ($c['status'] === 'in_progress'): ?>
                    <button class="btn btn-outline-success btn-sm" onclick="completeConsult(<?= $c['id'] ?>)" title="Complete"><i class="fas fa-check"></i></button>
                  <?php endif; ?>
                  <button class="btn btn-outline-danger btn-sm" onclick="quickCreateRx(<?= $c['id'] ?>, <?= $c['patient_id'] ?>, <?= $c['doctor_id'] ?: 0 ?>)" title="ePrescription"><i class="fas fa-file-prescription"></i></button>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: VIRTUAL WAITING ROOM
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'queue'): ?>
  <?php if (empty($waitRoom)): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>No patients in the virtual waiting room. Queue is clear!</div>
  <?php else: ?>
  <div class="row g-3">
    <?php foreach ($waitRoom as $q):
      $waitMins = (int)floor((time() - strtotime($q['scheduled_at'])) / 60);
      $waitColor = $waitMins >= 15 ? 'danger' : ($waitMins >= 5 ? 'warning' : 'success');
    ?>
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card shadow-sm border-<?= $q['status']==='in_waiting_room' ? 'info' : 'warning' ?>">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between mb-2">
            <div>
              <h6 class="mb-0 fw-bold"><?= htmlspecialchars($q['patient_name']) ?></h6>
              <small class="text-muted"><?= htmlspecialchars($q['patient_no']) ?></small>
            </div>
            <span class="badge bg-<?= $waitColor ?>"><i class="fas fa-clock me-1"></i><?= abs($waitMins) ?> min</span>
          </div>
          <div class="mb-2">
            <i class="fas fa-user-md text-muted me-1"></i><small><?= htmlspecialchars($q['doctor_name'] ?: 'Unassigned') ?></small><br>
            <i class="fas fa-info-circle text-muted me-1"></i><small><?= htmlspecialchars($q['chief_complaint'] ?: '—') ?></small>
          </div>
          <?php if ($q['meeting_link']): ?>
            <a href="<?= htmlspecialchars($q['meeting_link']) ?>" target="_blank" class="btn btn-success btn-sm w-100 mb-1">
              <i class="fas fa-video me-1"></i>Start Session
            </a>
          <?php endif; ?>
          <div class="btn-group w-100">
            <form method="POST" class="flex-fill">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="id" value="<?= $q['id'] ?>">
              <input type="hidden" name="status" value="in_progress">
              <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-play me-1"></i>Call In</button>
            </form>
            <form method="POST" class="flex-fill ms-1">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="id" value="<?= $q['id'] ?>">
              <input type="hidden" name="status" value="no_show">
              <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="fas fa-user-slash me-1"></i>No Show</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: ePRESCRIPTIONS
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'eprescriptions'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="eprescriptions">
        <div class="col-12 col-md-4">
          <select name="rx_patient" class="form-select form-select-sm select2" onchange="this.form.submit()">
            <option value="">All Patients</option>
            <?php foreach ($patients as $p): ?>
              <option value="<?= $p['id'] ?>" <?= $rxPid==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <input type="date" name="rx_date" class="form-control form-control-sm" value="<?= htmlspecialchars($rxDate) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-auto"><a href="?tab=eprescriptions" class="btn btn-outline-secondary btn-sm">Clear</a></div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="rxTable">
          <thead class="table-light">
            <tr><th>Rx No</th><th>Patient</th><th>Doctor</th><th>Diagnosis</th><th>Items</th><th>Valid Until</th><th>Status</th><th>Date</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (empty($prescriptions)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No ePrescriptions found.</td></tr>
          <?php else: foreach ($prescriptions as $rx):
            $rxStatus = match($rx['status']) {
                'active'    => 'success',
                'dispensed' => 'primary',
                'expired'   => 'secondary',
                'cancelled' => 'danger',
                default     => 'light text-dark'
            };
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($rx['rx_no']) ?></strong></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($rx['patient_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($rx['patient_no']) ?></small>
              </td>
              <td><small><?= htmlspecialchars($rx['doctor_name'] ?: '—') ?></small></td>
              <td><small><?= htmlspecialchars($rx['diagnosis'] ?: '—') ?></small></td>
              <td><span class="badge bg-secondary"><?= $rx['item_count'] ?> items</span></td>
              <td><small><?= $rx['valid_until'] ? date('d M Y', strtotime($rx['valid_until'])) : '—' ?></small></td>
              <td><span class="badge bg-<?= $rxStatus ?>"><?= ucfirst($rx['status']) ?></span></td>
              <td><small><?= date('d M Y', strtotime($rx['created_at'])) ?></small></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary btn-sm" onclick="printRx(<?= $rx['id'] ?>)" title="Print"><i class="fas fa-print"></i></button>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<!-- ── Modal: Schedule Consult ───────────────────────────────────── -->
<div class="modal fade" id="scheduleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="schedule">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-video me-2"></i>Schedule Teleconsult</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
              <select name="patient_id" class="form-select select2" required>
                <option value="">Select Patient</option>
                <?php foreach ($patients as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Doctor</label>
              <select name="doctor_id" class="form-select select2">
                <option value="">Select Doctor</option>
                <?php foreach ($doctors as $d): ?>
                  <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold">Platform</label>
              <select name="platform" id="platSelect" class="form-select" onchange="toggleMeetingLink()">
                <option value="jitsi">Jitsi Meet (auto-generate)</option>
                <option value="zoom">Zoom</option>
                <option value="teams">MS Teams</option>
                <option value="whatsapp">WhatsApp Video</option>
                <option value="phone">Phone Call</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold">Date & Time</label>
              <input type="datetime-local" name="scheduled_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold">Duration (min)</label>
              <input type="number" name="duration_mins" class="form-control" min="5" value="30">
            </div>
            <div class="col-12" id="meetingLinkRow" style="display:none">
              <label class="form-label fw-semibold">Meeting Link / ID</label>
              <input type="text" name="meeting_link" class="form-control" placeholder="Paste your Zoom/Teams/other meeting link">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Chief Complaint</label>
              <textarea name="chief_complaint" class="form-control" rows="2" placeholder="Reason for teleconsult…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-calendar-check me-1"></i>Schedule</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Complete Consult ───────────────────────────────────── -->
<div class="modal fade" id="completeModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="status" value="completed">
      <input type="hidden" name="id" id="completeId">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="fas fa-check me-2"></i>Complete Consult</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Clinical Notes</label>
            <textarea name="notes" class="form-control" rows="4" placeholder="Summary, findings, plan…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>Mark Complete</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: ePrescription ──────────────────────────────────────── -->
<div class="modal fade" id="rxModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <form method="POST" id="rxForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_rx">
      <input type="hidden" name="teleconsult_id" id="rxConsultId">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-file-prescription me-2"></i>Create ePrescription</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-5">
              <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
              <select name="patient_id" id="rxPatientId" class="form-select select2" required>
                <option value="">Select Patient</option>
                <?php foreach ($patients as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">Prescribing Doctor</label>
              <select name="doctor_id" id="rxDoctorId" class="form-select select2">
                <option value="">Select Doctor</option>
                <?php foreach ($doctors as $d): ?>
                  <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Valid Until</label>
              <input type="date" name="valid_until" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Diagnosis</label>
              <input type="text" name="diagnosis" class="form-control" placeholder="e.g. Hypertension, Diabetes Type 2">
            </div>
          </div>

          <!-- Medication rows -->
          <label class="form-label fw-semibold">Medications <span class="text-danger">*</span></label>
          <div id="rxItemsBody">
            <div class="rx-item row g-2 mb-2 border rounded p-2">
              <div class="col-12 col-md-3">
                <label class="form-label form-label-sm">Medicine Name *</label>
                <input type="text" name="med_name[]" class="form-control form-control-sm" required placeholder="e.g. Amoxicillin 500mg">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label form-label-sm">Dose</label>
                <input type="text" name="med_dose[]" class="form-control form-control-sm" placeholder="e.g. 500mg">
              </div>
              <div class="col-6 col-md-1">
                <label class="form-label form-label-sm">Route</label>
                <select name="med_route[]" class="form-select form-select-sm">
                  <option value="oral">PO</option><option value="iv">IV</option><option value="im">IM</option>
                  <option value="sc">SC</option><option value="topical">Top</option><option value="inhaled">INH</option>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label form-label-sm">Frequency</label>
                <input type="text" name="med_freq[]" class="form-control form-control-sm" list="rxFreqList" placeholder="BD, TDS…">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label form-label-sm">Duration</label>
                <input type="text" name="med_duration[]" class="form-control form-control-sm" placeholder="7 days">
              </div>
              <div class="col-4 col-md-1">
                <label class="form-label form-label-sm">Qty</label>
                <input type="number" name="med_qty[]" class="form-control form-control-sm" min="1" value="1">
              </div>
              <div class="col-8 col-md-1 d-flex align-items-end">
                <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="removeRxItem(this)"><i class="fas fa-minus"></i></button>
              </div>
            </div>
          </div>
          <datalist id="rxFreqList">
            <option>OD</option><option>BD</option><option>TDS</option><option>QID</option><option>PRN</option><option>STAT</option><option>nocte</option>
          </datalist>
          <button type="button" class="btn btn-outline-primary btn-sm mb-3" onclick="addRxItem()"><i class="fas fa-plus me-1"></i>Add Medication</button>

          <div class="mb-3">
            <label class="form-label fw-semibold">General Instructions</label>
            <textarea name="instructions" class="form-control" rows="2" placeholder="Patient instructions…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Create ePrescription</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Print Rx ────────────────────────────────────────────── -->
<div class="modal fade" id="printRxModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-prescription me-2"></i>ePrescription</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="printRxArea">Loading…</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" onclick="doPrintRx()"><i class="fas fa-print me-1"></i>Print</button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function toggleMeetingLink() {
    const plat = document.getElementById('platSelect').value;
    const row  = document.getElementById('meetingLinkRow');
    row.style.display = (plat !== 'jitsi') ? 'block' : 'none';
}

function completeConsult(id) {
    document.getElementById('completeId').value = id;
    new bootstrap.Modal(document.getElementById('completeModal')).show();
}

function quickCreateRx(consultId, patientId, doctorId) {
    document.getElementById('rxConsultId').value = consultId;
    document.getElementById('rxPatientId').value = patientId;
    if (doctorId) document.getElementById('rxDoctorId').value = doctorId;
    new bootstrap.Modal(document.getElementById('rxModal')).show();
}

function addRxItem() {
    const tmpl = document.querySelector('.rx-item').cloneNode(true);
    tmpl.querySelectorAll('input').forEach(i => {
        if (i.type !== 'hidden') { i.value = i.name.includes('med_qty') ? '1' : ''; }
    });
    tmpl.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
    document.getElementById('rxItemsBody').appendChild(tmpl);
}

function removeRxItem(btn) {
    const body = document.getElementById('rxItemsBody');
    if (body.querySelectorAll('.rx-item').length <= 1) return;
    btn.closest('.rx-item').remove();
}

function printRx(id) {
    document.getElementById('printRxArea').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-danger"></div></div>';
    new bootstrap.Modal(document.getElementById('printRxModal')).show();
    fetch('telemedicine.php?print_rx=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.rx) { document.getElementById('printRxArea').textContent = 'Not found.'; return; }
            const rx = d.rx; const items = d.items;
            let rows = items.map(i => `
              <tr>
                <td style="padding:8px;border-bottom:1px solid #eee;font-weight:600">${i.medicine_name}</td>
                <td style="padding:8px;border-bottom:1px solid #eee">${i.dose||'—'}</td>
                <td style="padding:8px;border-bottom:1px solid #eee">${i.route||'—'}</td>
                <td style="padding:8px;border-bottom:1px solid #eee">${i.frequency||'—'}</td>
                <td style="padding:8px;border-bottom:1px solid #eee">${i.duration||'—'}</td>
                <td style="padding:8px;border-bottom:1px solid #eee;text-align:center">${i.quantity}</td>
              </tr>`).join('');
            const now = new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'});
            document.getElementById('printRxArea').innerHTML = `
              <div id="rxContent" style="font-family:'Segoe UI',Arial,sans-serif;padding:16px;max-width:700px;margin:0 auto">
                <div style="text-align:center;border-bottom:2px solid #c00;padding-bottom:12px;margin-bottom:12px">
                  <h3 style="color:#c00;margin:0">${d.org_name}</h3>
                  <p style="margin:2px 0 0;color:#555;font-size:.8rem">ePrescription — ${rx.rx_no}</p>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.85rem;background:#f8f9fa;padding:10px;border-radius:6px;margin-bottom:12px">
                  <div><span style="color:#666">Patient</span><br><strong>${rx.patient_name}</strong> (${rx.patient_no})</div>
                  <div><span style="color:#666">Doctor</span><br>${rx.doctor_name||'—'} ${rx.specialization?'<br><small>'+rx.specialization+'</small>':''}</div>
                  <div><span style="color:#666">Diagnosis</span><br>${rx.diagnosis||'—'}</div>
                  <div><span style="color:#666">Valid Until</span><br>${rx.valid_until?new Date(rx.valid_until).toLocaleDateString('en-GB'):'—'}</div>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:12px">
                  <thead><tr style="background:#c00;color:white">
                    <th style="padding:6px 10px">Medicine</th><th style="padding:6px 10px">Dose</th>
                    <th style="padding:6px 10px">Route</th><th style="padding:6px 10px">Frequency</th>
                    <th style="padding:6px 10px">Duration</th><th style="padding:6px 10px;text-align:center">Qty</th>
                  </tr></thead>
                  <tbody>${rows}</tbody>
                </table>
                ${rx.instructions ? `<div style="background:#fffbeb;border:1px solid #fbbf24;padding:8px;border-radius:6px;font-size:.85rem;margin-bottom:12px"><strong>Instructions:</strong> ${rx.instructions}</div>` : ''}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.8rem;border-top:1px solid #eee;padding-top:10px">
                  <div>Doctor's Signature: <span style="border-bottom:1px solid #999;display:inline-block;width:120px"></span></div>
                  <div style="text-align:right">Date: ${now}</div>
                </div>
                <div style="text-align:center;font-size:.7rem;color:#aaa;margin-top:8px">
                  Digital Prescription — Token: ${rx.qr_token||'—'}
                </div>
              </div>`;
        });
}

function doPrintRx() {
    const c = document.getElementById('rxContent');
    if (!c) return;
    const w = window.open('','','width=800,height=900');
    w.document.write('<html><head><title>ePrescription</title></head><body>'+c.outerHTML+'</body></html>');
    w.document.close(); w.focus(); w.print(); w.close();
}

$(document).ready(function () {
    if ($('#consultsTable').length) $('#consultsTable').DataTable({ pageLength:25, order:[[4,'desc']], columnDefs:[{orderable:false,targets:[8]}] });
    if ($('#rxTable').length)       $('#rxTable').DataTable({ pageLength:25, order:[[7,'desc']], columnDefs:[{orderable:false,targets:[8]}] });
    if (typeof $.fn.select2 !== 'undefined') {
        $('.select2').select2({ theme:'bootstrap-5', dropdownParent: document.body, width:'100%' });
    }
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
