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

$statusColors = ['paid'=>'success','partial'=>'warning','unpaid'=>'danger','overdue'=>'danger'];
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
<div class="alert alert-warning d-flex align-items-start gap-2 mb-4">
  <i class="fas fa-exclamation-triangle flex-shrink-0 mt-1"></i>
  <div>
    <strong>Outstanding Balance: <?= formatCurrency($totalBalance) ?></strong>
    — Please contact the school bursar to make a payment or visit the school office.
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
      <table class="table mb-0">
        <thead class="table-light">
          <tr>
            <th>Fee Type</th>
            <th>Term</th>
            <th class="text-end">Amount</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Balance</th>
            <th>Due Date</th>
            <th class="text-center">Status</th>
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
            <td class="text-center">
              <?= statusBadge($inv['status']) ?>
            </td>
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
            <td class="small fw-semibold"><?= e($pay['receipt_no'] ?? '—') ?></td>
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

<?php require_once __DIR__ . '/../includes/footer-parent.php'; ?>
