<?php
$moduleSlug = 'salon';
$moduleName = 'Salon & Spa';
$moduleIcon = 'fas fa-cut';
$moduleColor = '#c0392b';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'appointments.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Appointments'],
    ['url' => 'clients.php',      'icon' => 'fas fa-users',          'label' => 'Clients'],
    ['url' => 'services.php',     'icon' => 'fas fa-concierge-bell', 'label' => 'Services'],
    ['url' => 'staff.php',        'icon' => 'fas fa-user-tie',       'label' => 'Staff'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

// KPI statistics
$totalBookings = countRows('salon_appointments', 'org_id = ?', [$orgId]);
$completedCount = countRows('salon_appointments', "org_id = ? AND status = 'completed'", [$orgId]);
$activeCount = countRows('salon_appointments', "org_id = ? AND status IN ('scheduled', 'in_progress')", [$orgId]);
$cancelledCount = countRows('salon_appointments', "org_id = ? AND status = 'cancelled'", [$orgId]);

$totalRevenue = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM salon_appointments WHERE org_id = ? AND status = 'completed'");
    $stmt->execute([$orgId]);
    $totalRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$avgTicket = $completedCount > 0 ? $totalRevenue / $completedCount : 0;

$totalClients = countRows('salon_clients', 'org_id = ?', [$orgId]);
$totalStaff = countRows('salon_staff', 'org_id = ? AND status = \'active\'', [$orgId]);

// Monthly sales data (6 months)
$months = [];
$monthlyRevenue = [];
$monthlyCount = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $months[] = date('M Y', strtotime("-$i months"));
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0), COUNT(*) FROM salon_appointments WHERE org_id = ? AND status = 'completed' AND DATE_FORMAT(appointment_date, '%Y-%m') = ?");
        $stmt->execute([$orgId, $m]);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $monthlyRevenue[] = (float)$row[0];
        $monthlyCount[] = (int)$row[1];
    } catch (Exception $e) {
        $monthlyRevenue[] = 0;
        $monthlyCount[] = 0;
    }
}

// Appointment status breakdown
$statuses = ['scheduled', 'in_progress', 'completed', 'cancelled', 'no_show'];
$statusCounts = [];
foreach ($statuses as $s) {
    $statusCounts[] = countRows('salon_appointments', 'org_id = ? AND status = ?', [$orgId, $s]);
}

// Top 5 popular services
$popularServices = [];
try {
    $stmt = $pdo->prepare("SELECT s.name, COUNT(a.id) AS cnt, SUM(a.total_amount) AS revenue 
                           FROM salon_appointments a 
                           JOIN salon_services s ON a.service_id = s.id 
                           WHERE a.org_id = ? AND a.status = 'completed' 
                           GROUP BY a.service_id 
                           ORDER BY cnt DESC 
                           LIMIT 5");
    $stmt->execute([$orgId]);
    $popularServices = $stmt->fetchAll();
} catch (Exception $e) {}

// Stylist Performance Table
$staffPerformance = [];
try {
    $stmt = $pdo->prepare("SELECT CONCAT(st.first_name, ' ', st.last_name) AS stylist_name, st.speciality, COUNT(a.id) AS total_appts, COALESCE(SUM(CASE WHEN a.status='completed' THEN a.total_amount ELSE 0 END), 0) AS total_sales
                           FROM salon_staff st 
                           LEFT JOIN salon_appointments a ON st.id = a.staff_id 
                           WHERE st.org_id = ?
                           GROUP BY st.id 
                           ORDER BY total_sales DESC");
    $stmt->execute([$orgId]);
    $staffPerformance = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Reports & Analytics</h4>
    <p class="text-muted mb-0">Evaluate beauty salon performance, stylist productivity, and booking trends</p>
  </div>
  <span class="text-muted small">Generated on <?= date('d M Y, h:i A') ?></span>
</div>

<div class="row g-3 mb-4">
  <?php foreach([
    ['green-bg', 'fas fa-dollar-sign', formatCurrency($totalRevenue), 'Total Realized Revenue'],
    ['info-bg', 'fas fa-calendar-check', $totalBookings, 'Total Booking Slots'],
    ['navy-bg', 'fas fa-user-friends', $totalClients, 'Salon Client Database'],
    ['warning-bg', 'fas fa-star', formatCurrency($avgTicket), 'Avg Session Value']
  ] as [$cl, $ic, $v, $lb]): ?>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $cl ?>"><i class="<?= $ic ?>"></i></div>
      <div class="stat-body">
        <div class="stat-value" style="font-size:1.1rem"><?= $v ?></div>
        <div class="stat-label"><?= $lb ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-4">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-area me-2" style="color:<?= $moduleColor ?>"></i>Monthly Session Revenue (KES)</h6></div>
  <div class="card-body"><canvas id="revenueChart" height="90"></canvas></div>
</div>

<div class="row g-4 mb-4">
  <!-- Booking Breakdown -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Appointments by Status</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="statusChart" height="230"></canvas></div>
    </div>
  </div>
  <!-- Top Performing Services -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-crown me-2" style="color:<?= $moduleColor ?>"></i>Popular Services & treatments</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-hover mb-0">
          <thead class="table-light"><tr><th>Service Name</th><th class="text-end">Completed Bookings</th><th class="text-end">Sales Generated</th></tr></thead>
          <tbody>
            <?php if (empty($popularServices)): ?>
            <tr><td colspan="3" class="text-center text-muted py-4">No completed bookings recorded yet.</td></tr>
            <?php else: foreach ($popularServices as $ps): ?>
            <tr>
              <td class="fw-semibold"><?= e($ps['name']) ?></td>
              <td class="text-end fw-semibold text-primary"><?= $ps['cnt'] ?></td>
              <td class="text-end fw-semibold text-success"><?= formatCurrency((float)$ps['revenue']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-user-tie me-2" style="color:<?= $moduleColor ?>"></i>Stylist & Barber Performance</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive"><table class="table table-hover mb-0">
      <thead class="table-light"><tr><th>Stylist Name</th><th>Speciality</th><th class="text-end">Total Bookings</th><th class="text-end">Sales Realized</th></tr></thead>
      <tbody>
        <?php if (empty($staffPerformance)): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">No staff activity data yet.</td></tr>
        <?php else: foreach ($staffPerformance as $sp): ?>
        <tr>
          <td class="fw-semibold"><?= e($sp['stylist_name']) ?></td>
          <td><span class="badge bg-light text-dark border"><?= e($sp['speciality'] ?: 'Stylist') ?></span></td>
          <td class="text-end"><?= $sp['total_appts'] ?></td>
          <td class="text-end fw-semibold text-success"><?= formatCurrency((float)$sp['total_sales']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php
$mJ = json_encode($months);
$mrJ = json_encode($monthlyRevenue);
$sNames = json_encode(array_map(fn($st) => ucfirst(str_replace('_', ' ', $st)), $statuses));
$sCounts = json_encode($statusCounts);
$extraJs = <<<JS
<script>
(function(){
  const color = '{$moduleColor}';
  new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
      labels: $mJ,
      datasets: [{
        label: 'Revenue (KES)',
        data: $mrJ,
        borderColor: color,
        backgroundColor: color + '22',
        fill: true,
        tension: 0.4,
        pointBackgroundColor: color,
        pointRadius: 5
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });

  new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
      labels: $sNames,
      datasets: [{
        data: $sCounts,
        backgroundColor: ['#17a2b8', '#ffc107', '#28a745', '#dc3545', '#6c757d']
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom' } }
    }
  });
})();
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
