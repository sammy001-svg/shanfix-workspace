<?php
// ── HRM: Payroll Processing ────────────────────────────────────
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

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'process_payroll') {
        $period = sanitize($_POST['period'] ?? date('Y-m'));
        // Fetch all active employees
        $stmt = $pdo->prepare("SELECT * FROM hrm_employees WHERE org_id=? AND status='active'");
        $stmt->execute([$orgId]);
        $activeEmps = $stmt->fetchAll();
        $inserted = 0;
        foreach ($activeEmps as $emp) {
            // Skip if already processed for this period
            $exists = countRows('hrm_payroll', 'org_id=? AND employee_id=? AND period=?', [$orgId, $emp['id'], $period]);
            if ($exists > 0) continue;

            $basic      = (float)$emp['salary'];
            $allowances = 0;
            $overtime   = 0;
            $gross      = $basic + $allowances + $overtime;

            // Basic Kenya PAYE brackets (simplified)
            $paye = 0;
            if ($gross > 800000) {
                $paye = (24000 * 0.10) + (8333 * 0.25) + (467667 * 0.30) + (300000 * 0.325) + (($gross - 800000) * 0.35);
            } elseif ($gross > 500000) {
                $paye = (24000 * 0.10) + (8333 * 0.25) + (467667 * 0.30) + (($gross - 500000) * 0.325);
            } elseif ($gross > 32333) {
                $paye = (24000 * 0.10) + (8333 * 0.25) + (($gross - 32333) * 0.30);
            } elseif ($gross > 24000) {
                $paye = (24000 * 0.10) + (($gross - 24000) * 0.25);
            } elseif ($gross > 0) {
                $paye = $gross * 0.10;
            }
            $paye = round(max(0, $paye - 2400), 2); // Subtract personal relief

            $nhif = 0;
            if ($gross >= 100000)     $nhif = 1700;
            elseif ($gross >= 80000)  $nhif = 1500;
            elseif ($gross >= 60000)  $nhif = 1300;
            elseif ($gross >= 40000)  $nhif = 1100;
            elseif ($gross >= 30000)  $nhif = 900;
            elseif ($gross >= 20000)  $nhif = 700;
            elseif ($gross >= 12000)  $nhif = 500;
            elseif ($gross >= 6000)   $nhif = 300;
            elseif ($gross > 0)       $nhif = 150;

            $nssf = min(round($gross * 0.06, 2), 2160); // 6% capped at 2160
            $totalDed = $paye + $nhif + $nssf;
            $net      = max(0, $gross - $totalDed);

            $stmt2 = $pdo->prepare("INSERT INTO hrm_payroll (org_id, employee_id, period, basic_salary, allowances, overtime, gross_salary, paye, nhif, nssf, total_deductions, net_salary, status, payment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt2->execute([$orgId, $emp['id'], $period, $basic, $allowances, $overtime, $gross, $paye, $nhif, $nssf, $totalDed, $net, 'draft', null]);
            $inserted++;
        }
        setFlash('success', "Payroll processed for $period. $inserted records created.");
        logActivity('process', 'hrm', "Processed payroll for $period — $inserted employees");
        redirect('payroll.php?period=' . $period);
    }

    if ($action === 'update_payroll') {
        $id         = (int)($_POST['id'] ?? 0);
        $allowances = (float)($_POST['allowances'] ?? 0);
        $overtime   = (float)($_POST['overtime']   ?? 0);
        $otherDed   = (float)($_POST['other_deductions'] ?? 0);
        // Recalculate
        $stmt = $pdo->prepare("SELECT * FROM hrm_payroll WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $pr = $stmt->fetch();
        if ($pr) {
            $gross    = (float)$pr['basic_salary'] + $allowances + $overtime;
            $paye     = (float)$pr['paye'];
            $nhif     = (float)$pr['nhif'];
            $nssf     = (float)$pr['nssf'];
            $totalDed = $paye + $nhif + $nssf + $otherDed;
            $net      = max(0, $gross - $totalDed);
            $upd = $pdo->prepare("UPDATE hrm_payroll SET allowances=?, overtime=?, gross_salary=?, other_deductions=?, total_deductions=?, net_salary=? WHERE id=? AND org_id=?");
            $upd->execute([$allowances, $overtime, $gross, $otherDed, $totalDed, $net, $id, $orgId]);
            setFlash('success', 'Payroll record updated.');
        }
        redirect('payroll.php?period=' . sanitize($_POST['period'] ?? date('Y-m')));
    }

    if ($action === 'mark_paid') {
        $id     = (int)($_POST['id'] ?? 0);
        $period = sanitize($_POST['period'] ?? date('Y-m'));
        $pdo->prepare("UPDATE hrm_payroll SET status='paid', payment_date=? WHERE id=? AND org_id=?")->execute([date('Y-m-d'), $id, $orgId]);
        setFlash('success', 'Payslip marked as paid.');
        logActivity('update', 'hrm', "Marked payroll #$id as paid");
        redirect('payroll.php?period=' . $period);
    }

    if ($action === 'delete_payroll') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM hrm_payroll WHERE id=? AND org_id=? AND status='draft'")->execute([$id, $orgId]);
        setFlash('success', 'Payroll draft deleted.');
        redirect('payroll.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Period filter
$filterPeriod = $_GET['period'] ?? date('Y-m');

$payroll = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, e.employee_no, e.first_name, e.last_name, d.name AS dept_name
        FROM hrm_payroll p
        JOIN hrm_employees e ON p.employee_id = e.id
        LEFT JOIN hrm_departments d ON e.department_id = d.id
        WHERE p.org_id = ? AND p.period = ?
        ORDER BY e.first_name, e.last_name
    ");
    $stmt->execute([$orgId, $filterPeriod]);
    $payroll = $stmt->fetchAll();
} catch (Exception $e) {}

// Period summaries
$totalGross    = array_sum(array_column($payroll, 'gross_salary'));
$totalDeductions = array_sum(array_column($payroll, 'total_deductions'));
$totalNet      = array_sum(array_column($payroll, 'net_salary'));
$paidCount     = count(array_filter($payroll, fn($p) => $p['status'] === 'paid'));
$draftCount    = count($payroll) - $paidCount;

// Available periods from DB
$periods = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT period FROM hrm_payroll WHERE org_id=? ORDER BY period DESC");
    $stmt->execute([$orgId]);
    $periods = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-check me-2" style="color:<?= $moduleColor ?>"></i>Payroll</h4>
    <p class="text-muted mb-0">Process and manage employee payroll</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#processModal">
    <i class="fas fa-cogs me-2"></i>Process Payroll
  </button>
</div>

<!-- Period Selector & Summary -->
<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-alt me-2"></i>Select Period</h6></div>
      <div class="card-body">
        <form method="GET">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Payroll Period (YYYY-MM)</label>
            <input type="month" name="period" class="form-control" value="<?= e($filterPeriod) ?>">
          </div>
          <button type="submit" class="btn btn-sm btn-success w-100"><i class="fas fa-filter me-1"></i>Load Period</button>
        </form>
        <?php if (!empty($periods)): ?>
        <hr>
        <div class="small fw-semibold text-muted mb-2">Recent Periods:</div>
        <?php foreach (array_slice($periods, 0, 5) as $p): ?>
        <a href="?period=<?= e($p) ?>" class="badge me-1 mb-1 <?= $p === $filterPeriod ? 'bg-success' : 'bg-secondary' ?>"><?= e($p) ?></a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="row g-3 h-100">
      <div class="col-sm-6">
        <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-money-bill-wave"></i></div>
          <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalGross) ?></div><div class="stat-label">Total Gross (<?= e($filterPeriod) ?>)</div></div></div>
      </div>
      <div class="col-sm-6">
        <div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-minus-circle"></i></div>
          <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalDeductions) ?></div><div class="stat-label">Total Deductions</div></div></div>
      </div>
      <div class="col-sm-6">
        <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-hand-holding-usd"></i></div>
          <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalNet) ?></div><div class="stat-label">Total Net Pay</div></div></div>
      </div>
      <div class="col-sm-6">
        <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-users"></i></div>
          <div class="stat-body"><div class="stat-value"><?= count($payroll) ?></div>
            <div class="stat-label"><?= $paidCount ?> paid / <?= $draftCount ?> pending</div></div></div>
      </div>
    </div>
  </div>
</div>

<!-- Payroll Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-money-check me-2" style="color:<?= $moduleColor ?>"></i>Payslips — <?= e($filterPeriod) ?></h6>
    <span class="badge bg-secondary"><?= count($payroll) ?> records</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($payroll)): ?>
    <div class="text-center text-muted py-5">
      <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
      No payroll processed for <?= e($filterPeriod) ?>.<br>
      <button class="btn btn-sm btn-success mt-2" data-bs-toggle="modal" data-bs-target="#processModal">
        <i class="fas fa-cogs me-1"></i>Process Now
      </button>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Employee</th>
            <th>Department</th>
            <th class="text-end">Basic</th>
            <th class="text-end">Allowances</th>
            <th class="text-end">Gross</th>
            <th class="text-end">Deductions</th>
            <th class="text-end">Net Pay</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payroll as $pr): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($pr['first_name'].' '.$pr['last_name']) ?></div>
              <div class="small text-muted"><?= e($pr['employee_no']) ?></div>
            </td>
            <td class="small text-muted"><?= e($pr['dept_name'] ?? '—') ?></td>
            <td class="text-end"><?= formatCurrency((float)$pr['basic_salary']) ?></td>
            <td class="text-end text-success"><?= formatCurrency((float)$pr['allowances'] + (float)$pr['overtime']) ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$pr['gross_salary']) ?></td>
            <td class="text-end text-danger"><?= formatCurrency((float)$pr['total_deductions']) ?></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$pr['net_salary']) ?></td>
            <td><?= statusBadge($pr['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEditPayroll(<?= htmlspecialchars(json_encode($pr), ENT_QUOTES) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <?php if ($pr['status'] !== 'paid'): ?>
              <button class="btn btn-sm btn-outline-success ms-1" onclick="markPaid(<?= $pr['id'] ?>, '<?= e($filterPeriod) ?>')" title="Mark Paid">
                <i class="fas fa-check"></i>
              </button>
              <?php endif; ?>
              <a href="<?= APP_URL ?>/modules/hrm/payslip-pdf.php?id=<?= $pr['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary ms-1" title="Download Payslip">
                <i class="fas fa-file-pdf"></i>
              </a>
              <?php if ($pr['status'] === 'draft'): ?>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="deletePayroll(<?= $pr['id'] ?>)" title="Delete Draft">
                <i class="fas fa-trash"></i>
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
          <tr>
            <td colspan="4" class="text-end">Totals:</td>
            <td class="text-end"><?= formatCurrency($totalGross) ?></td>
            <td class="text-end text-danger"><?= formatCurrency($totalDeductions) ?></td>
            <td class="text-end text-success"><?= formatCurrency($totalNet) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Process Payroll Modal -->
<div class="modal fade" id="processModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="process_payroll">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title"><i class="fas fa-cogs me-2"></i>Process Payroll</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>
            This will generate payroll for <strong>all active employees</strong> for the selected period. Existing records for the period will be skipped.
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Payroll Period</label>
            <input type="month" name="period" class="form-control" value="<?= e($filterPeriod) ?>" required>
          </div>
          <div class="bg-light p-3 rounded small">
            <strong>Deduction calculation includes:</strong>
            <ul class="mb-0 mt-1">
              <li>PAYE (Kenya progressive tax brackets)</li>
              <li>NHIF (based on salary band)</li>
              <li>NSSF (6% of gross, capped at KES 2,160)</li>
            </ul>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff" onclick="return confirm('Process payroll? This cannot be undone easily.')">
            <i class="fas fa-cogs me-1"></i>Process Payroll
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Payroll Modal -->
<div class="modal fade" id="editPayrollModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_payroll">
        <input type="hidden" name="id" id="prId">
        <input type="hidden" name="period" value="<?= e($filterPeriod) ?>">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Payroll Record</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Allowances (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="allowances" id="prAllowances" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Overtime (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="overtime" id="prOvertime" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Other Deductions (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="other_deductions" id="prOtherDed" class="form-control" step="0.01" min="0" value="0">
            </div>
          </div>
          <p class="text-muted small mt-2"><i class="fas fa-info-circle me-1"></i>PAYE, NHIF, NSSF are system-calculated. Changes here will update gross and net pay accordingly.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Action Forms -->
<form method="POST" id="paidPayForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="mark_paid">
  <input type="hidden" name="id" id="paidPayId">
  <input type="hidden" name="period" value="<?= e($filterPeriod) ?>">
</form>
<form method="POST" id="deletePayForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete_payroll">
  <input type="hidden" name="id" id="deletePayId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openEditPayroll(pr) {
  document.getElementById('prId').value          = pr.id;
  document.getElementById('prAllowances').value  = pr.allowances || 0;
  document.getElementById('prOvertime').value    = pr.overtime   || 0;
  document.getElementById('prOtherDed').value    = pr.other_deductions || 0;
  var modal = new bootstrap.Modal(document.getElementById('editPayrollModal'));
  modal.show();
}

function markPaid(id, period) {
  Swal.fire({
    title: 'Mark as Paid?',
    text: 'This payslip will be marked as paid for ' + period,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#1A8A4E',
    confirmButtonText: 'Yes, mark paid'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('paidPayId').value = id;
      document.getElementById('paidPayForm').submit();
    }
  });
}

function deletePayroll(id) {
  Swal.fire({
    title: 'Delete Draft?',
    text: 'This payroll draft will be permanently deleted.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deletePayId').value = id;
      document.getElementById('deletePayForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
