<?php
$moduleSlug  = 'rental';
$moduleName  = 'Rental & Property';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'properties.php','icon' => 'fas fa-building',       'label' => 'Properties'],
    ['url' => 'units.php',     'icon' => 'fas fa-door-open',      'label' => 'Units'],
    ['url' => 'tenants.php',   'icon' => 'fas fa-users',          'label' => 'Tenants'],
    ['url' => 'payments.php',  'icon' => 'fas fa-money-bill',     'label' => 'Payments'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
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
        $propertyId = (int)$_POST['property_id'];
        $unitNo = sanitize($_POST['unit_no'] ?? '');
        $type = sanitize($_POST['type'] ?? 'Apartment');
        $bedrooms = (int)($_POST['bedrooms'] ?? 0);
        $bathrooms = (int)($_POST['bathrooms'] ?? 0);
        $sizeSqm = (float)($_POST['size_sqm'] ?? 0);
        $floor = (int)($_POST['floor'] ?? 0);
        $rent = (float)$_POST['rent'];
        $deposit = (float)$_POST['deposit'];
        $amenities = sanitize($_POST['amenities'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['vacant', 'occupied', 'maintenance']) ? $_POST['status'] : 'vacant';

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE rental_units SET property_id = ?, unit_no = ?, type = ?, bedrooms = ?, bathrooms = ?, size_sqm = ?, floor = ?, rent = ?, deposit = ?, amenities = ?, status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$propertyId, $unitNo, $type, $bedrooms, $bathrooms, $sizeSqm, $floor, $rent, $deposit, $amenities, $status, $id, $orgId]);
            setFlash('success', 'Unit specifications updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO rental_units (org_id, property_id, unit_no, type, bedrooms, bathrooms, size_sqm, floor, rent, deposit, amenities, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $propertyId, $unitNo, $type, $bedrooms, $bathrooms, $sizeSqm, $floor, $rent, $deposit, $amenities, $status]);
            setFlash('success', "Unit '$unitNo' registered successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'rental', "Unit recorded: $unitNo (Property: $propertyId, Rent: $rent)");
        redirect('units.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM rental_units WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Unit removed from property configuration.');
        redirect('units.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fProperty = $_GET['property_id'] ?? '';
$fStatus = $_GET['status'] ?? '';
$where = 'u.org_id = ?';
$params = [$orgId];

if ($fProperty !== '') {
    $where .= ' AND u.property_id = ?';
    $params[] = $fProperty;
}
if ($fStatus !== '') {
    $where .= ' AND u.status = ?';
    $params[] = $fStatus;
}

$unitsList = [];
try {
    $stmt = $pdo->prepare("SELECT u.*, p.name AS property_name 
                           FROM rental_units u
                           JOIN rental_properties p ON u.property_id = p.id
                           WHERE $where
                           ORDER BY p.name ASC, u.unit_no ASC");
    $stmt->execute($params);
    $unitsList = $stmt->fetchAll();
} catch (Exception $e) {}

$propertiesList = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM rental_properties WHERE org_id = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $propertiesList = $stmt->fetchAll();
} catch (Exception $e) {}

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $uid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM rental_units WHERE id = ? AND org_id = ?");
        $stmt->execute([$uid, $orgId]);
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
    <h4 class="mb-1"><i class="fas fa-door-open me-2" style="color:<?= $moduleColor ?>"></i>Units Listing Roster</h4>
    <p class="text-muted mb-0">Record single units, define pricing catalogs, dimensions, and manage active tenant check-ins</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#unitModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Configure Unit</button>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Filter by Property Estate</label>
        <select name="property_id" class="form-select form-select-sm">
          <option value="">All Estates</option>
          <?php foreach ($propertiesList as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $fProperty == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Occupancy Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="vacant" <?= $fStatus === 'vacant' ? 'selected' : '' ?>>Vacant</option>
          <option value="occupied" <?= $fStatus === 'occupied' ? 'selected' : '' ?>>Occupied</option>
          <option value="maintenance" <?= $fStatus === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="units.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-door-open me-2 text-primary"></i>Units Directory</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Unit No / Level</th>
            <th>Property Estate</th>
            <th>Unit Category</th>
            <th>Specifications</th>
            <th>Rent / Month</th>
            <th>Deposit Required</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($unitsList)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-key fa-2x mb-2 d-block"></i>No units configured for this estate.</td></tr>
          <?php else: foreach ($unitsList as $u): 
            $stColors = ['vacant' => 'success', 'occupied' => 'primary', 'maintenance' => 'warning text-dark'];
            $sc = $stColors[$u['status']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-bold text-dark fs-6"><?= e($u['unit_no']) ?> <small class="text-muted d-block small">Floor: <?= $u['floor'] ?></small></td>
            <td class="fw-semibold text-dark"><?= e($u['property_name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= e($u['type']) ?></span></td>
            <td>
              <div class="small text-dark fw-semibold"><?= $u['bedrooms'] ?> BR | <?= $u['bathrooms'] ?> BA</div>
              <small class="text-muted"><?= number_format($u['size_sqm'], 1) ?> SQM</small>
            </td>
            <td class="fw-bold text-dark"><?= formatCurrency($u['rent']) ?></td>
            <td><?= formatCurrency($u['deposit']) ?></td>
            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($u['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $u['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delUnit(<?= $u['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="unitModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="unitId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="unitTitle"><i class="fas fa-door-open me-2"></i>Configure Property Unit</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Target Property Estate <span class="text-danger">*</span></label>
        <select name="property_id" id="unitPropertyId" class="form-select" required>
          <option value="">-- select property --</option>
          <?php foreach ($propertiesList as $p): ?>
          <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Unit Number / Tag <span class="text-danger">*</span></label>
        <input type="text" name="unit_no" id="unitNo" class="form-control" required placeholder="e.g. Apt A1, Shop G-03">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Unit Type / Category</label>
        <input type="text" name="type" id="unitType" class="form-control" placeholder="e.g. Apartment, Penthouse, Retail Shop, Office" value="Apartment">
      </div>
      <div class="col-3">
        <label class="form-label fw-semibold">Bedrooms</label>
        <input type="number" name="bedrooms" id="unitBedrooms" class="form-control" min="0" value="2">
      </div>
      <div class="col-3">
        <label class="form-label fw-semibold">Bathrooms</label>
        <input type="number" name="bathrooms" id="unitBathrooms" class="form-control" min="0" value="2">
      </div>
      <div class="col-3">
        <label class="form-label fw-semibold">Floor Level</label>
        <input type="number" name="floor" id="unitFloor" class="form-control" min="0" value="0">
      </div>
      <div class="col-3">
        <label class="form-label fw-semibold">Size (SQM)</label>
        <input type="number" name="size_sqm" id="unitSize" class="form-control" min="0" step="0.1" value="75">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Monthly Rent <span class="text-danger">*</span></label>
        <input type="number" name="rent" id="unitRent" class="form-control" required min="0" placeholder="e.g. 25000">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Security Deposit <span class="text-danger">*</span></label>
        <input type="number" name="deposit" id="unitDeposit" class="form-control" required min="0" placeholder="e.g. 25000">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Amenities / Facilities</label>
        <input type="text" name="amenities" id="unitAmenities" class="form-control" placeholder="e.g. Balcony, High speed lift, WiFi, Hot shower">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Unit Status</label>
        <select name="status" id="unitStatus" class="form-select">
          <option value="vacant">Vacant</option>
          <option value="occupied">Occupied</option>
          <option value="maintenance">Maintenance</option>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Unit</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delUnitForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delUnitId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('unitTitle').innerHTML = '<i class="fas fa-door-open me-2"></i>Configure Property Unit';
  document.getElementById('unitId').value = '0';
  document.getElementById('unitPropertyId').value = '';
  document.getElementById('unitNo').value = '';
  document.getElementById('unitType').value = 'Apartment';
  document.getElementById('unitBedrooms').value = '2';
  document.getElementById('unitBathrooms').value = '2';
  document.getElementById('unitFloor').value = '0';
  document.getElementById('unitSize').value = '75';
  document.getElementById('unitRent').value = '';
  document.getElementById('unitDeposit').value = '';
  document.getElementById('unitAmenities').value = '';
  document.getElementById('unitStatus').value = 'vacant';
}
function openEdit(id) {
  fetch('units.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('unitTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Unit Specifications';
      document.getElementById('unitId').value = data.id;
      document.getElementById('unitPropertyId').value = data.property_id;
      document.getElementById('unitNo').value = data.unit_no;
      document.getElementById('unitType').value = data.type;
      document.getElementById('unitBedrooms').value = data.bedrooms;
      document.getElementById('unitBathrooms').value = data.bathrooms;
      document.getElementById('unitFloor').value = data.floor;
      document.getElementById('unitSize').value = data.size_sqm;
      document.getElementById('unitRent').value = data.rent;
      document.getElementById('unitDeposit').value = data.deposit;
      document.getElementById('unitAmenities').value = data.amenities || '';
      document.getElementById('unitStatus').value = data.status;
      
      new bootstrap.Modal(document.getElementById('unitModal')).show();
    });
}
function delUnit(id) {
  Swal.fire({
    title: 'Delete Unit Listing?',
    text: 'Remove this unit and cancel any active tenant check-ins mapped to it?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delUnitId').value = id;
      document.getElementById('delUnitForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
