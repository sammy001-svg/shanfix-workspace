<?php
$moduleSlug  = 'salon';
$moduleName  = 'Salon & Spa';
$moduleIcon  = 'fas fa-cut';
$moduleColor = '#c0392b';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'appointments.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Appointments'],
    ['url' => 'clients.php',      'icon' => 'fas fa-users',          'label' => 'Clients'],
    ['url' => 'services.php',     'icon' => 'fas fa-concierge-bell', 'label' => 'Services'],
    ['url' => 'staff.php',        'icon' => 'fas fa-user-tie',       'label' => 'Staff'],
    ['url' => 'packages.php',     'icon' => 'fas fa-gift',           'label' => 'Packages'],
    ['url' => 'inventory.php',    'icon' => 'fas fa-boxes',          'label' => 'Inventory'],
    ['url' => 'loyalty.php',      'icon' => 'fas fa-star',           'label' => 'Loyalty'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$todayAppts  = countRows('salon_appointments', 'org_id = ? AND DATE(appointment_date) = CURDATE()', [$orgId]);
$totalClients= countRows('salon_clients', 'org_id = ?', [$orgId]);
$totalService= countRows('salon_services', 'org_id = ? AND status = ?', [$orgId, 'active']);
$todayRevenue= 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM salon_appointments WHERE org_id=? AND DATE(appointment_date)=CURDATE() AND status='completed'");
    $stmt->execute([$orgId]);
    $todayRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Today's appointments with details
$appointments = [];
try {
    $stmt = $pdo->prepare("SELECT a.*, c.name AS client_name, s.name AS service_name, s.duration_min AS duration, CONCAT(st.first_name, ' ', st.last_name) AS stylist_name 
                           FROM salon_appointments a 
                           LEFT JOIN salon_clients c ON a.client_id = c.id 
                           LEFT JOIN salon_services s ON a.service_id = s.id 
                           LEFT JOIN salon_staff st ON a.staff_id = st.id 
                           WHERE a.org_id=? AND DATE(a.appointment_date)=CURDATE() 
                           ORDER BY a.appointment_time ASC LIMIT 10");
    $stmt->execute([$orgId]);
    $appointments = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Today's schedule — <?= date('l, d M Y') ?></p>
  </div>
  <a href="appointments.php" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-plus me-2"></i>Book Appointment</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg" style="background-color:rgba(192,57,43,0.1);color:#c0392b"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $todayAppts ?></div><div class="stat-label">Today's Appointments</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($todayRevenue) ?></div><div class="stat-label">Today's Revenue</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalClients ?></div><div class="stat-label">Total Clients</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-concierge-bell"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalService ?></div><div class="stat-label">Active Services</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-calendar-alt me-2" style="color:<?= $moduleColor ?>"></i>Today's Appointments</h6>
    <a href="appointments.php" class="btn btn-sm" style="border-color:<?= $moduleColor ?>;color:<?= $moduleColor ?>">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="salonTable">
        <thead class="table-light">
          <tr><th>Time</th><th>Client</th><th>Service</th><th>Stylist</th><th>Duration</th><th>Status</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>
          <?php if (empty($appointments)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-calendar-times fa-2x mb-2 d-block"></i>No appointments today</td></tr>
          <?php else: foreach ($appointments as $a): ?>
          <tr>
            <td class="fw-semibold"><?= date('h:i A', strtotime($a['appointment_time'] ?? 'now')) ?></td>
            <td><?= e($a['client_name'] ?? '—') ?></td>
            <td><?= e($a['service_name'] ?? '—') ?></td>
            <td><?= e($a['stylist_name'] ?? '—') ?></td>
            <td><?= e($a['duration'] ?? '—') ?> mins</td>
            <td><?= statusBadge($a['status'] ?? 'scheduled') ?></td>
            <td class="text-end fw-semibold text-success"><?= formatCurrency((float)($a['total_amount'] ?? 0)) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#salonTable").DataTable({pageLength:10,order:[[0,"asc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
