<?php
/**
 * Doctor Portal Login
 * URL: /doctor/login.php?org=SLUG
 * Auth: health_doctors.email + linked users.password
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
sendSecurityHeaders();

// Resolve org by slug
$slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['org'] ?? '')));
if (!$slug) {
    setFlash('warning', 'No clinic specified. Please use the link provided by your clinic administrator.');
    redirect(APP_URL . '/auth/login.php');
}

$orgStmt = $pdo->prepare("SELECT id, name, slug, status, logo, city, country FROM organizations WHERE slug=? LIMIT 1");
$orgStmt->execute([$slug]);
$org = $orgStmt->fetch();
if (!$org) {
    setFlash('warning', 'Clinic portal not found. Please contact your administrator.');
    redirect(APP_URL . '/auth/login.php');
}

$orgActive = $org['status'] === 'active';

// Already logged in as doctor of this org
if (!empty($_SESSION['doc_id']) && (int)$_SESSION['doc_org_id'] === (int)$org['id']) {
    redirect(APP_URL . '/doctor/index.php');
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$notice = null;
if (!empty($_GET['expired'])) $notice = ['type'=>'warning', 'icon'=>'fa-clock',        'msg'=>'Your session has expired. Please sign in again.'];
if (!empty($_GET['logout']))  $notice = ['type'=>'success', 'icon'=>'fa-check-circle', 'msg'=>'You have been signed out successfully.'];

$loginError = null;
$emailVal   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $orgActive) {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $loginError = 'Security validation failed. Please refresh and try again.';
    } else {
        $emailVal = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (!$emailVal || !$password) {
            $loginError = 'Please enter your email address and password.';
        } elseif (!filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
            $loginError = 'Please enter a valid email address.';
        } else {
            $doctor = null;
            $userRow = null;
            try {
                // Find doctor record; join users for password
                $s = $pdo->prepare(
                    "SELECT d.*, u.password AS user_password, u.status AS user_status
                     FROM health_doctors d
                     LEFT JOIN users u ON u.id = d.user_id
                     WHERE d.email=? AND d.org_id=? AND d.status='active'
                     LIMIT 1"
                );
                $s->execute([$emailVal, $org['id']]);
                $doctor = $s->fetch();
            } catch (Throwable $e) {}

            if (!$doctor || empty($doctor['user_id'])) {
                $loginError = 'Invalid email or password. Contact your administrator if you need portal access.';
                try { $pdo->prepare("INSERT INTO login_attempts (email,ip,success) VALUES (?,?,0)")->execute([$emailVal, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (Throwable $e) {}
            } elseif (($doctor['user_status'] ?? '') !== 'active') {
                $loginError = 'Your account has been deactivated. Please contact the clinic administrator.';
            } elseif (!password_verify($password, $doctor['user_password'] ?? '')) {
                $loginError = 'Invalid email or password.';
                try { $pdo->prepare("INSERT INTO login_attempts (email,ip,success) VALUES (?,?,0)")->execute([$emailVal, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (Throwable $e) {}
            } else {
                session_regenerate_id(true);
                $_SESSION['doc_id']        = (int)$doctor['id'];
                $_SESSION['doc_org_id']    = (int)$org['id'];
                $_SESSION['doc_org_slug']  = $org['slug'];
                $_SESSION['doc_org_name']  = $org['name'];
                $_SESSION['doc_name']      = 'Dr. ' . trim($doctor['first_name'] . ' ' . $doctor['last_name']);
                $_SESSION['doc_specialty'] = $doctor['specialization'] ?? '';
                $_SESSION['doc_last_act']  = time();

                try { $pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$doctor['user_id']]); } catch (Throwable $e) {}

                setFlash('success', 'Welcome back, Dr. ' . e($doctor['first_name']) . '!');
                redirect(APP_URL . '/doctor/index.php');
            }
        }
    }
}

$initials = strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), array_slice(explode(' ', $org['name']), 0, 2))));
$location = implode(', ', array_filter([$org['city'] ?? null, $org['country'] ?? null]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Doctor Login &mdash; <?= e($org['name']) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--doc-blue:#1a4e7c;--doc-dark:#12375a;--doc-pale:#e8f0f8;--gray-50:#f8fafc;--gray-100:#f1f5f9;}
html,body{height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;}
body{background:var(--gray-50);display:flex;min-height:100vh;}

.login-split{display:flex;width:100%;min-height:100vh;}

.login-left{
  flex:0 0 42%;max-width:42%;
  background:linear-gradient(145deg, var(--doc-dark) 0%, var(--doc-blue) 50%, #1a6599 100%);
  padding:3rem 2.5rem;display:flex;flex-direction:column;justify-content:center;position:relative;overflow:hidden;
}
.login-left::before{
  content:'';position:absolute;top:-60px;right:-60px;
  width:280px;height:280px;background:rgba(255,255,255,.04);border-radius:50%;
}
.login-left::after{
  content:'';position:absolute;bottom:-80px;left:-40px;
  width:220px;height:220px;background:rgba(255,255,255,.04);border-radius:50%;
}
.clinic-badge{
  display:inline-flex;align-items:center;gap:.5rem;
  background:rgba(255,255,255,.12);color:rgba(255,255,255,.8);
  padding:.35rem .9rem;border-radius:50px;font-size:.78rem;font-weight:600;letter-spacing:.5px;
  margin-bottom:1.5rem;width:fit-content;
}
.clinic-logo{
  width:70px;height:70px;border-radius:18px;
  background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.2);
  display:flex;align-items:center;justify-content:center;font-size:1.8rem;
  font-weight:800;color:#fff;margin-bottom:1.25rem;
}
.login-left h1{font-size:1.6rem;font-weight:800;color:#fff;line-height:1.25;margin-bottom:.5rem;}
.login-left .sub{font-size:.88rem;color:rgba(255,255,255,.6);margin-bottom:2rem;}
.login-left .location{font-size:.78rem;color:rgba(255,255,255,.45);margin-top:auto;}

.feature-item{display:flex;align-items:center;gap:.75rem;margin-bottom:.85rem;}
.feature-icon{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.1);
  display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.8);font-size:.85rem;flex-shrink:0;}
.feature-text{font-size:.82rem;color:rgba(255,255,255,.75);}

.login-right{
  flex:1;display:flex;align-items:center;justify-content:center;
  padding:2rem;background:#fff;
}
.login-card{width:100%;max-width:420px;}
.login-card h2{font-size:1.5rem;font-weight:800;color:#1e293b;margin-bottom:.25rem;}
.login-card .tagline{font-size:.875rem;color:#64748b;margin-bottom:1.75rem;}

.form-label{font-size:.8rem;font-weight:600;color:#374151;margin-bottom:.35rem;}
.form-control{border-radius:10px;border:1.5px solid #e2e8f0;padding:.65rem 1rem;font-size:.9rem;transition:all .2s;}
.form-control:focus{border-color:var(--doc-blue);box-shadow:0 0 0 3px rgba(26,78,124,.12);}
.input-group .input-group-text{border-radius:10px 0 0 10px;border:1.5px solid #e2e8f0;border-right:0;background:#f8fafc;}
.input-group .form-control{border-radius:0 10px 10px 0;border-left:0;}
.input-group:focus-within .input-group-text{border-color:var(--doc-blue);}
.input-group:focus-within .form-control{border-color:var(--doc-blue);}

.btn-login{
  background:linear-gradient(135deg, var(--doc-blue) 0%, var(--doc-dark) 100%);
  color:#fff;border:none;border-radius:10px;padding:.75rem;font-size:.95rem;
  font-weight:700;width:100%;cursor:pointer;transition:opacity .2s;
}
.btn-login:hover{opacity:.9;}
.btn-login:disabled{opacity:.6;cursor:not-allowed;}

.alert{border-radius:10px;font-size:.85rem;padding:.75rem 1rem;}
.alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.alert-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;}

.back-link{font-size:.8rem;color:#94a3b8;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;margin-bottom:1.5rem;}
.back-link:hover{color:var(--doc-blue);}

@media (max-width:768px){
  .login-left{display:none;}
  .login-right{background:var(--gray-50);}
}
</style>
</head>
<body>
<div class="login-split">

  <!-- Left Panel -->
  <div class="login-left">
    <div class="position-relative" style="z-index:2">
      <div class="clinic-badge">
        <i class="fas fa-heartbeat"></i> Doctor Portal
      </div>
      <div class="clinic-logo">
        <?php if (!empty($org['logo'])): ?>
          <img src="<?= APP_URL ?>/uploads/logos/<?= e($org['logo']) ?>" alt="" style="width:50px;height:50px;object-fit:contain;border-radius:12px">
        <?php else: ?>
          <?= $initials ?>
        <?php endif; ?>
      </div>
      <h1><?= e($org['name']) ?></h1>
      <div class="sub">Secure clinical portal for authorised medical staff</div>

      <div class="feature-item">
        <div class="feature-icon"><i class="fas fa-calendar-check"></i></div>
        <div class="feature-text">Manage your daily appointments and patient queue</div>
      </div>
      <div class="feature-item">
        <div class="feature-icon"><i class="fas fa-file-medical"></i></div>
        <div class="feature-text">Write and access patient medical records</div>
      </div>
      <div class="feature-item">
        <div class="feature-icon"><i class="fas fa-prescription"></i></div>
        <div class="feature-text">Issue and print professional prescriptions</div>
      </div>

      <?php if ($location): ?>
      <div class="location"><i class="fas fa-map-marker-alt me-1"></i><?= e($location) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Right Panel -->
  <div class="login-right">
    <div class="login-card">
      <a href="<?= APP_URL ?>/auth/login.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to main login
      </a>

      <h2>Doctor Sign In</h2>
      <div class="tagline">Use your clinic-issued email address to sign in</div>

      <?php if ($notice): ?>
      <div class="alert alert-<?= $notice['type'] ?> d-flex gap-2 align-items-center mb-3">
        <i class="fas <?= $notice['icon'] ?>"></i><span><?= e($notice['msg']) ?></span>
      </div>
      <?php endif; ?>

      <?php if ($loginError): ?>
      <div class="alert alert-danger d-flex gap-2 align-items-center mb-3">
        <i class="fas fa-exclamation-triangle"></i><span><?= e($loginError) ?></span>
      </div>
      <?php endif; ?>

      <?php if (!$orgActive): ?>
      <div class="alert alert-warning d-flex gap-2 align-items-center mb-3">
        <i class="fas fa-pause-circle"></i><span>This clinic's portal is currently inactive. Contact your administrator.</span>
      </div>
      <?php endif; ?>

      <?= flashAlert() ?>

      <form method="POST" <?= !$orgActive ? 'style="opacity:.5;pointer-events:none"' : '' ?>>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <div class="mb-3">
          <label class="form-label">Email Address</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-envelope text-muted" style="font-size:.85rem"></i></span>
            <input type="email" name="email" class="form-control" placeholder="doctor@clinic.com"
                   value="<?= e($emailVal) ?>" required autofocus>
          </div>
        </div>

        <div class="mb-4">
          <label class="form-label">Password</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-lock text-muted" style="font-size:.85rem"></i></span>
            <input type="password" name="password" id="pwInput" class="form-control" placeholder="••••••••" required>
            <button type="button" class="input-group-text bg-white border-start-0 border"
                    style="border-radius:0 10px 10px 0" onclick="togglePw()">
              <i class="fas fa-eye text-muted" id="eyeIco" style="font-size:.8rem"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-login">
          <i class="fas fa-sign-in-alt me-2"></i>Sign In to Portal
        </button>
      </form>

      <div class="mt-3 p-3 rounded" style="background:#f0f6ff;border:1px solid #bcd3f0;font-size:.8rem;color:#4a6fa5">
        <i class="fas fa-info-circle me-1"></i>
        Access is restricted to registered doctors of <strong><?= e($org['name']) ?></strong>.
        Contact your clinic administrator for login credentials.
      </div>
    </div>
  </div>
</div>

<script>
function togglePw() {
  const i = document.getElementById('pwInput'), e = document.getElementById('eyeIco');
  if (i.type === 'password') { i.type = 'text'; e.classList.replace('fa-eye','fa-eye-slash'); }
  else { i.type = 'password'; e.classList.replace('fa-eye-slash','fa-eye'); }
}
</script>
</body>
</html>
