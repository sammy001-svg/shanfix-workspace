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
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',        'label' => 'Reports'],];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fFrom = $_GET['from'] ?? date('Y-01-01');
$fTo   = $_GET['to']   ?? date('Y-m-d');

// Validate dates
if (!strtotime($fFrom)) $fFrom = date('Y-01-01');
if (!strtotime($fTo))   $fTo   = date('Y-m-d');

// KPI totals
$totalIncome = 0; $totalExpense = 0;
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='income' AND date BETWEEN ? AND ?");
    $s->execute([$orgId, $fFrom, $fTo]); $totalIncome = (float)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='expense' AND date BETWEEN ? AND ?");
    $s->execute([$orgId, $fFrom, $fTo]); $totalExpense = (float)$s->fetchColumn();
} catch (Exception $e) {}

$netProfit   = $totalIncome - $totalExpense;
$savingsRate = $totalIncome > 0 ? round($netProfit / $totalIncome * 100, 1) : 0;

// Monthly chart (last 6 months)
$chartLabels = []; $incomeData = []; $expenseData = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $chartLabels[] = date('M Y', strtotime("-$i months"));
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='income' AND DATE_FORMAT(date,'%Y-%m')=?");
        $s->execute([$orgId, $month]); $incomeData[] = (float)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='expense' AND DATE_FORMAT(date,'%Y-%m')=?");
        $s->execute([$orgId, $month]); $expenseData[] = (float)$s->fetchColumn();
    } catch (Exception $e) { $incomeData[] = 0; $expenseData[] = 0; }
}

// Expense by category (doughnut)
$expByCat = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.name, c.color, COALESCE(SUM(t.amount),0) AS total
        FROM fin_transactions t
        LEFT JOIN fin_categories c ON t.category_id = c.id
        WHERE t.org_id=? AND t.type='expense' AND t.date BETWEEN ? AND ?
        GROUP BY t.category_id, c.name, c.color
        ORDER BY total DESC
        LIMIT 10
    ");
    $stmt->execute([$orgId, $fFrom, $fTo]);
    $expByCat = $stmt->fetchAll();
} catch (Exception $e) {}

// Income statement: income categories
$incomeByCategory = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.name, COALESCE(SUM(t.amount),0) AS total
        FROM fin_transactions t
        LEFT JOIN fin_categories c ON t.category_id = c.id
        WHERE t.org_id=? AND t.type='income' AND t.date BETWEEN ? AND ?
        GROUP BY t.category_id, c.name
        ORDER BY total DESC
    ");
    $stmt->execute([$orgId, $fFrom, $fTo]);
    $incomeByCategory = $stmt->fetchAll();
} catch (Exception $e) {}

// Expense statement: expense categories
$expenseByCategory = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.name, COALESCE(SUM(t.amount),0) AS total
        FROM fin_transactions t
        LEFT JOIN fin_categories c ON t.category_id = c.id
        WHERE t.org_id=? AND t.type='expense' AND t.date BETWEEN ? AND ?
        GROUP BY t.category_id, c.name
        ORDER BY total DESC
    ");
    $stmt->execute([$orgId, $fFrom, $fTo]);
    $expenseByCategory = $stmt->fetchAll();
} catch (Exception $e) {}

$doughnutLabels = array_column($expByCat, 'name');
$doughnutData   = array_column($expByCat, 'total');
$doughnutColors = array_map(fn($c) => $c['color'] ?: '#'.(dechex(rand(0x444444,0xaaaaaa))), $expByCat);
// Fill missing colors
foreach ($doughnutColors as &$dc) { if (!$dc || $dc === '#') $dc = '#' . substr(md5(rand()), 0, 6); }
unset($dc);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Financial Reports</h4>
    <p class="text-muted mb-0">Comprehensive financial performance overview</p>
  </div>
  <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print</button>
</div>

<!-- Date Range Filter -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fFrom) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($fTo) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-filter me-1"></i>Apply</button>
        <a href="reports.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
      <div class="col-auto ms-auto">
        <span class="text-muted small">Period: <strong><?= formatDate($fFrom) ?></strong> → <strong><?= formatDate($fTo) ?></strong></span>
      </div>
    </form>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-arrow-up"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalIncome) ?></div><div class="stat-label">Total Income</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-arrow-down"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalExpense) ?></div><div class="stat-label">Total Expenses</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $netProfit >= 0 ? 'green-bg' : 'danger-bg' ?>"><i class="fas fa-chart-line"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency(abs($netProfit)) ?></div>
        <div class="stat-label">Net <?= $netProfit >= 0 ? 'Profit' : 'Loss' ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-percentage"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $savingsRate ?>%</div><div class="stat-label">Savings Rate</div></div>
    </div>
  </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Income vs Expenses (Last 6 Months)</h6></div>
      <div class="card-body"><canvas id="barChart" height="120"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Expenses by Category</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <?php if (empty($expByCat)): ?>
        <div class="text-center text-muted"><i class="fas fa-chart-pie fa-3x mb-2 opacity-25"></i><br>No expense data</div>
        <?php else: ?>
        <canvas id="doughnutChart"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Income Statement -->
<div class="row g-3">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header" style="background:#27ae6015;border-bottom:2px solid #27ae60">
        <h6 class="mb-0 text-success"><i class="fas fa-arrow-up me-2"></i>Income Breakdown</h6>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Category</th><th class="text-end">Amount</th><th class="text-end">%</th></tr></thead>
          <tbody>
            <?php if (empty($incomeByCategory)): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">No income data</td></tr>
            <?php else: foreach ($incomeByCategory as $row): ?>
            <tr>
              <td><?= e($row['name'] ?? 'Uncategorized') ?></td>
              <td class="text-end text-success fw-semibold"><?= formatCurrency((float)$row['total']) ?></td>
              <td class="text-end text-muted small"><?= $totalIncome > 0 ? round($row['total']/$totalIncome*100,1) : 0 ?>%</td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <tfoot class="table-success">
            <tr><th>Total Income</th><th class="text-end"><?= formatCurrency($totalIncome) ?></th><th class="text-end">100%</th></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card">
      <div class="card-header" style="background:#e74c3c15;border-bottom:2px solid #e74c3c">
        <h6 class="mb-0 text-danger"><i class="fas fa-arrow-down me-2"></i>Expense Breakdown</h6>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Category</th><th class="text-end">Amount</th><th class="text-end">%</th></tr></thead>
          <tbody>
            <?php if (empty($expenseByCategory)): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">No expense data</td></tr>
            <?php else: foreach ($expenseByCategory as $row): ?>
            <tr>
              <td><?= e($row['name'] ?? 'Uncategorized') ?></td>
              <td class="text-end text-danger fw-semibold"><?= formatCurrency((float)$row['total']) ?></td>
              <td class="text-end text-muted small"><?= $totalExpense > 0 ? round($row['total']/$totalExpense*100,1) : 0 ?>%</td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <tfoot class="table-danger">
            <tr><th>Total Expenses</th><th class="text-end"><?= formatCurrency($totalExpense) ?></th><th class="text-end">100%</th></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Net Summary Row -->
<div class="card mt-3">
  <div class="card-body">
    <div class="row text-center">
      <div class="col-md-4">
        <div class="text-muted small">Total Income</div>
        <div class="fs-5 fw-bold text-success"><?= formatCurrency($totalIncome) ?></div>
      </div>
      <div class="col-md-4">
        <div class="text-muted small">Total Expenses</div>
        <div class="fs-5 fw-bold text-danger"><?= formatCurrency($totalExpense) ?></div>
      </div>
      <div class="col-md-4">
        <div class="text-muted small">Net <?= $netProfit >= 0 ? 'Profit' : 'Loss' ?></div>
        <div class="fs-5 fw-bold <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatCurrency(abs($netProfit)) ?></div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
(function(){
  new Chart(document.getElementById("barChart"),{
    type:"bar",
    data:{
      labels:' . json_encode($chartLabels) . ',
      datasets:[
        {label:"Income",data:' . json_encode($incomeData) . ',backgroundColor:"#16a085",borderRadius:5},
        {label:"Expenses",data:' . json_encode($expenseData) . ',backgroundColor:"#e74c3c",borderRadius:5}
      ]
    },
    options:{responsive:true,plugins:{legend:{position:"top"}},scales:{y:{beginAtZero:true}}}
  });
' . (!empty($expByCat) ? '
  new Chart(document.getElementById("doughnutChart"),{
    type:"doughnut",
    data:{
      labels:' . json_encode($doughnutLabels) . ',
      datasets:[{data:' . json_encode($doughnutData) . ',backgroundColor:' . json_encode($doughnutColors) . ',borderWidth:2}]
    },
    options:{responsive:true,plugins:{legend:{position:"bottom"}}}
  });
' : '') . '
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
