<?php
$pageTitle = 'My Results';
require_once __DIR__ . '/../includes/header-student.php';

// ── Exams with my results ────────────────────────────────────────
$exams = [];
try {
    $s = $pdo->prepare(
        "SELECT DISTINCT e.id, e.name, e.term, e.academic_year, e.start_date, e.end_date, e.status
         FROM sch_exams e JOIN sch_results r ON r.exam_id = e.id
         WHERE r.student_id=? AND r.org_id=?
         ORDER BY e.end_date DESC"
    );
    $s->execute([$stuId, $stuOrgId]);
    $exams = $s->fetchAll();
} catch (Throwable $e) {}

$selectedExamId = (int)($_GET['exam'] ?? ($exams[0]['id'] ?? 0));
$selectedExam   = null;
foreach ($exams as $ex) { if ((int)$ex['id'] === $selectedExamId) { $selectedExam = $ex; break; } }

$results = []; $totalMarks = 0; $maxMarks = 0;
if ($selectedExamId) {
    try {
        $s = $pdo->prepare(
            "SELECT r.marks, r.max_marks, r.grade, r.teacher_comment, r.remarks,
                    sub.name AS subject_name, sub.code AS subject_code
             FROM sch_results r JOIN sch_subjects sub ON r.subject_id = sub.id
             WHERE r.student_id=? AND r.exam_id=? AND r.org_id=?
             ORDER BY sub.name ASC"
        );
        $s->execute([$stuId, $selectedExamId, $stuOrgId]);
        $results = $s->fetchAll();
        foreach ($results as $r) { $totalMarks += $r['marks']; $maxMarks += $r['max_marks']; }
    } catch (Throwable $e) {}
}

$overallPct = $maxMarks > 0 ? round($totalMarks / $maxMarks * 100, 1) : 0;
$overallGrade = $overallPct>=80?'A':($overallPct>=70?'B':($overallPct>=60?'C':($overallPct>=50?'D':($overallPct>=40?'E':'F'))));

// Class position
$classPosition = null; $classTotal = 0;
if ($selectedExamId && $stuClassId) {
    try {
        $s = $pdo->prepare(
            "SELECT student_id, SUM(marks) AS total FROM sch_results
             WHERE exam_id=? AND class_id=? AND org_id=?
             GROUP BY student_id ORDER BY total DESC"
        );
        $s->execute([$selectedExamId, $stuClassId, $stuOrgId]);
        $rankings = $s->fetchAll();
        $classTotal = count($rankings);
        foreach ($rankings as $i => $r) {
            if ((int)$r['student_id'] === $stuId) { $classPosition = $i + 1; break; }
        }
    } catch (Throwable $e) {}
}

$gradeColors = ['A'=>'#1A8A4E','B'=>'#3498db','C'=>'#f39c12','D'=>'#e67e22','E'=>'#e74c3c','F'=>'#c0392b'];
$gradeLabels = ['A'=>'Excellent','B'=>'Very Good','C'=>'Good','D'=>'Satisfactory','E'=>'Poor','F'=>'Fail'];
$overallColor = $gradeColors[$overallGrade] ?? '#6c757d';

function stuOrdinal(int $n): string {
    $s = ['th','st','nd','rd']; $v = $n % 100;
    return $n . (isset($s[$v-10]) || isset($s[$v-11]) ? 'th' : ($s[$v%10] ?? 'th'));
}
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="fas fa-graduation-cap me-2" style="color:var(--stu-blue)"></i>My Results</h5>
  <?php if ($selectedExamId): ?>
  <a href="<?= APP_URL ?>/modules/school/report-card-pdf.php?student_id=<?= $stuId ?>&exam_id=<?= $selectedExamId ?>"
     class="btn btn-sm btn-outline-primary" target="_blank">
    <i class="fas fa-print me-1"></i>Print Report Card
  </a>
  <?php endif; ?>
</div>

<?php if (empty($exams)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-graduation-cap fa-3x mb-3 d-block opacity-25"></i>
    <h6>No results available yet</h6>
    <p class="small">Your exam results will appear here once they are entered by your teachers.</p>
  </div>
</div>
<?php else: ?>

<!-- Exam selector -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <label class="fw-semibold small flex-shrink-0">Select Exam:</label>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($exams as $ex): ?>
        <a href="?exam=<?= $ex['id'] ?>"
           class="btn btn-sm <?= (int)$ex['id']===$selectedExamId ? 'btn-primary' : 'btn-outline-secondary' ?>">
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

<?php if ($selectedExam && !empty($results)): ?>

<!-- Summary cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stu-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:<?= $overallColor ?>18">
        <i class="fas fa-percent" style="color:<?= $overallColor ?>"></i>
      </div>
      <div class="fs-3 fw-bold" style="color:<?= $overallColor ?>"><?= $overallPct ?>%</div>
      <div class="text-muted small">Overall Score</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stu-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:<?= $overallColor ?>18">
        <span class="fw-bold" style="color:<?= $overallColor ?>"><?= $overallGrade ?></span>
      </div>
      <div class="fs-3 fw-bold" style="color:<?= $overallColor ?>"><?= $gradeLabels[$overallGrade] ?? '' ?></div>
      <div class="text-muted small">Overall Grade</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stu-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:#eff6ff">
        <i class="fas fa-book-open" style="color:var(--stu-blue)"></i>
      </div>
      <div class="fs-3 fw-bold text-primary"><?= count($results) ?></div>
      <div class="text-muted small">Subjects</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stu-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:#fef5e7">
        <i class="fas fa-trophy" style="color:#f39c12"></i>
      </div>
      <div class="fs-3 fw-bold text-warning">
        <?= $classPosition ? stuOrdinal($classPosition) : '&mdash;' ?>
      </div>
      <div class="text-muted small">Class Position<?= $classTotal ? " of $classTotal" : '' ?></div>
    </div>
  </div>
</div>

<!-- Exam info banner -->
<div class="card border-0 shadow-sm mb-4" style="border-left:4px solid var(--stu-blue)!important">
  <div class="card-body py-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <div class="fw-bold" style="color:var(--stu-navy)"><?= e($selectedExam['name']) ?></div>
        <div class="text-muted small">
          <?php if (!empty($selectedExam['term'])): ?><?= e($selectedExam['term']) ?> &nbsp;&middot;&nbsp;<?php endif; ?>
          <?php if (!empty($selectedExam['start_date'])): ?>
          <?= date('d M', strtotime($selectedExam['start_date'])) ?> &ndash;
          <?= date('d M Y', strtotime($selectedExam['end_date'])) ?>
          <?php endif; ?>
          <?php if (!empty($selectedExam['academic_year'])): ?>&nbsp;&middot;&nbsp;<?= e($selectedExam['academic_year']) ?><?php endif; ?>
        </div>
      </div>
      <div class="text-end">
        <div class="fw-bold"><?= $totalMarks ?> / <?= $maxMarks ?> marks</div>
        <div class="text-muted small"><?= $overallPct ?>% overall</div>
      </div>
    </div>
  </div>
</div>

<!-- Subject breakdown -->
<div class="card border-0 shadow-sm">
  <div class="card-header">
    <h6 class="mb-0 fw-bold"><i class="fas fa-table me-2" style="color:var(--stu-blue)"></i>Subject Breakdown</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Subject</th>
            <th class="text-center">Marks</th>
            <th class="text-center" style="min-width:100px">Performance</th>
            <th class="text-center">Grade</th>
            <th class="d-none d-lg-table-cell">Teacher Comment</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $r):
            $pct   = $r['max_marks'] > 0 ? round($r['marks'] / $r['max_marks'] * 100) : 0;
            $grade = strtoupper($r['grade'][0] ?? '');
            $gc    = $gradeColors[$grade] ?? '#6c757d';
            $gl    = $gradeLabels[$grade] ?? '';
          ?>
          <tr>
            <td>
              <div class="fw-semibold small"><?= e($r['subject_name']) ?></div>
              <?php if (!empty($r['subject_code'])): ?>
              <div class="text-muted" style="font-size:.68rem"><?= e($r['subject_code']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-center fw-semibold"><?= $r['marks'] ?><span class="text-muted fw-normal">/<?= $r['max_marks'] ?></span></td>
            <td>
              <div class="progress mb-1" style="height:5px">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $gc ?>"></div>
              </div>
              <div class="text-center small fw-semibold" style="color:<?= $gc ?>"><?= $pct ?>%</div>
            </td>
            <td class="text-center">
              <span class="badge" style="background:<?= $gc ?>;font-size:.75rem"><?= e($r['grade']) ?></span>
              <?php if ($gl): ?><div class="text-muted" style="font-size:.65rem"><?= $gl ?></div><?php endif; ?>
            </td>
            <td class="small text-muted d-none d-lg-table-cell">
              <?= e($r['teacher_comment'] ?? $r['remarks'] ?? '&mdash;') ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <td class="fw-bold">Total</td>
            <td class="text-center fw-bold"><?= $totalMarks ?>/<?= $maxMarks ?></td>
            <td class="text-center fw-bold" style="color:<?= $overallColor ?>"><?= $overallPct ?>%</td>
            <td class="text-center"><span class="badge" style="background:<?= $overallColor ?>"><?= $overallGrade ?></span></td>
            <td class="d-none d-lg-table-cell small text-muted">
              <?php if ($classPosition): ?>
              <i class="fas fa-trophy text-warning me-1"></i>
              Position <?= $classPosition ?> of <?= $classTotal ?>
              <?php endif; ?>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php elseif ($selectedExam): ?>
<div class="alert alert-info border-0"><i class="fas fa-info-circle me-2"></i>No results recorded for this exam yet.</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-student.php'; ?>
