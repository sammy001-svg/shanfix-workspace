<?php
$moduleSlug  = 'church';
$moduleName  = 'Church Management';
$moduleIcon  = 'fas fa-church';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt',     'label' => 'Dashboard'],
    ['url' => 'members.php',   'icon' => 'fas fa-users',              'label' => 'Members'],
    ['url' => 'offerings.php', 'icon' => 'fas fa-hand-holding-heart', 'label' => 'Offerings'],
    ['url' => 'expenses.php',  'icon' => 'fas fa-money-bill-wave',    'label' => 'Expenses'],
    ['url' => 'attendance.php','icon' => 'fas fa-clipboard-check',    'label' => 'Attendance'],
    ['url' => 'sermons.php',   'icon' => 'fas fa-bible',              'label' => 'Sermons'],
    ['url' => 'prayers.php',   'icon' => 'fas fa-praying-hands',      'label' => 'Prayer Requests'],
    ['url' => 'pastoral.php',  'icon' => 'fas fa-hands-helping',      'label' => 'Pastoral Care'],
    ['url' => 'events.php',    'icon' => 'fas fa-calendar-alt',       'label' => 'Events'],
    ['url' => 'cells.php',     'icon' => 'fas fa-home',               'label' => 'Cells / Groups'],
    ['url' => 'pledges.php',   'icon' => 'fas fa-handshake',          'label' => 'Pledges'],
    ['url' => 'projects.php',  'icon' => 'fas fa-project-diagram',    'label' => 'Projects'],
    ['url' => 'notices.php',   'icon' => 'fas fa-bell',               'label' => 'Notices'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalMembers    = countRows('church_members', 'org_id = ?', [$orgId]);
$totalEvents     = countRows('church_events', 'org_id = ? AND start_date >= CURDATE()', [$orgId]);
$todayOfferings  = 0;
$totalTithe      = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM church_offerings WHERE org_id=? AND DATE(date)=CURDATE()");
    $stmt->execute([$orgId]);
    $todayOfferings = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM church_offerings WHERE org_id=? AND type='tithe'");
    $stmt->execute([$orgId]);
    $totalTithe = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Recent offerings
$offerings = [];
try {
    $stmt = $pdo->prepare("SELECT o.*, 
                                  CONCAT(m.first_name, ' ', m.last_name) AS member_name,
                                  u.name AS receiver_name
                           FROM church_offerings o
                           LEFT JOIN church_members m ON o.member_id = m.id
                           LEFT JOIN users u ON o.received_by = u.id
                           WHERE o.org_id=? 
                           ORDER BY o.date DESC 
                           LIMIT 10");
    $stmt->execute([$orgId]);
    $offerings = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Track ministry rosters, manage offerings, tithes collections, and upcoming church events</p>
  </div>
  <a href="offerings.php" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-plus me-2"></i>Record Tithe / Offering</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalMembers ?></div><div class="stat-label">Active Members</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hand-holding-heart"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($todayOfferings) ?></div><div class="stat-label">Today's Collections</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalTithe) ?></div><div class="stat-label">Total Tithes</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalEvents ?></div><div class="stat-label">Upcoming Ministry Events</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-hand-holding-heart me-2" style="color:<?= $moduleColor ?>"></i>Recent Contributions Registry</h6>
    <a href="offerings.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="churchTable">
        <thead class="table-light">
          <tr><th>Contribution Date</th><th>Member / Donor</th><th>Category</th><th>Reference</th><th>Recorded By</th><th class="text-end">Amount Paid</th></tr>
        </thead>
        <tbody>
          <?php if (empty($offerings)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No offerings logged yet.</td></tr>
          <?php else: foreach ($offerings as $o): ?>
          <tr>
            <td><?= formatDate($o['date'] ?? '') ?></td>
            <td class="fw-bold text-dark"><?= e($o['member_name'] ?: 'Anonymous Donor') ?></td>
            <td><span class="badge bg-light text-dark border"><?= strtoupper($o['type'] ?? 'offering') ?></span></td>
            <td><span class="badge bg-secondary"><?= e($o['reference'] ?: 'Cash-Tx') ?></span></td>
            <td><?= e($o['receiver_name'] ?: 'System') ?></td>
            <td class="text-end fw-bold text-dark"><?= formatCurrency((float)($o['amount'] ?? 0)) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#churchTable").DataTable({pageLength:10,order:[[0,"desc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
