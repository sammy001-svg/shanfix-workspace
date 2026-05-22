<?php
/**
 * 2FA verification — shown after password check passes, before session is created
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/totp.php';

// Must have a pending 2FA auth and it must be fresh (max 5 min)
if (empty($_SESSION['2fa_pending_uid']) ||
    (time() - ($_SESSION['2fa_pending_time'] ?? 0)) > 300) {
    unset($_SESSION['2fa_pending_uid'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_remember']);
    redirect(APP_URL . '/auth/login.php');
}

$pendingUid = (int)$_SESSION['2fa_pending_uid'];
$remember   = !empty($_SESSION['2fa_remember']);
$error      = '';

// Fetch the user
$stmt = $pdo->prepare("SELECT u.*, o.name as org_name FROM users u LEFT JOIN organizations o ON u.org_id=o.id WHERE u.id=? LIMIT 1");
$stmt->execute([$pendingUid]);
$user = $stmt->fetch();

if (!$user || !$user['totp_enabled'] || !$user['totp_secret']) {
    unset($_SESSION['2fa_pending_uid'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_remember']);
    redirect(APP_URL . '/auth/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = preg_replace('/\D/', '', $_POST['code'] ?? '');

    if (totpVerify($user['totp_secret'], $code)) {
        // Clear pending markers
        unset($_SESSION['2fa_pending_uid'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_remember']);

        // Create full session
        session_regenerate_id(true);
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['user_name']     = $user['name'];
        $_SESSION['user_email']    = $user['email'];
        $_SESSION['user_role']     = $user['role'];
        $_SESSION['org_id']        = $user['org_id'];
        $_SESSION['org_name']      = $user['org_name'] ?? '';
        $_SESSION['last_activity'] = time();
        $_SESSION['fingerprint']   = md5(($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));

        $pdo->prepare("UPDATE users SET last_login=NOW(), failed_logins=0 WHERE id=?")->execute([$user['id']]);

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            setcookie('remember_token', $token, time() + 86400 * 30, '/', '', true, true);
            $pdo->prepare("UPDATE users SET remember_token=? WHERE id=?")->execute([$token, $user['id']]);
        }

        setFlash('success', 'Welcome back, ' . $user['name'] . '!');
        redirect($user['role'] === 'super_admin' ? APP_URL . '/admin/index.php' : APP_URL . '/client/index.php');
    } else {
        $error = 'Invalid code. Please check your authenticator app and try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Two-Factor Authentication — <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<style>
.code-input {
  letter-spacing: .4rem;
  font-size: 1.8rem;
  font-weight: 700;
  text-align: center;
  font-family: monospace;
}
</style>
</head>
<body class="auth-body">
<div class="auth-right" style="margin:0 auto">
  <div class="auth-card">
    <div class="auth-logo">
      <div class="logo-box"><i class="fas fa-cubes"></i></div>
      <div class="logo-text"><?= APP_NAME ?></div>
    </div>

    <div class="text-center mb-4">
      <div style="width:64px;height:64px;background:#e6f5ee;border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
        <i class="fas fa-mobile-alt fa-2x text-green"></i>
      </div>
      <h2 class="auth-title mb-1">Two-Factor Auth</h2>
      <p class="auth-subtitle">Enter the 6-digit code from your authenticator app for <strong><?= e($user['name']) ?></strong></p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2">
      <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
      <span><?= e($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" id="totpForm">
      <div class="mb-4">
        <input type="text" name="code" id="codeInput"
               class="form-control code-input"
               maxlength="6" pattern="\d{6}" inputmode="numeric"
               placeholder="000000" required autofocus autocomplete="one-time-code">
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2 fw-600">
        <i class="fas fa-check me-2"></i>Verify Code
      </button>
    </form>

    <!-- Countdown -->
    <div class="text-center mt-3">
      <div class="d-flex align-items-center justify-content-center gap-2 text-muted small">
        <i class="fas fa-clock"></i>
        Code refreshes in <span id="countdown" class="fw-700 text-green">30</span>s
      </div>
      <div class="progress mt-1" style="height:3px">
        <div class="progress-bar bg-green" id="timerBar" style="width:100%;transition:width 1s linear"></div>
      </div>
    </div>

    <div class="text-center mt-4">
      <a href="login.php" class="text-muted small"><i class="fas fa-arrow-left me-1"></i>Use a different account</a>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-submit when 6 digits entered
document.getElementById('codeInput').addEventListener('input', function() {
  this.value = this.value.replace(/\D/g, '').substring(0, 6);
  if (this.value.length === 6) document.getElementById('totpForm').submit();
});

// TOTP 30-second countdown
(function() {
  function tick() {
    const secs = 30 - (Math.floor(Date.now() / 1000) % 30);
    document.getElementById('countdown').textContent = secs;
    document.getElementById('timerBar').style.width  = (secs / 30 * 100) + '%';
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
</body>
</html>
