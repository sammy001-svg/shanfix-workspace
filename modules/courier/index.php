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

$orgId = (int)$user['org_id'];

// Summary stats
$totalCouriers   = countRows('couriers', 'org_id = ?', [$orgId]);
$pendingCouriers = countRows('couriers', 'org_id = ? AND status = ?', [$orgId, 'pending']);
$inTransit       = countRows('couriers', 'org_id = ? AND status = ?', [$orgId, 'in_transit']);
$delivered       = countRows('couriers', "org_id = ? AND status = 'delivered' AND DATE(actual_delivery) = CURDATE()", [$orgId]);

$totalRevenue = 0;
$pendingPayments = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM courier_payments WHERE org_id=? AND status='cleared'");
    $stmt->execute([$orgId]);
    $totalRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM courier_payments WHERE org_id=? AND status='pending'");
    $stmt->execute([$orgId]);
    $pendingPayments = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Monthly trend (last 6 months)
$monthlyData = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at,'%b %Y') AS mon, COUNT(*) AS cnt
        FROM couriers WHERE org_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY YEAR(created_at), MONTH(created_at) ORDER BY created_at ASC
    ");
    $stmt->execute([$orgId]);
    $monthlyData = $stmt->fetchAll();
} catch (Exception $e) {}

// Status breakdown
$statusData = [];
try {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM couriers WHERE org_id=? GROUP BY status");
    $stmt->execute([$orgId]);
    $statusData = $stmt->fetchAll();
} catch (Exception $e) {}

// Recent couriers
$recentCouriers = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, st.name AS service_name, b.name AS branch_name
        FROM couriers c
        LEFT JOIN courier_service_types st ON c.service_type_id = st.id
        LEFT JOIN courier_branches b ON c.branch_id = b.id
        WHERE c.org_id=? ORDER BY c.created_at DESC LIMIT 10
    ");
    $stmt->execute([$orgId]);
    $recentCouriers = $stmt->fetchAll();
} catch (Exception $e) {}

$statusColors = [
    'pending'          => 'warning',
    'processing'       => 'info',
    'picked_up'        => 'primary',
    'in_transit'       => 'primary',
    'out_for_delivery' => 'info',
    'delivered'        => 'success',
    'failed'           => 'danger',
    'returned'         => 'secondary',
    'cancelled'        => 'dark',
];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage parcels, deliveries, agents, payments, and courier operations</p>
  </div>
  <a href="couriers.php" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-plus me-2"></i>New Courier</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-box"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalCouriers ?></div><div class="stat-label">Total Couriers</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingCouriers ?></div><div class="stat-label">Pending Dispatch</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#e3f2fd;color:#1565c0"><i class="fas fa-truck"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $inTransit ?></div><div class="stat-label">In Transit</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $delivered ?></div><div class="stat-label">Delivered Today</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Total Revenue Cleared</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($pendingPayments) ?></div><div class="stat-label">Pending Payments</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-chart-line me-2 text-primary"></i>Monthly Courier Volume (Last 6 Months)</h6></div>
      <div class="card-body"><canvas id="trendChart" height="100"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-chart-pie me-2 text-primary"></i>Status Breakdown</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="statusChart"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-box me-2" style="color:<?= $moduleColor ?>"></i>Recent Couriers</h6>
    <a href="couriers.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Tracking ID</th><th>Sender</th><th>Receiver</th><th>Service</th><th>Branch</th><th>Status</th><th>Date</th></tr>
        </thead>
        <tbody>
          <?php if (empty($recentCouriers)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-box fa-2x mb-2 d-block"></i>No couriers recorded yet.</td></tr>
          <?php else: foreach ($recentCouriers as $c):
            $sc = $statusColors[$c['status']] ?? 'secondary';
          ?>
          <tr>
            <td><span class="badge bg-dark font-monospace"><?= e($c['tracking_id']) ?></span></td>
            <td class="fw-bold text-dark"><?= e($c['sender_name']) ?></td>
            <td><?= e($c['receiver_name']) ?></td>
            <td><?= e($c['service_name'] ?? '—') ?></td>
            <td><?= e($c['branch_name'] ?? '—') ?></td>
            <td><span class="badge bg-<?= $sc ?>"><?= strtoupper(str_replace('_',' ',$c['status'])) ?></span></td>
            <td class="small text-muted"><?= formatDate($c['created_at']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$monthLabels = json_encode(array_column($monthlyData, 'mon'));
$monthCounts = json_encode(array_column($monthlyData, 'cnt'));
$statusLabels = json_encode(array_map(fn($r) => strtoupper(str_replace('_',' ',$r['status'])), $statusData));
$statusCounts = json_encode(array_column($statusData, 'cnt'));

$extraJs = <<<JS
<script>
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: $monthLabels,
    datasets: [{
      label: 'Couriers',
      data: $monthCounts,
      borderColor: '#1565c0',
      backgroundColor: 'rgba(21,101,192,0.1)',
      tension: 0.4,
      fill: true
    }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: $statusLabels,
    datasets: [{ data: $statusCounts, backgroundColor: ['#ffc107','#17a2b8','#0d6efd','#198754','#dc3545','#6c757d','#212529'] }]
  },
  options: { plugins: { legend: { position: 'bottom' } }, cutout: '60%' }
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
