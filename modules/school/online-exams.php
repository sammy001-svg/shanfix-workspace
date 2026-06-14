<?php
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $user = currentUser(); $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    // ── Save exam header ─────────────────────────────────────
    if ($action === 'save_exam') {
        $id          = (int)($_POST['id'] ?? 0);
        $classId     = (int)($_POST['class_id']   ?? 0) ?: null;
        $subjectId   = (int)($_POST['subject_id'] ?? 0) ?: null;
        $teacherId   = (int)($_POST['teacher_id'] ?? 0) ?: null;
        $title       = sanitize($_POST['title']        ?? '');
        $description = sanitize($_POST['description']  ?? '');
        $instruct    = sanitize($_POST['instructions'] ?? '');
        $startDt     = sanitize($_POST['start_datetime'] ?? '') ?: null;
        $endDt       = sanitize($_POST['end_datetime']   ?? '') ?: null;
        $duration    = max(1, (int)($_POST['duration_mins'] ?? 60));
        $totalMarks  = max(1, (float)($_POST['total_marks'] ?? 100));
        $passMarks   = strlen($_POST['pass_marks'] ?? '') ? (float)$_POST['pass_marks'] : null;
        $shuffle     = isset($_POST['shuffle_questions']) ? 1 : 0;
        $showResults = isset($_POST['show_results_immediately']) ? 1 : 0;
        $allowReview = isset($_POST['allow_review']) ? 1 : 0;
        $maxAttempts = max(1, (int)($_POST['max_attempts'] ?? 1));
        $status      = in_array($_POST['status'] ?? '', ['draft','published','active','closed']) ? $_POST['status'] : 'draft';

        if (!$title) { setFlash('error','Exam title is required.'); redirect('online-exams.php'); }

        if ($id) {
            $pdo->prepare(
                "UPDATE sch_online_exams
                 SET class_id=?,subject_id=?,teacher_id=?,title=?,description=?,instructions=?,
                     start_datetime=?,end_datetime=?,duration_mins=?,total_marks=?,pass_marks=?,
                     shuffle_questions=?,show_results_immediately=?,allow_review=?,max_attempts=?,status=?
                 WHERE id=? AND org_id=?"
            )->execute([$classId,$subjectId,$teacherId,$title,$description,$instruct,
                        $startDt,$endDt,$duration,$totalMarks,$passMarks,
                        $shuffle,$showResults,$allowReview,$maxAttempts,$status,$id,$orgId]);
            setFlash('success','Exam updated.');
            redirect("online-exams.php?view=$id");
        } else {
            $uid = (int)$user['id'];
            $pdo->prepare(
                "INSERT INTO sch_online_exams
                 (org_id,class_id,subject_id,teacher_id,title,description,instructions,
                  start_datetime,end_datetime,duration_mins,total_marks,pass_marks,
                  shuffle_questions,show_results_immediately,allow_review,max_attempts,status,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$orgId,$classId,$subjectId,$teacherId,$title,$description,$instruct,
                        $startDt,$endDt,$duration,$totalMarks,$passMarks,
                        $shuffle,$showResults,$allowReview,$maxAttempts,$status,$uid]);
            $newId = (int)$pdo->lastInsertId();
            setFlash('success','Online exam created. Now add your questions.');
            redirect("online-exams.php?view=$newId");
        }
    }

    if ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0); $back = (int)($_POST['back'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['draft','published','active','closed'])) {
            $pdo->prepare("UPDATE sch_online_exams SET status=? WHERE id=? AND org_id=?")->execute([$status,$id,$orgId]);
            setFlash('success','Status updated.');
        }
        redirect($back ? "online-exams.php?view=$back" : 'online-exams.php');
    }

    if ($action === 'delete_exam') {
        $id = (int)($_POST['id'] ?? 0);
        // Cascade delete: answers → attempts → options → questions → exam
        $qids = $pdo->prepare("SELECT id FROM sch_online_exam_questions WHERE exam_id=? AND org_id=?");
        $qids->execute([$id,$orgId]);
        foreach ($qids->fetchAll() as $q) {
            $pdo->prepare("DELETE FROM sch_online_exam_options WHERE question_id=?")->execute([$q['id']]);
        }
        $aids = $pdo->prepare("SELECT id FROM sch_online_exam_attempts WHERE exam_id=? AND org_id=?");
        $aids->execute([$id,$orgId]);
        foreach ($aids->fetchAll() as $a) {
            $pdo->prepare("DELETE FROM sch_online_exam_answers WHERE attempt_id=?")->execute([$a['id']]);
        }
        $pdo->prepare("DELETE FROM sch_online_exam_attempts WHERE exam_id=? AND org_id=?")->execute([$id,$orgId]);
        $pdo->prepare("DELETE FROM sch_online_exam_questions WHERE exam_id=? AND org_id=?")->execute([$id,$orgId]);
        $pdo->prepare("DELETE FROM sch_online_exams WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Exam deleted.');
        redirect('online-exams.php');
    }

    // ── Question management ───────────────────────────────────
    if ($action === 'save_question') {
        $qid      = (int)($_POST['q_id']    ?? 0);
        $examId   = (int)($_POST['exam_id'] ?? 0);
        $qtype    = in_array($_POST['q_type'] ?? '', ['mcq','true_false','short_answer']) ? $_POST['q_type'] : 'mcq';
        $qtext    = sanitize($_POST['q_text']    ?? '');
        $marks    = max(0, (float)($_POST['q_marks'] ?? 1));
        $order    = (int)($_POST['q_order'] ?? 0);
        $explain  = sanitize($_POST['q_explanation'] ?? '');

        if (!$qtext || !$examId) { setFlash('error','Question text is required.'); redirect("online-exams.php?view=$examId"); }

        // Verify exam belongs to this org
        $chk = $pdo->prepare("SELECT id FROM sch_online_exams WHERE id=? AND org_id=?");
        $chk->execute([$examId,$orgId]);
        if (!$chk->fetch()) { setFlash('error','Access denied.'); redirect('online-exams.php'); }

        if ($qid) {
            $pdo->prepare("UPDATE sch_online_exam_questions SET question_text=?,question_type=?,marks=?,sort_order=?,explanation=? WHERE id=? AND exam_id=?")
                ->execute([$qtext,$qtype,$marks,$order,$explain,$qid,$examId]);
            // Clear old options and re-insert
            $pdo->prepare("DELETE FROM sch_online_exam_options WHERE question_id=?")->execute([$qid]);
        } else {
            // Auto-assign sort_order = max + 1
            $mx = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM sch_online_exam_questions WHERE exam_id=?");
            $mx->execute([$examId]); $order = (int)$mx->fetchColumn();
            $pdo->prepare("INSERT INTO sch_online_exam_questions (exam_id,org_id,question_text,question_type,marks,sort_order,explanation) VALUES (?,?,?,?,?,?,?)")
                ->execute([$examId,$orgId,$qtext,$qtype,$marks,$order,$explain]);
            $qid = (int)$pdo->lastInsertId();
        }

        // Insert options for MCQ or True/False
        if ($qtype === 'mcq') {
            $optTexts   = $_POST['opt_text']    ?? [];
            $correctOpt = (int)($_POST['correct_opt'] ?? 0);
            foreach ($optTexts as $idx => $ot) {
                $ot = trim($ot);
                if ($ot === '') continue;
                $pdo->prepare("INSERT INTO sch_online_exam_options (question_id,option_text,is_correct,sort_order) VALUES (?,?,?,?)")
                    ->execute([$qid, $ot, $idx === $correctOpt ? 1 : 0, $idx]);
            }
        } elseif ($qtype === 'true_false') {
            $correctTf = $_POST['correct_tf'] ?? 'true';
            foreach (['true'=>'True','false'=>'False'] as $val=>$label) {
                $pdo->prepare("INSERT INTO sch_online_exam_options (question_id,option_text,is_correct,sort_order) VALUES (?,?,?,?)")
                    ->execute([$qid, $label, $val === $correctTf ? 1 : 0, $val==='true'?0:1]);
            }
        }

        setFlash('success','Question saved.');
        redirect("online-exams.php?view=$examId");
    }

    if ($action === 'delete_question') {
        $qid    = (int)($_POST['q_id']    ?? 0);
        $examId = (int)($_POST['exam_id'] ?? 0);
        $pdo->prepare("DELETE FROM sch_online_exam_options WHERE question_id=?")->execute([$qid]);
        $pdo->prepare("DELETE FROM sch_online_exam_questions WHERE id=? AND exam_id=?")->execute([$qid,$examId]);
        setFlash('success','Question deleted.');
        redirect("online-exams.php?view=$examId");
    }
}
require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

// ── Dropdown data ──────────────────────────────────────────
$classes = $subjects = $teachers = [];
try { $s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name"); $s->execute([$orgId]); $classes=$s->fetchAll(); } catch(Throwable $e) {}
try { $s=$pdo->prepare("SELECT id,name FROM sch_subjects WHERE org_id=? AND status='active' ORDER BY name"); $s->execute([$orgId]); $subjects=$s->fetchAll(); } catch(Throwable $e) {}
try { $s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name FROM sch_teachers WHERE org_id=? AND status='active' ORDER BY first_name"); $s->execute([$orgId]); $teachers=$s->fetchAll(); } catch(Throwable $e) {}

$viewId  = (int)($_GET['view'] ?? 0);
$viewExam = null; $questions = []; $attempts = [];

if ($viewId) {
    try { $s=$pdo->prepare("SELECT oe.*,c.name AS class_name,sub.name AS subject_name,CONCAT(t.first_name,' ',t.last_name) AS teacher_name FROM sch_online_exams oe LEFT JOIN sch_classes c ON c.id=oe.class_id LEFT JOIN sch_subjects sub ON sub.id=oe.subject_id LEFT JOIN sch_teachers t ON t.id=oe.teacher_id WHERE oe.id=? AND oe.org_id=?"); $s->execute([$viewId,$orgId]); $viewExam=$s->fetch(); } catch(Throwable $e) {}

    if ($viewExam) {
        // Load questions with options
        try {
            $s=$pdo->prepare("SELECT * FROM sch_online_exam_questions WHERE exam_id=? ORDER BY sort_order,id");
            $s->execute([$viewId]); $qs=$s->fetchAll();
            foreach ($qs as $q) {
                $opts=[]; $s2=$pdo->prepare("SELECT * FROM sch_online_exam_options WHERE question_id=? ORDER BY sort_order"); $s2->execute([$q['id']]); $opts=$s2->fetchAll();
                $questions[] = array_merge($q, ['options'=>$opts]);
            }
        } catch(Throwable $e) {}

        // Load attempts
        try {
            $s=$pdo->prepare("SELECT a.*,CONCAT(st.first_name,' ',st.last_name) AS student_name,st.admission_no FROM sch_online_exam_attempts a JOIN sch_students st ON st.id=a.student_id WHERE a.exam_id=? AND a.org_id=? ORDER BY a.submitted_at DESC");
            $s->execute([$viewId,$orgId]); $attempts=$s->fetchAll();
        } catch(Throwable $e) {}
    }
}

// ── Exam list data ─────────────────────────────────────────
$exams = [];
if (!$viewId) {
    try {
        $s=$pdo->prepare(
            "SELECT oe.*,c.name AS class_name,sub.name AS subject_name,
                    (SELECT COUNT(*) FROM sch_online_exam_questions WHERE exam_id=oe.id) AS question_count,
                    (SELECT COUNT(*) FROM sch_online_exam_attempts WHERE exam_id=oe.id AND org_id=oe.org_id) AS attempt_count
             FROM sch_online_exams oe
             LEFT JOIN sch_classes c ON c.id=oe.class_id
             LEFT JOIN sch_subjects sub ON sub.id=oe.subject_id
             WHERE oe.org_id=?
             ORDER BY FIELD(oe.status,'active','published','draft','closed'),oe.created_at DESC"
        );
        $s->execute([$orgId]); $exams=$s->fetchAll();
    } catch(Throwable $e) {}
    $counts=['draft'=>0,'published'=>0,'active'=>0,'closed'=>0,'total'=>0];
    foreach ($exams as $ex) { $counts[$ex['status']] = ($counts[$ex['status']]??0)+1; $counts['total']++; }
}

$statusColors=['draft'=>'secondary','published'=>'primary','active'=>'success','closed'=>'dark'];
$typeLabels=['mcq'=>'MCQ','true_false'=>'True/False','short_answer'=>'Essay'];
$typeColors=['mcq'=>'primary','true_false'=>'success','short_answer'=>'warning'];
?>
<?= flashAlert() ?>

<?php if (!$viewExam): ?>
<!-- ── LIST VIEW ──────────────────────────────────────────── -->
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-laptop me-2" style="color:<?= $moduleColor ?>"></i>Online Exams</h4>
    <p class="text-muted mb-0">Create and manage computer-based online assessments</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#examModal">
    <i class="fas fa-plus me-2"></i>New Online Exam
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-laptop"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['total'] ?></div><div class="stat-label">Total Exams</div></div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-play-circle"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['active'] ?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-globe"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['published'] ?></div><div class="stat-label">Published</div></div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-edit"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['draft'] ?></div><div class="stat-label">Draft</div></div></div></div>
</div>

<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>All Online Exams</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th>Exam</th><th>Class</th><th>Window</th><th>Duration</th><th class="text-center">Questions</th><th class="text-center">Attempts</th><th>Status</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($exams)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">
            <i class="fas fa-laptop fa-2x d-block mb-2 opacity-25"></i>No online exams yet. Create your first one!
          </td></tr>
          <?php else: foreach ($exams as $ex):
            $sc = $statusColors[$ex['status']] ?? 'secondary';
          ?>
          <tr>
            <td style="max-width:200px">
              <div class="fw-semibold"><?= e($ex['title']) ?></div>
              <div class="text-muted small"><?= e($ex['subject_name'] ?? '—') ?></div>
            </td>
            <td class="small"><?= e($ex['class_name'] ?? '—') ?></td>
            <td class="small">
              <?php if ($ex['start_datetime']): ?>
              <div><?= date('d M Y H:i', strtotime($ex['start_datetime'])) ?></div>
              <div class="text-muted">–&nbsp;<?= $ex['end_datetime'] ? date('d M Y H:i', strtotime($ex['end_datetime'])) : 'Open' ?></div>
              <?php else: ?>
              <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= $ex['duration_mins'] ?> min</td>
            <td class="text-center"><span class="badge bg-primary bg-opacity-25 text-primary"><?= (int)$ex['question_count'] ?></span></td>
            <td class="text-center"><span class="badge bg-secondary bg-opacity-25 text-dark"><?= (int)$ex['attempt_count'] ?></span></td>
            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($ex['status']) ?></span></td>
            <td class="text-end text-nowrap">
              <a href="online-exams.php?view=<?= $ex['id'] ?>" class="btn btn-xs btn-outline-primary me-1" title="Manage Questions">
                <i class="fas fa-question-circle"></i> Questions
              </a>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_exam">
                <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete this exam and all its questions and results?">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── DETAIL / QUESTION MANAGEMENT VIEW ─────────────────── -->
<?php $sc = $statusColors[$viewExam['status']] ?? 'secondary'; ?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <a href="online-exams.php" class="btn btn-sm btn-outline-secondary me-2"><i class="fas fa-arrow-left me-1"></i>Back</a>
    <span class="fw-bold"><?= e($viewExam['title']) ?></span>
    <span class="badge bg-<?= $sc ?> ms-2"><?= ucfirst($viewExam['status']) ?></span>
  </div>
  <div class="d-flex gap-2">
    <form method="POST" class="d-inline">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="id" value="<?= $viewId ?>">
      <input type="hidden" name="back" value="<?= $viewId ?>">
      <select name="status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
        <?php foreach (['draft'=>'Draft','published'=>'Published','active'=>'Active','closed'=>'Closed'] as $v=>$l): ?>
        <option value="<?= $v ?>" <?= $viewExam['status']===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <button class="btn btn-sm btn-outline-secondary btn-edit-exam"
      data-id="<?= $viewExam['id'] ?>"
      data-class_id="<?= $viewExam['class_id'] ?? '' ?>"
      data-subject_id="<?= $viewExam['subject_id'] ?? '' ?>"
      data-teacher_id="<?= $viewExam['teacher_id'] ?? '' ?>"
      data-title="<?= e($viewExam['title']) ?>"
      data-description="<?= e($viewExam['description'] ?? '') ?>"
      data-instructions="<?= e($viewExam['instructions'] ?? '') ?>"
      data-start_datetime="<?= $viewExam['start_datetime'] ? date('Y-m-d\TH:i', strtotime($viewExam['start_datetime'])) : '' ?>"
      data-end_datetime="<?= $viewExam['end_datetime'] ? date('Y-m-d\TH:i', strtotime($viewExam['end_datetime'])) : '' ?>"
      data-duration="<?= (int)$viewExam['duration_mins'] ?>"
      data-total_marks="<?= $viewExam['total_marks'] ?>"
      data-pass_marks="<?= $viewExam['pass_marks'] ?? '' ?>"
      data-shuffle="<?= (int)$viewExam['shuffle_questions'] ?>"
      data-show_results="<?= (int)$viewExam['show_results_immediately'] ?>"
      data-allow_review="<?= (int)$viewExam['allow_review'] ?>"
      data-max_attempts="<?= (int)$viewExam['max_attempts'] ?>"
      data-status="<?= $viewExam['status'] ?>">
      <i class="fas fa-edit me-1"></i>Edit Exam
    </button>
    <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#questionModal">
      <i class="fas fa-plus me-1"></i>Add Question
    </button>
  </div>
</div>

<!-- Exam info summary -->
<div class="card mb-3">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-sm-3 text-center">
        <div class="fw-bold fs-4" style="color:<?= $moduleColor ?>"><?= count($questions) ?></div>
        <div class="text-muted small">Questions</div>
      </div>
      <div class="col-sm-3 text-center">
        <div class="fw-bold fs-4"><?= $viewExam['duration_mins'] ?> min</div>
        <div class="text-muted small">Duration</div>
      </div>
      <div class="col-sm-3 text-center">
        <div class="fw-bold fs-4"><?= number_format($viewExam['total_marks'], 0) ?></div>
        <div class="text-muted small">Total Marks</div>
      </div>
      <div class="col-sm-3 text-center">
        <div class="fw-bold fs-4"><?= count($attempts) ?></div>
        <div class="text-muted small">Attempts</div>
      </div>
    </div>
    <?php if ($viewExam['class_name'] || $viewExam['subject_name']): ?>
    <hr class="my-2">
    <div class="small text-muted">
      <?php if ($viewExam['class_name']): ?><i class="fas fa-chalkboard me-1"></i><?= e($viewExam['class_name']) ?>&nbsp;&nbsp;<?php endif; ?>
      <?php if ($viewExam['subject_name']): ?><i class="fas fa-book me-1"></i><?= e($viewExam['subject_name']) ?>&nbsp;&nbsp;<?php endif; ?>
      <?php if ($viewExam['start_datetime']): ?><i class="fas fa-clock me-1"></i><?= date('d M Y H:i', strtotime($viewExam['start_datetime'])) ?><?php if($viewExam['end_datetime']): ?> – <?= date('d M Y H:i', strtotime($viewExam['end_datetime'])) ?><?php endif; ?><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($viewExam['instructions'])): ?>
    <div class="mt-2 small p-2 bg-light rounded"><i class="fas fa-info-circle me-1 text-muted"></i><?= nl2br(e($viewExam['instructions'])) ?></div>
    <?php endif; ?>
  </div>
</div>

<!-- Questions list -->
<div class="card mb-4">
  <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-question-circle me-2" style="color:<?= $moduleColor ?>"></i>Questions (<?= count($questions) ?>)</h6></div>
  <div class="card-body p-0">
    <?php if (empty($questions)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-question-circle fa-2x d-block mb-2 opacity-25"></i>
      No questions yet. Click "Add Question" to start building this exam.
    </div>
    <?php else: ?>
    <?php foreach ($questions as $qi => $q):
      $tc = $typeColors[$q['question_type']] ?? 'secondary';
      $tl = $typeLabels[$q['question_type']] ?? $q['question_type'];
    ?>
    <div class="px-4 py-3 border-bottom">
      <div class="d-flex align-items-start justify-content-between gap-3">
        <div class="flex-grow-1">
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge text-white rounded-pill" style="background:<?= $moduleColor ?>;font-size:.65rem;min-width:24px"><?= $qi+1 ?></span>
            <span class="badge bg-<?= $tc ?> bg-opacity-25 text-<?= $tc ?>" style="font-size:.65rem"><?= $tl ?></span>
            <span class="text-muted small"><?= number_format($q['marks'],1) ?> mark<?= $q['marks']!=1?'s':'' ?></span>
          </div>
          <div class="fw-semibold mb-1"><?= nl2br(e($q['question_text'])) ?></div>
          <?php if (!empty($q['options'])): ?>
          <div class="mt-1 ms-2">
            <?php foreach ($q['options'] as $opt): ?>
            <div class="d-flex align-items-center gap-2 mb-1 small">
              <i class="fas <?= $opt['is_correct'] ? 'fa-check-circle text-success' : 'fa-circle text-muted opacity-50' ?>" style="font-size:.8rem"></i>
              <span class="<?= $opt['is_correct'] ? 'fw-semibold text-success' : 'text-muted' ?>"><?= e($opt['option_text']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php elseif ($q['question_type'] === 'short_answer'): ?>
          <div class="mt-1 ms-2 small text-muted fst-italic">[ Essay / Short Answer — manually graded ]</div>
          <?php endif; ?>
          <?php if (!empty($q['explanation'])): ?>
          <div class="mt-1 ms-2 small text-muted"><i class="fas fa-lightbulb me-1"></i><?= e($q['explanation']) ?></div>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-2 flex-shrink-0">
          <button class="btn btn-xs btn-outline-secondary btn-edit-question"
            data-id="<?= $q['id'] ?>"
            data-type="<?= $q['question_type'] ?>"
            data-text="<?= e($q['question_text']) ?>"
            data-marks="<?= $q['marks'] ?>"
            data-explanation="<?= e($q['explanation'] ?? '') ?>"
            data-options='<?= json_encode(array_map(fn($o) => ['id'=>$o['id'],'text'=>$o['option_text'],'correct'=>(bool)$o['is_correct']], $q['options'])) ?>'>
            <i class="fas fa-edit"></i>
          </button>
          <form method="POST" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_question">
            <input type="hidden" name="q_id" value="<?= $q['id'] ?>">
            <input type="hidden" name="exam_id" value="<?= $viewId ?>">
            <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete this question?">
              <i class="fas fa-trash"></i>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Attempts table -->
<?php if (!empty($attempts)): ?>
<div class="card">
  <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-users me-2 text-muted"></i>Student Attempts (<?= count($attempts) ?>)</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr><th>Student</th><th>Adm No</th><th>Started</th><th>Submitted</th><th class="text-center">Score</th><th class="text-center">%</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($attempts as $att):
            $passVal = (float)($viewExam['pass_marks'] ?? 0);
            $pct     = $att['percentage'] !== null ? (float)$att['percentage'] : null;
            $passed  = $passVal > 0 ? ($pct !== null && $pct >= ($passVal/$viewExam['total_marks']*100)) : null;
          ?>
          <tr>
            <td class="fw-semibold small"><?= e($att['student_name'] ?? '—') ?></td>
            <td class="small text-muted"><?= e($att['admission_no'] ?? '') ?></td>
            <td class="small text-muted"><?= $att['started_at'] ? date('d M H:i', strtotime($att['started_at'])) : '—' ?></td>
            <td class="small text-muted"><?= $att['submitted_at'] ? date('d M H:i', strtotime($att['submitted_at'])) : '—' ?></td>
            <td class="text-center small fw-semibold"><?= $att['score'] !== null ? number_format($att['score'],1).'/'.$viewExam['total_marks'] : '—' ?></td>
            <td class="text-center">
              <?php if ($pct !== null): ?>
              <span class="badge <?= $pct>=50?'bg-success':'bg-danger' ?> bg-opacity-25 <?= $pct>=50?'text-success':'text-danger' ?>"><?= number_format($pct,0) ?>%</span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $att['status']==='graded'?'success':($att['status']==='submitted'?'primary':'secondary') ?>" style="font-size:.65rem"><?= ucfirst(str_replace('_',' ',$att['status'])) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Question Modal -->
<div class="modal fade" id="questionModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i><span id="qModalTitle">Add Question</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST"><div class="modal-body">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_question">
        <input type="hidden" name="exam_id" value="<?= $viewId ?>">
        <input type="hidden" name="q_id" id="qId" value="0">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Question Type <span class="text-danger">*</span></label>
            <select name="q_type" id="qType" class="form-select" onchange="toggleQType(this.value)">
              <option value="mcq">Multiple Choice (MCQ)</option>
              <option value="true_false">True / False</option>
              <option value="short_answer">Short Answer / Essay</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Marks</label>
            <input type="number" name="q_marks" id="qMarks" class="form-control" value="1" min="0" step="0.5">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Question Text <span class="text-danger">*</span></label>
            <textarea name="q_text" id="qText" class="form-control" rows="3" required placeholder="Enter the question here…"></textarea>
          </div>
          <!-- MCQ Options -->
          <div id="mcqOptions" class="col-12">
            <label class="form-label fw-semibold">Answer Options <span class="text-muted small">(mark the correct one)</span></label>
            <?php for ($i=0;$i<4;$i++): ?>
            <div class="input-group mb-2">
              <div class="input-group-text">
                <input type="radio" name="correct_opt" value="<?= $i ?>" <?= $i===0?'checked':'' ?>>
              </div>
              <input type="text" name="opt_text[<?= $i ?>]" class="form-control" placeholder="Option <?= chr(65+$i) ?>">
            </div>
            <?php endfor; ?>
            <div class="text-muted small"><i class="fas fa-info-circle me-1"></i>Select the radio button next to the correct answer.</div>
          </div>
          <!-- True/False Options -->
          <div id="tfOptions" class="col-12" style="display:none">
            <label class="form-label fw-semibold">Correct Answer</label>
            <div class="d-flex gap-4">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="correct_tf" id="tfTrue" value="true" checked>
                <label class="form-check-label fw-semibold text-success" for="tfTrue">True</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="correct_tf" id="tfFalse" value="false">
                <label class="form-check-label fw-semibold text-danger" for="tfFalse">False</label>
              </div>
            </div>
          </div>
          <!-- Short Answer note -->
          <div id="saNote" class="col-12" style="display:none">
            <div class="alert alert-info border-0 py-2 small mb-0">
              <i class="fas fa-info-circle me-1"></i>Short answer questions require manual grading by the teacher.
            </div>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Explanation <span class="text-muted small">(shown to student after submission)</span></label>
            <input type="text" name="q_explanation" id="qExplanation" class="form-control" placeholder="Optional: briefly explain the correct answer">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>">Save Question</button>
      </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Create/Edit Exam Modal (shown in both views) -->
<div class="modal fade" id="examModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-laptop me-2"></i><span id="examModalTitle">Create Online Exam</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST"><div class="modal-body">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_exam">
        <input type="hidden" name="id" id="examId" value="0">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Exam Title <span class="text-danger">*</span></label>
            <input type="text" name="title" id="examTitle" class="form-control" placeholder="e.g. End Term Mathematics Online Exam" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Class</label>
            <select name="class_id" id="examClass" class="form-select">
              <option value="">— All / Select —</option>
              <?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Subject</label>
            <select name="subject_id" id="examSubject" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Assigned Teacher</label>
            <select name="teacher_id" id="examTeacher" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Start Date &amp; Time</label>
            <input type="datetime-local" name="start_datetime" id="examStart" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">End Date &amp; Time</label>
            <input type="datetime-local" name="end_datetime" id="examEnd" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Duration (min)</label>
            <input type="number" name="duration_mins" id="examDuration" class="form-control" value="60" min="1">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Total Marks</label>
            <input type="number" name="total_marks" id="examTotal" class="form-control" value="100" min="1" step="0.5">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Pass Marks</label>
            <input type="number" name="pass_marks" id="examPass" class="form-control" placeholder="Optional" min="0" step="0.5">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Max Attempts</label>
            <input type="number" name="max_attempts" id="examMaxAtt" class="form-control" value="1" min="1">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="examStatus" class="form-select">
              <option value="draft">Draft</option>
              <option value="published">Published</option>
              <option value="active">Active</option>
              <option value="closed">Closed</option>
            </select>
          </div>
          <div class="col-md-6 d-flex align-items-end gap-3 pb-1">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="shuffle_questions" id="examShuffle" value="1">
              <label class="form-check-label small" for="examShuffle">Shuffle Questions</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="show_results_immediately" id="examShowResults" value="1" checked>
              <label class="form-check-label small" for="examShowResults">Show Results Immediately</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="allow_review" id="examAllowReview" value="1" checked>
              <label class="form-check-label small" for="examAllowReview">Allow Review</label>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Instructions for Students</label>
            <textarea name="instructions" id="examInstruct" class="form-control" rows="2" placeholder="Rules, guidelines, or notes students see before starting…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>">Save Exam</button>
      </div>
      </form>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
function toggleQType(type) {
  document.getElementById('mcqOptions').style.display = type==='mcq'        ? '' : 'none';
  document.getElementById('tfOptions').style.display  = type==='true_false' ? '' : 'none';
  document.getElementById('saNote').style.display     = type==='short_answer'? '' : 'none';
}
document.querySelectorAll('.btn-confirm').forEach(btn => {
  btn.addEventListener('click', function(e) { if (!confirm(this.dataset.msg || 'Are you sure?')) e.preventDefault(); });
});
// Populate exam edit modal
document.querySelectorAll('.btn-edit-exam').forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('examModalTitle').textContent = 'Edit Online Exam';
    document.getElementById('examId').value       = this.dataset.id;
    document.getElementById('examClass').value    = this.dataset.class_id    || '';
    document.getElementById('examSubject').value  = this.dataset.subject_id  || '';
    document.getElementById('examTeacher').value  = this.dataset.teacher_id  || '';
    document.getElementById('examTitle').value    = this.dataset.title       || '';
    document.getElementById('examStart').value    = this.dataset.start_datetime || '';
    document.getElementById('examEnd').value      = this.dataset.end_datetime   || '';
    document.getElementById('examDuration').value = this.dataset.duration    || 60;
    document.getElementById('examTotal').value    = this.dataset.total_marks || 100;
    document.getElementById('examPass').value     = this.dataset.pass_marks  || '';
    document.getElementById('examMaxAtt').value   = this.dataset.max_attempts|| 1;
    document.getElementById('examStatus').value   = this.dataset.status      || 'draft';
    document.getElementById('examShuffle').checked      = this.dataset.shuffle === '1';
    document.getElementById('examShowResults').checked  = this.dataset.show_results === '1';
    document.getElementById('examAllowReview').checked  = this.dataset.allow_review === '1';
    const d1=document.createElement('textarea'); d1.innerHTML=this.dataset.description||''; document.getElementById('examDesc') && (document.getElementById('examDesc').value=d1.value);
    const d2=document.createElement('textarea'); d2.innerHTML=this.dataset.instructions||''; document.getElementById('examInstruct').value=d2.value;
    new bootstrap.Modal(document.getElementById('examModal')).show();
  });
});
// Populate question edit modal
document.querySelectorAll('.btn-edit-question').forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('qModalTitle').textContent = 'Edit Question';
    document.getElementById('qId').value     = this.dataset.id;
    document.getElementById('qType').value   = this.dataset.type || 'mcq';
    document.getElementById('qMarks').value  = this.dataset.marks || 1;
    const dt=document.createElement('textarea'); dt.innerHTML=this.dataset.text||''; document.getElementById('qText').value=dt.value;
    const de=document.createElement('textarea'); de.innerHTML=this.dataset.explanation||''; document.getElementById('qExplanation').value=de.value;
    toggleQType(this.dataset.type);
    // Populate options
    const opts = JSON.parse(this.dataset.options || '[]');
    if (this.dataset.type === 'mcq') {
      const inputs = document.querySelectorAll('[name^="opt_text"]');
      const radios = document.querySelectorAll('[name="correct_opt"]');
      inputs.forEach((inp,i) => { inp.value = opts[i] ? opts[i].text : ''; });
      radios.forEach((r,i) => { r.checked = opts[i] && opts[i].correct; });
    } else if (this.dataset.type === 'true_false') {
      const correct = opts.find(o => o.correct);
      document.getElementById('tfTrue').checked  = correct && correct.text === 'True';
      document.getElementById('tfFalse').checked = correct && correct.text === 'False';
    }
    new bootstrap.Modal(document.getElementById('questionModal')).show();
  });
});
document.getElementById('questionModal')?.addEventListener('hidden.bs.modal', function() {
  document.getElementById('qModalTitle').textContent = 'Add Question';
  document.getElementById('qId').value = '0';
  this.querySelector('form').reset();
  document.getElementById('qMarks').value = 1;
  document.getElementById('qType').value = 'mcq';
  toggleQType('mcq');
});
document.getElementById('examModal')?.addEventListener('hidden.bs.modal', function() {
  document.getElementById('examModalTitle').textContent = 'Create Online Exam';
  document.getElementById('examId').value = '0';
  this.querySelector('form').reset();
  document.getElementById('examDuration').value = 60;
  document.getElementById('examTotal').value    = 100;
  document.getElementById('examMaxAtt').value   = 1;
  document.getElementById('examShowResults').checked = true;
  document.getElementById('examAllowReview').checked = true;
});
</script>
<?php $extraJs = ob_get_clean(); require_once __DIR__ . '/../../includes/footer.php'; ?>
