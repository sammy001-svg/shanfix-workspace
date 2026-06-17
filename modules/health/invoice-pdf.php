<?php
/**
 * Health Invoice / Bill PDF
 * Auth: admin OR patient (own bills)
 * GET: id (bill id)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$billId = (int)($_GET['id'] ?? 0);
if (!$billId) { http_response_code(404); exit('Bill not found.'); }

$isAdmin   = isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['super_admin','admin','client_admin','staff']);
$isPatient = !empty($_SESSION['patient_id']) && ($_SESSION['user_role'] ?? '') === 'patient';

if (!$isAdmin && !$isPatient) {
    redirect(APP_URL . '/auth/login.php');
}

$bill = null; $items = [];
try {
    $orgId = $isAdmin ? (int)currentUser()['org_id'] : (int)$_SESSION['org_id'];

    // Load health currency for this org
    try {
        $__cs = $pdo->prepare("SELECT setting_value FROM health_settings WHERE org_id=? AND setting_key='h_currency_symbol' LIMIT 1");
        $__cs->execute([$orgId]);
        $GLOBALS['hCurrencySymbol'] = $__cs->fetchColumn() ?: (defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'LRD');
    } catch (Throwable $__e) {
        $GLOBALS['hCurrencySymbol'] = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'LRD';
    }

    $s = $pdo->prepare("
        SELECT b.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
               p.phone AS patient_phone, p.email AS patient_email,
               p.date_of_birth, p.address AS patient_address,
               o.name AS org_name, o.address AS org_address, o.phone AS org_phone,
               o.email AS org_email, o.logo AS org_logo, o.city, o.country
        FROM health_bills b
        JOIN health_patients p ON p.id=b.patient_id
        JOIN organizations o ON o.id=b.org_id
        WHERE b.id=? AND b.org_id=?
    ");
    $s->execute([$billId, $orgId]);
    $bill = $s->fetch();

    if ($bill) {
        // Patient auth: own bills only
        if ($isPatient && !$isAdmin && (int)$bill['patient_id'] !== (int)$_SESSION['patient_id']) {
            http_response_code(403); exit('Access denied.');
        }

        $s = $pdo->prepare("SELECT * FROM health_bill_items WHERE bill_id=? ORDER BY id");
        $s->execute([$billId]);
        $items = $s->fetchAll();
    }
} catch (Throwable $e) {}

if (!$bill) { http_response_code(404); exit('Bill not found.'); }

$balance  = (float)$bill['total_amount'] - (float)$bill['paid_amount'];
$initials = strtoupper(implode('', array_map(fn($w)=>substr($w,0,1), array_slice(explode(' ', $bill['org_name']),0,2))));
$statusColors = ['draft'=>'#6c757d','sent'=>'#0dcaf0','partial'=>'#ffc107','paid'=>'#198754','cancelled'=>'#dc3545','overdue'=>'#dc3545'];
$accentBg = $statusColors[$bill['status']] ?? '#1a4e7c';
$invoiceNo = $bill['bill_no'] ?? ('INV-' . str_pad($billId, 5, '0', STR_PAD_LEFT));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice <?= e($invoiceNo) ?> — <?= e($bill['org_name']) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
@page{size:A4;margin:1.2cm}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:10pt;color:#1a1a2e;background:#fff}
.actions{display:flex;gap:8px;padding:10px 16px;background:#f8f9fa;border-bottom:1px solid #ddd}
@media print{.actions{display:none}}
.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;border:none;cursor:pointer;font-size:8.5pt;font-weight:600;color:#fff}

.page{max-width:780px;margin:0 auto;padding:16px}

.letterhead{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px;padding-bottom:12px;border-bottom:3px solid #1a4e7c}
.org-logo-box{width:60px;height:60px;border-radius:12px;background:#1a4e7c;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem;font-weight:800;flex-shrink:0}
.org-logo-box img{width:56px;height:56px;object-fit:contain;border-radius:10px}
.org-col{padding-left:12px;flex:1}
.org-name{font-size:14pt;font-weight:800;color:#1a4e7c}
.org-sub{font-size:8pt;color:#666;margin-top:2px}
.inv-col{text-align:right}
.inv-title{font-size:18pt;font-weight:900;color:#1a4e7c;line-height:1}
.inv-no{font-size:8.5pt;color:#666;margin-top:4px}

.parties{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px}
.party-box{background:#f8f9fa;border-radius:6px;padding:10px 12px}
.party-label{font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#888;margin-bottom:5px}
.party-name{font-size:10.5pt;font-weight:700;color:#111}
.party-detail{font-size:8.5pt;color:#555;margin-top:2px}

.status-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:8pt;font-weight:700;color:#fff;background:<?= $accentBg ?>;margin-bottom:10px}

.items-table{width:100%;border-collapse:collapse;font-size:9.5pt;margin-bottom:12px}
.items-table th{background:#1a4e7c;color:#fff;font-size:8pt;text-transform:uppercase;letter-spacing:.3px;padding:7px 8px;text-align:left}
.items-table th:last-child{text-align:right}
.items-table td{padding:7px 8px;border-bottom:1px solid #e8f0f8;vertical-align:top}
.items-table td:last-child{text-align:right;font-weight:600}
.items-table tr:nth-child(even) td{background:#f8fbff}
.items-table tfoot td{font-weight:700;border-top:2px solid #1a4e7c;padding:8px}

.totals-box{display:flex;justify-content:flex-end}
.totals-inner{min-width:240px}
.totals-inner table{width:100%;font-size:9.5pt}
.totals-inner td{padding:4px 6px}
.totals-inner .net-row td{font-weight:800;font-size:11pt;border-top:2px solid #1a4e7c;padding-top:7px}
.totals-inner .net-row td:last-child{color:<?= $balance > 0 ? '#c0392b' : '#1a8a4e' ?>}

.paid-stamp{position:absolute;right:40px;bottom:200px;transform:rotate(-15deg);font-size:26pt;font-weight:900;color:rgba(25,135,84,.18);border:6px solid rgba(25,135,84,.18);border-radius:8px;padding:4px 14px;text-transform:uppercase;letter-spacing:2px}

.notes-box{background:#fffef0;border:1px solid #ece59a;border-radius:5px;padding:8px 12px;font-size:8.5pt;margin-bottom:10px}

.payment-info{background:#e8f0f8;border-radius:6px;padding:10px 14px;font-size:8.5pt;margin-bottom:14px}
.payment-info .pi-title{font-weight:700;font-size:9pt;color:#1a4e7c;margin-bottom:4px}

.doc-footer{margin-top:20px;padding-top:8px;border-top:1px solid #e0e0e0;font-size:7pt;color:#aaa;text-align:center}
</style>
</head>
<body>

<div class="actions">
  <button class="btn" style="background:#1a4e7c" onclick="window.print()">🖨 Print Invoice</button>
  <button class="btn" style="background:#6c757d" onclick="window.close()">Close</button>
</div>

<div class="page" style="position:relative">
  <?php if ($bill['status'] === 'paid'): ?>
  <div class="paid-stamp">PAID</div>
  <?php endif; ?>

  <!-- Letterhead -->
  <div class="letterhead">
    <div style="display:flex;align-items:center">
      <div class="org-logo-box">
        <?php if (!empty($bill['org_logo'])): ?><img src="<?= APP_URL ?>/uploads/logos/<?= e($bill['org_logo']) ?>" alt=""><?php else: ?><?= $initials ?><?php endif; ?>
      </div>
      <div class="org-col">
        <div class="org-name"><?= e($bill['org_name']) ?></div>
        <div class="org-sub">
          <?= e($bill['org_address'] ?? '') ?>
          <?php if ($bill['org_phone']): ?><br>Tel: <?= e($bill['org_phone']) ?><?php endif; ?>
          <?php if ($bill['org_email']): ?> &bull; <?= e($bill['org_email']) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="inv-col">
      <div class="inv-title">INVOICE</div>
      <div class="inv-no"><strong><?= e($invoiceNo) ?></strong><br>
        Date: <?= date('d M Y', strtotime($bill['created_at'])) ?>
        <?php if ($bill['due_date'] ?? ''): ?><br>Due: <?= date('d M Y', strtotime($bill['due_date'])) ?><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Parties -->
  <div class="parties">
    <div class="party-box">
      <div class="party-label">Billed To</div>
      <div class="party-name"><?= e($bill['patient_name']) ?></div>
      <div class="party-detail">Patient No: #<?= e($bill['patient_no'] ?? $bill['patient_id']) ?></div>
      <?php if ($bill['patient_phone']): ?><div class="party-detail">Tel: <?= e($bill['patient_phone']) ?></div><?php endif; ?>
      <?php if ($bill['patient_email']): ?><div class="party-detail"><?= e($bill['patient_email']) ?></div><?php endif; ?>
      <?php if ($bill['patient_address']): ?><div class="party-detail"><?= e($bill['patient_address']) ?></div><?php endif; ?>
    </div>
    <div class="party-box" style="text-align:right">
      <div class="party-label">From</div>
      <div class="party-name"><?= e($bill['org_name']) ?></div>
      <div class="party-detail"><?= e($bill['org_address'] ?? '') ?></div>
      <?php if ($bill['org_phone']): ?><div class="party-detail">Tel: <?= e($bill['org_phone']) ?></div><?php endif; ?>
    </div>
  </div>

  <div><span class="status-badge"><?= ucfirst($bill['status']) ?></span></div>

  <!-- Items -->
  <table class="items-table">
    <thead>
      <tr><th>#</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Amount</th></tr>
    </thead>
    <tbody>
      <?php if (empty($items)): ?>
      <tr><td colspan="5" style="text-align:center;color:#aaa;padding:16px">No itemized details available.</td></tr>
      <?php else: ?>
      <?php foreach ($items as $i => $it): ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td><?= e($it['description'] ?? $it['service_name'] ?? '—') ?></td>
        <td><?= e($it['quantity'] ?? 1) ?></td>
        <td><?= hMoney($it['unit_price'] ?? $it['amount'] ?? 0) ?></td>
        <td><?= hMoney(($it['unit_price'] ?? $it['amount'] ?? 0) * ($it['quantity'] ?? 1)) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- Totals -->
  <div class="totals-box">
    <div class="totals-inner">
      <table>
        <tr><td class="text-muted">Subtotal</td><td><?= hMoney($bill['total_amount']) ?></td></tr>
        <?php if ((float)($bill['discount_amount'] ?? 0) > 0): ?>
        <tr><td class="text-muted">Discount</td><td style="color:#dc3545">- <?= hMoney($bill['discount_amount']) ?></td></tr>
        <?php endif; ?>
        <tr><td class="text-muted">Paid</td><td style="color:#198754"><?= hMoney($bill['paid_amount']) ?></td></tr>
        <tr class="net-row">
          <td>Balance Due</td>
          <td><?= hMoney(max(0, $balance)) ?></td>
        </tr>
      </table>
    </div>
  </div>

  <?php if ($bill['notes'] ?? ''): ?>
  <div class="notes-box"><strong>Notes:</strong> <?= nl2br(e($bill['notes'])) ?></div>
  <?php endif; ?>

  <?php if ($balance > 0): ?>
  <div class="payment-info">
    <div class="pi-title"><i>&#128179;</i> Payment Information</div>
    Please present this invoice when making payment. Payment can be made at the billing office.<br>
    Quote invoice number <strong><?= e($invoiceNo) ?></strong> for all correspondence.
  </div>
  <?php endif; ?>

  <div class="doc-footer">
    Thank you for choosing <?= e($bill['org_name']) ?>. For enquiries, contact us at <?= e($bill['org_phone'] ?? '') ?>.
    <br>Invoice <?= e($invoiceNo) ?> &bull; Generated <?= date('d M Y H:i') ?>
  </div>
</div>

</body>
</html>
