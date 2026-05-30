<?php
// ── Accounting: Journal Entries / Transactions ─────────────────
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
        $ref   = sanitize($_POST['reference'] ?? '');
        $date  = $_POST['date'] ?? date('Y-m-d');
        $desc  = sanitize($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? 'posted', ['draft','posted']) ? $_POST['status'] : 'posted';

        $accounts  = $_POST['account_id']    ?? [];
        $debits    = $_POST['line_debit']    ?? [];
        $credits   = $_POST['line_credit']   ?? [];
        $lineDescs = $_POST['line_desc']     ?? [];

        $totalDebit  = array_sum(array_map('floatval', $debits));
        $totalCredit = array_sum(array_map('floatval', $credits));

        if (abs($totalDebit - $totalCredit) > 0.005) {
            setFlash('danger', 'Transaction not balanced: Debit (' . number_format($totalDebit, 2) . ') ≠ Credit (' . number_format($totalCredit, 2) . ').');
            redirect('transactions.php?action=add');
        }

        if (count($accounts) < 2) {
            setFlash('danger', 'At least 2 line items are required.');
            redirect('transactions.php?action=add');
        }

        // Auto-generate reference if blank
        if ($ref === '') {
            $ref = 'JNL-' . strtoupper(substr(uniqid(), -6));
        }

        $stmt = $pdo->prepare("INSERT INTO acc_transactions (org_id, reference, date, description, total_debit, total_credit, status, created_by) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$orgId, $ref, $date, $desc, $totalDebit, $totalCredit, $status, $user['id']]);
        $txId = (int)$pdo->lastInsertId();

        // Pre-validate all submitted account IDs belong to this org (prevents cross-tenant balance corruption)
        $validAccIds = [];
        if (!empty($accounts)) {
            $placeholders = implode(',', array_fill(0, count($accounts), '?'));
            $chk = $pdo->prepare("SELECT id FROM acc_accounts WHERE id IN ($placeholders) AND org_id=?");
            $chk->execute([...array_map('intval', $accounts), $orgId]);
            $validAccIds = array_column($chk->fetchAll(), 'id');
        }

        $lineStmt = $pdo->prepare("INSERT INTO acc_transaction_items (transaction_id, account_id, description, debit, credit) VALUES (?,?,?,?,?)");
        foreach ($accounts as $i => $accId) {
            $accId  = (int)$accId;
            $debit  = (float)($debits[$i]  ?? 0);
            $credit = (float)($credits[$i] ?? 0);
            if ($accId < 1 && $debit == 0 && $credit == 0) continue;
            // Skip accounts not belonging to this org
            if (!in_array($accId, $validAccIds)) continue;
            $lineStmt->execute([$txId, $accId, sanitize($lineDescs[$i] ?? ''), $debit, $credit]);
            $pdo->prepare("UPDATE acc_accounts SET balance = balance + ? - ? WHERE id=? AND org_id=?")->execute([$debit, $credit, $accId, $orgId]);
        }

        setFlash('success', "Transaction $ref recorded successfully.");
        logActivity('create', 'accounting', "Journal entry: $ref");
        redirect('transactions.php');
    }

    if ($action === 'void') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE acc_transactions SET status='voided' WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Transaction voided.');
        logActivity('void', 'accounting', "Voided transaction #$id");
        redirect('transactions.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filters
$filterFrom   = $_GET['from']   ?? date('Y-m-01');
$filterTo     = $_GET['to']     ?? date('Y-m-d');
$filterStatus = $_GET['status'] ?? '';

$where  = 'org_id = ? AND date BETWEEN ? AND ?';
$params = [$orgId, $filterFrom, $filterTo];
if ($filterStatus) {
    $where   .= ' AND status = ?';
    $params[] = $filterStatus;
}

$transactions = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM acc_transactions WHERE $where ORDER BY date DESC, id DESC");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
} catch (Exception $e) {}

// Accounts for modal
$accounts = [];
try {
    $stmt = $pdo->prepare("SELECT id, code, name, type FROM acc_accounts WHERE org_id=? AND status='active' ORDER BY type, code, name");
    $stmt->execute([$orgId]);
    $accounts = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-exchange-alt me-2" style="color:<?= $moduleColor ?>"></i>Journal Entries</h4>
    <p class="text-muted mb-0">Record and manage double-entry transactions</p>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#txModal">
    <i class="fas fa-plus me-2"></i>New Journal Entry
  </button>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($filterFrom) ?>">
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($filterTo) ?>">
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="draft"  <?= $filterStatus==='draft'  ? 'selected':'' ?>>Draft</option>
          <option value="posted" <?= $filterStatus==='posted' ? 'selected':'' ?>>Posted</option>
          <option value="voided" <?= $filterStatus==='voided' ? 'selected':'' ?>>Voided</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="transactions.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Transactions Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-exchange-alt me-2 text-success"></i>Transactions</h6>
    <span class="badge bg-secondary"><?= count($transactions) ?> entries</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="txTable">
        <thead class="table-light">
          <tr>
            <th>Reference</th>
            <th>Date</th>
            <th>Description</th>
            <th class="text-end">Debit</th>
            <th class="text-end">Credit</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($transactions)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No transactions in this period.
          </td></tr>
          <?php else: foreach ($transactions as $tx): ?>
          <tr class="<?= $tx['status'] === 'voided' ? 'table-secondary text-muted' : '' ?>">
            <td class="fw-semibold"><?= e($tx['reference'] ?? '—') ?></td>
            <td><?= formatDate($tx['date']) ?></td>
            <td class="text-muted small"><?= e(mb_substr($tx['description'] ?? '—', 0, 60)) ?></td>
            <td class="text-end text-success fw-semibold"><?= formatCurrency((float)$tx['total_debit']) ?></td>
            <td class="text-end text-danger fw-semibold"><?= formatCurrency((float)$tx['total_credit']) ?></td>
            <td><?= statusBadge($tx['status']) ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-info" onclick="viewLines(<?= $tx['id'] ?>)" title="View Lines">
                <i class="fas fa-eye"></i>
              </button>
              <?php if ($tx['status'] !== 'voided'): ?>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="voidTx(<?= $tx['id'] ?>, '<?= e($tx['reference']) ?>')" title="Void">
                <i class="fas fa-ban"></i>
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- New Journal Entry Modal -->
<div class="modal fade" id="txModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST" id="txForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>New Journal Entry</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Reference</label>
              <input type="text" name="reference" class="form-control" placeholder="Auto-generated if blank" maxlength="100">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
              <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" class="form-select">
                <option value="posted">Posted</option>
                <option value="draft">Draft</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
              <input type="text" name="description" class="form-control" placeholder="Brief description of this entry" required>
            </div>
          </div>

          <!-- Line Items -->
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="mb-0 fw-semibold">Line Items</h6>
            <div>
              <span class="badge bg-success me-2">Debit: <span id="totalDebit">0.00</span></span>
              <span class="badge bg-danger me-2">Credit: <span id="totalCredit">0.00</span></span>
              <span class="badge" id="balanceBadge" style="background:#6c757d">Balanced</span>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-bordered mb-2" id="lineTable">
              <thead class="table-light">
                <tr>
                  <th style="width:35%">Account</th>
                  <th style="width:25%">Description</th>
                  <th style="width:15%">Debit</th>
                  <th style="width:15%">Credit</th>
                  <th style="width:10%" class="text-center">Remove</th>
                </tr>
              </thead>
              <tbody id="lineBody">
                <!-- rows inserted by JS -->
              </tbody>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-outline-success" onclick="addLine()">
            <i class="fas fa-plus me-1"></i>Add Line
          </button>
          <small class="text-muted ms-2">Minimum 2 lines. Total debits must equal total credits.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success" onclick="return validateBalance()">
            <i class="fas fa-save me-1"></i>Post Entry
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View Lines Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-list me-2"></i>Transaction Lines</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="viewBody"><div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div></div>
    </div>
  </div>
</div>

<!-- Void Form -->
<form method="POST" id="voidForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="void">
  <input type="hidden" name="id" id="voidId">
</form>

<?php
$accountsJson = json_encode(array_map(fn($a) => [
    'id'   => $a['id'],
    'label'=> ($a['code'] ? $a['code'].' — ' : '') . $a['name'],
    'type' => $a['type'],
], $accounts));
$extraJs = <<<JS
<script>
var accountList = $accountsJson;

function buildAccountOptions(selected) {
  var opts = '<option value="">-- Select Account --</option>';
  accountList.forEach(function(a) {
    opts += '<option value="' + a.id + '" ' + (selected == a.id ? 'selected' : '') + '>' + a.label + '</option>';
  });
  return opts;
}

function addLine(acct, debit, credit, desc) {
  var tbody = document.getElementById('lineBody');
  var row = document.createElement('tr');
  row.innerHTML =
    '<td><select name="account_id[]" class="form-select form-select-sm line-acct" required>' + buildAccountOptions(acct || '') + '</select></td>' +
    '<td><input type="text" name="line_desc[]" class="form-control form-control-sm" placeholder="Optional" value="' + (desc||'') + '"></td>' +
    '<td><input type="number" name="line_debit[]"  class="form-control form-control-sm line-num" step="0.01" min="0" value="' + (debit||'0') + '" oninput="calcTotals()"></td>' +
    '<td><input type="number" name="line_credit[]" class="form-control form-control-sm line-num" step="0.01" min="0" value="' + (credit||'0') + '" oninput="calcTotals()"></td>' +
    '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLine(this)" title="Remove"><i class="fas fa-times"></i></button></td>';
  tbody.appendChild(row);
  calcTotals();
}

function removeLine(btn) {
  var tbody = document.getElementById('lineBody');
  if (tbody.rows.length <= 2) { alert('Minimum 2 lines required.'); return; }
  btn.closest('tr').remove();
  calcTotals();
}

function calcTotals() {
  var debits  = document.querySelectorAll('input[name="line_debit[]"]');
  var credits = document.querySelectorAll('input[name="line_credit[]"]');
  var td = 0, tc = 0;
  debits.forEach(function(i)  { td += parseFloat(i.value||0); });
  credits.forEach(function(i) { tc += parseFloat(i.value||0); });
  document.getElementById('totalDebit').textContent  = td.toFixed(2);
  document.getElementById('totalCredit').textContent = tc.toFixed(2);
  var badge = document.getElementById('balanceBadge');
  var balanced = Math.abs(td - tc) < 0.005;
  badge.textContent = balanced ? 'Balanced ✓' : 'NOT BALANCED!';
  badge.style.background = balanced ? '#1A8A4E' : '#e74c3c';
}

function validateBalance() {
  var td = parseFloat(document.getElementById('totalDebit').textContent);
  var tc = parseFloat(document.getElementById('totalCredit').textContent);
  if (Math.abs(td - tc) > 0.005) {
    Swal.fire('Not Balanced!', 'Total Debit must equal Total Credit before posting.', 'error');
    return false;
  }
  var lines = document.querySelectorAll('#lineBody tr');
  if (lines.length < 2) {
    Swal.fire('Too few lines', 'At least 2 line items are required.', 'error');
    return false;
  }
  return true;
}

function viewLines(txId) {
  document.getElementById('viewBody').innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
  var modal = new bootstrap.Modal(document.getElementById('viewModal'));
  modal.show();
  fetch('ajax/get_tx_lines.php?id=' + txId)
    .then(function(r) { return r.text(); })
    .then(function(html) { document.getElementById('viewBody').innerHTML = html; })
    .catch(function() { document.getElementById('viewBody').innerHTML = '<p class="text-danger p-3">Failed to load lines.</p>'; });
}

function voidTx(id, ref) {
  Swal.fire({
    title: 'Void Transaction?',
    text: 'Reference "' + ref + '" will be marked as voided. This cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, void it',
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('voidId').value = id;
      document.getElementById('voidForm').submit();
    }
  });
}

// Pre-populate 2 blank lines when modal opens
document.getElementById('txModal').addEventListener('show.bs.modal', function() {
  document.getElementById('lineBody').innerHTML = '';
  addLine(); addLine();
  calcTotals();
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
