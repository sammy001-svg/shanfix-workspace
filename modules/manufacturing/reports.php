<?php
$moduleSlug  = 'manufacturing';
$moduleName  = 'Manufacturing';
$moduleIcon  = 'fas fa-industry';
$moduleColor = '#d35400';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'products.php',   'icon' => 'fas fa-box',            'label' => 'Products'],
    ['url' => 'materials.php',  'icon' => 'fas fa-cubes',          'label' => 'Raw Materials'],
    ['url' => 'bom.php',        'icon' => 'fas fa-list-alt',       'label' => 'Bill of Materials'],
    ['url' => 'production.php', 'icon' => 'fas fa-industry',       'label' => 'Production Orders'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// KPI: Total products manufactured (completed orders sum qty)
$totalManufactured = 0;
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM mfg_production_orders WHERE org_id=? AND status='completed'");
    $s->execute([$orgId]); $totalManufactured = (int)$s->fetchColumn();
} catch (Exception $e) {}

// Production value (qty * selling price)
$productionValue = 0;
try {
    $s = $pdo->prepare("
        SELECT COALESCE(SUM(o.quantity * p.selling_price), 0)
        FROM mfg_production_orders o
        LEFT JOIN mfg_products p ON o.product_id = p.id
        WHERE o.org_id=? AND o.status='completed'
    ");
    $s->execute([$orgId]); $productionValue = (float)$s->fetchColumn();
} catch (Exception $e) {}

// Raw materials used (approximate: from BOM * completed orders)
$rawMatsUsed = 0;
try {
    $s = $pdo->prepare("
        SELECT COALESCE(SUM(b.quantity_needed * o.quantity), 0)
        FROM mfg_production_orders o
        JOIN mfg_bom b ON b.product_id = o.product_id AND b.org_id = o.org_id
        WHERE o.org_id=? AND o.status='completed'
    ");
    $s->execute([$orgId]); $rawMatsUsed = (float)$s->fetchColumn();
} catch (Exception $e) {}

// Orders by status (doughnut)
$statusData = [];
try {
    $s = $pdo->prepare("
        SELECT status, COUNT(*) AS cnt
        FROM mfg_production_orders
        WHERE org_id=?
        GROUP BY status
    ");
    $s->execute([$orgId]);
    $statusData = $s->fetchAll();
} catch (Exception $e) {}

$statusColors = ['planned' => '#3498db', 'in_progress' => '#f39c12', 'completed' => '#27ae60', 'cancelled' => '#e74c3c'];
$doughLabels  = array_column($statusData, 'status');
$doughData    = array_column($statusData, 'cnt');
$doughColors  = array_map(fn($s) => $statusColors[$s] ?? '#999', $doughLabels);
$doughLabels  = array_map(fn($s) => ucwords(str_replace('_', ' ', $s)), $doughLabels);

// Monthly production (last 6 months, completed)
$chartLabels = []; $chartData = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $chartLabels[] = date('M Y', strtotime("-$i months"));
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM mfg_production_orders WHERE org_id=? AND status='completed' AND DATE_FORMAT(created_at,'%Y-%m')=?");
        $s->execute([$orgId, $month]); $chartData[] = (int)$s->fetchColumn();
    } catch (Exception $e) { $chartData[] = 0; }
}

// Materials consumption (top materials by qty consumed in completed orders)
$matsConsumption = [];
try {
    $stmt = $pdo->prepare("
        SELECT m.name AS material_name, m.unit, m.unit_cost,
               SUM(b.quantity_needed * o.quantity) AS total_consumed,
               SUM(b.quantity_needed * o.quantity * m.unit_cost) AS total_cost
        FROM mfg_production_orders o
        JOIN mfg_bom b ON b.product_id = o.product_id AND b.org_id = o.org_id
        JOIN mfg_raw_materials m ON m.id = b.material_id
        WHERE o.org_id=? AND o.status='completed'
        GROUP BY b.material_id, m.name, m.unit, m.unit_cost
        ORDER BY total_consumed DESC
        LIMIT 10
    ");
    $stmt->execute([$orgId]);
    $matsConsumption = $stmt->fetchAll();
} catch (Exception $e) {}

// Top products by production volume
$topProducts = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.name AS product_name, p.code, p.selling_price,
               COUNT(o.id) AS order_count,
               SUM(o.quantity) AS total_produced
        FROM mfg_production_orders o
        LEFT JOIN mfg_products p ON o.product_id = p.id
        WHERE o.org_id=? AND o.status='completed'
        GROUP BY o.product_id, p.name, p.code, p.selling_price
        ORDER BY total_produced DESC
        LIMIT 10
    ");
    $stmt->execute([$orgId]);
    $topProducts = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Manufacturing Reports</h4>
    <p class="text-muted mb-0">Production performance and resource consumption overview</p>
  </div>
  <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print</button>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-boxes"></i></div>
      <div class="stat-body"><div class="stat-value"><?= number_format($totalManufactured) ?></div><div class="stat-label">Units Manufactured</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-cubes"></i></div>
      <div class="stat-body"><div class="stat-value"><?= number_format($rawMatsUsed, 2) ?></div><div class="stat-label">Raw Materials Used</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($productionValue) ?></div><div class="stat-label">Production Value</div></div>
    </div>
  </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Monthly Production Volume (Last 6 Months)</h6></div>
      <div class="card-body"><canvas id="barChart" height="120"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Orders by Status</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <?php if (empty($statusData)): ?>
        <div class="text-center text-muted"><i class="fas fa-chart-pie fa-3x mb-2 opacity-25"></i><br>No orders yet</div>
        <?php else: ?>
        <canvas id="doughnutChart"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Materials Consumption Table -->
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header" style="background:<?= $moduleColor ?>15;border-bottom:2px solid <?= $moduleColor ?>">
        <h6 class="mb-0" style="color:<?= $moduleColor ?>"><i class="fas fa-cubes me-2"></i>Materials Consumption (Completed Orders)</h6>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th>Material</th><th class="text-end">Qty Used</th><th>Unit</th><th class="text-end">Total Cost</th></tr>
          </thead>
          <tbody>
            <?php if (empty($matsConsumption)): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">No data yet</td></tr>
            <?php else: foreach ($matsConsumption as $mc): ?>
            <tr>
              <td class="fw-semibold"><?= e($mc['material_name'] ?? '—') ?></td>
              <td class="text-end"><?= number_format((float)$mc['total_consumed'], 3) ?></td>
              <td class="text-muted small"><?= e($mc['unit'] ?? '') ?></td>
              <td class="text-end"><?= formatCurrency((float)$mc['total_cost']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Top Products -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header" style="background:<?= $moduleColor ?>15;border-bottom:2px solid <?= $moduleColor ?>">
        <h6 class="mb-0" style="color:<?= $moduleColor ?>"><i class="fas fa-trophy me-2"></i>Top Products by Volume</h6>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr><th>Product</th><th class="text-end">Orders</th><th class="text-end">Units</th><th class="text-end">Value</th></tr>
          </thead>
          <tbody>
            <?php if (empty($topProducts)): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">No completed orders yet</td></tr>
            <?php else: foreach ($topProducts as $i => $tp): ?>
            <tr>
              <td>
                <?php if ($i === 0): ?><i class="fas fa-trophy text-warning me-1"></i><?php endif; ?>
                <span class="fw-semibold"><?= e($tp['product_name'] ?? '—') ?></span>
                <div class="small text-muted"><?= e($tp['code'] ?? '') ?></div>
              </td>
              <td class="text-end"><?= (int)$tp['order_count'] ?></td>
              <td class="text-end fw-semibold"><?= number_format((int)$tp['total_produced']) ?></td>
              <td class="text-end text-success"><?= formatCurrency((float)$tp['selling_price'] * (int)$tp['total_produced']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <?php if (!empty($topProducts)): ?>
          <tfoot class="table-light">
            <tr>
              <th colspan="2">Total</th>
              <th class="text-end"><?= number_format(array_sum(array_column($topProducts, 'total_produced'))) ?></th>
              <th class="text-end"><?= formatCurrency(array_sum(array_map(fn($p) => (float)$p['selling_price'] * (int)$p['total_produced'], $topProducts))) ?></th>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
(function(){
  new Chart(document.getElementById("barChart"),{
    type:"bar",
    data:{
      labels:' . json_encode($chartLabels) . ',
      datasets:[{
        label:"Units Produced",
        data:' . json_encode($chartData) . ',
        backgroundColor:"' . $moduleColor . '",
        borderRadius:5
      }]
    },
    options:{responsive:true,plugins:{legend:{position:"top"}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
  });
' . (!empty($statusData) ? '
  new Chart(document.getElementById("doughnutChart"),{
    type:"doughnut",
    data:{
      labels:' . json_encode($doughLabels) . ',
      datasets:[{data:' . json_encode($doughData) . ',backgroundColor:' . json_encode($doughColors) . ',borderWidth:2}]
    },
    options:{responsive:true,plugins:{legend:{position:"bottom"}}}
  });
' : '') . '
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
