<?php
$pageTitle = 'Online Exams';
require_once __DIR__ . '/../includes/header-student.php';

// ── Handle POST: start attempt ────────────────────────────
$saveErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'start_exam') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        // Check exam exists and is available for this student
        try {
            $s = $pdo->prepare(
                "SELECT * FROM sch_online_exams
                 WHERE id=? AND org_id=? AND status IN ('published','active')
                   AND (class_id IS NULL OR class_id=?)
                   AND (start_datetime IS NULL OR start_datetime <= NOW())
                   AND (end_datetime IS NULL OR end_datetime >= NOW())"
            );
            $s->execute([$examId, $stuOrgId, $stuClassId]);
            $exam = $s->fetch();
            if (!$exam) {
                $saveErr = 'This exam is not available.';
            } else {
                // Check existing attempt
                $s2 = $pdo->prepare("SELECT id,status FROM sch_online_exam_attempts WHERE exam_id=? AND student_id=?");
                $s2->execute([$examId, $stuId]);
                $existing = $s2->fetch();
                if ($existing) {
                    if ($existing['status'] === 'in_progress') {
                        redirect(APP_URL . "/student/take-exam.php?exam_id=$examId");
                    } else {
                        $saveErr = 'You have already completed this exam.';
                    }
                } else {
                    // Create attempt
                    $pdo->prepare("INSERT INTO sch_online_exam_attempts (exam_id,student_id,org_id,started_at,status) VALUES (?,?,?,NOW(),'in_progress')")
                        ->execute([$examId, $stuId, $stuOrgId]);
                    redirect(APP_URL . "/student/take-exam.php?exam_id=$examId");
                }
            }
        } catch (Throwable $e) {
            $saveErr = 'Could not start the exam. Please try again.';
        }
    }
}

// ── Load available exams ──────────────────────────────────
$availableExams = [];
$completedExams = [];
try {
    $s = $pdo->prepare(
        "SELECT oe.*,
                sub.name AS subject_name,
                CONCAT(t.first_name,' ',t.last_name) AS teacher_name,
                (SELECT COUNT(*) FROM sch_online_exam_questions WHERE exam_id=oe.id) AS question_count,
                att.id AS attempt_id, att.status AS attempt_status,
                att.score, att.max_score, att.percentage, att.passed, att.submitted_at
         FROM sch_online_exams oe
         LEFT JOIN sch_subjects sub ON sub.id = oe.subject_id
         LEFT JOIN sch_teachers t   ON t.id   = oe.teacher_id
         LEFT JOIN sch_online_exam_attempts att ON att.exam_id=oe.id AND att.student_id=?
         WHERE oe.org_id=?
           AND oe.status IN ('published','active','closed')
           AND (oe.class_id IS NULL OR oe.class_id=?)
         ORDER BY FIELD(oe.status,'active','published','closed'), oe.start_datetime ASC"
    );
    $s->execute([$stuId, $stuOrgId, $stuClassId]);
    foreach ($s->fetchAll() as $exam) {
        if ($exam['attempt_status'] && $exam['attempt_status'] !== 'in_progress') {
            $completedExams[] = $exam;
        } else {
            $availableExams[] = $exam;
        }
    }
} catch (Throwable $e) {}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-laptop me-2" style="color:var(--stu-blue)"></i>Online Exams</h5>
    <div class="text-muted small mt-1">Computer-based assessments and tests</div>
  </div>
</div>

<?php if ($saveErr): ?>
<div class="alert alert-danger border-0"><i class="fas fa-exclamation-circle me-2"></i><?= e($saveErr) ?></div>
<?php endif; ?>

<?php if (empty($availableExams) && empty($completedExams)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-laptop fa-3x mb-3 d-block opacity-25"></i>
    <h6>No online exams available</h6>
    <p class="small mb-0">Exams assigned to your class will appear here when published by your teacher.</p>
  </div>
</div>

<?php else: ?>

<?php if (!empty($availableExams)): ?>
<div class="mb-2" style="font-size:.7rem;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px">
  <i class="fas fa-clipboard-list me-1"></i>Available Exams
</div>
<div class="row g-3 mb-4">
  <?php foreach ($availableExams as $exam):
    $isActive     = $exam['status'] === 'active';
    $inProgress   = $exam['attempt_status'] === 'in_progress';
    $now          = time();
    $windowOpen   = !$exam['start_datetime'] || strtotime($exam['start_datetime']) <= $now;
    $windowClosed = $exam['end_datetime'] && strtotime($exam['end_datetime']) < $now;
    $canStart     = $isActive && $windowOpen && !$windowClosed;
    $isClosed     = $exam['status'] === 'closed';
  ?>
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm h-100" style="<?= $canStart && !$inProgress ? 'border-left:3px solid var(--stu-blue)!important' : '' ?>">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
          <div>
            <?php if ($inProgress): ?>
            <span class="badge bg-warning text-dark mb-1" style="font-size:.65rem"><i class="fas fa-pencil-alt me-1"></i>In Progress</span>
            <?php elseif ($isActive && $canStart): ?>
            <span class="badge bg-success mb-1" style="font-size:.65rem"><i class="fas fa-check-circle me-1"></i>Ready to Take</span>
            <?php elseif ($isClosed): ?>
            <span class="badge bg-dark mb-1" style="font-size:.65rem">Closed</span>
            <?php else: ?>
            <span class="badge bg-primary bg-opacity-25 text-primary mb-1" style="font-size:.65rem">Published</span>
            <?php endif; ?>
            <h6 class="fw-bold mb-0"><?= e($exam['title']) ?></h6>
          </div>
          <div class="rounded d-flex align-items-center justify-content-center flex-shrink-0"
               style="width:40px;height:40px;background:var(--stu-blue-pale)">
            <i class="fas fa-laptop" style="color:var(--stu-blue)"></i>
          </div>
        </div>
        <div class="row g-2 text-muted small mb-3">
          <?php if (!empty($exam['subject_name'])): ?>
          <div class="col-auto"><i class="fas fa-book me-1"></i><?= e($exam['subject_name']) ?></div>
          <?php endif; ?>
          <div class="col-auto"><i class="fas fa-clock me-1"></i><?= $exam['duration_mins'] ?> min</div>
          <div class="col-auto"><i class="fas fa-question-circle me-1"></i><?= (int)$exam['question_count'] ?> questions</div>
          <?php if ($exam['pass_marks']): ?>
          <div class="col-auto"><i class="fas fa-star me-1"></i>Pass: <?= number_format($exam['pass_marks'],0) ?>/<?= number_format($exam['total_marks'],0) ?></div>
          <?php endif; ?>
        </div>
        <?php if ($exam['start_datetime'] || $exam['end_datetime']): ?>
        <div class="d-flex gap-3 text-muted small mb-3">
          <?php if ($exam['start_datetime']): ?><span><i class="fas fa-calendar-alt me-1"></i>Opens: <?= date('d M Y H:i', strtotime($exam['start_datetime'])) ?></span><?php endif; ?>
          <?php if ($exam['end_datetime']): ?><span><i class="fas fa-calendar-times me-1"></i>Closes: <?= date('d M Y H:i', strtotime($exam['end_datetime'])) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($exam['instructions'])): ?>
        <div class="p-2 bg-light rounded small text-muted mb-3">
          <i class="fas fa-info-circle me-1"></i><?= e(mb_strimwidth($exam['instructions'], 0, 100, '…')) ?>
        </div>
        <?php endif; ?>
        <?php if ($canStart || $inProgress): ?>
        <form method="POST">
          <input type="hidden" name="action" value="start_exam">
          <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
          <button type="submit" class="btn btn-sm <?= $inProgress ? 'btn-warning text-dark' : 'btn-primary' ?>">
            <i class="fas fa-<?= $inProgress ? 'play-circle' : 'play' ?> me-1"></i>
            <?= $inProgress ? 'Continue Exam' : 'Start Exam' ?>
          </button>
        </form>
        <?php elseif (!$windowOpen): ?>
        <div class="text-muted small"><i class="fas fa-lock me-1"></i>Exam opens <?= date('d M Y H:i', strtotime($exam['start_datetime'])) ?></div>
        <?php elseif ($windowClosed): ?>
        <div class="text-muted small"><i class="fas fa-lock me-1"></i>Exam window has closed</div>
        <?php elseif ($isClosed): ?>
        <div class="text-muted small"><i class="fas fa-ban me-1"></i>This exam is closed</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($completedExams)): ?>
<div class="mb-2 mt-2" style="font-size:.7rem;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px">
  <i class="fas fa-check-double me-1"></i>Completed Exams
</div>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Exam</th>
          <th>Subject</th>
          <th>Submitted</th>
          <th class="text-center">Score</th>
          <th class="text-center">%</th>
          <th>Result</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($completedExams as $exam):
          $pct = $exam['percentage'] !== null ? (float)$exam['percentage'] : null;
        ?>
        <tr>
          <td class="fw-semibold small"><?= e($exam['title']) ?></td>
          <td class="small text-muted"><?= e($exam['subject_name'] ?? '—') ?></td>
          <td class="small text-muted"><?= $exam['submitted_at'] ? date('d M Y H:i', strtotime($exam['submitted_at'])) : '—' ?></td>
          <td class="text-center small fw-semibold">
            <?= $exam['score'] !== null ? number_format($exam['score'],1).'/'.$exam['total_marks'] : '—' ?>
          </td>
          <td class="text-center">
            <?php if ($pct !== null): ?>
            <span class="badge <?= $pct>=50?'bg-success':'bg-danger' ?> bg-opacity-25 <?= $pct>=50?'text-success':'text-danger' ?>"><?= number_format($pct,0) ?>%</span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <?php if ($exam['passed'] !== null): ?>
            <span class="badge <?= $exam['passed'] ? 'bg-success' : 'bg-danger' ?>" style="font-size:.65rem">
              <?= $exam['passed'] ? 'Passed' : 'Failed' ?>
            </span>
            <?php else: ?>
            <span class="badge bg-secondary" style="font-size:.65rem"><?= ucfirst(str_replace('_',' ',$exam['attempt_status'])) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-student.php'; ?>
