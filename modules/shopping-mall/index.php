<?php
$moduleSlug  = 'shopping-mall';
$moduleName  = 'Shopping Mall';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',    'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'floors.php',   'icon' => 'fas fa-layer-group',    'label' => 'Floors'],
    ['url' => 'shops.php',    'icon' => 'fas fa-store',          'label' => 'Shops'],
    ['url' => 'tenants.php',  'icon' => 'fas fa-user-tie',       'label' => 'Tenants'],
    ['url' => 'payments.php', 'icon' => 'fas fa-money-check',    'label' => 'Rent Payments'],
    ['url' => 'reports.php',  'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalShops    = countRows('mall_shops', 'org_id = ?', [$orgId]);
$occupiedShops = countRows('mall_shops', 'org_id = ? AND status = ?', [$orgId, 'occupied']);
$vacantShops   = countRows('mall_shops', 'org_id = ? AND status = ?', [$orgId, 'vacant']);
$monthRevenue  = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM mall_rent_payments WHERE org_id=? AND DATE_FORMAT(payment_date,'%Y-%m')=?");
    $stmt->execute([$orgId, date('Y-m')]);
    $monthRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// All shops
$shops = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM mall_shops WHERE org_id=? ORDER BY shop_number ASC LIMIT 15");
    $stmt->execute([$orgId]);
    $shops = $stmt->fetchAll();
} catch (Exception $e) {}

// Occupancy overview
$maintenanceShops = countRows('mall_shops', 'org_id = ? AND status = ?', [$orgId, 'maintenance']);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage shops, tenants, and monthly rent collections</p>
  </div>
  <a href="shops.php?action=add" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-plus me-2"></i>Add Shop</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-store-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalShops ?></div><div class="stat-label">Total Shops</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-door-closed"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $occupiedShops ?></div><div class="stat-label">Occupied</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-door-open"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $vacantShops ?></div><div class="stat-label">Vacant</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($monthRevenue) ?></div><div class="stat-label">Monthly Revenue</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Shop Occupancy</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="mallOccChart" height="220"></canvas></div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-info-circle me-2" style="color:<?= $moduleColor ?>"></i>Occupancy Summary</h6></div>
      <div class="card-body">
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1"><span>Occupancy Rate</span><strong><?= $totalShops > 0 ? round(($occupiedShops / $totalShops) * 100) : 0 ?>%</strong></div>
          <div class="progress" style="height:10px">
            <div class="progress-bar" style="width:<?= $totalShops > 0 ? round(($occupiedShops / $totalShops) * 100) : 0 ?>%;background:<?= $moduleColor ?>"></div>
          </div>
        </div>
        <div class="row g-2 mt-2">
          <div class="col-4 text-center">
            <div class="fs-4 fw-bold text-success"><?= $occupiedShops ?></div>
            <small class="text-muted">Occupied</small>
          </div>
          <div class="col-4 text-center">
            <div class="fs-4 fw-bold text-warning"><?= $vacantShops ?></div>
            <small class="text-muted">Vacant</small>
          </div>
          <div class="col-4 text-center">
            <div class="fs-4 fw-bold text-secondary"><?= $maintenanceShops ?></div>
            <small class="text-muted">Maintenance</small>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-store me-2" style="color:<?= $moduleColor ?>"></i>Mall Shops</h6>
    <a href="shops.php" class="btn btn-sm" style="border-color:<?= $moduleColor ?>;color:<?= $moduleColor ?>">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="mallTable">
        <thead class="table-light">
          <tr><th>Shop #</th><th>Shop Name</th><th>Floor/Wing</th><th>Tenant</th><th>Size (sqft)</th><th>Rent/Month</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php if (empty($shops)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No shops found</td></tr>
          <?php else: foreach ($shops as $sh): ?>
          <tr>
            <td class="fw-semibold"><?= e($sh['shop_number'] ?? '—') ?></td>
            <td><?= e($sh['shop_name'] ?? '—') ?></td>
            <td><?= e($sh['floor'] ?? $sh['wing'] ?? '—') ?></td>
            <td><?= e($sh['tenant_name'] ?? '<span class="text-muted">Vacant</span>') ?></td>
            <td><?= number_format((float)($sh['size_sqft'] ?? 0)) ?></td>
            <td><?= formatCurrency((float)($sh['monthly_rent'] ?? 0)) ?></td>
            <td><?= statusBadge($sh['status'] ?? 'vacant') ?></td>
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
  new Chart(document.getElementById("mallOccChart"),{
    type:"doughnut",
    data:{labels:["Occupied","Vacant","Maintenance"],datasets:[{data:[' . $occupiedShops . ',' . $vacantShops . ',' . $maintenanceShops . '],backgroundColor:["#1abc9c","#f39c12","#95a5a6"]}]},
    options:{responsive:true,plugins:{legend:{position:"bottom"}}}
  });
  $("#mallTable").DataTable({pageLength:15,order:[[0,"asc"]]});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
