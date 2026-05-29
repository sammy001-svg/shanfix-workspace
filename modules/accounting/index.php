<?php
$moduleSlug  = 'accounting';
$moduleName  = 'Accounting & Bookkeeping';
$moduleIcon  = 'fas fa-calculator';
$moduleColor = '#1A8A4E';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'accounts.php',     'icon' => 'fas fa-list',            'label' => 'Chart of Accounts'],
    ['url' => 'transactions.php', 'icon' => 'fas fa-exchange-alt',    'label' => 'Journal Entries'],
    ['url' => 'invoices.php',     'icon' => 'fas fa-file-invoice',    'label' => 'Invoices'],
    ['url' => 'payments.php',     'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'expenses.php',     'icon' => 'fas fa-receipt',         'label' => 'Expenses'],
    ['url' => 'bills.php',        'icon' => 'fas fa-file-import',     'label' => 'Vendor Bills'],
    ['url' => 'budgets.php',      'icon' => 'fas fa-bullseye',        'label' => 'Budgets'],
    ['url' => 'taxes.php',        'icon' => 'fas fa-percentage',      'label' => 'Tax Rates'],
    ['url' => 'assets.php',        'icon' => 'fas fa-building',        'label' => 'Fixed Assets'],
    ['url' => 'payroll-journal.php','icon'=> 'fas fa-file-alt',        'label' => 'Payroll Journal'],
    ['url' => 'audit.php',         'icon' => 'fas fa-history',         'label' => 'Audit Trail'],
    ['url' => 'reports.php',       'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

// Stats
$totalRevenue  = 0;
$totalExpenses = 0;
$pendingInv    = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM acc_transactions WHERE org_id=? AND type='credit'");
    $stmt->execute([$orgId]);
    $totalRevenue = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM acc_transactions WHERE org_id=? AND type='debit'");
    $stmt->execute([$orgId]);
    $totalExpenses = (float)$stmt->fetchColumn();

    $pendingInv = countRows('acc_invoices', 'org_id = ? AND status = ?', [$orgId, 'pending']);
} catch (Exception $e) {}

$netProfit = $totalRevenue - $totalExpenses;

// Recent transactions
$transactions = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM acc_transactions WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$orgId]);
    $transactions = $stmt->fetchAll();
} catch (Exception $e) {}

// Monthly revenue vs expenses (last 6 months)
$chartLabels   = [];
$chartRevenue  = [];
$chartExpenses = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $label = date('M Y', strtotime("-$i months"));
    $chartLabels[] = $label;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM acc_transactions WHERE org_id=? AND type='credit' AND DATE_FORMAT(created_at,'%Y-%m')=?");
        $stmt->execute([$orgId, $month]);
        $chartRevenue[] = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM acc_transactions WHERE org_id=? AND type='debit' AND DATE_FORMAT(created_at,'%Y-%m')=?");
        $stmt->execute([$orgId, $month]);
        $chartExpenses[] = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        $chartRevenue[]  = 0;
        $chartExpenses[] = 0;
    }
}

// Expense by category
$expCats   = [];
$expAmts   = [];
try {
    $stmt = $pdo->prepare("SELECT category, COALESCE(SUM(amount),0) as total FROM acc_expenses WHERE org_id=? GROUP BY category ORDER BY total DESC LIMIT 6");
    $stmt->execute([$orgId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $expCats[] = $r['category'];
        $expAmts[] = (float)$r['total'];
    }
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Financial overview and quick access</p>
  </div>
  <a href="invoices.php?action=add" class="btn btn-success"><i class="fas fa-plus me-2"></i>New Invoice</a>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-arrow-up"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalRevenue) ?></div>
        <div class="stat-label">Total Revenue</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-arrow-down"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalExpenses) ?></div>
        <div class="stat-label">Total Expenses</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $netProfit >= 0 ? 'navy-bg' : 'danger-bg' ?>"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency(abs($netProfit)) ?></div>
        <div class="stat-label">Net <?= $netProfit >= 0 ? 'Profit' : 'Loss' ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $pendingInv ?></div>
        <div class="stat-label">Pending Invoices</div>
      </div>
    </div>
  </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-chart-line me-2 text-success"></i>Revenue vs Expenses (6 months)</h6>
      </div>
      <div class="card-body">
        <canvas id="revenueChart" height="100"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="mb-0"><i class="fas fa-chart-pie me-2 text-success"></i>Expenses by Category</h6>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <canvas id="expenseChart" height="220"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- Recent Transactions -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-exchange-alt me-2 text-success"></i>Recent Transactions</h6>
    <a href="transactions.php" class="btn btn-sm btn-outline-success">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="transTable">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Description</th>
            <th>Account</th>
            <th>Type</th>
            <th class="text-end">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($transactions)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No transactions found</td></tr>
          <?php else: foreach ($transactions as $t): ?>
          <tr>
            <td><?= formatDate($t['created_at'] ?? '') ?></td>
            <td><?= e($t['description'] ?? '—') ?></td>
            <td><?= e($t['account_name'] ?? '—') ?></td>
            <td><?= statusBadge($t['type'] ?? 'credit') ?></td>
            <td class="text-end fw-semibold <?= ($t['type'] ?? '') === 'credit' ? 'text-success' : 'text-danger' ?>">
              <?= ($t['type'] ?? '') === 'credit' ? '+' : '-' ?><?= formatCurrency((float)($t['amount'] ?? 0)) ?>
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
  var labels  = ' . json_encode($chartLabels) . ';
  var revenue = ' . json_encode($chartRevenue) . ';
  var expense = ' . json_encode($chartExpenses) . ';
  new Chart(document.getElementById("revenueChart"), {
    type:"line",
    data:{
      labels:labels,
      datasets:[
        {label:"Revenue",data:revenue,borderColor:"#1A8A4E",backgroundColor:"rgba(26,138,78,.1)",tension:.4,fill:true},
        {label:"Expenses",data:expense,borderColor:"#e74c3c",backgroundColor:"rgba(231,76,60,.1)",tension:.4,fill:true}
      ]
    },
    options:{responsive:true,plugins:{legend:{position:"top"}},scales:{y:{beginAtZero:true}}}
  });

  var cats = ' . json_encode($expCats) . ';
  var amts = ' . json_encode($expAmts) . ';
  if(cats.length){
    new Chart(document.getElementById("expenseChart"), {
      type:"doughnut",
      data:{labels:cats,datasets:[{data:amts,backgroundColor:["#1A8A4E","#0B2D4E","#e74c3c","#f39c12","#8e44ad","#2980b9"]}]},
      options:{responsive:true,plugins:{legend:{position:"bottom"}}}
    });
  }

  $("#transTable").DataTable({pageLength:10,order:[[0,"desc"]],language:{emptyTable:"No transactions found"}});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
