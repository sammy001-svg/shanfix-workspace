<?php
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
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalProducts  = countRows('retail_products', 'org_id = ?', [$orgId]);
$totalSuppliers = countRows('retail_suppliers', 'org_id = ?', [$orgId]);
$lowStock       = countRows('retail_products', 'org_id = ? AND stock <= reorder_level AND status = ?', [$orgId, 'active']);
$stockValue     = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(stock * cost_price),0) FROM retail_products WHERE org_id=?");
    $stmt->execute([$orgId]);
    $stockValue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Low stock products
$lowStockProducts = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category, p.stock AS stock_qty, p.retail_price AS selling_price FROM retail_products p LEFT JOIN retail_categories c ON p.category_id = c.id WHERE p.org_id=? AND p.stock <= p.reorder_level AND p.status='active' ORDER BY p.stock ASC LIMIT 10");
    $stmt->execute([$orgId]);
    $lowStockProducts = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage products, suppliers, purchases, and stock</p>
  </div>
  <a href="products.php?action=add" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-plus me-2"></i>Add Product</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-box"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalProducts ?></div><div class="stat-label">Total Products</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-truck"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalSuppliers ?></div><div class="stat-label">Suppliers</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $lowStock ?></div><div class="stat-label">Low Stock Items</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-warehouse"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($stockValue) ?></div><div class="stat-label">Total Stock Value</div></div>
    </div>
  </div>
</div>

<?php if ($lowStock > 0): ?>
<div class="alert alert-danger d-flex align-items-center mb-4">
  <i class="fas fa-exclamation-triangle me-2"></i>
  <strong><?= $lowStock ?> product(s)</strong>&nbsp;are running low on stock. Reorder soon.
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2 text-danger"></i>Low Stock Products</h6>
    <a href="products.php" class="btn btn-sm btn-outline-success">All Products</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="retailTable">
        <thead class="table-light">
          <tr><th>SKU</th><th>Product</th><th>Category</th><th class="text-center">Stock Qty</th><th class="text-center">Reorder Level</th><th>Supplier</th><th class="text-end">Unit Price</th></tr>
        </thead>
        <tbody>
          <?php if (empty($lowStockProducts)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-check-circle fa-2x mb-2 d-block text-success"></i>All products are adequately stocked</td></tr>
          <?php else: foreach ($lowStockProducts as $p): ?>
          <tr>
            <td class="fw-semibold"><?= e($p['sku'] ?? '—') ?></td>
            <td><?= e($p['name'] ?? '—') ?></td>
            <td><?= e($p['category'] ?? '—') ?></td>
            <td class="text-center">
              <span class="badge <?= (int)($p['stock_qty'] ?? 0) === 0 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                <?= (int)($p['stock_qty'] ?? 0) ?>
              </span>
            </td>
            <td class="text-center"><?= (int)($p['reorder_level'] ?? 0) ?></td>
            <td><?= e($p['supplier_name'] ?? '—') ?></td>
            <td class="text-end"><?= formatCurrency((float)($p['selling_price'] ?? 0)) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#retailTable").DataTable({pageLength:10,order:[[3,"asc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
