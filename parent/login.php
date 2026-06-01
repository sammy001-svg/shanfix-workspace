<?php
/**
 * Parent Portal Login
 * URL: /parent/login.php?org=SLUG
 * Login method: Student admission number + parent PIN
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
sendSecurityHeaders();

// Resolve org by slug
$slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['org'] ?? '')));
if (!$slug) {
    setFlash('warning', 'No school specified. Please use the link provided by your school.');
    redirect(APP_URL . '/auth/login.php');
}

$orgStmt = $pdo->prepare("SELECT id, name, slug, status, logo, city, country FROM organizations WHERE slug=? LIMIT 1");
$orgStmt->execute([$slug]);
$org = $orgStmt->fetch();
if (!$org) {
    setFlash('warning', 'School portal not found. Please contact your school administrator.');
    redirect(APP_URL . '/auth/login.php');
}

$orgActive = $org['status'] === 'active';

// Already logged in as a parent of this org — go to dashboard
if (!empty($_SESSION['par_id']) && (int)$_SESSION['par_org_id'] === (int)$org['id']) {
    redirect(APP_URL . '/parent/index.php');
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Status notices from redirects
$notice = null;
if (!empty($_GET['expired']))  $notice = ['type'=>'warning', 'icon'=>'fa-clock',        'msg'=>'Your session has expired. Please sign in again.'];
elseif (!empty($_GET['logout'])) $notice = ['type'=>'success', 'icon'=>'fa-check-circle', 'msg'=>'You have been signed out successfully.'];

$loginError = null;
$admNo      = '';

// ── POST: process login ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $orgActive) {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $loginError = 'Security validation failed. Please refresh and try again.';
    } else {
        $admNo    = trim($_POST['admission_no'] ?? '');
        $pin      = $_POST['pin'] ?? '';

        if (!$admNo || !$pin) {
            $loginError = 'Please enter the student admission number and your PIN.';
        } elseif (strlen($pin) < 4 || strlen($pin) > 8 || !ctype_digit($pin)) {
            $loginError = 'PIN must be 4 – 8 digits.';
        } else {
            // Look up student by admission number in this org
            try {
                $stmtStu = $pdo->prepare(
                    "SELECT id, first_name, last_name, admission_no, class_id
                     FROM sch_students WHERE admission_no=? AND org_id=? LIMIT 1"
                );
                $stmtStu->execute([$admNo, $org['id']]);
                $student = $stmtStu->fetch();
            } catch (Throwable $e) { $student = null; }

            if (!$student) {
                $loginError = 'No student found with that admission number in this school.';
            } else {
                // Find all parents linked to this student who have portal access
                $matchedParent = null;
                try {
                    $stmtPar = $pdo->prepare(
                        "SELECT p.* FROM sch_parents p
                         JOIN sch_student_parents sp ON sp.parent_id = p.id
                         WHERE sp.student_id = ? AND p.portal_enabled = 1
                           AND p.parent_pin IS NOT NULL AND p.status = 'active'"
                    );
                    $stmtPar->execute([$student['id']]);
                    $parents = $stmtPar->fetchAll();
                } catch (Throwable $e) { $parents = []; }

                foreach ($parents as $par) {
                    if (password_verify($pin, $par['parent_pin'])) {
                        $matchedParent = $par;
                        break;
                    }
                }

                if (!$matchedParent) {
                    $loginError = 'Incorrect PIN. Please contact your school administrator if you need help.';
                    // Simple rate limiting attempt log
                    try { $pdo->prepare("INSERT INTO login_attempts (email,ip,success) VALUES (?,?,0)")->execute([$admNo, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (Throwable $e) {}
                } else {
                    // Load all students linked to this parent for the student switcher
                    $linkedSids = [];
                    try {
                        $lnk = $pdo->prepare("SELECT student_id FROM sch_student_parents WHERE parent_id=?");
                        $lnk->execute([$matchedParent['id']]);
                        $linkedSids = array_column($lnk->fetchAll(), 'student_id');
                        $linkedSids = array_map('intval', $linkedSids);
                    } catch (Throwable $e) {
                        $linkedSids = [(int)$student['id']];
                    }

                    session_regenerate_id(true);
                    $_SESSION['par_id']       = (int)$matchedParent['id'];
                    $_SESSION['par_name']     = trim($matchedParent['first_name'] . ' ' . $matchedParent['last_name']);
                    $_SESSION['par_org_id']   = (int)$org['id'];
                    $_SESSION['par_org_slug'] = $org['slug'];
                    $_SESSION['par_org_name'] = $org['name'];
                    $_SESSION['par_sids']     = $linkedSids;
                    $_SESSION['par_active']   = (int)$student['id'];  // start with the looked-up child
                    $_SESSION['par_last_act'] = time();

                    setFlash('success', 'Welcome, ' . e($matchedParent['first_name']) . '!');
                    redirect(APP_URL . '/parent/index.php');
                }
            }
        }
    }
}

// ── Branding ───────────────────────────────────────────────────
$initials = strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), array_slice(explode(' ', $org['name']), 0, 2))));
$location = implode(', ', array_filter([$org['city'] ?? null, $org['country'] ?? null]));
$orgLogo  = !empty($org['logo']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Parent Login — <?= e($org['name']) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --navy:#0B2D4E;--green:#1A8A4E;--green-l:#22a860;
  --gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-300:#cbd5e1;
  --gray-400:#94a3b8;--gray-600:#475569;--gray-800:#1e293b;
  --red:#ef4444;
}
html,body{height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;}
body{background:var(--gray-50);display:flex;min-height:100vh;}

.login-split{display:flex;width:100%;min-height:100vh;}

/* Left */
.login-left{
  flex:0 0 42%;max-width:42%;
  background:linear-gradient(145deg,#091e35 0%,#0B2D4E 40%,#0d3d2a 100%);
  display:flex;flex-direction:column;justify-content:space-between;
  padding:48px 40px;position:relative;overflow:hidden;
}
.login-left::before{
  content:'';position:absolute;inset:0;
  background:url("data:image/svg+xml,%3Csvg width='80' height='80' viewBox='0 0 80 80' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%231A8A4E' fill-opacity='0.06'%3E%3Cpath d='M50 50c0-5.523 4.477-10 10-10s10 4.477 10 10-4.477 10-10 10c0 5.523-4.477 10-10 10s-10-4.477-10-10 4.477-10 10-10zM10 10c0-5.523 4.477-10 10-10s10 4.477 10 10-4.477 10-10 10c0 5.523-4.477 10-10 10S0 25.523 0 20s4.477-10 10-10z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
}
.login-left-content{position:relative;z-index:1;}
.org-avatar{
  width:80px;height:80px;border-radius:20px;
  background:linear-gradient(135deg,#1A8A4E,#22a860);
  display:flex;align-items:center;justify-content:center;
  font-size:1.75rem;font-weight:800;color:#fff;margin-bottom:20px;
  box-shadow:0 8px 24px rgba(26,138,78,.4);
}
.org-avatar img{width:100%;height:100%;object-fit:cover;border-radius:20px;}
.portal-badge{
  display:inline-flex;align-items:center;gap:6px;
  background:rgba(26,138,78,.18);border:1px solid rgba(26,138,78,.35);
  color:#4ade80;border-radius:20px;padding:4px 12px;
  font-size:.72rem;font-weight:600;letter-spacing:.04em;
  text-transform:uppercase;margin-bottom:12px;
}
.left-school-name{font-size:1.8rem;font-weight:800;color:#fff;line-height:1.2;margin-bottom:6px;}
.left-sub{font-size:.85rem;color:rgba(255,255,255,.5);}
.left-loc{
  display:inline-flex;align-items:center;gap:6px;margin-top:6px;
  font-size:.78rem;color:rgba(255,255,255,.4);
}
.left-loc i{color:rgba(26,138,78,.7);}
.left-features{position:relative;z-index:1;}
.feat-item{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06);}
.feat-item:last-child{border-bottom:none;}
.feat-icon{width:32px;height:32px;border-radius:8px;background:rgba(26,138,78,.15);color:#4ade80;display:flex;align-items:center;justify-content:center;font-size:.78rem;}
.feat-text{font-size:.8rem;color:rgba(255,255,255,.55);}
.left-footer{position:relative;z-index:1;display:flex;align-items:center;gap:10px;}
.left-footer-logo{width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;}
.left-footer-text{font-size:.72rem;color:rgba(255,255,255,.35);line-height:1.4;}
.left-footer-text strong{color:rgba(255,255,255,.6);}

/* Right */
.login-right{flex:1;display:flex;align-items:center;justify-content:center;padding:48px 40px;background:#fff;}
.login-form-wrap{width:100%;max-width:420px;}
.form-header{margin-bottom:32px;}
.form-header .welcome-tag{
  display:inline-flex;align-items:center;gap:6px;
  background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;
  border-radius:20px;padding:4px 12px;font-size:.72rem;font-weight:600;margin-bottom:14px;
}
.form-header h1{font-size:1.65rem;font-weight:800;color:var(--navy);margin-bottom:6px;}
.form-header p{font-size:.88rem;color:var(--gray-600);}

.form-field{margin-bottom:18px;}
.form-field label{display:block;font-size:.85rem;font-weight:600;color:var(--gray-800);margin-bottom:6px;}
.input-wrap{position:relative;}
.field-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:.82rem;pointer-events:none;}
.form-field input{
  width:100%;padding:11px 14px 11px 38px;
  border:1.5px solid var(--gray-300);border-radius:10px;
  font-size:.9rem;color:var(--navy);background:#fff;transition:border-color .2s,box-shadow .2s;outline:none;
}
.form-field input:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(26,138,78,.12);}
.form-field input::placeholder{color:var(--gray-400);}
.eye-btn{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--gray-400);cursor:pointer;padding:4px;}

.btn-signin{
  width:100%;padding:13px;border:none;border-radius:10px;
  background:linear-gradient(135deg,var(--navy) 0%,var(--green) 100%);
  color:#fff;font-size:.95rem;font-weight:700;cursor:pointer;
  transition:opacity .2s;box-shadow:0 4px 14px rgba(11,45,78,.25);letter-spacing:.02em;
}
.btn-signin:hover{opacity:.93;}

.login-notice{
  border-radius:10px;padding:11px 14px;margin-bottom:18px;
  display:flex;align-items:flex-start;gap:9px;font-size:.84rem;
}
.login-error{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d;}
.login-footer{text-align:center;margin-top:28px;font-size:.73rem;color:var(--gray-400);}
.login-footer a{color:var(--green);text-decoration:none;font-weight:500;}

.org-suspended{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:20px;text-align:center;color:#7c2d12;}

@media(max-width:768px){
  .login-split{flex-direction:column;}
  .login-left{flex:none;max-width:100%;padding:28px 20px;min-height:auto;}
  .login-right{padding:28px 20px;}
  .login-left-content .left-features,.left-footer{display:none;}
}
</style>
</head>
<body>
<div class="login-split">

  <!-- Left branding panel -->
  <div class="login-left">
    <div class="login-left-content">
      <div class="org-avatar">
        <?php if ($orgLogo): ?>
          <img src="<?= APP_URL . '/' . e($org['logo']) ?>" alt="<?= e($org['name']) ?>">
        <?php else: ?>
          <?= e($initials) ?>
        <?php endif; ?>
      </div>
      <span class="portal-badge"><i class="fas fa-users-class"></i>Parent Portal</span>
      <div class="left-school-name"><?= e($org['name']) ?></div>
      <div class="left-sub">School Management System</div>
      <?php if ($location): ?>
      <div class="left-loc"><i class="fas fa-map-marker-alt"></i><?= e($location) ?></div>
      <?php endif; ?>
    </div>

    <div class="left-features mt-4">
      <?php foreach ([
        ['fa-graduation-cap', 'View your child\'s exam results & grades'],
        ['fa-receipt',        'Check fee balances and payment history'],
        ['fa-clipboard-check','Monitor attendance records'],
        ['fa-bullhorn',       'Read school notices and announcements'],
      ] as [$ico, $txt]): ?>
      <div class="feat-item">
        <div class="feat-icon"><i class="fas <?= $ico ?>"></i></div>
        <div class="feat-text"><?= $txt ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="left-footer mt-4">
      <div class="left-footer-logo"><i class="fas fa-cubes"></i></div>
      <div class="left-footer-text">Powered by <strong><?= APP_NAME ?></strong><br>
        &copy; <?= date('Y') ?> All rights reserved</div>
    </div>
  </div>

  <!-- Right login form -->
  <div class="login-right">
    <div class="login-form-wrap">
      <div class="form-header">
        <div class="welcome-tag"><i class="fas fa-school"></i><?= e($org['name']) ?></div>
        <h1>Parent Sign In</h1>
        <p>Enter your child's admission number and your portal PIN to access the parent dashboard.</p>
      </div>

      <?php if (!$orgActive): ?>
      <div class="org-suspended">
        <i class="fas fa-pause-circle fa-2x d-block mb-2" style="color:#f97316"></i>
        <strong>Portal Unavailable</strong>
        <p class="mt-2 mb-0 small">This school's portal is currently <?= e($org['status']) ?>. Please contact the school administration.</p>
      </div>
      <?php else: ?>

      <?php if ($notice): ?>
      <div style="background:<?= $notice['type']==='success'?'#f0fdf4':($notice['type']==='warning'?'#fffbeb':'#eff6ff') ?>;
                  border:1px solid <?= $notice['type']==='success'?'#bbf7d0':($notice['type']==='warning'?'#fde68a':'#bfdbfe') ?>;
                  border-radius:10px;padding:11px 14px;margin-bottom:18px;display:flex;align-items:flex-start;gap:9px;font-size:.84rem;
                  color:<?= $notice['type']==='success'?'#166534':($notice['type']==='warning'?'#92400e':'#1e40af') ?>">
        <i class="fas <?= e($notice['icon']) ?>" style="margin-top:1px;flex-shrink:0"></i>
        <span><?= e($notice['msg']) ?></span>
      </div>
      <?php endif; ?>

      <?php if ($loginError): ?>
      <div class="login-notice login-error">
        <i class="fas fa-exclamation-circle flex-shrink-0" style="margin-top:1px"></i>
        <span><?= e($loginError) ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" autocomplete="on" id="parLoginForm">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <div class="form-field">
          <label for="adm">Student Admission Number</label>
          <div class="input-wrap">
            <i class="fas fa-id-badge field-icon"></i>
            <input type="text" id="adm" name="admission_no"
                   value="<?= e($admNo) ?>"
                   placeholder="e.g. ADM-00123"
                   autocomplete="username" required autofocus>
          </div>
        </div>

        <div class="form-field">
          <label for="pin">Your Portal PIN</label>
          <div class="input-wrap">
            <i class="fas fa-lock field-icon"></i>
            <input type="password" id="pin" name="pin"
                   placeholder="4 – 8 digit PIN"
                   autocomplete="current-password"
                   inputmode="numeric" pattern="[0-9]{4,8}"
                   maxlength="8" required>
            <button type="button" class="eye-btn" onclick="togglePin()" tabindex="-1">
              <i class="fas fa-eye" id="pinEye"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-signin" id="signinBtn">
          <i class="fas fa-sign-in-alt me-2"></i>Sign In to Parent Portal
        </button>
      </form>

      <div class="login-footer mt-4">
        <p class="mb-1">Don't have a PIN? <strong>Contact your school administrator</strong> to enable portal access.</p>
        <a href="<?= APP_URL ?>">Back to <?= APP_NAME ?></a>
        &nbsp;&middot;&nbsp;
        <a href="mailto:<?= APP_SUPPORT_EMAIL ?>">Support</a>
      </div>

      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePin() {
  const pw  = document.getElementById('pin');
  const ico = document.getElementById('pinEye');
  const show = pw.type === 'password';
  pw.type   = show ? 'text' : 'password';
  ico.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
}
document.getElementById('parLoginForm')?.addEventListener('submit', function() {
  const btn = document.getElementById('signinBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Signing in…';
});
</script>
</body>
</html>
