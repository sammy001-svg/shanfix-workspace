<?php
// ── Analytics & Insights — cross-module unified dashboard ────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Date range filter ─────────────────────────────────────────────
$rangeOptions = [
    '7d'       => 'Last 7 Days',
    '30d'      => 'Last 30 Days',
    '90d'      => 'Last 90 Days',
    'this_year'=> 'This Year',
];
$range = in_array($_GET['range'] ?? '', array_keys($rangeOptions)) ? $_GET['range'] : '30d';

switch ($range) {
    case '7d':        $dateFrom = date('Y-m-d', strtotime('-7 days'));  break;
    case '90d':       $dateFrom = date('Y-m-d', strtotime('-90 days')); break;
    case 'this_year': $dateFrom = date('Y-01-01');                       break;
    default:          $dateFrom = date('Y-m-d', strtotime('-30 days'));  break;
}
$dateTo = date('Y-m-d');

$pageTitle = 'Analytics & Insights';
require_once __DIR__ . '/../includes/header-client.php';

// ── KPI 1: Total Revenue (paid invoices in range) ─────────────────
$kpiRevenue = 0.0;
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM acc_invoices WHERE org_id=? AND status='paid' AND DATE(created_at) BETWEEN ? AND ?");
    $s->execute([$orgId, $dateFrom, $dateTo]);
    $kpiRevenue = (float)$s->fetchColumn();
} catch (Exception $e) {}

// ── KPI 2: Active Customers (CRM contacts + retail customers) ─────
$kpiCustomers = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM crm_contacts WHERE org_id=? AND status='active'");
    $s->execute([$orgId]);
    $kpiCustomers = (int)$s->fetchColumn();
} catch (Exception $e) {}
if ($kpiCustomers === 0) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM retail_customers WHERE org_id=?");
        $s->execute([$orgId]);
        $kpiCustomers = (int)$s->fetchColumn();
    } catch (Exception $e) {}
}

// ── KPI 3: Pending Invoices ───────────────────────────────────────
$kpiPendingInvoices = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM acc_invoices WHERE org_id=? AND status IN ('draft','sent','overdue')");
    $s->execute([$orgId]);
    $kpiPendingInvoices = (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── KPI 4: Team Members ───────────────────────────────────────────
$kpiTeam = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id=? AND status='active'");
    $s->execute([$orgId]);
    $kpiTeam = (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── KPI 5: Active Modules ─────────────────────────────────────────
$kpiModules = count($modules);

// ── KPI 6: Tasks Due (reminders + appointments within 7 days) ─────
$kpiTasksDue = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM org_reminders WHERE org_id=? AND status='pending' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
    $s->execute([$orgId]);
    $kpiTasksDue = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM health_appointments WHERE org_id=? AND appointment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status IN ('scheduled','pending')");
    $s->execute([$orgId]);
    $kpiTasksDue += (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── Revenue trend: last 12 months ────────────────────────────────
$revLabels = $revData = [];
try {
    $s = $pdo->prepare("
        SELECT DATE_FORMAT(created_at,'%b %Y') AS m,
               DATE_FORMAT(created_at,'%Y-%m') AS ym,
               COALESCE(SUM(total_amount),0) AS rev
        FROM acc_invoices
        WHERE org_id=? AND status='paid'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at,'%Y-%m')
        ORDER BY MIN(created_at) ASC
    ");
    $s->execute([$orgId]);
    foreach ($s->fetchAll() as $r) {
        $revLabels[] = $r['m'];
        $revData[]   = round((float)$r['rev'], 2);
    }
} catch (Exception $e) {}

// ── Module usage pie: record counts per module table ─────────────
$moduleCounts = [];
$moduleTables = [
    'CRM Contacts'     => ['table' => 'crm_contacts',        'icon' => 'fa-address-book',     'color' => '#3b82f6'],
    'Invoices'         => ['table' => 'acc_invoices',         'icon' => 'fa-file-invoice',     'color' => '#10b981'],
    'Sales Orders'     => ['table' => 'sales_orders',         'icon' => 'fa-shopping-cart',    'color' => '#f59e0b'],
    'SACCO Members'    => ['table' => 'sacco_members',        'icon' => 'fa-users',            'color' => '#8b5cf6'],
    'Rental Units'     => ['table' => 'rental_properties',   'icon' => 'fa-building',         'color' => '#ec4899'],
    'Products'         => ['table' => 'retail_products',     'icon' => 'fa-box',              'color' => '#06b6d4'],
    'Hotel Bookings'   => ['table' => 'hotel_bookings',       'icon' => 'fa-bed',              'color' => '#f97316'],
    'Support Tickets'  => ['table' => 'support_tickets',     'icon' => 'fa-headset',          'color' => '#ef4444'],
    'Church Members'   => ['table' => 'church_members',      'icon' => 'fa-church',           'color' => '#84cc16'],
    'Health Appts'     => ['table' => 'health_appointments', 'icon' => 'fa-stethoscope',      'color' => '#14b8a6'],
    'School Students'  => ['table' => 'sch_students',        'icon' => 'fa-graduation-cap',   'color' => '#a78bfa'],
    'Delivery Orders'  => ['table' => 'delivery_orders',     'icon' => 'fa-truck',            'color' => '#fb923c'],
];
foreach ($moduleTables as $label => $meta) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM `{$meta['table']}` WHERE org_id=?");
        $s->execute([$orgId]);
        $cnt = (int)$s->fetchColumn();
        if ($cnt > 0) {
            $moduleCounts[$label] = ['count' => $cnt, 'icon' => $meta['icon'], 'color' => $meta['color']];
        }
    } catch (Exception $e) {}
}

// ── Recent Transactions (last 10) ────────────────────────────────
$recentTx = [];
try {
    $s = $pdo->prepare("SELECT id, description, type, amount, created_at FROM acc_transactions WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
    $s->execute([$orgId]);
    $recentTx = $s->fetchAll();
} catch (Exception $e) {}
if (empty($recentTx)) {
    try {
        $s = $pdo->prepare("SELECT id, reference AS description, payment_method AS type, amount, created_at FROM sales_payments WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
        $s->execute([$orgId]);
        $recentTx = $s->fetchAll();
    } catch (Exception $e) {}
}

// ── Top Clients by Revenue ────────────────────────────────────────
$topClients = [];
try {
    $s = $pdo->prepare("
        SELECT c.name, c.email, COUNT(i.id) AS invoice_count,
               COALESCE(SUM(i.total_amount),0) AS total_rev
        FROM crm_contacts c
        JOIN acc_invoices i ON i.contact_id = c.id AND i.org_id=?
        WHERE c.org_id=?
        GROUP BY c.id
        ORDER BY total_rev DESC LIMIT 5
    ");
    $s->execute([$orgId, $orgId]);
    $topClients = $s->fetchAll();
} catch (Exception $e) {}
if (empty($topClients)) {
    try {
        $s = $pdo->prepare("
            SELECT c.name, c.phone AS email, COUNT(s.id) AS invoice_count,
                   COALESCE(SUM(s.total_amount),0) AS total_rev
            FROM retail_customers c
            JOIN sales_orders s ON s.customer_id = c.id AND s.org_id=?
            WHERE c.org_id=?
            GROUP BY c.id
            ORDER BY total_rev DESC LIMIT 5
        ");
        $s->execute([$orgId, $orgId]);
        $topClients = $s->fetchAll();
    } catch (Exception $e) {}
}

// ── Upcoming Tasks/Appointments within 7 days ────────────────────
$upcoming = [];
// Health appointments
try {
    $s = $pdo->prepare("
        SELECT CONCAT('Appointment: ', patient_name) AS title,
               appointment_date AS due_date, 'health' AS source
        FROM health_appointments
        WHERE org_id=? AND appointment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND status IN ('scheduled','pending')
        ORDER BY appointment_date ASC LIMIT 5
    ");
    $s->execute([$orgId]);
    $upcoming = array_merge($upcoming, $s->fetchAll());
} catch (Exception $e) {}
// Hotel bookings
try {
    $s = $pdo->prepare("
        SELECT CONCAT('Check-in: ', guest_name) AS title,
               check_in_date AS due_date, 'hotel' AS source
        FROM hotel_bookings
        WHERE org_id=? AND check_in_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND status IN ('confirmed','pending')
        ORDER BY check_in_date ASC LIMIT 5
    ");
    $s->execute([$orgId]);
    $upcoming = array_merge($upcoming, $s->fetchAll());
} catch (Exception $e) {}
// CRM tickets due
try {
    $s = $pdo->prepare("
        SELECT CONCAT('Ticket: ', subject) AS title,
               due_date, 'crm' AS source
        FROM crm_tickets
        WHERE org_id=? AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND status NOT IN ('closed','resolved')
        ORDER BY due_date ASC LIMIT 5
    ");
    $s->execute([$orgId]);
    $upcoming = array_merge($upcoming, $s->fetchAll());
} catch (Exception $e) {}
// Reminders
try {
    $s = $pdo->prepare("
        SELECT title, due_date, 'reminder' AS source
        FROM org_reminders
        WHERE org_id=? AND status='pending'
          AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY due_date ASC LIMIT 5
    ");
    $s->execute([$orgId]);
    $upcoming = array_merge($upcoming, $s->fetchAll());
} catch (Exception $e) {}
// Sort by due_date
usort($upcoming, fn($a,$b) => strcmp($a['due_date']??'', $b['due_date']??''));
$upcoming = array_slice($upcoming, 0, 10);

// ── Prepare JSON for charts ───────────────────────────────────────
$revLabelsJson = json_encode($revLabels ?: ['No data']);
$revDataJson   = json_encode($revData   ?: [0]);
$pieLabels     = array_keys($moduleCounts);
$pieData       = array_column(array_values($moduleCounts), 'count');
$pieColors     = array_column(array_values($moduleCounts), 'color');
$pieLabelsJson = json_encode($pieLabels ?: ['No modules with data']);
$pieDataJson   = json_encode($pieData   ?: [1]);
$pieColorsJson = json_encode($pieColors ?: ['#94a3b8']);

$sourceIcons = [
    'health'   => '<i class="fas fa-stethoscope text-info me-1"></i>',
    'hotel'    => '<i class="fas fa-bed text-warning me-1"></i>',
    'crm'      => '<i class="fas fa-headset text-danger me-1"></i>',
    'reminder' => '<i class="fas fa-tasks text-success me-1"></i>',
];
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-line me-2 text-green"></i>Analytics &amp; Insights</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/client/index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Analytics</li>
    </ol></nav>
  </div>
  <!-- Date range filter -->
  <form method="GET" class="d-flex gap-2 align-items-center">
    <label class="small text-muted fw-semibold">Range:</label>
    <?php foreach ($rangeOptions as $val => $label): ?>
    <a href="?range=<?= $val ?>"
       class="btn btn-sm <?= $range === $val ? 'btn-success' : 'btn-outline-secondary' ?>">
      <?= $label ?>
    </a>
    <?php endforeach; ?>
  </form>
</div>

<!-- ── Row 1: KPI Cards ──────────────────────────────────────────── -->
<div class="row g-3 mb-4">

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body text-center py-3 px-2">
        <div class="rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(26,138,78,.12)">
          <i class="fas fa-coins text-success"></i>
        </div>
        <div class="fw-bold fs-6"><?= formatCurrency($kpiRevenue) ?></div>
        <div class="text-muted small">Revenue</div>
        <div class="text-muted" style="font-size:.7rem"><?= $rangeOptions[$range] ?></div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body text-center py-3 px-2">
        <div class="rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(59,130,246,.12)">
          <i class="fas fa-users text-primary"></i>
        </div>
        <div class="fw-bold fs-6"><?= number_format($kpiCustomers) ?></div>
        <div class="text-muted small">Active Customers</div>
        <div class="text-muted" style="font-size:.7rem">CRM / Retail</div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body text-center py-3 px-2">
        <div class="rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(239,68,68,.12)">
          <i class="fas fa-file-invoice text-danger"></i>
        </div>
        <div class="fw-bold fs-6"><?= number_format($kpiPendingInvoices) ?></div>
        <div class="text-muted small">Pending Invoices</div>
        <div class="text-muted" style="font-size:.7rem">Draft + Sent + Overdue</div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body text-center py-3 px-2">
        <div class="rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(139,92,246,.12)">
          <i class="fas fa-user-tie" style="color:#8b5cf6"></i>
        </div>
        <div class="fw-bold fs-6"><?= number_format($kpiTeam) ?></div>
        <div class="text-muted small">Team Members</div>
        <div class="text-muted" style="font-size:.7rem">Active users</div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body text-center py-3 px-2">
        <div class="rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(245,158,11,.12)">
          <i class="fas fa-cubes text-warning"></i>
        </div>
        <div class="fw-bold fs-6"><?= number_format($kpiModules) ?></div>
        <div class="text-muted small">Active Modules</div>
        <div class="text-muted" style="font-size:.7rem">On subscription</div>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body text-center py-3 px-2">
        <div class="rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(20,184,166,.12)">
          <i class="fas fa-calendar-check" style="color:#14b8a6"></i>
        </div>
        <div class="fw-bold fs-6"><?= number_format($kpiTasksDue) ?></div>
        <div class="text-muted small">Tasks Due</div>
        <div class="text-muted" style="font-size:.7rem">Next 7 days</div>
      </div>
    </div>
  </div>

</div>

<!-- ── Row 2: Charts ─────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

  <!-- Revenue Trend -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent border-0 pb-0">
        <h6 class="fw-bold mb-0"><i class="fas fa-chart-line text-success me-2"></i>Revenue Trend — Last 12 Months</h6>
        <small class="text-muted">Paid invoices only</small>
      </div>
      <div class="card-body">
        <?php if (empty($revData)): ?>
        <div class="text-center text-muted py-5">
          <i class="fas fa-chart-line fa-3x mb-3 opacity-25"></i>
          <p>No paid invoice data yet.<br><a href="<?= APP_URL ?>/modules/accounting/invoices.php">Go to Invoices →</a></p>
        </div>
        <?php else: ?>
        <canvas id="revChart" height="90"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Module Usage Pie -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent border-0 pb-0">
        <h6 class="fw-bold mb-0"><i class="fas fa-chart-pie text-primary me-2"></i>Module Data Distribution</h6>
        <small class="text-muted">Records per module</small>
      </div>
      <div class="card-body d-flex flex-column align-items-center">
        <?php if (empty($moduleCounts)): ?>
        <div class="text-center text-muted py-5">
          <i class="fas fa-database fa-3x mb-3 opacity-25"></i>
          <p>No module data found.<br>Start using your modules to see data here.</p>
        </div>
        <?php else: ?>
        <canvas id="pieChart" style="max-height:220px"></canvas>
        <div class="mt-3 w-100" style="font-size:.78rem">
          <?php foreach ($moduleCounts as $label => $meta): ?>
          <div class="d-flex align-items-center gap-2 mb-1">
            <span style="width:10px;height:10px;border-radius:50%;background:<?= e($meta['color']) ?>;flex-shrink:0"></span>
            <span class="text-muted"><?= e($label) ?></span>
            <span class="ms-auto fw-semibold"><?= number_format($meta['count']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<!-- ── Row 3: Summary Tables ────────────────────────────────────── -->
<div class="row g-4 mb-4">

  <!-- Recent Transactions -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
        <h6 class="fw-bold mb-0"><i class="fas fa-exchange-alt text-warning me-2"></i>Recent Transactions</h6>
        <a href="<?= APP_URL ?>/modules/accounting/transactions.php" class="small">View All →</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($recentTx)): ?>
        <div class="text-center text-muted py-4 px-3">
          <i class="fas fa-receipt fa-2x mb-2 opacity-25"></i>
          <p class="mb-0 small">No transaction records yet.</p>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($recentTx as $tx): ?>
          <div class="list-group-item px-3 py-2 d-flex align-items-center gap-2">
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-semibold small text-truncate"><?= e($tx['description'] ?? '—') ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= timeAgo($tx['created_at']) ?></div>
            </div>
            <div class="text-end flex-shrink-0">
              <div class="fw-bold small <?= (float)($tx['amount']??0) >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatCurrency((float)($tx['amount']??0)) ?></div>
              <div><?= statusBadge($tx['type'] ?? 'other') ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Top Clients by Revenue -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
        <h6 class="fw-bold mb-0"><i class="fas fa-star text-success me-2"></i>Top Clients by Revenue</h6>
        <a href="<?= APP_URL ?>/modules/crm/contacts.php" class="small">View All →</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($topClients)): ?>
        <div class="text-center text-muted py-4 px-3">
          <i class="fas fa-users fa-2x mb-2 opacity-25"></i>
          <p class="mb-0 small">No client revenue data yet.</p>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($topClients as $i => $client): ?>
          <div class="list-group-item px-3 py-2 d-flex align-items-center gap-2">
            <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white flex-shrink-0"
                 style="width:30px;height:30px;background:<?= ['#1A8A4E','#3b82f6','#f59e0b','#ef4444','#8b5cf6'][$i] ?? '#94a3b8' ?>;font-size:.75rem">
              <?= $i + 1 ?>
            </div>
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-semibold small text-truncate"><?= e($client['name']) ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= (int)$client['invoice_count'] ?> invoice(s)</div>
            </div>
            <div class="text-success fw-bold small flex-shrink-0"><?= formatCurrency((float)$client['total_rev']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Upcoming Tasks / Appointments -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
        <h6 class="fw-bold mb-0"><i class="fas fa-calendar-alt text-primary me-2"></i>Upcoming (7 Days)</h6>
        <a href="<?= APP_URL ?>/client/reminders.php" class="small">View All →</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($upcoming)): ?>
        <div class="text-center text-muted py-4 px-3">
          <i class="fas fa-calendar-check fa-2x mb-2 opacity-25"></i>
          <p class="mb-0 small">Nothing scheduled in the next 7 days.</p>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($upcoming as $task): ?>
          <div class="list-group-item px-3 py-2 d-flex align-items-center gap-2">
            <div><?= $sourceIcons[$task['source']] ?? '<i class="fas fa-dot-circle text-secondary me-1"></i>' ?></div>
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-semibold small text-truncate"><?= e($task['title']) ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= formatDate($task['due_date']) ?></div>
            </div>
            <?php
            $daysUntil = max(0, (int)ceil((strtotime($task['due_date']??'now') - time()) / 86400));
            $daysClass = $daysUntil === 0 ? 'bg-danger' : ($daysUntil <= 2 ? 'bg-warning text-dark' : 'bg-secondary');
            ?>
            <span class="badge <?= $daysClass ?> flex-shrink-0">
              <?= $daysUntil === 0 ? 'Today' : "In {$daysUntil}d" ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<!-- Footer note -->
<p class="text-muted text-center small mt-2 mb-4">
  <i class="fas fa-info-circle me-1"></i>Data aggregated from your active modules. Empty sections indicate no data for that module yet.
</p>

<?php
$extraJs = <<<JS
<script>
(function() {
  // Revenue trend line chart
  const revCtx = document.getElementById('revChart');
  if (revCtx) {
    new Chart(revCtx, {
      type: 'line',
      data: {
        labels: {$revLabelsJson},
        datasets: [{
          label: 'Revenue',
          data: {$revDataJson},
          borderColor: '#1A8A4E',
          backgroundColor: 'rgba(26,138,78,.1)',
          borderWidth: 2.5,
          pointBackgroundColor: '#1A8A4E',
          pointRadius: 4,
          tension: 0.35,
          fill: true
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits:2}) } }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { callback: v => v.toLocaleString() },
            grid: { color: 'rgba(148,163,184,.12)' }
          },
          x: { grid: { color: 'rgba(148,163,184,.08)' } }
        }
      }
    });
  }

  // Module usage pie chart
  const pieCtx = document.getElementById('pieChart');
  if (pieCtx) {
    new Chart(pieCtx, {
      type: 'doughnut',
      data: {
        labels: {$pieLabelsJson},
        datasets: [{
          data: {$pieDataJson},
          backgroundColor: {$pieColorsJson},
          borderWidth: 2,
          borderColor: 'transparent',
          hoverOffset: 6
        }]
      },
      options: {
        responsive: true,
        cutout: '60%',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + ctx.parsed.toLocaleString() } }
        }
      }
    });
  }
})();
</script>
JS;

require_once __DIR__ . '/../includes/footer.php';
