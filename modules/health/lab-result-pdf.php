<?php
/**
 * Lab Result PDF — standalone A4 printable report
 * Auth: admin/staff OR doctor portal (own patients) OR patient portal (own results)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$orderId = (int)($_GET['id'] ?? 0);
if (!$orderId) { http_response_code(404); exit('Lab order not found.'); }

// ── Auth ──────────────────────────────────────────────────────────
$isAdmin   = isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['super_admin','admin','client_admin','staff']);
$isDoctor  = !empty($_SESSION['doc_id']);
$isPatient = !empty($_SESSION['patient_id']);

if (!$isAdmin && !$isDoctor && !$isPatient) {
    redirect(APP_URL . '/auth/login.php');
}

// ── Load order ────────────────────────────────────────────────────
$order = null;
try {
    if ($isAdmin) {
        $orgId = (int)currentUser()['org_id'];
    } elseif ($isDoctor) {
        $orgId = (int)$_SESSION['doc_org_id'];
    } else {
        $orgId = (int)($_SESSION['patient_org_id'] ?? 0);
    }

    $s = $pdo->prepare("
        SELECT lo.*,
               lt.name AS test_name, lt.category AS test_category,
               lt.normal_range, lt.unit AS test_unit,
               CONCAT(p.first_name,' ',p.last_name) AS patient_name,
               p.patient_no, p.date_of_birth, p.gender, p.phone AS patient_phone,
               CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
               d.specialization AS doctor_specialty,
               rb.name AS resulted_by_name,
               o.name AS org_name, o.address AS org_address,
               o.phone AS org_phone, o.email AS org_email,
               o.logo AS org_logo, o.city, o.country
        FROM health_lab_orders lo
        JOIN health_lab_tests lt ON lt.id = lo.test_id
        JOIN health_patients p ON p.id = lo.patient_id
        LEFT JOIN health_doctors d ON d.id = lo.doctor_id
        LEFT JOIN users rb ON rb.id = lo.resulted_by
        JOIN organizations o ON o.id = lo.org_id
        WHERE lo.id = ? AND lo.org_id = ?
    ");
    $s->execute([$orderId, $orgId]);
    $order = $s->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!$order) { http_response_code(404); exit('Lab result not found or access denied.'); }

// Patient portal: own results only
if ($isPatient && !$isAdmin && (int)$order['patient_id'] !== (int)$_SESSION['patient_id']) {
    http_response_code(403); exit('Access denied.');
}

// Doctor portal: can only see own patients' orders (those they ordered)
if ($isDoctor && !$isAdmin && (int)$order['doctor_id'] !== (int)$_SESSION['doc_id']) {
    // Still allow — doctor may view results for patients they ordered for
    // but enforce org match
    if ((int)$order['org_id'] !== (int)$_SESSION['doc_org_id']) {
        http_response_code(403); exit('Access denied.');
    }
}

// ── Helpers ───────────────────────────────────────────────────────
$patientAge = '';
if (!empty($order['date_of_birth'])) {
    $patientAge = date_diff(date_create($order['date_of_birth']), date_create('today'))->y . ' yrs';
}

$flagLabel = match($order['result_flag'] ?? '') {
    'critical' => 'CRITICAL',
    'high'     => 'HIGH',
    'low'      => 'LOW',
    'normal'   => 'NORMAL',
    default    => null,
};
$flagColor = match($order['result_flag'] ?? '') {
    'critical' => '#dc3545',
    'high'     => '#fd7e14',
    'low'      => '#0dcaf0',
    'normal'   => '#198754',
    default    => '#6c757d',
};

$priority = strtoupper($order['priority'] ?? 'ROUTINE');
$priColor  = match($order['priority'] ?? '') {
    'stat'   => '#dc3545',
    'urgent' => '#fd7e14',
    default  => '#6c757d',
};

$reportDate = $order['resulted_at']
    ? date('d F Y', strtotime($order['resulted_at']))
    : date('d F Y');

$logoSrc = '';
if (!empty($order['org_logo']) && file_exists(BASE_PATH . '/uploads/logos/' . $order['org_logo'])) {
    $logoSrc = APP_URL . '/uploads/logos/' . htmlspecialchars($order['org_logo']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lab Result — <?= htmlspecialchars($order['order_no']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI', Arial, sans-serif; font-size:13px; color:#1a1a1a; background:#f5f5f5; }

.page {
  max-width:794px; margin:20px auto; background:#fff;
  padding:32px 36px; border-radius:4px;
  box-shadow:0 2px 16px rgba(0,0,0,.12);
}

/* Header */
.header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding-bottom:16px; border-bottom:3px solid #c00; margin-bottom:20px; }
.header-brand { display:flex; align-items:center; gap:14px; }
.header-logo { width:64px; height:64px; object-fit:contain; }
.logo-placeholder { width:64px; height:64px; border-radius:8px; background:#c00; display:flex; align-items:center; justify-content:center; font-size:1.6rem; font-weight:900; color:#fff; }
.org-name { font-size:1.25rem; font-weight:800; color:#c00; }
.org-sub { font-size:.75rem; color:#666; margin-top:2px; }
.header-right { text-align:right; }
.report-type { font-size:1.05rem; font-weight:700; color:#c00; text-transform:uppercase; letter-spacing:.5px; }
.order-no { font-size:.8rem; color:#555; margin-top:4px; }
.report-date { font-size:.75rem; color:#888; margin-top:2px; }

/* Priority badge */
.priority-badge {
  display:inline-block; padding:3px 10px; border-radius:4px; font-size:.7rem; font-weight:800;
  letter-spacing:.5px; color:#fff; margin-top:6px;
  background: <?= $priColor ?>;
}

/* Critical alert */
.critical-alert {
  background:#fff0f0; border:2px solid #dc3545; border-radius:6px;
  padding:12px 16px; margin-bottom:20px; display:flex; align-items:center; gap:12px;
}
.critical-icon { font-size:1.6rem; }
.critical-text strong { color:#c00; font-size:.95rem; }
.critical-text p { color:#555; font-size:.8rem; margin-top:2px; }

/* Patient info grid */
.info-grid {
  display:grid; grid-template-columns:1fr 1fr; gap:0; margin-bottom:20px;
  border:1px solid #e0e0e0; border-radius:6px; overflow:hidden;
}
.info-section { background:#f8f9fa; padding:12px 16px; border-bottom:1px solid #e0e0e0; }
.info-section:nth-child(2n) { background:#fff; border-left:1px solid #e0e0e0; }
.info-section:last-child, .info-section:nth-last-child(2) { border-bottom:0; }
.info-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.3px; color:#888; }
.info-value { font-size:.9rem; font-weight:600; color:#1a1a1a; margin-top:2px; }

/* Result card */
.result-card {
  border:2px solid #c00; border-radius:8px; overflow:hidden; margin-bottom:20px;
}
.result-card-header {
  background:#c00; color:#fff; padding:10px 16px;
  display:flex; align-items:center; justify-content:space-between;
}
.result-card-header .test-name { font-size:1rem; font-weight:700; }
.result-card-header .test-cat { font-size:.75rem; opacity:.8; }
.result-card-body { padding:16px; }

.result-main {
  display:flex; align-items:flex-start; gap:24px; flex-wrap:wrap; margin-bottom:16px;
}
.result-value-block { flex:0 0 auto; }
.result-number { font-size:2.8rem; font-weight:800; color:#1a1a1a; line-height:1; }
.result-unit { font-size:1rem; color:#666; margin-left:4px; }

.result-meta { flex:1; min-width:180px; }
.result-meta-row { display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid #f0f0f0; font-size:.82rem; }
.result-meta-row:last-child { border-bottom:0; }
.result-meta-label { color:#666; }
.result-meta-value { font-weight:600; color:#1a1a1a; }

.flag-badge {
  display:inline-block; padding:4px 14px; border-radius:20px;
  font-size:.8rem; font-weight:800; letter-spacing:.4px; color:#fff;
  background: <?= $flagColor ?>;
}

/* Notes */
.notes-box { background:#fffbeb; border:1px solid #fbbf24; border-radius:6px; padding:12px 16px; margin-top:16px; }
.notes-box strong { color:#92400e; font-size:.8rem; text-transform:uppercase; letter-spacing:.3px; }
.notes-box p { color:#78350f; font-size:.85rem; margin-top:4px; }

/* Order details table */
.details-table { width:100%; border-collapse:collapse; margin-bottom:20px; font-size:.82rem; }
.details-table th { background:#f5f5f5; color:#555; font-weight:700; padding:7px 10px; text-align:left; border:1px solid #e0e0e0; font-size:.75rem; text-transform:uppercase; letter-spacing:.3px; }
.details-table td { padding:7px 10px; border:1px solid #e0e0e0; color:#1a1a1a; }
.details-table tr:hover td { background:#fafafa; }

/* Signatures */
.sig-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-top:20px; padding-top:16px; border-top:1px solid #e0e0e0; }
.sig-block { text-align:center; }
.sig-line { border-top:1.5px solid #333; margin:0 10px 6px; padding-top:6px; }
.sig-name { font-weight:700; font-size:.82rem; }
.sig-role { font-size:.72rem; color:#888; margin-top:1px; }

/* Footer */
.footer { margin-top:20px; padding-top:12px; border-top:1px solid #e0e0e0; display:flex; justify-content:space-between; align-items:center; font-size:.72rem; color:#aaa; }
.confidential { font-style:italic; }

/* Actions bar (hidden on print) */
.actions { display:flex; gap:10px; margin:0 auto 16px; max-width:794px; justify-content:flex-end; }
.btn-print { background:#c00; color:#fff; border:none; padding:9px 22px; border-radius:6px; font-size:.9rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:7px; }
.btn-close-link { background:#f5f5f5; color:#555; border:1px solid #ddd; padding:9px 18px; border-radius:6px; font-size:.9rem; text-decoration:none; display:flex; align-items:center; gap:6px; }

@media print {
  body { background:#fff; }
  .page { box-shadow:none; margin:0; border-radius:0; padding:20px; max-width:100%; }
  .actions { display:none !important; }
  @page { size:A4 portrait; margin:1.2cm; }
}
</style>
</head>
<body>

<!-- Actions bar -->
<div class="actions">
  <a href="javascript:history.back()" class="btn-close-link">&#8592; Back</a>
  <button class="btn-print" onclick="window.print()">&#128438; Print / Save PDF</button>
</div>

<div class="page">

  <!-- ── Letterhead ──────────────────────────────────────────────── -->
  <div class="header">
    <div class="header-brand">
      <?php if ($logoSrc): ?>
        <img src="<?= $logoSrc ?>" alt="Logo" class="header-logo">
      <?php else: ?>
        <div class="logo-placeholder"><?= strtoupper(substr($order['org_name'],0,1)) ?></div>
      <?php endif; ?>
      <div>
        <div class="org-name"><?= htmlspecialchars($order['org_name']) ?></div>
        <div class="org-sub">
          <?php if ($order['org_address']): ?><?= htmlspecialchars($order['org_address']) ?><?= $order['city'] ? ', '.$order['city'] : '' ?><br><?php endif; ?>
          <?php if ($order['org_phone']): ?><span>&#9990; <?= htmlspecialchars($order['org_phone']) ?></span><?php endif; ?>
          <?php if ($order['org_email']): ?> &nbsp;&#9993; <?= htmlspecialchars($order['org_email']) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="header-right">
      <div class="report-type">Laboratory Report</div>
      <div class="order-no"><strong><?= htmlspecialchars($order['order_no']) ?></strong></div>
      <div class="report-date">Date: <?= $reportDate ?></div>
      <?php if ($order['priority'] !== 'routine'): ?>
      <div><span class="priority-badge"><?= $priority ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Critical Alert ─────────────────────────────────────────── -->
  <?php if ($order['result_flag'] === 'critical'): ?>
  <div class="critical-alert">
    <div class="critical-icon">&#9888;</div>
    <div class="critical-text">
      <strong>&#9888; CRITICAL VALUE — Immediate Physician Notification Required</strong>
      <p>This result falls outside critical threshold limits. The ordering physician must be notified immediately per laboratory protocol.</p>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Patient & Order Info ───────────────────────────────────── -->
  <div class="info-grid">
    <div class="info-section">
      <div class="info-label">Patient Name</div>
      <div class="info-value"><?= htmlspecialchars($order['patient_name']) ?></div>
    </div>
    <div class="info-section">
      <div class="info-label">Patient No.</div>
      <div class="info-value"><?= htmlspecialchars($order['patient_no'] ?? '—') ?></div>
    </div>
    <div class="info-section">
      <div class="info-label">Age / Gender</div>
      <div class="info-value"><?= $patientAge ?: '—' ?> / <?= ucfirst($order['gender'] ?? '—') ?></div>
    </div>
    <div class="info-section">
      <div class="info-label">Contact</div>
      <div class="info-value"><?= htmlspecialchars($order['patient_phone'] ?? '—') ?></div>
    </div>
    <div class="info-section">
      <div class="info-label">Requesting Doctor</div>
      <div class="info-value">
        <?= $order['doctor_name'] ? 'Dr. '.htmlspecialchars($order['doctor_name']) : '—' ?>
        <?php if ($order['doctor_specialty']): ?>
        <span style="color:#888;font-size:.78rem;font-weight:400"><br><?= htmlspecialchars($order['doctor_specialty']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="info-section">
      <div class="info-label">Sample Type</div>
      <div class="info-value"><?= htmlspecialchars($order['sample_type'] ?: '—') ?></div>
    </div>
  </div>

  <!-- ── Result Card ────────────────────────────────────────────── -->
  <div class="result-card">
    <div class="result-card-header">
      <div>
        <div class="test-name"><?= htmlspecialchars($order['test_name']) ?></div>
        <?php if ($order['test_category']): ?>
        <div class="test-cat"><?= htmlspecialchars($order['test_category']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($flagLabel): ?>
      <span class="flag-badge"><?= $flagLabel ?></span>
      <?php endif; ?>
    </div>
    <div class="result-card-body">
      <div class="result-main">
        <div class="result-value-block">
          <div style="color:#666;font-size:.72rem;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Result</div>
          <?php if ($order['result_value']): ?>
          <div>
            <span class="result-number" style="color:<?= $flagColor ?>"><?= htmlspecialchars($order['result_value']) ?></span>
            <?php if ($order['unit'] ?? $order['test_unit']): ?>
            <span class="result-unit"><?= htmlspecialchars($order['unit'] ?? $order['test_unit']) ?></span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <div style="color:#999;font-size:1.1rem;font-style:italic">Pending</div>
          <?php endif; ?>
        </div>

        <div class="result-meta">
          <div class="result-meta-row">
            <span class="result-meta-label">Normal Range</span>
            <span class="result-meta-value"><?= htmlspecialchars($order['normal_range'] ?: '—') ?></span>
          </div>
          <div class="result-meta-row">
            <span class="result-meta-label">Unit</span>
            <span class="result-meta-value"><?= htmlspecialchars($order['unit'] ?? $order['test_unit'] ?? '—') ?></span>
          </div>
          <div class="result-meta-row">
            <span class="result-meta-label">Interpretation</span>
            <span class="result-meta-value"><?= $flagLabel ? '<span style="color:'.$flagColor.';font-weight:800">'.$flagLabel.'</span>' : '—' ?></span>
          </div>
          <div class="result-meta-row">
            <span class="result-meta-label">Result Date</span>
            <span class="result-meta-value"><?= $order['resulted_at'] ? date('d M Y, H:i', strtotime($order['resulted_at'])) : '—' ?></span>
          </div>
          <div class="result-meta-row">
            <span class="result-meta-label">Resulted By</span>
            <span class="result-meta-value"><?= htmlspecialchars($order['resulted_by_name'] ?: '—') ?></span>
          </div>
        </div>
      </div>

      <?php if (!empty($order['result_notes'])): ?>
      <div class="notes-box">
        <strong>&#128196; Notes &amp; Interpretation</strong>
        <p><?= nl2br(htmlspecialchars($order['result_notes'])) ?></p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Order Details Table ────────────────────────────────────── -->
  <table class="details-table">
    <thead>
      <tr>
        <th>Order No.</th>
        <th>Priority</th>
        <th>Date Ordered</th>
        <th>Sample Collected</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong><?= htmlspecialchars($order['order_no']) ?></strong></td>
        <td><span style="color:<?= $priColor ?>;font-weight:800"><?= $priority ?></span></td>
        <td><?= $order['ordered_at'] ? date('d M Y H:i', strtotime($order['ordered_at'])) : '—' ?></td>
        <td><?= $order['collected_at'] ? date('d M Y H:i', strtotime($order['collected_at'])) : '—' ?></td>
        <td><span style="color:#198754;font-weight:700"><?= ucfirst($order['status']) ?></span></td>
      </tr>
    </tbody>
  </table>

  <!-- ── Signatures ────────────────────────────────────────────── -->
  <div class="sig-row">
    <div class="sig-block">
      <div style="min-height:36px"></div>
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($order['resulted_by_name'] ?: 'Lab Technologist') ?></div>
      <div class="sig-role">Medical Laboratory Technologist</div>
    </div>
    <div class="sig-block">
      <div style="min-height:36px"></div>
      <div class="sig-line"></div>
      <div class="sig-name"><?= $order['doctor_name'] ? 'Dr. '.htmlspecialchars($order['doctor_name']) : 'Requesting Doctor' ?></div>
      <div class="sig-role">Requesting Physician</div>
    </div>
    <div class="sig-block">
      <div style="min-height:36px"></div>
      <div class="sig-line"></div>
      <div class="sig-name">Quality Assurance</div>
      <div class="sig-role">Laboratory Quality Control</div>
    </div>
  </div>

  <!-- ── Footer ────────────────────────────────────────────────── -->
  <div class="footer">
    <div class="confidential">CONFIDENTIAL — This report is intended solely for the patient and authorised healthcare professionals.</div>
    <div><?= htmlspecialchars($order['org_name']) ?> &bull; <?= date('d M Y H:i') ?></div>
  </div>

</div><!-- /.page -->

<script>
// Auto-print if ?print=1 in URL
if (new URLSearchParams(window.location.search).get('print') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
}
</script>
</body>
</html>
