<?php
// ── TOUR: Analytical Reports & Charts ──────────────────────────
require_once __DIR__ . '/_nav.php';

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

// ── Phase 2: cost of sales and trip margin ────────────────────
require_once __DIR__ . '/_lib.php';

$supplierCommitted = $supplierSettled = $directExpenses = 0.00;
$salesBooked = 0.00;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(cost),0), COALESCE(SUM(amount_paid),0) FROM tour_supplier_bookings WHERE org_id=? AND status <> 'cancelled'");
    $stmt->execute([$orgId]);
    [$supplierCommitted, $supplierSettled] = array_map('floatval', array_values($stmt->fetch(PDO::FETCH_NUM) ?: [0, 0]));

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM tour_expenses WHERE org_id=?");
    $stmt->execute([$orgId]);
    $directExpenses = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM tour_bookings WHERE org_id=? AND status IN ('confirmed','completed')");
    $stmt->execute([$orgId]);
    $salesBooked = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$costOfSales = $supplierCommitted + $directExpenses;
$grossMargin = $salesBooked - $costOfSales;
$marginPct   = $salesBooked > 0 ? round(($grossMargin / $salesBooked) * 100, 1) : 0;

// Spend by supplier type, for the cost mix chart
$costMixLabels = $costMixValues = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.supplier_type, COALESCE(SUM(sb.cost),0) AS spend
        FROM tour_supplier_bookings sb
        JOIN tour_suppliers s ON s.id = sb.supplier_id
        WHERE sb.org_id = ? AND sb.status <> 'cancelled'
        GROUP BY s.supplier_type
        HAVING spend > 0
        ORDER BY spend DESC
    ");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $r) {
        $costMixLabels[] = ucwords(str_replace('_', ' ', $r['supplier_type']));
        $costMixValues[] = (float)$r['spend'];
    }
} catch (Exception $e) {}

// Per-departure profitability
$departureMargins = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.id, d.departure_code, d.start_date, d.seats_total, d.status,
               p.name AS package_name,
               COALESCE(SUM(CASE WHEN b.status <> 'cancelled' THEN b.total_amount ELSE 0 END), 0) AS revenue,
               COALESCE(SUM(CASE WHEN b.status <> 'cancelled' THEN b.adults + b.children ELSE 0 END), 0) AS pax
        FROM tour_departures d
        JOIN tour_packages p      ON p.id = d.package_id
        LEFT JOIN tour_bookings b ON b.departure_id = d.id AND b.org_id = d.org_id
        WHERE d.org_id = ? AND d.status <> 'cancelled'
        GROUP BY d.id
        ORDER BY d.start_date DESC
        LIMIT 15
    ");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $r) {
        $cost = tourTripCost($orgId, null, (int)$r['id']);
        // Costs charged to the individual bookings on this departure count too
        try {
            $bs = $pdo->prepare("
                SELECT COALESCE(SUM(sb.cost),0) FROM tour_supplier_bookings sb
                JOIN tour_bookings b ON b.id = sb.booking_id
                WHERE sb.org_id=? AND b.departure_id=? AND sb.status <> 'cancelled'
            ");
            $bs->execute([$orgId, (int)$r['id']]);
            $cost += (float)$bs->fetchColumn();

            $bs = $pdo->prepare("
                SELECT COALESCE(SUM(ex.amount),0) FROM tour_expenses ex
                JOIN tour_bookings b ON b.id = ex.booking_id
                WHERE ex.org_id=? AND b.departure_id=?
            ");
            $bs->execute([$orgId, (int)$r['id']]);
            $cost += (float)$bs->fetchColumn();
        } catch (Exception $e) {}

        $r['cost']   = $cost;
        $r['margin'] = (float)$r['revenue'] - $cost;
        $r['margin_pct'] = (float)$r['revenue'] > 0 ? round(($r['margin'] / (float)$r['revenue']) * 100, 1) : 0;
        $departureMargins[] = $r;
    }
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

<!-- ── Profitability ────────────────────────────────────────── -->
<h5 class="fw-bold mb-3"><i class="fas fa-scale-balanced me-2" style="color:<?= $moduleColor ?>"></i>Trip Profitability</h5>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-sack-dollar"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($salesBooked) ?></div><div class="stat-label">Sales Booked</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($costOfSales) ?></div><div class="stat-label">Cost Of Sales</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $grossMargin >= 0 ? 'green-bg' : '' ?>" style="<?= $grossMargin < 0 ? 'background:rgba(231,76,60,.15);color:#e74c3c' : '' ?>">
        <i class="fas fa-chart-line"></i>
      </div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($grossMargin) ?></div><div class="stat-label">Gross Margin (<?= $marginPct ?>%)</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(231,76,60,.15);color:#e74c3c"><i class="fas fa-hand-holding-dollar"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency(max(0, $supplierCommitted - $supplierSettled)) ?></div>
        <div class="stat-label">Still Owed To Suppliers</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-4 col-md-6 col-12">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-pie me-2 text-warning"></i>Where The Money Goes</h6>
      </div>
      <div class="card-body">
        <?php if (empty($costMixValues)): ?>
        <div class="text-center text-muted py-5">
          <i class="fas fa-receipt fa-2x mb-2 d-block"></i>
          No supplier spend recorded yet.
        </div>
        <?php else: ?>
        <div style="height:280px;display:flex;align-items:center;justify-content:center">
          <canvas id="costMixChart"></canvas>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8 col-12">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-plane-departure me-2 text-primary"></i>Margin By Departure</h6>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="ps-3">Departure</th>
                <th>Date</th>
                <th class="text-center">Load</th>
                <th class="text-end">Revenue</th>
                <th class="text-end">Cost</th>
                <th class="text-end pe-3">Margin</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($departureMargins)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No departures scheduled yet.</td></tr>
              <?php else: foreach ($departureMargins as $dm): ?>
              <tr>
                <td class="ps-3">
                  <div class="fw-semibold text-dark"><?= e($dm['package_name']) ?></div>
                  <div class="small text-muted"><code class="bg-light px-1 rounded"><?= e($dm['departure_code']) ?></code></div>
                </td>
                <td class="small"><?= formatDate($dm['start_date']) ?></td>
                <td class="text-center small"><?= (int)$dm['pax'] ?> / <?= (int)$dm['seats_total'] ?></td>
                <td class="text-end fw-semibold"><?= formatCurrency((float)$dm['revenue']) ?></td>
                <td class="text-end text-muted"><?= formatCurrency((float)$dm['cost']) ?></td>
                <td class="text-end pe-3 fw-bold <?= $dm['margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
                  <?= formatCurrency((float)$dm['margin']) ?>
                  <div class="small fw-normal text-muted"><?= $dm['margin_pct'] ?>%</div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
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

  // 3. Cost mix by supplier type
  var mixEl = document.getElementById("costMixChart");
  if (mixEl) {
    new Chart(mixEl.getContext("2d"), {
      type: "doughnut",
      data: {
        labels: ' . json_encode($costMixLabels) . ',
        datasets: [{
          data: ' . json_encode($costMixValues) . ',
          backgroundColor: ["#2980b9","#16a085","#e67e22","#8e44ad","#f1c40f","#e74c3c","#34495e","#7f8c8d"],
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: "bottom" },
          tooltip: {
            callbacks: {
              label: function(ctx) {
                return ctx.label + ": KES " + Number(ctx.raw).toLocaleString();
              }
            }
          }
        }
      }
    });
  }
});
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
