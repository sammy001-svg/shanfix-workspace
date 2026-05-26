<?php
$moduleSlug  = 'driving';
$moduleName  = 'Driving School';
$moduleIcon  = 'fas fa-car-side';
$moduleColor = '#1a237e';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt',      'label' => 'Dashboard'],
    ['url' => 'students.php',    'icon' => 'fas fa-user-graduate',        'label' => 'Students'],
    ['url' => 'instructors.php', 'icon' => 'fas fa-chalkboard-teacher',   'label' => 'Instructors'],
    ['url' => 'vehicles.php',    'icon' => 'fas fa-car',                  'label' => 'Vehicles'],
    ['url' => 'classes.php',     'icon' => 'fas fa-calendar-alt',         'label' => 'Classes'],
    ['url' => 'lessons.php',     'icon' => 'fas fa-road',                 'label' => 'Lessons'],
    ['url' => 'tests.php',       'icon' => 'fas fa-clipboard-check',      'label' => 'Tests'],
    ['url' => 'licenses.php',    'icon' => 'fas fa-id-card',              'label' => 'Licenses'],
    ['url' => 'schedule.php',    'icon' => 'fas fa-calendar-week',        'label' => 'Schedule'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-bill',           'label' => 'Payments'],
    ['url' => 'certificates.php','icon' => 'fas fa-certificate',          'label' => 'Certificates'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',            'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

// KPIs
$totalStudents   = countRows('driving_students',    'org_id=?', [$orgId]);
$activeStudents  = countRows('driving_students',    'org_id=? AND status=?', [$orgId,'active']);
$totalInstructors= countRows('driving_instructors', 'org_id=? AND status=?', [$orgId,'active']);
$totalVehicles   = countRows('driving_vehicles',    'org_id=? AND status=?', [$orgId,'active']);
$lessonsToday    = 0;
$pendingLicenses = 0;
$upcomingTests   = 0;
$completedLessons= 0;
$today = date('Y-m-d');
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM driving_lessons WHERE org_id=? AND lesson_date=? AND status NOT IN ('cancelled')");
    $s->execute([$orgId, $today]); $lessonsToday = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM driving_licenses WHERE org_id=? AND status='pending'");
    $s->execute([$orgId]); $pendingLicenses = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM driving_tests WHERE org_id=? AND test_date >= ? AND status='scheduled'");
    $s->execute([$orgId, $today]); $upcomingTests = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM driving_lessons WHERE org_id=? AND status='completed'");
    $s->execute([$orgId]); $completedLessons = (int)$s->fetchColumn();
} catch (Exception $e) {}

// Recent lessons
$recentLessons = [];
try {
    $s = $pdo->prepare("
        SELECT l.*, CONCAT(st.first_name,' ',st.last_name) AS student_name,
               i.name AS instructor_name, v.name AS vehicle_name
        FROM driving_lessons l
        LEFT JOIN driving_students s2    ON l.student_id    = s2.id
        LEFT JOIN driving_instructors i  ON l.instructor_id = i.id
        LEFT JOIN driving_vehicles v     ON l.vehicle_id    = v.id
        JOIN driving_students st ON l.student_id = st.id
        WHERE l.org_id=? ORDER BY l.lesson_date DESC, l.start_time DESC LIMIT 8
    ");
    $s->execute([$orgId]); $recentLessons = $s->fetchAll();
} catch (Exception $e) {}

// Lesson status breakdown for chart
$lStatuses = ['draft','started','completed','cancelled']; $lCounts = [];
foreach ($lStatuses as $ls) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM driving_lessons WHERE org_id=? AND status=?");
        $s->execute([$orgId,$ls]); $lCounts[] = (int)$s->fetchColumn();
    } catch (Exception $e) { $lCounts[] = 0; }
}

// Monthly student enrollments last 6 months
$months = []; $mCounts = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $months[] = date('M Y', strtotime("-$i months"));
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM driving_students WHERE org_id=? AND DATE_FORMAT(created_at,'%Y-%m')=?");
        $s->execute([$orgId,$m]); $mCounts[] = (int)$s->fetchColumn();
    } catch (Exception $e) { $mCounts[] = 0; }
}

$statusColors = ['draft'=>'secondary','started'=>'primary','completed'=>'success','cancelled'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage students, instructors, vehicles, lessons, tests and licenses</p>
  </div>
  <a href="students.php" class="btn" style="background:<?= $moduleColor ?>;color:#fff">
    <i class="fas fa-plus me-2"></i>Enroll Student
  </a>
</div>

<!-- KPI Row 1 -->
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-user-graduate"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $totalStudents ?></div><div class="stat-label">Total Students</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-chalkboard-teacher"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $totalInstructors ?></div><div class="stat-label">Active Instructors</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-car"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $totalVehicles ?></div><div class="stat-label">Active Vehicles</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-road"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $completedLessons ?></div><div class="stat-label">Lessons Completed</div></div></div>
  </div>
</div>

<!-- KPI Row 2 -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-calendar-day"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $lessonsToday ?></div><div class="stat-label">Lessons Today</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-clipboard-check"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $upcomingTests ?></div><div class="stat-label">Upcoming Tests</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon" style="background:#e3f2fd;color:<?= $moduleColor ?>"><i class="fas fa-id-card"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $pendingLicenses ?></div><div class="stat-label">Pending Licenses</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-user-check"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $activeStudents ?></div><div class="stat-label">Active Students</div></div></div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Lesson status donut -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Lesson Status</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="lessonChart" height="220"></canvas></div>
    </div>
  </div>
  <!-- Monthly enrollment line -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-line me-2" style="color:<?= $moduleColor ?>"></i>Student Enrollments — Last 6 Months</h6></div>
      <div class="card-body"><canvas id="enrollChart" height="120"></canvas></div>
    </div>
  </div>
</div>

<!-- Recent Lessons -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-road me-2" style="color:<?= $moduleColor ?>"></i>Recent Lessons</h6>
    <a href="lessons.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>Student</th><th>Instructor</th><th>Vehicle</th><th>Date</th><th>Lesson #</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($recentLessons)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-road fa-2x mb-2 d-block"></i>No lessons yet</td></tr>
        <?php else: foreach ($recentLessons as $l): ?>
          <tr>
            <td class="fw-semibold"><?= e($l['student_name'] ?? '—') ?></td>
            <td><?= e($l['instructor_name'] ?? '—') ?></td>
            <td><?= e($l['vehicle_name'] ?? '—') ?></td>
            <td><?= formatDate($l['lesson_date']) ?></td>
            <td><span class="badge bg-secondary">#<?= $l['lesson_number'] ?></span></td>
            <td><span class="badge bg-<?= $statusColors[$l['status']] ?? 'secondary' ?>"><?= ucfirst($l['status']) ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$lStatusLabels = json_encode(array_map('ucfirst', $lStatuses));
$lCountsJ      = json_encode($lCounts);
$monthsJ       = json_encode($months);
$mCountsJ      = json_encode($mCounts);
$c = $moduleColor;
$extraJs = <<<JS
<script>
(function(){
  new Chart(document.getElementById('lessonChart'),{
    type:'doughnut',
    data:{labels:{$lStatusLabels},datasets:[{data:{$lCountsJ},backgroundColor:['#6c757d','#0d6efd','#198754','#dc3545']}]},
    options:{responsive:true,plugins:{legend:{position:'bottom'}}}
  });
  new Chart(document.getElementById('enrollChart'),{
    type:'line',
    data:{labels:{$monthsJ},datasets:[{label:'Enrollments',data:{$mCountsJ},borderColor:'{$c}',backgroundColor:'{$c}22',fill:true,tension:.4,pointBackgroundColor:'{$c}'}]},
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
  });
})();
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
