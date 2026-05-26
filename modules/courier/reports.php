<?php
$moduleSlug  = 'courier';
$moduleName  = 'Courier Management';
$moduleIcon  = 'fas fa-shipping-fast';
$moduleColor = '#1565c0';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'couriers.php',   'icon' => 'fas fa-box',             'label' => 'Couriers'],
    ['url' => 'tracking.php',   'icon' => 'fas fa-map-marker-alt',  'label' => 'Tracking'],
    ['url' => 'manifest.php',   'icon' => 'fas fa-clipboard-list',  'label' => 'Manifests'],
    ['url' => 'delivery.php',   'icon' => 'fas fa-truck',           'label' => 'Deliveries'],
    ['url' => 'routes.php',     'icon' => 'fas fa-route',           'label' => 'Routes'],
    ['url' => 'payments.php',   'icon' => 'fas fa-credit-card',     'label' => 'Payments'],
    ['url' => 'agents.php',     'icon' => 'fas fa-user-tie',        'label' => 'Agents'],
    ['url' => 'agreements.php', 'icon' => 'fas fa-file-contract',   'label' => 'Agreements'],
    ['url' => 'setup.php',      'icon' => 'fas fa-cog',             'label' => 'Setup'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$user  = currentUser();
$orgId = (int)$user['org_id'];

$fFrom = $_GET['from'] ?? date('Y-m-01');
$fTo   = $_GET['to']   ?? date('Y-m-d');

// ---- Summary stats for period ----
$totalCouriers   = 0;
$delivered       = 0;
$failed          = 0;
$inTransit       = 0;
$totalRevenue    = 0;
$clearedRevenue  = 0;
$pendingRevenue  = 0;

try {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM couriers WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ? GROUP BY status");
    $stmt->execute([$orgId, $fFrom, $fTo]);
    foreach ($stmt->fetchAll() as $row) {
        $totalCouriers += $row['cnt'];
        if ($row['status'] === 'delivered') $delivered = $row['cnt'];
        if ($row['status'] === 'failed')    $failed    = $row['cnt'];
        if (in_array($row['status'], ['in_transit','out_for_delivery','picked_up'])) $inTransit += $row['cnt'];
    }
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(c.price),0) FROM couriers c WHERE c.org_id=? AND DATE(c.created_at) BETWEEN ? AND ?");
    $stmt->execute([$orgId, $fFrom, $fTo]);
    $totalRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT status, COALESCE(SUM(amount),0) AS tot FROM courier_payments WHERE org_id=? AND payment_date BETWEEN ? AND ? GROUP BY status");
    $stmt->execute([$orgId, $fFrom, $fTo]);
    foreach ($stmt->fetchAll() as $row) {
        if ($row['status'] === 'cleared') $clearedRevenue = (float)$row['tot'];
        if ($row['status'] === 'pending') $pendingRevenue = (float)$row['tot'];
    }
} catch (Exception $e) {}

$deliveryRate = $totalCouriers > 0 ? round(($delivered / $totalCouriers) * 100, 1) : 0;

// ---- Monthly trend (volume + revenue) ----
$monthlyTrend = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at,'%b %Y') AS mon,
               YEAR(created_at) AS yr, MONTH(created_at) AS mo,
               COUNT(*) AS cnt,
               COALESCE(SUM(price),0) AS rev
        FROM couriers WHERE org_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY YEAR(created_at), MONTH(created_at) ORDER BY yr ASC, mo ASC
    ");
    $stmt->execute([$orgId]);
    $monthlyTrend = $stmt->fetchAll();
} catch (Exception $e) {}

// ---- Status breakdown for period ----
$statusBreakdown = [];
try {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM couriers WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ? GROUP BY status ORDER BY cnt DESC");
    $stmt->execute([$orgId, $fFrom, $fTo]);
    $statusBreakdown = $stmt->fetchAll();
} catch (Exception $e) {}

// ---- Payment method breakdown ----
$paymentMethods = [];
try {
    $stmt = $pdo->prepare("SELECT method, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS tot FROM courier_payments WHERE org_id=? AND payment_date BETWEEN ? AND ? GROUP BY method ORDER BY tot DESC");
    $stmt->execute([$orgId, $fFrom, $fTo]);
    $paymentMethods = $stmt->fetchAll();
} catch (Exception $e) {}

// ---- Top agents by deliveries ----
$topAgents = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.name, a.photo,
               COUNT(c.id) AS total_assigned,
               SUM(CASE WHEN c.status='delivered' THEN 1 ELSE 0 END) AS delivered,
               COALESCE(SUM(c.price),0) AS revenue
        FROM courier_agents a
        LEFT JOIN couriers c ON c.agent_id = a.id AND c.org_id = a.org_id AND DATE(c.created_at) BETWEEN ? AND ?
        WHERE a.org_id = ?
        GROUP BY a.id ORDER BY delivered DESC LIMIT 10
    ");
    $stmt->execute([$fFrom, $fTo, $orgId]);
    $topAgents = $stmt->fetchAll();
} catch (Exception $e) {}

// ---- Branch performance ----
$branchPerf = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.name,
               COUNT(c.id) AS total,
               SUM(CASE WHEN c.status='delivered' THEN 1 ELSE 0 END) AS delivered,
               COALESCE(SUM(c.price),0) AS revenue
        FROM courier_branches b
        LEFT JOIN couriers c ON c.branch_id = b.id AND c.org_id = b.org_id AND DATE(c.created_at) BETWEEN ? AND ?
        WHERE b.org_id = ?
        GROUP BY b.id ORDER BY total DESC
    ");
    $stmt->execute([$fFrom, $fTo, $orgId]);
    $branchPerf = $stmt->fetchAll();
} catch (Exception $e) {}

// ---- Service type performance ----
$servicePerf = [];
try {
    $stmt = $pdo->prepare("
        SELECT st.name,
               COUNT(c.id) AS total,
               COALESCE(SUM(c.price),0) AS revenue
        FROM courier_service_types st
        LEFT JOIN couriers c ON c.service_type_id = st.id AND c.org_id = st.org_id AND DATE(c.created_at) BETWEEN ? AND ?
        WHERE st.org_id = ?
        GROUP BY st.id ORDER BY total DESC
    ");
    $stmt->execute([$fFrom, $fTo, $orgId]);
    $servicePerf = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Courier Reports & Analytics</h4>
    <p class="text-muted mb-0">Delivery performance, revenue analysis, agent rankings, and branch statistics</p>
  </div>
</div>

<!-- Date Range Filter -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-auto"><label class="form-label small fw-semibold mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($fFrom) ?>"></div>
      <div class="col-auto"><label class="form-label small fw-semibold mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($fTo) ?>"></div>
      <div class="col-auto d-flex align-items-end gap-1">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Generate Report</button>
        <a href="reports.php" class="btn btn-sm btn-outline-secondary">Reset</a>
      </div>
      <div class="col-auto ms-auto d-flex align-items-end">
        <small class="text-muted">Reporting: <?= date('d M Y', strtotime($fFrom)) ?> — <?= date('d M Y', strtotime($fTo)) ?></small>
      </div>
    </form>
  </div>
</div>

<!-- Summary KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-box"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalCouriers ?></div><div class="stat-label">Total Couriers (Period)</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $delivered ?> <small class="fs-6 text-muted">(<?= $deliveryRate ?>%)</small></div><div class="stat-label">Successfully Delivered</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($clearedRevenue) ?></div><div class="stat-label">Cleared Revenue</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($pendingRevenue) ?></div><div class="stat-label">Pending Collections</div></div>
    </div>
  </div>
</div>

<!-- Delivery Rate Progress -->
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between mb-1"><span class="fw-bold">Delivery Success Rate</span><span class="fw-bold" style="color:<?= $moduleColor ?>"><?= $deliveryRate ?>%</span></div>
    <div class="progress" style="height:12px"><div class="progress-bar" style="width:<?= $deliveryRate ?>%;background:<?= $moduleColor ?>" role="progressbar"></div></div>
    <div class="row g-2 mt-2 text-center small">
      <div class="col-3"><div class="fw-bold text-primary"><?= $totalCouriers ?></div><div class="text-muted">Total</div></div>
      <div class="col-3"><div class="fw-bold text-success"><?= $delivered ?></div><div class="text-muted">Delivered</div></div>
      <div class="col-3"><div class="fw-bold text-info"><?= $inTransit ?></div><div class="text-muted">In Transit</div></div>
      <div class="col-3"><div class="fw-bold text-danger"><?= $failed ?></div><div class="text-muted">Failed</div></div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-chart-line me-2 text-primary"></i>Monthly Volume & Revenue Trend (12 Months)</h6></div>
      <div class="card-body"><canvas id="trendChart" height="130"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-chart-pie me-2 text-primary"></i>Status Distribution (Period)</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="statusChart"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Payment Methods -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-credit-card me-2 text-primary"></i>Payment Methods</h6></div>
      <div class="card-body">
        <?php if (empty($paymentMethods)): ?>
        <p class="text-muted text-center py-3">No payment data for period.</p>
        <?php else:
          $maxPay = max(array_column($paymentMethods, 'tot')) ?: 1;
          $pmLabels = ['cash'=>'Cash','mobile_money'=>'Mobile Money','bank_transfer'=>'Bank Transfer','card'=>'Card','cheque'=>'Cheque','other'=>'Other'];
        foreach ($paymentMethods as $pm):
          $pct = round(($pm['tot'] / $maxPay) * 100);
        ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small mb-1">
            <span><?= $pmLabels[$pm['method']] ?? ucfirst($pm['method']) ?> (<?= $pm['cnt'] ?>)</span>
            <span class="fw-bold"><?= formatCurrency((float)$pm['tot']) ?></span>
          </div>
          <div class="progress" style="height:8px"><div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $moduleColor ?>"></div></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Service Type Performance -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-layer-group me-2 text-primary"></i>Service Type Performance</h6></div>
      <div class="card-body">
        <?php if (empty($servicePerf)): ?>
        <p class="text-muted text-center py-3">No data for period.</p>
        <?php else:
          $maxSvc = max(array_column($servicePerf, 'total')) ?: 1;
          foreach ($servicePerf as $sp):
            $pct = round(($sp['total'] / $maxSvc) * 100);
        ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small mb-1">
            <span><?= e($sp['name']) ?></span>
            <span class="fw-bold"><?= $sp['total'] ?> orders · <?= formatCurrency((float)$sp['revenue']) ?></span>
          </div>
          <div class="progress" style="height:8px"><div class="progress-bar bg-info" style="width:<?= $pct ?>%"></div></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Branch Performance -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-code-branch me-2 text-primary"></i>Branch Performance</h6></div>
      <div class="card-body">
        <?php if (empty($branchPerf)): ?>
        <p class="text-muted text-center py-3">No branches configured.</p>
        <?php else:
          $maxBr = max(array_column($branchPerf, 'total')) ?: 1;
          foreach ($branchPerf as $bp):
            $pct = round(($bp['total'] / $maxBr) * 100);
            $brRate = $bp['total'] > 0 ? round(($bp['delivered'] / $bp['total']) * 100) : 0;
        ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small mb-1">
            <span><?= e($bp['name']) ?></span>
            <span class="fw-bold text-success"><?= $brRate ?>% delivered</span>
          </div>
          <div class="progress" style="height:8px"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div></div>
          <small class="text-muted"><?= $bp['total'] ?> couriers · <?= formatCurrency((float)$bp['revenue']) ?></small>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Top Agents Table -->
<div class="card">
  <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-medal me-2 text-primary"></i>Agent Performance Rankings</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>#</th><th>Agent</th><th>Assigned</th><th>Delivered</th><th>Success Rate</th><th>Revenue</th></tr>
        </thead>
        <tbody>
          <?php if (empty($topAgents)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No agent data for this period.</td></tr>
          <?php else: foreach ($topAgents as $i => $ag):
            $rate = $ag['total_assigned'] > 0 ? round(($ag['delivered'] / $ag['total_assigned']) * 100) : 0;
            $rateColor = $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger');
          ?>
          <tr>
            <td class="text-center"><span class="badge bg-<?= $i < 3 ? 'warning' : 'light text-dark' ?>"><?= $i + 1 ?></span></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <?php if ($ag['photo']): ?>
                <img src="../../uploads/courier/agents/<?= e($ag['photo']) ?>" alt="" class="rounded-circle" style="width:32px;height:32px;object-fit:cover">
                <?php else: ?>
                <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="width:32px;height:32px;background:<?= $moduleColor ?>;font-size:13px">
                  <?= strtoupper(substr($ag['name'],0,1)) ?>
                </div>
                <?php endif; ?>
                <span class="fw-bold"><?= e($ag['name']) ?></span>
              </div>
            </td>
            <td><?= $ag['total_assigned'] ?></td>
            <td class="fw-bold text-success"><?= $ag['delivered'] ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:6px"><div class="progress-bar bg-<?= $rateColor ?>" style="width:<?= $rate ?>%"></div></div>
                <span class="badge bg-<?= $rateColor ?>"><?= $rate ?>%</span>
              </div>
            </td>
            <td class="fw-bold text-dark"><?= formatCurrency((float)$ag['revenue']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$monthLabels   = json_encode(array_column($monthlyTrend, 'mon'));
$monthCounts   = json_encode(array_column($monthlyTrend, 'cnt'));
$monthRevenue  = json_encode(array_map(fn($r) => round((float)$r['rev'], 2), $monthlyTrend));
$stLabels      = json_encode(array_map(fn($r) => strtoupper(str_replace('_',' ',$r['status'])), $statusBreakdown));
$stCounts      = json_encode(array_column($statusBreakdown, 'cnt'));

$extraJs = <<<JS
<script>
new Chart(document.getElementById('trendChart'), {
  type: 'bar',
  data: {
    labels: $monthLabels,
    datasets: [
      {
        type: 'line',
        label: 'Revenue',
        data: $monthRevenue,
        borderColor: '#1565c0',
        backgroundColor: 'transparent',
        tension: 0.4,
        yAxisID: 'y1',
        pointBackgroundColor: '#1565c0'
      },
      {
        type: 'bar',
        label: 'Couriers',
        data: $monthCounts,
        backgroundColor: 'rgba(21,101,192,0.2)',
        borderColor: 'rgba(21,101,192,0.5)',
        borderWidth: 1,
        yAxisID: 'y'
      }
    ]
  },
  options: {
    responsive: true,
    interaction: { mode: 'index' },
    scales: {
      y:  { beginAtZero: true, position: 'left',  title: { display: true, text: 'Couriers' } },
      y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Revenue' } }
    }
  }
});
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: $stLabels,
    datasets: [{ data: $stCounts, backgroundColor: ['#ffc107','#17a2b8','#0d6efd','#198754','#dc3545','#6c757d','#212529','#fd7e14'] }]
  },
  options: { plugins: { legend: { position: 'bottom' } }, cutout: '55%' }
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
