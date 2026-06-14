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

// ── AJAX: fetch record for edit/print ────────────────────────────
if (isset($_GET['fetch_details'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $rid   = (int)$_GET['fetch_details'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_records WHERE id=? AND org_id=?");
        $st->execute([$rid, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $patientId = (int)$_POST['patient_id'];
        $doctorId = (int)($_POST['doctor_id'] ?? 0) ?: null;
        $appointmentId = (int)($_POST['appointment_id'] ?? 0) ?: null;
        $date = $_POST['date'] ?? date('Y-m-d');
        $diagnosis = sanitize($_POST['diagnosis'] ?? '');
        $treatment = sanitize($_POST['treatment'] ?? '');
        $prescription = sanitize($_POST['prescription'] ?? '');
        $notes = sanitize($_POST['notes'] ?? '');
        $followUp = $_POST['follow_up_date'] ?? null;
        if ($followUp === '') $followUp = null;

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE health_records SET patient_id = ?, doctor_id = ?, appointment_id = ?, date = ?, diagnosis = ?, treatment = ?, prescription = ?, notes = ?, follow_up_date = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$patientId, $doctorId, $appointmentId, $date, $diagnosis, $treatment, $prescription, $notes, $followUp, $id, $orgId]);
            setFlash('success', 'Medical record entry updated.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO health_records (org_id, patient_id, doctor_id, appointment_id, date, diagnosis, treatment, prescription, notes, follow_up_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $patientId, $doctorId, $appointmentId, $date, $diagnosis, $treatment, $prescription, $notes, $followUp]);
            
            // Auto complete appointment status if matched
            if ($appointmentId > 0) {
                $pdo->prepare("UPDATE health_appointments SET status = 'completed' WHERE id = ? AND org_id = ?")->execute([$appointmentId, $orgId]);
            }
            setFlash('success', 'EHR medical entry recorded successfully.');
        }
        logActivity($id > 0 ? 'update' : 'create', 'health', "Medical record registered (Patient: $patientId, Diagnosis: $diagnosis)");
        redirect('records.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM health_records WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Medical record entry removed.');
        redirect('records.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fPatient = $_GET['patient_id'] ?? '';
$where = 'r.org_id = ?';
$params = [$orgId];

if ($fPatient !== '') {
    $where .= ' AND r.patient_id = ?';
    $params[] = $fPatient;
}

$recordsList = [];
try {
    $stmt = $pdo->prepare("SELECT r.*, 
                                  CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.patient_no, p.blood_group,
                                  CONCAT(d.first_name, ' ', d.last_name) AS doctor_name, d.specialization
                           FROM health_records r
                           JOIN health_patients p ON r.patient_id = p.id
                           LEFT JOIN health_doctors d ON r.doctor_id = d.id
                           WHERE $where
                           ORDER BY r.date DESC, r.id DESC");
    $stmt->execute($params);
    $recordsList = $stmt->fetchAll();
} catch (Exception $e) {}

$patientsList = [];
try {
    $stmt = $pdo->prepare("SELECT id, patient_no, first_name, last_name FROM health_patients WHERE org_id = ? AND status = 'active' ORDER BY first_name ASC");
    $stmt->execute([$orgId]);
    $patientsList = $stmt->fetchAll();
} catch (Exception $e) {}

$doctorsList = [];
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, specialization FROM health_doctors WHERE org_id = ? AND status = 'active' ORDER BY first_name ASC");
    $stmt->execute([$orgId]);
    $doctorsList = $stmt->fetchAll();
} catch (Exception $e) {}

$appointmentsList = [];
try {
    $stmt = $pdo->prepare("SELECT a.id, a.date, a.type, CONCAT(p.first_name, ' ', p.last_name) AS patient_name
                           FROM health_appointments a
                           JOIN health_patients p ON a.patient_id = p.id
                           WHERE a.org_id = ? AND a.status = 'scheduled'
                           ORDER BY a.date DESC");
    $stmt->execute([$orgId]);
    $appointmentsList = $stmt->fetchAll();
} catch (Exception $e) {}

?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-medical me-2" style="color:<?= $moduleColor ?>"></i>Electronic Health Records (EHR)</h4>
    <p class="text-muted mb-0">Record physical diagnoses, clinical drug prescriptions, patient follow-up alerts</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#recordModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Clinical Entry</button>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-6 col-md-4">
        <label class="form-label small fw-semibold mb-1">Filter by Patient</label>
        <select name="patient_id" class="form-select form-select-sm select2-enable">
          <option value="">All Patients</option>
          <?php foreach ($patientsList as $pt): ?>
          <option value="<?= $pt['id'] ?>" <?= $fPatient == $pt['id'] ? 'selected' : '' ?>><?= e($pt['first_name'] . ' ' . $pt['last_name']) ?> (<?= e($pt['patient_no']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="records.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-file-medical-alt me-2 text-danger"></i>Clinical Charts Ledger</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Patient Details</th>
            <th>Visit Date</th>
            <th>Diagnosed Pathology</th>
            <th>Treatment Conducted</th>
            <th>Active Prescriptions</th>
            <th>Duty Practitioner</th>
            <th class="text-center">Print / Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recordsList)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-file-prescription fa-2x mb-2 d-block"></i>No clinical health charts recorded yet.</td></tr>
          <?php else: foreach ($recordsList as $r): ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><?= e($r['patient_name']) ?></div>
              <small class="text-muted">No: <?= e($r['patient_no']) ?> | Blood: <span class="text-danger fw-bold"><?= e($r['blood_group'] ?: '—') ?></span></small>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= formatDate($r['date']) ?></div>
              <?php if ($r['follow_up_date']): ?>
              <small class="text-danger fw-semibold"><i class="fas fa-calendar-alt me-1"></i>Follow-up: <?= formatDate($r['follow_up_date']) ?></small>
              <?php endif; ?>
            </td>
            <td class="fw-bold text-dark text-wrap" style="max-width:200px;"><?= e($r['diagnosis']) ?></td>
            <td class="text-wrap" style="max-width:220px;"><?= e($r['treatment'] ?: '—') ?></td>
            <td>
              <?php if ($r['prescription']): ?>
              <span class="badge bg-light text-dark border text-wrap py-2 px-2 text-start font-monospace small"><i class="fas fa-pills text-danger me-1"></i><?= e($r['prescription']) ?></span>
              <?php else: ?>
              <span class="text-muted small">No medications issued</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold text-dark">Dr. <?= e($r['doctor_name'] ?: 'Duty Physician') ?></div>
              <small class="text-muted"><?= e($r['specialization'] ?: 'General Practice') ?></small>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <a href="<?= APP_URL ?>/modules/health/medical-certificate-pdf.php?patient_id=<?= $r['patient_id'] ?>" target="_blank" class="btn btn-outline-danger" title="Medical Certificate"><i class="fas fa-file-medical"></i></a>
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $r['id'] ?>)" title="Edit Details"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delRecord(<?= $r['id'] ?>)" title="Remove Entry"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="recordModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="recId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="recTitle"><i class="fas fa-file-medical me-2"></i>Add Clinical Entry</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Patient Name <span class="text-danger">*</span></label>
        <select name="patient_id" id="recPatientId" class="form-select select2-enable" required style="width:100%;">
          <option value="">-- select patient --</option>
          <?php foreach ($patientsList as $pt): ?>
          <option value="<?= $pt['id'] ?>"><?= e($pt['first_name'] . ' ' . $pt['last_name']) ?> (<?= e($pt['patient_no']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Attending Doctor / Specialist <span class="text-danger">*</span></label>
        <select name="doctor_id" id="recDoctorId" class="form-select" required>
          <option value="">-- select doctor --</option>
          <?php foreach ($doctorsList as $doc): ?>
          <option value="<?= $doc['id'] ?>">Dr. <?= e($doc['first_name'] . ' ' . $doc['last_name']) ?> (<?= e($doc['specialization']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Link to Scheduled Consultation (Optional)</label>
        <select name="appointment_id" id="recApptId" class="form-select">
          <option value="">-- Direct walk-in patient --</option>
          <?php foreach ($appointmentsList as $ap): ?>
          <option value="<?= $ap['id'] ?>"><?= formatDate($ap['date']) ?> - <?= e($ap['patient_name']) ?> (<?= e($ap['type']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Visit / Consultation Date <span class="text-danger">*</span></label>
        <input type="date" name="date" id="recDate" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Follow-up Date</label>
        <input type="date" name="follow_up_date" id="recFollowUp" class="form-control">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Diagnosed Pathology / Condition <span class="text-danger">*</span></label>
        <input type="text" name="diagnosis" id="recDiagnosis" class="form-control" required placeholder="e.g. Acute Bronchitis, Migraine, Grade II Hypertension">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Treatment / Clinical Procedures Done</label>
        <textarea name="treatment" id="recTreatment" class="form-control" rows="2" placeholder="Describe clinical treatment, stitches, injections, dressing…"></textarea>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Medication Prescriptions <small class="text-muted">(Dosages & Instructions)</small></label>
        <textarea name="prescription" id="recPrescription" class="form-control font-monospace" rows="3" placeholder="e.g. Amoxicillin 500mg — 1 tab every 8 hours for 7 days&#10;Paracetamol 500mg — 2 tabs as needed for pain…"></textarea>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Progress / Prognosis Notes</label>
        <textarea name="notes" id="recNotes" class="form-control" rows="2" placeholder="Observation notes, dietary suggestions, rest recommendation…"></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Record Chart</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delRecForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delRecId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('recTitle').innerHTML = '<i class="fas fa-file-medical me-2"></i>Add Clinical Entry';
  document.getElementById('recId').value = '0';
  document.getElementById('recPatientId').value = '';
  document.getElementById('recDoctorId').value = '';
  document.getElementById('recApptId').value = '';
  document.getElementById('recDiagnosis').value = '';
  document.getElementById('recTreatment').value = '';
  document.getElementById('recPrescription').value = '';
  document.getElementById('recNotes').value = '';
  document.getElementById('recFollowUp').value = '';
  document.getElementById('recDate').value = new Date().toISOString().split('T')[0];
}
function openEdit(id) {
  fetch('records.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('recTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Clinical Record Details';
      document.getElementById('recId').value = data.id;
      document.getElementById('recPatientId').value = data.patient_id;
      document.getElementById('recDoctorId').value = data.doctor_id || '';
      document.getElementById('recApptId').value = data.appointment_id || '';
      document.getElementById('recDate').value = data.date;
      document.getElementById('recDiagnosis').value = data.diagnosis;
      document.getElementById('recTreatment').value = data.treatment || '';
      document.getElementById('recPrescription').value = data.prescription || '';
      document.getElementById('recNotes').value = data.notes || '';
      document.getElementById('recFollowUp').value = data.follow_up_date || '';
      
      new bootstrap.Modal(document.getElementById('recordModal')).show();
    });
}
function delRecord(id) {
  Swal.fire({
    title: 'Remove Medical Chart?',
    text: 'Permanently delete this clinical consultation chart from patient electronic history?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete record'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delRecId').value = id;
      document.getElementById('delRecForm').submit();
    }
  });
}
function printPrescription(id) {
  const w = window.open('', '_blank', 'width=800,height=600');
  fetch('records.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      w.document.write('<html><head><title>Prescription Slip</title>');
      w.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">');
      w.document.write('<style>body{padding:40px;font-family:sans-serif;} .rx-logo{font-size:42px;font-weight:bold;color:#e74c3c;}</style></head><body>');
      w.document.write('<div class="container border p-4 rounded bg-light">');
      w.document.write('<div class="d-flex justify-content-between align-items-center mb-4">');
      w.document.write('<div><h3 class="mb-0 text-uppercase fw-bold text-danger">OrbitDesk Medical Clinic</h3><p class="text-muted mb-0 small">Professional Healthcare Services</p></div>');
      w.document.write('<div class="rx-logo">℞</div>');
      w.document.write('</div>');
      w.document.write('<hr>');
      w.document.write('<div class="row mb-3"><div class="col-6"><strong>Patient ID:</strong> PAT-'+data.patient_id+'</div><div class="col-6 text-end"><strong>Prescription Date:</strong> '+data.date+'</div></div>');
      w.document.write('<div class="mb-4"><h5><strong>Pathological Diagnosis:</strong></h5><p class="bg-white p-2 border rounded fw-semibold text-dark">'+data.diagnosis+'</p></div>');
      w.document.write('<div class="mb-4"><h5><strong>Issued Prescription:</strong></h5><pre class="bg-white p-3 border rounded text-dark font-monospace" style="font-size:15px;white-space:pre-wrap;">'+(data.prescription || 'No medicines prescribed.')+'</pre></div>');
      w.document.write('<div class="mb-4"><h5><strong>attending Physician Instructions / Notes:</strong></h5><p class="bg-white p-2 border rounded small">'+(data.notes || 'No extra clinical instructions.')+'</p></div>');
      w.document.write('<div class="mt-5 pt-3 d-flex justify-content-between align-items-center small text-muted"><p>Printed officially via EHR portal.</p><p class="border-top pt-1 text-center" style="width:200px;">Authorized Signature / Seal</p></div>');
      w.document.write('</div>');
      w.document.write('<script>window.print();</script>');
      w.document.write('</body></html>');
      w.document.close();
    });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
