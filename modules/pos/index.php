<?php
$moduleSlug  = 'pos';
$moduleName  = 'Point of Sale';
$moduleIcon  = 'fas fa-cash-register';
$moduleColor = '#f39c12';
$moduleNav   = [
    ['url' => 'index.php',   'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'terminal.php','icon' => 'fas fa-cash-register',  'label' => 'POS Terminal'],
    ['url' => 'products.php','icon' => 'fas fa-box',            'label' => 'Products'],
    ['url' => 'sales.php',   'icon' => 'fas fa-list',           'label' => 'Sales History'],
    ['url' => 'reports.php', 'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalProducts = countRows('pos_products', 'org_id = ?', [$orgId]);
$lowStock      = countRows('pos_products', 'org_id = ? AND stock <= reorder_level', [$orgId]);
$todaySales    = countRows('pos_sales', 'org_id = ? AND DATE(created_at) = CURDATE()', [$orgId]);
$todayRevenue  = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM pos_sales WHERE org_id=? AND DATE(created_at)=CURDATE()");
    $stmt->execute([$orgId]);
    $todayRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Recent sales
$sales = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM pos_sales WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$orgId]);
    $sales = $stmt->fetchAll();
} catch (Exception $e) {}

// Sales by hour today
$hours      = [];
$hourlyData = [];
for ($h = 7; $h <= 22; $h++) {
    $hours[] = date('h A', mktime($h, 0, 0));
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pos_sales WHERE org_id=? AND DATE(created_at)=CURDATE() AND HOUR(created_at)=?");
        $stmt->execute([$orgId, $h]);
        $hourlyData[] = (int)$stmt->fetchColumn();
    } catch (Exception $e) { $hourlyData[] = 0; }
}

// Payment methods
$methods     = ['cash', 'mpesa', 'card', 'other'];
$methodCnts  = [];
foreach ($methods as $m) {
    try {
        $methodCnts[] = countRows('pos_sales', 'org_id = ? AND payment_method = ? AND DATE(created_at) = CURDATE()', [$orgId, $m]);
    } catch (Exception $e) { $methodCnts[] = 0; }
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Today's sales overview — <?= date('d M Y') ?></p>
  </div>
  <a href="terminal.php" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-cash-register me-2"></i>Open POS</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-receipt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $todaySales ?></div><div class="stat-label">Today Sales</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($todayRevenue) ?></div><div class="stat-label">Today Revenue</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-box"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalProducts ?></div><div class="stat-label">Products</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $lowStock ?></div><div class="stat-label">Low Stock</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Sales by Hour (Today)</h6></div>
      <div class="card-body"><canvas id="hourChart" height="120"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Payment Methods</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="payChart" height="220"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>Recent Sales</h6>
    <a href="sales.php" class="btn btn-sm btn-outline-warning">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="posTable">
        <thead class="table-light">
          <tr><th>Receipt #</th><th>Items</th><th>Payment</th><th>Cashier</th><th>Time</th><th class="text-end">Total</th></tr>
        </thead>
        <tbody>
          <?php if (empty($sales)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No sales found</td></tr>
          <?php else: foreach ($sales as $s): ?>
          <tr>
            <td class="fw-semibold"><?= e($s['receipt_number'] ?? '#' . $s['id']) ?></td>
            <td><?= (int)($s['items_count'] ?? 0) ?></td>
            <td><span class="badge bg-secondary"><?= ucfirst($s['payment_method'] ?? 'cash') ?></span></td>
            <td><?= e($s['cashier_name'] ?? '—') ?></td>
            <td><?= formatDateTime($s['created_at'] ?? '') ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)($s['total'] ?? 0)) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
(function(){
  new Chart(document.getElementById("hourChart"),{
    type:"bar",
    data:{labels:' . json_encode($hours) . ',datasets:[{label:"Sales",data:' . json_encode($hourlyData) . ',backgroundColor:"#f39c12",borderRadius:5}]},
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
  });
  new Chart(document.getElementById("payChart"),{
    type:"doughnut",
    data:{labels:["Cash","M-Pesa","Card","Other"],datasets:[{data:' . json_encode($methodCnts) . ',backgroundColor:["#1A8A4E","#0B2D4E","#f39c12","#8e44ad"]}]},
    options:{responsive:true,plugins:{legend:{position:"bottom"}}}
  });
  $("#posTable").DataTable({pageLength:10,order:[[4,"desc"]]});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
