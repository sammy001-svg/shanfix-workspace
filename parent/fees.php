<?php
$pageTitle = 'Fees & Payments';
require_once __DIR__ . '/../includes/header-parent.php';

// ── Fee invoices ───────────────────────────────────────────────
$invoices = [];
try {
    $s = $pdo->prepare(
        "SELECT f.*, t.name AS term_name
         FROM sch_fees f
         LEFT JOIN sch_terms t ON f.term_id = t.id
         WHERE f.student_id=? AND f.org_id=?
         ORDER BY f.created_at DESC"
    );
    $s->execute([$parActive, $parOrgId]);
    $invoices = $s->fetchAll();
} catch (Throwable $e) {}

// ── Totals ─────────────────────────────────────────────────────
$totalInvoiced = $totalPaid = $totalBalance = 0;
foreach ($invoices as $inv) {
    $totalInvoiced += $inv['amount'];
    $totalPaid     += $inv['paid'];
    $totalBalance  += $inv['balance'];
}

// ── Payment history ─────────────────────────────────────────────
$payments = [];
try {
    $s = $pdo->prepare(
        "SELECT p.*, f.fee_type
         FROM sch_fee_payments p
         JOIN sch_fees f ON p.fee_id = f.id
         WHERE f.student_id=? AND f.org_id=?
         ORDER BY p.payment_date DESC LIMIT 20"
    );
    $s->execute([$parActive, $parOrgId]);
    $payments = $s->fetchAll();
} catch (Throwable $e) {}

// Check if M-Pesa is configured for this org
$mpesaEnabled = false;
try {
    $mpesaEnabled = (bool) $pdo->query("SELECT COUNT(*) FROM settings WHERE org_id={$parOrgId} AND setting_key='mpesa_shortcode' AND setting_value!='' LIMIT 1")->fetchColumn();
} catch (Throwable $e) {}

// Parent's phone (pre-fill in payment modal)
$parentPhone = '';
try {
    $s = $pdo->prepare("SELECT phone FROM sch_parents WHERE id=? AND org_id=? LIMIT 1");
    $s->execute([$parId, $parOrgId]);
    $parentPhone = $s->fetchColumn() ?: '';
} catch (Throwable $e) {}
?>

<div class="d-flex align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="fas fa-receipt me-2" style="color:var(--par-green)"></i>Fees & Payments</h5>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="par-stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="par-stat-icon" style="background:#e8eaf6"><i class="fas fa-file-invoice-dollar" style="color:#5c6bc0"></i></div>
        <div><div class="fs-4 fw-bold"><?= formatCurrency($totalInvoiced) ?></div><div class="text-muted small">Total Invoiced</div></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="par-stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="par-stat-icon" style="background:#d4edda"><i class="fas fa-check-circle" style="color:#27ae60"></i></div>
        <div><div class="fs-4 fw-bold text-success"><?= formatCurrency($totalPaid) ?></div><div class="text-muted small">Total Paid</div></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="par-stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="par-stat-icon" style="background:<?= $totalBalance > 0 ? '#fde8e8' : '#d4edda' ?>">
          <i class="fas fa-<?= $totalBalance > 0 ? 'exclamation-triangle' : 'check-circle' ?>"
             style="color:<?= $totalBalance > 0 ? '#e74c3c' : '#27ae60' ?>"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold <?= $totalBalance > 0 ? 'text-danger' : 'text-success' ?>">
            <?= formatCurrency($totalBalance) ?>
          </div>
          <div class="text-muted small">Outstanding Balance</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($invoices) && $totalBalance > 0): ?>
<div class="alert <?= $mpesaEnabled ? 'alert-info' : 'alert-warning' ?> d-flex align-items-start gap-2 mb-4">
  <i class="fas fa-<?= $mpesaEnabled ? 'mobile-alt' : 'exclamation-triangle' ?> flex-shrink-0 mt-1"></i>
  <div>
    <strong>Outstanding Balance: <?= formatCurrency($totalBalance) ?></strong>
    <?php if ($mpesaEnabled): ?>
    — Pay instantly using M-Pesa by clicking <strong>Pay with M-Pesa</strong> next to any invoice below.
    <?php else: ?>
    — Please contact the school bursar to make a payment or visit the school office.
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Invoice table -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header">
    <h6 class="mb-0 fw-bold"><i class="fas fa-file-invoice me-2"></i>Fee Invoices</h6>
  </div>
  <div class="card-body p-0">
    <?php if (empty($invoices)): ?>
    <div class="text-center py-4 text-muted small">
      <i class="fas fa-receipt d-block fa-2x mb-1 opacity-25"></i>No fee invoices found
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Fee Type</th>
            <th>Term</th>
            <th class="text-end">Amount</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Balance</th>
            <th>Due Date</th>
            <th class="text-center">Status</th>
            <?php if ($mpesaEnabled): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv): ?>
          <tr>
            <td class="fw-semibold small"><?= e(ucwords(str_replace('_',' ',$inv['fee_type']))) ?></td>
            <td class="small text-muted"><?= e($inv['term_name'] ?? '—') ?></td>
            <td class="text-end small"><?= formatCurrency($inv['amount']) ?></td>
            <td class="text-end small text-success"><?= formatCurrency($inv['paid']) ?></td>
            <td class="text-end small <?= $inv['balance'] > 0 ? 'text-danger fw-semibold' : 'text-success' ?>">
              <?= formatCurrency($inv['balance']) ?>
            </td>
            <td class="small"><?= $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '—' ?></td>
            <td class="text-center"><?= statusBadge($inv['status']) ?></td>
            <?php if ($mpesaEnabled): ?>
            <td>
              <?php if ((float)$inv['balance'] > 0 && $inv['currency'] === 'KES'): ?>
              <button class="btn btn-sm btn-success py-1 mpesa-btn"
                      data-fee-id="<?= $inv['id'] ?>"
                      data-balance="<?= number_format((float)$inv['balance'], 2, '.', '') ?>"
                      data-desc="<?= e(ucwords(str_replace('_',' ',$inv['fee_type']))) ?>">
                <i class="fas fa-mobile-alt me-1"></i>Pay
              </button>
              <?php elseif ((float)$inv['balance'] > 0): ?>
              <span class="text-muted small">KES only</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Payment history -->
<div class="card border-0 shadow-sm">
  <div class="card-header">
    <h6 class="mb-0 fw-bold"><i class="fas fa-history me-2"></i>Payment History</h6>
  </div>
  <div class="card-body p-0">
    <?php if (empty($payments)): ?>
    <div class="text-center py-4 text-muted small">
      <i class="fas fa-history d-block fa-2x mb-1 opacity-25"></i>No payments recorded
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table mb-0">
        <thead class="table-light">
          <tr><th>Receipt No</th><th>Fee Type</th><th>Method</th><th class="text-end">Amount</th><th>Date</th></tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $pay): ?>
          <tr>
            <td class="small fw-semibold">
              <?php if (!empty($pay['receipt_no'])): ?>
              <a href="<?= APP_URL ?>/modules/school/fee-receipt-pdf.php?payment_id=<?= $pay['id'] ?>"
                 target="_blank" class="text-decoration-none" title="Print Receipt">
                <?= e($pay['receipt_no']) ?>
                <i class="fas fa-print ms-1 text-muted" style="font-size:.7rem"></i>
              </a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="small"><?= e(ucwords(str_replace('_',' ',$pay['fee_type']))) ?></td>
            <td><span class="badge bg-secondary bg-opacity-25 text-dark"><?= e(ucfirst($pay['payment_method'] ?? '')) ?></span></td>
            <td class="text-end fw-semibold text-success small"><?= formatCurrency($pay['amount_paid']) ?></td>
            <td class="small"><?= $pay['payment_date'] ? date('d M Y', strtotime($pay['payment_date'])) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($mpesaEnabled): ?>
<!-- M-Pesa Payment Modal -->
<div class="modal fade" id="mpesaModal" tabindex="-1" aria-labelledby="mpesaModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <div>
          <h6 class="modal-title fw-bold mb-0" id="mpesaModalLabel">
            <i class="fas fa-mobile-alt me-2" style="color:var(--par-green)"></i>Pay with M-Pesa
          </h6>
          <p class="text-muted mb-0" style="font-size:.78rem" id="mpesaFeeDesc"></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="mpesaForm">
          <div class="mb-3">
            <label class="form-label small fw-semibold">M-Pesa Phone Number</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="fas fa-phone"></i></span>
              <input type="tel" class="form-control" id="mpesaPhone"
                     placeholder="07XXXXXXXX" value="<?= e($parentPhone) ?>" maxlength="13">
            </div>
            <div class="form-text">Safaricom number registered for M-Pesa</div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Amount (KES)</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text">KES</span>
              <input type="number" class="form-control" id="mpesaAmount" min="1" step="1">
            </div>
            <div class="form-text" id="mpesaBalanceHint"></div>
          </div>
          <div id="mpesaAlert" class="alert d-none py-2 small"></div>
        </div>
        <div id="mpesaSuccess" class="d-none text-center py-3">
          <div class="mb-2" style="font-size:2.5rem">📱</div>
          <div class="fw-bold text-success mb-1">STK Push Sent!</div>
          <p class="text-muted small mb-0" id="mpesaSuccessMsg"></p>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0" id="mpesaFooter">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success btn-sm" id="mpesaSubmitBtn">
          <span id="mpesaBtnSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
          <i class="fas fa-paper-plane me-1" id="mpesaBtnIcon"></i>Send M-Pesa Prompt
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const csrfToken = '<?= $_SESSION['csrf_token'] ?? '' ?>';
let activeFeeId = null;

document.querySelectorAll('.mpesa-btn').forEach(btn => {
  btn.addEventListener('click', function () {
    activeFeeId  = this.dataset.feeId;
    const bal    = parseFloat(this.dataset.balance);
    const desc   = this.dataset.desc;
    document.getElementById('mpesaFeeDesc').textContent   = desc;
    document.getElementById('mpesaAmount').value          = Math.ceil(bal);
    document.getElementById('mpesaAmount').max            = Math.ceil(bal);
    document.getElementById('mpesaBalanceHint').textContent = 'Balance: KES ' + bal.toLocaleString('en-KE', {minimumFractionDigits: 2});
    document.getElementById('mpesaAlert').className = 'alert d-none py-2 small';
    document.getElementById('mpesaForm').classList.remove('d-none');
    document.getElementById('mpesaSuccess').classList.add('d-none');
    document.getElementById('mpesaFooter').classList.remove('d-none');
    new bootstrap.Modal(document.getElementById('mpesaModal')).show();
  });
});

document.getElementById('mpesaSubmitBtn').addEventListener('click', function () {
  const phone  = document.getElementById('mpesaPhone').value.trim();
  const amount = parseFloat(document.getElementById('mpesaAmount').value);
  const alertEl = document.getElementById('mpesaAlert');

  if (!phone || amount < 1) {
    alertEl.className = 'alert alert-warning py-2 small';
    alertEl.textContent = 'Please enter a valid phone number and amount.';
    return;
  }

  // Disable button + show spinner
  this.disabled = true;
  document.getElementById('mpesaBtnSpinner').classList.remove('d-none');
  document.getElementById('mpesaBtnIcon').classList.add('d-none');
  alertEl.className = 'alert d-none py-2 small';

  const fd = new FormData();
  fd.append('fee_id', activeFeeId);
  fd.append('phone',  phone);
  fd.append('amount', amount);

  fetch('<?= APP_URL ?>/parent/mpesa-pay.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      document.getElementById('mpesaBtnSpinner').classList.add('d-none');
      document.getElementById('mpesaBtnIcon').classList.remove('d-none');
      this.disabled = false;

      if (data.success) {
        document.getElementById('mpesaForm').classList.add('d-none');
        document.getElementById('mpesaFooter').classList.add('d-none');
        document.getElementById('mpesaSuccess').classList.remove('d-none');
        document.getElementById('mpesaSuccessMsg').textContent = data.message;
        // Reload page after 4s to reflect updated balance
        setTimeout(() => location.reload(), 4000);
      } else {
        alertEl.className = 'alert alert-danger py-2 small';
        alertEl.textContent = data.message || 'Payment failed. Please try again.';
      }
    })
    .catch(() => {
      document.getElementById('mpesaBtnSpinner').classList.add('d-none');
      document.getElementById('mpesaBtnIcon').classList.remove('d-none');
      this.disabled = false;
      alertEl.className = 'alert alert-danger py-2 small';
      alertEl.textContent = 'Network error. Please check your connection and try again.';
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-parent.php'; ?>
