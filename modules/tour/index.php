<?php
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

$orgId = (int)$user['org_id'];

$totalPackages   = countRows('tour_packages', 'org_id = ?', [$orgId]);
$totalBookings   = countRows('tour_bookings', 'org_id = ?', [$orgId]);
$upcomingTravel  = countRows('tour_bookings', 'org_id = ? AND travel_date >= CURDATE() AND status != ?', [$orgId, 'cancelled']);
$totalRevenue    = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM tour_bookings WHERE org_id=? AND status='confirmed'");
    $stmt->execute([$orgId]);
    $totalRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Recent bookings
$bookings = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tour_bookings WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$orgId]);
    $bookings = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage tour packages, bookings, and destinations</p>
  </div>
  <a href="bookings.php?action=add" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-plus me-2"></i>New Booking</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-suitcase"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalPackages ?></div><div class="stat-label">Tour Packages</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalBookings ?></div><div class="stat-label">Total Bookings</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-plane-departure"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $upcomingTravel ?></div><div class="stat-label">Upcoming Travel</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Total Revenue</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-calendar-check me-2" style="color:<?= $moduleColor ?>"></i>Recent Bookings</h6>
    <a href="bookings.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="tourTable">
        <thead class="table-light">
          <tr><th>Ref #</th><th>Client</th><th>Package</th><th>Destination</th><th>Travel Date</th><th>Pax</th><th>Status</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>
          <?php if (empty($bookings)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No bookings found</td></tr>
          <?php else: foreach ($bookings as $b): ?>
          <tr>
            <td class="fw-semibold"><?= e($b['reference'] ?? '#' . $b['id']) ?></td>
            <td><?= e($b['client_name'] ?? '—') ?></td>
            <td><?= e($b['package_name'] ?? '—') ?></td>
            <td><?= e($b['destination'] ?? '—') ?></td>
            <td><?= formatDate($b['travel_date'] ?? '') ?></td>
            <td><?= (int)($b['passengers'] ?? 1) ?></td>
            <td><?= statusBadge($b['status'] ?? 'pending') ?></td>
            <td class="text-end"><?= formatCurrency((float)($b['total_amount'] ?? 0)) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#tourTable").DataTable({pageLength:10,order:[[4,"asc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
