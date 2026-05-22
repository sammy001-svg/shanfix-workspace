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
        $studentId = (int)$_POST['student_id'];
        $subjectId = (int)$_POST['subject_id'];
        $term = sanitize($_POST['term'] ?? 'Term 1');
        $year = (int)($_POST['year'] ?? date('Y'));
        $catScore = (float)$_POST['cat_score'];
        $examScore = (float)$_POST['exam_score'];
        $totalScore = $catScore + $examScore;

        // Auto Grade Calculation
        $grade = 'E';
        $remarks = 'Needs Improvement';
        if ($totalScore >= 80) { $grade = 'A'; $remarks = 'Excellent'; }
        elseif ($totalScore >= 70) { $grade = 'B'; $remarks = 'Very Good'; }
        elseif ($totalScore >= 60) { $grade = 'C'; $remarks = 'Good'; }
        elseif ($totalScore >= 50) { $grade = 'D'; $remarks = 'Pass'; }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE sch_grades SET student_id = ?, subject_id = ?, term = ?, year = ?, cat_score = ?, exam_score = ?, total_score = ?, grade = ?, remarks = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$studentId, $subjectId, $term, $year, $catScore, $examScore, $totalScore, $grade, $remarks, $id, $orgId]);
            setFlash('success', 'Grades record updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO sch_grades (org_id, student_id, subject_id, term, year, cat_score, exam_score, total_score, grade, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $studentId, $subjectId, $term, $year, $catScore, $examScore, $totalScore, $grade, $remarks]);
            setFlash('success', 'Student score registered successfully.');
        }
        logActivity($id > 0 ? 'update' : 'create', 'school', "Score recorded: Stud ID $studentId, Sub ID $subjectId, Total: $totalScore");
        redirect('grades.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM sch_grades WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Grades record removed.');
        redirect('grades.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fSubject = $_GET['subject_id'] ?? '';
$fTerm = $_GET['term'] ?? '';

$where = 'g.org_id = ?';
$params = [$orgId];

if ($fSubject !== '') {
    $where .= ' AND g.subject_id = ?';
    $params[] = $fSubject;
}
if ($fTerm !== '') {
    $where .= ' AND g.term = ?';
    $params[] = $fTerm;
}

$gradesList = [];
try {
    $stmt = $pdo->prepare("SELECT g.*, s.first_name, s.last_name, s.admission_no, sub.name AS subject_name, sub.code AS subject_code
                           FROM sch_grades g
                           JOIN sch_students s ON g.student_id = s.id
                           JOIN sch_subjects sub ON g.subject_id = sub.id
                           WHERE $where
                           ORDER BY g.created_at DESC");
    $stmt->execute($params);
    $gradesList = $stmt->fetchAll();
} catch (Exception $e) {}

$studentsList = [];
try {
    $stmt = $pdo->prepare("SELECT id, admission_no, first_name, last_name FROM sch_students WHERE org_id = ? AND status = 'active' ORDER BY first_name ASC");
    $stmt->execute([$orgId]);
    $studentsList = $stmt->fetchAll();
} catch (Exception $e) {}

$subjectsList = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, code FROM sch_subjects WHERE org_id = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $subjectsList = $stmt->fetchAll();
} catch (Exception $e) {}

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $gid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM sch_grades WHERE id = ? AND org_id = ?");
        $stmt->execute([$gid, $orgId]);
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
    <h4 class="mb-1"><i class="fas fa-star me-2" style="color:<?= $moduleColor ?>"></i>Student Performance Grades</h4>
    <p class="text-muted mb-0">Record continuous assessments, final exams, calculate auto-GPA scales and issue report books</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#gradeModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Record Student Score</button>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Filter Academic Subject</label>
        <select name="subject_id" class="form-select form-select-sm">
          <option value="">All Subjects</option>
          <?php foreach ($subjectsList as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $fSubject == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e($s['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Academic Term</label>
        <select name="term" class="form-select form-select-sm">
          <option value="">All Terms</option>
          <option value="Term 1" <?= $fTerm === 'Term 1' ? 'selected' : '' ?>>Term 1</option>
          <option value="Term 2" <?= $fTerm === 'Term 2' ? 'selected' : '' ?>>Term 2</option>
          <option value="Term 3" <?= $fTerm === 'Term 3' ? 'selected' : '' ?>>Term 3</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="grades.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-award me-2 text-success"></i>Score Book Records</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Student Name</th>
            <th>Subject</th>
            <th>Term</th>
            <th class="text-center">CAT Score (30%)</th>
            <th class="text-center">Exam Score (70%)</th>
            <th class="text-center">Total Score (100%)</th>
            <th class="text-center">Grade Scale</th>
            <th>Teacher Remarks</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($gradesList)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-graduation-cap fa-2x mb-2 d-block"></i>No student performance scores recorded yet.</td></tr>
          <?php else: foreach ($gradesList as $g): 
            $gradeColors = ['A' => 'success', 'B' => 'info', 'C' => 'primary', 'D' => 'warning text-dark', 'E' => 'danger'];
            $gc = $gradeColors[$g['grade']] ?? 'secondary';
          ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><?= e($g['first_name'] . ' ' . $g['last_name']) ?></div>
              <small class="text-muted">Adm No: <?= e($g['admission_no']) ?></small>
            </td>
            <td class="fw-semibold text-dark"><?= e($g['subject_name']) ?> <small class="text-muted">(<?= e($g['subject_code']) ?>)</small></td>
            <td><?= e($g['term']) ?> / <?= $g['year'] ?></td>
            <td class="text-center fw-semibold"><?= number_format($g['cat_score'], 1) ?></td>
            <td class="text-center fw-semibold"><?= number_format($g['exam_score'], 1) ?></td>
            <td class="text-center fw-bold text-dark fs-6"><?= number_format($g['total_score'], 1) ?></td>
            <td class="text-center"><span class="badge bg-<?= $gc ?> p-2 fs-6 rounded-circle" style="width:35px;height:35px;display:inline-flex;align-items:center;justify-content:center;"><?= e($g['grade']) ?></span></td>
            <td><span class="text-dark small fw-semibold"><?= e($g['remarks'] ?: '—') ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $g['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delGrade(<?= $g['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="gradeModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="gradeId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="gradeTitle"><i class="fas fa-star me-2"></i>Record Student Score</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Select Student <span class="text-danger">*</span></label>
        <select name="student_id" id="gradeStudentId" class="form-select select2-enable" required style="width:100%;">
          <option value="">-- select student --</option>
          <?php foreach ($studentsList as $st): ?>
          <option value="<?= $st['id'] ?>"><?= e($st['first_name'] . ' ' . $st['last_name']) ?> (<?= e($st['admission_no']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Select Subject <span class="text-danger">*</span></label>
        <select name="subject_id" id="gradeSubjectId" class="form-select" required>
          <option value="">-- select subject --</option>
          <?php foreach ($subjectsList as $sub): ?>
          <option value="<?= $sub['id'] ?>"><?= e($sub['name']) ?> (<?= e($sub['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Academic Term <span class="text-danger">*</span></label>
        <select name="term" id="gradeTerm" class="form-select" required>
          <option value="Term 1">Term 1</option>
          <option value="Term 2">Term 2</option>
          <option value="Term 3">Term 3</option>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Academic Year <span class="text-danger">*</span></label>
        <input type="number" name="year" id="gradeYear" class="form-control" required value="<?= date('Y') ?>">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Continuous Assessment Test (CAT / 30) <span class="text-danger">*</span></label>
        <input type="number" name="cat_score" id="gradeCat" class="form-control" required min="0" max="30" step="0.1" placeholder="0.0 - 30.0" oninput="calculateLiveGrade()">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Term End Final Exam (Exam / 70) <span class="text-danger">*</span></label>
        <input type="number" name="exam_score" id="gradeExam" class="form-control" required min="0" max="70" step="0.1" placeholder="0.0 - 70.0" oninput="calculateLiveGrade()">
      </div>
      <div class="col-12 text-center bg-light py-2 border rounded">
        <div class="small fw-semibold text-muted">Auto Calculated GPA Performance Scale</div>
        <div class="fs-4 fw-bold text-dark mt-1" id="liveScore">Total Score: 0.0 &nbsp;|&nbsp; Grade: E</div>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Record Grade</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delGradeForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delGradeId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function calculateLiveGrade() {
  const catVal = parseFloat(document.getElementById('gradeCat').value) || 0;
  const examVal = parseFloat(document.getElementById('gradeExam').value) || 0;
  const total = catVal + examVal;
  
  let grade = 'E';
  let remarks = 'Needs Improvement';
  if (total >= 80) { grade = 'A'; remarks = 'Excellent'; }
  else if (total >= 70) { grade = 'B'; remarks = 'Very Good'; }
  else if (total >= 60) { grade = 'C'; remarks = 'Good'; }
  else if (total >= 50) { grade = 'D'; remarks = 'Pass'; }
  
  document.getElementById('liveScore').innerHTML = 'Total Score: <strong>' + total.toFixed(1) + '</strong> &nbsp;|&nbsp; Grade: <strong class="text-success">' + grade + ' (' + remarks + ')</strong>';
}
function openAdd() {
  document.getElementById('gradeTitle').innerHTML = '<i class="fas fa-star me-2"></i>Record Student Score';
  document.getElementById('gradeId').value = '0';
  document.getElementById('gradeStudentId').value = '';
  document.getElementById('gradeSubjectId').value = '';
  document.getElementById('gradeTerm').value = 'Term 1';
  document.getElementById('gradeYear').value = new Date().getFullYear();
  document.getElementById('gradeCat').value = '';
  document.getElementById('gradeExam').value = '';
  document.getElementById('liveScore').innerHTML = 'Total Score: 0.0 &nbsp;|&nbsp; Grade: E';
}
function openEdit(id) {
  fetch('grades.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('gradeTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Score Book Record';
      document.getElementById('gradeId').value = data.id;
      document.getElementById('gradeStudentId').value = data.student_id;
      document.getElementById('gradeSubjectId').value = data.subject_id;
      document.getElementById('gradeTerm').value = data.term;
      document.getElementById('gradeYear').value = data.year;
      document.getElementById('gradeCat').value = data.cat_score;
      document.getElementById('gradeExam').value = data.exam_score;
      
      calculateLiveGrade();
      new bootstrap.Modal(document.getElementById('gradeModal')).show();
    });
}
function delGrade(id) {
  Swal.fire({
    title: 'Delete Performance Score?',
    text: 'Permanently remove this score ledger from the student academic record?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delGradeId').value = id;
      document.getElementById('delGradeForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
