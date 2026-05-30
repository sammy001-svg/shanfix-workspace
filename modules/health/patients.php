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
        $patNo = sanitize($_POST['patient_no'] ?? '');
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $gender = in_array($_POST['gender'] ?? '', ['male', 'female', 'other']) ? $_POST['gender'] : 'male';
        $dob = $_POST['dob'] ?? date('Y-m-d', strtotime('-30 years'));
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $bloodGroup = sanitize($_POST['blood_group'] ?? '');
        $allergies = sanitize($_POST['allergies'] ?? '');
        $chronicConditions = sanitize($_POST['chronic_conditions'] ?? '');
        $emergencyContact = sanitize($_POST['emergency_contact'] ?? '');
        $emergencyPhone = sanitize($_POST['emergency_phone'] ?? '');
        $insuranceProvider = sanitize($_POST['insurance_provider'] ?? '');
        $insuranceNo = sanitize($_POST['insurance_no'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE health_patients SET patient_no = ?, first_name = ?, last_name = ?, gender = ?, dob = ?, phone = ?, email = ?, address = ?, blood_group = ?, allergies = ?, chronic_conditions = ?, emergency_contact = ?, emergency_phone = ?, insurance_provider = ?, insurance_no = ?, status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$patNo, $firstName, $lastName, $gender, $dob, $phone, $email, $address, $bloodGroup, $allergies, $chronicConditions, $emergencyContact, $emergencyPhone, $insuranceProvider, $insuranceNo, $status, $id, $orgId]);
            setFlash('success', 'Patient details updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO health_patients (org_id, patient_no, first_name, last_name, gender, dob, phone, email, address, blood_group, allergies, chronic_conditions, emergency_contact, emergency_phone, insurance_provider, insurance_no, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $patNo, $firstName, $lastName, $gender, $dob, $phone, $email, $address, $bloodGroup, $allergies, $chronicConditions, $emergencyContact, $emergencyPhone, $insuranceProvider, $insuranceNo, $status]);
            setFlash('success', "Patient '$firstName $lastName' registered successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'health', "Patient: $firstName $lastName (No: $patNo)");
        redirect('patients.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM health_patients WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Patient record deleted.');
        redirect('patients.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fBlood = $_GET['blood_group'] ?? '';
$fStatus = $_GET['status'] ?? '';
$fQ = trim($_GET['q'] ?? '');

$where = 'org_id = ?';
$params = [$orgId];

if ($fBlood !== '') {
    $where .= ' AND blood_group = ?';
    $params[] = $fBlood;
}
if ($fStatus !== '') {
    $where .= ' AND status = ?';
    $params[] = $fStatus;
}
if ($fQ !== '') {
    $where .= ' AND (patient_no LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?)';
    $like = "%$fQ%";
    array_push($params, $like, $like, $like, $like);
}

$patientsList = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM health_patients WHERE $where ORDER BY patient_no ASC");
    $stmt->execute($params);
    $patientsList = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-procedures me-2" style="color:<?= $moduleColor ?>"></i>Patient Registry</h4>
    <p class="text-muted mb-0">Search, enroll, and manage EHR records and emergency medical profiles</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#patModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Register Patient</button>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1">Search Patients</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Patient number, name, or phone number…" value="<?= e($fQ) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Blood Group</label>
        <select name="blood_group" class="form-select form-select-sm">
          <option value="">All Groups</option>
          <option value="A+" <?= $fBlood === 'A+' ? 'selected' : '' ?>>A+</option>
          <option value="A-" <?= $fBlood === 'A-' ? 'selected' : '' ?>>A-</option>
          <option value="B+" <?= $fBlood === 'B+' ? 'selected' : '' ?>>B+</option>
          <option value="B-" <?= $fBlood === 'B-' ? 'selected' : '' ?>>B-</option>
          <option value="AB+" <?= $fBlood === 'AB+' ? 'selected' : '' ?>>AB+</option>
          <option value="AB-" <?= $fBlood === 'AB-' ? 'selected' : '' ?>>AB-</option>
          <option value="O+" <?= $fBlood === 'O+' ? 'selected' : '' ?>>O+</option>
          <option value="O-" <?= $fBlood === 'O-' ? 'selected' : '' ?>>O-</option>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="patients.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-procedures me-2 text-danger"></i>Patients Directory</h6>
    <span class="badge bg-secondary"><?= count($patientsList) ?> active profiles</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Patient No</th>
            <th>Patient Name</th>
            <th>Contacts</th>
            <th>Emergency Profile</th>
            <th>Insurance Provider</th>
            <th>Blood Group</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($patientsList)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-procedures fa-2x mb-2 d-block"></i>No patient profiles found.</td></tr>
          <?php else: foreach ($patientsList as $p): ?>
          <tr>
            <td class="fw-semibold text-dark"><?= e($p['patient_no'] ?: '—') ?></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></div>
              <small class="text-muted">DOB: <?= formatDate($p['dob']) ?> (<?= ucfirst($p['gender']) ?>)</small>
            </td>
            <td>
              <div><i class="fas fa-phone text-muted me-1 small"></i><?= e($p['phone'] ?: '—') ?></div>
              <small class="text-muted"><i class="fas fa-envelope me-1 small"></i><?= e($p['email'] ?: '—') ?></small>
            </td>
            <td>
              <div class="fw-semibold"><?= e($p['emergency_contact'] ?: '—') ?></div>
              <small class="text-muted"><i class="fas fa-ambulance me-1 small"></i><?= e($p['emergency_phone'] ?: '—') ?></small>
            </td>
            <td>
              <div class="fw-semibold"><?= e($p['insurance_provider'] ?: 'Self Pay') ?></div>
              <small class="text-muted">No: <?= e($p['insurance_no'] ?: 'N/A') ?></small>
            </td>
            <td class="text-center"><span class="badge bg-danger p-2 rounded-circle" style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;"><?= e($p['blood_group'] ?: '—') ?></span></td>
            <td><span class="badge bg-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($p['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $p['id'] ?>)" title="Edit Profile"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delPatient(<?= $p['id'] ?>, '<?= e($p['first_name'] . ' ' . $p['last_name']) ?>')" title="Remove"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="patModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="patId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="patTitle"><i class="fas fa-procedures me-2"></i>Register Patient</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <h6 class="fw-bold mb-3 border-bottom pb-1 text-danger"><i class="fas fa-procedures me-2"></i>Demographics</h6>
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label class="form-label fw-semibold">Patient ID No <span class="text-danger">*</span></label>
        <input type="text" name="patient_no" id="patNo" class="form-control" required placeholder="e.g. PAT-2026-003">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
        <input type="text" name="first_name" id="patFirst" class="form-control" required placeholder="e.g. John">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
        <input type="text" name="last_name" id="patLast" class="form-control" required placeholder="e.g. Smith">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
        <select name="gender" id="patGender" class="form-select" required>
          <option value="male">Male</option>
          <option value="female">Female</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label>
        <input type="date" name="dob" id="patDob" class="form-control" required value="<?= date('Y-m-d', strtotime('-30 years')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Blood Group</label>
        <select name="blood_group" id="patBlood" class="form-select">
          <option value="">Unknown</option>
          <option value="A+">A+</option>
          <option value="A-">A-</option>
          <option value="B+">B+</option>
          <option value="B-">B-</option>
          <option value="AB+">AB+</option>
          <option value="AB-">AB-</option>
          <option value="O+">O+</option>
          <option value="O-">O-</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Status</label>
        <select name="status" id="patStatus" class="form-select">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label>
        <input type="tel" name="phone" id="patPhone" class="form-control" required placeholder="e.g. +254 711 000 000">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Email</label>
        <input type="email" name="email" id="patEmail" class="form-control" placeholder="e.g. patient@example.com">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Residential Address</label>
        <textarea name="address" id="patAddress" class="form-control" rows="2" placeholder="Street name, estate name, house number…"></textarea>
      </div>
    </div>

    <h6 class="fw-bold mb-3 border-bottom pb-1 text-danger"><i class="fas fa-file-prescription me-2"></i>Medical Alerts</h6>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Known Allergies</label>
        <input type="text" name="allergies" id="patAllergies" class="form-control" placeholder="e.g. Penicillin, Peanuts, Sulfa drugs…">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Chronic Conditions</label>
        <input type="text" name="chronic_conditions" id="patConditions" class="form-control" placeholder="e.g. Hypertension, Diabetes, Asthma…">
      </div>
    </div>

    <h6 class="fw-bold mb-3 border-bottom pb-1 text-danger"><i class="fas fa-file-invoice-dollar me-2"></i>Insurance & Emergency Info</h6>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Emergency Contact Full Name <span class="text-danger">*</span></label>
        <input type="text" name="emergency_contact" id="patEmerName" class="form-control" required placeholder="e.g. Spouse / Parent name…">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Emergency Contact Phone <span class="text-danger">*</span></label>
        <input type="tel" name="emergency_phone" id="patEmerPhone" class="form-control" required placeholder="e.g. +254 722 000 000">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Insurance Provider / Carrier</label>
        <input type="text" name="insurance_provider" id="patInsProvider" class="form-control" placeholder="e.g. NHIF, Jubilee Insurance…">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Insurance Policy Number</label>
        <input type="text" name="insurance_no" id="patInsNo" class="form-control" placeholder="e.g. POL-8827-04">
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Enroll Patient</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delPatForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delPatId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('patTitle').innerHTML = '<i class="fas fa-procedures me-2"></i>Register Patient';
  document.getElementById('patId').value = '0';
  document.getElementById('patNo').value = '';
  document.getElementById('patFirst').value = '';
  document.getElementById('patLast').value = '';
  document.getElementById('patGender').value = 'male';
  document.getElementById('patStatus').value = 'active';
  document.getElementById('patBlood').value = '';
  document.getElementById('patPhone').value = '';
  document.getElementById('patEmail').value = '';
  document.getElementById('patAddress').value = '';
  document.getElementById('patAllergies').value = '';
  document.getElementById('patConditions').value = '';
  document.getElementById('patEmerName').value = '';
  document.getElementById('patEmerPhone').value = '';
  document.getElementById('patInsProvider').value = '';
  document.getElementById('patInsNo').value = '';
  
  document.getElementById('patDob').value = new Date(new Date().setFullYear(new Date().getFullYear() - 30)).toISOString().split('T')[0];
}
function openEdit(id) {
  fetch('patients.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('patTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Patient Demographics';
      document.getElementById('patId').value = data.id;
      document.getElementById('patNo').value = data.patient_no || '';
      document.getElementById('patFirst').value = data.first_name;
      document.getElementById('patLast').value = data.last_name;
      document.getElementById('patGender').value = data.gender;
      document.getElementById('patStatus').value = data.status;
      document.getElementById('patDob').value = data.dob;
      document.getElementById('patBlood').value = data.blood_group || '';
      document.getElementById('patPhone').value = data.phone || '';
      document.getElementById('patEmail').value = data.email || '';
      document.getElementById('patAddress').value = data.address || '';
      document.getElementById('patAllergies').value = data.allergies || '';
      document.getElementById('patConditions').value = data.chronic_conditions || '';
      document.getElementById('patEmerName').value = data.emergency_contact || '';
      document.getElementById('patEmerPhone').value = data.emergency_phone || '';
      document.getElementById('patInsProvider').value = data.insurance_provider || '';
      document.getElementById('patInsNo').value = data.insurance_no || '';
      
      new bootstrap.Modal(document.getElementById('patModal')).show();
    });
}
function delPatient(id, name) {
  Swal.fire({
    title: 'Delete Patient Profile?',
    text: 'Remove "' + name + '" and delete all scheduled consultations and medical chart records?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delPatId').value = id;
      document.getElementById('delPatForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
