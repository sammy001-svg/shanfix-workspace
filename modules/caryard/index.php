<?php
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
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalVehicles     = countRows('caryard_vehicles', 'org_id = ?', [$orgId]);
$availableVehicles = countRows('caryard_vehicles', 'org_id = ? AND status = ?', [$orgId, 'available']);
$soldThisMonth     = countRows('caryard_sales', "org_id = ? AND DATE_FORMAT(sale_date,'%Y-%m') = ?", [$orgId, date('Y-m')]);
$monthRevenue      = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(sale_price),0) FROM caryard_sales WHERE org_id=? AND DATE_FORMAT(sale_date,'%Y-%m')=?");
    $stmt->execute([$orgId, date('Y-m')]);
    $monthRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Available vehicles
$vehicles = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM caryard_vehicles WHERE org_id=? AND status='available' ORDER BY added_at DESC LIMIT 10");
    $stmt->execute([$orgId]);
    $vehicles = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage vehicle inventory, sales, and test drives</p>
  </div>
  <a href="vehicles.php?action=add" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-plus me-2"></i>Add Vehicle</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon orange-bg" style="background:rgba(230,126,34,0.15);color:#e67e22"><i class="fas fa-car"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalVehicles ?></div><div class="stat-label">Total Vehicles</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-car-side"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $availableVehicles ?></div><div class="stat-label">Available</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-handshake"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $soldThisMonth ?></div><div class="stat-label">Sold This Month</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($monthRevenue) ?></div><div class="stat-label">Month Revenue</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-semibold"><i class="fas fa-car me-2" style="color:<?= $moduleColor ?>"></i>Available Showroom Vehicles</h6>
    <a href="vehicles.php" class="btn btn-sm" style="border-color:<?= $moduleColor ?>;color:<?= $moduleColor ?>">All Vehicles</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="carTable">
        <thead class="table-light">
          <tr><th>Stock No</th><th>Make & Model</th><th>Year</th><th>Color</th><th>Mileage</th><th>Grade</th><th class="text-end">Asking Price</th></tr>
        </thead>
        <tbody>
          <?php if (empty($vehicles)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-car-crash fa-2x mb-2 d-block"></i>No vehicles available in showroom</td></tr>
          <?php else: foreach ($vehicles as $v): ?>
          <tr>
            <td class="fw-semibold"><code class="text-dark bg-light px-2 py-0.5 rounded"><?= e($v['stock_no']) ?></code></td>
            <td class="fw-semibold text-dark"><i class="fas fa-car-side me-2 text-warning"></i><?= e(($v['make'] ?? '') . ' ' . ($v['model'] ?? '')) ?></td>
            <td><?= e($v['year'] ?? '—') ?></td>
            <td><?= e($v['color'] ?? '—') ?></td>
            <td><?= number_format((int)($v['mileage'] ?? 0)) ?> km</td>
            <td><span class="badge bg-light text-dark border"><?= e($v['condition_grade'] ?: '—') ?></span></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)($v['selling_price'] ?? 0)) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#carTable").DataTable({pageLength:10,order:[[6,"asc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>

