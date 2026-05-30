<?php
$moduleSlug  = 'hrm';
$moduleName  = 'Human Resource Management';
$moduleIcon  = 'fas fa-users-cog';
$moduleColor = '#2c3e50';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',     'label' => 'Dashboard'],
    ['url' => 'employees.php',    'icon' => 'fas fa-id-badge',           'label' => 'Employees'],
    ['url' => 'departments.php',  'icon' => 'fas fa-sitemap',            'label' => 'Departments'],
    ['url' => 'payroll.php',      'icon' => 'fas fa-money-check',        'label' => 'Payroll'],
    ['url' => 'leave.php',        'icon' => 'fas fa-calendar-minus',     'label' => 'Leave'],
    ['url' => 'attendance.php',   'icon' => 'fas fa-fingerprint',        'label' => 'Attendance'],
    ['url' => 'benefits.php',     'icon' => 'fas fa-gift',               'label' => 'Benefits'],
    ['url' => 'disciplinary.php', 'icon' => 'fas fa-gavel',              'label' => 'Disciplinary'],
    ['url' => 'recruitment.php',  'icon' => 'fas fa-user-plus',          'label' => 'Recruitment'],
    ['url' => 'performance.php',  'icon' => 'fas fa-star',               'label' => 'Performance'],
    ['url' => 'training.php',     'icon' => 'fas fa-chalkboard-teacher', 'label' => 'Training'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalEmployees   = countRows('hrm_employees', 'org_id = ? AND status = ?', [$orgId, 'active']);
$totalDepartments = countRows('hrm_departments', 'org_id = ?', [$orgId]);
$pendingLeave     = countRows('hrm_leave_requests', 'org_id = ? AND status = ?', [$orgId, 'pending']);
$monthPayroll     = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(net_salary),0) FROM hrm_payroll WHERE org_id=? AND DATE_FORMAT(pay_date,'%Y-%m')=?");
    $stmt->execute([$orgId, date('Y-m')]);
    $monthPayroll = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Recent employees
$employees = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM hrm_employees WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$orgId]);
    $employees = $stmt->fetchAll();
} catch (Exception $e) {}

// Employees by department (doughnut)
$deptLabels = [];
$deptCounts = [];
try {
    $stmt = $pdo->prepare("SELECT d.name, COUNT(e.id) as cnt FROM hrm_departments d LEFT JOIN hrm_employees e ON e.department_id=d.id AND e.org_id=? WHERE d.org_id=? GROUP BY d.id, d.name ORDER BY cnt DESC LIMIT 8");
    $stmt->execute([$orgId, $orgId]);
    foreach ($stmt->fetchAll() as $r) {
        $deptLabels[] = $r['name'];
        $deptCounts[] = (int)$r['cnt'];
    }
} catch (Exception $e) {}

// Attendance rate bar chart (last 7 days)
$attLabels  = [];
$attPresent = [];
$attAbsent  = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $attLabels[] = date('D d', strtotime($date));
    try {
        $p = countRows('hrm_attendance', 'org_id = ? AND date = ? AND status = ?', [$orgId, $date, 'present']);
        $a = countRows('hrm_attendance', 'org_id = ? AND date = ? AND status = ?', [$orgId, $date, 'absent']);
        $attPresent[] = $p;
        $attAbsent[]  = $a;
    } catch (Exception $e) {
        $attPresent[] = 0;
        $attAbsent[]  = 0;
    }
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage employees, payroll, leave, and attendance</p>
  </div>
  <a href="employees.php?action=add" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-plus me-2"></i>Add Employee</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-id-badge"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalEmployees ?></div><div class="stat-label">Active Employees</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-sitemap"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalDepartments ?></div><div class="stat-label">Departments</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-money-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($monthPayroll) ?></div><div class="stat-label">This Month Payroll</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-calendar-minus"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingLeave ?></div><div class="stat-label">Leave Requests</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Staff by Department</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="deptChart" height="220"></canvas></div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Attendance (Last 7 Days)</h6></div>
      <div class="card-body"><canvas id="attChart" height="120"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-id-badge me-2" style="color:<?= $moduleColor ?>"></i>Recent Employees</h6>
    <a href="employees.php" class="btn btn-sm" style="border-color:<?= $moduleColor ?>;color:<?= $moduleColor ?>">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="hrmTable">
        <thead class="table-light">
          <tr><th>Emp #</th><th>Name</th><th>Department</th><th>Position</th><th>Phone</th><th>Status</th><th>Joined</th></tr>
        </thead>
        <tbody>
          <?php if (empty($employees)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No employees found</td></tr>
          <?php else: foreach ($employees as $emp): ?>
          <tr>
            <td class="fw-semibold"><?= e($emp['employee_number'] ?? '#' . $emp['id']) ?></td>
            <td><?= e($emp['name'] ?? '—') ?></td>
            <td><?= e($emp['department_name'] ?? '—') ?></td>
            <td><?= e($emp['position'] ?? '—') ?></td>
            <td><?= e($emp['phone'] ?? '—') ?></td>
            <td><?= statusBadge($emp['status'] ?? 'active') ?></td>
            <td><?= formatDate($emp['hire_date'] ?? $emp['created_at'] ?? '') ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
(function(){
  var dl = ' . json_encode($deptLabels) . ';
  var dc = ' . json_encode($deptCounts) . ';
  if(dl.length){
    new Chart(document.getElementById("deptChart"),{
      type:"doughnut",
      data:{labels:dl,datasets:[{data:dc,backgroundColor:["#2c3e50","#1A8A4E","#f39c12","#e74c3c","#8e44ad","#2980b9","#16a085","#d35400"]}]},
      options:{responsive:true,plugins:{legend:{position:"bottom"}}}
    });
  }
  new Chart(document.getElementById("attChart"),{
    type:"bar",
    data:{
      labels:' . json_encode($attLabels) . ',
      datasets:[
        {label:"Present",data:' . json_encode($attPresent) . ',backgroundColor:"#1A8A4E",borderRadius:4},
        {label:"Absent",data:' . json_encode($attAbsent) . ',backgroundColor:"#e74c3c",borderRadius:4}
      ]
    },
    options:{responsive:true,plugins:{legend:{position:"top"}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
  });
  $("#hrmTable").DataTable({pageLength:10,order:[[6,"desc"]]});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
