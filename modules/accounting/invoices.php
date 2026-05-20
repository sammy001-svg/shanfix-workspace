<?php
// ── Accounting: Client Invoices ────────────────────────────────
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
        $id        = (int)($_POST['id'] ?? 0);
        $customer  = sanitize($_POST['customer_name'] ?? '');
        $email     = sanitize($_POST['customer_email'] ?? '');
        $issueDate = $_POST['issue_date'] ?? date('Y-m-d');
        $dueDate   = $_POST['due_date']   ?? date('Y-m-d');
        $taxRate   = (float)($_POST['tax_rate'] ?? 16);
        $notes     = sanitize($_POST['notes'] ?? '');
        $status    = in_array($_POST['status'] ?? 'draft', ['draft','sent','paid','overdue','cancelled']) ? $_POST['status'] : 'draft';

        // Line items
        $lineDescs  = $_POST['line_desc']  ?? [];
        $lineQtys   = $_POST['line_qty']   ?? [];
        $linePrices = $_POST['line_price'] ?? [];

        $subtotal = 0;
        foreach ($lineQtys as $i => $qty) {
            $subtotal += (float)$qty * (float)($linePrices[$i] ?? 0);
        }
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $total     = $subtotal + $taxAmount;
        $paid      = $id > 0 ? (float)($pdo->query("SELECT paid FROM acc_invoices WHERE id=$id")->fetchColumn()) : 0;
        $balance   = $total - $paid;

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE acc_invoices SET customer_name=?, customer_email=?, issue_date=?, due_date=?, subtotal=?, tax_rate=?, tax_amount=?, total=?, balance=?, status=?, notes=? WHERE id=? AND org_id=?");
            $stmt->execute([$customer, $email, $issueDate, $dueDate, $subtotal, $taxRate, $taxAmount, $total, $balance, $status, $notes, $id, $orgId]);
            // Store line items as JSON in notes extension — or we just recalc from totals
            setFlash('success', 'Invoice updated.');
            logActivity('update', 'accounting', "Updated invoice #$id");
        } else {
            // Generate invoice number
            $count  = countRows('acc_invoices', 'org_id=?', [$orgId]) + 1;
            $invNo  = 'INV-' . date('Y') . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            $stmt   = $pdo->prepare("INSERT INTO acc_invoices (org_id, invoice_no, customer_name, customer_email, issue_date, due_date, subtotal, tax_rate, tax_amount, total, paid, balance, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,?)");
            $stmt->execute([$orgId, $invNo, $customer, $email, $issueDate, $dueDate, $subtotal, $taxRate, $taxAmount, $total, $balance, $status, $notes]);
            setFlash('success', "Invoice $invNo created.");
            logActivity('create', 'accounting', "Created invoice $invNo for $customer");
        }
        redirect('invoices.php');
    }

    if ($action === 'mark_paid') {
        $id      = (int)($_POST['id'] ?? 0);
        $stmt    = $pdo->prepare("SELECT total FROM acc_invoices WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $inv     = $stmt->fetch();
        if ($inv) {
            $total = (float)$inv['total'];
            $pdo->prepare("UPDATE acc_invoices SET paid=?, balance=0, status='paid' WHERE id=? AND org_id=?")->execute([$total, $id, $orgId]);
            setFlash('success', 'Invoice marked as paid.');
            logActivity('update', 'accounting', "Marked invoice #$id as paid");
        }
        redirect('invoices.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM acc_invoices WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Invoice deleted.');
        logActivity('delete', 'accounting', "Deleted invoice #$id");
        redirect('invoices.php');
    }

    if ($action === 'change_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['draft','sent','paid','overdue','cancelled']) ? $_POST['status'] : 'draft';
        $pdo->prepare("UPDATE acc_invoices SET status=? WHERE id=? AND org_id=?")->execute([$status, $id, $orgId]);
        setFlash('success', 'Status updated.');
        redirect('invoices.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filters
$filterStatus = $_GET['status'] ?? '';
$where  = 'org_id = ?';
$params = [$orgId];
if ($filterStatus) { $where .= ' AND status = ?'; $params[] = $filterStatus; }

$invoices = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM acc_invoices WHERE $where ORDER BY created_at DESC");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
} catch (Exception $e) {}

// Summary stats
$totalRevenue   = 0; $totalPaid = 0; $totalBalance = 0; $overdueCount = 0;
foreach ($invoices as $inv) {
    $totalRevenue += (float)$inv['total'];
    $totalPaid    += (float)$inv['paid'];
    $totalBalance += (float)$inv['balance'];
    if ($inv['status'] === 'overdue') $overdueCount++;
}

// Edit load
$editInvoice = null;
if (isset($_GET['edit'])) {
    $eid  = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM acc_invoices WHERE id=? AND org_id=?");
    $stmt->execute([$eid, $orgId]);
    $editInvoice = $stmt->fetch();
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Invoices</h4>
    <p class="text-muted mb-0">Create and manage client invoices</p>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#invoiceModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>New Invoice
  </button>
</div>

<!-- Stats Row -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Total Invoiced</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPaid) ?></div><div class="stat-label">Total Collected</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalBalance) ?></div><div class="stat-label">Outstanding</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $overdueCount ?></div><div class="stat-label">Overdue</div></div>
    </div>
  </div>
</div>

<!-- Filter Bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
      <label class="small fw-semibold mb-0">Filter by Status:</label>
      <?php foreach ([''=>'All', 'draft'=>'Draft', 'sent'=>'Sent', 'paid'=>'Paid', 'overdue'=>'Overdue', 'cancelled'=>'Cancelled'] as $v => $l): ?>
      <a href="?status=<?= $v ?>" class="btn btn-sm <?= $filterStatus === $v ? 'btn-success' : 'btn-outline-secondary' ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </form>
  </div>
</div>

<!-- Invoices Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-file-invoice me-2 text-success"></i>Invoice List</h6>
    <span class="badge bg-secondary"><?= count($invoices) ?> invoices</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Invoice #</th>
            <th>Customer</th>
            <th>Issue Date</th>
            <th>Due Date</th>
            <th class="text-end">Total</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Balance</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($invoices)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No invoices yet.
          </td></tr>
          <?php else: foreach ($invoices as $inv): ?>
          <tr>
            <td class="fw-semibold"><?= e($inv['invoice_no'] ?? '#'.$inv['id']) ?></td>
            <td>
              <div class="fw-semibold"><?= e($inv['customer_name'] ?? '—') ?></div>
              <div class="small text-muted"><?= e($inv['customer_email'] ?? '') ?></div>
            </td>
            <td><?= formatDate($inv['issue_date']) ?></td>
            <td class="<?= ($inv['status'] === 'overdue') ? 'text-danger fw-semibold' : '' ?>">
              <?= formatDate($inv['due_date']) ?>
            </td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$inv['total']) ?></td>
            <td class="text-end text-success"><?= formatCurrency((float)$inv['paid']) ?></td>
            <td class="text-end <?= (float)$inv['balance'] > 0 ? 'text-danger fw-semibold' : 'text-success' ?>">
              <?= formatCurrency((float)$inv['balance']) ?>
            </td>
            <td><?= statusBadge($inv['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= htmlspecialchars(json_encode($inv), ENT_QUOTES) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <?php if ($inv['status'] !== 'paid'): ?>
              <button class="btn btn-sm btn-outline-success ms-1" onclick="markPaid(<?= $inv['id'] ?>, '<?= e($inv['invoice_no']) ?>')" title="Mark Paid">
                <i class="fas fa-check"></i>
              </button>
              <?php endif; ?>
              <a href="<?= APP_URL ?>/modules/accounting/invoice-pdf.php?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary ms-1" title="Download PDF">
                <i class="fas fa-file-pdf"></i>
              </a>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteInv(<?= $inv['id'] ?>, '<?= e($inv['invoice_no']) ?>')" title="Delete">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST" id="invoiceForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="invId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="invModalTitle"><i class="fas fa-file-invoice me-2"></i>New Invoice</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
              <input type="text" name="customer_name" id="invCustomer" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Customer Email</label>
              <input type="email" name="customer_email" id="invEmail" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Issue Date <span class="text-danger">*</span></label>
              <input type="date" name="issue_date" id="invIssue" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
              <input type="date" name="due_date" id="invDue" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Tax Rate (%)</label>
              <input type="number" name="tax_rate" id="invTax" class="form-control" value="16" min="0" max="100" step="0.01" oninput="calcInvoice()">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="invStatus" class="form-select">
                <option value="draft">Draft</option>
                <option value="sent">Sent</option>
                <option value="paid">Paid</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Notes</label>
              <input type="text" name="notes" id="invNotes" class="form-control" placeholder="Optional">
            </div>
          </div>

          <!-- Line Items -->
          <h6 class="fw-semibold mb-2">Line Items</h6>
          <div class="table-responsive">
            <table class="table table-bordered mb-2">
              <thead class="table-light">
                <tr>
                  <th style="width:50%">Description</th>
                  <th style="width:15%">Qty</th>
                  <th style="width:20%">Unit Price</th>
                  <th style="width:15%" class="text-end">Amount</th>
                  <th style="width:5%"></th>
                </tr>
              </thead>
              <tbody id="invLines"></tbody>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-outline-success" onclick="addInvLine()">
            <i class="fas fa-plus me-1"></i>Add Item
          </button>

          <!-- Totals -->
          <div class="row justify-content-end mt-3">
            <div class="col-md-5">
              <table class="table table-sm mb-0">
                <tr><td>Subtotal</td><td class="text-end fw-semibold" id="invSubtotal">0.00</td></tr>
                <tr><td>Tax (<span id="invTaxPct">16</span>%)</td><td class="text-end" id="invTaxAmt">0.00</td></tr>
                <tr class="table-success"><td class="fw-bold">Total</td><td class="text-end fw-bold" id="invTotal">0.00</td></tr>
              </table>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Invoice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Mark Paid / Delete forms -->
<form method="POST" id="paidForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="mark_paid">
  <input type="hidden" name="id" id="paidId">
</form>
<form method="POST" id="deleteInvForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteInvId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function addInvLine(desc, qty, price) {
  var tbody = document.getElementById('invLines');
  var row = document.createElement('tr');
  var amt = ((parseFloat(qty)||0) * (parseFloat(price)||0)).toFixed(2);
  row.innerHTML =
    '<td><input type="text" name="line_desc[]"  class="form-control form-control-sm" placeholder="Item description" value="' + (desc||'') + '" required></td>' +
    '<td><input type="number" name="line_qty[]"   class="form-control form-control-sm" step="0.01" min="0" value="' + (qty||1) + '" oninput="calcInvoice()"></td>' +
    '<td><input type="number" name="line_price[]" class="form-control form-control-sm" step="0.01" min="0" value="' + (price||'') + '" oninput="calcInvoice()"></td>' +
    '<td class="text-end line-amt">' + amt + '</td>' +
    '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeInvLine(this)"><i class="fas fa-times"></i></button></td>';
  tbody.appendChild(row);
  calcInvoice();
}

function removeInvLine(btn) {
  var tbody = document.getElementById('invLines');
  if (tbody.rows.length <= 1) { alert('At least 1 line item required.'); return; }
  btn.closest('tr').remove();
  calcInvoice();
}

function calcInvoice() {
  var qtys   = document.querySelectorAll('input[name="line_qty[]"]');
  var prices = document.querySelectorAll('input[name="line_price[]"]');
  var amts   = document.querySelectorAll('.line-amt');
  var sub = 0;
  qtys.forEach(function(q, i) {
    var a = (parseFloat(q.value)||0) * (parseFloat(prices[i].value)||0);
    sub += a;
    if (amts[i]) amts[i].textContent = a.toFixed(2);
  });
  var taxRate = parseFloat(document.getElementById('invTax').value) || 0;
  var taxAmt  = sub * (taxRate / 100);
  var total   = sub + taxAmt;
  document.getElementById('invSubtotal').textContent = sub.toFixed(2);
  document.getElementById('invTaxAmt').textContent   = taxAmt.toFixed(2);
  document.getElementById('invTotal').textContent    = total.toFixed(2);
  document.getElementById('invTaxPct').textContent   = taxRate;
}

function openAddModal() {
  document.getElementById('invModalTitle').innerHTML = '<i class="fas fa-file-invoice me-2"></i>New Invoice';
  document.getElementById('invId').value = 0;
  document.getElementById('invCustomer').value = '';
  document.getElementById('invEmail').value = '';
  document.getElementById('invIssue').value = new Date().toISOString().split('T')[0];
  var due = new Date(); due.setDate(due.getDate()+30);
  document.getElementById('invDue').value = due.toISOString().split('T')[0];
  document.getElementById('invTax').value = 16;
  document.getElementById('invStatus').value = 'draft';
  document.getElementById('invNotes').value = '';
  document.getElementById('invLines').innerHTML = '';
  addInvLine();
  calcInvoice();
}

function openEditModal(inv) {
  document.getElementById('invModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Invoice';
  document.getElementById('invId').value       = inv.id;
  document.getElementById('invCustomer').value = inv.customer_name || '';
  document.getElementById('invEmail').value    = inv.customer_email || '';
  document.getElementById('invIssue').value    = inv.issue_date || '';
  document.getElementById('invDue').value      = inv.due_date || '';
  document.getElementById('invTax').value      = inv.tax_rate || 16;
  document.getElementById('invStatus').value   = inv.status || 'draft';
  document.getElementById('invNotes').value    = inv.notes || '';
  document.getElementById('invLines').innerHTML = '';
  // Repopulate with subtotal as single line for editing
  addInvLine('(existing items)', 1, parseFloat(inv.subtotal)||0);
  calcInvoice();
  var modal = new bootstrap.Modal(document.getElementById('invoiceModal'));
  modal.show();
}

function markPaid(id, no) {
  Swal.fire({
    title: 'Mark as Paid?',
    text: 'Invoice ' + no + ' will be marked fully paid.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#1A8A4E',
    confirmButtonText: 'Yes, mark paid'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('paidId').value = id;
      document.getElementById('paidForm').submit();
    }
  });
}

function deleteInv(id, no) {
  Swal.fire({
    title: 'Delete Invoice?',
    text: 'Invoice ' + no + ' will be permanently deleted.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteInvId').value = id;
      document.getElementById('deleteInvForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
