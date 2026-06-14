<?php
/**
 * Student Portal Login
 * URL: /student/login.php?org=SLUG
 * Login method: admission number + password
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
sendSecurityHeaders();

$slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['org'] ?? '')));
if (!$slug) {
    setFlash('warning', 'No school specified. Please use the link provided by your school.');
    redirect(APP_URL . '/auth/login.php');
}

$orgStmt = $pdo->prepare("SELECT id, name, slug, status, logo, city, country FROM organizations WHERE slug=? LIMIT 1");
$orgStmt->execute([$slug]);
$org = $orgStmt->fetch();
if (!$org) {
    setFlash('warning', 'School portal not found.');
    redirect(APP_URL . '/auth/login.php');
}

$orgActive = $org['status'] === 'active';

if (!empty($_SESSION['stu_id']) && (int)$_SESSION['stu_org_id'] === (int)$org['id']) {
    redirect(APP_URL . '/student/index.php');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$notice = null;
if (!empty($_GET['expired']))  $notice = ['type'=>'warning', 'icon'=>'fa-clock',        'msg'=>'Your session has expired. Please sign in again.'];
elseif (!empty($_GET['logout'])) $notice = ['type'=>'success', 'icon'=>'fa-check-circle', 'msg'=>'You have been signed out successfully.'];

$loginError = null;
$admVal     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $orgActive) {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $loginError = 'Security validation failed. Please refresh and try again.';
    } else {
        $admVal   = strtoupper(trim($_POST['admission_no'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (!$admVal || !$password) {
            $loginError = 'Please enter your admission number and password.';
        } else {
            $student = null;
            try {
                $s = $pdo->prepare(
                    "SELECT s.*, c.name AS class_name
                     FROM sch_students s
                     LEFT JOIN sch_classes c ON c.id = s.class_id
                     WHERE s.admission_no=? AND s.org_id=? AND s.status='active'
                     LIMIT 1"
                );
                $s->execute([$admVal, $org['id']]);
                $student = $s->fetch();
            } catch (Throwable $e) {}

            if (!$student || empty($student['password_hash'])) {
                $loginError = 'Invalid admission number or password. Contact your school if you need access.';
                try { $pdo->prepare("INSERT INTO login_attempts (email,ip,success) VALUES (?,?,0)")->execute([$admVal, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (Throwable $e) {}
            } elseif (!password_verify($password, $student['password_hash'])) {
                $loginError = 'Invalid admission number or password.';
                try { $pdo->prepare("INSERT INTO login_attempts (email,ip,success) VALUES (?,?,0)")->execute([$admVal, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (Throwable $e) {}
            } elseif (empty($student['portal_enabled'])) {
                $loginError = 'Portal access is not enabled for your account. Please contact your school administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['stu_id']           = (int)$student['id'];
                $_SESSION['stu_name']         = trim($student['first_name'] . ' ' . $student['last_name']);
                $_SESSION['stu_org_id']       = (int)$org['id'];
                $_SESSION['stu_org_slug']     = $org['slug'];
                $_SESSION['stu_org_name']     = $org['name'];
                $_SESSION['stu_class_id']     = (int)($student['class_id'] ?? 0);
                $_SESSION['stu_class_name']   = $student['class_name'] ?? '';
                $_SESSION['stu_admission_no'] = $student['admission_no'] ?? '';
                $_SESSION['stu_last_act']     = time();

                try {
                    $pdo->prepare("UPDATE sch_students SET last_login=NOW() WHERE id=?")->execute([$student['id']]);
                } catch (Throwable $e) {}

                setFlash('success', 'Welcome, ' . e($student['first_name']) . '!');
                redirect(APP_URL . '/student/index.php');
            }
        }
    }
}

$initials = strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), array_slice(explode(' ', $org['name']), 0, 2))));
$location = implode(', ', array_filter([$org['city'] ?? null, $org['country'] ?? null]));
$orgLogo  = !empty($org['logo']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Login &mdash; <?= e($org['name']) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --navy:#0B2D4E;--blue:#1d4ed8;--blue-l:#2563eb;
  --gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-300:#cbd5e1;
  --gray-400:#94a3b8;--gray-600:#475569;--gray-800:#1e293b;
}
html,body{height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;}
body{background:var(--gray-50);display:flex;min-height:100vh;}
.login-split{display:flex;width:100%;min-height:100vh;}
.login-left{
  flex:0 0 42%;max-width:42%;
  background:linear-gradient(145deg,#091e35 0%,#0B2D4E 40%,#0d1f6a 100%);
  display:flex;flex-direction:column;justify-content:space-between;
  padding:48px 40px;position:relative;overflow:hidden;
}
.login-left::before{
  content:'';position:absolute;inset:0;
  background:url("data:image/svg+xml,%3Csvg width='80' height='80' viewBox='0 0 80 80' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%231d4ed8' fill-opacity='0.07'%3E%3Cpath d='M50 50c0-5.523 4.477-10 10-10s10 4.477 10 10-4.477 10-10 10c0 5.523-4.477 10-10 10s-10-4.477-10-10 4.477-10 10-10zM10 10c0-5.523 4.477-10 10-10s10 4.477 10 10-4.477 10-10 10c0 5.523-4.477 10-10 10S0 25.523 0 20s4.477-10 10-10z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
}
.login-left-content{position:relative;z-index:1;}
.org-avatar{
  width:80px;height:80px;border-radius:20px;
  background:linear-gradient(135deg,#1d4ed8,#2563eb);
  display:flex;align-items:center;justify-content:center;
  font-size:1.75rem;font-weight:800;color:#fff;margin-bottom:20px;
  box-shadow:0 8px 24px rgba(29,78,216,.4);
}
.org-avatar img{width:100%;height:100%;object-fit:cover;border-radius:20px;}
.portal-badge{
  display:inline-flex;align-items:center;gap:6px;
  background:rgba(29,78,216,.18);border:1px solid rgba(29,78,216,.35);
  color:#93c5fd;border-radius:20px;padding:4px 12px;
  font-size:.72rem;font-weight:600;letter-spacing:.04em;
  text-transform:uppercase;margin-bottom:12px;
}
.left-school-name{font-size:1.8rem;font-weight:800;color:#fff;line-height:1.2;margin-bottom:6px;}
.left-sub{font-size:.85rem;color:rgba(255,255,255,.5);}
.left-loc{display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:.78rem;color:rgba(255,255,255,.4);}
.left-loc i{color:rgba(29,78,216,.7);}
.left-features{position:relative;z-index:1;}
.feat-item{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06);}
.feat-item:last-child{border-bottom:none;}
.feat-icon{width:32px;height:32px;border-radius:8px;background:rgba(29,78,216,.15);color:#93c5fd;display:flex;align-items:center;justify-content:center;font-size:.78rem;}
.feat-text{font-size:.8rem;color:rgba(255,255,255,.55);}
.left-footer{position:relative;z-index:1;display:flex;align-items:center;gap:10px;}
.left-footer-logo{width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;}
.left-footer-text{font-size:.72rem;color:rgba(255,255,255,.35);line-height:1.4;}
.left-footer-text strong{color:rgba(255,255,255,.6);}
.login-right{flex:1;display:flex;align-items:center;justify-content:center;padding:48px 40px;background:#fff;}
.login-form-wrap{width:100%;max-width:420px;}
.form-header{margin-bottom:32px;}
.form-header .welcome-tag{
  display:inline-flex;align-items:center;gap:6px;
  background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;
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
.form-field input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(29,78,216,.12);}
.form-field input::placeholder{color:var(--gray-400);}
.eye-btn{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--gray-400);cursor:pointer;padding:4px;}
.btn-signin{
  width:100%;padding:13px;border:none;border-radius:10px;
  background:linear-gradient(135deg,var(--navy) 0%,var(--blue) 100%);
  color:#fff;font-size:.95rem;font-weight:700;cursor:pointer;
  transition:opacity .2s;box-shadow:0 4px 14px rgba(29,78,216,.3);letter-spacing:.02em;
}
.btn-signin:hover{opacity:.93;}
.login-notice{border-radius:10px;padding:11px 14px;margin-bottom:18px;display:flex;align-items:flex-start;gap:9px;font-size:.84rem;}
.login-error{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d;}
.login-footer{text-align:center;margin-top:28px;font-size:.73rem;color:var(--gray-400);}
.login-footer a{color:var(--blue);text-decoration:none;font-weight:500;}
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
  <div class="login-left">
    <div class="login-left-content">
      <div class="org-avatar">
        <?php if ($orgLogo): ?>
          <img src="<?= APP_URL . '/' . e($org['logo']) ?>" alt="<?= e($org['name']) ?>">
        <?php else: ?>
          <?= e($initials) ?>
        <?php endif; ?>
      </div>
      <span class="portal-badge"><i class="fas fa-user-graduate"></i>Student Portal</span>
      <div class="left-school-name"><?= e($org['name']) ?></div>
      <div class="left-sub">School Management System</div>
      <?php if ($location): ?>
      <div class="left-loc"><i class="fas fa-map-marker-alt"></i><?= e($location) ?></div>
      <?php endif; ?>
    </div>
    <div class="left-features mt-4">
      <?php foreach ([
        ['fa-graduation-cap', 'View your exam results and report cards'],
        ['fa-calendar-week',  'Check your class timetable and schedule'],
        ['fa-book-open',      'See homework assignments and due dates'],
        ['fa-clipboard-check','Track your attendance record'],
      ] as [$ico, $txt]): ?>
      <div class="feat-item">
        <div class="feat-icon"><i class="fas <?= $ico ?>"></i></div>
        <div class="feat-text"><?= $txt ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="left-footer mt-4">
      <div class="left-footer-logo"><i class="fas fa-cubes"></i></div>
      <div class="left-footer-text">Powered by <strong><?= APP_NAME ?></strong><br>&copy; <?= date('Y') ?> All rights reserved</div>
    </div>
  </div>

  <div class="login-right">
    <div class="login-form-wrap">
      <div class="form-header">
        <div class="welcome-tag"><i class="fas fa-school"></i><?= e($org['name']) ?></div>
        <h1>Student Sign In</h1>
        <p>Enter your admission number and portal password to access your student dashboard.</p>
      </div>

      <?php if (!$orgActive): ?>
      <div class="org-suspended">
        <i class="fas fa-pause-circle fa-2x d-block mb-2" style="color:#f97316"></i>
        <strong>Portal Unavailable</strong>
        <p class="mt-2 mb-0 small">This school's portal is currently <?= e($org['status']) ?>.</p>
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

      <form method="POST" autocomplete="on" id="stuLoginForm">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="form-field">
          <label for="adm">Admission Number</label>
          <div class="input-wrap">
            <i class="fas fa-id-card field-icon"></i>
            <input type="text" id="adm" name="admission_no"
                   value="<?= e($admVal) ?>" placeholder="e.g. ADM-00123"
                   autocomplete="username" required autofocus>
          </div>
        </div>
        <div class="form-field">
          <label for="password">Password</label>
          <div class="input-wrap">
            <i class="fas fa-lock field-icon"></i>
            <input type="password" id="password" name="password"
                   placeholder="Your portal password"
                   autocomplete="current-password" required>
            <button type="button" class="eye-btn" onclick="togglePw()" tabindex="-1">
              <i class="fas fa-eye" id="pwEye"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="btn-signin" id="signinBtn">
          <i class="fas fa-sign-in-alt me-2"></i>Sign In to Student Portal
        </button>
      </form>

      <div class="login-footer mt-4">
        <p class="mb-1">Forgot your password? <strong>Contact your class teacher or school administrator</strong>.</p>
        <a href="<?= APP_URL ?>">Back to <?= APP_NAME ?></a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePw() {
  const pw  = document.getElementById('password');
  const ico = document.getElementById('pwEye');
  const show = pw.type === 'password';
  pw.type = show ? 'text' : 'password';
  ico.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
}
document.getElementById('stuLoginForm')?.addEventListener('submit', function() {
  const btn = document.getElementById('signinBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Signing in&hellip;';
});
</script>
</body>
</html>
