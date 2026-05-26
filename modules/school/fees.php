<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user = currentUser();
$orgId = (int)$user['org_id'];

// Currency symbols helper
function getCurrencySymbol(string $curr): string {
    return [
        'KES' => 'KES ',
        'USD' => '$',
        'LRD' => 'L$'
    ][$curr] ?? $curr . ' ';
}

// ── POST Handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'invoice') {
        $studentId = (int)$_POST['student_id'];
        $feeType = sanitize($_POST['fee_type'] ?? 'tuition');
        $termId = (int)($_POST['term_id'] ?? 0) ?: null;
        $amount = (float)$_POST['amount'];
        $currency = in_array($_POST['currency'] ?? '', ['KES','USD','LRD']) ? $_POST['currency'] : 'KES';
        $dueDate = $_POST['due_date'] ?: date('Y-m-d', strtotime('+30 days'));
        $notes = sanitize($_POST['notes'] ?? '');

        // Fetch term name for compatible rendering if term_id is set
        $termName = 'Term 1';
        if ($termId) {
            $tStmt = $pdo->prepare("SELECT name FROM sch_terms WHERE id = ? AND org_id = ?");
            $tStmt->execute([$termId, $orgId]);
            if ($tr = $tStmt->fetch()) $termName = $tr['name'];
        }

        $stmt = $pdo->prepare("INSERT INTO sch_fees (org_id, student_id, fee_type, term_id, term, year, amount, paid, balance, currency, due_date, status, notes) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, 'unpaid', ?)");
        $stmt->execute([$orgId, $studentId, $feeType, $termId, $termName, (int)date('Y'), $amount, $amount, $currency, $dueDate, $notes]);

        setFlash('success', 'Fee invoice generated successfully.');
        logActivity('create', 'school', "Fee Invoice issued: $feeType ($currency $amount)");
        redirect('fees.php');
    }

    if ($action === 'pay') {
        $feeId = (int)$_POST['fee_id'];
        $payAmount = (float)$_POST['payment_amount'];
        $payCurrency = in_array($_POST['pay_currency'] ?? '', ['KES','USD','LRD']) ? $_POST['pay_currency'] : 'KES';
        $exchangeRate = max(0.0001, (float)($_POST['exchange_rate'] ?? 1.0000));
        $payMethod = in_array($_POST['payment_method'] ?? '', ['cash','mpesa','bank-transfer','card','cheque','online','other']) ? $_POST['payment_method'] : 'cash';
        $paidBy = sanitize($_POST['paid_by'] ?? '');
        $payDate = $_POST['payment_date'] ?: date('Y-m-d');
        $payNotes = sanitize($_POST['notes'] ?? '');

        // Get current fee invoice
        $stmt = $pdo->prepare("SELECT * FROM sch_fees WHERE id = ? AND org_id = ?");
        $stmt->execute([$feeId, $orgId]);
        $fee = $stmt->fetch();

        if ($fee) {
            // Convert payment amount to invoice currency if different
            $amountInInvoiceCurrency = $payAmount;
            if ($payCurrency !== $fee['currency']) {
                // If parents pay in USD for a KES invoice, exchangeRate is e.g. 130.00 (1 USD = 130 KES) -> amountInInvoiceCurrency = payAmount * 130
                // If parents pay in KES for a USD invoice, exchangeRate is e.g. 130.00 (1 USD = 130 KES) -> amountInInvoiceCurrency = payAmount / 130
                if ($payCurrency === 'USD' && $fee['currency'] === 'KES') {
                    $amountInInvoiceCurrency = $payAmount * $exchangeRate;
                } elseif ($payCurrency === 'KES' && $fee['currency'] === 'USD') {
                    $amountInInvoiceCurrency = $payAmount / $exchangeRate;
                } else {
                    // Generic conversion helper using exchange rate
                    $amountInInvoiceCurrency = $payAmount * $exchangeRate;
                }
            }

            $newPaid = (float)$fee['paid'] + $amountInInvoiceCurrency;
            $newBalance = max(0.00, (float)$fee['amount'] - $newPaid);
            
            $status = 'unpaid';
            if ($newBalance <= 0) {
                $status = 'paid';
            } elseif ($newPaid > 0) {
                $status = 'partial';
            }

            // Generate receipt number
            $receiptNo = 'REC-' . strtoupper(substr($payMethod, 0, 2)) . '-' . time();

            // Record to sch_fee_payments
            $amountKes = $payCurrency === 'KES' ? $payAmount : ($payCurrency === 'USD' ? $payAmount * 130.00 : $payAmount * 0.65);
            $payInsert = $pdo->prepare("INSERT INTO sch_fee_payments (org_id, fee_id, student_id, receipt_no, amount, currency, exchange_rate, amount_kes, payment_method, paid_by, payment_date, notes, created_by)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $payInsert->execute([$orgId, $feeId, (int)$fee['student_id'], $receiptNo, $payAmount, $payCurrency, $exchangeRate, $amountKes, $payMethod, $paidBy, $payDate, $payNotes, (int)$user['id']]);

            // Update main fee invoice
            $update = $pdo->prepare("UPDATE sch_fees SET paid = ?, balance = ?, status = ?, receipt_no = ?, payment_method = ?, paid_by = ? WHERE id = ? AND org_id = ?");
            $update->execute([$newPaid, $newBalance, $status, $receiptNo, $payMethod, $paidBy, $feeId, $orgId]);

            setFlash('success', "Payment of $payCurrency " . number_format($payAmount, 2) . " recorded. Receipt: $receiptNo");
            logActivity('update', 'school', "Recorded fee payment for Invoice #$feeId (Amt: $payAmount $payCurrency)");
        }
        redirect('fees.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        requireOrgOwnership('sch_fees', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_fee_payments WHERE fee_id=? AND org_id=?")->execute([$id, $orgId]);
        $pdo->prepare("DELETE FROM sch_fees WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Fee invoice deleted.');
        redirect('fees.php');
    }
}

// ── GET Handlers ──────────────────────────────────────────────────
$feesList = [];
try {
    $stmt = $pdo->prepare("SELECT f.*, s.first_name, s.last_name, s.admission_no, c.name AS class_name 
                           FROM sch_fees f
                           JOIN sch_students s ON f.student_id = s.id
                           LEFT JOIN sch_classes c ON s.class_id = c.id
                           WHERE f.org_id = ?
                           ORDER BY f.created_at DESC");
    $stmt->execute([$orgId]);
    $feesList = $stmt->fetchAll();
} catch (Exception $e) {}

$paymentsList = [];
try {
    $stmt = $pdo->prepare("SELECT fp.*, s.first_name, s.last_name, s.admission_no, f.fee_type 
                           FROM sch_fee_payments fp
                           JOIN sch_students s ON fp.student_id = s.id
                           JOIN sch_fees f ON fp.fee_id = f.id
                           WHERE fp.org_id = ?
                           ORDER BY fp.payment_date DESC LIMIT 50");
    $stmt->execute([$orgId]);
    $paymentsList = $stmt->fetchAll();
} catch (Exception $e) {}

$studentsList = [];
try {
    $stmt = $pdo->prepare("SELECT id, admission_no, first_name, last_name FROM sch_students WHERE org_id = ? AND status = 'active' ORDER BY first_name ASC");
    $stmt->execute([$orgId]);
    $studentsList = $stmt->fetchAll();
} catch (Exception $e) {}

$termsList = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM sch_terms WHERE org_id = ? ORDER BY start_date DESC");
    $stmt->execute([$orgId]);
    $termsList = $stmt->fetchAll();
} catch (Exception $e) {}

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-bill me-2" style="color:<?= $moduleColor ?>"></i>Fee Accounts & Billing</h4>
    <p class="text-muted mb-0">Record school fees in multi-currencies (KES, USD, LRD), issue invoices, and track payments</p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#payModal"><i class="fas fa-cash-register me-2"></i>Receive Payment</button>
    <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#invoiceModal"><i class="fas fa-file-invoice-dollar me-2"></i>Issue Invoice</button>
  </div>
</div>

<!-- Tabs for Invoices vs Receipts Ledger -->
<ul class="nav nav-pills mb-3" id="feeModuleTabs">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-invoices" type="button"><i class="fas fa-file-invoice-dollar me-2"></i>Fee Invoices</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-payments" type="button"><i class="fas fa-receipt me-2"></i>Payment Receipts Ledger</button></li>
</ul>

<div class="tab-content">
  <!-- Invoices Tab -->
  <div class="tab-pane fade show active" id="tab-invoices">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-file-invoice-dollar me-2 text-success"></i>Fee Invoices List</h6>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover data-table mb-0">
            <thead class="table-light">
              <tr>
                <th>Student Details</th>
                <th>Class</th>
                <th>Classification</th>
                <th>Currency</th>
                <th class="text-end">Invoice Amt</th>
                <th class="text-end">Paid</th>
                <th class="text-end">Balance Due</th>
                <th>Due Date</th>
                <th class="text-center">Status</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($feesList)): ?>
              <tr><td colspan="10" class="text-center text-muted py-5"><i class="fas fa-wallet fa-2x mb-2 d-block"></i>No fee invoices generated.</td></tr>
              <?php else: foreach ($feesList as $f): 
                $badges = ['paid' => 'success', 'partial' => 'warning text-dark', 'unpaid' => 'danger'];
                $bg = $badges[$f['status']] ?? 'secondary';
                $sym = getCurrencySymbol($f['currency']);
              ?>
              <tr>
                <td>
                  <div class="fw-semibold text-dark"><?= e($f['first_name'] . ' ' . $f['last_name']) ?></div>
                  <small class="text-muted">Adm No: <?= e($f['admission_no']) ?></small>
                </td>
                <td><?= e($f['class_name'] ?: 'Unassigned') ?></td>
                <td class="fw-semibold text-dark text-capitalize"><?= e(str_replace('-',' ',$f['fee_type'])) ?></td>
                <td><span class="badge bg-light text-dark border fw-bold"><?=e($f['currency'])?></span></td>
                <td class="text-end fw-bold text-dark"><?=$sym?><?= number_format($f['amount'], 2) ?></td>
                <td class="text-end text-success fw-semibold"><?=$sym?><?= number_format($f['paid'], 2) ?></td>
                <td class="text-end text-danger fw-bold"><?=$sym?><?= number_format($f['balance'], 2) ?></td>
                <td><?= formatDate($f['due_date']) ?></td>
                <td class="text-center"><span class="badge bg-<?= $bg ?>"><?= ucfirst($f['status']) ?></span></td>
                <td class="text-center">
                  <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-success" onclick="openPayment(<?= $f['id'] ?>, '<?= e($f['first_name'] . ' ' . $f['last_name']) ?>', <?= $f['balance'] ?>, '<?=e($f['currency'])?>')" title="Pay Now" <?= $f['balance'] <= 0 ? 'disabled' : '' ?>><i class="fas fa-hand-holding-usd"></i></button>
                    <button class="btn btn-outline-danger" onclick="delInvoice(<?= $f['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Payments Tab -->
  <div class="tab-pane fade" id="tab-payments">
    <div class="card">
      <div class="card-header"><h6 class="mb-0 text-dark fw-bold"><i class="fas fa-receipt me-2 text-success"></i>Transaction Receipts History</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Receipt No</th>
                <th>Student Details</th>
                <th>Fee Type</th>
                <th>Paid Amount</th>
                <th>Method</th>
                <th>Payer</th>
                <th>Payment Date</th>
                <th>Remarks</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($paymentsList)): ?>
              <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-receipt fa-2x mb-2 d-block opacity-25"></i>No payments recorded yet.</td></tr>
              <?php else: foreach($paymentsList as $p): ?>
              <tr>
                <td class="fw-bold text-dark"><?=e($p['receipt_no'])?></td>
                <td>
                  <div class="fw-semibold text-dark"><?=e($p['first_name'].' '.$p['last_name'])?></div>
                  <small class="text-muted"><?=e($p['admission_no'])?></small>
                </td>
                <td class="text-capitalize"><?=e(str_replace('-',' ',$p['fee_type']))?></td>
                <td class="fw-bold text-success"><?=getCurrencySymbol($p['currency'])?><?=number_format($p['amount'],2)?></td>
                <td class="text-uppercase"><span class="badge bg-light text-dark border"><?=e(str_replace('-',' ',$p['payment_method']))?></span></td>
                <td><?=e($p['paid_by'] ?: 'Parent')?></td>
                <td><?=formatDate($p['payment_date'])?></td>
                <td class="small text-muted"><?=e($p['notes'] ?: '—')?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="invoice">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2"></i>Issue Fee Invoice</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Select Student <span class="text-danger">*</span></label>
        <select name="student_id" class="form-select select2-enable" required style="width:100%;">
          <option value="">-- select student --</option>
          <?php foreach ($studentsList as $st): ?>
          <option value="<?= $st['id'] ?>"><?= e($st['first_name'] . ' ' . $st['last_name']) ?> (<?= e($st['admission_no']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Fee Classification <span class="text-danger">*</span></label>
        <select name="fee_type" class="form-select" required>
          <option value="tuition">Tuition Fee</option>
          <option value="hostel">Hostel Accommodation Fee</option>
          <option value="transport">Transport Bus Fee</option>
          <option value="activity">Extracurricular Activity Fee</option>
          <option value="exam">Examination Fee</option>
          <option value="library">Library Fee</option>
          <option value="uniform">School Uniform Fee</option>
          <option value="other">Other Miscellaneous Fee</option>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Academic Term <span class="text-danger">*</span></label>
        <select name="term_id" class="form-select" required>
          <?php foreach ($termsList as $t): ?>
          <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Billing Currency <span class="text-danger">*</span></label>
        <select name="currency" class="form-select" required>
          <option value="KES">KES — Kenya Shillings</option>
          <option value="USD">USD — US Dollars</option>
          <option value="LRD">LRD — Liberian Dollars</option>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Invoice Amount <span class="text-danger">*</span></label>
        <input type="number" name="amount" class="form-control" required min="1" step="0.01" placeholder="0.00">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Payment Due Date <span class="text-danger">*</span></label>
        <input type="date" name="due_date" class="form-control" required value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Description / Billing Notes</label>
        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. IB Diploma Exam fee breakdown..."></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-file-signature me-1"></i>Generate Invoice</button>
  </div>
  </form>
</div></div></div>

<!-- Pay Modal -->
<div class="modal fade" id="payModal" tabindex="-1"><div class="modal-dialog modal-md"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="pay">
  <div class="modal-header bg-success text-white">
    <h5 class="modal-title"><i class="fas fa-cash-register me-2"></i>Record Fee Payment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Select Invoice Statement <span class="text-danger">*</span></label>
        <select name="fee_id" id="payFeeId" class="form-select" required onchange="updatePayLimit(this)">
          <option value="">-- select billing invoice --</option>
          <?php foreach ($feesList as $f): if ($f['balance'] > 0): ?>
          <option value="<?= $f['id'] ?>" data-balance="<?= $f['balance'] ?>" data-currency="<?=e($f['currency'])?>">
            <?= e($f['first_name'] . ' ' . $f['last_name']) ?> - <?= ucfirst(e($f['fee_type'])) ?> (Bal: <?= getCurrencySymbol($f['currency']) ?><?= number_format($f['balance'],2) ?>)
          </option>
          <?php endif; endforeach; ?>
        </select>
      </div>
      
      <!-- Multi-currency Payment & Conversion Fields -->
      <div class="col-md-6">
        <label class="form-label fw-semibold">Payment Currency <span class="text-danger">*</span></label>
        <select name="pay_currency" id="payCurrency" class="form-select" required onchange="handleCurrencyChange()">
          <option value="KES">KES — Kenya Shillings</option>
          <option value="USD">USD — US Dollars</option>
          <option value="LRD">LRD — Liberian Dollars</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Exchange Rate to Invoice Currency</label>
        <input type="number" name="exchange_rate" id="payExchangeRate" class="form-control" step="0.0001" value="1.0000" min="0.0001">
        <small class="text-muted d-block mt-1" id="rateHelper">e.g. 1 USD = 130 KES</small>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Amount Paid <span class="text-danger">*</span></label>
        <input type="number" name="payment_amount" id="payAmt" class="form-control form-control-lg fw-bold text-success" required min="0.01" step="0.01" placeholder="0.00">
        <small class="text-muted d-block mt-1" id="payLimitHint"></small>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
        <select name="payment_method" class="form-select" required>
          <option value="cash">Cash</option>
          <option value="mpesa">M-Pesa</option>
          <option value="bank-transfer">Bank Transfer</option>
          <option value="card">Credit/Debit Card</option>
          <option value="cheque">Cheque</option>
          <option value="online">Online Payment Portal</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Paid By (Name)</label>
        <input type="text" name="paid_by" id="payPaidBy" class="form-control" placeholder="e.g. Guardian Name">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
        <input type="date" name="payment_date" class="form-control" required value="<?=date('Y-m-d')?>">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Receipt Remarks</label>
        <input type="text" name="notes" class="form-control" placeholder="Optional notes…">
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-success"><i class="fas fa-check-circle me-1"></i>Record Payment</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delFeeForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delFeeId">
</form>

<?php ob_start(); ?>
<script>
let activeInvoiceCurrency = 'KES';

function openPayment(id, name, balance, currency) {
  document.getElementById('payFeeId').value = id;
  activeInvoiceCurrency = currency || 'KES';
  document.getElementById('payCurrency').value = activeInvoiceCurrency;
  document.getElementById('payAmt').value = balance;
  document.getElementById('payLimitHint').innerHTML = 'Remaining balance for ' + name + ' is <strong>' + activeInvoiceCurrency + ' ' + balance.toLocaleString() + '</strong>';
  handleCurrencyChange();
  new bootstrap.Modal(document.getElementById('payModal')).show();
}

function updatePayLimit(select) {
  const opt = select.options[select.selectedIndex];
  if(opt && opt.value) {
    const bal = parseFloat(opt.getAttribute('data-balance'));
    activeInvoiceCurrency = opt.getAttribute('data-currency') || 'KES';
    document.getElementById('payCurrency').value = activeInvoiceCurrency;
    document.getElementById('payAmt').value = bal;
    document.getElementById('payLimitHint').innerHTML = 'Remaining balance is <strong>' + activeInvoiceCurrency + ' ' + bal.toLocaleString() + '</strong>';
    handleCurrencyChange();
  } else {
    document.getElementById('payLimitHint').innerHTML = '';
  }
}

function handleCurrencyChange() {
  const payCurr = document.getElementById('payCurrency').value;
  const rateInput = document.getElementById('payExchangeRate');
  const rateHelper = document.getElementById('rateHelper');
  
  if (payCurr === activeInvoiceCurrency) {
    rateInput.value = '1.0000';
    rateInput.setAttribute('readonly', true);
    rateHelper.innerHTML = 'Same currency payment. No exchange conversion needed.';
  } else {
    rateInput.removeAttribute('readonly');
    if (payCurr === 'USD' && activeInvoiceCurrency === 'KES') {
      rateInput.value = '130.0000';
      rateHelper.innerHTML = 'Invoice is in KES, paying in USD. Enter value of 1 USD in KES.';
    } else if (payCurr === 'KES' && activeInvoiceCurrency === 'USD') {
      rateInput.value = '130.0000';
      rateHelper.innerHTML = 'Invoice is in USD, paying in KES. Enter value of 1 USD in KES.';
    } else {
      rateInput.value = '1.0000';
      rateHelper.innerHTML = 'Cross-currency conversion. Enter exchange conversion rate.';
    }
  }
}

function delInvoice(id) {
  if (confirm('Are you sure you want to permanently delete this fee invoice? All payment transactions associated will also be removed.')) {
    document.getElementById('delFeeId').value = id;
    document.getElementById('delFeeForm').submit();
  }
}
</script>
<?php 
$extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php';
?>
