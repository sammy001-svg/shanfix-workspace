<?php
// ── Accounting: Budget Management ─────────────────────────────
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

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $accountId   = (int)($_POST['account_id'] ?? 0) ?: null;
        $category    = sanitize($_POST['category'] ?? '');
        $budgetYear  = (int)($_POST['budget_year'] ?? date('Y'));
        $budgetMonth = (int)($_POST['budget_month'] ?? 0);
        $amount      = (float)($_POST['amount'] ?? 0);

        try {
            $pdo->prepare("
                INSERT INTO acc_budgets (org_id, account_id, category, budget_year, budget_month, amount)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE amount=VALUES(amount), updated_at=NOW()
            ")->execute([$orgId, $accountId, $category ?: null, $budgetYear, $budgetMonth, $amount]);
            setFlash('success', 'Budget saved.');
            logActivity('update', 'accounting', "Budget set for $budgetYear: " . formatCurrency($amount));
        } catch (Exception $e) {
            setFlash('danger', 'Failed to save budget.');
        }
        redirect('budgets.php?year=' . $budgetYear);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM acc_budgets WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Budget entry removed.');
        } catch (Exception $e) {
            setFlash('danger', 'Failed to delete budget.');
        }
        redirect('budgets.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$selectedYear = (int)($_GET['year'] ?? date('Y'));

// All accounts for budget assignment
$accounts = [];
try {
    $stmt = $pdo->prepare("SELECT id, code, name, type FROM acc_accounts WHERE org_id=? AND status='active' ORDER BY type, code, name");
    $stmt->execute([$orgId]);
    $accounts = $stmt->fetchAll();
} catch (Exception $e) {}

// Expense categories used in expenses
$expCategories = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT category FROM acc_expenses WHERE org_id=? AND category IS NOT NULL AND category!='' ORDER BY category");
    $stmt->execute([$orgId]);
    $expCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Load budgets for selected year
$budgets = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, a.name AS account_name, a.code AS account_code, a.type AS account_type
        FROM acc_budgets b
        LEFT JOIN acc_accounts a ON b.account_id = a.id
        WHERE b.org_id=? AND b.budget_year=?
        ORDER BY b.budget_month ASC, a.type ASC, a.code ASC
    ");
    $stmt->execute([$orgId, $selectedYear]);
    $budgets = $stmt->fetchAll();
} catch (Exception $e) {}

// For each budget, fetch actual spend
$months = [0=>'Full Year',1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];

function getBudgetActual(PDO $pdo, int $orgId, int $year, int $month, ?int $accountId, ?string $category): float {
    $actual = 0.0;
    $m1 = $month === 0 ? "$year-01-01" : "$year-" . str_pad($month,2,'0',STR_PAD_LEFT) . "-01";
    $m2 = $month === 0 ? "$year-12-31" : date('Y-m-t', strtotime($m1));

    try {
        if ($accountId) {
            // Check actual from transaction items
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(ti.debit),0)
                FROM acc_transaction_items ti
                JOIN acc_transactions t ON ti.transaction_id = t.id
                WHERE t.org_id=? AND ti.account_id=? AND t.date BETWEEN ? AND ? AND t.status='posted'
            ");
            $stmt->execute([$orgId, $accountId, $m1, $m2]);
            $actual = (float)$stmt->fetchColumn();
        } elseif ($category) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM acc_expenses WHERE org_id=? AND category=? AND date BETWEEN ? AND ?");
            $stmt->execute([$orgId, $category, $m1, $m2]);
            $actual = (float)$stmt->fetchColumn();
        }
    } catch (Exception $e) {}
    return $actual;
}

$totalBudgeted = 0;
$totalActual   = 0;
$budgetRows    = [];
foreach ($budgets as $b) {
    $actual = getBudgetActual($pdo, $orgId, $selectedYear, (int)$b['budget_month'], $b['account_id'] ? (int)$b['account_id'] : null, $b['category']);
    $budgeted = (float)$b['amount'];
    $variance = $budgeted - $actual;
    $pct      = $budgeted > 0 ? min(round(($actual / $budgeted) * 100), 100) : 0;
    $overBudget = $actual > $budgeted && $budgeted > 0;
    $budgetRows[] = array_merge($b, [
        'actual'      => $actual,
        'variance'    => $variance,
        'pct'         => $pct,
        'over_budget' => $overBudget,
    ]);
    $totalBudgeted += $budgeted;
    $totalActual   += $actual;
}
$totalVariance = $totalBudgeted - $totalActual;

// Year options (current year ± 2)
$yearOptions = range(date('Y') - 2, date('Y') + 2);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bullseye me-2" style="color:<?= $moduleColor ?>"></i>Budget Management</h4>
    <p class="text-muted mb-0">Set budgets by account or category and track actual vs budgeted spend</p>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#budgetModal">
    <i class="fas fa-plus me-2"></i>Set Budget
  </button>
</div>

<!-- Year selector -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="d-flex align-items-center gap-3">
      <label class="form-label fw-semibold mb-0">Budget Year:</label>
      <select name="year" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
        <?php foreach ($yearOptions as $y): ?>
        <option value="<?= $y ?>" <?= $y === $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
      <span class="text-muted small">Showing budgets for <?= $selectedYear ?></span>
    </form>
  </div>
</div>

<?php if (!empty($budgetRows)): ?>
<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-bullseye"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalBudgeted) ?></div><div class="stat-label">Total Budgeted</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon <?= $totalActual > $totalBudgeted ? 'danger-bg' : 'green-bg' ?>"><i class="fas fa-chart-bar"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalActual) ?></div><div class="stat-label">Total Actual Spend</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon <?= $totalVariance >= 0 ? 'green-bg' : 'danger-bg' ?>"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body">
        <div class="stat-value <?= $totalVariance >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatCurrency(abs($totalVariance)) ?></div>
        <div class="stat-label"><?= $totalVariance >= 0 ? 'Under Budget' : 'Over Budget' ?></div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Budget Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-table me-2 text-success"></i>Budget vs Actual — <?= $selectedYear ?></h6>
    <span class="badge bg-secondary"><?= count($budgetRows) ?> entries</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Account / Category</th>
            <th class="text-center">Period</th>
            <th class="text-end">Budgeted</th>
            <th class="text-end">Actual</th>
            <th class="text-end">Variance</th>
            <th style="width:180px">Usage</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($budgetRows)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            <i class="fas fa-bullseye fa-2x mb-2 d-block"></i>No budgets set for <?= $selectedYear ?>. Click "Set Budget" to start.
          </td></tr>
          <?php else: foreach ($budgetRows as $bRow): ?>
          <tr class="<?= $bRow['over_budget'] ? 'table-danger' : '' ?>">
            <td class="ps-3">
              <div class="fw-semibold">
                <?php if ($bRow['account_name']): ?>
                <i class="fas fa-list text-muted small me-1"></i><?= e($bRow['account_code'] ? $bRow['account_code'].' — ' : '') . e($bRow['account_name']) ?>
                <?php elseif ($bRow['category']): ?>
                <i class="fas fa-tag text-muted small me-1"></i><?= e($bRow['category']) ?>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </div>
              <?php if ($bRow['account_type']): ?><div class="small text-muted"><?= ucfirst($bRow['account_type']) ?></div><?php endif; ?>
            </td>
            <td class="text-center">
              <span class="badge bg-light text-dark"><?= $months[(int)$bRow['budget_month']] ?? 'Full Year' ?></span>
            </td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$bRow['amount']) ?></td>
            <td class="text-end <?= $bRow['over_budget'] ? 'text-danger fw-bold' : '' ?>"><?= formatCurrency($bRow['actual']) ?></td>
            <td class="text-end <?= $bRow['variance'] >= 0 ? 'text-success' : 'text-danger' ?> fw-semibold">
              <?= $bRow['variance'] >= 0 ? '+' : '' ?><?= formatCurrency($bRow['variance']) ?>
            </td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:8px">
                  <div class="progress-bar <?= $bRow['over_budget'] ? 'bg-danger' : 'bg-success' ?>"
                    style="width:<?= $bRow['pct'] ?>%"></div>
                </div>
                <span class="small fw-semibold <?= $bRow['over_budget'] ? 'text-danger' : '' ?>" style="min-width:35px"><?= $bRow['pct'] ?>%</span>
              </div>
            </td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-danger"
                onclick="deleteBudget(<?= $bRow['id'] ?>)" title="Remove">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($budgetRows)): ?>
        <tfoot class="table-light fw-bold">
          <tr>
            <td class="ps-3">TOTAL</td>
            <td></td>
            <td class="text-end"><?= formatCurrency($totalBudgeted) ?></td>
            <td class="text-end"><?= formatCurrency($totalActual) ?></td>
            <td class="text-end <?= $totalVariance >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= $totalVariance >= 0 ? '+' : '' ?><?= formatCurrency($totalVariance) ?>
            </td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- Set Budget Modal -->
<div class="modal fade" id="budgetModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title"><i class="fas fa-bullseye me-2"></i>Set Budget Entry</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Budget Against</label>
              <div class="d-flex gap-3 mb-2">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="budget_type" id="typeAccount" value="account" checked onchange="toggleBudgetType()">
                  <label class="form-check-label" for="typeAccount">Account (from Chart of Accounts)</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="budget_type" id="typeCategory" value="category" onchange="toggleBudgetType()">
                  <label class="form-check-label" for="typeCategory">Expense Category</label>
                </div>
              </div>
            </div>
            <div class="col-12" id="accountDiv">
              <label class="form-label fw-semibold">Account</label>
              <select name="account_id" id="budgetAccount" class="form-select">
                <option value="">-- Select Account --</option>
                <?php foreach ($accounts as $acc): ?>
                <option value="<?= $acc['id'] ?>"><?= e(($acc['code'] ? $acc['code'].' — ' : '') . $acc['name']) ?> (<?= ucfirst($acc['type']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 d-none" id="categoryDiv">
              <label class="form-label fw-semibold">Expense Category</label>
              <input type="text" name="category" id="budgetCategory" class="form-control" list="budgetCatList" placeholder="Type or select category">
              <datalist id="budgetCatList">
                <?php foreach ($expCategories as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?>
                <?php foreach (['Rent','Utilities','Salaries','Transport','Office Supplies','Internet','Marketing','Maintenance','Insurance'] as $c): ?><option value="<?= $c ?>"><?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Budget Year <span class="text-danger">*</span></label>
              <select name="budget_year" class="form-select">
                <?php foreach ($yearOptions as $y): ?>
                <option value="<?= $y ?>" <?= $y === $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Period</label>
              <select name="budget_month" class="form-select">
                <?php foreach ($months as $m => $label): ?>
                <option value="<?= $m ?>"><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Budgeted Amount <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><?= CURRENCY_SYMBOL ?></span>
                <input type="number" name="amount" class="form-control" step="0.01" min="0" placeholder="0.00" required>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Budget</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteBudgetForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteBudgetId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function toggleBudgetType() {
  var isAccount = document.getElementById('typeAccount').checked;
  document.getElementById('accountDiv').classList.toggle('d-none', !isAccount);
  document.getElementById('categoryDiv').classList.toggle('d-none', isAccount);
  document.getElementById('budgetAccount').required  = isAccount;
  document.getElementById('budgetCategory').required = !isAccount;
}

function deleteBudget(id) {
  Swal.fire({
    title: 'Remove Budget?',
    text: 'This budget entry will be removed.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Remove'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteBudgetId').value = id;
      document.getElementById('deleteBudgetForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
