<?php
/**
 * Prescription PDF
 * Auth: admin (requireLogin) OR doctor portal (doc_id, own prescriptions)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$rxId = (int)($_GET['id'] ?? 0);
if (!$rxId) { http_response_code(404); exit('Prescription not found.'); }

// ── Auth ──────────────────────────────────────────────────────────
$isAdmin  = isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['super_admin','admin','staff']);
$isDoctor = !empty($_SESSION['doc_id']);

if (!$isAdmin && !$isDoctor) {
    redirect(APP_URL . '/auth/login.php');
}

// ── Load prescription ─────────────────────────────────────────────
$rx = null;
try {
    if ($isAdmin) {
        $orgId = (int)currentUser()['org_id'];
    } else {
        $orgId = (int)$_SESSION['doc_org_id'];
    }

    $s = $pdo->prepare("
        SELECT rx.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
               p.date_of_birth, p.gender, p.phone AS patient_phone,
               d.first_name AS doc_first, d.last_name AS doc_last,
               d.specialization, d.qualification,
               o.name AS org_name, o.address AS org_address, o.phone AS org_phone,
               o.email AS org_email, o.logo AS org_logo, o.city, o.country
        FROM health_prescriptions rx
        JOIN health_patients p ON p.id=rx.patient_id
        LEFT JOIN health_doctors d ON d.id=rx.doctor_id
        JOIN organizations o ON o.id=rx.org_id
        WHERE rx.id=? AND rx.org_id=?
    ");
    $s->execute([$rxId, $orgId]);
    $rx = $s->fetch();
} catch (Throwable $e) {}

if (!$rx) { http_response_code(404); exit('Prescription not found.'); }

// Doctor portal: can only see own prescriptions
if ($isDoctor && !$isAdmin && (int)$rx['doctor_id'] !== (int)$_SESSION['doc_id']) {
    http_response_code(403); exit('Access denied.');
}

$medicines = json_decode($rx['medicines'] ?? '[]', true) ?: [];
$age = $rx['date_of_birth'] ? date_diff(date_create($rx['date_of_birth']), date_create('today'))->y : null;
$initials = strtoupper(implode('', array_map(fn($w) => substr($w,0,1), array_slice(explode(' ', $rx['org_name']),0,2))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Prescription <?= e($rx['prescription_no']) ?> — <?= e($rx['org_name']) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
@page{size:A4;margin:1.2cm}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:10.5pt;color:#1a1a2e;background:#fff}
.actions{display:flex;gap:8px;padding:12px 16px;background:#f8f9fa;border-bottom:1px solid #dee2e6;print-resolution:none}
@media print{.actions{display:none}}
.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;border:none;cursor:pointer;font-size:9pt;font-weight:600}
.btn-primary{background:#1a4e7c;color:#fff}
.btn-secondary{background:#6c757d;color:#fff}

.page{max-width:780px;margin:0 auto;padding:16px}

/* Letterhead */
.letterhead{display:flex;align-items:center;justify-content:space-between;padding-bottom:10px;margin-bottom:10px;border-bottom:3px solid #1a4e7c}
.org-logo{width:60px;height:60px;border-radius:12px;background:#1a4e7c;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem;font-weight:800;flex-shrink:0}
.org-logo img{width:56px;height:56px;object-fit:contain;border-radius:10px}
.org-info{flex:1;padding-left:14px}
.org-name{font-size:15pt;font-weight:800;color:#1a4e7c;line-height:1.1}
.org-sub{font-size:8.5pt;color:#555;margin-top:2px}
.rx-badge{text-align:right}
.rx-badge .rx-symbol{font-size:28pt;font-weight:900;color:#1a4e7c;line-height:1}
.rx-badge .rx-no{font-size:8pt;color:#666}

/* Accent band */
.accent-band{background:#1a4e7c;color:#fff;padding:6px 14px;border-radius:6px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;font-size:8.5pt}
.accent-band .title{font-weight:700;font-size:10pt;letter-spacing:.3px}

/* Patient info */
.patient-box{background:#f0f6ff;border:1px solid #c5d9f0;border-radius:6px;padding:10px 14px;margin-bottom:14px}
.info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.info-item .label{font-size:7.5pt;color:#888;text-transform:uppercase;letter-spacing:.3px}
.info-item .value{font-weight:600;font-size:9.5pt}

/* Medicines table */
.section-title{font-size:9pt;font-weight:700;color:#1a4e7c;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;border-bottom:1px solid #dce8f5;padding-bottom:4px}
.med-table{width:100%;border-collapse:collapse;font-size:9.5pt}
.med-table th{background:#f0f6ff;color:#1a4e7c;font-weight:700;font-size:8.5pt;text-transform:uppercase;letter-spacing:.3px;padding:6px 8px;border:1px solid #c5d9f0}
.med-table td{padding:7px 8px;border:1px solid #e8f0f8;vertical-align:top}
.med-table tr:nth-child(even) td{background:#f8fbff}
.med-num{font-weight:700;color:#1a4e7c}
.med-name{font-weight:700}

/* Notes */
.notes-box{background:#fffef0;border:1px solid #e8e0a0;border-radius:6px;padding:8px 12px;font-size:9pt;margin-top:10px}
.notes-box .notes-title{font-size:7.5pt;font-weight:700;color:#a0810a;text-transform:uppercase;letter-spacing:.3px;margin-bottom:3px}

/* Signature */
.sig-section{margin-top:24px;display:flex;justify-content:flex-end}
.sig-block{text-align:center;min-width:200px}
.sig-line{border-top:1.5px solid #333;padding-top:5px;margin-top:28px}
.sig-name{font-weight:700;font-size:9.5pt}
.sig-spec{font-size:8pt;color:#555}
.sig-qual{font-size:7.5pt;color:#888}

/* Footer */
.doc-footer{margin-top:20px;padding-top:8px;border-top:1px solid #e0e0e0;font-size:7.5pt;color:#999;display:flex;justify-content:space-between}
.validity-notice{background:#fff3cd;border:1px solid #ffc107;border-radius:5px;padding:6px 10px;font-size:8pt;color:#856404;margin-top:10px;text-align:center}
</style>
</head>
<body>

<div class="actions">
  <button class="btn btn-primary" onclick="window.print()"><i>🖨</i> Print Prescription</button>
  <button class="btn btn-secondary" onclick="window.close()">Close</button>
</div>

<div class="page">
  <!-- Letterhead -->
  <div class="letterhead">
    <div class="d-flex align-items-center" style="display:flex;align-items:center">
      <div class="org-logo">
        <?php if (!empty($rx['org_logo'])): ?>
          <img src="<?= APP_URL ?>/uploads/logos/<?= e($rx['org_logo']) ?>" alt="">
        <?php else: ?><?= $initials ?><?php endif; ?>
      </div>
      <div class="org-info">
        <div class="org-name"><?= e($rx['org_name']) ?></div>
        <div class="org-sub">
          <?= e($rx['org_address'] ?? '') ?>
          <?php if ($rx['org_phone']): ?> &bull; Tel: <?= e($rx['org_phone']) ?><?php endif; ?>
          <?php if ($rx['org_email']): ?> &bull; <?= e($rx['org_email']) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="rx-badge">
      <div class="rx-symbol">&#8478;</div>
      <div class="rx-no"><?= e($rx['prescription_no']) ?></div>
      <div style="font-size:7.5pt;color:#888"><?= date('d M Y', strtotime($rx['prescription_date'])) ?></div>
    </div>
  </div>

  <!-- Accent band -->
  <div class="accent-band">
    <div class="title">Medical Prescription</div>
    <div>Date: <?= date('d F Y', strtotime($rx['prescription_date'])) ?></div>
  </div>

  <!-- Patient info -->
  <div class="patient-box">
    <div class="info-grid">
      <div class="info-item">
        <div class="label">Patient Name</div>
        <div class="value"><?= e($rx['patient_name']) ?></div>
      </div>
      <div class="info-item">
        <div class="label">Patient No.</div>
        <div class="value">#<?= e($rx['patient_no'] ?? $rx['patient_id']) ?></div>
      </div>
      <div class="info-item">
        <div class="label">Age / Gender</div>
        <div class="value"><?= $age !== null ? $age.' yrs' : '—' ?> <?= $rx['gender'] ? '/ '.ucfirst($rx['gender']) : '' ?></div>
      </div>
      <?php if ($rx['patient_phone']): ?>
      <div class="info-item">
        <div class="label">Phone</div>
        <div class="value"><?= e($rx['patient_phone']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($rx['diagnosis']): ?>
      <div class="info-item" style="grid-column:span 2">
        <div class="label">Diagnosis / Indication</div>
        <div class="value"><?= e($rx['diagnosis']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Medicines -->
  <div class="section-title">Prescribed Medicines</div>
  <?php if (empty($medicines)): ?>
  <p style="color:#999;font-style:italic">No medicines listed.</p>
  <?php else: ?>
  <table class="med-table">
    <thead>
      <tr><th>#</th><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Instructions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($medicines as $i => $m): ?>
      <tr>
        <td class="med-num"><?= $i+1 ?></td>
        <td class="med-name"><?= e($m['name']) ?></td>
        <td><?= e($m['dosage'] ?? '—') ?></td>
        <td><?= e($m['frequency'] ?? '—') ?></td>
        <td><?= e($m['duration'] ?? '—') ?></td>
        <td><?= e($m['instructions'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($rx['notes']): ?>
  <div class="notes-box">
    <div class="notes-title">Additional Instructions</div>
    <?= nl2br(e($rx['notes'])) ?>
  </div>
  <?php endif; ?>

  <div class="validity-notice">
    &#9888; This prescription is valid for <strong>30 days</strong> from the date of issue.
  </div>

  <!-- Signature -->
  <div class="sig-section">
    <div class="sig-block">
      <div class="sig-line">
        <div class="sig-name">Dr. <?= e(trim(($rx['doc_first'] ?? '').' '.($rx['doc_last'] ?? ''))) ?></div>
        <?php if ($rx['specialization']): ?><div class="sig-spec"><?= e($rx['specialization']) ?></div><?php endif; ?>
        <?php if ($rx['qualification']): ?><div class="sig-qual"><?= e($rx['qualification']) ?></div><?php endif; ?>
        <div class="sig-qual" style="margin-top:2px"><?= e($rx['org_name']) ?></div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <div class="doc-footer">
    <div>Ref: <?= e($rx['prescription_no']) ?> &bull; Generated <?= date('d M Y H:i') ?></div>
    <div>This is a computer-generated prescription. Valid with doctor's stamp/signature.</div>
  </div>
</div>

</body>
</html>
