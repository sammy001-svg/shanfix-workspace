<?php
$moduleSlug  = 'school';
$moduleName  = 'School Management';
$moduleIcon  = 'fas fa-school';
$moduleColor = '#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'parents.php','icon'=>'fas fa-users','label'=>'Parents'],['url'=>'staff.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Staff'],['url'=>'classes.php','icon'=>'fas fa-chalkboard','label'=>'Classes'],['url'=>'subjects.php','icon'=>'fas fa-book','label'=>'Subjects'],['url'=>'timetable.php','icon'=>'fas fa-calendar-alt','label'=>'Timetable'],['url'=>'attendance.php','icon'=>'fas fa-clipboard-check','label'=>'Attendance'],['url'=>'exams.php','icon'=>'fas fa-file-alt','label'=>'Exams'],['url'=>'results.php','icon'=>'fas fa-chart-line','label'=>'Results'],['url'=>'fees.php','icon'=>'fas fa-money-bill','label'=>'Fees'],['url'=>'library.php','icon'=>'fas fa-book-reader','label'=>'Library'],['url'=>'transport.php','icon'=>'fas fa-bus','label'=>'Transport'],['url'=>'events.php','icon'=>'fas fa-calendar-day','label'=>'Events'],['url'=>'notices.php','icon'=>'fas fa-bullhorn','label'=>'Notices'],['url'=>'grades.php','icon'=>'fas fa-star','label'=>'Grades'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

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
        $admNo = sanitize($_POST['admission_no'] ?? '');
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $gender = in_array($_POST['gender'] ?? '', ['male', 'female']) ? $_POST['gender'] : 'male';
        $dob = $_POST['dob'] ?? date('Y-m-d', strtotime('-10 years'));
        $classId = (int)($_POST['class_id'] ?? 0) ?: null;
        $parentName = sanitize($_POST['parent_name'] ?? '');
        $parentPhone = sanitize($_POST['parent_phone'] ?? '');
        $parentEmail = sanitize($_POST['parent_email'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'graduated', 'transferred']) ? $_POST['status'] : 'active';
        $admittedOn = $_POST['admitted_on'] ?? date('Y-m-d');

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE sch_students SET admission_no = ?, first_name = ?, last_name = ?, gender = ?, dob = ?, class_id = ?, parent_name = ?, parent_phone = ?, parent_email = ?, address = ?, status = ?, admitted_on = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$admNo, $firstName, $lastName, $gender, $dob, $classId, $parentName, $parentPhone, $parentEmail, $address, $status, $admittedOn, $id, $orgId]);
            setFlash('success', 'Student details updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO sch_students (org_id, admission_no, first_name, last_name, gender, dob, class_id, parent_name, parent_phone, parent_email, address, status, admitted_on) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $admNo, $firstName, $lastName, $gender, $dob, $classId, $parentName, $parentPhone, $parentEmail, $address, $status, $admittedOn]);
            setFlash('success', "Student '$firstName $lastName' enrolled successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'school', "Student: $firstName $lastName (Adm: $admNo)");
        redirect('students.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM sch_students WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Student record removed.');
        redirect('students.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fClass = $_GET['class_id'] ?? '';
$fStatus = $_GET['status'] ?? '';
$fQ = trim($_GET['q'] ?? '');

$where = 's.org_id = ?';
$params = [$orgId];

if ($fClass !== '') {
    $where .= ' AND s.class_id = ?';
    $params[] = $fClass;
}
if ($fStatus !== '') {
    $where .= ' AND s.status = ?';
    $params[] = $fStatus;
}
if ($fQ !== '') {
    $where .= ' AND (s.admission_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.parent_name LIKE ?)';
    $like = "%$fQ%";
    array_push($params, $like, $like, $like, $like);
}

$studentsList = [];
try {
    $stmt = $pdo->prepare("SELECT s.*, c.name AS class_name 
                           FROM sch_students s 
                           LEFT JOIN sch_classes c ON s.class_id = c.id 
                           WHERE $where 
                           ORDER BY s.admission_no ASC");
    $stmt->execute($params);
    $studentsList = $stmt->fetchAll();
} catch (Exception $e) {}

$classesList = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM sch_classes WHERE org_id = ? ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $classesList = $stmt->fetchAll();
} catch (Exception $e) {}

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $sid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM sch_students WHERE id = ? AND org_id = ?");
        $stmt->execute([$sid, $orgId]);
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
    <h4 class="mb-1"><i class="fas fa-user-graduate me-2" style="color:<?= $moduleColor ?>"></i>Student Directory</h4>
    <p class="text-muted mb-0">Enroll new students, assign classes, and manage parent/guardian profiles</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#stdModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Enroll Student</button>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1">Search Directory</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Admission number, student or parent name…" value="<?= e($fQ) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Academic Class</label>
        <select name="class_id" class="form-select form-select-sm">
          <option value="">All Classes</option>
          <?php foreach ($classesList as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $fClass == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          <option value="graduated" <?= $fStatus === 'graduated' ? 'selected' : '' ?>>Graduated</option>
          <option value="transferred" <?= $fStatus === 'transferred' ? 'selected' : '' ?>>Transferred</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="students.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-user-graduate me-2 text-success"></i>Students List</h6>
    <span class="badge bg-secondary"><?= count($studentsList) ?> registered</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Adm No</th>
            <th>Student Name</th>
            <th>Class</th>
            <th>Gender</th>
            <th>Parent / Guardian</th>
            <th>Phone</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($studentsList)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No students found in directory.</td></tr>
          <?php else: foreach ($studentsList as $s): 
            $badges = ['active' => 'success', 'inactive' => 'secondary', 'graduated' => 'primary', 'transferred' => 'warning'];
            $bg = $badges[$s['status']] ?? 'info';
          ?>
          <tr>
            <td class="fw-semibold text-dark"><?= e($s['admission_no'] ?: '—') ?></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></div>
              <small class="text-muted"><i class="fas fa-baby me-1"></i>DOB: <?= formatDate($s['dob']) ?></small>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($s['class_name'] ?: 'Unassigned') ?></span></td>
            <td><?= ucfirst($s['gender']) ?></td>
            <td class="fw-semibold"><?= e($s['parent_name'] ?: '—') ?></td>
            <td><?= e($s['parent_phone'] ?: '—') ?></td>
            <td><span class="badge bg-<?= $bg ?>"><?= ucfirst($s['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $s['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delStudent(<?= $s['id'] ?>, '<?= e($s['first_name'] . ' ' . $s['last_name']) ?>')" title="Remove"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="stdModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="stdId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="stdTitle"><i class="fas fa-user-graduate me-2"></i>Enroll Student</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <h6 class="fw-bold mb-3 border-bottom pb-1 text-success"><i class="fas fa-baby me-2"></i>Student Demographics</h6>
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label class="form-label fw-semibold">Admission No <span class="text-danger">*</span></label>
        <input type="text" name="admission_no" id="stdAdm" class="form-control" required placeholder="e.g. ADM-2026-004">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
        <input type="text" name="first_name" id="stdFirst" class="form-control" required placeholder="e.g. Jane">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
        <input type="text" name="last_name" id="stdLast" class="form-control" required placeholder="e.g. Doe">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
        <select name="gender" id="stdGender" class="form-select" required>
          <option value="male">Male</option>
          <option value="female">Female</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label>
        <input type="date" name="dob" id="stdDob" class="form-control" required value="<?= date('Y-m-d', strtotime('-10 years')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Academic Class</label>
        <select name="class_id" id="stdClass" class="form-select">
          <option value="">-- unassigned --</option>
          <?php foreach ($classesList as $c): ?>
          <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Enrollment Date</label>
        <input type="date" name="admitted_on" id="stdAdmitted" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Status</label>
        <select name="status" id="stdStatus" class="form-select">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="graduated">Graduated</option>
          <option value="transferred">Transferred</option>
        </select>
      </div>
    </div>
    
    <h6 class="fw-bold mb-3 border-bottom pb-1 text-success"><i class="fas fa-users-cog me-2"></i>Parent / Guardian Contacts</h6>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label fw-semibold">Parent Full Name <span class="text-danger">*</span></label>
        <input type="text" name="parent_name" id="stdParentName" class="form-control" required placeholder="e.g. Richard Doe">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Parent Phone <span class="text-danger">*</span></label>
        <input type="tel" name="parent_phone" id="stdParentPhone" class="form-control" required placeholder="e.g. +254 700 000 000">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Parent Email</label>
        <input type="email" name="parent_email" id="stdParentEmail" class="form-control" placeholder="e.g. parent@example.com">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Residential / Home Address</label>
        <textarea name="address" id="stdAddress" class="form-control" rows="2" placeholder="Street, City details…"></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Enroll Student</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delStdForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delStdId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('stdTitle').innerHTML = '<i class="fas fa-user-graduate me-2"></i>Enroll Student';
  document.getElementById('stdId').value = '0';
  document.getElementById('stdAdm').value = '';
  document.getElementById('stdFirst').value = '';
  document.getElementById('stdLast').value = '';
  document.getElementById('stdGender').value = 'male';
  document.getElementById('stdClass').value = '';
  document.getElementById('stdStatus').value = 'active';
  
  const now = new Date().toISOString().split('T')[0];
  document.getElementById('stdAdmitted').value = now;
  document.getElementById('stdDob').value = new Date(new Date().setFullYear(new Date().getFullYear() - 10)).toISOString().split('T')[0];
  
  document.getElementById('stdParentName').value = '';
  document.getElementById('stdParentPhone').value = '';
  document.getElementById('stdParentEmail').value = '';
  document.getElementById('stdAddress').value = '';
}
function openEdit(id) {
  fetch('students.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('stdTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Student Profile';
      document.getElementById('stdId').value = data.id;
      document.getElementById('stdAdm').value = data.admission_no || '';
      document.getElementById('stdFirst').value = data.first_name;
      document.getElementById('stdLast').value = data.last_name;
      document.getElementById('stdGender').value = data.gender;
      document.getElementById('stdDob').value = data.dob;
      document.getElementById('stdClass').value = data.class_id || '';
      document.getElementById('stdStatus').value = data.status;
      document.getElementById('stdAdmitted').value = data.admitted_on || '';
      
      document.getElementById('stdParentName').value = data.parent_name || '';
      document.getElementById('stdParentPhone').value = data.parent_phone || '';
      document.getElementById('stdParentEmail').value = data.parent_email || '';
      document.getElementById('stdAddress').value = data.address || '';
      
      new bootstrap.Modal(document.getElementById('stdModal')).show();
    });
}
function delStudent(id, name) {
  Swal.fire({
    title: 'Remove Student?',
    text: 'Permanently delete "' + name + '" and cancel their fee profiles and grades roster?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, remove'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delStdId').value = id;
      document.getElementById('delStdForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
