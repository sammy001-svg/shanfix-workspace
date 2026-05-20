<?php
// ── Accounting: Payments Received (Accounts Receivable) ────────
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
        $invoiceId   = (int)($_POST['invoice_id'] ?? 0) ?: null;
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $amount      = (float)($_POST['amount'] ?? 0);
        $method      = sanitize($_POST['method'] ?? 'Cash');
        $reference   = sanitize($_POST['reference'] ?? '');
        $notes       = sanitize($_POST['notes'] ?? '');

        try {
            $pdo->prepare("
                INSERT INTO acc_payments (org_id, invoice_id, payment_date, amount, method, reference, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([$orgId, $invoiceId, $paymentDate, $amount, $method, $reference, $notes, $user['id']]);

            // Update linked invoice paid amount and balance
            if ($invoiceId) {
                $inv = $pdo->prepare("SELECT total, paid, balance FROM acc_invoices WHERE id=? AND org_id=?");
                $inv->execute([$invoiceId, $orgId]);
                $invRow = $inv->fetch();
                if ($invRow) {
                    $newPaid    = (float)$invRow['paid'] + $amount;
                    $newBalance = (float)$invRow['total'] - $newPaid;
                    $newBalance = max($newBalance, 0);
                    $newStatus  = $newBalance <= 0 ? 'paid' : (($newPaid > 0) ? 'sent' : 'sent');
                    $pdo->prepare("UPDATE acc_invoices SET paid=?, balance=?, status=? WHERE id=? AND org_id=?")
                        ->execute([$newPaid, $newBalance, $newStatus, $invoiceId, $orgId]);
                }
            }

            setFlash('success', 'Payment of ' . formatCurrency($amount) . ' recorded successfully.');
            logActivity('create', 'accounting', "Recorded payment " . formatCurrency($amount) . " via $method");
        } catch (Exception $e) {
            setFlash('danger', 'Failed to record payment.');
        }
        redirect('payments.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            // Reverse the invoice update before deleting
            $pay = $pdo->prepare("SELECT invoice_id, amount FROM acc_payments WHERE id=? AND org_id=?");
            $pay->execute([$id, $orgId]);
            $payRow = $pay->fetch();
            if ($payRow && $payRow['invoice_id']) {
                $pdo->prepare("
                    UPDATE acc_invoices
                    SET paid    = GREATEST(paid - ?, 0),
                        balance = LEAST(balance + ?, total),
                        status  = CASE WHEN balance + ? >= total THEN 'sent' ELSE status END
                    WHERE id=? AND org_id=?
                ")->execute([$payRow['amount'], $payRow['amount'], $payRow['amount'], $payRow['invoice_id'], $orgId]);
            }
            $pdo->prepare("DELETE FROM acc_payments WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Payment deleted and invoice balance reversed.');
            logActivity('delete', 'accounting', "Deleted payment #$id");
        } catch (Exception $e) {
            setFlash('danger', 'Failed to delete payment.');
        }
        redirect('payments.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filters
$filterFrom   = $_GET['from']   ?? date('Y-m-01');
$filterTo     = $_GET['to']     ?? date('Y-m-d');
$filterMethod = $_GET['method'] ?? '';

$where  = 'p.org_id = ? AND p.payment_date BETWEEN ? AND ?';
$params = [$orgId, $filterFrom, $filterTo];
if ($filterMethod) { $where .= ' AND p.method = ?'; $params[] = $filterMethod; }

$payments = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, i.invoice_no, i.customer_name
        FROM acc_payments p
        LEFT JOIN acc_invoices i ON p.invoice_id = i.id
        WHERE $where
        ORDER BY p.payment_date DESC, p.id DESC
    ");
    $stmt->execute($params);
    $payments = $stmt->fetchAll();
} catch (Exception $e) {}

$totalCollected = array_sum(array_column($payments, 'amount'));

// Stats
$thisMonth = 0; $countPayments = count($payments);
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM acc_payments WHERE org_id=? AND DATE_FORMAT(payment_date,'%Y-%m')=?");
    $s->execute([$orgId, date('Y-m')]);
    $thisMonth = (float)$s->fetchColumn();
} catch (Exception $e) {}

// Outstanding from invoices
$outstanding = 0;
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM acc_invoices WHERE org_id=? AND status NOT IN ('paid','cancelled')");
    $s->execute([$orgId]);
    $outstanding = (float)$s->fetchColumn();
} catch (Exception $e) {}

// Unpaid invoices for modal dropdown
$unpaidInvoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, invoice_no, customer_name, balance
        FROM acc_invoices
        WHERE org_id=? AND status NOT IN ('paid','cancelled') AND balance > 0
        ORDER BY due_date ASC
    ");
    $stmt->execute([$orgId]);
    $unpaidInvoices = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-bill-wave me-2" style="color:<?= $moduleColor ?>"></i>Payments Received</h4>
    <p class="text-muted mb-0">Track all payments collected from clients against invoices</p>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#paymentModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Record Payment
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalCollected) ?></div><div class="stat-label">Collected (Period)</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($thisMonth) ?></div><div class="stat-label">This Month</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($outstanding) ?></div><div class="stat-label">Still Outstanding</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon info-bg"><i class="fas fa-receipt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $countPayments ?></div><div class="stat-label">Transactions (Period)</div></div>
    </div>
  </div>
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
        <label class="form-label small fw-semibold mb-1">Method</label>
        <select name="method" class="form-select form-select-sm">
          <option value="">All Methods</option>
          <?php foreach (['Cash','M-Pesa','Bank Transfer','Cheque','Credit Card','Debit Card'] as $m): ?>
          <option value="<?= $m ?>" <?= $filterMethod === $m ? 'selected' : '' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="payments.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Payments Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2 text-success"></i>Payment Records</h6>
    <span class="badge bg-secondary"><?= count($payments) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Invoice</th>
            <th>Customer</th>
            <th>Method</th>
            <th>Reference</th>
            <th class="text-end">Amount</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No payments recorded in this period.
          </td></tr>
          <?php else: foreach ($payments as $pay): ?>
          <tr>
            <td><?= formatDate($pay['payment_date']) ?></td>
            <td>
              <?php if ($pay['invoice_no']): ?>
              <a href="invoices.php" class="fw-semibold text-primary"><?= e($pay['invoice_no']) ?></a>
              <?php else: ?>
              <span class="text-muted small">— standalone —</span>
              <?php endif; ?>
            </td>
            <td class="fw-semibold"><?= e($pay['customer_name'] ?? '—') ?></td>
            <td>
              <span class="badge bg-light text-dark border">
                <i class="fas fa-<?= $pay['method'] === 'M-Pesa' ? 'mobile-alt' : ($pay['method'] === 'Cash' ? 'money-bill' : 'university') ?> me-1"></i>
                <?= e($pay['method']) ?>
              </span>
            </td>
            <td class="small text-muted"><?= e($pay['reference'] ?? '—') ?></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$pay['amount']) ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-danger"
                onclick="deletePayment(<?= $pay['id'] ?>, '<?= formatCurrency((float)$pay['amount']) ?>')"
                title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($payments)): ?>
        <tfoot class="table-light">
          <tr>
            <td colspan="5" class="fw-bold text-end">Total Collected:</td>
            <td class="text-end fw-bold text-success"><?= formatCurrency($totalCollected) ?></td>
            <td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- Add Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Invoice (optional)</label>
              <select name="invoice_id" id="payInvoice" class="form-select" onchange="fillInvoiceAmount()">
                <option value="">-- Standalone payment (no invoice) --</option>
                <?php foreach ($unpaidInvoices as $inv): ?>
                <option value="<?= $inv['id'] ?>"
                  data-balance="<?= (float)$inv['balance'] ?>"
                  data-customer="<?= e($inv['customer_name']) ?>">
                  <?= e($inv['invoice_no']) ?> — <?= e($inv['customer_name']) ?>
                  (Outstanding: <?= formatCurrency((float)$inv['balance']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <?php if (empty($unpaidInvoices)): ?>
              <div class="small text-muted mt-1"><i class="fas fa-check-circle text-success me-1"></i>All invoices are fully paid.</div>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
              <input type="date" name="payment_date" id="payDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><?= CURRENCY_SYMBOL ?></span>
                <input type="number" name="amount" id="payAmount" class="form-control" step="0.01" min="0.01" placeholder="0.00" required>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
              <select name="method" class="form-select" required>
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
              <input type="text" name="reference" class="form-control" placeholder="e.g. M-Pesa transaction ID" maxlength="100">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Record Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deletePayForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deletePayId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAddModal() {
  document.getElementById('payInvoice').value = '';
  document.getElementById('payDate').value    = new Date().toISOString().split('T')[0];
  document.getElementById('payAmount').value  = '';
}

function fillInvoiceAmount() {
  var sel = document.getElementById('payInvoice');
  var opt = sel.options[sel.selectedIndex];
  var bal = opt ? opt.getAttribute('data-balance') : null;
  if (bal && parseFloat(bal) > 0) {
    document.getElementById('payAmount').value = parseFloat(bal).toFixed(2);
  }
}

function deletePayment(id, amount) {
  Swal.fire({
    title: 'Delete Payment?',
    text: 'Payment of ' + amount + ' will be deleted and the invoice balance will be reversed.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deletePayId').value = id;
      document.getElementById('deletePayForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
