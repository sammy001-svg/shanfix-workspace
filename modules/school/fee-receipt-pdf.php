<?php
/**
 * School Module — Fee Payment Receipt (print-friendly HTML)
 * Access: staff/admin with school module access, OR parent session, OR student session.
 * GET: ?payment_id=X   (single payment receipt)
 *  OR: ?fee_id=X       (all payments for a fee invoice)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$paymentId = (int)($_GET['payment_id'] ?? 0);
$feeId     = (int)($_GET['fee_id']     ?? 0);
$orgId     = 0;
$isStaff   = false;

// ── Auth ─────────────────────────────────────────────────────────
if (!empty($_SESSION['user_id'])) {
    requireModuleAccess('school');
    $staffUser = currentUser();
    $orgId     = (int)$staffUser['org_id'];
    $isStaff   = true;
} elseif (!empty($_SESSION['par_id'])) {
    $orgId = (int)$_SESSION['par_org_id'];
} elseif (!empty($_SESSION['stu_id'])) {
    $orgId = (int)$_SESSION['stu_org_id'];
} else {
    redirect(APP_URL . '/auth/login.php');
}

// ── Load payment(s) ───────────────────────────────────────────────
$payments = [];
try {
    if ($paymentId) {
        $s = $pdo->prepare(
            "SELECT fp.*, s.first_name, s.last_name, s.admission_no,
                    c.name AS class_name,
                    f.fee_type, f.term, f.amount AS invoice_amount, f.balance AS fee_balance,
                    f.currency AS invoice_currency
             FROM sch_fee_payments fp
             JOIN sch_students s ON s.id = fp.student_id
             LEFT JOIN sch_fees f ON f.id = fp.fee_id
             LEFT JOIN sch_classes c ON c.id = s.class_id
             WHERE fp.id=? AND fp.org_id=? LIMIT 1"
        );
        $s->execute([$paymentId, $orgId]);
        $payments = $s->fetchAll();
    } elseif ($feeId) {
        $s = $pdo->prepare(
            "SELECT fp.*, s.first_name, s.last_name, s.admission_no,
                    c.name AS class_name,
                    f.fee_type, f.term, f.amount AS invoice_amount, f.balance AS fee_balance,
                    f.currency AS invoice_currency
             FROM sch_fee_payments fp
             JOIN sch_students s ON s.id = fp.student_id
             LEFT JOIN sch_fees f ON f.id = fp.fee_id
             LEFT JOIN sch_classes c ON c.id = s.class_id
             WHERE fp.fee_id=? AND fp.org_id=?
             ORDER BY fp.payment_date ASC"
        );
        $s->execute([$feeId, $orgId]);
        $payments = $s->fetchAll();
    }
} catch (Throwable $e) {}

if (empty($payments)) {
    http_response_code(404);
    exit('<p style="font-family:sans-serif;padding:2rem;color:#666">Receipt not found or access denied.</p>');
}

// ── Parent/student ownership check ────────────────────────────────
if (!$isStaff) {
    $allowedStudentId = null;
    if (!empty($_SESSION['par_id'])) {
        $allowedStudentId = (int)$_SESSION['par_active_student'] ?? null;
        $parSids = $_SESSION['par_sids'] ?? [];
        if (!in_array((int)$payments[0]['student_id'], $parSids, true)) {
            http_response_code(403); exit('Access denied.');
        }
    } elseif (!empty($_SESSION['stu_id'])) {
        if ((int)$_SESSION['stu_id'] !== (int)$payments[0]['student_id']) {
            http_response_code(403); exit('Access denied.');
        }
    }
}

// ── School info ───────────────────────────────────────────────────
$school = [];
try {
    $s = $pdo->prepare("SELECT * FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$orgId]);
    $school = $s->fetch() ?: [];
} catch (Throwable $e) {}

$student  = $payments[0];
$feeType  = ucfirst(str_replace('-', ' ', $student['fee_type'] ?? ''));
$currency = $payments[0]['currency'] ?? 'KES';
$sym      = ['KES'=>'KES ','USD'=>'$','LRD'=>'L$'][$currency] ?? $currency . ' ';
$totalPaid = array_sum(array_column($payments, 'amount'));

function methodLabel(string $m): string {
    return ['cash'=>'Cash','mpesa'=>'M-Pesa','bank-transfer'=>'Bank Transfer',
            'card'=>'Card','cheque'=>'Cheque','online'=>'Online','other'=>'Other'][$m] ?? ucfirst($m);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fee Receipt — <?= e($payments[0]['receipt_no'] ?? 'REC') ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 12px;
    color: #1a1a1a;
    background: #f4f4f4;
}
.page {
    max-width: 700px;
    margin: 24px auto;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    overflow: hidden;
}
/* Header */
.receipt-header {
    background: #0B2D4E;
    color: #fff;
    padding: 24px 28px;
    display: flex;
    align-items: center;
    gap: 20px;
}
.school-logo {
    width: 56px;
    height: 56px;
    object-fit: contain;
    border-radius: 6px;
    background: #fff;
    padding: 4px;
    flex-shrink: 0;
}
.school-initials {
    width: 56px;
    height: 56px;
    background: #1A8A4E;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    font-weight: 700;
    color: #fff;
    flex-shrink: 0;
}
.school-name { font-size: 18px; font-weight: 700; line-height: 1.2; }
.school-sub  { font-size: 11px; color: rgba(255,255,255,.75); margin-top: 3px; }
.receipt-badge {
    margin-left: auto;
    text-align: right;
    flex-shrink: 0;
}
.receipt-badge .badge-label { font-size: 10px; color: rgba(255,255,255,.6); text-transform: uppercase; letter-spacing: 1px; }
.receipt-badge .badge-value { font-size: 20px; font-weight: 700; color: #6ee7b7; }

/* Stamp band */
.stamp-band {
    background: #1A8A4E;
    color: #fff;
    text-align: center;
    padding: 6px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
}

/* Body */
.receipt-body { padding: 24px 28px; }

/* Info grid */
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 20px;
}
.info-cell {
    padding: 10px 14px;
    border-right: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
}
.info-cell:nth-child(2n) { border-right: none; }
.info-cell:nth-last-child(-n+2) { border-bottom: none; }
.info-label  { font-size: 10px; color: #9ca3af; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 3px; }
.info-value  { font-size: 12.5px; font-weight: 600; color: #111; }

/* Payment table */
.pay-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}
.pay-table th {
    background: #f9fafb;
    padding: 8px 12px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: #6b7280;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
}
.pay-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 12px;
    vertical-align: middle;
}
.pay-table tr:last-child td { border-bottom: none; }
.pay-table .amount-cell { text-align: right; font-weight: 700; font-size: 13px; color: #1A8A4E; }
.badge-method {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 10.5px;
    font-weight: 600;
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #e5e7eb;
}

/* Total bar */
.total-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 6px;
    padding: 12px 16px;
    margin-bottom: 20px;
}
.total-label { font-size: 11px; color: #166534; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; }
.total-value { font-size: 20px; font-weight: 700; color: #1A8A4E; }

/* Balance note */
.balance-note {
    background: #fef9c3;
    border: 1px solid #fde68a;
    border-radius: 6px;
    padding: 10px 14px;
    font-size: 11.5px;
    color: #92400e;
    margin-bottom: 20px;
}
.balance-note.paid {
    background: #f0fdf4;
    border-color: #bbf7d0;
    color: #166534;
}

/* Footer */
.receipt-footer {
    border-top: 1px solid #e5e7eb;
    padding: 16px 28px;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
}
.footer-note { font-size: 10.5px; color: #9ca3af; max-width: 300px; line-height: 1.5; }
.auth-block  { text-align: right; }
.auth-line   { border-top: 1px solid #9ca3af; margin-top: 30px; padding-top: 4px; font-size: 10px; color: #9ca3af; }

/* Print actions */
.print-actions {
    text-align: center;
    padding: 16px;
    background: #f9fafb;
    border-top: 1px solid #e5e7eb;
}
.btn-print {
    background: #0B2D4E;
    color: #fff;
    border: none;
    padding: 9px 24px;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    font-weight: 600;
}
.btn-back {
    background: transparent;
    color: #6b7280;
    border: 1px solid #d1d5db;
    padding: 9px 20px;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    margin-right: 8px;
    text-decoration: none;
    display: inline-block;
}

@media print {
    body { background: #fff; }
    .print-actions { display: none; }
    .page { margin: 0; border: none; border-radius: 0; max-width: 100%; }
    @page { size: A4; margin: 1.5cm; }
}
</style>
</head>
<body>

<div class="page">

  <!-- Header -->
  <div class="receipt-header">
    <?php if (!empty($school['logo'])): ?>
    <img src="<?= e(APP_URL . '/assets/uploads/logos/' . $school['logo']) ?>" alt="Logo" class="school-logo">
    <?php else: ?>
    <div class="school-initials"><?= strtoupper(substr($school['name'] ?? 'S', 0, 1)) ?></div>
    <?php endif; ?>
    <div>
      <div class="school-name"><?= e($school['name'] ?? 'School Name') ?></div>
      <div class="school-sub">
        <?= e($school['address'] ?? '') ?>
        <?php if (!empty($school['phone'])): ?>&nbsp;&bull; <?= e($school['phone']) ?><?php endif; ?>
      </div>
    </div>
    <div class="receipt-badge">
      <div class="badge-label">Receipt No.</div>
      <div class="badge-value"><?= e($payments[0]['receipt_no'] ?? '—') ?></div>
    </div>
  </div>

  <div class="stamp-band">Official Fee Payment Receipt</div>

  <div class="receipt-body">

    <!-- Student / fee info -->
    <div class="info-grid">
      <div class="info-cell">
        <div class="info-label">Student Name</div>
        <div class="info-value"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></div>
      </div>
      <div class="info-cell">
        <div class="info-label">Admission No.</div>
        <div class="info-value"><?= e($student['admission_no'] ?? '—') ?></div>
      </div>
      <div class="info-cell">
        <div class="info-label">Class</div>
        <div class="info-value"><?= e($student['class_name'] ?? '—') ?></div>
      </div>
      <div class="info-cell">
        <div class="info-label">Fee Type</div>
        <div class="info-value"><?= e($feeType) ?></div>
      </div>
      <?php if (!empty($student['term'])): ?>
      <div class="info-cell">
        <div class="info-label">Term / Period</div>
        <div class="info-value"><?= e($student['term']) ?></div>
      </div>
      <?php endif; ?>
      <div class="info-cell">
        <div class="info-label">Invoice Amount</div>
        <div class="info-value"><?= e($sym) ?><?= number_format((float)($student['invoice_amount'] ?? 0), 2) ?></div>
      </div>
    </div>

    <!-- Payment(s) table -->
    <table class="pay-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Payment Date</th>
          <th>Method</th>
          <th>Paid By</th>
          <th style="text-align:right">Amount Paid (<?= e($currency) ?>)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($payments as $i => $pay):
          $amount = (float)($pay['amount'] ?? $pay['amount_paid'] ?? 0);
        ?>
        <tr>
          <td style="color:#9ca3af"><?= $i + 1 ?></td>
          <td><?= $pay['payment_date'] ? date('d M Y', strtotime($pay['payment_date'])) : '—' ?></td>
          <td><span class="badge-method"><?= e(methodLabel($pay['payment_method'] ?? '')) ?></span></td>
          <td><?= e($pay['paid_by'] ?: '—') ?></td>
          <td class="amount-cell"><?= e($sym) ?><?= number_format($amount, 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Total -->
    <div class="total-bar">
      <div class="total-label">Total Amount Received</div>
      <div class="total-value"><?= e($sym) ?><?= number_format($totalPaid, 2) ?></div>
    </div>

    <!-- Balance note -->
    <?php
    $balance = (float)($student['fee_balance'] ?? 0);
    $isPaid  = $balance <= 0;
    ?>
    <div class="balance-note <?= $isPaid ? 'paid' : '' ?>">
      <?php if ($isPaid): ?>
      <strong>✓ PAID IN FULL</strong> — This invoice has been fully settled. Thank you!
      <?php else: ?>
      <strong>Outstanding Balance:</strong> <?= e($sym) ?><?= number_format($balance, 2) ?> remains unpaid on this invoice. Please settle at the school office.
      <?php endif; ?>
    </div>

    <?php if (!empty($payments[0]['notes'])): ?>
    <div style="font-size:11px;color:#6b7280;margin-bottom:16px">
      <strong>Notes:</strong> <?= e($payments[0]['notes']) ?>
    </div>
    <?php endif; ?>

  </div>

  <!-- Footer -->
  <div class="receipt-footer">
    <div class="footer-note">
      This is an official receipt issued by <?= e($school['name'] ?? 'the school') ?>.
      Please retain this for your records. For inquiries, contact the school accounts office.
    </div>
    <div class="auth-block">
      <div class="auth-line">Authorized Signature</div>
      <div style="font-size:10px;color:#9ca3af;margin-top:3px">School Accounts Office</div>
      <div style="font-size:10px;color:#9ca3af">Printed: <?= date('d M Y, H:i') ?></div>
    </div>
  </div>

  <!-- Print / Back actions (hidden on print) -->
  <div class="print-actions">
    <a href="javascript:history.back()" class="btn-back">← Back</a>
    <button class="btn-print" onclick="window.print()">
      <span>🖨</span> Print Receipt
    </button>
  </div>

</div>

</body>
</html>
