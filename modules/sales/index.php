<?php
$moduleSlug  = 'sales';
$moduleName  = 'Sales Management';
$moduleIcon  = 'fas fa-chart-line';
$moduleColor = '#1A8A4E';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'customers.php',   'icon' => 'fas fa-users',          'label' => 'Customers'],
    ['url' => 'orders.php',      'icon' => 'fas fa-shopping-cart',  'label' => 'Orders'],
    ['url' => 'quotes.php',      'icon' => 'fas fa-file-alt',       'label' => 'Quotes'],
    ['url' => 'products.php',    'icon' => 'fas fa-box',            'label' => 'Products'],
    ['url' => 'invoices.php',    'icon' => 'fas fa-file-invoice',   'label' => 'Invoices'],
    ['url' => 'fulfillment.php', 'icon' => 'fas fa-truck',          'label' => 'Fulfillment'],
    ['url' => 'commissions.php', 'icon' => 'fas fa-percent',        'label' => 'Commissions'],
    ['url' => 'targets.php',     'icon' => 'fas fa-bullseye',        'label' => 'Targets'],
    ['url' => 'returns.php',     'icon' => 'fas fa-undo-alt',        'label' => 'Returns'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-check-alt', 'label' => 'Payments'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalOrders   = countRows('sales_orders', 'org_id = ?', [$orgId]);
$pendingOrders = countRows('sales_orders', 'org_id = ? AND status = ?', [$orgId, 'pending']);
$totalCustomers= countRows('sales_customers', 'org_id = ?', [$orgId]);
$totalRevenue  = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM sales_orders WHERE org_id=? AND status='completed'");
    $stmt->execute([$orgId]);
    $totalRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Recent orders
$orders = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$orgId]);
    $orders = $stmt->fetchAll();
} catch (Exception $e) {}

// Monthly sales trend
$chartLabels = [];
$chartSales  = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $chartLabels[] = date('M Y', strtotime("-$i months"));
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM sales_orders WHERE org_id=? AND DATE_FORMAT(created_at,'%Y-%m')=?");
        $stmt->execute([$orgId, $month]);
        $chartSales[] = (float)$stmt->fetchColumn();
    } catch (Exception $e) { $chartSales[] = 0; }
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Track orders, revenue, and customers</p>
  </div>
  <a href="orders.php?action=add" class="btn btn-success"><i class="fas fa-plus me-2"></i>New Order</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-shopping-cart"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalOrders ?></div><div class="stat-label">Total Orders</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Revenue</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingOrders ?></div><div class="stat-label">Pending Orders</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalCustomers ?></div><div class="stat-label">Customers</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-line me-2 text-success"></i>Monthly Sales Trend (6 months)</h6></div>
      <div class="card-body"><canvas id="salesChart" height="80"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-shopping-cart me-2 text-success"></i>Recent Orders</h6>
    <a href="orders.php" class="btn btn-sm btn-outline-success">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="salesTable">
        <thead class="table-light">
          <tr><th>Order #</th><th>Customer</th><th>Date</th><th>Status</th><th class="text-end">Total</th></tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No orders found</td></tr>
          <?php else: foreach ($orders as $o): ?>
          <tr>
            <td class="fw-semibold"><?= e($o['order_number'] ?? '#' . $o['id']) ?></td>
            <td><?= e($o['customer_name'] ?? '—') ?></td>
            <td><?= formatDate($o['created_at'] ?? '') ?></td>
            <td><?= statusBadge($o['status'] ?? 'pending') ?></td>
            <td class="text-end"><?= formatCurrency((float)($o['total'] ?? 0)) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
new Chart(document.getElementById("salesChart"),{
  type:"line",
  data:{
    labels:' . json_encode($chartLabels) . ',
    datasets:[{label:"Sales",data:' . json_encode($chartSales) . ',borderColor:"#1A8A4E",backgroundColor:"rgba(26,138,78,.15)",tension:.4,fill:true,pointBackgroundColor:"#1A8A4E",pointRadius:5}]
  },
  options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}
});
$("#salesTable").DataTable({pageLength:10,order:[[2,"desc"]]});
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
