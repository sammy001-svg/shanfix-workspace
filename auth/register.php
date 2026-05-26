<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    redirect(APP_URL . '/client/index.php');
}

$errors = [];
$step   = 1;

// Fetch modules and plans
$stmt = $pdo->query("SELECT * FROM modules WHERE status='active' ORDER BY sort_order");
$allModules = $stmt->fetchAll();

$stmt = $pdo->query("SELECT * FROM subscription_plans WHERE status='active' ORDER BY price_monthly");
$plans = $stmt->fetchAll();

$planId = (int)($_GET['plan'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            redirect(APP_URL . '/client/onboarding.php');

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
<style>
/* 3-Step Wizard Styling */
.wizard-steps-container {
  display: flex;
  justify-content: space-between;
  align-items: center;
  position: relative;
  margin-bottom: 2rem;
}
.wizard-steps-container::before {
  content: '';
  position: absolute;
  top: 50%;
  left: 0;
  right: 0;
  height: 2px;
  background: var(--gray-200, #e2e8f0);
  z-index: 1;
  transform: translateY(-50%);
}
.wizard-progress-bar {
  position: absolute;
  top: 50%;
  left: 0;
  height: 2px;
  background: var(--green, #1A8A4E);
  z-index: 1;
  transform: translateY(-50%);
  transition: width 0.3s ease;
  width: 0%;
}
.step-node {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: #fff;
  border: 2px solid var(--gray-300, #cbd5e1);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  z-index: 2;
  transition: all 0.3s ease;
  color: var(--gray-500, #64748b);
  position: relative;
}
.step-node.active {
  border-color: var(--green, #1A8A4E);
  color: var(--green, #1A8A4E);
  box-shadow: 0 0 0 4px rgba(26, 138, 78, 0.15);
  font-weight: 800;
}
.step-node.completed {
  background: var(--green, #1A8A4E);
  border-color: var(--green, #1A8A4E);
  color: #fff;
}
.step-node-label {
  position: absolute;
  top: 45px;
  font-size: 0.72rem;
  font-weight: 600;
  white-space: nowrap;
  color: var(--gray-500, #64748b);
}
.step-node.active .step-node-label {
  color: var(--navy, #0B2D4E);
  font-weight: 700;
}
.step-node.completed .step-node-label {
  color: var(--green, #1A8A4E);
}
.wizard-panel {
  display: none;
}
.wizard-panel.active {
  display: block;
  animation: fadeIn 0.4s ease;
}
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}
.module-select-card {
  border: 2px solid var(--gray-200, #e2e8f0);
  background: #fff;
}
.module-select-card.selected {
  background: #E6F5EE;
  border-color: #1A8A4E;
}
.plan-option {
  border: 2px solid var(--gray-200, #e2e8f0);
  background: #fff;
}
.plan-option.selected {
  border-color: #1A8A4E;
  background: #E6F5EE;
}
</style>
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
    <p class="auth-subtitle">Set up your account in 3 simple steps</p>

    <!-- Wizard Steps Indicator -->
    <div class="wizard-steps-container">
      <div class="wizard-progress-bar" id="wizardProgressBar"></div>
      
      <div class="step-node active" id="node1">
        1
        <span class="step-node-label">Business Info</span>
      </div>
      <div class="step-node" id="node2">
        2
        <span class="step-node-label">Select Modules</span>
      </div>
      <div class="step-node" id="node3">
        3
        <span class="step-node-label">Credentials</span>
      </div>
    </div>

    <?php if ($errors): ?>
    <div class="alert alert-danger" id="backendErrorsAlert">
      <strong><i class="fas fa-exclamation-triangle me-2"></i>Please fix the following:</strong>
      <ul class="mb-0 mt-2">
        <?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <!-- Client-side Validation Errors Container -->
    <div class="alert alert-danger d-none" id="wizardErrorAlert">
      <strong><i class="fas fa-exclamation-triangle me-2"></i>Please fix the following:</strong>
      <ul class="mb-0 mt-2" id="wizardErrorList"></ul>
    </div>

    <form method="POST" data-loading id="registerForm">
      
      <!-- STEP 1: BUSINESS DETAIL -->
      <div class="wizard-panel active" id="panelStep1">
        <div class="mb-4 p-3 rounded" style="background:var(--gray-50);border:1px solid var(--gray-200)">
          <div class="fw-700 text-navy mb-3"><i class="fas fa-building me-2 text-green"></i>Business Information</div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Business / Organization Name *</label>
              <input type="text" id="inputOrgName" name="org_name" class="form-control" placeholder="e.g. Sunrise Academy, Umoja SACCO" value="<?= isset($_POST['org_name']) ? e($_POST['org_name']) : '' ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Your Full Name *</label>
              <input type="text" id="inputName" name="name" class="form-control" placeholder="John Doe" value="<?= isset($_POST['name']) ? e($_POST['name']) : '' ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone Number</label>
              <input type="tel" id="inputPhone" name="phone" class="form-control" placeholder="+254 700 000 000" value="<?= isset($_POST['phone']) ? e($_POST['phone']) : '' ?>">
            </div>
          </div>
        </div>

        <div class="mb-4 p-3 rounded" style="background:var(--gray-50);border:1px solid var(--gray-200)">
          <div class="fw-700 text-navy mb-3"><i class="fas fa-layer-group me-2 text-green"></i>Subscription Plan</div>
          <div class="row g-2">
            <?php foreach($plans as $p): ?>
            <div class="col-md-4">
              <label class="w-100" style="cursor:pointer">
                <input type="radio" name="plan_id" value="<?= $p['id'] ?>" class="d-none plan-radio"
                       <?= ($planId === $p['id'] || ($planId === 0 && $p['is_popular'])) ? 'checked' : '' ?>>
                <div class="plan-option p-3 rounded text-center" style="transition:all .2s">
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

        <div class="d-flex justify-content-end mt-4">
          <button type="button" class="btn btn-primary px-4 py-2 fw-700" onclick="goToStep(2)">
            Next: Select Modules <i class="fas fa-arrow-right ms-2"></i>
          </button>
        </div>
      </div>

      <!-- STEP 2: SELECT MODULES -->
      <div class="wizard-panel" id="panelStep2">
        <div class="mb-4">
          <div class="fw-700 text-navy mb-1"><i class="fas fa-puzzle-piece me-2 text-green"></i>Select Your Modules *</div>
          <p class="text-muted small mb-3">Choose the modules your business needs. You can add more anytime.</p>
          <div class="row g-2" id="modulesGrid">
            <?php foreach($allModules as $m): ?>
            <div class="col-6 col-md-4">
              <div class="module-select-card p-2 rounded cursor-pointer" data-price="<?= $m['monthly_price'] ?>"
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
          <div class="mt-3 d-flex align-items-center gap-2">
            <span class="text-muted small">Estimated cost:</span>
            <strong class="text-green" id="totalPrice">KES 0/month</strong>
            <span class="text-muted small">(free during 14-day trial)</span>
          </div>
        </div>

        <div class="d-flex justify-content-between mt-4">
          <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-700" onclick="goToStep(1)">
            <i class="fas fa-arrow-left me-2"></i> Back
          </button>
          <button type="button" class="btn btn-primary px-4 py-2 fw-700" onclick="goToStep(3)">
            Next: Set Credentials <i class="fas fa-arrow-right ms-2"></i>
          </button>
        </div>
      </div>

      <!-- STEP 3: USERNAME & PASSWORD -->
      <div class="wizard-panel" id="panelStep3">
        <div class="mb-4 p-3 rounded" style="background:var(--gray-50);border:1px solid var(--gray-200)">
          <div class="fw-700 text-navy mb-3"><i class="fas fa-key me-2 text-green"></i>Account Credentials</div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Work Email Address *</label>
              <input type="email" id="inputEmail" name="email" class="form-control" placeholder="you@company.com" value="<?= isset($_POST['email']) ? e($_POST['email']) : '' ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Password *</label>
              <input type="password" id="inputPassword" name="password" class="form-control" placeholder="Min. 8 characters" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Confirm Password *</label>
              <input type="password" id="inputPassword2" name="password2" class="form-control" placeholder="Repeat password" required>
            </div>
          </div>
        </div>

        <!-- Terms -->
        <div class="form-check mb-4">
          <input class="form-check-input" type="checkbox" id="terms" required>
          <label class="form-check-label small" for="terms">
            I agree to the <a href="#" class="text-green">Terms of Service</a> and <a href="#" class="text-green">Privacy Policy</a>
          </label>
        </div>

        <div class="d-flex justify-content-between mt-4">
          <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-700" onclick="goToStep(2)">
            <i class="fas fa-arrow-left me-2"></i> Back
          </button>
          <button type="submit" class="btn btn-success px-4 py-2 fw-700">
            <i class="fas fa-rocket me-2"></i> Launch Workspace
          </button>
        </div>
      </div>

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
let currentStep = 1;

function showValidationError(messages) {
  const alertBox = document.getElementById('wizardErrorAlert');
  const errorList = document.getElementById('wizardErrorList');
  errorList.innerHTML = '';
  messages.forEach(msg => {
    const li = document.createElement('li');
    li.textContent = msg;
    errorList.appendChild(li);
  });
  alertBox.classList.remove('d-none');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function clearValidationErrors() {
  document.getElementById('wizardErrorAlert').classList.add('d-none');
  const backendAlert = document.getElementById('backendErrorsAlert');
  if (backendAlert) {
    backendAlert.classList.add('d-none');
  }
}

function validateStep(step) {
  clearValidationErrors();
  let errors = [];
  
  if (step === 1) {
    const orgName = document.getElementById('inputOrgName').value.trim();
    const name = document.getElementById('inputName').value.trim();
    
    if (!orgName) errors.push('Business / Organization Name is required.');
    if (!name) errors.push('Your Full Name is required.');
  }
  
  if (step === 2) {
    const checkedModules = document.querySelectorAll('#modulesGrid input[type="checkbox"]:checked');
    if (checkedModules.length === 0) {
      errors.push('Please select at least one module to continue.');
    }
  }

  if (errors.length > 0) {
    showValidationError(errors);
    return false;
  }
  return true;
}

function goToStep(targetStep) {
  // If moving forward, validate current step first
  if (targetStep > currentStep) {
    if (!validateStep(currentStep)) return;
  } else {
    // Going back: just clear errors
    clearValidationErrors();
  }

  // Update panels
  document.querySelectorAll('.wizard-panel').forEach(panel => {
    panel.classList.remove('active');
  });
  document.getElementById('panelStep' + targetStep).classList.add('active');

  // Update indicator nodes
  for (let i = 1; i <= 3; i++) {
    const node = document.getElementById('node' + i);
    if (i < targetStep) {
      node.className = 'step-node completed';
    } else if (i === targetStep) {
      node.className = 'step-node active';
    } else {
      node.className = 'step-node';
    }
  }

  // Update Progress Bar width
  const progressBar = document.getElementById('wizardProgressBar');
  if (targetStep === 1) progressBar.style.width = '0%';
  if (targetStep === 2) progressBar.style.width = '50%';
  if (targetStep === 3) progressBar.style.width = '100%';

  currentStep = targetStep;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function toggleModule(card) {
  const cb = card.querySelector('input[type="checkbox"]');
  cb.checked = !cb.checked;
  if (cb.checked) {
    card.classList.add('selected');
  } else {
    card.classList.remove('selected');
  }
  updateTotal();
}

function updateTotal() {
  let total = 0;
  document.querySelectorAll('#modulesGrid input:checked').forEach(cb => {
    total += parseFloat(cb.closest('[data-price]').dataset.price || 0);
  });
  document.getElementById('totalPrice').textContent = 'KES ' + total.toLocaleString() + '/month';
}

// Plan radio styling
document.querySelectorAll('.plan-radio').forEach(radio => {
  radio.addEventListener('change', () => {
    document.querySelectorAll('.plan-option').forEach(o => { o.classList.remove('selected'); });
    if (radio.checked) {
      radio.nextElementSibling.classList.add('selected');
    }
  });
  // Init
  if (radio.checked) {
    radio.nextElementSibling.classList.add('selected');
  }
});

// Restore checked states & calculate total on page load
document.querySelectorAll('#modulesGrid input:checked').forEach(cb => {
  cb.closest('.module-select-card').classList.add('selected');
});
updateTotal();

// Form final submission validation
document.getElementById('registerForm').addEventListener('submit', function(e) {
  clearValidationErrors();
  let errors = [];

  const email = document.getElementById('inputEmail').value.trim();
  const password = document.getElementById('inputPassword').value;
  const password2 = document.getElementById('inputPassword2').value;
  const termsChecked = document.getElementById('terms').checked;

  if (!email || !email.includes('@')) {
    errors.push('A valid work email address is required.');
  }
  if (password.length < 8) {
    errors.push('Password must be at least 8 characters long.');
  }
  if (password !== password2) {
    errors.push('Passwords do not match.');
  }
  if (!termsChecked) {
    errors.push('You must agree to the Terms of Service and Privacy Policy.');
  }

  if (errors.length > 0) {
    e.preventDefault();
    showValidationError(errors);
  }
});
</script>
</body>
</html>
