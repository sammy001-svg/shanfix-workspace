<?php
$pageTitle = 'Exam Results';
require_once __DIR__ . '/../includes/header-parent.php';

// ── Fetch exams that have results for this student ──────────────
$exams = [];
try {
    $s = $pdo->prepare(
        "SELECT DISTINCT e.id, e.name, e.start_date, e.end_date, e.status
         FROM sch_exams e
         JOIN sch_results r ON r.exam_id = e.id
         WHERE r.student_id=? AND r.org_id=?
         ORDER BY e.end_date DESC"
    );
    $s->execute([$parActive, $parOrgId]);
    $exams = $s->fetchAll();
} catch (Throwable $e) {}

$selectedExamId = (int)($_GET['exam'] ?? ($exams[0]['id'] ?? 0));

// ── Fetch results for selected exam ─────────────────────────────
$results = [];
$selectedExam = null;
$totalMarks = $maxMarks = 0;

if ($selectedExamId) {
    foreach ($exams as $ex) {
        if ((int)$ex['id'] === $selectedExamId) { $selectedExam = $ex; break; }
    }
    try {
        $s = $pdo->prepare(
            "SELECT r.marks, r.max_marks, r.grade, r.predicted_grade, r.teacher_comment, r.remarks,
                    sub.name AS subject_name, sub.code AS subject_code
             FROM sch_results r
             JOIN sch_subjects sub ON r.subject_id = sub.id
             WHERE r.student_id=? AND r.exam_id=? AND r.org_id=?
             ORDER BY sub.name ASC"
        );
        $s->execute([$parActive, $selectedExamId, $parOrgId]);
        $results = $s->fetchAll();
        foreach ($results as $r) {
            $totalMarks += $r['marks'];
            $maxMarks   += $r['max_marks'];
        }
    } catch (Throwable $e) {}
}

$overallPct   = $maxMarks > 0 ? round($totalMarks / $maxMarks * 100, 1) : 0;
$gradeColors  = ['A'=>'#1A8A4E','B'=>'#3498db','C'=>'#f39c12','D'=>'#e67e22','E'=>'#e74c3c','F'=>'#c0392b'];
$gradeLabels  = ['A'=>'Excellent','B'=>'Very Good','C'=>'Good','D'=>'Satisfactory','E'=>'Poor','F'=>'Fail'];
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="fas fa-graduation-cap me-2" style="color:var(--par-green)"></i>Exam Results</h5>
  <?php if ($selectedExamId && $parActive): ?>
  <a href="<?= APP_URL ?>/modules/school/report-card-pdf.php?student_id=<?= $parActive ?>&exam_id=<?= $selectedExamId ?>&token=<?= md5($parActive . $selectedExamId . $parOrgId) ?>"
     class="btn btn-sm btn-outline-success" target="_blank">
    <i class="fas fa-print me-1"></i>Print Report Card
  </a>
  <?php endif; ?>
</div>

<?php if (empty($exams)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-graduation-cap fa-3x mb-3 d-block opacity-25"></i>
    <h6>No results available yet</h6>
    <p class="small">Results will appear here once your child's exams have been graded by their teachers.</p>
  </div>
</div>
<?php else: ?>

<!-- Exam selector -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <label class="fw-semibold small mb-0">Select Exam:</label>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($exams as $ex): ?>
        <a href="?exam=<?= $ex['id'] ?>"
           class="btn btn-sm <?= (int)$ex['id'] === $selectedExamId ? 'btn-success' : 'btn-outline-secondary' ?>">
          <?= e($ex['name']) ?>
          <span class="ms-1" style="font-size:.7rem"><?= date('Y', strtotime($ex['end_date'])) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($selectedExam && !empty($results)): ?>

<!-- Summary bar -->
<div class="card border-0 shadow-sm mb-4" style="border-left:4px solid var(--par-green)!important">
  <div class="card-body">
    <div class="row g-3 align-items-center">
      <div class="col-md-6">
        <div class="fw-700 text-navy"><?= e($selectedExam['name']) ?></div>
        <div class="text-muted small">
          <?= date('d M', strtotime($selectedExam['start_date'])) ?> –
          <?= date('d M Y', strtotime($selectedExam['end_date'])) ?>
        </div>
      </div>
      <div class="col-md-6">
        <div class="d-flex align-items-center justify-content-md-end gap-4">
          <div class="text-center">
            <div class="fw-700 fs-4" style="color:var(--par-green)"><?= $overallPct ?>%</div>
            <div class="text-muted small">Overall</div>
          </div>
          <div class="text-center">
            <div class="fw-700 fs-4"><?= $totalMarks ?>/<?= $maxMarks ?></div>
            <div class="text-muted small">Total Marks</div>
          </div>
          <div class="text-center">
            <div class="fw-700 fs-4"><?= count($results) ?></div>
            <div class="text-muted small">Subjects</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Results table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0">
        <thead class="table-light">
          <tr>
            <th>Subject</th>
            <th class="text-center">Marks</th>
            <th class="text-center">%</th>
            <th class="text-center">Grade</th>
            <th class="d-none d-md-table-cell">Teacher Comment</th>
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
              <?php if (!empty($r['subject_code'])): ?><div class="text-muted" style="font-size:.7rem"><?= e($r['subject_code']) ?></div><?php endif; ?>
            </td>
            <td class="text-center fw-semibold"><?= $r['marks'] ?><span class="text-muted">/<?= $r['max_marks'] ?></span></td>
            <td class="text-center">
              <div class="progress" style="height:6px;min-width:60px">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $gc ?>"></div>
              </div>
              <div class="small mt-1"><?= $pct ?>%</div>
            </td>
            <td class="text-center">
              <span class="badge" style="background:<?= $gc ?>;font-size:.75rem"><?= e($r['grade']) ?></span>
              <?php if ($gl): ?><div class="text-muted" style="font-size:.65rem"><?= $gl ?></div><?php endif; ?>
            </td>
            <td class="small text-muted d-none d-md-table-cell"><?= e($r['teacher_comment'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <td class="fw-700">Total</td>
            <td class="text-center fw-700"><?= $totalMarks ?>/<?= $maxMarks ?></td>
            <td class="text-center fw-700"><?= $overallPct ?>%</td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php elseif ($selectedExam): ?>
<div class="alert alert-info">No results recorded for this exam yet.</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-parent.php'; ?>
