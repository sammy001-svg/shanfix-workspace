<?php
/**
 * modules/tour/_document.php
 * Shared branded A4 document renderer for tour quotations and invoices.
 * Outputs a standalone printable page and exits.
 *
 * Expects the caller to define:
 *   $docType   string  'QUOTATION' | 'INVOICE'
 *   $docNo     string
 *   $docStatus string
 *   $org       array   name, logo, email, phone, city, country
 *   $party     array   name, phone, email
 *   $dateRows  array   [['Issue Date','12 Aug 2026'], ...]
 *   $tripRows  array   [['Package','Masai Mara 3-day'], ...]   (may be empty)
 *   $items     array   [['description'=>,'quantity'=>,'unit_price'=>,'line_total'=>], ...]
 *   $totals    array   subtotal, discount, tax_label, tax_rate, tax_amount, total, paid, balance
 *   $notes     string
 *   $terms     string
 *   $backUrl   string
 *   $accent    string  hex colour
 */

$accent   = $accent   ?? '#2980b9';
$backUrl  = $backUrl  ?? 'index.php';
$showPaid = array_key_exists('paid', $totals);

$statusColors = [
    'paid'      => ['#dcfce7', '#15803d', '#86efac'],
    'converted' => ['#dcfce7', '#15803d', '#86efac'],
    'accepted'  => ['#dcfce7', '#15803d', '#86efac'],
    'overdue'   => ['#fee2e2', '#dc2626', '#fca5a5'],
    'declined'  => ['#fee2e2', '#dc2626', '#fca5a5'],
    'partial'   => ['#fef3c7', '#b45309', '#fcd34d'],
    'sent'      => ['#dbeafe', '#1d4ed8', '#93c5fd'],
    'draft'     => ['#f1f5f9', '#64748b', '#cbd5e1'],
    'expired'   => ['#f1f5f9', '#94a3b8', '#e2e8f0'],
    'cancelled' => ['#f1f5f9', '#94a3b8', '#e2e8f0'],
];
$sc = $statusColors[strtolower($docStatus)] ?? $statusColors['draft'];

$orgInitials = strtoupper(implode('', array_map(
    fn($w) => substr($w, 0, 1),
    array_slice(explode(' ', trim($org['name'] ?? 'Tour')), 0, 2)
)));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($docType . ' ' . $docNo) ?></title>
<meta name="robots" content="noindex, nofollow">
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 24px 16px 60px;
    background: #eef1f5;
    font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #1f2937; font-size: 13px; line-height: 1.55;
  }
  .toolbar {
    max-width: 800px; margin: 0 auto 16px;
    display: flex; gap: 8px; justify-content: flex-end;
  }
  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 8px; border: 1px solid #cbd5e1;
    background: #fff; color: #334155; text-decoration: none;
    font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .btn-primary { background: <?= e($accent) ?>; border-color: <?= e($accent) ?>; color: #fff; }

  .sheet {
    max-width: 800px; margin: 0 auto; background: #fff;
    border-radius: 10px; box-shadow: 0 2px 14px rgba(15,23,42,.08);
    padding: 40px 44px;
  }

  .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
  .brand { display: flex; gap: 14px; align-items: center; }
  .logo {
    width: 54px; height: 54px; border-radius: 10px; object-fit: cover;
    background: <?= e($accent) ?>; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 18px; letter-spacing: .5px;
  }
  .org-name { font-size: 17px; font-weight: 700; color: #0f172a; }
  .org-meta { font-size: 11.5px; color: #64748b; }

  .doc-title { text-align: right; }
  .doc-title h1 {
    margin: 0; font-size: 26px; letter-spacing: 2px;
    color: <?= e($accent) ?>; text-transform: uppercase;
  }
  .doc-no { font-size: 13px; font-weight: 600; color: #475569; margin-top: 2px; }
  .status {
    display: inline-block; margin-top: 8px; padding: 3px 12px; border-radius: 999px;
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px;
    background: <?= $sc[0] ?>; color: <?= $sc[1] ?>; border: 1px solid <?= $sc[2] ?>;
  }

  hr.rule { border: 0; border-top: 1px solid #e2e8f0; margin: 26px 0; }

  .cols { display: flex; gap: 32px; }
  .cols > div { flex: 1; }
  .label {
    font-size: 10.5px; font-weight: 700; letter-spacing: .8px;
    text-transform: uppercase; color: <?= e($accent) ?>; margin-bottom: 6px;
  }
  .party-name { font-size: 14px; font-weight: 700; color: #0f172a; }
  .muted { color: #64748b; font-size: 12px; }

  table.kv { width: 100%; border-collapse: collapse; }
  table.kv td { padding: 2px 0; font-size: 12px; vertical-align: top; }
  table.kv td:first-child { color: #64748b; padding-right: 12px; white-space: nowrap; }
  table.kv td:last-child { font-weight: 600; text-align: right; }

  table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
  table.items thead th {
    background: <?= e($accent) ?>; color: #fff; font-size: 11px;
    text-transform: uppercase; letter-spacing: .5px;
    padding: 9px 10px; text-align: left;
  }
  table.items thead th.r { text-align: right; }
  table.items tbody td { padding: 9px 10px; border-bottom: 1px solid #eef2f7; font-size: 12.5px; }
  table.items tbody td.r { text-align: right; }
  table.items tbody tr:nth-child(even) { background: #fafbfc; }

  .totals { margin-top: 18px; display: flex; justify-content: flex-end; }
  .totals table { width: 300px; border-collapse: collapse; }
  .totals td { padding: 5px 0; font-size: 12.5px; }
  .totals td:last-child { text-align: right; font-weight: 600; }
  .totals tr.grand td {
    border-top: 2px solid <?= e($accent) ?>; padding-top: 10px;
    font-size: 16px; font-weight: 700; color: <?= e($accent) ?>;
  }
  .totals tr.balance td { font-size: 14px; font-weight: 700; color: #b91c1c; }

  .block { margin-top: 26px; }
  .block .body { font-size: 12px; color: #475569; white-space: pre-line; }

  .foot {
    margin-top: 34px; padding-top: 16px; border-top: 1px solid #e2e8f0;
    text-align: center; font-size: 11px; color: #94a3b8;
  }

  @media print {
    body { background: #fff; padding: 0; }
    .toolbar { display: none; }
    .sheet { box-shadow: none; border-radius: 0; max-width: none; padding: 0; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <a href="<?= e($backUrl) ?>" class="btn">&larr; Back</a>
  <button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="sheet">
  <div class="head">
    <div class="brand">
      <?php if (!empty($org['logo'])): ?>
        <img src="<?= e($org['logo']) ?>" alt="" class="logo">
      <?php else: ?>
        <div class="logo"><?= e($orgInitials) ?></div>
      <?php endif; ?>
      <div>
        <div class="org-name"><?= e($org['name'] ?? '') ?></div>
        <?php if (!empty($org['tagline'])): ?>
        <div class="org-meta"><?= e($org['tagline']) ?></div>
        <?php endif; ?>
        <div class="org-meta">
          <?= e(implode(' · ', array_filter([$org['phone'] ?? '', $org['email'] ?? '']))) ?>
        </div>
        <?php $loc = implode(', ', array_filter([$org['city'] ?? '', $org['country'] ?? ''])); ?>
        <?php if ($loc): ?><div class="org-meta"><?= e($loc) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="doc-title">
      <h1><?= e($docType) ?></h1>
      <div class="doc-no"><?= e($docNo) ?></div>
      <div class="status"><?= e(ucwords(str_replace('_', ' ', $docStatus))) ?></div>
    </div>
  </div>

  <hr class="rule">

  <div class="cols">
    <div>
      <div class="label"><?= $docType === 'INVOICE' ? 'Billed To' : 'Prepared For' ?></div>
      <div class="party-name"><?= e($party['name'] ?? '') ?></div>
      <?php if (!empty($party['phone'])): ?><div class="muted"><?= e($party['phone']) ?></div><?php endif; ?>
      <?php if (!empty($party['email'])): ?><div class="muted"><?= e($party['email']) ?></div><?php endif; ?>
    </div>
    <div>
      <div class="label">Details</div>
      <table class="kv">
        <?php foreach ($dateRows as $row): ?>
        <tr><td><?= e($row[0]) ?></td><td><?= e($row[1]) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <?php if (!empty($tripRows)): ?>
  <div class="block">
    <div class="label">Trip Summary</div>
    <div class="cols">
      <div>
        <table class="kv">
          <?php foreach (array_slice($tripRows, 0, (int)ceil(count($tripRows) / 2)) as $row): ?>
          <tr><td><?= e($row[0]) ?></td><td><?= e($row[1]) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
      <div>
        <table class="kv">
          <?php foreach (array_slice($tripRows, (int)ceil(count($tripRows) / 2)) as $row): ?>
          <tr><td><?= e($row[0]) ?></td><td><?= e($row[1]) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="block">
    <table class="items">
      <thead>
        <tr>
          <th style="width:55%">Description</th>
          <th class="r" style="width:10%">Qty</th>
          <th class="r" style="width:17%">Unit Price</th>
          <th class="r" style="width:18%">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
        <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:20px">No line items.</td></tr>
        <?php else: foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['description']) ?></td>
          <td class="r"><?= rtrim(rtrim(number_format((float)$it['quantity'], 2), '0'), '.') ?></td>
          <td class="r"><?= formatCurrency((float)$it['unit_price']) ?></td>
          <td class="r"><?= formatCurrency((float)$it['line_total']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <div class="totals">
      <table>
        <tr><td>Subtotal</td><td><?= formatCurrency((float)$totals['subtotal']) ?></td></tr>
        <?php if ((float)$totals['discount'] > 0): ?>
        <tr><td>Discount</td><td>&minus; <?= formatCurrency((float)$totals['discount']) ?></td></tr>
        <?php endif; ?>
        <?php if ((float)$totals['tax_rate'] > 0): ?>
        <tr>
          <td><?= e($totals['tax_label']) ?> (<?= rtrim(rtrim(number_format((float)$totals['tax_rate'], 2), '0'), '.') ?>%)</td>
          <td><?= formatCurrency((float)$totals['tax_amount']) ?></td>
        </tr>
        <?php endif; ?>
        <tr class="grand"><td><?= $docType === 'INVOICE' ? 'Total Due' : 'Total' ?></td><td><?= formatCurrency((float)$totals['total']) ?></td></tr>
        <?php if ($showPaid): ?>
        <tr><td>Amount Paid</td><td><?= formatCurrency((float)$totals['paid']) ?></td></tr>
        <?php if ((float)$totals['balance'] > 0): ?>
        <tr class="balance"><td>Balance Outstanding</td><td><?= formatCurrency((float)$totals['balance']) ?></td></tr>
        <?php else: ?>
        <tr><td style="color:#15803d;font-weight:700">Settled In Full</td><td style="color:#15803d">&#10003;</td></tr>
        <?php endif; ?>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <?php if (trim((string)$notes) !== ''): ?>
  <div class="block">
    <div class="label">Notes</div>
    <div class="body"><?= e($notes) ?></div>
  </div>
  <?php endif; ?>

  <?php if (trim((string)$terms) !== ''): ?>
  <div class="block">
    <div class="label">Terms &amp; Conditions</div>
    <div class="body"><?= e($terms) ?></div>
  </div>
  <?php endif; ?>

  <div class="foot">
    <?= e($org['name'] ?? '') ?> &mdash; generated <?= date('d M Y') ?>
  </div>
</div>

</body>
</html>
<?php exit;
