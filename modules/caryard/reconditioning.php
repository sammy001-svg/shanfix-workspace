<?php
// ── CARYARD: Reconditioning / Pre-sale Preparation Costs ───────
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
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
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
        $id        = (int)($_POST['id']         ?? 0);
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        $item      = sanitize($_POST['item']      ?? '');
        $cost      = (float)($_POST['cost']       ?? 0);
        $supplier  = sanitize($_POST['supplier']  ?? '');
        $workDate  = $_POST['work_date']           ?? date('Y-m-d');
        $status    = ($_POST['status'] ?? 'pending') === 'done' ? 'done' : 'pending';
        $notes     = sanitize($_POST['notes']     ?? '');

        if (!$vehicleId || !$item) {
            setFlash('danger', 'Vehicle and item description are required.');
            redirect('reconditioning.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE caryard_reconditioning SET vehicle_id=?,item=?,cost=?,supplier=?,work_date=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$vehicleId, $item, $cost, $supplier, $workDate, $status, $notes, $id, $orgId]);
            setFlash('success', 'Reconditioning item updated.');
            logActivity('update', 'caryard', "Updated reconditioning item #$id");
        } else {
            $pdo->prepare("INSERT INTO caryard_reconditioning (org_id,vehicle_id,item,cost,supplier,work_date,status,notes) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $vehicleId, $item, $cost, $supplier, $workDate, $status, $notes]);
            setFlash('success', "Reconditioning item '$item' added.");
            logActivity('create', 'caryard', "Added reconditioning: $item");
        }
        redirect('reconditioning.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM caryard_reconditioning WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Reconditioning item deleted.');
        logActivity('delete', 'caryard', "Deleted reconditioning item #$id");
        redirect('reconditioning.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterVehicle = (int)($_GET['vehicle'] ?? 0);
$filterStatus  = $_GET['status'] ?? '';

$where  = 'r.org_id = ?';
$params = [$orgId];
if ($filterVehicle) { $where .= ' AND r.vehicle_id = ?'; $params[] = $filterVehicle; }
if ($filterStatus)  { $where .= ' AND r.status = ?';     $params[] = $filterStatus; }

$items = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, CONCAT(v.make,' ',v.model,' (',v.year,')') AS vehicle_label, v.stock_no,
               v.purchase_price, v.selling_price
        FROM caryard_reconditioning r
        JOIN caryard_vehicles v ON r.vehicle_id = v.id
        WHERE $where
        ORDER BY r.work_date DESC
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} catch (Exception $e) {}

$vehicles = [];
try {
    $stmt = $pdo->prepare("SELECT id, stock_no, make, model, year FROM caryard_vehicles WHERE org_id=? ORDER BY make,model");
    $stmt->execute([$orgId]);
    $vehicles = $stmt->fetchAll();
} catch (Exception $e) {}

// Per-vehicle cost summaries (for the vehicle breakdown card)
$vehicleCosts = [];
try {
    $stmt = $pdo->prepare("
        SELECT v.id, CONCAT(v.make,' ',v.model,' (',v.year,')') AS vehicle_label, v.stock_no,
               v.purchase_price, v.selling_price,
               COALESCE(SUM(r.cost),0) AS recon_cost,
               COUNT(r.id) AS recon_count
        FROM caryard_vehicles v
        LEFT JOIN caryard_reconditioning r ON r.vehicle_id = v.id AND r.org_id = v.org_id
        WHERE v.org_id = ?
        GROUP BY v.id
        HAVING recon_count > 0
        ORDER BY recon_cost DESC
        LIMIT 10
    ");
    $stmt->execute([$orgId]);
    $vehicleCosts = $stmt->fetchAll();
} catch (Exception $e) {}

$totalReconCost = 0;
$pendingCost    = 0;
$doneCost       = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM caryard_reconditioning WHERE org_id=?");
    $stmt->execute([$orgId]);
    $totalReconCost = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM caryard_reconditioning WHERE org_id=? AND status='pending'");
    $stmt->execute([$orgId]);
    $pendingCost = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM caryard_reconditioning WHERE org_id=? AND status='done'");
    $stmt->execute([$orgId]);
    $doneCost = (float)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-wrench me-2" style="color:<?= $moduleColor ?>"></i>Reconditioning Costs</h4>
    <p class="text-muted mb-0">Track pre-sale preparation, repairs, and compliance costs per vehicle</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#reconModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Add Item
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(230,126,34,.12);color:#e67e22"><i class="fas fa-wrench"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalReconCost) ?></div><div class="stat-label">Total Recon Cost</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($pendingCost) ?></div><div class="stat-label">Pending Work Cost</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($doneCost) ?></div><div class="stat-label">Completed Work Cost</div></div>
    </div>
  </div>
</div>

<!-- Cost by Vehicle -->
<?php if (!empty($vehicleCosts)): ?>
<div class="card mb-4">
  <div class="card-header py-2">
    <h6 class="mb-0"><i class="fas fa-car me-2" style="color:<?= $moduleColor ?>"></i>Cost per Vehicle (Top 10)</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Vehicle</th>
            <th class="text-end">Purchase Price</th>
            <th class="text-end">Recon Cost</th>
            <th class="text-end">True Cost</th>
            <th class="text-end">Selling Price</th>
            <th class="text-end">Net Margin</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($vehicleCosts as $vc):
            $trueCost  = (float)$vc['purchase_price'] + (float)$vc['recon_cost'];
            $margin    = (float)$vc['selling_price'] - $trueCost;
          ?>
          <tr>
            <td>
              <div class="fw-semibold small"><?= e($vc['vehicle_label']) ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= e($vc['stock_no']) ?> &bull; <?= $vc['recon_count'] ?> items</div>
            </td>
            <td class="text-end small"><?= formatCurrency((float)$vc['purchase_price']) ?></td>
            <td class="text-end small text-danger"><?= formatCurrency((float)$vc['recon_cost']) ?></td>
            <td class="text-end small fw-semibold"><?= formatCurrency($trueCost) ?></td>
            <td class="text-end small"><?= formatCurrency((float)$vc['selling_price']) ?></td>
            <td class="text-end fw-bold <?= $margin >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= ($margin >= 0 ? '+' : '') . formatCurrency($margin) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1">Vehicle</label>
        <select name="vehicle" class="form-select form-select-sm">
          <option value="">All Vehicles</option>
          <?php foreach ($vehicles as $v): ?>
          <option value="<?= $v['id'] ?>" <?= $filterVehicle == $v['id'] ? 'selected' : '' ?>>
            <?= e($v['stock_no'].' — '.$v['make'].' '.$v['model'].' ('.$v['year'].')') ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="pending" <?= $filterStatus==='pending' ?'selected':'' ?>>Pending</option>
          <option value="done"    <?= $filterStatus==='done'    ?'selected':'' ?>>Done</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="reconditioning.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Items Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-wrench me-2" style="color:<?= $moduleColor ?>"></i>Reconditioning Items</h6>
    <span class="badge bg-secondary"><?= count($items) ?> items</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Vehicle</th>
            <th>Work Item</th>
            <th>Supplier / Garage</th>
            <th>Date</th>
            <th class="text-end">Cost</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
          <tr><td colspan="7" class="text-center py-5 text-muted">
            <i class="fas fa-wrench fa-2x mb-2 d-block"></i>No reconditioning items recorded yet.
          </td></tr>
          <?php else: foreach ($items as $r): ?>
          <tr>
            <td>
              <div class="fw-semibold small"><?= e($r['vehicle_label']) ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= e($r['stock_no']) ?></div>
            </td>
            <td>
              <div class="fw-semibold"><?= e($r['item']) ?></div>
              <?php if ($r['notes']): ?><div class="small text-muted"><?= e(mb_strimwidth($r['notes'], 0, 50, '…')) ?></div><?php endif; ?>
            </td>
            <td class="small"><?= e($r['supplier'] ?: '—') ?></td>
            <td><?= formatDate($r['work_date']) ?></td>
            <td class="text-end fw-semibold text-danger"><?= formatCurrency((float)$r['cost']) ?></td>
            <td>
              <?php if ($r['status'] === 'done'): ?>
              <span class="badge bg-success">Done</span>
              <?php else: ?>
              <span class="badge bg-warning text-dark">Pending</span>
              <?php endif; ?>
            </td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this item?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="reconModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="reconId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="reconTitle"><i class="fas fa-wrench me-2"></i>Add Reconditioning Item</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label>
              <select name="vehicle_id" id="reconVehicle" class="form-select" required>
                <option value="">— Select Vehicle —</option>
                <?php foreach ($vehicles as $v): ?>
                <option value="<?= $v['id'] ?>"><?= e($v['stock_no'].' — '.$v['make'].' '.$v['model'].' ('.$v['year'].')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Work Item <span class="text-danger">*</span></label>
              <input type="text" name="item" id="reconItem" class="form-control" required placeholder="e.g. Brake pads replacement, Respray, Engine tune-up">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Cost (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="cost" id="reconCost" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Supplier / Garage</label>
              <input type="text" name="supplier" id="reconSupplier" class="form-control" placeholder="Name or business">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Work Date</label>
              <input type="date" name="work_date" id="reconDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="reconStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="done">Done</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="reconNotes" class="form-control" rows="2" placeholder="Work details, parts used, warranty info..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Item</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("reconTitle").innerHTML   = "<i class=\"fas fa-wrench me-2\"></i>Add Reconditioning Item";
  document.getElementById("reconId").value      = 0;
  document.getElementById("reconVehicle").value = "";
  document.getElementById("reconItem").value    = "";
  document.getElementById("reconCost").value    = "0";
  document.getElementById("reconSupplier").value= "";
  document.getElementById("reconDate").value    = "' . date('Y-m-d') . '";
  document.getElementById("reconStatus").value  = "pending";
  document.getElementById("reconNotes").value   = "";
}
function openEdit(r) {
  document.getElementById("reconTitle").innerHTML   = "<i class=\"fas fa-edit me-2\"></i>Edit Reconditioning Item";
  document.getElementById("reconId").value      = r.id;
  document.getElementById("reconVehicle").value = r.vehicle_id || "";
  document.getElementById("reconItem").value    = r.item       || "";
  document.getElementById("reconCost").value    = r.cost       || "0";
  document.getElementById("reconSupplier").value= r.supplier   || "";
  document.getElementById("reconDate").value    = r.work_date  || "";
  document.getElementById("reconStatus").value  = r.status     || "pending";
  document.getElementById("reconNotes").value   = r.notes      || "";
  new bootstrap.Modal(document.getElementById("reconModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
