<?php
// ── Retail: Stock Adjustments ──────────────────────────────────
$moduleSlug  = 'retail';
$moduleName  = 'Retail & Wholesale';
$moduleIcon  = 'fas fa-store';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'categories.php','icon' => 'fas fa-tags',           'label' => 'Categories'],
    ['url' => 'products.php',  'icon' => 'fas fa-boxes',          'label' => 'Products'],
    ['url' => 'suppliers.php', 'icon' => 'fas fa-truck',          'label' => 'Suppliers'],
    ['url' => 'purchases.php', 'icon' => 'fas fa-file-invoice',   'label' => 'Purchase Orders'],
    ['url' => 'sales.php',     'icon' => 'fas fa-cash-register',  'label' => 'Sales / POS'],
    ['url' => 'stock.php',     'icon' => 'fas fa-warehouse',      'label' => 'Stock Adjustments'],
    ['url' => 'pricing.php',   'icon' => 'fas fa-tags',           'label' => 'Pricing Rules'],
    ['url' => 'customers.php', 'icon' => 'fas fa-users',           'label' => 'Customers'],
    ['url' => 'expenses.php',  'icon' => 'fas fa-receipt',         'label' => 'Expenses'],
    ['url' => 'transfers.php', 'icon' => 'fas fa-exchange-alt',    'label' => 'Stock Transfers'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];

    $productId = (int)($_POST['product_id'] ?? 0);
    $adjType   = sanitize($_POST['adjustment_type'] ?? 'addition');
    $qty       = (int)($_POST['quantity'] ?? 0);
    $reason    = sanitize($_POST['reason'] ?? '');
    $adjDate   = $_POST['adjustment_date'] ?? date('Y-m-d');

    if ($productId <= 0 || $qty <= 0) {
        setFlash('danger', 'Product and quantity are required.');
        redirect('stock.php');
    }

    try {
        $pdo->beginTransaction();

        // Get current stock
        $stmt = $pdo->prepare("SELECT stock_qty, product_name FROM retail_products WHERE id=? AND org_id=?");
        $stmt->execute([$productId, $orgId]);
        $prod = $stmt->fetch();
        if (!$prod) throw new Exception("Product not found.");

        $before  = (int)$prod['stock_qty'];
        $delta   = ($adjType === 'reduction') ? -$qty : $qty;
        $after   = $before + $delta;
        if ($after < 0) throw new Exception("Insufficient stock. Current: {$before}, Reduction: {$qty}");

        $stmt = $pdo->prepare("UPDATE retail_products SET stock_qty=? WHERE id=? AND org_id=?");
        $stmt->execute([$after, $productId, $orgId]);

        $stmt = $pdo->prepare("
            INSERT INTO retail_stock_adjustments (org_id, product_id, adjustment_type, quantity, quantity_before, quantity_after, reason, adjustment_date)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([$orgId, $productId, $adjType, $qty, $before, $after, $reason, $adjDate]);

        $pdo->commit();
        setFlash('success', "Stock adjusted for '{$prod['product_name']}': {$before} → {$after}");
        logActivity('create', 'retail', "Stock adjustment: {$prod['product_name']} {$adjType} {$qty} units");
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Error: ' . $e->getMessage());
    }
    redirect('stock.php');
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$products = [];
try {
    $stmt = $pdo->prepare("SELECT id, product_name, sku, stock_qty, reorder_level FROM retail_products WHERE org_id=? ORDER BY product_name");
    $stmt->execute([$orgId]);
    $products = $stmt->fetchAll();
} catch (Exception $e) {}

$adjustments = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, p.product_name, p.sku
        FROM retail_stock_adjustments a
        JOIN retail_products p ON p.id = a.product_id
        WHERE a.org_id=?
        ORDER BY a.adjustment_date DESC, a.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $adjustments = $stmt->fetchAll();
} catch (Exception $e) {}

$lowStock = array_filter($products, fn($p) => (int)$p['stock_qty'] <= max(1, (int)$p['reorder_level']));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-warehouse me-2" style="color:<?= $moduleColor ?>"></i>Stock Adjustments</h4>
    <p class="text-muted mb-0">Record stock additions, reductions, damages, and stock-takes</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#adjModal">
    <i class="fas fa-plus-circle me-1"></i>New Adjustment
  </button>
</div>

<?php if (!empty($lowStock)): ?>
<div class="alert alert-warning d-flex align-items-center mb-4">
  <i class="fas fa-exclamation-triangle me-3 fs-5"></i>
  <div><strong><?= count($lowStock) ?> product(s)</strong> are at or below reorder level.
  <?php foreach (array_slice(array_values($lowStock), 0, 3) as $ls): ?>
    <span class="badge bg-warning text-dark ms-1"><?= e($ls['product_name']) ?> (<?= (int)$ls['stock_qty'] ?>)</span>
  <?php endforeach; ?>
  <?= count($lowStock) > 3 ? ' …and more.' : '' ?>
  </div>
</div>
<?php endif; ?>

<!-- Current stock levels (compact) -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white py-3">
    <h6 class="mb-0 fw-semibold"><i class="fas fa-boxes me-2" style="color:<?= $moduleColor ?>"></i>Current Stock Levels</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0" id="stockTable">
        <thead class="table-light"><tr><th>Product</th><th>SKU</th><th class="text-center">Stock Qty</th><th class="text-center">Reorder Level</th><th class="text-center">Status</th></tr></thead>
        <tbody>
          <?php foreach ($products as $p):
            $status = (int)$p['stock_qty'] <= 0
              ? ['bg-danger','Out of Stock']
              : ((int)$p['stock_qty'] <= (int)$p['reorder_level']
                ? ['bg-warning text-dark','Low Stock']
                : ['bg-success','OK']);
          ?>
          <tr>
            <td class="fw-semibold"><?= e($p['product_name']) ?></td>
            <td class="text-muted small"><?= e($p['sku'] ?: '—') ?></td>
            <td class="text-center fw-bold"><?= (int)$p['stock_qty'] ?></td>
            <td class="text-center text-muted"><?= (int)$p['reorder_level'] ?></td>
            <td class="text-center"><span class="badge <?= $status[0] ?>"><?= $status[1] ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Adjustments History -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white py-3">
    <h6 class="mb-0 fw-semibold"><i class="fas fa-history me-2" style="color:<?= $moduleColor ?>"></i>Adjustment History</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="adjTable">
        <thead class="table-light"><tr><th>Date</th><th>Product</th><th class="text-center">Type</th><th class="text-center">Qty</th><th class="text-center">Before</th><th class="text-center">After</th><th>Reason</th></tr></thead>
        <tbody>
          <?php if (empty($adjustments)): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">No adjustments recorded.</td></tr>
          <?php else: foreach ($adjustments as $a): ?>
          <tr>
            <td><?= formatDate($a['adjustment_date']) ?></td>
            <td>
              <div class="fw-semibold"><?= e($a['product_name']) ?></div>
              <div class="small text-muted"><?= e($a['sku'] ?: '') ?></div>
            </td>
            <td class="text-center">
              <span class="badge <?= $a['adjustment_type']==='addition' ? 'bg-success' : 'bg-danger' ?>">
                <i class="fas <?= $a['adjustment_type']==='addition' ? 'fa-arrow-up' : 'fa-arrow-down' ?> me-1"></i>
                <?= ucfirst($a['adjustment_type']) ?>
              </span>
            </td>
            <td class="text-center fw-bold"><?= (int)$a['quantity'] ?></td>
            <td class="text-center text-muted"><?= (int)$a['quantity_before'] ?></td>
            <td class="text-center fw-semibold"><?= (int)$a['quantity_after'] ?></td>
            <td class="small text-muted"><?= e($a['reason'] ?: '—') ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Adjustment Modal -->
<div class="modal fade" id="adjModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-warehouse me-2"></i>New Stock Adjustment</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
            <select name="product_id" class="form-select" required>
              <option value="">-- Select Product --</option>
              <?php foreach ($products as $p): ?>
              <option value="<?= $p['id'] ?>"><?= e($p['product_name']) ?> (Stock: <?= (int)$p['stock_qty'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-semibold">Adjustment Type</label>
              <select name="adjustment_type" class="form-select">
                <option value="addition">Addition (Restock)</option>
                <option value="reduction">Reduction (Damage/Loss)</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Quantity <span class="text-danger">*</span></label>
              <input type="number" name="quantity" class="form-control" required min="1">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Adjustment Date</label>
              <input type="date" name="adjustment_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Reason</label>
              <input type="text" name="reason" class="form-control" placeholder="e.g. Stocktake, Damage">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Adjustment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#adjTable").DataTable({pageLength:15, order:[[0,"desc"]]});
    $("#stockTable").DataTable({pageLength:25, order:[[2,"asc"]]});
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
