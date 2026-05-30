<?php
// ── CARYARD: Sales Logs & Status Automations ───────────────────
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
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id            = (int)($_POST['id'] ?? 0);
        $vehicleId     = (int)($_POST['vehicle_id']     ?? 0);
        $buyerName     = sanitize($_POST['buyer_name']     ?? '');
        $buyerPhone    = sanitize($_POST['buyer_phone']    ?? '');
        $buyerEmail    = sanitize($_POST['buyer_email']    ?? '');
        $idNumber      = sanitize($_POST['id_number']      ?? '');
        $salePrice     = (float)($_POST['sale_price']      ?? 0.00);
        $paymentMethod = sanitize($_POST['payment_method']  ?? 'Bank Transfer');
        $saleDate      = $_POST['sale_date']               ?? date('Y-m-d');
        $financing     = isset($_POST['financing']) ? 1 : 0;
        $financer      = sanitize($_POST['financer']        ?? '');
        $notes         = sanitize($_POST['notes']           ?? '');

        if ($vehicleId <= 0 || empty($buyerName) || $salePrice <= 0 || empty($saleDate)) {
            setFlash('danger', 'Vehicle, Buyer Name, Sale Price, and Sale Date are required.');
            redirect('sales.php');
        }

        if ($action === 'add') {
            // Check vehicle availability
            $stmt = $pdo->prepare("SELECT status FROM caryard_vehicles WHERE id=? AND org_id=?");
            $stmt->execute([$vehicleId, $orgId]);
            $status = $stmt->fetchColumn();

            if ($status === 'sold') {
                setFlash('danger', 'This vehicle has already been marked as sold.');
                redirect('sales.php');
            }

            $stmt = $pdo->prepare("
                INSERT INTO caryard_sales (org_id, vehicle_id, buyer_name, buyer_phone, buyer_email, id_number, sale_price, payment_method, sale_date, financing, financer, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $vehicleId, $buyerName, $buyerPhone, $buyerEmail, $idNumber, $salePrice, $paymentMethod, $saleDate, $financing, $financer, $notes]);

            // Auto-update vehicle status to sold
            $stmtVeh = $pdo->prepare("UPDATE caryard_vehicles SET status='sold' WHERE id=? AND org_id=?");
            $stmtVeh->execute([$vehicleId, $orgId]);

            setFlash('success', 'Sale logged successfully. Vehicle status updated to sold.');
            logActivity('create', 'caryard', "Logged vehicle sale for vehicle #$vehicleId to '$buyerName'");
        } else {
            // Fetch old vehicle ID
            $stmt = $pdo->prepare("SELECT vehicle_id FROM caryard_sales WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            $oldVehicleId = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                UPDATE caryard_sales
                SET vehicle_id=?, buyer_name=?, buyer_phone=?, buyer_email=?, id_number=?, sale_price=?, payment_method=?, sale_date=?, financing=?, financer=?, notes=?
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$vehicleId, $buyerName, $buyerPhone, $buyerEmail, $idNumber, $salePrice, $paymentMethod, $saleDate, $financing, $financer, $notes, $id, $orgId]);

            if ($oldVehicleId !== $vehicleId) {
                // Revert old vehicle status
                $stmtVeh = $pdo->prepare("UPDATE caryard_vehicles SET status='available' WHERE id=? AND org_id=?");
                $stmtVeh->execute([$oldVehicleId, $orgId]);

                // Update new vehicle status to sold
                $stmtVeh2 = $pdo->prepare("UPDATE caryard_vehicles SET status='sold' WHERE id=? AND org_id=?");
                $stmtVeh2->execute([$vehicleId, $orgId]);
            }

            setFlash('success', 'Sale details updated successfully.');
            logActivity('update', 'caryard', "Updated sale details for record #$id");
        }
        redirect('sales.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Fetch vehicle ID before deleting sale
        $stmt = $pdo->prepare("SELECT vehicle_id FROM caryard_sales WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $vehicleId = (int)$stmt->fetchColumn();

        if ($vehicleId > 0) {
            // Revert vehicle status to available
            $stmtVeh = $pdo->prepare("UPDATE caryard_vehicles SET status='available' WHERE id=? AND org_id=?");
            $stmtVeh->execute([$vehicleId, $orgId]);
        }

        $stmt = $pdo->prepare("DELETE FROM caryard_sales WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);

        setFlash('success', 'Sale record cancelled and deleted. Vehicle is available in showroom.');
        logActivity('delete', 'caryard', "Cancelled and deleted sale record #$id");
        redirect('sales.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Retrieve available/reserved vehicles
$showroomVehicles = [];
try {
    $stmt = $pdo->prepare("SELECT id, stock_no, make, model, year, selling_price, status FROM caryard_vehicles WHERE org_id=? ORDER BY stock_no ASC");
    $stmt->execute([$orgId]);
    $showroomVehicles = $stmt->fetchAll();
} catch (Exception $e) {}

// Retrieve sales logs
$sales = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, v.stock_no, v.make, v.model, v.year, v.color
        FROM caryard_sales s
        JOIN caryard_vehicles v ON s.vehicle_id = v.id
        WHERE s.org_id = ?
        ORDER BY s.sale_date DESC, s.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $sales = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats metrics
$totalSalesCount = count($sales);
$totalRevenueSum = 0.00;
$financedCount   = 0;
foreach ($sales as $s) {
    $totalRevenueSum += (float)$s['sale_price'];
    if ($s['financing'] == 1) $financedCount++;
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-handshake me-2" style="color:<?= $moduleColor ?>"></i>Sales Registry</h4>
    <p class="text-muted mb-0">Record sold vehicle invoices, track custom financing profiles, and view ledger revenues</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#saleModal" onclick="openAddModal()">
    <i class="fas fa-plus me-1"></i>Log New Sale
  </button>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon orange-bg" style="background:rgba(230,126,34,0.15);color:#e67e22"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenueSum) ?></div><div class="stat-label">Cumulative Turnover</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-clipboard-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalSalesCount ?></div><div class="stat-label">Vehicles Sold</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-landmark"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $financedCount ?></div><div class="stat-label">Financed Purchases</div></div>
    </div>
  </div>
</div>

<!-- Sales List Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="salesTable">
        <thead class="table-light">
          <tr>
            <th>Buyer Name</th>
            <th>Sold Vehicle</th>
            <th>Invoice Date</th>
            <th>Financing Type</th>
            <th>Payment Mode</th>
            <th class="text-end">Sale Price</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sales as $s): ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><i class="fas fa-user-tie me-2 text-warning"></i><?= e($s['buyer_name']) ?></div>
              <div class="small text-muted"><i class="fas fa-phone me-1"></i><?= e($s['buyer_phone'] ?: '—') ?> • ID: <?= e($s['id_number'] ?: '—') ?></div>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= e($s['make'] . ' ' . $s['model']) ?> (<?= $s['year'] ?>)</div>
              <div class="small text-muted"><code class="text-dark bg-light px-2 py-0.5 rounded">Stock: <?= e($s['stock_no']) ?></code></div>
            </td>
            <td><?= formatDate($s['sale_date']) ?></td>
            <td>
              <?php if ($s['financing'] == 1): ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-university me-1"></i><?= e($s['financer'] ?: 'Financed') ?></span>
              <?php else: ?>
                <span class="badge bg-light text-dark border">Cash Purchase</span>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($s['payment_method']) ?></span></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$s['sale_price']) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick="editSale(<?= e(json_encode($s)) ?>)" title="Edit Invoice">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Cancel vehicle invoice and revert showroom inventory status to available?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel Invoice">
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
<div class="modal fade" id="saleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="saleId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-handshake me-2"></i>Log New Sale</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Select Vehicle <span class="text-danger">*</span></label>
              <select name="vehicle_id" id="saleVehicle" class="form-select" required onchange="autofillPrice()">
                <option value="">-- Select Spot --</option>
                <?php foreach ($showroomVehicles as $sv): ?>
                <option value="<?= $sv['id'] ?>" data-price="<?= $sv['selling_price'] ?>" data-status="<?= $sv['status'] ?>">
                  <?= e($sv['stock_no'] . ' - ' . $sv['make'] . ' ' . $sv['model'] . ' (' . $sv['year'] . ') [' . ucfirst($sv['status']) . ']') ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Buyer Full Name <span class="text-danger">*</span></label>
              <input type="text" name="buyer_name" id="saleBuyerName" class="form-control" required placeholder="e.g. Samuel Kamau">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Buyer Phone</label>
              <input type="text" name="buyer_phone" id="saleBuyerPhone" class="form-control" placeholder="e.g. +254 712 345678">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Buyer Email</label>
              <input type="email" name="saleBuyerEmail" id="saleBuyerEmail" class="form-control" placeholder="e.g. buyer@example.com">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">National ID / Passport Number</label>
              <input type="text" name="id_number" id="saleIdNo" class="form-control" placeholder="e.g. ID-12345678">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Final Sale Price (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="sale_price" id="salePrice" class="form-control" required min="0" value="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Payment Mode</label>
              <select name="payment_method" id="salePaymentMethod" class="form-select">
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="Cash / Banker Cheque">Cash / Banker Cheque</option>
                <option value="M-Pesa Paybill">M-Pesa Paybill</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Invoice Date <span class="text-danger">*</span></label>
              <input type="date" name="sale_date" id="saleDate" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <div class="form-check form-switch mt-4">
                <input class="form-check-input" type="checkbox" name="financing" id="saleFinancing" onchange="toggleFinancing()">
                <label class="form-check-input-label fw-semibold" for="saleFinancing">Is Financed / Loan Purchase</label>
              </div>
            </div>
            <div class="col-md-8" id="financerField" style="display:none">
              <label class="form-label fw-semibold">Financing Bank / Institution</label>
              <input type="text" name="financer" id="saleFinancer" class="form-control" placeholder="e.g. NCBA Bank, KCB Asset Finance">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Invoicing Notes</label>
              <textarea name="notes" id="saleNotes" class="form-control" rows="2" placeholder="Provide trade-in info, warranty provisions, or discount waivers..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Invoice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
  $("#salesTable").DataTable({pageLength:10,order:[[2,"desc"]],language:{emptyTable:"<div class=\'text-center py-5 text-muted\'><i class=\'fas fa-handshake fa-3x mb-3 d-block\'></i>No vehicle sales logged yet.</div>"}});
});

function openAddModal() {
  $("#modalAction").val("add");
  $("#modalTitle").html("<i class=\"fas fa-handshake me-2\"></i>Log New Sale");
  $("#saleId").val("");
  $("#saleVehicle").val("").prop("disabled", false);
  $("#saleBuyerName").val("");
  $("#saleBuyerPhone").val("");
  $("#saleBuyerEmail").val("");
  $("#saleIdNo").val("");
  $("#salePrice").val("0.00");
  $("#salePaymentMethod").val("Bank Transfer");
  $("#saleDate").val("' . date('Y-m-d') . '");
  $("#saleFinancing").prop("checked", false);
  $("#saleFinancer").val("");
  $("#saleNotes").val("");
  toggleFinancing();
}

function autofillPrice() {
  var selected = $("#saleVehicle option:selected");
  if(selected.length && selected.data("price")) {
    $("#salePrice").val(selected.data("price"));
  }
}

function toggleFinancing() {
  if ($("#saleFinancing").is(":checked")) {
    $("#financerField").show();
  } else {
    $("#financerField").hide();
    $("#saleFinancer").val("");
  }
}

function editSale(s) {
  $("#modalAction").val("edit");
  $("#modalTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Invoice Details");
  $("#saleId").val(s.id);
  $("#saleVehicle").val(s.vehicle_id || "").prop("disabled", true);
  $("#saleBuyerName").val(s.buyer_name || "");
  $("#saleBuyerPhone").val(s.buyer_phone || "");
  $("#saleBuyerEmail").val(s.buyer_email || "");
  $("#saleIdNo").val(s.id_number || "");
  $("#salePrice").val(s.sale_price || "0.00");
  $("#salePaymentMethod").val(s.payment_method || "Bank Transfer");
  $("#saleDate").val(s.sale_date || "");
  
  if (s.financing == 1) {
    $("#saleFinancing").prop("checked", true);
    $("#saleFinancer").val(s.financer || "");
  } else {
    $("#saleFinancing").prop("checked", false);
    $("#saleFinancer").val("");
  }
  
  $("#saleNotes").val(s.notes || "");
  toggleFinancing();

  new bootstrap.Modal(document.getElementById("saleModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
