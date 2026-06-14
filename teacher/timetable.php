<?php
$pageTitle = 'My Timetable';
require_once __DIR__ . '/../includes/header-teacher.php';

// Full weekly timetable for this teacher
$weekTimetable = [];
try {
    $s = $pdo->prepare(
        "SELECT t.*, c.name AS class_name, sub.name AS subject_name, sub.code AS subject_code
         FROM sch_timetable t
         JOIN sch_classes c ON c.id = t.class_id
         JOIN sch_subjects sub ON sub.id = t.subject_id
         WHERE t.org_id=? AND t.staff_id=?
         ORDER BY t.day_of_week, t.period, t.start_time"
    );
    $s->execute([$tchOrgId, $tchId]);
    foreach ($s->fetchAll() as $row) {
        $weekTimetable[$row['day_of_week']][] = $row;
    }
} catch (Throwable $e) {}

// Upcoming exams for this teacher's assigned subjects
$upcomingExams = [];
try {
    $s = $pdo->prepare(
        "SELECT DISTINCT e.id, e.name AS exam_name, e.start_date, e.end_date, e.status,
                c.name AS class_name, sub.name AS subject_name
         FROM sch_exams e
         JOIN sch_exam_schedule es ON es.exam_id = e.id
         JOIN sch_class_subjects cs ON cs.class_id = es.class_id AND cs.subject_id = es.subject_id
         JOIN sch_classes c ON c.id = es.class_id
         JOIN sch_subjects sub ON sub.id = es.subject_id
         WHERE e.org_id=? AND cs.staff_id=? AND e.status IN ('upcoming','ongoing')
         ORDER BY e.start_date ASC, c.name LIMIT 20"
    );
    $s->execute([$tchOrgId, $tchId]);
    $upcomingExams = $s->fetchAll();
} catch (Throwable $e) {}

$dayNames  = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
$todayNum  = (int)date('N');
$dayColors = [
    1 => ['bg'=>'#e8f5f0','accent'=>'#1A8A4E'],
    2 => ['bg'=>'#e8f0fd','accent'=>'#3498db'],
    3 => ['bg'=>'#f5e8fd','accent'=>'#9b59b6'],
    4 => ['bg'=>'#fdf5e8','accent'=>'#e67e22'],
    5 => ['bg'=>'#fde8e8','accent'=>'#e74c3c'],
    6 => ['bg'=>'#e8fdfa','accent'=>'#1abc9c'],
    7 => ['bg'=>'#f5f5f5','accent'=>'#95a5a6'],
];

$showDays = [1,2,3,4,5];
if (!empty($weekTimetable[6])) $showDays[] = 6;
if (!empty($weekTimetable[7])) $showDays[] = 7;

$totalPeriods = array_sum(array_map('count', $weekTimetable));
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-calendar-week me-2" style="color:var(--tch-green)"></i>My Timetable</h5>
    <div class="text-muted small mt-1">Weekly teaching schedule</div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <span class="badge bg-light text-dark border">
      <i class="fas fa-chalkboard me-1"></i><?= $totalPeriods ?> period<?= $totalPeriods!==1?'s':'' ?> per week
    </span>
    <span class="badge bg-light text-dark border">
      <i class="fas fa-calendar-day me-1"></i><?= date('l, d M Y') ?>
    </span>
  </div>
</div>

<?php if (empty($weekTimetable)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-calendar-week fa-3x mb-3 d-block opacity-25"></i>
    <h6>No timetable assigned yet</h6>
    <p class="small mb-0">Contact your school administrator to set up your teaching schedule.</p>
  </div>
</div>

<?php else: ?>

<!-- ── Desktop weekly grid ─────────────────────────────────────── -->
<div class="d-none d-lg-block mb-4">
  <div class="row g-3">
    <?php foreach ($showDays as $day):
      $dc      = $dayColors[$day] ?? ['bg'=>'#f8f9fa','accent'=>'#6c757d'];
      $isToday = ($day === $todayNum);
      $periods = $weekTimetable[$day] ?? [];
    ?>
    <div class="col">
      <div class="card border-0 shadow-sm h-100" style="<?= $isToday ? 'box-shadow:0 0 0 2px '.$dc['accent'].'!important;' : '' ?>">
        <div class="card-header py-2 text-center border-bottom-0"
             style="background:<?= $dc['bg'] ?>;border-bottom:2px solid <?= $dc['accent'] ?>!important">
          <div class="fw-bold small" style="color:<?= $dc['accent'] ?>"><?= $dayNames[$day] ?></div>
          <?php if ($isToday): ?>
          <span class="badge mt-1" style="background:<?= $dc['accent'] ?>;font-size:.58rem">TODAY</span>
          <?php else: ?>
          <div class="text-muted mt-1" style="font-size:.65rem"><?= count($periods) ?> period<?= count($periods)!==1?'s':'' ?></div>
          <?php endif; ?>
        </div>
        <div class="card-body p-0">
          <?php if (empty($periods)): ?>
          <div class="text-center py-4 text-muted" style="font-size:.75rem">
            <i class="fas fa-coffee mb-1 d-block opacity-25"></i>Free
          </div>
          <?php else: ?>
          <?php foreach ($periods as $p): ?>
          <div class="px-2 py-2 border-bottom" style="<?= $isToday ? 'background:rgba(26,138,78,.03)' : '' ?>">
            <div class="fw-semibold" style="font-size:.7rem;color:<?= $dc['accent'] ?>">
              Per <?= (int)$p['period'] ?> &middot; <?= date('H:i', strtotime($p['start_time'])) ?>–<?= date('H:i', strtotime($p['end_time'])) ?>
            </div>
            <div class="fw-bold mt-1" style="font-size:.8rem"><?= e($p['subject_name']) ?></div>
            <div class="text-muted" style="font-size:.7rem">
              <?= e($p['class_name']) ?>
              <?php if (!empty($p['room_no'])): ?><br><?= e($p['room_no']) ?><?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── Mobile / tablet: accordion by day ─────────────────────── -->
<div class="d-lg-none mb-4">
  <?php foreach ($showDays as $day):
    $dc      = $dayColors[$day] ?? ['bg'=>'#f8f9fa','accent'=>'#6c757d'];
    $isToday = ($day === $todayNum);
    $periods = $weekTimetable[$day] ?? [];
  ?>
  <div class="card border-0 shadow-sm mb-2">
    <div class="card-header d-flex align-items-center justify-content-between py-2"
         data-bs-toggle="collapse" data-bs-target="#dayM<?= $day ?>"
         style="cursor:pointer;background:<?= $isToday ? $dc['bg'] : '#fff' ?>;border-left:3px solid <?= $dc['accent'] ?>">
      <div class="d-flex align-items-center gap-2">
        <span class="fw-bold small" style="color:<?= $dc['accent'] ?>"><?= $dayNames[$day] ?></span>
        <?php if ($isToday): ?>
        <span class="badge" style="background:<?= $dc['accent'] ?>;font-size:.58rem">TODAY</span>
        <?php endif; ?>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="text-muted" style="font-size:.72rem"><?= count($periods) ?> period<?= count($periods)!==1?'s':'' ?></span>
        <i class="fas fa-chevron-down text-muted" style="font-size:.7rem"></i>
      </div>
    </div>
    <div class="collapse <?= $isToday ? 'show' : '' ?>" id="dayM<?= $day ?>">
      <?php if (empty($periods)): ?>
      <div class="p-3 text-muted small text-center">No classes scheduled</div>
      <?php else: ?>
      <?php foreach ($periods as $p): ?>
      <div class="d-flex align-items-start gap-3 px-4 py-3 border-bottom">
        <div class="text-center flex-shrink-0" style="min-width:32px">
          <div class="fw-bold" style="font-size:.9rem;color:<?= $dc['accent'] ?>"><?= (int)$p['period'] ?></div>
          <div class="text-muted" style="font-size:.58rem">period</div>
        </div>
        <div class="flex-grow-1">
          <div class="fw-semibold small"><?= e($p['subject_name']) ?></div>
          <div class="text-muted" style="font-size:.75rem">
            <?= e($p['class_name']) ?>
            &middot; <?= date('H:i', strtotime($p['start_time'])) ?>–<?= date('H:i', strtotime($p['end_time'])) ?>
            <?php if (!empty($p['room_no'])): ?>&middot; <?= e($p['room_no']) ?><?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Teaching load summary ─────────────────────────────────── -->
<div class="row g-3 mb-4">
  <?php
  $subjectCount = 0; $classCount = 0; $allSubjects = []; $allClasses = [];
  foreach ($weekTimetable as $dayPeriods) {
      foreach ($dayPeriods as $p) {
          $allSubjects[$p['subject_name']] = true;
          $allClasses[$p['class_name']] = true;
      }
  }
  $subjectCount = count($allSubjects);
  $classCount   = count($allClasses);
  ?>
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm text-center py-3">
      <div class="fs-2 fw-bold" style="color:var(--tch-green)"><?= $totalPeriods ?></div>
      <div class="text-muted small">Periods / Week</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm text-center py-3">
      <div class="fs-2 fw-bold text-primary"><?= $classCount ?></div>
      <div class="text-muted small">Classes</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm text-center py-3">
      <div class="fs-2 fw-bold text-warning"><?= $subjectCount ?></div>
      <div class="text-muted small">Subjects</div>
    </div>
  </div>
</div>

<?php endif; ?>

<!-- ── Upcoming Exam Schedule ─────────────────────────────────── -->
<?php if (!empty($upcomingExams)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-graduation-cap me-2" style="color:#9b59b6"></i>Upcoming Exam Schedule</h6>
    <a href="results.php" class="btn btn-sm btn-outline-secondary">Enter Results</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Exam</th>
          <th>Class</th>
          <th>Subject</th>
          <th>Date</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($upcomingExams as $ex): ?>
        <tr>
          <td class="fw-semibold small"><?= e($ex['exam_name']) ?></td>
          <td class="small"><?= e($ex['class_name']) ?></td>
          <td class="small"><?= e($ex['subject_name']) ?></td>
          <td class="small text-muted">
            <?= !empty($ex['start_date']) ? date('d M Y', strtotime($ex['start_date'])) : '—' ?>
            <?php if (!empty($ex['end_date']) && $ex['end_date'] !== $ex['start_date']): ?>
            &ndash; <?= date('d M Y', strtotime($ex['end_date'])) ?>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $ex['status']==='ongoing' ? 'bg-success' : 'bg-primary' ?> bg-opacity-25 <?= $ex['status']==='ongoing' ? 'text-success' : 'text-primary' ?>" style="font-size:.68rem">
              <?= ucfirst($ex['status']) ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-teacher.php'; ?>
