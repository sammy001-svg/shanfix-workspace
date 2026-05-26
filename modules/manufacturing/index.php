<?php
$moduleSlug  = 'manufacturing';
$moduleName  = 'Manufacturing';
$moduleIcon  = 'fas fa-industry';
$moduleColor = '#d35400';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'products.php',    'icon' => 'fas fa-box',            'label' => 'Products'],
    ['url' => 'materials.php',   'icon' => 'fas fa-cubes',          'label' => 'Raw Materials'],
    ['url' => 'bom.php',         'icon' => 'fas fa-list-alt',       'label' => 'Bill of Materials'],
    ['url' => 'production.php',  'icon' => 'fas fa-industry',       'label' => 'Production Orders'],
    ['url' => 'workorders.php',  'icon' => 'fas fa-clipboard-list', 'label' => 'Work Orders'],
    ['url' => 'machines.php',    'icon' => 'fas fa-cogs',           'label' => 'Machines'],
    ['url' => 'quality.php',     'icon' => 'fas fa-check-circle',   'label' => 'Quality Control'],
    ['url' => 'suppliers.php',   'icon' => 'fas fa-truck',           'label' => 'Suppliers'],
    ['url' => 'inventory.php',   'icon' => 'fas fa-warehouse',       'label' => 'Inventory'],
    ['url' => 'procurement.php', 'icon' => 'fas fa-shopping-basket', 'label' => 'Procurement'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalProducts   = countRows('mfg_products', 'org_id = ?', [$orgId]);
$totalMaterials  = countRows('mfg_raw_materials', 'org_id = ?', [$orgId]);
$activeOrders    = countRows('mfg_production_orders', 'org_id = ? AND status IN (?,?)', [$orgId, 'in_progress', 'pending']);
$completedOrders = countRows('mfg_production_orders', 'org_id = ? AND status = ?', [$orgId, 'completed']);

// Recent production orders
$orders = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM mfg_production_orders WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$orgId]);
    $orders = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage production orders, materials, and BOM</p>
  </div>
  <a href="production.php?action=add" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-plus me-2"></i>New Order</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-box"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalProducts ?></div><div class="stat-label">Products</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-cubes"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalMaterials ?></div><div class="stat-label">Raw Materials</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-cogs fa-spin-slow"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeOrders ?></div><div class="stat-label">Active Orders</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $completedOrders ?></div><div class="stat-label">Completed</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-cogs me-2" style="color:<?= $moduleColor ?>"></i>Recent Production Orders</h6>
    <a href="production.php" class="btn btn-sm" style="border-color:<?= $moduleColor ?>;color:<?= $moduleColor ?>">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="mfgTable">
        <thead class="table-light">
          <tr><th>Order #</th><th>Product</th><th>Qty</th><th>Start Date</th><th>End Date</th><th>Priority</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No production orders found</td></tr>
          <?php else: foreach ($orders as $o): ?>
          <tr>
            <td class="fw-semibold"><?= e($o['order_number'] ?? '#' . $o['id']) ?></td>
            <td><?= e($o['product_name'] ?? '—') ?></td>
            <td><?= (int)($o['quantity'] ?? 0) ?></td>
            <td><?= formatDate($o['start_date'] ?? '') ?></td>
            <td><?= formatDate($o['end_date'] ?? '') ?></td>
            <td>
              <?php $priority = $o['priority'] ?? 'normal';
              $pClass = ['high' => 'danger', 'medium' => 'warning', 'low' => 'secondary', 'normal' => 'info'][$priority] ?? 'secondary'; ?>
              <span class="badge bg-<?= $pClass ?>"><?= ucfirst($priority) ?></span>
            </td>
            <td><?= statusBadge($o['status'] ?? 'pending') ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#mfgTable").DataTable({pageLength:10,order:[[3,"desc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
