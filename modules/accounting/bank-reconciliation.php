<?php
// ── Accounting: Bank Reconciliation ───────────────────────────
$moduleSlug  = 'accounting';
$moduleName  = 'Accounting & Bookkeeping';
$moduleIcon  = 'fas fa-calculator';
$moduleColor = '#1A8A4E';
$moduleNav   = [
    ['url' => 'index.php',         'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'accounts.php',      'icon' => 'fas fa-list',            'label' => 'Chart of Accounts'],
    ['url' => 'transactions.php',  'icon' => 'fas fa-exchange-alt',    'label' => 'Journal Entries'],
    ['url' => 'invoices.php',      'icon' => 'fas fa-file-invoice',    'label' => 'Invoices'],
    ['url' => 'payments.php',      'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'expenses.php',      'icon' => 'fas fa-receipt',         'label' => 'Expenses'],
    ['url' => 'bills.php',         'icon' => 'fas fa-file-import',     'label' => 'Vendor Bills'],
    ['url' => 'budgets.php',       'icon' => 'fas fa-bullseye',        'label' => 'Budgets'],
    ['url' => 'taxes.php',         'icon' => 'fas fa-percentage',      'label' => 'Tax Rates'],
    ['url' => 'assets.php',        'icon' => 'fas fa-building',        'label' => 'Fixed Assets'],
    ['url' => 'payroll-journal.php','icon'=> 'fas fa-file-alt',        'label' => 'Payroll Journal'],
    ['url' => 'audit.php',         'icon' => 'fas fa-history',         'label' => 'Audit Trail'],
    ['url' => 'reports.php',       'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];

// ── POST Handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_reconciliation') {
        $accountId   = (int)($_POST['account_id']   ?? 0);
        $period      = sanitize($_POST['period']      ?? date('Y-m'));
        $bankBalance = (float)($_POST['bank_balance'] ?? 0);
        $bookBalance = (float)($_POST['book_balance'] ?? 0);
        $difference  = round($bankBalance - $bookBalance, 2);
        $status      = abs($difference) < 0.01 ? 'balanced' : 'unbalanced';

        // Ensure the table exists
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS acc_reconciliations (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                org_id        INT UNSIGNED NOT NULL,
                account_id    INT UNSIGNED NOT NULL,
                period        VARCHAR(7) NOT NULL,
                bank_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
                book_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
                difference    DECIMAL(15,2) NOT NULL DEFAULT 0,
                status        VARCHAR(20) NOT NULL DEFAULT 'unbalanced',
                reconciled_by INT UNSIGNED DEFAULT NULL,
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_org_period (org_id, period),
                INDEX idx_account   (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $ex) {}

        try {
            $ins = $pdo->prepare("INSERT INTO acc_reconciliations
                (org_id, account_id, period, bank_balance, book_balance, difference, status, reconciled_by)
                VALUES (?,?,?,?,?,?,?,?)");
            $ins->execute([$orgId, $accountId, $period, $bankBalance, $bookBalance, $difference, $status, $user['id']]);
            setFlash('success', "Reconciliation saved for $period. Difference: " . number_format($difference, 2) . ($status === 'balanced' ? ' — Balanced!' : ' — Unbalanced.'));
            logActivity('create', 'accounting', "Bank reconciliation saved for account #$accountId period $period");
        } catch (Exception $ex) {
            setFlash('danger', 'Save failed: ' . $ex->getMessage());
        }
        redirect('bank-reconciliation.php?account_id=' . $accountId . '&period=' . urlencode($period));
    }
}

// ── Page Load ──────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Ensure reconciliations table exists (read path)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_reconciliations (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        org_id        INT UNSIGNED NOT NULL,
        account_id    INT UNSIGNED NOT NULL,
        period        VARCHAR(7) NOT NULL,
        bank_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
        book_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
        difference    DECIMAL(15,2) NOT NULL DEFAULT 0,
        status        VARCHAR(20) NOT NULL DEFAULT 'unbalanced',
        reconciled_by INT UNSIGNED DEFAULT NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_org_period (org_id, period),
        INDEX idx_account    (account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $ex) {}

$filterAccount = (int)($_GET['account_id'] ?? 0);
$filterPeriod  = sanitize($_GET['period']     ?? date('Y-m'));
$filterFrom    = $_GET['from'] ?? date('Y-m-01');
$filterTo      = $_GET['to']   ?? date('Y-m-d');

// Bank / cash accounts for dropdown
$bankAccounts = [];
try {
    $stmt = $pdo->prepare("SELECT id, code, name, balance FROM acc_accounts WHERE org_id=? AND type='asset' AND status='active' ORDER BY code, name");
    $stmt->execute([$orgId]);
    $bankAccounts = $stmt->fetchAll();
} catch (Exception $e) {}

// Transactions for the date range
$transactions = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.date, t.reference, t.description, t.amount, t.type
        FROM acc_transactions t
        WHERE t.org_id=? AND t.date BETWEEN ? AND ?
        ORDER BY t.date ASC, t.id ASC
    ");
    $stmt->execute([$orgId, $filterFrom, $filterTo]);
    $transactions = $stmt->fetchAll();
} catch (Exception $e) {}

// Cleared items from POST (during preview) — not stored per-transaction, just totals computed on JS side
// Opening balance: last reconciliation for this account before filterPeriod
$openingBalance = 0;
$lastReconDate  = null;
if ($filterAccount) {
    try {
        $stmt = $pdo->prepare("SELECT book_balance, created_at FROM acc_reconciliations WHERE org_id=? AND account_id=? AND period < ? ORDER BY period DESC LIMIT 1");
        $stmt->execute([$orgId, $filterAccount, $filterPeriod]);
        $lastRecon = $stmt->fetch();
        if ($lastRecon) {
            $openingBalance = (float)$lastRecon['book_balance'];
            $lastReconDate  = $lastRecon['created_at'];
        }
    } catch (Exception $e) {}
}

// Current account balance
$currentBalance = 0;
if ($filterAccount) {
    try {
        $stmt = $pdo->prepare("SELECT balance FROM acc_accounts WHERE id=? AND org_id=?");
        $stmt->execute([$filterAccount, $orgId]);
        $row = $stmt->fetch();
        if ($row) $currentBalance = (float)$row['balance'];
    } catch (Exception $e) {}
}

// Past reconciliations
$pastRecons = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, a.name AS account_name, u.name AS reconciled_by_name
        FROM acc_reconciliations r
        LEFT JOIN acc_accounts a ON r.account_id = a.id
        LEFT JOIN users u ON r.reconciled_by = u.id
        WHERE r.org_id=?
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$orgId]);
    $pastRecons = $stmt->fetchAll();
} catch (Exception $e) {}

// Outstanding items count (transactions not yet reconciled in any period)
$outstandingCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM acc_transactions WHERE org_id=? AND date > COALESCE((SELECT MAX(period) FROM acc_reconciliations WHERE org_id=?), '1900-01')");
    $stmt->execute([$orgId, $orgId]);
    $outstandingCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$lastReconRecord = null;
try {
    $stmt = $pdo->prepare("SELECT created_at FROM acc_reconciliations WHERE org_id=? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$orgId]);
    $lastReconRecord = $stmt->fetchColumn();
} catch (Exception $e) {}

$totalDeposits    = array_sum(array_map(fn($t) => $t['type'] === 'credit' ? (float)$t['amount'] : 0, $transactions));
$totalWithdrawals = array_sum(array_map(fn($t) => $t['type'] === 'debit'  ? (float)$t['amount'] : 0, $transactions));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-university me-2" style="color:<?= $moduleColor ?>"></i>Bank Reconciliation</h4>
    <p class="text-muted mb-0">Match your bank statement with your book balance</p>
  </div>
  <a href="reports.php" class="btn btn-outline-secondary">
    <i class="fas fa-chart-bar me-2"></i>Financial Reports
  </a>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($currentBalance) ?></div>
        <div class="stat-label">Current Book Balance<?= $filterAccount ? ' (Selected A/C)' : '' ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $lastReconRecord ? formatDate($lastReconRecord) : 'Never' ?></div>
        <div class="stat-label">Last Reconciled Date</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon <?= $outstandingCount > 0 ? 'warning-bg' : 'green-bg' ?>"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $outstandingCount ?></div>
        <div class="stat-label">Unreconciled Transactions</div>
      </div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Account</label>
        <select name="account_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Accounts</option>
          <?php foreach ($bankAccounts as $acc): ?>
          <option value="<?= $acc['id'] ?>" <?= $filterAccount === (int)$acc['id'] ? 'selected' : '' ?>>
            <?= e(($acc['code'] ? $acc['code'].' — ' : '') . $acc['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Period (YYYY-MM)</label>
        <input type="month" name="period" class="form-control form-control-sm" value="<?= e($filterPeriod) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($filterFrom) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($filterTo) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Apply</button>
      </div>
    </form>
  </div>
</div>

<!-- Main Reconciliation Panel -->
<div class="row g-4 mb-4">

  <!-- Left: Bank Statement -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header" style="background:#0B2D4E;color:#fff">
        <h6 class="mb-0"><i class="fas fa-file-alt me-2"></i>Bank Statement
          <span class="badge bg-light text-dark ms-2"><?= formatDate($filterFrom) ?> – <?= formatDate($filterTo) ?></span>
        </h6>
      </div>
      <div class="card-body">
        <!-- Bank balance input -->
        <div class="row g-3 mb-3 pb-3 border-bottom">
          <div class="col-sm-6">
            <label class="form-label small fw-semibold">Bank Statement Date</label>
            <input type="date" id="bankDate" class="form-control" value="<?= e($filterTo) ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label small fw-semibold">Bank Closing Balance (from statement)</label>
            <div class="input-group">
              <span class="input-group-text"><?= CURRENCY_SYMBOL ?></span>
              <input type="number" id="bankBalance" class="form-control fw-bold" step="0.01"
                     placeholder="0.00" oninput="updateDifference()">
            </div>
          </div>
        </div>

        <!-- Transactions with cleared checkboxes -->
        <?php if (empty($transactions)): ?>
        <div class="text-center text-muted py-4">
          <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
          No transactions found for <?= e($filterFrom) ?> – <?= e($filterTo) ?>
        </div>
        <?php else: ?>
        <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
          <table class="table table-sm table-hover mb-0" id="txTable">
            <thead class="table-light sticky-top">
              <tr>
                <th style="width:36px">
                  <input type="checkbox" id="selectAllCleared" title="Select all" onchange="toggleAll(this)">
                </th>
                <th>Date</th>
                <th>Description</th>
                <th>Type</th>
                <th class="text-end">Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($transactions as $tx): ?>
              <tr>
                <td>
                  <input type="checkbox" class="cleared-item"
                         data-amount="<?= (float)$tx['amount'] ?>"
                         data-type="<?= e($tx['type']) ?>"
                         onchange="updateDifference()">
                </td>
                <td class="small"><?= formatDate($tx['date']) ?></td>
                <td class="small"><?= e($tx['description'] ?? '—') ?></td>
                <td>
                  <span class="badge bg-<?= $tx['type'] === 'credit' ? 'success' : 'danger' ?> bg-opacity-75">
                    <?= $tx['type'] === 'credit' ? 'Deposit' : 'Withdrawal' ?>
                  </span>
                </td>
                <td class="text-end fw-semibold <?= $tx['type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                  <?= ($tx['type'] === 'credit' ? '+' : '-') . formatCurrency((float)$tx['amount']) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
              <tr>
                <td colspan="4" class="fw-semibold text-end small">All Deposits / Withdrawals:</td>
                <td class="text-end small">
                  <span class="text-success d-block">+<?= formatCurrency($totalDeposits) ?></span>
                  <span class="text-danger d-block">-<?= formatCurrency($totalWithdrawals) ?></span>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: Book Balance & Save -->
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header" style="background:#1A8A4E;color:#fff">
        <h6 class="mb-0"><i class="fas fa-book me-2"></i>Book Balance (Auto-Computed)</h6>
      </div>
      <div class="card-body">
        <table class="table table-sm mb-3">
          <tbody>
            <tr>
              <td class="text-muted">Opening Balance</td>
              <td class="text-end fw-semibold" id="openingBalDisplay"><?= formatCurrency($openingBalance) ?></td>
            </tr>
            <tr>
              <td class="text-muted text-success">+ Cleared Deposits</td>
              <td class="text-end text-success fw-semibold" id="clearedDeposits"><?= CURRENCY_SYMBOL ?>0.00</td>
            </tr>
            <tr>
              <td class="text-muted text-danger">− Cleared Withdrawals</td>
              <td class="text-end text-danger fw-semibold" id="clearedWithdrawals"><?= CURRENCY_SYMBOL ?>0.00</td>
            </tr>
          </tbody>
          <tfoot class="table-success">
            <tr class="fw-bold">
              <td>Adjusted Book Balance</td>
              <td class="text-end fs-5" id="bookBalDisplay"><?= formatCurrency($openingBalance) ?></td>
            </tr>
          </tfoot>
        </table>

        <!-- Difference badge -->
        <div class="text-center mb-3">
          <div class="small text-muted mb-1">Reconciliation Difference</div>
          <span id="diffBadge" class="badge fs-5 px-4 py-2 bg-secondary">
            <?= CURRENCY_SYMBOL ?>0.00
          </span>
          <div id="diffNote" class="small mt-1 text-muted">Enter bank balance and mark cleared items</div>
        </div>

        <!-- Save form -->
        <form method="POST" id="reconForm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="save_reconciliation">
          <input type="hidden" name="period" value="<?= e($filterPeriod) ?>">
          <input type="hidden" name="account_id" value="<?= $filterAccount ?>">
          <input type="hidden" name="bank_balance" id="bankBalanceHidden" value="0">
          <input type="hidden" name="book_balance" id="bookBalanceHidden" value="<?= $openingBalance ?>">

          <div class="mb-3">
            <label class="form-label small fw-semibold">Account <span class="text-danger">*</span></label>
            <select name="account_id" class="form-select" required>
              <option value="">Select account</option>
              <?php foreach ($bankAccounts as $acc): ?>
              <option value="<?= $acc['id'] ?>" <?= $filterAccount === (int)$acc['id'] ? 'selected' : '' ?>>
                <?= e(($acc['code'] ? $acc['code'].' — ' : '') . $acc['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="btn btn-success w-100 fw-semibold"
                  onclick="return prepareSave()">
            <i class="fas fa-save me-2"></i>Save Reconciliation
          </button>
        </form>
      </div>
    </div>

    <?php if ($lastReconDate): ?>
    <div class="alert alert-info small">
      <i class="fas fa-history me-2"></i>Opening balance of <strong><?= formatCurrency($openingBalance) ?></strong>
      carried from reconciliation on <?= formatDate($lastReconDate) ?>.
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Past Reconciliations -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-history me-2" style="color:<?= $moduleColor ?>"></i>Past Reconciliations</h6>
    <span class="badge bg-secondary"><?= count($pastRecons) ?> records</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($pastRecons)): ?>
    <div class="text-center text-muted py-5">
      <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No reconciliations saved yet.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="pastReconTable">
        <thead class="table-light">
          <tr>
            <th>Period</th>
            <th>Account</th>
            <th class="text-end">Bank Balance</th>
            <th class="text-end">Book Balance</th>
            <th class="text-end">Difference</th>
            <th>Status</th>
            <th>Reconciled By</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pastRecons as $rec): ?>
          <tr>
            <td class="fw-semibold"><?= e($rec['period']) ?></td>
            <td class="small text-muted"><?= e($rec['account_name'] ?? 'N/A') ?></td>
            <td class="text-end"><?= formatCurrency((float)$rec['bank_balance']) ?></td>
            <td class="text-end"><?= formatCurrency((float)$rec['book_balance']) ?></td>
            <td class="text-end fw-semibold <?= abs((float)$rec['difference']) < 0.01 ? 'text-success' : 'text-danger' ?>">
              <?= formatCurrency(abs((float)$rec['difference'])) ?>
            </td>
            <td><?= statusBadge($rec['status']) ?></td>
            <td class="small text-muted"><?= e($rec['reconciled_by_name'] ?? '—') ?></td>
            <td class="small"><?= formatDate($rec['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$openingBalJS = (float)$openingBalance;
$currSymbol   = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'KES ';

$extraJs = <<<JS
<script>
var openingBal = {$openingBalJS};
var currSymbol = '{$currSymbol}';

function fmt(n) {
  return currSymbol + parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function updateDifference() {
  var bankVal = parseFloat(document.getElementById('bankBalance').value) || 0;
  var cleared = document.querySelectorAll('.cleared-item:checked');
  var deposits    = 0;
  var withdrawals = 0;
  cleared.forEach(function(cb) {
    var amt  = parseFloat(cb.dataset.amount) || 0;
    var type = cb.dataset.type;
    if (type === 'credit') deposits    += amt;
    else                   withdrawals += amt;
  });

  var bookBal = openingBal + deposits - withdrawals;
  var diff    = bankVal - bookBal;

  document.getElementById('clearedDeposits').textContent    = fmt(deposits);
  document.getElementById('clearedWithdrawals').textContent = fmt(withdrawals);
  document.getElementById('bookBalDisplay').textContent     = fmt(bookBal);
  document.getElementById('bankBalanceHidden').value        = bankVal;
  document.getElementById('bookBalanceHidden').value        = bookBal;

  var badge = document.getElementById('diffBadge');
  var note  = document.getElementById('diffNote');
  badge.textContent = fmt(Math.abs(diff));

  if (bankVal === 0 && cleared.length === 0) {
    badge.className = 'badge fs-5 px-4 py-2 bg-secondary';
    note.textContent = 'Enter bank balance and mark cleared items';
    note.className   = 'small mt-1 text-muted';
  } else if (Math.abs(diff) < 0.01) {
    badge.className = 'badge fs-5 px-4 py-2 bg-success';
    note.textContent = 'Account is balanced!';
    note.className   = 'small mt-1 text-success fw-semibold';
  } else {
    badge.className = 'badge fs-5 px-4 py-2 bg-danger';
    note.textContent = (diff > 0 ? 'Bank exceeds books by ' : 'Books exceed bank by ') + fmt(Math.abs(diff));
    note.className   = 'small mt-1 text-danger';
  }
}

function toggleAll(master) {
  document.querySelectorAll('.cleared-item').forEach(function(cb){ cb.checked = master.checked; });
  updateDifference();
}

function prepareSave() {
  updateDifference();
  var bankVal = parseFloat(document.getElementById('bankBalance').value) || 0;
  if (bankVal === 0) {
    Swal.fire('Missing Bank Balance', 'Please enter the bank closing balance from your statement.', 'warning');
    return false;
  }
  return true;
}

// Init DataTable for past reconciliations
$(function(){
  var el = document.getElementById('pastReconTable');
  if (el) { \$(el).DataTable({pageLength:10,order:[[0,'desc']]}); }
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
