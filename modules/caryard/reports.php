<?php
// ── CARYARD: Analytics Dashboard & Sales Performance ───────────
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
    ['url' => 'insurance.php',      'icon' => 'fas fa-shield-alt',     'label' => 'Insurance'],
    ['url' => 'parts.php',          'icon' => 'fas fa-cogs',           'label' => 'Parts & Spares'],
    ['url' => 'delivery.php',       'icon' => 'fas fa-truck-loading',  'label' => 'Deliveries'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// 1. 6-Month sales trends
$trendLabels = [];
$trendRevenue = [];
$trendCount = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(sale_date, '%b %Y') as month_name, 
               COALESCE(SUM(sale_price),0) as monthly_revenue, 
               COUNT(*) as monthly_count 
        FROM caryard_sales 
        WHERE org_id = ? 
        GROUP BY DATE_FORMAT(sale_date, '%Y-%m') 
        ORDER BY MIN(sale_date) ASC 
        LIMIT 6
    ");
    $stmt->execute([$orgId]);
    $trends = $stmt->fetchAll();
    foreach ($trends as $t) {
        $trendLabels[]  = $t['month_name'];
        $trendRevenue[] = (float)$t['monthly_revenue'];
        $trendCount[]   = (int)$t['monthly_count'];
    }
} catch (Exception $e) {}

// Fallback if empty
if (empty($trendLabels)) {
    $trendLabels  = [date('M Y')];
    $trendRevenue = [0.00];
    $trendCount   = [0];
}

// 2. Vehicle Status Proportions
$statusLabels = [];
$statusData   = [];
try {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as qty FROM caryard_vehicles WHERE org_id = ? GROUP BY status");
    $stmt->execute([$orgId]);
    $statuses = $stmt->fetchAll();
    foreach ($statuses as $st) {
        $statusLabels[] = ucfirst($st['status']);
        $statusData[]   = (int)$st['qty'];
    }
} catch (Exception $e) {}

if (empty($statusLabels)) {
    $statusLabels = ['Available', 'Reserved', 'Sold'];
    $statusData   = [0, 0, 0];
}

// 3. Top Brands / Makes sold
$makeLabels = [];
$makeData   = [];
try {
    $stmt = $pdo->prepare("
        SELECT v.make, COUNT(*) as qty 
        FROM caryard_sales s 
        JOIN caryard_vehicles v ON s.vehicle_id = v.id 
        WHERE s.org_id = ? 
        GROUP BY v.make 
        ORDER BY qty DESC 
        LIMIT 5
    ");
    $stmt->execute([$orgId]);
    $makes = $stmt->fetchAll();
    foreach ($makes as $m) {
        $makeLabels[] = $m['make'];
        $makeData[]   = (int)$m['qty'];
    }
} catch (Exception $e) {}

if (empty($makeLabels)) {
    $makeLabels = ['Toyota', 'Nissan', 'Mazda'];
    $makeData   = [0, 0, 0];
}

// 4. Cumulative Gross Profit margin calculation
$totalPurchaseValue = 0.00;
$totalSellingValue  = 0.00;
$grossProfitMargin  = 0.00;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(v.purchase_price),0) as cost, 
               COALESCE(SUM(s.sale_price),0) as sales
        FROM caryard_sales s
        JOIN caryard_vehicles v ON s.vehicle_id = v.id
        WHERE s.org_id = ?
    ");
    $stmt->execute([$orgId]);
    $profitRow = $stmt->fetch();
    $totalPurchaseValue = (float)$profitRow['cost'];
    $totalSellingValue  = (float)$profitRow['sales'];
    $grossProfitMargin  = $totalSellingValue - $totalPurchaseValue;
} catch (Exception $e) {}

// Detailed ledger breakdown
$ledger = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.sale_date, s.buyer_name, s.sale_price, v.stock_no, v.make, v.model, v.purchase_price,
               (s.sale_price - v.purchase_price) as margin
        FROM caryard_sales s
        JOIN caryard_vehicles v ON s.vehicle_id = v.id
        WHERE s.org_id = ?
        ORDER BY s.sale_date DESC
    ");
    $stmt->execute([$orgId]);
    $ledger = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Performance Reports</h4>
    <p class="text-muted mb-0">Evaluate vehicle trading gross profit margins, showroom status ratios, and historical invoice trends</p>
  </div>
  <a href="report-pdf.php" class="btn btn-outline-secondary"><i class="fas fa-file-pdf me-1"></i>Export PDF</a>
</div>

<!-- Valuation Widget Metrics -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-warehouse"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPurchaseValue) ?></div><div class="stat-label">Stock Purchase Outlay</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-cash-register"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalSellingValue) ?></div><div class="stat-label">Invoiced Turnover</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg" style="background:rgba(46,204,113,0.15);color:#2ecc71"><i class="fas fa-piggy-bank"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($grossProfitMargin) ?></div><div class="stat-label">Net Trading Profit Margin</div></div>
    </div>
  </div>
</div>

<!-- Charts Grid -->
<div class="row g-4 mb-4">
  <div class="col-xl-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-line me-2 text-warning"></i>Monthly Sales Revenue & Trends</h6>
      </div>
      <div class="card-body">
        <canvas id="trendsChart" height="260"></canvas>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-pie me-2 text-success"></i>Inventory Ratio</h6>
      </div>
      <div class="card-body">
        <canvas id="ratioChart" height="260"></canvas>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-handshake me-2 text-info"></i>Popular Brands</h6>
      </div>
      <div class="card-body">
        <canvas id="makesChart" height="260"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- Profit ledger ledger details -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white py-3">
    <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-file-invoice-dollar me-2 text-warning"></i>Stock Invoicing & Gross Profit Ledger</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="ledgerTable">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Stock No</th>
            <th>Vehicle Description</th>
            <th>Buyer / Client</th>
            <th class="text-end">Cost Price</th>
            <th class="text-end">Invoiced Value</th>
            <th class="text-end">Markup Margin</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ledger as $l): ?>
          <tr>
            <td><?= formatDate($l['sale_date']) ?></td>
            <td><code class="bg-light px-2 py-1 rounded text-dark fw-bold"><?= e($l['stock_no']) ?></code></td>
            <td><strong><?= e($l['make'] . ' ' . $l['model']) ?></strong></td>
            <td><strong><?= e($l['buyer_name']) ?></strong></td>
            <td class="text-end text-muted"><?= formatCurrency((float)$l['purchase_price']) ?></td>
            <td class="text-end fw-semibold text-dark"><?= formatCurrency((float)$l['sale_price']) ?></td>
            <td class="text-end fw-bold <?= $l['margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= ($l['margin'] >= 0 ? '+' : '') . formatCurrency((float)$l['margin']) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function(){
  $("#ledgerTable").DataTable({pageLength:10,order:[[0,"desc"]],language:{emptyTable:"<div class=\'text-center py-5 text-muted\'><i class=\'fas fa-file-invoice-dollar fa-3x mb-3 d-block\'></i>No entries logged in transaction ledger yet.</div>"}});
  
  // 1. Sales Trends
  var trendsCtx = document.getElementById("trendsChart").getContext("2d");
  new Chart(trendsCtx, {
    type: "bar",
    data: {
      labels: ' . json_encode($trendLabels) . ',
      datasets: [
        {
          label: "Invoiced Revenue (' . CURRENCY . ')",
          data: ' . json_encode($trendRevenue) . ',
          backgroundColor: "rgba(230, 126, 34, 0.75)",
          borderColor: "#e67e22",
          borderWidth: 2,
          yAxisID: "y"
        },
        {
          label: "Vehicles Sold",
          data: ' . json_encode($trendCount) . ',
          type: "line",
          borderColor: "#2c3e50",
          backgroundColor: "#2c3e50",
          fill: false,
          tension: 0.3,
          borderWidth: 3,
          yAxisID: "y1"
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          type: "linear",
          position: "left",
          ticks: {
            callback: function(value) { return "' . CURRENCY . ' " + value.toLocaleString(); }
          }
        },
        y1: {
          type: "linear",
          position: "right",
          grid: { drawOnChartArea: false },
          ticks: { stepSize: 1 }
        }
      }
    }
  });

  // 2. Inventory Ratio
  var ratioCtx = document.getElementById("ratioChart").getContext("2d");
  new Chart(ratioCtx, {
    type: "doughnut",
    data: {
      labels: ' . json_encode($statusLabels) . ',
      datasets: [{
        data: ' . json_encode($statusData) . ',
        backgroundColor: ["#2ecc71", "#f1c40f", "#95a5a6"]
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: "bottom" } }
    }
  });

  // 3. Top Makes
  var makesCtx = document.getElementById("makesChart").getContext("2d");
  new Chart(makesCtx, {
    type: "pie",
    data: {
      labels: ' . json_encode($makeLabels) . ',
      datasets: [{
        data: ' . json_encode($makeData) . ',
        backgroundColor: ["#e67e22", "#3498db", "#9b59b6", "#e74c3c", "#1abc9c"]
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: "bottom" } }
    }
  });
});
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
