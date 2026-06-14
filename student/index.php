<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header-student.php';

// ── Attendance summary this term ─────────────────────────────────
$attSummary = ['present'=>0,'absent'=>0,'late'=>0,'excused'=>0,'total'=>0];
try {
    $s = $pdo->prepare(
        "SELECT status, COUNT(*) AS cnt FROM sch_attendance
         WHERE student_id=? AND org_id=?
           AND att_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
         GROUP BY status"
    );
    $s->execute([$stuId, $stuOrgId]);
    foreach ($s->fetchAll() as $r) {
        $attSummary[$r['status']] = (int)$r['cnt'];
        $attSummary['total'] += (int)$r['cnt'];
    }
} catch (Throwable $e) {}
$attRate = $attSummary['total'] > 0
    ? round($attSummary['present'] / $attSummary['total'] * 100)
    : null;

// ── Latest exam results ──────────────────────────────────────────
$latestExam = null; $latestResults = []; $overallPct = null;
try {
    $s = $pdo->prepare(
        "SELECT e.id, e.name, e.end_date FROM sch_exams e
         JOIN sch_results r ON r.exam_id = e.id
         WHERE r.student_id=? AND r.org_id=?
         ORDER BY e.end_date DESC LIMIT 1"
    );
    $s->execute([$stuId, $stuOrgId]);
    $latestExam = $s->fetch() ?: null;
} catch (Throwable $e) {}

if ($latestExam) {
    try {
        $s = $pdo->prepare(
            "SELECT r.marks, r.max_marks, r.grade, sub.name AS subject_name
             FROM sch_results r JOIN sch_subjects sub ON sub.id = r.subject_id
             WHERE r.student_id=? AND r.exam_id=? AND r.org_id=?"
        );
        $s->execute([$stuId, $latestExam['id'], $stuOrgId]);
        $latestResults = $s->fetchAll();
        $tm = array_sum(array_column($latestResults,'marks'));
        $mm = array_sum(array_column($latestResults,'max_marks'));
        $overallPct = $mm > 0 ? round($tm / $mm * 100, 1) : null;
    } catch (Throwable $e) {}
}

// ── Active homework for my class ─────────────────────────────────
$activeHw = [];
try {
    $s = $pdo->prepare(
        "SELECT h.title, h.due_date, h.max_marks, sub.name AS subject_name
         FROM sch_homework h
         JOIN sch_subjects sub ON sub.id = h.subject_id
         WHERE h.class_id=? AND h.org_id=? AND h.status='active'
         ORDER BY h.due_date ASC LIMIT 5"
    );
    $s->execute([$stuClassId, $stuOrgId]);
    $activeHw = $s->fetchAll();
} catch (Throwable $e) {}

// ── Upcoming exams ───────────────────────────────────────────────
$upcomingExams = [];
try {
    $s = $pdo->prepare(
        "SELECT e.name, e.start_date, e.end_date, e.status,
                COUNT(DISTINCT es.subject_id) AS subject_count
         FROM sch_exams e
         LEFT JOIN sch_exam_schedule es ON es.exam_id = e.id AND es.class_id=?
         WHERE e.org_id=? AND e.status IN ('upcoming','ongoing')
         GROUP BY e.id ORDER BY e.start_date ASC LIMIT 3"
    );
    $s->execute([$stuClassId, $stuOrgId]);
    $upcomingExams = $s->fetchAll();
} catch (Throwable $e) {}

// ── Today's timetable ────────────────────────────────────────────
$todaySlots = [];
$todayNum = (int)date('N');
if ($stuClassId && $todayNum <= 5) {
    try {
        $s = $pdo->prepare(
            "SELECT t.period, t.start_time, t.end_time, t.room,
                    sub.name AS subject_name,
                    CONCAT(st.first_name,' ',st.last_name) AS teacher_name
             FROM sch_timetable t
             JOIN sch_subjects sub ON sub.id = t.subject_id
             LEFT JOIN sch_teachers st ON st.id = t.staff_id
             WHERE t.org_id=? AND t.class_id=? AND t.day_of_week=?
             ORDER BY t.period"
        );
        $s->execute([$stuOrgId, $stuClassId, $todayNum]);
        $todaySlots = $s->fetchAll();
    } catch (Throwable $e) {}
}

// ── Recent notices ───────────────────────────────────────────────
$notices = [];
try {
    $s = $pdo->prepare(
        "SELECT title, content, priority, created_at FROM sch_notices
         WHERE org_id=? AND (audience IN ('all','students') OR audience IS NULL)
           AND (expiry_date IS NULL OR expiry_date >= CURDATE())
         ORDER BY is_pinned DESC, created_at DESC LIMIT 3"
    );
    $s->execute([$stuOrgId]);
    $notices = $s->fetchAll();
} catch (Throwable $e) {}

$gradeColor = ['A'=>'#1A8A4E','B'=>'#3498db','C'=>'#f39c12','D'=>'#e67e22','E'=>'#e74c3c','F'=>'#c0392b'];
$days = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday'];
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-th-large me-2" style="color:var(--stu-blue)"></i>Dashboard</h5>
    <div class="text-muted small mt-1">Welcome back, <?= e(explode(' ', $stuName)[0]) ?> &mdash; <?= date('l, d M Y') ?></div>
  </div>
</div>

<!-- KPI strip -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stu-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:rgba(29,78,216,.1)">
        <i class="fas fa-clipboard-check" style="color:var(--stu-blue)"></i>
      </div>
      <div class="fs-3 fw-bold <?= $attRate!==null ? ($attRate>=80?'text-success':($attRate>=60?'text-warning':'text-danger')) : '' ?>">
        <?= $attRate !== null ? $attRate . '%' : '&mdash;' ?>
      </div>
      <div class="text-muted small">Attendance Rate</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stu-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:#f0fdf4">
        <i class="fas fa-graduation-cap" style="color:#1A8A4E"></i>
      </div>
      <div class="fs-3 fw-bold text-success">
        <?= $overallPct !== null ? $overallPct . '%' : '&mdash;' ?>
      </div>
      <div class="text-muted small">Last Exam Score</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stu-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:#fef5e7">
        <i class="fas fa-book-open" style="color:#f39c12"></i>
      </div>
      <div class="fs-3 fw-bold text-warning"><?= count($activeHw) ?></div>
      <div class="text-muted small">Active Homework</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stu-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:#f5f0ff">
        <i class="fas fa-file-alt" style="color:#9b59b6"></i>
      </div>
      <div class="fs-3 fw-bold" style="color:#9b59b6"><?= count($upcomingExams) ?></div>
      <div class="text-muted small">Upcoming Exams</div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Today's classes -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header">
        <h6 class="mb-0 fw-bold"><i class="fas fa-calendar-day me-2" style="color:var(--stu-blue)"></i>
          Today &mdash; <?= $days[$todayNum] ?? date('l') ?>
        </h6>
      </div>
      <div class="card-body p-0">
        <?php if (empty($todaySlots)): ?>
        <div class="text-center py-5 text-muted">
          <i class="fas fa-calendar-check fa-2x mb-2 d-block opacity-25"></i>
          <div class="small"><?= $todayNum > 5 ? 'No classes on weekends' : 'No timetable set for today' ?></div>
        </div>
        <?php else: foreach ($todaySlots as $slot): ?>
        <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom">
          <div class="text-center flex-shrink-0" style="min-width:28px">
            <div class="fw-bold" style="color:var(--stu-blue)"><?= $slot['period'] ?></div>
            <div class="text-muted" style="font-size:.6rem">period</div>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold small"><?= e($slot['subject_name']) ?></div>
            <div class="text-muted" style="font-size:.75rem">
              <?= date('H:i', strtotime($slot['start_time'])) ?> &ndash; <?= date('H:i', strtotime($slot['end_time'])) ?>
              <?php if (!empty($slot['teacher_name'])): ?>
              &middot; <?= e($slot['teacher_name']) ?>
              <?php endif; ?>
            </div>
          </div>
          <?php if (!empty($slot['room'])): ?>
          <span class="badge bg-light text-dark border" style="font-size:.65rem"><?= e($slot['room']) ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Right column -->
  <div class="col-lg-7">

    <!-- Latest results snapshot -->
    <?php if ($latestExam && !empty($latestResults)): ?>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-graduation-cap me-2" style="color:#1A8A4E"></i><?= e($latestExam['name']) ?></h6>
        <a href="results.php" class="btn btn-sm btn-outline-success">Full Results</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr><th>Subject</th><th class="text-center">Marks</th><th class="text-center">Grade</th></tr>
            </thead>
            <tbody>
              <?php foreach (array_slice($latestResults, 0, 5) as $r):
                $g = strtoupper($r['grade'][0] ?? '');
                $gc = $gradeColor[$g] ?? '#6c757d';
              ?>
              <tr>
                <td class="small"><?= e($r['subject_name']) ?></td>
                <td class="text-center small"><?= $r['marks'] ?>/<?= $r['max_marks'] ?></td>
                <td class="text-center"><span class="badge" style="background:<?= $gc ?>"><?= e($r['grade']) ?></span></td>
              </tr>
              <?php endforeach; ?>
              <?php if (count($latestResults) > 5): ?>
              <tr><td colspan="3" class="text-center small text-muted py-2">+<?= count($latestResults)-5 ?> more subjects &mdash; <a href="results.php">view all</a></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Active homework -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-book-open me-2" style="color:#f39c12"></i>Active Homework</h6>
        <a href="homework.php" class="btn btn-sm btn-outline-warning text-dark">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($activeHw)): ?>
        <div class="text-center py-4 text-muted small">
          <i class="fas fa-check-circle fa-2x d-block mb-2 opacity-25 text-success"></i>No active homework right now
        </div>
        <?php else: foreach ($activeHw as $hw):
          $dueDate  = $hw['due_date'] ?? null;
          $isToday  = $dueDate === date('Y-m-d');
          $isOverdue = $dueDate && $dueDate < date('Y-m-d');
        ?>
        <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom">
          <div class="flex-grow-1">
            <div class="fw-semibold small <?= $isOverdue?'text-danger':($isToday?'text-warning':'') ?>"><?= e($hw['title']) ?></div>
            <div class="text-muted" style="font-size:.75rem">
              <?= e($hw['subject_name']) ?>
              <?php if ($dueDate): ?>
              &middot; Due <?= date('d M', strtotime($dueDate)) ?>
              <?php if ($isToday): ?><span class="text-warning fw-semibold"> (Today!)</span><?php endif; ?>
              <?php if ($isOverdue): ?><span class="text-danger fw-semibold"> (Overdue)</span><?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($hw['max_marks']): ?>
          <span class="text-muted small"><?= $hw['max_marks'] ?>mks</span>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Upcoming exams + notices side by side -->
    <div class="row g-3">
      <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header"><h6 class="mb-0 fw-bold small"><i class="fas fa-file-alt me-1" style="color:#9b59b6"></i>Upcoming Exams</h6></div>
          <div class="card-body p-0">
            <?php if (empty($upcomingExams)): ?>
            <div class="text-center py-4 text-muted" style="font-size:.8rem">No upcoming exams</div>
            <?php else: foreach ($upcomingExams as $ex): ?>
            <div class="px-3 py-2 border-bottom">
              <div class="fw-semibold small"><?= e($ex['name']) ?></div>
              <div class="text-muted" style="font-size:.72rem">
                <?= $ex['start_date'] ? date('d M Y', strtotime($ex['start_date'])) : '' ?>
                <span class="badge ms-1 <?= $ex['status']==='ongoing'?'bg-success':'bg-primary' ?> bg-opacity-25 <?= $ex['status']==='ongoing'?'text-success':'text-primary' ?>"><?= ucfirst($ex['status']) ?></span>
              </div>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="mb-0 fw-bold small"><i class="fas fa-bullhorn me-1" style="color:#e74c3c"></i>Notices</h6>
            <a href="notices.php" class="btn btn-sm btn-link p-0 text-decoration-none" style="font-size:.72rem">All</a>
          </div>
          <div class="card-body p-0">
            <?php if (empty($notices)): ?>
            <div class="text-center py-4 text-muted" style="font-size:.8rem">No notices</div>
            <?php else: foreach ($notices as $n): ?>
            <div class="px-3 py-2 border-bottom">
              <div class="fw-semibold small"><?= e($n['title']) ?></div>
              <div class="text-muted" style="font-size:.7rem"><?= date('d M Y', strtotime($n['created_at'])) ?></div>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer-student.php'; ?>
