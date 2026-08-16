<?php
/**
 * School Portal — White-label login page
 * Shown to staff logging in via a school's custom domain.
 * No OrbitDesk branding is displayed.
 *
 * Org detection order:
 *   1. HTTP_HOST matched against organizations.school_portal_domain
 *   2. ?org=SLUG  GET parameter (fallback / direct link)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Defensive migration — works even if settings.php was never visited yet
try { $pdo->exec("ALTER TABLE organizations ADD COLUMN IF NOT EXISTS school_portal_domain VARCHAR(255) DEFAULT NULL AFTER brand_tagline"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE organizations ADD UNIQUE KEY IF NOT EXISTS uq_school_portal_domain (school_portal_domain)"); } catch (Throwable $e) {}

// ── Already logged in via portal? ────────────────────────────────
if (!empty($_SESSION['school_portal_mode']) && isLoggedIn()) {
    header('Location: /modules/school/index.php'); exit;
}

$error   = '';
$orgId   = null;
$orgSlug = '';
$org     = null;

// ── Detect org from HTTP_HOST ─────────────────────────────────────
$reqHost = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
$appHost = strtolower(parse_url(APP_URL, PHP_URL_HOST) ?? '');

if ($reqHost && $reqHost !== $appHost) {
    try {
        $s = $pdo->prepare("SELECT id FROM organizations WHERE school_portal_domain IS NOT NULL AND LOWER(school_portal_domain)=? LIMIT 1");
        $s->execute([$reqHost]);
        $orgId = $s->fetchColumn() ?: null;
    } catch (Throwable $e) {}
}

// ── Fallback: detect org from ?org=SLUG ──────────────────────────
if (!$orgId && !empty($_GET['org'])) {
    $slug = preg_replace('/[^a-zA-Z0-9\-_]/', '', $_GET['org']);
    try {
        $s = $pdo->prepare("SELECT id FROM organizations WHERE slug=? LIMIT 1");
        $s->execute([$slug]);
        $orgId = $s->fetchColumn() ?: null;
    } catch (Throwable $e) {}
}

if (!$orgId) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Portal Not Found</title>'
        .'<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
        .'<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">'
        .'<div class="text-center p-5"><i class="fas fa-globe fa-3x text-muted mb-3 d-block"></i>'
        .'<h4>Portal Not Found</h4><p class="text-muted">This school portal has not been configured.<br>'
        .'Contact your system administrator.</p></div>'
        .'<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"></body></html>';
    exit;
}

// ── Load org ──────────────────────────────────────────────────────
try {
    $s = $pdo->prepare("SELECT * FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$orgId]);
    $org = $s->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!$org || $org['status'] !== 'active') {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Portal Unavailable</title>'
        .'<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
        .'<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">'
        .'<div class="text-center p-5"><h4 class="text-danger">Portal Unavailable</h4>'
        .'<p class="text-muted">This portal is currently inactive. Please contact your administrator.</p></div></body></html>';
    exit;
}

$orgId   = (int)$org['id'];
$orgSlug = $org['slug'] ?? '';

// ── Portal branding — reuse the org's own profile fields ──────────
$portalTitle   = $org['name'] ?? 'School Portal';
$portalTagline = $org['brand_tagline'] ?? '';
$accentColor   = $org['primary_color'] ?? '#1A8A4E';
$showPowered   = true;

// Logo (school stores organizations.logo as a relative path, e.g. assets/uploads/logos/...)
$logoUrl = '';
if (!empty($org['logo'])) {
    $logoPath = __DIR__ . '/../../' . $org['logo'];
    if (file_exists($logoPath)) {
        $logoUrl = APP_URL . '/' . htmlspecialchars($org['logo']);
    }
}
$initial = strtoupper(substr($portalTitle, 0, 1));

// ── POST: authenticate ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check (manual — no session token seeded yet on GET for this public page)
    if (empty($_POST['_ptok']) || empty($_SESSION['_ptok']) || !hash_equals($_SESSION['_ptok'], $_POST['_ptok'])) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $email    = strtolower(trim($_POST['email']    ?? ''));
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $error = 'Please enter your email and password.';
        } else {
            try {
                $s = $pdo->prepare("
                    SELECT u.*, o.name AS org_name, o.slug AS org_slug, o.status AS org_status
                    FROM users u
                    JOIN organizations o ON o.id = u.org_id
                    WHERE LOWER(u.email) = ? AND u.org_id = ?
                    LIMIT 1
                ");
                $s->execute([$email, $orgId]);
                $authUser = $s->fetch(PDO::FETCH_ASSOC);

                if (!$authUser) {
                    $error = 'Invalid email or password.';
                } elseif ($authUser['status'] !== 'active') {
                    $error = 'Your account has been deactivated. Contact your administrator.';
                } elseif (!password_verify($password, $authUser['password'])) {
                    $error = 'Invalid email or password.';
                } elseif ($authUser['role'] === 'super_admin') {
                    $error = 'Super admin accounts cannot access school portals.';
                } else {
                    // ── Set standard session variables ────────────────
                    session_regenerate_id(true);
                    $_SESSION['user_id']      = (int)$authUser['id'];
                    $_SESSION['user_role']    = $authUser['role'];
                    $_SESSION['user_name']    = $authUser['name'];
                    $_SESSION['user_email']   = $authUser['email'];
                    $_SESSION['org_id']       = (int)$authUser['org_id'];
                    $_SESSION['org_slug']     = $authUser['org_slug'];
                    $_SESSION['org_name']     = $authUser['org_name'];
                    $_SESSION['org_status']   = $authUser['org_status'];
                    $_SESSION['last_active']  = time();

                    // ── School portal mode flags ──────────────────────
                    $_SESSION['school_portal_mode']   = true;
                    $_SESSION['school_portal_org_id'] = (int)$orgId;
                    $_SESSION['school_portal_host']   = $reqHost ?: $appHost;
                    $_SESSION['school_portal_accent'] = $accentColor;
                    $_SESSION['school_portal_title']  = $portalTitle;
                    $_SESSION['school_portal_logo']   = $logoUrl;

                    // Relative redirect — stays on current domain (custom or main)
                    header('Location: /modules/school/index.php'); exit;
                }
            } catch (Throwable $e) {
                $error = 'A system error occurred. Please try again.';
            }
        }
    }
}

// Seed one-time CSRF token for login form
if (empty($_SESSION['_ptok'])) {
    $_SESSION['_ptok'] = bin2hex(random_bytes(24));
}

// ── Background style ──────────────────────────────────────────────
$leftBg     = 'linear-gradient(135deg, #0b2d4e 0%, ' . $accentColor . ' 50%, #0d3b1e 100%)';
$textOnLeft = '#ffffff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($portalTitle) ?> — Staff Login</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: 'Segoe UI', system-ui, sans-serif; }

.login-wrap {
  display: flex; min-height: 100vh;
}

/* ── Left branding panel ──────────────────────────────────────── */
.brand-panel {
  width: 42%; background: <?= $leftBg ?>; color: <?= $textOnLeft ?>;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 48px 40px; position: relative; overflow: hidden;
}
.brand-panel::before {
  content: ''; position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
  background-size: 40px 40px; pointer-events: none;
}
.brand-panel .school-icons {
  position: absolute; inset: 0; pointer-events: none; overflow: hidden; opacity: .06;
  font-size: 5rem; display: flex; flex-wrap: wrap; gap: 2rem; padding: 2rem;
  align-content: flex-start;
}
.brand-logo {
  width: 84px; height: 84px; border-radius: 20px;
  background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.3);
  display: flex; align-items: center; justify-content: center;
  font-size: 2.2rem; font-weight: 900;
  color: <?= $textOnLeft ?>; margin-bottom: 20px; position: relative; z-index: 1;
  overflow: hidden;
}
.brand-logo img { width: 100%; height: 100%; object-fit: contain; padding: 10px; }
.brand-name {
  font-size: 1.5rem; font-weight: 800; text-align: center; line-height: 1.25;
  position: relative; z-index: 1; margin-bottom: 8px;
  color: <?= $textOnLeft ?>;
}
.brand-tagline {
  font-size: .875rem; text-align: center; line-height: 1.6;
  position: relative; z-index: 1; margin-bottom: 32px;
  color: <?= $textOnLeft ?>; opacity: .75;
}
.brand-features {
  list-style: none; padding: 0; position: relative; z-index: 1; width: 100%; max-width: 260px;
}
.brand-features li {
  display: flex; align-items: center; gap: 10px;
  padding: 6px 0; font-size: .82rem; color: <?= $textOnLeft ?>; opacity: .85;
  border-bottom: 1px solid rgba(255,255,255,.1);
}
.brand-features li:last-child { border-bottom: 0; }
.brand-features li i { opacity: .7; width: 16px; text-align: center; }
.powered-by {
  position: absolute; bottom: 20px; font-size: .7rem;
  color: <?= $textOnLeft ?>; opacity: .35; text-align: center;
}

/* ── Right login panel ────────────────────────────────────────── */
.login-panel {
  flex: 1; background: #fff; display: flex; align-items: center; justify-content: center;
  padding: 48px 40px;
}
.login-box { width: 100%; max-width: 400px; }
.login-box .welcome { font-size: 1.6rem; font-weight: 800; color: #1a1a2e; margin-bottom: 4px; }
.login-box .welcome-sub { font-size: .9rem; color: #6c757d; margin-bottom: 32px; }

.form-label-sm { font-size: .8rem; font-weight: 700; color: #374151; margin-bottom: 5px; }
.form-control-portal {
  height: 46px; border: 1.5px solid #e5e7eb; border-radius: 10px;
  font-size: .9rem; padding: 0 14px 0 42px; width: 100%;
  transition: border-color .2s, box-shadow .2s; outline: none; background: #fafafa;
}
.form-control-portal:focus {
  border-color: <?= $accentColor ?>; background: #fff;
  box-shadow: 0 0 0 3px <?= $accentColor ?>22;
}
.input-wrap { position: relative; }
.input-icon {
  position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
  color: #9ca3af; font-size: .85rem; pointer-events: none;
}
.toggle-pass {
  position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
  color: #9ca3af; cursor: pointer; background: none; border: none; padding: 0;
  font-size: .85rem;
}
.btn-portal {
  width: 100%; height: 48px; background: <?= $accentColor ?>; color: #fff;
  border: none; border-radius: 10px; font-size: .95rem; font-weight: 700;
  cursor: pointer; transition: filter .2s, transform .15s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-portal:hover { filter: brightness(1.08); transform: translateY(-1px); }
.btn-portal:active { transform: translateY(0); }
.btn-portal .spinner { display: none; }
.btn-portal.loading .btn-text { display: none; }
.btn-portal.loading .spinner { display: inline-block; }

.divider { display: flex; align-items: center; gap: 12px; margin: 20px 0; color: #d1d5db; font-size: .78rem; }
.divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e5e7eb; }

.error-box {
  background: #fff5f5; border: 1px solid #fecaca; border-radius: 10px;
  padding: 12px 14px; margin-bottom: 20px; color: #b91c1c; font-size: .85rem;
  display: flex; align-items: center; gap: 10px;
}

@media (max-width: 768px) {
  .brand-panel { display: none; }
  .login-panel { padding: 32px 24px; }
}
</style>
</head>
<body>

<div class="login-wrap">

  <!-- ── Left: Branding ──────────────────────────────────────── -->
  <div class="brand-panel">
    <!-- Decorative school icons background -->
    <div class="school-icons" aria-hidden="true">
      <i class="fas fa-school"></i><i class="fas fa-user-graduate"></i>
      <i class="fas fa-chalkboard-teacher"></i><i class="fas fa-book"></i>
      <i class="fas fa-graduation-cap"></i><i class="fas fa-calendar-check"></i>
      <i class="fas fa-file-invoice-dollar"></i><i class="fas fa-bus"></i>
      <i class="fas fa-book-reader"></i><i class="fas fa-clipboard-check"></i>
    </div>

    <!-- Logo -->
    <div class="brand-logo">
      <?php if ($logoUrl): ?>
        <img src="<?= $logoUrl ?>" alt="<?= htmlspecialchars($portalTitle) ?>">
      <?php else: ?>
        <?= $initial ?>
      <?php endif; ?>
    </div>

    <h1 class="brand-name"><?= htmlspecialchars($portalTitle) ?></h1>
    <?php if ($portalTagline): ?>
    <p class="brand-tagline"><?= htmlspecialchars($portalTagline) ?></p>
    <?php endif; ?>

    <ul class="brand-features">
      <li><i class="fas fa-user-graduate"></i> Student Management</li>
      <li><i class="fas fa-clipboard-check"></i> Attendance &amp; Timetable</li>
      <li><i class="fas fa-file-alt"></i> Exams &amp; Results</li>
      <li><i class="fas fa-money-bill-wave"></i> Fee Billing</li>
      <li><i class="fas fa-bullhorn"></i> Parent Communication</li>
    </ul>

    <?php if ($showPowered): ?>
    <div class="powered-by">School Management System</div>
    <?php endif; ?>
  </div>

  <!-- ── Right: Login form ───────────────────────────────────── -->
  <div class="login-panel">
    <div class="login-box">

      <h2 class="welcome">Welcome back</h2>
      <p class="welcome-sub">Sign in to access <?= htmlspecialchars($portalTitle) ?></p>

      <?php if ($error): ?>
      <div class="error-box">
        <i class="fas fa-exclamation-circle fa-lg"></i>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" id="loginForm" onsubmit="handleSubmit(event)">
        <input type="hidden" name="_ptok" value="<?= htmlspecialchars($_SESSION['_ptok']) ?>">

        <!-- Email -->
        <div class="mb-4">
          <label class="form-label-sm">Email Address</label>
          <div class="input-wrap">
            <i class="fas fa-envelope input-icon"></i>
            <input type="email" name="email" class="form-control-portal"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   placeholder="your@school.com" required autofocus autocomplete="email">
          </div>
        </div>

        <!-- Password -->
        <div class="mb-5">
          <label class="form-label-sm">Password</label>
          <div class="input-wrap">
            <i class="fas fa-lock input-icon"></i>
            <input type="password" name="password" id="passField" class="form-control-portal"
                   placeholder="••••••••" required autocomplete="current-password">
            <button type="button" class="toggle-pass" onclick="togglePass()" tabindex="-1">
              <i class="fas fa-eye" id="passEye"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-portal" id="loginBtn">
          <span class="btn-text"><i class="fas fa-sign-in-alt"></i> Sign In</span>
          <span class="spinner"><i class="fas fa-circle-notch fa-spin"></i> Signing in…</span>
        </button>
      </form>

      <div class="divider">secure access</div>

      <div style="text-align:center;font-size:.78rem;color:#9ca3af">
        <i class="fas fa-shield-alt me-1" style="color:<?= $accentColor ?>"></i>
        This is a private school management portal.<br>
        Unauthorised access is strictly prohibited.
      </div>

    </div>
  </div>

</div>

<script>
function togglePass() {
  const f = document.getElementById('passField');
  const e = document.getElementById('passEye');
  if (f.type === 'password') { f.type = 'text'; e.className = 'fas fa-eye-slash'; }
  else                        { f.type = 'password'; e.className = 'fas fa-eye'; }
}
function handleSubmit(ev) {
  const btn = document.getElementById('loginBtn');
  btn.classList.add('loading');
  btn.disabled = true;
}
</script>
</body>
</html>
