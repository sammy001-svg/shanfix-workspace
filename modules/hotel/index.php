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

$orgId = (int)$user['org_id'];

$totalRooms    = countRows('hotel_rooms', 'org_id = ?', [$orgId]);
$occupiedRooms = countRows('hotel_rooms', 'org_id = ? AND status = ?', [$orgId, 'occupied']);
$availableRooms= countRows('hotel_rooms', 'org_id = ? AND status = ?', [$orgId, 'available']);
$todayRevenue  = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM hotel_bookings WHERE org_id=? AND DATE(created_at)=CURDATE() AND status != 'cancelled'");
    $stmt->execute([$orgId]);
    $todayRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Today's bookings
$bookings = [];
try {
    $stmt = $pdo->prepare("SELECT b.*, 
                                  CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
                                  r.room_no AS room_number
                           FROM hotel_bookings b
                           LEFT JOIN hotel_guests g ON b.guest_id = g.id
                           LEFT JOIN hotel_rooms r ON b.room_id = r.id
                           WHERE b.org_id=? AND (DATE(b.check_in)=CURDATE() OR DATE(b.check_out)=CURDATE() OR b.status='checked_in') 
                           ORDER BY b.check_in ASC 
                           LIMIT 10");
    $stmt->execute([$orgId]);
    $bookings = $stmt->fetchAll();
} catch (Exception $e) {}

// Occupancy doughnut
$maintenanceRooms = countRows('hotel_rooms', 'org_id = ? AND status = ?', [$orgId, 'maintenance']);

// Monthly revenue trend (6 months)
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
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage rooms, bookings, guests, and revenue</p>
  </div>
  <a href="bookings.php" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-plus me-2"></i>New Booking</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-bed"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalRooms ?></div><div class="stat-label">Total Rooms</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-door-closed"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $occupiedRooms ?></div><div class="stat-label">Occupied</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-door-open"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $availableRooms ?></div><div class="stat-label">Available</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($todayRevenue) ?></div><div class="stat-label">Today Revenue</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 text-dark fw-bold"><i class="fas fa-chart-line me-2" style="color:<?= $moduleColor ?>"></i>Monthly Revenue (6 months)</h6></div>
      <div class="card-body"><canvas id="revChart" height="120"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 text-dark fw-bold"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Room Occupancy</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="occChart" height="220"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-calendar-check me-2" style="color:<?= $moduleColor ?>"></i>Today's Bookings — <?= date('d M Y') ?></h6>
    <a href="bookings.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="hotelTable">
        <thead class="table-light">
          <tr><th>Booking #</th><th>Guest</th><th>Room</th><th>Check-In</th><th>Check-Out</th><th>Status</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>
          <?php if (empty($bookings)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No bookings for today</td></tr>
          <?php else: foreach ($bookings as $b): ?>
          <tr>
            <td class="fw-bold text-dark"><?= e($b['booking_no'] ?? '#' . $b['id']) ?></td>
            <td class="fw-semibold text-dark"><?= e($b['guest_name'] ?? '—') ?></td>
            <td><span class="badge bg-light text-dark border"><i class="fas fa-door-closed me-1"></i><?= e($b['room_number'] ?? '—') ?></span></td>
            <td><?= formatDate($b['check_in'] ?? '') ?></td>
            <td><?= formatDate($b['check_out'] ?? '') ?></td>
            <td>
              <?php
              $statusColors = ['confirmed'=>'#3498db','checked_in'=>'#2ecc71','checked_out'=>'#95a5a6','cancelled'=>'#e74c3c','no_show'=>'#f39c12'];
              $color = $statusColors[$b['status'] ?? 'confirmed'] ?? '#7f8c8d';
              ?>
              <span class="badge text-white" style="background:<?= $color ?>"><?= strtoupper(str_replace('_', ' ', $b['status'] ?? 'confirmed')) ?></span>
            </td>
            <td class="text-end fw-bold text-dark"><?= formatCurrency((float)($b['total_amount'] ?? 0)) ?></td>
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
  new Chart(document.getElementById("revChart"),{
    type:"line",
    data:{labels:' . json_encode($revLabels) . ',datasets:[{label:"Revenue",data:' . json_encode($revData) . ',borderColor:"#d35400",backgroundColor:"rgba(211,84,0,.15)",tension:.4,fill:true}]},
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}
  });
  new Chart(document.getElementById("occChart"),{
    type:"doughnut",
    data:{labels:["Occupied","Available","Maintenance"],datasets:[{data:[' . $occupiedRooms . ',' . $availableRooms . ',' . $maintenanceRooms . '],backgroundColor:["#e74c3c","#1A8A4E","#f39c12"]}]},
    options:{responsive:true,plugins:{legend:{position:"bottom"}}}
  });
  $("#hotelTable").DataTable({pageLength:10,order:[[3,"asc"]]});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
