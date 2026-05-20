<?php
// ── HRM: Department Management ─────────────────────────────────
$moduleSlug  = 'hrm';
$moduleName  = 'HRM System';
$moduleIcon  = 'fas fa-users-cog';
$moduleColor = '#2c3e50';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'employees.php',   'icon' => 'fas fa-id-badge',       'label' => 'Employees'],
    ['url' => 'departments.php', 'icon' => 'fas fa-sitemap',        'label' => 'Departments'],
    ['url' => 'payroll.php',     'icon' => 'fas fa-money-check',    'label' => 'Payroll'],
    ['url' => 'leave.php',       'icon' => 'fas fa-calendar-minus', 'label' => 'Leave'],
    ['url' => 'attendance.php',  'icon' => 'fas fa-fingerprint',    'label' => 'Attendance'],
];

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = sanitize($_POST['name']    ?? '');
        $headId = (int)($_POST['head_id']    ?? 0);
        $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE hrm_departments SET name=?, head_id=?, status=? WHERE id=? AND org_id=?");
            $stmt->execute([$name, $headId ?: null, $status, $id, $orgId]);
            setFlash('success', 'Department updated.');
            logActivity('update', 'hrm', "Updated department: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO hrm_departments (org_id, name, head_id, status) VALUES (?,?,?,?)");
            $stmt->execute([$orgId, $name, $headId ?: null, $status]);
            setFlash('success', "Department \"$name\" created.");
            logActivity('create', 'hrm', "Created department: $name");
        }
        redirect('departments.php');
    }

    if ($action === 'delete') {
        $id    = (int)($_POST['id'] ?? 0);
        $count = countRows('hrm_employees', 'department_id=?', [$id]);
        if ($count > 0) {
            setFlash('danger', "Cannot delete: $count employee(s) belong to this department.");
        } else {
            $pdo->prepare("DELETE FROM hrm_departments WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Department deleted.');
            logActivity('delete', 'hrm', "Deleted department #$id");
        }
        redirect('departments.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch departments with head name and employee count
$departments = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.*,
               CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS head_name,
               (SELECT COUNT(*) FROM hrm_employees em WHERE em.department_id = d.id AND em.status='active') AS emp_count
        FROM hrm_departments d
        LEFT JOIN hrm_employees e ON d.head_id = e.id
        WHERE d.org_id = ?
        ORDER BY d.name
    ");
    $stmt->execute([$orgId]);
    $departments = $stmt->fetchAll();
} catch (Exception $e) {}

// Employees for head dropdown
$employees = [];
try {
    $stmt = $pdo->prepare("SELECT id, employee_no, first_name, last_name FROM hrm_employees WHERE org_id=? AND status='active' ORDER BY first_name, last_name");
    $stmt->execute([$orgId]);
    $employees = $stmt->fetchAll();
} catch (Exception $e) {}

// Summary stats
$totalDepts    = count($departments);
$totalEmployees = countRows('hrm_employees', 'org_id=?', [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-sitemap me-2" style="color:<?= $moduleColor ?>"></i>Departments</h4>
    <p class="text-muted mb-0">Manage organisational structure</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#deptModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Add Department
  </button>
</div>

<!-- Quick Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-sitemap"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalDepts ?></div><div class="stat-label">Total Departments</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalEmployees ?></div><div class="stat-label">Total Employees</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-user-tie"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= count(array_filter($departments, fn($d) => !empty(trim($d['head_name'])))) ?></div>
        <div class="stat-label">Depts with a Head</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= count(array_filter($departments, fn($d) => $d['status'] === 'active')) ?></div>
        <div class="stat-label">Active Departments</div>
      </div>
    </div>
  </div>
</div>

<!-- Department Cards Grid -->
<div class="row g-3 mb-4">
  <?php if (empty($departments)): ?>
  <div class="col-12">
    <div class="card"><div class="card-body text-center text-muted py-5">
      <i class="fas fa-sitemap fa-2x mb-2 d-block"></i>No departments yet. Add your first department.
    </div></div>
  </div>
  <?php else: foreach ($departments as $dept): ?>
  <div class="col-sm-6 col-xl-4">
    <div class="card h-100 border-start border-4" style="border-color:<?= $moduleColor ?> !important">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div>
            <h6 class="fw-bold mb-0"><?= e($dept['name']) ?></h6>
            <?= statusBadge($dept['status']) ?>
          </div>
          <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="#" onclick='openEditModal(<?= htmlspecialchars(json_encode($dept), ENT_QUOTES) ?>); return false;'><i class="fas fa-edit me-2"></i>Edit</a></li>
              <li><a class="dropdown-item text-danger" href="#" onclick="deleteDept(<?= $dept['id'] ?>, '<?= e($dept['name']) ?>'); return false;"><i class="fas fa-trash me-2"></i>Delete</a></li>
            </ul>
          </div>
        </div>
        <div class="d-flex align-items-center gap-3 mt-3">
          <div class="text-center">
            <div class="fs-4 fw-bold" style="color:<?= $moduleColor ?>"><?= $dept['emp_count'] ?></div>
            <div class="small text-muted">Employees</div>
          </div>
          <div class="vr"></div>
          <div>
            <div class="small text-muted fw-semibold">Department Head</div>
            <div class="small"><?= trim($dept['head_name']) ? e(trim($dept['head_name'])) : '<span class="text-muted fst-italic">Not assigned</span>' ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Departments Table -->
<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-table me-2" style="color:<?= $moduleColor ?>"></i>All Departments</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Department Name</th>
            <th>Head of Department</th>
            <th class="text-center">Employees</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($departments)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No departments found</td></tr>
          <?php else: foreach ($departments as $i => $dept): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td class="fw-semibold"><?= e($dept['name']) ?></td>
            <td><?= trim($dept['head_name']) ? e(trim($dept['head_name'])) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-center">
              <span class="badge" style="background:<?= $moduleColor ?>"><?= $dept['emp_count'] ?></span>
            </td>
            <td><?= statusBadge($dept['status']) ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= htmlspecialchars(json_encode($dept), ENT_QUOTES) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteDept(<?= $dept['id'] ?>, '<?= e($dept['name']) ?>')" title="Delete">
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

<!-- Add/Edit Department Modal -->
<div class="modal fade" id="deptModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="deptId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="deptModalTitle"><i class="fas fa-sitemap me-2"></i>Add Department</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Department Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="deptName" class="form-control" required maxlength="255" placeholder="e.g. Finance, Human Resources">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Head of Department</label>
            <select name="head_id" id="deptHead" class="form-select">
              <option value="">-- Not Assigned --</option>
              <?php foreach ($employees as $emp): ?>
              <option value="<?= $emp['id'] ?>"><?= e($emp['first_name'].' '.$emp['last_name'].' ('.$emp['employee_no'].')') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="deptStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Department</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteDeptForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteDeptId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAddModal() {
  document.getElementById('deptModalTitle').innerHTML = '<i class="fas fa-sitemap me-2"></i>Add Department';
  document.getElementById('deptId').value     = 0;
  document.getElementById('deptName').value   = '';
  document.getElementById('deptHead').value   = '';
  document.getElementById('deptStatus').value = 'active';
}

function openEditModal(dept) {
  document.getElementById('deptModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Department';
  document.getElementById('deptId').value     = dept.id;
  document.getElementById('deptName').value   = dept.name   || '';
  document.getElementById('deptHead').value   = dept.head_id || '';
  document.getElementById('deptStatus').value = dept.status  || 'active';
  var modal = new bootstrap.Modal(document.getElementById('deptModal'));
  modal.show();
}

function deleteDept(id, name) {
  Swal.fire({
    title: 'Delete Department?',
    text: '"' + name + '" will be deleted. Ensure no employees are assigned to it.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteDeptId').value = id;
      document.getElementById('deleteDeptForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
