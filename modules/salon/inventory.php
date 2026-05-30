<?php
// ── Salon: Product Inventory ───────────────────────────────────
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
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_product') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = sanitize($_POST['name']        ?? '');
        $brand       = sanitize($_POST['brand']       ?? '');
        $category    = sanitize($_POST['category']    ?? '');
        $unit        = sanitize($_POST['unit']        ?? 'unit');
        $costPrice   = (float)($_POST['cost_price']   ?? 0);
        $sellingPrice= (float)($_POST['selling_price']?? 0);
        $stockQty    = (int)($_POST['stock_qty']      ?? 0);
        $reorderLevel= (int)($_POST['reorder_level']  ?? 5);

        if (empty($name)) {
            setFlash('danger', 'Product name is required.');
            redirect('inventory.php');
        }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE salon_inventory SET name=?, brand=?, category=?, unit=?, cost_price=?, selling_price=?, reorder_level=? WHERE id=? AND org_id=?")
                    ->execute([$name, $brand, $category, $unit, $costPrice, $sellingPrice, $reorderLevel, $id, $orgId]);
                setFlash('success', "Product '{$name}' updated.");
            } else {
                $pdo->prepare("INSERT INTO salon_inventory (org_id, name, brand, category, unit, cost_price, selling_price, stock_qty, reorder_level) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $name, $brand, $category, $unit, $costPrice, $sellingPrice, $stockQty, $reorderLevel]);
                setFlash('success', "Product '{$name}' added.");
                logActivity('create', 'salon', "Added inventory product: {$name}");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('inventory.php');
    }

    if ($action === 'log_movement') {
        $productId   = (int)($_POST['product_id']    ?? 0);
        $movType     = sanitize($_POST['movement_type']?? 'in');
        $qty         = (int)($_POST['quantity']       ?? 0);
        $reason      = sanitize($_POST['reason']      ?? '');

        if ($productId <= 0 || $qty <= 0) {
            setFlash('danger', 'Product and quantity required.');
            redirect('inventory.php');
        }
        try {
            $pdo->beginTransaction();
            $delta = ($movType === 'out') ? -$qty : $qty;
            $stmt = $pdo->prepare("SELECT stock_qty, name FROM salon_inventory WHERE id=? AND org_id=?");
            $stmt->execute([$productId, $orgId]);
            $prod = $stmt->fetch();
            if (!$prod) throw new Exception("Product not found.");

            $newQty = (int)$prod['stock_qty'] + $delta;
            if ($newQty < 0) throw new Exception("Insufficient stock. Current: {$prod['stock_qty']}");

            $pdo->prepare("UPDATE salon_inventory SET stock_qty=? WHERE id=? AND org_id=?")->execute([$newQty, $productId, $orgId]);
            $pdo->prepare("INSERT INTO salon_inventory_log (org_id, product_id, movement_type, quantity, quantity_before, quantity_after, reason) VALUES (?,?,?,?,?,?,?)")
                ->execute([$orgId, $productId, $movType, $qty, $prod['stock_qty'], $newQty, $reason]);

            $pdo->commit();
            setFlash('success', "Stock updated: {$prod['name']} {$prod['stock_qty']} → {$newQty}");
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('inventory.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$products = $movements = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM salon_inventory WHERE org_id=? ORDER BY name");
    $stmt->execute([$orgId]); $products = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT l.*, p.name AS product_name
        FROM salon_inventory_log l
        JOIN salon_inventory p ON p.id = l.product_id
        WHERE l.org_id=? ORDER BY l.created_at DESC LIMIT 50
    ");
    $stmt->execute([$orgId]); $movements = $stmt->fetchAll();
} catch (Exception $e) {}

$lowStock = array_filter($products, fn($p) => (int)$p['stock_qty'] <= (int)$p['reorder_level']);
$totalValue = array_sum(array_map(fn($p) => (int)$p['stock_qty'] * (float)$p['cost_price'], $products));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-boxes me-2" style="color:<?= $moduleColor ?>"></i>Product Inventory</h4>
    <p class="text-muted mb-0">Manage salon retail products, stock levels, and movements</p>
  </div>
  <div class="btn-group">
    <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#productModal" onclick="resetProdForm()">
      <i class="fas fa-plus-circle me-1"></i>Add Product
    </button>
    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#movModal">
      <i class="fas fa-exchange-alt me-1"></i>Log Movement
    </button>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(192,57,43,0.12);color:#c0392b"><i class="fas fa-boxes"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($products) ?></div><div class="stat-label">Products</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($lowStock) ?></div><div class="stat-label">Low Stock Items</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalValue) ?></div><div class="stat-label">Inventory Value</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="productsTable">
        <thead class="table-light"><tr><th>Product</th><th>Brand</th><th>Category</th><th class="text-center">Stock</th><th class="text-center">Reorder</th><th class="text-end">Cost</th><th class="text-end">Selling</th><th class="text-center">Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
          <?php if (empty($products)): ?>
          <tr><td colspan="9" class="text-center py-5 text-muted">No products added.</td></tr>
          <?php else: foreach ($products as $p):
            $isLow = (int)$p['stock_qty'] <= (int)$p['reorder_level'];
          ?>
          <tr>
            <td class="fw-semibold"><?= e($p['name']) ?></td>
            <td><?= e($p['brand'] ?: '—') ?></td>
            <td><?= e($p['category'] ?: '—') ?></td>
            <td class="text-center fw-bold <?= $isLow ? 'text-danger' : '' ?>"><?= (int)$p['stock_qty'] ?> <?= e($p['unit']) ?></td>
            <td class="text-center text-muted"><?= (int)$p['reorder_level'] ?></td>
            <td class="text-end"><?= formatCurrency((float)$p['cost_price']) ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$p['selling_price']) ?></td>
            <td class="text-center">
              <span class="badge <?= (int)$p['stock_qty'] <= 0 ? 'bg-danger' : ($isLow ? 'bg-warning text-dark' : 'bg-success') ?>">
                <?= (int)$p['stock_qty'] <= 0 ? 'Out of Stock' : ($isLow ? 'Low Stock' : 'OK') ?>
              </span>
            </td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary" onclick='openProdEdit(<?= json_encode($p) ?>)'><i class="fas fa-edit"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Recent Movements -->
<?php if (!empty($movements)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white py-3">
    <h6 class="mb-0 fw-semibold"><i class="fas fa-history me-2" style="color:<?= $moduleColor ?>"></i>Recent Stock Movements</h6>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Date</th><th>Product</th><th class="text-center">Type</th><th class="text-center">Qty</th><th class="text-center">Before → After</th><th>Reason</th></tr></thead>
      <tbody>
        <?php foreach ($movements as $mv): ?>
        <tr>
          <td class="small"><?= formatDate($mv['created_at']) ?></td>
          <td><?= e($mv['product_name']) ?></td>
          <td class="text-center"><span class="badge <?= $mv['movement_type']==='in' ? 'bg-success' : 'bg-danger' ?>"><?= ucfirst($mv['movement_type']) ?></span></td>
          <td class="text-center fw-bold"><?= (int)$mv['quantity'] ?></td>
          <td class="text-center text-muted small"><?= (int)$mv['quantity_before'] ?> → <?= (int)$mv['quantity_after'] ?></td>
          <td class="small text-muted"><?= e($mv['reason'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Product Modal -->
<div class="modal fade" id="productModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_product">
        <input type="hidden" name="id" id="prodId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="prodModalTitle"><i class="fas fa-plus me-2"></i>Add Product</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="prodName" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Brand</label>
              <input type="text" name="brand" id="prodBrand" class="form-control" placeholder="e.g. Loreal">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Category</label>
              <input type="text" name="category" id="prodCategory" class="form-control" placeholder="e.g. Shampoo">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Unit</label>
              <input type="text" name="unit" id="prodUnit" class="form-control" value="unit">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Initial Stock</label>
              <input type="number" name="stock_qty" id="prodStock" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Reorder Level</label>
              <input type="number" name="reorder_level" id="prodReorder" class="form-control" min="0" value="5">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Cost Price</label>
              <input type="number" step="0.01" name="cost_price" id="prodCost" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Selling Price</label>
              <input type="number" step="0.01" name="selling_price" id="prodSelling" class="form-control" min="0" value="0">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Movement Modal -->
<div class="modal fade" id="movModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="log_movement">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Log Stock Movement</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
              <select name="product_id" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (Stock: <?= (int)$p['stock_qty'] ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Type</label>
              <select name="movement_type" class="form-select">
                <option value="in">Stock In</option>
                <option value="out">Stock Out / Used</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Quantity <span class="text-danger">*</span></label>
              <input type="number" name="quantity" class="form-control" required min="1">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Reason</label>
              <input type="text" name="reason" class="form-control" placeholder="e.g. Restock from supplier, Used in appointment">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Log Movement</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#productsTable").DataTable({pageLength:15, order:[[0,"asc"]]});
});
function resetProdForm() {
    $("#prodId").val(0);
    $("#prodModalTitle").html('<i class="fas fa-plus me-2"></i>Add Product');
    document.querySelector("#productModal form").reset();
    $("#prodUnit").val("unit");
    $("#prodReorder").val(5);
}
function openProdEdit(p) {
    $("#prodId").val(p.id);
    $("#prodModalTitle").html('<i class="fas fa-edit me-2"></i>Edit Product');
    $("#prodName").val(p.name);
    $("#prodBrand").val(p.brand);
    $("#prodCategory").val(p.category);
    $("#prodUnit").val(p.unit);
    $("#prodStock").val(p.stock_qty);
    $("#prodReorder").val(p.reorder_level);
    $("#prodCost").val(p.cost_price);
    $("#prodSelling").val(p.selling_price);
    $("#productModal").modal("show");
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
