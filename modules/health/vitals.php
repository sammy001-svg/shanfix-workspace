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
    ['url'=>'schedule.php',      'icon'=>'fas fa-calendar-alt',        'label'=>'Doctor Schedule'],
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

// ── AJAX: fetch patient vitals for trend chart ────────────────────
if (isset($_GET['chart_data'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $pid   = (int)($_GET['chart_data']);
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT recorded_at, bp_systolic, bp_diastolic, pulse, temperature, spo2 FROM health_vitals WHERE org_id=? AND patient_id=? ORDER BY recorded_at ASC LIMIT 20");
        $st->execute([$orgId, $pid]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo '[]'; }
    exit;
}

// ── AJAX: fetch single record for edit ────────────────────────────
if (isset($_GET['fetch'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_vitals WHERE id=? AND org_id=?");
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
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $uid   = (int)$user['id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = (int)($_POST['id']             ?? 0);
        $patientId = (int)($_POST['patient_id']     ?? 0);
        $apptId    = (int)($_POST['appointment_id'] ?? 0) ?: null;
        $bpSys     = $_POST['bp_systolic']   !== '' ? (int)$_POST['bp_systolic']   : null;
        $bpDia     = $_POST['bp_diastolic']  !== '' ? (int)$_POST['bp_diastolic']  : null;
        $pulse     = $_POST['pulse']         !== '' ? (int)$_POST['pulse']         : null;
        $temp      = $_POST['temperature']   !== '' ? (float)$_POST['temperature'] : null;
        $weight    = $_POST['weight']        !== '' ? (float)$_POST['weight']      : null;
        $height    = $_POST['height']        !== '' ? (float)$_POST['height']      : null;
        $spo2      = $_POST['spo2']          !== '' ? (int)$_POST['spo2']          : null;
        $resp      = $_POST['resp_rate']     !== '' ? (int)$_POST['resp_rate']     : null;
        $pain      = $_POST['pain_scale']    !== '' ? (int)$_POST['pain_scale']    : null;
        $notes     = sanitize($_POST['notes'] ?? '');
        $recAt     = $_POST['recorded_at']   ?? date('Y-m-d H:i:s');

        // Auto-calculate BMI
        $bmi = ($weight && $height && $height > 0)
            ? round($weight / (($height / 100) ** 2), 2) : null;

        if (!$patientId) {
            setFlash('danger','Patient is required.'); redirect('vitals.php'); exit;
        }

        if ($id) {
            $pdo->prepare("UPDATE health_vitals SET patient_id=?,appointment_id=?,bp_systolic=?,bp_diastolic=?,pulse=?,temperature=?,weight=?,height=?,bmi=?,spo2=?,resp_rate=?,pain_scale=?,notes=?,recorded_at=?,recorded_by=? WHERE id=? AND org_id=?")
                ->execute([$patientId,$apptId,$bpSys,$bpDia,$pulse,$temp,$weight,$height,$bmi,$spo2,$resp,$pain,$notes,$recAt,$uid,$id,$orgId]);
            setFlash('success','Vitals updated.');
        } else {
            $pdo->prepare("INSERT INTO health_vitals (org_id,patient_id,appointment_id,bp_systolic,bp_diastolic,pulse,temperature,weight,height,bmi,spo2,resp_rate,pain_scale,notes,recorded_at,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$patientId,$apptId,$bpSys,$bpDia,$pulse,$temp,$weight,$height,$bmi,$spo2,$resp,$pain,$notes,$recAt,$uid]);
            setFlash('success','Vital signs recorded.');
        }
        logActivity('save','health',"Vitals saved for patient #$patientId");
        redirect('vitals.php' . ($patientId ? '?pid='.$patientId : ''));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM health_vitals WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('info','Record deleted.');
        redirect('vitals.php');
    }
}

// ── Data ──────────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterPid = (int)($_GET['pid'] ?? 0);
$fFrom     = $_GET['from'] ?? '';
$fTo       = $_GET['to']   ?? '';

$where  = ['v.org_id=?'];
$params = [$orgId];
if ($filterPid) { $where[] = 'v.patient_id=?'; $params[] = $filterPid; }
if ($fFrom)     { $where[] = 'v.recorded_at>=?'; $params[] = $fFrom.' 00:00:00'; }
if ($fTo)       { $where[] = 'v.recorded_at<=?'; $params[] = $fTo.' 23:59:59'; }

$vitals = [];
try {
    $st = $pdo->prepare("
        SELECT v.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
               CONCAT(u.name) AS recorder_name
        FROM health_vitals v
        JOIN health_patients p ON v.patient_id=p.id
        LEFT JOIN users u ON v.recorded_by=u.id
        WHERE ".implode(' AND ',$where)."
        ORDER BY v.recorded_at DESC
        LIMIT 200
    ");
    $st->execute($params);
    $vitals = $st->fetchAll();
} catch (Exception $e) {}

$patients = [];
try {
    $st = $pdo->prepare("SELECT id,patient_no,first_name,last_name FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
    $st->execute([$orgId]);
    $patients = $st->fetchAll();
} catch (Exception $e) {}

$appointments = [];
try {
    $apptQ = $filterPid
        ? $pdo->prepare("SELECT id, CONCAT(date,' ',time) AS label FROM health_appointments WHERE org_id=? AND patient_id=? ORDER BY date DESC LIMIT 20")
        : $pdo->prepare("SELECT id, CONCAT(date,' ',time,' — P#',patient_id) AS label FROM health_appointments WHERE org_id=? ORDER BY date DESC LIMIT 50");
    $apptQ->execute($filterPid ? [$orgId,$filterPid] : [$orgId]);
    $appointments = $apptQ->fetchAll();
} catch (Exception $e) {}

$selectedPatient = null;
if ($filterPid) {
    try {
        $sp = $pdo->prepare("SELECT * FROM health_patients WHERE id=? AND org_id=?");
        $sp->execute([$filterPid,$orgId]);
        $selectedPatient = $sp->fetch();
    } catch (Exception $e) {}
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-heartbeat me-2" style="color:<?= $moduleColor ?>"></i>Vital Signs</h4>
    <p class="text-muted mb-0">Record and track patient vitals — BP, pulse, temperature, SpO₂, weight</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>"
          data-bs-toggle="modal" data-bs-target="#vitModal"
          onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Record Vitals
  </button>
</div>

<?= flashAlert() ?>

<!-- Filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Patient</label>
        <select name="pid" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Patients</option>
          <?php foreach ($patients as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $filterPid==(int)$p['id']?'selected':'' ?>>
            <?= e($p['first_name'].' '.$p['last_name']) ?> (<?= e($p['patient_no']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fFrom) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($fTo) ?>">
      </div>
      <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="vitals.php" class="btn btn-sm btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>
</div>

<?php if ($filterPid && $selectedPatient): ?>
<!-- Trend Charts -->
<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-heart me-2" style="color:<?= $moduleColor ?>"></i>Blood Pressure & Pulse Trend</div>
      <div class="card-body"><canvas id="bpChart" height="120"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-thermometer-half me-2" style="color:<?= $moduleColor ?>"></i>Temperature & SpO₂ Trend</div>
      <div class="card-body"><canvas id="tempChart" height="120"></canvas></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Vitals Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold">
      <i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>
      Vitals Records <?= $filterPid && $selectedPatient ? '— '.e($selectedPatient['first_name'].' '.$selectedPatient['last_name']) : '' ?>
    </h6>
    <span class="badge bg-secondary"><?= count($vitals) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Date / Time</th>
            <?php if (!$filterPid): ?><th>Patient</th><?php endif; ?>
            <th>BP (mmHg)</th>
            <th>Pulse</th>
            <th>Temp (°C)</th>
            <th>SpO₂ (%)</th>
            <th>Wt (kg)</th>
            <th>BMI</th>
            <th>Pain</th>
            <th>Recorded By</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($vitals)): ?>
          <tr><td colspan="11" class="text-center text-muted py-5">
            <i class="fas fa-heartbeat fa-2x mb-2 d-block opacity-25"></i>No vitals recorded yet.
          </td></tr>
          <?php else: foreach ($vitals as $v):
            $bpFlag = '';
            if ($v['bp_systolic'] && ($v['bp_systolic']>=140||$v['bp_diastolic']>=90)) $bpFlag='text-danger fw-bold';
            if ($v['bp_systolic'] && $v['bp_systolic']<90) $bpFlag='text-warning fw-bold';
          ?>
          <tr>
            <td class="small"><?= formatDateTime($v['recorded_at']) ?></td>
            <?php if (!$filterPid): ?>
            <td>
              <a href="?pid=<?= $v['patient_id'] ?>" class="fw-600 text-decoration-none">
                <?= e($v['patient_name']) ?>
              </a>
              <div class="text-muted" style="font-size:.7rem"><?= e($v['patient_no']) ?></div>
            </td>
            <?php endif; ?>
            <td class="<?= $bpFlag ?>">
              <?= $v['bp_systolic'] ? $v['bp_systolic'].'/'.$v['bp_diastolic'] : '—' ?>
            </td>
            <td><?= $v['pulse'] ? $v['pulse'].' bpm' : '—' ?></td>
            <td><?= $v['temperature'] ? $v['temperature'].'°' : '—' ?></td>
            <td class="<?= ($v['spo2']&&$v['spo2']<95)?'text-danger fw-bold':'' ?>">
              <?= $v['spo2'] ? $v['spo2'].'%' : '—' ?>
            </td>
            <td><?= $v['weight'] ?: '—' ?></td>
            <td><?= $v['bmi'] ?: '—' ?></td>
            <td>
              <?php if ($v['pain_scale'] !== null): ?>
              <span class="badge bg-<?= $v['pain_scale']>=7?'danger':($v['pain_scale']>=4?'warning':'success') ?>">
                <?= $v['pain_scale'] ?>/10
              </span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="small text-muted"><?= e($v['recorder_name'] ?: '—') ?></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" title="Edit" onclick="openEdit(<?= $v['id'] ?>)">
                  <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-outline-danger" title="Delete" onclick="delVital(<?= $v['id'] ?>)">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Delete form -->
<form id="delForm" method="POST" style="display:none">
  <?= csrfField() ?><input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delId">
</form>

<!-- Record / Edit Modal -->
<div class="modal fade" id="vitModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="vitId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="vitTitle"><i class="fas fa-heartbeat me-2"></i>Record Vital Signs</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
              <select name="patient_id" id="vitPatient" class="form-select" required>
                <option value="">— select patient —</option>
                <?php foreach ($patients as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $filterPid==$p['id']?'selected':'' ?>>
                  <?= e($p['first_name'].' '.$p['last_name']) ?> (<?= e($p['patient_no']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Linked Appointment</label>
              <select name="appointment_id" id="vitAppt" class="form-select">
                <option value="">None / Walk-in</option>
                <?php foreach ($appointments as $a): ?>
                <option value="<?= $a['id'] ?>"><?= e($a['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <h6 class="fw-bold mb-3 border-bottom pb-1" style="color:<?= $moduleColor ?>">
            <i class="fas fa-heartbeat me-2"></i>Cardiovascular
          </h6>
          <div class="row g-3 mb-4">
            <div class="col-md-3">
              <label class="form-label fw-semibold">Systolic BP <small class="text-muted">(mmHg)</small></label>
              <input type="number" name="bp_systolic" id="vitBpSys" class="form-control" min="50" max="300" placeholder="120">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Diastolic BP <small class="text-muted">(mmHg)</small></label>
              <input type="number" name="bp_diastolic" id="vitBpDia" class="form-control" min="30" max="200" placeholder="80">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Pulse <small class="text-muted">(bpm)</small></label>
              <input type="number" name="pulse" id="vitPulse" class="form-control" min="20" max="300" placeholder="72">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">SpO₂ <small class="text-muted">(%)</small></label>
              <input type="number" name="spo2" id="vitSpo2" class="form-control" min="50" max="100" placeholder="98">
            </div>
          </div>

          <h6 class="fw-bold mb-3 border-bottom pb-1" style="color:<?= $moduleColor ?>">
            <i class="fas fa-thermometer-half me-2"></i>Respiratory & Temperature
          </h6>
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Temperature <small class="text-muted">(°C)</small></label>
              <input type="number" name="temperature" id="vitTemp" class="form-control" min="30" max="45" step="0.1" placeholder="36.6">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Resp. Rate <small class="text-muted">(breaths/min)</small></label>
              <input type="number" name="resp_rate" id="vitResp" class="form-control" min="5" max="60" placeholder="16">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Pain Scale <small class="text-muted">(0–10)</small></label>
              <input type="number" name="pain_scale" id="vitPain" class="form-control" min="0" max="10" placeholder="0">
            </div>
          </div>

          <h6 class="fw-bold mb-3 border-bottom pb-1" style="color:<?= $moduleColor ?>">
            <i class="fas fa-weight me-2"></i>Anthropometry
          </h6>
          <div class="row g-3 mb-3">
            <div class="col-md-3">
              <label class="form-label fw-semibold">Weight <small class="text-muted">(kg)</small></label>
              <input type="number" name="weight" id="vitWeight" class="form-control" min="1" max="500" step="0.1" placeholder="70" oninput="calcBMI()">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Height <small class="text-muted">(cm)</small></label>
              <input type="number" name="height" id="vitHeight" class="form-control" min="30" max="250" step="0.1" placeholder="170" oninput="calcBMI()">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">BMI <small class="text-muted">(auto)</small></label>
              <input type="text" id="vitBmiDisplay" class="form-control bg-light" readonly placeholder="—">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Date &amp; Time</label>
              <input type="datetime-local" name="recorded_at" id="vitAt" class="form-control">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Clinical Notes</label>
            <textarea name="notes" id="vitNotes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>">
            <i class="fas fa-save me-1"></i>Save Vitals
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$filterPidJs = $filterPid ?: 0;
$extraJs = <<<JS
<script>
function openAdd() {
  document.getElementById('vitTitle').innerHTML = '<i class="fas fa-heartbeat me-2"></i>Record Vital Signs';
  document.getElementById('vitId').value = '0';
  ['vitBpSys','vitBpDia','vitPulse','vitSpo2','vitTemp','vitResp','vitPain','vitWeight','vitHeight','vitNotes'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  document.getElementById('vitBmiDisplay').value = '';
  document.getElementById('vitAt').value = new Date().toISOString().slice(0,16);
}
function openEdit(id) {
  fetch('vitals.php?fetch=' + id)
    .then(r => r.json())
    .then(d => {
      document.getElementById('vitTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Vital Signs';
      document.getElementById('vitId').value = d.id;
      document.getElementById('vitPatient').value = d.patient_id || '';
      document.getElementById('vitAppt').value = d.appointment_id || '';
      document.getElementById('vitBpSys').value   = d.bp_systolic   || '';
      document.getElementById('vitBpDia').value   = d.bp_diastolic  || '';
      document.getElementById('vitPulse').value   = d.pulse         || '';
      document.getElementById('vitSpo2').value    = d.spo2          || '';
      document.getElementById('vitTemp').value    = d.temperature   || '';
      document.getElementById('vitResp').value    = d.resp_rate     || '';
      document.getElementById('vitPain').value    = d.pain_scale    !== null ? d.pain_scale : '';
      document.getElementById('vitWeight').value  = d.weight        || '';
      document.getElementById('vitHeight').value  = d.height        || '';
      document.getElementById('vitNotes').value   = d.notes         || '';
      document.getElementById('vitBmiDisplay').value = d.bmi ? d.bmi + ' kg/m²' : '';
      document.getElementById('vitAt').value = d.recorded_at ? d.recorded_at.replace(' ','T').slice(0,16) : '';
      new bootstrap.Modal(document.getElementById('vitModal')).show();
    });
}
function delVital(id) {
  Swal.fire({ title:'Delete this record?', icon:'warning', showCancelButton:true,
    confirmButtonColor:'#e74c3c', confirmButtonText:'Delete' })
    .then(r => { if (r.isConfirmed) { document.getElementById('delId').value=id; document.getElementById('delForm').submit(); }});
}
function calcBMI() {
  const w = parseFloat(document.getElementById('vitWeight').value);
  const h = parseFloat(document.getElementById('vitHeight').value);
  const el = document.getElementById('vitBmiDisplay');
  if (w && h && h > 0) {
    const bmi = (w / ((h/100)**2)).toFixed(1);
    let cat = bmi < 18.5 ? 'Underweight' : bmi < 25 ? 'Normal' : bmi < 30 ? 'Overweight' : 'Obese';
    el.value = bmi + ' kg/m² (' + cat + ')';
  } else { el.value = ''; }
}

// Trend charts
const filterPid = {$filterPidJs};
if (filterPid) {
  fetch('vitals.php?chart_data=' + filterPid)
    .then(r => r.json())
    .then(rows => {
      if (!rows.length) return;
      const labels = rows.map(r => r.recorded_at.slice(0,10));
      // BP Chart
      new Chart(document.getElementById('bpChart'), {
        type: 'line',
        data: {
          labels,
          datasets: [
            { label:'Systolic',  data: rows.map(r=>r.bp_systolic),  borderColor:'#e74c3c', backgroundColor:'rgba(231,76,60,0.1)', tension:.3, fill:false },
            { label:'Diastolic', data: rows.map(r=>r.bp_diastolic), borderColor:'#3498db', backgroundColor:'rgba(52,152,219,0.1)', tension:.3, fill:false },
            { label:'Pulse',     data: rows.map(r=>r.pulse),        borderColor:'#2ecc71', backgroundColor:'rgba(46,204,113,0.1)', tension:.3, fill:false },
          ]
        },
        options: { responsive:true, plugins:{ legend:{ position:'bottom' } }, scales:{ y:{ beginAtZero:false } } }
      });
      // Temp/SpO2 Chart
      new Chart(document.getElementById('tempChart'), {
        type: 'line',
        data: {
          labels,
          datasets: [
            { label:'Temp (°C)', data: rows.map(r=>r.temperature), borderColor:'#f39c12', tension:.3, fill:false, yAxisID:'y' },
            { label:'SpO₂ (%)',  data: rows.map(r=>r.spo2),        borderColor:'#9b59b6', tension:.3, fill:false, yAxisID:'y1' },
          ]
        },
        options: {
          responsive:true, plugins:{ legend:{ position:'bottom' } },
          scales: {
            y:  { type:'linear', position:'left',  beginAtZero:false, title:{ display:true, text:'Temp °C' } },
            y1: { type:'linear', position:'right', beginAtZero:false, grid:{ drawOnChartArea:false }, title:{ display:true, text:'SpO₂ %' } }
          }
        }
      });
    });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
