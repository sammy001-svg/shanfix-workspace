<?php
// ── CARYARD: Vehicle Inventory & CRUD ──────────────────────────
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

    if ($action === 'add' || $action === 'edit') {
        $id             = (int)($_POST['id'] ?? 0);
        $stockNo        = sanitize($_POST['stock_no']        ?? '');
        $make           = sanitize($_POST['make']            ?? '');
        $model          = sanitize($_POST['model']           ?? '');
        $year           = (int)($_POST['year']               ?? date('Y'));
        $color          = sanitize($_POST['color']           ?? '');
        $bodyType       = sanitize($_POST['body_type']       ?? '');
        $mileage        = (int)($_POST['mileage']           ?? 0);
        $engineCc       = (int)($_POST['engine_cc']          ?? 0);
        $transmission   = sanitize($_POST['transmission']    ?? 'automatic');
        $fuelType       = sanitize($_POST['fuel_type']       ?? 'petrol');
        $driveType      = sanitize($_POST['drive_type']      ?? '');
        $conditionGrade = sanitize($_POST['condition_grade'] ?? '');
        $purchasePrice  = (float)($_POST['purchase_price']  ?? 0.00);
        $sellingPrice   = (float)($_POST['selling_price']   ?? 0.00);
        $images         = sanitize($_POST['images']          ?? '');
        $status         = sanitize($_POST['status']          ?? 'available');

        if (empty($stockNo) || empty($make) || empty($model) || $year < 1900 || $sellingPrice <= 0) {
            setFlash('danger', 'Stock No, Make, Model, Year, and Selling Price are required.');
            redirect('vehicles.php');
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO caryard_vehicles (org_id, stock_no, make, model, year, color, body_type, mileage, engine_cc, transmission, fuel_type, drive_type, condition_grade, purchase_price, selling_price, images, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $stockNo, $make, $model, $year, $color, $bodyType, $mileage, $engineCc, $transmission, $fuelType, $driveType, $conditionGrade, $purchasePrice, $sellingPrice, $images, $status]);
            setFlash('success', 'Vehicle ' . e($make . ' ' . $model) . ' added to stock successfully.');
            logActivity('create', 'caryard', "Added vehicle stock '$stockNo' ($make $model)");
        } else {
            $stmt = $pdo->prepare("
                UPDATE caryard_vehicles
                SET stock_no=?, make=?, model=?, year=?, color=?, body_type=?, mileage=?, engine_cc=?, transmission=?, fuel_type=?, drive_type=?, condition_grade=?, purchase_price=?, selling_price=?, images=?, status=?
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$stockNo, $make, $model, $year, $color, $bodyType, $mileage, $engineCc, $transmission, $fuelType, $driveType, $conditionGrade, $purchasePrice, $sellingPrice, $images, $status, $id, $orgId]);
            setFlash('success', 'Vehicle details updated successfully.');
            logActivity('update', 'caryard', "Updated vehicle stock '$stockNo' (#$id)");
        }
        redirect('vehicles.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Safety locks: verify no linked sales or test drives exist
        $salesCount = countRows('caryard_sales', 'vehicle_id = ? AND org_id = ?', [$id, $orgId]);
        $testDrivesCount = countRows('caryard_test_drives', 'vehicle_id = ? AND org_id = ?', [$id, $orgId]);

        if ($salesCount > 0 || $testDrivesCount > 0) {
            setFlash('danger', 'Cannot delete this vehicle because it has ' . $salesCount . ' linked sales records and ' . $testDrivesCount . ' scheduled test drives.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM caryard_vehicles WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            setFlash('success', 'Vehicle deleted from stock registry.');
            logActivity('delete', 'caryard', "Deleted vehicle stock #$id");
        }
        redirect('vehicles.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Retrieve vehicles
$vehicles = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM caryard_vehicles WHERE org_id = ? ORDER BY added_at DESC");
    $stmt->execute([$orgId]);
    $vehicles = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats widgets metrics
$totalStock      = count($vehicles);
$availableCount  = countRows('caryard_vehicles', "org_id=? AND status='available'", [$orgId]);
$reservedCount   = countRows('caryard_vehicles', "org_id=? AND status='reserved'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-car me-2" style="color:<?= $moduleColor ?>"></i>Vehicle Inventory</h4>
    <p class="text-muted mb-0">Record vehicle features, adjust purchase/asking values, and view showroom listings</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#vehicleModal" onclick="openAddModal()">
    <i class="fas fa-plus me-1"></i>Add Vehicle
  </button>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon orange-bg" style="background:rgba(230,126,34,0.15);color:#e67e22"><i class="fas fa-car"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalStock ?></div><div class="stat-label">Total Stock Registry</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-car-side"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $availableCount ?></div><div class="stat-label">Available Showroom</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-bookmark"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $reservedCount ?></div><div class="stat-label">Reserved Vehicles</div></div>
    </div>
  </div>
</div>

<!-- Table list -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="vehiclesTable">
        <thead class="table-light">
          <tr>
            <th>Stock Code / VIN</th>
            <th>Brand Make & Model</th>
            <th>Year</th>
            <th>Transmission</th>
            <th>Fuel Type</th>
            <th class="text-end">Asking Price</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($vehicles as $v): ?>
          <tr>
            <td>
              <code class="bg-light px-2 py-1 rounded text-dark fw-bold"><?= e($v['stock_no']) ?></code>
              <div class="small text-muted mt-1">Grade: <strong><?= e($v['condition_grade'] ?: '—') ?></strong></div>
            </td>
            <td>
              <div class="fw-semibold text-dark"><i class="fas fa-car-side me-2 text-warning"></i><?= e($v['make'] . ' ' . $v['model']) ?></div>
              <div class="small text-muted"><?= e($v['color']) ?> • <?= number_format($v['mileage']) ?> km • <?= $v['engine_cc'] ?> CC</div>
            </td>
            <td><strong><?= e($v['year']) ?></strong></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst(e($v['transmission'])) ?></span></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst(e($v['fuel_type'])) ?></span></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$v['selling_price']) ?></td>
            <td>
              <?php if ($v['status'] === 'available'): ?>
                <span class="badge bg-success">Available</span>
              <?php elseif ($v['status'] === 'reserved'): ?>
                <span class="badge bg-warning text-dark">Reserved</span>
              <?php else: ?>
                <span class="badge bg-secondary">Sold</span>
              <?php endif; ?>
            </td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick="editVehicle(<?= e(json_encode($v)) ?>)" title="Edit Vehicle">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this vehicle from stock registry?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $v['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Vehicle">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="vehicleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="vehicleId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-car me-2"></i>Add Vehicle</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Stock Number <span class="text-danger">*</span></label>
              <input type="text" name="stock_no" id="vehicleStockNo" class="form-control" required placeholder="e.g. STK-1002">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Brand Make <span class="text-danger">*</span></label>
              <input type="text" name="make" id="vehicleMake" class="form-control" required placeholder="e.g. Toyota">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Model Title <span class="text-danger">*</span></label>
              <input type="text" name="model" id="vehicleModel" class="form-control" required placeholder="e.g. Prado">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Year <span class="text-danger">*</span></label>
              <input type="number" name="year" id="vehicleYear" class="form-control" required min="1900" max="<?= date('Y')+1 ?>" value="<?= date('Y') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Exterior Color</label>
              <input type="text" name="color" id="vehicleColor" class="form-control" placeholder="e.g. Pearl White">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Body Shape / Type</label>
              <input type="text" name="body_type" id="vehicleBodyType" class="form-control" placeholder="e.g. SUV, Sedan, Hatchback">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Mileage (KM) <span class="text-danger">*</span></label>
              <input type="number" name="mileage" id="vehicleMileage" class="form-control" required min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Engine Size (CC)</label>
              <input type="number" name="engine_cc" id="vehicleEngineCc" class="form-control" min="0" value="1998">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Transmission</label>
              <select name="transmission" id="vehicleTransmission" class="form-select">
                <option value="automatic">Automatic</option>
                <option value="manual">Manual</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Fuel Type</label>
              <select name="fuel_type" id="vehicleFuelType" class="form-select">
                <option value="petrol">Petrol</option>
                <option value="diesel">Diesel</option>
                <option value="electric">Electric</option>
                <option value="hybrid">Hybrid</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Drive System (WD)</label>
              <input type="text" name="drive_type" id="vehicleDriveType" class="form-control" placeholder="e.g. 4WD, AWD, FWD">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Condition Grade</label>
              <input type="text" name="condition_grade" id="vehicleGrade" class="form-control" placeholder="e.g. 4.5, Grade A">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Purchase Price (<?= CURRENCY ?>)</label>
              <input type="number" step="0.01" name="purchase_price" id="vehiclePurchasePrice" class="form-control" min="0" value="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Asking Selling Price (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="selling_price" id="vehicleSellingPrice" class="form-control" required min="0" value="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Showroom Status</label>
              <select name="status" id="vehicleStatus" class="form-select">
                <option value="available">Available</option>
                <option value="reserved">Reserved</option>
                <option value="sold">Sold</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Vehicle Images Preview URL</label>
              <input type="text" name="images" id="vehicleImages" class="form-control" placeholder="https://example.com/images/prado.jpg">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Vehicle</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
  $("#vehiclesTable").DataTable({pageLength:10,order:[[0,"desc"]],language:{emptyTable:"<div class=\'text-center py-5 text-muted\'><i class=\'fas fa-car-alt fa-3x mb-3 d-block\'></i>No vehicles currently in stock.</div>"}});
});

function openAddModal() {
  $("#modalAction").val("add");
  $("#modalTitle").html("<i class=\"fas fa-car me-2\"></i>Add Vehicle");
  $("#vehicleId").val("");
  $("#vehicleStockNo").val("");
  $("#vehicleMake").val("");
  $("#vehicleModel").val("");
  $("#vehicleYear").val("' . date('Y') . '");
  $("#vehicleColor").val("");
  $("#vehicleBodyType").val("");
  $("#vehicleMileage").val("0");
  $("#vehicleEngineCc").val("1998");
  $("#vehicleTransmission").val("automatic");
  $("#vehicleFuelType").val("petrol");
  $("#vehicleDriveType").val("");
  $("#vehicleGrade").val("");
  $("#vehiclePurchasePrice").val("0.00");
  $("#vehicleSellingPrice").val("0.00");
  $("#vehicleImages").val("");
  $("#vehicleStatus").val("available");
}

function editVehicle(v) {
  $("#modalAction").val("edit");
  $("#modalTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Vehicle Details");
  $("#vehicleId").val(v.id);
  $("#vehicleStockNo").val(v.stock_no || "");
  $("#vehicleMake").val(v.make || "");
  $("#vehicleModel").val(v.model || "");
  $("#vehicleYear").val(v.year || "' . date('Y') . '");
  $("#vehicleColor").val(v.color || "");
  $("#vehicleBodyType").val(v.body_type || "");
  $("#vehicleMileage").val(v.mileage || 0);
  $("#vehicleEngineCc").val(v.engine_cc || 1998);
  $("#vehicleTransmission").val(v.transmission || "automatic");
  $("#vehicleFuelType").val(v.fuel_type || "petrol");
  $("#vehicleDriveType").val(v.drive_type || "");
  $("#vehicleGrade").val(v.condition_grade || "");
  $("#vehiclePurchasePrice").val(v.purchase_price || "0.00");
  $("#vehicleSellingPrice").val(v.selling_price || "0.00");
  $("#vehicleImages").val(v.images || "");
  $("#vehicleStatus").val(v.status || "available");

  new bootstrap.Modal(document.getElementById("vehicleModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
