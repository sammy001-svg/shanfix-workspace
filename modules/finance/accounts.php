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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $name      = sanitize($_POST['name'] ?? '');
        $type      = in_array($_POST['type'] ?? '', ['bank','cash','mobile_money','investment']) ? $_POST['type'] : 'bank';
        $accountNo = sanitize($_POST['account_no'] ?? '');
        $balance   = (float)($_POST['balance'] ?? 0);
        $currency  = sanitize($_POST['currency'] ?? 'KES');
        $status    = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if (empty($name)) {
            setFlash('danger', 'Account name is required.');
            redirect('accounts.php');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE fin_accounts SET name=?, type=?, account_no=?, currency=?, status=? WHERE id=? AND org_id=?");
            $stmt->execute([$name, $type, $accountNo, $currency, $status, $id, $orgId]);
            setFlash('success', 'Account updated successfully.');
            logActivity('update', 'finance', "Updated account: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO fin_accounts (org_id, name, type, account_no, balance, currency, status) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $name, $type, $accountNo, $balance, $currency, $status]);
            setFlash('success', 'Account created successfully.');
            logActivity('create', 'finance', "Created account: $name");
        }
        redirect('accounts.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = countRows('fin_transactions', 'account_id = ?', [$id]);
        if ($used > 0) {
            setFlash('danger', 'Cannot delete: this account has linked transactions.');
        } else {
            $pdo->prepare("DELETE FROM fin_accounts WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Account deleted.');
            logActivity('delete', 'finance', "Deleted account #$id");
        }
        redirect('accounts.php');
    }

    if ($action === 'quick_tx') {
        $accountId  = (int)($_POST['account_id'] ?? 0);
        $txType     = in_array($_POST['tx_type'] ?? '', ['income','expense']) ? $_POST['tx_type'] : 'income';
        $amount     = (float)($_POST['amount'] ?? 0);
        $desc       = sanitize($_POST['description'] ?? '');
        if ($amount > 0 && $accountId > 0) {
            $pdo->prepare("INSERT INTO fin_transactions (org_id, account_id, type, amount, description, date, created_by) VALUES (?,?,?,?,?,CURDATE(),?)")
                ->execute([$orgId, $accountId, $txType, $amount, $desc, $user['id']]);
            if ($txType === 'income') {
                $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id=? AND org_id=?")->execute([$amount, $accountId, $orgId]);
            } else {
                $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id=? AND org_id=?")->execute([$amount, $accountId, $orgId]);
            }
            setFlash('success', 'Transaction added successfully.');
            logActivity('create', 'finance', "Quick transaction on account #$accountId");
        } else {
            setFlash('danger', 'Invalid amount or account.');
        }
        redirect('accounts.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$accounts = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM fin_accounts WHERE org_id=? ORDER BY type, name");
    $stmt->execute([$orgId]);
    $accounts = $stmt->fetchAll();
} catch (Exception $e) {}

$totalActive = 0;
$totalAll    = 0;
foreach ($accounts as $acc) {
    $totalAll += (float)$acc['balance'];
    if ($acc['status'] === 'active') $totalActive += (float)$acc['balance'];
}

$typeIcons = [
    'bank'         => 'fas fa-university',
    'cash'         => 'fas fa-money-bill-wave',
    'mobile_money' => 'fas fa-mobile-alt',
    'investment'   => 'fas fa-chart-line',
];
$typeColors = [
    'bank'         => '#2980b9',
    'cash'         => '#27ae60',
    'mobile_money' => '#8e44ad',
    'investment'   => '#e67e22',
];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-university me-2" style="color:<?= $moduleColor ?>"></i>Accounts</h4>
    <p class="text-muted mb-0">Manage your bank, cash, and investment accounts</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#accountModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Account
  </button>
</div>

<!-- Total Balance Summary Bar -->
<div class="card mb-4 border-0 shadow-sm" style="background:linear-gradient(135deg,<?= $moduleColor ?>,#1abc9c)">
  <div class="card-body py-3 text-white">
    <div class="row align-items-center">
      <div class="col-md-4 text-center border-end border-white border-opacity-25">
        <div class="fs-2 fw-bold"><?= formatCurrency($totalActive) ?></div>
        <div class="small opacity-75">Total Active Balance</div>
      </div>
      <div class="col-md-4 text-center border-end border-white border-opacity-25">
        <div class="fs-4 fw-semibold"><?= count(array_filter($accounts, fn($a) => $a['status'] === 'active')) ?></div>
        <div class="small opacity-75">Active Accounts</div>
      </div>
      <div class="col-md-4 text-center">
        <div class="fs-4 fw-semibold"><?= count($accounts) ?></div>
        <div class="small opacity-75">Total Accounts</div>
      </div>
    </div>
  </div>
</div>

<?php if (empty($accounts)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
  <i class="fas fa-university fa-3x mb-3 d-block opacity-25"></i>
  <h5>No accounts yet</h5>
  <p>Create your first account to start tracking finances.</p>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#accountModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add First Account
  </button>
</div></div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($accounts as $acc):
    $icon  = $typeIcons[$acc['type']] ?? 'fas fa-landmark';
    $tColor = $typeColors[$acc['type']] ?? $moduleColor;
    $bal   = (float)$acc['balance'];
  ?>
  <div class="col-sm-6 col-xl-4">
    <div class="card shadow-sm h-100 <?= $acc['status'] === 'inactive' ? 'opacity-60' : '' ?>">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between mb-3">
          <div class="d-flex align-items-center gap-2">
            <div style="width:42px;height:42px;border-radius:10px;background:<?= $tColor ?>22;color:<?= $tColor ?>;display:flex;align-items:center;justify-content:center;font-size:1.1rem">
              <i class="<?= $icon ?>"></i>
            </div>
            <div>
              <div class="fw-semibold"><?= e($acc['name']) ?></div>
              <div class="small text-muted"><?= ucwords(str_replace('_',' ',$acc['type'])) ?></div>
            </div>
          </div>
          <?= statusBadge($acc['status']) ?>
        </div>
        <div class="mb-1">
          <div class="fs-4 fw-bold <?= $bal >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatCurrency($bal) ?></div>
          <div class="small text-muted"><?= e($acc['currency'] ?? 'KES') ?><?= $acc['account_no'] ? ' · ' . e($acc['account_no']) : '' ?></div>
        </div>
        <hr class="my-2">
        <!-- Quick Credit/Debit -->
        <form method="POST" class="mb-2">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="quick_tx">
          <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
          <div class="row g-1 mb-1">
            <div class="col-5">
              <select name="tx_type" class="form-select form-select-sm">
                <option value="income">Credit (+)</option>
                <option value="expense">Debit (-)</option>
              </select>
            </div>
            <div class="col-7">
              <input type="number" name="amount" class="form-control form-control-sm" placeholder="Amount" min="0.01" step="0.01" required>
            </div>
          </div>
          <div class="row g-1">
            <div class="col-8">
              <input type="text" name="description" class="form-control form-control-sm" placeholder="Description">
            </div>
            <div class="col-4">
              <button type="submit" class="btn btn-sm w-100" style="background:<?= $moduleColor ?>;color:#fff">Go</button>
            </div>
          </div>
        </form>
        <div class="d-flex gap-1 mt-2">
          <button class="btn btn-sm btn-outline-primary flex-fill" onclick='openEdit(<?= htmlspecialchars(json_encode($acc), ENT_QUOTES) ?>)'>
            <i class="fas fa-edit me-1"></i>Edit
          </button>
          <button class="btn btn-sm btn-outline-secondary flex-fill" onclick="window.location='transactions.php?account_id=<?= $acc['id'] ?>'">
            <i class="fas fa-list me-1"></i>Txns
          </button>
          <button class="btn btn-sm btn-outline-danger" onclick="delAccount(<?= $acc['id'] ?>, '<?= e($acc['name']) ?>')">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add/Edit Modal -->
<div class="modal fade" id="accountModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="accountForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="accId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="accModalTitle"><i class="fas fa-university me-2"></i>Add Account</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Account Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="accName" class="form-control" placeholder="e.g. KCB Business Account" required maxlength="255">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
              <select name="type" id="accType" class="form-select" required>
                <option value="bank">Bank</option>
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="investment">Investment</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Account Number</label>
              <input type="text" name="account_no" id="accNo" class="form-control" placeholder="e.g. 1234567890" maxlength="100">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Currency</label>
              <select name="currency" id="accCurrency" class="form-select">
                <option value="KES">KES</option>
                <option value="USD">USD</option>
                <option value="EUR">EUR</option>
                <option value="GBP">GBP</option>
                <option value="UGX">UGX</option>
                <option value="TZS">TZS</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="accStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-6" id="balanceRow">
              <label class="form-label fw-semibold">Opening Balance</label>
              <input type="number" name="balance" id="accBalance" class="form-control" placeholder="0.00" step="0.01" min="0" value="0">
              <div class="form-text">Only set for new accounts. Balance updates via transactions.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Account</button>
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
function openAdd() {
  document.getElementById('accModalTitle').innerHTML = '<i class="fas fa-university me-2"></i>Add Account';
  document.getElementById('accId').value       = '0';
  document.getElementById('accName').value     = '';
  document.getElementById('accType').value     = 'bank';
  document.getElementById('accNo').value       = '';
  document.getElementById('accBalance').value  = '0';
  document.getElementById('accCurrency').value = 'KES';
  document.getElementById('accStatus').value   = 'active';
  document.getElementById('balanceRow').style.display = '';
}
function openEdit(acc) {
  document.getElementById('accModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Account';
  document.getElementById('accId').value       = acc.id;
  document.getElementById('accName').value     = acc.name || '';
  document.getElementById('accType').value     = acc.type || 'bank';
  document.getElementById('accNo').value       = acc.account_no || '';
  document.getElementById('accBalance').value  = acc.balance || '0';
  document.getElementById('accCurrency').value = acc.currency || 'KES';
  document.getElementById('accStatus').value   = acc.status || 'active';
  document.getElementById('balanceRow').style.display = 'none';
  new bootstrap.Modal(document.getElementById('accountModal')).show();
}
function delAccount(id, name) {
  Swal.fire({
    title: 'Delete Account?',
    text: '"' + name + '" will be permanently deleted. This cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete it'
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
