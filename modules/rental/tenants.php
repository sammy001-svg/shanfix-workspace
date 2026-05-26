<?php
$moduleSlug  = 'rental';
$moduleName  = 'Rental & Property';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'properties.php',  'icon' => 'fas fa-building',       'label' => 'Properties'],
    ['url' => 'units.php',       'icon' => 'fas fa-door-open',      'label' => 'Units'],
    ['url' => 'tenants.php',     'icon' => 'fas fa-users',          'label' => 'Tenants'],
    ['url' => 'leases.php',      'icon' => 'fas fa-file-contract',  'label' => 'Leases'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-bill',     'label' => 'Payments'],
    ['url' => 'maintenance.php', 'icon' => 'fas fa-tools',          'label' => 'Maintenance'],
    ['url' => 'invoices.php',    'icon' => 'fas fa-file-invoice',   'label' => 'Invoices'],
    ['url' => 'utilities.php',   'icon' => 'fas fa-bolt',            'label' => 'Utilities'],
    ['url' => 'agreements.php',  'icon' => 'fas fa-file-signature', 'label' => 'Agreements'],
    ['url' => 'inspections.php', 'icon' => 'fas fa-clipboard-check','label' => 'Inspections'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $unitId = (int)$_POST['unit_id'];
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $idNumber = sanitize($_POST['id_number'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $employer = sanitize($_POST['employer'] ?? '');
        $leaseStart = $_POST['lease_start'] ?? date('Y-m-d');
        $leaseEnd = $_POST['lease_end'] ?? date('Y-m-d', strtotime('+1 year'));
        $deposit = (float)($_POST['deposit'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($id > 0) {
            // Get original unit_id to see if we changed units
            $stmt = $pdo->prepare("SELECT unit_id FROM rental_tenants WHERE id = ? AND org_id = ?");
            $stmt->execute([$id, $orgId]);
            $oldUnitId = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("UPDATE rental_tenants SET unit_id = ?, first_name = ?, last_name = ?, id_number = ?, phone = ?, email = ?, employer = ?, lease_start = ?, lease_end = ?, deposit = ?, status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$unitId, $firstName, $lastName, $idNumber, $phone, $email, $employer, $leaseStart, $leaseEnd, $deposit, $status, $id, $orgId]);
            
            if ($oldUnitId !== $unitId) {
                // Free up old unit
                $pdo->prepare("UPDATE rental_units SET status = 'vacant' WHERE id = ? AND org_id = ?")->execute([$oldUnitId, $orgId]);
                // Occupy new unit
                $pdo->prepare("UPDATE rental_units SET status = 'occupied' WHERE id = ? AND org_id = ?")->execute([$unitId, $orgId]);
            }
            setFlash('success', 'Tenant lease profile updated.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO rental_tenants (org_id, unit_id, first_name, last_name, id_number, phone, email, employer, lease_start, lease_end, deposit, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $unitId, $firstName, $lastName, $idNumber, $phone, $email, $employer, $leaseStart, $leaseEnd, $deposit, $status]);
            
            // Auto update unit status to occupied
            $pdo->prepare("UPDATE rental_units SET status = 'occupied' WHERE id = ? AND org_id = ?")->execute([$unitId, $orgId]);
            setFlash('success', "Tenant '$firstName $lastName' checked-in successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'rental', "Tenant Lease: $firstName $lastName (Unit ID: $unitId)");
        redirect('tenants.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        // Free up the unit before deleting
        $stmt = $pdo->prepare("SELECT unit_id FROM rental_tenants WHERE id = ? AND org_id = ?");
        $stmt->execute([$id, $orgId]);
        $unitId = (int)$stmt->fetchColumn();
        if ($unitId > 0) {
            $pdo->prepare("UPDATE rental_units SET status = 'vacant' WHERE id = ? AND org_id = ?")->execute([$unitId, $orgId]);
        }

        $pdo->prepare("DELETE FROM rental_tenants WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Tenant lease deleted, unit status set to Vacant.');
        redirect('tenants.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';
$where = 't.org_id = ?';
$params = [$orgId];

if ($fStatus !== '') {
    $where .= ' AND t.status = ?';
    $params[] = $fStatus;
}

$tenantsList = [];
try {
    $stmt = $pdo->prepare("SELECT t.*, u.unit_no, p.name AS property_name, u.rent AS unit_rent
                           FROM rental_tenants t
                           JOIN rental_units u ON t.unit_id = u.id
                           JOIN rental_properties p ON u.property_id = p.id
                           WHERE $where
                           ORDER BY t.created_at DESC");
    $stmt->execute($params);
    $tenantsList = $stmt->fetchAll();
} catch (Exception $e) {}

$vacantUnits = [];
try {
    $stmt = $pdo->prepare("SELECT u.id, u.unit_no, p.name AS property_name 
                           FROM rental_units u
                           JOIN rental_properties p ON u.property_id = p.id
                           WHERE u.org_id = ? AND (u.status = 'vacant' OR u.status = 'maintenance')
                           ORDER BY p.name ASC, u.unit_no ASC");
    $stmt->execute([$orgId]);
    $vacantUnits = $stmt->fetchAll();
} catch (Exception $e) {}

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $tid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM rental_tenants WHERE id = ? AND org_id = ?");
        $stmt->execute([$tid, $orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            header('Content-Type: application/json');
            echo json_encode($row);
            exit;
        }
    } catch (Exception $e) {}
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Renters & Tenants Roster</h4>
    <p class="text-muted mb-0">Record tenants, check lease boundaries, allocate security deposits and track employer coordinates</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#tenantModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Check-in Tenant</button>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Lease Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Lease Statuses</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Active Lease</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Terminated</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="tenants.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-users me-2 text-primary"></i>Lease Agreements Ledger</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Tenant Profile</th>
            <th>Allocated Unit</th>
            <th>Contacts Details</th>
            <th>Lease Schedule</th>
            <th>Deposit Paid</th>
            <th>Employer Detail</th>
            <th>Lease Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($tenantsList)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-user-friends fa-2x mb-2 d-block"></i>No active tenant check-ins found.</td></tr>
          <?php else: foreach ($tenantsList as $t): ?>
          <tr>
            <td>
              <div class="fw-bold text-dark fs-6"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></div>
              <small class="text-muted">National ID: <?= e($t['id_number']) ?></small>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= e($t['unit_no']) ?></div>
              <small class="text-muted"><?= e($t['property_name']) ?></small>
            </td>
            <td>
              <div><i class="fas fa-phone text-muted me-1 small"></i><?= e($t['phone']) ?></div>
              <small class="text-muted"><i class="fas fa-envelope me-1 small"></i><?= e($t['email'] ?: '—') ?></small>
            </td>
            <td>
              <div class="small fw-semibold text-dark">Start: <?= formatDate($t['lease_start']) ?></div>
              <small class="text-danger fw-semibold">End: <?= formatDate($t['lease_end']) ?></small>
            </td>
            <td class="fw-bold text-dark"><?= formatCurrency($t['deposit']) ?></td>
            <td class="small fw-semibold"><?= e($t['employer'] ?: 'Self-Employed') ?></td>
            <td><span class="badge bg-<?= $t['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($t['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $t['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delTenant(<?= $t['id'] ?>, '<?= e($t['first_name'] . ' ' . $t['last_name']) ?>')" title="Terminate"><i class="fas fa-times-circle"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="tenantModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="tenantId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="tenantTitle"><i class="fas fa-user-plus me-2"></i>Check-in Tenant</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
        <input type="text" name="first_name" id="tenantFirst" class="form-control" required placeholder="e.g. David">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
        <input type="text" name="last_name" id="tenantLast" class="form-control" required placeholder="e.g. Miller">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">National ID / Passport No <span class="text-danger">*</span></label>
        <input type="text" name="id_number" id="tenantIdNo" class="form-control" required placeholder="e.g. 33445566">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Primary Phone Contact <span class="text-danger">*</span></label>
        <input type="tel" name="phone" id="tenantPhone" class="form-control" required placeholder="e.g. +254 712 345 678">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Active Email Address</label>
        <input type="email" name="email" id="tenantEmail" class="form-control" placeholder="e.g. david.miller@example.com">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Assign Property Unit <span class="text-danger">*</span></label>
        <select name="unit_id" id="tenantUnitId" class="form-select select2-enable" required style="width:100%;">
          <option value="">-- select vacant / maintenance unit --</option>
          <?php foreach ($vacantUnits as $vu): ?>
          <option value="<?= $vu['id'] ?>"><?= e($vu['unit_no']) ?> (<?= e($vu['property_name']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted d-block mt-1">If editing, already assigned units will display in the selection</small>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Lease Agreement Start <span class="text-danger">*</span></label>
        <input type="date" name="lease_start" id="tenantLeaseStart" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Lease Agreement End <span class="text-danger">*</span></label>
        <input type="date" name="lease_end" id="tenantLeaseEnd" class="form-control" required value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Security Deposit Paid <span class="text-danger">*</span></label>
        <input type="number" name="deposit" id="tenantDeposit" class="form-control" required min="0" placeholder="e.g. 25000">
      </div>
      <div class="col-md-8">
        <label class="form-label fw-semibold">Employer Profile / Workspace details</label>
        <input type="text" name="employer" id="tenantEmployer" class="form-control" placeholder="e.g. Senior Software Engineer at Safaricom PLC">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Lease Status</label>
        <select name="status" id="tenantStatus" class="form-select">
          <option value="active">Active Lease</option>
          <option value="inactive">Terminated / Checked Out</option>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Check-in Tenant</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delTenantForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delTenantId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('tenantTitle').innerHTML = '<i class="fas fa-user-plus me-2"></i>Check-in Tenant';
  document.getElementById('tenantId').value = '0';
  document.getElementById('tenantFirst').value = '';
  document.getElementById('tenantLast').value = '';
  document.getElementById('tenantIdNo').value = '';
  document.getElementById('tenantPhone').value = '';
  document.getElementById('tenantEmail').value = '';
  document.getElementById('tenantUnitId').value = '';
  document.getElementById('tenantDeposit').value = '';
  document.getElementById('tenantEmployer').value = '';
  document.getElementById('tenantStatus').value = 'active';
  
  document.getElementById('tenantLeaseStart').value = new Date().toISOString().split('T')[0];
  document.getElementById('tenantLeaseEnd').value = new Date(new Date().setFullYear(new Date().getFullYear() + 1)).toISOString().split('T')[0];
}
function openEdit(id) {
  fetch('tenants.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('tenantTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Lease Agreement';
      document.getElementById('tenantId').value = data.id;
      document.getElementById('tenantFirst').value = data.first_name;
      document.getElementById('tenantLast').value = data.last_name;
      document.getElementById('tenantIdNo').value = data.id_number;
      document.getElementById('tenantPhone').value = data.phone;
      document.getElementById('tenantEmail').value = data.email || '';
      
      // Ensure unit select contains this unit (might be occupied)
      const opt = document.createElement('option');
      opt.value = data.unit_id;
      opt.text = 'Currently Assigned Unit (Keep / Swap)';
      opt.selected = true;
      document.getElementById('tenantUnitId').appendChild(opt);
      
      document.getElementById('tenantLeaseStart').value = data.lease_start;
      document.getElementById('tenantLeaseEnd').value = data.lease_end;
      document.getElementById('tenantDeposit').value = data.deposit;
      document.getElementById('tenantEmployer').value = data.employer || '';
      document.getElementById('tenantStatus').value = data.status;
      
      new bootstrap.Modal(document.getElementById('tenantModal')).show();
    });
}
function delTenant(id, name) {
  Swal.fire({
    title: 'Terminate Lease Agreement?',
    text: 'Check-out "' + name + '" and set their assigned property unit to Vacant?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, check-out tenant'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delTenantId').value = id;
      document.getElementById('delTenantForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
