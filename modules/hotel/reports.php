<?php
$moduleSlug  = 'hotel';
$moduleName  = 'Hotel Management';
$moduleIcon  = 'fas fa-hotel';
$moduleColor = '#d35400';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'room-types.php',   'icon' => 'fas fa-bed',            'label' => 'Room Types'],
    ['url' => 'rooms.php',        'icon' => 'fas fa-door-open',      'label' => 'Rooms'],
    ['url' => 'guests.php',       'icon' => 'fas fa-user-tie',       'label' => 'Guests'],
    ['url' => 'bookings.php',     'icon' => 'fas fa-calendar-check', 'label' => 'Bookings'],
    ['url' => 'checkin.php',      'icon' => 'fas fa-sign-in-alt',    'label' => 'Check-In/Out'],
    ['url' => 'housekeeping.php', 'icon' => 'fas fa-broom',          'label' => 'Housekeeping'],
    ['url' => 'restaurant.php',   'icon' => 'fas fa-utensils',       'label' => 'Restaurant'],
    ['url' => 'invoices.php',     'icon' => 'fas fa-file-invoice',   'label' => 'Invoices'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// 1. Core financial stats
$totalRevenue   = 0;
$totalPaid      = 0;
$totalBalance   = 0;
$totalBookings  = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount),0) AS total_rev, 
               COALESCE(SUM(paid_amount),0) AS total_paid, 
               COUNT(id) AS booking_count 
        FROM hotel_bookings 
        WHERE org_id = ?
    ");
    $stmt->execute([$orgId]);
    $fin = $stmt->fetch();
    if ($fin) {
        $totalRevenue = (float)$fin['total_rev'];
        $totalPaid    = (float)$fin['total_paid'];
        $totalBalance = max(0, $totalRevenue - $totalPaid);
        $totalBookings= (int)$fin['booking_count'];
    }
} catch(Exception $e){}

// 2. Room metrics
$totalRooms       = countRows('hotel_rooms', 'org_id = ?', [$orgId]);
$occupiedRooms    = countRows('hotel_rooms', 'org_id = ? AND status = ?', [$orgId, 'occupied']);
$availableRooms   = countRows('hotel_rooms', 'org_id = ? AND status = ?', [$orgId, 'available']);
$maintenanceRooms = countRows('hotel_rooms', 'org_id = ? AND status = ?', [$orgId, 'maintenance']);
$reservedRooms    = countRows('hotel_rooms', 'org_id = ? AND status = ?', [$orgId, 'reserved']);

// 3. Revenue by room type
$typeLabels = [];
$typeValues = [];
try {
    $stmt = $pdo->prepare("
        SELECT rt.name, COALESCE(SUM(b.total_amount),0) AS total_rev 
        FROM hotel_room_types rt
        LEFT JOIN hotel_rooms r ON r.type_id = rt.id
        LEFT JOIN hotel_bookings b ON b.room_id = r.id
        WHERE rt.org_id = ?
        GROUP BY rt.id
        ORDER BY total_rev DESC
    ");
    $stmt->execute([$orgId]);
    $typeData = $stmt->fetchAll();
    foreach ($typeData as $row) {
        $typeLabels[] = $row['name'];
        $typeValues[] = (float)$row['total_rev'];
    }
} catch (Exception $e){}

// 4. Monthly revenue trend (last 6 months)
$revLabels = [];
$revData   = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $revLabels[] = date('M Y', strtotime("-$i months"));
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM hotel_bookings WHERE org_id=? AND DATE_FORMAT(check_in,'%Y-%m')=? AND status != 'cancelled'");
        $stmt->execute([$orgId, $month]);
        $revData[] = (float)$stmt->fetchColumn();
    } catch (Exception $e) { $revData[] = 0; }
}

// 5. Recent Bookings with Balance
$recentBookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, 
               CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
               r.room_no
        FROM hotel_bookings b
        LEFT JOIN hotel_guests g ON g.id = b.guest_id
        LEFT JOIN hotel_rooms r ON r.id = b.room_id
        WHERE b.org_id = ?
        ORDER BY b.id DESC
        LIMIT 10
    ");
    $stmt->execute([$orgId]);
    $recentBookings = $stmt->fetchAll();
} catch(Exception $e){}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Hotel Analytics & Reports</h4>
    <p class="text-muted mb-0">Track hotel revenues, room occupancies, and guest registries</p>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalRevenue) ?></div>
        <div class="stat-label">Total Revenue</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalPaid) ?></div>
        <div class="stat-label">Paid Settlements</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value text-danger"><?= formatCurrency($totalBalance) ?></div>
        <div class="stat-label">Outstanding Balance</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hotel"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalBookings ?></div>
        <div class="stat-label">Total Bookings</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Monthly Revenue Trend -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header bg-transparent text-dark fw-bold"><i class="fas fa-chart-line text-primary me-2"></i>Monthly Revenue Trend (6 Months)</div>
      <div class="card-body"><canvas id="revenueTrendChart" height="150"></canvas></div>
    </div>
  </div>

  <!-- Room Occupancy Pie -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header bg-transparent text-dark fw-bold"><i class="fas fa-chart-pie text-success me-2"></i>Room Status Breakdown</div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="roomStatusPie" height="220"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Revenue by Room Type -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header bg-transparent text-dark fw-bold"><i class="fas fa-bed text-warning me-2"></i>Revenue by Room Type</div>
      <div class="card-body">
        <?php if (empty($typeLabels)): ?>
        <p class="text-muted text-center py-4">No booking data available</p>
        <?php else: ?>
        <canvas id="revenueByTypeChart" height="220"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent booking ledger -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header bg-transparent text-dark fw-bold"><i class="fas fa-receipt text-info me-2"></i>Recent Booking Financial Ledger</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Guest</th><th>Room</th><th>Total</th><th>Paid</th><th class="text-end">Balance</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentBookings as $rb):
                $bal = max(0, (float)$rb['total_amount'] - (float)$rb['paid_amount']);
              ?>
              <tr>
                <td class="fw-bold text-dark"><?= e($rb['guest_name']) ?></td>
                <td><span class="badge bg-light text-dark border"><?= e($rb['room_no']) ?></span></td>
                <td class="text-dark fw-semibold"><?= formatCurrency((float)$rb['total_amount']) ?></td>
                <td class="text-success fw-semibold"><?= formatCurrency((float)$rb['paid_amount']) ?></td>
                <td class="text-end text-danger fw-bold"><?= formatCurrency($bal) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
(function(){
  // Monthly Revenue Chart
  new Chart(document.getElementById("revenueTrendChart"), {
    type: "line",
    data: {
      labels: ' . json_encode($revLabels) . ',
      datasets: [{
        label: "Monthly Revenue (KES)",
        data: ' . json_encode($revData) . ',
        borderColor: "#d35400",
        backgroundColor: "rgba(211,84,0,0.15)",
        tension: 0.4,
        fill: true
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });

  // Room Status Pie Chart
  new Chart(document.getElementById("roomStatusPie"), {
    type: "doughnut",
    data: {
      labels: ["Occupied", "Available", "Maintenance", "Reserved"],
      datasets: [{
        data: [' . $occupiedRooms . ', ' . $availableRooms . ', ' . $maintenanceRooms . ', ' . $reservedRooms . '],
        backgroundColor: ["#e74c3c", "#1A8A4E", "#f39c12", "#3498db"]
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: "bottom" } }
    }
  });

  // Revenue by Room Type Bar Chart
  ' . (!empty($typeLabels) ? '
  new Chart(document.getElementById("revenueByTypeChart"), {
    type: "bar",
    data: {
      labels: ' . json_encode($typeLabels) . ',
      datasets: [{
        label: "Revenue by Category",
        data: ' . json_encode($typeValues) . ',
        backgroundColor: "rgba(211,84,0,0.85)"
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });
  ' : '') . '
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
