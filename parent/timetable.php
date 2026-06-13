<?php
$pageTitle = 'Timetable';
require_once __DIR__ . '/../includes/header-parent.php';

$classId = (int)($activeStudent['class_id'] ?? 0);

// ── Weekly class schedule ───────────────────────────────────────
$slots = [];
if ($classId) {
    try {
        $s = $pdo->prepare(
            "SELECT t.*, sub.name AS subject_name, sub.code AS subject_code,
                    CONCAT(st.first_name,' ',st.last_name) AS teacher_name, t.room
             FROM sch_timetable t
             LEFT JOIN sch_subjects sub ON t.subject_id = sub.id
             LEFT JOIN sch_teachers st ON t.staff_id = st.id
             WHERE t.org_id=? AND t.class_id=?
             ORDER BY t.day_of_week, t.period"
        );
        $s->execute([$parOrgId, $classId]);
        $slots = $s->fetchAll();
    } catch (Throwable $e) {}
}

$days = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday'];
$grid = [];
foreach ($slots as $slot) {
    $grid[$slot['day_of_week']][$slot['period']] = $slot;
}
$maxPeriod = !empty($slots) ? max(array_column($slots, 'period')) : 0;

// ── Upcoming exam timetable ─────────────────────────────────────
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

$schedule = [];
if ($classId && !empty($exams)) {
    $examIds = implode(',', array_map('intval', array_column($exams, 'id')));
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

$activeTab = $_GET['tab'] ?? 'weekly';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-calendar-week me-2" style="color:var(--par-green)"></i>Timetable</h5>
    <?php if (!empty($activeStudent['class_name'])): ?>
    <div class="text-muted small mt-1"><i class="fas fa-chalkboard me-1"></i><?= e($activeStudent['class_name']) ?></div>
    <?php endif; ?>
  </div>
  <a href="?tab=<?= $activeTab === 'weekly' ? 'exams' : 'weekly' ?>"
     class="btn btn-sm btn-outline-secondary">
    <i class="fas fa-exchange-alt me-1"></i>
    <?= $activeTab === 'weekly' ? 'Exam Schedule' : 'Weekly Schedule' ?>
  </a>
</div>

<!-- Tabs -->
<ul class="nav nav-pills mb-4 gap-2">
  <li class="nav-item">
    <a href="?tab=weekly" class="nav-link <?= $activeTab === 'weekly' ? 'active' : '' ?>" style="<?= $activeTab === 'weekly' ? 'background:var(--par-green)' : '' ?>">
      <i class="fas fa-calendar-alt me-1"></i>Weekly Schedule
    </a>
  </li>
  <li class="nav-item">
    <a href="?tab=exams" class="nav-link <?= $activeTab === 'exams' ? 'active' : '' ?>" style="<?= $activeTab === 'exams' ? 'background:var(--par-green)' : '' ?>">
      <i class="fas fa-file-alt me-1"></i>Exam Timetable
      <?php if (!empty($exams)): ?>
      <span class="badge bg-white text-dark ms-1" style="font-size:.65rem"><?= count($exams) ?></span>
      <?php endif; ?>
    </a>
  </li>
</ul>

<?php if ($activeTab === 'weekly'): ?>
<!-- ── Weekly class schedule ─────────────────────────────────── -->
<?php if (!$classId): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-calendar-alt fa-3x mb-3 d-block opacity-25"></i>
    <h6>No class assigned</h6>
    <p class="small">Your child has not been assigned to a class yet.</p>
  </div>
</div>
<?php elseif (empty($slots)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-calendar-alt fa-3x mb-3 d-block opacity-25"></i>
    <h6>No timetable available</h6>
    <p class="small">The class timetable has not been set up yet. Check back later.</p>
  </div>
</div>
<?php else: ?>

<!-- Desktop grid -->
<div class="card border-0 shadow-sm d-none d-md-block mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-table me-2" style="color:var(--par-green)"></i>
      Weekly Schedule &mdash; <?= e($activeStudent['class_name'] ?? '') ?>
    </h6>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-bordered mb-0 text-center small">
        <thead>
          <tr style="background:var(--par-green);color:#fff">
            <th style="width:60px">Period</th>
            <?php foreach ($days as $d => $dName): ?>
            <th><?= $dName ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php for ($p = 1; $p <= $maxPeriod; $p++): ?>
          <tr>
            <td class="fw-bold text-muted align-middle"><?= $p ?></td>
            <?php foreach ($days as $d => $dName):
              $slot = $grid[$d][$p] ?? null;
            ?>
            <td class="p-1 align-middle" style="min-width:110px">
              <?php if ($slot): ?>
              <div class="p-2 rounded-2" style="background:var(--par-green-pale);border-left:3px solid var(--par-green)">
                <div class="fw-semibold" style="color:var(--par-navy);font-size:.78rem">
                  <?= e($slot['subject_name'] ?? 'Free') ?>
                </div>
                <div class="text-muted" style="font-size:.68rem">
                  <?= date('H:i', strtotime($slot['start_time'])) ?>
                  &ndash;
                  <?= date('H:i', strtotime($slot['end_time'])) ?>
                </div>
                <?php if (!empty($slot['teacher_name'])): ?>
                <div class="text-muted" style="font-size:.66rem"><i class="fas fa-user me-1"></i><?= e($slot['teacher_name']) ?></div>
                <?php endif; ?>
                <?php if (!empty($slot['room'])): ?>
                <div class="text-muted" style="font-size:.65rem"><i class="fas fa-map-marker-alt me-1"></i><?= e($slot['room']) ?></div>
                <?php endif; ?>
              </div>
              <?php else: ?>
              <span class="text-muted opacity-50">&mdash;</span>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Mobile card view -->
<div class="d-md-none">
  <?php foreach ($days as $d => $dName): ?>
  <?php
  $daySlots = [];
  for ($p = 1; $p <= $maxPeriod; $p++) {
      if (isset($grid[$d][$p])) $daySlots[$p] = $grid[$d][$p];
  }
  if (empty($daySlots)) continue;
  ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header" style="background:var(--par-green);color:#fff">
      <h6 class="mb-0 fw-bold"><?= $dName ?></h6>
    </div>
    <div class="card-body p-0">
      <?php foreach ($daySlots as $period => $slot): ?>
      <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
        <div class="text-center flex-shrink-0" style="width:32px">
          <div class="fw-bold" style="color:var(--par-green);font-size:1rem"><?= $period ?></div>
          <div class="text-muted" style="font-size:.6rem">period</div>
        </div>
        <div class="flex-grow-1">
          <div class="fw-semibold small"><?= e($slot['subject_name'] ?? 'Free') ?></div>
          <div class="text-muted" style="font-size:.72rem">
            <?= date('H:i', strtotime($slot['start_time'])) ?> &ndash; <?= date('H:i', strtotime($slot['end_time'])) ?>
            <?php if (!empty($slot['teacher_name'])): ?>
            &nbsp;&middot;&nbsp; <?= e($slot['teacher_name']) ?>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($slot['room'])): ?>
        <span class="badge bg-secondary bg-opacity-25 text-dark" style="font-size:.65rem"><?= e($slot['room']) ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; // end weekly schedule ?>

<?php else: ?>
<!-- ── Exam Timetable ─────────────────────────────────────────── -->

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
    <div style="width:10px;height:10px;border-radius:50%;background:<?= $exam['status']==='ongoing'?'#27ae60':'#3498db' ?>;flex-shrink:0"></div>
    <div class="flex-grow-1">
      <div class="fw-bold"><?= e($exam['name']) ?></div>
      <div class="small text-muted">
        <?= date('d M Y', strtotime($exam['start_date'])) ?> &ndash;
        <?= date('d M Y', strtotime($exam['end_date'])) ?>
        &nbsp;&middot;&nbsp;
        <span class="badge <?= $exam['status']==='ongoing'?'bg-success':'bg-primary' ?> bg-opacity-25 <?= $exam['status']==='ongoing'?'text-success':'text-primary' ?>">
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
            <th class="text-center d-none d-md-table-cell">Marks</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($schedule[$exam['id']] as $slot):
            $isPast  = !empty($slot['exam_date']) && $slot['exam_date'] < date('Y-m-d');
            $isToday = !empty($slot['exam_date']) && $slot['exam_date'] === date('Y-m-d');
          ?>
          <tr class="<?= $isToday ? 'table-success' : ($isPast ? 'table-light text-muted' : '') ?>">
            <td>
              <div class="fw-semibold small"><?= $slot['exam_date'] ? date('d M Y', strtotime($slot['exam_date'])) : '&mdash;' ?></div>
              <?php if ($isToday): ?><span class="badge bg-success" style="font-size:.65rem">Today</span><?php endif; ?>
              <?php if ($isPast):  ?><span class="badge bg-secondary bg-opacity-25 text-muted" style="font-size:.65rem">Done</span><?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold small"><?= e($slot['subject_name']) ?></div>
              <?php if (!empty($slot['subject_code'])): ?><div class="text-muted" style="font-size:.7rem"><?= e($slot['subject_code']) ?></div><?php endif; ?>
            </td>
            <td class="text-center small"><?= $slot['start_time'] ? date('h:i A', strtotime($slot['start_time'])) : '&mdash;' ?></td>
            <td class="text-center small"><?= $slot['end_time']   ? date('h:i A', strtotime($slot['end_time']))   : '&mdash;' ?></td>
            <td class="small text-muted d-none d-md-table-cell"><?= e($slot['room'] ?? '&mdash;') ?></td>
            <td class="text-center small d-none d-md-table-cell"><?= $slot['max_marks'] ? $slot['max_marks'] . ' marks' : '&mdash;' ?></td>
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

<?php endif; // end exam tab ?>

<?php require_once __DIR__ . '/../includes/footer-parent.php'; ?>
