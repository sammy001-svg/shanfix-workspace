<?php
$pageTitle = 'Homework';
require_once __DIR__ . '/../includes/header-student.php';

$tab = ($_GET['tab'] ?? '') === 'closed' ? 'closed' : 'active';

// ── Homework for student's class ──────────────────────────────────
$homework = [];
try {
    $whereStatus = $tab === 'closed'
        ? "hw.status = 'closed'"
        : "hw.status IN ('active','draft')";
    $s = $pdo->prepare(
        "SELECT hw.id, hw.title, hw.description, hw.instructions, hw.due_date,
                hw.status, hw.max_marks, hw.created_at,
                sub.name AS subject_name, sub.code AS subject_code,
                CONCAT(t.first_name,' ',t.last_name) AS teacher_name,
                (SELECT COUNT(*) FROM sch_homework_questions q WHERE q.homework_id=hw.id) AS q_count,
                sm.status AS sub_status, sm.marks_obtained, sm.submitted_at, sm.marked_at
         FROM sch_homework hw
         LEFT JOIN sch_subjects sub   ON hw.subject_id = sub.id
         LEFT JOIN sch_teachers t     ON hw.teacher_id = t.id
         LEFT JOIN sch_homework_submissions sm
               ON sm.homework_id = hw.id AND sm.student_id = ? AND sm.org_id = hw.org_id
         WHERE hw.class_id=? AND hw.org_id=? AND $whereStatus
         ORDER BY hw.due_date ASC, hw.created_at DESC"
    );
    $s->execute([$stuId, $stuClassId, $stuOrgId]);
    $homework = $s->fetchAll();
} catch (Throwable $e) {}

// ── Total question marks per homework (for score display) ─────────
$hwMaxMarksMap = [];
try {
    if (!empty($homework)) {
        $ids = implode(',', array_map('intval', array_column($homework, 'id')));
        $s   = $pdo->query(
            "SELECT homework_id, COALESCE(SUM(marks),0) AS total_q_marks
             FROM sch_homework_questions WHERE homework_id IN ($ids) GROUP BY homework_id"
        );
        foreach ($s->fetchAll() as $row) $hwMaxMarksMap[$row['homework_id']] = (float)$row['total_q_marks'];
    }
} catch (Throwable $e) {}

$today       = date('Y-m-d');
$statusBadge = ['active'=>'success','draft'=>'secondary','closed'=>'dark'];

// Bucket by urgency for active tab
$overdue = []; $dueToday = []; $upcoming = [];
if ($tab === 'active') {
    foreach ($homework as $hw) {
        if (empty($hw['due_date']))           { $upcoming[]  = $hw; }
        elseif ($hw['due_date'] < $today)     { $overdue[]   = $hw; }
        elseif ($hw['due_date'] === $today)   { $dueToday[]  = $hw; }
        else                                  { $upcoming[]  = $hw; }
    }
}

function renderStuHwList(array $list, string $accentColor, string $today, array $hwMaxMarksMap, int $stuId): void {
    global $statusBadge;
    foreach ($list as $hw):
        $isOverdue   = !empty($hw['due_date']) && $hw['due_date'] < $today && $hw['status'] !== 'closed';
        $isToday     = !empty($hw['due_date']) && $hw['due_date'] === $today;
        $border      = $isOverdue ? '#e74c3c' : ($isToday ? '#f39c12' : $accentColor);
        $hasQ        = (int)$hw['q_count'] > 0;
        $subStatus   = $hw['sub_status'];
        $submitted   = in_array($subStatus, ['submitted','marked']);
        $marked      = $subStatus === 'marked';
        $totalQMarks = $hwMaxMarksMap[$hw['id']] ?? (float)$hw['max_marks'];
?>
<div class="card border-0 shadow-sm mb-3" style="border-left:4px solid <?= $border ?>!important">
  <div class="card-body">
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
      <div class="flex-grow-1">

        <!-- Title row -->
        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
          <span class="fw-bold"><?= e($hw['title']) ?></span>
          <span class="badge bg-<?= $statusBadge[$hw['status']] ?? 'secondary' ?>" style="font-size:.62rem">
            <?= ucfirst($hw['status']) ?>
          </span>
          <?php if ($isOverdue): ?>
          <span class="badge bg-danger" style="font-size:.62rem">Overdue</span>
          <?php elseif ($isToday): ?>
          <span class="badge bg-warning text-dark" style="font-size:.62rem">Due Today</span>
          <?php endif; ?>

          <!-- Submission status badge -->
          <?php if ($marked): ?>
          <span class="badge bg-success" style="font-size:.62rem">
            <i class="fas fa-check me-1"></i>Marked
          </span>
          <?php elseif ($submitted): ?>
          <span class="badge bg-primary" style="font-size:.62rem">
            <i class="fas fa-clock me-1"></i>Submitted
          </span>
          <?php elseif ($hasQ && $hw['status']==='active'): ?>
          <span class="badge bg-warning text-dark" style="font-size:.62rem">
            <i class="fas fa-pen me-1"></i>Answer Required
          </span>
          <?php endif; ?>
        </div>

        <!-- Meta row -->
        <div class="small text-muted mb-2 d-flex flex-wrap gap-2">
          <?php if (!empty($hw['subject_name'])): ?>
          <span><i class="fas fa-book me-1"></i><?= e($hw['subject_name']) ?>
            <?php if (!empty($hw['subject_code'])): ?>
            <span class="opacity-75">(<?= e($hw['subject_code']) ?>)</span>
            <?php endif; ?>
          </span>
          <?php endif; ?>
          <?php if (!empty($hw['teacher_name'])): ?>
          <span><i class="fas fa-user-tie me-1"></i><?= e($hw['teacher_name']) ?></span>
          <?php endif; ?>
          <?php if ($hasQ): ?>
          <span><i class="fas fa-question-circle me-1"></i><?= (int)$hw['q_count'] ?> question<?= (int)$hw['q_count']!==1?'s':'' ?></span>
          <?php endif; ?>
        </div>

        <?php if (!empty($hw['description'])): ?>
        <p class="small mb-0 text-secondary" style="white-space:pre-line">
          <?= e(mb_strimwidth($hw['description'],0,160,'…')) ?>
        </p>
        <?php endif; ?>

        <!-- Score row (if marked) -->
        <?php if ($marked && $hw['marks_obtained'] !== null && $totalQMarks > 0): ?>
        <div class="mt-2 p-2 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0">
          <span class="fw-bold" style="color:#1A8A4E">
            <?= number_format($hw['marks_obtained'],1) ?> / <?= number_format($totalQMarks,1) ?> marks
          </span>
          <?php
            $pct = $totalQMarks > 0 ? round(($hw['marks_obtained']/$totalQMarks)*100) : 0;
          ?>
          <span class="badge ms-1 <?= $pct>=50?'bg-success':'bg-danger' ?>" style="font-size:.65rem">
            <?= $pct ?>%
          </span>
        </div>
        <?php endif; ?>

        <!-- Action buttons -->
        <div class="d-flex gap-2 mt-3 flex-wrap align-items-center">
          <?php if ($hasQ): ?>
            <?php if (!$submitted && $hw['status']==='active'): ?>
            <a href="<?= APP_URL ?>/student/submit-homework.php?id=<?= $hw['id'] ?>"
               class="btn btn-sm btn-primary">
              <i class="fas fa-pen me-1"></i>Answer Questions
            </a>
            <?php else: ?>
            <a href="<?= APP_URL ?>/student/submit-homework.php?id=<?= $hw['id'] ?>"
               class="btn btn-sm btn-outline-secondary">
              <i class="fas fa-eye me-1"></i>
              <?= $marked ? 'View Marks &amp; Feedback' : 'View My Answers' ?>
            </a>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!empty($hw['instructions']) && !$hasQ): ?>
          <button class="btn btn-sm btn-outline-secondary"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#instr<?= $hw['id'] ?>">
            <i class="fas fa-info-circle me-1"></i>Instructions
          </button>
          <?php endif; ?>
        </div>

        <?php if (!empty($hw['instructions']) && !$hasQ): ?>
        <div class="collapse mt-2" id="instr<?= $hw['id'] ?>">
          <div class="p-2 bg-light rounded small" style="white-space:pre-wrap"><?= e($hw['instructions']) ?></div>
        </div>
        <?php endif; ?>

      </div>

      <!-- Due date column -->
      <div class="text-end flex-shrink-0">
        <?php if (!empty($hw['due_date'])): ?>
        <div class="small fw-semibold <?= $isOverdue ? 'text-danger' : ($isToday ? 'text-warning' : 'text-muted') ?>">
          <i class="fas fa-calendar-day me-1"></i><?= date('d M Y', strtotime($hw['due_date'])) ?>
        </div>
        <div class="text-muted" style="font-size:.65rem">Due date</div>
        <?php else: ?>
        <span class="badge bg-secondary" style="font-size:.62rem">No deadline</span>
        <?php endif; ?>
        <div class="text-muted mt-1" style="font-size:.68rem">
          Posted <?= date('d M', strtotime($hw['created_at'])) ?>
        </div>
        <?php if ($submitted && $hw['submitted_at']): ?>
        <div class="text-muted mt-1" style="font-size:.65rem">
          <i class="fas fa-upload me-1"></i>Submitted <?= date('d M', strtotime($hw['submitted_at'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
    endforeach;
}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="fas fa-book-open me-2" style="color:var(--stu-blue)"></i>Homework
  </h5>
  <div class="d-flex gap-2">
    <a href="?tab=active" class="btn btn-sm <?= $tab==='active' ? 'btn-primary' : 'btn-outline-secondary' ?>">
      Active
    </a>
    <a href="?tab=closed" class="btn btn-sm <?= $tab==='closed' ? 'btn-primary' : 'btn-outline-secondary' ?>">
      Closed
    </a>
  </div>
</div>

<?php if ($tab === 'active'): ?>

<?php if (!empty($overdue)): ?>
<h6 class="fw-bold text-danger mb-2">
  <i class="fas fa-exclamation-circle me-1"></i>Overdue (<?= count($overdue) ?>)
</h6>
<?php renderStuHwList($overdue, '#e74c3c', $today, $hwMaxMarksMap, $stuId); ?>
<?php endif; ?>

<?php if (!empty($dueToday)): ?>
<h6 class="fw-bold text-warning mb-2 <?= !empty($overdue)?'mt-4':'' ?>">
  <i class="fas fa-clock me-1"></i>Due Today (<?= count($dueToday) ?>)
</h6>
<?php renderStuHwList($dueToday, '#f39c12', $today, $hwMaxMarksMap, $stuId); ?>
<?php endif; ?>

<?php if (!empty($upcoming)): ?>
<h6 class="fw-bold text-secondary mb-2 <?= (!empty($overdue)||!empty($dueToday))?'mt-4':'' ?>">
  <i class="fas fa-calendar-alt me-1"></i>Upcoming (<?= count($upcoming) ?>)
</h6>
<?php renderStuHwList($upcoming, '#1d4ed8', $today, $hwMaxMarksMap, $stuId); ?>
<?php endif; ?>

<?php if (empty($overdue) && empty($dueToday) && empty($upcoming)): ?>
<div class="text-center py-5 text-muted">
  <i class="fas fa-check-circle fa-3x mb-3 d-block text-success opacity-50"></i>
  <h6 class="text-success">All clear! No active homework.</h6>
  <p class="small mb-0">
    Check the <a href="?tab=closed">Closed</a> tab for past assignments.
  </p>
</div>
<?php endif; ?>

<?php else: ?>

<?php if (empty($homework)): ?>
<div class="text-center py-5 text-muted">
  <i class="fas fa-archive fa-3x mb-3 d-block opacity-25"></i>
  <h6>No closed assignments yet</h6>
</div>
<?php else: ?>
<?php renderStuHwList($homework, '#6c757d', $today, $hwMaxMarksMap, $stuId); ?>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-student.php'; ?>
