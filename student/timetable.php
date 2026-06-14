<?php
$pageTitle = 'Timetable';
require_once __DIR__ . '/../includes/header-student.php';

$tab = ($_GET['tab'] ?? '') === 'exams' ? 'exams' : 'weekly';

// ── Weekly class timetable ───────────────────────────────────────
$weeklySlots = [];
if ($tab === 'weekly' && $stuClassId) {
    try {
        $s = $pdo->prepare(
            "SELECT tt.day_of_week, tt.start_time, tt.end_time, tt.room,
                    sub.name AS subject_name, sub.code AS subject_code,
                    CONCAT(t.first_name,' ',t.last_name) AS teacher_name
             FROM sch_timetable tt
             LEFT JOIN sch_subjects sub ON tt.subject_id = sub.id
             LEFT JOIN sch_teachers t   ON tt.staff_id = t.id
             WHERE tt.class_id=? AND tt.org_id=?
             ORDER BY FIELD(tt.day_of_week,1,2,3,4,5,6,7), tt.start_time ASC"
        );
        $s->execute([$stuClassId, $stuOrgId]);
        foreach ($s->fetchAll() as $row) {
            $weeklySlots[$row['day_of_week']][] = $row;
        }
    } catch (Throwable $e) {}
}

// ── Exam timetable ───────────────────────────────────────────────
$examSchedule = [];
if ($tab === 'exams' && $stuClassId) {
    try {
        $s = $pdo->prepare(
            "SELECT e.name AS exam_name, e.academic_year, es.exam_date, es.start_time, es.end_time, es.room,
                    sub.name AS subject_name, sub.code AS subject_code
             FROM sch_exam_schedule es
             JOIN sch_exams e   ON es.exam_id   = e.id
             JOIN sch_subjects sub ON es.subject_id = sub.id
             WHERE es.class_id=? AND es.org_id=?
               AND e.status IN ('active','upcoming','published')
               AND es.exam_date >= CURDATE()
             ORDER BY es.exam_date ASC, es.start_time ASC"
        );
        $s->execute([$stuClassId, $stuOrgId]);
        foreach ($s->fetchAll() as $row) {
            $examSchedule[$row['exam_name']][] = $row;
        }
    } catch (Throwable $e) {}
}

$days = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
$todayDow = (int)date('N'); // 1=Mon … 7=Sun
$periodColors = [
    '#1d4ed8','#7c3aed','#0e7490','#065f46','#b45309','#be185d','#c2410c','#15803d',
];
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="fas fa-calendar-week me-2" style="color:var(--stu-blue)"></i>Timetable</h5>
  <div class="d-flex gap-2">
    <a href="?tab=weekly" class="btn btn-sm <?= $tab==='weekly' ? 'btn-primary' : 'btn-outline-secondary' ?>">
      <i class="fas fa-th-large me-1"></i>Class Schedule
    </a>
    <a href="?tab=exams"  class="btn btn-sm <?= $tab==='exams'  ? 'btn-primary' : 'btn-outline-secondary' ?>">
      <i class="fas fa-file-alt me-1"></i>Exam Timetable
    </a>
  </div>
</div>

<?php if ($tab === 'weekly'): ?>

<?php if (!$stuClassId): ?>
<div class="alert alert-warning border-0"><i class="fas fa-exclamation-triangle me-2"></i>Your class is not assigned yet.</div>
<?php elseif (empty($weeklySlots)): ?>
<div class="text-center py-5 text-muted">
  <i class="fas fa-calendar-xmark fa-3x mb-3 d-block opacity-25"></i>
  <h6>No timetable entries found for your class</h6>
  <p class="small mb-0">Contact your school administrator if this looks incorrect.</p>
</div>
<?php else: ?>

<p class="text-muted small mb-3">
  <i class="fas fa-info-circle me-1" style="color:var(--stu-blue)"></i>
  Class: <strong><?= e($stuClassName) ?></strong>
  <?php if ($todayDow <= 5): ?>
  &nbsp;&middot;&nbsp;Today is <strong><?= $days[$todayDow] ?></strong>
  <?php endif; ?>
</p>

<div class="row g-3">
  <?php foreach ($days as $dow => $dayName):
    if (empty($weeklySlots[$dow])) continue;
    $isToday = $dow === $todayDow;
  ?>
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card border-0 shadow-sm <?= $isToday ? 'border border-primary' : '' ?>">
      <div class="card-header py-2 d-flex align-items-center justify-content-between"
           style="background:<?= $isToday ? 'var(--stu-blue)' : '#f8f9fa' ?>">
        <span class="fw-bold <?= $isToday ? 'text-white' : '' ?>"><?= $dayName ?></span>
        <?php if ($isToday): ?><span class="badge bg-white text-primary" style="font-size:.65rem">Today</span><?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php foreach ($weeklySlots[$dow] as $i => $slot):
          $color = $periodColors[$i % count($periodColors)];
        ?>
        <div class="d-flex align-items-stretch border-bottom" style="min-height:52px">
          <div style="width:4px;background:<?= $color ?>;flex-shrink:0"></div>
          <div class="px-3 py-2 flex-grow-1">
            <div class="fw-semibold small" style="color:<?= $color ?>"><?= e($slot['subject_name'] ?? '—') ?></div>
            <div class="d-flex gap-2 flex-wrap" style="font-size:.7rem;color:#6c757d">
              <span><i class="fas fa-clock me-1"></i>
                <?= e(substr($slot['start_time'],0,5)) ?>–<?= e(substr($slot['end_time'],0,5)) ?>
              </span>
              <?php if (!empty($slot['teacher_name'])): ?>
              <span><i class="fas fa-user-tie me-1"></i><?= e($slot['teacher_name']) ?></span>
              <?php endif; ?>
              <?php if (!empty($slot['room'])): ?>
              <span><i class="fas fa-map-marker-alt me-1"></i><?= e($slot['room']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php else: // exam timetable ?>

<?php if (empty($examSchedule)): ?>
<div class="text-center py-5 text-muted">
  <i class="fas fa-calendar fa-3x mb-3 d-block opacity-25"></i>
  <h6>No upcoming exams scheduled</h6>
  <p class="small mb-0">Exam timetables will appear here once your school publishes them.</p>
</div>
<?php else: ?>

<?php foreach ($examSchedule as $examName => $slots): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header" style="background:var(--stu-blue-pale);border-left:4px solid var(--stu-blue)">
    <h6 class="mb-0 fw-bold" style="color:var(--stu-navy)">
      <i class="fas fa-file-alt me-2" style="color:var(--stu-blue)"></i><?= e($examName) ?>
      <?php if (!empty($slots[0]['academic_year'])): ?>
      <span class="text-muted fw-normal" style="font-size:.78rem">&middot; <?= e($slots[0]['academic_year']) ?></span>
      <?php endif; ?>
    </h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Day</th>
            <th>Subject</th>
            <th>Time</th>
            <th class="d-none d-md-table-cell">Room / Venue</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($slots as $slot):
            $slotDate = $slot['exam_date'] ?? '';
            $isToday  = $slotDate === date('Y-m-d');
            $isPast   = $slotDate && $slotDate < date('Y-m-d');
          ?>
          <tr class="<?= $isToday ? 'table-warning' : ($isPast ? 'text-muted' : '') ?>">
            <td class="fw-semibold small">
              <?= $slotDate ? date('d M Y', strtotime($slotDate)) : '—' ?>
              <?php if ($isToday): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">Today</span><?php endif; ?>
            </td>
            <td class="small text-muted">
              <?= $slotDate ? date('l', strtotime($slotDate)) : '' ?>
            </td>
            <td>
              <div class="fw-semibold small"><?= e($slot['subject_name'] ?? '—') ?></div>
              <?php if (!empty($slot['subject_code'])): ?>
              <div class="text-muted" style="font-size:.68rem"><?= e($slot['subject_code']) ?></div>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php if (!empty($slot['start_time'])): ?>
              <?= e(substr($slot['start_time'],0,5)) ?>
              <?php if (!empty($slot['end_time'])): ?>–<?= e(substr($slot['end_time'],0,5)) ?><?php endif; ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="small text-muted d-none d-md-table-cell"><?= e($slot['room'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-student.php'; ?>
