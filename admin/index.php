<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header-admin.php';

// ── Subscriptions expiring within 7 days ───────────────────────────
$expiringSoon = $pdo->query("
    SELECT o.name AS org_name, o.email AS org_email,
           s.ends_at, s.amount,
           p.name AS plan_name,
           DATEDIFF(s.ends_at, CURDATE()) AS days_left
    FROM subscriptions s
    JOIN organizations o ON s.org_id = o.id
    LEFT JOIN subscription_plans p ON s.plan_id = p.id
    WHERE s.status = 'active'
      AND s.ends_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY s.ends_at ASC
    LIMIT 20
")->fetchAll();

// ── Stats ──────────────────────────────────────────────────────────
$totalClients  = countRows('organizations');
$totalUsers    = countRows('users', "role != 'super_admin'");
$activeSubsRow = $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','trial')")->fetchColumn();
$totalRevenue  = $pdo->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid'")->fetchColumn();
$newClients30  = $pdo->query("SELECT COUNT(*) FROM organizations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

// ── Revenue chart data (last 6 months) ────────────────────────────
$chartData = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%b %Y') as month,
           COALESCE(SUM(total),0) as revenue
    FROM invoices WHERE status='paid' AND created_at >= DATE_SUB(NOW(),INTERVAL 6 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at)
    ORDER BY created_at ASC
")->fetchAll();

// ── Module popularity ─────────────────────────────────────────────
$modPop = $pdo->query("
    SELECT m.name, COUNT(sm.id) as cnt
    FROM subscription_modules sm
    JOIN modules m ON sm.module_id = m.id
    GROUP BY m.id ORDER BY cnt DESC LIMIT 8
")->fetchAll();

// ── Recent clients ────────────────────────────────────────────────
$recentClients = $pdo->query("
    SELECT o.*, s.status as sub_status, s.trial_ends_at,
           COUNT(DISTINCT sm.module_id) as module_count
    FROM organizations o
    LEFT JOIN subscriptions s ON o.id = s.org_id
    LEFT JOIN subscription_modules sm ON s.id = sm.subscription_id
    GROUP BY o.id ORDER BY o.created_at DESC LIMIT 10
")->fetchAll();
?>

<?php if (!empty($expiringSoon)): ?>
<!-- Expiring Soon Alert -->
<div class="alert alert-warning border-warning d-flex align-items-start gap-3 mb-4" role="alert">
  <i class="fas fa-exclamation-triangle fa-lg mt-1 text-warning flex-shrink-0"></i>
  <div class="w-100">
    <div class="fw-700 mb-2">
      <?= count($expiringSoon) ?> subscription<?= count($expiringSoon) > 1 ? 's' : '' ?> expiring within 7 days
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0 bg-transparent">
        <thead>
          <tr>
            <th class="border-0 ps-0">Organization</th>
            <th class="border-0">Plan</th>
            <th class="border-0">Expires</th>
            <th class="border-0 text-end pe-0">Days Left</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expiringSoon as $es): ?>
          <tr>
            <td class="ps-0 border-0">
              <div class="fw-600"><?= e($es['org_name']) ?></div>
              <div class="text-muted small"><?= e($es['org_email']) ?></div>
            </td>
            <td class="border-0"><?= e($es['plan_name'] ?? '—') ?></td>
            <td class="border-0"><?= formatDate($es['ends_at']) ?></td>
            <td class="border-0 text-end pe-0">
              <span class="badge <?= $es['days_left'] <= 1 ? 'bg-danger' : ($es['days_left'] <= 3 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                <?= $es['days_left'] === 0 ? 'Today' : $es['days_left'] . 'd' ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <a href="<?= APP_URL ?>/admin/subscriptions.php" class="btn btn-sm btn-warning mt-1">
      <i class="fas fa-credit-card me-1"></i>Manage Subscriptions
    </a>
  </div>
</div>
<?php endif; ?>

<!-- Stats row -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-building"></i></div>
      <div>
        <div class="stat-value" data-counter data-target="<?= $totalClients ?>"><?= $totalClients ?></div>
        <div class="stat-label">Total Clients</div>
        <div class="stat-change up"><i class="fas fa-arrow-up"></i> <?= $newClients30 ?> this month</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div>
        <div class="stat-value" data-counter data-target="<?= $activeSubsRow ?>"><?= $activeSubsRow ?></div>
        <div class="stat-label">Active Subscriptions</div>
        <div class="stat-change up"><i class="fas fa-chart-line"></i> Growing</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card warning">
      <div class="stat-icon warning-bg"><i class="fas fa-users"></i></div>
      <div>
        <div class="stat-value" data-counter data-target="<?= $totalUsers ?>"><?= $totalUsers ?></div>
        <div class="stat-label">Total Users</div>
        <div class="stat-change up"><i class="fas fa-user-plus"></i> Active</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-dollar-sign"></i></div>
      <div>
        <div class="stat-value"><?= 'KES ' . number_format($totalRevenue) ?></div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-change up"><i class="fas fa-arrow-up"></i> All time</div>
      </div>
    </div>
  </div>
</div>

<!-- Charts row -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <i class="fas fa-chart-line text-green me-2"></i>Revenue Overview (Last 6 Months)
        <span class="ms-auto badge bg-success">Live</span>
      </div>
      <div class="card-body">
        <div class="chart-container">
          <canvas id="revenueChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-puzzle-piece text-green me-2"></i>Module Popularity</div>
      <div class="card-body">
        <div class="chart-container" style="height:230px">
          <canvas id="moduleChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Quick actions + recent clients -->
<div class="row g-3">
  <div class="col-lg-3">
    <div class="card mb-3">
      <div class="card-header"><i class="fas fa-bolt text-green me-2"></i>Quick Actions</div>
      <div class="card-body p-2">
        <?php
        $actions = [
          [APP_URL.'/admin/clients.php?action=add','fa-plus','Add New Client','btn-outline-success'],
          [APP_URL.'/admin/subscriptions.php','fa-credit-card','Manage Subscriptions','btn-outline-primary'],
          [APP_URL.'/admin/modules.php','fa-puzzle-piece','Module Settings','btn-outline-secondary'],
          [APP_URL.'/admin/invoices.php','fa-file-invoice','View Invoices','btn-outline-warning'],
          [APP_URL.'/admin/users.php','fa-users','Manage Users','btn-outline-info'],
          [APP_URL.'/admin/settings.php','fa-cog','System Settings','btn-outline-dark'],
        ];
        foreach($actions as $a): ?>
        <a href="<?= $a[0] ?>" class="btn <?= $a[3] ?> btn-sm w-100 mb-1 text-start">
          <i class="fas <?= $a[1] ?> me-2"></i><?= $a[2] ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="fas fa-chart-pie text-green me-2"></i>Subscription Status</div>
      <div class="card-body">
        <canvas id="statusChart" height="180"></canvas>
      </div>
    </div>
  </div>

  <div class="col-lg-9">
    <div class="card">
      <div class="card-header">
        <i class="fas fa-building text-green me-2"></i>Recent Clients
        <a href="<?= APP_URL ?>/admin/clients.php" class="ms-auto btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Organization</th>
                <th>Modules</th>
                <th>Subscription</th>
                <th>Trial Ends</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($recentClients as $c): ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div class="avatar-sm" style="background:var(--navy);font-size:.7rem"><?= strtoupper(substr($c['name'], 0, 2)) ?></div>
                    <div>
                      <div class="fw-600"><?= e($c['name']) ?></div>
                      <div class="text-muted small"><?= e($c['email'] ?? '') ?></div>
                    </div>
                  </div>
                </td>
                <td><span class="badge bg-primary"><?= $c['module_count'] ?> Modules</span></td>
                <td><?= statusBadge($c['sub_status'] ?? 'none') ?></td>
                <td><span class="small"><?= $c['trial_ends_at'] ? formatDate($c['trial_ends_at']) : '—' ?></span></td>
                <td><?= statusBadge($c['status']) ?></td>
                <td>
                  <div class="d-flex gap-1">
                    <a href="<?= APP_URL ?>/admin/clients.php?view=<?= $c['id'] ?>" class="btn btn-xs btn-outline-primary" title="View"><i class="fas fa-eye"></i></a>
                    <a href="<?= APP_URL ?>/admin/clients.php?edit=<?= $c['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($recentClients)): ?>
              <tr><td colspan="6" class="text-center py-4 text-muted">No clients yet. <a href="<?= APP_URL ?>/admin/clients.php?action=add">Add the first one</a></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '
<script>
// Revenue Chart
const revCtx = document.getElementById("revenueChart");
const revData = ' . json_encode(array_values(array_column($chartData, 'revenue'))) . ';
const revLabels = ' . json_encode(array_values(array_column($chartData, 'month'))) . ';
new Chart(revCtx, {
  type: "line",
  data: {
    labels: revLabels.length ? revLabels : ["Jan","Feb","Mar","Apr","May","Jun"],
    datasets: [{
      label: "Revenue (KES)",
      data: revData.length ? revData : [0,0,0,0,0,0],
      borderColor: "#1A8A4E",
      backgroundColor: "rgba(26,138,78,.1)",
      fill: true, tension: .4,
      pointBackgroundColor: "#1A8A4E",
      pointRadius: 5
    }]
  },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, grid: { color: "rgba(0,0,0,.05)" } }, x: { grid: { display: false } } }
  }
});

// Module chart
const modCtx = document.getElementById("moduleChart");
const modLabels = ' . json_encode(array_column($modPop, 'name')) . ';
const modData   = ' . json_encode(array_column($modPop, 'cnt')) . ';
new Chart(modCtx, {
  type: "bar",
  data: {
    labels: modLabels.length ? modLabels : ["No data"],
    datasets: [{
      data: modData.length ? modData : [0],
      backgroundColor: ["#1A8A4E","#0B2D4E","#22B864","#1A4A72","#0F5C32","#1A8A4E","#0B2D4E","#22B864"],
    }]
  },
  options: { indexAxis: "y", responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { beginAtZero: true }, y: { ticks: { font: { size: 10 } } } }
  }
});

// Status donut
const stCtx = document.getElementById("statusChart");
new Chart(stCtx, {
  type: "doughnut",
  data: {
    labels: ["Active","Trial","Expired","Cancelled"],
    datasets: [{ data: [' .
    implode(',', [
        $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='active'")->fetchColumn(),
        $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='trial'")->fetchColumn(),
        $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='expired'")->fetchColumn(),
        $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='cancelled'")->fetchColumn(),
    ]) . '],
      backgroundColor: ["#1A8A4E","#f59e0b","#64748b","#ef4444"],
      borderWidth: 2, borderColor: "#fff"
    }]
  },
  options: { responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: "bottom", labels: { font: { size: 11 } } } },
    cutout: "65%"
  }
});
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
