<?php
/**
 * Student — Take Online Exam
 * Standalone page (no sidebar) for focused exam-taking experience.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
sendSecurityHeaders();

// ── Auth guard ────────────────────────────────────────────
$_stuOrgSlug  = $_SESSION['stu_org_slug'] ?? null;
$_stuLoginUrl = APP_URL . '/student/login.php' . ($_stuOrgSlug ? '?org=' . rawurlencode($_stuOrgSlug) : '');
if (empty($_SESSION['stu_id']) || empty($_SESSION['stu_org_id'])) redirect($_stuLoginUrl);
if (isset($_SESSION['stu_last_act']) && (time() - $_SESSION['stu_last_act']) > SESSION_LIFETIME) {
    $__slug = $_SESSION['stu_org_slug'] ?? null;
    session_unset(); session_destroy();
    redirect(APP_URL . '/student/login.php' . ($__slug ? '?org=' . rawurlencode($__slug) . '&expired=1' : '?expired=1'));
}
$_SESSION['stu_last_act'] = time();

$stuId    = (int)$_SESSION['stu_id'];
$stuOrgId = (int)$_SESSION['stu_org_id'];
$stuName  = $_SESSION['stu_name'] ?? 'Student';
$stuClassId = (int)($_SESSION['stu_class_id'] ?? 0);
$schoolName = $_SESSION['stu_org_name'] ?? APP_NAME;

$examId = (int)($_GET['exam_id'] ?? 0);
if (!$examId) redirect(APP_URL . '/student/online-exams.php');

// ── Load exam ─────────────────────────────────────────────
$exam = null;
try {
    $s = $pdo->prepare(
        "SELECT * FROM sch_online_exams
         WHERE id=? AND org_id=? AND status IN ('published','active')
           AND (class_id IS NULL OR class_id=?)"
    );
    $s->execute([$examId, $stuOrgId, $stuClassId]);
    $exam = $s->fetch();
} catch (Throwable $e) {}
if (!$exam) {
    redirect(APP_URL . '/student/online-exams.php');
}

// ── Load or create attempt ────────────────────────────────
$attempt = null;
try {
    $s = $pdo->prepare("SELECT * FROM sch_online_exam_attempts WHERE exam_id=? AND student_id=?");
    $s->execute([$examId, $stuId]);
    $attempt = $s->fetch();
} catch (Throwable $e) {}

if (!$attempt) redirect(APP_URL . '/student/online-exams.php');
if ($attempt['status'] !== 'in_progress') {
    redirect(APP_URL . '/student/online-exams.php');
}

// ── Handle submit ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_exam') {
    $answers = $_POST['answers'] ?? [];

    // Load questions with correct options
    $questions = [];
    try {
        $s = $pdo->prepare("SELECT * FROM sch_online_exam_questions WHERE exam_id=? ORDER BY sort_order,id");
        $s->execute([$examId]);
        $qs = $s->fetchAll();
        foreach ($qs as $q) {
            $opts = [];
            $s2 = $pdo->prepare("SELECT * FROM sch_online_exam_options WHERE question_id=? ORDER BY sort_order");
            $s2->execute([$q['id']]); $opts = $s2->fetchAll();
            $questions[] = array_merge($q, ['options' => $opts]);
        }
    } catch (Throwable $e) {}

    $totalScore = 0.0;
    $maxScore   = 0.0;
    foreach ($questions as $q) {
        $maxScore += (float)$q['marks'];
        $qid  = $q['id'];
        $type = $q['question_type'];
        $selectedOptId = null;
        $textAnswer    = null;
        $isCorrect     = null;
        $marksAwarded  = 0.0;

        if ($type === 'mcq' || $type === 'true_false') {
            $selectedOptId = !empty($answers[$qid]) ? (int)$answers[$qid] : null;
            if ($selectedOptId) {
                foreach ($q['options'] as $opt) {
                    if ((int)$opt['id'] === $selectedOptId) {
                        $isCorrect = (bool)$opt['is_correct'];
                        $marksAwarded = $isCorrect ? (float)$q['marks'] : 0.0;
                        break;
                    }
                }
            } else {
                $isCorrect = false;
            }
        } else {
            $textAnswer = trim($answers[$qid] ?? '');
            // short_answer: null until teacher grades
            $isCorrect = null; $marksAwarded = null;
        }

        if ($isCorrect) $totalScore += $marksAwarded;

        try {
            $pdo->prepare(
                "INSERT INTO sch_online_exam_answers (attempt_id,question_id,selected_option_id,text_answer,is_correct,marks_awarded)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE selected_option_id=VALUES(selected_option_id),text_answer=VALUES(text_answer),is_correct=VALUES(is_correct),marks_awarded=VALUES(marks_awarded)"
            )->execute([$attempt['id'], $qid, $selectedOptId, $textAnswer, $isCorrect, $marksAwarded]);
        } catch (Throwable $e) {}
    }

    $hasEssay   = !empty(array_filter($questions, fn($q) => $q['question_type'] === 'short_answer'));
    $finalStatus = $hasEssay ? 'submitted' : 'graded';
    $pct         = $maxScore > 0 ? round($totalScore / $maxScore * 100, 2) : 0;
    $passMarks   = (float)($exam['pass_marks'] ?? 0);
    $passed      = $passMarks > 0 ? ($totalScore >= $passMarks ? 1 : 0) : null;
    $startedAt   = strtotime($attempt['started_at']);
    $timeTaken   = max(1, (int)round((time() - $startedAt) / 60));

    try {
        $pdo->prepare(
            "UPDATE sch_online_exam_attempts SET submitted_at=NOW(),time_taken_mins=?,score=?,max_score=?,percentage=?,passed=?,status=? WHERE id=?"
        )->execute([$timeTaken, $hasEssay ? null : $totalScore, $maxScore, $hasEssay ? null : $pct, $passed, $finalStatus, $attempt['id']]);
    } catch (Throwable $e) {}

    redirect(APP_URL . '/student/take-exam.php?exam_id=' . $examId . '&result=1');
}

// ── Review / Result mode ──────────────────────────────────
$resultMode = !empty($_GET['result']) && $attempt['status'] !== 'in_progress';
if ($resultMode) {
    // Re-fetch updated attempt
    try {
        $s = $pdo->prepare("SELECT * FROM sch_online_exam_attempts WHERE id=?");
        $s->execute([$attempt['id']]); $attempt = $s->fetch();
    } catch (Throwable $e) {}
}

// ── Load questions (for taking or reviewing) ──────────────
$questions = [];
try {
    $s = $pdo->prepare("SELECT * FROM sch_online_exam_questions WHERE exam_id=? ORDER BY sort_order,id");
    $s->execute([$examId]);
    $qs = $s->fetchAll();
    foreach ($qs as $q) {
        $opts = [];
        $s2 = $pdo->prepare("SELECT * FROM sch_online_exam_options WHERE question_id=? ORDER BY sort_order");
        $s2->execute([$q['id']]); $opts = $s2->fetchAll();
        $questions[] = array_merge($q, ['options' => $opts]);
    }
} catch (Throwable $e) {}

// Load student's answers (for review mode)
$myAnswers = [];
if ($resultMode) {
    try {
        $s = $pdo->prepare("SELECT * FROM sch_online_exam_answers WHERE attempt_id=?");
        $s->execute([$attempt['id']]);
        foreach ($s->fetchAll() as $a) $myAnswers[$a['question_id']] = $a;
    } catch (Throwable $e) {}
}

$durationSecs = $exam['duration_mins'] * 60;
$startedAt    = strtotime($attempt['started_at']);
$elapsed      = time() - $startedAt;
$remaining    = max(0, $durationSecs - $elapsed);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($exam['title']) ?> — <?= e($schoolName) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
:root { --stu-blue:#1d4ed8; --stu-blue-dark:#1e3a8a; }
body { background:#f4f6f9; }
#examTopbar {
  background:#fff; border-bottom:1px solid #e9ecef;
  padding:.65rem 1.5rem; position:sticky; top:0; z-index:100;
  display:flex; align-items:center; justify-content:space-between;
}
#timer {
  font-size:1.25rem; font-weight:700; font-variant-numeric:tabular-nums;
  color:var(--stu-blue);
}
#timer.warning { color:#e67e22; }
#timer.danger  { color:#e74c3c; animation:pulse .8s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.6} }
.question-card { background:#fff; border-radius:12px; box-shadow:0 1px 4px rgba(0,0,0,.08); margin-bottom:1.25rem; overflow:hidden; }
.question-header { padding:.75rem 1.25rem; background:#f8f9fa; border-bottom:1px solid #f1f3f5; }
.question-body { padding:1.25rem; }
.option-label {
  display:flex; align-items:center; gap:.75rem;
  padding:.65rem 1rem; border-radius:8px; cursor:pointer;
  border:2px solid #e9ecef; transition:all .15s; margin-bottom:.5rem;
}
.option-label:hover { border-color:var(--stu-blue); background:#eff6ff; }
.option-label.selected { border-color:var(--stu-blue); background:#eff6ff; }
.option-label.correct  { border-color:#27ae60; background:#f0fdf4; }
.option-label.wrong    { border-color:#e74c3c; background:#fef2f2; }
.option-label.missed   { border-color:#27ae60; background:#f0fdf4; border-style:dashed; }
</style>
</head>
<body>

<!-- Top bar -->
<div id="examTopbar">
  <div class="d-flex align-items-center gap-3">
    <div class="d-flex align-items-center justify-content-center rounded" style="width:36px;height:36px;background:#eff6ff">
      <i class="fas fa-laptop" style="color:var(--stu-blue)"></i>
    </div>
    <div>
      <div class="fw-bold small" style="color:var(--stu-blue-dark);line-height:1.2"><?= e($exam['title']) ?></div>
      <div class="text-muted" style="font-size:.72rem"><?= e($stuName) ?></div>
    </div>
  </div>
  <div class="d-flex align-items-center gap-4">
    <div class="text-center">
      <div class="text-muted" style="font-size:.65rem;text-transform:uppercase;letter-spacing:.5px">Questions</div>
      <div class="fw-bold small"><?= count($questions) ?></div>
    </div>
    <?php if (!$resultMode): ?>
    <div class="text-center">
      <div class="text-muted" style="font-size:.65rem;text-transform:uppercase;letter-spacing:.5px">Time Left</div>
      <div id="timer"><?= gmdate('H:i:s', $remaining) ?></div>
    </div>
    <?php else: ?>
    <div class="text-center">
      <div class="text-muted" style="font-size:.65rem">Score</div>
      <div class="fw-bold" style="color:<?= ($attempt['percentage']??0)>=50?'#27ae60':'#e74c3c' ?>">
        <?= $attempt['score'] !== null ? number_format($attempt['score'],0).'/'.$exam['total_marks'] : 'Pending' ?>
      </div>
    </div>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/student/online-exams.php" class="btn btn-sm btn-outline-secondary">
      <i class="fas fa-arrow-left me-1"></i>Back
    </a>
  </div>
</div>

<div class="container py-4" style="max-width:780px">

<?php if ($resultMode): ?>
<!-- ── Result summary ─────────────────────────────────────── -->
<?php
  $pct = (float)($attempt['percentage'] ?? 0);
  $passed = $attempt['passed'];
?>
<div class="card border-0 shadow-sm mb-4 text-center py-4">
  <div class="card-body">
    <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
         style="width:80px;height:80px;background:<?= $pct>=50?'#f0fdf4':'#fef2f2' ?>">
      <i class="fas <?= $pct>=50?'fa-check-circle text-success':'fa-times-circle text-danger' ?>" style="font-size:2rem"></i>
    </div>
    <h4 class="fw-bold mb-1"><?= $attempt['score'] !== null ? number_format($attempt['score'],1) . ' / ' . number_format($exam['total_marks'],1) : 'Awaiting Grading' ?></h4>
    <?php if ($attempt['score'] !== null): ?>
    <div class="fs-5 fw-bold mb-2" style="color:<?= $pct>=50?'#27ae60':'#e74c3c' ?>"><?= number_format($pct,1) ?>%</div>
    <?php endif; ?>
    <?php if ($passed !== null): ?>
    <span class="badge fs-6 <?= $passed?'bg-success':'bg-danger' ?>"><?= $passed?'Passed':'Failed' ?></span>
    <?php endif; ?>
    <?php if ($attempt['time_taken_mins']): ?>
    <div class="text-muted small mt-2"><i class="fas fa-clock me-1"></i>Completed in <?= $attempt['time_taken_mins'] ?> min</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($exam['allow_review'] && !empty($myAnswers)): ?>
<div class="mb-3 fw-bold small text-muted text-uppercase" style="letter-spacing:.5px">
  <i class="fas fa-search me-1"></i>Review Your Answers
</div>
<?php endif; ?>

<?php endif; /* end resultMode header */ ?>

<!-- ── Questions ──────────────────────────────────────────── -->
<?php if (!$resultMode): ?>
<form method="POST" id="examForm">
  <input type="hidden" name="action" value="submit_exam">
  <input type="hidden" name="exam_id" value="<?= $examId ?>">
<?php endif; ?>

<?php foreach ($questions as $qi => $q):
  $myAns     = $myAnswers[$q['id']] ?? null;
  $myOptId   = $myAns ? (int)$myAns['selected_option_id'] : null;
  $myText    = $myAns['text_answer'] ?? '';
  $isEssay   = $q['question_type'] === 'short_answer';
?>
<div class="question-card">
  <div class="question-header d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <span class="badge text-white" style="background:var(--stu-blue)"><?= $qi+1 ?></span>
      <span class="small text-muted"><?= $q['question_type']==='mcq'?'Multiple Choice':($q['question_type']==='true_false'?'True / False':'Essay') ?></span>
    </div>
    <span class="small fw-semibold text-muted"><?= number_format($q['marks'],1) ?> mark<?= $q['marks']!=1?'s':'' ?></span>
  </div>
  <div class="question-body">
    <p class="fw-semibold mb-3"><?= nl2br(e($q['question_text'])) ?></p>

    <?php if (!$isEssay): ?>
    <?php foreach ($q['options'] as $opt):
      $optId = (int)$opt['id'];
      $isSelected = $myOptId === $optId;
      $isCorrect  = (bool)$opt['is_correct'];
      $labelClass = '';
      if ($resultMode) {
          if ($isSelected && $isCorrect)  $labelClass = 'correct';
          elseif ($isSelected && !$isCorrect) $labelClass = 'wrong';
          elseif (!$isSelected && $isCorrect) $labelClass = 'missed';
      } elseif ($isSelected) { $labelClass = 'selected'; }
    ?>
    <?php if ($resultMode): ?>
    <div class="option-label <?= $labelClass ?>">
      <i class="fas <?= $isCorrect ? 'fa-check-circle text-success' : ($isSelected && !$isCorrect ? 'fa-times-circle text-danger' : 'fa-circle text-muted') ?>" style="font-size:.85rem"></i>
      <span class="small"><?= e($opt['option_text']) ?></span>
      <?php if ($isSelected && !$isCorrect): ?><span class="text-danger ms-auto small">Your answer</span><?php endif; ?>
      <?php if ($isCorrect): ?><span class="text-success ms-auto small">Correct</span><?php endif; ?>
    </div>
    <?php else: ?>
    <label class="option-label <?= $isSelected ? 'selected' : '' ?>">
      <input type="radio" name="answers[<?= $q['id'] ?>]" value="<?= $optId ?>" <?= $isSelected?'checked':'' ?> style="display:none"
             onchange="this.closest('.question-body').querySelectorAll('.option-label').forEach(l=>l.classList.remove('selected'));this.closest('.option-label').classList.add('selected')">
      <div class="d-flex align-items-center justify-content-center rounded-circle border flex-shrink-0"
           style="width:22px;height:22px;font-size:.75rem;font-weight:700"><?= chr(65+array_search($opt, $q['options'])) ?></div>
      <span class="small"><?= e($opt['option_text']) ?></span>
    </label>
    <?php endif; ?>
    <?php endforeach; ?>

    <?php else: /* essay */ ?>
    <?php if ($resultMode): ?>
    <div class="p-3 bg-light rounded small">
      <?= $myText ? nl2br(e($myText)) : '<span class="text-muted fst-italic">No answer provided</span>' ?>
    </div>
    <div class="text-muted small mt-1"><i class="fas fa-info-circle me-1"></i>This answer will be graded by your teacher.</div>
    <?php else: ?>
    <textarea name="answers[<?= $q['id'] ?>]" class="form-control" rows="4" placeholder="Write your answer here…"><?= e($myText) ?></textarea>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($resultMode && !empty($q['explanation'])): ?>
    <div class="mt-3 p-2 rounded small" style="background:#fffbeb;border-left:3px solid #f59e0b">
      <i class="fas fa-lightbulb me-1 text-warning"></i><strong>Explanation:</strong> <?= e($q['explanation']) ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if (!$resultMode): ?>
<div class="d-flex justify-content-between align-items-center mt-2 mb-5">
  <a href="<?= APP_URL ?>/student/online-exams.php" class="btn btn-outline-secondary"
     onclick="return confirm('Leave exam? Your progress will be lost for unsaved answers.')">
    <i class="fas fa-arrow-left me-1"></i>Exit
  </a>
  <button type="submit" class="btn btn-success px-4"
          onclick="return confirm('Submit exam? You cannot change answers after submission.')">
    <i class="fas fa-paper-plane me-1"></i>Submit Exam
  </button>
</div>
</form>
<?php else: ?>
<div class="text-center mt-3 mb-5">
  <a href="<?= APP_URL ?>/student/online-exams.php" class="btn btn-primary px-4">
    <i class="fas fa-arrow-left me-1"></i>Back to Exams
  </a>
</div>
<?php endif; ?>
</div>

<?php if (!$resultMode): ?>
<script>
let remaining = <?= (int)$remaining ?>;
const timerEl = document.getElementById('timer');
function formatTime(s) {
  const h=Math.floor(s/3600),m=Math.floor((s%3600)/60),sec=s%60;
  return (h>0?String(h).padStart(2,'0')+':':'')+String(m).padStart(2,'0')+':'+String(sec).padStart(2,'0');
}
function tick() {
  if (remaining <= 0) {
    timerEl.textContent = '00:00';
    document.getElementById('examForm').submit();
    return;
  }
  remaining--;
  timerEl.textContent = formatTime(remaining);
  timerEl.className = remaining < 120 ? 'danger' : remaining < 300 ? 'warning' : '';
}
tick();
setInterval(tick, 1000);
// Warn before leaving
window.addEventListener('beforeunload', e => { e.preventDefault(); e.returnValue = ''; });
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
