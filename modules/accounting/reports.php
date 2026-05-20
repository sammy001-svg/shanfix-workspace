<?php
// ── Accounting: Financial Reports (5 tabs) ────────────────────
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
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Common date parameters ─────────────────────────────────────
$activeTab  = $_GET['tab']  ?? 'income';
$filterFrom = $_GET['from'] ?? date('Y-01-01');
$filterTo   = $_GET['to']   ?? date('Y-m-d');
$asOfDate   = $_GET['as_of'] ?? date('Y-m-d');
$glAccount  = (int)($_GET['account_id'] ?? 0);

// ── TAB 1: Income Statement ────────────────────────────────────
$totalRevenue  = 0; $revenueRows  = [];
$totalExpenses = 0; $expenseRows  = [];
try {
    $stmt = $pdo->prepare("
        SELECT customer_name, SUM(total) AS total, COUNT(*) AS cnt
        FROM acc_invoices
        WHERE org_id=? AND issue_date BETWEEN ? AND ? AND status IN ('paid','sent')
        GROUP BY customer_name ORDER BY total DESC
    ");
    $stmt->execute([$orgId, $filterFrom, $filterTo]);
    $revenueRows  = $stmt->fetchAll();
    $totalRevenue = array_sum(array_column($revenueRows, 'total'));

    $stmt = $pdo->prepare("
        SELECT category, SUM(amount) AS total, COUNT(*) AS cnt
        FROM acc_expenses WHERE org_id=? AND date BETWEEN ? AND ?
        GROUP BY category ORDER BY total DESC
    ");
    $stmt->execute([$orgId, $filterFrom, $filterTo]);
    $expenseRows   = $stmt->fetchAll();
    $totalExpenses = array_sum(array_column($expenseRows, 'total'));
} catch (Exception $e) {}
$netProfit = $totalRevenue - $totalExpenses;

// ── TAB 2: Balance Sheet (current balances from chart of accounts) ──
$bsAssets = $bsLiabilities = $bsEquity = [];
$totalAssets = $totalLiabilities = $totalEquity = 0;
try {
    $stmt = $pdo->prepare("SELECT code, name, balance, parent_id FROM acc_accounts WHERE org_id=? AND type='asset'  AND status='active' ORDER BY code, name");
    $stmt->execute([$orgId]); $bsAssets = $stmt->fetchAll();
    $totalAssets = array_sum(array_column($bsAssets, 'balance'));

    $stmt = $pdo->prepare("SELECT code, name, balance, parent_id FROM acc_accounts WHERE org_id=? AND type='liability' AND status='active' ORDER BY code, name");
    $stmt->execute([$orgId]); $bsLiabilities = $stmt->fetchAll();
    $totalLiabilities = array_sum(array_column($bsLiabilities, 'balance'));

    $stmt = $pdo->prepare("SELECT code, name, balance, parent_id FROM acc_accounts WHERE org_id=? AND type='equity' AND status='active' ORDER BY code, name");
    $stmt->execute([$orgId]); $bsEquity = $stmt->fetchAll();
    $totalEquity = array_sum(array_column($bsEquity, 'balance'));
} catch (Exception $e) {}
// Retained earnings = equity + net P&L
$totalLiabEquity = $totalLiabilities + $totalEquity + $netProfit;

// ── TAB 3: Trial Balance ───────────────────────────────────────
$trialBalance = [];
$tbTotalDebit = $tbTotalCredit = 0;
try {
    $stmt = $pdo->prepare("SELECT code, name, type, balance FROM acc_accounts WHERE org_id=? AND status='active' ORDER BY type, code, name");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $acc) {
        $bal = (float)$acc['balance'];
        // Normal debit accounts: asset, expense
        $normalDebit = in_array($acc['type'], ['asset','expense']);
        $debit  = ($normalDebit && $bal > 0) || (!$normalDebit && $bal < 0) ? abs($bal) : 0;
        $credit = (!$normalDebit && $bal > 0) || ($normalDebit && $bal < 0) ? abs($bal) : 0;
        $tbTotalDebit  += $debit;
        $tbTotalCredit += $credit;
        $trialBalance[] = array_merge($acc, ['tb_debit' => $debit, 'tb_credit' => $credit]);
    }
} catch (Exception $e) {}

// ── TAB 4: General Ledger ──────────────────────────────────────
$glAccounts  = [];
$glEntries   = [];
$glOpenBal   = 0;
try {
    $stmt = $pdo->prepare("SELECT id, code, name, type FROM acc_accounts WHERE org_id=? AND status='active' ORDER BY type, code, name");
    $stmt->execute([$orgId]);
    $glAccounts = $stmt->fetchAll();
} catch (Exception $e) {}

if ($glAccount > 0) {
    try {
        // Opening balance (transactions before filterFrom)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ti.debit),0) - COALESCE(SUM(ti.credit),0)
            FROM acc_transaction_items ti
            JOIN acc_transactions t ON ti.transaction_id = t.id
            WHERE t.org_id=? AND ti.account_id=? AND t.date < ? AND t.status='posted'
        ");
        $stmt->execute([$orgId, $glAccount, $filterFrom]);
        $glOpenBal = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT t.date, t.reference, t.description AS tx_desc,
                   ti.description AS line_desc, ti.debit, ti.credit
            FROM acc_transaction_items ti
            JOIN acc_transactions t ON ti.transaction_id = t.id
            WHERE t.org_id=? AND ti.account_id=? AND t.date BETWEEN ? AND ? AND t.status='posted'
            ORDER BY t.date ASC, t.id ASC
        ");
        $stmt->execute([$orgId, $glAccount, $filterFrom, $filterTo]);
        $glEntries = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// ── TAB 5: Aged Receivables ────────────────────────────────────
$arRows = [];
$arBuckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT invoice_no, customer_name, issue_date, due_date, balance,
               DATEDIFF(CURDATE(), due_date) AS days_overdue
        FROM acc_invoices
        WHERE org_id=? AND status NOT IN ('paid','cancelled') AND balance > 0
        ORDER BY due_date ASC
    ");
    $stmt->execute([$orgId]);
    $arRows = $stmt->fetchAll();
    foreach ($arRows as $ar) {
        $d = (int)$ar['days_overdue'];
        $b = (float)$ar['balance'];
        if ($d <= 0)       $arBuckets['current'] += $b;
        elseif ($d <= 30)  $arBuckets['1_30']    += $b;
        elseif ($d <= 60)  $arBuckets['31_60']   += $b;
        elseif ($d <= 90)  $arBuckets['61_90']   += $b;
        else               $arBuckets['over_90'] += $b;
    }
} catch (Exception $e) {}
$arTotal = array_sum($arBuckets);

// ── Chart data (income statement 6-month trend) ────────────────
$chartLabels = []; $chartRevenue = []; $chartExpenses = [];
for ($i = 5; $i >= 0; $i--) {
    $m   = date('Y-m', strtotime("-$i months"));
    $m1  = $m . '-01';
    $m2  = date('Y-m-t', strtotime($m1));
    $chartLabels[] = date('M Y', strtotime($m1));
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM acc_invoices WHERE org_id=? AND issue_date BETWEEN ? AND ? AND status IN ('paid','sent')");
        $s->execute([$orgId, $m1, $m2]);
        $chartRevenue[] = (float)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM acc_expenses WHERE org_id=? AND date BETWEEN ? AND ?");
        $s->execute([$orgId, $m1, $m2]);
        $chartExpenses[] = (float)$s->fetchColumn();
    } catch (Exception $e) { $chartRevenue[] = 0; $chartExpenses[] = 0; }
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4 d-print-none">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Financial Reports</h4>
    <p class="text-muted mb-0">Income Statement · Balance Sheet · Trial Balance · General Ledger · Aged Receivables</p>
  </div>
  <button class="btn btn-outline-secondary d-print-none" onclick="window.print()">
    <i class="fas fa-print me-2"></i>Print
  </button>
</div>

<!-- Date Range Filter -->
<div class="card mb-4 d-print-none">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
      <input type="hidden" name="account_id" value="<?= $glAccount ?>">
      <div class="col-sm-3 col-md-2">
        <label class="form-label small fw-semibold mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($filterFrom) ?>">
      </div>
      <div class="col-sm-3 col-md-2">
        <label class="form-label small fw-semibold mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($filterTo) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-search me-1"></i>Apply</button>
        <a href="?tab=<?= e($activeTab) ?>&from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-secondary ms-1">This Year</a>
        <a href="?tab=<?= e($activeTab) ?>&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-secondary ms-1">This Month</a>
      </div>
    </form>
  </div>
</div>

<!-- Report Tabs -->
<ul class="nav nav-tabs d-print-none mb-4" id="reportTabs">
  <?php
  $tabs = [
    'income'   => ['icon' => 'fa-chart-line',     'label' => 'Income Statement'],
    'balance'  => ['icon' => 'fa-balance-scale',  'label' => 'Balance Sheet'],
    'trial'    => ['icon' => 'fa-table',           'label' => 'Trial Balance'],
    'ledger'   => ['icon' => 'fa-book',            'label' => 'General Ledger'],
    'aged'     => ['icon' => 'fa-clock',           'label' => 'Aged Receivables'],
  ];
  foreach ($tabs as $key => $tab): ?>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === $key ? 'active' : '' ?>"
      href="?tab=<?= $key ?>&from=<?= e($filterFrom) ?>&to=<?= e($filterTo) ?>">
      <i class="fas <?= $tab['icon'] ?> me-1"></i><?= $tab['label'] ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- ═══ TAB: INCOME STATEMENT ═══════════════════════════════ -->
<?php if ($activeTab === 'income'): ?>
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-arrow-up"></i></div>
      <div class="stat-body"><div class="stat-value text-success"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Revenue</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-arrow-down"></i></div>
      <div class="stat-body"><div class="stat-value text-danger"><?= formatCurrency($totalExpenses) ?></div><div class="stat-label">Expenses</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon <?= $netProfit >= 0 ? 'navy-bg' : 'danger-bg' ?>"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body"><div class="stat-value <?= $netProfit >= 0 ? 'text-primary' : 'text-danger' ?>"><?= formatCurrency(abs($netProfit)) ?></div>
      <div class="stat-label">Net <?= $netProfit >= 0 ? 'Profit' : 'Loss' ?></div></div></div>
  </div>
</div>

<div class="row g-3 mb-4 d-print-none">
  <div class="col-lg-8">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-line me-2 text-success"></i>6-Month Revenue vs Expenses</h6></div>
      <div class="card-body"><canvas id="revExpChart" height="100"></canvas></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2 text-success"></i>Expense Breakdown</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <?php if (!empty($expenseRows)): ?><canvas id="expPieChart" height="220"></canvas>
        <?php else: ?><div class="text-muted small py-4">No expense data</div><?php endif; ?>
      </div></div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-table me-2 text-success"></i>Income Statement — <?= formatDate($filterFrom) ?> to <?= formatDate($filterTo) ?></h6></div>
  <div class="card-body p-0">
    <table class="table mb-0">
      <thead class="table-light"><tr><th>Description</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
        <tr class="table-success"><td colspan="2" class="fw-bold">REVENUE</td></tr>
        <?php if (empty($revenueRows)): ?>
        <tr><td colspan="2" class="text-muted small ps-4">No revenue entries in period</td></tr>
        <?php else: foreach ($revenueRows as $row): ?>
        <tr><td class="ps-4"><?= e($row['customer_name'] ?? 'Unknown') ?> <span class="badge bg-light text-dark"><?= $row['cnt'] ?> inv.</span></td>
          <td class="text-end"><?= formatCurrency((float)$row['total']) ?></td></tr>
        <?php endforeach; endif; ?>
        <tr class="fw-bold border-top"><td class="ps-4">Total Revenue</td><td class="text-end text-success"><?= formatCurrency($totalRevenue) ?></td></tr>

        <tr class="table-danger"><td colspan="2" class="fw-bold pt-3">EXPENSES</td></tr>
        <?php if (empty($expenseRows)): ?>
        <tr><td colspan="2" class="text-muted small ps-4">No expense entries in period</td></tr>
        <?php else: foreach ($expenseRows as $row): ?>
        <tr><td class="ps-4"><?= e($row['category'] ?: 'Uncategorised') ?> <span class="badge bg-light text-dark"><?= $row['cnt'] ?> records</span></td>
          <td class="text-end"><?= formatCurrency((float)$row['total']) ?></td></tr>
        <?php endforeach; endif; ?>
        <tr class="fw-bold border-top"><td class="ps-4">Total Expenses</td><td class="text-end text-danger"><?= formatCurrency($totalExpenses) ?></td></tr>
      </tbody>
      <tfoot>
        <tr class="<?= $netProfit >= 0 ? 'table-success' : 'table-danger' ?> fw-bold">
          <td class="fs-6">NET <?= $netProfit >= 0 ? 'PROFIT' : 'LOSS' ?></td>
          <td class="text-end fs-6 <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatCurrency(abs($netProfit)) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- ═══ TAB: BALANCE SHEET ═══════════════════════════════════ -->
<?php elseif ($activeTab === 'balance'): ?>
<div class="alert alert-info d-print-none small"><i class="fas fa-info-circle me-2"></i>Balance Sheet shows account balances as per the current ledger. Balances update automatically when journal entries are posted.</div>

<div class="row g-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header" style="background:#1A8A4E;color:#fff">
        <h6 class="mb-0 fw-bold"><i class="fas fa-coins me-2"></i>ASSETS</h6>
      </div>
      <div class="card-body p-0">
        <table class="table mb-0">
          <tbody>
            <?php foreach ($bsAssets as $acc): ?>
            <tr>
              <td class="ps-3"><?= e(($acc['code'] ? $acc['code'].' — ' : '') . $acc['name']) ?></td>
              <td class="text-end fw-semibold <?= (float)$acc['balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= formatCurrency((float)$acc['balance']) ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($bsAssets)): ?>
            <tr><td colspan="2" class="text-muted small ps-3 py-3">No asset accounts set up</td></tr>
            <?php endif; ?>
          </tbody>
          <tfoot class="table-success">
            <tr class="fw-bold"><td class="ps-3">TOTAL ASSETS</td><td class="text-end"><?= formatCurrency($totalAssets) ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card mb-4">
      <div class="card-header" style="background:#e74c3c;color:#fff">
        <h6 class="mb-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2"></i>LIABILITIES</h6>
      </div>
      <div class="card-body p-0">
        <table class="table mb-0">
          <tbody>
            <?php foreach ($bsLiabilities as $acc): ?>
            <tr>
              <td class="ps-3"><?= e(($acc['code'] ? $acc['code'].' — ' : '') . $acc['name']) ?></td>
              <td class="text-end fw-semibold"><?= formatCurrency((float)$acc['balance']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($bsLiabilities)): ?>
            <tr><td colspan="2" class="text-muted small ps-3 py-3">No liability accounts</td></tr>
            <?php endif; ?>
          </tbody>
          <tfoot class="table-danger">
            <tr class="fw-bold"><td class="ps-3">TOTAL LIABILITIES</td><td class="text-end"><?= formatCurrency($totalLiabilities) ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header" style="background:#0B2D4E;color:#fff">
        <h6 class="mb-0 fw-bold"><i class="fas fa-landmark me-2"></i>EQUITY</h6>
      </div>
      <div class="card-body p-0">
        <table class="table mb-0">
          <tbody>
            <?php foreach ($bsEquity as $acc): ?>
            <tr>
              <td class="ps-3"><?= e(($acc['code'] ? $acc['code'].' — ' : '') . $acc['name']) ?></td>
              <td class="text-end fw-semibold"><?= formatCurrency((float)$acc['balance']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr>
              <td class="ps-3 text-muted">Retained Earnings (Net P&amp;L)</td>
              <td class="text-end <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?> fw-semibold"><?= formatCurrency($netProfit) ?></td>
            </tr>
          </tbody>
          <tfoot class="table-primary">
            <tr class="fw-bold"><td class="ps-3">TOTAL EQUITY</td><td class="text-end"><?= formatCurrency($totalEquity + $netProfit) ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>
    <div class="card mt-3 border-2 <?= abs($totalAssets - $totalLiabEquity) < 0.01 ? 'border-success' : 'border-danger' ?>">
      <div class="card-body py-2">
        <div class="d-flex justify-content-between align-items-center">
          <span class="fw-bold">LIABILITIES + EQUITY</span>
          <span class="fw-bold fs-5"><?= formatCurrency($totalLiabEquity) ?></span>
        </div>
        <?php if (abs($totalAssets - $totalLiabEquity) < 0.01): ?>
        <div class="small text-success mt-1"><i class="fas fa-check-circle me-1"></i>Balance sheet is balanced</div>
        <?php else: ?>
        <div class="small text-danger mt-1"><i class="fas fa-exclamation-triangle me-1"></i>Difference: <?= formatCurrency(abs($totalAssets - $totalLiabEquity)) ?> — post journal entries to balance</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══ TAB: TRIAL BALANCE ════════════════════════════════════ -->
<?php elseif ($activeTab === 'trial'): ?>
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-table me-2 text-success"></i>Trial Balance — As of <?= formatDate($filterTo) ?></h6>
    <?php
    $balanced = abs($tbTotalDebit - $tbTotalCredit) < 0.01;
    ?>
    <span class="badge <?= $balanced ? 'bg-success' : 'bg-danger' ?>">
      <?= $balanced ? 'Balanced ✓' : 'NOT BALANCED !' ?>
    </span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="trialTable">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Code</th>
            <th>Account Name</th>
            <th>Type</th>
            <th class="text-end">Debit</th>
            <th class="text-end pe-3">Credit</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $typeConfig = ['asset'=>['Assets','success'],'liability'=>['Liabilities','danger'],'equity'=>['Equity','primary'],'revenue'=>['Revenue','info'],'expense'=>['Expenses','warning']];
          $lastType = '';
          foreach ($trialBalance as $acc):
            if ($acc['type'] !== $lastType):
              $lastType = $acc['type'];
              $tc = $typeConfig[$acc['type']] ?? [ucfirst($acc['type']), 'secondary'];
          ?>
          <tr class="table-light"><td colspan="5" class="fw-bold ps-3"><span class="badge bg-<?= $tc[1] ?>"><?= $tc[0] ?></span></td></tr>
          <?php endif; ?>
          <tr>
            <td class="ps-3 text-muted small"><?= e($acc['code'] ?? '—') ?></td>
            <td class="fw-semibold"><?= e($acc['name']) ?></td>
            <td><span class="badge bg-<?= $typeConfig[$acc['type']][1] ?? 'secondary' ?> bg-opacity-25 text-dark"><?= ucfirst($acc['type']) ?></span></td>
            <td class="text-end <?= $acc['tb_debit'] > 0 ? 'fw-semibold' : 'text-muted' ?>">
              <?= $acc['tb_debit'] > 0 ? formatCurrency($acc['tb_debit']) : '—' ?>
            </td>
            <td class="text-end pe-3 <?= $acc['tb_credit'] > 0 ? 'fw-semibold' : 'text-muted' ?>">
              <?= $acc['tb_credit'] > 0 ? formatCurrency($acc['tb_credit']) : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-dark">
          <tr class="fw-bold">
            <td class="ps-3" colspan="3">TOTALS</td>
            <td class="text-end"><?= formatCurrency($tbTotalDebit) ?></td>
            <td class="text-end pe-3"><?= formatCurrency($tbTotalCredit) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<!-- ═══ TAB: GENERAL LEDGER ══════════════════════════════════ -->
<?php elseif ($activeTab === 'ledger'): ?>
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="ledger">
      <input type="hidden" name="from" value="<?= e($filterFrom) ?>">
      <input type="hidden" name="to"   value="<?= e($filterTo) ?>">
      <div class="col-sm-8 col-md-6">
        <label class="form-label small fw-semibold mb-1">Account <span class="text-danger">*</span></label>
        <select name="account_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">-- Select an account to view ledger --</option>
          <?php
          $lastType = '';
          foreach ($glAccounts as $acc):
            if ($acc['type'] !== $lastType) { $lastType = $acc['type']; echo '<optgroup label="'.ucfirst($acc['type']).'">'; }
          ?>
          <option value="<?= $acc['id'] ?>" <?= $glAccount === (int)$acc['id'] ? 'selected' : '' ?>>
            <?= e(($acc['code'] ? $acc['code'].' — ' : '') . $acc['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<?php if ($glAccount > 0): ?>
<?php
$runningBal = $glOpenBal;
$glAccountName = '';
foreach ($glAccounts as $a) { if ((int)$a['id'] === $glAccount) { $glAccountName = ($a['code'] ? $a['code'].' — ' : '') . $a['name']; break; } }
?>
<div class="card">
  <div class="card-header">
    <h6 class="mb-0"><i class="fas fa-book me-2 text-success"></i>General Ledger — <?= e($glAccountName) ?></h6>
    <small class="text-muted">Period: <?= formatDate($filterFrom) ?> to <?= formatDate($filterTo) ?></small>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Date</th>
            <th>Reference</th>
            <th>Description</th>
            <th class="text-end">Debit</th>
            <th class="text-end">Credit</th>
            <th class="text-end pe-3">Balance</th>
          </tr>
        </thead>
        <tbody>
          <tr class="table-secondary">
            <td class="ps-3" colspan="5" class="text-muted fw-semibold">Opening Balance (before <?= formatDate($filterFrom) ?>)</td>
            <td class="text-end pe-3 fw-bold"><?= formatCurrency($glOpenBal) ?></td>
          </tr>
          <?php if (empty($glEntries)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No transactions in this period for this account.</td></tr>
          <?php else: foreach ($glEntries as $gl):
            $runningBal += (float)$gl['debit'] - (float)$gl['credit'];
          ?>
          <tr>
            <td class="ps-3"><?= formatDate($gl['date']) ?></td>
            <td class="fw-semibold small"><?= e($gl['reference'] ?? '—') ?></td>
            <td class="small text-muted"><?= e($gl['line_desc'] ?: $gl['tx_desc'] ?: '—') ?></td>
            <td class="text-end <?= (float)$gl['debit'] > 0 ? 'text-success fw-semibold' : 'text-muted' ?>">
              <?= (float)$gl['debit'] > 0 ? formatCurrency((float)$gl['debit']) : '—' ?>
            </td>
            <td class="text-end <?= (float)$gl['credit'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">
              <?= (float)$gl['credit'] > 0 ? formatCurrency((float)$gl['credit']) : '—' ?>
            </td>
            <td class="text-end pe-3 fw-bold <?= $runningBal >= 0 ? '' : 'text-danger' ?>">
              <?= formatCurrency($runningBal) ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <tfoot class="table-dark">
          <tr class="fw-bold">
            <td class="ps-3" colspan="5">Closing Balance</td>
            <td class="text-end pe-3"><?= formatCurrency($runningBal) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>
<?php else: ?>
<div class="alert alert-secondary text-center py-5">
  <i class="fas fa-book fa-3x mb-3 d-block text-muted"></i>
  <p class="text-muted">Select an account above to view its detailed transaction ledger.</p>
</div>
<?php endif; ?>

<!-- ═══ TAB: AGED RECEIVABLES ═════════════════════════════════ -->
<?php elseif ($activeTab === 'aged'): ?>
<div class="row g-3 mb-4">
  <?php
  $bucketConfig = [
    'current' => ['Current / Not Due', 'success'],
    '1_30'    => ['1 – 30 Days',       'info'],
    '31_60'   => ['31 – 60 Days',      'warning'],
    '61_90'   => ['61 – 90 Days',      'orange'],
    'over_90' => ['Over 90 Days',      'danger'],
  ];
  foreach ($bucketConfig as $key => [$label, $color]):
  ?>
  <div class="col-sm-6 col-xl">
    <div class="card border-0 shadow-sm text-center py-3">
      <div class="fw-bold fs-5 text-<?= $color === 'orange' ? 'warning' : $color ?>"><?= formatCurrency($arBuckets[$key]) ?></div>
      <div class="small text-muted"><?= $label ?></div>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="col-sm-6 col-xl">
    <div class="card border-0 shadow-sm text-center py-3 bg-dark text-white">
      <div class="fw-bold fs-5"><?= formatCurrency($arTotal) ?></div>
      <div class="small opacity-75">Total Outstanding</div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-clock me-2 text-success"></i>Aged Receivables — Outstanding Invoices</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="arTable">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Invoice #</th>
            <th>Customer</th>
            <th>Issue Date</th>
            <th>Due Date</th>
            <th class="text-center">Days Overdue</th>
            <th class="text-end pe-3">Balance Due</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($arRows)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">
            <i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>No outstanding receivables. All invoices are paid!
          </td></tr>
          <?php else: foreach ($arRows as $ar):
            $d = (int)$ar['days_overdue'];
            $badge = $d <= 0 ? 'success' : ($d <= 30 ? 'info' : ($d <= 60 ? 'warning' : ($d <= 90 ? 'warning' : 'danger')));
            $label = $d <= 0 ? 'Current' : "$d days";
          ?>
          <tr>
            <td class="ps-3 fw-semibold"><?= e($ar['invoice_no']) ?></td>
            <td class="fw-semibold"><?= e($ar['customer_name']) ?></td>
            <td><?= formatDate($ar['issue_date']) ?></td>
            <td class="<?= $d > 0 ? 'text-danger fw-semibold' : '' ?>"><?= formatDate($ar['due_date']) ?></td>
            <td class="text-center"><span class="badge bg-<?= $badge ?>"><?= $label ?></span></td>
            <td class="text-end pe-3 fw-bold text-danger"><?= formatCurrency((float)$ar['balance']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($arRows)): ?>
        <tfoot class="table-dark">
          <tr class="fw-bold">
            <td class="ps-3" colspan="5">Total Outstanding</td>
            <td class="text-end pe-3"><?= formatCurrency($arTotal) ?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$labelsJson   = json_encode($chartLabels);
$revenueJson  = json_encode($chartRevenue);
$expensesJson = json_encode($chartExpenses);
$expPieLabels = json_encode(array_map(fn($r) => $r['category'] ?: 'Uncategorised', $expenseRows));
$expPieData   = json_encode(array_column($expenseRows, 'total'));

$extraJs = <<<JS
<script>
(function(){
  var revEl = document.getElementById('revExpChart');
  if (revEl) {
    new Chart(revEl, {
      type: 'line',
      data: {
        labels: $labelsJson,
        datasets: [
          {label:'Revenue', data:$revenueJson,  borderColor:'#1A8A4E', backgroundColor:'rgba(26,138,78,.1)',  tension:.4, fill:true},
          {label:'Expenses',data:$expensesJson, borderColor:'#e74c3c', backgroundColor:'rgba(231,76,60,.1)',  tension:.4, fill:true}
        ]
      },
      options:{responsive:true,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true}}}
    });
  }
  var pieEl = document.getElementById('expPieChart');
  if (pieEl) {
    new Chart(pieEl, {
      type:'doughnut',
      data:{labels:$expPieLabels,datasets:[{data:$expPieData,backgroundColor:['#1A8A4E','#0B2D4E','#e74c3c','#f39c12','#8e44ad','#2980b9','#16a085','#d35400']}]},
      options:{responsive:true,plugins:{legend:{position:'bottom'}}}
    });
  }
  var arEl = document.getElementById('arTable');
  if (arEl) { $(arEl).DataTable({pageLength:25,order:[[4,'desc']]}); }
  var tbEl = document.getElementById('trialTable');
  if (tbEl) { $(tbEl).DataTable({pageLength:50,paging:false,searching:true}); }
})();
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
