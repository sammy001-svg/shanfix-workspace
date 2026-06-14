<?php
$pageTitle = 'Homework';
require_once __DIR__ . '/../includes/header-teacher.php';

// ── My classes / subjects ────────────────────────────────────────
$myClassSubjects = [];
try {
    $s = $pdo->prepare(
        "SELECT cs.class_id, cs.subject_id, c.name AS class_name, sub.name AS subject_name
         FROM sch_class_subjects cs
         JOIN sch_classes c   ON c.id   = cs.class_id
         JOIN sch_subjects sub ON sub.id = cs.subject_id
         WHERE cs.org_id=? AND cs.staff_id=?
         ORDER BY c.name, sub.name"
    );
    $s->execute([$tchOrgId, $tchId]);
    $myClassSubjects = $s->fetchAll();
} catch (Throwable $e) {}

// ── Terms ────────────────────────────────────────────────────────
$terms = [];
try {
    $s = $pdo->prepare("SELECT id, name, status FROM sch_terms WHERE org_id=? ORDER BY start_date DESC LIMIT 6");
    $s->execute([$tchOrgId]);
    $terms = $s->fetchAll();
} catch (Throwable $e) {}
$currentTermId = 0;
foreach ($terms as $t) { if ($t['status'] === 'active') { $currentTermId = $t['id']; break; } }
if (!$currentTermId && !empty($terms)) $currentTermId = $terms[0]['id'];

// ── POST handlers ─────────────────────────────────────────────────
$saveMsg = null;
$saveErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    // ── Add / edit a question ─────────────────────────────────────
    if ($action === 'save_hw_question') {
        $hwId  = (int)($_POST['hw_id']  ?? 0);
        $qId   = (int)($_POST['q_id']   ?? 0);
        $qText = trim($_POST['q_text']  ?? '');
        $marks = max(0, (float)($_POST['q_marks'] ?? 1));
        $order = (int)($_POST['q_order'] ?? 0);
        if (!$hwId || !$qText) {
            setFlash('error', 'Question text is required.');
        } else {
            $chk = $pdo->prepare("SELECT id FROM sch_homework WHERE id=? AND teacher_id=? AND org_id=?");
            $chk->execute([$hwId, $tchId, $tchOrgId]);
            if (!$chk->fetchColumn()) {
                setFlash('error', 'Not authorised.');
            } elseif ($qId) {
                try {
                    $pdo->prepare(
                        "UPDATE sch_homework_questions SET question_text=?,marks=?,sort_order=?
                         WHERE id=? AND homework_id=? AND org_id=?"
                    )->execute([$qText, $marks, $order, $qId, $hwId, $tchOrgId]);
                    setFlash('success', 'Question updated.');
                } catch (Throwable $e) { setFlash('error', 'Could not update question.'); }
            } else {
                try {
                    $pdo->prepare(
                        "INSERT INTO sch_homework_questions (homework_id,org_id,question_text,marks,sort_order)
                         VALUES (?,?,?,?,?)"
                    )->execute([$hwId, $tchOrgId, $qText, $marks, $order]);
                    setFlash('success', 'Question added.');
                } catch (Throwable $e) { setFlash('error', 'Could not add question.'); }
            }
        }
        redirect("homework.php?view=$hwId&tab=questions");

    // ── Delete a question ─────────────────────────────────────────
    } elseif ($action === 'delete_hw_question') {
        $hwId = (int)($_POST['hw_id'] ?? 0);
        $qId  = (int)($_POST['q_id']  ?? 0);
        try {
            $pdo->prepare("DELETE FROM sch_homework_answers WHERE question_id=?")->execute([$qId]);
            $pdo->prepare("DELETE FROM sch_homework_questions WHERE id=? AND homework_id=? AND org_id=?")
                ->execute([$qId, $hwId, $tchOrgId]);
            setFlash('success', 'Question deleted.');
        } catch (Throwable $e) { setFlash('error', 'Could not delete question.'); }
        redirect("homework.php?view=$hwId&tab=questions");

    // ── Mark a student's submission ───────────────────────────────
    } elseif ($action === 'mark_submission') {
        $hwId   = (int)($_POST['hw_id']      ?? 0);
        $stuId2 = (int)($_POST['student_id'] ?? 0);
        $qMarks = $_POST['q_marks']    ?? [];
        $qFb    = $_POST['q_feedback'] ?? [];
        try {
            $chk = $pdo->prepare("SELECT id,org_id FROM sch_homework WHERE id=? AND teacher_id=? AND org_id=?");
            $chk->execute([$hwId, $tchId, $tchOrgId]);
            if (!$chk->fetchColumn()) {
                setFlash('error', 'Not authorised.');
                redirect("homework.php?view=$hwId&tab=submissions");
            }
            $totalAwarded = 0.0;
            foreach ($qMarks as $qid => $mkVal) {
                $qid  = (int)$qid;
                $mk   = max(0.0, (float)$mkVal);
                $fb   = trim($qFb[$qid] ?? '');
                $totalAwarded += $mk;
                $pdo->prepare(
                    "INSERT INTO sch_homework_answers
                     (homework_id,question_id,student_id,org_id,answer_text,marks_awarded,feedback,marked_by,marked_at)
                     VALUES (?,?,?,?,'',?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE
                       marks_awarded=VALUES(marks_awarded),
                       feedback=VALUES(feedback),
                       marked_by=VALUES(marked_by),
                       marked_at=NOW()"
                )->execute([$hwId, $qid, $stuId2, $tchOrgId, $mk, $fb, $tchId]);
            }
            $pdo->prepare(
                "INSERT INTO sch_homework_submissions
                 (homework_id,student_id,org_id,status,marks_obtained,marked_at,marked_by)
                 VALUES (?,?,?,'marked',?,NOW(),?)
                 ON DUPLICATE KEY UPDATE
                   status='marked',marks_obtained=VALUES(marks_obtained),
                   marked_at=NOW(),marked_by=VALUES(marked_by)"
            )->execute([$hwId, $stuId2, $tchOrgId, $totalAwarded, $tchId]);
            setFlash('success', 'Marks saved successfully.');
        } catch (Throwable $e) {
            setFlash('error', 'Could not save marks.');
        }
        redirect("homework.php?view=$hwId&tab=submissions");

    // ── Change homework status ────────────────────────────────────
    } elseif ($action === 'status_change') {
        $hwId   = (int)($_POST['hw_id'] ?? 0);
        $status = in_array($_POST['new_status'] ?? '', ['active','closed','draft'])
                  ? $_POST['new_status'] : 'active';
        try {
            $pdo->prepare("UPDATE sch_homework SET status=? WHERE id=? AND teacher_id=? AND org_id=?")
                ->execute([$status, $hwId, $tchId, $tchOrgId]);
            $saveMsg = 'Homework status updated.';
        } catch (Throwable $e) { $saveErr = 'Could not update status.'; }

    // ── Delete homework ───────────────────────────────────────────
    } elseif ($action === 'delete') {
        $hwId = (int)($_POST['hw_id'] ?? 0);
        try {
            // cascade: answers → questions → submissions → homework
            $pdo->prepare(
                "DELETE a FROM sch_homework_answers a
                 JOIN sch_homework_questions q ON q.id=a.question_id
                 WHERE q.homework_id=?"
            )->execute([$hwId]);
            $pdo->prepare("DELETE FROM sch_homework_questions WHERE homework_id=?")->execute([$hwId]);
            $pdo->prepare("DELETE FROM sch_homework_submissions WHERE homework_id=?")->execute([$hwId]);
            $pdo->prepare("DELETE FROM sch_homework WHERE id=? AND teacher_id=? AND org_id=?")
                ->execute([$hwId, $tchId, $tchOrgId]);
            $saveMsg = 'Homework deleted.';
        } catch (Throwable $e) { $saveErr = 'Could not delete homework.'; }

    // ── Create / edit homework ────────────────────────────────────
    } else {
        $hwId      = (int)($_POST['hw_id']      ?? 0);
        $classId   = (int)($_POST['class_id']   ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $termId    = (int)($_POST['term_id']    ?? $currentTermId);
        $title     = trim($_POST['title']        ?? '');
        $desc      = trim($_POST['description']  ?? '');
        $instr     = trim($_POST['instructions'] ?? '');
        $dueDate   = $_POST['due_date']           ?? null;
        $maxMarks  = (int)($_POST['max_marks']   ?? 0);
        $status    = in_array($_POST['status'] ?? '', ['active','closed','draft'])
                     ? $_POST['status'] : 'active';
        if (!$classId || !$subjectId || !$title) {
            $saveErr = 'Class, subject, and title are required.';
        } elseif ($hwId) {
            try {
                $pdo->prepare(
                    "UPDATE sch_homework
                     SET class_id=?,subject_id=?,term_id=?,title=?,description=?,
                         instructions=?,due_date=?,max_marks=?,status=?
                     WHERE id=? AND teacher_id=? AND org_id=?"
                )->execute([$classId,$subjectId,$termId,$title,$desc,$instr,
                             $dueDate?:null,$maxMarks,$status,$hwId,$tchId,$tchOrgId]);
                $saveMsg = 'Homework updated.';
            } catch (Throwable $e) { $saveErr = 'Could not update homework.'; }
        } else {
            try {
                $pdo->prepare(
                    "INSERT INTO sch_homework
                     (org_id,class_id,subject_id,teacher_id,term_id,title,description,instructions,due_date,max_marks,status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([$tchOrgId,$classId,$subjectId,$tchId,$termId,$title,$desc,$instr,
                             $dueDate?:null,$maxMarks,$status]);
                $saveMsg = 'Homework assigned successfully.';
            } catch (Throwable $e) { $saveErr = 'Could not create homework.'; }
        }
    }
}

// ── Route: detail view vs list view ──────────────────────────────
$viewId      = (int)($_GET['view'] ?? 0);
$viewTab     = in_array($_GET['tab'] ?? '', ['questions','submissions']) ? ($_GET['tab'] ?? 'questions') : 'questions';
$viewStudent = (int)($_GET['student'] ?? 0);

$viewHw         = null;
$questions      = [];
$totalQMarks    = 0.0;
$classStudents  = [];
$submissionsMap = [];
$studentData    = null;
$studentAnswers = [];
$hwList         = [];
$editHw         = null;
$filterStatus   = 'all';

if ($viewId) {
    // ── Load homework detail ──────────────────────────────────────
    try {
        $s = $pdo->prepare(
            "SELECT h.*, c.name AS class_name, sub.name AS subject_name
             FROM sch_homework h
             JOIN sch_classes c   ON c.id   = h.class_id
             JOIN sch_subjects sub ON sub.id = h.subject_id
             WHERE h.id=? AND h.teacher_id=? AND h.org_id=? LIMIT 1"
        );
        $s->execute([$viewId, $tchId, $tchOrgId]);
        $viewHw = $s->fetch();
    } catch (Throwable $e) {}
    if (!$viewHw) redirect('homework.php');

    // Questions
    try {
        $s = $pdo->prepare(
            "SELECT * FROM sch_homework_questions
             WHERE homework_id=? AND org_id=?
             ORDER BY sort_order ASC, id ASC"
        );
        $s->execute([$viewId, $tchOrgId]);
        $questions = $s->fetchAll();
        $totalQMarks = (float)array_sum(array_column($questions, 'marks'));
    } catch (Throwable $e) {}

    if ($viewTab === 'submissions') {
        // All students in the class
        try {
            $s = $pdo->prepare(
                "SELECT st.id, st.first_name, st.last_name, st.admission_no
                 FROM sch_students st
                 WHERE st.class_id=? AND st.org_id=? AND st.status='active'
                 ORDER BY st.first_name, st.last_name"
            );
            $s->execute([$viewHw['class_id'], $tchOrgId]);
            $classStudents = $s->fetchAll();
        } catch (Throwable $e) {}

        // Submissions map
        try {
            $s = $pdo->prepare("SELECT * FROM sch_homework_submissions WHERE homework_id=? AND org_id=?");
            $s->execute([$viewId, $tchOrgId]);
            foreach ($s->fetchAll() as $row) $submissionsMap[$row['student_id']] = $row;
        } catch (Throwable $e) {}

        if ($viewStudent) {
            // Student record
            try {
                $s = $pdo->prepare("SELECT first_name,last_name,admission_no FROM sch_students WHERE id=? AND org_id=? LIMIT 1");
                $s->execute([$viewStudent, $tchOrgId]);
                $studentData = $s->fetch();
            } catch (Throwable $e) {}

            // Student answers keyed by question_id
            try {
                $s = $pdo->prepare(
                    "SELECT * FROM sch_homework_answers
                     WHERE homework_id=? AND student_id=? AND org_id=?"
                );
                $s->execute([$viewId, $viewStudent, $tchOrgId]);
                foreach ($s->fetchAll() as $row) $studentAnswers[$row['question_id']] = $row;
            } catch (Throwable $e) {}
        }
    }

} else {
    // ── Load homework list ────────────────────────────────────────
    $filterStatus = $_GET['status'] ?? 'all';
    try {
        $where  = "h.org_id=? AND h.teacher_id=?";
        $params = [$tchOrgId, $tchId];
        if ($filterStatus !== 'all') { $where .= " AND h.status=?"; $params[] = $filterStatus; }
        $s = $pdo->prepare(
            "SELECT h.*, c.name AS class_name, sub.name AS subject_name,
                    (SELECT COUNT(*) FROM sch_homework_questions q WHERE q.homework_id=h.id) AS q_count,
                    (SELECT COUNT(*) FROM sch_homework_submissions sm
                     WHERE sm.homework_id=h.id AND sm.status IN ('submitted','marked')) AS sub_count,
                    (SELECT COUNT(*) FROM sch_homework_submissions sm2
                     WHERE sm2.homework_id=h.id AND sm2.status='submitted') AS pending_mark_count
             FROM sch_homework h
             JOIN sch_classes c   ON c.id   = h.class_id
             JOIN sch_subjects sub ON sub.id = h.subject_id
             WHERE $where ORDER BY h.created_at DESC"
        );
        $s->execute($params);
        $hwList = $s->fetchAll();
    } catch (Throwable $e) {}

    if (!empty($_GET['edit'])) {
        $eid = (int)$_GET['edit'];
        foreach ($hwList as $hw) { if ((int)$hw['id'] === $eid) { $editHw = $hw; break; } }
        if (!$editHw) {
            try {
                $s = $pdo->prepare("SELECT * FROM sch_homework WHERE id=? AND teacher_id=? AND org_id=? LIMIT 1");
                $s->execute([$eid, $tchId, $tchOrgId]);
                $editHw = $s->fetch() ?: null;
            } catch (Throwable $e) {}
        }
    }
}

$statusBadges = ['active'=>'success','closed'=>'secondary','draft'=>'warning'];
?>

<?php if ($saveMsg): ?>
<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?= e($saveMsg) ?></div>
<?php endif; ?>
<?php if ($saveErr): ?>
<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?= e($saveErr) ?></div>
<?php endif; ?>

<?php if ($viewId && $viewHw): ?>
<!-- ═══════════════════════════════════════════════════════════
     DETAIL VIEW — Questions & Submissions
══════════════════════════════════════════════════════════════ -->

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-3">
    <a href="homework.php" class="btn btn-sm btn-outline-secondary">
      <i class="fas fa-arrow-left me-1"></i>Back
    </a>
    <div>
      <h5 class="fw-bold mb-0"><?= e($viewHw['title']) ?></h5>
      <div class="text-muted small">
        <i class="fas fa-chalkboard me-1"></i><?= e($viewHw['class_name']) ?>
        &nbsp;&middot;&nbsp;<i class="fas fa-book me-1"></i><?= e($viewHw['subject_name']) ?>
        <?php if (!empty($viewHw['due_date'])): ?>
        &nbsp;&middot;&nbsp;<i class="fas fa-calendar-times me-1"></i>Due <?= date('d M Y', strtotime($viewHw['due_date'])) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <span class="badge bg-<?= $statusBadges[$viewHw['status']] ?? 'secondary' ?> px-3 py-2" style="font-size:.8rem">
    <?= ucfirst($viewHw['status']) ?>
  </span>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a href="homework.php?view=<?= $viewId ?>&tab=questions"
       class="nav-link <?= $viewTab==='questions'?'active':'' ?>">
      <i class="fas fa-question-circle me-1"></i>Questions
      <span class="badge rounded-pill ms-1 <?= $viewTab==='questions'?'bg-success':'bg-secondary' ?>" style="font-size:.6rem">
        <?= count($questions) ?>
      </span>
    </a>
  </li>
  <li class="nav-item">
    <a href="homework.php?view=<?= $viewId ?>&tab=submissions"
       class="nav-link <?= $viewTab==='submissions'?'active':'' ?>">
      <i class="fas fa-inbox me-1"></i>Submissions &amp; Marking
      <?php
        $pendingCount = isset($submissionsMap)
            ? count(array_filter($submissionsMap, fn($s) => $s['status']==='submitted'))
            : 0;
      ?>
      <?php if ($pendingCount>0): ?>
      <span class="badge rounded-pill bg-danger ms-1" style="font-size:.6rem"><?= $pendingCount ?></span>
      <?php endif; ?>
    </a>
  </li>
</ul>

<?php if ($viewTab === 'questions'): ?>
<!-- ─── QUESTIONS TAB ──────────────────────────────────────── -->

<div class="d-flex align-items-center justify-content-between mb-3">
  <div class="text-muted small">
    <?= count($questions) ?> question<?= count($questions)!==1?'s':'' ?> &mdash;
    total <strong class="text-dark"><?= number_format($totalQMarks,1) ?> marks</strong>
    <?php if ($viewHw['max_marks']>0 && $totalQMarks !== (float)$viewHw['max_marks']): ?>
    <span class="text-warning ms-1">(homework max_marks is <?= (int)$viewHw['max_marks'] ?>)</span>
    <?php endif; ?>
  </div>
  <button class="btn btn-sm text-white" style="background:var(--tch-green)"
          data-bs-toggle="modal" data-bs-target="#qModal">
    <i class="fas fa-plus me-1"></i>Add Question
  </button>
</div>

<?php if (empty($questions)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-question-circle fa-3x mb-3 d-block opacity-25"></i>
    <h6>No questions yet</h6>
    <p class="small mb-3">Add questions so students can submit answers online.</p>
    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#qModal">
      <i class="fas fa-plus me-1"></i>Add First Question
    </button>
  </div>
</div>
<?php else: ?>
<div class="d-flex flex-column gap-2">
  <?php foreach ($questions as $qi => $q): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body py-3">
      <div class="d-flex align-items-start gap-3">
        <div class="flex-shrink-0 d-flex align-items-center justify-content-center fw-bold text-white rounded"
             style="width:32px;height:32px;background:var(--tch-green);font-size:.82rem;flex-shrink:0">
          <?= $qi+1 ?>
        </div>
        <div class="flex-grow-1">
          <p class="mb-2 fw-semibold" style="line-height:1.55"><?= nl2br(e($q['question_text'])) ?></p>
          <span class="badge" style="background:var(--tch-green-pale);color:var(--tch-green);font-size:.7rem">
            <i class="fas fa-star me-1"></i><?= number_format($q['marks'],1) ?> mark<?= $q['marks']!=1?'s':'' ?>
          </span>
        </div>
        <div class="flex-shrink-0 d-flex gap-1">
          <button class="btn btn-sm btn-outline-secondary"
                  onclick="openEditQ(<?= $q['id'] ?>, <?= json_encode($q['question_text']) ?>, <?= $q['marks'] ?>, <?= $q['sort_order'] ?>)"
                  data-bs-toggle="modal" data-bs-target="#qModal">
            <i class="fas fa-edit"></i>
          </button>
          <form method="POST" class="d-inline"
                onsubmit="return confirm('Delete this question and all student answers to it?')">
            <input type="hidden" name="action" value="delete_hw_question">
            <input type="hidden" name="hw_id"  value="<?= $viewId ?>">
            <input type="hidden" name="q_id"   value="<?= $q['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">
              <i class="fas fa-trash"></i>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add / Edit Question Modal -->
<div class="modal fade" id="qModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow">
      <div class="modal-header" style="background:var(--tch-green-pale)">
        <h6 class="modal-title fw-bold" id="qModalTitle">
          <i class="fas fa-question-circle me-2"></i>Add Question
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="action"  value="save_hw_question">
          <input type="hidden" name="hw_id"   value="<?= $viewId ?>">
          <input type="hidden" name="q_id"    id="qId"    value="0">
          <input type="hidden" name="q_order" id="qOrder" value="<?= count($questions) ?>">
          <div class="mb-3">
            <label class="form-label fw-semibold small">
              Question Text <span class="text-danger">*</span>
            </label>
            <textarea class="form-control" name="q_text" id="qText" rows="4" required
                      placeholder="Write the question clearly. Students will answer with free-form text."></textarea>
          </div>
          <div style="max-width:200px">
            <label class="form-label fw-semibold small">Marks for this question</label>
            <div class="input-group input-group-sm">
              <input type="number" class="form-control" name="q_marks" id="qMarks"
                     value="1" min="0" max="100" step="0.5" required>
              <span class="input-group-text">marks</span>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm text-white" style="background:var(--tch-green)">
            <i class="fas fa-save me-1"></i><span id="qSaveLbl">Save Question</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php elseif ($viewTab === 'submissions'): ?>
<!-- ─── SUBMISSIONS & MARKING TAB ─────────────────────────── -->

<?php if (empty($questions)): ?>
<div class="alert alert-warning border-0 shadow-sm">
  <i class="fas fa-exclamation-triangle me-2"></i>
  No questions have been added to this homework yet.
  Add questions in the <a href="homework.php?view=<?= $viewId ?>&tab=questions"
  class="fw-semibold">Questions</a> tab before students can submit answers.
</div>

<?php elseif ($viewStudent && $studentData): ?>
<!-- ─── Mark one student ──────────────────────────────────── -->
<div class="d-flex align-items-center gap-3 mb-4">
  <a href="homework.php?view=<?= $viewId ?>&tab=submissions" class="btn btn-sm btn-outline-secondary">
    <i class="fas fa-arrow-left me-1"></i>All Students
  </a>
  <div>
    <h6 class="fw-bold mb-0">
      <i class="fas fa-user-pen me-1" style="color:var(--tch-green)"></i>
      Marking: <?= e($studentData['first_name'].' '.$studentData['last_name']) ?>
    </h6>
    <div class="text-muted small">Adm No: <?= e($studentData['admission_no'] ?? '—') ?></div>
  </div>
</div>

<?php
  $existingSub = $submissionsMap[$viewStudent] ?? null;
  $alreadyMarked = $existingSub && $existingSub['status'] === 'marked';
?>
<?php if ($alreadyMarked): ?>
<div class="alert alert-info border-0 mb-3" style="font-size:.875rem">
  <i class="fas fa-check-circle me-2"></i>
  Previously marked — total <strong><?= number_format($existingSub['marks_obtained'],1) ?></strong>
  / <?= number_format($totalQMarks,1) ?> marks. You can adjust marks below and re-save.
</div>
<?php endif; ?>

<form method="POST">
  <input type="hidden" name="action"     value="mark_submission">
  <input type="hidden" name="hw_id"      value="<?= $viewId ?>">
  <input type="hidden" name="student_id" value="<?= $viewStudent ?>">

  <?php foreach ($questions as $qi => $q):
    $ans = $studentAnswers[$q['id']] ?? null;
  ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <!-- Question header -->
      <div class="d-flex align-items-start gap-3 mb-3">
        <div class="d-flex align-items-center justify-content-center fw-bold text-white rounded flex-shrink-0"
             style="width:28px;height:28px;background:var(--tch-green);font-size:.78rem">
          <?= $qi+1 ?>
        </div>
        <div class="flex-grow-1">
          <p class="mb-1 fw-semibold"><?= nl2br(e($q['question_text'])) ?></p>
          <span class="badge bg-secondary" style="font-size:.65rem">
            Max marks: <?= number_format($q['marks'],1) ?>
          </span>
        </div>
      </div>

      <!-- Student answer -->
      <div class="rounded p-3 mb-3"
           style="background:#f8f9fa;border-left:3px solid <?= ($ans && trim($ans['answer_text'])) ? 'var(--tch-green)' : '#adb5bd' ?>">
        <div class="fw-semibold text-muted mb-1" style="font-size:.75rem">
          <i class="fas fa-user-edit me-1"></i>STUDENT'S ANSWER
        </div>
        <?php if ($ans && trim($ans['answer_text'])): ?>
        <p class="mb-0 small" style="white-space:pre-wrap;line-height:1.65"><?= e($ans['answer_text']) ?></p>
        <?php else: ?>
        <p class="text-muted mb-0 small fst-italic">No answer submitted for this question.</p>
        <?php endif; ?>
      </div>

      <!-- Marks input + feedback -->
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label fw-semibold small">
            Marks Awarded
            <span class="text-muted fw-normal">(max <?= number_format($q['marks'],1) ?>)</span>
          </label>
          <input type="number"
                 class="form-control form-control-sm"
                 name="q_marks[<?= $q['id'] ?>]"
                 value="<?= $ans['marks_awarded'] ?? 0 ?>"
                 min="0" max="<?= $q['marks'] ?>" step="0.5" required>
        </div>
        <div class="col-md-9">
          <label class="form-label fw-semibold small">Teacher Feedback (optional)</label>
          <input type="text"
                 class="form-control form-control-sm"
                 name="q_feedback[<?= $q['id'] ?>]"
                 value="<?= e($ans['feedback'] ?? '') ?>"
                 placeholder="Brief comment the student will see…">
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="d-flex gap-2 mt-1 mb-4">
    <button type="submit" class="btn btn-success px-4">
      <i class="fas fa-save me-1"></i>Save Marks
    </button>
    <a href="homework.php?view=<?= $viewId ?>&tab=submissions" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<?php else: ?>
<!-- ─── All students list ─────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="text-muted small">
    <i class="fas fa-users me-1"></i>
    <?= count($classStudents) ?> student<?= count($classStudents)!==1?'s':'' ?>
    in <strong class="text-dark"><?= e($viewHw['class_name']) ?></strong>
    &nbsp;&middot;&nbsp; Total: <strong class="text-dark"><?= number_format($totalQMarks,1) ?></strong> marks
  </div>
  <?php
    $submittedCount = count(array_filter($submissionsMap, fn($s)=>in_array($s['status'],['submitted','marked'])));
    $markedCount    = count(array_filter($submissionsMap, fn($s)=>$s['status']==='marked'));
  ?>
  <div class="d-flex gap-2 text-muted small">
    <span><i class="fas fa-inbox me-1"></i><?= $submittedCount ?> submitted</span>
    <span><i class="fas fa-check-circle me-1 text-success"></i><?= $markedCount ?> marked</span>
  </div>
</div>

<?php if (empty($classStudents)): ?>
<div class="alert alert-info border-0">
  <i class="fas fa-info-circle me-2"></i>No active students found in this class.
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Student</th>
          <th>Adm No</th>
          <th class="text-center">Status</th>
          <th class="text-center">Score</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($classStudents as $stu):
          $sub       = $submissionsMap[$stu['id']] ?? null;
          $submitted = $sub && in_array($sub['status'], ['submitted','marked']);
        ?>
        <tr>
          <td class="fw-semibold small"><?= e($stu['first_name'].' '.$stu['last_name']) ?></td>
          <td class="small text-muted"><?= e($stu['admission_no'] ?? '—') ?></td>
          <td class="text-center">
            <?php if (!$sub || $sub['status'] === 'pending'): ?>
            <span class="badge bg-secondary" style="font-size:.62rem">Not Submitted</span>
            <?php elseif ($sub['status'] === 'submitted'): ?>
            <span class="badge bg-primary" style="font-size:.62rem">
              <i class="fas fa-clock me-1"></i>Submitted
            </span>
            <?php else: ?>
            <span class="badge bg-success" style="font-size:.62rem">
              <i class="fas fa-check me-1"></i>Marked
            </span>
            <?php endif; ?>
          </td>
          <td class="text-center small">
            <?php if ($sub && $sub['marks_obtained'] !== null): ?>
            <strong><?= number_format($sub['marks_obtained'],1) ?></strong>
            <span class="text-muted">/ <?= number_format($totalQMarks,1) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($submitted): ?>
            <a href="homework.php?view=<?= $viewId ?>&tab=submissions&student=<?= $stu['id'] ?>"
               class="btn btn-sm <?= ($sub['status']==='marked') ? 'btn-outline-secondary' : 'btn-success' ?>">
              <i class="fas fa-<?= ($sub['status']==='marked') ? 'eye' : 'pen' ?> me-1"></i>
              <?= ($sub['status']==='marked') ? 'Review' : 'Mark' ?>
            </a>
            <?php else: ?>
            <span class="text-muted small">Awaiting submission</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; // end submissions inner branches ?>

<?php endif; // end tab switch ?>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════
     LIST VIEW
══════════════════════════════════════════════════════════════ -->

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="fas fa-book-open me-2" style="color:var(--tch-green)"></i>Homework
  </h5>
  <button class="btn btn-sm text-white" style="background:var(--tch-green)"
          data-bs-toggle="collapse" data-bs-target="#hwForm">
    <i class="fas fa-plus me-1"></i><?= $editHw ? 'Edit Assignment' : 'New Assignment' ?>
  </button>
</div>

<!-- Create / Edit form -->
<div class="collapse <?= ($editHw || !empty($saveErr)) ? 'show' : '' ?> mb-4" id="hwForm">
  <div class="card border-0 shadow-sm">
    <div class="card-header">
      <h6 class="mb-0 fw-bold">
        <?= $editHw ? 'Edit Homework: '.e($editHw['title']) : 'New Homework Assignment' ?>
      </h6>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="<?= $editHw ? 'edit' : 'create' ?>">
        <?php if ($editHw): ?>
        <input type="hidden" name="hw_id" value="<?= $editHw['id'] ?>">
        <?php endif; ?>

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Class <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm" name="class_id" id="classSelect" required>
              <option value="">Select class</option>
              <?php
                $classList = [];
                foreach ($myClassSubjects as $cs) {
                    if (!isset($classList[$cs['class_id']])) $classList[$cs['class_id']] = $cs['class_name'];
                }
                foreach ($classList as $cid => $cname):
              ?>
              <option value="<?= $cid ?>" <?= ($editHw && (int)$editHw['class_id']===$cid) ? 'selected' : '' ?>>
                <?= e($cname) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Subject <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm" name="subject_id" id="subjectSelect" required>
              <option value="">Select subject</option>
              <?php foreach ($myClassSubjects as $cs): ?>
              <option value="<?= $cs['subject_id'] ?>"
                      data-class="<?= $cs['class_id'] ?>"
                      <?= ($editHw && (int)$editHw['subject_id']===(int)$cs['subject_id'] && (int)$editHw['class_id']===(int)$cs['class_id']) ? 'selected' : '' ?>>
                <?= e($cs['subject_name']) ?> (<?= e($cs['class_name']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Term</label>
            <select class="form-select form-select-sm" name="term_id">
              <?php foreach ($terms as $t): ?>
              <option value="<?= $t['id'] ?>"
                      <?= ($editHw ? (int)$editHw['term_id']===(int)$t['id'] : (int)$t['id']===$currentTermId) ? 'selected' : '' ?>>
                <?= e($t['name']) ?><?= $t['status']==='active' ? ' (Current)' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-sm" name="title" required
                   value="<?= e($editHw['title'] ?? '') ?>"
                   placeholder="e.g. Chapter 5 Practice Questions">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">Description</label>
            <textarea class="form-control form-control-sm" name="description" rows="2"
                      placeholder="Brief overview of the assignment"><?= e($editHw['description'] ?? '') ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">Instructions for Students</label>
            <textarea class="form-control form-control-sm" name="instructions" rows="3"
                      placeholder="Detailed instructions students will see"><?= e($editHw['instructions'] ?? '') ?></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Due Date</label>
            <input type="date" class="form-control form-control-sm" name="due_date"
                   value="<?= e($editHw['due_date'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Max Marks</label>
            <input type="number" class="form-control form-control-sm" name="max_marks" min="0"
                   value="<?= (int)($editHw['max_marks'] ?? 0) ?>" placeholder="0 = ungraded">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Status</label>
            <select class="form-select form-select-sm" name="status">
              <option value="draft"  <?= ($editHw['status']??'active')==='draft'  ? 'selected':'' ?>>Draft (not visible)</option>
              <option value="active" <?= ($editHw['status']??'active')==='active' ? 'selected':'' ?>>Active (students can see it)</option>
              <option value="closed" <?= ($editHw['status']??'active')==='closed' ? 'selected':'' ?>>Closed</option>
            </select>
          </div>
        </div>

        <div class="d-flex gap-2 mt-3">
          <button type="submit" class="btn btn-success btn-sm px-3">
            <i class="fas fa-save me-1"></i><?= $editHw ? 'Update' : 'Assign Homework' ?>
          </button>
          <a href="homework.php" class="btn btn-outline-secondary btn-sm">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Filter tabs -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php foreach (['all'=>'All','active'=>'Active','draft'=>'Drafts','closed'=>'Closed'] as $val => $lbl): ?>
  <a href="?status=<?= $val ?>"
     class="btn btn-sm <?= $filterStatus===$val ? 'btn-success' : 'btn-outline-secondary' ?>">
    <?= $lbl ?>
    <?php if ($val==='all'): ?>
    <span class="badge bg-white text-dark ms-1" style="font-size:.6rem"><?= count($hwList) ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Homework cards -->
<?php if (empty($hwList)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-book-open fa-3x mb-3 d-block opacity-25"></i>
    <h6>No homework assignments yet</h6>
    <p class="small">Create your first assignment using the button above.</p>
  </div>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($hwList as $hw):
    $isOverdue = $hw['status']==='active' && !empty($hw['due_date']) && $hw['due_date'] < date('Y-m-d');
    $borderColor = $isOverdue ? '#e74c3c' : ($hw['status']==='active' ? '#1A8A4E' : ($hw['status']==='draft' ? '#f39c12' : '#adb5bd'));
  ?>
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm h-100"
         style="border-left:4px solid <?= $borderColor ?>!important">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
          <h6 class="fw-bold mb-0 <?= $isOverdue?'text-danger':'' ?>"><?= e($hw['title']) ?></h6>
          <span class="badge bg-<?= $statusBadges[$hw['status']] ?? 'secondary' ?> flex-shrink-0">
            <?= ucfirst($hw['status']) ?>
          </span>
        </div>
        <div class="text-muted small mb-2">
          <i class="fas fa-chalkboard me-1"></i><?= e($hw['class_name']) ?>
          &nbsp;&middot;&nbsp;<i class="fas fa-book me-1"></i><?= e($hw['subject_name']) ?>
          <?php if (!empty($hw['due_date'])): ?>
          &nbsp;&middot;&nbsp;<i class="fas fa-calendar-times me-1 <?= $isOverdue?'text-danger':'' ?>"></i>
          Due <?= date('d M Y', strtotime($hw['due_date'])) ?>
          <?php if ($isOverdue): ?><span class="text-danger"> (Overdue)</span><?php endif; ?>
          <?php endif; ?>
        </div>

        <!-- Question / submission stats -->
        <div class="d-flex gap-3 mb-3" style="font-size:.78rem">
          <span class="text-muted">
            <i class="fas fa-question-circle me-1"></i>
            <strong class="text-dark"><?= (int)$hw['q_count'] ?></strong> question<?= (int)$hw['q_count']!==1?'s':'' ?>
          </span>
          <span class="text-muted">
            <i class="fas fa-inbox me-1"></i>
            <strong class="text-dark"><?= (int)$hw['sub_count'] ?></strong> submitted
            <?php if ((int)$hw['pending_mark_count']>0): ?>
            <span class="badge bg-danger ms-1" style="font-size:.58rem">
              <?= (int)$hw['pending_mark_count'] ?> to mark
            </span>
            <?php endif; ?>
          </span>
        </div>

        <?php if (!empty($hw['description'])): ?>
        <p class="text-muted mb-2" style="font-size:.83rem;line-height:1.55">
          <?= nl2br(e(mb_strimwidth($hw['description'],0,120,'…'))) ?>
        </p>
        <?php endif; ?>

        <div class="d-flex gap-2 mt-3 flex-wrap">
          <!-- Questions -->
          <a href="homework.php?view=<?= $hw['id'] ?>&tab=questions"
             class="btn btn-sm btn-outline-primary">
            <i class="fas fa-question-circle me-1"></i>Questions (<?= (int)$hw['q_count'] ?>)
          </a>
          <!-- Submissions -->
          <a href="homework.php?view=<?= $hw['id'] ?>&tab=submissions"
             class="btn btn-sm <?= (int)$hw['pending_mark_count']>0 ? 'btn-warning text-dark' : 'btn-outline-secondary' ?>">
            <i class="fas fa-inbox me-1"></i>
            Submissions
            <?php if ((int)$hw['pending_mark_count']>0): ?>
            <span class="badge bg-danger ms-1" style="font-size:.6rem"><?= (int)$hw['pending_mark_count'] ?></span>
            <?php endif; ?>
          </a>
          <!-- Edit -->
          <a href="?edit=<?= $hw['id'] ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-edit me-1"></i>Edit
          </a>
          <!-- Close / Reopen -->
          <?php if ($hw['status'] === 'active'): ?>
          <form method="POST" class="d-inline">
            <input type="hidden" name="action"     value="status_change">
            <input type="hidden" name="hw_id"      value="<?= $hw['id'] ?>">
            <input type="hidden" name="new_status" value="closed">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
              <i class="fas fa-lock me-1"></i>Close
            </button>
          </form>
          <?php else: ?>
          <form method="POST" class="d-inline">
            <input type="hidden" name="action"     value="status_change">
            <input type="hidden" name="hw_id"      value="<?= $hw['id'] ?>">
            <input type="hidden" name="new_status" value="active">
            <button type="submit" class="btn btn-sm btn-outline-success">
              <i class="fas fa-unlock me-1"></i>Re-open
            </button>
          </form>
          <?php endif; ?>
          <!-- Delete -->
          <form method="POST" class="d-inline"
                onsubmit="return confirm('Delete this assignment and all student answers? This cannot be undone.')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="hw_id"  value="<?= $hw['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; // end list vs detail view ?>

<?php
$extraJs = '<script>
// ── Question modal ────────────────────────────────────────────
function openEditQ(qId, qText, qMarks, qOrder) {
    document.getElementById("qModalTitle").innerHTML = \'<i class="fas fa-edit me-2"></i>Edit Question\';
    document.getElementById("qId").value    = qId;
    document.getElementById("qText").value  = qText;
    document.getElementById("qMarks").value = qMarks;
    document.getElementById("qOrder").value = qOrder;
    document.getElementById("qSaveLbl").textContent = "Update Question";
}
var qModal = document.getElementById("qModal");
if (qModal) {
    qModal.addEventListener("hidden.bs.modal", function () {
        document.getElementById("qModalTitle").innerHTML = \'<i class="fas fa-question-circle me-2"></i>Add Question\';
        document.getElementById("qId").value    = "0";
        document.getElementById("qText").value  = "";
        document.getElementById("qMarks").value = "1";
        document.getElementById("qSaveLbl").textContent = "Save Question";
    });
}
// ── Class → subject filter ────────────────────────────────────
var classSelect = document.getElementById("classSelect");
if (classSelect) {
    classSelect.addEventListener("change", function () {
        var cid = this.value;
        var sel = document.getElementById("subjectSelect");
        Array.from(sel.options).forEach(function(o){
            if (!o.value) return;
            o.hidden = cid && o.dataset.class !== cid;
        });
        sel.value = "";
    });
}
</script>';
require_once __DIR__ . '/../includes/footer-teacher.php';
?>
