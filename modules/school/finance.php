<?php
require_once __DIR__ . '/../../modules/school/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user   = currentUser();
$orgId  = (int)$user['org_id'];
$pageTitle = 'Finance Dashboard';

$selYear  = (int)($_GET['year'] ?? date('Y'));
$selMonth = (int)($_GET['month'] ?? 0);

// ── Helper: safe query ────────────────────────────────────────────
function safeScalar(PDO $pdo, string $sql, array $params = []): float {
    try {
        $s = $pdo->prepare($sql); $s->execute($params);
        return (float)($s->fetchColumn() ?: 0);
    } catch (Throwable $e) { return 0.0; }
}
function safeAll(PDO $pdo, string $sql, array $params = []): array {
    try {
        $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}

// ── Fee Collection ────────────────────────────────────────────────
$feeWhere  = $selMonth ? "AND YEAR(fp.payment_date)=? AND MONTH(fp.payment_date)=?" : "AND YEAR(fp.payment_date)=?";
$feeParams = $selMonth ? [$orgId,$selYear,$selMonth] : [$orgId,$selYear];

$feeCollected  = safeScalar($pdo,
    "SELECT COALESCE(SUM(fp.amount_paid),0) FROM sch_fee_payments fp WHERE fp.org_id=? $feeWhere",
    $feeParams);
$feeBilled = safeScalar($pdo,
    "SELECT COALESCE(SUM(fs.amount),0) FROM sch_fee_structure fs JOIN sch_academic_years ay ON ay.id=fs.academic_year_id WHERE fs.org_id=? AND ay.is_current=1",
    [$orgId]);
$feeOutstanding = max(0, $feeBilled - $feeCollected);

// Monthly fee trend (last 12 months)
$feeMonthly = safeAll($pdo,
    "SELECT DATE_FORMAT(fp.payment_date,'%Y-%m') AS ym,
            SUM(fp.amount_paid) AS collected
     FROM sch_fee_payments fp
     WHERE fp.org_id=? AND fp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
     GROUP BY ym ORDER BY ym",
    [$orgId]);

// ── Expenses ──────────────────────────────────────────────────────
$expWhere  = $selMonth ? "AND YEAR(expense_date)=? AND MONTH(expense_date)=?" : "AND YEAR(expense_date)=?";
$expParams = $selMonth ? [$orgId,$selYear,$selMonth] : [$orgId,$selYear];

$totalExpenses = safeScalar($pdo,
    "SELECT COALESCE(SUM(amount),0) FROM sch_expenses WHERE org_id=? $expWhere AND status IN ('approved','paid')",
    $expParams);
$pendingExpenses = safeScalar($pdo,
    "SELECT COALESCE(SUM(amount),0) FROM sch_expenses WHERE org_id=? $expWhere AND status='pending'",
    $expParams);

// Expense by category
$expByCategory = safeAll($pdo,
    "SELECT category, SUM(amount) AS total FROM sch_expenses WHERE org_id=? $expWhere AND status IN ('approved','paid') GROUP BY category ORDER BY total DESC LIMIT 8",
    $expParams);

// Monthly expense trend
$expMonthly = safeAll($pdo,
    "SELECT DATE_FORMAT(expense_date,'%Y-%m') AS ym, SUM(amount) AS total
     FROM sch_expenses WHERE org_id=? AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
     AND status IN ('approved','paid') GROUP BY ym ORDER BY ym",
    [$orgId]);

// ── Payroll ───────────────────────────────────────────────────────
$prWhere  = $selMonth ? "WHERE org_id=? AND period_year=? AND period_month=?" : "WHERE org_id=? AND period_year=?";
$prParams = $selMonth ? [$orgId,$selYear,$selMonth] : [$orgId,$selYear];

$totalPayroll = safeScalar($pdo,
    "SELECT COALESCE(SUM(total_net),0) FROM sch_payroll_runs $prWhere AND status IN ('approved','paid')",
    $prParams);
$payrollRuns = safeAll($pdo,
    "SELECT period_month, period_year, total_gross, total_net, status FROM sch_payroll_runs WHERE org_id=? AND period_year=? ORDER BY period_month DESC",
    [$orgId,$selYear]);

// ── Budget ────────────────────────────────────────────────────────
$budgetAllocated = safeScalar($pdo,
    "SELECT COALESCE(SUM(amount),0) FROM sch_budget WHERE org_id=? AND status='active' AND YEAR(budget_date)=?",
    [$orgId,$selYear]);
$budgetSpent = safeScalar($pdo,
    "SELECT COALESCE(SUM(spent),0) FROM sch_budget WHERE org_id=? AND status='active' AND YEAR(budget_date)=?",
    [$orgId,$selYear]);

// ── Net position ──────────────────────────────────────────────────
$totalOutflows = $totalExpenses + $totalPayroll;
$netPosition   = $feeCollected - $totalOutflows;

// ── Chart data ────────────────────────────────────────────────────
// Build last-12-months label set
$months12 = [];
for ($i = 11; $i >= 0; $i--) {
    $months12[] = date('Y-m', strtotime("-$i months"));
}
// Map monthly data
$feeMap = []; foreach ($feeMonthly as $r) $feeMap[$r['ym']] = (float)$r['collected'];
$expMap = []; foreach ($expMonthly as $r) $expMap[$r['ym']] = (float)$r['total'];

$chartLabels = array_map(fn($m)=>date('M Y',strtotime($m.'-01')), $months12);
$chartFee    = array_map(fn($m)=>$feeMap[$m]??0, $months12);
$chartExp    = array_map(fn($m)=>$expMap[$m]??0, $months12);

$monthNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

require_once __DIR__ . '/../../includes/header-module.php';

function fmtK(float $v): string {
    if ($v >= 1000000) return 'KES '.number_format($v/1000000,2).'M';
    if ($v >= 1000)    return 'KES '.number_format($v/1000,1).'K';
    return 'KES '.number_format($v,2);
}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-chart-pie me-2" style="color:#1A8A4E"></i>Finance Dashboard</h5>
    <div class="text-muted small mt-1">Consolidated school financial overview</div>
  </div>
  <form method="GET" class="d-flex gap-2 align-items-center">
    <select name="month" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <option value="0">All Months</option>
      <?php for ($m=1;$m<=12;$m++): ?>
      <option value="<?= $m ?>" <?= $selMonth===$m?'selected':'' ?>><?= $monthNames[$m] ?></option>
      <?php endfor; ?>
    </select>
    <select name="year" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <?php for ($y=date('Y');$y>=2020;$y--): ?>
      <option value="<?= $y ?>" <?= $selYear===$y?'selected':'' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
  </form>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <?php foreach ([
    ['val'=>fmtK($feeCollected), 'lbl'=>'Fee Income',      'sub'=>'Collected this period',  'icon'=>'fas fa-money-bill-wave','bg'=>'#f0fdf4','ic'=>'#1A8A4E','trend'=>null],
    ['val'=>fmtK($feeOutstanding),'lbl'=>'Outstanding Fees','sub'=>'Yet to be collected',    'icon'=>'fas fa-exclamation-circle','bg'=>'#fff7ed','ic'=>'#ea580c','trend'=>null],
    ['val'=>fmtK($totalExpenses),'lbl'=>'Expenses',        'sub'=>'Approved + paid',         'icon'=>'fas fa-receipt',       'bg'=>'#fef2f2','ic'=>'#dc2626','trend'=>null],
    ['val'=>fmtK($totalPayroll), 'lbl'=>'Payroll Cost',    'sub'=>'Net salaries paid',        'icon'=>'fas fa-users',         'bg'=>'#eff6ff','ic'=>'#1d4ed8','trend'=>null],
    ['val'=>fmtK(abs($netPosition)),'lbl'=>$netPosition>=0?'Net Surplus':'Net Deficit','sub'=>'Income minus all outflows','icon'=>$netPosition>=0?'fas fa-arrow-trend-up':'fas fa-arrow-trend-down','bg'=>$netPosition>=0?'#f0fdf4':'#fef2f2','ic'=>$netPosition>=0?'#1A8A4E':'#dc2626','trend'=>null],
    ['val'=>fmtK($pendingExpenses),'lbl'=>'Pending Expenses','sub'=>'Awaiting approval',     'icon'=>'fas fa-clock',         'bg'=>'#fffbeb','ic'=>'#f59e0b','trend'=>null],
  ] as $k): ?>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card border-0 shadow-sm h-100" style="background:<?= $k['bg'] ?>">
      <div class="card-body py-3 px-3">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="rounded d-flex align-items-center justify-content-center flex-shrink-0"
               style="width:36px;height:36px;background:<?= $k['ic'] ?>22">
            <i class="<?= $k['icon'] ?>" style="color:<?= $k['ic'] ?>;font-size:.9rem"></i>
          </div>
          <div class="text-muted" style="font-size:.7rem;line-height:1.2"><?= $k['lbl'] ?></div>
        </div>
        <div class="fw-bold" style="font-size:.95rem"><?= $k['val'] ?></div>
        <div class="text-muted" style="font-size:.65rem"><?= $k['sub'] ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header fw-bold small">Income vs Expenses (Last 12 Months)</div>
      <div class="card-body">
        <canvas id="incExpChart" height="100"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header fw-bold small">Expense Breakdown (<?= $selYear ?>)</div>
      <div class="card-body">
        <canvas id="catChart"></canvas>
        <?php if (empty($expByCategory)): ?>
        <div class="text-center text-muted small mt-3">No expense data yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Lower row: payroll summary + budget -->
<div class="row g-4">
  <!-- Payroll by month -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-bold small"><?= $selYear ?> Payroll Summary</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Month</th><th>Gross</th><th>Net</th><th>Status</th></tr></thead>
          <tbody>
            <?php if (empty($payrollRuns)): ?>
            <tr><td colspan="4" class="text-center text-muted py-4 small">No payroll runs for <?= $selYear ?>.</td></tr>
            <?php else: foreach ($payrollRuns as $pr):
              $sc = ['draft'=>'secondary','approved'=>'success','paid'=>'primary'];
            ?>
            <tr>
              <td class="small fw-semibold"><?= $monthNames[(int)$pr['period_month']] ?></td>
              <td class="small">KES <?= number_format((float)$pr['total_gross']) ?></td>
              <td class="small fw-semibold text-success">KES <?= number_format((float)$pr['total_net']) ?></td>
              <td><span class="badge bg-<?= $sc[$pr['status']] ?>"><?= ucfirst($pr['status']) ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Expense breakdown table -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold small">Top Expense Categories (<?= $selYear ?>)</span>
        <a href="expenses.php" class="btn btn-sm btn-outline-secondary">Manage Expenses</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Category</th><th>Amount</th><th>% of Total</th></tr></thead>
          <tbody>
            <?php if (empty($expByCategory)): ?>
            <tr><td colspan="3" class="text-center text-muted py-4 small">No expenses recorded.</td></tr>
            <?php else:
              $expTotal = array_sum(array_column($expByCategory,'total'));
              foreach ($expByCategory as $ec):
                $pct = $expTotal > 0 ? round($ec['total']/$expTotal*100,1) : 0;
            ?>
            <tr>
              <td class="small fw-semibold"><?= e($ec['category']) ?></td>
              <td class="small">KES <?= number_format((float)$ec['total']) ?></td>
              <td class="small">
                <div class="d-flex align-items-center gap-2">
                  <div class="progress flex-grow-1" style="height:6px">
                    <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                  </div>
                  <span><?= $pct ?>%</span>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Quick links -->
<div class="row g-3 mt-2">
  <?php foreach ([
    ['url'=>'fees.php',         'icon'=>'fas fa-money-bill-wave','label'=>'Manage Fees',     'c'=>'#1A8A4E'],
    ['url'=>'expenses.php',     'icon'=>'fas fa-receipt',        'label'=>'View Expenses',   'c'=>'#dc2626'],
    ['url'=>'payroll.php',      'icon'=>'fas fa-users',          'label'=>'Run Payroll',     'c'=>'#1d4ed8'],
    ['url'=>'budget.php',       'icon'=>'fas fa-chart-pie',      'label'=>'Budget',          'c'=>'#9333ea'],
    ['url'=>'fee-statement.php','icon'=>'fas fa-file-invoice',   'label'=>'Fee Statements',  'c'=>'#f59e0b'],
    ['url'=>'reports.php',      'icon'=>'fas fa-chart-bar',      'label'=>'Reports',         'c'=>'#0891b2'],
  ] as $ql): ?>
  <div class="col-6 col-md-2">
    <a href="<?= $ql['url'] ?>" class="card border-0 shadow-sm text-decoration-none h-100"
       style="color:inherit;transition:transform .15s" onmouseover="this.style.transform='translateY(-2px)'"
       onmouseout="this.style.transform=''">
      <div class="card-body text-center py-3">
        <i class="<?= $ql['icon'] ?> mb-2 d-block" style="color:<?= $ql['c'] ?>;font-size:1.4rem"></i>
        <div class="small fw-semibold"><?= $ql['label'] ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Income vs Expenses bar chart
new Chart(document.getElementById('incExpChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [
            {label:'Fee Income', data: <?= json_encode($chartFee) ?>, backgroundColor:'rgba(26,138,78,.7)', borderRadius:4},
            {label:'Expenses',   data: <?= json_encode($chartExp) ?>, backgroundColor:'rgba(220,38,38,.6)', borderRadius:4}
        ]
    },
    options: {
        responsive:true, interaction:{mode:'index'},
        plugins:{legend:{position:'top'}},
        scales:{y:{beginAtZero:true, ticks:{callback:v=>v>=1e6?'KES '+(v/1e6).toFixed(1)+'M':v>=1e3?'KES '+(v/1e3).toFixed(0)+'K':'KES '+v}}}
    }
});

// Category donut chart
<?php if (!empty($expByCategory)): ?>
new Chart(document.getElementById('catChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($expByCategory,'category')) ?>,
        datasets:[{
            data: <?= json_encode(array_map(fn($r)=>round((float)$r['total'],2),$expByCategory)) ?>,
            backgroundColor:['#1A8A4E','#1d4ed8','#dc2626','#f59e0b','#9333ea','#0891b2','#ea580c','#be185d'],
            borderWidth:2
        }]
    },
    options:{responsive:true, plugins:{legend:{position:'bottom',labels:{font:{size:10},boxWidth:12}}}}
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
