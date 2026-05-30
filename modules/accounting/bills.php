<?php
// ── Accounting: Vendor Bills (Accounts Payable) ────────────────
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
        $id          = (int)($_POST['id'] ?? 0);
        $vendor      = sanitize($_POST['vendor_name'] ?? '');
        $vendorEmail = sanitize($_POST['vendor_email'] ?? '');
        $billNo      = sanitize($_POST['bill_no'] ?? '');
        $billDate    = $_POST['bill_date'] ?? date('Y-m-d');
        $dueDate     = $_POST['due_date']  ?? date('Y-m-d');
        $taxRate     = (float)($_POST['tax_rate'] ?? 0);
        $notes       = sanitize($_POST['notes'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['draft','pending','partial','paid','cancelled']) ? $_POST['status'] : 'pending';

        $lineDescs  = $_POST['line_desc']  ?? [];
        $lineQtys   = $_POST['line_qty']   ?? [];
        $linePrices = $_POST['line_price'] ?? [];

        $subtotal = 0;
        foreach ($lineQtys as $i => $qty) {
            $subtotal += (float)$qty * (float)($linePrices[$i] ?? 0);
        }
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $total     = $subtotal + $taxAmount;

        try {
            if ($id > 0) {
                $existingPaid = (float)$pdo->query("SELECT paid_amount FROM acc_bills WHERE id=$id")->fetchColumn();
                $newBalance   = $total - $existingPaid;
                $pdo->prepare("
                    UPDATE acc_bills SET vendor_name=?, vendor_email=?, bill_no=?, bill_date=?, due_date=?,
                    subtotal=?, tax_amount=?, total=?, balance=?, status=?, notes=?
                    WHERE id=? AND org_id=?
                ")->execute([$vendor, $vendorEmail, $billNo, $billDate, $dueDate,
                    $subtotal, $taxAmount, $total, max($newBalance,0), $status, $notes, $id, $orgId]);
                // Rebuild line items
                $pdo->prepare("DELETE FROM acc_bill_items WHERE bill_id=?")->execute([$id]);
                setFlash('success', 'Bill updated successfully.');
                logActivity('update', 'accounting', "Updated vendor bill #$id: $vendor");
            } else {
                if ($billNo === '') {
                    $cnt   = countRows('acc_bills', 'org_id=?', [$orgId]) + 1;
                    $billNo = 'BILL-' . date('Y') . '-' . str_pad($cnt, 4, '0', STR_PAD_LEFT);
                }
                $pdo->prepare("
                    INSERT INTO acc_bills (org_id, bill_no, vendor_name, vendor_email, bill_date, due_date,
                    subtotal, tax_amount, total, paid_amount, balance, status, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?,?)
                ")->execute([$orgId, $billNo, $vendor, $vendorEmail, $billDate, $dueDate,
                    $subtotal, $taxAmount, $total, $total, $status, $notes, $user['id']]);
                $id = (int)$pdo->lastInsertId();
                setFlash('success', "Bill $billNo created.");
                logActivity('create', 'accounting', "Created vendor bill $billNo for $vendor");
            }

            // Save line items
            $itemStmt = $pdo->prepare("INSERT INTO acc_bill_items (bill_id, description, quantity, unit_price, amount) VALUES (?,?,?,?,?)");
            foreach ($lineQtys as $i => $qty) {
                $qty   = (float)$qty;
                $price = (float)($linePrices[$i] ?? 0);
                $desc  = sanitize($lineDescs[$i] ?? '');
                if ($qty == 0 && $price == 0) continue;
                $itemStmt->execute([$id, $desc, $qty, $price, $qty * $price]);
            }
        } catch (Exception $e) {
            setFlash('danger', 'Failed to save bill.');
        }
        redirect('bills.php');
    }

    if ($action === 'mark_paid') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $bill = $pdo->prepare("SELECT total FROM acc_bills WHERE id=? AND org_id=?");
            $bill->execute([$id, $orgId]);
            $row = $bill->fetch();
            if ($row) {
                $pdo->prepare("UPDATE acc_bills SET paid_amount=?, balance=0, status='paid' WHERE id=? AND org_id=?")
                    ->execute([$row['total'], $id, $orgId]);
                setFlash('success', 'Bill marked as fully paid.');
                logActivity('update', 'accounting', "Marked bill #$id as paid");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Failed to update bill status.');
        }
        redirect('bills.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM acc_bill_items WHERE bill_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM acc_bills WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Bill deleted.');
            logActivity('delete', 'accounting', "Deleted vendor bill #$id");
        } catch (Exception $e) {
            setFlash('danger', 'Failed to delete bill.');
        }
        redirect('bills.php');
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

$bills = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM acc_bills WHERE $where ORDER BY bill_date DESC, id DESC");
    $stmt->execute($params);
    $bills = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats
$totalBilled = 0; $totalPaid = 0; $totalBalance = 0; $overdueCount = 0;
foreach ($bills as $b) {
    $totalBilled  += (float)$b['total'];
    $totalPaid    += (float)$b['paid_amount'];
    $totalBalance += (float)$b['balance'];
    if ($b['status'] === 'overdue') $overdueCount++;
}

// Expense accounts for modal
$expAccounts = [];
try {
    $stmt = $pdo->prepare("SELECT id, code, name FROM acc_accounts WHERE org_id=? AND type='expense' AND status='active' ORDER BY code, name");
    $stmt->execute([$orgId]);
    $expAccounts = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-import me-2" style="color:<?= $moduleColor ?>"></i>Vendor Bills</h4>
    <p class="text-muted mb-0">Manage purchase bills and track amounts owed to suppliers</p>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#billModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Add Bill
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-file-import"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalBilled) ?></div><div class="stat-label">Total Bills</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPaid) ?></div><div class="stat-label">Total Paid</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalBalance) ?></div><div class="stat-label">Outstanding Payable</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $overdueCount ?></div><div class="stat-label">Overdue Bills</div></div>
    </div>
  </div>
</div>

<!-- Filter Bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
      <label class="small fw-semibold mb-0">Status:</label>
      <?php foreach ([''=>'All','draft'=>'Draft','pending'=>'Pending','partial'=>'Partial','paid'=>'Paid','overdue'=>'Overdue','cancelled'=>'Cancelled'] as $v => $l): ?>
      <a href="?status=<?= $v ?>" class="btn btn-sm <?= $filterStatus === $v ? 'btn-success' : 'btn-outline-secondary' ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </form>
  </div>
</div>

<!-- Bills Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-file-import me-2 text-success"></i>Bill List</h6>
    <span class="badge bg-secondary"><?= count($bills) ?> bills</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Bill #</th>
            <th>Vendor</th>
            <th>Bill Date</th>
            <th>Due Date</th>
            <th class="text-end">Total</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Balance</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($bills)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No bills recorded yet.
          </td></tr>
          <?php else: foreach ($bills as $bill): ?>
          <tr>
            <td class="fw-semibold"><?= e($bill['bill_no'] ?? '#'.$bill['id']) ?></td>
            <td>
              <div class="fw-semibold"><?= e($bill['vendor_name']) ?></div>
              <div class="small text-muted"><?= e($bill['vendor_email'] ?? '') ?></div>
            </td>
            <td><?= formatDate($bill['bill_date']) ?></td>
            <td class="<?= $bill['status'] === 'overdue' ? 'text-danger fw-semibold' : '' ?>">
              <?= formatDate($bill['due_date']) ?>
            </td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$bill['total']) ?></td>
            <td class="text-end text-success"><?= formatCurrency((float)$bill['paid_amount']) ?></td>
            <td class="text-end <?= (float)$bill['balance'] > 0 ? 'text-danger fw-semibold' : 'text-success' ?>">
              <?= formatCurrency((float)$bill['balance']) ?>
            </td>
            <td><?= statusBadge($bill['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEditModal(<?= htmlspecialchars(json_encode($bill), ENT_QUOTES) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <?php if (!in_array($bill['status'], ['paid','cancelled'])): ?>
              <button class="btn btn-sm btn-outline-success ms-1"
                onclick="markPaid(<?= $bill['id'] ?>, '<?= e($bill['bill_no']) ?>')" title="Mark Paid">
                <i class="fas fa-check"></i>
              </button>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="deleteBill(<?= $bill['id'] ?>, '<?= e($bill['bill_no']) ?>')" title="Delete">
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

<!-- Bill Modal -->
<div class="modal fade" id="billModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST" id="billForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="billId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="billModalTitle"><i class="fas fa-file-import me-2"></i>Add Vendor Bill</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Vendor / Supplier Name <span class="text-danger">*</span></label>
              <input type="text" name="vendor_name" id="billVendor" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Vendor Email</label>
              <input type="email" name="vendor_email" id="billEmail" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Bill Number</label>
              <input type="text" name="bill_no" id="billNo" class="form-control" placeholder="Auto-generated if blank" maxlength="50">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Bill Date <span class="text-danger">*</span></label>
              <input type="date" name="bill_date" id="billDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
              <input type="date" name="due_date" id="billDue" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="billStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="draft">Draft</option>
                <option value="partial">Partial</option>
                <option value="paid">Paid</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Tax Rate (%)</label>
              <input type="number" name="tax_rate" id="billTax" class="form-control" value="0" min="0" max="100" step="0.01" oninput="calcBill()">
            </div>
            <div class="col-md-10">
              <label class="form-label fw-semibold">Notes</label>
              <input type="text" name="notes" id="billNotes" class="form-control" placeholder="Optional notes or reference">
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
                  <th style="width:12%" class="text-end">Amount</th>
                  <th style="width:3%"></th>
                </tr>
              </thead>
              <tbody id="billLines"></tbody>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-outline-success" onclick="addBillLine()">
            <i class="fas fa-plus me-1"></i>Add Item
          </button>

          <!-- Totals -->
          <div class="row justify-content-end mt-3">
            <div class="col-md-5">
              <table class="table table-sm mb-0">
                <tr><td>Subtotal</td><td class="text-end fw-semibold" id="billSubtotal">0.00</td></tr>
                <tr><td>Tax (<span id="billTaxPct">0</span>%)</td><td class="text-end" id="billTaxAmt">0.00</td></tr>
                <tr class="table-danger"><td class="fw-bold">Total Due</td><td class="text-end fw-bold" id="billTotal">0.00</td></tr>
              </table>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Bill</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Action forms -->
<form method="POST" id="paidBillForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="mark_paid">
  <input type="hidden" name="id" id="paidBillId">
</form>
<form method="POST" id="deleteBillForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteBillId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function addBillLine(desc, qty, price) {
  var tbody = document.getElementById('billLines');
  var row = document.createElement('tr');
  row.innerHTML =
    '<td><input type="text" name="line_desc[]" class="form-control form-control-sm" placeholder="Item description" value="'+(desc||'')+'" required></td>' +
    '<td><input type="number" name="line_qty[]" class="form-control form-control-sm" step="0.01" min="0" value="'+(qty||1)+'" oninput="calcBill()"></td>' +
    '<td><input type="number" name="line_price[]" class="form-control form-control-sm" step="0.01" min="0" value="'+(price||'')+'" oninput="calcBill()"></td>' +
    '<td class="text-end bill-line-amt">0.00</td>' +
    '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeBillLine(this)"><i class="fas fa-times"></i></button></td>';
  tbody.appendChild(row);
  calcBill();
}

function removeBillLine(btn) {
  var tbody = document.getElementById('billLines');
  if (tbody.rows.length <= 1) { alert('At least 1 line required.'); return; }
  btn.closest('tr').remove();
  calcBill();
}

function calcBill() {
  var qtys   = document.querySelectorAll('input[name="line_qty[]"]');
  var prices = document.querySelectorAll('input[name="line_price[]"]');
  var amts   = document.querySelectorAll('.bill-line-amt');
  var sub = 0;
  qtys.forEach(function(q, i) {
    var a = (parseFloat(q.value)||0) * (parseFloat(prices[i].value)||0);
    sub += a;
    if (amts[i]) amts[i].textContent = a.toFixed(2);
  });
  var taxRate = parseFloat(document.getElementById('billTax').value) || 0;
  var taxAmt  = sub * (taxRate / 100);
  document.getElementById('billSubtotal').textContent = sub.toFixed(2);
  document.getElementById('billTaxAmt').textContent   = taxAmt.toFixed(2);
  document.getElementById('billTotal').textContent    = (sub + taxAmt).toFixed(2);
  document.getElementById('billTaxPct').textContent   = taxRate;
}

function openAddModal() {
  document.getElementById('billModalTitle').innerHTML = '<i class="fas fa-file-import me-2"></i>Add Vendor Bill';
  document.getElementById('billId').value     = 0;
  document.getElementById('billVendor').value = '';
  document.getElementById('billEmail').value  = '';
  document.getElementById('billNo').value     = '';
  document.getElementById('billDate').value   = new Date().toISOString().split('T')[0];
  var due = new Date(); due.setDate(due.getDate()+30);
  document.getElementById('billDue').value    = due.toISOString().split('T')[0];
  document.getElementById('billStatus').value = 'pending';
  document.getElementById('billTax').value    = 0;
  document.getElementById('billNotes').value  = '';
  document.getElementById('billLines').innerHTML = '';
  addBillLine(); calcBill();
}

function openEditModal(bill) {
  document.getElementById('billModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Bill';
  document.getElementById('billId').value     = bill.id;
  document.getElementById('billVendor').value = bill.vendor_name  || '';
  document.getElementById('billEmail').value  = bill.vendor_email || '';
  document.getElementById('billNo').value     = bill.bill_no      || '';
  document.getElementById('billDate').value   = bill.bill_date    || '';
  document.getElementById('billDue').value    = bill.due_date     || '';
  document.getElementById('billStatus').value = bill.status       || 'pending';
  document.getElementById('billTax').value    = 0;
  document.getElementById('billNotes').value  = bill.notes        || '';
  document.getElementById('billLines').innerHTML = '';
  addBillLine('(existing items)', 1, parseFloat(bill.subtotal)||0);
  calcBill();
  new bootstrap.Modal(document.getElementById('billModal')).show();
}

function markPaid(id, no) {
  Swal.fire({
    title: 'Mark as Fully Paid?',
    text: 'Bill ' + no + ' will be marked as fully paid.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#1A8A4E',
    confirmButtonText: 'Yes, mark paid'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('paidBillId').value = id;
      document.getElementById('paidBillForm').submit();
    }
  });
}

function deleteBill(id, no) {
  Swal.fire({
    title: 'Delete Bill?',
    text: 'Bill ' + no + ' will be permanently deleted.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteBillId').value = id;
      document.getElementById('deleteBillForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
