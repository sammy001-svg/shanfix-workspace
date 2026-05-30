<?php
// ── Accounting: Expense Management ────────────────────────────
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

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $accId    = (int)($_POST['account_id'] ?? 0);
        $category = sanitize($_POST['category'] ?? '');
        $desc     = sanitize($_POST['description'] ?? '');
        $amount   = (float)($_POST['amount'] ?? 0);
        $date     = $_POST['date'] ?? date('Y-m-d');
        $method   = sanitize($_POST['payment_method'] ?? '');
        $ref      = sanitize($_POST['reference'] ?? '');

        // Receipt file upload
        $receiptPath = null;
        if (!empty($_FILES['receipt']['tmp_name']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['receipt']['name'] ?? '', PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp','pdf'];
            if (in_array($ext, $allowed)) {
                $dir = __DIR__ . '/../../assets/uploads/receipts/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'receipt-' . $orgId . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['receipt']['tmp_name'], $dir . $fname)) {
                    $receiptPath = 'assets/uploads/receipts/' . $fname;
                }
            }
        }

        if ($id > 0) {
            if ($receiptPath) {
                $stmt = $pdo->prepare("UPDATE acc_expenses SET account_id=?, category=?, description=?, amount=?, date=?, payment_method=?, reference=?, receipt=? WHERE id=? AND org_id=?");
                $stmt->execute([$accId ?: null, $category, $desc, $amount, $date, $method, $ref, $receiptPath, $id, $orgId]);
            } else {
                $stmt = $pdo->prepare("UPDATE acc_expenses SET account_id=?, category=?, description=?, amount=?, date=?, payment_method=?, reference=? WHERE id=? AND org_id=?");
                $stmt->execute([$accId ?: null, $category, $desc, $amount, $date, $method, $ref, $id, $orgId]);
            }
            setFlash('success', 'Expense updated.');
            logActivity('update', 'accounting', "Updated expense #$id");
        } else {
            $stmt = $pdo->prepare("INSERT INTO acc_expenses (org_id, account_id, category, description, amount, date, payment_method, reference, receipt, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $accId ?: null, $category, $desc, $amount, $date, $method, $ref, $receiptPath, $user['id']]);
            setFlash('success', 'Expense recorded.');
            logActivity('create', 'accounting', "Recorded expense: $category — " . number_format($amount, 2));
        }
        redirect('expenses.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM acc_expenses WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Expense deleted.');
        logActivity('delete', 'accounting', "Deleted expense #$id");
        redirect('expenses.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filters
$filterCat  = $_GET['category'] ?? '';
$filterFrom = $_GET['from']     ?? date('Y-m-01');
$filterTo   = $_GET['to']       ?? date('Y-m-d');

$where  = 'e.org_id = ? AND e.date BETWEEN ? AND ?';
$params = [$orgId, $filterFrom, $filterTo];
if ($filterCat) { $where .= ' AND e.category = ?'; $params[] = $filterCat; }

$expenses = [];
try {
    $stmt = $pdo->prepare("
        SELECT e.*, a.name AS account_name
        FROM acc_expenses e
        LEFT JOIN acc_accounts a ON e.account_id = a.id
        WHERE $where
        ORDER BY e.date DESC, e.id DESC
    ");
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();
} catch (Exception $e) {}

// Total and category breakdown
$totalExpenses = 0;
$catBreakdown  = [];
foreach ($expenses as $exp) {
    $totalExpenses += (float)$exp['amount'];
    $cat = $exp['category'] ?: 'Uncategorised';
    $catBreakdown[$cat] = ($catBreakdown[$cat] ?? 0) + (float)$exp['amount'];
}
arsort($catBreakdown);

// All distinct categories for filter
$categories = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT category FROM acc_expenses WHERE org_id=? AND category IS NOT NULL AND category != '' ORDER BY category");
    $stmt->execute([$orgId]);
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Expense accounts
$accounts = [];
try {
    $stmt = $pdo->prepare("SELECT id, code, name FROM acc_accounts WHERE org_id=? AND type='expense' AND status='active' ORDER BY code, name");
    $stmt->execute([$orgId]);
    $accounts = $stmt->fetchAll();
} catch (Exception $e) {}

// Common categories
$commonCats = ['Rent','Utilities','Salaries','Transport','Office Supplies','Internet','Marketing','Maintenance','Insurance','Bank Charges','Miscellaneous'];

// Edit load
$editExpense = null;
if (isset($_GET['edit'])) {
    $eid  = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM acc_expenses WHERE id=? AND org_id=?");
    $stmt->execute([$eid, $orgId]);
    $editExpense = $stmt->fetch();
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-receipt me-2" style="color:<?= $moduleColor ?>"></i>Expense Management</h4>
    <p class="text-muted mb-0">Track and manage all business expenses</p>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#expenseModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Record Expense
  </button>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalExpenses) ?></div>
        <div class="stat-label">Total Expenses</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-receipt"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= count($expenses) ?></div>
        <div class="stat-label">Total Records</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-tags"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= count($catBreakdown) ?></div>
        <div class="stat-label">Categories</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency(count($expenses) > 0 ? $totalExpenses / count($expenses) : 0) ?></div>
        <div class="stat-label">Avg per Record</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Category Breakdown -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2 text-success"></i>By Category</h6></div>
      <div class="card-body p-0">
        <?php if (empty($catBreakdown)): ?>
        <div class="text-center text-muted py-4">No data</div>
        <?php else: foreach ($catBreakdown as $cat => $amt): $pct = $totalExpenses > 0 ? round($amt/$totalExpenses*100) : 0; ?>
        <div class="px-3 py-2 border-bottom">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold"><?= e($cat) ?></span>
            <span class="small text-muted"><?= formatCurrency($amt) ?> (<?= $pct ?>%)</span>
          </div>
          <div class="progress" style="height:5px">
            <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Filters + Table -->
  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
          <div class="col-sm-4">
            <label class="form-label small fw-semibold mb-1">From</label>
            <input type="date" name="from" class="form-control form-control-sm" value="<?= e($filterFrom) ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label small fw-semibold mb-1">To</label>
            <input type="date" name="to" class="form-control form-control-sm" value="<?= e($filterTo) ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label small fw-semibold mb-1">Category</label>
            <select name="category" class="form-select form-select-sm">
              <option value="">All Categories</option>
              <?php foreach ($categories as $cat): ?>
              <option value="<?= e($cat) ?>" <?= $filterCat === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 text-end">
            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
            <a href="expenses.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Expenses Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-receipt me-2 text-success"></i>Expense Records</h6>
    <span class="badge bg-secondary"><?= count($expenses) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Category</th>
            <th>Description</th>
            <th class="text-end">Amount</th>
            <th>Method</th>
            <th>Account</th>
            <th class="text-center">Receipt</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($expenses)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No expenses found.
          </td></tr>
          <?php else: foreach ($expenses as $exp): ?>
          <tr>
            <td><?= formatDate($exp['date']) ?></td>
            <td><span class="badge bg-warning text-dark"><?= e($exp['category'] ?? '—') ?></span></td>
            <td class="small text-muted"><?= e(mb_substr($exp['description'] ?? '—', 0, 60)) ?></td>
            <td class="text-end fw-semibold text-danger"><?= formatCurrency((float)$exp['amount']) ?></td>
            <td class="small"><?= e($exp['payment_method'] ?? '—') ?></td>
            <td class="small text-muted"><?= e($exp['account_name'] ?? '—') ?></td>
            <td class="text-center">
              <?php if (!empty($exp['receipt'])): ?>
                <a href="<?= APP_URL ?>/<?= e($exp['receipt']) ?>" target="_blank" class="btn btn-sm btn-outline-success" title="View Receipt">
                  <i class="fas <?= str_ends_with($exp['receipt'], '.pdf') ? 'fa-file-pdf' : 'fa-image' ?>"></i>
                </a>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= htmlspecialchars(json_encode($exp), ENT_QUOTES) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteExp(<?= $exp['id'] ?>)" title="Delete">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($expenses)): ?>
        <tfoot class="table-light">
          <tr>
            <td colspan="3" class="fw-bold text-end">Total:</td>
            <td class="text-end fw-bold text-danger"><?= formatCurrency($totalExpenses) ?></td>
            <td colspan="4"></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="expId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="expModalTitle"><i class="fas fa-receipt me-2"></i>Record Expense</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Account (Expense)</label>
              <select name="account_id" id="expAccount" class="form-select">
                <option value="">-- No specific account --</option>
                <?php foreach ($accounts as $acc): ?>
                <option value="<?= $acc['id'] ?>"><?= e(($acc['code'] ? $acc['code'].' — ' : '') . $acc['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
              <input type="text" name="category" id="expCategory" class="form-control" list="catList" required maxlength="100">
              <datalist id="catList">
                <?php foreach ($commonCats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
              <textarea name="description" id="expDesc" class="form-control" rows="2" required></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Amount (<?= CURRENCY_SYMBOL ?>) <span class="text-danger">*</span></label>
              <input type="number" name="amount" id="expAmount" class="form-control" step="0.01" min="0" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
              <input type="date" name="date" id="expDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Payment Method</label>
              <select name="payment_method" id="expMethod" class="form-select">
                <option value="">-- Select --</option>
                <option value="Cash">Cash</option>
                <option value="M-Pesa">M-Pesa</option>
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="Cheque">Cheque</option>
                <option value="Credit Card">Credit Card</option>
                <option value="Debit Card">Debit Card</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Reference / Receipt #</label>
              <input type="text" name="reference" id="expRef" class="form-control" placeholder="e.g. receipt number" maxlength="100">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Receipt / Attachment</label>
              <input type="file" name="receipt" id="expReceipt" class="form-control" accept="image/*,.pdf">
              <div class="form-text">JPG, PNG, PDF · Max 5 MB. Leave blank to keep existing.</div>
              <div id="existingReceipt" class="mt-1" style="display:none">
                <a href="#" id="existingReceiptLink" target="_blank" class="small text-success">
                  <i class="fas fa-paperclip me-1"></i><span id="existingReceiptName">View current receipt</span>
                </a>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteExpForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteExpId">
</form>

<?php
$extraJs = <<<'JS'
<script>
const APP_URL = <?= json_encode(APP_URL) ?>;

function clearReceiptField() {
  document.getElementById('expReceipt').value = '';
  document.getElementById('existingReceipt').style.display = 'none';
  document.getElementById('existingReceiptLink').href = '#';
  document.getElementById('existingReceiptName').textContent = 'View current receipt';
}

function openAddModal() {
  document.getElementById('expModalTitle').innerHTML = '<i class="fas fa-receipt me-2"></i>Record Expense';
  document.getElementById('expId').value = 0;
  document.getElementById('expAccount').value  = '';
  document.getElementById('expCategory').value = '';
  document.getElementById('expDesc').value     = '';
  document.getElementById('expAmount').value   = '';
  document.getElementById('expDate').value     = new Date().toISOString().split('T')[0];
  document.getElementById('expMethod').value   = '';
  document.getElementById('expRef').value      = '';
  clearReceiptField();
}

function openEditModal(exp) {
  document.getElementById('expModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Expense';
  document.getElementById('expId').value       = exp.id;
  document.getElementById('expAccount').value  = exp.account_id  || '';
  document.getElementById('expCategory').value = exp.category    || '';
  document.getElementById('expDesc').value     = exp.description || '';
  document.getElementById('expAmount').value   = exp.amount      || '';
  document.getElementById('expDate').value     = exp.date        || '';
  document.getElementById('expMethod').value   = exp.payment_method || '';
  document.getElementById('expRef').value      = exp.reference   || '';
  clearReceiptField();
  if (exp.receipt) {
    const link = document.getElementById('existingReceiptLink');
    link.href  = APP_URL + '/' + exp.receipt;
    const isPdf = exp.receipt.endsWith('.pdf');
    document.getElementById('existingReceiptName').textContent = isPdf ? 'View current PDF receipt' : 'View current image receipt';
    document.getElementById('existingReceipt').style.display = 'block';
  }
  var modal = new bootstrap.Modal(document.getElementById('expenseModal'));
  modal.show();
}

function deleteExp(id) {
  Swal.fire({
    title: 'Delete Expense?',
    text: 'This expense record will be permanently deleted.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteExpId').value = id;
      document.getElementById('deleteExpForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
