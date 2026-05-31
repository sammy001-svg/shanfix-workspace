<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header-admin.php';

// ── Expiring within 7 days ────────────────────────────────────────
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

// ── Core KPIs ─────────────────────────────────────────────────────
$totalClients   = (int)countRows('organizations');
$totalUsers     = (int)countRows('users', "role != 'super_admin'");
$activeSubsRow  = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','trial')")->fetchColumn();
$totalRevenue   = (float)$pdo->query("SELECT COALESCE(SUM(CAST(total AS DECIMAL(12,2))),0) FROM invoices WHERE status='paid'")->fetchColumn();
$newClients30   = (int)$pdo->query("SELECT COUNT(*) FROM organizations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
$newClients60   = (int)$pdo->query("SELECT COUNT(*) FROM organizations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
$clientGrowthPct = $newClients60 > 0 ? round(($newClients30 - $newClients60) / $newClients60 * 100, 1) : ($newClients30 > 0 ? 100 : 0);

// MRR — normalize annual plans to monthly
$mrr = (float)$pdo->query("
    SELECT COALESCE(SUM(CASE WHEN billing_cycle='annual' THEN amount/12.0 ELSE amount END), 0)
    FROM subscriptions WHERE status='active'
")->fetchColumn();

// This month vs last month revenue
$thisMonthRev = (float)$pdo->query("SELECT COALESCE(SUM(CAST(total AS DECIMAL(12,2))),0) FROM invoices WHERE status='paid' AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())")->fetchColumn();
$lastMonthRev = (float)$pdo->query("SELECT COALESCE(SUM(CAST(total AS DECIMAL(12,2))),0) FROM invoices WHERE status='paid' AND YEAR(created_at)=YEAR(DATE_SUB(NOW(),INTERVAL 1 MONTH)) AND MONTH(created_at)=MONTH(DATE_SUB(NOW(),INTERVAL 1 MONTH))")->fetchColumn();
$revChangePct = $lastMonthRev > 0 ? round(($thisMonthRev - $lastMonthRev) / $lastMonthRev * 100, 1) : ($thisMonthRev > 0 ? 100 : 0);

// Open support tickets
try {
    $openTickets   = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')")->fetchColumn();
    $recentTickets = $pdo->query("
        SELECT t.id, t.ticket_number, t.subject, t.priority, t.status, t.updated_at,
               o.name AS org_name
        FROM support_tickets t
        JOIN organizations o ON t.org_id = o.id
        WHERE t.status IN ('open','in_progress')
        ORDER BY t.updated_at DESC
        LIMIT 6
    ")->fetchAll();
} catch (Exception $e) { $openTickets = 0; $recentTickets = []; }

// Invoice pipeline
$invPipeline = ['paid' => 0, 'sent' => 0, 'pending' => 0, 'overdue' => 0];
$invAmounts  = ['paid' => 0, 'sent' => 0, 'pending' => 0, 'overdue' => 0];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) as cnt, COALESCE(SUM(CAST(total AS DECIMAL(12,2))),0) as amt FROM invoices GROUP BY status")->fetchAll() as $r) {
        if (isset($invPipeline[$r['status']])) {
            $invPipeline[$r['status']] = (int)$r['cnt'];
            $invAmounts[$r['status']]  = (float)$r['amt'];
        }
    }
} catch (Exception $e) {}

// Trial conversion rate (all time)
try {
    $trialsTotal     = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE trial_ends_at IS NOT NULL")->fetchColumn();
    $trialsConverted = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND trial_ends_at IS NOT NULL")->fetchColumn();
    $convRate = $trialsTotal > 0 ? round($trialsConverted / $trialsTotal * 100) : 0;
} catch (Exception $e) { $trialsTotal = $trialsConverted = 0; $convRate = 0; }

// Revenue chart — last 12 months
$chartData = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%b %Y') AS month,
           COALESCE(SUM(total), 0) AS revenue
    FROM invoices
    WHERE status='paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at)
    ORDER BY created_at ASC
")->fetchAll();

// Client growth — last 6 months
$clientGrowthData = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%b %Y') AS month, COUNT(*) AS cnt
    FROM organizations
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at)
    ORDER BY created_at ASC
")->fetchAll();

// Module popularity
$modPop = $pdo->query("
    SELECT m.name, COUNT(sm.id) AS cnt
    FROM subscription_modules sm
    JOIN modules m ON sm.module_id = m.id
    WHERE sm.status = 'active'
    GROUP BY m.id ORDER BY cnt DESC LIMIT 8
")->fetchAll();

// Subscription status counts
$subStatusCounts = [];
foreach (['active','trial','expired','cancelled','suspended'] as $st) {
    $subStatusCounts[$st] = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='$st'")->fetchColumn();
}

// Recent clients
$recentClients = $pdo->query("
    SELECT o.*, s.status AS sub_status, s.trial_ends_at,
           COUNT(DISTINCT sm.module_id) AS module_count
    FROM organizations o
    LEFT JOIN subscriptions s ON o.id = s.org_id
    LEFT JOIN subscription_modules sm ON s.id = sm.subscription_id
    GROUP BY o.id ORDER BY o.created_at DESC LIMIT 8
")->fetchAll();

// Recent activity
try {
    $recentActivity = $pdo->query("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT 10")->fetchAll();
} catch (Exception $e) { $recentActivity = []; }

// Helpers
$priorityColors = ['urgent' => 'danger', 'high' => 'warning', 'normal' => 'primary', 'low' => 'secondary'];
?>

<?php if (!empty($expiringSoon)): ?>
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
                <?= $es['days_left'] == 0 ? 'Today' : $es['days_left'] . 'd' ?>
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

<!-- ── KPI Cards ─────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-building"></i></div>
      <div>
        <div class="stat-value" data-counter data-target="<?= $totalClients ?>"><?= $totalClients ?></div>
        <div class="stat-label">Total Clients</div>
        <div class="stat-change <?= $clientGrowthPct >= 0 ? 'up' : 'down' ?>">
          <i class="fas fa-arrow-<?= $clientGrowthPct >= 0 ? 'up' : 'down' ?>"></i>
          <?= $newClients30 ?> new this month
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div>
        <div class="stat-value" data-counter data-target="<?= $activeSubsRow ?>"><?= $activeSubsRow ?></div>
        <div class="stat-label">Active Subscriptions</div>
        <div class="stat-change up">
          <i class="fas fa-sync-alt"></i>
          <?= $subStatusCounts['trial'] ?> on trial
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-chart-line"></i></div>
      <div>
        <div class="stat-value">KES <?= number_format($mrr) ?></div>
        <div class="stat-label">MRR</div>
        <div class="stat-change up"><i class="fas fa-calendar-alt"></i> Monthly Recurring</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card warning">
      <div class="stat-icon warning-bg"><i class="fas fa-coins"></i></div>
      <div>
        <div class="stat-value">KES <?= number_format($thisMonthRev) ?></div>
        <div class="stat-label">This Month</div>
        <div class="stat-change <?= $revChangePct >= 0 ? 'up' : 'down' ?>">
          <i class="fas fa-arrow-<?= $revChangePct >= 0 ? 'up' : 'down' ?>"></i>
          <?= abs($revChangePct) ?>% vs last month
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
      <div>
        <div class="stat-value" data-counter data-target="<?= $totalUsers ?>"><?= $totalUsers ?></div>
        <div class="stat-label">Total Users</div>
        <div class="stat-change up"><i class="fas fa-user-shield"></i> Across all orgs</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card <?= $openTickets > 5 ? 'warning' : 'green' ?>">
      <div class="stat-icon <?= $openTickets > 5 ? 'warning-bg' : 'green-bg' ?>"><i class="fas fa-ticket-alt"></i></div>
      <div>
        <div class="stat-value" data-counter data-target="<?= $openTickets ?>"><?= $openTickets ?></div>
        <div class="stat-label">Open Tickets</div>
        <div class="stat-change <?= $openTickets > 0 ? 'down' : 'up' ?>">
          <i class="fas fa-<?= $openTickets > 0 ? 'clock' : 'check' ?>"></i>
          <?= $openTickets > 0 ? 'Awaiting response' : 'All resolved' ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Revenue + Status ──────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header">
        <i class="fas fa-chart-area text-green me-2"></i>Revenue Overview
        <span class="text-muted small ms-1">(Last 12 months)</span>
        <span class="ms-auto badge bg-success">KES <?= number_format($totalRevenue) ?> total</span>
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
      <div class="card-header"><i class="fas fa-chart-pie text-green me-2"></i>Subscription Status</div>
      <div class="card-body d-flex flex-column justify-content-between">
        <canvas id="statusChart" style="max-height:160px"></canvas>
        <div class="mt-3">
          <?php
          $statusInfo = [
            'active'    => ['bg-success', 'Active'],
            'trial'     => ['bg-warning text-dark', 'Trial'],
            'expired'   => ['bg-secondary', 'Expired'],
            'cancelled' => ['bg-danger', 'Cancelled'],
            'suspended' => ['bg-dark', 'Suspended'],
          ];
          foreach ($statusInfo as $st => [$badge, $label]):
            $cnt = $subStatusCounts[$st];
            if (!$cnt) continue;
          ?>
          <div class="d-flex justify-content-between align-items-center mb-1 small">
            <span class="badge <?= $badge ?>"><?= $label ?></span>
            <strong><?= $cnt ?></strong>
          </div>
          <?php endforeach; ?>
          <?php if ($trialsTotal > 0): ?>
          <hr class="my-2">
          <div class="d-flex justify-content-between align-items-center small text-muted">
            <span><i class="fas fa-percent me-1"></i>Trial conversion</span>
            <span class="fw-600 <?= $convRate >= 50 ? 'text-success' : 'text-warning' ?>"><?= $convRate ?>%</span>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Client Growth + Invoice Pipeline + Module Popularity ─────── -->
<div class="row g-3 mb-4">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-bar text-green me-2"></i>Client Growth
        <span class="text-muted small ms-1">(Last 6 months)</span>
      </div>
      <div class="card-body">
        <div class="chart-container" style="height:200px">
          <canvas id="clientGrowthChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-3">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-file-invoice text-green me-2"></i>Invoice Pipeline</div>
      <div class="card-body">
        <?php
        $pipelineConfig = [
          'paid'    => ['bg-success',   'fa-check-circle',    'Paid'],
          'sent'    => ['bg-primary',   'fa-paper-plane',     'Sent / Awaiting'],
          'pending' => ['bg-warning',   'fa-clock',           'Pending'],
          'overdue' => ['bg-danger',    'fa-exclamation-circle', 'Overdue'],
        ];
        $totalInvs = array_sum($invPipeline);
        foreach ($pipelineConfig as $st => [$bg, $icon, $label]):
          $cnt = $invPipeline[$st];
          $amt = $invAmounts[$st];
          $pct = $totalInvs > 0 ? round($cnt / $totalInvs * 100) : 0;
        ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1 small">
            <span class="d-flex align-items-center gap-1">
              <i class="fas <?= $icon ?> text-<?= explode('-', $bg)[1] ?? 'secondary' ?>"></i>
              <span class="fw-600"><?= $label ?></span>
            </span>
            <span class="badge <?= $bg ?>"><?= $cnt ?></span>
          </div>
          <div class="progress mb-0" style="height:5px">
            <div class="progress-bar <?= $bg ?>" style="width:<?= $pct ?>%"></div>
          </div>
          <div class="text-muted" style="font-size:.7rem">KES <?= number_format($amt) ?></div>
        </div>
        <?php endforeach; ?>
        <a href="<?= APP_URL ?>/admin/invoices.php" class="btn btn-sm btn-outline-primary w-100 mt-1">
          <i class="fas fa-external-link-alt me-1"></i>View All Invoices
        </a>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-puzzle-piece text-green me-2"></i>Module Popularity</div>
      <div class="card-body">
        <div class="chart-container" style="height:200px">
          <canvas id="moduleChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Quick Actions + Open Tickets + Activity Feed ─────────────── -->
<div class="row g-3 mb-4">
  <!-- Quick actions -->
  <div class="col-lg-3">
    <div class="card mb-3">
      <div class="card-header"><i class="fas fa-bolt text-green me-2"></i>Quick Actions</div>
      <div class="card-body p-2">
        <?php
        $actions = [
          [APP_URL.'/admin/clients.php?action=add', 'fa-plus',          'Add New Client',        'btn-outline-success'],
          [APP_URL.'/admin/subscriptions.php',       'fa-credit-card',   'Manage Subscriptions',  'btn-outline-primary'],
          [APP_URL.'/admin/invoices.php',            'fa-file-invoice',  'View Invoices',         'btn-outline-warning'],
          [APP_URL.'/admin/support.php',             'fa-ticket-alt',    'Support Tickets',       'btn-outline-danger'],
          [APP_URL.'/admin/modules.php',             'fa-puzzle-piece',  'Module Settings',       'btn-outline-secondary'],
          [APP_URL.'/admin/users.php',               'fa-users',         'Manage Users',          'btn-outline-info'],
          [APP_URL.'/admin/notifications.php',       'fa-bell',          'Send Notification',     'btn-outline-dark'],
          [APP_URL.'/admin/settings.php',            'fa-cog',           'System Settings',       'btn-outline-dark'],
        ];
        foreach ($actions as $a): ?>
        <a href="<?= $a[0] ?>" class="btn <?= $a[3] ?> btn-sm w-100 mb-1 text-start">
          <i class="fas <?= $a[1] ?> me-2"></i><?= $a[2] ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Open tickets -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">
        <i class="fas fa-ticket-alt text-green me-2"></i>Open Tickets
        <?php if ($openTickets > 0): ?>
        <span class="badge bg-danger ms-1"><?= $openTickets ?></span>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/admin/support.php" class="ms-auto btn btn-xs btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($recentTickets)): ?>
        <div class="text-center py-5 text-muted">
          <i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>
          <span class="small">No open tickets — all clear!</span>
        </div>
        <?php else: ?>
        <?php foreach ($recentTickets as $tk): ?>
        <a href="<?= APP_URL ?>/admin/support.php?view=<?= $tk['id'] ?>"
           class="d-flex align-items-start gap-2 px-3 py-2 border-bottom text-decoration-none text-dark ticket-row">
          <span class="badge bg-<?= $priorityColors[$tk['priority']] ?? 'secondary' ?> mt-1 flex-shrink-0" style="font-size:.6rem">
            <?= strtoupper($tk['priority']) ?>
          </span>
          <div class="flex-grow-1 overflow-hidden">
            <div class="fw-600 text-truncate small"><?= e($tk['subject']) ?></div>
            <div class="text-muted" style="font-size:.7rem"><?= e($tk['org_name']) ?> &bull; #<?= e($tk['ticket_number']) ?></div>
          </div>
          <div class="flex-shrink-0 text-muted" style="font-size:.65rem;white-space:nowrap">
            <?= timeAgo($tk['updated_at']) ?>
          </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Activity feed -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <i class="fas fa-stream text-green me-2"></i>Recent Activity
      </div>
      <div class="card-body p-0" style="overflow-y:auto;max-height:380px">
        <?php if (empty($recentActivity)): ?>
        <div class="text-center py-5 text-muted small">No activity recorded yet.</div>
        <?php else: ?>
        <?php
        $actIcons = [
            'login'           => ['fa-sign-in-alt',     'text-success'],
            'logout'          => ['fa-sign-out-alt',    'text-secondary'],
            'create'          => ['fa-plus-circle',     'text-primary'],
            'update'          => ['fa-edit',            'text-warning'],
            'delete'          => ['fa-trash',           'text-danger'],
            'extend_subscription' => ['fa-calendar-plus', 'text-success'],
            'suspend_subscription'   => ['fa-pause-circle', 'text-warning'],
            'reactivate_subscription'=> ['fa-play-circle',  'text-success'],
            'generate_invoice'       => ['fa-file-invoice', 'text-primary'],
            'mark_invoice_paid'      => ['fa-check-circle', 'text-success'],
            'cron_email'             => ['fa-envelope',     'text-info'],
            'cron_suspend'           => ['fa-ban',          'text-danger'],
            'broadcast_notification' => ['fa-bell',         'text-warning'],
        ];
        foreach ($recentActivity as $act):
            [$icon, $color] = $actIcons[$act['action']] ?? ['fa-circle', 'text-secondary'];
        ?>
        <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
          <div class="mt-1 flex-shrink-0">
            <i class="fas <?= $icon ?> <?= $color ?> fa-sm"></i>
          </div>
          <div class="flex-grow-1 overflow-hidden">
            <div class="small text-truncate"><?= e($act['description'] ?? $act['action']) ?></div>
            <div class="text-muted" style="font-size:.7rem">
              <?= ucfirst(e($act['module'] ?? '')) ?>
              &bull; <?= timeAgo($act['created_at']) ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Recent Clients ────────────────────────────────────────────── -->
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
            <th>Joined</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentClients as $c): ?>
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
            <td><span class="badge bg-primary"><?= $c['module_count'] ?></span></td>
            <td><?= statusBadge($c['sub_status'] ?? 'none') ?></td>
            <td><span class="small"><?= $c['trial_ends_at'] ? formatDate($c['trial_ends_at']) : '—' ?></span></td>
            <td><?= statusBadge($c['status']) ?></td>
            <td><span class="small text-muted"><?= formatDate($c['created_at']) ?></span></td>
            <td>
              <div class="d-flex gap-1">
                <a href="<?= APP_URL ?>/admin/clients.php?view=<?= $c['id'] ?>" class="btn btn-xs btn-outline-primary" title="View"><i class="fas fa-eye"></i></a>
                <a href="<?= APP_URL ?>/admin/clients.php?edit=<?= $c['id'] ?>"  class="btn btn-xs btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($recentClients)): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">No clients yet. <a href="<?= APP_URL ?>/admin/clients.php?action=add">Add the first one</a></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
// ── Chart data prep ───────────────────────────────────────────────
$revLabels   = json_encode(array_column($chartData, 'month'));
$revValues   = json_encode(array_map('floatval', array_column($chartData, 'revenue')));
$modLabels   = json_encode(array_column($modPop,  'name'));
$modValues   = json_encode(array_map('intval',    array_column($modPop, 'cnt')));
$cgLabels    = json_encode(array_column($clientGrowthData, 'month'));
$cgValues    = json_encode(array_map('intval', array_column($clientGrowthData, 'cnt')));
$subActive   = $subStatusCounts['active'];
$subTrial    = $subStatusCounts['trial'];
$subExpired  = $subStatusCounts['expired'];
$subCancelled= $subStatusCounts['cancelled'];
$subSuspended= $subStatusCounts['suspended'];

$extraJs = <<<JS
<script>
// ── Revenue line chart ────────────────────────────────────────────
new Chart(document.getElementById('revenueChart'), {
  type: 'line',
  data: {
    labels: {$revLabels}.length ? {$revLabels} : ['Jan','Feb','Mar','Apr','May','Jun'],
    datasets: [{
      label: 'Revenue (KES)',
      data: {$revValues}.length ? {$revValues} : [0,0,0,0,0,0],
      borderColor: '#1A8A4E',
      backgroundColor: 'rgba(26,138,78,.08)',
      fill: true, tension: .4,
      pointBackgroundColor: '#1A8A4E',
      pointRadius: 4, pointHoverRadius: 6,
      borderWidth: 2.5
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: { label: ctx => 'KES ' + Number(ctx.raw).toLocaleString() }
      }
    },
    scales: {
      y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.04)' },
           ticks: { callback: v => 'KES ' + Number(v).toLocaleString() } },
      x: { grid: { display: false } }
    }
  }
});

// ── Module bar chart ─────────────────────────────────────────────
new Chart(document.getElementById('moduleChart'), {
  type: 'bar',
  data: {
    labels: {$modLabels}.length ? {$modLabels} : ['No data'],
    datasets: [{
      data: {$modValues}.length ? {$modValues} : [0],
      backgroundColor: ['#1A8A4E','#0B2D4E','#22B864','#1A4A72','#0F5C32','#2563eb','#7c3aed','#db2777'],
      borderRadius: 4
    }]
  },
  options: {
    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.04)' } },
      y: { ticks: { font: { size: 10 } } }
    }
  }
});

// ── Client growth chart ───────────────────────────────────────────
new Chart(document.getElementById('clientGrowthChart'), {
  type: 'bar',
  data: {
    labels: {$cgLabels}.length ? {$cgLabels} : ['No data'],
    datasets: [{
      label: 'New Clients',
      data: {$cgValues}.length ? {$cgValues} : [0],
      backgroundColor: 'rgba(11,45,78,.75)',
      borderRadius: 6,
      borderSkipped: false
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: 'rgba(0,0,0,.04)' } },
      x: { grid: { display: false } }
    }
  }
});

// ── Status donut ──────────────────────────────────────────────────
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: ['Active','Trial','Expired','Cancelled','Suspended'],
    datasets: [{
      data: [{$subActive},{$subTrial},{$subExpired},{$subCancelled},{$subSuspended}],
      backgroundColor: ['#1A8A4E','#f59e0b','#64748b','#ef4444','#1e293b'],
      borderWidth: 2, borderColor: '#fff'
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    cutout: '68%'
  }
});

// ── Counter animation ─────────────────────────────────────────────
document.querySelectorAll('[data-counter]').forEach(el => {
  const target = +el.dataset.target;
  if (!target) return;
  let current = 0;
  const step  = Math.max(1, Math.ceil(target / 40));
  const timer = setInterval(() => {
    current = Math.min(current + step, target);
    el.textContent = current.toLocaleString();
    if (current >= target) clearInterval(timer);
  }, 30);
});
</script>
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
