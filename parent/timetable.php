<?php
$pageTitle = 'Exam Timetable';
require_once __DIR__ . '/../includes/header-parent.php';

$classId = (int)($activeStudent['class_id'] ?? 0);

// ── Fetch all upcoming & ongoing exams ──────────────────────────
$exams = [];
try {
    $s = $pdo->prepare(
        "SELECT * FROM sch_exams
         WHERE org_id=? AND status IN ('upcoming','ongoing')
         ORDER BY start_date ASC"
    );
    $s->execute([$parOrgId]);
    $exams = $s->fetchAll();
} catch (Throwable $e) {}

// ── Per-exam schedule for this class ───────────────────────────
$schedule = [];
if ($classId && !empty($exams)) {
    $examIds  = implode(',', array_map('intval', array_column($exams, 'id')));
    try {
        $s = $pdo->prepare(
            "SELECT es.*, sub.name AS subject_name, sub.code AS subject_code
             FROM sch_exam_schedule es
             JOIN sch_subjects sub ON es.subject_id = sub.id
             WHERE es.exam_id IN ($examIds) AND es.class_id=?
             ORDER BY es.exam_date ASC, es.start_time ASC"
        );
        $s->execute([$classId]);
        foreach ($s->fetchAll() as $row) {
            $schedule[$row['exam_id']][] = $row;
        }
    } catch (Throwable $e) {}
}
?>

<div class="d-flex align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="fas fa-calendar-week me-2 text-primary"></i>Exam Timetable</h5>
  <?php if (!empty($activeStudent['class_name'])): ?>
  <span class="badge bg-primary bg-opacity-25 text-primary ms-2"><?= e($activeStudent['class_name']) ?></span>
  <?php endif; ?>
</div>

<?php if (empty($exams)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-calendar-times fa-3x mb-3 d-block opacity-25"></i>
    <h6>No upcoming exams scheduled</h6>
    <p class="small">Exam schedules will appear here when published by the school.</p>
  </div>
</div>
<?php else: ?>

<?php foreach ($exams as $exam): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header d-flex align-items-center gap-3">
    <div style="width:10px;height:10px;border-radius:50%;background:<?= $exam['status']==='ongoing'?'#27ae60':'#3498db' ?>"></div>
    <div>
      <div class="fw-700"><?= e($exam['name']) ?></div>
      <div class="small text-muted">
        <?= date('d M Y', strtotime($exam['start_date'])) ?> &ndash;
        <?= date('d M Y', strtotime($exam['end_date'])) ?>
        &nbsp;&middot;&nbsp;
        <span class="badge <?= $exam['status']==='ongoing'?'bg-success':'bg-primary' ?> bg-opacity-25
                           <?= $exam['status']==='ongoing'?'text-success':'text-primary' ?>">
          <?= ucfirst($exam['status']) ?>
        </span>
      </div>
    </div>
  </div>
  <div class="card-body p-0">
    <?php if (empty($schedule[$exam['id']])): ?>
    <div class="text-center py-3 text-muted small">
      <i class="fas fa-clock me-1"></i>Schedule not yet published for this exam.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Subject</th>
            <th class="text-center">Start</th>
            <th class="text-center">End</th>
            <th class="d-none d-md-table-cell">Room</th>
            <th class="text-center d-none d-md-table-cell">Max Marks</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($schedule[$exam['id']] as $slot):
            $isPast = !empty($slot['exam_date']) && $slot['exam_date'] < date('Y-m-d');
            $isToday = !empty($slot['exam_date']) && $slot['exam_date'] === date('Y-m-d');
          ?>
          <tr class="<?= $isToday ? 'table-success' : ($isPast ? 'table-light text-muted' : '') ?>">
            <td>
              <div class="fw-semibold small"><?= $slot['exam_date'] ? date('d M Y', strtotime($slot['exam_date'])) : '—' ?></div>
              <?php if ($isToday): ?><span class="badge bg-success" style="font-size:.65rem">Today</span><?php endif; ?>
              <?php if ($isPast): ?><span class="badge bg-secondary bg-opacity-25 text-muted" style="font-size:.65rem">Done</span><?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold small"><?= e($slot['subject_name']) ?></div>
              <?php if (!empty($slot['subject_code'])): ?><div class="text-muted" style="font-size:.7rem"><?= e($slot['subject_code']) ?></div><?php endif; ?>
            </td>
            <td class="text-center small"><?= $slot['start_time'] ? date('h:i A', strtotime($slot['start_time'])) : '—' ?></td>
            <td class="text-center small"><?= $slot['end_time']   ? date('h:i A', strtotime($slot['end_time']))   : '—' ?></td>
            <td class="small text-muted d-none d-md-table-cell"><?= e($slot['room'] ?? '—') ?></td>
            <td class="text-center small d-none d-md-table-cell"><?= $slot['max_marks'] ? $slot['max_marks'] . ' marks' : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-parent.php'; ?>
