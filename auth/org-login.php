<?php
/**
 * Branded organization login portal
 * URL: /auth/org-login.php?org=SLUG
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// ── Resolve org by slug ────────────────────────────────────────────
$slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['org'] ?? '')));
if (!$slug) { redirect(APP_URL . '/auth/login.php'); }

$orgStmt = $pdo->prepare("SELECT * FROM organizations WHERE slug = ? LIMIT 1");
$orgStmt->execute([$slug]);
$org = $orgStmt->fetch();

if (!$org) {
    setFlash('warning', 'Organization portal not found. Please use the main login.');
    redirect(APP_URL . '/auth/login.php');
}

// Already logged into the right org — skip to dashboard
if (!empty($_SESSION['user_id'])) {
    $sessionOrgId = (int)($_SESSION['org_id'] ?? 0);
    $sessionRole  = $_SESSION['user_role'] ?? '';
    if ($sessionOrgId === (int)$org['id'] || $sessionRole === 'super_admin') {
        redirect($sessionRole === 'super_admin' ? APP_URL . '/admin/index.php' : APP_URL . '/client/index.php');
    }
}

// ── CSRF token ─────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$loginError  = null;
$loginEmail  = '';
$orgActive   = $org['status'] === 'active';

// ── POST: process login ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $orgActive) {
    // CSRF
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $loginError = 'Security validation failed. Please refresh and try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $loginEmail = htmlspecialchars($email, ENT_QUOTES);

        // Rate limiting
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $rl = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email=? AND ip_address=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $rl->execute([$email, $ip]);
            if ((int)$rl->fetchColumn() >= 5) {
                $loginError = 'Too many failed attempts. Please wait 15 minutes before trying again.';
            }
        } catch (Exception $e) {}

        if (!$loginError) {
            if (!$email || !$password) {
                $loginError = 'Please enter your email address and password.';
            } else {
                $stmt = $pdo->prepare("
                    SELECT u.*, o.name AS org_name, o.status AS org_status
                    FROM users u
                    LEFT JOIN organizations o ON u.org_id = o.id
                    WHERE u.email = ? LIMIT 1
                ");
                $stmt->execute([$email]);
                $u = $stmt->fetch();

                // Record failed attempt helper
                $recordAttempt = function() use ($pdo, $email, $ip) {
                    try { $pdo->prepare("INSERT INTO login_attempts (email,ip_address,attempted_at) VALUES (?,?,NOW())")->execute([$email, $ip]); } catch (Exception $e) {}
                };

                if (!$u || !password_verify($password, $u['password'])) {
                    $loginError = 'The email or password you entered is incorrect.';
                    $recordAttempt();
                } elseif ((int)$u['org_id'] !== (int)$org['id'] && $u['role'] !== 'super_admin') {
                    $loginError = 'This account does not belong to the <strong>' . htmlspecialchars($org['name'], ENT_QUOTES) . '</strong> workspace.';
                    $recordAttempt();
                } elseif (!empty($u['locked_until']) && strtotime($u['locked_until']) > time()) {
                    $loginError = 'Your account has been temporarily locked. Please contact your administrator.';
                } elseif ($u['status'] !== 'active') {
                    $loginError = 'Your account is not active. Please contact your administrator.';
                } else {
                    // 2FA check
                    if (!empty($u['totp_enabled']) && !empty($u['totp_secret'])) {
                        $_SESSION['2fa_pending_uid']  = $u['id'];
                        $_SESSION['2fa_pending_time'] = time();
                        redirect(APP_URL . '/auth/2fa-verify.php');
                    }

                    // Complete login
                    session_regenerate_id(true);
                    $_SESSION['user_id']       = $u['id'];
                    $_SESSION['user_name']     = $u['name'];
                    $_SESSION['user_email']    = $u['email'];
                    $_SESSION['user_role']     = $u['role'];
                    $_SESSION['org_id']        = $u['org_id'];
                    $_SESSION['org_name']      = $u['org_name'];
                    $_SESSION['last_activity'] = time();
                    $_SESSION['fingerprint']   = md5(($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));

                    try { $pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$u['id']]); } catch (Exception $e) {}
                    try { $pdo->prepare("DELETE FROM login_attempts WHERE email=?")->execute([$email]); } catch (Exception $e) {}

                    redirect($u['role'] === 'super_admin' ? APP_URL . '/admin/index.php' : APP_URL . '/client/index.php');
                }
            }
        }
    }
}

// ── Branding helpers ───────────────────────────────────────────────
$nameWords = array_filter(explode(' ', $org['name']));
$initials  = strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), array_slice($nameWords, 0, 2))));
if (!$initials) $initials = strtoupper(substr($org['name'], 0, 2));
$location = implode(', ', array_filter([$org['city'] ?? null, $org['country'] ?? null]));
$orgHasLogo = !empty($org['logo']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — <?= htmlspecialchars($org['name'], ENT_QUOTES) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --navy:#0B2D4E;
  --green:#1A8A4E;
  --green-light:#22a860;
  --gray-50:#f8fafc;
  --gray-100:#f1f5f9;
  --gray-300:#cbd5e1;
  --gray-400:#94a3b8;
  --gray-600:#475569;
  --gray-800:#1e293b;
  --red:#ef4444;
  --shadow-md:0 4px 16px rgba(0,0,0,.10);
  --shadow-lg:0 8px 32px rgba(0,0,0,.14);
}
html,body{height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;}
body{background:var(--gray-50);display:flex;align-items:stretch;min-height:100vh;}

/* ── Layout ──────────────────────────────────────────────────────── */
.login-split{display:flex;width:100%;min-height:100vh;}

/* Left panel */
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
.org-avatar-wrap{margin-bottom:32px;}
.org-avatar{
  width:90px;height:90px;border-radius:22px;
  background:linear-gradient(135deg,#1A8A4E,#22a860);
  display:flex;align-items:center;justify-content:center;
  font-size:2rem;font-weight:800;color:#fff;
  box-shadow:0 8px 24px rgba(26,138,78,.4);
  letter-spacing:-1px;margin-bottom:24px;
}
.org-avatar img{width:100%;height:100%;object-fit:cover;border-radius:22px;}
.left-portal-badge{
  display:inline-flex;align-items:center;gap:6px;
  background:rgba(26,138,78,.18);border:1px solid rgba(26,138,78,.35);
  color:#4ade80;border-radius:20px;padding:4px 12px;
  font-size:.72rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;
  margin-bottom:14px;
}
.left-org-name{font-size:2rem;font-weight:800;color:#fff;line-height:1.2;margin-bottom:8px;}
.left-org-sub{font-size:.9rem;color:rgba(255,255,255,.55);margin-bottom:6px;}
.left-org-loc{
  display:inline-flex;align-items:center;gap:6px;
  font-size:.8rem;color:rgba(255,255,255,.45);margin-top:4px;
}
.left-org-loc i{color:rgba(26,138,78,.8);}

.login-left-features{position:relative;z-index:1;margin-top:40px;}
.feature-item{
  display:flex;align-items:center;gap:12px;
  padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);
}
.feature-item:last-child{border-bottom:none;}
.feature-icon{
  width:34px;height:34px;border-radius:9px;
  background:rgba(26,138,78,.15);color:#4ade80;
  display:flex;align-items:center;justify-content:center;
  font-size:.8rem;flex-shrink:0;
}
.feature-text{font-size:.82rem;color:rgba(255,255,255,.6);}

.login-left-footer{
  position:relative;z-index:1;
  display:flex;align-items:center;gap:10px;
}
.left-footer-logo{
  width:32px;height:32px;border-radius:8px;
  background:rgba(255,255,255,.1);
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-size:.9rem;
}
.left-footer-text{font-size:.75rem;color:rgba(255,255,255,.4);line-height:1.4;}
.left-footer-text strong{color:rgba(255,255,255,.65);}

/* Right panel */
.login-right{
  flex:1;display:flex;align-items:center;justify-content:center;
  padding:48px 40px;background:#fff;
}
.login-form-wrap{width:100%;max-width:420px;}

.login-form-header{margin-bottom:36px;}
.login-form-header .welcome-tag{
  display:inline-flex;align-items:center;gap:6px;
  background:#f0fdf4;border:1px solid #bbf7d0;
  color:#166534;border-radius:20px;padding:4px 12px;
  font-size:.72rem;font-weight:600;margin-bottom:16px;
}
.login-form-header h1{font-size:1.75rem;font-weight:800;color:var(--navy);line-height:1.2;margin-bottom:8px;}
.login-form-header p{font-size:.9rem;color:var(--gray-600);}

.form-field{margin-bottom:20px;}
.form-field label{display:block;font-size:.85rem;font-weight:600;color:var(--gray-800);margin-bottom:7px;}
.form-field .input-wrap{position:relative;}
.form-field .field-icon{
  position:absolute;left:14px;top:50%;transform:translateY(-50%);
  color:var(--gray-400);font-size:.85rem;pointer-events:none;
}
.form-field input{
  width:100%;padding:11px 14px 11px 40px;
  border:1.5px solid var(--gray-300);border-radius:10px;
  font-size:.9rem;color:var(--navy);background:#fff;
  transition:border-color .2s,box-shadow .2s;outline:none;
}
.form-field input:focus{
  border-color:var(--green);
  box-shadow:0 0 0 3px rgba(26,138,78,.12);
}
.form-field input::placeholder{color:var(--gray-400);}
.field-eye{
  position:absolute;right:12px;top:50%;transform:translateY(-50%);
  background:none;border:none;color:var(--gray-400);cursor:pointer;
  font-size:.85rem;padding:4px;
}
.field-eye:hover{color:var(--navy);}

.form-row-inline{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;font-size:.83rem;}
.form-check-label{color:var(--gray-600);}
.form-check-input:checked{background-color:var(--green);border-color:var(--green);}
.forgot-link{color:var(--green);text-decoration:none;font-weight:500;}
.forgot-link:hover{color:#146038;text-decoration:underline;}

.btn-signin{
  width:100%;padding:13px;border:none;border-radius:10px;
  background:linear-gradient(135deg,var(--navy) 0%,var(--green) 100%);
  color:#fff;font-size:.95rem;font-weight:700;cursor:pointer;
  transition:opacity .2s,transform .1s,box-shadow .2s;
  box-shadow:0 4px 14px rgba(11,45,78,.25);letter-spacing:.02em;
}
.btn-signin:hover{opacity:.93;box-shadow:0 6px 20px rgba(11,45,78,.3);}
.btn-signin:active{transform:scale(.99);}
.btn-signin .spinner-border{display:none;}

.login-divider{
  display:flex;align-items:center;gap:12px;
  color:var(--gray-400);font-size:.78rem;margin:24px 0;
}
.login-divider::before,.login-divider::after{content:'';flex:1;height:1px;background:var(--gray-300);}

.back-link{
  display:inline-flex;align-items:center;gap:8px;
  color:var(--gray-600);text-decoration:none;font-size:.85rem;font-weight:500;
  border:1.5px solid var(--gray-300);border-radius:10px;
  padding:9px 18px;transition:all .2s;width:100%;justify-content:center;
}
.back-link:hover{border-color:var(--navy);color:var(--navy);background:var(--gray-50);}

.login-footer{text-align:center;margin-top:32px;font-size:.75rem;color:var(--gray-400);}
.login-footer a{color:var(--green);text-decoration:none;font-weight:500;}

/* Error alert */
.login-error{
  background:#fef2f2;border:1px solid #fecaca;border-radius:10px;
  padding:12px 16px;margin-bottom:20px;
  display:flex;align-items:flex-start;gap:10px;font-size:.85rem;color:#7f1d1d;
}
.login-error i{color:var(--red);flex-shrink:0;margin-top:1px;}

/* Suspended state */
.org-suspended-notice{
  background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;
  padding:20px;text-align:center;margin-bottom:24px;color:#7c2d12;
}
.org-suspended-notice i{font-size:2rem;margin-bottom:12px;color:#f97316;display:block;}

/* Responsive */
@media(max-width:768px){
  .login-split{flex-direction:column;}
  .login-left{flex:none;max-width:100%;padding:32px 24px;min-height:auto;}
  .login-right{padding:32px 24px;}
  .left-org-name{font-size:1.5rem;}
  .login-left-features,.login-left-footer{display:none;}
}
</style>
</head>
<body>

<div class="login-split">

  <!-- ── Left branding panel ──────────────────────────────────────── -->
  <div class="login-left">
    <div class="login-left-content">
      <div class="org-avatar-wrap">
        <div class="org-avatar">
          <?php if ($orgHasLogo): ?>
            <img src="<?= APP_URL . '/' . htmlspecialchars($org['logo'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars($org['name'], ENT_QUOTES) ?>">
          <?php else: ?>
            <?= htmlspecialchars($initials, ENT_QUOTES) ?>
          <?php endif; ?>
        </div>
        <span class="left-portal-badge"><i class="fas fa-shield-alt"></i>Secure Login Portal</span>
        <div class="left-org-name"><?= htmlspecialchars($org['name'], ENT_QUOTES) ?></div>
        <div class="left-org-sub">Business Management Workspace</div>
        <?php if ($location): ?>
        <div class="left-org-loc"><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($location, ENT_QUOTES) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="login-left-features">
      <div class="feature-item">
        <div class="feature-icon"><i class="fas fa-lock"></i></div>
        <div class="feature-text">256-bit encrypted connection</div>
      </div>
      <div class="feature-item">
        <div class="feature-icon"><i class="fas fa-users"></i></div>
        <div class="feature-text">Multi-user team workspace</div>
      </div>
      <div class="feature-item">
        <div class="feature-icon"><i class="fas fa-th"></i></div>
        <div class="feature-text">Access all your business modules</div>
      </div>
    </div>

    <div class="login-left-footer">
      <div class="left-footer-logo"><i class="fas fa-cubes"></i></div>
      <div class="left-footer-text">
        Powered by <strong><?= APP_NAME ?></strong><br>
        v<?= APP_VERSION ?> &mdash; All rights reserved
      </div>
    </div>
  </div>

  <!-- ── Right login form ─────────────────────────────────────────── -->
  <div class="login-right">
    <div class="login-form-wrap">

      <div class="login-form-header">
        <div class="welcome-tag"><i class="fas fa-building"></i><?= htmlspecialchars($org['name'], ENT_QUOTES) ?></div>
        <h1>Welcome back</h1>
        <p>Sign in to your workspace to access your modules and manage your business.</p>
      </div>

      <?php if (!$orgActive): ?>
      <!-- Org suspended/inactive notice -->
      <div class="org-suspended-notice">
        <i class="fas fa-pause-circle"></i>
        <strong>Portal Unavailable</strong>
        <p class="mt-2 mb-0 small">This organization's workspace is currently <?= htmlspecialchars($org['status'], ENT_QUOTES) ?>. Please contact your administrator or <a href="mailto:<?= APP_SUPPORT_EMAIL ?? 'support@orbitdesk.co' ?>" style="color:inherit;font-weight:600">our support team</a> for assistance.</p>
      </div>
      <?php else: ?>

      <?php if ($loginError): ?>
      <div class="login-error" role="alert">
        <i class="fas fa-exclamation-circle"></i>
        <div><?= $loginError ?></div>
      </div>
      <?php endif; ?>

      <form method="POST" id="loginForm" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">

        <div class="form-field">
          <label for="email">Email Address</label>
          <div class="input-wrap">
            <i class="fas fa-envelope field-icon"></i>
            <input type="email" id="email" name="email"
                   value="<?= $loginEmail ?>"
                   placeholder="you@company.com"
                   autocomplete="email" required autofocus>
          </div>
        </div>

        <div class="form-field">
          <label for="password">Password</label>
          <div class="input-wrap">
            <i class="fas fa-lock field-icon"></i>
            <input type="password" id="password" name="password"
                   placeholder="Enter your password"
                   autocomplete="current-password" required>
            <button type="button" class="field-eye" id="eyeBtn" onclick="togglePassword()" tabindex="-1" aria-label="Toggle password visibility">
              <i class="fas fa-eye" id="eyeIcon"></i>
            </button>
          </div>
        </div>

        <div class="form-row-inline">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
            <label class="form-check-label" for="remember">Remember me</label>
          </div>
          <a href="<?= APP_URL ?>/auth/forgot-password.php" class="forgot-link">Forgot password?</a>
        </div>

        <button type="submit" class="btn-signin" id="signinBtn">
          <span class="spinner-border spinner-border-sm me-2" id="btnSpinner"></span>
          <i class="fas fa-sign-in-alt me-2" id="btnIcon"></i>Sign In to Workspace
        </button>
      </form>

      <div class="login-divider">or</div>

      <a href="<?= APP_URL ?>/auth/login.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to main login
      </a>

      <?php endif; ?>

      <div class="login-footer">
        &copy; <?= date('Y') ?> <?= APP_NAME ?>. &mdash;
        <a href="<?= APP_URL ?>">Platform Home</a> &middot;
        <a href="mailto:<?= APP_SUPPORT_EMAIL ?? 'support@orbitdesk.co' ?>">Support</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePassword() {
  const pw  = document.getElementById('password');
  const ico = document.getElementById('eyeIcon');
  const show = pw.type === 'password';
  pw.type   = show ? 'text' : 'password';
  ico.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
}

document.getElementById('loginForm').addEventListener('submit', function() {
  const btn = document.getElementById('signinBtn');
  btn.disabled = true;
  document.getElementById('btnSpinner').style.display = 'inline-block';
  document.getElementById('btnIcon').style.display    = 'none';
  btn.querySelector('span.spinner-border').previousSibling;
});
</script>
</body>
</html>
