<?php
// ── CARYARD: Vehicle Service & Maintenance ─────────────────────
$moduleSlug  = 'caryard';
$moduleName  = 'Car Yard Management';
$moduleIcon  = 'fas fa-car';
$moduleColor = '#e67e22';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'vehicles.php',       'icon' => 'fas fa-car',            'label' => 'Vehicles'],
    ['url' => 'sales.php',          'icon' => 'fas fa-handshake',      'label' => 'Sales'],
    ['url' => 'customers.php',      'icon' => 'fas fa-users',          'label' => 'Customers'],
    ['url' => 'finance.php',        'icon' => 'fas fa-university',     'label' => 'Finance'],
    ['url' => 'testdrives.php',     'icon' => 'fas fa-road',           'label' => 'Test Drives'],
    ['url' => 'services.php',       'icon' => 'fas fa-tools',          'label' => 'Services'],
    ['url' => 'reconditioning.php', 'icon' => 'fas fa-wrench',         'label' => 'Reconditioning'],
    ['url' => 'valuations.php',     'icon' => 'fas fa-search-dollar',  'label' => 'Valuations'],
    ['url' => 'inquiries.php',      'icon' => 'fas fa-comments',       'label' => 'Inquiries'],
    ['url' => 'insurance.php',      'icon' => 'fas fa-shield-alt',     'label' => 'Insurance'],
    ['url' => 'parts.php',          'icon' => 'fas fa-cogs',           'label' => 'Parts & Spares'],
    ['url' => 'delivery.php',       'icon' => 'fas fa-truck-loading',  'label' => 'Deliveries'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $vehicleId   = (int)($_POST['vehicle_id']   ?? 0);
        $serviceType = sanitize($_POST['service_type'] ?? '');
        $description = sanitize($_POST['description']  ?? '');
        $cost        = (float)($_POST['cost']          ?? 0);
        $serviceDate = $_POST['service_date']           ?? date('Y-m-d');
        $technician  = sanitize($_POST['technician']    ?? '');
        $status      = in_array($_POST['status'] ?? '', ['pending','in_progress','completed']) ? $_POST['status'] : 'pending';

        if (!$vehicleId || !$serviceType) {
            setFlash('danger', 'Vehicle and service type are required.');
            redirect('services.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE caryard_services SET vehicle_id=?,service_type=?,description=?,cost=?,service_date=?,technician=?,status=? WHERE id=? AND org_id=?")
                ->execute([$vehicleId, $serviceType, $description, $cost, $serviceDate, $technician, $status, $id, $orgId]);
            setFlash('success', 'Service record updated.');
            logActivity('update', 'caryard', "Updated service record #$id");
        } else {
            $pdo->prepare("INSERT INTO caryard_services (org_id,vehicle_id,service_type,description,cost,service_date,technician,status) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $vehicleId, $serviceType, $description, $cost, $serviceDate, $technician, $status]);
            setFlash('success', 'Service record added successfully.');
            logActivity('create', 'caryard', "Added service: $serviceType");
        }
        redirect('services.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM caryard_services WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Service record deleted.');
        logActivity('delete', 'caryard', "Deleted service record #$id");
        redirect('services.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterStatus  = $_GET['status']  ?? '';
$filterVehicle = $_GET['vehicle'] ?? '';

$where  = 's.org_id = ?';
$params = [$orgId];
if ($filterStatus)  { $where .= ' AND s.status = ?';     $params[] = $filterStatus; }
if ($filterVehicle) { $where .= ' AND s.vehicle_id = ?'; $params[] = $filterVehicle; }

$services = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, CONCAT(v.make,' ',v.model,' (',v.year,')') AS vehicle_label, v.stock_no
        FROM caryard_services s
        JOIN caryard_vehicles v ON s.vehicle_id = v.id
        WHERE $where ORDER BY s.service_date DESC
    ");
    $stmt->execute($params);
    $services = $stmt->fetchAll();
} catch (Exception $e) {}

$vehicles = [];
try {
    $stmt = $pdo->prepare("SELECT id, stock_no, make, model, year FROM caryard_vehicles WHERE org_id=? ORDER BY make,model");
    $stmt->execute([$orgId]);
    $vehicles = $stmt->fetchAll();
} catch (Exception $e) {}

$totalServices  = countRows('caryard_services', 'org_id=?', [$orgId]);
$pendingCount   = countRows('caryard_services', "org_id=? AND status='pending'",     [$orgId]);
$inProgCount    = countRows('caryard_services', "org_id=? AND status='in_progress'", [$orgId]);
$completedCount = countRows('caryard_services', "org_id=? AND status='completed'",   [$orgId]);
$totalCost = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM caryard_services WHERE org_id=? AND status='completed'");
    $stmt->execute([$orgId]);
    $totalCost = (float)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-tools me-2" style="color:<?= $moduleColor ?>"></i>Vehicle Services & Maintenance</h4>
    <p class="text-muted mb-0">Track pre-sale servicing, repairs, and inspections</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#svcModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Log Service
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(230,126,34,.12);color:#e67e22"><i class="fas fa-tools"></i></div>
      <div><div class="stat-value"><?= $totalServices ?></div><div class="stat-label">Total Service Records</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div><div class="stat-value"><?= $pendingCount ?></div><div class="stat-label">Pending</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-spinner"></i></div>
      <div><div class="stat-value"><?= $inProgCount ?></div><div class="stat-label">In Progress</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= formatCurrency($totalCost) ?></div><div class="stat-label">Total Service Cost</div></div></div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-4">
        <label class="form-label small fw-semibold mb-1">Vehicle</label>
        <select name="vehicle" class="form-select form-select-sm">
          <option value="">All Vehicles</option>
          <?php foreach ($vehicles as $v): ?>
          <option value="<?= $v['id'] ?>" <?= $filterVehicle == $v['id'] ? 'selected':'' ?>>
            <?= e($v['stock_no'].' — '.$v['make'].' '.$v['model'].' ('.$v['year'].')') ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="pending"     <?= $filterStatus==='pending'     ?'selected':'' ?>>Pending</option>
          <option value="in_progress" <?= $filterStatus==='in_progress' ?'selected':'' ?>>In Progress</option>
          <option value="completed"   <?= $filterStatus==='completed'   ?'selected':'' ?>>Completed</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="services.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-tools me-2" style="color:<?= $moduleColor ?>"></i>Service Records</h6>
    <span class="badge bg-secondary"><?= count($services) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Vehicle</th>
            <th>Service Type</th>
            <th>Description</th>
            <th>Technician</th>
            <th>Date</th>
            <th class="text-end">Cost</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($services)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-tools fa-2x mb-2 d-block"></i>No service records found.</td></tr>
          <?php else: foreach ($services as $s): ?>
          <tr>
            <td>
              <div class="fw-semibold small"><?= e($s['vehicle_label']) ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= e($s['stock_no']) ?></div>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($s['service_type']) ?></span></td>
            <td class="small text-muted"><?= e(mb_strimwidth($s['description'] ?? '', 0, 50, '…')) ?></td>
            <td class="small"><?= e($s['technician'] ?: '—') ?></td>
            <td><?= formatDate($s['service_date']) ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$s['cost']) ?></td>
            <td><?= statusBadge($s['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this service record?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="svcModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="svcId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="svcModalTitle"><i class="fas fa-tools me-2"></i>Log Service</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label>
              <select name="vehicle_id" id="svcVehicle" class="form-select" required>
                <option value="">— Select Vehicle —</option>
                <?php foreach ($vehicles as $v): ?>
                <option value="<?= $v['id'] ?>"><?= e($v['stock_no'].' — '.$v['make'].' '.$v['model'].' ('.$v['year'].')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Service Type <span class="text-danger">*</span></label>
              <input type="text" name="service_type" id="svcType" class="form-control" required placeholder="e.g. Oil Change, Engine Tune-up">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Service Date</label>
              <input type="date" name="service_date" id="svcDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Cost (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="cost" id="svcCost" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="svcStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="in_progress">In Progress</option>
                <option value="completed">Completed</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Technician / Garage</label>
              <input type="text" name="technician" id="svcTech" class="form-control" placeholder="Name or garage">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Description / Notes</label>
              <textarea name="description" id="svcDesc" class="form-control" rows="2" placeholder="Service details..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Record</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("svcModalTitle").innerHTML = "<i class=\"fas fa-tools me-2\"></i>Log Service";
  document.getElementById("svcId").value       = 0;
  document.getElementById("svcVehicle").value  = "";
  document.getElementById("svcType").value     = "";
  document.getElementById("svcDate").value     = "' . date('Y-m-d') . '";
  document.getElementById("svcCost").value     = "0";
  document.getElementById("svcStatus").value   = "pending";
  document.getElementById("svcTech").value     = "";
  document.getElementById("svcDesc").value     = "";
}
function openEdit(s) {
  document.getElementById("svcModalTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Service";
  document.getElementById("svcId").value       = s.id;
  document.getElementById("svcVehicle").value  = s.vehicle_id  || "";
  document.getElementById("svcType").value     = s.service_type|| "";
  document.getElementById("svcDate").value     = s.service_date|| "";
  document.getElementById("svcCost").value     = s.cost        || 0;
  document.getElementById("svcStatus").value   = s.status      || "pending";
  document.getElementById("svcTech").value     = s.technician  || "";
  document.getElementById("svcDesc").value     = s.description || "";
  new bootstrap.Modal(document.getElementById("svcModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
