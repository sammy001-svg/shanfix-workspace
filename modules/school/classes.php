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
        $name = sanitize($_POST['name'] ?? '');
        $level = sanitize($_POST['level'] ?? '');
        $capacity = (int)($_POST['capacity'] ?? 40);
        $classTeacher = (int)($_POST['class_teacher'] ?? 0) ?: null;
        $academicYearId = (int)($_POST['academic_year_id'] ?? 0) ?: null;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE sch_classes SET name = ?, level = ?, capacity = ?, class_teacher = ?, academic_year_id = ?, status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$name, $level, $capacity, $classTeacher, $academicYearId, $status, $id, $orgId]);
            setFlash('success', 'Class details updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO sch_classes (org_id, name, level, capacity, class_teacher, academic_year_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $name, $level, $capacity, $classTeacher, $academicYearId, $status]);
            setFlash('success', "Academic class '$name' created successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'school', "Class: $name");
        redirect('classes.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM sch_classes WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Class removed successfully.');
        redirect('classes.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$classesList = [];
try {
    $stmt = $pdo->prepare("SELECT c.*, u.name AS teacher_name, 
                           (SELECT COUNT(*) FROM sch_students WHERE class_id = c.id AND org_id = ?) AS student_count
                           FROM sch_classes c
                           LEFT JOIN users u ON c.class_teacher = u.id
                           WHERE c.org_id = ?
                           ORDER BY c.name ASC");
    $stmt->execute([$orgId, $orgId]);
    $classesList = $stmt->fetchAll();
} catch (Exception $e) {}

$usersList = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE org_id = ? ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $usersList = $stmt->fetchAll();
} catch (Exception $e) {}

$yearsList = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM sch_academic_years WHERE org_id = ? ORDER BY name DESC");
    $stmt->execute([$orgId]);
    $yearsList = $stmt->fetchAll();
} catch (Exception $e) {}

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $cid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM sch_classes WHERE id = ? AND org_id = ?");
        $stmt->execute([$cid, $orgId]);
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
    <h4 class="mb-1"><i class="fas fa-chalkboard me-2" style="color:<?= $moduleColor ?>"></i>Academic Classes</h4>
    <p class="text-muted mb-0">Set up standard classes, configure enrollment caps, and assign classroom teachers</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#clsModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Create Class</button>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-chalkboard me-2 text-success"></i>Classes List</h6>
    <span class="badge bg-secondary"><?= count($classesList) ?> configured</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Class Name</th>
            <th>Education Level</th>
            <th>Class Teacher</th>
            <th>Student Enrollment</th>
            <th>Remaining Capacity</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($classesList)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No academic classes created yet.</td></tr>
          <?php else: foreach ($classesList as $c): 
            $pct = $c['capacity'] > 0 ? ($c['student_count'] / $c['capacity']) * 100 : 0;
            $progressColor = 'success';
            if ($pct >= 90) $progressColor = 'danger';
            elseif ($pct >= 75) $progressColor = 'warning';
          ?>
          <tr>
            <td class="fw-semibold text-dark"><?= e($c['name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= e($c['level'] ?: '—') ?></span></td>
            <td class="fw-semibold"><?= e($c['teacher_name'] ?: 'Not Assigned') ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <span class="fw-bold"><?= $c['student_count'] ?></span> / <span class="text-muted"><?= $c['capacity'] ?></span>
                <div class="progress flex-grow-1" style="height:6px; min-width:80px;">
                  <div class="progress-bar bg-<?= $progressColor ?>" style="width: <?= min($pct, 100) ?>%"></div>
                </div>
              </div>
            </td>
            <td>
              <?php $rem = $c['capacity'] - $c['student_count']; 
              if ($rem <= 0) echo '<span class="text-danger fw-bold"><i class="fas fa-exclamation-circle me-1"></i>FULL</span>';
              else echo "<strong class='text-success'>$rem slots</strong>";
              ?>
            </td>
            <td>
              <span class="badge bg-<?= $c['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($c['status']) ?></span>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $c['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delClass(<?= $c['id'] ?>, '<?= e($c['name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="clsModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="clsId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="clsTitle"><i class="fas fa-chalkboard me-2"></i>Create Academic Class</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Class Name <span class="text-danger">*</span></label>
        <input type="text" name="name" id="clsName" class="form-control" required placeholder="e.g. Grade 4 West">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Education Level / Category</label>
        <input type="text" name="level" id="clsLevel" class="form-control" placeholder="e.g. Primary, Secondary, Form 1">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Student Capacity <span class="text-danger">*</span></label>
        <input type="number" name="capacity" id="clsCapacity" class="form-control" required min="1" max="200" value="40">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Status</label>
        <select name="status" id="clsStatus" class="form-select">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Class Teacher / Tutor</label>
        <select name="class_teacher" id="clsTeacher" class="form-select">
          <option value="">-- select teacher --</option>
          <?php foreach ($usersList as $ul): ?>
          <option value="<?= $ul['id'] ?>"><?= e($ul['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Academic Year Session</label>
        <select name="academic_year_id" id="clsYear" class="form-select">
          <option value="">-- select session --</option>
          <?php foreach ($yearsList as $yl): ?>
          <option value="<?= $yl['id'] ?>"><?= e($yl['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Class</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delClsForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delClsId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('clsTitle').innerHTML = '<i class="fas fa-chalkboard me-2"></i>Create Academic Class';
  document.getElementById('clsId').value = '0';
  document.getElementById('clsName').value = '';
  document.getElementById('clsLevel').value = '';
  document.getElementById('clsCapacity').value = '40';
  document.getElementById('clsStatus').value = 'active';
  document.getElementById('clsTeacher').value = '';
  document.getElementById('clsYear').value = '';
}
function openEdit(id) {
  fetch('classes.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('clsTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Class Details';
      document.getElementById('clsId').value = data.id;
      document.getElementById('clsName').value = data.name;
      document.getElementById('clsLevel').value = data.level || '';
      document.getElementById('clsCapacity').value = data.capacity;
      document.getElementById('clsStatus').value = data.status;
      document.getElementById('clsTeacher').value = data.class_teacher || '';
      document.getElementById('clsYear').value = data.academic_year_id || '';
      
      new bootstrap.Modal(document.getElementById('clsModal')).show();
    });
}
function delClass(id, name) {
  Swal.fire({
    title: 'Delete Class?',
    text: 'Remove "' + name + '"? Enrolled students will be marked as unassigned.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delClsId').value = id;
      document.getElementById('delClsForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
