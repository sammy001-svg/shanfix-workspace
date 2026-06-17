<?php
/**
 * Medical Certificate PDF
 * Auth: admin or doctor portal (for own patients)
 * GET: patient_id, type (fit|sick|referral), from_date, to_date, diagnosis, notes
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAdmin  = isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['super_admin','admin','client_admin','staff']);
$isDoctor = !empty($_SESSION['doc_id']);

if (!$isAdmin && !$isDoctor) {
    redirect(APP_URL . '/auth/login.php');
}

$orgId    = $isAdmin ? (int)currentUser()['org_id'] : (int)$_SESSION['doc_org_id'];
$docId    = $isAdmin ? 0 : (int)$_SESSION['doc_id'];

// Parameters
$patientId   = (int)($_GET['patient_id'] ?? 0);
$certType    = in_array($_GET['type'] ?? '', ['fit','sick','referral']) ? $_GET['type'] : 'sick';
$fromDate    = $_GET['from_date'] ?? date('Y-m-d');
$toDate      = $_GET['to_date']   ?? date('Y-m-d', strtotime('+3 days'));
$diagnosis   = sanitize($_GET['diagnosis'] ?? '');
$notes       = sanitize($_GET['notes']     ?? '');
$refDoctor   = sanitize($_GET['ref_doctor'] ?? '');
$refHospital = sanitize($_GET['ref_hospital'] ?? '');

if (!$patientId) {
    // Show a form to fill in parameters
    $patients = [];
    try {
        $s = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, patient_no FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name LIMIT 300");
        $s->execute([$orgId]); $patients = $s->fetchAll();
    } catch (Throwable $e) {}

    $doctor = [];
    if ($isDoctor) {
        try {
            $s = $pdo->prepare("SELECT first_name, last_name, specialization, qualification FROM health_doctors WHERE id=? LIMIT 1");
            $s->execute([$docId]); $doctor = $s->fetch() ?: [];
        } catch (Throwable $e) {}
    }
    ?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Generate Medical Certificate</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light p-4">
<div class="card shadow-sm mx-auto" style="max-width:550px">
  <div class="card-body">
    <h5 class="fw-bold mb-3"><i class="fas fa-file-medical text-danger me-2"></i>Medical Certificate</h5>
    <form method="GET">
      <div class="mb-3">
        <label class="form-label small fw-semibold">Patient</label>
        <select name="patient_id" class="form-select form-select-sm" required>
          <option value="">— Select patient —</option>
          <?php foreach ($patients as $p): ?>
          <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (#<?= e($p['patient_no'] ?? $p['id']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold">Certificate Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="sick">Sick Leave Certificate</option>
          <option value="fit">Fit-for-Work Certificate</option>
          <option value="referral">Referral Letter</option>
        </select>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label small fw-semibold">From Date</label>
          <input type="date" name="from_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-6"><label class="form-label small fw-semibold">To Date</label>
          <input type="date" name="to_date" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('+3 days')) ?>"></div>
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold">Diagnosis / Condition</label>
        <input type="text" name="diagnosis" class="form-control form-control-sm" placeholder="e.g., Acute Upper Respiratory Tract Infection">
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold">Notes (optional)</label>
        <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Any additional clinical notes..."></textarea>
      </div>
      <div class="mb-3" id="refFields" style="display:none">
        <label class="form-label small fw-semibold">Referred To (Doctor/Specialist)</label>
        <input type="text" name="ref_doctor" class="form-control form-control-sm mb-2" placeholder="Dr. Specialist Name">
        <input type="text" name="ref_hospital" class="form-control form-control-sm" placeholder="Hospital / Facility Name">
      </div>
      <button type="submit" class="btn btn-danger btn-sm w-100">Generate Certificate</button>
    </form>
  </div>
</div>
<script>
document.querySelector('[name=type]').addEventListener('change', function() {
  document.getElementById('refFields').style.display = this.value === 'referral' ? '' : 'none';
});
</script>
</body></html>
<?php exit; }

// Load patient and doctor
$patient = null; $docRow = null; $org = null;
try {
    $s = $pdo->prepare("SELECT p.*, CONCAT(p.first_name,' ',p.last_name) AS full_name FROM health_patients p WHERE p.id=? AND p.org_id=? LIMIT 1");
    $s->execute([$patientId, $orgId]); $patient = $s->fetch();

    if ($isDoctor) {
        $s = $pdo->prepare("SELECT d.*, o.name AS org_name, o.address, o.phone AS org_phone, o.email AS org_email, o.logo, o.city, o.country FROM health_doctors d JOIN organizations o ON o.id=d.org_id WHERE d.id=? AND d.org_id=? LIMIT 1");
        $s->execute([$docId, $orgId]); $docRow = $s->fetch();
    } else {
        $s = $pdo->prepare("SELECT name AS org_name, address, phone AS org_phone, email AS org_email, logo, city, country FROM organizations WHERE id=? LIMIT 1");
        $s->execute([$orgId]); $docRow = $s->fetch();
        $user = currentUser();
        if ($docRow) { $docRow['first_name'] = $user['name']; $docRow['last_name'] = ''; $docRow['specialization'] = 'Medical Officer'; $docRow['qualification'] = ''; }
    }
} catch (Throwable $e) {}

if (!$patient || !$docRow) { exit('Data not found.'); }

$age = $patient['date_of_birth'] ? date_diff(date_create($patient['date_of_birth']), date_create('today'))->y : null;
$initials = strtoupper(implode('', array_map(fn($w)=>substr($w,0,1), array_slice(explode(' ', $docRow['org_name']),0,2))));
$certNo = 'MC-' . date('Ym') . '-' . str_pad($patientId, 4, '0', STR_PAD_LEFT);

$typeLabels = ['sick'=>'Sick Leave Certificate','fit'=>'Fitness for Work Certificate','referral'=>'Referral Letter'];
$accentColor = $certType === 'referral' ? '#1a4e7c' : ($certType === 'fit' ? '#1a8a4e' : '#c0392b');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $typeLabels[$certType] ?> — <?= e($patient['full_name']) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
@page{size:A4;margin:1.5cm}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:10.5pt;color:#1a1a2e;background:#fff}
.actions{display:flex;gap:8px;padding:12px 16px;background:#f8f9fa;border-bottom:1px solid #dee2e6}
@media print{.actions{display:none}}
.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;border:none;cursor:pointer;font-size:9pt;font-weight:600;color:#fff}

.page{max-width:780px;margin:0 auto;padding:16px}

.letterhead{display:flex;align-items:center;justify-content:space-between;padding-bottom:10px;margin-bottom:10px;border-bottom:3px solid <?= $accentColor ?>}
.org-logo{width:60px;height:60px;border-radius:12px;background:<?= $accentColor ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem;font-weight:800;flex-shrink:0}
.org-logo img{width:56px;height:56px;object-fit:contain;border-radius:10px}
.org-info{flex:1;padding-left:14px}
.org-name{font-size:15pt;font-weight:800;color:<?= $accentColor ?>;line-height:1.1}
.org-sub{font-size:8.5pt;color:#555;margin-top:2px}
.cert-no{text-align:right;font-size:8pt;color:#888}

.accent-band{background:<?= $accentColor ?>;color:#fff;padding:8px 14px;border-radius:6px;margin-bottom:14px;text-align:center;font-size:12pt;font-weight:700;letter-spacing:.5px}

.patient-box{background:#f8f9fa;border-left:4px solid <?= $accentColor ?>;padding:10px 14px;margin-bottom:16px;border-radius:0 6px 6px 0}
.info-row{display:flex;gap:24px;flex-wrap:wrap;margin-bottom:4px;font-size:9.5pt}
.info-label{color:#888;font-size:8pt;text-transform:uppercase;letter-spacing:.3px}
.info-value{font-weight:600}

.cert-body{line-height:1.8;font-size:10.5pt;margin-bottom:16px}
.cert-body p{margin-bottom:10px}
.cert-body strong{color:#111}
.highlight-box{background:#f0f9f4;border:1px solid #a7d7bc;border-radius:6px;padding:10px 14px;margin:12px 0}

.sig-grid{display:flex;justify-content:space-between;margin-top:32px}
.sig-block{text-align:center;min-width:200px}
.sig-line{border-top:1.5px solid #333;padding-top:5px;margin-top:28px}
.sig-name{font-weight:700;font-size:9.5pt}
.sig-spec{font-size:8pt;color:#555}

.doc-footer{margin-top:20px;padding-top:8px;border-top:1px solid #e0e0e0;font-size:7.5pt;color:#999;display:flex;justify-content:space-between}

.stamp-circle{width:90px;height:90px;border-radius:50%;border:3px dashed <?= $accentColor ?>;display:flex;align-items:center;justify-content:center;font-size:7pt;color:<?= $accentColor ?>;text-align:center;margin:0 auto 6px}
</style>
</head>
<body>

<div class="actions">
  <button class="btn" style="background:<?= $accentColor ?>" onclick="window.print()">🖨 Print Certificate</button>
  <button class="btn" style="background:#6c757d" onclick="window.close()">Close</button>
</div>

<div class="page">
  <!-- Letterhead -->
  <div class="letterhead">
    <div style="display:flex;align-items:center">
      <div class="org-logo">
        <?php if (!empty($docRow['logo'])): ?><img src="<?= APP_URL ?>/uploads/logos/<?= e($docRow['logo']) ?>" alt=""><?php else: ?><?= $initials ?><?php endif; ?>
      </div>
      <div class="org-info">
        <div class="org-name"><?= e($docRow['org_name']) ?></div>
        <div class="org-sub">
          <?= e($docRow['address'] ?? '') ?>
          <?php if ($docRow['org_phone']): ?> &bull; <?= e($docRow['org_phone']) ?><?php endif; ?>
          <?php if ($docRow['org_email']): ?> &bull; <?= e($docRow['org_email']) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="cert-no">
      Ref: <?= $certNo ?><br>
      Date: <?= date('d M Y') ?>
    </div>
  </div>

  <!-- Title -->
  <div class="accent-band"><?= $typeLabels[$certType] ?></div>

  <!-- Patient -->
  <div class="patient-box">
    <div class="info-row">
      <div><div class="info-label">Patient Name</div><div class="info-value"><?= e($patient['full_name']) ?></div></div>
      <div><div class="info-label">Patient No.</div><div class="info-value">#<?= e($patient['patient_no'] ?? $patientId) ?></div></div>
      <?php if ($age !== null): ?><div><div class="info-label">Age</div><div class="info-value"><?= $age ?> years</div></div><?php endif; ?>
      <?php if ($patient['gender']): ?><div><div class="info-label">Gender</div><div class="info-value"><?= ucfirst($patient['gender']) ?></div></div><?php endif; ?>
    </div>
    <?php if ($patient['id_number'] ?? ''): ?>
    <div class="info-row"><div><div class="info-label">ID Number</div><div class="info-value"><?= e($patient['id_number']) ?></div></div></div>
    <?php endif; ?>
  </div>

  <!-- Certificate body -->
  <div class="cert-body">
    <?php $docFull = 'Dr. ' . trim(($docRow['first_name'] ?? '') . ' ' . ($docRow['last_name'] ?? '')); ?>
    <?php if ($certType === 'sick'): ?>
    <p>This is to certify that <strong><?= e($patient['full_name']) ?></strong>, Patient No. <strong>#<?= e($patient['patient_no'] ?? $patientId) ?></strong>, attended this clinic on <strong><?= date('d F Y', strtotime($fromDate)) ?></strong> and was examined by the undersigned medical officer.</p>
    <?php if ($diagnosis): ?><p>The patient is suffering from <strong><?= e($diagnosis) ?></strong>.</p><?php endif; ?>
    <div class="highlight-box">
      <strong>Medical Leave Recommended:</strong><br>
      From <strong><?= date('d F Y', strtotime($fromDate)) ?></strong> to <strong><?= date('d F Y', strtotime($toDate)) ?></strong>
      (<?= max(1, (int)((strtotime($toDate) - strtotime($fromDate)) / 86400) + 1) ?> day<?= max(1, (int)((strtotime($toDate) - strtotime($fromDate)) / 86400) + 1) !== 1 ? 's' : '' ?>)
    </div>
    <p>The patient is advised to rest and refrain from work during the above period.</p>

    <?php elseif ($certType === 'fit'): ?>
    <p>This is to certify that <strong><?= e($patient['full_name']) ?></strong> was examined by the undersigned on <strong><?= date('d F Y', strtotime($fromDate)) ?></strong>.</p>
    <?php if ($diagnosis): ?><p>History / Condition noted: <strong><?= e($diagnosis) ?></strong>.</p><?php endif; ?>
    <div class="highlight-box">
      The above named individual is hereby certified <strong>FIT FOR WORK / NORMAL DUTIES</strong> with effect from <strong><?= date('d F Y', strtotime($toDate)) ?></strong>.
    </div>
    <p>There are no medical contraindications to the resumption of normal work duties.</p>

    <?php elseif ($certType === 'referral'): ?>
    <p>Dear Colleague,</p>
    <p>I am referring <strong><?= e($patient['full_name']) ?></strong>, aged <strong><?= $age !== null ? $age.' years' : 'N/A' ?></strong>, for specialist review and further management.</p>
    <?php if ($diagnosis): ?><p><strong>Presenting Complaint / Diagnosis:</strong> <?= e($diagnosis) ?></p><?php endif; ?>
    <div class="highlight-box">
      <strong>Referred To:</strong> <?= $refDoctor ? e($refDoctor) : 'Specialist' ?><?= $refHospital ? ', '.e($refHospital) : '' ?>
    </div>
    <p>Please kindly review this patient and advise on further management. Your feedback will be highly appreciated.</p>
    <?php endif; ?>

    <?php if ($notes): ?>
    <div style="background:#fff8e6;border:1px solid #fde68a;border-radius:5px;padding:8px 12px;font-size:9.5pt;margin-top:10px">
      <strong>Additional Notes:</strong><br><?= nl2br(e($notes)) ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Signatures -->
  <div class="sig-grid">
    <div class="sig-block">
      <div class="stamp-circle">OFFICIAL<br>STAMP</div>
      <div style="font-size:7.5pt;color:#aaa;text-align:center">Clinic Stamp</div>
    </div>
    <div class="sig-block">
      <div class="sig-line">
        <div class="sig-name"><?= e($docFull) ?></div>
        <?php if ($docRow['specialization'] ?? ''): ?><div class="sig-spec"><?= e($docRow['specialization']) ?></div><?php endif; ?>
        <?php if ($docRow['qualification'] ?? ''): ?><div class="sig-spec"><?= e($docRow['qualification']) ?></div><?php endif; ?>
        <div class="sig-spec"><?= e($docRow['org_name']) ?></div>
      </div>
    </div>
    <div class="sig-block">
      <div class="sig-line">
        <div class="sig-name"><?= e($patient['full_name']) ?></div>
        <div class="sig-spec">Patient / Recipient</div>
      </div>
    </div>
  </div>

  <div class="doc-footer">
    <div>Ref: <?= $certNo ?> &bull; <?= date('d M Y H:i') ?></div>
    <div>This certificate was issued by <?= e($docRow['org_name']) ?></div>
  </div>
</div>

</body>
</html>
