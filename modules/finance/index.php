<?php
$moduleSlug  = 'finance';
$moduleName  = 'Finance & Budgeting';
$moduleIcon  = 'fas fa-wallet';
$moduleColor = '#16a085';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'accounts.php',       'icon' => 'fas fa-university',     'label' => 'Accounts'],
    ['url' => 'transactions.php',   'icon' => 'fas fa-exchange-alt',   'label' => 'Transactions'],
    ['url' => 'categories.php',     'icon' => 'fas fa-tags',           'label' => 'Categories'],
    ['url' => 'budgets.php',        'icon' => 'fas fa-bullseye',       'label' => 'Budgets'],
    ['url' => 'journals.php',       'icon' => 'fas fa-book',           'label' => 'Journals'],
    ['url' => 'reconciliation.php', 'icon' => 'fas fa-check-double',   'label' => 'Reconciliation'],
    ['url' => 'statements.php',     'icon' => 'fas fa-file-alt',       'label' => 'Statements'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalBalance = 0;
$totalIncome  = 0;
$totalExpense = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM fin_accounts WHERE org_id=?");
    $stmt->execute([$orgId]);
    $totalBalance = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='income'");
    $stmt->execute([$orgId]);
    $totalIncome = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='expense'");
    $stmt->execute([$orgId]);
    $totalExpense = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$netAmount = $totalIncome - $totalExpense;

// Recent transactions
$transactions = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM fin_transactions WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$orgId]);
    $transactions = $stmt->fetchAll();
} catch (Exception $e) {}

// Monthly income vs expense (6 months)
$chartLabels  = [];
$incomeData   = [];
$expenseData  = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $chartLabels[] = date('M Y', strtotime("-$i months"));
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='income' AND DATE_FORMAT(created_at,'%Y-%m')=?");
        $stmt->execute([$orgId, $month]);
        $incomeData[] = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='expense' AND DATE_FORMAT(created_at,'%Y-%m')=?");
        $stmt->execute([$orgId, $month]);
        $expenseData[] = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        $incomeData[]  = 0;
        $expenseData[] = 0;
    }
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Track accounts, budgets, and financial transactions</p>
  </div>
  <a href="transactions.php?action=add" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-plus me-2"></i>New Transaction</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-university"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalBalance) ?></div><div class="stat-label">Total Balance</div></div>
    </div>
  </div>
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
      <div class="stat-icon <?= $netAmount >= 0 ? 'green-bg' : 'danger-bg' ?>"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency(abs($netAmount)) ?></div><div class="stat-label">Net <?= $netAmount >= 0 ? 'Surplus' : 'Deficit' ?></div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Income vs Expenses (6 months)</h6></div>
      <div class="card-body"><canvas id="finChart" height="80"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-exchange-alt me-2" style="color:<?= $moduleColor ?>"></i>Recent Transactions</h6>
    <a href="transactions.php" class="btn btn-sm" style="border-color:<?= $moduleColor ?>;color:<?= $moduleColor ?>">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="finTable">
        <thead class="table-light">
          <tr><th>Date</th><th>Description</th><th>Account</th><th>Category</th><th>Type</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>
          <?php if (empty($transactions)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No transactions found</td></tr>
          <?php else: foreach ($transactions as $t): ?>
          <tr>
            <td><?= formatDate($t['created_at'] ?? '') ?></td>
            <td><?= e($t['description'] ?? '—') ?></td>
            <td><?= e($t['account_name'] ?? '—') ?></td>
            <td><?= e($t['category'] ?? '—') ?></td>
            <td><?= statusBadge($t['type'] ?? 'income') ?></td>
            <td class="text-end fw-semibold <?= ($t['type'] ?? '') === 'income' ? 'text-success' : 'text-danger' ?>">
              <?= formatCurrency((float)($t['amount'] ?? 0)) ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
(function(){
  new Chart(document.getElementById("finChart"),{
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
  $("#finTable").DataTable({pageLength:10,order:[[0,"desc"]]});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
