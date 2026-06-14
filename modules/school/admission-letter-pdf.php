<?php
/**
 * School Module — Student Admission Letter (print-friendly HTML)
 * GET: ?student_id=X
 * Auth: admin staff OR parent portal (linked students) OR student portal (own)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$studentId = (int)($_GET['student_id'] ?? 0);
$orgId     = 0;
$isStaff   = false;

if (!empty($_SESSION['user_id'])) {
    requireModuleAccess('school');
    $u = currentUser();
    $orgId   = (int)$u['org_id'];
    $isStaff = true;
} elseif (!empty($_SESSION['par_id'])) {
    $orgId   = (int)$_SESSION['par_org_id'];
    $parSids = $_SESSION['par_sids'] ?? [];
    if (!in_array($studentId, $parSids, true)) {
        http_response_code(403); exit('Access denied.');
    }
} elseif (!empty($_SESSION['stu_id'])) {
    if ((int)$_SESSION['stu_id'] !== $studentId) {
        http_response_code(403); exit('Access denied.');
    }
    $orgId = (int)$_SESSION['stu_org_id'];
} else {
    redirect(APP_URL . '/auth/login.php');
}

if (!$studentId) exit('Student ID required.');

// ── Load student ──────────────────────────────────────────────────
$student = [];
try {
    $s = $pdo->prepare(
        "SELECT s.*,
                c.name AS class_name, c.curriculum,
                ay.name AS academic_year
         FROM sch_students s
         LEFT JOIN sch_classes c ON c.id = s.class_id
         LEFT JOIN sch_academic_years ay ON ay.is_current = 1 AND ay.org_id = s.org_id
         WHERE s.id=? AND s.org_id=? LIMIT 1"
    );
    $s->execute([$studentId, $orgId]);
    $student = $s->fetch() ?: [];
} catch (Throwable $e) {}

if (!$student) { http_response_code(404); exit('Student not found.'); }

// ── Parent/guardian ───────────────────────────────────────────────
$guardian = [];
try {
    $s = $pdo->prepare(
        "SELECT p.full_name, p.phone, p.email, p.relationship
         FROM sch_parents p
         JOIN sch_student_parents sp ON sp.parent_id = p.id
         WHERE sp.student_id=? AND p.org_id=?
         ORDER BY sp.is_primary DESC LIMIT 1"
    );
    $s->execute([$studentId, $orgId]);
    $guardian = $s->fetch() ?: [];
} catch (Throwable $e) {}

// ── School info ───────────────────────────────────────────────────
$school = [];
try {
    $s = $pdo->prepare("SELECT * FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$orgId]); $school = $s->fetch() ?: [];
} catch (Throwable $e) {}

$refNo      = 'ADM-' . date('Y') . '-' . strtoupper(str_pad($student['admission_no'] ?? $studentId, 6, '0', STR_PAD_LEFT));
$letterDate = date('d F Y');
$studentName = trim(($student['first_name']??'') . ' ' . ($student['last_name']??''));
$dob        = !empty($student['date_of_birth']) ? date('d F Y', strtotime($student['date_of_birth'])) : '—';
$gender     = ucfirst($student['gender'] ?? '');
$classJoin  = date('d F Y', strtotime($student['created_at'] ?? 'now'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admission Letter — <?= e($studentName) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#1a1a1a;background:#f0f2f5;line-height:1.7}
.page{max-width:680px;margin:24px auto;background:#fff;border:1px solid #dde3ec;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)}

/* Letterhead */
.letterhead{padding:28px 32px 0}
.lh-top{display:flex;align-items:center;gap:16px;padding-bottom:14px;border-bottom:1px solid #e5e7eb}
.logo-box{width:68px;height:68px;background:#0B2D4E;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;color:#fff;flex-shrink:0}
.logo-img{width:68px;height:68px;object-fit:contain;border-radius:8px;flex-shrink:0}
.org-name{font-size:20px;font-weight:700;color:#0B2D4E;line-height:1.2}
.org-tagline{font-size:11px;font-style:italic;color:#6b7280;margin-top:2px}
.org-contacts{font-size:11px;color:#6b7280;margin-top:4px}
.lh-right{margin-left:auto;text-align:right;flex-shrink:0;font-size:11.5px;color:#6b7280}
.lh-right .ref{font-weight:700;color:#0B2D4E;font-size:13px}

/* Rule band */
.rule-band{height:5px;background:linear-gradient(to right,#0B2D4E,#1A8A4E);margin:0 32px}

/* Subject line */
.subject-block{padding:20px 32px 0}
.subject-line{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#0B2D4E;margin-bottom:6px;padding-bottom:6px;border-bottom:2px solid #1A8A4E;display:inline-block}

/* Body */
.letter-body{padding:16px 32px 28px;font-size:13px}
.salutation{margin-bottom:14px;font-weight:600}
.body-text{margin-bottom:13px;text-align:justify}

/* Student details */
.admit-card{background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:16px 20px;margin:18px 0}
.admit-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#166534;margin-bottom:12px}
.admit-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 24px}
.admit-row{}
.admit-label{font-size:10.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px}
.admit-val{font-weight:600;color:#111;font-size:13px}

/* Requirements list */
.req-list{margin:12px 0 12px 18px;color:#374151}
.req-list li{margin-bottom:6px}

/* Signatures */
.sig-section{margin-top:32px;display:flex;justify-content:space-between;gap:32px}
.sig-line{border-top:1px solid #374151;margin-top:40px;padding-top:5px;font-size:11.5px;color:#374151;font-weight:600}
.sig-sublabel{font-size:10.5px;color:#9ca3af;margin-top:2px}

/* Stamp placeholder */
.stamp-area{text-align:right;margin-top:-50px}
.stamp-circle{display:inline-block;width:80px;height:80px;border:2px dashed #d1d5db;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;color:#d1d5db;text-align:center;line-height:1.3;float:right;margin-top:30px}

/* Footer */
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
        <?php if (!empty($school['tagline'])): ?>
        <div class="org-tagline"><?= e($school['tagline']) ?></div>
        <?php endif; ?>
        <div class="org-contacts">
          <?= e($school['address']??'') ?>
          <?php if(!empty($school['phone'])): ?> &bull; Tel: <?= e($school['phone']) ?><?php endif; ?>
          <?php if(!empty($school['email'])): ?> &bull; <?= e($school['email']) ?><?php endif; ?>
        </div>
      </div>
      <div class="lh-right">
        <div class="ref">Ref: <?= e($refNo) ?></div>
        <div>Date: <?= $letterDate ?></div>
        <?php if ($student['academic_year']): ?>
        <div>Year: <?= e($student['academic_year']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="rule-band"></div>

  <!-- Addressee -->
  <div class="subject-block">
    <?php if ($guardian): ?>
    <p style="font-size:13px;margin-bottom:6px">
      <?= e($guardian['full_name']) ?><br>
      <?php if(!empty($guardian['email'])): ?><?= e($guardian['email']) ?><br><?php endif; ?>
    </p>
    <?php endif; ?>
    <div class="subject-line">
      LETTER OF ADMISSION — <?= strtoupper(e($student['class_name']??'')) ?>
    </div>
  </div>

  <div class="letter-body">

    <p class="salutation">Dear <?= $guardian ? e($guardian['full_name']) : 'Parent / Guardian' ?>,</p>

    <p class="body-text">
      On behalf of the management and staff of <strong><?= e($school['name']??'this school') ?></strong>,
      we are delighted to welcome <strong><?= e($studentName) ?></strong> to our school community.
      Following the successful completion of the admission process, we are pleased to confirm
      enrollment for the <?= $student['academic_year'] ? e($student['academic_year']).' academic year' : 'current academic year' ?>.
    </p>

    <!-- Student Details -->
    <div class="admit-card">
      <div class="admit-title">Student Admission Details</div>
      <div class="admit-grid">
        <div class="admit-row">
          <div class="admit-label">Full Name</div>
          <div class="admit-val"><?= e($studentName) ?></div>
        </div>
        <div class="admit-row">
          <div class="admit-label">Admission No.</div>
          <div class="admit-val"><?= e($student['admission_no'] ?? '—') ?></div>
        </div>
        <div class="admit-row">
          <div class="admit-label">Class / Grade</div>
          <div class="admit-val"><?= e($student['class_name'] ?? '—') ?></div>
        </div>
        <?php if ($student['curriculum']): ?>
        <div class="admit-row">
          <div class="admit-label">Curriculum</div>
          <div class="admit-val"><?= e($student['curriculum']) ?></div>
        </div>
        <?php endif; ?>
        <div class="admit-row">
          <div class="admit-label">Date of Birth</div>
          <div class="admit-val"><?= $dob ?></div>
        </div>
        <?php if ($gender): ?>
        <div class="admit-row">
          <div class="admit-label">Gender</div>
          <div class="admit-val"><?= $gender ?></div>
        </div>
        <?php endif; ?>
        <?php if ($guardian): ?>
        <div class="admit-row">
          <div class="admit-label">Guardian</div>
          <div class="admit-val"><?= e($guardian['full_name']) ?></div>
        </div>
        <div class="admit-row">
          <div class="admit-label">Guardian Phone</div>
          <div class="admit-val"><?= e($guardian['phone']??'—') ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($student['blood_group'])): ?>
        <div class="admit-row">
          <div class="admit-label">Blood Group</div>
          <div class="admit-val"><?= e($student['blood_group']) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <p class="body-text">
      Please ensure the student reports to school as scheduled and comes prepared with all
      necessary learning materials. The following items are required for joining:
    </p>
    <ul class="req-list">
      <li>This admission letter (original copy to be submitted to the class teacher)</li>
      <li>Copies of previous academic records / transcripts</li>
      <li>Two recent passport-size photographs</li>
      <li>Birth certificate (certified copy)</li>
      <li>Proof of fee payment for the current term</li>
    </ul>

    <p class="body-text">
      We look forward to partnering with you in <?= $gender==='Female'?'her':($gender==='Male'?'his':'their') ?>
      academic journey. Should you have any questions, please do not hesitate to contact the school office.
    </p>

    <p class="body-text">Yours faithfully,</p>

    <!-- Signatures -->
    <div class="sig-section">
      <div>
        <div class="sig-line">___________________________</div>
        <div class="sig-sublabel">Principal / Head Teacher</div>
        <div class="sig-sublabel"><?= e($school['name']??'') ?></div>
      </div>
      <div style="text-align:right">
        <div class="stamp-circle">SCHOOL<br>STAMP</div>
      </div>
    </div>

  </div>

  <div class="doc-footer">
    This is an official admission letter issued by <?= e($school['name']??'the school') ?>.
    &bull; Ref: <?= e($refNo) ?> &bull; Generated: <?= date('d M Y, H:i') ?>
  </div>

  <div class="actions">
    <a href="javascript:history.back()" class="btn btn-secondary">← Back</a>
    <button class="btn btn-primary" onclick="window.print()">🖨 Print Letter</button>
  </div>

</div>
</body>
</html>
