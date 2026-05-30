<?php
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'subject_assign') {
        $subjectName = sanitize($_POST['subject_name'] ?? '');
        $subjectCode = sanitize($_POST['subject_code'] ?? '');
        $classId = (int)($_POST['class_id'] ?? 0) ?: null;
        $teacherId = (int)($_POST['teacher_id'] ?? 0) ?: null;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        $stmt = $pdo->prepare("INSERT INTO sch_subjects (org_id, name, code, class_id, teacher_id, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$orgId, $subjectName, $subjectCode, $classId, $teacherId, $status]);

        setFlash('success', "Assigned subject '$subjectName' successfully.");
        logActivity('create', 'school', "Subject created: $subjectName (Code: $subjectCode)");
        redirect('staff.php');
    }

    if ($action === 'delete_subject') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM sch_subjects WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Subject assignment removed.');
        redirect('staff.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$staffList = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE org_id = ? ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $staffList = $stmt->fetchAll();
} catch (Exception $e) {}

$subjectsList = [];
try {
    $stmt = $pdo->prepare("SELECT s.*, c.name AS class_name, u.name AS teacher_name 
                           FROM sch_subjects s
                           LEFT JOIN sch_classes c ON s.class_id = c.id
                           LEFT JOIN users u ON s.teacher_id = u.id
                           WHERE s.org_id = ?
                           ORDER BY c.name ASC, s.name ASC");
    $stmt->execute([$orgId]);
    $subjectsList = $stmt->fetchAll();
} catch (Exception $e) {}

$classesList = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM sch_classes WHERE org_id = ? ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $classesList = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chalkboard-teacher me-2" style="color:<?= $moduleColor ?>"></i>Academic Staff & Curriculum</h4>
    <p class="text-muted mb-0">Manage teaching assignments, curricular subjects, and class instructors</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#subjectModal"><i class="fas fa-book-reader me-2"></i>Assign Subject</button>
</div>

<div class="row g-4">
  <!-- Staff Roster Card -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-users me-2 text-success"></i>School Instructors</h6>
        <span class="badge bg-secondary"><?= count($staffList) ?> Staff</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Instructor Name</th>
                <th>Role</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($staffList)): ?>
              <tr><td colspan="3" class="text-center text-muted py-4">No staff members found.</td></tr>
              <?php else: foreach ($staffList as $st): ?>
              <tr>
                <td>
                  <div class="fw-semibold text-dark"><?= e($st['name']) ?></div>
                  <small class="text-muted"><?= e($st['email']) ?></small>
                </td>
                <td><span class="badge bg-light text-dark border"><?= ucfirst($st['role']) ?></span></td>
                <td><span class="badge bg-<?= $st['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($st['status']) ?></span></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Subject Assignments Card -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-book me-2 text-success"></i>Curriculum Subject Assignments</h6>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Subject Details</th>
                <th>Assigned Class</th>
                <th>Teacher Assigned</th>
                <th>Status</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($subjectsList)): ?>
              <tr><td colspan="5" class="text-center text-muted py-5"><i class="fas fa-book-open fa-2x mb-2 d-block"></i>No subject/teacher assignments configured yet.</td></tr>
              <?php else: foreach ($subjectsList as $sub): ?>
              <tr>
                <td>
                  <div class="fw-semibold text-dark"><?= e($sub['name']) ?></div>
                  <small class="text-muted">Code: <?= e($sub['code']) ?></small>
                </td>
                <td><span class="badge bg-light text-dark border"><?= e($sub['class_name'] ?: 'Unassigned') ?></span></td>
                <td class="fw-semibold text-dark"><?= e($sub['teacher_name'] ?: 'Not Assigned') ?></td>
                <td><span class="badge bg-<?= $sub['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($sub['status']) ?></span></td>
                <td class="text-center">
                  <button class="btn btn-sm btn-outline-danger" onclick="delSubject(<?= $sub['id'] ?>)" title="Remove Assignment"><i class="fas fa-trash-alt"></i></button>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Assign Subject Modal -->
<div class="modal fade" id="subjectModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="subject_assign">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title"><i class="fas fa-book-open me-2"></i>Assign Subject Assignment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Subject Title <span class="text-danger">*</span></label>
        <input type="text" name="subject_name" class="form-control" required placeholder="e.g. Mathematics, English Language">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Subject Code <span class="text-danger">*</span></label>
        <input type="text" name="subject_code" class="form-control" required placeholder="e.g. MAT-04, ENG-01">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Status</label>
        <select name="status" class="form-select">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Academic Class <span class="text-danger">*</span></label>
        <select name="class_id" class="form-select" required>
          <option value="">-- select target class --</option>
          <?php foreach ($classesList as $cl): ?>
          <option value="<?= $cl['id'] ?>"><?= e($cl['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Teaching Instructor <span class="text-danger">*</span></label>
        <select name="teacher_id" class="form-select select2-enable" required style="width:100%;">
          <option value="">-- select instructor --</option>
          <?php foreach ($staffList as $st): ?>
          <option value="<?= $st['id'] ?>"><?= e($st['name']) ?> (<?= e($st['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Assignment</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delSubForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete_subject">
  <input type="hidden" name="id" id="delSubId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function delSubject(id) {
  Swal.fire({
    title: 'Remove Assignment?',
    text: 'Permanently remove this subject configuration from classes and scorebooks?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, remove'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delSubId').value = id;
      document.getElementById('delSubForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>

