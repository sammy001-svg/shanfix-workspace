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
$user = currentUser();
$orgId = (int)$user['org_id'];

// General Counts
$totalMembers = countRows('church_members', 'org_id = ?', [$orgId]);
$activeMembers = countRows('church_members', "org_id = ? AND status = 'active'", [$orgId]);
$baptizedCount = countRows('church_members', "org_id = ? AND baptized = 1", [$orgId]);

$baptismRate = $totalMembers > 0 ? round(($baptizedCount / $totalMembers) * 100, 1) : 0;

$totalTithes = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM church_offerings WHERE org_id = ? AND type = 'tithe'");
    $stmt->execute([$orgId]);
    $totalTithes = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$totalOfferings = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM church_offerings WHERE org_id = ? AND type = 'offering'");
    $stmt->execute([$orgId]);
    $totalOfferings = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Offerings categories breakdown
$titheSum = 0;
$offeringSum = 0;
$fruitSum = 0;
$buildingSum = 0;
$missionSum = 0;
$welfareSum = 0;

try {
    $stmt = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) AS total 
                           FROM church_offerings 
                           WHERE org_id = ? 
                           GROUP BY type");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $row) {
        if ($row['type'] === 'tithe') $titheSum = (float)$row['total'];
        elseif ($row['type'] === 'offering') $offeringSum = (float)$row['total'];
        elseif ($row['type'] === 'first_fruit') $fruitSum = (float)$row['total'];
        elseif ($row['type'] === 'building_fund') $buildingSum = (float)$row['total'];
        elseif ($row['type'] === 'mission') $missionSum = (float)$row['total'];
        elseif ($row['type'] === 'welfare') $welfareSum = (float)$row['total'];
    }
} catch (Exception $e) {}

// Monthly offerings trend
$monthlyAmounts = [];
$monthlyLabels = [];
try {
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(date, '%b %Y') AS month_label, 
                                  COALESCE(SUM(amount), 0) AS total,
                                  DATE_FORMAT(date, '%Y-%m') AS month_sort
                           FROM church_offerings 
                           WHERE org_id = ? 
                           GROUP BY month_label, month_sort
                           ORDER BY month_sort ASC 
                           LIMIT 6");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $row) {
        $monthlyLabels[] = $row['month_label'];
        $monthlyAmounts[] = (float)$row['total'];
    }
} catch (Exception $e) {}

if (empty($monthlyLabels)) {
    $monthlyLabels = [date('F')];
    $monthlyAmounts = [0];
}

// Gender breakdown
$maleCount = countRows('church_members', "org_id = ? AND gender = 'male'", [$orgId]);
$femaleCount = countRows('church_members', "org_id = ? AND gender = 'female'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Ministry Reports & Analytics</h4>
    <p class="text-muted mb-0">Review congregation demographics, worship collections categories, and baptism status ratios</p>
  </div>
  <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print Analytics Summary</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalMembers ?></div>
        <div class="stat-label">Total Congregation</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalTithes) ?></div>
        <div class="stat-label">Total Tithes Collected</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hand-holding-heart"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalOfferings) ?></div>
        <div class="stat-label">Total Offerings</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-water"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $baptismRate ?>%</div>
        <div class="stat-label">Holy Baptism Rate</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Contribution trend bar chart -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold text-dark"><i class="fas fa-chart-line me-2 text-primary"></i>Cumulative Contributions Trend (Last 6 Months)</h6></div>
      <div class="card-body">
        <div style="height:280px;"><canvas id="worshipTrendChart"></canvas></div>
      </div>
    </div>
  </div>

  <!-- Gender demograph -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold text-dark"><i class="fas fa-venus-mars me-2 text-primary"></i>Membership Demographics</h6></div>
      <div class="card-body d-flex flex-column justify-content-center">
        <div style="height:200px;"><canvas id="genderChart"></canvas></div>
        <div class="mt-3 text-center small text-muted">
          Male Members: <strong><?= $maleCount ?></strong> &nbsp;|&nbsp; 
          Female Members: <strong><?= $femaleCount ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Worship Categories Distributions Table -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold text-dark"><i class="fas fa-hand-holding-heart me-2 text-primary"></i>Worship Category Ledger Distributions</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Contribution Category</th><th class="text-end">Total Sum</th></tr>
            </thead>
            <tbody>
              <tr><td class="fw-bold text-dark">Tithes</td><td class="text-end fw-bold text-primary"><?= formatCurrency($titheSum) ?></td></tr>
              <tr><td class="fw-bold text-dark">Worship Offerings</td><td class="text-end fw-bold text-primary"><?= formatCurrency($offeringSum) ?></td></tr>
              <tr><td class="fw-bold text-dark">First Fruits</td><td class="text-end fw-bold text-primary"><?= formatCurrency($fruitSum) ?></td></tr>
              <tr><td class="fw-bold text-dark">Building & Development Drives</td><td class="text-end fw-bold text-primary"><?= formatCurrency($buildingSum) ?></td></tr>
              <tr><td class="fw-bold text-dark">Missions & Evangelism Funds</td><td class="text-end fw-bold text-primary"><?= formatCurrency($missionSum) ?></td></tr>
              <tr><td class="fw-bold text-dark">Welfare & Benevolence</td><td class="text-end fw-bold text-primary"><?= formatCurrency($welfareSum) ?></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Baptism breakdown -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold text-dark"><i class="fas fa-water me-2 text-primary"></i>Holy Sacrament Confirmation status</h6></div>
      <div class="card-body d-flex flex-column justify-content-center align-items-center">
        <div style="font-size: 70px;color: #16a34a;"><i class="fas fa-water"></i></div>
        <h3 class="fw-bold text-dark mt-2 mb-1"><?= $baptizedCount ?> Members Baptized</h3>
        <p class="text-muted text-center small px-4">
          A total of <strong><?= $baptizedCount ?></strong> out of <strong><?= $totalMembers ?></strong> registered church members (<?= $baptismRate ?>%) have received Holy Baptism.
        </p>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php
$jsLabels = json_encode($monthlyLabels);
$jsAmounts = json_encode($monthlyAmounts);

$genderLabels = json_encode(['Male Congregants', 'Female Congregants']);
$genderData   = json_encode([$maleCount, $femaleCount]);

$extraJs = <<<JS
<script>
// Contributions Bar Chart
new Chart(document.getElementById('worshipTrendChart'), {
  type: 'bar',
  data: {
    labels: {$jsLabels},
    datasets: [{
      label: 'Monthly Collections',
      data: {$jsAmounts},
      backgroundColor: '#8e44ad',
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

// Demographics Doughnut Chart
new Chart(document.getElementById('genderChart'), {
  type: 'doughnut',
  data: {
    labels: {$genderLabels},
    datasets: [{
      data: {$genderData},
      backgroundColor: ['#2563eb', '#ec4899']
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
