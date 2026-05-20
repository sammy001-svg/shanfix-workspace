<?php
$moduleSlug = 'meetings';
$moduleName = 'Meetings & Minutes';
$moduleIcon = 'fas fa-video';
$moduleColor = '#0B2D4E';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'meetings.php',     'icon' => 'fas fa-video',          'label' => 'Meetings'],
    ['url' => 'minutes.php',      'icon' => 'fas fa-file-alt',       'label' => 'Minutes'],
    ['url' => 'actions.php',      'icon' => 'fas fa-tasks',          'label' => 'Action Items'],
    ['url' => 'participants.php', 'icon' => 'fas fa-address-book',   'label' => 'Participants'],
    ['url' => 'calendar.php',     'icon' => 'fas fa-calendar',       'label' => 'Calendar'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

// Aggregated metrics
$total = countRows('meetings', 'org_id = ?', [$orgId]);
$scheduled = countRows('meetings', "org_id = ? AND status = 'scheduled'", [$orgId]);
$ongoing = countRows('meetings', "org_id = ? AND status = 'ongoing'", [$orgId]);
$completed = countRows('meetings', "org_id = ? AND status = 'completed'", [$orgId]);
$cancelled = countRows('meetings', "org_id = ? AND status = 'cancelled'", [$orgId]);

$minutesCount = countRows('meeting_minutes mm JOIN meetings m ON mm.meeting_id = m.id', 'm.org_id = ?', [$orgId]);

// Location type aggregation
$typeStats = ['physical' => 0, 'virtual' => 0, 'hybrid' => 0];
try {
    $stmt = $pdo->prepare("SELECT type, COUNT(*) AS count FROM meetings WHERE org_id = ? GROUP BY type");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $row) {
        $typeStats[$row['type']] = (int)$row['count'];
    }
} catch (Exception $e) {}

// Monthly trends (last 6 months)
$monthlyTrends = [];
try {
    for ($i = 5; $i >= 0; $i--) {
        $monthStr = date('Y-m', strtotime("-$i months"));
        $label = date('M Y', strtotime("-$i months"));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM meetings WHERE org_id = ? AND DATE_FORMAT(meeting_date, '%Y-%m') = ?");
        $stmt->execute([$orgId, $monthStr]);
        $row = $stmt->fetch();
        $monthlyTrends[$label] = (int)($row['count'] ?? 0);
    }
} catch (Exception $e) {}

// Top organizers / hosts list
$topOrganizers = [];
try {
    $stmt = $pdo->prepare("SELECT u.name, COUNT(m.id) AS sessions_count 
                           FROM meetings m
                           JOIN users u ON m.organizer_id = u.id
                           WHERE m.org_id = ?
                           GROUP BY m.organizer_id
                           ORDER BY sessions_count DESC
                           LIMIT 5");
    $stmt->execute([$orgId]);
    $topOrganizers = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Meetings Reports & Analytics</h4>
    <p class="text-muted mb-0">Track host productivity, meeting type distributions, and scheduling statistics</p>
  </div>
  <div class="d-flex gap-2">
    <a href="report-pdf.php" class="btn btn-outline-secondary"><i class="fas fa-file-pdf me-1"></i>Export PDF</a>
    <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-video"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $total ?></div>
        <div class="stat-label">Total Meetings</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon info-bg"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $scheduled ?></div>
        <div class="stat-label">Scheduled / Upcoming</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $completed ?></div>
        <div class="stat-label">Completed Sessions</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-file-signature"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $minutesCount ?></div>
        <div class="stat-label">Minute Records Saved</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Monthly Trend Chart -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-line me-2 text-primary"></i>6-Month Session Scheduling Trend</h6></div>
      <div class="card-body">
        <div style="height:300px;"><canvas id="trendChart"></canvas></div>
      </div>
    </div>
  </div>
  
  <!-- Location Type Distribution -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-pie me-2 text-primary"></i>Meeting Location Distribution</h6></div>
      <div class="card-body d-flex flex-column justify-content-center">
        <div style="height:220px;"><canvas id="typeChart"></canvas></div>
        <div class="mt-3 text-center small text-muted">
          Physical: <strong><?= $typeStats['physical'] ?></strong> &nbsp;|&nbsp; 
          Virtual: <strong><?= $typeStats['virtual'] ?></strong> &nbsp;|&nbsp; 
          Hybrid: <strong><?= $typeStats['hybrid'] ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Active Hosts Roster -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-user-tie me-2 text-primary"></i>Top Active Meeting Organizers</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Host / Organizer</th><th class="text-end">Sessions Managed</th></tr>
            </thead>
            <tbody>
              <?php if (empty($topOrganizers)): ?>
              <tr><td colspan="2" class="text-center text-muted py-4">No host tracking statistics found.</td></tr>
              <?php else: foreach ($topOrganizers as $org): ?>
              <tr>
                <td class="fw-semibold text-dark"><?= e($org['name']) ?></td>
                <td class="text-end fw-bold text-primary"><?= $org['sessions_count'] ?> sessions</td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Status Distribution Card -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-info-circle me-2 text-primary"></i>Status Distribution</h6></div>
      <div class="card-body d-flex flex-column justify-content-between">
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1 small fw-semibold">
            <span>Scheduled</span>
            <span><?= $total > 0 ? round(($scheduled/$total)*100, 1) : 0 ?>% (<?= $scheduled ?>)</span>
          </div>
          <div class="progress" style="height:8px;"><div class="progress-bar bg-info" style="width: <?= $total > 0 ? ($scheduled/$total)*100 : 0 ?>%"></div></div>
        </div>
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1 small fw-semibold">
            <span>Ongoing</span>
            <span><?= $total > 0 ? round(($ongoing/$total)*100, 1) : 0 ?>% (<?= $ongoing ?>)</span>
          </div>
          <div class="progress" style="height:8px;"><div class="progress-bar bg-warning" style="width: <?= $total > 0 ? ($ongoing/$total)*100 : 0 ?>%"></div></div>
        </div>
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1 small fw-semibold">
            <span>Completed</span>
            <span><?= $total > 0 ? round(($completed/$total)*100, 1) : 0 ?>% (<?= $completed ?>)</span>
          </div>
          <div class="progress" style="height:8px;"><div class="progress-bar bg-success" style="width: <?= $total > 0 ? ($completed/$total)*100 : 0 ?>%"></div></div>
        </div>
        <div>
          <div class="d-flex justify-content-between mb-1 small fw-semibold">
            <span>Cancelled</span>
            <span><?= $total > 0 ? round(($cancelled/$total)*100, 1) : 0 ?>% (<?= $cancelled ?>)</span>
          </div>
          <div class="progress" style="height:8px;"><div class="progress-bar bg-danger" style="width: <?= $total > 0 ? ($cancelled/$total)*100 : 0 ?>%"></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php
$trendLabelsJson = json_encode(array_keys($monthlyTrends));
$trendDataJson   = json_encode(array_values($monthlyTrends));

$typeLabelsJson = json_encode(['Physical Room', 'Virtual Call', 'Hybrid']);
$typeDataJson   = json_encode([$typeStats['physical'], $typeStats['virtual'], $typeStats['hybrid']]);

$extraJs = <<<JS
<script>
// Trend Line Chart
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: {$trendLabelsJson},
    datasets: [{
      label: 'Scheduled Meetings',
      data: {$trendDataJson},
      borderColor: '#0B2D4E',
      backgroundColor: 'rgba(11, 45, 78, 0.08)',
      fill: true,
      tension: 0.3,
      borderWidth: 2
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 } }
    }
  }
});

// Type Doughnut Chart
new Chart(document.getElementById('typeChart'), {
  type: 'doughnut',
  data: {
    labels: {$typeLabelsJson},
    datasets: [{
      data: {$typeDataJson},
      backgroundColor: ['#dc3545', '#0b5ed7', '#ffca28']
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
