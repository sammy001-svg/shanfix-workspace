<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) redirect(APP_URL . '/client/index.php');

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Always show success message — don't leak whether email exists
        $user = $pdo->prepare("SELECT id, name FROM users WHERE email=? AND status='active' LIMIT 1");
        $user->execute([$email]);
        $user = $user->fetch();

        if ($user) {
            // Invalidate any existing unused tokens for this email
            $pdo->prepare("UPDATE password_resets SET used=1 WHERE email=? AND used=0")->execute([$email]);

            // Generate a secure token (64 hex chars)
            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?,?,?)")
                ->execute([$email, $token, $expiresAt]);

            // Send email
            $resetUrl = APP_URL . '/auth/reset-password.php?token=' . $token;
            require_once __DIR__ . '/../includes/mailer.php';
            mailer()->send(
                $email,
                'Reset Your Password — ' . APP_NAME,
                "
                <div style='font-family:system-ui,sans-serif;max-width:560px;margin:0 auto'>
                  <div style='background:#0B2D4E;padding:20px 28px;border-radius:8px 8px 0 0;text-align:center'>
                    <span style='color:white;font-size:1.1rem;font-weight:800'>" . APP_NAME . "</span>
                  </div>
                  <div style='background:#fff;padding:32px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px'>
                    <h2 style='color:#0B2D4E;margin-top:0'>Password Reset Request</h2>
                    <p>Hi <strong>" . htmlspecialchars($user['name']) . "</strong>,</p>
                    <p>We received a request to reset your password. Click the button below to create a new one:</p>
                    <p style='text-align:center;margin:28px 0'>
                      <a href='{$resetUrl}' style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:700;display:inline-block'>
                        Reset My Password
                      </a>
                    </p>
                    <p style='font-size:.85rem;color:#64748b'>This link expires in <strong>1 hour</strong>. If you did not request a password reset, please ignore this email — your account is safe.</p>
                    <p style='font-size:.75rem;color:#94a3b8;word-break:break-all'>Or copy this URL: {$resetUrl}</p>
                    <hr style='border:none;border-top:1px solid #e2e8f0'>
                    <p style='font-size:.75rem;color:#94a3b8;margin:0'>" . APP_NAME . " Security Team</p>
                  </div>
                </div>"
            );
        }

        $sent = true; // Always show success regardless of whether email was found
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — <?= APP_NAME ?></title>
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

    <?php if ($sent): ?>
    <div class="text-center py-2">
      <div style="width:64px;height:64px;background:#e6f5ee;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
        <i class="fas fa-envelope-open-text fa-2x text-green"></i>
      </div>
      <h3 class="fw-700">Check Your Email</h3>
      <p class="text-muted">If an account exists for <strong><?= e($_POST['email'] ?? '') ?></strong>, we've sent a password reset link. It expires in 1 hour.</p>
      <p class="text-muted small">Didn't receive it? Check your spam folder or try again.</p>
      <a href="forgot-password.php" class="btn btn-outline-secondary btn-sm me-2">Try Again</a>
      <a href="login.php" class="btn btn-primary btn-sm">Back to Login</a>
    </div>
    <?php else: ?>
    <h2 class="auth-title">Forgot Password?</h2>
    <p class="auth-subtitle">Enter your email and we'll send you a reset link.</p>

    <?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="mb-4">
        <label class="form-label">Email Address</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-envelope text-muted"></i></span>
          <input type="email" name="email" class="form-control" placeholder="you@company.com"
                 value="<?= isset($_POST['email']) ? e($_POST['email']) : '' ?>" required autofocus>
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2 fw-600">
        <i class="fas fa-paper-plane me-2"></i>Send Reset Link
      </button>
    </form>

    <div class="text-center mt-4">
      <a href="login.php" class="text-muted small"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
    </div>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
