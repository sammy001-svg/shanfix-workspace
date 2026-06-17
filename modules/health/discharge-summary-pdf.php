<?php
/**
 * Discharge Summary PDF
 * Auth: admin or doctor (via doctor portal)
 * GET: admission_id
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$admId = (int)($_GET['id'] ?? 0);
if (!$admId) { http_response_code(404); exit('Admission record not found.'); }

$isAdmin  = isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['super_admin','admin','client_admin','staff']);
$isDoctor = !empty($_SESSION['doc_id']);

if (!$isAdmin && !$isDoctor) {
    redirect(APP_URL . '/auth/login.php');
}

$orgId = $isAdmin ? (int)currentUser()['org_id'] : (int)$_SESSION['doc_org_id'];

$adm = null; $records = []; $rx = [];
try {
    $s = $pdo->prepare("
        SELECT a.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
               p.date_of_birth, p.gender, p.phone AS patient_phone, p.blood_group,
               w.name AS ward_name, b.bed_number,
               d.first_name AS doc_first, d.last_name AS doc_last,
               d.specialization, d.qualification,
               o.name AS org_name, o.address AS org_address, o.phone AS org_phone,
               o.email AS org_email, o.logo AS org_logo
        FROM health_admissions a
        JOIN health_patients p ON p.id=a.patient_id
        LEFT JOIN health_wards w ON w.id=a.ward_id
        LEFT JOIN health_beds b ON b.id=a.bed_id
        LEFT JOIN health_doctors d ON d.id=a.doctor_id
        JOIN organizations o ON o.id=a.org_id
        WHERE a.id=? AND a.org_id=?
    ");
    $s->execute([$admId, $orgId]);
    $adm = $s->fetch();

    if ($adm) {
        // Medical records during admission
        $s = $pdo->prepare("
            SELECT r.*, u.name AS recorded_by
            FROM health_records r
            LEFT JOIN users u ON u.id=(SELECT user_id FROM health_doctors WHERE id=r.doctor_id LIMIT 1)
            WHERE r.patient_id=? AND r.org_id=?
              AND r.date BETWEEN ? AND IFNULL(?, NOW())
            ORDER BY r.date ASC LIMIT 20
        ");
        $s->execute([$adm['patient_id'], $orgId, date('Y-m-d', strtotime($adm['admission_date'])), $adm['discharge_date'] ?? null]);
        $records = $s->fetchAll();

        // Active prescriptions at discharge
        $s = $pdo->prepare("
            SELECT rx.*, CONCAT(p.first_name,' ',p.last_name) AS doc_name
            FROM health_prescriptions rx
            LEFT JOIN health_doctors p ON p.id=rx.doctor_id
            WHERE rx.patient_id=? AND rx.org_id=? AND rx.status != 'cancelled'
            ORDER BY rx.prescription_date DESC LIMIT 5
        ");
        $s->execute([$adm['patient_id'], $orgId]);
        $rx = $s->fetchAll();
    }
} catch (Throwable $e) {}

if (!$adm) { http_response_code(404); exit('Admission record not found or access denied.'); }

$age = $adm['date_of_birth'] ? date_diff(date_create($adm['date_of_birth']), date_create('today'))->y : null;
$los = $adm['discharge_date']
    ? max(1, (int)((strtotime($adm['discharge_date']) - strtotime($adm['admission_date'])) / 86400))
    : null;
$initials = strtoupper(implode('', array_map(fn($w)=>substr($w,0,1), array_slice(explode(' ', $adm['org_name']),0,2))));
$docName  = 'Dr. ' . trim(($adm['doc_first'] ?? '') . ' ' . ($adm['doc_last'] ?? ''));
$refNo    = 'DS-' . date('Ym', strtotime($adm['admission_date'])) . '-' . str_pad($admId, 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Discharge Summary — <?= e($adm['patient_name']) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
@page{size:A4;margin:1.3cm}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:9.5pt;color:#1a1a2e;background:#fff}
.actions{display:flex;gap:8px;padding:10px 16px;background:#f8f9fa;border-bottom:1px solid #ddd}
@media print{.actions{display:none}}
.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;border:none;cursor:pointer;font-size:8.5pt;font-weight:600;color:#fff}

.page{max-width:780px;margin:0 auto;padding:14px}

.letterhead{display:flex;align-items:center;justify-content:space-between;padding-bottom:10px;margin-bottom:8px;border-bottom:3px solid #1a4e7c}
.org-logo{width:58px;height:58px;border-radius:12px;background:#1a4e7c;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.3rem;font-weight:800;flex-shrink:0}
.org-logo img{width:52px;height:52px;object-fit:contain;border-radius:10px}
.org-col{flex:1;padding-left:12px}
.org-name{font-size:13pt;font-weight:800;color:#1a4e7c}
.org-sub{font-size:8pt;color:#666;margin-top:2px}

.accent-band{background:#1a4e7c;color:#fff;padding:7px 14px;border-radius:6px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center}
.accent-band .t{font-weight:700;font-size:11pt}
.accent-band .r{font-size:8pt;opacity:.8}

.patient-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;background:#f0f6ff;border-radius:6px;padding:10px 12px;margin-bottom:12px}
.pb-item .lbl{font-size:7pt;color:#888;text-transform:uppercase;letter-spacing:.4px}
.pb-item .val{font-weight:700;font-size:9.5pt}

.section{margin-bottom:12px}
.section-title{font-size:8.5pt;font-weight:700;color:#1a4e7c;text-transform:uppercase;letter-spacing:.5px;border-bottom:1.5px solid #dce8f5;padding-bottom:4px;margin-bottom:7px}
.section-body{font-size:9.5pt;line-height:1.7}

.two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px}

.info-table{width:100%;font-size:9pt;border-collapse:collapse}
.info-table td{padding:4px 6px;border-bottom:1px solid #f0f0f0}
.info-table td:first-child{color:#888;width:38%}
.info-table td:last-child{font-weight:600}

.records-list{display:flex;flex-direction:column;gap:6px}
.record-card{background:#f8f9fa;border-radius:5px;padding:7px 10px;font-size:9pt}
.record-date{font-weight:700;font-size:8pt;color:#1a4e7c;margin-bottom:2px}
.record-dx{font-weight:600;margin-bottom:1px}
.record-tx{color:#444;font-size:8.5pt}

.rx-table{width:100%;border-collapse:collapse;font-size:8.5pt}
.rx-table th{background:#f0f6ff;color:#1a4e7c;font-weight:700;font-size:7.5pt;text-transform:uppercase;padding:5px 7px;border:1px solid #d4e6f8}
.rx-table td{padding:5px 7px;border:1px solid #e8f2fd;vertical-align:top}

.sig-grid{display:flex;gap:24px;margin-top:24px}
.sig-block{flex:1;text-align:center}
.sig-line{border-top:1.5px solid #333;padding-top:5px;margin-top:28px}
.sig-name{font-weight:700;font-size:9pt}
.sig-spec{font-size:7.5pt;color:#555}

.follow-up-box{background:#fff8e6;border:1px solid #fde68a;border-radius:5px;padding:8px 12px;font-size:9pt}
.doc-footer{margin-top:16px;padding-top:7px;border-top:1px solid #e0e0e0;font-size:7pt;color:#aaa;text-align:center}
</style>
</head>
<body>

<div class="actions">
  <button class="btn" style="background:#1a4e7c" onclick="window.print()">🖨 Print Summary</button>
  <button class="btn" style="background:#6c757d" onclick="window.close()">Close</button>
</div>

<div class="page">
  <!-- Letterhead -->
  <div class="letterhead">
    <div style="display:flex;align-items:center">
      <div class="org-logo">
        <?php if (!empty($adm['org_logo'])): ?><img src="<?= APP_URL ?>/uploads/logos/<?= e($adm['org_logo']) ?>" alt=""><?php else: ?><?= $initials ?><?php endif; ?>
      </div>
      <div class="org-col">
        <div class="org-name"><?= e($adm['org_name']) ?></div>
        <div class="org-sub">
          <?= e($adm['org_address'] ?? '') ?>
          <?php if ($adm['org_phone']): ?> &bull; <?= e($adm['org_phone']) ?><?php endif; ?>
          <?php if ($adm['org_email']): ?> &bull; <?= e($adm['org_email']) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <div style="text-align:right;font-size:7.5pt;color:#888">Ref: <?= $refNo ?><br>Generated: <?= date('d M Y') ?></div>
  </div>

  <!-- Title band -->
  <div class="accent-band">
    <div class="t">Discharge Summary</div>
    <div class="r">Admission #<?= $admId ?> &bull; <?= date('d M Y', strtotime($adm['admission_date'])) ?> → <?= $adm['discharge_date'] ? date('d M Y', strtotime($adm['discharge_date'])) : 'Ongoing' ?></div>
  </div>

  <!-- Patient bar -->
  <div class="patient-bar">
    <div class="pb-item"><div class="lbl">Patient</div><div class="val"><?= e($adm['patient_name']) ?></div></div>
    <div class="pb-item"><div class="lbl">Patient No.</div><div class="val">#<?= e($adm['patient_no'] ?? $adm['patient_id']) ?></div></div>
    <div class="pb-item"><div class="lbl">Age / Gender</div><div class="val"><?= $age !== null ? $age.' yrs' : '—' ?><?= $adm['gender'] ? ' / '.ucfirst($adm['gender']) : '' ?></div></div>
    <div class="pb-item"><div class="lbl">Blood Group</div><div class="val"><?= e($adm['blood_group'] ?? '—') ?></div></div>
  </div>

  <!-- Two column: admission details + clinical summary -->
  <div class="two-col">
    <div>
      <div class="section">
        <div class="section-title">Admission Details</div>
        <table class="info-table">
          <tr><td>Admission Date</td><td><?= date('d M Y', strtotime($adm['admission_date'])) ?></td></tr>
          <tr><td>Discharge Date</td><td><?= $adm['discharge_date'] ? date('d M Y', strtotime($adm['discharge_date'])) : '<em>Ongoing</em>' ?></td></tr>
          <?php if ($los): ?><tr><td>Length of Stay</td><td><?= $los ?> day<?= $los!==1?'s':'' ?></td></tr><?php endif; ?>
          <tr><td>Ward</td><td><?= e($adm['ward_name'] ?? '—') ?><?= ($adm['bed_number'] ?? '') ? ' · Bed '.$adm['bed_number'] : '' ?></td></tr>
          <tr><td>Attending Doctor</td><td><?= e($docName) ?><?= $adm['specialization'] ? ', '.$adm['specialization'] : '' ?></td></tr>
          <tr><td>Admission Type</td><td><?= ucfirst($adm['admission_type'] ?? 'Inpatient') ?></td></tr>
        </table>
      </div>

      <div class="section">
        <div class="section-title">Admission Diagnosis</div>
        <div class="section-body"><?= nl2br(e($adm['diagnosis'] ?? 'Not documented.')) ?></div>
      </div>
    </div>

    <div>
      <div class="section">
        <div class="section-title">Discharge Status</div>
        <table class="info-table">
          <tr><td>Condition</td><td><strong><?= ucfirst($adm['discharge_condition'] ?? 'Stable') ?></strong></td></tr>
          <tr><td>Discharge Type</td><td><?= ucfirst(str_replace('_',' ',$adm['discharge_type'] ?? 'Regular')) ?></td></tr>
        </table>
      </div>

      <?php if ($adm['treatment'] ?? ''): ?>
      <div class="section">
        <div class="section-title">Treatment Given</div>
        <div class="section-body"><?= nl2br(e($adm['treatment'])) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($adm['surgery_details'] ?? ''): ?>
      <div class="section">
        <div class="section-title">Procedures / Surgery</div>
        <div class="section-body"><?= nl2br(e($adm['surgery_details'])) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Clinical records -->
  <?php if (!empty($records)): ?>
  <div class="section">
    <div class="section-title">Clinical Progress Notes</div>
    <div class="records-list">
      <?php foreach ($records as $r): ?>
      <div class="record-card">
        <div class="record-date"><?= date('d M Y', strtotime($r['date'])) ?><?= $r['recorded_by'] ? ' — Dr. '.$r['recorded_by'] : '' ?></div>
        <div class="record-dx"><?= e($r['diagnosis'] ?? '') ?></div>
        <?php if ($r['treatment']): ?><div class="record-tx"><?= e(mb_substr($r['treatment'],0,200)) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Medications at discharge -->
  <?php if (!empty($rx)): ?>
  <div class="section">
    <div class="section-title">Medications at Discharge</div>
    <table class="rx-table">
      <thead>
        <tr><th>Prescription No.</th><th>Date</th><th>Medicines</th><th>Prescriber</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rx as $r):
          $meds = json_decode($r['medicines'] ?? '[]', true) ?: [];
          $medSummary = implode(', ', array_map(fn($m) => $m['name'] . ($m['dosage'] ? ' '.$m['dosage'] : ''), array_slice($meds, 0, 3)));
        ?>
        <tr>
          <td class="font-monospace"><?= e($r['prescription_no'] ?? '#'.$r['id']) ?></td>
          <td><?= date('d M Y', strtotime($r['prescription_date'])) ?></td>
          <td><?= e($medSummary ?: '—') ?><?= count($meds) > 3 ? ' +' . (count($meds) - 3) . ' more' : '' ?></td>
          <td><?= e($r['doc_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Follow-up / discharge instructions -->
  <?php if ($adm['follow_up'] ?? $adm['notes'] ?? ''): ?>
  <div class="section">
    <div class="section-title">Discharge Instructions &amp; Follow-up</div>
    <div class="follow-up-box">
      <?php if ($adm['follow_up']): ?><div><strong>Follow-up:</strong> <?= nl2br(e($adm['follow_up'])) ?></div><?php endif; ?>
      <?php if ($adm['notes']): ?><div style="margin-top:5px"><?= nl2br(e($adm['notes'])) ?></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Signatures -->
  <div class="sig-grid">
    <div class="sig-block">
      <div class="sig-line">
        <div class="sig-name"><?= e($docName) ?></div>
        <?php if ($adm['specialization']): ?><div class="sig-spec"><?= e($adm['specialization']) ?></div><?php endif; ?>
        <?php if ($adm['qualification'] ?? ''): ?><div class="sig-spec"><?= e($adm['qualification']) ?></div><?php endif; ?>
        <div class="sig-spec">Attending Physician</div>
      </div>
    </div>
    <div class="sig-block">
      <div class="sig-line">
        <div class="sig-name"><?= e($adm['patient_name']) ?></div>
        <div class="sig-spec">Patient / Next of Kin</div>
        <div class="sig-spec"><?= $adm['discharge_date'] ? 'Discharged: '.date('d M Y', strtotime($adm['discharge_date'])) : '' ?></div>
      </div>
    </div>
    <div class="sig-block">
      <div class="sig-line">
        <div class="sig-name">______________________</div>
        <div class="sig-spec">Authorising Officer</div>
        <div class="sig-spec"><?= e($adm['org_name']) ?></div>
      </div>
    </div>
  </div>

  <div class="doc-footer">
    <?= e($adm['org_name']) ?> &bull; Discharge Summary Ref: <?= $refNo ?> &bull; Generated <?= date('d M Y H:i') ?><br>
    This document is confidential and intended solely for the named patient and treating medical team.
  </div>
</div>

</body>
</html>
