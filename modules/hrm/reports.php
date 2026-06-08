<?php
// ── HRM: Reports ───────────────────────────────────────────────
$moduleSlug  = 'hrm';
$moduleName  = 'HRM System';
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
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

// ── Filters ─────────────────────────────────────────────────────────────────
$filterYear  = (int)($_GET['year']  ?? date('Y'));
$filterMonth = (int)($_GET['month'] ?? date('n'));
$filterDept  = (int)($_GET['dept']  ?? 0);

$monthStart = sprintf('%04d-%02d-01', $filterYear, $filterMonth);
$monthEnd   = date('Y-m-t', strtotime($monthStart));

// ── Departments list (for filter) ────────────────────────────────────────────
$departments = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM hrm_departments WHERE org_id=? ORDER BY name");
    $stmt->execute([$orgId]);
    $departments = $stmt->fetchAll();
} catch (Exception $e) {}

// ── 1. WORKFORCE SUMMARY ─────────────────────────────────────────────────────
$totalActive      = countRows('hrm_employees', 'org_id=? AND status=?', [$orgId, 'active']);
$totalInactive    = countRows('hrm_employees', 'org_id=? AND status=?', [$orgId, 'inactive']);
$totalOnLeave     = countRows('hrm_employees', 'org_id=? AND status=?', [$orgId, 'on_leave']);
$totalTerminated  = countRows('hrm_employees', 'org_id=? AND status=?', [$orgId, 'terminated']);

// Employees by department
$byDept = [];
try {
    $dWhere = $filterDept ? 'AND e.department_id=?' : '';
    $dParams = $filterDept ? [$orgId, $filterDept] : [$orgId];
    $stmt = $pdo->prepare("
        SELECT d.name AS dept_name,
               COUNT(e.id) AS total,
               SUM(e.status='active') AS active,
               AVG(e.salary) AS avg_salary
        FROM hrm_departments d
        LEFT JOIN hrm_employees e ON e.department_id=d.id AND e.org_id=?
        WHERE d.org_id=? $dWhere
        GROUP BY d.id, d.name
        ORDER BY total DESC
    ");
    $filterDept
        ? $stmt->execute([$orgId, $orgId, $filterDept])
        : $stmt->execute([$orgId, $orgId]);
    $byDept = $stmt->fetchAll();
} catch (Exception $e) {}

// Employees by employment type
$byType = [];
try {
    $stmt = $pdo->prepare("
        SELECT employment_type, COUNT(*) AS cnt
        FROM hrm_employees
        WHERE org_id=? AND status='active'
        GROUP BY employment_type
        ORDER BY cnt DESC
    ");
    $stmt->execute([$orgId]);
    $byType = $stmt->fetchAll();
} catch (Exception $e) {}

// Headcount hired per month (last 12 months)
$hireLabels = [];
$hireData   = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $hireLabels[] = date('M Y', strtotime("-$i months"));
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM hrm_employees WHERE org_id=? AND DATE_FORMAT(date_hired,'%Y-%m')=?");
        $stmt->execute([$orgId, $m]);
        $hireData[] = (int)$stmt->fetchColumn();
    } catch (Exception $e) { $hireData[] = 0; }
}

// Gender split
$genderLabels = ['Male', 'Female'];
$genderCounts = [0, 0];
try {
    $stmt = $pdo->prepare("SELECT gender, COUNT(*) AS cnt FROM hrm_employees WHERE org_id=? AND status='active' GROUP BY gender");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $r) {
        if ($r['gender'] === 'male')   $genderCounts[0] = (int)$r['cnt'];
        if ($r['gender'] === 'female') $genderCounts[1] = (int)$r['cnt'];
    }
} catch (Exception $e) {}

// ── 2. PAYROLL SUMMARY ───────────────────────────────────────────────────────
$payrollMonth = 0;
$payrollYear  = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(net_salary),0) FROM hrm_payroll WHERE org_id=? AND period=?");
    $stmt->execute([$orgId, sprintf('%04d-%02d', $filterYear, $filterMonth)]);
    $payrollMonth = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(net_salary),0) FROM hrm_payroll WHERE org_id=? AND LEFT(period,4)=?");
    $stmt->execute([$orgId, $filterYear]);
    $payrollYear = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Payroll trend (12 months)
$payLabels = [];
$payData   = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $payLabels[] = date('M Y', strtotime("-$i months"));
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(net_salary),0) FROM hrm_payroll WHERE org_id=? AND period=?");
        $stmt->execute([$orgId, $m]);
        $payData[] = (float)$stmt->fetchColumn();
    } catch (Exception $e) { $payData[] = 0; }
}

// Payroll by department (selected month)
$payByDept = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.name AS dept_name,
               COUNT(p.id) AS emp_count,
               COALESCE(SUM(p.net_salary),0) AS total_pay
        FROM hrm_departments d
        LEFT JOIN hrm_employees e ON e.department_id=d.id AND e.org_id=?
        LEFT JOIN hrm_payroll p ON p.employee_id=e.id AND p.period=? AND p.org_id=?
        WHERE d.org_id=?
        GROUP BY d.id, d.name
        HAVING total_pay > 0
        ORDER BY total_pay DESC
    ");
    $stmt->execute([
        $orgId,
        sprintf('%04d-%02d', $filterYear, $filterMonth),
        $orgId,
        $orgId
    ]);
    $payByDept = $stmt->fetchAll();
} catch (Exception $e) {}

// ── 3. LEAVE ANALYSIS ────────────────────────────────────────────────────────
$leavePending  = countRows('hrm_leave_requests', 'org_id=?', [$orgId]) > 0
    ? countRows('hrm_leave_requests', 'org_id=? AND status=?', [$orgId, 'pending']) : 0;
$leaveApproved = countRows('hrm_leave_requests', 'org_id=? AND status=?', [$orgId, 'approved']);
$leaveRejected = countRows('hrm_leave_requests', 'org_id=? AND status=?', [$orgId, 'rejected']);
$leaveTotalDays = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(days),0) FROM hrm_leave_requests WHERE org_id=? AND status='approved' AND YEAR(start_date)=?");
    $stmt->execute([$orgId, $filterYear]);
    $leaveTotalDays = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Leave by month (approved days, selected year)
$leaveMonthLabels = [];
$leaveMonthData   = [];
for ($m = 1; $m <= 12; $m++) {
    $leaveMonthLabels[] = date('M', mktime(0,0,0,$m,1));
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(days),0) FROM hrm_leave_requests WHERE org_id=? AND status='approved' AND MONTH(start_date)=? AND YEAR(start_date)=?");
        $stmt->execute([$orgId, $m, $filterYear]);
        $leaveMonthData[] = (int)$stmt->fetchColumn();
    } catch (Exception $e) { $leaveMonthData[] = 0; }
}

// Top leave takers (approved, year)
$leaveTopEmps = [];
try {
    $stmt = $pdo->prepare("
        SELECT CONCAT(e.first_name,' ',e.last_name) AS emp_name,
               d.name AS dept_name,
               SUM(lr.days) AS total_days,
               COUNT(lr.id) AS applications
        FROM hrm_leave_requests lr
        JOIN hrm_employees e ON lr.employee_id=e.id
        LEFT JOIN hrm_departments d ON e.department_id=d.id
        WHERE lr.org_id=? AND lr.status='approved' AND YEAR(lr.start_date)=?
        GROUP BY lr.employee_id, emp_name, dept_name
        ORDER BY total_days DESC
        LIMIT 10
    ");
    $stmt->execute([$orgId, $filterYear]);
    $leaveTopEmps = $stmt->fetchAll();
} catch (Exception $e) {}

// ── 4. ATTENDANCE REPORT ─────────────────────────────────────────────────────
$attPresent = 0; $attAbsent = 0; $attLate = 0; $attHalf = 0;
try {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) AS cnt
        FROM hrm_attendance
        WHERE org_id=? AND date BETWEEN ? AND ?
        GROUP BY status
    ");
    $stmt->execute([$orgId, $monthStart, $monthEnd]);
    foreach ($stmt->fetchAll() as $r) {
        match ($r['status']) {
            'present'  => $attPresent += (int)$r['cnt'],
            'absent'   => $attAbsent  += (int)$r['cnt'],
            'late'     => $attLate    += (int)$r['cnt'],
            'half_day' => $attHalf    += (int)$r['cnt'],
            default    => null,
        };
    }
} catch (Exception $e) {}

$attTotal = $attPresent + $attAbsent + $attLate + $attHalf;
$attRate  = $attTotal > 0 ? round(($attPresent + $attLate) / $attTotal * 100, 1) : 0;

// Attendance by department (month)
$attByDept = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.name AS dept_name,
               COUNT(a.id) AS total,
               SUM(a.status='present') AS present,
               SUM(a.status='absent')  AS absent,
               SUM(a.status='late')    AS late
        FROM hrm_departments d
        LEFT JOIN hrm_employees e ON e.department_id=d.id AND e.org_id=?
        LEFT JOIN hrm_attendance a ON a.employee_id=e.id AND a.org_id=? AND a.date BETWEEN ? AND ?
        WHERE d.org_id=?
        GROUP BY d.id, d.name
        HAVING total > 0
        ORDER BY present DESC
    ");
    $stmt->execute([$orgId, $orgId, $monthStart, $monthEnd, $orgId]);
    $attByDept = $stmt->fetchAll();
} catch (Exception $e) {}

// Daily attendance trend (month)
$attDayLabels = [];
$attDayPresent = [];
$attDayAbsent  = [];
try {
    $daysInMonth = (int)date('t', strtotime($monthStart));
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $filterYear, $filterMonth, $d);
        $attDayLabels[] = $d;
        $p = countRows('hrm_attendance', 'org_id=? AND date=? AND status IN (?,?)', [$orgId, $dateStr, 'present', 'late']);
        $a = countRows('hrm_attendance', 'org_id=? AND date=? AND status=?', [$orgId, $dateStr, 'absent']);
        $attDayPresent[] = $p;
        $attDayAbsent[]  = $a;
    }
} catch (Exception $e) {}

?>

<!-- ── Page header ───────────────────────────────────────────────────────── -->
<div class="page-header d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>HRM Reports</h4>
    <p class="text-muted mb-0">Workforce analytics, payroll, leave, and attendance insights</p>
  </div>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
      <i class="fas fa-print me-1"></i>Print
    </button>
  </div>
</div>

<!-- ── Period / Dept Filter ──────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
      <label class="small fw-semibold text-muted mb-0">Filter:</label>
      <select name="month" class="form-select form-select-sm" style="width:130px">
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m === $filterMonth ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
        <?php endfor; ?>
      </select>
      <select name="year" class="form-select form-select-sm" style="width:90px">
        <?php for ($y = date('Y'); $y >= date('Y') - 4; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $filterYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
      <?php if (!empty($departments)): ?>
      <select name="dept" class="form-select form-select-sm" style="width:180px">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= $filterDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Apply</button>
      <a href="reports.php" class="btn btn-sm btn-outline-secondary">Reset</a>
    </form>
  </div>
</div>

<!-- ── Top KPI cards ─────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalActive ?></div>
        <div class="stat-label">Active Employees</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-money-check-alt"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($payrollMonth) ?></div>
        <div class="stat-label">Month Payroll</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-calendar-minus"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $leaveTotalDays ?></div>
        <div class="stat-label">Leave Days (<?= $filterYear ?>)</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $attRate >= 85 ? 'green' : ($attRate >= 70 ? 'warning' : 'danger') ?>-bg"><i class="fas fa-fingerprint"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $attRate ?>%</div>
        <div class="stat-label">Attendance Rate</div>
      </div>
    </div>
  </div>
</div>

<!-- ── Report Tabs ───────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-4" id="reportTabs">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#workforce"><i class="fas fa-id-badge me-1"></i>Workforce</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#payroll"><i class="fas fa-money-check me-1"></i>Payroll</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#leave"><i class="fas fa-calendar-minus me-1"></i>Leave</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#attendance"><i class="fas fa-fingerprint me-1"></i>Attendance</a></li>
</ul>

<div class="tab-content">

  <!-- ══ WORKFORCE TAB ══════════════════════════════════════════════════════ -->
  <div class="tab-pane fade show active" id="workforce">

    <!-- Status + Gender cards -->
    <div class="row g-3 mb-4">
      <div class="col-lg-8">
        <div class="card h-100">
          <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Employee Status Breakdown</h6></div>
          <div class="card-body">
            <div class="row g-3">
              <?php
              $statuses = [
                  ['Active',     $totalActive,    'success', 'fa-user-check'],
                  ['Inactive',   $totalInactive,  'secondary','fa-user-slash'],
                  ['On Leave',   $totalOnLeave,   'warning', 'fa-user-clock'],
                  ['Terminated', $totalTerminated,'danger',  'fa-user-times'],
              ];
              $grandTotal = max(1, $totalActive + $totalInactive + $totalOnLeave + $totalTerminated);
              foreach ($statuses as [$lbl, $cnt, $color, $icon]): ?>
              <div class="col-6 col-md-3">
                <div class="border rounded-3 p-3 text-center">
                  <div class="fw-800 fs-4 text-<?= $color ?>"><?= $cnt ?></div>
                  <div class="small text-muted"><?= $lbl ?></div>
                  <div class="progress mt-2" style="height:4px">
                    <div class="progress-bar bg-<?= $color ?>" style="width:<?= round($cnt / $grandTotal * 100) ?>%"></div>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header"><h6 class="mb-0"><i class="fas fa-venus-mars me-2" style="color:<?= $moduleColor ?>"></i>Gender Split</h6></div>
          <div class="card-body d-flex align-items-center justify-content-center">
            <canvas id="genderChart" height="180"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Headcount trend -->
    <div class="card mb-4">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-line me-2" style="color:<?= $moduleColor ?>"></i>New Hires — Last 12 Months</h6></div>
      <div class="card-body"><canvas id="hireChart" height="100"></canvas></div>
    </div>

    <!-- Dept + Employment type -->
    <div class="row g-3 mb-4">
      <div class="col-lg-8">
        <div class="card h-100">
          <div class="card-header"><h6 class="mb-0"><i class="fas fa-sitemap me-2" style="color:<?= $moduleColor ?>"></i>Employees by Department</h6></div>
          <div class="card-body p-0">
            <?php if (empty($byDept)): ?>
            <div class="text-center text-muted py-5"><i class="fas fa-sitemap fa-2x mb-2 d-block opacity-25"></i>No department data.</div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-light">
                  <tr><th>Department</th><th class="text-center">Active</th><th class="text-center">Total</th><th>Avg Salary</th><th>Share</th></tr>
                </thead>
                <tbody>
                  <?php
                  $grandEmp = max(1, array_sum(array_column($byDept, 'total')));
                  foreach ($byDept as $d):
                    $pct = round($d['total'] / $grandEmp * 100);
                  ?>
                  <tr>
                    <td class="fw-semibold"><?= e($d['dept_name'] ?? '—') ?></td>
                    <td class="text-center"><?= (int)$d['active'] ?></td>
                    <td class="text-center"><?= (int)$d['total'] ?></td>
                    <td><?= $d['avg_salary'] ? formatCurrency((float)$d['avg_salary']) : '—' ?></td>
                    <td style="min-width:120px">
                      <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-fill" style="height:6px">
                          <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $moduleColor ?>"></div>
                        </div>
                        <span class="small text-muted"><?= $pct ?>%</span>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header"><h6 class="mb-0"><i class="fas fa-briefcase me-2" style="color:<?= $moduleColor ?>"></i>Employment Types</h6></div>
          <div class="card-body">
            <?php if (empty($byType)): ?>
            <div class="text-center text-muted py-4">No data.</div>
            <?php else:
              $typeLabels  = [];
              $typeCounts  = [];
              $typeColors  = ['#2c3e50','#1A8A4E','#f39c12','#e74c3c','#8e44ad'];
              foreach ($byType as $i => $t):
                $label = match($t['employment_type']) {
                    'full_time' => 'Full Time', 'part_time' => 'Part Time',
                    'contract'  => 'Contract',  'intern'    => 'Intern', default => ucfirst($t['employment_type'])
                };
                $typeLabels[] = $label;
                $typeCounts[] = (int)$t['cnt'];
            endforeach; ?>
            <canvas id="typeChart" height="200"></canvas>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div><!-- /workforce -->

  <!-- ══ PAYROLL TAB ═══════════════════════════════════════════════════════ -->
  <div class="tab-pane fade" id="payroll">

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <div class="card">
          <div class="card-body text-center">
            <div class="text-muted small mb-1"><?= date('F Y', strtotime($monthStart)) ?> Payroll</div>
            <div class="fw-800 fs-3 text-success"><?= formatCurrency($payrollMonth) ?></div>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card">
          <div class="card-body text-center">
            <div class="text-muted small mb-1"><?= $filterYear ?> YTD Payroll</div>
            <div class="fw-800 fs-3" style="color:<?= $moduleColor ?>"><?= formatCurrency($payrollYear) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Trend chart -->
    <div class="card mb-4">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-line me-2" style="color:<?= $moduleColor ?>"></i>Payroll Trend — Last 12 Months</h6></div>
      <div class="card-body"><canvas id="payChart" height="100"></canvas></div>
    </div>

    <!-- By department -->
    <div class="card mb-4">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-sitemap me-2" style="color:<?= $moduleColor ?>"></i>Payroll by Department — <?= date('F Y', strtotime($monthStart)) ?></h6></div>
      <div class="card-body p-0">
        <?php if (empty($payByDept)): ?>
        <div class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>No payroll processed for this month.</div>
        <?php else:
          $maxPay = max(array_column($payByDept, 'total_pay')) ?: 1;
        ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Department</th><th class="text-center">Employees Paid</th><th class="text-end">Total Net Pay</th><th>Distribution</th></tr>
            </thead>
            <tbody>
              <?php foreach ($payByDept as $r):
                $pct = round($r['total_pay'] / $maxPay * 100);
              ?>
              <tr>
                <td class="fw-semibold"><?= e($r['dept_name']) ?></td>
                <td class="text-center"><?= (int)$r['emp_count'] ?></td>
                <td class="text-end fw-semibold text-success"><?= formatCurrency((float)$r['total_pay']) ?></td>
                <td style="min-width:140px">
                  <div class="progress" style="height:8px">
                    <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div><!-- /payroll -->

  <!-- ══ LEAVE TAB ════════════════════════════════════════════════════════ -->
  <div class="tab-pane fade" id="leave">

    <!-- Leave status cards -->
    <div class="row g-3 mb-4">
      <?php
      $leaveStats = [
          ['Pending',  $leavePending,  'warning'],
          ['Approved', $leaveApproved, 'success'],
          ['Rejected', $leaveRejected, 'danger'],
          ['Days Taken ('.$filterYear.')', $leaveTotalDays, 'primary'],
      ];
      foreach ($leaveStats as [$lbl, $val, $color]): ?>
      <div class="col-6 col-lg-3">
        <div class="card text-center">
          <div class="card-body py-3">
            <div class="fw-800 fs-3 text-<?= $color ?>"><?= $val ?></div>
            <div class="small text-muted"><?= $lbl ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Monthly leave chart -->
    <div class="card mb-4">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Approved Leave Days by Month — <?= $filterYear ?></h6></div>
      <div class="card-body"><canvas id="leaveMonthChart" height="100"></canvas></div>
    </div>

    <!-- Top leave takers -->
    <div class="card mb-4">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>Top Leave Takers — <?= $filterYear ?></h6></div>
      <div class="card-body p-0">
        <?php if (empty($leaveTopEmps)): ?>
        <div class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>No approved leave in <?= $filterYear ?>.</div>
        <?php else:
          $maxDays = max(array_column($leaveTopEmps, 'total_days')) ?: 1;
        ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="leaveTable">
            <thead class="table-light">
              <tr><th>#</th><th>Employee</th><th>Department</th><th class="text-center">Applications</th><th class="text-center">Days Taken</th><th>Usage</th></tr>
            </thead>
            <tbody>
              <?php foreach ($leaveTopEmps as $i => $r): ?>
              <tr>
                <td class="text-muted"><?= $i + 1 ?></td>
                <td class="fw-semibold"><?= e($r['emp_name']) ?></td>
                <td><?= e($r['dept_name'] ?? '—') ?></td>
                <td class="text-center"><?= (int)$r['applications'] ?></td>
                <td class="text-center fw-semibold text-warning"><?= (int)$r['total_days'] ?></td>
                <td style="min-width:120px">
                  <div class="progress" style="height:6px">
                    <div class="progress-bar bg-warning" style="width:<?= round($r['total_days'] / $maxDays * 100) ?>%"></div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div><!-- /leave -->

  <!-- ══ ATTENDANCE TAB ════════════════════════════════════════════════════ -->
  <div class="tab-pane fade" id="attendance">

    <!-- Attendance KPIs -->
    <div class="row g-3 mb-4">
      <?php
      $attStats = [
          ['Present',  $attPresent, 'success'],
          ['Late',     $attLate,    'warning'],
          ['Absent',   $attAbsent,  'danger'],
          ['Half Day', $attHalf,    'secondary'],
      ];
      foreach ($attStats as [$lbl, $val, $color]): ?>
      <div class="col-6 col-lg-3">
        <div class="card text-center">
          <div class="card-body py-3">
            <div class="fw-800 fs-3 text-<?= $color ?>"><?= $val ?></div>
            <div class="small text-muted"><?= $lbl ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Attendance rate summary -->
    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="fw-semibold">Overall Attendance Rate — <?= date('F Y', strtotime($monthStart)) ?></span>
          <span class="fw-800 fs-5 <?= $attRate >= 85 ? 'text-success' : ($attRate >= 70 ? 'text-warning' : 'text-danger') ?>"><?= $attRate ?>%</span>
        </div>
        <div class="progress" style="height:12px;border-radius:6px">
          <div class="progress-bar <?= $attRate >= 85 ? 'bg-success' : ($attRate >= 70 ? 'bg-warning' : 'bg-danger') ?>"
               style="width:<?= $attRate ?>%;border-radius:6px"></div>
        </div>
        <div class="d-flex justify-content-between small text-muted mt-1">
          <span>0%</span><span class="text-warning">70% threshold</span><span>100%</span>
        </div>
      </div>
    </div>

    <!-- Daily trend chart -->
    <div class="card mb-4">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Daily Attendance — <?= date('F Y', strtotime($monthStart)) ?></h6></div>
      <div class="card-body"><canvas id="attDayChart" height="100"></canvas></div>
    </div>

    <!-- By department -->
    <?php if (!empty($attByDept)): ?>
    <div class="card mb-4">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-sitemap me-2" style="color:<?= $moduleColor ?>"></i>Attendance by Department</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="attTable">
            <thead class="table-light">
              <tr><th>Department</th><th class="text-center">Present</th><th class="text-center">Late</th><th class="text-center">Absent</th><th>Rate</th></tr>
            </thead>
            <tbody>
              <?php foreach ($attByDept as $r):
                $dTotal = max(1, (int)$r['total']);
                $dRate  = round(((int)$r['present'] + (int)$r['late']) / $dTotal * 100, 1);
              ?>
              <tr>
                <td class="fw-semibold"><?= e($r['dept_name']) ?></td>
                <td class="text-center text-success fw-semibold"><?= (int)$r['present'] ?></td>
                <td class="text-center text-warning"><?= (int)$r['late'] ?></td>
                <td class="text-center text-danger"><?= (int)$r['absent'] ?></td>
                <td style="min-width:140px">
                  <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-fill" style="height:8px">
                      <div class="progress-bar <?= $dRate >= 85 ? 'bg-success' : ($dRate >= 70 ? 'bg-warning' : 'bg-danger') ?>"
                           style="width:<?= $dRate ?>%"></div>
                    </div>
                    <span class="small fw-semibold" style="min-width:38px"><?= $dRate ?>%</span>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div><!-- /attendance -->

</div><!-- /tab-content -->

<!-- ── Print styles ──────────────────────────────────────────────────────── -->
<style>
@media print {
  .sidebar, .top-header, nav, .page-header .btn,
  ul.nav-tabs, .card-header button, form { display: none !important; }
  .tab-pane { display: block !important; opacity: 1 !important; }
  .card { break-inside: avoid; }
  canvas { max-height: 200px; }
}
</style>

<?php
$deptLabelsJs = json_encode(array_column($byDept, 'dept_name'));
$deptTotals   = json_encode(array_map('intval', array_column($byDept, 'total')));
$typeLabelsJs = json_encode($typeLabels ?? []);
$typeCountsJs = json_encode($typeCounts ?? []);

$extraJs = '<script>
(function(){
  var mc = "' . $moduleColor . '";

  // Gender doughnut
  new Chart(document.getElementById("genderChart"),{
    type:"doughnut",
    data:{labels:' . json_encode($genderLabels) . ',datasets:[{data:' . json_encode($genderCounts) . ',backgroundColor:["#0B2D4E","#e91e63"]}]},
    options:{responsive:true,plugins:{legend:{position:"bottom"}}}
  });

  // Hire trend
  new Chart(document.getElementById("hireChart"),{
    type:"bar",
    data:{labels:' . json_encode($hireLabels) . ',datasets:[{label:"Hires",data:' . json_encode($hireData) . ',backgroundColor:mc,borderRadius:4}]},
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
  });

  // Employment type
  var tc = document.getElementById("typeChart");
  if(tc){
    new Chart(tc,{
      type:"doughnut",
      data:{labels:' . $typeLabelsJs . ',datasets:[{data:' . $typeCountsJs . ',backgroundColor:["#2c3e50","#1A8A4E","#f39c12","#e74c3c","#8e44ad"]}]},
      options:{responsive:true,plugins:{legend:{position:"bottom"}}}
    });
  }

  // Payroll trend
  new Chart(document.getElementById("payChart"),{
    type:"line",
    data:{labels:' . json_encode($payLabels) . ',datasets:[{label:"Net Pay",data:' . json_encode($payData) . ',borderColor:mc,backgroundColor:mc+"22",tension:.4,fill:true}]},
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}
  });

  // Leave monthly
  new Chart(document.getElementById("leaveMonthChart"),{
    type:"bar",
    data:{labels:' . json_encode($leaveMonthLabels) . ',datasets:[{label:"Days",data:' . json_encode($leaveMonthData) . ',backgroundColor:"#f39c12",borderRadius:4}]},
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
  });

  // Attendance daily
  new Chart(document.getElementById("attDayChart"),{
    type:"bar",
    data:{
      labels:' . json_encode($attDayLabels) . ',
      datasets:[
        {label:"Present/Late",data:' . json_encode($attDayPresent) . ',backgroundColor:"#1A8A4E",borderRadius:3},
        {label:"Absent",      data:' . json_encode($attDayAbsent)  . ',backgroundColor:"#e74c3c",borderRadius:3}
      ]
    },
    options:{responsive:true,plugins:{legend:{position:"top"}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
  });

  // DataTables
  if($.fn.DataTable){
    $("#leaveTable").DataTable({pageLength:10,order:[[4,"desc"]]});
    $("#attTable").DataTable({pageLength:10,order:[[1,"desc"]]});
  }
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
