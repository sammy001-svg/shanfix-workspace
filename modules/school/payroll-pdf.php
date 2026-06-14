<?php
/**
 * School Module — Payroll Run Summary Report (print-friendly HTML)
 * GET: ?run_id=X
 * Auth: admin staff only
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../modules/school/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$u     = currentUser();
$orgId = (int)$u['org_id'];
$runId = (int)($_GET['run_id'] ?? 0);
if (!$runId) exit('Run ID required.');

// ── Load payroll run ──────────────────────────────────────────────
$run = null;
try {
    $s = $pdo->prepare("SELECT pr.*, CONCAT(u.first_name,' ',u.last_name) AS processed_by_name FROM sch_payroll_runs pr LEFT JOIN users u ON u.id=pr.processed_by WHERE pr.id=? AND pr.org_id=? LIMIT 1");
    $s->execute([$runId,$orgId]); $run = $s->fetch() ?: null;
} catch (Throwable $e) {}

if (!$run) { http_response_code(404); exit('Payroll run not found.'); }

// ── Load payslips ─────────────────────────────────────────────────
$payslips = [];
try {
    $s = $pdo->prepare("SELECT * FROM sch_payslips WHERE payroll_run_id=? AND org_id=? ORDER BY staff_name");
    $s->execute([$runId,$orgId]); $payslips = $s->fetchAll();
} catch (Throwable $e) {}

// ── School info ───────────────────────────────────────────────────
$school = [];
try {
    $s = $pdo->prepare("SELECT * FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$orgId]); $school = $s->fetch() ?: [];
} catch (Throwable $e) {}

$monthNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$cur = $run['currency'] ?? 'KES';
$refNo = 'PR-' . $run['period_year'] . str_pad($run['period_month'],2,'0',STR_PAD_LEFT) . '-' . str_pad($runId,4,'0',STR_PAD_LEFT);

// Totals per column
$totals = [
    'basic_salary'=>0,'house_allowance'=>0,'transport_allow'=>0,'medical_allow'=>0,
    'other_allowances'=>0,'gross_salary'=>0,'paye'=>0,'nhif'=>0,'nssf'=>0,
    'other_deductions'=>0,'total_deductions'=>0,'net_salary'=>0
];
foreach ($payslips as $ps) {
    foreach (array_keys($totals) as $k) $totals[$k] += (float)$ps[$k];
}

function fm(float $v): string { return number_format($v, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Payroll Report — <?= $monthNames[(int)$run['period_month']] ?> <?= $run['period_year'] ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:11.5px;color:#1a1a1a;background:#f0f2f5;line-height:1.5}
.page{max-width:1000px;margin:24px auto;background:#fff;border:1px solid #dde3ec;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)}

/* Header */
.doc-header{background:#0B2D4E;color:#fff;padding:22px 28px;display:flex;align-items:center;gap:16px}
.logo-box{width:54px;height:54px;background:#1A8A4E;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#fff;flex-shrink:0}
.logo-img{width:54px;height:54px;object-fit:contain;border-radius:6px;background:#fff;padding:3px;flex-shrink:0}
.hdr-school{font-size:16px;font-weight:700;line-height:1.2}
.hdr-sub{font-size:10px;color:rgba(255,255,255,.65);margin-top:3px}
.hdr-right{margin-left:auto;text-align:right;flex-shrink:0}
.hdr-right .ref-label{font-size:9px;color:rgba(255,255,255,.55);text-transform:uppercase;letter-spacing:.7px}
.hdr-right .ref-val{font-size:15px;font-weight:700;color:#6ee7b7}

/* Band */
.band{background:#1A8A4E;color:#fff;text-align:center;padding:7px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:2px}

/* Summary cards */
.summary-row{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:#e5e7eb;border-bottom:1px solid #e5e7eb}
.sum-card{background:#fff;padding:14px 16px}
.sum-label{font-size:9.5px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.sum-val{font-size:17px;font-weight:700;color:#0B2D4E}
.sum-sub{font-size:10px;color:#6b7280;margin-top:2px}

/* Table */
.body{padding:20px 24px}
.section-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#6b7280;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #e5e7eb}

.pr-table{width:100%;border-collapse:collapse;font-size:11px}
.pr-table th{background:#f9fafb;padding:7px 8px;font-size:9.5px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;color:#6b7280;border-bottom:1px solid #e5e7eb;border-top:1px solid #e5e7eb;text-align:left;white-space:nowrap}
.pr-table th.num,.pr-table td.num{text-align:right;font-variant-numeric:tabular-nums}
.pr-table td{padding:7px 8px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.pr-table tr:hover td{background:#fafafa}
.pr-table .totals-row td{background:#f0fdf4;font-weight:700;font-size:11.5px;border-top:2px solid #86efac;border-bottom:none}
.pr-table .totals-row .num{color:#1A8A4E}
.pr-table .ded{color:#dc2626}

/* Status badge */
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:9.5px;font-weight:700;text-transform:uppercase}
.b-paid{background:#dcfce7;color:#166534}
.b-approved{background:#dbeafe;color:#1e40af}
.b-draft{background:#f3f4f6;color:#6b7280}

/* Footer */
.doc-footer{border-top:1px solid #e5e7eb;padding:12px 24px;font-size:9.5px;color:#9ca3af;display:flex;justify-content:space-between;align-items:center}

/* Actions */
.actions{text-align:center;padding:14px;background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:center}
.btn{padding:8px 22px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-block}
.btn-primary{background:#0B2D4E;color:#fff}
.btn-secondary{background:#fff;color:#374151;border:1px solid #d1d5db}

@media print{
  body{background:#fff}
  .actions{display:none}
  .page{margin:0;border:none;border-radius:0;box-shadow:none;max-width:100%}
  @page{size:A4 landscape;margin:1cm}
}
</style>
</head>
<body>
<div class="page">

  <!-- Header -->
  <div class="doc-header">
    <?php if (!empty($school['logo'])): ?>
    <img src="<?= e(APP_URL.'/assets/uploads/logos/'.$school['logo']) ?>" class="logo-img" alt="Logo">
    <?php else: ?>
    <div class="logo-box"><?= strtoupper(substr($school['name']??'S',0,1)) ?></div>
    <?php endif; ?>
    <div>
      <div class="hdr-school"><?= e($school['name'] ?? 'School Name') ?></div>
      <div class="hdr-sub">
        <?= e($school['address']??'') ?>
        <?php if(!empty($school['phone'])): ?> &bull; <?= e($school['phone']) ?><?php endif; ?>
      </div>
    </div>
    <div class="hdr-right">
      <div class="ref-label">Report Ref</div>
      <div class="ref-val"><?= e($refNo) ?></div>
    </div>
  </div>

  <div class="band">
    Payroll Summary Report &mdash; <?= $monthNames[(int)$run['period_month']] ?> <?= $run['period_year'] ?>
  </div>

  <!-- KPI Row -->
  <div class="summary-row">
    <?php foreach ([
      ['Total Staff',          count($payslips),                         '', ''],
      ['Total Gross Pay',      $cur.' '.fm($totals['gross_salary']),      '', ''],
      ['Total Deductions',     $cur.' '.fm($totals['total_deductions']),  'color:#dc2626', ''],
      ['Total Net Pay',        $cur.' '.fm($totals['net_salary']),        'color:#1A8A4E', ''],
    ] as $c): ?>
    <div class="sum-card">
      <div class="sum-label"><?= $c[0] ?></div>
      <div class="sum-val" style="<?= $c[2] ?>"><?= $c[1] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="body">

    <!-- Run metadata -->
    <div style="display:flex;gap:32px;margin-bottom:16px;font-size:11px;color:#6b7280">
      <span><strong>Period:</strong> <?= $monthNames[(int)$run['period_month']] ?> <?= $run['period_year'] ?></span>
      <span><strong>Currency:</strong> <?= e($cur) ?></span>
      <span><strong>Status:</strong>
        <span class="badge b-<?= $run['status'] ?>"><?= ucfirst($run['status']) ?></span>
      </span>
      <?php if ($run['processed_by_name']): ?>
      <span><strong>Processed by:</strong> <?= e($run['processed_by_name']) ?></span>
      <?php endif; ?>
      <?php if ($run['processed_at']): ?>
      <span><strong>Date:</strong> <?= date('d M Y', strtotime($run['processed_at'])) ?></span>
      <?php endif; ?>
    </div>

    <div class="section-title">Individual Payslips</div>

    <div style="overflow-x:auto">
      <table class="pr-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Employee</th>
            <th>ID</th>
            <th>Grade</th>
            <th class="num">Basic</th>
            <th class="num">House</th>
            <th class="num">Transport</th>
            <th class="num">Medical</th>
            <th class="num">Other All.</th>
            <th class="num">Gross</th>
            <th class="num">PAYE</th>
            <th class="num">NHIF</th>
            <th class="num">NSSF</th>
            <th class="num">Total Ded.</th>
            <th class="num">Net Pay</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payslips)): ?>
          <tr><td colspan="15" style="text-align:center;color:#9ca3af;padding:20px">No payslips in this run.</td></tr>
          <?php else: foreach ($payslips as $i => $ps): ?>
          <tr>
            <td style="color:#9ca3af"><?= $i+1 ?></td>
            <td style="font-weight:600"><?= e($ps['staff_name']) ?></td>
            <td style="color:#6b7280"><?= e($ps['employee_id']??'—') ?></td>
            <td><?= e($ps['grade_name']??'—') ?></td>
            <td class="num"><?= fm((float)$ps['basic_salary']) ?></td>
            <td class="num"><?= fm((float)$ps['house_allowance']) ?></td>
            <td class="num"><?= fm((float)$ps['transport_allow']) ?></td>
            <td class="num"><?= fm((float)$ps['medical_allow']) ?></td>
            <td class="num"><?= fm((float)$ps['other_allowances']) ?></td>
            <td class="num" style="font-weight:600"><?= fm((float)$ps['gross_salary']) ?></td>
            <td class="num ded"><?= fm((float)$ps['paye']) ?></td>
            <td class="num ded"><?= fm((float)$ps['nhif']) ?></td>
            <td class="num ded"><?= fm((float)$ps['nssf']) ?></td>
            <td class="num ded" style="font-weight:600"><?= fm((float)$ps['total_deductions']) ?></td>
            <td class="num" style="font-weight:700;color:#1A8A4E"><?= fm((float)$ps['net_salary']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($payslips)): ?>
        <tfoot>
          <tr class="totals-row">
            <td colspan="4" style="font-weight:700">TOTALS (<?= $cur ?>)</td>
            <td class="num"><?= fm($totals['basic_salary']) ?></td>
            <td class="num"><?= fm($totals['house_allowance']) ?></td>
            <td class="num"><?= fm($totals['transport_allow']) ?></td>
            <td class="num"><?= fm($totals['medical_allow']) ?></td>
            <td class="num"><?= fm($totals['other_allowances']) ?></td>
            <td class="num"><?= fm($totals['gross_salary']) ?></td>
            <td class="num" style="color:#dc2626"><?= fm($totals['paye']) ?></td>
            <td class="num" style="color:#dc2626"><?= fm($totals['nhif']) ?></td>
            <td class="num" style="color:#dc2626"><?= fm($totals['nssf']) ?></td>
            <td class="num" style="color:#dc2626"><?= fm($totals['total_deductions']) ?></td>
            <td class="num"><?= fm($totals['net_salary']) ?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>

    <!-- Authorisation -->
    <div style="display:flex;justify-content:space-between;margin-top:36px;gap:40px">
      <?php foreach (['Prepared By — Accounts Office','Approved By — HR Manager','Authorized By — Principal'] as $sig): ?>
      <div>
        <div style="border-top:1px solid #374151;margin-top:36px;padding-top:5px;font-size:10.5px;font-weight:600;color:#374151"><?= $sig ?></div>
        <div style="font-size:10px;color:#9ca3af;margin-top:2px">Signature &amp; Date</div>
      </div>
      <?php endforeach; ?>
    </div>

  </div>

  <div class="doc-footer">
    <span>Confidential &mdash; <?= e($school['name']??'') ?> Payroll Department</span>
    <span>Ref: <?= e($refNo) ?> &bull; Generated: <?= date('d M Y, H:i') ?></span>
  </div>

  <div class="actions">
    <a href="javascript:history.back()" class="btn btn-secondary">← Back</a>
    <button class="btn btn-primary" onclick="window.print()">🖨 Print Report</button>
  </div>

</div>
</body>
</html>
