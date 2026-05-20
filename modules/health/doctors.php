<?php
$moduleSlug  = 'health';
$moduleName  = 'Health & Clinic';
$moduleIcon  = 'fas fa-heartbeat';
$moduleColor = '#e74c3c';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'patients.php',    'icon' => 'fas fa-procedures',     'label' => 'Patients'],
    ['url' => 'appointments.php','icon' => 'fas fa-calendar-check', 'label' => 'Appointments'],
    ['url' => 'doctors.php',     'icon' => 'fas fa-user-md',        'label' => 'Doctors'],
    ['url' => 'records.php',     'icon' => 'fas fa-file-medical',   'label' => 'Medical Records'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0) ?: null;
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $specialization = sanitize($_POST['specialization'] ?? 'General Practitioner');
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE health_doctors SET user_id = ?, first_name = ?, last_name = ?, specialization = ?, phone = ?, email = ?, status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$userId, $firstName, $lastName, $specialization, $phone, $email, $status, $id, $orgId]);
            setFlash('success', 'Doctor details updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO health_doctors (org_id, user_id, first_name, last_name, specialization, phone, email, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $userId, $firstName, $lastName, $specialization, $phone, $email, $status]);
            setFlash('success', "Doctor '$firstName $lastName' added to the registry.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'health', "Doctor added/updated: $firstName $lastName ($specialization)");
        redirect('doctors.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM health_doctors WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Practitioner removed from registry.');
        redirect('doctors.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$doctorsList = [];
try {
    $stmt = $pdo->prepare("SELECT d.*, u.name AS system_user_name 
                           FROM health_doctors d
                           LEFT JOIN users u ON d.user_id = u.id
                           WHERE d.org_id = ?
                           ORDER BY d.first_name ASC");
    $stmt->execute([$orgId]);
    $doctorsList = $stmt->fetchAll();
} catch (Exception $e) {}

$usersList = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE org_id = ? ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $usersList = $stmt->fetchAll();
} catch (Exception $e) {}

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $did = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM health_doctors WHERE id = ? AND org_id = ?");
        $stmt->execute([$did, $orgId]);
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
    <h4 class="mb-1"><i class="fas fa-user-md me-2" style="color:<?= $moduleColor ?>"></i>Medical Practitioners Registry</h4>
    <p class="text-muted mb-0">Manage clinical specialists, consultants, on-duty physicians and map system user profiles</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#docModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Practitioner</button>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-user-md me-2 text-danger"></i>Clinical Staff & Consultants</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Practitioner Name</th>
            <th>Clinical Specialty</th>
            <th>Contact Details</th>
            <th>System Account Integration</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($doctorsList)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5"><i class="fas fa-user-md fa-2x mb-2 d-block"></i>No medical practitioners registered yet.</td></tr>
          <?php else: foreach ($doctorsList as $d): ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark">Dr. <?= e($d['first_name'] . ' ' . $d['last_name']) ?></div>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($d['specialization']) ?></span></td>
            <td>
              <div><i class="fas fa-phone text-muted me-1 small"></i><?= e($d['phone'] ?: '—') ?></div>
              <small class="text-muted"><i class="fas fa-envelope me-1 small"></i><?= e($d['email'] ?: '—') ?></small>
            </td>
            <td>
              <?php if ($d['user_id']): ?>
              <span class="text-success small fw-semibold"><i class="fas fa-check-circle me-1"></i>Linked: <?= e($d['system_user_name']) ?></span>
              <?php else: ?>
              <span class="text-muted small"><i class="fas fa-minus me-1"></i>Direct Portal Access Disabled</span>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $d['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($d['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $d['id'] ?>)" title="Edit Details"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delDoc(<?= $d['id'] ?>, '<?= e($d['first_name'] . ' ' . $d['last_name']) ?>')" title="Remove"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="docModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="docId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="docTitle"><i class="fas fa-user-md me-2"></i>Add Practitioner</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-6">
        <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
        <input type="text" name="first_name" id="docFirst" class="form-control" required placeholder="e.g. Elizabeth">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
        <input type="text" name="last_name" id="docLast" class="form-control" required placeholder="e.g. Blackwell">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Clinical Specialty / Title <span class="text-danger">*</span></label>
        <input type="text" name="specialization" id="docSpecialty" class="form-control" required placeholder="e.g. Pediatrics, Cardiology, General Surgery">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Phone Contact <span class="text-danger">*</span></label>
        <input type="tel" name="phone" id="docPhone" class="form-control" required placeholder="e.g. +254 700 111 222">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Official Email <span class="text-danger">*</span></label>
        <input type="email" name="email" id="docEmail" class="form-control" required placeholder="e.g. dr.blackwell@clinic.com">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Link to System Account <small class="text-muted">(Optional)</small></label>
        <select name="user_id" id="docUserId" class="form-select">
          <option value="">-- No portal login required --</option>
          <?php foreach ($usersList as $u): ?>
          <option value="<?= $u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted mt-1 d-block">Integrates clinical operations with the practitioner's workspace login</small>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Practitioner Status</label>
        <select name="status" id="docStatus" class="form-select">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Practitioner</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delDocForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delDocId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('docTitle').innerHTML = '<i class="fas fa-user-md me-2"></i>Add Practitioner';
  document.getElementById('docId').value = '0';
  document.getElementById('docFirst').value = '';
  document.getElementById('docLast').value = '';
  document.getElementById('docSpecialty').value = 'General Practitioner';
  document.getElementById('docPhone').value = '';
  document.getElementById('docEmail').value = '';
  document.getElementById('docUserId').value = '';
  document.getElementById('docStatus').value = 'active';
}
function openEdit(id) {
  fetch('doctors.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('docTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Doctor Details';
      document.getElementById('docId').value = data.id;
      document.getElementById('docFirst').value = data.first_name;
      document.getElementById('docLast').value = data.last_name;
      document.getElementById('docSpecialty').value = data.specialization;
      document.getElementById('docPhone').value = data.phone;
      document.getElementById('docEmail').value = data.email;
      document.getElementById('docUserId').value = data.user_id || '';
      document.getElementById('docStatus').value = data.status;
      
      new bootstrap.Modal(document.getElementById('docModal')).show();
    });
}
function delDoc(id, name) {
  Swal.fire({
    title: 'Remove Practitioner?',
    text: 'Permanently remove Dr. ' + name + ' from the clinical practitioners list?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, remove doctor'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delDocId').value = id;
      document.getElementById('delDocForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
