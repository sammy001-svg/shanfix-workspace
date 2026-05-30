<?php
$moduleSlug  = 'finance';
$moduleName  = 'Finance & Budgeting';
$moduleIcon  = 'fas fa-wallet';
$moduleColor = '#16a085';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt',   'label' => 'Dashboard'],
    ['url' => 'income.php',         'icon' => 'fas fa-arrow-circle-down','label'=> 'Income'],
    ['url' => 'expenses.php',       'icon' => 'fas fa-arrow-circle-up',  'label'=> 'Expenses'],
    ['url' => 'accounts.php',       'icon' => 'fas fa-university',       'label' => 'Accounts'],
    ['url' => 'transactions.php',   'icon' => 'fas fa-exchange-alt',     'label' => 'All Transactions'],
    ['url' => 'categories.php',     'icon' => 'fas fa-tags',             'label' => 'Categories'],
    ['url' => 'budgets.php',        'icon' => 'fas fa-bullseye',         'label' => 'Budgets'],
    ['url' => 'journals.php',       'icon' => 'fas fa-book',             'label' => 'Journals'],
    ['url' => 'reconciliation.php', 'icon' => 'fas fa-check-double',     'label' => 'Reconciliation'],
    ['url' => 'statements.php',     'icon' => 'fas fa-file-alt',         'label' => 'Statements'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',        'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId    = (int)$user['org_id'];
$thisYear = date('Y');
$thisMo   = date('Y-m');

// ── KPI aggregates ─────────────────────────────────────────────
$totalBalance = 0; $totalIncome = 0; $totalExpense = 0;
$moIncome = 0; $moExpense = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM fin_accounts WHERE org_id=? AND status='active'");
    $stmt->execute([$orgId]); $totalBalance = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='income' AND YEAR(date)=?");
    $stmt->execute([$orgId, $thisYear]); $totalIncome = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='expense' AND YEAR(date)=?");
    $stmt->execute([$orgId, $thisYear]); $totalExpense = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='income' AND DATE_FORMAT(date,'%Y-%m')=?");
    $stmt->execute([$orgId, $thisMo]); $moIncome = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='expense' AND DATE_FORMAT(date,'%Y-%m')=?");
    $stmt->execute([$orgId, $thisMo]); $moExpense = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$netAnnual = $totalIncome - $totalExpense;
$moNet     = $moIncome - $moExpense;

// ── 6-month income vs expense + net cash flow ──────────────────
$chartLabels = []; $incomeData = []; $expenseData = []; $netData = [];
for ($i = 5; $i >= 0; $i--) {
    $mo  = date('Y-m', strtotime("-$i months"));
    $lbl = date('M', strtotime("-$i months"));
    $inc = 0; $exp = 0;
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='income' AND DATE_FORMAT(date,'%Y-%m')=?");
        $s->execute([$orgId, $mo]); $inc = (float)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='expense' AND DATE_FORMAT(date,'%Y-%m')=?");
        $s->execute([$orgId, $mo]); $exp = (float)$s->fetchColumn();
    } catch (Exception $e) {}
    $chartLabels[] = $lbl; $incomeData[] = $inc; $expenseData[] = $exp; $netData[] = $inc - $exp;
}

// ── Account balances ───────────────────────────────────────────
$accounts = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM fin_accounts WHERE org_id=? AND status='active' ORDER BY balance DESC LIMIT 6");
    $stmt->execute([$orgId]); $accounts = $stmt->fetchAll();
} catch (Exception $e) {}

// ── Budget utilization (current month) ────────────────────────
$budgets = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, c.name AS cat_name, c.color AS cat_color,
               COALESCE(SUM(t.amount),0) AS spent_actual
        FROM fin_budgets b
        LEFT JOIN fin_categories c ON b.category_id=c.id
        LEFT JOIN fin_transactions t ON t.category_id=b.category_id AND t.org_id=b.org_id
                  AND t.type='expense' AND DATE_FORMAT(t.date,'%Y-%m') = b.period
        WHERE b.org_id=? AND b.period=?
        GROUP BY b.id
        ORDER BY (COALESCE(SUM(t.amount),0)/NULLIF(b.amount,0)) DESC
        LIMIT 6
    ");
    $stmt->execute([$orgId, $thisMo]); $budgets = $stmt->fetchAll();
} catch (Exception $e) {}

// ── Recent transactions ────────────────────────────────────────
$recentTx = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, a.name AS account_name, c.name AS category_name
        FROM fin_transactions t
        LEFT JOIN fin_accounts a ON t.account_id=a.id
        LEFT JOIN fin_categories c ON t.category_id=c.id
        WHERE t.org_id=? ORDER BY t.date DESC, t.id DESC LIMIT 8
    ");
    $stmt->execute([$orgId]); $recentTx = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Financial overview for <?= date('Y') ?></p>
  </div>
  <div class="d-flex gap-2">
    <a href="income.php" class="btn btn-success btn-sm"><i class="fas fa-plus me-1"></i>Record Income</a>
    <a href="expenses.php" class="btn btn-danger btn-sm"><i class="fas fa-plus me-1"></i>Record Expense</a>
    <a href="transactions.php" class="btn btn-sm" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-exchange-alt me-1"></i>All Transactions</a>
  </div>
</div>

<!-- Annual KPIs -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-university"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalBalance) ?></div><div class="stat-label">Total Balance (All Accounts)</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-arrow-circle-down"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalIncome) ?></div><div class="stat-label">Income <?= $thisYear ?></div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-arrow-circle-up"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalExpense) ?></div><div class="stat-label">Expenses <?= $thisYear ?></div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $netAnnual >= 0 ? 'green-bg' : 'danger-bg' ?>"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body">
        <div class="stat-value <?= $netAnnual < 0 ? 'text-danger' : '' ?>"><?= formatCurrency(abs($netAnnual)) ?></div>
        <div class="stat-label">Net <?= $netAnnual >= 0 ? 'Surplus' : 'Deficit' ?> <?= $thisYear ?></div>
      </div>
    </div>
  </div>
</div>

<!-- This month snapshot -->
<div class="card mb-4" style="border-left:4px solid <?= $moduleColor ?>">
  <div class="card-body py-3">
    <div class="row align-items-center g-3">
      <div class="col-auto">
        <div class="fw-bold" style="color:<?= $moduleColor ?>"><?= date('F Y') ?> Snapshot</div>
        <div class="text-muted small">Month-to-date performance</div>
      </div>
      <div class="col-sm-3">
        <div class="small text-muted">Income</div>
        <div class="fw-bold text-success fs-6"><?= formatCurrency($moIncome) ?></div>
      </div>
      <div class="col-sm-3">
        <div class="small text-muted">Expenses</div>
        <div class="fw-bold text-danger fs-6"><?= formatCurrency($moExpense) ?></div>
      </div>
      <div class="col-sm-3">
        <div class="small text-muted">Net Cash Flow</div>
        <div class="fw-bold <?= $moNet >= 0 ? 'text-success' : 'text-danger' ?> fs-6">
          <?= ($moNet >= 0 ? '+' : '−') . formatCurrency(abs($moNet)) ?>
        </div>
      </div>
      <?php if ($moIncome > 0): $savRate = round((($moIncome-$moExpense)/$moIncome)*100); ?>
      <div class="col-sm-3">
        <div class="small text-muted">Savings Rate</div>
        <div class="fw-bold <?= $savRate >= 0 ? 'text-success' : 'text-danger' ?> fs-6"><?= $savRate ?>%</div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Income vs Expense Chart -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Income vs Expenses (6 months)</h6>
        <div class="d-flex gap-2">
          <span class="badge" style="background:#16a085">Income</span>
          <span class="badge bg-danger">Expenses</span>
          <span class="badge bg-dark">Net</span>
        </div>
      </div>
      <div class="card-body"><canvas id="finChart" height="100"></canvas></div>
    </div>
  </div>
  <!-- Accounts -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-university me-2" style="color:<?= $moduleColor ?>"></i>Account Balances</h6>
        <a href="accounts.php" class="btn btn-sm btn-outline-secondary">Manage</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($accounts)): ?>
          <div class="text-center text-muted py-4 small">No accounts set up yet.<br><a href="accounts.php">+ Add Account</a></div>
        <?php else: foreach ($accounts as $acc):
          $typeIcons = ['bank'=>'fa-landmark','cash'=>'fa-money-bill-wave','mobile_money'=>'fa-mobile-alt','investment'=>'fa-chart-line'];
          $icon = $typeIcons[$acc['type'] ?? 'bank'] ?? 'fa-university';
        ?>
          <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
            <div class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0"
                 style="width:34px;height:34px;background:<?= $moduleColor ?>1a;color:<?= $moduleColor ?>">
              <i class="fas <?= $icon ?> small"></i>
            </div>
            <div class="flex-fill">
              <div class="small fw-semibold"><?= e($acc['name']) ?></div>
              <div class="smaller text-muted" style="font-size:.72rem"><?= ucfirst($acc['type'] ?? '') ?></div>
            </div>
            <div class="fw-bold small <?= (float)$acc['balance'] < 0 ? 'text-danger' : 'text-success' ?>">
              <?= formatCurrency((float)$acc['balance']) ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Budget Utilization -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-bullseye me-2" style="color:<?= $moduleColor ?>"></i>Budget vs Actual (<?= date('M Y') ?>)</h6>
        <a href="budgets.php" class="btn btn-sm btn-outline-secondary">Budgets</a>
      </div>
      <div class="card-body">
        <?php if (empty($budgets)): ?>
          <div class="text-center text-muted small py-3">No budgets set for <?= date('M Y') ?>.<br><a href="budgets.php">+ Set Budget</a></div>
        <?php else: foreach ($budgets as $b):
          $budgeted = (float)$b['amount'];
          $spent    = (float)$b['spent_actual'];
          $pct      = $budgeted > 0 ? min(100, round($spent/$budgeted*100)) : 0;
          $barColor = $pct >= 90 ? '#e74c3c' : ($pct >= 70 ? '#f39c12' : $moduleColor);
        ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between mb-1">
              <span class="small fw-semibold"><?= e($b['cat_name'] ?? 'General') ?></span>
              <span class="small text-muted"><?= formatCurrency($spent) ?> / <?= formatCurrency($budgeted) ?> (<?= $pct ?>%)</span>
            </div>
            <div class="progress" style="height:8px;border-radius:4px">
              <div class="progress-bar" role="progressbar" style="width:<?= $pct ?>%;background:<?= $barColor ?>;border-radius:4px"></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Transactions -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-exchange-alt me-2" style="color:<?= $moduleColor ?>"></i>Recent Transactions</h6>
        <a href="transactions.php" class="btn btn-sm" style="border-color:<?= $moduleColor ?>;color:<?= $moduleColor ?>">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
              <tr><th>Date</th><th>Description</th><th>Account</th><th>Type</th><th class="text-end">Amount</th></tr>
            </thead>
            <tbody>
              <?php if (empty($recentTx)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No transactions yet</td></tr>
              <?php else: foreach ($recentTx as $t):
                $isIncome   = ($t['type'] ?? '') === 'income';
                $isTransfer = ($t['type'] ?? '') === 'transfer';
              ?>
              <tr>
                <td class="small"><?= formatDate($t['date'] ?? '') ?></td>
                <td class="small fw-semibold"><?= e(mb_substr($t['description'] ?? '—', 0, 35)) ?></td>
                <td class="small text-muted"><?= e($t['account_name'] ?? '—') ?></td>
                <td>
                  <?php if ($isTransfer): ?>
                    <span class="badge bg-info text-dark">Transfer</span>
                  <?php elseif ($isIncome): ?>
                    <span class="badge bg-success">Income</span>
                  <?php else: ?>
                    <span class="badge bg-danger">Expense</span>
                  <?php endif; ?>
                </td>
                <td class="text-end small fw-bold <?= $isIncome ? 'text-success' : ($isTransfer ? 'text-info' : 'text-danger') ?>">
                  <?= ($isIncome ? '+' : '−') . formatCurrency((float)($t['amount'] ?? 0)) ?>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
(function(){
  const labels   = ' . json_encode($chartLabels) . ';
  const incData  = ' . json_encode($incomeData)  . ';
  const expData  = ' . json_encode($expenseData) . ';
  const netData  = ' . json_encode($netData)     . ';
  new Chart(document.getElementById("finChart"), {
    data: {
      labels: labels,
      datasets: [
        { type:"bar",  label:"Income",   data:incData, backgroundColor:"#16a08599", borderColor:"#16a085", borderWidth:2, borderRadius:5, yAxisID:"y" },
        { type:"bar",  label:"Expenses", data:expData, backgroundColor:"#e74c3c99", borderColor:"#e74c3c", borderWidth:2, borderRadius:5, yAxisID:"y" },
        { type:"line", label:"Net CF",   data:netData, borderColor:"#2c3e50",       backgroundColor:"transparent", borderWidth:2, pointRadius:4, tension:.35, yAxisID:"y" }
      ]
    },
    options:{
      responsive:true,
      plugins:{legend:{position:"top"}},
      scales:{y:{beginAtZero:true, ticks:{callback:v => "KES " + v.toLocaleString("en-KE")}}}
    }
  });
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
