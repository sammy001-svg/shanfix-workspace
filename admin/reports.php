<?php
$pageTitle = 'Reports & Analytics';
require_once __DIR__ . '/../includes/header-admin.php';

// ── CSV Export ───────────────────────────────────────────────────
$export = $_GET['export'] ?? '';
if ($export) {
    $filename = '';
    $rows = [];
    $headers = [];
    switch ($export) {
        case 'revenue':
            $filename = 'revenue_' . date('Y-m-d') . '.csv';
            $headers = ['Month','Revenue (KES)','Invoice Count'];
            $rows = $pdo->query("
                SELECT DATE_FORMAT(paid_at,'%b %Y') as month,
                       ROUND(SUM(total),2) as revenue,
                       COUNT(*) as cnt
                FROM invoices WHERE status='paid'
                GROUP BY YEAR(paid_at), MONTH(paid_at)
                ORDER BY paid_at ASC
            ")->fetchAll(PDO::FETCH_NUM);
            break;
        case 'clients':
            $filename = 'clients_' . date('Y-m-d') . '.csv';
            $headers = ['Organization','Status','Plan','Modules','Joined','Total Paid (KES)'];
            $rows = $pdo->query("
                SELECT o.name, o.status, COALESCE(p.name,'—') as plan,
                       COUNT(DISTINCT sm.module_id) as modules,
                       DATE_FORMAT(o.created_at,'%d %b %Y'),
                       ROUND(COALESCE(SUM(i.total),0),2) as paid
                FROM organizations o
                LEFT JOIN subscriptions s ON s.org_id=o.id
                LEFT JOIN subscription_plans p ON p.id=s.plan_id
                LEFT JOIN subscription_modules sm ON sm.subscription_id=s.id AND sm.status='active'
                LEFT JOIN invoices i ON i.org_id=o.id AND i.status='paid'
                GROUP BY o.id ORDER BY paid DESC
            ")->fetchAll(PDO::FETCH_NUM);
            break;
        case 'modules':
            $filename = 'module_adoption_' . date('Y-m-d') . '.csv';
            $headers = ['Module','Category','Subscribers','Monthly Price (KES)'];
            $rows = $pdo->query("
                SELECT m.name, m.category,
                       COUNT(DISTINCT sm.id) as subscribers,
                       m.monthly_price
                FROM modules m
                LEFT JOIN subscription_modules sm ON sm.module_id=m.id AND sm.status='active'
                GROUP BY m.id ORDER BY subscribers DESC
            ")->fetchAll(PDO::FETCH_NUM);
            break;
        case 'invoices':
            $filename = 'invoices_' . date('Y-m-d') . '.csv';
            $headers = ['Invoice #','Organization','Amount','VAT','Total','Status','Due Date','Paid Date'];
            $rows = $pdo->query("
                SELECT i.invoice_number, o.name, i.amount, i.tax, i.total,
                       i.status, i.due_date, COALESCE(DATE(i.paid_at),'')
                FROM invoices i JOIN organizations o ON i.org_id=o.id
                ORDER BY i.created_at DESC LIMIT 5000
            ")->fetchAll(PDO::FETCH_NUM);
            break;
    }
    if ($filename) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }
}

// ── Date range ────────────────────────────────────────────────────
$range   = (int)($_GET['range'] ?? 12);
if (!in_array($range, [3,6,12,24])) $range = 12;
$rangeLabel = ['3'=>'3 Months','6'=>'6 Months','12'=>'12 Months','24'=>'24 Months'][$range] ?? '12 Months';

// ── KPI Metrics ───────────────────────────────────────────────────
$mrr = (float)$pdo->query("
    SELECT COALESCE(SUM(CASE WHEN billing_cycle='monthly' THEN amount ELSE amount/12 END),0)
    FROM subscriptions WHERE status='active'
")->fetchColumn();
$arr = $mrr * 12;

$revenueThisMonth = (float)$pdo->query("
    SELECT COALESCE(SUM(total),0) FROM invoices
    WHERE status='paid' AND YEAR(paid_at)=YEAR(CURDATE()) AND MONTH(paid_at)=MONTH(CURDATE())
")->fetchColumn();

$revenueLastMonth = (float)$pdo->query("
    SELECT COALESCE(SUM(total),0) FROM invoices
    WHERE status='paid' AND YEAR(paid_at)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))
      AND MONTH(paid_at)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))
")->fetchColumn();

$revenueGrowth = $revenueLastMonth > 0
    ? round(($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth * 100, 1)
    : 0;

$totalRevenue   = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid'")->fetchColumn();
$outstanding    = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status IN ('draft','sent')")->fetchColumn();
$overdue        = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='overdue'")->fetchColumn();
$activeClients  = (int)$pdo->query("SELECT COUNT(DISTINCT org_id) FROM subscriptions WHERE status='active'")->fetchColumn();
$trialClients   = (int)$pdo->query("SELECT COUNT(DISTINCT org_id) FROM subscriptions WHERE status='trial'")->fetchColumn();
$totalClients   = (int)$pdo->query("SELECT COUNT(*) FROM organizations")->fetchColumn();
$churnedClients = (int)$pdo->query("SELECT COUNT(DISTINCT org_id) FROM subscriptions WHERE status IN ('expired','cancelled','suspended')")->fetchColumn();

$newClientsThisMonth = (int)$pdo->query("
    SELECT COUNT(*) FROM organizations
    WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())
")->fetchColumn();

// ── Revenue by month ──────────────────────────────────────────────
$revenueByMonth = $pdo->query("
    SELECT DATE_FORMAT(paid_at,'%b %Y') as label,
           YEAR(paid_at) as yr, MONTH(paid_at) as mo,
           ROUND(SUM(total),2) as revenue
    FROM invoices WHERE status='paid'
      AND paid_at >= DATE_SUB(NOW(), INTERVAL {$range} MONTH)
    GROUP BY yr, mo ORDER BY yr, mo ASC
")->fetchAll();

// ── New clients by month ──────────────────────────────────────────
$clientsByMonth = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%b %Y') as label,
           YEAR(created_at) as yr, MONTH(created_at) as mo,
           COUNT(*) as cnt
    FROM organizations
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$range} MONTH)
    GROUP BY yr, mo ORDER BY yr, mo ASC
")->fetchAll();

// ── Subscription status breakdown ────────────────────────────────
$subStatus = $pdo->query("SELECT status, COUNT(*) as cnt FROM subscriptions GROUP BY status")->fetchAll();

// ── Module adoption ───────────────────────────────────────────────
$moduleAdoption = $pdo->query("
    SELECT m.name, m.color,
           COUNT(DISTINCT sm.id) as subscribers,
           ROUND(m.monthly_price,2) as price
    FROM modules m
    LEFT JOIN subscription_modules sm ON sm.module_id=m.id AND sm.status='active'
    WHERE m.status='active'
    GROUP BY m.id ORDER BY subscribers DESC LIMIT 10
")->fetchAll();

// ── Revenue by plan ───────────────────────────────────────────────
$revenueByPlan = $pdo->query("
    SELECT COALESCE(p.name,'Custom') as plan_name,
           COUNT(DISTINCT s.org_id) as clients,
           ROUND(SUM(i.total),2) as revenue
    FROM subscriptions s
    LEFT JOIN subscription_plans p ON p.id=s.plan_id
    JOIN invoices i ON i.org_id=s.org_id AND i.status='paid'
    GROUP BY p.id ORDER BY revenue DESC
")->fetchAll();

// ── Top clients by revenue ────────────────────────────────────────
$topClients = $pdo->query("
    SELECT o.name, o.id,
           COALESCE(p.name,'—') as plan,
           ROUND(SUM(i.total),2) as total_revenue,
           COUNT(i.id) as invoice_count,
           s.status as sub_status
    FROM organizations o
    JOIN invoices i ON i.org_id=o.id AND i.status='paid'
    LEFT JOIN subscriptions s ON s.org_id=o.id
    LEFT JOIN subscription_plans p ON p.id=s.plan_id
    GROUP BY o.id ORDER BY total_revenue DESC LIMIT 10
")->fetchAll();

// ── Invoice aging ─────────────────────────────────────────────────
$invoiceAging = $pdo->query("
    SELECT
      SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 0 AND 30 THEN total ELSE 0 END) as d0_30,
      SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN total ELSE 0 END) as d31_60,
      SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN total ELSE 0 END) as d61_90,
      SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN total ELSE 0 END) as d90plus,
      COUNT(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 0 AND 30 THEN 1 END) as n0_30,
      COUNT(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN 1 END) as n31_60,
      COUNT(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN 1 END) as n61_90,
      COUNT(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN 1 END) as n90plus
    FROM invoices WHERE status IN ('sent','overdue')
")->fetch();

// ── Prepare chart JSON ─────────────────────────────────────────────
$revLabels  = array_column($revenueByMonth, 'label');
$revValues  = array_map('floatval', array_column($revenueByMonth, 'revenue'));
$cliLabels  = array_column($clientsByMonth, 'label');
$cliValues  = array_map('intval',   array_column($clientsByMonth, 'cnt'));
$subLabels  = array_column($subStatus, 'status');
$subValues  = array_map('intval', array_column($subStatus, 'cnt'));
$modLabels  = array_column($moduleAdoption, 'name');
$modValues  = array_map('intval', array_column($moduleAdoption, 'subscribers'));
$modColors  = array_map(fn($m) => $m['color'] ?: '#1A8A4E', $moduleAdoption);
?>

<!-- Page Header -->
<div class="page-header d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4><i class="fas fa-chart-bar me-2 text-green"></i>Reports &amp; Analytics</h4>
    <p class="text-muted mb-0">Business intelligence and performance metrics</p>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <!-- Range selector -->
    <div class="btn-group btn-group-sm">
      <?php foreach ([3,6,12,24] as $r): ?>
      <a href="?range=<?= $r ?>" class="btn btn-<?= $range==$r ? 'primary' : 'outline-secondary' ?>"><?= $r ?>M</a>
      <?php endforeach; ?>
    </div>
    <!-- Exports -->
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown">
        <i class="fas fa-download me-1"></i>Export
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="?export=revenue"><i class="fas fa-chart-line me-2 text-green"></i>Revenue CSV</a></li>
        <li><a class="dropdown-item" href="?export=clients"><i class="fas fa-building me-2 text-navy"></i>Clients CSV</a></li>
        <li><a class="dropdown-item" href="?export=modules"><i class="fas fa-puzzle-piece me-2 text-warning"></i>Module Adoption CSV</a></li>
        <li><a class="dropdown-item" href="?export=invoices"><i class="fas fa-file-invoice me-2 text-info"></i>All Invoices CSV</a></li>
      </ul>
    </div>
  </div>
</div>

<!-- ── KPI Cards ──────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="text-muted small fw-600">MRR</span>
          <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:36px;height:36px;background:#e6f5ee">
            <i class="fas fa-chart-line text-green small"></i>
          </div>
        </div>
        <div class="fw-800 fs-4 text-navy"><?= 'KES ' . number_format($mrr, 0) ?></div>
        <div class="text-muted small">Monthly Recurring Revenue</div>
        <div class="text-muted small mt-1">ARR: <strong><?= 'KES ' . number_format($arr, 0) ?></strong></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="text-muted small fw-600">Revenue This Month</span>
          <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:36px;height:36px;background:#<?= $revenueGrowth >= 0 ? 'e6f5ee' : 'fef2f2' ?>">
            <i class="fas fa-arrow-<?= $revenueGrowth >= 0 ? 'up' : 'down' ?> small" style="color:<?= $revenueGrowth >= 0 ? '#1A8A4E' : '#ef4444' ?>"></i>
          </div>
        </div>
        <div class="fw-800 fs-4 text-navy"><?= 'KES ' . number_format($revenueThisMonth, 0) ?></div>
        <div class="text-muted small">
          <?php if ($revenueGrowth >= 0): ?>
          <span class="text-success"><i class="fas fa-arrow-up me-1"></i><?= abs($revenueGrowth) ?>%</span> vs last month
          <?php else: ?>
          <span class="text-danger"><i class="fas fa-arrow-down me-1"></i><?= abs($revenueGrowth) ?>%</span> vs last month
          <?php endif; ?>
        </div>
        <div class="text-muted small mt-1">All time: <strong><?= 'KES ' . number_format($totalRevenue, 0) ?></strong></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="text-muted small fw-600">Active Clients</span>
          <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:36px;height:36px;background:#e6f5ee">
            <i class="fas fa-building text-green small"></i>
          </div>
        </div>
        <div class="fw-800 fs-4 text-navy"><?= $activeClients ?></div>
        <div class="text-muted small">
          <span class="text-warning fw-600"><?= $trialClients ?></span> on trial ·
          <span class="text-success fw-600">+<?= $newClientsThisMonth ?></span> this month
        </div>
        <div class="text-muted small mt-1">Total: <strong><?= $totalClients ?></strong> orgs</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="text-muted small fw-600">Outstanding</span>
          <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:36px;height:36px;background:#fff5f5">
            <i class="fas fa-exclamation-circle text-danger small"></i>
          </div>
        </div>
        <div class="fw-800 fs-4 <?= $overdue > 0 ? 'text-danger' : 'text-navy' ?>"><?= 'KES ' . number_format($outstanding + $overdue, 0) ?></div>
        <?php if ($overdue > 0): ?>
        <div class="text-danger small"><i class="fas fa-exclamation-triangle me-1"></i><?= 'KES ' . number_format($overdue, 0) ?> overdue</div>
        <?php else: ?>
        <div class="text-muted small">No overdue invoices</div>
        <?php endif; ?>
        <div class="text-muted small mt-1">Pending: <strong><?= 'KES ' . number_format($outstanding, 0) ?></strong></div>
      </div>
    </div>
  </div>
</div>

<!-- ── Revenue + Clients Charts ───────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fas fa-chart-area text-green me-2"></i>Revenue — Last <?= $rangeLabel ?></span>
        <a href="?export=revenue&range=<?= $range ?>" class="btn btn-xs btn-outline-success btn-sm">
          <i class="fas fa-download me-1"></i>CSV
        </a>
      </div>
      <div class="card-body">
        <div style="height:260px"><canvas id="revenueChart"></canvas></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-users text-green me-2"></i>Client Growth</div>
      <div class="card-body">
        <div style="height:260px"><canvas id="clientChart"></canvas></div>
      </div>
    </div>
  </div>
</div>

<!-- ── Module Adoption + Subscription Status ─────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fas fa-puzzle-piece text-green me-2"></i>Module Adoption (Top 10)</span>
        <a href="?export=modules" class="btn btn-xs btn-outline-success btn-sm"><i class="fas fa-download me-1"></i>CSV</a>
      </div>
      <div class="card-body">
        <div style="height:260px"><canvas id="moduleChart"></canvas></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-pie text-green me-2"></i>Subscription Status</div>
      <div class="card-body d-flex flex-column align-items-center justify-content-center">
        <div style="height:200px;width:200px"><canvas id="statusChart"></canvas></div>
        <div class="mt-3 w-100">
          <?php
          $statusColorMap = ['active'=>'#1A8A4E','trial'=>'#f59e0b','expired'=>'#64748b','cancelled'=>'#ef4444','suspended'=>'#e11d48'];
          foreach ($subStatus as $ss):
            $col = $statusColorMap[$ss['status']] ?? '#94a3b8';
          ?>
          <div class="d-flex align-items-center justify-content-between mb-1 small">
            <div class="d-flex align-items-center gap-2">
              <div class="rounded-circle" style="width:10px;height:10px;background:<?= $col ?>"></div>
              <span><?= ucfirst($ss['status']) ?></span>
            </div>
            <strong><?= $ss['cnt'] ?></strong>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Revenue by Plan ────────────────────────────────────────────── -->
<?php if (!empty($revenueByPlan)): ?>
<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="fas fa-layer-group text-green me-2"></i>Revenue by Plan</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Plan</th><th>Clients</th><th>Total Revenue</th><th>% of Total</th><th>Contribution</th></tr>
            </thead>
            <tbody>
              <?php
              $planTotal = array_sum(array_column($revenueByPlan, 'revenue')) ?: 1;
              foreach ($revenueByPlan as $p):
                $pct = round($p['revenue'] / $planTotal * 100, 1);
              ?>
              <tr>
                <td class="fw-600"><?= e($p['plan_name']) ?></td>
                <td><?= $p['clients'] ?></td>
                <td class="fw-bold text-green"><?= 'KES ' . number_format($p['revenue'], 0) ?></td>
                <td><?= $pct ?>%</td>
                <td style="width:200px">
                  <div class="progress" style="height:8px">
                    <div class="progress-bar bg-green" style="width:<?= $pct ?>%"></div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Top Clients + Invoice Aging ────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fas fa-trophy text-green me-2"></i>Top Clients by Revenue</span>
        <a href="?export=clients" class="btn btn-xs btn-outline-success btn-sm"><i class="fas fa-download me-1"></i>CSV</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>#</th><th>Organization</th><th>Plan</th><th>Invoices</th><th>Revenue</th></tr>
            </thead>
            <tbody>
              <?php foreach ($topClients as $i => $c): ?>
              <tr>
                <td>
                  <?php if ($i < 3): ?>
                  <span style="font-size:1.1rem"><?= ['🥇','🥈','🥉'][$i] ?></span>
                  <?php else: ?>
                  <span class="text-muted"><?= $i + 1 ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <a href="<?= APP_URL ?>/admin/clients.php?view=<?= $c['id'] ?>" class="fw-600 text-dark text-decoration-none">
                    <?= e($c['name']) ?>
                  </a>
                </td>
                <td class="small text-muted"><?= e($c['plan']) ?></td>
                <td class="text-center"><span class="badge bg-light text-dark border"><?= $c['invoice_count'] ?></span></td>
                <td class="fw-bold text-green"><?= 'KES ' . number_format($c['total_revenue'], 0) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($topClients)): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">No paid invoices yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-hourglass-half text-green me-2"></i>Invoice Aging (Outstanding)</div>
      <div class="card-body">
        <?php
        $agingTotal = ($invoiceAging['d0_30'] ?? 0) + ($invoiceAging['d31_60'] ?? 0) + ($invoiceAging['d61_90'] ?? 0) + ($invoiceAging['d90plus'] ?? 0);
        $agingBuckets = [
          ['0–30 days',  $invoiceAging['d0_30'] ?? 0,   $invoiceAging['n0_30'] ?? 0,   '#3b82f6'],
          ['31–60 days', $invoiceAging['d31_60'] ?? 0,  $invoiceAging['n31_60'] ?? 0,  '#f59e0b'],
          ['61–90 days', $invoiceAging['d61_90'] ?? 0,  $invoiceAging['n61_90'] ?? 0,  '#ef4444'],
          ['90+ days',   $invoiceAging['d90plus'] ?? 0, $invoiceAging['n90plus'] ?? 0, '#7f1d1d'],
        ];
        ?>
        <?php if ($agingTotal == 0): ?>
        <div class="text-center py-4">
          <i class="fas fa-check-circle fa-3x text-success mb-2 d-block"></i>
          <h6 class="text-success">All invoices paid!</h6>
          <p class="text-muted small">No outstanding balances.</p>
        </div>
        <?php else: ?>
        <div class="mb-3 text-center">
          <div class="fw-800 fs-4 text-danger"><?= 'KES ' . number_format($agingTotal, 0) ?></div>
          <div class="text-muted small">Total outstanding</div>
        </div>
        <?php foreach ($agingBuckets as [$label, $amount, $count, $color]): ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-600"><?= $label ?></span>
            <span class="small fw-bold" style="color:<?= $color ?>">
              KES <?= number_format($amount, 0) ?>
              <span class="text-muted fw-normal">(<?= $count ?> inv.)</span>
            </span>
          </div>
          <div class="progress" style="height:8px">
            <div class="progress-bar" style="width:<?= $agingTotal > 0 ? round($amount / $agingTotal * 100) : 0 ?>%;background:<?= $color ?>"></div>
          </div>
        </div>
        <?php endforeach; ?>
        <a href="<?= APP_URL ?>/admin/invoices.php" class="btn btn-sm btn-outline-danger w-100 mt-1">
          <i class="fas fa-file-invoice me-1"></i>Manage Invoices
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Module detail table ────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span><i class="fas fa-table text-green me-2"></i>Module Performance</span>
    <a href="?export=modules" class="btn btn-xs btn-outline-success btn-sm"><i class="fas fa-download me-1"></i>Export</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Module</th><th>Category</th><th>Price/mo</th><th>Subscribers</th><th>Est. MRR</th><th>Adoption</th></tr>
        </thead>
        <tbody>
          <?php
          $maxSubs = $moduleAdoption ? max(array_column($moduleAdoption, 'subscribers')) : 1;
          foreach ($moduleAdoption as $m):
            $pct = $maxSubs > 0 ? round($m['subscribers'] / $maxSubs * 100) : 0;
            $modMrr = $m['subscribers'] * $m['price'];
          ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="rounded" style="width:10px;height:10px;background:<?= e($m['color'] ?: '#1A8A4E') ?>"></div>
                <span class="fw-600"><?= e($m['name']) ?></span>
              </div>
            </td>
            <td class="text-muted small"><?= e($m['category'] ?? '—') ?></td>
            <td><?= 'KES ' . number_format($m['price'], 0) ?></td>
            <td><span class="badge bg-primary"><?= $m['subscribers'] ?></span></td>
            <td class="fw-bold text-green"><?= 'KES ' . number_format($modMrr, 0) ?></td>
            <td style="width:140px">
              <div class="progress" style="height:6px">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= e($m['color'] ?: '#1A8A4E') ?>"></div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "system-ui,sans-serif";
Chart.defaults.color = "#64748b";

// Revenue Chart
new Chart(document.getElementById("revenueChart"), {
  type: "bar",
  data: {
    labels: ' . json_encode($revLabels) . ',
    datasets: [{
      label: "Revenue (KES)",
      data: ' . json_encode($revValues) . ',
      backgroundColor: "rgba(26,138,78,.75)",
      borderColor: "#1A8A4E",
      borderWidth: 1,
      borderRadius: 4,
    },{
      type: "line",
      label: "Trend",
      data: ' . json_encode($revValues) . ',
      borderColor: "#0B2D4E",
      borderWidth: 2,
      pointRadius: 3,
      pointBackgroundColor: "#0B2D4E",
      tension: 0.4,
      fill: false,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: "top", labels: { boxWidth: 12 } } },
    scales: {
      y: { beginAtZero: true, grid: { color: "rgba(0,0,0,.05)" },
           ticks: { callback: v => "KES " + v.toLocaleString() } },
      x: { grid: { display: false } }
    }
  }
});

// Client growth chart
new Chart(document.getElementById("clientChart"), {
  type: "line",
  data: {
    labels: ' . json_encode($cliLabels) . ',
    datasets: [{
      label: "New Clients",
      data: ' . json_encode($cliValues) . ',
      borderColor: "#1A8A4E",
      backgroundColor: "rgba(26,138,78,.1)",
      fill: true, tension: 0.4,
      pointBackgroundColor: "#1A8A4E",
      pointRadius: 4,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: "rgba(0,0,0,.05)" } },
      x: { grid: { display: false } }
    }
  }
});

// Module adoption chart
new Chart(document.getElementById("moduleChart"), {
  type: "bar",
  data: {
    labels: ' . json_encode($modLabels) . ',
    datasets: [{
      label: "Subscribers",
      data: ' . json_encode($modValues) . ',
      backgroundColor: ' . json_encode($modColors) . ',
      borderRadius: 4,
    }]
  },
  options: {
    indexAxis: "y",
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { beginAtZero: true, ticks: { stepSize: 1 } },
      y: { ticks: { font: { size: 11 } } }
    }
  }
});

// Subscription status donut
const statusColors = {active:"#1A8A4E",trial:"#f59e0b",expired:"#64748b",cancelled:"#ef4444",suspended:"#e11d48"};
new Chart(document.getElementById("statusChart"), {
  type: "doughnut",
  data: {
    labels: ' . json_encode($subLabels) . '.map(l => l.charAt(0).toUpperCase() + l.slice(1)),
    datasets: [{
      data: ' . json_encode($subValues) . ',
      backgroundColor: ' . json_encode($subLabels) . '.map(l => statusColors[l] || "#94a3b8"),
      borderWidth: 2,
      borderColor: "#fff"
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    cutout: "65%"
  }
});
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
