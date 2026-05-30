<?php
// ── Accounting: Chart of Accounts ─────────────────────────────
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

// ── Action Handling (before header) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $code   = sanitize($_POST['code'] ?? '');
        $name   = sanitize($_POST['name'] ?? '');
        $type   = in_array($_POST['type'] ?? '', ['asset','liability','equity','revenue','expense']) ? $_POST['type'] : 'asset';
        $parent = (int)($_POST['parent_id'] ?? 0);
        $desc   = sanitize($_POST['description'] ?? '');
        $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE acc_accounts SET code=?, name=?, type=?, parent_id=?, description=?, status=? WHERE id=? AND org_id=?");
            $stmt->execute([$code, $name, $type, $parent ?: null, $desc, $status, $id, $orgId]);
            setFlash('success', 'Account updated successfully.');
            logActivity('update', 'accounting', "Updated account: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO acc_accounts (org_id, code, name, type, parent_id, description, status) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $code, $name, $type, $parent ?: null, $desc, $status]);
            setFlash('success', 'Account created successfully.');
            logActivity('create', 'accounting', "Created account: $name");
        }
        redirect('accounts.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Check if used in transaction items
        $used = $pdo->prepare("SELECT COUNT(*) FROM acc_transaction_items WHERE account_id=?");
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) {
            setFlash('danger', 'Cannot delete: this account is used in transactions.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM acc_accounts WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            setFlash('success', 'Account deleted.');
            logActivity('delete', 'accounting', "Deleted account #$id");
        }
        redirect('accounts.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch all accounts for this org
$accounts = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, p.name AS parent_name
        FROM acc_accounts a
        LEFT JOIN acc_accounts p ON a.parent_id = p.id
        WHERE a.org_id = ?
        ORDER BY a.type, a.code, a.name
    ");
    $stmt->execute([$orgId]);
    $accounts = $stmt->fetchAll();
} catch (Exception $e) {}

// Totals by type
$typeTotals = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'revenue' => 0, 'expense' => 0];
foreach ($accounts as $acc) {
    $typeTotals[$acc['type']] = ($typeTotals[$acc['type']] ?? 0) + (float)$acc['balance'];
}

// Type display config
$typeConfig = [
    'asset'     => ['label' => 'Assets',       'color' => '#1A8A4E', 'bg' => 'success'],
    'liability' => ['label' => 'Liabilities',  'color' => '#e74c3c', 'bg' => 'danger'],
    'equity'    => ['label' => 'Equity',        'color' => '#0B2D4E', 'bg' => 'primary'],
    'revenue'   => ['label' => 'Revenue',       'color' => '#2980b9', 'bg' => 'info'],
    'expense'   => ['label' => 'Expenses',      'color' => '#f39c12', 'bg' => 'warning'],
];

// Edit: load record if ?edit=ID
$editAccount = null;
if (isset($_GET['edit'])) {
    $eid  = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM acc_accounts WHERE id=? AND org_id=?");
    $stmt->execute([$eid, $orgId]);
    $editAccount = $stmt->fetch();
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>Chart of Accounts</h4>
    <p class="text-muted mb-0">Manage your organisation's account structure</p>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#accountModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Add Account
  </button>
</div>

<!-- Type Summary Cards -->
<div class="row g-3 mb-4">
  <?php foreach ($typeConfig as $type => $cfg): ?>
  <div class="col-sm-6 col-xl">
    <div class="card border-0 shadow-sm">
      <div class="card-body py-3">
        <div class="d-flex align-items-center justify-content-between mb-1">
          <span class="small text-muted fw-semibold"><?= $cfg['label'] ?></span>
          <span class="badge bg-<?= $cfg['bg'] ?>"><?= count(array_filter($accounts, fn($a) => $a['type'] === $type)) ?></span>
        </div>
        <div class="fs-5 fw-bold" style="color:<?= $cfg['color'] ?>"><?= formatCurrency($typeTotals[$type]) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Accounts Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-list me-2 text-success"></i>All Accounts</h6>
    <span class="badge bg-secondary"><?= count($accounts) ?> accounts</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="accountsTable">
        <thead class="table-light">
          <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Type</th>
            <th>Parent</th>
            <th class="text-end">Balance</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($accounts)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No accounts yet. Add your first account.
          </td></tr>
          <?php else: foreach ($accounts as $acc):
            $tc = $typeConfig[$acc['type']] ?? ['label' => ucfirst($acc['type']), 'bg' => 'secondary'];
          ?>
          <tr>
            <td class="fw-semibold text-muted"><?= e($acc['code'] ?? '—') ?></td>
            <td class="fw-semibold"><?= e($acc['name']) ?></td>
            <td><span class="badge bg-<?= $tc['bg'] ?>"><?= $tc['label'] ?></span></td>
            <td class="text-muted small"><?= e($acc['parent_name'] ?? '—') ?></td>
            <td class="text-end fw-semibold <?= (float)$acc['balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= formatCurrency((float)$acc['balance']) ?>
            </td>
            <td><?= statusBadge($acc['status']) ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary"
                onclick="openEditModal(<?= htmlspecialchars(json_encode($acc), ENT_QUOTES) ?>)"
                title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="confirmDelete(<?= $acc['id'] ?>, '<?= e($acc['name']) ?>')"
                title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Account Modal -->
<div class="modal fade" id="accountModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="modalId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-list me-2"></i>Add Account</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Account Code</label>
              <input type="text" name="code" id="modalCode" class="form-control" placeholder="e.g. 1001" maxlength="30">
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Account Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="modalName" class="form-control" placeholder="e.g. Cash at Hand" required maxlength="255">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Account Type <span class="text-danger">*</span></label>
              <select name="type" id="modalType" class="form-select" required>
                <option value="">-- Select Type --</option>
                <option value="asset">Asset</option>
                <option value="liability">Liability</option>
                <option value="equity">Equity</option>
                <option value="revenue">Revenue</option>
                <option value="expense">Expense</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Parent Account</label>
              <select name="parent_id" id="modalParent" class="form-select">
                <option value="">-- None (Top Level) --</option>
                <?php foreach ($accounts as $acc): ?>
                <option value="<?= $acc['id'] ?>" data-type="<?= $acc['type'] ?>"><?= e($acc['code'] ? $acc['code'].' — ' : '') . e($acc['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" id="modalDesc" class="form-control" rows="2" placeholder="Optional description"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="modalStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Account</button>
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
function openAddModal() {
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>Add Account';
  document.getElementById('modalId').value    = 0;
  document.getElementById('modalCode').value  = '';
  document.getElementById('modalName').value  = '';
  document.getElementById('modalType').value  = '';
  document.getElementById('modalParent').value = '';
  document.getElementById('modalDesc').value  = '';
  document.getElementById('modalStatus').value = 'active';
}

function openEditModal(acc) {
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Account';
  document.getElementById('modalId').value     = acc.id;
  document.getElementById('modalCode').value   = acc.code  || '';
  document.getElementById('modalName').value   = acc.name  || '';
  document.getElementById('modalType').value   = acc.type  || '';
  document.getElementById('modalParent').value = acc.parent_id || '';
  document.getElementById('modalDesc').value   = acc.description || '';
  document.getElementById('modalStatus').value = acc.status || 'active';
  var modal = new bootstrap.Modal(document.getElementById('accountModal'));
  modal.show();
}

function confirmDelete(id, name) {
  Swal.fire({
    title: 'Delete Account?',
    text: 'Account "' + name + '" will be permanently deleted.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete',
  }).then(function(result) {
    if (result.isConfirmed) {
      document.getElementById('deleteId').value = id;
      document.getElementById('deleteForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
