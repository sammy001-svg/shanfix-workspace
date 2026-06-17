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
        $date = $_POST['date'] ?? date('Y-m-d');
        $time = $_POST['time'] ?? '09:00:00';
        $type = sanitize($_POST['type'] ?? 'General Consultation');
        $complaint = sanitize($_POST['complaint'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['scheduled', 'completed', 'cancelled', 'no_show']) ? $_POST['status'] : 'scheduled';
        $notes = sanitize($_POST['notes'] ?? '');

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE health_appointments SET patient_id = ?, doctor_id = ?, date = ?, time = ?, type = ?, complaint = ?, status = ?, notes = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$patientId, $doctorId, $date, $time, $type, $complaint, $status, $notes, $id, $orgId]);
            setFlash('success', 'Appointment rescheduled successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO health_appointments (org_id, patient_id, doctor_id, date, time, type, complaint, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $patientId, $doctorId, $date, $time, $type, $complaint, $status, $notes]);
            setFlash('success', 'Appointment booked successfully.');
            // SMS confirmation to patient
            try {
                $ptRow = $pdo->prepare("SELECT phone, CONCAT(first_name,' ',last_name) AS name FROM health_patients WHERE id=? AND org_id=?");
                $ptRow->execute([$patientId, $orgId]);
                $pt = $ptRow->fetch();
                if ($pt && !empty($pt['phone'])) {
                    $apptStr = date('D d M', strtotime($date)) . ' at ' . date('g:ia', strtotime($time));
                    notifySms($pt['phone'], APP_NAME . ": Hi {$pt['name']}, your {$type} appointment is confirmed for $apptStr. Please arrive 10 mins early.", $orgId, 'appointment_booked');
                }
            } catch (Throwable $e) {}
        }
        logActivity($id > 0 ? 'update' : 'create', 'health', "Appointment booked (Patient: $patientId, Doc: $doctorId, Date: $date)");
        redirect('appointments.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM health_appointments WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Appointment booking cancelled and removed.');
        redirect('appointments.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

try { $pdo->exec("CREATE TABLE IF NOT EXISTS health_appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL, patient_id INT NOT NULL, doctor_id INT,
    date DATE NOT NULL, time TIME,
    type VARCHAR(100), complaint TEXT,
    status ENUM('scheduled','completed','cancelled','no_show') DEFAULT 'scheduled',
    notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org(org_id), INDEX idx_date(date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

$appointmentsList = [];
try {
    $stmt = $pdo->prepare("SELECT a.*, 
                                  CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.patient_no,
                                  CONCAT(d.first_name, ' ', d.last_name) AS doctor_name, d.specialization
                           FROM health_appointments a
                           JOIN health_patients p ON a.patient_id = p.id
                           LEFT JOIN health_doctors d ON a.doctor_id = d.id
                           WHERE a.org_id = ?
                           ORDER BY a.date DESC, a.time DESC");
    $stmt->execute([$orgId]);
    $appointmentsList = $stmt->fetchAll();
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

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $aid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM health_appointments WHERE id = ? AND org_id = ?");
        $stmt->execute([$aid, $orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            header('Content-Type: application/json');
            echo json_encode($row);
            exit;
        }
    } catch (Exception $e) {}
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-check me-2" style="color:<?= $moduleColor ?>"></i>Appointments Schedules</h4>
    <p class="text-muted mb-0">Book patient consultations, map duty physicians, and trace clinical visit histories</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#apptModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Book Appointment</button>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-calendar-alt me-2 text-danger"></i>Consultations Roster</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Patient Details</th>
            <th>Date & Time</th>
            <th>Specialization / Category</th>
            <th>Physician Assigned</th>
            <th>Primary Complaint</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($appointmentsList)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-calendar-times fa-2x mb-2 d-block"></i>No appointments configured.</td></tr>
          <?php else: foreach ($appointmentsList as $a): 
            $statuses = ['scheduled' => 'info', 'completed' => 'success', 'cancelled' => 'danger', 'no_show' => 'secondary'];
            $bg = $statuses[$a['status']] ?? 'info';
          ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><?= e($a['patient_name']) ?></div>
              <small class="text-muted">ID No: <?= e($a['patient_no']) ?></small>
            </td>
            <td>
              <div class="fw-semibold text-dark"><i class="far fa-calendar-alt me-1 text-muted"></i><?= formatDate($a['date']) ?></div>
              <small class="text-muted"><i class="far fa-clock me-1 text-muted"></i><?= date('h:i A', strtotime($a['time'])) ?></small>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($a['type']) ?></span></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($a['doctor_name'] ?: 'Duty Physician') ?></div>
              <small class="text-muted"><?= e($a['specialization'] ?: 'General Practitioner') ?></small>
            </td>
            <td><span class="text-dark small fw-semibold"><?= e($a['complaint'] ?: 'Routine Assessment') ?></span></td>
            <td><span class="badge bg-<?= $bg ?>"><?= ucfirst($a['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $a['id'] ?>)" title="Reschedule"><i class="fas fa-calendar-day"></i></button>
                <button class="btn btn-outline-danger" onclick="delAppt(<?= $a['id'] ?>)" title="Cancel"><i class="fas fa-times-circle"></i></button>
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
<div class="modal fade" id="apptModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="apptId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="apptTitle"><i class="fas fa-calendar-plus me-2"></i>Book Patient Appointment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Select Patient <span class="text-danger">*</span></label>
        <select name="patient_id" id="apptPatientId" class="form-select select2-enable" required style="width:100%;">
          <option value="">-- select patient --</option>
          <?php foreach ($patientsList as $pt): ?>
          <option value="<?= $pt['id'] ?>"><?= e($pt['first_name'] . ' ' . $pt['last_name']) ?> (<?= e($pt['patient_no']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Assigned Medical Practitioner <span class="text-danger">*</span></label>
        <select name="doctor_id" id="apptDoctorId" class="form-select" required>
          <option value="">-- select doctor / physician --</option>
          <?php foreach ($doctorsList as $doc): ?>
          <option value="<?= $doc['id'] ?>"><?= e($doc['first_name'] . ' ' . $doc['last_name']) ?> (<?= e($doc['specialization']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Appointment Date <span class="text-danger">*</span></label>
        <input type="date" name="date" id="apptDate" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Session Start Time <span class="text-danger">*</span></label>
        <input type="time" name="time" id="apptTime" class="form-control" required value="09:00">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Consultation Classification / Type</label>
        <input type="text" name="type" id="apptType" class="form-control" placeholder="e.g. Dental Care, Routine Checkup, Cardiac Follow-up" value="General Consultation">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Primary Medical Complaint</label>
        <textarea name="complaint" id="apptComplaint" class="form-control" rows="2" placeholder="Describe symptoms or reasons for the clinic visit…"></textarea>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Session Status</label>
        <select name="status" id="apptStatus" class="form-select">
          <option value="scheduled">Scheduled</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
          <option value="no_show">No Show</option>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Administrative Notes</label>
        <textarea name="notes" id="apptNotes" class="form-control" rows="2" placeholder="Optional notes…"></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Appointment</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delApptForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delApptId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('apptTitle').innerHTML = '<i class="fas fa-calendar-plus me-2"></i>Book Patient Appointment';
  document.getElementById('apptId').value = '0';
  document.getElementById('apptPatientId').value = '';
  document.getElementById('apptDoctorId').value = '';
  document.getElementById('apptType').value = 'General Consultation';
  document.getElementById('apptComplaint').value = '';
  document.getElementById('apptStatus').value = 'scheduled';
  document.getElementById('apptNotes').value = '';
  
  document.getElementById('apptDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('apptTime').value = '09:00';
}
function openEdit(id) {
  fetch('appointments.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('apptTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Reschedule Consultation';
      document.getElementById('apptId').value = data.id;
      document.getElementById('apptPatientId').value = data.patient_id;
      document.getElementById('apptDoctorId').value = data.doctor_id || '';
      document.getElementById('apptDate').value = data.date;
      document.getElementById('apptTime').value = data.time.substring(0, 5);
      document.getElementById('apptType').value = data.type;
      document.getElementById('apptComplaint').value = data.complaint || '';
      document.getElementById('apptStatus').value = data.status;
      document.getElementById('apptNotes').value = data.notes || '';
      
      new bootstrap.Modal(document.getElementById('apptModal')).show();
    });
}
function delAppt(id) {
  Swal.fire({
    title: 'Cancel Appointment?',
    text: 'Remove this consultation schedule from the patient clinical record?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, cancel booking'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delApptId').value = id;
      document.getElementById('delApptForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
