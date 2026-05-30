<?php
$moduleSlug = 'salon';
$moduleName = 'Salon & Spa';
$moduleIcon = 'fas fa-cut';
$moduleColor = '#c0392b';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'appointments.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Appointments'],
    ['url' => 'clients.php',      'icon' => 'fas fa-users',          'label' => 'Clients'],
    ['url' => 'services.php',     'icon' => 'fas fa-concierge-bell', 'label' => 'Services'],
    ['url' => 'staff.php',        'icon' => 'fas fa-user-tie',       'label' => 'Staff'],
    ['url' => 'packages.php',     'icon' => 'fas fa-gift',           'label' => 'Packages'],
    ['url' => 'inventory.php',    'icon' => 'fas fa-boxes',          'label' => 'Inventory'],
    ['url' => 'loyalty.php',      'icon' => 'fas fa-star',           'label' => 'Loyalty'],
    ['url' => 'payments.php',     'icon' => 'fas fa-money-bill-wave','label' => 'Payments'],
    ['url' => 'expenses.php',     'icon' => 'fas fa-receipt',        'label' => 'Expenses'],
    ['url' => 'promotions.php',   'icon' => 'fas fa-tag',            'label' => 'Promotions'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $first_name = sanitize($_POST['first_name'] ?? '');
        $last_name = sanitize($_POST['last_name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $speciality = sanitize($_POST['speciality'] ?? '');
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE salon_staff SET first_name = ?, last_name = ?, phone = ?, speciality = ?, status = ?, user_id = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$first_name, $last_name, $phone, $speciality, $status, $userId ?: null, $id, $orgId]);
            setFlash('success', 'Staff member updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO salon_staff (org_id, first_name, last_name, phone, speciality, status, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $first_name, $last_name, $phone, $speciality, $status, $userId ?: null]);
            setFlash('success', "Staff member '$first_name $last_name' added successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'salon', "Staff: $first_name $last_name");
        redirect('staff.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM salon_staff WHERE id = ? AND org_id = ?");
        $stmt->execute([$id, $orgId]);
        setFlash('success', 'Staff member deleted successfully.');
        redirect('staff.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';
$fQ = trim($_GET['q'] ?? '');

$where = 'org_id = ?';
$params = [$orgId];

if ($fStatus !== '') {
    $where .= ' AND status = ?';
    $params[] = $fStatus;
}
if ($fQ !== '') {
    $where .= ' AND (first_name LIKE ? OR last_name LIKE ? OR speciality LIKE ?)';
    $like = "%$fQ%";
    array_push($params, $like, $like, $like);
}

$staffList = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM salon_staff WHERE $where ORDER BY first_name ASC, last_name ASC");
    $stmt->execute($params);
    $staffList = $stmt->fetchAll();
} catch (Exception $e) {}

// Fetch system users to optionally link to a staff profile
$systemUsers = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE org_id = ? ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $systemUsers = $stmt->fetchAll();
} catch (Exception $e) {}

$totalStaff = countRows('salon_staff', 'org_id = ?', [$orgId]);
$activeStaff = countRows('salon_staff', "org_id = ? AND status = 'active'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-user-tie me-2" style="color:<?= $moduleColor ?>"></i>Staff / Stylists</h4>
    <p class="text-muted mb-0">Manage service providers, specialists, and scheduling availability</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#stModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Staff</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-user-tie"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalStaff ?></div>
        <div class="stat-label">Total Staff</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $activeStaff ?></div>
        <div class="stat-label">Active & Available</div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-6">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Name or speciality…" value="<?= e($fQ) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="staff.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-user-tie me-2" style="color:<?= $moduleColor ?>"></i>Staff Directory</h6>
    <span class="badge bg-secondary"><?= count($staffList) ?> members</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Name</th>
            <th>Phone</th>
            <th>Speciality</th>
            <th>Linked User</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($staffList)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No staff members found.</td></tr>
          <?php else: foreach ($staffList as $st): 
            $linkedUser = '—';
            if ($st['user_id']) {
                foreach ($systemUsers as $su) {
                    if ($su['id'] == $st['user_id']) {
                        $linkedUser = e($su['name']) . ' (' . e($su['email']) . ')';
                        break;
                    }
                }
            }
          ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div style="width:34px;height:34px;border-radius:50%;background:<?= $moduleColor ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:600;flex-shrink:0">
                  <?= strtoupper(substr($st['first_name'], 0, 1) . substr($st['last_name'], 0, 1)) ?>
                </div>
                <div class="fw-semibold"><?= e($st['first_name'] . ' ' . $st['last_name']) ?></div>
              </div>
            </td>
            <td><?= e($st['phone'] ?? '—') ?></td>
            <td><span class="badge bg-info text-dark"><?= e($st['speciality'] ?: 'Generalist') ?></span></td>
            <td class="small text-muted"><?= $linkedUser ?></td>
            <td><?= statusBadge($st['status'] ?? 'active') ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($st), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delStaff(<?= $st['id'] ?>, '<?= e($st['first_name'] . ' ' . $st['last_name']) ?>')"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="stModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="stId" value="0">
  <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
    <h5 class="modal-title" id="stTitle"><i class="fas fa-user-tie me-2"></i>Add Staff Member</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
        <input type="text" name="first_name" id="stFirst" class="form-control" required maxlength="100">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
        <input type="text" name="last_name" id="stLast" class="form-control" required maxlength="100">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label>
        <input type="text" name="phone" id="stPhone" class="form-control" required maxlength="25">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Speciality <span class="text-danger">*</span></label>
        <input type="text" name="speciality" id="stSpec" class="form-control" required maxlength="255" placeholder="e.g. Master Stylist, Colorist, Masseuse">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Link System User <small class="text-muted">(Optional)</small></label>
        <select name="user_id" id="stUser" class="form-select">
          <option value="">-- No linked user --</option>
          <?php foreach ($systemUsers as $su): ?>
          <option value="<?= $su['id'] ?>"><?= e($su['name']) ?> (<?= e($su['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Status</label>
        <select name="status" id="stStatus" class="form-select">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Staff</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delStForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delStId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('stTitle').innerHTML = '<i class="fas fa-user-tie me-2"></i>Add Staff Member';
  ['stId', 'stFirst', 'stLast', 'stPhone', 'stSpec'].forEach(i => document.getElementById(i).value = i === 'stId' ? '0' : '');
  document.getElementById('stUser').value = '';
  document.getElementById('stStatus').value = 'active';
}
function openEdit(st) {
  document.getElementById('stTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Staff Member';
  document.getElementById('stId').value = st.id;
  document.getElementById('stFirst').value = st.first_name || '';
  document.getElementById('stLast').value = st.last_name || '';
  document.getElementById('stPhone').value = st.phone || '';
  document.getElementById('stSpec').value = st.speciality || '';
  document.getElementById('stUser').value = st.user_id || '';
  document.getElementById('stStatus').value = st.status || 'active';
  new bootstrap.Modal(document.getElementById('stModal')).show();
}
function delStaff(id, name) {
  Swal.fire({
    title: 'Delete Staff Member?',
    text: '"' + name + '" will be permanently deleted and cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delStId').value = id;
      document.getElementById('delStForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
