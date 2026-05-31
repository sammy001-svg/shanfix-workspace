<?php
// ── Bootstrap (CSV export must run before any HTML) ─────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();
requireAdminRole('Reports are available to organisation administrators only.');

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Report Builder: whitelisted data sources ─────────────────────
$REPORT_SOURCES = [
    'crm_contacts'        => ['label'=>'CRM — Contacts',          'cols'=>['id','first_name','last_name','email','phone','company','type','status','created_at']],
    'crm_leads'           => ['label'=>'CRM — Leads',             'cols'=>['id','title','contact_name','email','phone','source','status','value','created_at']],
    'crm_deals'           => ['label'=>'CRM — Deals',             'cols'=>['id','title','contact_name','company','stage','value','close_date','created_at']],
    'hrm_employees'       => ['label'=>'HRM — Employees',         'cols'=>['id','employee_no','first_name','last_name','email','phone','position','employment_type','salary','status','date_hired']],
    'hrm_leave_requests'  => ['label'=>'HRM — Leave Requests',    'cols'=>['id','employee_id','leave_type_id','start_date','end_date','days','reason','status','created_at']],
    'hrm_payroll'         => ['label'=>'HRM — Payroll',           'cols'=>['id','employee_id','period','basic_salary','gross_salary','net_salary','paye','status']],
    'acc_expenses'        => ['label'=>'Accounting — Expenses',   'cols'=>['id','category','description','amount','date','payment_method','reference','created_at']],
    'fin_transactions'    => ['label'=>'Finance — Transactions',  'cols'=>['id','type','amount','description','date','reference','created_at']],
    'pos_sales'           => ['label'=>'POS — Sales',             'cols'=>['id','invoice_no','customer_name','subtotal','discount','total','payment_method','created_at']],
    'pos_products'        => ['label'=>'POS — Products',          'cols'=>['id','name','sku','category','price','cost','stock_qty','status']],
    'rental_tenants'      => ['label'=>'Rental — Tenants',        'cols'=>['id','first_name','last_name','phone','email','unit_id','lease_start','lease_end','status']],
    'rental_payments'     => ['label'=>'Rental — Payments',       'cols'=>['id','tenant_id','amount','period','payment_date','payment_method','reference','status']],
    'health_patients'     => ['label'=>'Health — Patients',       'cols'=>['id','first_name','last_name','phone','email','gender','dob','blood_group','status']],
    'health_appointments' => ['label'=>'Health — Appointments',   'cols'=>['id','patient_id','doctor_id','date','time','type','status','created_at']],
    'sacco_members'       => ['label'=>'SACCO — Members',         'cols'=>['id','member_no','first_name','last_name','phone','email','shares','status','joined_date']],
    'sacco_loans'         => ['label'=>'SACCO — Loans',           'cols'=>['id','member_id','loan_type','amount','interest_rate','period_months','status','disbursed_date']],
    'mall_tenants'        => ['label'=>'Shopping Mall — Tenants', 'cols'=>['id','business_name','contact_person','phone','email','shop_id','lease_start','lease_end','status']],
    'mall_rent_payments'  => ['label'=>'Mall — Rent Payments',    'cols'=>['id','tenant_id','shop_id','amount','period','payment_date','payment_method','status']],
    'activity_log'        => ['label'=>'System — Activity Log',   'cols'=>['id','user_id','action','module','description','ip_address','created_at']],
    'users'               => ['label'=>'System — Team Members',   'cols'=>['id','name','email','role','status','created_at']],
];

// ── AJAX: run custom report ───────────────────────────────────────
if (($_GET['action'] ?? '') === 'run_report') {
    header('Content-Type: application/json');
    $source  = $_GET['source'] ?? '';
    $cols    = array_filter((array)($_GET['cols'] ?? []));
    $from    = $_GET['from']   ?? '';
    $to      = $_GET['to']     ?? '';
    $limit   = min(500, max(10, (int)($_GET['limit'] ?? 100)));

    if (!isset($REPORT_SOURCES[$source])) {
        echo json_encode(['error' => 'Invalid data source.']); exit;
    }
    $allowed = $REPORT_SOURCES[$source]['cols'];
    $cols = array_filter($cols, fn($c) => in_array($c, $allowed, true));
    if (empty($cols)) { echo json_encode(['error' => 'Select at least one column.']); exit; }

    // Build SELECT and WHERE
    $selects = array_map(fn($c) => "`$c`", $cols);
    $where   = 'org_id = ?';
    $params  = [$orgId];
    $dateCol = in_array('created_at', $allowed) ? 'created_at' : (in_array('date', $allowed) ? 'date' : null);
    if ($dateCol && $from) { $where .= " AND `$dateCol` >= ?"; $params[] = $from . ' 00:00:00'; }
    if ($dateCol && $to)   { $where .= " AND `$dateCol` <= ?"; $params[] = $to . ' 23:59:59'; }
    // special tables without org_id
    if ($source === 'activity_log') { $where = 'org_id = ?'; }
    if ($source === 'users') { $where = 'org_id = ?'; }

    try {
        $sql  = "SELECT " . implode(',', $selects) . " FROM `{$source}` WHERE {$where} ORDER BY id DESC LIMIT {$limit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['cols' => $cols, 'rows' => $stmt->fetchAll(PDO::FETCH_NUM)]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Query failed: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: save report template ────────────────────────────────────
if (($_POST['action'] ?? '') === 'save_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    verifyCsrf();
    $name   = sanitize($_POST['name']   ?? '');
    $source = $_POST['source']          ?? '';
    $cols   = json_decode($_POST['cols'] ?? '[]', true);
    $from   = sanitize($_POST['from']   ?? '');
    $to     = sanitize($_POST['to']     ?? '');
    if (!$name || !$source || empty($cols)) { echo json_encode(['error' => 'Name, source and columns required.']); exit; }
    $existing = json_decode(getOrgSetting($orgId, 'report_templates', '[]'), true) ?: [];
    $existing = array_values(array_filter($existing, fn($t) => $t['name'] !== $name)); // overwrite same name
    $existing[] = ['name'=>$name,'source'=>$source,'cols'=>$cols,'from'=>$from,'to'=>$to,'saved'=>date('Y-m-d H:i')];
    saveOrgSetting($orgId, 'report_templates', json_encode(array_slice($existing, -20))); // cap 20
    echo json_encode(['success' => true]);
    exit;
}

// ── AJAX: delete template ─────────────────────────────────────────
if (($_POST['action'] ?? '') === 'delete_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    verifyCsrf();
    $name     = sanitize($_POST['name'] ?? '');
    $existing = json_decode(getOrgSetting($orgId, 'report_templates', '[]'), true) ?: [];
    $existing = array_values(array_filter($existing, fn($t) => $t['name'] !== $name));
    saveOrgSetting($orgId, 'report_templates', json_encode($existing));
    echo json_encode(['success' => true]);
    exit;
}

// Helper: getOrgSetting / saveOrgSetting (inline if not already loaded)
if (!function_exists('getOrgSetting')) {
    function getOrgSetting(int $orgId, string $key, string $default = ''): string {
        global $pdo;
        try {
            $s = $pdo->prepare("SELECT `value` FROM org_settings WHERE org_id=? AND `key`=? LIMIT 1");
            $s->execute([$orgId, $key]);
            $v = $s->fetchColumn();
            return ($v !== false) ? (string)$v : $default;
        } catch (Exception $e) { return $default; }
    }
    function saveOrgSetting(int $orgId, string $key, string $value): void {
        global $pdo;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS org_settings (id INT AUTO_INCREMENT PRIMARY KEY, org_id INT NOT NULL, `key` VARCHAR(64) NOT NULL, `value` TEXT NOT NULL, UNIQUE KEY uq_org_key (org_id, `key`))");
            $pdo->prepare("INSERT INTO org_settings (org_id,`key`,`value`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$orgId,$key,$value,$value]);
        } catch (Exception $e) {}
    }
}

// Load saved templates for UI
$savedTemplates = json_decode(getOrgSetting($orgId, 'report_templates', '[]'), true) ?: [];

// ── CSV Export Handler ────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/../includes/export.php';
    $report = $_GET['report'] ?? '';

    switch ($report) {

        case 'financial':
            try {
                $rows = $pdo->prepare("
                    SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,
                           SUM(CASE WHEN type='invoice' THEN amount ELSE 0 END) AS invoiced,
                           SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expenses
                    FROM acc_transactions WHERE org_id=?
                    GROUP BY month ORDER BY month DESC LIMIT 24")->execute([$orgId]);
                $rows = $pdo->prepare("
                    SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,
                           SUM(CASE WHEN type='invoice' THEN amount ELSE 0 END) AS invoiced,
                           SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expenses
                    FROM acc_transactions WHERE org_id=? GROUP BY month ORDER BY month DESC LIMIT 24");
                $rows->execute([$orgId]);
                $data = $rows->fetchAll();
            } catch (Exception $e) { $data = []; }
            exportCsv('financial-summary.csv', ['Month','Invoiced','Expenses','Net'],
                array_map(fn($r) => [$r['month'], $r['invoiced'], $r['expenses'],
                    $r['invoiced'] - $r['expenses']], $data));

        case 'team':
            try {
                $stmt = $pdo->prepare("
                    SELECT u.name, COUNT(*) AS total_actions, MAX(a.created_at) AS last_active
                    FROM activity_log a
                    JOIN users u ON u.id = a.user_id
                    WHERE a.org_id=? GROUP BY a.user_id ORDER BY total_actions DESC LIMIT 50");
                $stmt->execute([$orgId]);
                $data = $stmt->fetchAll();
            } catch (Exception $e) { $data = []; }
            exportCsv('team-activity.csv', ['User','Total Actions','Last Active'],
                array_map(fn($r) => [$r['name'], $r['total_actions'], $r['last_active']], $data));

        case 'sales':
            try {
                $stmt = $pdo->prepare("
                    SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,
                           COUNT(*) AS orders, SUM(total_amount) AS revenue
                    FROM sales_orders WHERE org_id=?
                    GROUP BY month ORDER BY month DESC LIMIT 12");
                $stmt->execute([$orgId]);
                $data = $stmt->fetchAll();
            } catch (Exception $e) { $data = []; }
            exportCsv('sales-performance.csv', ['Month','Orders','Revenue'],
                array_map(fn($r) => [$r['month'], $r['orders'], $r['revenue']], $data));

        case 'modules':
            try {
                $stmt = $pdo->prepare("
                    SELECT m.name, m.slug, sm.status
                    FROM subscription_modules sm
                    JOIN modules m ON m.id = sm.module_id
                    JOIN subscriptions s ON s.id = sm.subscription_id
                    WHERE s.org_id=?");
                $stmt->execute([$orgId]);
                $data = $stmt->fetchAll();
            } catch (Exception $e) { $data = []; }
            exportCsv('module-usage.csv', ['Module','Slug','Status'],
                array_map(fn($r) => [$r['name'], $r['slug'], $r['status']], $data));

        case 'members':
            $counts = [];
            foreach (['sacco_members'=>'SACCO','church_members'=>'Church','salon_clients'=>'Salon','crm_contacts'=>'CRM'] as $tbl => $label) {
                try {
                    $n = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` WHERE org_id=?");
                    $n->execute([$orgId]);
                    $counts[] = [$label, (int)$n->fetchColumn()];
                } catch (Exception $e) {}
            }
            exportCsv('member-overview.csv', ['Module','Member Count'], $counts);

        case 'subscription':
            try {
                $stmt = $pdo->prepare("SELECT s.*, p.name AS plan_name FROM subscriptions s LEFT JOIN subscription_plans p ON p.id=s.plan_id WHERE s.org_id=? ORDER BY s.created_at DESC LIMIT 1");
                $stmt->execute([$orgId]);
                $sub = $stmt->fetch();
            } catch (Exception $e) { $sub = []; }
            $rows = $sub ? [[$sub['plan_name'] ?? 'N/A', $sub['status'] ?? '', $sub['starts_at'] ?? '', $sub['expires_at'] ?? '']] : [];
            exportCsv('subscription-status.csv', ['Plan','Status','Start Date','Expiry Date'], $rows);

        default:
            header('HTTP/1.1 400 Bad Request');
            exit('Unknown report.');
    }
}

// ── Page setup ───────────────────────────────────────────────────
$pageTitle = 'Reports & Analytics';
require_once __DIR__ . '/../includes/header-client.php';

// ── Data for dashboard cards ─────────────────────────────────────
// Financial
$totalInvoiced = $totalExpenses = 0.0;
try {
    $s = $pdo->prepare("SELECT SUM(CASE WHEN type='invoice' THEN amount ELSE 0 END) AS i, SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS e FROM acc_transactions WHERE org_id=?");
    $s->execute([$orgId]); $fin = $s->fetch();
    $totalInvoiced = (float)($fin['i'] ?? 0);
    $totalExpenses = (float)($fin['e'] ?? 0);
} catch (Exception $e) {}

// Team activity (top 10)
$teamRows = [];
try {
    $s = $pdo->prepare("SELECT u.name, COUNT(*) AS total_actions, a.module, MAX(a.created_at) AS last_active FROM activity_log a JOIN users u ON u.id=a.user_id WHERE a.org_id=? GROUP BY a.user_id ORDER BY total_actions DESC LIMIT 10");
    $s->execute([$orgId]);
    $teamRows = $s->fetchAll();
} catch (Exception $e) {}

// Sales by month (last 12)
$salesLabels = $salesData = [];
try {
    $s = $pdo->prepare("SELECT DATE_FORMAT(created_at,'%b %Y') AS m, SUM(total_amount) AS rev FROM sales_orders WHERE org_id=? GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY MIN(created_at) DESC LIMIT 12");
    $s->execute([$orgId]);
    foreach (array_reverse($s->fetchAll()) as $r) {
        $salesLabels[] = $r['m'];
        $salesData[]   = round((float)$r['rev'], 2);
    }
} catch (Exception $e) {}

// Member counts
$memberCounts = [];
foreach (['sacco_members'=>'SACCO Members','church_members'=>'Church Members','salon_clients'=>'Salon Clients','crm_contacts'=>'CRM Contacts'] as $tbl => $label) {
    try {
        $n = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` WHERE org_id=?");
        $n->execute([$orgId]);
        $memberCounts[$label] = (int)$n->fetchColumn();
    } catch (Exception $e) { $memberCounts[$label] = 0; }
}

// Subscription
$sub = null;
try {
    $s = $pdo->prepare("SELECT s.*, p.name AS plan_name FROM subscriptions s LEFT JOIN subscription_plans p ON p.id=s.plan_id WHERE s.org_id=? ORDER BY s.created_at DESC LIMIT 1");
    $s->execute([$orgId]); $sub = $s->fetch();
} catch (Exception $e) {}
$daysLeft = $sub && $sub['expires_at'] ? max(0, (int)ceil((strtotime($sub['expires_at']) - time()) / 86400)) : 0;

// Module usage
$activeModules = [];
try {
    $s = $pdo->prepare("SELECT m.name, m.slug, sm.status FROM subscription_modules sm JOIN modules m ON m.id=sm.module_id JOIN subscriptions sub ON sub.id=sm.subscription_id WHERE sub.org_id=?");
    $s->execute([$orgId]);
    $activeModules = $s->fetchAll();
} catch (Exception $e) {}

$labelsJson = json_encode($salesLabels);
$dataJson   = json_encode($salesData);
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h4><i class="fas fa-chart-bar me-2 text-green"></i>Reports &amp; Analytics</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Reports</li>
    </ol></nav>
  </div>
</div>

<?= flashAlert() ?>

<!-- ── Report Cards ─────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

  <!-- Financial Summary -->
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-3 p-2" style="background:#e8f5e9"><i class="fas fa-coins fa-lg text-success"></i></div>
          <div><h6 class="mb-0 fw-bold">Financial Summary</h6><small class="text-muted">Income vs Expenses</small></div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6"><div class="p-2 rounded" style="background:#f0fdf4"><div class="text-muted small">Invoiced</div><strong class="text-success"><?= formatCurrency($totalInvoiced) ?></strong></div></div>
          <div class="col-6"><div class="p-2 rounded" style="background:#fff5f5"><div class="text-muted small">Expenses</div><strong class="text-danger"><?= formatCurrency($totalExpenses) ?></strong></div></div>
          <div class="col-12"><div class="p-2 rounded" style="background:#eff6ff"><div class="text-muted small">Net Balance</div><strong class="text-primary"><?= formatCurrency($totalInvoiced - $totalExpenses) ?></strong></div></div>
        </div>
        <div class="d-flex gap-2">
          <a href="#section-financial" class="btn btn-sm btn-outline-success flex-fill" onclick="showSection('financial')"><i class="fas fa-eye me-1"></i>View</a>
          <a href="?export=csv&report=financial" class="btn btn-sm btn-success flex-fill"><i class="fas fa-download me-1"></i>Export CSV</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Team Activity -->
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-3 p-2" style="background:#e3f2fd"><i class="fas fa-users fa-lg text-primary"></i></div>
          <div><h6 class="mb-0 fw-bold">Team Activity</h6><small class="text-muted">User actions &amp; engagement</small></div>
        </div>
        <p class="text-muted small mb-3">Track the most active team members and top performed actions across all modules.</p>
        <div class="d-flex gap-2">
          <a href="#section-team" class="btn btn-sm btn-outline-primary flex-fill" onclick="showSection('team')"><i class="fas fa-eye me-1"></i>View</a>
          <a href="?export=csv&report=team" class="btn btn-sm btn-primary flex-fill"><i class="fas fa-download me-1"></i>Export CSV</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Sales Performance -->
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-3 p-2" style="background:#fff3e0"><i class="fas fa-chart-line fa-lg text-warning"></i></div>
          <div><h6 class="mb-0 fw-bold">Sales Performance</h6><small class="text-muted">Revenue by month</small></div>
        </div>
        <p class="text-muted small mb-3">Monthly revenue trends across sales orders. Visualized as an interactive bar chart.</p>
        <div class="d-flex gap-2">
          <a href="#section-sales" class="btn btn-sm btn-outline-warning flex-fill" onclick="showSection('sales')"><i class="fas fa-eye me-1"></i>View</a>
          <a href="?export=csv&report=sales" class="btn btn-sm btn-warning flex-fill"><i class="fas fa-download me-1"></i>Export CSV</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Module Usage -->
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-3 p-2" style="background:#f3e5f5"><i class="fas fa-cubes fa-lg text-purple" style="color:#9c27b0"></i></div>
          <div><h6 class="mb-0 fw-bold">Module Usage</h6><small class="text-muted">Active modules &amp; record counts</small></div>
        </div>
        <p class="text-muted small mb-3">See which modules are currently active on your subscription and how many records exist in each.</p>
        <div class="d-flex gap-2">
          <a href="#section-modules" class="btn btn-sm btn-outline-secondary flex-fill" onclick="showSection('modules')"><i class="fas fa-eye me-1"></i>View</a>
          <a href="?export=csv&report=modules" class="btn btn-sm btn-secondary flex-fill"><i class="fas fa-download me-1"></i>Export CSV</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Member Overview -->
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-3 p-2" style="background:#e8eaf6"><i class="fas fa-id-card fa-lg" style="color:#3f51b5"></i></div>
          <div><h6 class="mb-0 fw-bold">Member Overview</h6><small class="text-muted">Cross-module member counts</small></div>
        </div>
        <div class="row g-1 mb-3">
          <?php foreach ($memberCounts as $lbl => $cnt): ?>
          <div class="col-6"><div class="p-1 rounded text-center" style="background:#f8fafc"><small class="text-muted d-block"><?= e($lbl) ?></small><strong><?= number_format($cnt) ?></strong></div></div>
          <?php endforeach; ?>
        </div>
        <div class="d-flex gap-2">
          <a href="#section-members" class="btn btn-sm btn-outline-dark flex-fill" onclick="showSection('members')"><i class="fas fa-eye me-1"></i>View</a>
          <a href="?export=csv&report=members" class="btn btn-sm btn-dark flex-fill"><i class="fas fa-download me-1"></i>Export CSV</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Custom Report Builder -->
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 shadow-sm border-0" style="border-top:3px solid #8e44ad!important">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-3 p-2" style="background:#f3e5f5"><i class="fas fa-tools fa-lg" style="color:#8e44ad"></i></div>
          <div><h6 class="mb-0 fw-bold">Custom Report Builder</h6><small class="text-muted">Build, save &amp; export any report</small></div>
        </div>
        <p class="text-muted small mb-3">Choose any data source, pick your columns, set a date range and preview results. Save as a named template for quick re-use.</p>
        <div class="d-flex gap-2">
          <a href="#section-builder" class="btn btn-sm btn-outline-secondary flex-fill" onclick="showSection('builder')"><i class="fas fa-tools me-1"></i>Open Builder</a>
          <?php if (!empty($savedTemplates)): ?>
          <span class="badge rounded-pill align-self-center" style="background:#8e44ad"><?= count($savedTemplates) ?> saved</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Subscription Status -->
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rounded-3 p-2" style="background:#e0f7fa"><i class="fas fa-crown fa-lg" style="color:#00bcd4"></i></div>
          <div><h6 class="mb-0 fw-bold">Subscription Status</h6><small class="text-muted">Plan &amp; usage summary</small></div>
        </div>
        <?php if ($sub): ?>
        <div class="mb-2 small">
          <strong>Plan:</strong> <?= e($sub['plan_name'] ?? 'N/A') ?> &nbsp; <?= statusBadge($sub['status'] ?? 'unknown') ?><br>
          <strong>Expires:</strong> <?= formatDate($sub['expires_at']) ?><br>
          <strong>Days Left:</strong> <span class="<?= $daysLeft < 7 ? 'text-danger fw-bold' : 'text-success' ?>"><?= $daysLeft ?> days</span>
        </div>
        <?php else: ?><p class="text-muted small">No active subscription found.</p><?php endif; ?>
        <div class="d-flex gap-2">
          <a href="#section-subscription" class="btn btn-sm btn-outline-info flex-fill" onclick="showSection('subscription')"><i class="fas fa-eye me-1"></i>View</a>
          <a href="?export=csv&report=subscription" class="btn btn-sm btn-info flex-fill text-white"><i class="fas fa-download me-1"></i>Export CSV</a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Detail Sections ───────────────────────────────────────────── -->
<div id="report-sections">

  <!-- Financial -->
  <div id="section-financial" class="report-section card shadow-sm border-0 mb-4 d-none">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span><i class="fas fa-coins text-success me-2"></i>Financial Summary</span>
      <button class="btn btn-sm btn-outline-secondary" onclick="hideSection('financial')"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="p-3 rounded text-center" style="background:#f0fdf4;border:1px solid #bbf7d0"><div class="text-muted small">Total Invoiced</div><h4 class="text-success mb-0"><?= formatCurrency($totalInvoiced) ?></h4></div></div>
        <div class="col-md-4"><div class="p-3 rounded text-center" style="background:#fff5f5;border:1px solid #fecaca"><div class="text-muted small">Total Expenses</div><h4 class="text-danger mb-0"><?= formatCurrency($totalExpenses) ?></h4></div></div>
        <div class="col-md-4"><div class="p-3 rounded text-center" style="background:#eff6ff;border:1px solid #bfdbfe"><div class="text-muted small">Net Balance</div><h4 class="text-primary mb-0"><?= formatCurrency($totalInvoiced - $totalExpenses) ?></h4></div></div>
      </div>
    </div>
  </div>

  <!-- Team Activity -->
  <div id="section-team" class="report-section card shadow-sm border-0 mb-4 d-none">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span><i class="fas fa-users text-primary me-2"></i>Team Activity</span>
      <button class="btn btn-sm btn-outline-secondary" onclick="hideSection('team')"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <table class="table table-hover" id="teamTable">
        <thead><tr><th>User</th><th>Module</th><th>Total Actions</th><th>Last Active</th></tr></thead>
        <tbody>
          <?php foreach ($teamRows as $r): ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td><span class="badge bg-secondary"><?= e($r['module'] ?? 'general') ?></span></td>
            <td><strong><?= number_format((int)$r['total_actions']) ?></strong></td>
            <td><?= formatDate($r['last_active']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($teamRows)): ?><tr><td colspan="4" class="text-center text-muted py-4">No activity data yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Sales Performance -->
  <div id="section-sales" class="report-section card shadow-sm border-0 mb-4 d-none">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span><i class="fas fa-chart-line text-warning me-2"></i>Sales Performance — Monthly Revenue</span>
      <button class="btn btn-sm btn-outline-secondary" onclick="hideSection('sales')"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body"><canvas id="salesChart" height="100"></canvas></div>
  </div>

  <!-- Module Usage -->
  <div id="section-modules" class="report-section card shadow-sm border-0 mb-4 d-none">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span><i class="fas fa-cubes me-2" style="color:#9c27b0"></i>Module Usage</span>
      <button class="btn btn-sm btn-outline-secondary" onclick="hideSection('modules')"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <?php if (empty($activeModules)): ?>
        <p class="text-muted text-center py-3">No modules found on current subscription.</p>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($activeModules as $m): ?>
          <div class="col-md-3">
            <div class="p-3 rounded border text-center">
              <i class="fas fa-puzzle-piece fa-2x text-secondary mb-2"></i>
              <div class="fw-bold small"><?= e($m['name']) ?></div>
              <div><?= statusBadge($m['status']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Member Overview -->
  <div id="section-members" class="report-section card shadow-sm border-0 mb-4 d-none">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span><i class="fas fa-id-card me-2" style="color:#3f51b5"></i>Member Overview</span>
      <button class="btn btn-sm btn-outline-secondary" onclick="hideSection('members')"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <?php foreach ($memberCounts as $label => $cnt): ?>
        <div class="col-md-3">
          <div class="p-3 rounded border text-center">
            <h3 class="text-primary mb-1"><?= number_format($cnt) ?></h3>
            <div class="text-muted small"><?= e($label) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Subscription Status -->
  <div id="section-subscription" class="report-section card shadow-sm border-0 mb-4 d-none">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span><i class="fas fa-crown me-2" style="color:#00bcd4"></i>Subscription Status</span>
      <button class="btn btn-sm btn-outline-secondary" onclick="hideSection('subscription')"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <?php if ($sub): ?>
      <table class="table table-bordered w-auto">
        <tr><th>Plan</th><td><?= e($sub['plan_name'] ?? 'N/A') ?></td></tr>
        <tr><th>Status</th><td><?= statusBadge($sub['status'] ?? 'unknown') ?></td></tr>
        <tr><th>Start Date</th><td><?= formatDate($sub['starts_at'] ?? '') ?></td></tr>
        <tr><th>Expiry Date</th><td><?= formatDate($sub['expires_at'] ?? '') ?></td></tr>
        <tr><th>Days Remaining</th><td><span class="<?= $daysLeft < 7 ? 'text-danger fw-bold' : 'text-success' ?>"><?= $daysLeft ?> days</span></td></tr>
      </table>
      <?php else: ?><p class="text-muted">No active subscription found.</p><?php endif; ?>
    </div>
  </div>

  <!-- Custom Report Builder -->
  <div id="section-builder" class="report-section card shadow-sm border-0 mb-4 d-none">
    <div class="card-header d-flex align-items-center justify-content-between" style="background:linear-gradient(135deg,#8e44ad,#6c3483);color:white">
      <span><i class="fas fa-tools me-2"></i>Custom Report Builder</span>
      <button class="btn btn-sm btn-light btn-close-builder" onclick="hideSection('builder')"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-3">
        <!-- Source -->
        <div class="col-md-4">
          <label class="form-label fw-semibold">Data Source <span class="text-danger">*</span></label>
          <select id="rbSource" class="form-select" onchange="rbLoadColumns()">
            <option value="">-- Select source --</option>
            <?php foreach ($REPORT_SOURCES as $tbl => $meta): ?>
              <option value="<?= $tbl ?>"><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Date from / to -->
        <div class="col-md-2">
          <label class="form-label fw-semibold">From</label>
          <input type="date" id="rbFrom" class="form-control" value="<?= date('Y-m-01') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold">To</label>
          <input type="date" id="rbTo" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <!-- Limit -->
        <div class="col-md-2">
          <label class="form-label fw-semibold">Max Rows</label>
          <select id="rbLimit" class="form-select">
            <option value="50">50</option>
            <option value="100" selected>100</option>
            <option value="250">250</option>
            <option value="500">500</option>
          </select>
        </div>
        <!-- Actions -->
        <div class="col-md-2 d-flex flex-column justify-content-end gap-2">
          <button class="btn btn-sm" style="background:#8e44ad;color:white" onclick="rbRun()"><i class="fas fa-play me-1"></i>Run Report</button>
          <button class="btn btn-sm btn-outline-secondary" onclick="rbExportCsv()"><i class="fas fa-download me-1"></i>Export CSV</button>
        </div>
      </div>

      <!-- Column selector -->
      <div id="rbColSection" style="display:none" class="mb-3">
        <label class="form-label fw-semibold">Columns <span class="text-muted fw-normal small">(select all you want to include)</span></label>
        <div id="rbCols" class="d-flex flex-wrap gap-2"></div>
        <button class="btn btn-xs btn-outline-secondary mt-2" style="font-size:.75rem;padding:2px 10px" onclick="rbSelectAll()">Select All</button>
        <button class="btn btn-xs btn-outline-secondary mt-2 ms-1" style="font-size:.75rem;padding:2px 10px" onclick="rbClearAll()">Clear All</button>
      </div>

      <!-- Save template row -->
      <div id="rbSaveRow" style="display:none" class="d-flex align-items-center gap-2 mb-3 p-2 rounded" style="background:#f8f0ff;border:1px solid #e0c4f5">
        <input type="text" id="rbTemplateName" class="form-control form-control-sm" placeholder="Template name…" style="max-width:220px">
        <button class="btn btn-sm" style="background:#8e44ad;color:white" onclick="rbSaveTemplate()"><i class="fas fa-save me-1"></i>Save Template</button>
        <?php if (!empty($savedTemplates)): ?>
        <span class="text-muted small ms-2">Saved:</span>
        <?php foreach ($savedTemplates as $tpl): ?>
        <button class="btn btn-sm btn-outline-secondary" onclick='rbLoadTemplate(<?= htmlspecialchars(json_encode($tpl), ENT_QUOTES) ?>)' title="Load template">
          <?= e($tpl['name']) ?>
        </button>
        <button class="btn btn-sm btn-outline-danger" onclick="rbDeleteTemplate('<?= e($tpl['name']) ?>')" title="Delete"><i class="fas fa-times"></i></button>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Results -->
      <div id="rbResults" style="display:none">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="fw-semibold small" id="rbResultCount"></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover data-table mb-0" id="rbTable">
            <thead id="rbThead" class="table-light"></thead>
            <tbody id="rbTbody"></tbody>
          </table>
        </div>
      </div>
      <div id="rbEmpty" class="text-center text-muted py-5" style="display:none">
        <i class="fas fa-search fa-2x mb-2 d-block opacity-25"></i>No rows returned for your selection.
      </div>
      <div id="rbError" class="alert alert-danger d-none"></div>
    </div>
  </div>

</div>

<?php
$sourcesJson   = json_encode($REPORT_SOURCES);
$csrfTokenJson = json_encode($_SESSION['csrf_token'] ?? '');
$extraJs = <<<JS
<script>
const salesChart = document.getElementById('salesChart');
if (salesChart) {
    new Chart(salesChart, {
        type: 'bar',
        data: {
            labels: {$labelsJson},
            datasets: [{
                label: 'Revenue',
                data: {$dataJson},
                backgroundColor: 'rgba(26,138,78,0.7)',
                borderColor: '#1A8A4E',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ' KES ' + ctx.parsed.y.toLocaleString() } } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => 'KES ' + v.toLocaleString() } } }
        }
    });
}

$('#teamTable').DataTable({ pageLength: 10, order: [[2,'desc']] });

function showSection(id) {
    document.querySelectorAll('.report-section').forEach(el => el.classList.add('d-none'));
    const sec = document.getElementById('section-' + id);
    if (sec) { sec.classList.remove('d-none'); sec.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
}
function hideSection(id) {
    const sec = document.getElementById('section-' + id);
    if (sec) sec.classList.add('d-none');
}

// ── Report Builder ─────────────────────────────────────────────────
const SOURCES    = {$sourcesJson};
const CSRF_TOKEN = {$csrfTokenJson};
let   _rbTable   = null;

function rbLoadColumns() {
    const src  = document.getElementById('rbSource').value;
    const wrap = document.getElementById('rbColSection');
    const box  = document.getElementById('rbCols');
    const save = document.getElementById('rbSaveRow');
    box.innerHTML = '';
    if (!src || !SOURCES[src]) { wrap.style.display='none'; save.style.display='none'; return; }
    SOURCES[src].cols.forEach(col => {
        const lbl = document.createElement('label');
        lbl.className = 'd-flex align-items-center gap-1 px-2 py-1 rounded border small user-select-none';
        lbl.style.cursor = 'pointer';
        lbl.style.background = '#f8fafc';
        lbl.innerHTML = '<input type="checkbox" class="rb-col form-check-input m-0" value="' + col + '" checked> ' + col;
        box.appendChild(lbl);
    });
    wrap.style.display = '';
    save.style.display = '';
    document.getElementById('rbResults').style.display = 'none';
    document.getElementById('rbEmpty').style.display = 'none';
}

function rbSelectAll() { document.querySelectorAll('.rb-col').forEach(c => c.checked = true); }
function rbClearAll()  { document.querySelectorAll('.rb-col').forEach(c => c.checked = false); }

function rbRun() {
    const src  = document.getElementById('rbSource').value;
    const cols = Array.from(document.querySelectorAll('.rb-col:checked')).map(c => c.value);
    if (!src) { Swal.fire('Pick a source','Select a data source first.','warning'); return; }
    if (!cols.length) { Swal.fire('Pick columns','Select at least one column.','warning'); return; }
    const from  = document.getElementById('rbFrom').value;
    const to    = document.getElementById('rbTo').value;
    const limit = document.getElementById('rbLimit').value;
    const params = new URLSearchParams({ action:'run_report', source:src, from, to, limit });
    cols.forEach(c => params.append('cols[]', c));

    document.getElementById('rbError').classList.add('d-none');
    fetch(window.location.pathname + '?' + params.toString())
        .then(r => r.json())
        .then(res => {
            if (res.error) { document.getElementById('rbError').textContent = res.error; document.getElementById('rbError').classList.remove('d-none'); return; }
            const thead = document.getElementById('rbThead');
            const tbody = document.getElementById('rbTbody');
            thead.innerHTML = '<tr>' + res.cols.map(c => '<th>' + c + '</th>').join('') + '</tr>';
            tbody.innerHTML = res.rows.length
                ? res.rows.map(r => '<tr>' + r.map(v => '<td>' + (v ?? '') + '</td>').join('') + '</tr>').join('')
                : '';
            document.getElementById('rbResultCount').textContent = res.rows.length + ' row' + (res.rows.length!==1?'s':'') + ' returned';
            if (_rbTable) { try { _rbTable.destroy(); } catch(e){} _rbTable = null; }
            if (res.rows.length) {
                document.getElementById('rbResults').style.display = '';
                document.getElementById('rbEmpty').style.display = 'none';
                _rbTable = $('#rbTable').DataTable({ pageLength:25, order:[], responsive:true });
            } else {
                document.getElementById('rbResults').style.display = 'none';
                document.getElementById('rbEmpty').style.display = '';
            }
        })
        .catch(e => { document.getElementById('rbError').textContent = 'Network error: ' + e.message; document.getElementById('rbError').classList.remove('d-none'); });
}

function rbExportCsv() {
    const src  = document.getElementById('rbSource').value;
    const cols = Array.from(document.querySelectorAll('.rb-col:checked')).map(c => c.value);
    if (!src || !cols.length) { rbRun(); return; }
    const from  = document.getElementById('rbFrom').value;
    const to    = document.getElementById('rbTo').value;
    const params = new URLSearchParams({ action:'run_report', source:src, from, to, limit:500 });
    cols.forEach(c => params.append('cols[]', c));
    fetch(window.location.pathname + '?' + params.toString())
        .then(r => r.json())
        .then(res => {
            if (res.error || !res.rows) return;
            const rows = [res.cols, ...res.rows];
            const csv  = rows.map(r => r.map(v => '"' + String(v??'').replace(/"/g,'""') + '"').join(',')).join('\\n');
            const a    = document.createElement('a');
            a.href     = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
            a.download = src + '-' + new Date().toISOString().slice(0,10) + '.csv';
            a.click();
        });
}

function rbSaveTemplate() {
    const name = document.getElementById('rbTemplateName').value.trim();
    const src  = document.getElementById('rbSource').value;
    const cols = Array.from(document.querySelectorAll('.rb-col:checked')).map(c => c.value);
    if (!name || !src || !cols.length) { Swal.fire('Incomplete','Fill name, source and columns.','warning'); return; }
    fetch(window.location.pathname, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'save_template', _token:CSRF_TOKEN, name, source:src, cols:JSON.stringify(cols), from:document.getElementById('rbFrom').value, to:document.getElementById('rbTo').value })
    }).then(r=>r.json()).then(res => {
        if (res.success) { Swal.fire({icon:'success',title:'Saved',timer:1200,showConfirmButton:false}).then(()=>location.reload()); }
        else Swal.fire('Error', res.error ?? 'Could not save.', 'error');
    });
}

function rbLoadTemplate(tpl) {
    document.getElementById('rbSource').value = tpl.source;
    rbLoadColumns();
    setTimeout(() => {
        document.querySelectorAll('.rb-col').forEach(c => { c.checked = tpl.cols.includes(c.value); });
        if (tpl.from) document.getElementById('rbFrom').value = tpl.from;
        if (tpl.to)   document.getElementById('rbTo').value   = tpl.to;
        rbRun();
    }, 50);
}

function rbDeleteTemplate(name) {
    Swal.fire({title:'Delete template?',text:name,icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Delete'})
        .then(r => {
            if (!r.isConfirmed) return;
            fetch(window.location.pathname, {
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action:'delete_template',_token:CSRF_TOKEN,name})
            }).then(()=>location.reload());
        });
}
</script>
JS;
require_once __DIR__ . '/../includes/footer.php';
