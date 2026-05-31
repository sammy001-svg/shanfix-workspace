<?php
// ── HRM: Bulk Payroll Run ──────────────────────────────────────
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
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],];

// ── Helpers ────────────────────────────────────────────────────
function computePayeTax(float $gross): float
{
    $paye = 0;
    if ($gross > 32333) {
        $paye = (24000 * 0.10) + (8333 * 0.25) + (($gross - 32333) * 0.30);
    } elseif ($gross > 24000) {
        $paye = (24000 * 0.10) + (($gross - 24000) * 0.25);
    } elseif ($gross > 0) {
        $paye = $gross * 0.10;
    }
    return round(max(0, $paye - 2400), 2); // subtract personal relief
}

function computeNhif(float $gross): float
{
    if ($gross >= 50000) return 1700;
    if ($gross >= 45000) return 1100;
    if ($gross >= 40000) return 1000;
    if ($gross >= 35000) return 950;
    if ($gross >= 30000) return 900;
    if ($gross >= 25000) return 850;
    if ($gross >= 20000) return 750;
    if ($gross >= 15000) return 600;
    if ($gross >= 12000) return 500;
    if ($gross >= 8000)  return 400;
    if ($gross >= 6000)  return 300;
    if ($gross >= 1)     return 150;
    return 0;
}

function computeNssf(float $gross): float
{
    return min(round($gross * 0.06, 2), 2160);
}

// ── POST Handlers (must run before header include) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    // ── Process (save) payroll ─────────────────────────────────
    if ($action === 'run_payroll') {
        $period   = sanitize($_POST['period'] ?? date('Y-m'));
        $deptId   = (int)($_POST['department_id'] ?? 0);
        $rerun    = (int)($_POST['rerun'] ?? 0);

        try {
            // If re-run: delete existing drafts for this period (+dept filter)
            if ($rerun) {
                if ($deptId) {
                    $delSql = "DELETE p FROM hrm_payroll p JOIN hrm_employees e ON p.employee_id=e.id WHERE p.org_id=? AND p.period=? AND e.department_id=? AND p.status='draft'";
                    $pdo->prepare($delSql)->execute([$orgId, $period, $deptId]);
                } else {
                    $pdo->prepare("DELETE FROM hrm_payroll WHERE org_id=? AND period=? AND status='draft'")->execute([$orgId, $period]);
                }
            }

            // Fetch active employees (with optional dept filter)
            if ($deptId) {
                $stmt = $pdo->prepare("SELECT * FROM hrm_employees WHERE org_id=? AND status='active' AND department_id=?");
                $stmt->execute([$orgId, $deptId]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM hrm_employees WHERE org_id=? AND status='active'");
                $stmt->execute([$orgId]);
            }
            $employees = $stmt->fetchAll();

            $inserted  = 0;
            $skipped   = 0;
            foreach ($employees as $emp) {
                // Skip if record already exists for this period
                $chk = $pdo->prepare("SELECT COUNT(*) FROM hrm_payroll WHERE org_id=? AND employee_id=? AND period=?");
                $chk->execute([$orgId, $emp['id'], $period]);
                if ((int)$chk->fetchColumn() > 0) { $skipped++; continue; }

                $basic      = (float)($emp['salary'] ?? 0);
                $allowances = 0;
                $overtime   = 0;
                $gross      = $basic + $allowances + $overtime;

                $paye     = computePayeTax($gross);
                $nhif     = computeNhif($gross);
                $nssf     = computeNssf($gross);
                $totalDed = $paye + $nhif + $nssf;
                $net      = max(0, $gross - $totalDed);

                $ins = $pdo->prepare("INSERT INTO hrm_payroll
                    (org_id, employee_id, period, basic_salary, allowances, overtime,
                     gross_salary, paye, nhif, nssf, total_deductions, net_salary, status, payment_date)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'draft',NULL)");
                $ins->execute([$orgId, $emp['id'], $period, $basic, $allowances, $overtime,
                               $gross, $paye, $nhif, $nssf, $totalDed, $net]);
                $inserted++;
            }
            $msg = "Payroll run for <strong>$period</strong> complete. $inserted records created" . ($skipped ? ", $skipped skipped (already exist)." : ".");
            setFlash('success', $msg);
            logActivity('process', 'hrm', "Bulk payroll run for $period — $inserted created, $skipped skipped");
            // SMS payslip-ready notification to each processed employee
            if ($inserted > 0) {
                try {
                    $empPhones = $pdo->prepare("SELECT DISTINCT e.phone, CONCAT(e.first_name,' ',e.last_name) AS name, p.net_salary FROM hrm_payroll p JOIN hrm_employees e ON p.employee_id=e.id WHERE p.org_id=? AND p.period=? AND e.phone IS NOT NULL AND e.phone != ''");
                    $empPhones->execute([$orgId, $period]);
                    foreach ($empPhones->fetchAll() as $ep) {
                        $netFmt = number_format((float)$ep['net_salary'], 2);
                        notifySms($ep['phone'], APP_NAME . ": Hi {$ep['name']}, your payslip for $period is ready. Net pay: KES $netFmt. Login to view.", $orgId, 'payroll_processed');
                    }
                } catch (Throwable $e) {}
            }
        } catch (Exception $ex) {
            setFlash('danger', 'Payroll run failed: ' . $ex->getMessage());
        }
        redirect('payroll-run.php?period=' . urlencode($period) . ($deptId ? '&department_id=' . $deptId : ''));
    }

    // ── Approve all drafts for a period ───────────────────────
    if ($action === 'approve_all') {
        $period = sanitize($_POST['period'] ?? date('Y-m'));
        $deptId = (int)($_POST['department_id'] ?? 0);
        try {
            if ($deptId) {
                $sql = "UPDATE hrm_payroll p JOIN hrm_employees e ON p.employee_id=e.id
                        SET p.status='approved'
                        WHERE p.org_id=? AND p.period=? AND e.department_id=? AND p.status='draft'";
                $pdo->prepare($sql)->execute([$orgId, $period, $deptId]);
            } else {
                $pdo->prepare("UPDATE hrm_payroll SET status='approved' WHERE org_id=? AND period=? AND status='draft'")
                    ->execute([$orgId, $period]);
            }
            setFlash('success', "All draft payslips for $period have been approved.");
            logActivity('approve', 'hrm', "Approved all payroll drafts for $period");
        } catch (Exception $ex) {
            setFlash('danger', 'Approval failed: ' . $ex->getMessage());
        }
        redirect('payroll-run.php?period=' . urlencode($period) . ($deptId ? '&department_id=' . $deptId : ''));
    }

    // ── Download payslips PDF ──────────────────────────────────
    if ($action === 'download_pdf') {
        $period = sanitize($_POST['period'] ?? date('Y-m'));
        $deptId = (int)($_POST['department_id'] ?? 0);
        try {
            if ($deptId) {
                $sql = "SELECT p.*, e.first_name, e.last_name, e.employee_no, d.name AS dept_name
                        FROM hrm_payroll p
                        JOIN hrm_employees e ON p.employee_id=e.id
                        LEFT JOIN hrm_departments d ON e.department_id=d.id
                        WHERE p.org_id=? AND p.period=? AND e.department_id=?
                        ORDER BY e.first_name, e.last_name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$orgId, $period, $deptId]);
            } else {
                $sql = "SELECT p.*, e.first_name, e.last_name, e.employee_no, d.name AS dept_name
                        FROM hrm_payroll p
                        JOIN hrm_employees e ON p.employee_id=e.id
                        LEFT JOIN hrm_departments d ON e.department_id=d.id
                        WHERE p.org_id=? AND p.period=?
                        ORDER BY e.first_name, e.last_name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$orgId, $period]);
            }
            $rows = $stmt->fetchAll();
        } catch (Exception $ex) {
            $rows = [];
        }

        require_once __DIR__ . '/../../includes/pdf.php';
        $cols    = ['Employee', 'Emp No', 'Department', 'Gross', 'PAYE', 'NHIF', 'NSSF', 'Total Ded.', 'Net Pay'];
        $pdfRows = [];
        foreach ($rows as $r) {
            $pdfRows[] = [
                $r['first_name'] . ' ' . $r['last_name'],
                $r['employee_no'] ?? '',
                $r['dept_name']   ?? '',
                number_format((float)$r['gross_salary'],    2),
                number_format((float)$r['paye'],            2),
                number_format((float)$r['nhif'],            2),
                number_format((float)$r['nssf'],            2),
                number_format((float)$r['total_deductions'],2),
                number_format((float)$r['net_salary'],      2),
            ];
        }
        $totalGross = array_sum(array_column($rows, 'gross_salary'));
        $totalDed   = array_sum(array_column($rows, 'total_deductions'));
        $totalNet   = array_sum(array_column($rows, 'net_salary'));
        $summary    = [
            'Period'       => $period,
            'Employees'    => count($rows),
            'Total Gross'  => 'KES ' . number_format($totalGross, 2),
            'Total Deductions' => 'KES ' . number_format($totalDed, 2),
            'Total Net Pay'=> 'KES ' . number_format($totalNet, 2),
        ];
        generateModuleReportPDF(
            'Payroll Run — ' . $period,
            'Bulk Payslip Summary',
            $summary,
            $cols,
            $pdfRows,
            'payroll-run-' . $period . '.pdf',
            '#2c3e50'
        );
        exit;
    }
}

// ── Page load ──────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterPeriod = sanitize($_GET['period'] ?? date('Y-m'));
$filterDept   = (int)($_GET['department_id'] ?? 0);

// Departments dropdown
$departments = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM hrm_departments WHERE org_id=? ORDER BY name");
    $stmt->execute([$orgId]);
    $departments = $stmt->fetchAll();
} catch (Exception $e) {}

// Load current period preview
$preview      = [];
$periodExists = false;
try {
    if ($filterDept) {
        $sql = "SELECT p.*, e.first_name, e.last_name, e.employee_no, d.name AS dept_name
                FROM hrm_payroll p
                JOIN hrm_employees e ON p.employee_id=e.id
                LEFT JOIN hrm_departments d ON e.department_id=d.id
                WHERE p.org_id=? AND p.period=? AND e.department_id=?
                ORDER BY e.first_name, e.last_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$orgId, $filterPeriod, $filterDept]);
    } else {
        $sql = "SELECT p.*, e.first_name, e.last_name, e.employee_no, d.name AS dept_name
                FROM hrm_payroll p
                JOIN hrm_employees e ON p.employee_id=e.id
                LEFT JOIN hrm_departments d ON e.department_id=d.id
                WHERE p.org_id=? AND p.period=?
                ORDER BY e.first_name, e.last_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$orgId, $filterPeriod]);
    }
    $preview      = $stmt->fetchAll();
    $periodExists = !empty($preview);
} catch (Exception $e) {}

// Preview simulation for Step 2 (active employees not yet inserted)
$simPreview = [];
if (!$periodExists) {
    try {
        if ($filterDept) {
            $stmt = $pdo->prepare("SELECT e.*, d.name AS dept_name FROM hrm_employees e LEFT JOIN hrm_departments d ON e.department_id=d.id WHERE e.org_id=? AND e.status='active' AND e.department_id=?");
            $stmt->execute([$orgId, $filterDept]);
        } else {
            $stmt = $pdo->prepare("SELECT e.*, d.name AS dept_name FROM hrm_employees e LEFT JOIN hrm_departments d ON e.department_id=d.id WHERE e.org_id=? AND e.status='active'");
            $stmt->execute([$orgId]);
        }
        foreach ($stmt->fetchAll() as $emp) {
            $basic  = (float)($emp['salary'] ?? 0);
            $gross  = $basic;
            $paye   = computePayeTax($gross);
            $nhif   = computeNhif($gross);
            $nssf   = computeNssf($gross);
            $totDed = $paye + $nhif + $nssf;
            $net    = max(0, $gross - $totDed);
            $simPreview[] = [
                'first_name'       => $emp['first_name'],
                'last_name'        => $emp['last_name'],
                'employee_no'      => $emp['employee_no'] ?? '',
                'dept_name'        => $emp['dept_name']   ?? '',
                'basic_salary'     => $basic,
                'gross_salary'     => $gross,
                'paye'             => $paye,
                'nhif'             => $nhif,
                'nssf'             => $nssf,
                'total_deductions' => $totDed,
                'net_salary'       => $net,
                'status'           => 'preview',
            ];
        }
    } catch (Exception $e) {}
}

$displayRows  = $periodExists ? $preview : $simPreview;
$totalGross   = array_sum(array_column($displayRows, 'gross_salary'));
$totalDedAll  = array_sum(array_column($displayRows, 'total_deductions'));
$totalNetAll  = array_sum(array_column($displayRows, 'net_salary'));
$draftCount   = count(array_filter($preview, fn($r) => $r['status'] === 'draft'));
$approvedCount= count(array_filter($preview, fn($r) => $r['status'] === 'approved'));
$paidCount    = count(array_filter($preview, fn($r) => $r['status'] === 'paid'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-cogs me-2" style="color:<?= $moduleColor ?>"></i>Payroll Run</h4>
    <p class="text-muted mb-0">Bulk payroll processing for all active employees</p>
  </div>
  <a href="payroll.php" class="btn btn-outline-secondary">
    <i class="fas fa-arrow-left me-2"></i>Back to Payroll
  </a>
</div>

<!-- ── STEP 1: Period & Department ─────────────────────────── -->
<div class="card mb-4">
  <div class="card-header" style="background:<?= $moduleColor ?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Step 1 — Select Period &amp; Department</h6>
  </div>
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-sm-4">
        <label class="form-label fw-semibold small">Payroll Period <span class="text-danger">*</span></label>
        <input type="month" name="period" class="form-control" value="<?= e($filterPeriod) ?>" required>
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold small">Department Filter</label>
        <select name="department_id" class="form-select">
          <option value="">All Departments</option>
          <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $filterDept === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e($d['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4">
        <button type="submit" class="btn w-100" style="background:<?= $moduleColor ?>;color:#fff">
          <i class="fas fa-search me-2"></i>Preview Payroll
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($periodExists): ?>
<!-- ── EXISTING PERIOD ALERT ──────────────────────────────── -->
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4">
  <i class="fas fa-exclamation-triangle fa-2x"></i>
  <div>
    <strong>Payroll for <?= e($filterPeriod) ?> already processed</strong> —
    <?= count($preview) ?> records found
    (<?= $draftCount ?> draft, <?= $approvedCount ?> approved, <?= $paidCount ?> paid).
    You can approve all drafts, download the PDF, or re-run (clears and re-inserts draft records).
  </div>
</div>
<?php endif; ?>

<!-- ── KPI Cards ──────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= count($displayRows) ?></div>
        <div class="stat-label"><?= $periodExists ? 'Processed Employees' : 'Employees in Preview' ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalGross) ?></div>
        <div class="stat-label">Total Gross</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-minus-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalDedAll) ?></div>
        <div class="stat-label">Total Deductions</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hand-holding-usd"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalNetAll) ?></div>
        <div class="stat-label">Total Net Pay</div>
      </div>
    </div>
  </div>
</div>

<!-- ── STEP 2: Preview Table ──────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0">
      <i class="fas fa-table me-2" style="color:<?= $moduleColor ?>"></i>
      Step 2 — <?= $periodExists ? 'Processed Payroll' : 'Preview' ?> — <?= e($filterPeriod) ?>
      <?php if ($filterDept): ?>
        <span class="badge bg-secondary ms-1"><?= e($departments[array_search($filterDept, array_column($departments, 'id'))]['name'] ?? 'Dept') ?></span>
      <?php endif; ?>
    </h6>
    <span class="badge bg-secondary"><?= count($displayRows) ?> employees</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($displayRows)): ?>
    <div class="text-center text-muted py-5">
      <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
      <p>No active employees found<?= $filterDept ? ' in this department' : '' ?>.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Employee</th>
            <th>Department</th>
            <th class="text-end">Basic</th>
            <th class="text-end">Gross</th>
            <th class="text-end">PAYE</th>
            <th class="text-end">NHIF</th>
            <th class="text-end">NSSF</th>
            <th class="text-end text-danger">Total Ded.</th>
            <th class="text-end text-success fw-bold">Net Pay</th>
            <?php if ($periodExists): ?><th>Status</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($displayRows as $i => $r): ?>
          <tr>
            <td class="text-muted small"><?= $i + 1 ?></td>
            <td>
              <div class="fw-semibold"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></div>
              <div class="small text-muted"><?= e($r['employee_no'] ?? '') ?></div>
            </td>
            <td class="small text-muted"><?= e($r['dept_name'] ?? '—') ?></td>
            <td class="text-end"><?= formatCurrency((float)$r['basic_salary']) ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$r['gross_salary']) ?></td>
            <td class="text-end text-danger small"><?= formatCurrency((float)$r['paye']) ?></td>
            <td class="text-end text-danger small"><?= formatCurrency((float)$r['nhif']) ?></td>
            <td class="text-end text-danger small"><?= formatCurrency((float)$r['nssf']) ?></td>
            <td class="text-end text-danger fw-semibold"><?= formatCurrency((float)$r['total_deductions']) ?></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$r['net_salary']) ?></td>
            <?php if ($periodExists): ?>
            <td><?= statusBadge($r['status']) ?></td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-dark fw-bold">
          <tr>
            <td colspan="4" class="text-end">TOTALS:</td>
            <td class="text-end"><?= formatCurrency($totalGross) ?></td>
            <td class="text-end"><?= formatCurrency(array_sum(array_column($displayRows, 'paye'))) ?></td>
            <td class="text-end"><?= formatCurrency(array_sum(array_column($displayRows, 'nhif'))) ?></td>
            <td class="text-end"><?= formatCurrency(array_sum(array_column($displayRows, 'nssf'))) ?></td>
            <td class="text-end"><?= formatCurrency($totalDedAll) ?></td>
            <td class="text-end"><?= formatCurrency($totalNetAll) ?></td>
            <?php if ($periodExists): ?><td></td><?php endif; ?>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── STEP 3: Action Buttons ─────────────────────────────── -->
<?php if (!empty($displayRows)): ?>
<div class="card">
  <div class="card-header" style="background:<?= $moduleColor ?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-play-circle me-2"></i>Step 3 — Actions</h6>
  </div>
  <div class="card-body">
    <div class="row g-3">

      <!-- Process & Save -->
      <?php if (!$periodExists): ?>
      <div class="col-sm-6 col-lg-4">
        <form method="POST" id="runForm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="run_payroll">
          <input type="hidden" name="period" value="<?= e($filterPeriod) ?>">
          <input type="hidden" name="department_id" value="<?= $filterDept ?>">
          <input type="hidden" name="rerun" value="0">
          <button type="button" class="btn w-100 fw-semibold"
                  style="background:<?= $moduleColor ?>;color:#fff"
                  onclick="confirmRun()">
            <i class="fas fa-cogs me-2"></i>Process &amp; Save Payroll
          </button>
          <div class="small text-muted mt-1 text-center">Inserts <?= count($displayRows) ?> records as <span class="badge bg-info">draft</span></div>
        </form>
      </div>
      <?php else: ?>

      <!-- Re-run (clear drafts and re-insert) -->
      <div class="col-sm-6 col-lg-4">
        <form method="POST" id="rerunForm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="run_payroll">
          <input type="hidden" name="period" value="<?= e($filterPeriod) ?>">
          <input type="hidden" name="department_id" value="<?= $filterDept ?>">
          <input type="hidden" name="rerun" value="1">
          <button type="button" class="btn btn-warning w-100 fw-semibold"
                  onclick="confirmRerun()">
            <i class="fas fa-redo me-2"></i>Re-run Payroll (Clears Drafts)
          </button>
          <div class="small text-muted mt-1 text-center">Deletes <?= $draftCount ?> draft(s) and re-computes</div>
        </form>
      </div>

      <!-- Approve All -->
      <?php if ($draftCount > 0): ?>
      <div class="col-sm-6 col-lg-4">
        <form method="POST" id="approveAllForm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="approve_all">
          <input type="hidden" name="period" value="<?= e($filterPeriod) ?>">
          <input type="hidden" name="department_id" value="<?= $filterDept ?>">
          <button type="button" class="btn btn-success w-100 fw-semibold"
                  onclick="confirmApprove()">
            <i class="fas fa-check-double me-2"></i>Approve All Drafts
          </button>
          <div class="small text-muted mt-1 text-center"><?= $draftCount ?> draft(s) will be approved</div>
        </form>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <!-- Download PDF -->
      <div class="col-sm-6 col-lg-4">
        <form method="POST" target="_blank">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="download_pdf">
          <input type="hidden" name="period" value="<?= e($filterPeriod) ?>">
          <input type="hidden" name="department_id" value="<?= $filterDept ?>">
          <button type="submit" class="btn btn-outline-danger w-100 fw-semibold">
            <i class="fas fa-file-pdf me-2"></i>Download Payslips PDF
          </button>
          <div class="small text-muted mt-1 text-center">
            <?= $periodExists ? 'PDF of processed payroll' : 'PDF preview (before saving)' ?>
          </div>
        </form>
      </div>

    </div><!-- /row -->

    <!-- Tax info accordion -->
    <div class="accordion mt-4" id="taxInfoAccordion">
      <div class="accordion-item border">
        <h2 class="accordion-header">
          <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#taxInfoBody">
            <i class="fas fa-info-circle me-2 text-primary"></i><small>Kenya Tax Calculation Reference (PAYE, NHIF, NSSF)</small>
          </button>
        </h2>
        <div id="taxInfoBody" class="accordion-collapse collapse">
          <div class="accordion-body">
            <div class="row g-3 small">
              <div class="col-md-4">
                <strong>PAYE Brackets (after KES 2,400 personal relief):</strong>
                <ul class="mb-0 mt-1 ps-3">
                  <li>0 – 24,000 @ 10%</li>
                  <li>24,001 – 32,333 @ 25%</li>
                  <li>32,334+ @ 30%</li>
                </ul>
              </div>
              <div class="col-md-4">
                <strong>NHIF (salary bands):</strong>
                <ul class="mb-0 mt-1 ps-3">
                  <li>&lt; 6,000 → KES 150</li>
                  <li>6,000–7,999 → KES 300</li>
                  <li>8,000–11,999 → KES 400</li>
                  <li>12,000–14,999 → KES 500</li>
                  <li>15,000–19,999 → KES 600</li>
                  <li>20,000–24,999 → KES 750</li>
                  <li>25,000–29,999 → KES 850</li>
                  <li>30,000–39,999 → KES 900</li>
                  <li>40,000–44,999 → KES 1,000</li>
                  <li>45,000–49,999 → KES 1,100</li>
                  <li>&ge; 50,000 → KES 1,700</li>
                </ul>
              </div>
              <div class="col-md-4">
                <strong>NSSF:</strong>
                <ul class="mb-0 mt-1 ps-3">
                  <li>6% of gross salary</li>
                  <li>Maximum cap: KES 2,160</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>
<?php endif; ?>

<?php
$extraJs = <<<'JS'
<script>
function confirmRun() {
  Swal.fire({
    title: 'Process & Save Payroll?',
    html: 'This will insert payroll records for all active employees as <strong>draft</strong>. Continue?',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#2c3e50',
    confirmButtonText: 'Yes, process'
  }).then(function(r){ if (r.isConfirmed) document.getElementById('runForm').submit(); });
}
function confirmRerun() {
  Swal.fire({
    title: 'Re-run Payroll?',
    html: 'This will <strong>delete all draft records</strong> for this period and re-insert them. Approved/Paid records are kept. Continue?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e6ac00',
    confirmButtonText: 'Yes, re-run'
  }).then(function(r){ if (r.isConfirmed) document.getElementById('rerunForm').submit(); });
}
function confirmApprove() {
  Swal.fire({
    title: 'Approve All Drafts?',
    text: 'All draft payslips for this period will be marked as approved.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#1A8A4E',
    confirmButtonText: 'Yes, approve all'
  }).then(function(r){ if (r.isConfirmed) document.getElementById('approveAllForm').submit(); });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
