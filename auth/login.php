<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    redirect(($_SESSION['user_role'] === 'super_admin') ? APP_URL . '/admin/index.php' : APP_URL . '/client/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT u.*, o.name as org_name FROM users u LEFT JOIN organizations o ON u.org_id = o.id WHERE u.email = ? AND u.status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['org_id']     = $user['org_id'];
            $_SESSION['org_name']   = $user['org_name'] ?? '';

            // Update last login
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

            if ($remember) {
                $token = generateToken();
                setcookie('remember_token', $token, time() + 86400 * 30, '/', '', true, true);
                $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?")->execute([$token, $user['id']]);
            }

            setFlash('success', 'Welcome back, ' . $user['name'] . '!');
            redirect($user['role'] === 'super_admin' ? APP_URL . '/admin/index.php' : APP_URL . '/client/index.php');
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — <?= APP_NAME ?></title>
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

    <!-- Module badges -->
    <div class="d-flex flex-wrap gap-2 mb-4">
      <?php
      $badges = ['Accounting','CRM','HRM','Hotel','SACCO','School','POS','Rental'];
      foreach($badges as $b):
      ?>
      <span class="badge" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.8);font-size:.75rem;padding:.4rem .8rem;border-radius:50px">
        <?= $b ?>
      </span>
      <?php endforeach; ?>
      <span class="badge" style="background:var(--green);font-size:.75rem;padding:.4rem .8rem;border-radius:50px">+12 More</span>
    </div>

    <div class="d-flex gap-3">
      <?php
      $stats = [['500+','Businesses'],['20','Modules'],['99.9%','Uptime']];
      foreach($stats as $s): ?>
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
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= e($error) ?></div>
    <?php endif; ?>

    <?= flashAlert() ?>

    <form method="POST" data-loading>
      <div class="mb-3">
        <label class="form-label">Email Address</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-envelope text-muted"></i></span>
          <input type="email" name="email" class="form-control" placeholder="you@company.com"
                 value="<?= isset($_POST['email']) ? e($_POST['email']) : '' ?>" required autofocus>
        </div>
      </div>

      <div class="mb-3">
        <div class="d-flex justify-content-between">
          <label class="form-label">Password</label>
          <a href="#" class="text-green small">Forgot password?</a>
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
          <label class="form-check-label small" for="remember">Remember me</label>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 py-2 fw-600">
        <i class="fas fa-sign-in-alt me-2"></i> Sign In
      </button>
    </form>

    <div class="text-center mt-4">
      <p class="text-muted small">Don't have an account?
        <a href="<?= APP_URL ?>/auth/register.php" class="text-green fw-600">Create one free</a>
      </p>
      <a href="<?= APP_URL ?>" class="text-muted small"><i class="fas fa-arrow-left me-1"></i> Back to home</a>
    </div>

    <!-- Demo credentials -->
    <div class="mt-4 p-3 rounded" style="background:var(--green-pale);border:1px dashed var(--green)">
      <div class="small fw-700 text-green mb-1"><i class="fas fa-info-circle me-1"></i> Demo Access</div>
      <div class="small text-muted">Admin: <strong>admin@shanfix.com</strong> / <strong>Admin@2024</strong></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
function togglePassword() {
  const input = document.getElementById('passwordInput');
  const icon  = document.getElementById('eyeIcon');
  if (input.type === 'password') { input.type = 'text'; icon.classList.replace('fa-eye','fa-eye-slash'); }
  else { input.type = 'password'; icon.classList.replace('fa-eye-slash','fa-eye'); }
}
</script>
</body>
</html>
