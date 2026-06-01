<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) redirect(APP_URL . '/client/index.php');

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$done  = false;

// ── Validate token ────────────────────────────────────────────────
$reset = null;
if ($token) {
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM password_resets WHERE token=? AND used=0 AND expires_at > NOW() LIMIT 1"
        );
        $stmt->execute([$token]);
        $reset = $stmt->fetch();
    } catch (Exception $e) { /* table may not exist */ }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $new1 = $_POST['password']  ?? '';
    $new2 = $_POST['password2'] ?? '';

    if (strlen($new1) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($new1 !== $new2) {
        $error = 'Passwords do not match.';
    } else {
        // Update password
        $hash = password_hash($new1, PASSWORD_BCRYPT);
        $pdo->prepare(
            "UPDATE users SET password=?, failed_logins=0, locked_until=NULL, last_password_change=CURDATE()
             WHERE email=?"
        )->execute([$hash, $reset['email']]);

        // Mark token as used
        $pdo->prepare("UPDATE password_resets SET used=1 WHERE token=?")->execute([$token]);

        // Determine org portal URL for post-reset redirect
        $postResetUrl = APP_URL . '/auth/login.php';
        try {
            $uRow = $pdo->prepare("SELECT u.role, o.slug FROM users u LEFT JOIN organizations o ON u.org_id=o.id WHERE u.email=? LIMIT 1");
            $uRow->execute([$reset['email']]);
            $uData = $uRow->fetch();
            if ($uData && in_array($uData['role'], ['staff', 'client_admin'], true) && $uData['slug']) {
                $postResetUrl = APP_URL . '/auth/org-login.php?org=' . rawurlencode($uData['slug']) . '&reset=1';
            }
        } catch (Exception $e) {}

        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-right" style="margin:0 auto">
  <div class="auth-card">
    <div class="auth-logo">
      <div class="logo-box"><i class="fas fa-cubes"></i></div>
      <div class="logo-text"><?= APP_NAME ?></div>
    </div>

    <?php if ($done): ?>
    <div class="text-center py-2">
      <div style="width:64px;height:64px;background:#e6f5ee;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
        <i class="fas fa-check-circle fa-2x text-green"></i>
      </div>
      <h3 class="fw-700">Password Reset!</h3>
      <p class="text-muted">Your password has been updated successfully. You can now sign in with your new password.</p>
      <a href="<?= htmlspecialchars($postResetUrl, ENT_QUOTES) ?>" class="btn btn-primary"><i class="fas fa-sign-in-alt me-2"></i>Sign In</a>
    </div>

    <?php elseif (!$reset): ?>
    <div class="text-center py-2">
      <div style="width:64px;height:64px;background:#fff5f5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
        <i class="fas fa-times-circle fa-2x text-danger"></i>
      </div>
      <h3 class="fw-700">Link Expired</h3>
      <p class="text-muted">This password reset link is invalid or has expired. Reset links are valid for 1 hour.</p>
      <a href="forgot-password.php" class="btn btn-primary">Request New Link</a>
      <div class="mt-3">
        <a href="login.php" class="text-muted small"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
      </div>
    </div>

    <?php else: ?>
    <h2 class="auth-title">Set New Password</h2>
    <p class="auth-subtitle">For <strong><?= e($reset['email']) ?></strong></p>

    <?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="token" value="<?= e($token) ?>">

      <div class="mb-3">
        <label class="form-label">New Password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
          <input type="password" name="password" id="pwd1" class="form-control" placeholder="Min. 8 characters"
                 required minlength="8" oninput="checkStrength(this.value)">
          <button type="button" class="input-group-text bg-white border-start-0" onclick="togglePwd('pwd1','eye1')">
            <i class="fas fa-eye text-muted" id="eye1"></i>
          </button>
        </div>
        <!-- Strength indicator -->
        <div class="mt-1 d-flex gap-1" id="strengthBars">
          <div class="flex-fill rounded" id="s1" style="height:4px;background:#e2e8f0"></div>
          <div class="flex-fill rounded" id="s2" style="height:4px;background:#e2e8f0"></div>
          <div class="flex-fill rounded" id="s3" style="height:4px;background:#e2e8f0"></div>
          <div class="flex-fill rounded" id="s4" style="height:4px;background:#e2e8f0"></div>
        </div>
        <div class="small mt-1 text-muted" id="strengthLabel"></div>
      </div>

      <div class="mb-4">
        <label class="form-label">Confirm New Password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
          <input type="password" name="password2" id="pwd2" class="form-control" placeholder="Repeat password" required minlength="8">
          <button type="button" class="input-group-text bg-white border-start-0" onclick="togglePwd('pwd2','eye2')">
            <i class="fas fa-eye text-muted" id="eye2"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 py-2 fw-600">
        <i class="fas fa-key me-2"></i>Set New Password
      </button>
    </form>

    <div class="text-center mt-3">
      <a href="login.php" class="text-muted small"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePwd(inputId, iconId) {
  const i = document.getElementById(inputId);
  const e = document.getElementById(iconId);
  if (i.type === 'password') { i.type = 'text'; e.classList.replace('fa-eye','fa-eye-slash'); }
  else { i.type = 'password'; e.classList.replace('fa-eye-slash','fa-eye'); }
}

function checkStrength(val) {
  let score = 0;
  if (val.length >= 8)              score++;
  if (/[A-Z]/.test(val))           score++;
  if (/[0-9]/.test(val))           score++;
  if (/[^A-Za-z0-9]/.test(val))   score++;
  const colors = ['#ef4444','#f59e0b','#3b82f6','#1A8A4E'];
  const labels = ['Weak','Fair','Good','Strong'];
  for (let i = 1; i <= 4; i++) {
    document.getElementById('s' + i).style.background = i <= score ? colors[score - 1] : '#e2e8f0';
  }
  document.getElementById('strengthLabel').textContent = val.length ? labels[score - 1] || '' : '';
}
</script>
</body>
</html>
