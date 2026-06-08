<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user = currentUser();
$orgId = (int)$user['org_id'];

// ── POST Handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $level = sanitize($_POST['level'] ?? '');
        $capacity = (int)($_POST['capacity'] ?? 40);
        $classTeacherId = (int)($_POST['class_teacher_id'] ?? 0) ?: null;
        $room = sanitize($_POST['room'] ?? '');
        $curriculum = in_array($_POST['curriculum'] ?? '', ['IB', 'IGCSE', 'Cambridge', 'CBC', 'AP', 'Mixed']) ? $_POST['curriculum'] : 'IB';
        $academicYearId = (int)($_POST['academic_year_id'] ?? 0) ?: null;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        try {
            if ($id > 0) {
                requireOrgOwnership('sch_classes', $id, $orgId);
                $stmt = $pdo->prepare("UPDATE sch_classes SET name = ?, level = ?, capacity = ?, class_teacher_id = ?, room = ?, curriculum = ?, academic_year_id = ?, status = ? WHERE id = ? AND org_id = ?");
                $stmt->execute([$name, $level, $capacity, $classTeacherId, $room, $curriculum, $academicYearId, $status, $id, $orgId]);
                setFlash('success', 'Class details updated successfully.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO sch_classes (org_id, name, level, capacity, class_teacher_id, room, curriculum, academic_year_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$orgId, $name, $level, $capacity, $classTeacherId, $room, $curriculum, $academicYearId, $status]);
                setFlash('success', "Academic class '$name' created successfully.");
            }
            logActivity($id > 0 ? 'update' : 'create', 'school', "Class: $name");
        } catch (Throwable $e) {
            error_log('[school/classes save] ' . $e->getMessage());
            setFlash('danger', 'Could not save class. Please run the school module database migration (database/school_module_migration.sql) and try again.');
        }
        redirect('classes.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            requireOrgOwnership('sch_classes', $id, $orgId);
            $pdo->prepare("DELETE FROM sch_classes WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
            setFlash('success', 'Class removed successfully.');
        } catch (Throwable $e) {
            setFlash('danger', 'Could not delete class.');
        }
        redirect('classes.php');
    }
}

// ── Load data ─────────────────────────────────────────────────────
$classesList = [];
try {
    $stmt = $pdo->prepare("SELECT c.*, CONCAT(t.first_name, ' ', t.last_name) AS teacher_name, 
                           (SELECT COUNT(*) FROM sch_students WHERE class_id = c.id AND org_id = ?) AS student_count
                           FROM sch_classes c
                           LEFT JOIN sch_teachers t ON c.class_teacher_id = t.id
                           WHERE c.org_id = ?
                           ORDER BY c.name ASC");
    $stmt->execute([$orgId, $orgId]);
    $classesList = $stmt->fetchAll();
} catch (Exception $e) {}

$teachersList = [];
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM sch_teachers WHERE org_id = ? AND status = 'active' ORDER BY first_name ASC");
    $stmt->execute([$orgId]);
    $teachersList = $stmt->fetchAll();
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

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chalkboard me-2" style="color:<?= $moduleColor ?>"></i>Academic Classes</h4>
    <p class="text-muted mb-0">Set up standard classes, configure curriculum alignments, and assign class rooms & teachers</p>
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
            <th>Class</th>
            <th>Curriculum</th>
            <th>Level</th>
            <th>Room</th>
            <th>Teacher</th>
            <th>Enrolled</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($classesList)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No academic classes created yet.</td></tr>
          <?php else: foreach ($classesList as $c):
            $pct = $c['capacity'] > 0 ? ($c['student_count'] / $c['capacity']) * 100 : 0;
            $progressColor = 'success';
            if ($pct >= 90) $progressColor = 'danger';
            elseif ($pct >= 75) $progressColor = 'warning';
            $rem = $c['capacity'] - $c['student_count'];
          ?>
          <tr>
            <td class="fw-semibold text-dark"><?= e($c['name']) ?></td>
            <td><span class="badge bg-primary"><?= e($c['curriculum'] ?: 'IB') ?></span></td>
            <td><span class="badge bg-light text-dark border"><?= e($c['level'] ?: '—') ?></span></td>
            <td class="text-muted small"><?= e($c['room'] ?: '—') ?></td>
            <td class="small"><?= e($c['teacher_name'] ?: '—') ?></td>
            <td>
              <div class="d-flex align-items-center gap-1 flex-wrap">
                <span class="fw-semibold small"><?= $c['student_count'] ?>/<?= $c['capacity'] ?></span>
                <?php if ($rem <= 0): ?><span class="badge bg-danger small">FULL</span>
                <?php else: ?>
                <div class="progress" style="width:50px;height:5px">
                  <div class="progress-bar bg-<?= $progressColor ?>" style="width:<?= min($pct,100) ?>%"></div>
                </div>
                <?php endif; ?>
              </div>
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
      <div class="col-md-8">
        <label class="form-label fw-semibold">Class Name <span class="text-danger">*</span></label>
        <input type="text" name="name" id="clsName" class="form-control" required placeholder="e.g. DP Year 2 Science">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Curriculum <span class="text-danger">*</span></label>
        <select name="curriculum" id="clsCurriculum" class="form-select" required>
          <?php foreach(['IB','IGCSE','Cambridge','CBC','AP','Mixed'] as $curr): ?>
          <option value="<?=$curr?>"><?=$curr?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Education Level / Category</label>
        <input type="text" name="level" id="clsLevel" class="form-control" placeholder="e.g. PYP, MYP, DP, IGCSE">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Room / Location</label>
        <input type="text" name="room" id="clsRoom" class="form-control" placeholder="e.g. Room 204, Lab B">
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
        <label class="form-label fw-semibold">Class Teacher (Dedicated Teacher Profile)</label>
        <select name="class_teacher_id" id="clsTeacher" class="form-select">
          <option value="">-- select teacher --</option>
          <?php foreach ($teachersList as $t): ?>
          <option value="<?= $t['id'] ?>"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></option>
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

<?php ob_start(); ?>
<script>
function openAdd() {
  document.getElementById('clsTitle').innerHTML = '<i class="fas fa-chalkboard me-2"></i>Create Academic Class';
  document.getElementById('clsId').value = '0';
  document.getElementById('clsName').value = '';
  document.getElementById('clsLevel').value = '';
  document.getElementById('clsRoom').value = '';
  document.getElementById('clsCurriculum').value = 'IB';
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
      document.getElementById('clsRoom').value = data.room || '';
      document.getElementById('clsCurriculum').value = data.curriculum || 'IB';
      document.getElementById('clsCapacity').value = data.capacity;
      document.getElementById('clsStatus').value = data.status;
      document.getElementById('clsTeacher').value = data.class_teacher_id || '';
      document.getElementById('clsYear').value = data.academic_year_id || '';
      
      new bootstrap.Modal(document.getElementById('clsModal')).show();
    });
}
function delClass(id, name) {
  if (confirm('Remove class "' + name + '"? Enrolled students will be marked as unassigned.')) {
    document.getElementById('delClsId').value = id;
    document.getElementById('delClsForm').submit();
  }
}
</script>
<?php 
$extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php';
?>
