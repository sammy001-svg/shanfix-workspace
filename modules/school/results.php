<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user = currentUser();
$orgId = (int)$user['org_id'];

// Helper to calculate curriculum-specific grades
function calculateCurriculumGrade(float $score, float $max, string $curriculum): string {
    if ($max <= 0) return '—';
    $pct = ($score / $max) * 100;
    
    if ($curriculum === 'IB') {
        if ($pct >= 85) return 'Level 7';
        if ($pct >= 75) return 'Level 6';
        if ($pct >= 65) return 'Level 5';
        if ($pct >= 55) return 'Level 4';
        if ($pct >= 45) return 'Level 3';
        if ($pct >= 30) return 'Level 2';
        return 'Level 1';
    } elseif (in_array($curriculum, ['IGCSE', 'Cambridge'])) {
        if ($pct >= 90) return 'A*';
        if ($pct >= 80) return 'A';
        if ($pct >= 70) return 'B';
        if ($pct >= 60) return 'C';
        if ($pct >= 50) return 'D';
        if ($pct >= 40) return 'E';
        if ($pct >= 30) return 'F';
        if ($pct >= 20) return 'G';
        return 'U';
    } else {
        // General / CBC / AP
        if ($pct >= 80) return 'A';
        if ($pct >= 70) return 'B';
        if ($pct >= 60) return 'C';
        if ($pct >= 50) return 'D';
        return 'E';
    }
}

// ── POST Handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_results') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $marks = $_POST['marks'] ?? [];
        $remarks = $_POST['remarks'] ?? [];
        $predictedGrades = $_POST['predicted_grades'] ?? [];
        $teacherComments = $_POST['teacher_comments'] ?? [];
        $maxMarks = (float)($_POST['max_marks'] ?? 100);

        if (!$examId || !$classId || !$subjectId) {
            setFlash('error', 'Exam, class and subject are required.');
            redirect('results.php');
        }

        // Fetch students and their curricula
        $studs = [];
        $stQuery = $pdo->prepare("SELECT id, curriculum FROM sch_students WHERE org_id = ? AND class_id = ?");
        $stQuery->execute([$orgId, $classId]);
        foreach ($stQuery->fetchAll() as $sRow) {
            $studs[$sRow['id']] = $sRow['curriculum'] ?: 'General';
        }

        $pdo->beginTransaction();
        foreach ($marks as $studentId => $mark) {
            $studentId = (int)$studentId;
            $mark = trim($mark) === '' ? null : (float)$mark;
            $remark = sanitize($remarks[$studentId] ?? '');
            $predictedGrade = sanitize($predictedGrades[$studentId] ?? '');
            $teacherComment = sanitize($teacherComments[$studentId] ?? '');
            
            $curr = $studs[$studentId] ?? 'General';
            $grade = $mark !== null ? calculateCurriculumGrade($mark, $maxMarks, $curr) : '';

            $pdo->prepare("INSERT INTO sch_results (org_id, exam_id, student_id, class_id, subject_id, marks, max_marks, grade, remarks, curriculum, predicted_grade, teacher_comment, created_by) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                           ON DUPLICATE KEY UPDATE 
                             marks = VALUES(marks), 
                             grade = VALUES(grade), 
                             remarks = VALUES(remarks), 
                             curriculum = VALUES(curriculum), 
                             predicted_grade = VALUES(predicted_grade), 
                             teacher_comment = VALUES(teacher_comment), 
                             created_by = VALUES(created_by)")
               ->execute([$orgId, $examId, $studentId, $classId, $subjectId, $mark, $maxMarks, $grade, $remark, $curr, $predictedGrade, $teacherComment, $user['id']]);
        }
        $pdo->commit();
        setFlash('success', 'Exam results registered successfully.');
        redirect("results.php?exam_id=$examId&class_id=$classId&subject_id=$subjectId");
    }
}

// ── GET Handlers ──────────────────────────────────────────────────
$fExam = (int)($_GET['exam_id'] ?? 0);
$fClass = (int)($_GET['class_id'] ?? 0);
$fSubject = (int)($_GET['subject_id'] ?? 0);
$reportMode = $_GET['mode'] ?? 'enter'; // enter | card

$exams = [];
try {
    $s = $pdo->prepare("SELECT id, name, term, academic_year FROM sch_exams WHERE org_id = ? ORDER BY created_at DESC");
    $s->execute([$orgId]);
    $exams = $s->fetchAll();
} catch (Exception $e) {}

$classes = [];
try {
    $s = $pdo->prepare("SELECT id, name FROM sch_classes WHERE org_id = ? ORDER BY name");
    $s->execute([$orgId]);
    $classes = $s->fetchAll();
} catch (Exception $e) {}

$subjects = [];
$maxMarks = 100;
if ($fExam && $fClass) {
    try {
        $s = $pdo->prepare("SELECT es.subject_id, es.max_marks, sub.name FROM sch_exam_schedule es JOIN sch_subjects sub ON es.subject_id = sub.id WHERE es.exam_id = ? AND es.class_id = ?");
        $s->execute([$fExam, $fClass]);
        $subjects = $s->fetchAll();
    } catch (Exception $e) {}
    if ($fSubject) {
        foreach ($subjects as $sub) {
            if ($sub['subject_id'] == $fSubject) {
                $maxMarks = $sub['max_marks'];
                break;
            }
        }
    }
}
if (empty($subjects) && $fClass) {
    try {
        $s = $pdo->prepare("SELECT id AS subject_id, 100 AS max_marks, name FROM sch_subjects WHERE org_id = ? AND status = 'active' ORDER BY name");
        $s->execute([$orgId]);
        $subjects = $s->fetchAll();
    } catch (Exception $e) {}
}

$students = [];
$existing = [];
if ($fExam && $fClass && $fSubject) {
    try {
        $s = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name, admission_no, curriculum FROM sch_students WHERE org_id = ? AND class_id = ? AND status = 'active' ORDER BY first_name");
        $s->execute([$orgId, $fClass]);
        $students = $s->fetchAll();
    } catch (Exception $e) {}
    try {
        $s = $pdo->prepare("SELECT student_id, marks, grade, remarks, predicted_grade, teacher_comment FROM sch_results WHERE org_id = ? AND exam_id = ? AND class_id = ? AND subject_id = ?");
        $s->execute([$orgId, $fExam, $fClass, $fSubject]);
        foreach ($s->fetchAll() as $r) {
            $existing[$r['student_id']] = $r;
        }
    } catch (Exception $e) {}
}

// Report card view data
$reportCard = [];
$reportStudents = [];
if ($fExam && $fClass && $reportMode === 'card') {
    try {
        $s = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name, admission_no, curriculum FROM sch_students WHERE org_id = ? AND class_id = ? AND status = 'active' ORDER BY first_name");
        $s->execute([$orgId, $fClass]);
        $reportStudents = $s->fetchAll();
    } catch (Exception $e) {}
    try {
        $s = $pdo->prepare("SELECT r.*, sub.name AS subject_name FROM sch_results r JOIN sch_subjects sub ON r.subject_id = sub.id WHERE r.org_id = ? AND r.exam_id = ? AND r.class_id = ? ORDER BY r.student_id, sub.name");
        $s->execute([$orgId, $fExam, $fClass]);
        foreach ($s->fetchAll() as $r) {
            $reportCard[$r['student_id']][] = $r;
        }
    } catch (Exception $e) {}
}

$examName = '';
foreach ($exams as $ex) {
    if ($ex['id'] == $fExam) $examName = $ex['name'] . ($ex['term'] ? ' — ' . $ex['term'] : '');
}
$className = '';
foreach ($classes as $c) {
    if ($c['id'] == $fClass) $className = $c['name'];
}
$subjectName = '';
foreach ($subjects as $s) {
    if ($s['subject_id'] == $fSubject) $subjectName = $s['name'];
}

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-line me-2" style="color:<?= $moduleColor ?>"></i>Curriculum Exam Results</h4>
    <p class="text-muted mb-0">Record exam marks, teacher comments, predicted grades, and print comprehensive international report cards</p>
  </div>
  <?php if ($fExam && $fClass): ?>
  <div class="d-flex gap-2">
    <?php if ($reportMode !== 'card'): ?>
      <a href="results.php?exam_id=<?= $fExam ?>&class_id=<?= $fClass ?>&mode=card" class="btn btn-outline-info btn-sm"><i class="fas fa-id-card me-1"></i>View Report Cards</a>
    <?php else: ?>
      <a href="results.php?exam_id=<?= $fExam ?>&class_id=<?= $fClass ?>&mode=enter" class="btn btn-outline-secondary btn-sm"><i class="fas fa-edit me-1"></i>Enter Marks</a>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print Report Cards</button>
  </div>
  <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <input type="hidden" name="mode" value="<?= e($reportMode) ?>">
    <div class="col-sm-4">
      <label class="form-label small fw-semibold mb-1">Select Exam Session</label>
      <select name="exam_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">— Select Exam —</option>
        <?php foreach ($exams as $ex): ?>
          <option value="<?= $ex['id'] ?>" <?= $fExam == $ex['id'] ? 'selected' : '' ?>><?= e($ex['name']) ?><?= $ex['term'] ? ' (' . $ex['term'] . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-3">
      <label class="form-label small fw-semibold mb-1">Class / Form</label>
      <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">— Select Class —</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $fClass == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($reportMode === 'enter' && $fExam && $fClass): ?>
    <div class="col-sm-3">
      <label class="form-label small fw-semibold mb-1">Academic Subject</label>
      <select name="subject_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">— Select Subject —</option>
        <?php foreach ($subjects as $s): ?>
          <option value="<?= $s['subject_id'] ?>" <?= $fSubject == $s['subject_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-auto"><button class="btn btn-sm btn-success">Apply Filter</button></div>
  </form>
</div></div>

<?php if (!$fExam || !$fClass): ?>
<div class="text-center py-5 text-muted bg-white rounded border"><i class="fas fa-chart-line fa-3x mb-3 d-block text-secondary opacity-50"></i>Select an exam session and student class to get started.</div>

<?php elseif ($reportMode === 'card'): ?>
<!-- Report Card View -->
<?php if (empty($reportStudents)): ?>
<div class="text-center py-5 text-muted bg-white rounded border"><i class="fas fa-users fa-3x mb-3 d-block text-secondary opacity-50"></i>No active students found in this class.</div>
<?php else: foreach ($reportStudents as $st):
  $rows = $reportCard[$st['id']] ?? [];
  $total = 0;
  $maxTotal = 0;
  $count = 0;
  $curr = $st['curriculum'] ?: 'General';

  foreach ($rows as $r) {
      if ($r['marks'] !== null) {
          $total += $r['marks'];
          $maxTotal += $r['max_marks'];
          $count++;
      }
  }
  $pct = $maxTotal > 0 ? round(100 * $total / $maxTotal) : 0;
  $pctC = $pct >= 90 ? 'success' : ($pct >= 75 ? 'primary' : ($pct >= 60 ? 'warning text-dark' : 'danger'));
?>
<div class="card mb-4 report-card-print">
  <div class="card-header d-flex justify-content-between align-items-center" style="background:<?= $moduleColor ?>10; border-left: 5px solid <?= $moduleColor ?>">
    <div>
      <h5 class="mb-1 fw-bold text-dark"><?= e($st['name']) ?></h5>
      <span class="badge bg-light text-dark border me-2 fw-semibold">Curriculum: <?=e($curr)?></span>
      <small class="text-muted">Adm No: <strong><?= e($st['admission_no'] ?? '') ?></strong> &bull; Class: <strong><?= e($className) ?></strong> &bull; Exam: <strong><?= e($examName) ?></strong></small>
    </div>
    <div class="text-end">
      <span class="badge bg-<?= $pctC ?> fs-5 py-2 px-3"><?= $pct ?>% Avg</span>
      <div class="small fw-semibold text-muted mt-1"><?= $total ?> / <?= $maxTotal ?> Total Score</div>
    </div>
  </div>
  <div class="card-body p-0">
    <table class="table table-striped table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Subject Name</th>
          <th class="text-center">Max Marks</th>
          <th class="text-center">Marks Obtained</th>
          <th class="text-center">Percentage</th>
          <th class="text-center">Assigned Grade</th>
          <th class="text-center">Predicted Grade</th>
          <th>Teacher Comment</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">No academic exam results recorded.</td></tr>
        <?php else: foreach ($rows as $r):
          $p = $r['max_marks'] > 0 ? round(100 * $r['marks'] / $r['max_marks']) : 0;
          $pc = $p >= 90 ? 'success' : ($p >= 75 ? 'primary' : ($p >= 60 ? 'warning text-dark' : 'danger'));
        ?>
          <tr>
            <td class="fw-semibold text-dark"><?= e($r['subject_name']) ?></td>
            <td class="text-center"><?= number_format($r['max_marks'], 0) ?></td>
            <td class="text-center fw-bold text-dark"><?= $r['marks'] !== null ? number_format($r['marks'], 1) : '—' ?></td>
            <td class="text-center"><span class="badge bg-<?= $pc ?>"><?= $p ?>%</span></td>
            <td class="text-center fw-bold text-success fs-6"><?= e($r['grade'] ?: '—') ?></td>
            <td class="text-center fw-bold text-primary fs-6"><?= e($r['predicted_grade'] ?: '—') ?></td>
            <td class="small text-muted"><?= e($r['teacher_comment'] ?: $r['remarks'] ?: '—') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; endif; ?>

<?php elseif (!$fSubject): ?>
<div class="text-center py-5 text-muted bg-white rounded border"><i class="fas fa-book fa-3x mb-3 d-block text-secondary opacity-50"></i>Select a subject from the academic schedule to begin entering results.</div>

<?php else: ?>
<!-- Enter Marks -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-pencil-alt me-2 text-success"></i>Enter Marks — <?= e($subjectName) ?> | <?= e($className) ?></h6>
    <span class="badge bg-secondary">Max Marks: <?= number_format($maxMarks, 0) ?></span>
  </div>
  <div class="card-body p-0">
  <?php if (empty($students)): ?>
    <div class="text-center py-4 text-muted">No active students enrolled in this class.</div>
  <?php else: ?>
  <form method="POST">
    <?= csrfField() ?><input type="hidden" name="action" value="save_results">
    <input type="hidden" name="exam_id" value="<?= $fExam ?>"><input type="hidden" name="class_id" value="<?= $fClass ?>">
    <input type="hidden" name="subject_id" value="<?= $fSubject ?>"><input type="hidden" name="max_marks" value="<?= $maxMarks ?>">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th style="width: 50px;">#</th>
            <th>Student Name</th>
            <th>Curriculum</th>
            <th>Adm No</th>
            <th style="width: 150px;">Marks / <?= number_format($maxMarks, 0) ?></th>
            <th style="width: 100px;">Auto Grade</th>
            <th style="width: 150px;">Predicted Grade</th>
            <th>Teacher Reports / Comments</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $i => $st):
            $cur = $existing[$st['id']] ?? ['marks' => '', 'grade' => '', 'remarks' => '', 'predicted_grade' => '', 'teacher_comment' => ''];
            $stCurr = $st['curriculum'] ?: 'General';
          ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td class="fw-semibold text-dark"><?= e($st['name']) ?></td>
            <td><span class="badge bg-light text-dark border fw-bold"><?= e($stCurr) ?></span></td>
            <td class="small text-muted"><?= e($st['admission_no'] ?? '—') ?></td>
            <td>
              <input type="number" name="marks[<?= $st['id'] ?>]" class="form-control form-control-sm mark-input fw-bold text-success"
                data-max="<?= $maxMarks ?>" data-student="<?= $st['id'] ?>" data-curriculum="<?= e($stCurr) ?>"
                value="<?= $cur['marks'] !== null && $cur['marks'] !== '' ? e($cur['marks']) : '' ?>" min="0" max="<?= $maxMarks ?>" step="0.5" placeholder="—" oninput="calculateLiveGrade(this)">
            </td>
            <td class="text-center"><span class="badge bg-secondary grade-badge font-monospace p-2 fs-6" id="grade_<?= $st['id'] ?>"><?= e($cur['grade'] ?: '—') ?></span></td>
            <td>
              <input type="text" name="predicted_grades[<?= $st['id'] ?>]" class="form-control form-control-sm font-monospace text-center fw-bold" value="<?= e($cur['predicted_grade'] ?? '') ?>" placeholder="e.g. A* / 7">
            </td>
            <td>
              <input type="text" name="remarks[<?= $st['id'] ?>]" class="form-control form-control-sm mb-1" value="<?= e($cur['remarks'] ?? '') ?>" placeholder="Short remarks">
              <input type="text" name="teacher_comments[<?= $st['id'] ?>]" class="form-control form-control-sm" value="<?= e($cur['teacher_comment'] ?? '') ?>" placeholder="Detailed term feedback comment...">
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="p-3 border-top bg-light text-end">
      <button type="submit" class="btn text-white px-4" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-2"></i>Save Exam Results Ledger</button>
    </div>
  </form>
  <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php ob_start(); ?>
<script>
function getCurriculumGrade(score, max, curriculum) {
  if (max <= 0 || score === '') return '—';
  var pct = (parseFloat(score) / parseFloat(max)) * 100;
  
  if (curriculum === 'IB') {
    if (pct >= 85) return 'Level 7';
    if (pct >= 75) return 'Level 6';
    if (pct >= 65) return 'Level 5';
    if (pct >= 55) return 'Level 4';
    if (pct >= 45) return 'Level 3';
    if (pct >= 30) return 'Level 2';
    return 'Level 1';
  } else if (curriculum === 'IGCSE' || curriculum === 'Cambridge') {
    if (pct >= 90) return 'A*';
    if (pct >= 80) return 'A';
    if (pct >= 70) return 'B';
    if (pct >= 60) return 'C';
    if (pct >= 50) return 'D';
    if (pct >= 40) return 'E';
    if (pct >= 30) return 'F';
    if (pct >= 20) return 'G';
    return 'U';
  } else {
    // General
    if (pct >= 80) return 'A';
    if (pct >= 70) return 'B';
    if (pct >= 60) return 'C';
    if (pct >= 50) return 'D';
    return 'E';
  }
}

function calculateLiveGrade(input) {
  const studentId = input.getAttribute('data-student');
  const max = parseFloat(input.getAttribute('data-max')) || 100;
  const curr = input.getAttribute('data-curriculum') || 'General';
  const val = input.value;
  
  const grade = getCurriculumGrade(val, max, curr);
  const badge = document.getElementById('grade_' + studentId);
  if (badge) {
    badge.textContent = grade;
    // Style update
    if (grade.includes('7') || grade.includes('6') || ['A*','A','B'].includes(grade)) {
      badge.className = 'badge bg-success font-monospace p-2 fs-6';
    } else if (grade.includes('5') || grade.includes('4') || ['C','D'].includes(grade)) {
      badge.className = 'badge bg-info font-monospace p-2 fs-6';
    } else if (grade === '—') {
      badge.className = 'badge bg-secondary font-monospace p-2 fs-6';
    } else {
      badge.className = 'badge bg-danger font-monospace p-2 fs-6';
    }
  }
}
</script>
<?php 
$extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php';
?>
