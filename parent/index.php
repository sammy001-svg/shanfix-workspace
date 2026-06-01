<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header-parent.php';

// ── Dashboard data ────────────────────────────────────────────
$feeBalance = 0;
$attendPct  = null;
$latestExam = null;
$latestResults = [];
$recentNotices = [];
$upcomingExams = [];

// Fee outstanding balance
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM sch_fees WHERE student_id=? AND org_id=? AND balance>0");
    $s->execute([$parActive, $parOrgId]);
    $feeBalance = (float)$s->fetchColumn();
} catch (Throwable $e) {}

// Attendance summary (current term or last 30 days)
try {
    $s = $pdo->prepare(
        "SELECT
           COUNT(*) AS total,
           SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) AS present
         FROM sch_attendance
         WHERE student_id=? AND org_id=? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
    );
    $s->execute([$parActive, $parOrgId]);
    $attRow = $s->fetch();
    if ($attRow && $attRow['total'] > 0) {
        $attendPct = round($attRow['present'] / $attRow['total'] * 100);
    }
} catch (Throwable $e) {}

// Latest exam results (last completed exam)
try {
    $s = $pdo->prepare(
        "SELECT r.marks, r.max_marks, r.grade, sub.name AS subject_name
         FROM sch_results r
         JOIN sch_exams e ON r.exam_id = e.id
         JOIN sch_subjects sub ON r.subject_id = sub.id
         WHERE r.student_id=? AND r.org_id=? AND e.status='completed'
         ORDER BY e.end_date DESC, sub.name ASC LIMIT 6"
    );
    $s->execute([$parActive, $parOrgId]);
    $latestResults = $s->fetchAll();
} catch (Throwable $e) {}

// Name of the latest exam
if (!empty($latestResults)) {
    try {
        $s = $pdo->prepare(
            "SELECT DISTINCT e.name FROM sch_results r
             JOIN sch_exams e ON r.exam_id = e.id
             WHERE r.student_id=? AND r.org_id=? AND e.status='completed'
             ORDER BY e.end_date DESC LIMIT 1"
        );
        $s->execute([$parActive, $parOrgId]);
        $latestExam = $s->fetchColumn() ?: null;
    } catch (Throwable $e) {}
}

// Recent school notices (general + this student's class)
try {
    $classId = (int)($activeStudent['class_id'] ?? 0);
    $s = $pdo->prepare(
        "SELECT title, content, created_at, audience FROM sch_notices
         WHERE org_id=? AND (audience='all' OR audience='parents' OR class_id=? OR class_id IS NULL)
         ORDER BY created_at DESC LIMIT 4"
    );
    $s->execute([$parOrgId, $classId]);
    $recentNotices = $s->fetchAll();
} catch (Throwable $e) {}

// Upcoming exams for this student's class
try {
    $classId = (int)($activeStudent['class_id'] ?? 0);
    $s = $pdo->prepare(
        "SELECT e.name, e.start_date, e.end_date
         FROM sch_exams e
         WHERE e.org_id=? AND e.status='upcoming' AND e.start_date >= CURDATE()
         ORDER BY e.start_date ASC LIMIT 3"
    );
    $s->execute([$parOrgId]);
    $upcomingExams = $s->fetchAll();
} catch (Throwable $e) {}

$gradeColors = ['A'=>'#1A8A4E','B'=>'#3498db','C'=>'#f39c12','D'=>'#e67e22','E'=>'#e74c3c','F'=>'#c0392b'];
?>

<!-- Welcome -->
<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
       style="width:52px;height:52px;background:var(--par-green);font-size:1.2rem;flex-shrink:0">
    <?= strtoupper(substr($parName, 0, 1)) ?>
  </div>
  <div>
    <h4 class="mb-0 fw-bold">Hello, <?= e(explode(' ', $parName)[0]) ?> 👋</h4>
    <p class="text-muted mb-0 small">
      <?php if ($activeStudent): ?>
      Viewing: <strong><?= e(($activeStudent['first_name'] ?? '') . ' ' . ($activeStudent['last_name'] ?? '')) ?></strong>
      <?php if (!empty($activeStudent['class_name'])): ?> · <?= e($activeStudent['class_name']) ?><?php endif; ?>
      <?php else: ?>
      Welcome to your parent dashboard
      <?php endif; ?>
      &nbsp;—&nbsp; <?= date('l, d F Y') ?>
    </p>
  </div>
</div>

<!-- KPI row -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="par-stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="par-stat-icon" style="background:#fde8e8"><i class="fas fa-receipt" style="color:#e74c3c"></i></div>
        <div>
          <div class="fs-4 fw-bold lh-1 <?= $feeBalance > 0 ? 'text-danger' : 'text-success' ?>">
            <?= formatCurrency($feeBalance) ?>
          </div>
          <div class="text-muted small">Fee Balance</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="par-stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="par-stat-icon" style="background:#d4edda"><i class="fas fa-clipboard-check" style="color:#27ae60"></i></div>
        <div>
          <div class="fs-4 fw-bold lh-1 <?= $attendPct !== null ? ($attendPct >= 80 ? 'text-success' : 'text-warning') : '' ?>">
            <?= $attendPct !== null ? $attendPct . '%' : '—' ?>
          </div>
          <div class="text-muted small">Attendance (30d)</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="par-stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="par-stat-icon" style="background:#cce5ff"><i class="fas fa-graduation-cap" style="color:#3498db"></i></div>
        <div>
          <div class="fs-4 fw-bold lh-1 text-primary"><?= count($latestResults) ?></div>
          <div class="text-muted small">Subject Results</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="par-stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="par-stat-icon" style="background:#fff3cd"><i class="fas fa-bullhorn" style="color:#f39c12"></i></div>
        <div>
          <div class="fs-4 fw-bold lh-1 text-warning"><?= count($recentNotices) ?></div>
          <div class="text-muted small">New Notices</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Left column -->
  <div class="col-lg-8">

    <!-- Latest results -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold">
          <i class="fas fa-graduation-cap me-2" style="color:var(--par-green)"></i>
          Latest Results <?= $latestExam ? '<span class="badge bg-success ms-2" style="font-size:.7rem">' . e($latestExam) . '</span>' : '' ?>
        </h6>
        <a href="<?= APP_URL ?>/parent/results.php" class="btn btn-sm btn-outline-success">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($latestResults)): ?>
        <div class="text-center py-4 text-muted small">
          <i class="fas fa-graduation-cap d-block mb-1 fa-2x opacity-25"></i>No results available yet
        </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr><th>Subject</th><th class="text-center">Marks</th><th class="text-center">Grade</th></tr>
            </thead>
            <tbody>
              <?php foreach ($latestResults as $r):
                $pct   = $r['max_marks'] > 0 ? round($r['marks'] / $r['max_marks'] * 100) : 0;
                $grade = $r['grade'] ?? '—';
                $gc    = $gradeColors[$grade[0] ?? ''] ?? '#6c757d';
              ?>
              <tr>
                <td class="fw-semibold small"><?= e($r['subject_name']) ?></td>
                <td class="text-center small"><?= $r['marks'] ?>/<?= $r['max_marks'] ?> <span class="text-muted">(<?= $pct ?>%)</span></td>
                <td class="text-center">
                  <span class="badge" style="background:<?= $gc ?>;font-size:.72rem"><?= e($grade) ?></span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent notices -->
    <div class="card border-0 shadow-sm">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-bullhorn me-2" style="color:#f39c12"></i>Recent Notices</h6>
        <a href="<?= APP_URL ?>/parent/notices.php" class="btn btn-sm btn-outline-warning">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($recentNotices)): ?>
        <div class="text-center py-4 text-muted small"><i class="fas fa-bullhorn d-block mb-1 fa-2x opacity-25"></i>No notices yet</div>
        <?php else: foreach ($recentNotices as $n): ?>
        <div class="px-3 py-2 border-bottom">
          <div class="d-flex justify-content-between align-items-start">
            <div class="fw-semibold small"><?= e($n['title']) ?></div>
            <span class="text-muted ms-2" style="font-size:.68rem;white-space:nowrap"><?= date('d M', strtotime($n['created_at'])) ?></span>
          </div>
          <div class="text-muted small"><?= e(truncate($n['content'] ?? '', 100)) ?></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  </div><!-- /left -->

  <!-- Right column -->
  <div class="col-lg-4">

    <!-- Upcoming exams -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-calendar-check me-2 text-primary"></i>Upcoming Exams</h6>
        <a href="<?= APP_URL ?>/parent/timetable.php" class="btn btn-sm btn-outline-primary">Schedule</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($upcomingExams)): ?>
        <div class="text-center py-3 text-muted small"><i class="fas fa-calendar-times d-block mb-1 fa-2x opacity-25"></i>No upcoming exams</div>
        <?php else: foreach ($upcomingExams as $ex): ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
          <div class="text-center flex-shrink-0" style="width:42px">
            <div class="fw-bold text-primary" style="font-size:1.1rem"><?= date('d', strtotime($ex['start_date'])) ?></div>
            <div class="text-muted" style="font-size:.65rem"><?= date('M Y', strtotime($ex['start_date'])) ?></div>
          </div>
          <div>
            <div class="fw-semibold small"><?= e($ex['name']) ?></div>
            <div class="text-muted" style="font-size:.72rem">
              <?= date('d M', strtotime($ex['start_date'])) ?> – <?= date('d M', strtotime($ex['end_date'])) ?>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Quick links -->
    <div class="card border-0 shadow-sm">
      <div class="card-header">
        <h6 class="mb-0 fw-bold"><i class="fas fa-bolt me-2" style="color:var(--par-green)"></i>Quick Links</h6>
      </div>
      <div class="card-body p-2">
        <?php foreach ([
          ['fees.php',       'fa-receipt',        'Fee Balance',    '#e74c3c'],
          ['attendance.php', 'fa-clipboard-check','Attendance',     '#27ae60'],
          ['results.php',    'fa-graduation-cap', 'Results',        '#3498db'],
          ['timetable.php',  'fa-calendar-week',  'Exam Timetable', '#9b59b6'],
        ] as [$url, $icon, $label, $color]): ?>
        <a href="<?= APP_URL ?>/parent/<?= $url ?>"
           class="d-flex align-items-center gap-3 p-2 rounded mb-1 text-decoration-none"
           style="transition:background .12s" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
          <div style="width:36px;height:36px;border-radius:8px;background:<?= $color ?>18;
                      display:flex;align-items:center;justify-content:center;color:<?= $color ?>;font-size:.85rem;flex-shrink:0">
            <i class="fas <?= $icon ?>"></i>
          </div>
          <span class="fw-600 small" style="color:#0B2D4E"><?= $label ?></span>
          <i class="fas fa-chevron-right ms-auto text-muted" style="font-size:.65rem"></i>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

  </div><!-- /right -->
</div>

<?php require_once __DIR__ . '/../includes/footer-parent.php'; ?>
