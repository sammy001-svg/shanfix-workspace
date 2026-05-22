<?php
/**
 * 2FA Setup — enable/disable TOTP for the current user
 * Accessible from client/profile.php or admin profile
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/totp.php';

requireLogin();
$user = currentUser();
$uid  = (int)$user['id'];

// Fetch fresh user row for TOTP fields
$row = $pdo->prepare("SELECT id, name, email, totp_secret, totp_enabled FROM users WHERE id=?");
$row->execute([$uid]);
$row = $row->fetch();

$error   = '';
$success = '';
$step    = $_GET['step'] ?? 'status'; // status | setup | confirm | disable

// ── POST actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // Begin setup — generate secret, store in session until confirmed
    if ($act === 'begin_setup') {
        $_SESSION['totp_setup_secret'] = totpGenerateSecret();
        $step = 'setup';
    }

    // Confirm code and activate 2FA
    if ($act === 'confirm_setup') {
        $secret = $_SESSION['totp_setup_secret'] ?? '';
        $code   = preg_replace('/\D/', '', $_POST['code'] ?? '');
        if (!$secret) {
            $error = 'Setup session expired. Please start again.';
            $step  = 'status';
        } elseif (!totpVerify($secret, $code)) {
            $error = 'Invalid code. Please try again.';
            $step  = 'setup';
        } else {
            $pdo->prepare("UPDATE users SET totp_secret=?, totp_enabled=1 WHERE id=?")->execute([$secret, $uid]);
            unset($_SESSION['totp_setup_secret']);
            logActivity('2fa_enabled', 'security', 'User enabled 2FA');
            $success = '2FA has been enabled on your account.';
            $step    = 'done_enabled';
            // Refresh row
            $row['totp_enabled'] = 1;
            $row['totp_secret']  = $secret;
        }
    }

    // Disable 2FA — require password confirmation
    if ($act === 'disable_2fa') {
        $password = $_POST['confirm_password'] ?? '';
        $dbHash   = $pdo->prepare("SELECT password FROM users WHERE id=?")->execute([$uid]) ? $pdo->query("SELECT password FROM users WHERE id=$uid")->fetchColumn() : '';
        $dbRow    = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $dbRow->execute([$uid]);
        $dbHash = $dbRow->fetchColumn();
        if (!password_verify($password, $dbHash)) {
            $error = 'Incorrect password. 2FA was not disabled.';
            $step  = 'disable';
        } else {
            $pdo->prepare("UPDATE users SET totp_secret=NULL, totp_enabled=0 WHERE id=?")->execute([$uid]);
            logActivity('2fa_disabled', 'security', 'User disabled 2FA');
            $success = '2FA has been disabled. Your account is now less secure.';
            $step    = 'done_disabled';
            $row['totp_enabled'] = 0;
        }
    }
}

$setupSecret = $_SESSION['totp_setup_secret'] ?? '';
$qrUrl       = $setupSecret ? totpQrUrl($setupSecret, $row['email'], defined('APP_NAME') ? APP_NAME : 'OrbitDesk') : '';
$returnUrl   = $user['role'] === 'super_admin' ? APP_URL . '/admin/profile.php' : APP_URL . '/client/profile.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Two-Factor Setup — <?= APP_NAME ?></title>
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

    <?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= e($success) ?></div>
    <?php endif; ?>

    <!-- ── Status view ── -->
    <?php if (in_array($step, ['status','done_enabled','done_disabled'])): ?>
    <div class="text-center mb-4">
      <?php if ($row['totp_enabled']): ?>
      <div style="width:64px;height:64px;background:#e6f5ee;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
        <i class="fas fa-shield-alt fa-2x text-green"></i>
      </div>
      <h3 class="fw-700">2FA is Active</h3>
      <p class="text-muted">Your account is protected with time-based one-time passwords.</p>
      <div class="d-flex flex-column gap-2 mt-3">
        <a href="<?= $returnUrl ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Profile</a>
        <button class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#disableForm">
          <i class="fas fa-shield-virus me-1"></i>Disable 2FA
        </button>
      </div>
      <!-- Disable collapse -->
      <div class="collapse mt-3 text-start" id="disableForm">
        <div class="card border-danger">
          <div class="card-body">
            <p class="small text-danger fw-600 mb-2"><i class="fas fa-exclamation-triangle me-1"></i>Disabling 2FA reduces your account security.</p>
            <form method="POST">
              <input type="hidden" name="action" value="disable_2fa">
              <div class="mb-3">
                <label class="form-label small">Confirm your current password</label>
                <input type="password" name="confirm_password" class="form-control form-control-sm" required placeholder="Your password">
              </div>
              <button type="submit" class="btn btn-danger btn-sm w-100">Confirm Disable</button>
            </form>
          </div>
        </div>
      </div>
      <?php else: ?>
      <div style="width:64px;height:64px;background:#fff5f5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
        <i class="fas fa-shield fa-2x text-muted"></i>
      </div>
      <h3 class="fw-700">2FA Not Enabled</h3>
      <p class="text-muted">Protect your account with an authenticator app (Google Authenticator, Authy, etc.).</p>
      <form method="POST" class="mt-3">
        <input type="hidden" name="action" value="begin_setup">
        <button type="submit" class="btn btn-primary w-100 py-2 fw-600">
          <i class="fas fa-mobile-alt me-2"></i>Enable Two-Factor Auth
        </button>
      </form>
      <div class="mt-3">
        <a href="<?= $returnUrl ?>" class="text-muted small"><i class="fas fa-arrow-left me-1"></i>Back to Profile</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Setup: show QR ── -->
    <?php elseif ($step === 'setup' && $setupSecret): ?>
    <h3 class="fw-700 text-center mb-1">Scan QR Code</h3>
    <p class="text-muted text-center small mb-3">Open your authenticator app and scan the code below.</p>

    <div class="text-center mb-3">
      <img src="<?= e($qrUrl) ?>" alt="2FA QR Code" width="200" height="200" class="rounded border p-2">
    </div>

    <div class="mb-3">
      <label class="form-label small fw-600">Can't scan? Enter this key manually:</label>
      <div class="input-group">
        <input type="text" class="form-control form-control-sm font-monospace" id="secretKey"
               value="<?= e(chunk_split($setupSecret, 4, ' ')) ?>" readonly>
        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="copyKey()">
          <i class="fas fa-copy"></i>
        </button>
      </div>
    </div>

    <hr class="my-3">
    <p class="small text-muted text-center mb-3">Enter the 6-digit code from the app to confirm setup:</p>

    <form method="POST" id="confirmForm">
      <input type="hidden" name="action" value="confirm_setup">
      <div class="mb-3">
        <input type="text" name="code" id="codeInput"
               class="form-control text-center fw-700"
               style="letter-spacing:.4rem;font-size:1.5rem;font-family:monospace"
               maxlength="6" inputmode="numeric" pattern="\d{6}"
               placeholder="000000" required autofocus autocomplete="one-time-code">
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-600">
        <i class="fas fa-check me-2"></i>Confirm &amp; Activate
      </button>
    </form>
    <div class="text-center mt-3">
      <a href="2fa-setup.php" class="text-muted small">Start over</a>
    </div>
    <?php endif; ?>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
<?php if ($step === 'setup'): ?>
document.getElementById('codeInput').addEventListener('input', function() {
  this.value = this.value.replace(/\D/g,'').substring(0,6);
  if (this.value.length === 6) document.getElementById('confirmForm').submit();
});
function copyKey() {
  const val = document.getElementById('secretKey').value.replace(/\s/g,'');
  navigator.clipboard.writeText(val).then(() => alert('Secret key copied!'));
}
<?php endif; ?>
</script>
</body>
</html>
