<?php
/**
 * School Module — Employee Payslip (print-friendly HTML)
 * GET: ?id=X  (payslip ID)
 * Auth: admin staff OR teacher viewing own payslips
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$payslipId = (int)($_GET['id'] ?? 0);
$orgId     = 0;
$isStaff   = false;
$ownStaffId   = null;
$ownStaffType = null;

// ── Auth ──────────────────────────────────────────────────────────
if (!empty($_SESSION['user_id'])) {
    requireModuleAccess('school');
    $u = currentUser();
    $orgId   = (int)$u['org_id'];
    $isStaff = true;
} elseif (!empty($_SESSION['tch_id'])) {
    $orgId        = (int)$_SESSION['tch_org_id'];
    $ownStaffId   = (int)$_SESSION['tch_id'];
    $ownStaffType = 'teacher';
} else {
    redirect(APP_URL . '/auth/login.php');
}

if (!$payslipId) exit('Payslip ID required.');

// ── Load payslip ──────────────────────────────────────────────────
$ps = null;
try {
    $s = $pdo->prepare(
        "SELECT ps.*, pr.period_month, pr.period_year
         FROM sch_payslips ps
         JOIN sch_payroll_runs pr ON pr.id = ps.payroll_run_id
         WHERE ps.id=? AND ps.org_id=? LIMIT 1"
    );
    $s->execute([$payslipId, $orgId]);
    $ps = $s->fetch() ?: null;
} catch (Throwable $e) {}

if (!$ps) { http_response_code(404); exit('Payslip not found.'); }

// Teachers may only view their own payslips
if (!$isStaff && ($ownStaffType !== $ps['staff_type'] || $ownStaffId !== (int)$ps['staff_id'])) {
    http_response_code(403); exit('Access denied.');
}

// Only show approved/paid payslips to teachers
if (!$isStaff && !in_array($ps['status'], ['approved','paid'])) {
    exit('This payslip is not yet available.');
}

// ── School info ───────────────────────────────────────────────────
$school = [];
try {
    $s = $pdo->prepare("SELECT * FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$orgId]); $school = $s->fetch() ?: [];
} catch (Throwable $e) {}

$monthNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$cur = $ps['currency'] ?? 'KES';
function fmtAmt(float $v, string $c): string {
    return $c . ' ' . number_format($v, 2);
}
function rowIfNonZero(string $label, float $val, string $cur, string $cls=''): string {
    if ($val <= 0) return '';
    return '<tr><td>'.$label.'</td><td class="num '.$cls.'">'.fmtAmt($val,$cur).'</td></tr>';
}
$refNo = 'PAY-' . str_pad($ps['period_year'],4,'0',STR_PAD_LEFT)
       . str_pad($ps['period_month'],2,'0',STR_PAD_LEFT)
       . '-' . str_pad($ps['id'],4,'0',STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Payslip — <?= e($ps['staff_name']) ?> — <?= $monthNames[(int)$ps['period_month']] ?> <?= $ps['period_year'] ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:12px;color:#1a1a1a;background:#f0f2f5}
.page{max-width:700px;margin:24px auto;background:#fff;border:1px solid #dde3ec;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)}

/* Header */
.doc-header{background:#0B2D4E;color:#fff;padding:24px 28px;display:flex;align-items:center;gap:18px}
.logo-box{width:56px;height:56px;background:#1A8A4E;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:#fff;flex-shrink:0}
.logo-img{width:56px;height:56px;object-fit:contain;border-radius:6px;background:#fff;padding:4px;flex-shrink:0}
.school-name{font-size:17px;font-weight:700;line-height:1.2}
.school-sub{font-size:10.5px;color:rgba(255,255,255,.7);margin-top:3px}
.doc-ref{margin-left:auto;text-align:right;flex-shrink:0}
.doc-ref-label{font-size:9.5px;color:rgba(255,255,255,.55);text-transform:uppercase;letter-spacing:.8px}
.doc-ref-val{font-size:15px;font-weight:700;color:#6ee7b7}

/* Band */
.band{background:#1A8A4E;color:#fff;text-align:center;padding:7px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:2.5px}

.body{padding:24px 28px}

/* Employee card */
.emp-card{display:grid;grid-template-columns:1fr 1fr;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;margin-bottom:20px}
.emp-cell{padding:9px 13px;border-right:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb}
.emp-cell:nth-child(2n){border-right:none}
.emp-cell:nth-last-child(-n+2){border-bottom:none}
.cell-label{font-size:9.5px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.cell-val{font-size:12.5px;font-weight:600;color:#111}

/* Earnings / deductions tables */
.section-title{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6b7280;margin:16px 0 8px;padding-bottom:4px;border-bottom:1px solid #e5e7eb}
.earn-table{width:100%;border-collapse:collapse;margin-bottom:0}
.earn-table td{padding:7px 10px;border-bottom:1px solid #f3f4f6;font-size:12px;vertical-align:middle}
.earn-table td:first-child{color:#374151}
.earn-table td.num{text-align:right;font-variant-numeric:tabular-nums}
.earn-table tr:last-child td{border-bottom:none}
.earn-table .subtotal td{background:#f9fafb;font-weight:700;font-size:12.5px}
.earn-table .subtotal .num{color:#1A8A4E}
.earn-table .deduct-total td{background:#fef2f2;font-weight:700}
.earn-table .deduct-total .num{color:#dc2626}

/* Two-column layout */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px}

/* Net pay */
.netpay{display:flex;justify-content:space-between;align-items:center;background:#f0fdf4;border:2px solid #86efac;border-radius:8px;padding:14px 18px;margin-top:20px}
.netpay-label{font-size:12px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.5px}
.netpay-val{font-size:24px;font-weight:700;color:#1A8A4E}

/* Status badge */
.status-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase}
.status-paid{background:#dcfce7;color:#166534}
.status-approved{background:#dbeafe;color:#1e40af}
.status-draft{background:#f3f4f6;color:#6b7280}

/* Footer */
.doc-footer{border-top:1px solid #e5e7eb;padding:16px 28px;display:flex;justify-content:space-between;align-items:flex-end;gap:20px}
.footer-note{font-size:10px;color:#9ca3af;line-height:1.6;max-width:280px}
.sig-block{text-align:right}
.sig-line{border-top:1px solid #9ca3af;margin-top:28px;padding-top:4px;font-size:10px;color:#9ca3af}

/* Actions */
.actions{text-align:center;padding:14px;background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:center}
.btn{padding:8px 22px;border-radius:6px;font-size:12.5px;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-block}
.btn-primary{background:#0B2D4E;color:#fff}
.btn-secondary{background:#fff;color:#374151;border:1px solid #d1d5db}

@media print{
  body{background:#fff}
  .actions{display:none}
  .page{margin:0;border:none;border-radius:0;box-shadow:none;max-width:100%}
  @page{size:A4;margin:1.2cm}
}
</style>
</head>
<body>
<div class="page">

  <!-- Header -->
  <div class="doc-header">
    <?php if (!empty($school['logo'])): ?>
    <img src="<?= e(APP_URL.'/assets/uploads/logos/'.$school['logo']) ?>" alt="Logo" class="logo-img">
    <?php else: ?>
    <div class="logo-box"><?= strtoupper(substr($school['name']??'S',0,1)) ?></div>
    <?php endif; ?>
    <div>
      <div class="school-name"><?= e($school['name'] ?? 'School Name') ?></div>
      <div class="school-sub">
        <?= e($school['address']??'') ?>
        <?php if(!empty($school['phone'])): ?> &bull; <?= e($school['phone']) ?><?php endif; ?>
        <?php if(!empty($school['email'])): ?><br><?= e($school['email']) ?><?php endif; ?>
      </div>
    </div>
    <div class="doc-ref">
      <div class="doc-ref-label">Payslip Ref</div>
      <div class="doc-ref-val"><?= e($refNo) ?></div>
    </div>
  </div>

  <div class="band">Employee Payslip — <?= $monthNames[(int)$ps['period_month']] ?> <?= $ps['period_year'] ?></div>

  <div class="body">

    <!-- Employee info -->
    <div class="emp-card">
      <div class="emp-cell">
        <div class="cell-label">Employee Name</div>
        <div class="cell-val"><?= e($ps['staff_name']) ?></div>
      </div>
      <div class="emp-cell">
        <div class="cell-label">Employee ID</div>
        <div class="cell-val"><?= e($ps['employee_id'] ?? '—') ?></div>
      </div>
      <div class="emp-cell">
        <div class="cell-label">Grade / Designation</div>
        <div class="cell-val"><?= e($ps['grade_name'] ?? '—') ?></div>
      </div>
      <div class="emp-cell">
        <div class="cell-label">Pay Period</div>
        <div class="cell-val"><?= $monthNames[(int)$ps['period_month']] ?> <?= $ps['period_year'] ?></div>
      </div>
      <div class="emp-cell">
        <div class="cell-label">Currency</div>
        <div class="cell-val"><?= e($cur) ?></div>
      </div>
      <div class="emp-cell">
        <div class="cell-label">Status</div>
        <div class="cell-val">
          <span class="status-badge status-<?= $ps['status'] ?>"><?= ucfirst($ps['status']) ?></span>
        </div>
      </div>
    </div>

    <!-- Earnings & Deductions two-column -->
    <div class="two-col">
      <!-- Earnings -->
      <div>
        <div class="section-title">Earnings</div>
        <table class="earn-table">
          <?= rowIfNonZero('Basic Salary',       (float)$ps['basic_salary'],    $cur) ?>
          <?= rowIfNonZero('House Allowance',    (float)$ps['house_allowance'], $cur) ?>
          <?= rowIfNonZero('Transport Allowance',(float)$ps['transport_allow'], $cur) ?>
          <?= rowIfNonZero('Medical Allowance',  (float)$ps['medical_allow'],   $cur) ?>
          <?= rowIfNonZero('Other Allowances',   (float)$ps['other_allowances'],$cur) ?>
          <tr class="subtotal">
            <td>Gross Salary</td>
            <td class="num"><?= fmtAmt((float)$ps['gross_salary'],$cur) ?></td>
          </tr>
        </table>
      </div>
      <!-- Deductions -->
      <div>
        <div class="section-title">Deductions</div>
        <table class="earn-table">
          <?= rowIfNonZero('PAYE Tax',         (float)$ps['paye'],             $cur,'text-danger') ?>
          <?= rowIfNonZero('NHIF',             (float)$ps['nhif'],             $cur,'text-danger') ?>
          <?= rowIfNonZero('NSSF',             (float)$ps['nssf'],             $cur,'text-danger') ?>
          <?= rowIfNonZero('Other Deductions', (float)$ps['other_deductions'], $cur,'text-danger') ?>
          <tr class="deduct-total">
            <td>Total Deductions</td>
            <td class="num" style="color:#dc2626"><?= fmtAmt((float)$ps['total_deductions'],$cur) ?></td>
          </tr>
        </table>
      </div>
    </div>

    <!-- Net Pay -->
    <div class="netpay">
      <div class="netpay-label">Net Pay (Take Home)</div>
      <div class="netpay-val"><?= fmtAmt((float)$ps['net_salary'],$cur) ?></div>
    </div>

  </div>

  <!-- Footer -->
  <div class="doc-footer">
    <div class="footer-note">
      This payslip is a confidential document issued by <?= e($school['name']??'the school') ?>.
      Please retain for your records. For queries, contact the HR office.
      <br>Generated: <?= date('d M Y, H:i') ?>
    </div>
    <div class="sig-block">
      <div class="sig-line">Authorized — HR / Accounts</div>
      <div style="font-size:10px;color:#9ca3af;margin-top:3px"><?= e($school['name']??'') ?></div>
    </div>
  </div>

  <div class="actions">
    <a href="javascript:history.back()" class="btn btn-secondary">← Back</a>
    <button class="btn btn-primary" onclick="window.print()">🖨 Print Payslip</button>
  </div>

</div>
</body>
</html>
