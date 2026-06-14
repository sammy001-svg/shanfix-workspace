<?php
$pageTitle = 'Homework';
require_once __DIR__ . '/../includes/header-student.php';

$tab = ($_GET['tab'] ?? '') === 'closed' ? 'closed' : 'active';

// ── Homework for student's class ─────────────────────────────────
$homework = [];
try {
    $whereStatus = $tab === 'closed'
        ? "hw.status = 'closed'"
        : "hw.status IN ('active','draft')";
    $s = $pdo->prepare(
        "SELECT hw.id, hw.title, hw.description, hw.due_date, hw.status, hw.created_at,
                sub.name AS subject_name, sub.code AS subject_code,
                CONCAT(t.first_name,' ',t.last_name) AS teacher_name
         FROM sch_homework hw
         LEFT JOIN sch_subjects sub ON hw.subject_id = sub.id
         LEFT JOIN sch_teachers t   ON hw.teacher_id = t.id
         WHERE hw.class_id=? AND hw.org_id=? AND $whereStatus
         ORDER BY hw.due_date ASC, hw.created_at DESC"
    );
    $s->execute([$stuClassId, $stuOrgId]);
    $homework = $s->fetchAll();
} catch (Throwable $e) {}

$today = date('Y-m-d');
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

function renderHwList(array $list, string $accentColor, string $today): void {
    global $statusBadge;
    foreach ($list as $hw):
        $isOverdue = !empty($hw['due_date']) && $hw['due_date'] < $today && $hw['status'] !== 'closed';
        $isToday   = !empty($hw['due_date']) && $hw['due_date'] === $today;
        $border    = $isOverdue ? '#e74c3c' : ($isToday ? '#f39c12' : $accentColor);
?>
<div class="card border-0 shadow-sm mb-3" style="border-left:4px solid <?= $border ?>!important">
  <div class="card-body">
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
          <span class="fw-bold"><?= e($hw['title']) ?></span>
          <span class="badge bg-<?= $statusBadge[$hw['status']] ?? 'secondary' ?>" style="font-size:.65rem">
            <?= ucfirst($hw['status']) ?>
          </span>
          <?php if ($isOverdue): ?>
          <span class="badge bg-danger" style="font-size:.65rem">Overdue</span>
          <?php elseif ($isToday): ?>
          <span class="badge bg-warning text-dark" style="font-size:.65rem">Due Today</span>
          <?php endif; ?>
        </div>
        <div class="small text-muted mb-2 d-flex flex-wrap gap-2">
          <?php if (!empty($hw['subject_name'])): ?>
          <span><i class="fas fa-book me-1"></i><?= e($hw['subject_name']) ?>
            <?php if (!empty($hw['subject_code'])): ?><span class="opacity-75">(<?= e($hw['subject_code']) ?>)</span><?php endif; ?>
          </span>
          <?php endif; ?>
          <?php if (!empty($hw['teacher_name'])): ?>
          <span><i class="fas fa-user-tie me-1"></i><?= e($hw['teacher_name']) ?></span>
          <?php endif; ?>
        </div>
        <?php if (!empty($hw['description'])): ?>
        <p class="small mb-0 text-secondary" style="white-space:pre-line"><?= e($hw['description']) ?></p>
        <?php endif; ?>
      </div>
      <div class="text-end flex-shrink-0">
        <?php if (!empty($hw['due_date'])): ?>
        <div class="small fw-semibold <?= $isOverdue ? 'text-danger' : ($isToday ? 'text-warning' : 'text-muted') ?>">
          <i class="fas fa-calendar-day me-1"></i><?= date('d M Y', strtotime($hw['due_date'])) ?>
        </div>
        <div class="text-muted" style="font-size:.65rem">Due date</div>
        <?php else: ?>
        <span class="badge bg-secondary" style="font-size:.65rem">No deadline</span>
        <?php endif; ?>
        <div class="text-muted mt-1" style="font-size:.68rem">
          Posted <?= date('d M', strtotime($hw['created_at'])) ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
    endforeach;
}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="fas fa-book-open me-2" style="color:var(--stu-blue)"></i>Homework</h5>
  <div class="d-flex gap-2">
    <a href="?tab=active"  class="btn btn-sm <?= $tab==='active'  ? 'btn-primary' : 'btn-outline-secondary' ?>">Active</a>
    <a href="?tab=closed"  class="btn btn-sm <?= $tab==='closed'  ? 'btn-primary' : 'btn-outline-secondary' ?>">Closed</a>
  </div>
</div>

<?php if ($tab === 'active'): ?>

<?php if (!empty($overdue)): ?>
<h6 class="fw-bold text-danger mb-2"><i class="fas fa-exclamation-circle me-1"></i>Overdue (<?= count($overdue) ?>)</h6>
<?php renderHwList($overdue, '#e74c3c', $today); ?>
<?php endif; ?>

<?php if (!empty($dueToday)): ?>
<h6 class="fw-bold text-warning mb-2 <?= !empty($overdue)?'mt-4':'' ?>">
  <i class="fas fa-clock me-1"></i>Due Today (<?= count($dueToday) ?>)
</h6>
<?php renderHwList($dueToday, '#f39c12', $today); ?>
<?php endif; ?>

<?php if (!empty($upcoming)): ?>
<h6 class="fw-bold text-secondary mb-2 <?= (!empty($overdue)||!empty($dueToday))?'mt-4':'' ?>">
  <i class="fas fa-calendar-alt me-1"></i>Upcoming (<?= count($upcoming) ?>)
</h6>
<?php renderHwList($upcoming, '#1d4ed8', $today); ?>
<?php endif; ?>

<?php if (empty($overdue) && empty($dueToday) && empty($upcoming)): ?>
<div class="text-center py-5 text-muted">
  <i class="fas fa-check-circle fa-3x mb-3 d-block text-success opacity-50"></i>
  <h6 class="text-success">All clear! No active homework.</h6>
  <p class="small mb-0">Check the <a href="?tab=closed">Closed</a> tab for past assignments.</p>
</div>
<?php endif; ?>

<?php else: // closed tab ?>

<?php if (empty($homework)): ?>
<div class="text-center py-5 text-muted">
  <i class="fas fa-archive fa-3x mb-3 d-block opacity-25"></i>
  <h6>No closed assignments yet</h6>
</div>
<?php else: ?>
<?php renderHwList($homework, '#6c757d', $today); ?>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-student.php'; ?>
