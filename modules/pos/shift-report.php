<?php
/**
 * Shift Handover Report — printable / PDF-ready standalone page
 * Access: modules/pos/shift-report.php?id=SHIFT_ID
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireModuleAccess('pos');

$user  = currentUser();
$orgId = (int)$user['org_id'];
$id    = (int)($_GET['id'] ?? 0);

if (!$id) { redirect(APP_URL . '/modules/pos/shifts.php'); }

// ── Fetch shift ────────────────────────────────────────────────
$shift = null;
try {
    $s = $pdo->prepare("SELECT * FROM pos_shifts WHERE id=? AND org_id=?");
    $s->execute([$id, $orgId]);
    $shift = $s->fetch();
} catch (Exception $e) {}

if (!$shift) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:2rem">Shift not found or access denied.</p>');
}

// ── Fetch sales for this shift ─────────────────────────────────
$sales = [];
try {
    $s = $pdo->prepare(
        "SELECT ps.*, u.name AS cashier_name
         FROM pos_sales ps
         LEFT JOIN users u ON ps.cashier_id = u.id
         WHERE ps.org_id=? AND ps.shift_id=? AND ps.status != 'void'
         ORDER BY ps.created_at ASC"
    );
    $s->execute([$orgId, $id]);
    $sales = $s->fetchAll();
} catch (Exception $e) {}

// ── Fetch expenses for this shift ─────────────────────────────
$expenses = [];
try {
    $s = $pdo->prepare("SELECT * FROM pos_expenses WHERE org_id=? AND shift_id=? ORDER BY created_at ASC");
    $s->execute([$orgId, $id]);
    $expenses = $s->fetchAll();
} catch (Exception $e) {}

// ── Fetch returns on the same shift date ──────────────────────
$returns = [];
try {
    $s = $pdo->prepare("SELECT * FROM pos_returns WHERE org_id=? AND DATE(created_at)=? ORDER BY created_at ASC");
    $s->execute([$orgId, $shift['shift_date']]);
    $returns = $s->fetchAll();
} catch (Exception $e) {}

// ── Compute reconciliation numbers ────────────────────────────
// Use stored totals if shift is closed, otherwise compute live
$isOpen = ($shift['status'] === 'open');

if ($isOpen) {
    $totalSales = array_sum(array_column($sales, 'total'));
    $cashSales  = array_sum(array_map(fn($s) => $s['payment_method'] === 'cash'  ? $s['total'] : 0, $sales));
    $mpesaSales = array_sum(array_map(fn($s) => $s['payment_method'] === 'mpesa' ? $s['total'] : 0, $sales));
    $cardSales  = array_sum(array_map(fn($s) => $s['payment_method'] === 'card'  ? $s['total'] : 0, $sales));
    $totalExp   = array_sum(array_column($expenses, 'amount'));
    $totalRet   = array_sum(array_column($returns, 'refund_amount'));
    $txnCount   = count($sales);
} else {
    $totalSales = (float)$shift['total_sales'];
    $cashSales  = (float)$shift['total_cash'];
    $mpesaSales = (float)$shift['total_mpesa'];
    $cardSales  = (float)$shift['total_card'];
    $totalExp   = (float)$shift['total_expenses'];
    $totalRet   = (float)$shift['total_returns'];
    $txnCount   = (int)$shift['transactions'];
}

$openFloat    = (float)$shift['opening_float'];
$closeFloat   = (float)($shift['closing_float'] ?? 0);
$expectedCash = $openFloat + $cashSales - $totalExp;
$variance     = $isOpen ? null : ($closeFloat - $expectedCash);
$netRevenue   = $totalSales - $totalRet;

// ── Duration ───────────────────────────────────────────────────
$startDt = new DateTime($shift['start_time']);
$endDt   = $shift['end_time'] ? new DateTime($shift['end_time']) : new DateTime();
$diff    = $startDt->diff($endDt);
$duration = ($diff->h > 0 ? $diff->h . 'h ' : '') . $diff->i . 'min';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shift Report #<?= $id ?> — <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
  body { font-family: 'Segoe UI', Arial, sans-serif; background: #f1f5f9; color: #1e293b; font-size: .875rem; }
  .report-wrap { max-width: 820px; margin: 24px auto; background: white; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.1); overflow: hidden; }
  .report-header { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: white; padding: 28px 32px; }
  .report-header .org-name { font-size: 1.4rem; font-weight: 800; }
  .report-header .sub { opacity: .7; font-size: .82rem; margin-top: 2px; }
  .report-header .badge-status { display: inline-block; padding: 3px 12px; border-radius: 20px; font-size: .75rem; font-weight: 700; margin-top: 8px; }
  .badge-open   { background: #fbbf24; color: #1e293b; }
  .badge-closed { background: #4ade80; color: #052e16; }
  .report-body { padding: 28px 32px; }
  .section-title { font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin-bottom: 10px; padding-bottom: 4px; border-bottom: 2px solid #e2e8f0; }
  .info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
  .info-cell { background: #f8fafc; border-radius: 8px; padding: 10px 14px; }
  .info-cell .label { font-size: .72rem; color: #64748b; margin-bottom: 2px; }
  .info-cell .value { font-weight: 700; font-size: .95rem; }
  .metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 24px; }
  .metric-card { border-radius: 8px; padding: 12px 14px; }
  .metric-card .m-label { font-size: .72rem; color: #64748b; margin-bottom: 4px; }
  .metric-card .m-value { font-size: 1.05rem; font-weight: 800; }
  .m-green  { background: #f0fdf4; } .m-green  .m-value { color: #16a34a; }
  .m-blue   { background: #eff6ff; } .m-blue   .m-value { color: #2563eb; }
  .m-purple { background: #faf5ff; } .m-purple .m-value { color: #7c3aed; }
  .m-red    { background: #fef2f2; } .m-red    .m-value { color: #dc2626; }
  .m-slate  { background: #f8fafc; } .m-slate  .m-value { color: #475569; }
  .recon-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  .recon-table td { padding: 7px 12px; border-bottom: 1px solid #f1f5f9; }
  .recon-table tr:last-child td { border-bottom: none; }
  .recon-table .row-label { color: #475569; }
  .recon-table .row-value { text-align: right; font-weight: 600; }
  .recon-table .total-row td { background: #f8fafc; font-weight: 800; font-size: .95rem; border-top: 2px solid #e2e8f0; }
  .recon-table .variance-pos { color: #2563eb; }
  .recon-table .variance-neg { color: #dc2626; }
  .recon-table .variance-ok  { color: #16a34a; }
  .sales-table { width: 100%; border-collapse: collapse; font-size: .8rem; margin-bottom: 24px; }
  .sales-table th { background: #f8fafc; padding: 7px 10px; text-align: left; font-weight: 700; color: #475569; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; border-bottom: 2px solid #e2e8f0; }
  .sales-table td { padding: 6px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  .sales-table tbody tr:hover { background: #f8fafc; }
  .sales-table tfoot td { background: #f8fafc; font-weight: 700; border-top: 2px solid #e2e8f0; }
  .pm-badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: .7rem; font-weight: 600; }
  .pm-cash  { background: #dcfce7; color: #16a34a; }
  .pm-mpesa { background: #cffafe; color: #0e7490; }
  .pm-card  { background: #ede9fe; color: #6d28d9; }
  .pm-credit{ background: #fef9c3; color: #854d0e; }
  .sig-area { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 32px; padding-top: 24px; border-top: 2px dashed #e2e8f0; }
  .sig-box { padding-top: 48px; border-top: 1px solid #1e293b; font-size: .78rem; color: #475569; text-align: center; }
  .report-footer-bar { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 12px 32px; display: flex; justify-content: space-between; align-items: center; font-size: .75rem; color: #94a3b8; }
  .print-bar { background: white; border-bottom: 1px solid #e2e8f0; padding: 10px 32px; display: flex; gap: 10px; align-items: center; position: sticky; top: 0; z-index: 10; }
  @media print {
    body { background: white; }
    .print-bar { display: none !important; }
    .report-wrap { box-shadow: none; border-radius: 0; margin: 0; max-width: 100%; }
    .report-footer-bar { display: none; }
    .section-title { break-after: avoid; }
    .sales-table { font-size: .75rem; }
    table { page-break-inside: avoid; }
    tr { page-break-inside: avoid; }
  }
</style>
</head>
<body>

<!-- Print bar (hidden on print) -->
<div class="print-bar">
  <button onclick="window.print()" class="btn btn-sm btn-danger">
    <i class="fas fa-print me-2"></i>Print / Save PDF
  </button>
  <a href="shifts.php" class="btn btn-sm btn-outline-secondary">
    <i class="fas fa-arrow-left me-1"></i>Back to Shifts
  </a>
  <span class="text-muted small ms-auto">
    Shift #<?= $id ?> — Generated <?= date('d M Y H:i') ?> by <?= e($user['name']) ?>
  </span>
</div>

<div class="report-wrap">

  <!-- Header -->
  <div class="report-header">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <div class="org-name"><?= e(APP_NAME) ?></div>
        <div class="sub">Cashier Shift Handover Report</div>
        <div class="badge-status <?= $isOpen ? 'badge-open' : 'badge-closed' ?>">
          <?= $isOpen ? '● SHIFT IN PROGRESS' : '✓ SHIFT CLOSED' ?>
        </div>
      </div>
      <div class="text-end" style="opacity:.8">
        <div style="font-size:1.8rem;font-weight:800;letter-spacing:.05em">#<?= str_pad($id, 5, '0', STR_PAD_LEFT) ?></div>
        <div style="font-size:.78rem"><?= formatDate($shift['shift_date'], 'l, d M Y') ?></div>
      </div>
    </div>
  </div>

  <div class="report-body">

    <!-- Shift info grid -->
    <div class="section-title">Shift Information</div>
    <div class="info-grid">
      <div class="info-cell">
        <div class="label">Cashier</div>
        <div class="value"><?= e($shift['cashier_name']) ?></div>
      </div>
      <div class="info-cell">
        <div class="label">Start Time</div>
        <div class="value"><?= date('H:i', strtotime($shift['start_time'])) ?></div>
      </div>
      <div class="info-cell">
        <div class="label">End Time</div>
        <div class="value"><?= $shift['end_time'] ? date('H:i', strtotime($shift['end_time'])) : '<span style="color:#f59e0b">Open</span>' ?></div>
      </div>
      <div class="info-cell">
        <div class="label">Duration</div>
        <div class="value"><?= $duration ?></div>
      </div>
      <div class="info-cell">
        <div class="label">Opening Float</div>
        <div class="value"><?= formatCurrency($openFloat) ?></div>
      </div>
      <div class="info-cell">
        <div class="label">Closing Float</div>
        <div class="value"><?= $shift['closing_float'] !== null ? formatCurrency($closeFloat) : '<span style="color:#94a3b8">Pending</span>' ?></div>
      </div>
    </div>

    <!-- Metrics -->
    <div class="section-title">Sales Summary</div>
    <div class="metrics-grid" style="grid-template-columns:repeat(4,1fr)">
      <div class="metric-card m-green">
        <div class="m-label">Total Revenue</div>
        <div class="m-value"><?= formatCurrency($totalSales) ?></div>
      </div>
      <div class="metric-card m-blue">
        <div class="m-label">Transactions</div>
        <div class="m-value"><?= $txnCount ?></div>
      </div>
      <div class="metric-card m-purple">
        <div class="m-label">Avg. Sale</div>
        <div class="m-value"><?= formatCurrency($txnCount > 0 ? $totalSales / $txnCount : 0) ?></div>
      </div>
      <div class="metric-card m-red">
        <div class="m-label">Returns / Voids</div>
        <div class="m-value"><?= formatCurrency($totalRet) ?></div>
      </div>
    </div>

    <!-- Reconciliation -->
    <div class="section-title">Cash Reconciliation</div>
    <table class="recon-table">
      <tr>
        <td class="row-label"><i class="fas fa-coins me-2 text-muted"></i>Opening Float</td>
        <td class="row-value"><?= formatCurrency($openFloat) ?></td>
      </tr>
      <tr>
        <td class="row-label"><i class="fas fa-money-bill-wave me-2" style="color:#16a34a"></i>Cash Sales Collected</td>
        <td class="row-value" style="color:#16a34a">+ <?= formatCurrency($cashSales) ?></td>
      </tr>
      <tr>
        <td class="row-label"><i class="fas fa-mobile-alt me-2" style="color:#0e7490"></i>M-Pesa Sales</td>
        <td class="row-value" style="color:#0e7490"><?= formatCurrency($mpesaSales) ?></td>
      </tr>
      <tr>
        <td class="row-label"><i class="fas fa-credit-card me-2" style="color:#6d28d9"></i>Card Sales</td>
        <td class="row-value" style="color:#6d28d9"><?= formatCurrency($cardSales) ?></td>
      </tr>
      <tr>
        <td class="row-label"><i class="fas fa-wallet me-2" style="color:#dc2626"></i>Cash Expenses Paid Out</td>
        <td class="row-value" style="color:#dc2626">− <?= formatCurrency($totalExp) ?></td>
      </tr>
      <?php if ($totalRet > 0): ?>
      <tr>
        <td class="row-label"><i class="fas fa-undo me-2" style="color:#f59e0b"></i>Returns / Refunds</td>
        <td class="row-value" style="color:#f59e0b">− <?= formatCurrency($totalRet) ?></td>
      </tr>
      <?php endif; ?>
      <tr class="total-row">
        <td>Expected Cash in Drawer</td>
        <td class="row-value" style="color:#16a34a"><?= formatCurrency($expectedCash) ?></td>
      </tr>
      <?php if (!$isOpen): ?>
      <tr class="total-row">
        <td>Actual Cash Counted (Closing Float)</td>
        <td class="row-value"><?= formatCurrency($closeFloat) ?></td>
      </tr>
      <tr class="total-row">
        <td>Variance</td>
        <td class="row-value <?=
          abs($variance) < 0.01 ? 'variance-ok' : ($variance > 0 ? 'variance-pos' : 'variance-neg')
        ?>">
          <?php if (abs($variance) < 0.01): ?>
          ✓ Balanced
          <?php elseif ($variance > 0): ?>
          + <?= formatCurrency(abs($variance)) ?> (over)
          <?php else: ?>
          − <?= formatCurrency(abs($variance)) ?> (short)
          <?php endif; ?>
        </td>
      </tr>
      <?php endif; ?>
    </table>

    <!-- Sales transactions -->
    <?php if (!empty($sales)): ?>
    <div class="section-title">Transaction Log (<?= count($sales) ?> sales)</div>
    <table class="sales-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Receipt</th>
          <th>Time</th>
          <th>Customer</th>
          <th>Payment</th>
          <th style="text-align:right">Total</th>
        </tr>
      </thead>
      <tbody>
      <?php $saleTot = 0; foreach ($sales as $i => $sale):
        $saleTot += (float)$sale['total'];
        $pm = $sale['payment_method'];
      ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td style="font-weight:600"><?= e($sale['receipt_no'] ?? '—') ?></td>
          <td><?= date('H:i', strtotime($sale['created_at'])) ?></td>
          <td class="text-muted"><?= $sale['customer_name'] ? e($sale['customer_name']) : '—' ?></td>
          <td>
            <span class="pm-badge pm-<?= $pm ?>">
              <?php $pmLabels=['cash'=>'Cash','mpesa'=>'M-Pesa','card'=>'Card','credit'=>'Credit'];
                    echo $pmLabels[$pm] ?? ucfirst($pm); ?>
            </span>
            <?php if ($pm === 'mpesa' && !empty($sale['mpesa_receipt'])): ?>
            <div style="font-size:.68rem;color:#64748b"><?= e($sale['mpesa_receipt']) ?></div>
            <?php endif; ?>
          </td>
          <td style="text-align:right;font-weight:700"><?= formatCurrency($sale['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="5">Total (<?= count($sales) ?> transactions)</td>
          <td style="text-align:right"><?= formatCurrency($saleTot) ?></td>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>

    <!-- Expenses -->
    <?php if (!empty($expenses)): ?>
    <div class="section-title">Expenses Paid Out (<?= count($expenses) ?>)</div>
    <table class="sales-table">
      <thead>
        <tr><th>Time</th><th>Category</th><th>Description</th><th>Receipt No.</th><th style="text-align:right">Amount</th></tr>
      </thead>
      <tbody>
      <?php $expTot = 0; foreach ($expenses as $exp):
        $expTot += (float)$exp['amount'];
      ?>
        <tr>
          <td><?= date('H:i', strtotime($exp['created_at'])) ?></td>
          <td><?= e($exp['category'] ?? '—') ?></td>
          <td><?= e($exp['description']) ?></td>
          <td class="text-muted"><?= e($exp['receipt_no'] ?? '—') ?></td>
          <td style="text-align:right;color:#dc2626;font-weight:600"><?= formatCurrency($exp['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4">Total Expenses</td>
          <td style="text-align:right;color:#dc2626"><?= formatCurrency($expTot) ?></td>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>

    <!-- Notes -->
    <?php if (!empty($shift['notes'])): ?>
    <div class="section-title">Notes</div>
    <div style="background:#f8fafc;border-left:3px solid #e74c3c;padding:10px 14px;border-radius:0 8px 8px 0;font-size:.85rem;margin-bottom:24px">
      <?= nl2br(e($shift['notes'])) ?>
    </div>
    <?php endif; ?>

    <!-- Signature lines -->
    <div class="sig-area">
      <div class="sig-box">
        <div>Cashier Signature</div>
        <div style="font-weight:600;margin-top:4px"><?= e($shift['cashier_name']) ?></div>
      </div>
      <div class="sig-box">
        <div>Supervisor / Manager Signature</div>
        <div style="margin-top:4px;color:#94a3b8">Name &amp; Stamp</div>
      </div>
    </div>

  </div><!-- /report-body -->

  <div class="report-footer-bar">
    <span>Shift #<?= str_pad($id, 5, '0', STR_PAD_LEFT) ?> &bull; <?= e(APP_NAME) ?></span>
    <span>Printed <?= date('d M Y H:i') ?> by <?= e($user['name']) ?></span>
  </div>

</div><!-- /report-wrap -->

</body>
</html>
