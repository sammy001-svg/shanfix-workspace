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

// ── AJAX: fetch triage record ─────────────────────────────────────
if (isset($_GET['fetch'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_triage WHERE id=? AND org_id=?");
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
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $uid   = (int)$user['id'];
    $action = $_POST['action'] ?? '';

    // ── Register triage ───────────────────────────────────────────
    if ($action === 'register') {
        $patientId   = (int)($_POST['patient_id']  ?? 0) ?: null;
        $patName     = sanitize($_POST['patient_name']  ?? '');
        $patPhone    = sanitize($_POST['patient_phone'] ?? '');
        $age         = $_POST['age']     !== '' ? (int)$_POST['age']     : null;
        $gender      = in_array($_POST['gender'] ?? '', ['male','female','other','']) ? ($_POST['gender'] ?: null) : null;
        $level       = in_array($_POST['triage_level'] ?? '', ['1_immediate','2_emergent','3_urgent','4_semi_urgent','5_non_urgent']) ? $_POST['triage_level'] : '3_urgent';
        $complaint   = sanitize($_POST['chief_complaint'] ?? '');
        $bpSys       = $_POST['bp_systolic']  !== '' ? (int)$_POST['bp_systolic']  : null;
        $bpDia       = $_POST['bp_diastolic'] !== '' ? (int)$_POST['bp_diastolic'] : null;
        $pulse       = $_POST['pulse']        !== '' ? (int)$_POST['pulse']        : null;
        $temp        = $_POST['temperature']  !== '' ? (float)$_POST['temperature'] : null;
        $spo2        = $_POST['spo2']         !== '' ? (int)$_POST['spo2']         : null;
        $gcs         = $_POST['gcs']          !== '' ? (int)$_POST['gcs']          : null;
        $doctorId    = (int)($_POST['doctor_id'] ?? 0) ?: null;

        // Generate triage number
        $yr    = date('Y');
        $seq   = str_pad((int)$pdo->query("SELECT COUNT(*)+1 FROM health_triage WHERE org_id={$orgId} AND YEAR(triaged_at)='{$yr}'")->fetchColumn(), 4, '0', STR_PAD_LEFT);
        $trNo  = 'TR-' . $yr . '-' . $seq;

        $pdo->prepare("INSERT INTO health_triage (org_id,triage_no,patient_id,patient_name,patient_phone,age,gender,triage_level,chief_complaint,bp_systolic,bp_diastolic,pulse,temperature,spo2,gcs,status,triaged_by,doctor_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'waiting',?,?)")
            ->execute([$orgId,$trNo,$patientId,$patName,$patPhone,$age,$gender,$level,$complaint,$bpSys,$bpDia,$pulse,$temp,$spo2,$gcs,$uid,$doctorId]);
        setFlash('success', "Triage registered: {$trNo}");
        redirect('emergency.php');
    }

    // ── Update triage status ──────────────────────────────────────
    if ($action === 'update_status') {
        $id     = (int)($_POST['id']     ?? 0);
        $status = in_array($_POST['status'] ?? '', ['waiting','in_progress','admitted','discharged','referred','left_without_seen']) ? $_POST['status'] : '';
        if ($id && $status) {
            $seenAt = in_array($status, ['in_progress','admitted','discharged','referred']) ? ', seen_at=NOW()' : '';
            $pdo->prepare("UPDATE health_triage SET status=? {$seenAt} WHERE id=? AND org_id=?")
                ->execute([$status,$id,$orgId]);
            setFlash('success', 'Status updated.');
        }
        redirect('emergency.php');
    }

    // ── Disposition ───────────────────────────────────────────────
    if ($action === 'disposition') {
        $id    = (int)($_POST['id']    ?? 0);
        $disp  = in_array($_POST['disposition'] ?? '', ['admit','discharge','refer','observation','died']) ? $_POST['disposition'] : 'discharge';
        $notes = sanitize($_POST['disposition_notes'] ?? '');
        $status = match($disp) {
            'admit'       => 'admitted',
            'discharge'   => 'discharged',
            'refer'       => 'referred',
            'observation' => 'in_progress',
            default       => 'discharged'
        };
        $pdo->prepare("UPDATE health_triage SET disposition=?,disposition_notes=?,status=?,seen_at=COALESCE(seen_at,NOW()) WHERE id=? AND org_id=?")
            ->execute([$disp,$notes,$status,$id,$orgId]);
        setFlash('success', 'Disposition saved.');
        redirect('emergency.php');
    }
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['queue','history']) ? $_GET['tab'] : 'queue';

// ── Registered patients for dropdown ─────────────────────────────
$patientsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, patient_no FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
$patientsSt->execute([$orgId]);
$patients = $patientsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Doctors ───────────────────────────────────────────────────────
$doctorsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name FROM health_doctors WHERE org_id=? AND status='active' ORDER BY first_name");
$doctorsSt->execute([$orgId]);
$doctors = $doctorsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Active triage queue ───────────────────────────────────────────
$queueSt = $pdo->prepare("
    SELECT t.*, CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM health_triage t
    LEFT JOIN health_doctors d ON d.id=t.doctor_id
    WHERE t.org_id=? AND t.status IN ('waiting','in_progress')
    ORDER BY FIELD(t.triage_level,'1_immediate','2_emergent','3_urgent','4_semi_urgent','5_non_urgent'), t.triaged_at ASC
");
$queueSt->execute([$orgId]);
$queue = $queueSt->fetchAll(PDO::FETCH_ASSOC);

// ── History (last 100) ────────────────────────────────────────────
$filterDate = sanitize($_GET['date'] ?? '');
$histWhere  = "t.org_id=? AND t.status NOT IN ('waiting','in_progress')";
$histParams = [$orgId];
if ($filterDate) { $histWhere .= " AND DATE(t.triaged_at)=?"; $histParams[] = $filterDate; }

$histSt = $pdo->prepare("
    SELECT t.*, CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM health_triage t
    LEFT JOIN health_doctors d ON d.id=t.doctor_id
    WHERE {$histWhere}
    ORDER BY t.triaged_at DESC LIMIT 100
");
$histSt->execute($histParams);
$history = $histSt->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────
$waitingSt = $pdo->prepare("SELECT COUNT(*) FROM health_triage WHERE org_id=? AND status='waiting'");
$waitingSt->execute([$orgId]);
$waiting = (int)$waitingSt->fetchColumn();

$inProgSt = $pdo->prepare("SELECT COUNT(*) FROM health_triage WHERE org_id=? AND status='in_progress'");
$inProgSt->execute([$orgId]);
$inProgress = (int)$inProgSt->fetchColumn();

$immediateSt = $pdo->prepare("SELECT COUNT(*) FROM health_triage WHERE org_id=? AND triage_level='1_immediate' AND status='waiting'");
$immediateSt->execute([$orgId]);
$immediateWaiting = (int)$immediateSt->fetchColumn();

$todaySt = $pdo->prepare("SELECT COUNT(*) FROM health_triage WHERE org_id=? AND DATE(triaged_at)=CURDATE()");
$todaySt->execute([$orgId]);
$todayCount = (int)$todaySt->fetchColumn();

$levelLabels = [
    '1_immediate'  => ['label'=>'Immediate',  'color'=>'danger',  'short'=>'P1'],
    '2_emergent'   => ['label'=>'Emergent',   'color'=>'warning', 'short'=>'P2'],
    '3_urgent'     => ['label'=>'Urgent',     'color'=>'primary', 'short'=>'P3'],
    '4_semi_urgent'=> ['label'=>'Semi-Urgent','color'=>'info',    'short'=>'P4'],
    '5_non_urgent' => ['label'=>'Non-Urgent', 'color'=>'success', 'short'=>'P5'],
];

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="container-fluid py-4">

  <?php flash(); ?>

  <!-- ── Page Header ───────────────────────────────────────────── -->
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h4 class="mb-0 fw-bold"><i class="fas fa-ambulance me-2 text-danger"></i>Emergency / Triage</h4>
      <small class="text-muted">ER patient registration, triage queue & disposition</small>
    </div>
    <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#triageModal">
      <i class="fas fa-plus me-1"></i>Register Patient
    </button>
  </div>

  <!-- ── Stats ─────────────────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3 <?= $immediateWaiting > 0 ? 'border-danger border-2' : '' ?>">
        <div class="text-danger fs-3 fw-bold"><?= $immediateWaiting ?></div>
        <small class="text-muted">P1 Immediate</small>
        <?php if ($immediateWaiting > 0): ?><div><small class="text-danger fw-bold"><i class="fas fa-exclamation-circle"></i> URGENT</small></div><?php endif; ?>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-warning fs-3 fw-bold"><?= $waiting ?></div>
        <small class="text-muted">Waiting</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-primary fs-3 fw-bold"><?= $inProgress ?></div>
        <small class="text-muted">In Progress</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-info fs-3 fw-bold"><?= $todayCount ?></div>
        <small class="text-muted">Total Today</small>
      </div>
    </div>
  </div>

  <!-- ── Tabs ──────────────────────────────────────────────────── -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab==='queue'  ?'active':'' ?>" href="?tab=queue"><i class="fas fa-list-ol me-1"></i>Triage Queue <span class="badge bg-danger ms-1"><?= $waiting + $inProgress ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='history'?'active':'' ?>" href="?tab=history"><i class="fas fa-history me-1"></i>History</a></li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: TRIAGE QUEUE
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'queue'): ?>
  <?php if (empty($queue)): ?>
    <div class="alert alert-success">
      <i class="fas fa-check-circle me-2"></i>No patients currently in triage queue. ER is clear!
    </div>
  <?php else: ?>

  <!-- Grouped by priority -->
  <?php foreach ($levelLabels as $levelKey => $info):
    $levelPatients = array_filter($queue, fn($q) => $q['triage_level'] === $levelKey);
    if (empty($levelPatients)) continue;
  ?>
  <div class="card shadow-sm border-<?= $info['color'] ?> mb-3">
    <div class="card-header bg-<?= $info['color'] ?> <?= in_array($info['color'],['warning','info']) ? 'text-dark' : 'text-white' ?> py-2">
      <strong><?= $info['short'] ?> — <?= $info['label'] ?></strong>
      <span class="badge bg-white text-<?= $info['color'] ?> ms-2"><?= count($levelPatients) ?></span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr><th>#</th><th>Patient</th><th>Age/Gender</th><th>Complaint</th><th>Vitals</th><th>Doctor</th><th>Wait</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php foreach ($levelPatients as $q):
            $waitMins = (int)floor((time() - strtotime($q['triaged_at'])) / 60);
            $waitBadge = $waitMins >= 30 ? 'danger' : ($waitMins >= 15 ? 'warning text-dark' : 'success');
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($q['triage_no']) ?></strong></td>
              <td>
                <?php if ($q['patient_name']): ?>
                  <div class="fw-semibold"><?= htmlspecialchars($q['patient_name']) ?></div>
                <?php else: ?>
                  <div class="fw-semibold"><?= htmlspecialchars($q['patient_name'] ?: 'Walk-in') ?></div>
                <?php endif; ?>
                <?php if ($q['patient_phone']): ?><small class="text-muted"><?= htmlspecialchars($q['patient_phone']) ?></small><?php endif; ?>
              </td>
              <td><?= $q['age'] ? $q['age'].'y' : '?' ?> / <?= $q['gender'] ? ucfirst($q['gender'][0]) : '?' ?></td>
              <td style="max-width:200px"><small><?= htmlspecialchars($q['chief_complaint'] ?: '—') ?></small></td>
              <td>
                <small>
                  <?php if ($q['bp_systolic'] && $q['bp_diastolic']): ?><div>BP: <?= $q['bp_systolic'] ?>/<?= $q['bp_diastolic'] ?></div><?php endif; ?>
                  <?php if ($q['pulse']): ?><div>HR: <?= $q['pulse'] ?> bpm</div><?php endif; ?>
                  <?php if ($q['temperature']): ?><div>T: <?= $q['temperature'] ?>°C</div><?php endif; ?>
                  <?php if ($q['spo2']): ?><div>SpO2: <?= $q['spo2'] ?>%</div><?php endif; ?>
                  <?php if ($q['gcs']): ?><div>GCS: <?= $q['gcs'] ?></div><?php endif; ?>
                </small>
              </td>
              <td><small><?= htmlspecialchars($q['doctor_name'] ?: '—') ?></small></td>
              <td><span class="badge bg-<?= $waitBadge ?>"><?= $waitMins ?> min</span></td>
              <td>
                <?= $q['status']==='waiting' ?
                  '<span class="badge bg-warning text-dark">Waiting</span>' :
                  '<span class="badge bg-primary">In Progress</span>' ?>
              </td>
              <td>
                <div class="btn-group btn-group-sm">
                  <?php if ($q['status'] === 'waiting'): ?>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="id" value="<?= $q['id'] ?>">
                      <input type="hidden" name="status" value="in_progress">
                      <button type="submit" class="btn btn-outline-primary btn-sm" title="Start Assessment"><i class="fas fa-play"></i></button>
                    </form>
                  <?php endif; ?>
                  <button class="btn btn-outline-success btn-sm" onclick="openDisposition(<?= $q['id'] ?>, '<?= htmlspecialchars(addslashes($q['triage_no'])) ?>')" title="Disposition"><i class="fas fa-sign-out-alt"></i></button>
                  <button class="btn btn-outline-secondary btn-sm" onclick="viewTriage(<?= $q['id'] ?>)" title="View Full"><i class="fas fa-eye"></i></button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: HISTORY
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'history'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="history">
        <div class="col-6 col-md-2">
          <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDate) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-auto">
          <a href="?tab=history" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
      </form>
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="histTable">
          <thead class="table-light">
            <tr><th>Triage No</th><th>Patient</th><th>Level</th><th>Complaint</th><th>Doctor</th><th>Status</th><th>Disposition</th><th>Triaged</th></tr>
          </thead>
          <tbody>
          <?php if (empty($history)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No history found.</td></tr>
          <?php else: foreach ($history as $h):
            $lvl     = $levelLabels[$h['triage_level']] ?? ['label'=>$h['triage_level'],'color'=>'secondary','short'=>'?'];
            $stBadge = match($h['status']) {
                'admitted'         => 'danger',
                'discharged'       => 'success',
                'referred'         => 'info',
                'left_without_seen'=> 'warning text-dark',
                default            => 'secondary'
            };
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($h['triage_no']) ?></strong></td>
              <td><?= htmlspecialchars($h['patient_name'] ?: '(Walk-in)') ?></td>
              <td><span class="badge bg-<?= $lvl['color'] ?>"><?= $lvl['short'] ?> <?= $lvl['label'] ?></span></td>
              <td><small><?= htmlspecialchars($h['chief_complaint'] ?: '—') ?></small></td>
              <td><small><?= htmlspecialchars($h['doctor_name'] ?: '—') ?></small></td>
              <td><span class="badge bg-<?= $stBadge ?>"><?= ucfirst(str_replace('_',' ',$h['status'])) ?></span></td>
              <td><?= $h['disposition'] ? ucfirst($h['disposition']) : '—' ?></td>
              <td><small><?= date('d M Y H:i', strtotime($h['triaged_at'])) ?></small></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<!-- ── Modal: Register Triage ────────────────────────────────────── -->
<div class="modal fade" id="triageModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="register">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-ambulance me-2"></i>Register Emergency / Triage</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <!-- Patient identification -->
            <div class="col-12">
              <div class="alert alert-light border py-2 mb-0">
                <strong>Patient Identification</strong> — search existing or enter walk-in details
              </div>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">Registered Patient</label>
              <select name="patient_id" class="form-select select2">
                <option value="">Walk-in (not registered)</option>
                <?php foreach ($patients as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold">Walk-in Name</label>
              <input type="text" name="patient_name" class="form-control" placeholder="Name (if not registered)">
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="patient_phone" class="form-control" placeholder="Phone number">
            </div>
            <div class="col-4 col-md-2">
              <label class="form-label fw-semibold">Age</label>
              <input type="number" name="age" class="form-control" min="0" max="120" placeholder="yrs">
            </div>
            <div class="col-8 col-md-3">
              <label class="form-label fw-semibold">Gender</label>
              <select name="gender" class="form-select">
                <option value="">Unknown</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="other">Other</option>
              </select>
            </div>

            <!-- Triage classification -->
            <div class="col-12">
              <div class="alert alert-warning border py-2 mb-0"><strong>Triage Classification</strong></div>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Triage Level <span class="text-danger">*</span></label>
              <select name="triage_level" class="form-select" id="triageLevelSel" onchange="highlightLevel()">
                <option value="1_immediate">P1 — Immediate (life-threatening)</option>
                <option value="2_emergent">P2 — Emergent (potentially life-threatening)</option>
                <option value="3_urgent" selected>P3 — Urgent (stable but needs care)</option>
                <option value="4_semi_urgent">P4 — Semi-Urgent</option>
                <option value="5_non_urgent">P5 — Non-Urgent</option>
              </select>
              <div id="levelHint" class="form-text mt-1"></div>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Chief Complaint</label>
              <textarea name="chief_complaint" class="form-control" rows="3" placeholder="Main presenting complaint…"></textarea>
            </div>

            <!-- Vital signs -->
            <div class="col-12">
              <div class="alert alert-light border py-2 mb-0"><strong>Vital Signs at Triage</strong></div>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold">BP Systolic</label>
              <input type="number" name="bp_systolic" class="form-control" placeholder="mmHg">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold">BP Diastolic</label>
              <input type="number" name="bp_diastolic" class="form-control" placeholder="mmHg">
            </div>
            <div class="col-4 col-md-2">
              <label class="form-label fw-semibold">Pulse (bpm)</label>
              <input type="number" name="pulse" class="form-control">
            </div>
            <div class="col-4 col-md-2">
              <label class="form-label fw-semibold">Temp (°C)</label>
              <input type="number" name="temperature" class="form-control" step="0.1">
            </div>
            <div class="col-4 col-md-2">
              <label class="form-label fw-semibold">SpO2 (%)</label>
              <input type="number" name="spo2" class="form-control" min="0" max="100">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold">GCS (3-15)</label>
              <input type="number" name="gcs" class="form-control" min="3" max="15">
            </div>

            <!-- Assignment -->
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">Assign Doctor</label>
              <select name="doctor_id" class="form-select select2">
                <option value="">Unassigned</option>
                <?php foreach ($doctors as $d): ?>
                  <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-ambulance me-1"></i>Register Patient</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Disposition ────────────────────────────────────────── -->
<div class="modal fade" id="dispositionModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="disposition">
      <input type="hidden" name="id" id="dispId">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="fas fa-sign-out-alt me-2"></i>Patient Disposition</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-light border mb-3 py-2">Triage: <strong id="dispTriageNo"></strong></div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Disposition</label>
            <select name="disposition" class="form-select">
              <option value="discharge">Discharge</option>
              <option value="admit">Admit (IPD)</option>
              <option value="refer">Refer</option>
              <option value="observation">Observation</option>
              <option value="died">Death</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="disposition_notes" class="form-control" rows="3" placeholder="Discharge instructions, referral notes…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Disposition</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: View Triage ────────────────────────────────────────── -->
<div class="modal fade" id="viewTriageModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="fas fa-ambulance me-2"></i>Triage Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="viewTriageBody">Loading…</div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<?php
$levelHints = json_encode([
    '1_immediate'   => '🔴 Immediate — life-threatening; must be seen NOW',
    '2_emergent'    => '🟠 Emergent — potentially life-threatening; within 15 min',
    '3_urgent'      => '🟡 Urgent — stable but requires timely care; within 30 min',
    '4_semi_urgent' => '🟢 Semi-Urgent — non-life-threatening; within 60 min',
    '5_non_urgent'  => '⚪ Non-Urgent — minor complaint; within 2 hours',
]);
$extraJs = <<<JS
<script>
const LEVEL_HINTS = {$levelHints};
function highlightLevel() {
    const val = document.getElementById('triageLevelSel').value;
    document.getElementById('levelHint').textContent = LEVEL_HINTS[val] || '';
}
highlightLevel();

function openDisposition(id, no) {
    document.getElementById('dispId').value = id;
    document.getElementById('dispTriageNo').textContent = no;
    new bootstrap.Modal(document.getElementById('dispositionModal')).show();
}

function viewTriage(id) {
    document.getElementById('viewTriageBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-danger"></div></div>';
    new bootstrap.Modal(document.getElementById('viewTriageModal')).show();
    fetch('emergency.php?fetch=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.id) { document.getElementById('viewTriageBody').textContent = 'Not found.'; return; }
            document.getElementById('viewTriageBody').innerHTML = `
              <table class="table table-sm">
                <tr><td class="text-muted w-40">Triage No</td><td><strong>\${d.triage_no}</strong></td></tr>
                <tr><td class="text-muted">Patient</td><td>\${d.patient_name || '(Walk-in)'} \${d.patient_phone ? '<br><small>'+d.patient_phone+'</small>' : ''}</td></tr>
                <tr><td class="text-muted">Age / Gender</td><td>\${d.age ? d.age+'y' : '?'} / \${d.gender || '?'}</td></tr>
                <tr><td class="text-muted">Triage Level</td><td><span class="badge bg-danger">\${d.triage_level.replace(/_/g,' ')}</span></td></tr>
                <tr><td class="text-muted">Chief Complaint</td><td>\${d.chief_complaint || '—'}</td></tr>
                <tr><td class="text-muted">BP</td><td>\${d.bp_systolic && d.bp_diastolic ? d.bp_systolic+'/'+d.bp_diastolic+' mmHg' : '—'}</td></tr>
                <tr><td class="text-muted">Pulse</td><td>\${d.pulse ? d.pulse+' bpm' : '—'}</td></tr>
                <tr><td class="text-muted">Temperature</td><td>\${d.temperature ? d.temperature+'°C' : '—'}</td></tr>
                <tr><td class="text-muted">SpO2</td><td>\${d.spo2 ? d.spo2+'%' : '—'}</td></tr>
                <tr><td class="text-muted">GCS</td><td>\${d.gcs || '—'}</td></tr>
                <tr><td class="text-muted">Status</td><td>\${d.status}</td></tr>
                <tr><td class="text-muted">Triaged</td><td>\${d.triaged_at}</td></tr>
              </table>`;
        });
}

$(document).ready(function () {
    if ($('#histTable').length) $('#histTable').DataTable({ pageLength: 25, order: [[7,'desc']] });
    if (typeof \$.fn.select2 !== 'undefined') {
        \$('.select2').select2({ theme:'bootstrap-5', dropdownParent: document.body, width:'100%' });
    }
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
