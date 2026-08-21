<?php
/**
 * Traveller Portal — receipts, invoices and outstanding balance
 */
$pageTitle = 'Payments';
require_once __DIR__ . '/../includes/header-traveller.php';

$payments = [];
try {
    $stmt = $pdo->prepare("
        SELECT receipt_no, amount, payment_type, payment_mode, reference, payment_date, notes
        FROM tour_payments
        WHERE org_id = ? AND booking_id = ?
        ORDER BY payment_date DESC, id DESC
    ");
    $stmt->execute([$trvOrgId, $trvBid]);
    $payments = $stmt->fetchAll();
} catch (Throwable $e) {}

$invoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, invoice_no, issue_date, due_date, total_amount, amount_paid, status
        FROM tour_invoices
        WHERE org_id = ? AND booking_id = ? AND status <> 'draft'
        ORDER BY issue_date DESC, id DESC
    ");
    $stmt->execute([$trvOrgId, $trvBid]);
    $invoices = $stmt->fetchAll();
} catch (Throwable $e) {}

$paidPercent  = $tripTotal > 0 ? min(100, (int)round(($tripPaid / $tripTotal) * 100)) : 0;
$contactPhone = tourConf($trvOrgId, 't_contact_phone');
$contactEmail = tourConf($trvOrgId, 't_contact_email');

// Soonest unpaid due date across this booking's invoices
$nextDue = null;
foreach ($invoices as $inv) {
    $bal = (float)$inv['total_amount'] - (float)$inv['amount_paid'];
    if ($bal > 0 && !empty($inv['due_date'])) {
        if ($nextDue === null || $inv['due_date'] < $nextDue) $nextDue = $inv['due_date'];
    }
}
?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="trv-card">
      <div class="d-flex align-items-center gap-3">
        <div class="trv-stat-icon" style="background:#eff6fb;color:#2980b9"><i class="fas fa-file-invoice-dollar"></i></div>
        <div>
          <div class="text-muted small">Trip Total</div>
          <div class="fs-5 fw-bold"><?= formatCurrency($tripTotal) ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="trv-card">
      <div class="d-flex align-items-center gap-3">
        <div class="trv-stat-icon" style="background:#dcfce7;color:#15803d"><i class="fas fa-circle-check"></i></div>
        <div>
          <div class="text-muted small">Received</div>
          <div class="fs-5 fw-bold text-success"><?= formatCurrency($tripPaid) ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="trv-card">
      <div class="d-flex align-items-center gap-3">
        <div class="trv-stat-icon" style="background:<?= $tripBalance > 0 ? '#fee2e2;color:#dc2626' : '#dcfce7;color:#15803d' ?>">
          <i class="fas <?= $tripBalance > 0 ? 'fa-hourglass-half' : 'fa-check-double' ?>"></i>
        </div>
        <div>
          <div class="text-muted small">Balance Due</div>
          <div class="fs-5 fw-bold <?= $tripBalance > 0 ? 'text-danger' : 'text-success' ?>">
            <?= $tripBalance > 0 ? formatCurrency($tripBalance) : 'Settled' ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="trv-card mb-4">
  <div class="d-flex justify-content-between small mb-2">
    <span class="fw-semibold">Payment progress</span>
    <span class="text-muted"><?= $paidPercent ?>% settled</span>
  </div>
  <div class="progress" style="height:8px">
    <div class="progress-bar <?= $paidPercent >= 100 ? 'bg-success' : '' ?>"
         style="width:<?= $paidPercent ?>%;<?= $paidPercent < 100 ? 'background:#2980b9' : '' ?>"></div>
  </div>
  <?php if ($tripBalance > 0): ?>
  <div class="alert alert-warning small mt-3 mb-0 d-flex align-items-start gap-2">
    <i class="fas fa-circle-info mt-1"></i>
    <div>
      A balance of <strong><?= formatCurrency($tripBalance) ?></strong> is outstanding
      <?= $nextDue ? 'and due by <strong>' . formatDate($nextDue) . '</strong>' : 'on this booking' ?>.
      To settle it, contact <?= e($operatorName) ?>
      <?php if ($contactPhone): ?>on <a href="tel:<?= e($contactPhone) ?>"><?= e($contactPhone) ?></a><?php endif; ?>
      <?php if ($contactEmail): ?><?= $contactPhone ? ' or ' : 'at ' ?><a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a><?php endif; ?>
      quoting <strong><?= e($trip['booking_no']) ?></strong>.
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ── Invoices ─────────────────────────────────────────────── -->
<div class="trv-card mb-4">
  <h6 class="fw-bold mb-3"><i class="fas fa-file-invoice me-2" style="color:var(--trv-blue)"></i>Your Invoices</h6>
  <?php if (empty($invoices)): ?>
  <p class="text-muted small mb-0">No invoices have been issued for this booking yet.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Invoice</th><th>Issued</th><th>Due</th>
          <th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $inv):
            $bal = max(0, (float)$inv['total_amount'] - (float)$inv['amount_paid']);
        ?>
        <tr>
          <td><code class="bg-light px-1 rounded"><?= e($inv['invoice_no']) ?></code></td>
          <td class="small"><?= formatDate($inv['issue_date']) ?></td>
          <td class="small <?= ($bal > 0 && $inv['due_date'] && $inv['due_date'] < date('Y-m-d')) ? 'text-danger fw-semibold' : '' ?>">
            <?= $inv['due_date'] ? formatDate($inv['due_date']) : '—' ?>
          </td>
          <td class="text-end fw-semibold"><?= formatCurrency((float)$inv['total_amount']) ?></td>
          <td class="text-end text-success"><?= formatCurrency((float)$inv['amount_paid']) ?></td>
          <td class="text-end fw-bold <?= $bal > 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($bal) ?></td>
          <td><?= statusBadge($inv['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── Receipts ─────────────────────────────────────────────── -->
<div class="trv-card">
  <h6 class="fw-bold mb-3"><i class="fas fa-receipt me-2" style="color:var(--trv-blue)"></i>Payment History</h6>
  <?php if (empty($payments)): ?>
  <p class="text-muted small mb-0">No payments have been recorded against this booking yet.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Receipt</th><th>Date</th><th>Type</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th></tr>
      </thead>
      <tbody>
        <?php foreach ($payments as $p):
            $isRefund = $p['payment_type'] === 'refund';
        ?>
        <tr>
          <td><code class="bg-light px-1 rounded"><?= e($p['receipt_no']) ?></code></td>
          <td class="small"><?= formatDate($p['payment_date']) ?></td>
          <td class="small"><span class="badge bg-light text-dark border"><?= e(ucwords(str_replace('_', ' ', $p['payment_type']))) ?></span></td>
          <td class="small text-muted"><?= e(ucwords(str_replace('_', ' ', $p['payment_mode']))) ?></td>
          <td class="small text-muted"><?= e($p['reference'] ?: '—') ?></td>
          <td class="text-end fw-semibold <?= $isRefund ? 'text-danger' : 'text-success' ?>">
            <?= $isRefund ? '&minus;' : '' ?><?= formatCurrency((float)$p['amount']) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light">
        <tr>
          <th colspan="5" class="text-end">Total received</th>
          <th class="text-end"><?= formatCurrency($tripPaid) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer-traveller.php'; ?>
