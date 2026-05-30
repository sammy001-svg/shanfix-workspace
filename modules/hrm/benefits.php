<?php
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

$createTable = "CREATE TABLE IF NOT EXISTS hrm_benefits (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    employee_id     INT NOT NULL,
    benefit_type    VARCHAR(100) NOT NULL,
    description     TEXT,
    amount          DECIMAL(15,2) DEFAULT 0.00,
    frequency       ENUM('monthly','quarterly','annual','one_time') DEFAULT 'monthly',
    start_date      DATE,
    end_date        DATE DEFAULT NULL,
    status          ENUM('active','expired','cancelled') DEFAULT 'active',
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    try { $pdo->exec($createTable); } catch (Exception $e) {}
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int)($_POST['id']          ?? 0);
        $empId      = (int)($_POST['employee_id'] ?? 0);
        $type       = sanitize($_POST['benefit_type'] ?? '');
        $desc       = sanitize($_POST['description']  ?? '');
        $amount     = (float)($_POST['amount']    ?? 0);
        $freq       = in_array($_POST['frequency'] ?? '', ['monthly','quarterly','annual','one_time']) ? $_POST['frequency'] : 'monthly';
        $startDate  = $_POST['start_date'] ?? date('Y-m-d');
        $endDate    = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $status     = in_array($_POST['status'] ?? '', ['active','expired','cancelled']) ? $_POST['status'] : 'active';

        if (!$empId || !$type) { setFlash('danger', 'Employee and benefit type are required.'); redirect('benefits.php'); }

        if ($id > 0) {
            $pdo->prepare("UPDATE hrm_benefits SET employee_id=?,benefit_type=?,description=?,amount=?,frequency=?,start_date=?,end_date=?,status=? WHERE id=? AND org_id=?")
                ->execute([$empId, $type, $desc, $amount, $freq, $startDate, $endDate, $status, $id, $orgId]);
            setFlash('success', 'Benefit updated.');
            logActivity('update', 'hrm', "Updated benefit #$id");
        } else {
            $pdo->prepare("INSERT INTO hrm_benefits (org_id,employee_id,benefit_type,description,amount,frequency,start_date,end_date,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $empId, $type, $desc, $amount, $freq, $startDate, $endDate, $status, $user['id']]);
            setFlash('success', 'Benefit assigned successfully.');
            logActivity('create', 'hrm', "Assigned benefit: $type to employee #$empId");
        }
        redirect('benefits.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM hrm_benefits WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Benefit removed.');
        redirect('benefits.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];
try { $pdo->exec($createTable); } catch (Exception $e) {}

$filterEmp    = (int)($_GET['emp']    ?? 0);
$filterStatus = $_GET['status']       ?? 'active';
$filterType   = $_GET['type']         ?? '';

$where  = 'b.org_id=?';
$params = [$orgId];
if ($filterEmp)    { $where .= ' AND b.employee_id=?';  $params[] = $filterEmp; }
if ($filterStatus) { $where .= ' AND b.status=?';       $params[] = $filterStatus; }
if ($filterType)   { $where .= ' AND b.benefit_type=?'; $params[] = $filterType; }

$benefits = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_no, e.position,
               d.name AS dept_name
        FROM hrm_benefits b
        JOIN hrm_employees e ON b.employee_id=e.id
        LEFT JOIN hrm_departments d ON e.department_id=d.id
        WHERE $where ORDER BY b.start_date DESC, b.id DESC
    ");
    $stmt->execute($params);
    $benefits = $stmt->fetchAll();
} catch (Exception $e) {}

// Summary KPIs
$totalActive   = 0; $totalMonthly = 0; $uniqueEmployees = [];
foreach ($benefits as $b) {
    if ($b['status'] === 'active') {
        $totalActive++;
        $uniqueEmployees[$b['employee_id']] = true;
        if ($b['frequency'] === 'monthly') $totalMonthly += (float)$b['amount'];
        if ($b['frequency'] === 'quarterly') $totalMonthly += (float)$b['amount'] / 3;
        if ($b['frequency'] === 'annual') $totalMonthly += (float)$b['amount'] / 12;
    }
}

// Employees for filter/form
$employees = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, employee_no FROM hrm_employees WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]); $employees = $stmt->fetchAll();
} catch (Exception $e) {}

// Common benefit types
$commonBenefits = ['Medical Insurance','Pension Contribution','Transport Allowance','House Allowance','Lunch Allowance','Airtime Allowance','Education Allowance','Hardship Allowance','Leave Allowance','End of Year Bonus','13th Month','Performance Bonus'];

// Distinct types for filter
$types = [];
try {
    $s = $pdo->prepare("SELECT DISTINCT benefit_type FROM hrm_benefits WHERE org_id=? ORDER BY benefit_type");
    $s->execute([$orgId]); $types = $s->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$freqLabels = ['monthly'=>'Monthly','quarterly'=>'Quarterly','annual'=>'Annual','one_time'=>'One-time'];
$statusColors = ['active'=>'success','expired'=>'secondary','cancelled'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-gift me-2" style="color:<?= $moduleColor ?>"></i>Employee Benefits</h4>
    <p class="text-muted mb-0">Manage allowances, insurance, pension & other employee benefits</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#benefitModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Benefit
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-gift"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalActive ?></div><div class="stat-label">Active Benefits</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($uniqueEmployees) ?></div><div class="stat-label">Employees with Benefits</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalMonthly) ?></div><div class="stat-label">Est. Monthly Cost</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-calendar-year"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalMonthly * 12) ?></div><div class="stat-label">Est. Annual Cost</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Employee</label>
        <select name="emp" class="form-select form-select-sm">
          <option value="">All Employees</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $filterEmp == $e['id'] ? 'selected' : '' ?>><?= e($e['employee_no'].' — '.$e['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach (['active','expired','cancelled'] as $s): ?>
            <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="benefits.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-gift me-2" style="color:<?= $moduleColor ?>"></i>Benefits Register</h6>
    <span class="badge bg-secondary"><?= count($benefits) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr><th>Employee</th><th>Department</th><th>Benefit Type</th><th>Frequency</th><th class="text-end">Amount</th><th>Start Date</th><th>End Date</th><th>Status</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($benefits)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-gift fa-2x mb-2 d-block opacity-25"></i>No benefits found.</td></tr>
          <?php else: foreach ($benefits as $b): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($b['emp_name']) ?></div>
              <div class="small text-muted"><?= e($b['employee_no']) ?> · <?= e($b['position'] ?? '') ?></div>
            </td>
            <td class="small text-muted"><?= e($b['dept_name'] ?? '—') ?></td>
            <td><span class="badge bg-info text-dark"><?= e($b['benefit_type']) ?></span></td>
            <td class="small"><?= $freqLabels[$b['frequency']] ?? ucfirst($b['frequency']) ?></td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$b['amount']) ?></td>
            <td class="small"><?= formatDate($b['start_date']) ?></td>
            <td class="small"><?= $b['end_date'] ? formatDate($b['end_date']) : '<span class="text-muted">—</span>' ?></td>
            <td><span class="badge bg-<?= $statusColors[$b['status']] ?? 'secondary' ?>"><?= ucfirst($b['status']) ?></span></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($b), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delBenefit(<?= $b['id'] ?>)" title="Remove"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="benefitModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="bId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="bTitle"><i class="fas fa-gift me-2"></i>Add Benefit</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
              <select name="employee_id" id="bEmp" class="form-select" required>
                <option value="">-- Select employee --</option>
                <?php foreach ($employees as $e): ?>
                  <option value="<?= $e['id'] ?>"><?= e($e['employee_no'].' — '.$e['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Benefit Type <span class="text-danger">*</span></label>
              <input type="text" name="benefit_type" id="bType" class="form-control" list="benefitList" required>
              <datalist id="benefitList">
                <?php foreach ($commonBenefits as $bt): ?><option value="<?= e($bt) ?>"><?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Amount (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="amount" id="bAmount" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Frequency</label>
              <select name="frequency" id="bFreq" class="form-select">
                <?php foreach ($freqLabels as $k => $v): ?>
                  <option value="<?= $k ?>"><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="bStatus" class="form-select">
                <option value="active">Active</option>
                <option value="expired">Expired</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Start Date</label>
              <input type="date" name="start_date" id="bStart" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">End Date <span class="text-muted fw-normal small">(leave blank if ongoing)</span></label>
              <input type="date" name="end_date" id="bEnd" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description / Notes</label>
              <textarea name="description" id="bDesc" class="form-control" rows="2" placeholder="Additional details about this benefit"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete form -->
<form method="POST" id="delForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('bTitle').innerHTML = '<i class="fas fa-gift me-2"></i>Add Benefit';
  document.getElementById('bId').value      = 0;
  document.getElementById('bEmp').value     = '';
  document.getElementById('bType').value    = '';
  document.getElementById('bAmount').value  = '0';
  document.getElementById('bFreq').value    = 'monthly';
  document.getElementById('bStatus').value  = 'active';
  document.getElementById('bStart').value   = new Date().toISOString().split('T')[0];
  document.getElementById('bEnd').value     = '';
  document.getElementById('bDesc').value    = '';
}
function openEdit(b) {
  document.getElementById('bTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Benefit';
  document.getElementById('bId').value      = b.id;
  document.getElementById('bEmp').value     = b.employee_id || '';
  document.getElementById('bType').value    = b.benefit_type || '';
  document.getElementById('bAmount').value  = b.amount || '0';
  document.getElementById('bFreq').value    = b.frequency || 'monthly';
  document.getElementById('bStatus').value  = b.status || 'active';
  document.getElementById('bStart').value   = b.start_date || '';
  document.getElementById('bEnd').value     = b.end_date || '';
  document.getElementById('bDesc').value    = b.description || '';
  new bootstrap.Modal(document.getElementById('benefitModal')).show();
}
function delBenefit(id) {
  Swal.fire({ title:'Remove this benefit?', icon:'warning', showCancelButton:true,
    confirmButtonColor:'#e74c3c', confirmButtonText:'Remove'
  }).then(r => { if (r.isConfirmed) { document.getElementById('delId').value=id; document.getElementById('delForm').submit(); } });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
