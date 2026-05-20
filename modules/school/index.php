<?php
$moduleSlug  = 'school';
$moduleName  = 'School Management';
$moduleIcon  = 'fas fa-school';
$moduleColor = '#1A8A4E';
$moduleNav   = [
    ['url' => 'index.php',   'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'students.php','icon' => 'fas fa-user-graduate',  'label' => 'Students'],
    ['url' => 'classes.php', 'icon' => 'fas fa-chalkboard',     'label' => 'Classes'],
    ['url' => 'fees.php',    'icon' => 'fas fa-money-bill',     'label' => 'Fees'],
    ['url' => 'grades.php',  'icon' => 'fas fa-star',           'label' => 'Grades'],
    ['url' => 'staff.php',   'icon' => 'fas fa-chalkboard-teacher','label' => 'Staff'],
    ['url' => 'reports.php', 'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalStudents = countRows('sch_students', 'org_id = ?', [$orgId]);
$totalClasses  = countRows('sch_classes', 'org_id = ?', [$orgId]);
$pendingFees   = 0;
$feeCollection = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(paid),0) FROM sch_fees WHERE org_id=?");
    $stmt->execute([$orgId]);
    $feeCollection = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM sch_fees WHERE org_id=? AND balance > 0");
    $stmt->execute([$orgId]);
    $pendingFees = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Recent students
$students = [];
try {
    $stmt = $pdo->prepare("SELECT s.*, c.name AS class_name 
                           FROM sch_students s 
                           LEFT JOIN sch_classes c ON s.class_id = c.id 
                           WHERE s.org_id=? 
                           ORDER BY s.created_at DESC 
                           LIMIT 10");
    $stmt->execute([$orgId]);
    $students = $stmt->fetchAll();
} catch (Exception $e) {}

// Enrollment by class
$classLabels  = [];
$classEnroll  = [];
try {
    $stmt = $pdo->prepare("SELECT c.name, COUNT(s.id) as cnt FROM sch_classes c LEFT JOIN sch_students s ON s.class_id=c.id AND s.org_id=? WHERE c.org_id=? GROUP BY c.id, c.name ORDER BY cnt DESC LIMIT 8");
    $stmt->execute([$orgId, $orgId]);
    foreach ($stmt->fetchAll() as $r) {
        $classLabels[] = $r['name'];
        $classEnroll[] = (int)$r['cnt'];
    }
} catch (Exception $e) {}

// Fee status doughnut (Paid, Partial, Unpaid)
$feePaid    = countRows('sch_fees', "org_id = ? AND status = 'paid'", [$orgId]);
$feePending = countRows('sch_fees', "org_id = ? AND status = 'partial'", [$orgId]);
$feeOverdue = countRows('sch_fees', "org_id = ? AND status = 'unpaid'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage students, academic classes, fee structures, and grades performance</p>
  </div>
  <a href="students.php" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-plus me-2"></i>Enroll Student</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-user-graduate"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalStudents ?></div><div class="stat-label">Total Students</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-chalkboard"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalClasses ?></div><div class="stat-label">Classes</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($feeCollection) ?></div><div class="stat-label">Total Fees Collected</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($pendingFees) ?></div><div class="stat-label">Pending / Balance Fees</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2 text-success"></i>Enrollment by Class</h6></div>
      <div class="card-body"><canvas id="classChart" height="120"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2 text-success"></i>Fee Accounts Status</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="feeChart" height="220"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-user-graduate me-2 text-success"></i>Recent Student Enrollments</h6>
    <a href="students.php" class="btn btn-sm btn-outline-success">View All Students</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="studentsTable">
        <thead class="table-light">
          <tr><th>Adm No</th><th>Full Name</th><th>Assigned Class</th><th>Gender</th><th>Status</th><th>Enrolled Date</th></tr>
        </thead>
        <tbody>
          <?php if (empty($students)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No students enrolled yet.</td></tr>
          <?php else: foreach ($students as $s): ?>
          <tr>
            <td class="fw-semibold"><?= e($s['admission_no'] ?? '#' . $s['id']) ?></td>
            <td><?= e(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?></td>
            <td><?= e($s['class_name'] ?? 'Unassigned') ?></td>
            <td><?= ucfirst($s['gender'] ?? '—') ?></td>
            <td>
              <?php
              $badges = ['active' => 'success', 'inactive' => 'secondary', 'graduated' => 'primary', 'transferred' => 'warning'];
              $bg = $badges[$s['status']] ?? 'info';
              ?>
              <span class="badge bg-<?= $bg ?>"><?= ucfirst($s['status'] ?? 'active') ?></span>
            </td>
            <td><?= formatDate($s['admitted_on'] ?? $s['created_at'] ?? '') ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script>
(function(){
  var cl = ' . json_encode($classLabels) . ';
  var ce = ' . json_encode($classEnroll) . ';
  if(cl.length){
    new Chart(document.getElementById("classChart"),{
      type:"bar",
      data:{labels:cl,datasets:[{label:"Students",data:ce,backgroundColor:"#1A8A4E",borderRadius:5}]},
      options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
    });
  }
  new Chart(document.getElementById("feeChart"),{
    type:"doughnut",
    data:{labels:["Paid","Partial / Pending","Unpaid / Overdue"],datasets:[{data:[' . $feePaid . ',' . $feePending . ',' . $feeOverdue . '],backgroundColor:["#1A8A4E","#f39c12","#e74c3c"]}]},
    options:{responsive:true,plugins:{legend:{position:"bottom"}}}
  });
  $("#studentsTable").DataTable({pageLength:10,order:[[5,"desc"]]});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
