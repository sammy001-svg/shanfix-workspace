<?php
$moduleSlug  = 'rental';
$moduleName  = 'Rental & Property';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'properties.php','icon' => 'fas fa-building',       'label' => 'Properties'],
    ['url' => 'units.php',     'icon' => 'fas fa-door-open',      'label' => 'Units'],
    ['url' => 'tenants.php',   'icon' => 'fas fa-users',          'label' => 'Tenants'],
    ['url' => 'payments.php',  'icon' => 'fas fa-money-bill',     'label' => 'Payments'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

// General Statistics
$totalProperties = countRows('rental_properties', 'org_id = ?', [$orgId]);
$totalUnits      = countRows('rental_units', 'org_id = ?', [$orgId]);
$occupiedUnits   = countRows('rental_units', 'org_id = ? AND status = ?', [$orgId, 'occupied']);
$vacantUnits     = countRows('rental_units', 'org_id = ? AND status = ?', [$orgId, 'vacant']);
$maintUnits      = countRows('rental_units', 'org_id = ? AND status = ?', [$orgId, 'maintenance']);

$occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;

$totalDeposits = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(deposit), 0) FROM rental_tenants WHERE org_id = ? AND status = 'active'");
    $stmt->execute([$orgId]);
    $totalDeposits = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$totalRevenue = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM rental_payments WHERE org_id = ? AND status = 'paid'");
    $stmt->execute([$orgId]);
    $totalRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Monthly payments history trend for Chart.js
$monthlyTrend = [];
try {
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(payment_date, '%b %Y') AS month_label, 
                                  COALESCE(SUM(amount), 0) AS total_amount,
                                  DATE_FORMAT(payment_date, '%Y-%m') AS month_sort
                           FROM rental_payments 
                           WHERE org_id = ? AND status = 'paid'
                           GROUP BY month_label, month_sort
                           ORDER BY month_sort ASC 
                           LIMIT 6");
    $stmt->execute([$orgId]);
    $monthlyTrend = $stmt->fetchAll();
} catch (Exception $e) {}

// Top properties by revenue
$topProperties = [];
try {
    $stmt = $pdo->prepare("SELECT p.name, COALESCE(SUM(r.amount), 0) AS total_collected
                           FROM rental_payments r
                           JOIN rental_units u ON r.unit_id = u.id
                           JOIN rental_properties p ON u.property_id = p.id
                           WHERE r.org_id = ? AND r.status = 'paid'
                           GROUP BY p.id, p.name
                           ORDER BY total_collected DESC
                           LIMIT 5");
    $stmt->execute([$orgId]);
    $topProperties = $stmt->fetchAll();
} catch (Exception $e) {}

// Upcoming Lease Expirations (proactive vacancy management)
$expiringLeases = [];
try {
    $stmt = $pdo->prepare("SELECT t.lease_end, CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone,
                                  u.unit_no, p.name AS property_name
                           FROM rental_tenants t
                           JOIN rental_units u ON t.unit_id = u.id
                           JOIN rental_properties p ON u.property_id = p.id
                           WHERE t.org_id = ? AND t.status = 'active' AND t.lease_end >= CURDATE()
                           ORDER BY t.lease_end ASC
                           LIMIT 5");
    $stmt->execute([$orgId]);
    $expiringLeases = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Property & Revenue Reports</h4>
    <p class="text-muted mb-0">Track occupancy fluctuations, rent collection trends, and manage security deposit ledgers</p>
  </div>
  <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print Analytics Roster</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-percentage"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $occupancyRate ?>%</div>
        <div class="stat-label">Occupancy Rate</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-wallet"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalDeposits) ?></div>
        <div class="stat-label">Active Deposits Held</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalRevenue) ?></div>
        <div class="stat-label">Cumulative Collections</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-home"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalProperties ?></div>
        <div class="stat-label">Registered Properties</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Rent Payment Trend Chart -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold text-dark"><i class="fas fa-chart-line me-2 text-primary"></i>Rent Collections Trend (Last 6 Months)</h6></div>
      <div class="card-body">
        <div style="height:280px;"><canvas id="revenueTrendChart"></canvas></div>
      </div>
    </div>
  </div>

  <!-- Occupancy breakdown chart -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold text-dark"><i class="fas fa-door-open me-2 text-primary"></i>Units Occupancy Matrix</h6></div>
      <div class="card-body d-flex flex-column justify-content-center">
        <div style="height:200px;"><canvas id="occupancyChart"></canvas></div>
        <div class="mt-3 text-center small text-muted">
          Occupied: <strong><?= $occupiedUnits ?></strong> &nbsp;|&nbsp; 
          Vacant: <strong><?= $vacantUnits ?></strong> &nbsp;|&nbsp;
          Maintenance: <strong><?= $maintUnits ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Top Revenue-Generating properties -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold text-dark"><i class="fas fa-medal me-2 text-warning"></i>Top Revenue-Generating Properties</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Property Estate Name</th><th class="text-end">Rent Collections</th></tr>
            </thead>
            <tbody>
              <?php if (empty($topProperties)): ?>
              <tr><td colspan="2" class="text-center text-muted py-4">No collections metrics compiled.</td></tr>
              <?php else: foreach ($topProperties as $tp): ?>
              <tr>
                <td class="fw-semibold text-dark"><?= e($tp['name']) ?></td>
                <td class="text-end fw-bold text-primary"><?= formatCurrency($tp['total_collected']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Upcoming lease expirations -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold text-dark"><i class="fas fa-hourglass-half me-2 text-danger"></i>Upcoming Lease Expirations</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Tenant</th><th>Unit / Estate</th><th>Lease Ends</th></tr>
            </thead>
            <tbody>
              <?php if (empty($expiringLeases)): ?>
              <tr><td colspan="3" class="text-center text-muted py-4">No active leases expiring soon.</td></tr>
              <?php else: foreach ($expiringLeases as $el): ?>
              <tr>
                <td>
                  <div class="fw-semibold text-dark"><?= e($el['tenant_name']) ?></div>
                  <small class="text-muted"><i class="fas fa-phone me-1 small"></i><?= e($el['phone']) ?></small>
                </td>
                <td>
                  <div class="small fw-semibold text-dark"><?= e($el['unit_no']) ?></div>
                  <small class="text-muted"><?= e($el['property_name']) ?></small>
                </td>
                <td><span class="badge bg-danger small"><?= formatDate($el['lease_end']) ?></span></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php
$monthLabels = [];
$monthAmounts = [];
foreach ($monthlyTrend as $mt) {
    $monthLabels[] = $mt['month_label'];
    $monthAmounts[] = (float)$mt['total_amount'];
}
if (empty($monthLabels)) {
    $monthLabels = [date('F')];
    $monthAmounts = [0];
}
$jsLabels = json_encode($monthLabels);
$jsAmounts = json_encode($monthAmounts);

$occLabels = json_encode(['Occupied', 'Vacant', 'Maintenance']);
$occData   = json_encode([$occupiedUnits, $vacantUnits, $maintUnits]);

$extraJs = <<<JS
<script>
// Rent Collections Bar Chart
new Chart(document.getElementById('revenueTrendChart'), {
  type: 'bar',
  data: {
    labels: {$jsLabels},
    datasets: [{
      label: 'Monthly Collections',
      data: {$jsAmounts},
      backgroundColor: '#2980b9',
      borderRadius: 4
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true }
    }
  }
});

// Occupancy Doughnut Chart
new Chart(document.getElementById('occupancyChart'), {
  type: 'doughnut',
  data: {
    labels: {$occLabels},
    datasets: [{
      data: {$occData},
      backgroundColor: ['#2563eb', '#16a34a', '#d97706']
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' } }
  }
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
