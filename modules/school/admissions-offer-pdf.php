<?php
/**
 * School Module — Admissions Offer Letter (print-friendly HTML → PDF)
 * GET: ?id=X  (sch_admissions.id)
 * Auth: admin staff only
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireModuleAccess('school');
$user  = currentUser();
$orgId = (int)$user['org_id'];

$appId = (int)($_GET['id'] ?? 0);
if (!$appId) exit('Application ID required.');

// ── Load application ───────────────────────────────────────────────────
$app = [];
try {
    $s = $pdo->prepare("SELECT * FROM sch_admissions WHERE id=? AND org_id=? LIMIT 1");
    $s->execute([$appId, $orgId]);
    $app = $s->fetch() ?: [];
} catch (Throwable $e) {}
if (!$app) { http_response_code(404); exit('Application not found.'); }

// ── Load school info ───────────────────────────────────────────────────
$school = [];
try {
    $s = $pdo->prepare("SELECT * FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$orgId]); $school = $s->fetch() ?: [];
} catch (Throwable $e) {}

// ── Load current academic year ─────────────────────────────────────────
$academicYear = '';
try {
    $s = $pdo->prepare("SELECT name FROM sch_academic_years WHERE org_id=? AND is_current=1 LIMIT 1");
    $s->execute([$orgId]);
    $academicYear = $s->fetchColumn() ?: '';
} catch (Throwable $e) {}

$refNo      = 'OFR-' . date('Y') . '-' . strtoupper(str_pad($appId, 5, '0', STR_PAD_LEFT));
$letterDate = date('d F Y');
$fullName   = trim($app['first_name'] . ' ' . $app['last_name']);
$gender     = ucfirst($app['gender'] ?? '');
$pronoun    = $gender === 'Female' ? 'her' : ($gender === 'Male' ? 'his' : 'their');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Offer Letter — <?= e($fullName) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#1a1a1a;background:#f0f2f5;line-height:1.75}
.page{max-width:680px;margin:24px auto;background:#fff;border:1px solid #dde3ec;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)}

.letterhead{padding:28px 32px 0}
.lh-top{display:flex;align-items:center;gap:16px;padding-bottom:14px;border-bottom:1px solid #e5e7eb}
.logo-box{width:68px;height:68px;background:#0B2D4E;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;color:#fff;flex-shrink:0}
.logo-img{width:68px;height:68px;object-fit:contain;border-radius:8px;flex-shrink:0}
.org-name{font-size:20px;font-weight:700;color:#0B2D4E;line-height:1.2}
.org-tagline{font-size:11px;font-style:italic;color:#6b7280;margin-top:2px}
.org-contacts{font-size:11px;color:#6b7280;margin-top:4px}
.lh-right{margin-left:auto;text-align:right;flex-shrink:0;font-size:11.5px;color:#6b7280}
.lh-right .ref{font-weight:700;color:#0B2D4E;font-size:13px}

.rule-band{height:5px;background:linear-gradient(to right,#0B2D4E,#1A8A4E);margin:0 32px}

.subject-block{padding:20px 32px 0}
.subject-line{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#0B2D4E;margin-bottom:6px;padding-bottom:6px;border-bottom:2px solid #1A8A4E;display:inline-block}

.letter-body{padding:16px 32px 28px;font-size:13px}
.salutation{margin-bottom:14px;font-weight:600}
.body-text{margin-bottom:13px;text-align:justify}

.offer-card{background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:16px 20px;margin:18px 0}
.offer-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#166534;margin-bottom:12px}
.offer-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 24px}
.offer-label{font-size:10.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px}
.offer-val{font-weight:600;color:#111;font-size:13px}

.conditions-list{margin:10px 0 10px 18px;color:#374151}
.conditions-list li{margin-bottom:6px}

.sig-section{margin-top:32px;display:flex;justify-content:space-between;gap:32px}
.sig-line{border-top:1px solid #374151;margin-top:40px;padding-top:5px;font-size:11.5px;color:#374151;font-weight:600}
.sig-sublabel{font-size:10.5px;color:#9ca3af;margin-top:2px}
.stamp-circle{display:inline-block;width:80px;height:80px;border:2px dashed #d1d5db;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;color:#d1d5db;text-align:center;line-height:1.3;float:right;margin-top:30px}

.doc-footer{border-top:1px solid #e5e7eb;padding:12px 32px;font-size:10px;color:#9ca3af;text-align:center;line-height:1.6}

.actions{text-align:center;padding:14px;background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:center}
.btn{padding:8px 22px;border-radius:6px;font-size:12.5px;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-block}
.btn-primary{background:#0B2D4E;color:#fff}
.btn-success{background:#1A8A4E;color:#fff}
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
        <?php if ($academicYear): ?><div>Year: <?= e($academicYear) ?></div><?php endif; ?>
        <div>App No: <?= e($app['app_no']) ?></div>
      </div>
    </div>
  </div>
  <div class="rule-band"></div>

  <!-- Addressee -->
  <div class="subject-block">
    <?php if ($app['parent_name']): ?>
    <p style="font-size:13px;margin-bottom:6px">
      <?= e($app['parent_name']) ?><br>
      <?php if($app['parent_email']): ?><?= e($app['parent_email']) ?><br><?php endif; ?>
      <?php if($app['parent_phone']): ?>Tel: <?= e($app['parent_phone']) ?><br><?php endif; ?>
    </p>
    <?php endif; ?>
    <div class="subject-line">OFFER OF ADMISSION — <?= strtoupper(e($app['class_applying'] ?: 'Proposed Class')) ?></div>
  </div>

  <div class="letter-body">
    <p class="salutation">Dear <?= $app['parent_name'] ? e($app['parent_name']) : 'Parent / Guardian' ?>,</p>

    <p class="body-text">
      We are delighted to inform you that following the careful review of
      <strong><?= e($fullName) ?></strong>'s application to
      <strong><?= e($school['name'] ?? 'our school') ?></strong>,
      the Admissions Committee has approved <?= $pronoun ?> for enrollment.
      <?php if ($academicYear): ?>
      This offer is for the <strong><?= e($academicYear) ?></strong> academic year.
      <?php endif; ?>
    </p>

    <!-- Offer Details Card -->
    <div class="offer-card">
      <div class="offer-title">Offer of Admission — Details</div>
      <div class="offer-grid">
        <div>
          <div class="offer-label">Applicant Name</div>
          <div class="offer-val"><?= e($fullName) ?></div>
        </div>
        <div>
          <div class="offer-label">Application No.</div>
          <div class="offer-val"><?= e($app['app_no']) ?></div>
        </div>
        <div>
          <div class="offer-label">Class / Grade Offered</div>
          <div class="offer-val"><?= e($app['class_applying'] ?: '—') ?></div>
        </div>
        <div>
          <div class="offer-label">Curriculum</div>
          <div class="offer-val"><?= e($app['curriculum']) ?></div>
        </div>
        <?php if ($app['dob']): ?>
        <div>
          <div class="offer-label">Date of Birth</div>
          <div class="offer-val"><?= date('d F Y', strtotime($app['dob'])) ?></div>
        </div>
        <?php endif; ?>
        <div>
          <div class="offer-label">Gender</div>
          <div class="offer-val"><?= $gender ?: '—' ?></div>
        </div>
        <?php if ($app['nationality']): ?>
        <div>
          <div class="offer-label">Nationality</div>
          <div class="offer-val"><?= e($app['nationality']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($app['previous_school']): ?>
        <div>
          <div class="offer-label">Previous School</div>
          <div class="offer-val"><?= e($app['previous_school']) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <p class="body-text">
      To secure this offer, please complete the following steps within <strong>14 days</strong>
      of the date of this letter. Failure to do so may result in the place being offered to
      another applicant.
    </p>

    <ul class="conditions-list">
      <li>Sign and return the enclosed acceptance slip</li>
      <li>Pay the non-refundable registration / placement fee</li>
      <li>Submit original birth certificate and previous academic records</li>
      <li>Complete the school's medical disclosure form</li>
      <li>Provide two recent passport-size photographs</li>
      <?php if (!empty($app['notes'])): ?>
      <li><?= e($app['notes']) ?></li>
      <?php endif; ?>
    </ul>

    <p class="body-text">
      We look forward to welcoming <?= e($fullName) ?> to our school community and partnering
      with you to support <?= $pronoun ?> academic and personal development. Please do not
      hesitate to contact the Admissions Office if you have any questions.
    </p>

    <p class="body-text">Yours sincerely,</p>

    <div class="sig-section">
      <div>
        <div class="sig-line">___________________________</div>
        <div class="sig-sublabel">Director of Admissions</div>
        <div class="sig-sublabel"><?= e($school['name']??'') ?></div>
      </div>
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
    Official Offer of Admission issued by <?= e($school['name']??'the school') ?>.
    &bull; Ref: <?= e($refNo) ?> &bull; App: <?= e($app['app_no']) ?> &bull; Generated: <?= date('d M Y, H:i') ?>
  </div>

  <div class="actions">
    <a href="javascript:history.back()" class="btn btn-secondary">← Back</a>
    <button class="btn btn-success" onclick="window.print()">🖨 Print Offer Letter</button>
  </div>

</div>
</body>
</html>
