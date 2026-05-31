<?php
// ── HRM: Leave Management ──────────────────────────────────────
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

    if ($action === 'apply') {
        $empId       = (int)($_POST['employee_id']   ?? 0);
        $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
        $startDate   = $_POST['start_date']           ?? date('Y-m-d');
        $endDate     = $_POST['end_date']             ?? date('Y-m-d');
        $reason      = sanitize($_POST['reason']      ?? '');

        $start = new DateTime($startDate);
        $end   = new DateTime($endDate);
        $days  = max(1, $end->diff($start)->days + 1);

        $stmt = $pdo->prepare("INSERT INTO hrm_leave_requests (org_id, employee_id, leave_type_id, start_date, end_date, days, reason, status) VALUES (?,?,?,?,?,?,?,'pending')");
        $stmt->execute([$orgId, $empId, $leaveTypeId ?: null, $startDate, $endDate, $days, $reason]);
        setFlash('success', "Leave application submitted ($days day(s)).");
        logActivity('create', 'hrm', "Leave application by employee #$empId");
        redirect('leave.php');
    }

    if ($action === 'approve' || $action === 'reject') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt   = $pdo->prepare("UPDATE hrm_leave_requests SET status=?, approved_by=?, approved_at=NOW() WHERE id=? AND org_id=?");
        $stmt->execute([$status, $user['id'], $id, $orgId]);
        setFlash('success', 'Leave request ' . $status . '.');
        logActivity('update', 'hrm', "Leave #$id $status by admin");
        // SMS notification to employee
        try {
            $empRow = $pdo->prepare("SELECT e.phone, CONCAT(e.first_name,' ',e.last_name) AS name FROM hrm_leave_requests lr JOIN hrm_employees e ON lr.employee_id=e.id WHERE lr.id=?");
            $empRow->execute([$id]);
            $emp = $empRow->fetch();
            if ($emp && !empty($emp['phone'])) {
                $word = strtoupper($status);
                notifySms($emp['phone'], APP_NAME . ": Hi {$emp['name']}, your leave request has been $word. Login to view details.", $orgId, "leave_$status");
            }
        } catch (Throwable $e) {}
        redirect('leave.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM hrm_leave_requests WHERE id=? AND org_id=? AND status='pending'")->execute([$id, $orgId]);
        setFlash('success', 'Leave request deleted.');
        redirect('leave.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filters
$filterStatus = $_GET['status']   ?? '';
$filterEmp    = (int)($_GET['emp'] ?? 0);
$filterType   = (int)($_GET['type'] ?? 0);

$where  = 'lr.org_id = ?';
$params = [$orgId];
if ($filterStatus) { $where .= ' AND lr.status = ?';         $params[] = $filterStatus; }
if ($filterEmp)    { $where .= ' AND lr.employee_id = ?';    $params[] = $filterEmp; }
if ($filterType)   { $where .= ' AND lr.leave_type_id = ?';  $params[] = $filterType; }

$leaves = [];
try {
    $stmt = $pdo->prepare("
        SELECT lr.*,
               e.first_name, e.last_name, e.employee_no,
               lt.name AS leave_type_name
        FROM hrm_leave_requests lr
        JOIN hrm_employees e ON lr.employee_id = e.id
        LEFT JOIN hrm_leave_types lt ON lr.leave_type_id = lt.id
        WHERE $where
        ORDER BY lr.created_at DESC
    ");
    $stmt->execute($params);
    $leaves = $stmt->fetchAll();
} catch (Exception $e) {}

// Leave types
$leaveTypes = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM hrm_leave_types WHERE org_id=? ORDER BY name");
    $stmt->execute([$orgId]);
    $leaveTypes = $stmt->fetchAll();
} catch (Exception $e) {
    // Default types if none configured
    $leaveTypes = [
        ['id' => 0, 'name' => 'Annual Leave',     'days_allowed' => 21, 'is_paid' => 1],
        ['id' => 0, 'name' => 'Sick Leave',        'days_allowed' => 14, 'is_paid' => 1],
        ['id' => 0, 'name' => 'Maternity Leave',   'days_allowed' => 90, 'is_paid' => 1],
        ['id' => 0, 'name' => 'Paternity Leave',   'days_allowed' => 14, 'is_paid' => 1],
        ['id' => 0, 'name' => 'Unpaid Leave',      'days_allowed' => 30, 'is_paid' => 0],
        ['id' => 0, 'name' => 'Compassionate Leave','days_allowed' => 3, 'is_paid' => 1],
    ];
}

// Employees
$employees = [];
try {
    $stmt = $pdo->prepare("SELECT id, employee_no, first_name, last_name FROM hrm_employees WHERE org_id=? AND status IN ('active','on_leave') ORDER BY first_name");
    $stmt->execute([$orgId]);
    $employees = $stmt->fetchAll();
} catch (Exception $e) {}

// Leave balance summary by type
$balanceSummary = [];
try {
    foreach ($leaveTypes as $lt) {
        if (!$lt['id']) continue;
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(days),0) FROM hrm_leave_requests WHERE org_id=? AND leave_type_id=? AND status='approved' AND YEAR(start_date)=?");
        $stmt->execute([$orgId, $lt['id'], date('Y')]);
        $used = (int)$stmt->fetchColumn();
        $balanceSummary[] = [
            'type'    => $lt['name'],
            'allowed' => $lt['days_allowed'],
            'used'    => $used,
            'balance' => max(0, $lt['days_allowed'] - $used),
        ];
    }
} catch (Exception $e) {}

// Quick counts
$pendingCount  = count(array_filter($leaves, fn($l) => $l['status'] === 'pending'));
$approvedCount = count(array_filter($leaves, fn($l) => $l['status'] === 'approved'));
$rejectedCount = count(array_filter($leaves, fn($l) => $l['status'] === 'rejected'));
$totalDays     = array_sum(array_column(array_filter($leaves, fn($l) => $l['status'] === 'approved'), 'days'));
$isAdmin       = in_array($user['role'], ['super_admin', 'client_admin']);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-minus me-2" style="color:<?= $moduleColor ?>"></i>Leave Management</h4>
    <p class="text-muted mb-0">Manage employee leave requests</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#leaveModal" onclick="openLeaveModal()">
    <i class="fas fa-plus me-2"></i>Apply Leave
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingCount ?></div><div class="stat-label">Pending Requests</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $approvedCount ?></div><div class="stat-label">Approved</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-times-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $rejectedCount ?></div><div class="stat-label">Rejected</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-calendar-day"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalDays ?></div><div class="stat-label">Total Days (Approved)</div></div></div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Leave Balance Summary -->
  <?php if (!empty($balanceSummary)): ?>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Leave Balance (This Year)</h6></div>
      <div class="card-body p-0">
        <?php foreach ($balanceSummary as $bs): $pct = $bs['allowed'] > 0 ? min(100, round($bs['used']/$bs['allowed']*100)) : 0; ?>
        <div class="px-3 py-2 border-bottom">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold"><?= e($bs['type']) ?></span>
            <span class="small text-muted"><?= $bs['used'] ?>/<?= $bs['allowed'] ?> days used</span>
          </div>
          <div class="progress" style="height:6px">
            <div class="progress-bar <?= $pct >= 80 ? 'bg-danger' : 'bg-success' ?>" style="width:<?= $pct ?>%"></div>
          </div>
          <div class="small text-end mt-1 <?= $bs['balance'] > 0 ? 'text-success' : 'text-danger' ?>">
            <?= $bs['balance'] ?> days remaining
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Filters -->
  <div class="col-lg-<?= !empty($balanceSummary) ? '8' : '12' ?>">
    <div class="card mb-3">
      <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
          <div class="col-sm-3">
            <label class="form-label small fw-semibold mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
              <option value="">All</option>
              <option value="pending"  <?= $filterStatus==='pending'  ?'selected':'' ?>>Pending</option>
              <option value="approved" <?= $filterStatus==='approved' ?'selected':'' ?>>Approved</option>
              <option value="rejected" <?= $filterStatus==='rejected' ?'selected':'' ?>>Rejected</option>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label small fw-semibold mb-1">Employee</label>
            <select name="emp" class="form-select form-select-sm">
              <option value="">All Employees</option>
              <?php foreach ($employees as $emp): ?>
              <option value="<?= $emp['id'] ?>" <?= $filterEmp==$emp['id']?'selected':'' ?>><?= e($emp['first_name'].' '.$emp['last_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label small fw-semibold mb-1">Leave Type</label>
            <select name="type" class="form-select form-select-sm">
              <option value="">All Types</option>
              <?php foreach ($leaveTypes as $lt): if (!$lt['id']) continue; ?>
              <option value="<?= $lt['id'] ?>" <?= $filterType==$lt['id']?'selected':'' ?>><?= e($lt['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
            <a href="leave.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Leave Requests Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-calendar-minus me-2" style="color:<?= $moduleColor ?>"></i>Leave Requests</h6>
    <span class="badge bg-secondary"><?= count($leaves) ?> requests</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Employee</th>
            <th>Leave Type</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th class="text-center">Days</th>
            <th>Reason</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($leaves)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No leave requests found.
          </td></tr>
          <?php else: foreach ($leaves as $lr): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($lr['first_name'].' '.$lr['last_name']) ?></div>
              <div class="small text-muted"><?= e($lr['employee_no']) ?></div>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($lr['leave_type_name'] ?? 'General') ?></span></td>
            <td><?= formatDate($lr['start_date']) ?></td>
            <td><?= formatDate($lr['end_date']) ?></td>
            <td class="text-center fw-semibold"><?= $lr['days'] ?></td>
            <td class="small text-muted" style="max-width:200px"><?= e(mb_substr($lr['reason'] ?? '—', 0, 80)) ?></td>
            <td><?= statusBadge($lr['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <?php if ($isAdmin && $lr['status'] === 'pending'): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="id" value="<?= $lr['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-success" title="Approve">
                  <i class="fas fa-check"></i>
                </button>
              </form>
              <form method="POST" class="d-inline ms-1">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" value="<?= $lr['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Reject">
                  <i class="fas fa-times"></i>
                </button>
              </form>
              <?php elseif ($lr['status'] === 'pending'): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this request?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $lr['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
              <?php else: ?>
              <span class="text-muted small"><?= $lr['status'] === 'approved' ? 'Approved' : 'Closed' ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Apply Leave Modal -->
<div class="modal fade" id="leaveModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="apply">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Apply for Leave</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
              <select name="employee_id" id="leaveEmp" class="form-select" required>
                <option value="">-- Select Employee --</option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>"><?= e($emp['first_name'].' '.$emp['last_name'].' ('.$emp['employee_no'].')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Leave Type <span class="text-danger">*</span></label>
              <select name="leave_type_id" class="form-select" required>
                <option value="">-- Select Type --</option>
                <?php foreach ($leaveTypes as $lt): if (!$lt['id']) continue; ?>
                <option value="<?= $lt['id'] ?>"><?= e($lt['name']) ?> (<?= $lt['days_allowed'] ?> days)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
              <input type="date" name="start_date" id="leaveStart" class="form-control" required oninput="calcDays()">
            </div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label>
              <input type="date" name="end_date" id="leaveEnd" class="form-control" required oninput="calcDays()">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Days</label>
              <input type="text" id="leaveDays" class="form-control bg-light" readonly value="0">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
              <textarea name="reason" class="form-control" rows="3" required placeholder="Briefly explain the reason for leave"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-paper-plane me-1"></i>Submit Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function openLeaveModal() {
  document.getElementById('leaveEmp').value   = '';
  document.getElementById('leaveStart').value = '';
  document.getElementById('leaveEnd').value   = '';
  document.getElementById('leaveDays').value  = 0;
}

function calcDays() {
  var start = document.getElementById('leaveStart').value;
  var end   = document.getElementById('leaveEnd').value;
  if (start && end) {
    var s = new Date(start), e = new Date(end);
    var diff = Math.round((e - s) / (1000*60*60*24)) + 1;
    document.getElementById('leaveDays').value = diff > 0 ? diff : 0;
  }
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
