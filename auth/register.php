<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    redirect(APP_URL . '/client/index.php');
}

$errors = [];
$step   = (int)($_GET['step'] ?? 1);

// Fetch modules and plans
$stmt = $pdo->query("SELECT * FROM modules WHERE status='active' ORDER BY sort_order");
$allModules = $stmt->fetchAll();

$stmt = $pdo->query("SELECT * FROM subscription_plans WHERE status='active' ORDER BY price_monthly");
$plans = $stmt->fetchAll();

$planId = (int)($_GET['plan'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Step 1: account details ──────────────────────────────────
    $orgName   = sanitize($_POST['org_name'] ?? '');
    $name      = sanitize($_POST['name']     ?? '');
    $email     = trim($_POST['email']        ?? '');
    $phone     = sanitize($_POST['phone']    ?? '');
    $password  = $_POST['password']          ?? '';
    $password2 = $_POST['password2']         ?? '';
    $plan      = (int)($_POST['plan_id']     ?? 0);
    $modules   = $_POST['modules']           ?? [];
    $billing   = $_POST['billing_cycle']     ?? 'monthly';

    // Validation
    if (!$orgName) $errors[] = 'Business name is required.';
    if (!$name)    $errors[] = 'Your full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $password2) $errors[] = 'Passwords do not match.';
    if (empty($modules)) $errors[] = 'Please select at least one module.';

    // Check email uniqueness
    if (!$errors) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors[] = 'This email is already registered. Please login instead.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            // Create organization
            $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $orgName)) . '-' . substr(md5(microtime()), 0, 6);
            $stmt = $pdo->prepare("INSERT INTO organizations (name, email, phone, slug) VALUES (?,?,?,?)");
            $stmt->execute([$orgName, $email, $phone, $slug]);
            $orgId = $pdo->lastInsertId();

            // Create admin user
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("INSERT INTO users (org_id, name, email, password, phone, role, status) VALUES (?,?,?,?,?,'client_admin','active')");
            $stmt->execute([$orgId, $name, $email, $hashed, $phone]);
            $userId = $pdo->lastInsertId();

            // Create subscription (trial)
            $trialEnd = date('Y-m-d H:i:s', strtotime('+14 days'));
            $stmt = $pdo->prepare("INSERT INTO subscriptions (org_id, plan_id, billing_cycle, amount, status, trial_ends_at, starts_at) VALUES (?,?,?,?,?,?,NOW())");
            $stmt->execute([$orgId, $plan ?: null, $billing, 0, 'trial', $trialEnd]);
            $subId = $pdo->lastInsertId();

            // Attach modules
            $stmtMod = $pdo->prepare("SELECT id FROM modules WHERE slug = ? AND status='active'");
            $stmtIns = $pdo->prepare("INSERT INTO subscription_modules (subscription_id, module_id) VALUES (?,?)");
            foreach ($modules as $slug) {
                $stmtMod->execute([$slug]);
                $mod = $stmtMod->fetch();
                if ($mod) $stmtIns->execute([$subId, $mod['id']]);
            }

            $pdo->commit();

            // Auto-login
            $_SESSION['user_id']    = $userId;
            $_SESSION['user_name']  = $name;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role']  = 'client_admin';
            $_SESSION['org_id']     = $orgId;
            $_SESSION['org_name']   = $orgName;

            setFlash('success', "Welcome to {$orgName}'s workspace! Your 14-day trial has started.");
            redirect(APP_URL . '/client/index.php');

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account — <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body">

<div class="auth-left d-none d-lg-flex flex-column justify-content-center">
  <div class="position-relative z-2 text-white">
    <div class="mb-3">
      <div style="width:56px;height:56px;background:rgba(255,255,255,.15);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin-bottom:1rem">
        <i class="fas fa-cubes"></i>
      </div>
      <h2 class="fw-800 mb-1"><?= APP_NAME ?></h2>
      <p class="text-white-50 small">Start your 14-day free trial today</p>
    </div>
    <div class="d-flex flex-column gap-2">
      <?php
      $perks = [
        ['fas fa-check-circle','No credit card required'],
        ['fas fa-check-circle','Full access to selected modules'],
        ['fas fa-check-circle','Unlimited data during trial'],
        ['fas fa-check-circle','M-Pesa payment integration'],
        ['fas fa-check-circle','Cancel anytime, no commitments'],
        ['fas fa-check-circle','Local Kenyan support team'],
      ];
      foreach($perks as $p): ?>
      <div class="d-flex align-items-center gap-2">
        <i class="<?= $p[0] ?>" style="color:var(--green-light)"></i>
        <span><?= $p[1] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="auth-right" style="overflow-y:auto;padding:2rem">
  <div style="width:100%;max-width:580px;margin:auto">
    <div class="auth-logo">
      <div class="logo-box"><i class="fas fa-cubes"></i></div>
      <div class="logo-text"><?= APP_NAME ?></div>
    </div>

    <h2 class="auth-title">Create Your Workspace</h2>
    <p class="auth-subtitle">Set up your account and choose your modules</p>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <strong><i class="fas fa-exclamation-triangle me-2"></i>Please fix the following:</strong>
      <ul class="mb-0 mt-2">
        <?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <form method="POST" data-loading id="registerForm">
      <!-- Business Info -->
      <div class="mb-4 p-3 rounded" style="background:var(--gray-50);border:1px solid var(--gray-200)">
        <div class="fw-700 text-navy mb-3"><i class="fas fa-building me-2 text-green"></i>Business Information</div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Business / Organization Name *</label>
            <input type="text" name="org_name" class="form-control" placeholder="e.g. Sunrise Academy, Umoja SACCO" value="<?= isset($_POST['org_name']) ? e($_POST['org_name']) : '' ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Your Full Name *</label>
            <input type="text" name="name" class="form-control" placeholder="John Doe" value="<?= isset($_POST['name']) ? e($_POST['name']) : '' ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone Number</label>
            <input type="tel" name="phone" class="form-control" placeholder="+254 700 000 000" value="<?= isset($_POST['phone']) ? e($_POST['phone']) : '' ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Work Email Address *</label>
            <input type="email" name="email" class="form-control" placeholder="you@company.com" value="<?= isset($_POST['email']) ? e($_POST['email']) : '' ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Password *</label>
            <input type="password" name="password" class="form-control" placeholder="Min. 8 characters" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Confirm Password *</label>
            <input type="password" name="password2" class="form-control" placeholder="Repeat password" required>
          </div>
        </div>
      </div>

      <!-- Module Selection -->
      <div class="mb-4">
        <div class="fw-700 text-navy mb-1"><i class="fas fa-puzzle-piece me-2 text-green"></i>Select Your Modules *</div>
        <p class="text-muted small mb-3">Choose the modules your business needs. You can add more anytime.</p>
        <div class="row g-2" id="modulesGrid">
          <?php foreach($allModules as $m): ?>
          <div class="col-6 col-md-4">
            <div class="module-select-card p-2 rounded border cursor-pointer" data-price="<?= $m['monthly_price'] ?>"
                 style="transition:all .2s;cursor:pointer"
                 onclick="toggleModule(this)">
              <input type="checkbox" name="modules[]" value="<?= e($m['slug']) ?>" class="d-none"
                     <?= (isset($_POST['modules']) && in_array($m['slug'], $_POST['modules'])) ? 'checked' : '' ?>>
              <div class="d-flex align-items-center gap-2">
                <div style="width:32px;height:32px;border-radius:8px;background:<?= e($m['color']) ?>1a;color:<?= e($m['color']) ?>;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0">
                  <i class="<?= e($m['icon']) ?>"></i>
                </div>
                <div>
                  <div style="font-size:.78rem;font-weight:600;color:var(--navy);line-height:1.2"><?= e($m['name']) ?></div>
                  <div style="font-size:.65rem;color:var(--gray-400)">KES <?= number_format($m['monthly_price']) ?>/mo</div>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-2 d-flex align-items-center gap-2">
          <span class="text-muted small">Estimated cost:</span>
          <strong class="text-green" id="totalPrice">KES 0/month</strong>
          <span class="text-muted small">(free during 14-day trial)</span>
        </div>
      </div>

      <!-- Plan -->
      <div class="mb-4 p-3 rounded" style="background:var(--gray-50);border:1px solid var(--gray-200)">
        <div class="fw-700 text-navy mb-3"><i class="fas fa-layer-group me-2 text-green"></i>Subscription Plan</div>
        <div class="row g-2">
          <?php foreach($plans as $p): ?>
          <div class="col-md-4">
            <label class="w-100" style="cursor:pointer">
              <input type="radio" name="plan_id" value="<?= $p['id'] ?>" class="d-none plan-radio"
                     <?= ($planId === $p['id'] || ($planId === 0 && $p['is_popular'])) ? 'checked' : '' ?>>
              <div class="plan-option p-3 rounded border text-center" style="transition:all .2s">
                <div class="fw-700 text-navy"><?= e($p['name']) ?></div>
                <div style="font-size:1.1rem;font-weight:800;color:var(--green)">KES <?= number_format($p['price_monthly']) ?><span class="text-muted fw-400" style="font-size:.7rem">/mo</span></div>
                <div class="text-muted" style="font-size:.7rem"><?= $p['max_users'] ?> users · <?= $p['max_modules'] ?> modules</div>
              </div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-2">
          <label class="form-label small fw-600">Billing Cycle</label>
          <select name="billing_cycle" class="form-select form-select-sm w-auto">
            <option value="monthly">Monthly Billing</option>
            <option value="annual">Annual Billing (Save 20%)</option>
          </select>
        </div>
      </div>

      <!-- Terms -->
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="terms" required>
        <label class="form-check-label small" for="terms">
          I agree to the <a href="#" class="text-green">Terms of Service</a> and <a href="#" class="text-green">Privacy Policy</a>
        </label>
      </div>

      <button type="submit" class="btn btn-primary w-100 py-2 fw-700 btn-lg">
        <i class="fas fa-rocket me-2"></i> Create My Workspace — Start Free Trial
      </button>
    </form>

    <div class="text-center mt-3">
      <p class="text-muted small">Already have an account?
        <a href="<?= APP_URL ?>/auth/login.php" class="text-green fw-600">Sign in here</a>
      </p>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleModule(card) {
  const cb = card.querySelector('input[type="checkbox"]');
  cb.checked = !cb.checked;
  if (cb.checked) {
    card.style.background = '#E6F5EE';
    card.style.borderColor = '#1A8A4E';
  } else {
    card.style.background = '';
    card.style.borderColor = '';
  }
  updateTotal();
}

function updateTotal() {
  let total = 0;
  document.querySelectorAll('.module-select-card input:checked').forEach(cb => {
    total += parseFloat(cb.closest('[data-price]').dataset.price || 0);
  });
  document.getElementById('totalPrice').textContent = 'KES ' + total.toLocaleString() + '/month';
}

// Plan radio styling
document.querySelectorAll('.plan-radio').forEach(radio => {
  radio.addEventListener('change', () => {
    document.querySelectorAll('.plan-option').forEach(o => { o.style.borderColor=''; o.style.background=''; });
    if (radio.checked) {
      const opt = radio.nextElementSibling;
      opt.style.borderColor = '#1A8A4E';
      opt.style.background = '#E6F5EE';
    }
  });
  // Init
  if (radio.checked) {
    const opt = radio.nextElementSibling;
    opt.style.borderColor = '#1A8A4E';
    opt.style.background = '#E6F5EE';
  }
});

// Restore checked states
document.querySelectorAll('.module-select-card input:checked').forEach(cb => {
  const card = cb.closest('.module-select-card');
  card.style.background = '#E6F5EE';
  card.style.borderColor = '#1A8A4E';
});
updateTotal();
</script>
</body>
</html>
