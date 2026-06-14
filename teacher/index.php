<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header-teacher.php';

// ── My classes today (from timetable) ───────────────────────────
$todayNum  = (int)date('N'); // 1=Mon … 5=Fri
$todayClasses = [];
try {
    $s = $pdo->prepare(
        "SELECT t.*, c.name AS class_name, sub.name AS subject_name,
                COUNT(st.id) AS student_count
         FROM sch_timetable t
         JOIN sch_classes c  ON c.id = t.class_id
         JOIN sch_subjects sub ON sub.id = t.subject_id
         LEFT JOIN sch_students st ON st.class_id = t.class_id AND st.org_id = t.org_id AND st.status='active'
         WHERE t.org_id=? AND t.staff_id=? AND t.day_of_week=?
         GROUP BY t.id
         ORDER BY t.period"
    );
    $s->execute([$tchOrgId, $tchId, $todayNum]);
    $todayClasses = $s->fetchAll();
} catch (Throwable $e) {}

// ── Attendance status for today's classes ────────────────────────
$markedToday = [];
if (!empty($todayClasses)) {
    $cids = array_unique(array_column($todayClasses, 'class_id'));
    try {
        $in = implode(',', array_fill(0, count($cids), '?'));
        $s  = $pdo->prepare("SELECT class_id, COUNT(*) AS cnt FROM sch_attendance WHERE org_id=? AND att_date=CURDATE() AND class_id IN ($in) GROUP BY class_id");
        $s->execute(array_merge([$tchOrgId], $cids));
        foreach ($s->fetchAll() as $r) $markedToday[$r['class_id']] = (int)$r['cnt'];
    } catch (Throwable $e) {}
}

// ── Homework pending grading (closed, no marks entered yet) ─────
$pendingHomework = 0;
try {
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM sch_homework WHERE org_id=? AND teacher_id=? AND status='closed'"
    );
    $s->execute([$tchOrgId, $tchId]);
    $pendingHomework = (int)$s->fetchColumn();
} catch (Throwable $e) {}

// ── Upcoming exams for my subjects ──────────────────────────────
$upcomingExams = [];
try {
    $s = $pdo->prepare(
        "SELECT DISTINCT e.id, e.name, e.start_date, e.end_date, e.status
         FROM sch_exams e
         JOIN sch_exam_schedule es ON es.exam_id = e.id
         JOIN sch_class_subjects cs ON cs.class_id = es.class_id AND cs.subject_id = es.subject_id
         WHERE e.org_id=? AND cs.staff_id=? AND e.status IN ('upcoming','ongoing')
         ORDER BY e.start_date ASC LIMIT 5"
    );
    $s->execute([$tchOrgId, $tchId]);
    $upcomingExams = $s->fetchAll();
} catch (Throwable $e) {}

// ── My classes overview ──────────────────────────────────────────
$myClasses = [];
try {
    $s = $pdo->prepare(
        "SELECT cs.class_id, c.name AS class_name, sub.name AS subject_name,
                COUNT(st.id) AS student_count
         FROM sch_class_subjects cs
         JOIN sch_classes c  ON c.id = cs.class_id
         JOIN sch_subjects sub ON sub.id = cs.subject_id
         LEFT JOIN sch_students st ON st.class_id = cs.class_id AND st.org_id=? AND st.status='active'
         WHERE cs.org_id=? AND cs.staff_id=?
         GROUP BY cs.class_id, cs.subject_id
         ORDER BY c.name, sub.name"
    );
    $s->execute([$tchOrgId, $tchOrgId, $tchId]);
    $myClasses = $s->fetchAll();
} catch (Throwable $e) {}

// ── Recent homework ──────────────────────────────────────────────
$recentHw = [];
try {
    $s = $pdo->prepare(
        "SELECT h.*, c.name AS class_name, sub.name AS subject_name
         FROM sch_homework h
         JOIN sch_classes c ON c.id = h.class_id
         JOIN sch_subjects sub ON sub.id = h.subject_id
         WHERE h.org_id=? AND h.teacher_id=?
         ORDER BY h.created_at DESC LIMIT 5"
    );
    $s->execute([$tchOrgId, $tchId]);
    $recentHw = $s->fetchAll();
} catch (Throwable $e) {}

$dayNames = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
$todayName = $dayNames[$todayNum] ?? date('l');
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-th-large me-2" style="color:var(--tch-green)"></i>Dashboard</h5>
    <div class="text-muted small mt-1">Welcome back, <?= e(explode(' ', $tchName)[0]) ?> &mdash; <?= $todayName ?>, <?= date('d M Y') ?></div>
  </div>
  <a href="attendance.php" class="btn btn-sm text-white" style="background:var(--tch-green)">
    <i class="fas fa-clipboard-check me-1"></i>Mark Attendance
  </a>
</div>

<!-- KPI strip -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="tch-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:rgba(26,138,78,.1)">
        <i class="fas fa-chalkboard" style="color:var(--tch-green)"></i>
      </div>
      <div class="fs-3 fw-bold" style="color:var(--tch-green)"><?= count($myClasses) ?></div>
      <div class="text-muted small">My Classes</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="tch-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:#e8f4fd">
        <i class="fas fa-calendar-alt" style="color:#3498db"></i>
      </div>
      <div class="fs-3 fw-bold text-primary"><?= count($todayClasses) ?></div>
      <div class="text-muted small">Classes Today</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="tch-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:#fef5e7">
        <i class="fas fa-book-open" style="color:#f39c12"></i>
      </div>
      <div class="fs-3 fw-bold text-warning"><?= $pendingHomework ?></div>
      <div class="text-muted small">Homework Closed</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="tch-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:#f5f0ff">
        <i class="fas fa-graduation-cap" style="color:#9b59b6"></i>
      </div>
      <div class="fs-3 fw-bold" style="color:#9b59b6"><?= count($upcomingExams) ?></div>
      <div class="text-muted small">Upcoming Exams</div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Today's schedule -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-calendar-day me-2" style="color:var(--tch-green)"></i>Today &mdash; <?= $todayName ?></h6>
        <a href="attendance.php" class="btn btn-sm btn-outline-success">Mark All</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($todayClasses)): ?>
        <div class="text-center py-5 text-muted">
          <i class="fas fa-calendar-check fa-2x mb-2 d-block opacity-25"></i>
          <div class="small">No classes scheduled for today</div>
        </div>
        <?php else: ?>
        <?php foreach ($todayClasses as $cls):
          $attended = isset($markedToday[$cls['class_id']]);
          $attCount = $markedToday[$cls['class_id']] ?? 0;
        ?>
        <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom">
          <div class="text-center flex-shrink-0" style="min-width:34px">
            <div class="fw-bold" style="color:var(--tch-green);font-size:1rem"><?= $cls['period'] ?></div>
            <div class="text-muted" style="font-size:.6rem">period</div>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold small"><?= e($cls['subject_name']) ?> &mdash; <?= e($cls['class_name']) ?></div>
            <div class="text-muted" style="font-size:.75rem">
              <?= date('H:i', strtotime($cls['start_time'])) ?> &ndash; <?= date('H:i', strtotime($cls['end_time'])) ?>
              &nbsp;&middot;&nbsp; <?= $cls['student_count'] ?> students
            </div>
          </div>
          <?php if ($attended): ?>
          <span class="badge bg-success" style="font-size:.68rem"><i class="fas fa-check me-1"></i><?= $attCount ?> marked</span>
          <?php else: ?>
          <a href="attendance.php?class_id=<?= $cls['class_id'] ?>"
             class="badge text-decoration-none" style="background:rgba(26,138,78,.12);color:var(--tch-green);font-size:.68rem">
            <i class="fas fa-plus me-1"></i>Mark
          </a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- My classes overview -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-chalkboard me-2" style="color:#3498db"></i>My Classes &amp; Subjects</h6>
        <a href="students.php" class="btn btn-sm btn-outline-primary">View Students</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($myClasses)): ?>
        <div class="text-center py-5 text-muted">
          <i class="fas fa-chalkboard fa-2x mb-2 d-block opacity-25"></i>
          <div class="small">No class assignments yet</div>
        </div>
        <?php else: ?>
        <?php foreach ($myClasses as $cls): ?>
        <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom">
          <div class="flex-shrink-0 rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
               style="width:36px;height:36px;background:var(--tch-green);font-size:.75rem">
            <?= strtoupper(substr($cls['class_name'], 0, 1)) ?>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold small"><?= e($cls['class_name']) ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= e($cls['subject_name']) ?></div>
          </div>
          <span class="badge bg-light text-dark border"><?= $cls['student_count'] ?> students</span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Upcoming exams -->
  <?php if (!empty($upcomingExams)): ?>
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-graduation-cap me-2" style="color:#9b59b6"></i>Upcoming Exams</h6>
        <a href="results.php" class="btn btn-sm btn-outline-secondary">Enter Results</a>
      </div>
      <div class="card-body p-0">
        <?php foreach ($upcomingExams as $exam): ?>
        <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom">
          <div style="width:10px;height:10px;border-radius:50%;background:<?= $exam['status']==='ongoing'?'#27ae60':'#3498db' ?>;flex-shrink:0"></div>
          <div class="flex-grow-1">
            <div class="fw-semibold small"><?= e($exam['name']) ?></div>
            <div class="text-muted" style="font-size:.75rem">
              <?= $exam['start_date'] ? date('d M Y', strtotime($exam['start_date'])) : '' ?>
              <?php if ($exam['end_date']): ?>&ndash;<?= date('d M Y', strtotime($exam['end_date'])) ?><?php endif; ?>
            </div>
          </div>
          <span class="badge <?= $exam['status']==='ongoing'?'bg-success':'bg-primary' ?> bg-opacity-25 <?= $exam['status']==='ongoing'?'text-success':'text-primary' ?>">
            <?= ucfirst($exam['status']) ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recent homework -->
  <div class="col-lg-<?= empty($upcomingExams) ? '12' : '6' ?>">
    <div class="card border-0 shadow-sm">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-book-open me-2" style="color:#f39c12"></i>Recent Homework</h6>
        <a href="homework.php" class="btn btn-sm btn-outline-warning text-dark">Manage</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($recentHw)): ?>
        <div class="text-center py-4 text-muted small">
          <i class="fas fa-book-open fa-2x mb-2 d-block opacity-25"></i>No homework assigned yet
        </div>
        <?php else: ?>
        <?php foreach ($recentHw as $hw):
          $statusColors = ['active'=>'success','closed'=>'secondary','draft'=>'warning'];
          $isOverdue = $hw['status']==='active' && !empty($hw['due_date']) && $hw['due_date'] < date('Y-m-d');
        ?>
        <div class="d-flex align-items-start gap-3 px-4 py-3 border-bottom">
          <div class="flex-grow-1">
            <div class="fw-semibold small <?= $isOverdue ? 'text-danger' : '' ?>"><?= e($hw['title']) ?></div>
            <div class="text-muted" style="font-size:.75rem">
              <?= e($hw['class_name']) ?> &middot; <?= e($hw['subject_name']) ?>
              <?php if (!empty($hw['due_date'])): ?>
              &middot; Due <?= date('d M', strtotime($hw['due_date'])) ?>
              <?php endif; ?>
            </div>
          </div>
          <span class="badge bg-<?= $statusColors[$hw['status']] ?? 'secondary' ?> flex-shrink-0" style="font-size:.65rem">
            <?= ucfirst($hw['status']) ?>
          </span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer-teacher.php'; ?>
