<?php
$pageTitle = 'Submit Homework';
require_once __DIR__ . '/../includes/header-student.php';

$hwId = (int)($_GET['id'] ?? 0);
if (!$hwId) redirect(APP_URL . '/student/homework.php');

// ── Load homework ────────────────────────────────────────────────
$hw = null;
try {
    $s = $pdo->prepare(
        "SELECT hw.*, c.name AS class_name, sub.name AS subject_name,
                CONCAT(t.first_name,' ',t.last_name) AS teacher_name
         FROM sch_homework hw
         LEFT JOIN sch_classes c    ON c.id   = hw.class_id
         LEFT JOIN sch_subjects sub ON sub.id  = hw.subject_id
         LEFT JOIN sch_teachers t   ON t.id    = hw.teacher_id
         WHERE hw.id=? AND hw.class_id=? AND hw.org_id=? LIMIT 1"
    );
    $s->execute([$hwId, $stuClassId, $stuOrgId]);
    $hw = $s->fetch();
} catch (Throwable $e) {}

if (!$hw) {
    setFlash('error', 'Homework not found or not assigned to your class.');
    redirect(APP_URL . '/student/homework.php');
}

// ── Load questions ───────────────────────────────────────────────
$questions = [];
try {
    $s = $pdo->prepare(
        "SELECT * FROM sch_homework_questions WHERE homework_id=? AND org_id=?
         ORDER BY sort_order ASC, id ASC"
    );
    $s->execute([$hwId, $stuOrgId]);
    $questions = $s->fetchAll();
} catch (Throwable $e) {}

if (empty($questions)) {
    setFlash('error', 'This homework has no questions to answer yet.');
    redirect(APP_URL . '/student/homework.php');
}

// ── Load existing submission ─────────────────────────────────────
$submission    = null;
$myAnswers     = [];
$canSubmit     = $hw['status'] === 'active';
try {
    $s = $pdo->prepare(
        "SELECT * FROM sch_homework_submissions WHERE homework_id=? AND student_id=? AND org_id=? LIMIT 1"
    );
    $s->execute([$hwId, $stuId, $stuOrgId]);
    $submission = $s->fetch();
} catch (Throwable $e) {}

try {
    $s = $pdo->prepare(
        "SELECT * FROM sch_homework_answers WHERE homework_id=? AND student_id=? AND org_id=?"
    );
    $s->execute([$hwId, $stuId, $stuOrgId]);
    foreach ($s->fetchAll() as $a) $myAnswers[$a['question_id']] = $a;
} catch (Throwable $e) {}

$alreadySubmitted = $submission && in_array($submission['status'], ['submitted','marked']);
$isMarked         = $submission && $submission['status'] === 'marked';
$totalQMarks      = (float)array_sum(array_column($questions, 'marks'));

// ── POST: submit answers ─────────────────────────────────────────
$saveErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_answers') {
    if (!$canSubmit) {
        $saveErr = 'This homework is closed and no longer accepting submissions.';
    } elseif ($alreadySubmitted) {
        $saveErr = 'You have already submitted. Your teacher is reviewing your answers.';
    } else {
        $answers = $_POST['answers'] ?? [];
        try {
            $pdo->beginTransaction();
            foreach ($questions as $q) {
                $ansText = trim($answers[$q['id']] ?? '');
                $pdo->prepare(
                    "INSERT INTO sch_homework_answers
                     (homework_id,question_id,student_id,org_id,answer_text,submitted_at)
                     VALUES (?,?,?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE answer_text=VALUES(answer_text), submitted_at=NOW()"
                )->execute([$hwId, $q['id'], $stuId, $stuOrgId, $ansText]);
            }
            $pdo->prepare(
                "INSERT INTO sch_homework_submissions
                 (homework_id,student_id,org_id,status,submitted_at)
                 VALUES (?,?,?,'submitted',NOW())
                 ON DUPLICATE KEY UPDATE status='submitted', submitted_at=NOW()"
            )->execute([$hwId, $stuId, $stuOrgId]);
            $pdo->commit();
            setFlash('success', 'Your answers have been submitted successfully!');
            redirect(APP_URL . "/student/submit-homework.php?id=$hwId");
        } catch (Throwable $e) {
            $pdo->rollBack();
            $saveErr = 'Could not save your answers. Please try again.';
        }
    }
}

// Reload answers after submit redirect
if ($alreadySubmitted && empty($myAnswers)) {
    try {
        $s = $pdo->prepare(
            "SELECT * FROM sch_homework_answers WHERE homework_id=? AND student_id=? AND org_id=?"
        );
        $s->execute([$hwId, $stuId, $stuOrgId]);
        foreach ($s->fetchAll() as $a) $myAnswers[$a['question_id']] = $a;
    } catch (Throwable $e) {}
}
?>

<?php if ($saveErr): ?>
<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?= e($saveErr) ?></div>
<?php endif; ?>

<!-- Header -->
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <a href="<?= APP_URL ?>/student/homework.php" class="btn btn-sm btn-outline-secondary">
    <i class="fas fa-arrow-left me-1"></i>Back
  </a>
  <div class="flex-grow-1">
    <h5 class="fw-bold mb-0"><?= e($hw['title']) ?></h5>
    <div class="text-muted small">
      <?php if (!empty($hw['subject_name'])): ?>
      <i class="fas fa-book me-1"></i><?= e($hw['subject_name']) ?>
      <?php endif; ?>
      <?php if (!empty($hw['teacher_name'])): ?>
      &nbsp;&middot;&nbsp;<i class="fas fa-user-tie me-1"></i><?= e($hw['teacher_name']) ?>
      <?php endif; ?>
      <?php if (!empty($hw['due_date'])): ?>
      &nbsp;&middot;&nbsp;<i class="fas fa-calendar-times me-1"></i>Due <?= date('d M Y', strtotime($hw['due_date'])) ?>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($isMarked && $submission['marks_obtained'] !== null): ?>
  <div class="text-end">
    <div class="fw-bold" style="color:var(--stu-blue);font-size:1.1rem">
      <?= number_format($submission['marks_obtained'],1) ?> / <?= number_format($totalQMarks,1) ?>
    </div>
    <div class="text-muted" style="font-size:.72rem">Total marks</div>
  </div>
  <?php endif; ?>
</div>

<!-- Status banner -->
<?php if ($isMarked): ?>
<div class="alert border-0 shadow-sm mb-4" style="background:#f0fdf4;border-left:4px solid #1A8A4E!important">
  <div class="d-flex align-items-center gap-2">
    <i class="fas fa-check-circle" style="color:#1A8A4E;font-size:1.2rem"></i>
    <div>
      <div class="fw-semibold" style="color:#1A8A4E">Marked by Teacher</div>
      <div class="text-muted small">
        Your submission has been reviewed.
        Score: <strong><?= number_format($submission['marks_obtained'],1) ?></strong>
        / <?= number_format($totalQMarks,1) ?> marks
        <?php if ($totalQMarks>0): ?>
        (<?= round(($submission['marks_obtained']/$totalQMarks)*100) ?>%)
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php elseif ($alreadySubmitted): ?>
<div class="alert alert-primary border-0 shadow-sm mb-4">
  <i class="fas fa-clock me-2"></i>
  <strong>Submitted</strong> — your answers are awaiting review by your teacher.
  Submitted on <?= date('d M Y H:i', strtotime($submission['submitted_at'])) ?>.
</div>
<?php elseif (!$canSubmit): ?>
<div class="alert alert-secondary border-0 shadow-sm mb-4">
  <i class="fas fa-lock me-2"></i>This homework is closed and no longer accepting new submissions.
</div>
<?php endif; ?>

<!-- Instructions -->
<?php if (!empty($hw['instructions'])): ?>
<div class="card border-0 shadow-sm mb-4" style="border-left:3px solid var(--stu-blue)!important">
  <div class="card-body py-3">
    <div class="fw-semibold small text-muted mb-1">
      <i class="fas fa-info-circle me-1"></i>INSTRUCTIONS
    </div>
    <p class="mb-0 small" style="white-space:pre-wrap;line-height:1.65"><?= e($hw['instructions']) ?></p>
  </div>
</div>
<?php endif; ?>

<!-- Questions -->
<?php if ($alreadySubmitted): ?>
<!-- ── Read-only view of submitted answers ── -->
<div class="d-flex flex-column gap-3">
  <?php foreach ($questions as $qi => $q):
    $ans = $myAnswers[$q['id']] ?? null;
    $awarded = $ans['marks_awarded'] ?? null;
  ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <!-- Question -->
      <div class="d-flex gap-3 align-items-start mb-3">
        <div class="d-flex align-items-center justify-content-center fw-bold text-white rounded flex-shrink-0"
             style="width:28px;height:28px;background:var(--stu-blue);font-size:.8rem">
          <?= $qi+1 ?>
        </div>
        <div class="flex-grow-1">
          <p class="mb-1 fw-semibold"><?= nl2br(e($q['question_text'])) ?></p>
          <span class="badge bg-primary bg-opacity-25 text-primary" style="font-size:.65rem">
            <?= number_format($q['marks'],1) ?> mark<?= $q['marks']!=1?'s':'' ?>
          </span>
        </div>
        <?php if ($isMarked && $awarded !== null): ?>
        <div class="text-end flex-shrink-0">
          <div class="fw-bold" style="color:var(--stu-blue);font-size:.95rem">
            <?= number_format($awarded,1) ?>/<?= number_format($q['marks'],1) ?>
          </div>
          <div class="text-muted" style="font-size:.65rem">marks</div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Student's answer -->
      <div class="p-3 rounded mb-0"
           style="background:#f8f9fa;border-left:3px solid <?= ($ans && trim($ans['answer_text'])) ? 'var(--stu-blue)' : '#adb5bd' ?>">
        <div class="fw-semibold text-muted mb-1" style="font-size:.72rem">
          <i class="fas fa-user-edit me-1"></i>YOUR ANSWER
        </div>
        <?php if ($ans && trim($ans['answer_text'])): ?>
        <p class="mb-0 small" style="white-space:pre-wrap;line-height:1.65"><?= e($ans['answer_text']) ?></p>
        <?php else: ?>
        <p class="text-muted mb-0 small fst-italic">No answer provided for this question.</p>
        <?php endif; ?>
      </div>

      <!-- Teacher feedback -->
      <?php if ($isMarked && !empty($ans['feedback'])): ?>
      <div class="mt-2 p-2 rounded" style="background:#f0fdf4;border-left:3px solid #1A8A4E">
        <div class="fw-semibold mb-1" style="font-size:.72rem;color:#1A8A4E">
          <i class="fas fa-comment-dots me-1"></i>TEACHER FEEDBACK
        </div>
        <p class="mb-0 small"><?= e($ans['feedback']) ?></p>
      </div>
      <?php endif; ?>

    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php else: ?>
<!-- ── Submission form ── -->
<form method="POST" id="hwSubmitForm">
  <input type="hidden" name="action" value="submit_answers">

  <div class="d-flex flex-column gap-3">
    <?php foreach ($questions as $qi => $q): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="d-flex gap-3 align-items-start mb-3">
          <div class="d-flex align-items-center justify-content-center fw-bold text-white rounded flex-shrink-0"
               style="width:28px;height:28px;background:var(--stu-blue);font-size:.8rem">
            <?= $qi+1 ?>
          </div>
          <div class="flex-grow-1">
            <p class="mb-1 fw-semibold"><?= nl2br(e($q['question_text'])) ?></p>
            <span class="badge bg-primary bg-opacity-25 text-primary" style="font-size:.65rem">
              <?= number_format($q['marks'],1) ?> mark<?= $q['marks']!=1?'s':'' ?>
            </span>
          </div>
        </div>
        <textarea class="form-control"
                  name="answers[<?= $q['id'] ?>]"
                  rows="5"
                  placeholder="Write your answer here…"
                  style="resize:vertical"><?= e($myAnswers[$q['id']]['answer_text'] ?? '') ?></textarea>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="d-flex gap-3 mt-4 align-items-center">
    <button type="submit" class="btn btn-primary px-4"
            onclick="return confirm('Submit your answers? You will not be able to edit them after submission.')">
      <i class="fas fa-paper-plane me-1"></i>Submit Answers
    </button>
    <a href="<?= APP_URL ?>/student/homework.php" class="btn btn-outline-secondary">Cancel</a>
    <span class="text-muted small ms-auto">
      <?= count($questions) ?> question<?= count($questions)!==1?'s':'' ?> &mdash;
      total <?= number_format($totalQMarks,1) ?> marks
    </span>
  </div>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-student.php'; ?>
