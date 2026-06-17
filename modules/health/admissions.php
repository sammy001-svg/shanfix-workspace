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
    ['url'=>'settings.php',      'icon'=>'fas fa-cog',                 'label'=>'Settings'],
];

// ── AJAX: fetch admission ─────────────────────────────────────────
if (isset($_GET['fetch'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_admissions WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── AJAX: available beds for a ward ──────────────────────────────
if (isset($_GET['avail_beds'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId  = (int)currentUser()['org_id'];
    $wardId = (int)$_GET['avail_beds'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT id, bed_no, bed_type FROM health_beds WHERE org_id=? AND ward_id=? AND status='available' ORDER BY bed_no");
        $st->execute([$orgId, $wardId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo '[]'; }
    exit;
}

// ── POST handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $uid   = (int)$user['id'];
    $action = $_POST['action'] ?? '';

    // ── Admit patient ─────────────────────────────────────────────
    if ($action === 'admit') {
        $patientId  = (int)($_POST['patient_id']     ?? 0);
        $doctorId   = (int)($_POST['doctor_id']      ?? 0) ?: null;
        $wardId     = (int)($_POST['ward_id']        ?? 0) ?: null;
        $bedId      = (int)($_POST['bed_id']         ?? 0) ?: null;
        $reason     = sanitize($_POST['reason']      ?? '');
        $diagnosis  = sanitize($_POST['diagnosis']   ?? '');
        $admType    = in_array($_POST['admission_type'] ?? '', ['emergency','elective','maternity','referral','other']) ? $_POST['admission_type'] : 'elective';
        $admittedAt = $_POST['admitted_at']          ?? date('Y-m-d H:i:s');

        if (!$patientId) { setFlash('error', 'Patient is required.'); redirect('admissions.php'); }

        // Generate admission number
        $yr    = date('Y');
        $cntSt = $pdo->prepare("SELECT COUNT(*)+1 FROM health_admissions WHERE org_id=? AND YEAR(admitted_at)=?");
        $cntSt->execute([$orgId, $yr]);
        $seq   = str_pad((int)$cntSt->fetchColumn(), 4, '0', STR_PAD_LEFT);
        $admNo = 'IPD-' . $yr . '-' . $seq;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO health_admissions (org_id,admission_no,patient_id,doctor_id,ward_id,bed_id,reason,diagnosis,admission_type,status,admitted_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,'admitted',?,?)")
                ->execute([$orgId,$admNo,$patientId,$doctorId,$wardId,$bedId,$reason,$diagnosis,$admType,$admittedAt,$uid]);
            // Mark bed as occupied
            if ($bedId) {
                $pdo->prepare("UPDATE health_beds SET status='occupied' WHERE id=? AND org_id=?")
                    ->execute([$bedId, $orgId]);
            }
            $pdo->commit();
            setFlash('success', "Patient admitted. Admission No: {$admNo}");
        } catch (Exception $ex) {
            $pdo->rollBack();
            setFlash('error', 'Admission failed: ' . $ex->getMessage());
        }
        redirect('admissions.php');
    }

    // ── Transfer (change ward/bed) ────────────────────────────────
    if ($action === 'transfer') {
        $id        = (int)($_POST['id']      ?? 0);
        $newWardId = (int)($_POST['ward_id'] ?? 0) ?: null;
        $newBedId  = (int)($_POST['bed_id']  ?? 0) ?: null;

        // Fetch current bed
        $curSt = $pdo->prepare("SELECT bed_id FROM health_admissions WHERE id=? AND org_id=?");
        $curSt->execute([$id, $orgId]);
        $cur = $curSt->fetch();

        $pdo->beginTransaction();
        try {
            // Free old bed
            if ($cur && $cur['bed_id']) {
                $pdo->prepare("UPDATE health_beds SET status='available' WHERE id=? AND org_id=?")
                    ->execute([$cur['bed_id'], $orgId]);
            }
            // Occupy new bed
            if ($newBedId) {
                $pdo->prepare("UPDATE health_beds SET status='occupied' WHERE id=? AND org_id=?")
                    ->execute([$newBedId, $orgId]);
            }
            $pdo->prepare("UPDATE health_admissions SET ward_id=?,bed_id=?,status='admitted' WHERE id=? AND org_id=?")
                ->execute([$newWardId,$newBedId,$id,$orgId]);
            $pdo->commit();
            setFlash('success', 'Transfer completed.');
        } catch (Exception $ex) {
            $pdo->rollBack();
            setFlash('error', 'Transfer failed: ' . $ex->getMessage());
        }
        redirect('admissions.php');
    }

    // ── Discharge ─────────────────────────────────────────────────
    if ($action === 'discharge') {
        $id          = (int)($_POST['id']            ?? 0);
        $discNotes   = sanitize($_POST['discharge_notes'] ?? '');
        $discType    = in_array($_POST['discharge_type'] ?? '', ['recovered','referred','absconded','death','other']) ? $_POST['discharge_type'] : 'recovered';
        $diagnosis   = sanitize($_POST['diagnosis']  ?? '');

        $curSt = $pdo->prepare("SELECT bed_id FROM health_admissions WHERE id=? AND org_id=?");
        $curSt->execute([$id, $orgId]);
        $cur = $curSt->fetch();

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE health_admissions SET status='discharged',discharged_at=NOW(),discharge_notes=?,discharge_type=?,diagnosis=? WHERE id=? AND org_id=?")
                ->execute([$discNotes,$discType,$diagnosis,$id,$orgId]);
            if ($cur && $cur['bed_id']) {
                $pdo->prepare("UPDATE health_beds SET status='cleaning' WHERE id=? AND org_id=?")
                    ->execute([$cur['bed_id'], $orgId]);
            }
            $pdo->commit();
            setFlash('success', 'Patient discharged.');
        } catch (Exception $ex) {
            $pdo->rollBack();
            setFlash('error', 'Discharge failed: ' . $ex->getMessage());
        }
        redirect('admissions.php?tab=discharged');
    }

    // ── Update diagnosis/notes inline ─────────────────────────────
    if ($action === 'update_notes') {
        $id        = (int)($_POST['id'] ?? 0);
        $diagnosis = sanitize($_POST['diagnosis'] ?? '');
        $reason    = sanitize($_POST['reason']    ?? '');
        $pdo->prepare("UPDATE health_admissions SET diagnosis=?,reason=? WHERE id=? AND org_id=?")
            ->execute([$diagnosis,$reason,$id,$orgId]);
        setFlash('success', 'Notes updated.');
        redirect('admissions.php');
    }
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['active','discharged']) ? $_GET['tab'] : 'active';

// ── Patients ──────────────────────────────────────────────────────
$patientsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, patient_no FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
$patientsSt->execute([$orgId]);
$patients = $patientsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Doctors ───────────────────────────────────────────────────────
$doctorsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name FROM health_doctors WHERE org_id=? AND status='active' ORDER BY first_name");
$doctorsSt->execute([$orgId]);
$doctors = $doctorsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Wards ─────────────────────────────────────────────────────────
$wardsSt = $pdo->prepare("SELECT * FROM health_wards WHERE org_id=? AND status='active' ORDER BY name");
$wardsSt->execute([$orgId]);
$wards = $wardsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Active admissions ─────────────────────────────────────────────
$filterDoctor = (int)($_GET['doctor_id'] ?? 0);
$filterWard   = (int)($_GET['ward_id']   ?? 0);
$filterSearch = sanitize($_GET['search'] ?? '');

$admWhere  = "a.org_id=? AND a.status='admitted'";
$admParams = [$orgId];
if ($filterDoctor) { $admWhere .= " AND a.doctor_id=?"; $admParams[] = $filterDoctor; }
if ($filterWard)   { $admWhere .= " AND a.ward_id=?";   $admParams[] = $filterWard; }
if ($filterSearch) { $admWhere .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR a.admission_no LIKE ?)"; $admParams[] = "%$filterSearch%"; $admParams[] = "%$filterSearch%"; $admParams[] = "%$filterSearch%"; }

$admSt = $pdo->prepare("
    SELECT a.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no, p.dob, p.gender,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
           w.name AS ward_name, b.bed_no,
           DATEDIFF(NOW(), a.admitted_at) AS los
    FROM health_admissions a
    LEFT JOIN health_patients p ON p.id=a.patient_id
    LEFT JOIN health_doctors d ON d.id=a.doctor_id
    LEFT JOIN health_wards w ON w.id=a.ward_id
    LEFT JOIN health_beds b ON b.id=a.bed_id
    WHERE {$admWhere}
    ORDER BY a.admitted_at DESC
");
$admSt->execute($admParams);
$admissions = $admSt->fetchAll(PDO::FETCH_ASSOC);

// ── Discharged (last 30 days) ─────────────────────────────────────
$discSt = $pdo->prepare("
    SELECT a.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
           w.name AS ward_name, b.bed_no,
           DATEDIFF(a.discharged_at, a.admitted_at) AS los
    FROM health_admissions a
    LEFT JOIN health_patients p ON p.id=a.patient_id
    LEFT JOIN health_doctors d ON d.id=a.doctor_id
    LEFT JOIN health_wards w ON w.id=a.ward_id
    LEFT JOIN health_beds b ON b.id=a.bed_id
    WHERE a.org_id=? AND a.status='discharged'
    ORDER BY a.discharged_at DESC LIMIT 100
");
$discSt->execute([$orgId]);
$discharged = $discSt->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────
$statActive = count($admissions);

$todayAdmSt = $pdo->prepare("SELECT COUNT(*) FROM health_admissions WHERE org_id=? AND DATE(admitted_at)=CURDATE()");
$todayAdmSt->execute([$orgId]);
$todayAdm = (int)$todayAdmSt->fetchColumn();

$todayDiscSt = $pdo->prepare("SELECT COUNT(*) FROM health_admissions WHERE org_id=? AND DATE(discharged_at)=CURDATE()");
$todayDiscSt->execute([$orgId]);
$todayDisc = (int)$todayDiscSt->fetchColumn();

$avgLosSt = $pdo->prepare("SELECT ROUND(AVG(DATEDIFF(discharged_at, admitted_at)),1) FROM health_admissions WHERE org_id=? AND status='discharged' AND discharged_at >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
$avgLosSt->execute([$orgId]);
$avgLos = $avgLosSt->fetchColumn() ?: 0;

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="container-fluid py-4">

  <?php flash(); ?>

  <!-- ── Page Header ───────────────────────────────────────────── -->
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h4 class="mb-0 fw-bold"><i class="fas fa-hospital-user me-2 text-danger"></i>Admissions (IPD)</h4>
      <small class="text-muted">In-patient admissions, transfers & discharges</small>
    </div>
    <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#admitModal">
      <i class="fas fa-plus me-1"></i>Admit Patient
    </button>
  </div>

  <!-- ── Stats ─────────────────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-danger fs-3 fw-bold"><?= $statActive ?></div>
        <small class="text-muted">Currently Admitted</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-primary fs-3 fw-bold"><?= $todayAdm ?></div>
        <small class="text-muted">Admitted Today</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-success fs-3 fw-bold"><?= $todayDisc ?></div>
        <small class="text-muted">Discharged Today</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-info fs-3 fw-bold"><?= $avgLos ?> days</div>
        <small class="text-muted">Avg LOS (30 days)</small>
      </div>
    </div>
  </div>

  <!-- ── Tabs ──────────────────────────────────────────────────── -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab==='active'    ?'active':'' ?>" href="?tab=active"><i class="fas fa-hospital-user me-1"></i>Active <span class="badge bg-danger ms-1"><?= $statActive ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='discharged'?'active':'' ?>" href="?tab=discharged"><i class="fas fa-sign-out-alt me-1"></i>Discharged</a></li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: ACTIVE ADMISSIONS
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'active'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="active">
        <div class="col-12 col-md-3">
          <input type="text" name="search" class="form-control form-control-sm" value="<?= htmlspecialchars($filterSearch) ?>" placeholder="Search patient / admission no…">
        </div>
        <div class="col-6 col-md-3">
          <select name="doctor_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Doctors</option>
            <?php foreach ($doctors as $d): ?>
              <option value="<?= $d['id'] ?>" <?= $filterDoctor==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <select name="ward_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Wards</option>
            <?php foreach ($wards as $w): ?>
              <option value="<?= $w['id'] ?>" <?= $filterWard==$w['id']?'selected':'' ?>><?= htmlspecialchars($w['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary btn-sm">Search</button>
          <a href="?tab=active" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="admTable">
          <thead class="table-light">
            <tr>
              <th>Adm No</th>
              <th>Patient</th>
              <th>Ward / Bed</th>
              <th>Doctor</th>
              <th>Type</th>
              <th>Diagnosis</th>
              <th>Admitted</th>
              <th>LOS</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($admissions)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No active admissions.</td></tr>
          <?php else: foreach ($admissions as $a):
            $typeBadge = match($a['admission_type']) {
                'emergency' => 'danger',
                'maternity' => 'info text-dark',
                'referral'  => 'primary',
                default     => 'secondary'
            };
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($a['admission_no']) ?></strong></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($a['patient_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($a['patient_no']) ?></small>
              </td>
              <td>
                <div><?= htmlspecialchars($a['ward_name'] ?: '—') ?></div>
                <?php if ($a['bed_no']): ?><small class="text-muted">Bed <?= htmlspecialchars($a['bed_no']) ?></small><?php endif; ?>
              </td>
              <td><small><?= htmlspecialchars($a['doctor_name'] ?: '—') ?></small></td>
              <td><span class="badge bg-<?= $typeBadge ?>"><?= ucfirst($a['admission_type']) ?></span></td>
              <td><small><?= htmlspecialchars($a['diagnosis'] ?: '—') ?></small></td>
              <td><small><?= date('d M Y H:i', strtotime($a['admitted_at'])) ?></small></td>
              <td><span class="badge bg-<?= ($a['los']??0) > 7 ? 'danger' : (($a['los']??0) > 3 ? 'warning text-dark' : 'success') ?>"><?= $a['los'] ?? 0 ?> days</span></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary btn-sm" onclick="openTransfer(<?= $a['id'] ?>, <?= $a['ward_id'] ?: 0 ?>, <?= $a['bed_id'] ?: 0 ?>)" title="Transfer"><i class="fas fa-exchange-alt"></i></button>
                  <button class="btn btn-outline-warning btn-sm" onclick="openNotesModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['diagnosis'])) ?>', '<?= htmlspecialchars(addslashes($a['reason'])) ?>')" title="Update Notes"><i class="fas fa-notes-medical"></i></button>
                  <button class="btn btn-outline-success btn-sm" onclick="openDischarge(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['admission_no'])) ?>')" title="Discharge"><i class="fas fa-sign-out-alt"></i></button>
                  <a href="<?= APP_URL ?>/modules/health/discharge-summary-pdf.php?id=<?= $a['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm" title="Discharge Summary PDF"><i class="fas fa-file-alt"></i></a>
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
       TAB: DISCHARGED
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'discharged'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="discTable">
          <thead class="table-light">
            <tr>
              <th>Adm No</th>
              <th>Patient</th>
              <th>Ward / Bed</th>
              <th>Doctor</th>
              <th>Diagnosis</th>
              <th>LOS</th>
              <th>Discharge Type</th>
              <th>Discharged</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($discharged)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No discharge records.</td></tr>
          <?php else: foreach ($discharged as $d):
            $dtBadge = match($d['discharge_type']) {
                'death'     => 'dark',
                'referred'  => 'info',
                'absconded' => 'warning text-dark',
                'recovered' => 'success',
                default     => 'secondary'
            };
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($d['admission_no']) ?></strong></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($d['patient_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($d['patient_no']) ?></small>
              </td>
              <td><?= htmlspecialchars($d['ward_name'] ?: '—') ?><?php if($d['bed_no']): ?> / Bed <?= htmlspecialchars($d['bed_no']) ?><?php endif; ?></td>
              <td><small><?= htmlspecialchars($d['doctor_name'] ?: '—') ?></small></td>
              <td><small><?= htmlspecialchars($d['diagnosis'] ?: '—') ?></small></td>
              <td><span class="badge bg-secondary"><?= $d['los'] ?? 0 ?> days</span></td>
              <td><span class="badge bg-<?= $dtBadge ?>"><?= ucfirst($d['discharge_type'] ?? '—') ?></span></td>
              <td><small><?= $d['discharged_at'] ? date('d M Y H:i', strtotime($d['discharged_at'])) : '—' ?></small></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<!-- ── Modal: Admit Patient ───────────────────────────────────────── -->
<div class="modal fade" id="admitModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="admit">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-hospital-user me-2"></i>Admit Patient</h5>
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
              <label class="form-label fw-semibold">Attending Doctor</label>
              <select name="doctor_id" class="form-select select2">
                <option value="">Select Doctor</option>
                <?php foreach ($doctors as $d): ?>
                  <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Admission Type</label>
              <select name="admission_type" class="form-select">
                <option value="elective">Elective</option>
                <option value="emergency">Emergency</option>
                <option value="maternity">Maternity</option>
                <option value="referral">Referral</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Ward</label>
              <select name="ward_id" id="admWardId" class="form-select" onchange="loadAvailBeds(this.value)">
                <option value="">Select Ward</option>
                <?php foreach ($wards as $w): ?>
                  <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Bed</label>
              <select name="bed_id" id="admBedId" class="form-select">
                <option value="">Select Ward first</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Admitted At</label>
              <input type="datetime-local" name="admitted_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Reason for Admission</label>
              <textarea name="reason" class="form-control" rows="2" placeholder="Chief complaint / reason…"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Provisional Diagnosis</label>
              <textarea name="diagnosis" class="form-control" rows="2" placeholder="Working diagnosis…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-hospital-user me-1"></i>Admit Patient</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Transfer ────────────────────────────────────────────── -->
<div class="modal fade" id="transferModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="transfer">
      <input type="hidden" name="id" id="transferId">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Transfer Patient</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">New Ward</label>
              <select name="ward_id" id="transferWardId" class="form-select" onchange="loadAvailBeds(this.value, 'transferBedId')">
                <option value="">No Change</option>
                <?php foreach ($wards as $w): ?>
                  <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">New Bed</label>
              <select name="bed_id" id="transferBedId" class="form-select">
                <option value="">Select Ward first</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-exchange-alt me-1"></i>Confirm Transfer</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Discharge ───────────────────────────────────────────── -->
<div class="modal fade" id="dischargeModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="discharge">
      <input type="hidden" name="id" id="dischargeId">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="fas fa-sign-out-alt me-2"></i>Discharge Patient</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning py-2"><i class="fas fa-exclamation-triangle me-1"></i>Admission: <strong id="dischargeAdmNo"></strong></div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Final Diagnosis</label>
              <textarea name="diagnosis" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Discharge Type</label>
              <select name="discharge_type" class="form-select">
                <option value="recovered">Recovered</option>
                <option value="referred">Referred</option>
                <option value="absconded">Absconded</option>
                <option value="death">Death</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Discharge Notes / Instructions</label>
              <textarea name="discharge_notes" class="form-control" rows="3" placeholder="Discharge summary, follow-up instructions…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-sign-out-alt me-1"></i>Discharge</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Update Notes ────────────────────────────────────────── -->
<div class="modal fade" id="notesModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_notes">
      <input type="hidden" name="id" id="notesId">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title"><i class="fas fa-notes-medical me-2"></i>Update Notes</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Reason for Admission</label>
            <textarea name="reason" id="notesReason" class="form-control" rows="2"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Diagnosis</label>
            <textarea name="diagnosis" id="notesDiagnosis" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning text-dark"><i class="fas fa-save me-1"></i>Save</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function loadAvailBeds(wardId, targetId) {
    const sel = document.getElementById(targetId || 'admBedId');
    sel.innerHTML = '<option value="">Loading…</option>';
    if (!wardId) { sel.innerHTML = '<option value="">Select Ward first</option>'; return; }
    fetch('admissions.php?avail_beds=' + wardId)
        .then(r => r.json())
        .then(beds => {
            sel.innerHTML = '<option value="">No specific bed</option>';
            beds.forEach(b => {
                const opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = b.bed_no + ' (' + b.bed_type + ')';
                sel.appendChild(opt);
            });
            if (beds.length === 0) sel.innerHTML = '<option value="">No available beds</option>';
        });
}

function openTransfer(id, currentWardId, currentBedId) {
    document.getElementById('transferId').value = id;
    document.getElementById('transferWardId').value = currentWardId || '';
    if (currentWardId) loadAvailBeds(currentWardId, 'transferBedId');
    new bootstrap.Modal(document.getElementById('transferModal')).show();
}

function openDischarge(id, admNo) {
    document.getElementById('dischargeId').value = id;
    document.getElementById('dischargeAdmNo').textContent = admNo;
    new bootstrap.Modal(document.getElementById('dischargeModal')).show();
}

function openNotesModal(id, diagnosis, reason) {
    document.getElementById('notesId').value         = id;
    document.getElementById('notesDiagnosis').value  = diagnosis;
    document.getElementById('notesReason').value     = reason;
    new bootstrap.Modal(document.getElementById('notesModal')).show();
}

$(document).ready(function () {
    if ($('#admTable').length)  $('#admTable').DataTable({ pageLength: 25, order: [[6,'desc']], columnDefs:[{orderable:false,targets:[8]}] });
    if ($('#discTable').length) $('#discTable').DataTable({ pageLength: 25, order: [[7,'desc']] });
    if (typeof $.fn.select2 !== 'undefined') {
        $('.select2').select2({ theme:'bootstrap-5', dropdownParent: document.body, width:'100%' });
    }
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
