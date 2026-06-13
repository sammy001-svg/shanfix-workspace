<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalStudents  = countRows('sch_students', 'org_id = ?', [$orgId]);
$totalClasses   = countRows('sch_classes', 'org_id = ?', [$orgId]);
$totalTeachers  = countRows('sch_teachers', "org_id = ? AND status = 'active'", [$orgId]);
$pendingFees    = 0;
$feeCollection  = 0;

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

// Today's attendance rate
$todayTotal = 0; $todayPresent = 0; $todayRate = null;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='present') AS present FROM sch_attendance WHERE org_id=? AND att_date=CURDATE()");
    $stmt->execute([$orgId]);
    $ar = $stmt->fetch();
    if ($ar && $ar['total'] > 0) {
        $todayTotal   = (int)$ar['total'];
        $todayPresent = (int)$ar['present'];
        $todayRate    = round($todayPresent / $todayTotal * 100);
    }
} catch (Exception $e) {}

// Upcoming exams
$upcomingExams = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sch_exams WHERE org_id=? AND status IN ('upcoming','ongoing')");
    $stmt->execute([$orgId]);
    $upcomingExams = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Monthly fee collection trend (last 6 months)
$monthLabels = []; $monthAmounts = [];
try {
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(fp.payment_date,'%b %Y') AS month,
                SUM(fp.amount_paid) AS total
         FROM sch_fee_payments fp
         JOIN sch_fees f ON fp.fee_id = f.id
         WHERE f.org_id=? AND fp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY DATE_FORMAT(fp.payment_date,'%Y-%m')
         ORDER BY MIN(fp.payment_date)"
    );
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $r) {
        $monthLabels[]  = $r['month'];
        $monthAmounts[] = (float)$r['total'];
    }
} catch (Exception $e) {}

// Recent students
$students = [];
try {
    $stmt = $pdo->prepare("SELECT s.*, c.name AS class_name FROM sch_students s LEFT JOIN sch_classes c ON s.class_id = c.id WHERE s.org_id=? ORDER BY s.created_at DESC LIMIT 10");
    $stmt->execute([$orgId]);
    $students = $stmt->fetchAll();
} catch (Exception $e) {}

// Enrollment by class
$classLabels = []; $classEnroll = [];
try {
    $stmt = $pdo->prepare("SELECT c.name, COUNT(s.id) as cnt FROM sch_classes c LEFT JOIN sch_students s ON s.class_id=c.id AND s.org_id=? WHERE c.org_id=? GROUP BY c.id, c.name ORDER BY cnt DESC LIMIT 8");
    $stmt->execute([$orgId, $orgId]);
    foreach ($stmt->fetchAll() as $r) {
        $classLabels[] = $r['name'];
        $classEnroll[] = (int)$r['cnt'];
    }
} catch (Exception $e) {}

// Fee status doughnut
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

<!-- KPI row 1: core counts -->
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-user-graduate"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalStudents ?></div><div class="stat-label">Total Students</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-chalkboard"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalClasses ?></div><div class="stat-label">Classes</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#e8f4fd"><i class="fas fa-chalkboard-teacher" style="color:#3498db"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalTeachers ?></div><div class="stat-label">Active Teachers</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#fef5e7"><i class="fas fa-calendar-check" style="color:#f39c12"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $upcomingExams ?></div><div class="stat-label">Upcoming Exams</div></div>
    </div>
  </div>
</div>

<!-- KPI row 2: financial + attendance -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.2rem"><?= formatCurrency($feeCollection) ?></div><div class="stat-label">Fees Collected</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.2rem"><?= formatCurrency($pendingFees) ?></div><div class="stat-label">Pending Fees</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#d4edda"><i class="fas fa-clipboard-check" style="color:#27ae60"></i></div>
      <div class="stat-body">
        <div class="stat-value <?= $todayRate !== null ? ($todayRate >= 80 ? 'text-success' : 'text-warning') : '' ?>">
          <?= $todayRate !== null ? $todayRate . '%' : '&mdash;' ?>
        </div>
        <div class="stat-label">Attendance Today<?= $todayTotal > 0 ? " ($todayPresent/$todayTotal)" : '' ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <a href="students.php" class="stat-card text-decoration-none d-flex" style="align-items:center">
      <div class="stat-icon" style="background:#f0f4ff"><i class="fas fa-users" style="color:#5c6bc0"></i></div>
      <div class="stat-body"><div class="stat-value text-dark"><?= $totalStudents ?></div><div class="stat-label">Manage Students</div></div>
    </a>
  </div>
</div>

<!-- Charts row -->
<div class="row g-3 mb-4">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2 text-success"></i>Enrollment by Class</h6></div>
      <div class="card-body"><canvas id="classChart" height="160"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2 text-success"></i>Fee Accounts Status</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="feeChart" height="200"></canvas></div>
    </div>
  </div>
  <div class="col-lg-3">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-line me-2 text-primary"></i>Fee Trend (6m)</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <?php if (empty($monthLabels)): ?>
        <div class="text-center text-muted small"><i class="fas fa-chart-line fa-2x mb-2 d-block opacity-25"></i>No payment data yet</div>
        <?php else: ?>
        <canvas id="trendChart" height="200"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Quick actions -->
<div class="row g-3 mb-4">
  <?php foreach ([
    ['students.php',  'fa-user-graduate', 'Students',     $moduleColor,   'Enroll & manage'],
    ['teachers.php',  'fa-chalkboard-teacher','Teachers', '#3498db',      'Staff records'],
    ['fees.php',      'fa-money-bill-alt','Fees',          '#27ae60',      'Invoices & payments'],
    ['results.php',   'fa-graduation-cap','Results',       '#9b59b6',      'Exam grades'],
    ['attendance.php','fa-clipboard-check','Attendance',   '#f39c12',      'Daily records'],
    ['timetable.php', 'fa-calendar-alt',  'Timetable',    '#e74c3c',      'Class schedules'],
  ] as [$url, $icon, $label, $color, $sub]): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="<?= $url ?>" class="card border-0 shadow-sm text-decoration-none h-100 text-center p-3" style="transition:.15s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
      <div class="d-flex align-items-center justify-content-center rounded-circle mx-auto mb-2"
           style="width:46px;height:46px;background:<?= $color ?>18;color:<?= $color ?>;font-size:1.1rem">
        <i class="fas <?= $icon ?>"></i>
      </div>
      <div class="fw-semibold small" style="color:#0B2D4E"><?= $label ?></div>
      <div class="text-muted" style="font-size:.7rem"><?= $sub ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- Recent enrollments -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-user-graduate me-2 text-success"></i>Recent Student Enrollments</h6>
    <a href="students.php" class="btn btn-sm btn-outline-success">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="studentsTable">
        <thead class="table-light">
          <tr><th>Adm No</th><th>Full Name</th><th>Class</th><th class="d-none d-md-table-cell">Gender</th><th>Status</th><th class="d-none d-md-table-cell">Enrolled</th></tr>
        </thead>
        <tbody>
          <?php if (empty($students)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No students enrolled yet.</td></tr>
          <?php else: foreach ($students as $s): ?>
          <tr>
            <td class="fw-semibold"><?= e($s['admission_no'] ?? '#' . $s['id']) ?></td>
            <td><?= e(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?></td>
            <td><?= e($s['class_name'] ?? 'Unassigned') ?></td>
            <td class="d-none d-md-table-cell"><?= ucfirst($s['gender'] ?? '&mdash;') ?></td>
            <td>
              <?php $badges = ['active'=>'success','inactive'=>'secondary','graduated'=>'primary','transferred'=>'warning']; ?>
              <span class="badge bg-<?= $badges[$s['status']] ?? 'info' ?>"><?= ucfirst($s['status'] ?? 'active') ?></span>
            </td>
            <td class="d-none d-md-table-cell"><?= formatDate($s['admitted_on'] ?? $s['created_at'] ?? '') ?></td>
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
      data:{labels:cl,datasets:[{label:"Students",data:ce,backgroundColor:"#1A8A4E",borderRadius:5,borderSkipped:false}]},
      options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:"#f1f3f5"}},x:{grid:{display:false}}}}
    });
  }
  new Chart(document.getElementById("feeChart"),{
    type:"doughnut",
    data:{labels:["Paid","Partial","Unpaid"],datasets:[{data:[' . $feePaid . ',' . $feePending . ',' . $feeOverdue . '],backgroundColor:["#1A8A4E","#f39c12","#e74c3c"],borderWidth:0}]},
    options:{responsive:true,plugins:{legend:{position:"bottom",labels:{boxWidth:10,font:{size:11}}}},cutout:"65%"}
  });
  var ml = ' . json_encode($monthLabels) . ';
  var ma = ' . json_encode($monthAmounts) . ';
  if(ml.length && document.getElementById("trendChart")){
    new Chart(document.getElementById("trendChart"),{
      type:"line",
      data:{labels:ml,datasets:[{label:"Collected",data:ma,borderColor:"#1A8A4E",backgroundColor:"#1A8A4E22",fill:true,tension:.4,pointRadius:3}]},
      options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:"#f1f3f5"},ticks:{font:{size:10}}},x:{grid:{display:false},ticks:{font:{size:10}}}}}
    });
  }
  if(typeof $ !== "undefined") $("#studentsTable").DataTable({pageLength:10,order:[[5,"desc"]],columnDefs:[{targets:[3,5],className:"d-none d-md-table-cell"}]});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
