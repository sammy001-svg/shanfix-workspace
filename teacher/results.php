<?php
$pageTitle = 'Enter Results';
require_once __DIR__ . '/../includes/header-teacher.php';

// ── Load exams with my subjects ──────────────────────────────────
$exams = [];
try {
    $s = $pdo->prepare(
        "SELECT DISTINCT e.id, e.name, e.term, e.academic_year, e.start_date, e.end_date, e.status
         FROM sch_exams e
         JOIN sch_exam_schedule es ON es.exam_id = e.id
         JOIN sch_class_subjects cs ON cs.class_id = es.class_id AND cs.subject_id = es.subject_id
         WHERE e.org_id=? AND cs.staff_id=?
         ORDER BY e.end_date DESC"
    );
    $s->execute([$tchOrgId, $tchId]);
    $exams = $s->fetchAll();
} catch (Throwable $e) {}

$selectedExamId = (int)($_GET['exam'] ?? ($exams[0]['id'] ?? 0));
$selectedExam   = null;
foreach ($exams as $ex) { if ((int)$ex['id'] === $selectedExamId) { $selectedExam = $ex; break; } }

// ── Load my class+subject combos for this exam ───────────────────
$examSections = [];
if ($selectedExamId) {
    try {
        $s = $pdo->prepare(
            "SELECT es.class_id, es.subject_id, c.name AS class_name, sub.name AS subject_name,
                    sub.code AS subject_code, es.max_marks
             FROM sch_exam_schedule es
             JOIN sch_class_subjects cs ON cs.class_id = es.class_id AND cs.subject_id = es.subject_id
             JOIN sch_classes c ON c.id = es.class_id
             JOIN sch_subjects sub ON sub.id = es.subject_id
             WHERE es.exam_id=? AND es.org_id=? AND cs.staff_id=?
             ORDER BY c.name, sub.name"
        );
        $s->execute([$selectedExamId, $tchOrgId, $tchId]);
        $examSections = $s->fetchAll();
    } catch (Throwable $e) {}
}

$selectedClassId   = (int)($_GET['class_id']   ?? ($examSections[0]['class_id']   ?? 0));
$selectedSubjectId = (int)($_GET['subject_id']  ?? ($examSections[0]['subject_id'] ?? 0));
$selectedSection   = null;
foreach ($examSections as $sec) {
    if ((int)$sec['class_id']===$selectedClassId && (int)$sec['subject_id']===$selectedSubjectId) {
        $selectedSection = $sec; break;
    }
}
if (!$selectedSection && !empty($examSections)) {
    $selectedSection   = $examSections[0];
    $selectedClassId   = (int)$selectedSection['class_id'];
    $selectedSubjectId = (int)$selectedSection['subject_id'];
}

// ── POST: save results ───────────────────────────────────────────
$saveMsg = null; $saveErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedExamId && $selectedClassId && $selectedSubjectId) {
    $postExam    = (int)$_POST['exam_id'];
    $postClass   = (int)$_POST['class_id'];
    $postSubject = (int)$_POST['subject_id'];
    $postMax     = max(1, (int)$_POST['max_marks']);
    $entries     = $_POST['results'] ?? [];
    $saved = 0;
    foreach ($entries as $studentId => $data) {
        $studentId = (int)$studentId;
        $marks     = $data['marks'] === '' ? null : max(0, min((float)$data['marks'], $postMax));
        $comment   = trim($data['comment'] ?? '');
        $grade     = trim($data['grade'] ?? '');

        if ($marks === null) continue; // skip blanks

        // Compute grade if not supplied
        if (!$grade && $marks !== null) {
            $pct = $marks / $postMax * 100;
            $grade = $pct>=80?'A':($pct>=70?'B':($pct>=60?'C':($pct>=50?'D':($pct>=40?'E':'F'))));
        }

        try {
            $pdo->prepare(
                "INSERT INTO sch_results (org_id, student_id, exam_id, class_id, subject_id, marks, max_marks, grade, teacher_comment)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE marks=VALUES(marks), max_marks=VALUES(max_marks),
                     grade=VALUES(grade), teacher_comment=VALUES(teacher_comment)"
            )->execute([$tchOrgId, $studentId, $postExam, $postClass, $postSubject, $marks, $postMax, $grade, $comment]);
            $saved++;
        } catch (Throwable $e) {}
    }
    $saveMsg = $saved > 0 ? "Results saved for $saved student(s)." : null;
    if (!$saveMsg) $saveErr = 'No changes were saved. Make sure marks are entered.';
}

// ── Load students for selected class ────────────────────────────
$students = [];
$existingResults = [];
if ($selectedClassId && $selectedExamId && $selectedSubjectId) {
    try {
        $s = $pdo->prepare(
            "SELECT id, first_name, last_name, admission_no
             FROM sch_students WHERE class_id=? AND org_id=? AND status='active'
             ORDER BY last_name, first_name"
        );
        $s->execute([$selectedClassId, $tchOrgId]);
        $students = $s->fetchAll();
    } catch (Throwable $e) {}

    try {
        $s = $pdo->prepare(
            "SELECT student_id, marks, max_marks, grade, teacher_comment
             FROM sch_results WHERE exam_id=? AND class_id=? AND subject_id=? AND org_id=?"
        );
        $s->execute([$selectedExamId, $selectedClassId, $selectedSubjectId, $tchOrgId]);
        foreach ($s->fetchAll() as $r) $existingResults[$r['student_id']] = $r;
    } catch (Throwable $e) {}
}

$gradeColors = ['A'=>'#1A8A4E','B'=>'#3498db','C'=>'#f39c12','D'=>'#e67e22','E'=>'#e74c3c','F'=>'#c0392b'];
$maxMarksDefault = (int)($selectedSection['max_marks'] ?? 100);
?>

<?php if ($saveMsg): ?>
<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?= e($saveMsg) ?></div>
<?php endif; ?>
<?php if ($saveErr): ?>
<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?= e($saveErr) ?></div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="fas fa-graduation-cap me-2" style="color:var(--tch-green)"></i>Enter Results</h5>
  <?php if (!empty($existingResults)): ?>
  <span class="badge bg-success"><?= count($existingResults) ?> result(s) already entered</span>
  <?php endif; ?>
</div>

<?php if (empty($exams)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-graduation-cap fa-3x mb-3 d-block opacity-25"></i>
    <h6>No exams assigned to your subjects yet</h6>
    <p class="small">Exams will appear here once the administrator adds them to your class-subject schedule.</p>
  </div>
</div>
<?php else: ?>

<!-- Exam selector -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <label class="fw-semibold small flex-shrink-0">Exam:</label>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($exams as $ex): ?>
        <a href="?exam=<?= $ex['id'] ?>"
           class="btn btn-sm <?= (int)$ex['id']===$selectedExamId ? 'btn-success' : 'btn-outline-secondary' ?>">
          <?= e($ex['name']) ?>
          <?php if (!empty($ex['end_date'])): ?>
          <span class="ms-1 opacity-75" style="font-size:.68rem"><?= date('Y', strtotime($ex['end_date'])) ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($examSections)): ?>

<!-- Class/Subject selector -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <label class="fw-semibold small d-block mb-2">Class &amp; Subject:</label>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($examSections as $sec): ?>
      <a href="?exam=<?= $selectedExamId ?>&class_id=<?= $sec['class_id'] ?>&subject_id=<?= $sec['subject_id'] ?>"
         class="btn btn-sm <?= ((int)$sec['class_id']===$selectedClassId && (int)$sec['subject_id']===$selectedSubjectId) ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?= e($sec['class_name']) ?> &mdash; <?= e($sec['subject_name']) ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($selectedSection && !empty($students)): ?>

<!-- Results entry form -->
<form method="POST">
  <input type="hidden" name="exam_id"    value="<?= $selectedExamId ?>">
  <input type="hidden" name="class_id"   value="<?= $selectedClassId ?>">
  <input type="hidden" name="subject_id" value="<?= $selectedSubjectId ?>">
  <input type="hidden" name="max_marks"  value="<?= $maxMarksDefault ?>">

  <div class="card border-0 shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h6 class="mb-0 fw-bold">
        <i class="fas fa-table me-2" style="color:var(--tch-green)"></i>
        <?= e($selectedSection['class_name']) ?> &mdash; <?= e($selectedSection['subject_name']) ?>
        <?php if (!empty($selectedSection['subject_code'])): ?>
        <span class="text-muted small">(<?= e($selectedSection['subject_code']) ?>)</span>
        <?php endif; ?>
      </h6>
      <div class="d-flex align-items-center gap-2">
        <label class="fw-semibold small mb-0">Max marks:</label>
        <input type="number" id="maxMarksInput" class="form-control form-control-sm" style="width:80px"
               value="<?= $maxMarksDefault ?>" min="1"
               onchange="document.querySelector('[name=max_marks]').value=this.value;updateAllGrades()">
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:36px">#</th>
            <th>Student</th>
            <th class="text-center" style="min-width:110px">Marks <span class="text-muted fw-normal" style="font-size:.75rem">/ <span id="maxHdr"><?= $maxMarksDefault ?></span></span></th>
            <th class="text-center" style="min-width:80px">%</th>
            <th class="text-center" style="min-width:70px">Grade</th>
            <th style="min-width:160px">Teacher Comment</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $i => $stu):
            $res = $existingResults[$stu['id']] ?? null;
            $existingMarks   = $res ? $res['marks'] : '';
            $existingGrade   = $res ? strtoupper($res['grade'][0] ?? '') : '';
            $existingComment = $res ? ($res['teacher_comment'] ?? '') : '';
          ?>
          <tr>
            <td class="text-muted small"><?= $i+1 ?></td>
            <td>
              <div class="fw-semibold small"><?= e($stu['first_name'] . ' ' . $stu['last_name']) ?></div>
              <div class="text-muted" style="font-size:.7rem"><?= e($stu['admission_no'] ?? '') ?></div>
            </td>
            <td class="text-center">
              <input type="number" class="form-control form-control-sm text-center marks-input"
                     name="results[<?= $stu['id'] ?>][marks]"
                     data-student="<?= $stu['id'] ?>"
                     value="<?= $existingMarks !== '' ? e($existingMarks) : '' ?>"
                     min="0" step="0.5" placeholder="&mdash;"
                     style="max-width:80px;margin:auto"
                     oninput="autoGrade(this)">
            </td>
            <td class="text-center">
              <span class="pct-cell small fw-semibold" id="pct_<?= $stu['id'] ?>">
                <?php if ($existingMarks !== '' && $maxMarksDefault > 0): ?>
                <?= round($existingMarks / $maxMarksDefault * 100) ?>%
                <?php else: ?>&mdash;<?php endif; ?>
              </span>
            </td>
            <td class="text-center">
              <input type="hidden" name="results[<?= $stu['id'] ?>][grade]" id="grade_<?= $stu['id'] ?>" value="<?= e($existingGrade) ?>">
              <span class="grade-badge badge" id="gbadge_<?= $stu['id'] ?>"
                    style="background:<?= $gradeColors[$existingGrade] ?? '#dee2e6' ?>;font-size:.78rem">
                <?= $existingGrade ?: '&mdash;' ?>
              </span>
            </td>
            <td>
              <input type="text" class="form-control form-control-sm"
                     name="results[<?= $stu['id'] ?>][comment]"
                     value="<?= e($existingComment) ?>"
                     placeholder="Optional teacher comment">
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card-footer d-flex justify-content-between align-items-center">
      <span class="small text-muted"><?= count($students) ?> students &nbsp;&middot;&nbsp; <?= count($existingResults) ?> results entered</span>
      <button type="submit" class="btn btn-success px-4">
        <i class="fas fa-save me-1"></i>Save Results
      </button>
    </div>
  </div>
</form>

<?php elseif ($selectedSection): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted"><i class="fas fa-users fa-2x mb-2 d-block opacity-25"></i><p class="small">No active students in this class.</p></div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-graduation-cap fa-3x mb-3 d-block opacity-25"></i>
    <h6>No subject schedule found for this exam</h6>
    <p class="small">Ask the administrator to add your class-subject to the exam schedule.</p>
  </div>
</div>
<?php endif; ?>

<?php endif; // exams not empty ?>

<?php
$gradeColorsJson = json_encode($gradeColors);
$extraJs = "<script>
const gradeColors = $gradeColorsJson;
function getGrade(pct){
    return pct>=80?'A':pct>=70?'B':pct>=60?'C':pct>=50?'D':pct>=40?'E':'F';
}
function autoGrade(input){
    const sid = input.dataset.student;
    const max = parseFloat(document.getElementById('maxMarksInput').value)||1;
    const val = parseFloat(input.value);
    const pctEl = document.getElementById('pct_'+sid);
    const gradeInput = document.getElementById('grade_'+sid);
    const badge = document.getElementById('gbadge_'+sid);
    if(isNaN(val)){pctEl.innerHTML='&mdash;';badge.innerHTML='&mdash;';badge.style.background='#dee2e6';return;}
    const pct = Math.round(val/max*100);
    pctEl.textContent = pct+'%';
    const g = getGrade(pct);
    gradeInput.value = g;
    badge.textContent = g;
    badge.style.background = gradeColors[g]||'#dee2e6';
}
function updateAllGrades(){
    const max = parseFloat(document.getElementById('maxMarksInput').value)||1;
    document.getElementById('maxHdr').textContent = max;
    document.querySelectorAll('input[name=max_marks]').forEach(i=>i.value=max);
    document.querySelectorAll('.marks-input').forEach(inp=>autoGrade(inp));
}
</script>";
require_once __DIR__ . '/../includes/footer-teacher.php';
?>
