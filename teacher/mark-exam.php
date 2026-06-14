<?php
$pageTitle = 'Mark Exams';
require_once __DIR__ . '/../includes/header-teacher.php';

// ── POST: save marks for a short-answer attempt ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_attempt') {
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $qMarks    = $_POST['q_marks']    ?? [];
    $qFeedback = $_POST['q_feedback'] ?? [];
    $errMsg    = null;

    try {
        // Verify attempt belongs to a teacher's exam
        $s = $pdo->prepare(
            "SELECT att.*, oe.total_marks, oe.pass_marks
             FROM sch_online_exam_attempts att
             JOIN sch_online_exams oe ON oe.id = att.exam_id
             WHERE att.id=? AND oe.teacher_id=? AND oe.org_id=?"
        );
        $s->execute([$attemptId, $tchId, $tchOrgId]);
        $attempt = $s->fetch();
        if (!$attempt) throw new RuntimeException('Not authorised.');

        // Load all answers for this attempt
        $s2 = $pdo->prepare(
            "SELECT a.*, q.question_type, q.marks AS q_max_marks
             FROM sch_online_exam_answers a
             JOIN sch_online_exam_questions q ON q.id = a.question_id
             WHERE a.attempt_id=?"
        );
        $s2->execute([$attemptId]);
        $allAnswers = $s2->fetchAll();

        $totalScore       = 0.0;
        $hasUnmarkedEssay = false;

        foreach ($allAnswers as $ans) {
            if ($ans['question_type'] === 'short_answer') {
                $qid = $ans['question_id'];
                if (isset($qMarks[$qid])) {
                    $mk = max(0.0, min((float)$qMarks[$qid], (float)$ans['q_max_marks']));
                    $fb = trim($qFeedback[$qid] ?? '');
                    $pdo->prepare(
                        "UPDATE sch_online_exam_answers
                         SET marks_awarded=?, feedback=?, marked_by=?, marked_at=NOW()
                         WHERE attempt_id=? AND question_id=?"
                    )->execute([$mk, $fb, $tchId, $attemptId, $qid]);
                    $totalScore += $mk;
                } else {
                    $totalScore += (float)($ans['marks_awarded'] ?? 0);
                    $hasUnmarkedEssay = true;
                }
            } else {
                // MCQ / T-F already auto-graded
                $totalScore += (float)($ans['marks_awarded'] ?? 0);
            }
        }

        $maxScore = (float)$attempt['total_marks'];
        $pct      = $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : null;
        $passed   = ($attempt['pass_marks'] !== null && $maxScore > 0)
                    ? ($totalScore >= (float)$attempt['pass_marks'] ? 1 : 0)
                    : null;
        $status   = $hasUnmarkedEssay ? 'submitted' : 'graded';

        $pdo->prepare(
            "UPDATE sch_online_exam_attempts
             SET score=?, max_score=?, percentage=?, passed=?, status=?, submitted_at=COALESCE(submitted_at,NOW())
             WHERE id=?"
        )->execute([$totalScore, $maxScore, $pct, $passed, $status, $attemptId]);

        setFlash('success', 'Marks saved.' . ($status==='graded' ? ' Attempt fully graded.' : ' Some essay questions still pending.'));
    } catch (Throwable $e) {
        setFlash('error', 'Could not save marks: ' . $e->getMessage());
    }
    $examId = (int)($_POST['exam_id'] ?? 0);
    redirect("mark-exam.php?exam=$examId");
}

// ── Route params ─────────────────────────────────────────────────
$viewExam    = (int)($_GET['exam']    ?? 0);
$viewAttempt = (int)($_GET['attempt'] ?? 0);

// ── Load exams that have short-answer questions & submitted attempts ─
$examList = [];
if (!$viewExam) {
    try {
        $s = $pdo->prepare(
            "SELECT oe.*,
                    sub.name AS subject_name,
                    c.name   AS class_name,
                    (SELECT COUNT(DISTINCT q.id) FROM sch_online_exam_questions q
                     WHERE q.exam_id=oe.id AND q.question_type='short_answer') AS essay_count,
                    (SELECT COUNT(*) FROM sch_online_exam_attempts att
                     WHERE att.exam_id=oe.id AND att.status='submitted') AS pending_count
             FROM sch_online_exams oe
             LEFT JOIN sch_subjects sub ON sub.id = oe.subject_id
             LEFT JOIN sch_classes  c   ON c.id   = oe.class_id
             WHERE oe.org_id=? AND oe.teacher_id=?
             HAVING essay_count > 0
             ORDER BY pending_count DESC, oe.title ASC"
        );
        $s->execute([$tchOrgId, $tchId]);
        $examList = $s->fetchAll();
    } catch (Throwable $e) {}
}

// ── Load exam detail + attempts list ─────────────────────────────
$examDetail  = null;
$essayQs     = [];
$attemptList = [];
if ($viewExam && !$viewAttempt) {
    try {
        $s = $pdo->prepare(
            "SELECT oe.*, sub.name AS subject_name, c.name AS class_name
             FROM sch_online_exams oe
             LEFT JOIN sch_subjects sub ON sub.id = oe.subject_id
             LEFT JOIN sch_classes  c   ON c.id   = oe.class_id
             WHERE oe.id=? AND oe.teacher_id=? AND oe.org_id=? LIMIT 1"
        );
        $s->execute([$viewExam, $tchId, $tchOrgId]);
        $examDetail = $s->fetch();
    } catch (Throwable $e) {}
    if (!$examDetail) redirect('mark-exam.php');

    try {
        $s = $pdo->prepare(
            "SELECT att.*,
                    CONCAT(st.first_name,' ',st.last_name) AS student_name,
                    st.admission_no,
                    (SELECT COUNT(*) FROM sch_online_exam_answers a
                     JOIN sch_online_exam_questions q ON q.id=a.question_id
                     WHERE a.attempt_id=att.id AND q.question_type='short_answer'
                       AND (a.marks_awarded IS NULL OR a.marked_at IS NULL)) AS ungraded_essay_count
             FROM sch_online_exam_attempts att
             JOIN sch_students st ON st.id = att.student_id
             WHERE att.exam_id=? AND att.org_id=? AND att.status IN ('submitted','graded')
             ORDER BY att.status ASC, att.submitted_at ASC"
        );
        $s->execute([$viewExam, $tchOrgId]);
        $attemptList = $s->fetchAll();
    } catch (Throwable $e) {}
}

// ── Load individual attempt for marking ──────────────────────────
$attempt     = null;
$studentInfo = null;
$qaRows      = [];
if ($viewAttempt) {
    try {
        $s = $pdo->prepare(
            "SELECT att.*, oe.title AS exam_title, oe.total_marks, oe.pass_marks,
                    CONCAT(st.first_name,' ',st.last_name) AS student_name,
                    st.admission_no
             FROM sch_online_exam_attempts att
             JOIN sch_online_exams oe ON oe.id = att.exam_id
             JOIN sch_students    st  ON st.id  = att.student_id
             WHERE att.id=? AND oe.teacher_id=? AND oe.org_id=? LIMIT 1"
        );
        $s->execute([$viewAttempt, $tchId, $tchOrgId]);
        $attempt = $s->fetch();
    } catch (Throwable $e) {}
    if (!$attempt) redirect('mark-exam.php');

    try {
        $s = $pdo->prepare(
            "SELECT q.id AS qid, q.question_text, q.question_type, q.marks AS q_max_marks,
                    a.id AS aid, a.selected_option_id, a.answer_text,
                    a.marks_awarded, a.feedback, a.is_correct, a.marked_at
             FROM sch_online_exam_questions q
             LEFT JOIN sch_online_exam_answers a
                    ON a.question_id=q.id AND a.attempt_id=?
             WHERE q.exam_id=?
             ORDER BY q.id ASC"
        );
        $s->execute([$viewAttempt, $attempt['exam_id']]);
        $qaRows = $s->fetchAll();
    } catch (Throwable $e) {}
}
?>

<?php if (!$viewExam && !$viewAttempt): ?>
<!-- ═══════════════════════════════════════════════════════════
     EXAM LIST — pick an exam to grade
══════════════════════════════════════════════════════════════ -->

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0">
      <i class="fas fa-pen-to-square me-2" style="color:var(--tch-green)"></i>Mark Online Exams
    </h5>
    <div class="text-muted small mt-1">
      Grade student essay responses for your online exams
    </div>
  </div>
</div>

<?php if (empty($examList)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-check-double fa-3x mb-3 d-block opacity-25"></i>
    <h6>No exams need grading</h6>
    <p class="small mb-0">
      Short-answer questions in your exams will appear here when students submit.
    </p>
  </div>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($examList as $exam): ?>
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm h-100"
         style="border-left:4px solid <?= (int)$exam['pending_count']>0 ? 'var(--tch-green)' : '#adb5bd' ?>!important">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
          <div>
            <h6 class="fw-bold mb-1"><?= e($exam['title']) ?></h6>
            <div class="text-muted small">
              <?php if (!empty($exam['subject_name'])): ?>
              <i class="fas fa-book me-1"></i><?= e($exam['subject_name']) ?>
              <?php endif; ?>
              <?php if (!empty($exam['class_name'])): ?>
              &nbsp;&middot; <i class="fas fa-chalkboard me-1"></i><?= e($exam['class_name']) ?>
              <?php endif; ?>
            </div>
          </div>
          <?php if ((int)$exam['pending_count'] > 0): ?>
          <span class="badge bg-danger flex-shrink-0" style="font-size:.68rem">
            <?= (int)$exam['pending_count'] ?> to grade
          </span>
          <?php else: ?>
          <span class="badge bg-success flex-shrink-0" style="font-size:.68rem">All graded</span>
          <?php endif; ?>
        </div>

        <div class="d-flex gap-3 text-muted small mb-3">
          <span><i class="fas fa-pen me-1"></i><?= (int)$exam['essay_count'] ?> essay question<?= (int)$exam['essay_count']!==1?'s':'' ?></span>
          <span><i class="fas fa-users me-1"></i><?= (int)$exam['pending_count'] ?> pending</span>
        </div>

        <a href="mark-exam.php?exam=<?= $exam['id'] ?>"
           class="btn btn-sm <?= (int)$exam['pending_count']>0 ? 'btn-success' : 'btn-outline-secondary' ?>">
          <i class="fas fa-list me-1"></i>View Submissions
        </a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($viewExam && $examDetail && !$viewAttempt): ?>
<!-- ═══════════════════════════════════════════════════════════
     ATTEMPT LIST for one exam
══════════════════════════════════════════════════════════════ -->

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <a href="mark-exam.php" class="btn btn-sm btn-outline-secondary">
    <i class="fas fa-arrow-left me-1"></i>All Exams
  </a>
  <div>
    <h5 class="fw-bold mb-0"><?= e($examDetail['title']) ?></h5>
    <div class="text-muted small">
      <?php if (!empty($examDetail['subject_name'])): ?>
      <i class="fas fa-book me-1"></i><?= e($examDetail['subject_name']) ?>
      <?php endif; ?>
      <?php if (!empty($examDetail['class_name'])): ?>
      &nbsp;&middot; <i class="fas fa-chalkboard me-1"></i><?= e($examDetail['class_name']) ?>
      <?php endif; ?>
      &nbsp;&middot; Total: <?= number_format($examDetail['total_marks'],1) ?> marks
    </div>
  </div>
</div>

<?php
  $pendingCount = count(array_filter($attemptList, fn($a)=>(int)$a['ungraded_essay_count']>0));
  $gradedCount  = count($attemptList) - $pendingCount;
?>
<div class="d-flex gap-3 text-muted small mb-3">
  <span><i class="fas fa-clock me-1 text-danger"></i><?= $pendingCount ?> pending</span>
  <span><i class="fas fa-check-circle me-1 text-success"></i><?= $gradedCount ?> fully graded</span>
</div>

<?php if (empty($attemptList)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>
    <h6>No submitted attempts yet</h6>
    <p class="small mb-0">Students who submit this exam will appear here.</p>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Student</th>
          <th>Adm No</th>
          <th>Submitted</th>
          <th class="text-center">Score</th>
          <th class="text-center">Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($attemptList as $att):
          $needsGrading = (int)$att['ungraded_essay_count'] > 0;
          $pct = $att['percentage'] !== null ? (float)$att['percentage'] : null;
        ?>
        <tr>
          <td class="fw-semibold small"><?= e($att['student_name']) ?></td>
          <td class="small text-muted"><?= e($att['admission_no'] ?? '—') ?></td>
          <td class="small text-muted">
            <?= $att['submitted_at'] ? date('d M Y H:i', strtotime($att['submitted_at'])) : '—' ?>
          </td>
          <td class="text-center small">
            <?php if ($att['score'] !== null): ?>
            <strong><?= number_format($att['score'],1) ?></strong> / <?= number_format($att['total_marks'],1) ?>
            <?php if ($pct !== null): ?>
            <br><span class="badge <?= $pct>=50?'bg-success':'bg-danger' ?> bg-opacity-25 <?= $pct>=50?'text-success':'text-danger' ?>"><?= number_format($pct,0) ?>%</span>
            <?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($needsGrading): ?>
            <span class="badge bg-warning text-dark" style="font-size:.62rem">
              <i class="fas fa-pen me-1"></i>Needs Grading
            </span>
            <?php else: ?>
            <span class="badge bg-success" style="font-size:.62rem">
              <i class="fas fa-check me-1"></i>Graded
            </span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a href="mark-exam.php?exam=<?= $viewExam ?>&attempt=<?= $att['id'] ?>"
               class="btn btn-sm <?= $needsGrading ? 'btn-success' : 'btn-outline-secondary' ?>">
              <i class="fas fa-<?= $needsGrading ? 'pen' : 'eye' ?> me-1"></i>
              <?= $needsGrading ? 'Grade' : 'Review' ?>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php elseif ($viewAttempt && $attempt): ?>
<!-- ═══════════════════════════════════════════════════════════
     MARKING FORM for one attempt
══════════════════════════════════════════════════════════════ -->

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <a href="mark-exam.php?exam=<?= $attempt['exam_id'] ?>" class="btn btn-sm btn-outline-secondary">
    <i class="fas fa-arrow-left me-1"></i>All Students
  </a>
  <div>
    <h5 class="fw-bold mb-0">
      <i class="fas fa-user-pen me-1" style="color:var(--tch-green)"></i>
      <?= e($attempt['student_name']) ?>
    </h5>
    <div class="text-muted small">
      <?= e($attempt['exam_title']) ?>
      &nbsp;&middot;&nbsp;Adm: <?= e($attempt['admission_no'] ?? '—') ?>
      <?php if ($attempt['submitted_at']): ?>
      &nbsp;&middot;&nbsp;Submitted <?= date('d M Y H:i', strtotime($attempt['submitted_at'])) ?>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($attempt['score'] !== null): ?>
  <div class="ms-auto text-end">
    <div class="fw-bold" style="color:var(--tch-green);font-size:1.1rem">
      <?= number_format($attempt['score'],1) ?> / <?= number_format($attempt['total_marks'],1) ?>
    </div>
    <div class="text-muted" style="font-size:.72rem">Current score</div>
  </div>
  <?php endif; ?>
</div>

<form method="POST">
  <input type="hidden" name="action"     value="mark_attempt">
  <input type="hidden" name="attempt_id" value="<?= $viewAttempt ?>">
  <input type="hidden" name="exam_id"    value="<?= $attempt['exam_id'] ?>">

  <?php $qNum = 0; foreach ($qaRows as $row): $qNum++; ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">

      <!-- Question header -->
      <div class="d-flex gap-3 align-items-start mb-3">
        <div class="d-flex align-items-center justify-content-center fw-bold text-white rounded flex-shrink-0"
             style="width:28px;height:28px;background:var(--tch-green);font-size:.78rem">
          <?= $qNum ?>
        </div>
        <div class="flex-grow-1">
          <p class="mb-1 fw-semibold"><?= nl2br(e($row['question_text'])) ?></p>
          <div class="d-flex gap-2 align-items-center">
            <?php
              $typeBg = [
                  'mcq'          => 'primary',
                  'true_false'   => 'info',
                  'short_answer' => 'warning',
              ][$row['question_type']] ?? 'secondary';
              $typeLabel = [
                  'mcq'          => 'MCQ',
                  'true_false'   => 'True / False',
                  'short_answer' => 'Short Answer',
              ][$row['question_type']] ?? $row['question_type'];
            ?>
            <span class="badge bg-<?= $typeBg ?> bg-opacity-25 text-<?= $typeBg ?>" style="font-size:.62rem">
              <?= $typeLabel ?>
            </span>
            <span class="badge bg-secondary" style="font-size:.62rem">
              Max: <?= number_format($row['q_max_marks'],1) ?> marks
            </span>
          </div>
        </div>
        <!-- Auto-graded result indicator -->
        <?php if ($row['question_type'] !== 'short_answer' && $row['marks_awarded'] !== null): ?>
        <div class="text-end flex-shrink-0">
          <div class="fw-bold <?= $row['is_correct'] ? 'text-success' : 'text-danger' ?>"
               style="font-size:.9rem">
            <?= number_format($row['marks_awarded'],1) ?> / <?= number_format($row['q_max_marks'],1) ?>
          </div>
          <div style="font-size:.65rem;color:<?= $row['is_correct'] ? '#1A8A4E' : '#e74c3c' ?>">
            <?= $row['is_correct'] ? 'Correct' : 'Incorrect' ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($row['question_type'] === 'short_answer'): ?>
      <!-- Essay answer — needs teacher marking -->
      <div class="p-3 rounded mb-3"
           style="background:#f8f9fa;border-left:3px solid <?= trim($row['answer_text']??'') ? 'var(--tch-green)' : '#adb5bd' ?>">
        <div class="fw-semibold text-muted mb-1" style="font-size:.72rem">
          <i class="fas fa-user-edit me-1"></i>STUDENT'S ANSWER
        </div>
        <?php if (trim($row['answer_text'] ?? '')): ?>
        <p class="mb-0 small" style="white-space:pre-wrap;line-height:1.65"><?= e($row['answer_text']) ?></p>
        <?php else: ?>
        <p class="text-muted mb-0 small fst-italic">No answer provided.</p>
        <?php endif; ?>
      </div>
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label fw-semibold small">
            Marks Awarded
            <span class="text-muted fw-normal">(max <?= number_format($row['q_max_marks'],1) ?>)</span>
          </label>
          <input type="number"
                 class="form-control form-control-sm"
                 name="q_marks[<?= $row['qid'] ?>]"
                 value="<?= $row['marks_awarded'] ?? 0 ?>"
                 min="0" max="<?= $row['q_max_marks'] ?>" step="0.5" required>
        </div>
        <div class="col-md-9">
          <label class="form-label fw-semibold small">Feedback (optional)</label>
          <input type="text"
                 class="form-control form-control-sm"
                 name="q_feedback[<?= $row['qid'] ?>]"
                 value="<?= e($row['feedback'] ?? '') ?>"
                 placeholder="Brief comment visible to the student…">
        </div>
      </div>
      <?php else: ?>
      <!-- MCQ / T-F — auto-graded, read-only -->
      <div class="p-3 rounded"
           style="background:#f8f9fa">
        <div class="fw-semibold text-muted mb-1" style="font-size:.72rem">
          <i class="fas fa-check-double me-1"></i>AUTO-GRADED
        </div>
        <div class="small text-muted">
          This question was automatically graded when the student submitted.
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
  <?php endforeach; ?>

  <div class="d-flex gap-2 mt-1 mb-4">
    <button type="submit" class="btn btn-success px-4">
      <i class="fas fa-save me-1"></i>Save Marks &amp; Update Score
    </button>
    <a href="mark-exam.php?exam=<?= $attempt['exam_id'] ?>" class="btn btn-outline-secondary">
      Cancel
    </a>
  </div>
</form>

<?php endif; // routing ?>

<?php require_once __DIR__ . '/../includes/footer-teacher.php'; ?>
