<?php
$moduleSlug  = 'school';
$moduleName  = 'School Management';
$moduleIcon  = 'fas fa-school';
$moduleColor = '#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'parents.php','icon'=>'fas fa-users','label'=>'Parents'],['url'=>'staff.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Staff'],['url'=>'classes.php','icon'=>'fas fa-chalkboard','label'=>'Classes'],['url'=>'subjects.php','icon'=>'fas fa-book','label'=>'Subjects'],['url'=>'timetable.php','icon'=>'fas fa-calendar-alt','label'=>'Timetable'],['url'=>'attendance.php','icon'=>'fas fa-clipboard-check','label'=>'Attendance'],['url'=>'exams.php','icon'=>'fas fa-file-alt','label'=>'Exams'],['url'=>'results.php','icon'=>'fas fa-chart-line','label'=>'Results'],['url'=>'fees.php','icon'=>'fas fa-money-bill','label'=>'Fees'],['url'=>'library.php','icon'=>'fas fa-book-reader','label'=>'Library'],['url'=>'transport.php','icon'=>'fas fa-bus','label'=>'Transport'],['url'=>'events.php','icon'=>'fas fa-calendar-day','label'=>'Events'],['url'=>'notices.php','icon'=>'fas fa-bullhorn','label'=>'Notices'],['url'=>'grades.php','icon'=>'fas fa-star','label'=>'Grades'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

// General aggregated metrics
$totalStudents = countRows('sch_students', 'org_id = ?', [$orgId]);
$activeStudents = countRows('sch_students', "org_id = ? AND status = 'active'", [$orgId]);
$graduatedStudents = countRows('sch_students', "org_id = ? AND status = 'graduated'", [$orgId]);
$transferredStudents = countRows('sch_students', "org_id = ? AND status = 'transferred'", [$orgId]);

// Gender breakdown
$maleCount = countRows('sch_students', "org_id = ? AND gender = 'male'", [$orgId]);
$femaleCount = countRows('sch_students', "org_id = ? AND gender = 'female'", [$orgId]);

// Total Invoice billing amount vs Collections
$totalInvoiced = 0;
$totalCollected = 0;
$outstandingFees = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0), COALESCE(SUM(paid),0), COALESCE(SUM(balance),0) FROM sch_fees WHERE org_id = ?");
    $stmt->execute([$orgId]);
    $row = $stmt->fetch();
    $totalInvoiced = (float)($row[0] ?? 0);
    $totalCollected = (float)($row[1] ?? 0);
    $outstandingFees = (float)($row[2] ?? 0);
} catch (Exception $e) {}

// Grade Distribution statistics
$gradesDistribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
try {
    $stmt = $pdo->prepare("SELECT grade, COUNT(*) AS count FROM sch_grades WHERE org_id = ? GROUP BY grade");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $row) {
        $g = strtoupper($row['grade']);
        if (array_key_exists($g, $gradesDistribution)) {
            $gradesDistribution[$g] = (int)$row['count'];
        }
    }
} catch (Exception $e) {}

// Top Performing Subjects
$subjectAverages = [];
try {
    $stmt = $pdo->prepare("SELECT sub.name AS subject_name, sub.code, AVG(g.total_score) AS avg_score 
                           FROM sch_grades g 
                           JOIN sch_subjects sub ON g.subject_id = sub.id
                           WHERE g.org_id = ? 
                           GROUP BY g.subject_id 
                           ORDER BY avg_score DESC 
                           LIMIT 5");
    $stmt->execute([$orgId]);
    $subjectAverages = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>School Performance & Fee Reports</h4>
    <p class="text-muted mb-0">Review student demographics, exam grading metrics and collection pipelines</p>
  </div>
  <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print Report Card</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-user-graduate"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalStudents ?></div>
        <div class="stat-label">Total Enrollment</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalInvoiced) ?></div>
        <div class="stat-label">Total Invoiced</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalCollected) ?></div>
        <div class="stat-label">Total Fees Collected</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($outstandingFees) ?></div>
        <div class="stat-label">Outstanding Arrears</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Grade Performance Distribution -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-award me-2 text-success"></i>Score Book Grade Distribution</h6></div>
      <div class="card-body">
        <div style="height:300px;"><canvas id="gradeDistChart"></canvas></div>
      </div>
    </div>
  </div>
  
  <!-- Demographics (Gender) Doughnut -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-venus-mars me-2 text-success"></i>Student Gender Demographics</h6></div>
      <div class="card-body d-flex flex-column justify-content-center">
        <div style="height:220px;"><canvas id="genderChart"></canvas></div>
        <div class="mt-3 text-center small text-muted">
          Male Enrolled: <strong><?= $maleCount ?></strong> &nbsp;|&nbsp; 
          Female Enrolled: <strong><?= $femaleCount ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Top Performing Subjects Roster -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-star me-2 text-success"></i>Highest Average Subjects</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Subject Details</th><th class="text-end">Average Score (100%)</th></tr>
            </thead>
            <tbody>
              <?php if (empty($subjectAverages)): ?>
              <tr><td colspan="2" class="text-center text-muted py-4">No performance metrics registered.</td></tr>
              <?php else: foreach ($subjectAverages as $sa): ?>
              <tr>
                <td class="fw-semibold text-dark"><?= e($sa['subject_name']) ?> <small class="text-muted">(<?= e($sa['code']) ?>)</small></td>
                <td class="text-end fw-bold text-success"><?= number_format($sa['avg_score'], 1) ?>%</td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Enrollment Status Details -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-pie me-2 text-success"></i>Enrollment Status Distribution</h6></div>
      <div class="card-body d-flex flex-column justify-content-between">
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1 small fw-semibold">
            <span>Active Students</span>
            <span><?= $totalStudents > 0 ? round(($activeStudents/$totalStudents)*100, 1) : 0 ?>% (<?= $activeStudents ?>)</span>
          </div>
          <div class="progress" style="height:8px;"><div class="progress-bar bg-success" style="width: <?= $totalStudents > 0 ? ($activeStudents/$totalStudents)*100 : 0 ?>%"></div></div>
        </div>
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1 small fw-semibold">
            <span>Graduated Alumni</span>
            <span><?= $totalStudents > 0 ? round(($graduatedStudents/$totalStudents)*100, 1) : 0 ?>% (<?= $graduatedStudents ?>)</span>
          </div>
          <div class="progress" style="height:8px;"><div class="progress-bar bg-primary" style="width: <?= $totalStudents > 0 ? ($graduatedStudents/$totalStudents)*100 : 0 ?>%"></div></div>
        </div>
        <div class="mb-0">
          <div class="d-flex justify-content-between mb-1 small fw-semibold">
            <span>Transferred</span>
            <span><?= $totalStudents > 0 ? round(($transferredStudents/$totalStudents)*100, 1) : 0 ?>% (<?= $transferredStudents ?>)</span>
          </div>
          <div class="progress" style="height:8px;"><div class="progress-bar bg-warning" style="width: <?= $totalStudents > 0 ? ($transferredStudents/$totalStudents)*100 : 0 ?>%"></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php
$gradeLabelsJson = json_encode(array_keys($gradesDistribution));
$gradeDataJson   = json_encode(array_values($gradesDistribution));

$genderLabelsJson = json_encode(['Male Students', 'Female Students']);
$genderDataJson   = json_encode([$maleCount, $femaleCount]);

$extraJs = <<<JS
<script>
// Grade distribution Chart
new Chart(document.getElementById('gradeDistChart'), {
  type: 'bar',
  data: {
    labels: {$gradeLabelsJson},
    datasets: [{
      label: 'Performance Score Entries',
      data: {$gradeDataJson},
      backgroundColor: '#1A8A4E',
      borderRadius: 4
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 } }
    }
  }
});

// Gender Doughnut Chart
new Chart(document.getElementById('genderChart'), {
  type: 'doughnut',
  data: {
    labels: {$genderLabelsJson},
    datasets: [{
      data: {$genderDataJson},
      backgroundColor: ['#0b5ed7', '#d63384']
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' } }
  }
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
