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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $type        = in_array($_POST['type'] ?? '', ['income','expense','transfer']) ? $_POST['type'] : 'income';
        $accountId   = (int)($_POST['account_id'] ?? 0);
        $toAccountId = (int)($_POST['to_account_id'] ?? 0);
        $categoryId  = (int)($_POST['category_id'] ?? 0) ?: null;
        $amount      = (float)($_POST['amount'] ?? 0);
        $description = sanitize($_POST['description'] ?? '');
        $date        = sanitize($_POST['date'] ?? date('Y-m-d'));
        $reference   = sanitize($_POST['reference'] ?? '');

        if ($amount <= 0 || $accountId <= 0) {
            setFlash('danger', 'Amount and account are required.');
            redirect('transactions.php');
        }

        if ($id > 0) {
            // Reverse old balance effect
            $old = $pdo->prepare("SELECT * FROM fin_transactions WHERE id=? AND org_id=?");
            $old->execute([$id, $orgId]);
            $oldTx = $old->fetch();
            if ($oldTx) {
                if ($oldTx['type'] === 'income') {
                    $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id=? AND org_id=?")->execute([(float)$oldTx['amount'], (int)$oldTx['account_id'], $orgId]);
                } elseif ($oldTx['type'] === 'expense') {
                    $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id=? AND org_id=?")->execute([(float)$oldTx['amount'], (int)$oldTx['account_id'], $orgId]);
                } elseif ($oldTx['type'] === 'transfer') {
                    $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id=? AND org_id=?")->execute([(float)$oldTx['amount'], (int)$oldTx['account_id'], $orgId]);
                    // Find destination from description
                    $toAccOld = (int)($oldTx['to_account_id'] ?? 0);
                    if ($toAccOld > 0) {
                        $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id=? AND org_id=?")->execute([(float)$oldTx['amount'], $toAccOld, $orgId]);
                    }
                }
            }
            $stmt = $pdo->prepare("UPDATE fin_transactions SET account_id=?, to_account_id=?, category_id=?, type=?, amount=?, description=?, date=?, reference=? WHERE id=? AND org_id=?");
            $stmt->execute([$accountId, $type === 'transfer' ? $toAccountId : null, $categoryId, $type, $amount, $description, $date, $reference, $id, $orgId]);
            setFlash('success', 'Transaction updated.');
            logActivity('update', 'finance', "Updated transaction #$id");
        } else {
            $stmt = $pdo->prepare("INSERT INTO fin_transactions (org_id, account_id, to_account_id, category_id, type, amount, description, date, reference, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $accountId, $type === 'transfer' ? $toAccountId : null, $categoryId, $type, $amount, $description, $date, $reference, $user['id']]);
            setFlash('success', 'Transaction added successfully.');
            logActivity('create', 'finance', "Added $type transaction: $description");
        }

        // Apply new balance effect
        if ($type === 'income') {
            $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id=? AND org_id=?")->execute([$amount, $accountId, $orgId]);
        } elseif ($type === 'expense') {
            $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id=? AND org_id=?")->execute([$amount, $accountId, $orgId]);
        } elseif ($type === 'transfer' && $toAccountId > 0) {
            $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id=? AND org_id=?")->execute([$amount, $accountId, $orgId]);
            $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id=? AND org_id=?")->execute([$amount, $toAccountId, $orgId]);
        }

        redirect('transactions.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $tx = $pdo->prepare("SELECT * FROM fin_transactions WHERE id=? AND org_id=?");
        $tx->execute([$id, $orgId]);
        $txRow = $tx->fetch();
        if ($txRow) {
            // Reverse balance
            if ($txRow['type'] === 'income') {
                $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id=? AND org_id=?")->execute([(float)$txRow['amount'], (int)$txRow['account_id'], $orgId]);
            } elseif ($txRow['type'] === 'expense') {
                $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id=? AND org_id=?")->execute([(float)$txRow['amount'], (int)$txRow['account_id'], $orgId]);
            } elseif ($txRow['type'] === 'transfer') {
                $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id=? AND org_id=?")->execute([(float)$txRow['amount'], (int)$txRow['account_id'], $orgId]);
                $toAccId = (int)($txRow['to_account_id'] ?? 0);
                if ($toAccId > 0) {
                    $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id=? AND org_id=?")->execute([(float)$txRow['amount'], $toAccId, $orgId]);
                }
            }
            $pdo->prepare("DELETE FROM fin_transactions WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Transaction deleted and balance reversed.');
            logActivity('delete', 'finance', "Deleted transaction #$id");
        }
        redirect('transactions.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filters
$fType      = $_GET['type']       ?? '';
$fAccount   = (int)($_GET['account_id'] ?? 0);
$fFrom      = $_GET['from']       ?? date('Y-m-01');
$fTo        = $_GET['to']         ?? date('Y-m-d');
$fQ         = trim($_GET['q']     ?? '');

$where  = 't.org_id = ?';
$params = [$orgId];
if ($fType) { $where .= ' AND t.type = ?'; $params[] = $fType; }
if ($fAccount) { $where .= ' AND t.account_id = ?'; $params[] = $fAccount; }
if ($fFrom) { $where .= ' AND t.date >= ?'; $params[] = $fFrom; }
if ($fTo)   { $where .= ' AND t.date <= ?'; $params[] = $fTo; }
if ($fQ)    { $where .= ' AND (t.description LIKE ? OR t.reference LIKE ?)'; $like = "%$fQ%"; array_push($params, $like, $like); }

$transactions = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, a.name AS account_name, c.name AS category_name, c.color AS category_color,
               ta.name AS to_account_name
        FROM fin_transactions t
        LEFT JOIN fin_accounts a   ON t.account_id    = a.id
        LEFT JOIN fin_accounts ta  ON t.to_account_id = ta.id
        LEFT JOIN fin_categories c ON t.category_id   = c.id
        WHERE $where
        ORDER BY t.date DESC, t.id DESC
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
} catch (Exception $e) {}

// Stat totals for filtered period
$stWhere  = "org_id=? AND date BETWEEN ? AND ?";
$stParams = [$orgId, $fFrom, $fTo];
$totalIncome   = 0; $totalExpense = 0;
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE $stWhere AND type='income'");
    $s->execute($stParams); $totalIncome = (float)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE $stWhere AND type='expense'");
    $s->execute($stParams); $totalExpense = (float)$s->fetchColumn();
} catch (Exception $e) {}
$netBalance = $totalIncome - $totalExpense;
$txCount    = count($transactions);

// Accounts and categories for dropdowns
$accounts = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM fin_accounts WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]); $accounts = $stmt->fetchAll();
} catch (Exception $e) {}

$categories = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, type, color FROM fin_categories WHERE org_id=? ORDER BY type, name");
    $stmt->execute([$orgId]); $categories = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-exchange-alt me-2" style="color:<?= $moduleColor ?>"></i>Transactions</h4>
    <p class="text-muted mb-0">Record and manage all financial transactions</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#txModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>New Transaction
  </button>
</div>

<!-- Stat Cards -->
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
      <div class="stat-icon <?= $netBalance >= 0 ? 'green-bg' : 'danger-bg' ?>"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency(abs($netBalance)) ?></div><div class="stat-label">Net <?= $netBalance >= 0 ? 'Income' : 'Loss' ?></div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-list"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $txCount ?></div><div class="stat-label">Transactions</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <option value="income"   <?= $fType==='income'   ? 'selected':'' ?>>Income</option>
          <option value="expense"  <?= $fType==='expense'  ? 'selected':'' ?>>Expense</option>
          <option value="transfer" <?= $fType==='transfer' ? 'selected':'' ?>>Transfer</option>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Account</label>
        <select name="account_id" class="form-select form-select-sm">
          <option value="">All Accounts</option>
          <?php foreach ($accounts as $acc): ?>
          <option value="<?= $acc['id'] ?>" <?= $fAccount === (int)$acc['id'] ? 'selected':'' ?>><?= e($acc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fFrom) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($fTo) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Description, ref…" value="<?= e($fQ) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="transactions.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-exchange-alt me-2" style="color:<?= $moduleColor ?>"></i>Transaction List</h6>
    <span class="badge bg-secondary"><?= count($transactions) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Account</th>
            <th>Category</th>
            <th>Description</th>
            <th>Reference</th>
            <th class="text-end">Amount</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($transactions)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No transactions found.</td></tr>
          <?php else: foreach ($transactions as $tx): ?>
          <tr>
            <td class="text-nowrap"><?= formatDate($tx['date']) ?></td>
            <td>
              <?php
              $typeCls = ['income' => 'success', 'expense' => 'danger', 'transfer' => 'info'];
              $typeIco = ['income' => 'fa-arrow-up', 'expense' => 'fa-arrow-down', 'transfer' => 'fa-exchange-alt'];
              $tc = $typeCls[$tx['type']] ?? 'secondary';
              $ti = $typeIco[$tx['type']] ?? 'fa-circle';
              ?>
              <span class="badge bg-<?= $tc ?>"><i class="fas <?= $ti ?> me-1"></i><?= ucfirst($tx['type']) ?></span>
            </td>
            <td>
              <?= e($tx['account_name'] ?? '—') ?>
              <?php if ($tx['type'] === 'transfer' && $tx['to_account_name']): ?>
              <span class="text-muted small"> → <?= e($tx['to_account_name']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($tx['category_name']): ?>
              <span class="badge" style="background:<?= e($tx['category_color'] ?? '#6c757d') ?>20;color:<?= e($tx['category_color'] ?? '#6c757d') ?>;border:1px solid <?= e($tx['category_color'] ?? '#6c757d') ?>40">
                <?= e($tx['category_name']) ?>
              </span>
              <?php else: ?>
              <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= e($tx['description'] ?? '—') ?></td>
            <td class="text-muted small"><?= e($tx['reference'] ?? '—') ?></td>
            <td class="text-end fw-semibold <?= $tx['type'] === 'income' ? 'text-success' : ($tx['type'] === 'expense' ? 'text-danger' : 'text-info') ?>">
              <?= $tx['type'] === 'expense' ? '-' : ('+') ?><?= formatCurrency((float)$tx['amount']) ?>
            </td>
            <td class="text-center text-nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($tx), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delTx(<?= $tx['id'] ?>, '<?= e($tx['description'] ?? 'this transaction') ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="txModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="txForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="txId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="txModalTitle"><i class="fas fa-plus me-2"></i>New Transaction</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
              <select name="type" id="txType" class="form-select" required onchange="toggleTransfer()">
                <option value="income">Income</option>
                <option value="expense">Expense</option>
                <option value="transfer">Transfer</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Account <span class="text-danger">*</span></label>
              <select name="account_id" id="txAccount" class="form-select" required>
                <option value="">-- Select Account --</option>
                <?php foreach ($accounts as $acc): ?>
                <option value="<?= $acc['id'] ?>"><?= e($acc['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4" id="toAccountRow" style="display:none">
              <label class="form-label fw-semibold">To Account <span class="text-danger">*</span></label>
              <select name="to_account_id" id="txToAccount" class="form-select">
                <option value="">-- Destination --</option>
                <?php foreach ($accounts as $acc): ?>
                <option value="<?= $acc['id'] ?>"><?= e($acc['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
              <input type="number" name="amount" id="txAmount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
              <input type="date" name="date" id="txDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Category</label>
              <select name="category_id" id="txCategory" class="form-select">
                <option value="">-- No Category --</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" data-type="<?= $cat['type'] ?>"><?= e($cat['name']) ?> (<?= ucfirst($cat['type']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Description</label>
              <input type="text" name="description" id="txDesc" class="form-control" placeholder="e.g. Office supplies purchase" maxlength="500">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Reference</label>
              <input type="text" name="reference" id="txRef" class="form-control" placeholder="e.g. INV-001" maxlength="100">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Transaction</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function toggleTransfer() {
  var type = document.getElementById('txType').value;
  document.getElementById('toAccountRow').style.display = type === 'transfer' ? '' : 'none';
}
function openAdd() {
  document.getElementById('txModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>New Transaction';
  document.getElementById('txId').value        = '0';
  document.getElementById('txType').value      = 'income';
  document.getElementById('txAccount').value   = '';
  document.getElementById('txToAccount').value = '';
  document.getElementById('txAmount').value    = '';
  document.getElementById('txDate').value      = new Date().toISOString().split('T')[0];
  document.getElementById('txCategory').value  = '';
  document.getElementById('txDesc').value      = '';
  document.getElementById('txRef').value       = '';
  toggleTransfer();
}
function openEdit(tx) {
  document.getElementById('txModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Transaction';
  document.getElementById('txId').value        = tx.id;
  document.getElementById('txType').value      = tx.type || 'income';
  document.getElementById('txAccount').value   = tx.account_id || '';
  document.getElementById('txToAccount').value = tx.to_account_id || '';
  document.getElementById('txAmount').value    = tx.amount || '';
  document.getElementById('txDate').value      = tx.date || '';
  document.getElementById('txCategory').value  = tx.category_id || '';
  document.getElementById('txDesc').value      = tx.description || '';
  document.getElementById('txRef').value       = tx.reference || '';
  toggleTransfer();
  new bootstrap.Modal(document.getElementById('txModal')).show();
}
function delTx(id, desc) {
  Swal.fire({
    title: 'Delete Transaction?',
    text: '"' + desc + '" — balance will be reversed automatically.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteId').value = id;
      document.getElementById('deleteForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
