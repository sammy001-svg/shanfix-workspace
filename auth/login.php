<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    redirect(($_SESSION['user_role'] === 'super_admin') ? APP_URL . '/admin/index.php' : APP_URL . '/client/index.php');
}

// ── Security config ───────────────────────────────────────────────
$cfg = getSettings(['max_login_attempts', 'session_timeout']);
$maxAttempts  = max(3, (int)($cfg['max_login_attempts'] ?? 5));
$lockMinutes  = 15;

// ── Helpers ───────────────────────────────────────────────────────
function recentFailedAttempts(PDO $pdo, string $email, string $ip, int $windowMin = 15): int {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE (email=? OR ip=?) AND success=0
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$email, $ip, $windowMin]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function logAttempt(PDO $pdo, string $email, string $ip, bool $success): void {
    try {
        $pdo->prepare(
            "INSERT INTO login_attempts (email, ip, user_agent, success) VALUES (?,?,?,?)"
        )->execute([$email, $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500), $success ? 1 : 0]);
    } catch (Exception $e) { /* table may not exist yet */ }
}

$error    = '';
$warning  = '';
$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Expired session / hijack messages
if (isset($_GET['expired'])) $warning = 'Your session expired. Please sign in again.';
if (isset($_GET['hijack']))  $warning = 'Session invalidated for security. Please sign in again.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        // ── Rate limiting check ───────────────────────────────
        $recentFails = recentFailedAttempts($pdo, $email, $ip, $lockMinutes);
        if ($recentFails >= $maxAttempts) {
            $error = "Too many failed attempts. Please wait {$lockMinutes} minutes before trying again.";
            logAttempt($pdo, $email, $ip, false);
        } else {
            // ── Fetch user ────────────────────────────────────
            $stmt = $pdo->prepare(
                "SELECT u.*, o.name as org_name, o.status as org_status
                 FROM users u
                 LEFT JOIN organizations o ON u.org_id = o.id
                 WHERE u.email=? LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // ── Hard lockout check (DB-level) ─────────────────
            if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $minutes = ceil((strtotime($user['locked_until']) - time()) / 60);
                $error   = "Account locked. Try again in {$minutes} minute" . ($minutes !== 1 ? 's' : '') . ".";
                logAttempt($pdo, $email, $ip, false);
            }
            // ── Inactive user / suspended org ─────────────────
            elseif ($user && $user['status'] !== 'active') {
                $error = 'Your account has been deactivated. Please contact support.';
                logAttempt($pdo, $email, $ip, false);
            }
            elseif ($user && ($user['org_status'] ?? 'active') === 'suspended' && $user['role'] !== 'super_admin') {
                $error = 'Your organization account is suspended. Please contact your administrator.';
                logAttempt($pdo, $email, $ip, false);
            }
            // ── Password verification ─────────────────────────
            elseif ($user && password_verify($password, $user['password'])) {
                // Reset failed logins
                try {
                    $pdo->prepare("UPDATE users SET failed_logins=0, locked_until=NULL WHERE id=?")
                        ->execute([$user['id']]);
                } catch (Exception $e) {}

                logAttempt($pdo, $email, $ip, true);

                // ── 2FA check ─────────────────────────────────
                if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
                    // Store pending auth in session (not full login yet)
                    $_SESSION['2fa_pending_uid']  = $user['id'];
                    $_SESSION['2fa_pending_time'] = time();
                    $_SESSION['2fa_remember']     = $remember;
                    redirect(APP_URL . '/auth/2fa-verify.php');
                }

                // ── Full login ────────────────────────────────
                _completeLogin($pdo, $user, $remember);
            }
            // ── Wrong password ────────────────────────────────
            else {
                logAttempt($pdo, $email, $ip, false);

                if ($user) {
                    // Increment DB-level counter
                    try {
                        $fails = (int)$user['failed_logins'] + 1;
                        if ($fails >= $maxAttempts) {
                            $lockUntil = date('Y-m-d H:i:s', strtotime("+{$lockMinutes} minutes"));
                            $pdo->prepare("UPDATE users SET failed_logins=?, locked_until=? WHERE id=?")
                                ->execute([$fails, $lockUntil, $user['id']]);
                            $error = "Too many failed attempts. Account locked for {$lockMinutes} minutes.";
                        } else {
                            $pdo->prepare("UPDATE users SET failed_logins=? WHERE id=?")->execute([$fails, $user['id']]);
                            $remaining = $maxAttempts - $fails;
                            $error = "Invalid password. {$remaining} attempt" . ($remaining !== 1 ? 's' : '') . " remaining.";
                        }
                    } catch (Exception $e) {
                        $error = 'Invalid email or password.';
                    }
                } else {
                    $error = 'Invalid email or password.';
                }
            }
        }
    }
}

function _completeLogin(PDO $pdo, array $user, bool $remember): void {
    session_regenerate_id(true);
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user_name']     = $user['name'];
    $_SESSION['user_email']    = $user['email'];
    $_SESSION['user_role']     = $user['role'];
    $_SESSION['org_id']        = $user['org_id'];
    $_SESSION['org_name']      = $user['org_name'] ?? '';
    $_SESSION['last_activity'] = time();
    $_SESSION['fingerprint']   = md5(($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));

    $pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);

    if ($remember) {
        $token = bin2hex(random_bytes(32));
        setcookie('remember_token', $token, time() + 86400 * 30, '/', '', true, true);
        $pdo->prepare("UPDATE users SET remember_token=? WHERE id=?")->execute([$token, $user['id']]);
    }

    setFlash('success', 'Welcome back, ' . $user['name'] . '!');
    redirect($user['role'] === 'super_admin' ? APP_URL . '/admin/index.php' : APP_URL . '/client/index.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body">

<!-- Left panel -->
<div class="auth-left d-none d-lg-flex flex-column justify-content-center">
  <div class="position-relative z-2 text-white">
    <div class="mb-4">
      <div style="width:56px;height:56px;background:rgba(255,255,255,.15);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin-bottom:1rem">
        <i class="fas fa-cubes"></i>
      </div>
      <h2 class="fw-800 mb-2"><?= APP_NAME ?></h2>
      <p class="text-white-50"><?= APP_TAGLINE ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-4">
      <?php foreach(['Accounting','CRM','HRM','Hotel','SACCO','School','POS','Rental'] as $b): ?>
      <span class="badge" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.8);font-size:.75rem;padding:.4rem .8rem;border-radius:50px"><?= $b ?></span>
      <?php endforeach; ?>
      <span class="badge" style="background:var(--green);font-size:.75rem;padding:.4rem .8rem;border-radius:50px">+12 More</span>
    </div>
    <div class="d-flex gap-3">
      <?php foreach([['500+','Businesses'],['20','Modules'],['99.9%','Uptime']] as $s): ?>
      <div class="text-center p-3" style="background:rgba(255,255,255,.08);border-radius:12px;min-width:80px">
        <div style="font-size:1.3rem;font-weight:800"><?= $s[0] ?></div>
        <div style="font-size:.7rem;opacity:.6"><?= $s[1] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Right panel -->
<div class="auth-right">
  <div class="auth-card">
    <div class="auth-logo">
      <div class="logo-box"><i class="fas fa-cubes"></i></div>
      <div class="logo-text"><?= APP_NAME ?></div>
    </div>

    <h2 class="auth-title">Welcome Back</h2>
    <p class="auth-subtitle">Sign in to access your workspace</p>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2">
      <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
      <span><?= e($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($warning): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
      <i class="fas fa-shield-alt flex-shrink-0"></i>
      <span><?= e($warning) ?></span>
    </div>
    <?php endif; ?>

    <?= flashAlert() ?>

    <form method="POST" data-loading>
      <div class="mb-3">
        <label class="form-label">Email Address</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-envelope text-muted"></i></span>
          <input type="email" name="email" class="form-control" placeholder="you@company.com"
                 value="<?= isset($_POST['email']) ? e(strtolower($_POST['email'])) : '' ?>" required autofocus>
        </div>
      </div>

      <div class="mb-3">
        <div class="d-flex justify-content-between">
          <label class="form-label">Password</label>
          <a href="<?= APP_URL ?>/auth/forgot-password.php" class="text-green small">Forgot password?</a>
        </div>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
          <input type="password" name="password" id="passwordInput" class="form-control" placeholder="••••••••" required>
          <button type="button" class="input-group-text bg-white border-start-0" onclick="togglePassword()">
            <i class="fas fa-eye text-muted" id="eyeIcon"></i>
          </button>
        </div>
      </div>

      <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="remember" id="remember">
          <label class="form-check-label small" for="remember">Remember me for 30 days</label>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 py-2 fw-600">
        <i class="fas fa-sign-in-alt me-2"></i>Sign In
      </button>
    </form>

    <div class="text-center mt-4">
      <p class="text-muted small">
        Don't have an account?
        <a href="<?= APP_URL ?>/auth/register.php" class="text-green fw-600">Create one free</a>
      </p>
      <a href="<?= APP_URL ?>" class="text-muted small"><i class="fas fa-arrow-left me-1"></i>Back to home</a>
    </div>

    <!-- Security notice -->
    <div class="mt-4 p-3 rounded d-flex align-items-start gap-2" style="background:#f0f9f4;border:1px solid #c3e6d1">
      <i class="fas fa-shield-alt text-green mt-1 flex-shrink-0"></i>
      <div class="small text-muted">
        This system enforces login rate limiting and session security. Accounts are locked after <?= $maxAttempts ?> failed attempts.
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
function togglePassword() {
  const input = document.getElementById('passwordInput');
  const icon  = document.getElementById('eyeIcon');
  if (input.type === 'password') { input.type = 'text';     icon.classList.replace('fa-eye','fa-eye-slash'); }
  else                           { input.type = 'password'; icon.classList.replace('fa-eye-slash','fa-eye'); }
}
</script>
</body>
</html>
