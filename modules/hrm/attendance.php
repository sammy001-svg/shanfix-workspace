<?php
// ── HRM: Attendance Tracking ───────────────────────────────────
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
        $id       = (int)($_POST['id'] ?? 0);
        $empId    = (int)($_POST['employee_id'] ?? 0);
        $date     = $_POST['date']      ?? date('Y-m-d');
        $checkIn  = $_POST['check_in']  ?? null;
        $checkOut = $_POST['check_out'] ?? null;
        $status   = in_array($_POST['status'] ?? '', ['present','absent','late','half_day','leave']) ? $_POST['status'] : 'present';
        $notes    = sanitize($_POST['notes'] ?? '');

        // Calculate hours worked
        $hoursWorked = null;
        if ($checkIn && $checkOut) {
            $in  = strtotime($date . ' ' . $checkIn);
            $out = strtotime($date . ' ' . $checkOut);
            if ($out > $in) {
                $hoursWorked = round(($out - $in) / 3600, 2);
            }
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE hrm_attendance SET employee_id=?, date=?, check_in=?, check_out=?, hours_worked=?, status=?, notes=? WHERE id=? AND org_id=?");
            $stmt->execute([$empId, $date, $checkIn ?: null, $checkOut ?: null, $hoursWorked, $status, $notes, $id, $orgId]);
            setFlash('success', 'Attendance record updated.');
        } else {
            // Check for duplicate
            $exists = countRows('hrm_attendance', 'org_id=? AND employee_id=? AND date=?', [$orgId, $empId, $date]);
            if ($exists > 0) {
                // Update existing
                $stmt = $pdo->prepare("UPDATE hrm_attendance SET check_in=?, check_out=?, hours_worked=?, status=?, notes=? WHERE org_id=? AND employee_id=? AND date=?");
                $stmt->execute([$checkIn ?: null, $checkOut ?: null, $hoursWorked, $status, $notes, $orgId, $empId, $date]);
                setFlash('success', 'Attendance record updated (existing).');
            } else {
                $stmt = $pdo->prepare("INSERT INTO hrm_attendance (org_id, employee_id, date, check_in, check_out, hours_worked, status, notes) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$orgId, $empId, $date, $checkIn ?: null, $checkOut ?: null, $hoursWorked, $status, $notes]);
                setFlash('success', 'Attendance marked.');
            }
        }
        logActivity('save', 'hrm', "Attendance for employee #$empId on $date: $status");
        redirect('attendance.php?month=' . substr($date, 0, 7));
    }

    if ($action === 'bulk_mark') {
        $bulkDate   = $_POST['bulk_date']   ?? date('Y-m-d');
        $bulkStatus = in_array($_POST['bulk_status'] ?? '', ['present','absent','late','leave']) ? $_POST['bulk_status'] : 'present';
        $empIds     = $_POST['bulk_emp_ids'] ?? [];

        $stmt = $pdo->prepare("INSERT INTO hrm_attendance (org_id, employee_id, date, status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)");
        $count = 0;
        foreach ($empIds as $eid) {
            $eid = (int)$eid;
            if ($eid > 0) {
                $stmt->execute([$orgId, $eid, $bulkDate, $bulkStatus]);
                $count++;
            }
        }
        setFlash('success', "Bulk attendance marked ($count employees) as $bulkStatus for $bulkDate.");
        logActivity('bulk', 'hrm', "Bulk attendance: $count employees as $bulkStatus on $bulkDate");
        redirect('attendance.php?month=' . substr($bulkDate, 0, 7));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM hrm_attendance WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Attendance record deleted.');
        redirect('attendance.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Period filter
$filterMonth = $_GET['month'] ?? date('Y-m');
$filterDate  = $_GET['date']  ?? '';

$monthStart = $filterMonth . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

$where  = 'a.org_id = ? AND a.date BETWEEN ? AND ?';
$params = [$orgId, $monthStart, $monthEnd];
if ($filterDate) { $where = 'a.org_id = ? AND a.date = ?'; $params = [$orgId, $filterDate]; }

$attendance = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, e.first_name, e.last_name, e.employee_no, d.name AS dept_name
        FROM hrm_attendance a
        JOIN hrm_employees e ON a.employee_id = e.id
        LEFT JOIN hrm_departments d ON e.department_id = d.id
        WHERE $where
        ORDER BY a.date DESC, e.first_name
    ");
    $stmt->execute($params);
    $attendance = $stmt->fetchAll();
} catch (Exception $e) {}

// Summary stats for the period
$presentCount  = count(array_filter($attendance, fn($a) => $a['status'] === 'present'));
$absentCount   = count(array_filter($attendance, fn($a) => $a['status'] === 'absent'));
$lateCount     = count(array_filter($attendance, fn($a) => $a['status'] === 'late'));
$onLeaveCount  = count(array_filter($attendance, fn($a) => $a['status'] === 'leave'));
$halfDayCount  = count(array_filter($attendance, fn($a) => $a['status'] === 'half_day'));
$totalHours    = array_sum(array_column($attendance, 'hours_worked'));

// Employees for forms
$employees = [];
try {
    $stmt = $pdo->prepare("SELECT id, employee_no, first_name, last_name FROM hrm_employees WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]);
    $employees = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-fingerprint me-2" style="color:<?= $moduleColor ?>"></i>Attendance Tracking</h4>
    <p class="text-muted mb-0">Monitor employee check-in/out and attendance status</p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#bulkModal">
      <i class="fas fa-list-check me-1"></i>Bulk Mark
    </button>
    <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#attModal" onclick="openAddModal()">
      <i class="fas fa-plus me-2"></i>Add Attendance
    </button>
  </div>
</div>

<!-- Period Selector -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Month</label>
        <input type="month" name="month" class="form-control form-control-sm" value="<?= e($filterMonth) ?>">
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Specific Date (overrides month)</label>
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($filterDate) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-search me-1"></i>View</button>
        <a href="attendance.php?month=<?= date('Y-m') ?>" class="btn btn-sm btn-outline-secondary ms-1">Today's Month</a>
        <a href="attendance.php?date=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-secondary ms-1">Today</a>
      </div>
    </form>
  </div>
</div>

<!-- Summary Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-4 col-xl-2">
    <div class="card text-center border-0 shadow-sm">
      <div class="card-body py-3">
        <div class="fs-4 fw-bold text-success"><?= $presentCount ?></div>
        <div class="small text-muted">Present</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4 col-xl-2">
    <div class="card text-center border-0 shadow-sm">
      <div class="card-body py-3">
        <div class="fs-4 fw-bold text-danger"><?= $absentCount ?></div>
        <div class="small text-muted">Absent</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4 col-xl-2">
    <div class="card text-center border-0 shadow-sm">
      <div class="card-body py-3">
        <div class="fs-4 fw-bold text-warning"><?= $lateCount ?></div>
        <div class="small text-muted">Late</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4 col-xl-2">
    <div class="card text-center border-0 shadow-sm">
      <div class="card-body py-3">
        <div class="fs-4 fw-bold text-info"><?= $onLeaveCount ?></div>
        <div class="small text-muted">On Leave</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4 col-xl-2">
    <div class="card text-center border-0 shadow-sm">
      <div class="card-body py-3">
        <div class="fs-4 fw-bold text-secondary"><?= $halfDayCount ?></div>
        <div class="small text-muted">Half Day</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4 col-xl-2">
    <div class="card text-center border-0 shadow-sm">
      <div class="card-body py-3">
        <div class="fs-4 fw-bold" style="color:<?= $moduleColor ?>"><?= number_format($totalHours, 1) ?>h</div>
        <div class="small text-muted">Total Hours</div>
      </div>
    </div>
  </div>
</div>

<!-- Attendance Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0">
      <i class="fas fa-fingerprint me-2" style="color:<?= $moduleColor ?>"></i>
      Attendance — <?= $filterDate ? formatDate($filterDate) : date('F Y', strtotime($monthStart)) ?>
    </h6>
    <span class="badge bg-secondary"><?= count($attendance) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Employee</th>
            <th>Date</th>
            <th>Check-In</th>
            <th>Check-Out</th>
            <th class="text-center">Hours</th>
            <th>Status</th>
            <th>Notes</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($attendance)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No attendance records for this period.
          </td></tr>
          <?php else: foreach ($attendance as $att):
            $statusColors = ['present'=>'success','absent'=>'danger','late'=>'warning','half_day'=>'secondary','leave'=>'info'];
            $sBg = $statusColors[$att['status']] ?? 'secondary';
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($att['first_name'].' '.$att['last_name']) ?></div>
              <div class="small text-muted"><?= e($att['employee_no']) ?></div>
            </td>
            <td><?= formatDate($att['date']) ?></td>
            <td><?= $att['check_in']  ? date('h:i A', strtotime($att['check_in'])) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $att['check_out'] ? date('h:i A', strtotime($att['check_out'])) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-center">
              <?= $att['hours_worked'] ? number_format((float)$att['hours_worked'], 1).'h' : '<span class="text-muted">—</span>' ?>
            </td>
            <td><span class="badge bg-<?= $sBg ?>"><?= ucwords(str_replace('_',' ',$att['status'])) ?></span></td>
            <td class="small text-muted"><?= e($att['notes'] ?? '—') ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= htmlspecialchars(json_encode($att), ENT_QUOTES) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteAtt(<?= $att['id'] ?>)" title="Delete">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Attendance Modal -->
<div class="modal fade" id="attModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="attId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="attModalTitle"><i class="fas fa-fingerprint me-2"></i>Add Attendance</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
              <select name="employee_id" id="attEmp" class="form-select" required>
                <option value="">-- Select Employee --</option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>"><?= e($emp['first_name'].' '.$emp['last_name'].' ('.$emp['employee_no'].')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
              <input type="date" name="date" id="attDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Check-In Time</label>
              <input type="time" name="check_in" id="attIn" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Check-Out Time</label>
              <input type="time" name="check_out" id="attOut" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
              <select name="status" id="attStatus" class="form-select" required>
                <option value="present">Present</option>
                <option value="absent">Absent</option>
                <option value="late">Late</option>
                <option value="half_day">Half Day</option>
                <option value="leave">On Leave</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <input type="text" name="notes" id="attNotes" class="form-control" placeholder="Optional notes" maxlength="255">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Bulk Mark Modal -->
<div class="modal fade" id="bulkModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="bulk_mark">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title"><i class="fas fa-list-check me-2"></i>Bulk Mark Attendance</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
              <input type="date" name="bulk_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Mark As <span class="text-danger">*</span></label>
              <select name="bulk_status" class="form-select" required>
                <option value="present">Present</option>
                <option value="absent">Absent</option>
                <option value="late">Late</option>
                <option value="leave">On Leave</option>
              </select>
            </div>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label fw-semibold mb-0">Select Employees</label>
            <div>
              <button type="button" class="btn btn-xs btn-outline-success btn-sm" onclick="selectAll()">Select All</button>
              <button type="button" class="btn btn-xs btn-outline-secondary btn-sm ms-1" onclick="selectNone()">Deselect All</button>
            </div>
          </div>
          <div class="border rounded p-2" style="max-height:300px;overflow-y:auto">
            <?php foreach ($employees as $emp): ?>
            <div class="form-check">
              <input type="checkbox" name="bulk_emp_ids[]" value="<?= $emp['id'] ?>" class="form-check-input bulk-check" id="bEmp<?= $emp['id'] ?>" checked>
              <label class="form-check-label" for="bEmp<?= $emp['id'] ?>">
                <?= e($emp['employee_no']) ?> — <?= e($emp['first_name'].' '.$emp['last_name']) ?>
              </label>
            </div>
            <?php endforeach; ?>
            <?php if (empty($employees)): ?>
            <p class="text-muted small mb-0">No active employees found.</p>
            <?php endif; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-check me-1"></i>Mark Attendance</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteAttForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteAttId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAddModal() {
  document.getElementById('attModalTitle').innerHTML = '<i class="fas fa-fingerprint me-2"></i>Add Attendance';
  document.getElementById('attId').value     = 0;
  document.getElementById('attEmp').value    = '';
  document.getElementById('attDate').value   = new Date().toISOString().split('T')[0];
  document.getElementById('attIn').value     = '';
  document.getElementById('attOut').value    = '';
  document.getElementById('attStatus').value = 'present';
  document.getElementById('attNotes').value  = '';
}

function openEditModal(att) {
  document.getElementById('attModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Attendance';
  document.getElementById('attId').value     = att.id;
  document.getElementById('attEmp').value    = att.employee_id || '';
  document.getElementById('attDate').value   = att.date        || '';
  document.getElementById('attIn').value     = att.check_in    || '';
  document.getElementById('attOut').value    = att.check_out   || '';
  document.getElementById('attStatus').value = att.status      || 'present';
  document.getElementById('attNotes').value  = att.notes       || '';
  var modal = new bootstrap.Modal(document.getElementById('attModal'));
  modal.show();
}

function deleteAtt(id) {
  Swal.fire({
    title: 'Delete Attendance?',
    text: 'This record will be permanently deleted.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteAttId').value = id;
      document.getElementById('deleteAttForm').submit();
    }
  });
}

function selectAll()  { document.querySelectorAll('.bulk-check').forEach(function(c) { c.checked = true; }); }
function selectNone() { document.querySelectorAll('.bulk-check').forEach(function(c) { c.checked = false; }); }
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
