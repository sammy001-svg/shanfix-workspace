<?php
/**
 * Shared HTML Invoice Renderer
 * Included by client/invoice-pdf.php and admin/invoice-pdf.php
 * Expects: $invoice (row), $org (array), $items (array), $cfg (settings array)
 * Outputs a full standalone HTML page and exits.
 */

$isOverdue = ($invoice['status'] !== 'paid'
           && !empty($invoice['due_date'])
           && strtotime($invoice['due_date']) < strtotime(date('Y-m-d')));

$statusLabel  = ucfirst($invoice['status'] ?? 'draft');
$statusColors = [
    'paid'      => ['bg' => '#dcfce7', 'color' => '#15803d', 'border' => '#86efac'],
    'overdue'   => ['bg' => '#fee2e2', 'color' => '#dc2626', 'border' => '#fca5a5'],
    'sent'      => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'border' => '#93c5fd'],
    'draft'     => ['bg' => '#f1f5f9', 'color' => '#64748b', 'border' => '#cbd5e1'],
    'cancelled' => ['bg' => '#f1f5f9', 'color' => '#94a3b8', 'border' => '#e2e8f0'],
];
$sc = $statusColors[$invoice['status']] ?? $statusColors['draft'];
if ($isOverdue) $sc = $statusColors['overdue'];

// Compute subtotal from items
$subtotal = 0;
if (!empty($items)) {
    foreach ($items as $it) {
        $subtotal += (float)($it['amount'] ?? ($it['qty'] ?? 1) * ($it['price'] ?? 0));
    }
} else {
    $subtotal = (float)($invoice['amount'] ?? 0);
}
$taxRate    = (float)($cfg['invoice_tax_rate'] ?? 16) / 100;
$taxAmount  = (float)($invoice['tax'] ?? round($subtotal * $taxRate, 2));
$totalDue   = (float)($invoice['total'] ?? $subtotal + $taxAmount);

// Payment details from settings
$mpesaPaybill = trim($cfg['mpesa_paybill']    ?? $cfg['mpesa_shortcode'] ?? '');
$mpesaRef     = trim($cfg['mpesa_account_ref'] ?? 'Invoice Number');
$bankName     = trim($cfg['bank_name']         ?? '');
$bankAccount  = trim($cfg['bank_account']      ?? '');
$bankBranch   = trim($cfg['bank_branch']       ?? '');
$supportEmail = trim($cfg['support_email']     ?? '');
$hasPayment   = $mpesaPaybill || $bankAccount;
$invoiceFooter = trim($cfg['invoice_footer'] ?? 'Thank you for your business.');
$companyAddr   = trim($cfg['company_address'] ?? '');

// Back URL injected by caller
global $invoiceBackUrl, $invoiceAdminMode;
$backUrl   = $invoiceBackUrl   ?? APP_URL . '/client/billing.php';
$adminMode = $invoiceAdminMode ?? false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= e($invoice['invoice_number'] ?? '') ?> — <?= e(APP_NAME) ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Arial, sans-serif; background: #f1f5f9; color: #1e293b; font-size: 14px; line-height: 1.5; }

  /* ── Action bar (hidden on print) ─────────────────── */
  .action-bar { background: #1e293b; padding: 10px 32px; display: flex; align-items: center; gap: 12px; position: sticky; top: 0; z-index: 100; }
  .action-bar .btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; }
  .btn-print  { background: #16a34a; color: white; }
  .btn-back   { background: transparent; color: #94a3b8; border: 1px solid #334155 !important; }
  .action-bar .hint { color: #64748b; font-size: 12px; margin-left: auto; }

  /* ── Invoice card ─────────────────────────────────── */
  .invoice-wrap { max-width: 860px; margin: 28px auto 48px; background: white; border-radius: 12px; box-shadow: 0 4px 32px rgba(0,0,0,.10); overflow: hidden; position: relative; }

  /* Overdue diagonal watermark */
  <?php if ($isOverdue): ?>
  .invoice-wrap::before {
    content: 'OVERDUE';
    position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-35deg);
    font-size: 6rem; font-weight: 900; color: rgba(220,38,38,.07);
    letter-spacing: .2em; pointer-events: none; z-index: 0; white-space: nowrap;
  }
  <?php endif; ?>

  /* ── Header ─────────────────────────────────────────  */
  .inv-header { background: linear-gradient(135deg, #0B2D4E 0%, #1a3d5c 100%); color: white; padding: 32px 40px; display: flex; justify-content: space-between; align-items: flex-start; }
  .inv-header .brand-block .brand-name { font-size: 22px; font-weight: 800; letter-spacing: -.01em; }
  .inv-header .brand-block .brand-sub  { font-size: 12px; opacity: .65; margin-top: 2px; }
  .inv-header .brand-block .company-addr { font-size: 11.5px; opacity: .7; margin-top: 8px; line-height: 1.6; }
  .inv-header .inv-title-block { text-align: right; }
  .inv-header .inv-title { font-size: 32px; font-weight: 900; letter-spacing: .08em; opacity: .9; }
  .inv-header .inv-number { font-size: 14px; opacity: .75; margin-top: 4px; }
  .status-badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; letter-spacing: .04em; margin-top: 10px;
    background: <?= $sc['bg'] ?>; color: <?= $sc['color'] ?>; border: 1.5px solid <?= $sc['border'] ?>; }

  /* ── Meta grid ──────────────────────────────────────── */
  .inv-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border-bottom: 1px solid #e2e8f0; }
  .inv-meta-cell { padding: 24px 40px; }
  .inv-meta-cell + .inv-meta-cell { border-left: 1px solid #e2e8f0; }
  .inv-meta .label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin-bottom: 4px; }
  .inv-meta .org-name { font-size: 17px; font-weight: 700; color: #0B2D4E; }
  .inv-meta .org-detail { font-size: 13px; color: #475569; margin-top: 2px; }
  .meta-table { width: 100%; border-collapse: collapse; }
  .meta-table td { padding: 4px 0; font-size: 13px; }
  .meta-table td:first-child { color: #64748b; width: 40%; }
  .meta-table td:last-child { font-weight: 600; text-align: right; }

  /* ── Line items ─────────────────────────────────────── */
  .inv-body { padding: 28px 40px; position: relative; z-index: 1; }
  .section-title { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .09em; color: #64748b; margin-bottom: 10px; }
  .items-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
  .items-table thead tr { background: #0B2D4E; color: white; }
  .items-table thead th { padding: 9px 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
  .items-table thead th:last-child { text-align: right; }
  .items-table tbody td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-size: 13.5px; vertical-align: top; }
  .items-table tbody tr:nth-child(even) td { background: #f8fafc; }
  .items-table tbody tr:last-child td { border-bottom: 2px solid #e2e8f0; }
  .items-table tbody td:last-child { text-align: right; font-weight: 600; }
  .item-name { font-weight: 600; color: #0f172a; }
  .item-desc { font-size: 12px; color: #64748b; margin-top: 2px; }

  /* ── Totals ─────────────────────────────────────────── */
  .totals-block { display: flex; justify-content: flex-end; margin-top: 16px; }
  .totals-table { width: 280px; border-collapse: collapse; }
  .totals-table td { padding: 6px 0; font-size: 13.5px; }
  .totals-table td:last-child { text-align: right; font-weight: 600; }
  .totals-table tr.label-col td:first-child { color: #64748b; }
  .totals-table .total-row td { font-size: 16px; font-weight: 800; color: white; background: #1A8A4E; padding: 10px 14px; border-radius: 8px; }
  .totals-table .total-row td:first-child { border-radius: 8px 0 0 8px; }
  .totals-table .total-row td:last-child  { border-radius: 0 8px 8px 0; }

  /* ── Payment info ───────────────────────────────────── */
  .payment-section { margin-top: 28px; padding-top: 20px; border-top: 1px dashed #e2e8f0; }
  .payment-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px; }
  .payment-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 18px; }
  .payment-card .pay-title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: #64748b; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
  .payment-card .pay-row { display: flex; justify-content: space-between; font-size: 13px; padding: 3px 0; }
  .payment-card .pay-row span:last-child { font-weight: 600; }
  .payment-card .pay-highlight { font-size: 18px; font-weight: 800; color: #0B2D4E; margin-bottom: 4px; }

  /* ── Notes / Footer ─────────────────────────────────── */
  .inv-notes { margin-top: 20px; padding: 14px 18px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; font-size: 13px; color: #78350f; }
  .inv-notes strong { display: block; margin-bottom: 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; }
  .inv-footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 12px; color: #94a3b8; }
  .inv-footer strong { color: #64748b; }

  /* ── Print rules ────────────────────────────────────── */
  @media print {
    body { background: white; }
    .action-bar { display: none !important; }
    .invoice-wrap { box-shadow: none; border-radius: 0; margin: 0; max-width: 100%; }
    @page { size: A4; margin: 0; }
    .inv-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .items-table thead tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .totals-table .total-row td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .invoice-wrap::before { font-size: 8rem; }
  }
</style>
</head>
<body>

<!-- Action bar -->
<div class="action-bar">
  <button class="btn btn-print" onclick="window.print()">
    <i class="fas fa-print"></i> Print / Save as PDF
  </button>
  <a href="<?= e($backUrl) ?>" class="btn btn-back">
    <i class="fas fa-arrow-left"></i> Back
  </a>
  <?php if ($adminMode): ?>
  <span style="background:#7c3aed;color:white;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600">
    <i class="fas fa-shield-alt me-1"></i> Admin View
  </span>
  <?php endif; ?>
  <span class="hint"><i class="fas fa-info-circle me-1"></i>Use browser Print → Save as PDF for a PDF copy</span>
</div>

<!-- Invoice -->
<div class="invoice-wrap">

  <!-- Header -->
  <div class="inv-header">
    <div class="brand-block">
      <div class="brand-name"><i class="fas fa-cubes" style="margin-right:8px;opacity:.8"></i><?= e(APP_NAME) ?></div>
      <div class="brand-sub"><?= e(APP_TAGLINE) ?></div>
      <?php if ($companyAddr): ?>
      <div class="company-addr"><?= nl2br(e($companyAddr)) ?></div>
      <?php endif; ?>
    </div>
    <div class="inv-title-block">
      <div class="inv-title">INVOICE</div>
      <div class="inv-number"><?= e($invoice['invoice_number'] ?? '—') ?></div>
      <div>
        <span class="status-badge"><?= $isOverdue ? 'OVERDUE' : strtoupper($statusLabel) ?></span>
      </div>
    </div>
  </div>

  <!-- Billed To + Invoice Details -->
  <div class="inv-meta">
    <div class="inv-meta-cell">
      <div class="label">Billed To</div>
      <div class="org-name"><?= e($org['name'] ?? '') ?></div>
      <?php if (!empty($org['email'])): ?>
      <div class="org-detail"><i class="fas fa-envelope" style="width:14px;color:#94a3b8"></i> <?= e($org['email']) ?></div>
      <?php endif; ?>
      <?php if (!empty($org['phone'])): ?>
      <div class="org-detail"><i class="fas fa-phone" style="width:14px;color:#94a3b8"></i> <?= e($org['phone']) ?></div>
      <?php endif; ?>
      <?php if (!empty($org['address'])): ?>
      <div class="org-detail" style="margin-top:4px"><?= nl2br(e($org['address'])) ?></div>
      <?php elseif (!empty($org['city'])): ?>
      <div class="org-detail"><i class="fas fa-map-marker-alt" style="width:14px;color:#94a3b8"></i> <?= e($org['city']) ?><?= !empty($org['country']) ? ', ' . e($org['country']) : '' ?></div>
      <?php endif; ?>
    </div>
    <div class="inv-meta-cell">
      <div class="label">Invoice Details</div>
      <table class="meta-table">
        <tr>
          <td>Invoice Number</td>
          <td><?= e($invoice['invoice_number'] ?? '—') ?></td>
        </tr>
        <tr>
          <td>Issue Date</td>
          <td><?= !empty($invoice['issue_date']) ? formatDate($invoice['issue_date']) : formatDate($invoice['created_at'] ?? '') ?></td>
        </tr>
        <tr>
          <td>Due Date</td>
          <td style="<?= $isOverdue ? 'color:#dc2626;font-weight:700' : '' ?>">
            <?= !empty($invoice['due_date']) ? formatDate($invoice['due_date']) : '—' ?>
            <?= $isOverdue ? ' <span style="font-size:11px">(Overdue)</span>' : '' ?>
          </td>
        </tr>
        <?php if (!empty($invoice['paid_at'])): ?>
        <tr>
          <td>Paid On</td>
          <td style="color:#16a34a"><?= formatDate($invoice['paid_at']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if (!empty($invoice['mpesa_receipt'])): ?>
        <tr>
          <td>M-Pesa Ref</td>
          <td><?= e($invoice['mpesa_receipt']) ?></td>
        </tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <!-- Body: line items + totals + payment -->
  <div class="inv-body">

    <div class="section-title">Items &amp; Services</div>
    <table class="items-table">
      <thead>
        <tr>
          <th style="width:50%">Description</th>
          <th style="width:15%;text-align:center">Qty</th>
          <th style="width:17.5%;text-align:right">Unit Price</th>
          <th style="width:17.5%">Amount</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!empty($items)): ?>
        <?php foreach ($items as $it):
          $qty      = (float)($it['qty']   ?? 1);
          $price    = (float)($it['price'] ?? $it['amount'] ?? 0);
          $lineAmt  = (float)($it['amount'] ?? ($qty * $price));
        ?>
        <tr>
          <td>
            <div class="item-name"><?= e($it['module_name'] ?? $it['description'] ?? 'Service') ?></div>
            <?php if (!empty($it['module_description']) || !empty($it['item_description'])): ?>
            <div class="item-desc"><?= e($it['module_description'] ?? $it['item_description'] ?? '') ?></div>
            <?php endif; ?>
          </td>
          <td style="text-align:center"><?= number_format($qty) ?></td>
          <td style="text-align:right"><?= formatCurrency($price) ?></td>
          <td><?= formatCurrency($lineAmt) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr>
          <td>
            <div class="item-name">Subscription Services</div>
            <div class="item-desc"><?= e(APP_NAME) ?> — <?= date('F Y', strtotime($invoice['created_at'] ?? 'now')) ?></div>
          </td>
          <td style="text-align:center">1</td>
          <td style="text-align:right"><?= formatCurrency($subtotal) ?></td>
          <td><?= formatCurrency($subtotal) ?></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>

    <!-- Totals -->
    <div class="totals-block">
      <table class="totals-table">
        <tr class="label-col">
          <td>Subtotal</td>
          <td><?= formatCurrency($subtotal) ?></td>
        </tr>
        <?php if ($taxAmount > 0): ?>
        <tr class="label-col">
          <td>VAT (<?= (int)($cfg['invoice_tax_rate'] ?? 16) ?>%)</td>
          <td><?= formatCurrency($taxAmount) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($invoice['status'] === 'paid'): ?>
        <tr class="label-col">
          <td style="color:#16a34a">Amount Paid</td>
          <td style="color:#16a34a"><?= formatCurrency($totalDue) ?></td>
        </tr>
        <tr class="label-col">
          <td style="color:#16a34a">Balance Due</td>
          <td style="color:#16a34a"><?= formatCurrency(0) ?></td>
        </tr>
        <?php else: ?>
        <tr class="total-row">
          <td>Total Due</td>
          <td><?= formatCurrency($totalDue) ?></td>
        </tr>
        <?php endif; ?>
      </table>
    </div>

    <!-- Payment Instructions -->
    <?php if ($hasPayment && $invoice['status'] !== 'paid'): ?>
    <div class="payment-section">
      <div class="section-title"><i class="fas fa-credit-card" style="margin-right:4px"></i>How to Pay</div>
      <div class="payment-grid">
        <?php if ($mpesaPaybill): ?>
        <div class="payment-card">
          <div class="pay-title"><i class="fas fa-mobile-alt" style="color:#16a34a"></i> M-Pesa</div>
          <div class="pay-highlight"><?= e($mpesaPaybill) ?></div>
          <div class="pay-row"><span>Paybill</span><span><?= e($mpesaPaybill) ?></span></div>
          <div class="pay-row"><span>Account No.</span><span><?= e($mpesaRef) === 'Invoice Number' ? e($invoice['invoice_number'] ?? '') : e($mpesaRef) ?></span></div>
          <div class="pay-row"><span>Amount</span><span><?= formatCurrency($totalDue) ?></span></div>
        </div>
        <?php endif; ?>
        <?php if ($bankAccount): ?>
        <div class="payment-card">
          <div class="pay-title"><i class="fas fa-university" style="color:#1d4ed8"></i> Bank Transfer</div>
          <?php if ($bankName): ?><div class="pay-highlight"><?= e($bankName) ?></div><?php endif; ?>
          <div class="pay-row"><span>Account No.</span><span><?= e($bankAccount) ?></span></div>
          <?php if ($bankBranch): ?><div class="pay-row"><span>Branch</span><span><?= e($bankBranch) ?></span></div><?php endif; ?>
          <div class="pay-row"><span>Reference</span><span><?= e($invoice['invoice_number'] ?? '') ?></span></div>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($supportEmail): ?>
      <div style="margin-top:12px;font-size:12.5px;color:#64748b;text-align:center">
        <i class="fas fa-envelope" style="margin-right:4px"></i>
        Payment queries: <a href="mailto:<?= e($supportEmail) ?>" style="color:#1A8A4E"><?= e($supportEmail) ?></a>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Notes -->
    <?php if (!empty($invoice['notes']) || $invoiceFooter): ?>
    <div class="inv-notes">
      <?php if (!empty($invoice['notes'])): ?>
      <strong>Notes</strong>
      <?= nl2br(e($invoice['notes'])) ?>
      <?php endif; ?>
      <?php if ($invoiceFooter && !empty($invoice['notes'])): ?><hr style="border:none;border-top:1px solid #fde68a;margin:8px 0"><?php endif; ?>
      <?php if ($invoiceFooter): ?><span style="font-size:12px"><?= e($invoiceFooter) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="inv-footer">
      <strong><?= e(APP_NAME) ?></strong> &bull; <?= e(APP_URL) ?>
      <?php if ($companyAddr): ?> &bull; <?= e(str_replace("\n", ' ', $companyAddr)) ?><?php endif; ?>
      <br>
      <span>Generated <?= date('d M Y H:i') ?></span>
    </div>

  </div><!-- /inv-body -->
</div><!-- /invoice-wrap -->

</body>
</html>
<?php exit; ?>
