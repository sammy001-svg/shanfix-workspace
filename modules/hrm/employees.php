<?php
// ── HRM: Employee Management ───────────────────────────────────
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

    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $empNo      = sanitize($_POST['employee_no']     ?? '');
        $deptId     = (int)($_POST['department_id']      ?? 0);
        $firstName  = sanitize($_POST['first_name']      ?? '');
        $lastName   = sanitize($_POST['last_name']       ?? '');
        $email      = sanitize($_POST['email']           ?? '');
        $phone      = sanitize($_POST['phone']           ?? '');
        $idNum      = sanitize($_POST['id_number']       ?? '');
        $gender     = in_array($_POST['gender'] ?? '', ['male','female']) ? $_POST['gender'] : 'male';
        $dob        = $_POST['dob']                      ?? null;
        $position   = sanitize($_POST['position']        ?? '');
        $empType    = in_array($_POST['employment_type'] ?? '', ['full_time','part_time','contract','intern']) ? $_POST['employment_type'] : 'full_time';
        $salary     = (float)($_POST['salary']           ?? 0);
        $bankName   = sanitize($_POST['bank_name']       ?? '');
        $bankAcc    = sanitize($_POST['bank_account']    ?? '');
        $dateHired  = $_POST['date_hired']               ?? date('Y-m-d');
        $status     = in_array($_POST['status'] ?? '', ['active','inactive','on_leave','terminated']) ? $_POST['status'] : 'active';

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE hrm_employees SET department_id=?, first_name=?, last_name=?, email=?, phone=?, id_number=?, gender=?, dob=?, position=?, employment_type=?, salary=?, bank_name=?, bank_account=?, date_hired=?, status=? WHERE id=? AND org_id=?");
            $stmt->execute([$deptId ?: null, $firstName, $lastName, $email, $phone, $idNum, $gender, $dob ?: null, $position, $empType, $salary, $bankName, $bankAcc, $dateHired, $status, $id, $orgId]);
            setFlash('success', 'Employee updated successfully.');
            logActivity('update', 'hrm', "Updated employee: $firstName $lastName");
        } else {
            // Auto-generate employee number if blank
            if ($empNo === '') {
                $count = countRows('hrm_employees', 'org_id=?', [$orgId]) + 1;
                $empNo = 'EMP-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            }
            $stmt = $pdo->prepare("INSERT INTO hrm_employees (org_id, employee_no, department_id, first_name, last_name, email, phone, id_number, gender, dob, position, employment_type, salary, bank_name, bank_account, date_hired, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $empNo, $deptId ?: null, $firstName, $lastName, $email, $phone, $idNum, $gender, $dob ?: null, $position, $empType, $salary, $bankName, $bankAcc, $dateHired, $status]);
            setFlash('success', "Employee $empNo added successfully.");
            logActivity('create', 'hrm', "Added employee: $firstName $lastName ($empNo)");
        }
        redirect('employees.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE hrm_employees SET status='terminated' WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Employee terminated/removed.');
        logActivity('delete', 'hrm', "Terminated employee #$id");
        redirect('employees.php');
    }
}

// ── CSV Export (GET) ───────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];

    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT e.employee_no, e.first_name, e.last_name, e.email, e.phone,
                   e.id_number, e.gender, e.dob, d.name AS department,
                   e.position, e.employment_type, e.salary,
                   e.bank_name, e.bank_account, e.date_hired, e.status
            FROM hrm_employees e
            LEFT JOIN hrm_departments d ON e.department_id = d.id
            WHERE e.org_id = ?
            ORDER BY e.employee_no
        ");
        $stmt->execute([$orgId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $filename = 'employees-' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Emp No','First Name','Last Name','Email','Phone','ID Number','Gender','Date of Birth','Department','Position','Employment Type','Basic Salary','Bank Name','Bank Account','Date Hired','Status']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['employee_no'], $r['first_name'], $r['last_name'],
            $r['email'], $r['phone'], $r['id_number'],
            ucfirst($r['gender'] ?? ''),
            $r['dob'] ?? '',
            $r['department'] ?? '',
            $r['position'],
            ucwords(str_replace('_', ' ', $r['employment_type'] ?? '')),
            number_format((float)$r['salary'], 2),
            $r['bank_name'], $r['bank_account'],
            $r['date_hired'] ?? '',
            ucfirst($r['status'] ?? ''),
        ]);
    }
    fclose($out);
    logActivity('export', 'hrm', 'Exported employee list to CSV');
    exit;
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filters
$filterDept   = $_GET['dept']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type']   ?? '';

$where  = 'e.org_id = ?';
$params = [$orgId];
if ($filterDept)   { $where .= ' AND e.department_id = ?'; $params[] = $filterDept; }
if ($filterStatus) { $where .= ' AND e.status = ?';        $params[] = $filterStatus; }
if ($filterType)   { $where .= ' AND e.employment_type = ?'; $params[] = $filterType; }

$employees = [];
try {
    $stmt = $pdo->prepare("
        SELECT e.*, d.name AS dept_name
        FROM hrm_employees e
        LEFT JOIN hrm_departments d ON e.department_id = d.id
        WHERE $where
        ORDER BY e.employee_no, e.first_name
    ");
    $stmt->execute($params);
    $employees = $stmt->fetchAll();
} catch (Exception $e) {}

// Departments for filter/form
$departments = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM hrm_departments WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]);
    $departments = $stmt->fetchAll();
} catch (Exception $e) {}

// Quick stats
$activeCount   = countRows('hrm_employees', 'org_id=? AND status=?', [$orgId, 'active']);
$onLeaveCount  = countRows('hrm_employees', 'org_id=? AND status=?', [$orgId, 'on_leave']);
$inactiveCount = countRows('hrm_employees', 'org_id=? AND status=?', [$orgId, 'inactive']);
$totalSalary   = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(salary),0) FROM hrm_employees WHERE org_id=? AND status='active'");
    $stmt->execute([$orgId]);
    $totalSalary = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// View detail
$viewEmployee = null;
if (isset($_GET['view'])) {
    $vid  = (int)$_GET['view'];
    $stmt = $pdo->prepare("SELECT e.*, d.name AS dept_name FROM hrm_employees e LEFT JOIN hrm_departments d ON e.department_id=d.id WHERE e.id=? AND e.org_id=?");
    $stmt->execute([$vid, $orgId]);
    $viewEmployee = $stmt->fetch();
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-id-badge me-2" style="color:<?= $moduleColor ?>"></i>Employee Management</h4>
    <p class="text-muted mb-0">Manage your workforce</p>
  </div>
  <div class="d-flex gap-2">
    <a href="employees.php?export=csv" class="btn btn-outline-secondary"><i class="fas fa-download me-1"></i>Export CSV</a>
    <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#empModal" onclick="openAddModal()">
      <i class="fas fa-plus me-2"></i>Add Employee
    </button>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-user-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Active Employees</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-user-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $onLeaveCount ?></div><div class="stat-label">On Leave</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-money-check-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalSalary) ?></div><div class="stat-label">Monthly Salary Bill</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-user-slash"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $inactiveCount ?></div><div class="stat-label">Inactive / Terminated</div></div></div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Department</label>
        <select name="dept" class="form-select form-select-sm">
          <option value="">All Departments</option>
          <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $filterDept == $d['id'] ? 'selected':'' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="active"     <?= $filterStatus==='active'     ?'selected':'' ?>>Active</option>
          <option value="inactive"   <?= $filterStatus==='inactive'   ?'selected':'' ?>>Inactive</option>
          <option value="on_leave"   <?= $filterStatus==='on_leave'   ?'selected':'' ?>>On Leave</option>
          <option value="terminated" <?= $filterStatus==='terminated' ?'selected':'' ?>>Terminated</option>
        </select>
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Employment Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <option value="full_time" <?= $filterType==='full_time' ?'selected':'' ?>>Full Time</option>
          <option value="part_time" <?= $filterType==='part_time' ?'selected':'' ?>>Part Time</option>
          <option value="contract"  <?= $filterType==='contract'  ?'selected':'' ?>>Contract</option>
          <option value="intern"    <?= $filterType==='intern'    ?'selected':'' ?>>Intern</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="employees.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Employees Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-id-badge me-2" style="color:<?= $moduleColor ?>"></i>Employee List</h6>
    <span class="badge bg-secondary"><?= count($employees) ?> employees</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Emp #</th>
            <th>Name</th>
            <th>Department</th>
            <th>Position</th>
            <th>Type</th>
            <th class="text-end">Salary</th>
            <th>Date Hired</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($employees)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No employees found.
          </td></tr>
          <?php else: foreach ($employees as $emp): ?>
          <tr>
            <td class="fw-semibold text-muted"><?= e($emp['employee_no'] ?? '#'.$emp['id']) ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="avatar-sm" style="background:<?= $moduleColor ?>;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.75rem;flex-shrink:0">
                  <?= strtoupper(substr($emp['first_name'],0,1) . substr($emp['last_name'],0,1)) ?>
                </div>
                <div>
                  <div class="fw-semibold"><?= e($emp['first_name'].' '.$emp['last_name']) ?></div>
                  <div class="small text-muted"><?= e($emp['email'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td><?= e($emp['dept_name'] ?? '—') ?></td>
            <td class="small"><?= e($emp['position'] ?? '—') ?></td>
            <td><span class="badge bg-info text-dark"><?= ucwords(str_replace('_',' ',$emp['employment_type'])) ?></span></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$emp['salary']) ?></td>
            <td><?= formatDate($emp['date_hired']) ?></td>
            <td><?= statusBadge($emp['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <a href="?view=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-info" title="View Details">
                <i class="fas fa-eye"></i>
              </a>
              <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEditModal(<?= htmlspecialchars(json_encode($emp), ENT_QUOTES) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteEmp(<?= $emp['id'] ?>, '<?= e($emp['first_name']." ".$emp['last_name']) ?>')" title="Terminate">
                <i class="fas fa-user-times"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($viewEmployee): ?>
<!-- View Employee Detail (shown below table when ?view=ID) -->
<div class="card mt-4">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:<?= $moduleColor ?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-id-card me-2"></i>Employee Profile — <?= e($viewEmployee['first_name'].' '.$viewEmployee['last_name']) ?></h6>
    <a href="employees.php" class="btn btn-sm btn-light btn-close-custom">
      <i class="fas fa-times me-1"></i>Close
    </a>
  </div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-md-6">
        <table class="table table-sm">
          <tr><th class="text-muted w-50">Employee #</th><td class="fw-semibold"><?= e($viewEmployee['employee_no'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Full Name</th><td><?= e($viewEmployee['first_name'].' '.$viewEmployee['last_name']) ?></td></tr>
          <tr><th class="text-muted">Email</th><td><?= e($viewEmployee['email'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Phone</th><td><?= e($viewEmployee['phone'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">ID Number</th><td><?= e($viewEmployee['id_number'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Gender</th><td><?= ucfirst($viewEmployee['gender'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Date of Birth</th><td><?= formatDate($viewEmployee['dob']) ?></td></tr>
        </table>
      </div>
      <div class="col-md-6">
        <table class="table table-sm">
          <tr><th class="text-muted w-50">Department</th><td><?= e($viewEmployee['dept_name'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Position</th><td><?= e($viewEmployee['position'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Type</th><td><?= ucwords(str_replace('_',' ',$viewEmployee['employment_type'])) ?></td></tr>
          <tr><th class="text-muted">Salary</th><td class="fw-semibold text-success"><?= formatCurrency((float)$viewEmployee['salary']) ?></td></tr>
          <tr><th class="text-muted">Bank</th><td><?= e($viewEmployee['bank_name'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Bank Account</th><td><?= e($viewEmployee['bank_account'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Date Hired</th><td><?= formatDate($viewEmployee['date_hired']) ?></td></tr>
          <tr><th class="text-muted">Status</th><td><?= statusBadge($viewEmployee['status']) ?></td></tr>
        </table>
      </div>
    </div>
    <?php
    // Recent payroll
    $recentPayroll = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM hrm_payroll WHERE employee_id=? ORDER BY created_at DESC LIMIT 6");
        $stmt->execute([$viewEmployee['id']]);
        $recentPayroll = $stmt->fetchAll();
    } catch (Exception $e) {}
    if (!empty($recentPayroll)):
    ?>
    <h6 class="fw-semibold mt-3 mb-2">Recent Payroll</h6>
    <div class="table-responsive">
      <table class="table table-sm table-bordered">
        <thead class="table-light"><tr><th>Period</th><th>Basic</th><th>Gross</th><th>Deductions</th><th>Net</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($recentPayroll as $p): ?>
          <tr>
            <td><?= e($p['period']) ?></td>
            <td><?= formatCurrency((float)$p['basic_salary']) ?></td>
            <td><?= formatCurrency((float)$p['gross_salary']) ?></td>
            <td class="text-danger"><?= formatCurrency((float)$p['total_deductions']) ?></td>
            <td class="fw-semibold text-success"><?= formatCurrency((float)$p['net_salary']) ?></td>
            <td><?= statusBadge($p['status']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Add/Edit Employee Modal -->
<div class="modal fade" id="empModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="empId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="empModalTitle"><i class="fas fa-id-badge me-2"></i>Add Employee</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label fw-semibold">Employee # <small class="text-muted">(auto if blank)</small></label>
              <input type="text" name="employee_no" id="empNo" class="form-control" placeholder="e.g. EMP-0001" maxlength="50">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" id="empFirst" class="form-control" required maxlength="100">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
              <input type="text" name="last_name" id="empLast" class="form-control" required maxlength="100">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Gender</label>
              <select name="gender" id="empGender" class="form-select">
                <option value="male">Male</option>
                <option value="female">Female</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="empEmail" class="form-control" maxlength="255">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="empPhone" class="form-control" maxlength="25">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">ID Number</label>
              <input type="text" name="id_number" id="empIdNum" class="form-control" maxlength="50">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Date of Birth</label>
              <input type="date" name="dob" id="empDob" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Department</label>
              <select name="department_id" id="empDept" class="form-select">
                <option value="">-- Select Dept --</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Position / Job Title</label>
              <input type="text" name="position" id="empPosition" class="form-control" maxlength="100">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Employment Type</label>
              <select name="employment_type" id="empType" class="form-select">
                <option value="full_time">Full Time</option>
                <option value="part_time">Part Time</option>
                <option value="contract">Contract</option>
                <option value="intern">Intern</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Gross Salary (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="salary" id="empSalary" class="form-control" step="0.01" min="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Date Hired</label>
              <input type="date" name="date_hired" id="empHired" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="empStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="on_leave">On Leave</option>
                <option value="terminated">Terminated</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Bank Name</label>
              <input type="text" name="bank_name" id="empBank" class="form-control" maxlength="100">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Bank Account Number</label>
              <input type="text" name="bank_account" id="empBankAcc" class="form-control" maxlength="50">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Employee</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete/Terminate Form -->
<form method="POST" id="deleteEmpForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteEmpId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAddModal() {
  document.getElementById('empModalTitle').innerHTML = '<i class="fas fa-id-badge me-2"></i>Add Employee';
  document.getElementById('empId').value       = 0;
  document.getElementById('empNo').value       = '';
  document.getElementById('empFirst').value    = '';
  document.getElementById('empLast').value     = '';
  document.getElementById('empGender').value   = 'male';
  document.getElementById('empEmail').value    = '';
  document.getElementById('empPhone').value    = '';
  document.getElementById('empIdNum').value    = '';
  document.getElementById('empDob').value      = '';
  document.getElementById('empDept').value     = '';
  document.getElementById('empPosition').value = '';
  document.getElementById('empType').value     = 'full_time';
  document.getElementById('empSalary').value   = '';
  document.getElementById('empHired').value    = new Date().toISOString().split('T')[0];
  document.getElementById('empStatus').value   = 'active';
  document.getElementById('empBank').value     = '';
  document.getElementById('empBankAcc').value  = '';
}

function openEditModal(emp) {
  document.getElementById('empModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Employee';
  document.getElementById('empId').value       = emp.id;
  document.getElementById('empNo').value       = emp.employee_no    || '';
  document.getElementById('empFirst').value    = emp.first_name     || '';
  document.getElementById('empLast').value     = emp.last_name      || '';
  document.getElementById('empGender').value   = emp.gender         || 'male';
  document.getElementById('empEmail').value    = emp.email          || '';
  document.getElementById('empPhone').value    = emp.phone          || '';
  document.getElementById('empIdNum').value    = emp.id_number      || '';
  document.getElementById('empDob').value      = emp.dob            || '';
  document.getElementById('empDept').value     = emp.department_id  || '';
  document.getElementById('empPosition').value = emp.position       || '';
  document.getElementById('empType').value     = emp.employment_type|| 'full_time';
  document.getElementById('empSalary').value   = emp.salary         || '';
  document.getElementById('empHired').value    = emp.date_hired     || '';
  document.getElementById('empStatus').value   = emp.status         || 'active';
  document.getElementById('empBank').value     = emp.bank_name      || '';
  document.getElementById('empBankAcc').value  = emp.bank_account   || '';
  var modal = new bootstrap.Modal(document.getElementById('empModal'));
  modal.show();
}

function deleteEmp(id, name) {
  Swal.fire({
    title: 'Terminate Employee?',
    text: name + ' will be marked as terminated.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, terminate'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteEmpId').value = id;
      document.getElementById('deleteEmpForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
