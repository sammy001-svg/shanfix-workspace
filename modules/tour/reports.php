<?php
// ── TOUR: Analytical Reports & Charts ──────────────────────────
$moduleSlug  = 'tour';
$moduleName  = 'Tour & Travel';
$moduleIcon  = 'fas fa-plane';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'packages.php',     'icon' => 'fas fa-box-open',       'label' => 'Packages'],
    ['url' => 'destinations.php', 'icon' => 'fas fa-map-marker-alt', 'label' => 'Destinations'],
    ['url' => 'bookings.php',     'icon' => 'fas fa-calendar-check', 'label' => 'Bookings'],
    ['url' => 'customers.php',    'icon' => 'fas fa-user-friends',   'label' => 'Customers'],
    ['url' => 'guides.php',       'icon' => 'fas fa-hiking',         'label' => 'Guides'],
    ['url' => 'itineraries.php', 'icon' => 'fas fa-route',           'label' => 'Itineraries'],
    ['url' => 'vehicles.php',    'icon' => 'fas fa-bus',             'label' => 'Vehicles'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch Core Totals
$totalReservations = 0;
$totalRevenue      = 0.00;
$totalPendingPax   = 0;
$completedTravels  = 0;

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tour_bookings WHERE org_id = ?");
    $stmt->execute([$orgId]);
    $totalReservations = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM tour_bookings WHERE org_id = ?");
    $stmt->execute([$orgId]);
    $totalRevenue = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tour_bookings WHERE org_id = ? AND status = 'pending'");
    $stmt->execute([$orgId]);
    $totalPendingPax = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tour_bookings WHERE org_id = ? AND status = 'completed'");
    $stmt->execute([$orgId]);
    $completedTravels = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Monthly trends over last 6 months
$months = [];
$bookingTrend = [];
$revenueTrend = [];

for ($i = 5; $i >= 0; $i--) {
    $date = date('Y-m', strtotime("-$i months"));
    $months[] = date('M Y', strtotime("-$i months"));
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tour_bookings WHERE org_id=? AND DATE_FORMAT(created_at, '%Y-%m') = ?");
        $stmt->execute([$orgId, $date]);
        $bookingTrend[] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(paid_amount), 0)
            FROM tour_bookings
            WHERE org_id=? AND DATE_FORMAT(created_at, '%Y-%m') = ?
        ");
        $stmt->execute([$orgId, $date]);
        $revenueTrend[] = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        $bookingTrend[] = 0;
        $revenueTrend[] = 0.00;
    }
}

// Popular Destinations
$destLabels = [];
$destValues = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.name, COUNT(b.id) as booking_cnt
        FROM tour_bookings b
        JOIN tour_packages p ON b.package_id = p.id
        JOIN tour_destinations d ON p.destination_id = d.id
        WHERE b.org_id = ?
        GROUP BY d.id
        ORDER BY booking_cnt DESC
    ");
    $stmt->execute([$orgId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $destLabels[] = $r['name'];
        $destValues[] = (int)$r['booking_cnt'];
    }
} catch (Exception $e) {}

// Detailed package breakdown
$packageBreakdown = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.name, p.duration_days, p.max_pax,
               COUNT(b.id) as booking_count,
               COALESCE(SUM(b.total_amount), 0) as total_value,
               COALESCE(SUM(b.paid_amount), 0) as total_collected
        FROM tour_packages p
        LEFT JOIN tour_bookings b ON p.id = b.package_id
        WHERE p.org_id = ?
        GROUP BY p.id
        ORDER BY booking_count DESC
    ");
    $stmt->execute([$orgId]);
    $packageBreakdown = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Tour Analytics Dashboard</h4>
    <p class="text-muted mb-0">Assess holiday sales trends, destination popularity, and collection performance metrics</p>
  </div>
  <a href="report-pdf.php" class="btn btn-outline-secondary"><i class="fas fa-file-pdf me-1"></i>Export PDF</a>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue-bg" style="background:rgba(41,128,185,0.15);color:#2980b9"><i class="fas fa-globe-africa"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalReservations ?></div><div class="stat-label">Holiday Bookings</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-wallet"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Total Revenue Collected</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalPendingPax ?></div><div class="stat-label">Pending Bookings</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-check-double"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $completedTravels ?></div><div class="stat-label">Completed Tours</div></div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
  <!-- Monthly Trend -->
  <div class="col-lg-8 col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-line me-2 text-primary"></i>Travel Reservations & Payments Trend (Last 6 Months)</h6>
      </div>
      <div class="card-body">
        <div style="height:320px">
          <canvas id="monthlyTrendChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Popular Destination Pie -->
  <div class="col-lg-4 col-md-6 col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-map-marked-alt me-2 text-danger"></i>Scenic Spot Share Proportions</h6>
      </div>
      <div class="card-body">
        <div style="height:320px;display:flex;align-items:center;justify-content:center">
          <canvas id="popularSpotsChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Detail Performance Row -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white border-bottom py-3">
    <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-clipboard-list me-2 text-secondary"></i>Detailed Package Performance Summary</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Holiday Package Name</th>
            <th class="text-center">Itinerary Days</th>
            <th class="text-center">Capacity Limit</th>
            <th class="text-center">Bookings Count</th>
            <th class="text-end">Total Booking Value</th>
            <th class="text-end pe-3">Revenue Collected</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($packageBreakdown)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No packages designed yet.</td></tr>
          <?php else: foreach ($packageBreakdown as $pb): ?>
          <tr>
            <td class="fw-semibold text-dark ps-3"><?= e($pb['name']) ?></td>
            <td class="text-center fw-bold text-primary"><?= (int)$pb['duration_days'] ?> Days</td>
            <td class="text-center"><?= (int)$pb['max_pax'] ?> Pax</td>
            <td class="text-center fw-bold"><?= (int)$pb['booking_count'] ?> bookings</td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$pb['total_value']) ?></td>
            <td class="text-end pe-3 fw-bold text-success"><?= formatCurrency((float)$pb['total_collected']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
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
  // 1. Monthly revenue trend
  var trendCtx = document.getElementById("monthlyTrendChart").getContext("2d");
  new Chart(trendCtx, {
    type: "line",
    data: {
      labels: ' . json_encode($months) . ',
      datasets: [
        {
          label: "Revenue Collected (KES)",
          data: ' . json_encode($revenueTrend) . ',
          backgroundColor: "rgba(41, 128, 185, 0.15)",
          borderColor: "#2980b9",
          borderWidth: 3,
          fill: true,
          tension: 0.3
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function(value) {
              return "KES " + value.toLocaleString();
            }
          }
        }
      }
    }
  });

  // 2. Destinations pie
  var spotCtx = document.getElementById("popularSpotsChart").getContext("2d");
  new Chart(spotCtx, {
    type: "pie",
    data: {
      labels: ' . json_encode($destLabels) . ',
      datasets: [{
        data: ' . json_encode($destValues) . ',
        backgroundColor: [
          "#2980b9", "#2ecc71", "#e67e22", "#f1c40f", "#e74c3c", "#9b59b6"
        ],
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      }
    }
  });
});
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
