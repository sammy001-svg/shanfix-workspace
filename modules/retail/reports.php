<?php
// ── Retail: Reports ───────────────────────────────────────────
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

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// KPIs
$totalStockValue = 0;
$lowStockCount   = 0;
$suppliersCount  = countRows('retail_suppliers', 'org_id=?', [$orgId]);
$posThisMonth    = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(stock * cost_price),0) FROM retail_products WHERE org_id=?");
    $stmt->execute([$orgId]);
    $totalStockValue = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM retail_products WHERE org_id=? AND stock <= reorder_level AND status='active'");
    $stmt->execute([$orgId]);
    $lowStockCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM retail_purchase_orders WHERE org_id=? AND DATE_FORMAT(order_date,'%Y-%m')=?");
    $stmt->execute([$orgId, date('Y-m')]);
    $posThisMonth = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Stock value by category (for doughnut chart)
$catLabels = [];
$catValues = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.name, COALESCE(SUM(p.stock * p.cost_price),0) AS stock_value
        FROM retail_categories c
        LEFT JOIN retail_products p ON p.category_id = c.id AND p.org_id = c.org_id
        WHERE c.org_id = ?
        GROUP BY c.id
        ORDER BY stock_value DESC
    ");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $row) {
        $catLabels[] = $row['name'];
        $catValues[] = round((float)$row['stock_value'], 2);
    }
} catch (Exception $e) {}

// POs by status (bar chart)
$poStatuses = ['draft','ordered','received','cancelled'];
$poCounts   = [];
foreach ($poStatuses as $s) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM retail_purchase_orders WHERE org_id=? AND status=?");
        $stmt->execute([$orgId, $s]);
        $poCounts[] = (int)$stmt->fetchColumn();
    } catch (Exception $e) { $poCounts[] = 0; }
}

// Top 10 products by stock value
$topProducts = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.name, p.stock, p.cost_price,
               (p.stock * p.cost_price) AS total_value
        FROM retail_products p
        WHERE p.org_id = ? AND p.status = 'active'
        ORDER BY total_value DESC
        LIMIT 10
    ");
    $stmt->execute([$orgId]);
    $topProducts = $stmt->fetchAll();
} catch (Exception $e) {}

// Low stock alert list
$lowStockProducts = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS category_name
        FROM retail_products p
        LEFT JOIN retail_categories c ON p.category_id = c.id
        WHERE p.org_id = ? AND p.stock <= p.reorder_level AND p.status = 'active'
        ORDER BY (p.reorder_level - p.stock) DESC
    ");
    $stmt->execute([$orgId]);
    $lowStockProducts = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Retail Reports</h4>
    <p class="text-muted mb-0">Inventory analytics and procurement overview</p>
  </div>
  <span class="text-muted small">As of <?= date('d M Y') ?></span>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1rem"><?= formatCurrency($totalStockValue) ?></div><div class="stat-label">Total Stock Value</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $lowStockCount ?></div><div class="stat-label">Low Stock Products</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-truck"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $suppliersCount ?></div><div class="stat-label">Total Suppliers</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-file-invoice"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $posThisMonth ?></div><div class="stat-label">POs This Month</div></div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Stock Value by Category</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <?php if (empty($catLabels)): ?>
        <p class="text-muted text-center py-4">No category data yet.</p>
        <?php else: ?>
        <canvas id="catChart" height="280"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Purchase Orders by Status</h6></div>
      <div class="card-body d-flex align-items-center">
        <canvas id="poChart" height="200" style="width:100%"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- Top 10 Products by Stock Value -->
<div class="card mb-4">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-trophy me-2" style="color:<?= $moduleColor ?>"></i>Top 10 Products by Stock Value</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Product</th>
            <th class="text-center">Stock</th>
            <th class="text-end">Cost Price</th>
            <th class="text-end">Total Value</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($topProducts)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No products found.</td></tr>
          <?php else: foreach ($topProducts as $i => $p): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td class="fw-semibold"><?= e($p['name']) ?></td>
            <td class="text-center"><?= number_format((int)$p['stock']) ?></td>
            <td class="text-end"><?= formatCurrency((float)$p['cost_price']) ?></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$p['total_value']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Low Stock Alert List -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2 text-warning"></i>Low Stock Alert</h6>
    <span class="badge bg-warning text-dark"><?= count($lowStockProducts) ?> products</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($lowStockProducts)): ?>
    <div class="text-center py-5 text-success">
      <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
      All products are sufficiently stocked.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>SKU</th>
            <th>Product</th>
            <th>Category</th>
            <th class="text-center">Stock</th>
            <th class="text-center">Reorder Level</th>
            <th class="text-center">Shortfall</th>
            <th class="text-center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lowStockProducts as $p): ?>
          <tr class="table-warning">
            <td class="small text-muted"><?= e($p['sku'] ?? '—') ?></td>
            <td class="fw-semibold"><?= e($p['name']) ?></td>
            <td class="small"><?= e($p['category_name'] ?? '—') ?></td>
            <td class="text-center"><span class="badge bg-danger"><?= (int)$p['stock'] ?></span></td>
            <td class="text-center"><?= (int)$p['reorder_level'] ?></td>
            <td class="text-center text-danger fw-bold"><?= max(0, (int)$p['reorder_level'] - (int)$p['stock']) ?></td>
            <td class="text-center">
              <a href="purchases.php" class="btn btn-sm btn-outline-primary">Order Now</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$catLabelsJson = json_encode($catLabels);
$catValuesJson = json_encode($catValues);
$poStatusJson  = json_encode(array_map('ucfirst', $poStatuses));
$poCountJson   = json_encode($poCounts);
$extraJs = <<<JS
<script>
(function(){
  var c = '$moduleColor';
  var catColors = ['#2980b9','#1abc9c','#e74c3c','#f39c12','#9b59b6','#1a8a4e','#e67e22','#3498db','#2ecc71','#e84393'];

  <?php if (!empty($catLabels)): ?>
  new Chart(document.getElementById('catChart'), {
    type: 'doughnut',
    data: {
      labels: $catLabelsJson,
      datasets: [{ data: $catValuesJson, backgroundColor: catColors.slice(0, $catLabelsJson.split(',').length) }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
  });
  <?php endif; ?>

  new Chart(document.getElementById('poChart'), {
    type: 'bar',
    data: {
      labels: $poStatusJson,
      datasets: [{
        label: 'Purchase Orders',
        data: $poCountJson,
        backgroundColor: ['#17a2b8','#ffc107','#28a745','#dc3545'],
        borderRadius: 6
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
  });
})();
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
