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
        $name = sanitize($_POST['name'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $type = in_array($_POST['type'] ?? '', ['residential', 'commercial', 'mixed']) ? $_POST['type'] : 'residential';
        $totalUnits = (int)($_POST['total_units'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        $imageUrl = sanitize($_POST['image'] ?? '');

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE rental_properties SET name = ?, address = ?, type = ?, total_units = ?, image = ?, status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$name, $address, $type, $totalUnits, $imageUrl, $status, $id, $orgId]);
            setFlash('success', 'Property estate updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO rental_properties (org_id, name, address, type, total_units, image, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $name, $address, $type, $totalUnits, $imageUrl, $status]);
            setFlash('success', "Property '$name' created successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'rental', "Property Estate: $name (Type: $type)");
        redirect('properties.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM rental_properties WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Property estate removed from roster.');
        redirect('properties.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fType = $_GET['type'] ?? '';
$where = 'org_id = ?';
$params = [$orgId];

if ($fType !== '') {
    $where .= ' AND type = ?';
    $params[] = $fType;
}

$propertiesList = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM rental_properties WHERE $where ORDER BY name ASC");
    $stmt->execute($params);
    $propertiesList = $stmt->fetchAll();
} catch (Exception $e) {}

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $pid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM rental_properties WHERE id = ? AND org_id = ?");
        $stmt->execute([$pid, $orgId]);
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
    <h4 class="mb-1"><i class="fas fa-building me-2" style="color:<?= $moduleColor ?>"></i>Property Estates</h4>
    <p class="text-muted mb-0">Search, establish, and configure residential, commercial, or mixed-use rental estates</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#propModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Create Property Estate</button>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Estate Classification</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <option value="residential" <?= $fType === 'residential' ? 'selected' : '' ?>>Residential</option>
          <option value="commercial" <?= $fType === 'commercial' ? 'selected' : '' ?>>Commercial</option>
          <option value="mixed" <?= $fType === 'mixed' ? 'selected' : '' ?>>Mixed-Use</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="properties.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="row g-3">
  <?php if (empty($propertiesList)): ?>
  <div class="col-12"><div class="card py-5 text-center text-muted"><i class="fas fa-building fa-3x mb-2"></i>No property estates configured yet.</div></div>
  <?php else: foreach ($propertiesList as $p): ?>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100">
      <div style="height:160px;background:#e9ecef;overflow:hidden;position:relative;">
        <?php if ($p['image']): ?>
        <img src="<?= e($p['image']) ?>" style="width:100%;height:100%;object-fit:cover;">
        <?php else: ?>
        <div class="d-flex align-items-center justify-content-center h-100 text-muted"><i class="fas fa-image fa-3x"></i></div>
        <?php endif; ?>
        <span class="badge bg-<?= $p['type'] === 'residential' ? 'success' : ($p['type'] === 'commercial' ? 'primary' : 'warning text-dark') ?> position-absolute top-2 start-2" style="font-size:11px;">
          <?= ucfirst($p['type']) ?>
        </span>
      </div>
      <div class="card-body">
        <h5 class="card-title text-dark fw-bold mb-1"><?= e($p['name']) ?></h5>
        <p class="text-muted small mb-2"><i class="fas fa-map-marker-alt me-1 text-danger"></i><?= e($p['address'] ?: 'No address specified') ?></p>
        <div class="d-flex align-items-center justify-content-between bg-light p-2 rounded small">
          <span class="fw-semibold text-muted">Configured Units:</span>
          <span class="fw-bold text-dark fs-6"><?= $p['total_units'] ?> units</span>
        </div>
      </div>
      <div class="card-footer bg-white d-flex align-items-center justify-content-between border-top-0 pt-0 pb-3">
        <span class="badge bg-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($p['status']) ?></span>
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-primary" onclick="openEdit(<?= $p['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-outline-danger" onclick="delProp(<?= $p['id'] ?>, '<?= e($p['name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Modal -->
<div class="modal fade" id="propModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="propId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="propTitle"><i class="fas fa-building me-2"></i>Create Property Estate</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Property Name / Title <span class="text-danger">*</span></label>
        <input type="text" name="name" id="propName" class="form-control" required placeholder="e.g. Sunset Heights Apartments">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Property Physical Address <span class="text-danger">*</span></label>
        <input type="text" name="address" id="propAddress" class="form-control" required placeholder="e.g. Ring Road, Kilimani, Nairobi">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Classification Type <span class="text-danger">*</span></label>
        <select name="type" id="propType" class="form-select" required>
          <option value="residential">Residential</option>
          <option value="commercial">Commercial</option>
          <option value="mixed">Mixed-Use</option>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Total Target Units <span class="text-danger">*</span></label>
        <input type="number" name="total_units" id="propUnits" class="form-control" required min="1" value="10">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Property Outer Image URL</label>
        <input type="url" name="image" id="propImage" class="form-control" placeholder="e.g. https://example.com/sunset.jpg">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Estate Status</label>
        <select name="status" id="propStatus" class="form-select">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Property</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delPropForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delPropId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('propTitle').innerHTML = '<i class="fas fa-building me-2"></i>Create Property Estate';
  document.getElementById('propId').value = '0';
  document.getElementById('propName').value = '';
  document.getElementById('propAddress').value = '';
  document.getElementById('propType').value = 'residential';
  document.getElementById('propUnits').value = '10';
  document.getElementById('propImage').value = '';
  document.getElementById('propStatus').value = 'active';
}
function openEdit(id) {
  fetch('properties.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('propTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Property Estate';
      document.getElementById('propId').value = data.id;
      document.getElementById('propName').value = data.name;
      document.getElementById('propAddress').value = data.address;
      document.getElementById('propType').value = data.type;
      document.getElementById('propUnits').value = data.total_units;
      document.getElementById('propImage').value = data.image || '';
      document.getElementById('propStatus').value = data.status;
      
      new bootstrap.Modal(document.getElementById('propModal')).show();
    });
}
function delProp(id, name) {
  Swal.fire({
    title: 'Delete Property Estate?',
    text: 'Remove "' + name + '"? This will delete all unit allocations and tenant lease records linked inside!',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete estate'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delPropId').value = id;
      document.getElementById('delPropForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
