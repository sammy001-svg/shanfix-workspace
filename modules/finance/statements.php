<?php
// ── Finance: Financial Statements (P&L + Balance Sheet) ────────
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
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Date range filter
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$view     = $_GET['view']      ?? 'pnl'; // pnl | balance

// ── Profit & Loss ────────────────────────────────────────────────
$incomeRows   = [];
$expenseRows  = [];
$totalIncome  = 0;
$totalExpense = 0;

try {
    // Income by category
    $stmt = $pdo->prepare("
        SELECT COALESCE(c.name, 'Uncategorised') AS cat_name,
               COALESCE(SUM(t.amount), 0) AS total
        FROM fin_transactions t
        LEFT JOIN fin_categories c ON c.id = t.category_id
        WHERE t.org_id=? AND t.type='income'
          AND t.transaction_date BETWEEN ? AND ?
        GROUP BY c.id, c.name
        ORDER BY total DESC
    ");
    $stmt->execute([$orgId, $dateFrom, $dateTo]);
    $incomeRows  = $stmt->fetchAll();
    $totalIncome = array_sum(array_column($incomeRows, 'total'));
} catch (Exception $e) {}

try {
    // Expense by category
    $stmt = $pdo->prepare("
        SELECT COALESCE(c.name, 'Uncategorised') AS cat_name,
               COALESCE(SUM(t.amount), 0) AS total
        FROM fin_transactions t
        LEFT JOIN fin_categories c ON c.id = t.category_id
        WHERE t.org_id=? AND t.type='expense'
          AND t.transaction_date BETWEEN ? AND ?
        GROUP BY c.id, c.name
        ORDER BY total DESC
    ");
    $stmt->execute([$orgId, $dateFrom, $dateTo]);
    $expenseRows  = $stmt->fetchAll();
    $totalExpense = array_sum(array_column($expenseRows, 'total'));
} catch (Exception $e) {}

$netProfit = $totalIncome - $totalExpense;

// ── Balance Sheet ────────────────────────────────────────────────
$balanceRows = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.account_type,
               a.account_name,
               a.account_code,
               COALESCE(SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END), 0) AS balance
        FROM fin_accounts a
        LEFT JOIN fin_transactions t ON t.account_id = a.id AND t.org_id = a.org_id
          AND t.transaction_date <= ?
        WHERE a.org_id = ?
        GROUP BY a.id, a.account_type, a.account_name, a.account_code
        ORDER BY a.account_type, a.account_code
    ");
    $stmt->execute([$dateTo, $orgId]);
    $balanceRows = $stmt->fetchAll();
} catch (Exception $e) {}

// Group by type
$grouped = [];
foreach ($balanceRows as $r) {
    $grouped[$r['account_type']][] = $r;
}

$typeOrder = ['asset', 'bank', 'liability', 'equity', 'income', 'expense'];
?>

<style>
@media print {
  .module-sidebar, .module-topnav, .page-header .btn, .filter-bar, footer { display:none !important; }
  .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
}
</style>

<div class="page-header d-flex align-items-center justify-content-between mb-4 filter-bar">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-alt me-2" style="color:<?= $moduleColor ?>"></i>Financial Statements</h4>
    <p class="text-muted mb-0">Profit & Loss and Balance Sheet for your organisation</p>
  </div>
  <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
</div>

<!-- Filter bar -->
<div class="card border-0 shadow-sm mb-4 filter-bar">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label form-label-sm mb-1">View</label>
        <select name="view" class="form-select form-select-sm">
          <option value="pnl"     <?= $view==='pnl'     ? 'selected' : '' ?>>Profit & Loss</option>
          <option value="balance" <?= $view==='balance' ? 'selected' : '' ?>>Balance Sheet</option>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label form-label-sm mb-1">From</label>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label form-label-sm mb-1">To</label>
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-filter me-1"></i>Apply</button>
      </div>
    </form>
  </div>
</div>

<?php if ($view === 'pnl'): ?>
<!-- ── Profit & Loss Statement ─────────────────────────────── -->
<div class="row mb-4 g-3">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-arrow-down"></i></div>
      <div class="stat-body"><div class="stat-value text-success"><?= formatCurrency($totalIncome) ?></div><div class="stat-label">Total Income</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-arrow-up"></i></div>
      <div class="stat-body"><div class="stat-value text-danger"><?= formatCurrency($totalExpense) ?></div><div class="stat-label">Total Expenses</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon <?= $netProfit >= 0 ? 'green-bg' : 'danger-bg' ?>"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body"><div class="stat-value <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatCurrency(abs($netProfit)) ?></div><div class="stat-label"><?= $netProfit >= 0 ? 'Net Profit' : 'Net Loss' ?></div></div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Income -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-success"><i class="fas fa-arrow-down me-2"></i>Income Breakdown</h6>
        <div class="small text-muted"><?= formatDate($dateFrom) ?> – <?= formatDate($dateTo) ?></div>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th class="ps-3">Category</th><th class="text-end pe-3">Amount</th><th class="text-end pe-3">%</th></tr>
          </thead>
          <tbody>
            <?php if (empty($incomeRows)): ?>
            <tr><td colspan="3" class="text-center text-muted py-4">No income in this period.</td></tr>
            <?php else: foreach ($incomeRows as $ir): ?>
            <tr>
              <td class="ps-3"><?= e($ir['cat_name']) ?></td>
              <td class="text-end pe-3 fw-semibold text-success"><?= formatCurrency((float)$ir['total']) ?></td>
              <td class="text-end pe-3 text-muted small"><?= $totalIncome > 0 ? number_format((float)$ir['total'] / $totalIncome * 100, 1) : '0.0' ?>%</td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <tfoot class="table-light">
            <tr><th class="ps-3 fw-bold">TOTAL INCOME</th><th class="text-end pe-3 fw-bold text-success"><?= formatCurrency($totalIncome) ?></th><th class="text-end pe-3">100%</th></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- Expenses -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-danger"><i class="fas fa-arrow-up me-2"></i>Expense Breakdown</h6>
        <div class="small text-muted"><?= formatDate($dateFrom) ?> – <?= formatDate($dateTo) ?></div>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th class="ps-3">Category</th><th class="text-end pe-3">Amount</th><th class="text-end pe-3">%</th></tr>
          </thead>
          <tbody>
            <?php if (empty($expenseRows)): ?>
            <tr><td colspan="3" class="text-center text-muted py-4">No expenses in this period.</td></tr>
            <?php else: foreach ($expenseRows as $er): ?>
            <tr>
              <td class="ps-3"><?= e($er['cat_name']) ?></td>
              <td class="text-end pe-3 fw-semibold text-danger"><?= formatCurrency((float)$er['total']) ?></td>
              <td class="text-end pe-3 text-muted small"><?= $totalExpense > 0 ? number_format((float)$er['total'] / $totalExpense * 100, 1) : '0.0' ?>%</td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <tfoot class="table-light">
            <tr><th class="ps-3 fw-bold">TOTAL EXPENSES</th><th class="text-end pe-3 fw-bold text-danger"><?= formatCurrency($totalExpense) ?></th><th class="text-end pe-3">100%</th></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Net Summary -->
<div class="card border-0 shadow-sm mt-4">
  <div class="card-body">
    <div class="row text-center">
      <div class="col"><div class="text-muted small mb-1">Total Income</div><div class="fs-5 fw-bold text-success"><?= formatCurrency($totalIncome) ?></div></div>
      <div class="col d-flex align-items-center justify-content-center"><span class="fs-4 text-muted">−</span></div>
      <div class="col"><div class="text-muted small mb-1">Total Expenses</div><div class="fs-5 fw-bold text-danger"><?= formatCurrency($totalExpense) ?></div></div>
      <div class="col d-flex align-items-center justify-content-center"><span class="fs-4 text-muted">=</span></div>
      <div class="col">
        <div class="text-muted small mb-1"><?= $netProfit >= 0 ? 'Net Profit' : 'Net Loss' ?></div>
        <div class="fs-4 fw-bold <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatCurrency(abs($netProfit)) ?></div>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── Balance Sheet ──────────────────────────────────────────── -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white border-bottom py-3">
    <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-balance-scale me-2" style="color:<?= $moduleColor ?>"></i>Balance Sheet — as at <?= formatDate($dateTo) ?></h6>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th class="ps-3">Account</th><th>Code</th><th>Type</th><th class="text-end pe-3">Balance</th></tr>
      </thead>
      <tbody>
        <?php
        $sectionTotals = [];
        foreach ($typeOrder as $type):
            if (empty($grouped[$type])) continue;
            $secTotal = array_sum(array_column($grouped[$type], 'balance'));
            $sectionTotals[$type] = $secTotal;
            $typeLabel = ucfirst($type);
        ?>
        <tr class="table-secondary">
          <td colspan="4" class="ps-3 fw-bold text-uppercase small tracking-wide"><?= $typeLabel ?></td>
        </tr>
        <?php foreach ($grouped[$type] as $r): ?>
        <tr>
          <td class="ps-4"><?= e($r['account_name']) ?></td>
          <td class="text-muted small"><?= e($r['account_code']) ?></td>
          <td><span class="badge bg-light text-dark border"><?= ucfirst($type) ?></span></td>
          <td class="text-end pe-3 fw-semibold <?= (float)$r['balance'] >= 0 ? '' : 'text-danger' ?>">
            <?= formatCurrency(abs((float)$r['balance'])) ?>
            <?= (float)$r['balance'] < 0 ? ' <small class="text-danger">(Dr)</small>' : '' ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr class="table-light">
          <td colspan="3" class="ps-3 fw-semibold">Subtotal — <?= $typeLabel ?></td>
          <td class="text-end pe-3 fw-bold"><?= formatCurrency($secTotal) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php $extraJs = <<<'JS'
<script>
// No DataTable needed — statement is not tabular/filterable
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
