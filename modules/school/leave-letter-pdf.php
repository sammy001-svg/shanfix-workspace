<?php
/**
 * School Module — Leave Approval / Rejection Letter (print-friendly HTML)
 * GET: ?id=X  (leave request ID)
 * Auth: admin staff OR teacher viewing own leave
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$leaveId = (int)($_GET['id'] ?? 0);
$orgId   = 0;
$isStaff = false;
$ownTchId = null;

if (!empty($_SESSION['user_id'])) {
    requireModuleAccess('school');
    $u = currentUser();
    $orgId   = (int)$u['org_id'];
    $isStaff = true;
} elseif (!empty($_SESSION['tch_id'])) {
    $orgId    = (int)$_SESSION['tch_org_id'];
    $ownTchId = (int)$_SESSION['tch_id'];
} else {
    redirect(APP_URL . '/auth/login.php');
}

if (!$leaveId) exit('Leave request ID required.');

// ── Load leave request ────────────────────────────────────────────
$leave = null;
try {
    $s = $pdo->prepare(
        "SELECT lr.*,
                lt.name AS leave_type_name, lt.paid_leave,
                CONCAT(t.first_name,' ',t.last_name) AS staff_name,
                t.employee_id, t.email AS staff_email,
                CONCAT(a.first_name,' ',a.last_name) AS approved_by_name
         FROM sch_leave_requests lr
         LEFT JOIN sch_leave_types lt ON lt.id = lr.leave_type_id
         LEFT JOIN sch_teachers t    ON t.id = lr.staff_id AND lr.staff_type='teacher'
         LEFT JOIN users a           ON a.id = lr.approved_by
         WHERE lr.id=? AND lr.org_id=? LIMIT 1"
    );
    $s->execute([$leaveId, $orgId]);
    $leave = $s->fetch() ?: null;
} catch (Throwable $e) {}

if (!$leave) { http_response_code(404); exit('Leave request not found.'); }

// Teachers may only view their own
if (!$isStaff && $ownTchId !== (int)$leave['staff_id']) {
    http_response_code(403); exit('Access denied.');
}

// Only generate letters for decided requests
if (!in_array($leave['status'], ['approved','rejected'])) {
    exit('A letter can only be generated once the request has been approved or rejected.');
}

// ── School info ───────────────────────────────────────────────────
$school = [];
try {
    $s = $pdo->prepare("SELECT * FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$orgId]); $school = $s->fetch() ?: [];
} catch (Throwable $e) {}

$isApproved = $leave['status'] === 'approved';
$refNo = 'LV-' . date('Y') . '-' . str_pad($leave['id'], 4, '0', STR_PAD_LEFT);
$letterDate = $leave['approved_at'] ? date('d F Y', strtotime($leave['approved_at'])) : date('d F Y');
$startFmt   = date('d F Y', strtotime($leave['start_date']));
$endFmt     = date('d F Y', strtotime($leave['end_date']));
$days       = (int)$leave['days'];
$accentColor = $isApproved ? '#1A8A4E' : '#dc2626';
$accentLight = $isApproved ? '#f0fdf4' : '#fef2f2';
$accentBorder= $isApproved ? '#86efac' : '#fca5a5';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Leave <?= $isApproved?'Approval':'Rejection' ?> Letter — <?= e($leave['staff_name']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#1a1a1a;background:#f0f2f5;line-height:1.6}
.page{max-width:680px;margin:24px auto;background:#fff;border:1px solid #dde3ec;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)}

/* Letterhead */
.letterhead{padding:28px 32px 20px;border-bottom:3px solid <?= $accentColor ?>}
.lh-top{display:flex;align-items:center;gap:16px;margin-bottom:14px}
.logo-box{width:60px;height:60px;background:#0B2D4E;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700;color:#fff;flex-shrink:0}
.logo-img{width:60px;height:60px;object-fit:contain;border-radius:8px;flex-shrink:0}
.org-name{font-size:19px;font-weight:700;color:#0B2D4E;line-height:1.2}
.org-sub{font-size:11px;color:#6b7280;margin-top:3px}
.lh-meta{display:flex;justify-content:space-between;align-items:flex-start;font-size:11px;color:#6b7280}
.ref-block .ref-label{font-weight:700;color:#111;font-size:12px}

/* Decision banner */
.decision-band{padding:10px 32px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:2px;background:<?= $accentColor ?>;color:#fff;text-align:center}

/* Body */
.letter-body{padding:28px 32px}
.salutation{margin-bottom:16px;font-weight:600;font-size:13.5px}
.body-text{margin-bottom:14px;text-align:justify}

/* Details table */
.details-box{background:<?= $accentLight ?>;border:1px solid <?= $accentBorder ?>;border-radius:6px;padding:14px 18px;margin:18px 0}
.details-box table{width:100%;border-collapse:collapse;font-size:12.5px}
.details-box td{padding:5px 0;vertical-align:top}
.details-box td:first-child{color:#6b7280;width:160px;font-size:11.5px;text-transform:uppercase;letter-spacing:.3px}
.details-box td:last-child{font-weight:600;color:#111}

/* Conditions / notes */
.notes-box{background:#fffbeb;border-left:3px solid #f59e0b;padding:10px 14px;margin:16px 0;font-size:12px;color:#92400e;border-radius:0 4px 4px 0}

/* Signature */
.sig-section{margin-top:28px;display:flex;justify-content:space-between;gap:32px}
.sig-col{}
.sig-line{border-top:1px solid #374151;margin-top:36px;padding-top:5px;font-size:11px;color:#374151;font-weight:600}
.sig-sublabel{font-size:10.5px;color:#9ca3af;margin-top:2px}

/* Footer note */
.doc-footer{border-top:1px solid #e5e7eb;padding:12px 32px;font-size:10px;color:#9ca3af;text-align:center;line-height:1.6}

/* Actions */
.actions{text-align:center;padding:14px;background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:center}
.btn{padding:8px 22px;border-radius:6px;font-size:12.5px;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-block}
.btn-primary{background:#0B2D4E;color:#fff}
.btn-secondary{background:#fff;color:#374151;border:1px solid #d1d5db}

@media print{
  body{background:#fff}
  .actions{display:none}
  .page{margin:0;border:none;border-radius:0;box-shadow:none;max-width:100%}
  @page{size:A4;margin:1.5cm}
}
</style>
</head>
<body>
<div class="page">

  <!-- Letterhead -->
  <div class="letterhead">
    <div class="lh-top">
      <?php if (!empty($school['logo'])): ?>
      <img src="<?= e(APP_URL.'/assets/uploads/logos/'.$school['logo']) ?>" class="logo-img" alt="Logo">
      <?php else: ?>
      <div class="logo-box"><?= strtoupper(substr($school['name']??'S',0,1)) ?></div>
      <?php endif; ?>
      <div>
        <div class="org-name"><?= e($school['name'] ?? 'School Name') ?></div>
        <div class="org-sub">
          <?= e($school['address']??'') ?>
          <?php if(!empty($school['phone'])): ?> &bull; Tel: <?= e($school['phone']) ?><?php endif; ?>
          <?php if(!empty($school['email'])): ?> &bull; <?= e($school['email']) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="lh-meta">
      <div>
        <span class="ref-label">Ref: <?= e($refNo) ?></span>
      </div>
      <div>Date: <?= $letterDate ?></div>
    </div>
  </div>

  <div class="decision-band">
    <?= $isApproved ? '✓ Leave Approval Notice' : '✗ Leave Rejection Notice' ?>
  </div>

  <div class="letter-body">

    <div class="salutation">Dear <?= e($leave['staff_name']) ?>,</div>

    <?php if ($isApproved): ?>
    <p class="body-text">
      We are pleased to inform you that your leave request has been reviewed and
      <strong>approved</strong> by the management. Please find the details of your approved leave below.
    </p>
    <?php else: ?>
    <p class="body-text">
      We regret to inform you that after careful consideration, your leave request has been
      <strong>declined</strong>. The details of your request and the reason for the decision are outlined below.
    </p>
    <?php endif; ?>

    <!-- Leave details box -->
    <div class="details-box">
      <table>
        <tr><td>Employee Name</td><td><?= e($leave['staff_name']) ?></td></tr>
        <?php if ($leave['employee_id']): ?>
        <tr><td>Employee ID</td><td><?= e($leave['employee_id']) ?></td></tr>
        <?php endif; ?>
        <tr><td>Leave Type</td><td><?= e($leave['leave_type_name']) ?></td></tr>
        <tr><td>Start Date</td><td><?= $startFmt ?></td></tr>
        <tr><td>End Date</td><td><?= $endFmt ?></td></tr>
        <tr><td>Duration</td><td><?= $days ?> working day<?= $days!==1?'s':'' ?></td></tr>
        <tr><td>Leave Category</td><td><?= $leave['paid_leave'] ? 'Paid Leave' : 'Unpaid Leave' ?></td></tr>
        <tr><td>Decision</td><td><strong style="color:<?= $accentColor ?>"><?= ucfirst($leave['status']) ?></strong></td></tr>
      </table>
    </div>

    <?php if (!empty($leave['admin_notes'])): ?>
    <div class="notes-box">
      <strong><?= $isApproved ? 'Special Conditions / Notes:' : 'Reason for Rejection:' ?></strong><br>
      <?= nl2br(e($leave['admin_notes'])) ?>
    </div>
    <?php endif; ?>

    <?php if ($isApproved): ?>
    <p class="body-text">
      During your absence, please ensure that all pending duties are properly delegated and
      that you hand over any ongoing responsibilities to your supervisor or a designated colleague.
      Kindly report back to duty on <strong><?= date('d F Y', strtotime($leave['end_date'].' +1 day')) ?></strong>.
    </p>
    <p class="body-text">
      We wish you a restful <?= $days <= 2 ? 'break' : 'leave period' ?>.
    </p>
    <?php else: ?>
    <p class="body-text">
      We understand this may be inconvenient and encourage you to speak with your supervisor
      to explore alternative arrangements or to resubmit your request at a more suitable time.
      Should you have any queries, please contact the HR office.
    </p>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="sig-section">
      <div class="sig-col">
        <div class="sig-line"><?= e($leave['approved_by_name'] ?? 'Authorised Officer') ?></div>
        <div class="sig-sublabel">HR / Administration</div>
        <div class="sig-sublabel"><?= e($school['name']??'') ?></div>
      </div>
      <div class="sig-col">
        <div class="sig-line"><?= e($leave['staff_name']) ?></div>
        <div class="sig-sublabel">Employee Acknowledgement</div>
        <div class="sig-sublabel">Date: ___________________</div>
      </div>
    </div>

  </div>

  <div class="doc-footer">
    This is an official document issued by <?= e($school['name']??'the school') ?> HR Department.
    &bull; Ref: <?= e($refNo) ?> &bull; Generated: <?= date('d M Y, H:i') ?>
  </div>

  <div class="actions">
    <a href="javascript:history.back()" class="btn btn-secondary">← Back</a>
    <button class="btn btn-primary" onclick="window.print()">🖨 Print Letter</button>
  </div>

</div>
</body>
</html>
