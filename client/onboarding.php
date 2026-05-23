<?php
/**
 * Onboarding Wizard — shown once to new client admins after registration.
 * Does NOT use header-client.php (standalone layout, no sidebar).
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Must be logged in as a client
if (!isLoggedIn()) {
    redirect(APP_URL . '/auth/login.php');
}

$user   = currentUser();
$orgId  = (int)$user['org_id'];
$userId = (int)$user['id'];

// Already done? Go to dashboard
$row = $pdo->prepare("SELECT is_onboarded FROM users WHERE id=?");
$row->execute([$userId]);
if ((bool)$row->fetchColumn()) {
    redirect(APP_URL . '/client/index.php');
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── AJAX handlers ─────────────────────────────────────────────────
$isAjax = ($_POST['ajax'] ?? '') === '1';
if ($isAjax) {
    header('Content-Type: application/json');

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Security token mismatch.']);
        exit;
    }

    $step = $_POST['step'] ?? '';

    // ── Step 1: Save org profile ──────────────────────────────────
    if ($step === 'profile') {
        $phone   = sanitize($_POST['phone']   ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city    = sanitize($_POST['city']    ?? '');
        $country = sanitize($_POST['country'] ?? '');

        // Handle logo upload
        $logoSet = '';
        $logoParams = [];
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'] ?? '', PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'])) {
                $dir = __DIR__ . '/../assets/uploads/logos/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'org-' . $orgId . '-' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $dir . $fname)) {
                    $logoSet    = ', logo=?';
                    $logoParams = ['assets/uploads/logos/' . $fname];
                }
            }
        }

        $params = array_merge([$phone, $address, $city, $country], $logoParams, [$orgId]);
        $pdo->prepare("UPDATE organizations SET phone=?, address=?, city=?, country=?{$logoSet} WHERE id=?")
            ->execute($params);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── Step 2: Invite team members ───────────────────────────────
    if ($step === 'team') {
        require_once __DIR__ . '/../includes/mailer.php';
        $names  = $_POST['member_name']  ?? [];
        $emails = $_POST['member_email'] ?? [];
        $roles  = $_POST['member_role']  ?? [];
        $invited = 0;

        for ($i = 0; $i < min(count($emails), 3); $i++) {
            $mEmail = filter_var(trim($emails[$i] ?? ''), FILTER_VALIDATE_EMAIL);
            $mName  = sanitize($names[$i] ?? '');
            $mRole  = in_array($roles[$i] ?? '', ['client_admin', 'client_user']) ? $roles[$i] : 'client_user';
            if (!$mEmail || !$mName) continue;

            // Skip if already registered
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $chk->execute([$mEmail]);
            if ($chk->fetch()) continue;

            // Create user with temp password
            $tempPass = substr(str_shuffle('abcdefghijkmnpqrstuvwxyz23456789'), 0, 10);
            $hashed   = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("INSERT INTO users (org_id,name,email,password,role,status,is_onboarded) VALUES (?,?,?,?,?,'active',1)")
                ->execute([$orgId, $mName, $mEmail, $hashed, $mRole]);

            // Send invite email
            $orgName  = htmlspecialchars($user['org_name'], ENT_QUOTES);
            $invBody  = "
                <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
                  <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
                    <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
                  </div>
                  <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
                    <h2 style='color:#0B2D4E;margin-top:0'>You've Been Invited!</h2>
                    <p>Hi <strong>" . htmlspecialchars($mName, ENT_QUOTES) . "</strong>,</p>
                    <p><strong>{$orgName}</strong> has added you to their workspace on " . APP_NAME . ".</p>
                    <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                      <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Email</td><td style='padding:8px;border:1px solid #eee'>" . htmlspecialchars($mEmail, ENT_QUOTES) . "</td></tr>
                      <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Temporary Password</td><td style='padding:8px;border:1px solid #eee;font-weight:700;font-family:monospace'>{$tempPass}</td></tr>
                      <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Role</td><td style='padding:8px;border:1px solid #eee'>" . ($mRole === 'client_admin' ? 'Administrator' : 'Team Member') . "</td></tr>
                    </table>
                    <p style='color:#e67e22;font-weight:600;font-size:.9rem'>Please login and change your password immediately.</p>
                    <div style='text-align:center;margin:24px 0'>
                      <a href='" . APP_URL . "/auth/login.php'
                         style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
                        Login Now &rarr;
                      </a>
                    </div>
                    <hr style='border:none;border-top:1px solid #eee;margin:24px 0'>
                    <p style='color:#999;font-size:.8rem;margin:0'>&copy; " . date('Y') . " " . APP_NAME . "</p>
                  </div>
                </div>";
            mailer()->send($mEmail, "You've been invited to {$orgName} — " . APP_NAME, $invBody);
            $invited++;
        }

        echo json_encode(['success' => true, 'invited' => $invited]);
        exit;
    }

    // ── Step 3: Complete onboarding ───────────────────────────────
    if ($step === 'complete') {
        $pdo->prepare("UPDATE users SET is_onboarded=1 WHERE id=?")->execute([$userId]);
        logActivity('onboarding_complete', 'client', "Onboarding completed by {$user['name']}");
        echo json_encode(['success' => true, 'redirect' => APP_URL . '/client/index.php']);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown step']);
    exit;
}

// ── Load org data for pre-fill ────────────────────────────────────
$orgRow = $pdo->prepare("SELECT * FROM organizations WHERE id=?");
$orgRow->execute([$orgId]);
$org = $orgRow->fetch() ?: [];

$countries = [
    'Kenya','Uganda','Tanzania','Rwanda','Ethiopia','Nigeria','Ghana','South Africa',
    'Egypt','Morocco','Senegal','Côte d\'Ivoire','Cameroon','Mozambique','Zambia',
    'Zimbabwe','Angola','United Kingdom','United States','Canada','Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Getting Started — <?= APP_NAME ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <style>
    :root {
      --navy: #0B2D4E;
      --green: #1A8A4E;
    }
    body {
      min-height: 100vh;
      background: linear-gradient(135deg, #0B2D4E 0%, #1A8A4E 100%);
      display: flex;
      flex-direction: column;
      font-family: 'Segoe UI', Arial, sans-serif;
    }

    /* ── Top bar ── */
    .wizard-topbar {
      padding: 16px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .wizard-brand {
      color: white;
      font-size: 1.25rem;
      font-weight: 800;
      letter-spacing: -.5px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .wizard-brand .icon {
      width: 36px; height: 36px;
      background: rgba(255,255,255,.15);
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem;
    }
    .wizard-welcome {
      color: rgba(255,255,255,.75);
      font-size: .9rem;
    }

    /* ── Progress stepper ── */
    .wizard-progress {
      display: flex;
      justify-content: center;
      gap: 0;
      padding: 0 32px 24px;
    }
    .step-item {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .step-item + .step-item::before {
      content: '';
      width: 64px;
      height: 2px;
      background: rgba(255,255,255,.3);
      transition: background .4s;
    }
    .step-item.done + .step-item::before,
    .step-item.active + .step-item::before {
      background: rgba(255,255,255,.7);
    }
    .step-num {
      width: 32px; height: 32px;
      border-radius: 50%;
      background: rgba(255,255,255,.2);
      border: 2px solid rgba(255,255,255,.4);
      color: white;
      font-size: .8rem;
      font-weight: 700;
      display: flex; align-items: center; justify-content: center;
      transition: all .3s;
    }
    .step-item.active .step-num {
      background: white;
      color: var(--navy);
      border-color: white;
    }
    .step-item.done .step-num {
      background: var(--green);
      border-color: var(--green);
    }
    .step-label {
      color: rgba(255,255,255,.6);
      font-size: .78rem;
      font-weight: 600;
    }
    .step-item.active .step-label { color: white; }
    .step-item.done .step-label   { color: rgba(255,255,255,.8); }

    /* ── Wizard card ── */
    .wizard-card {
      background: white;
      border-radius: 20px;
      padding: 40px;
      max-width: 640px;
      margin: 0 auto 48px;
      width: 100%;
      box-shadow: 0 20px 60px rgba(0,0,0,.25);
    }
    .wizard-card h2 {
      color: var(--navy);
      font-weight: 800;
      margin-bottom: 4px;
    }
    .wizard-card .subtitle {
      color: #64748b;
      margin-bottom: 28px;
      font-size: .95rem;
    }

    /* ── Step panels ── */
    .step-panel { display: none; }
    .step-panel.active { display: block; }

    /* ── Team invite rows ── */
    .invite-row {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 12px;
    }
    .invite-row-num {
      font-size: .75rem;
      font-weight: 700;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: .05em;
      margin-bottom: 10px;
    }

    /* ── Logo preview ── */
    .logo-preview {
      width: 80px; height: 80px;
      border-radius: 12px;
      border: 2px dashed #cbd5e1;
      display: flex; align-items: center; justify-content: center;
      background: #f8fafc;
      cursor: pointer;
      transition: all .2s;
      overflow: hidden;
    }
    .logo-preview:hover { border-color: var(--green); background: #f0fdf4; }
    .logo-preview img { width: 100%; height: 100%; object-fit: contain; }
    .logo-preview .placeholder { color: #94a3b8; font-size: 1.5rem; }

    /* ── Completion animation ── */
    @keyframes pop-in {
      0%   { transform: scale(0) rotate(-10deg); opacity: 0; }
      70%  { transform: scale(1.1) rotate(3deg); }
      100% { transform: scale(1) rotate(0); opacity: 1; }
    }
    .success-icon {
      width: 80px; height: 80px;
      background: linear-gradient(135deg, var(--green), #2dd4bf);
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 20px;
      animation: pop-in .5s cubic-bezier(.175,.885,.32,1.275) forwards;
      font-size: 2rem;
      color: white;
    }
    .checklist-item {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 10px 0;
      border-bottom: 1px solid #f1f5f9;
      font-size: .9rem;
    }
    .checklist-item:last-child { border-bottom: none; }
    .checklist-item .ci-icon {
      width: 24px; height: 24px;
      border-radius: 50%;
      background: #f0fdf4;
      color: var(--green);
      display: flex; align-items: center; justify-content: center;
      font-size: .7rem;
      flex-shrink: 0;
    }

    /* ── Buttons ── */
    .btn-wizard-next {
      background: linear-gradient(135deg, var(--navy), #1a5276);
      color: white;
      border: none;
      padding: 12px 32px;
      border-radius: 50px;
      font-weight: 700;
      font-size: 1rem;
      width: 100%;
      transition: all .2s;
    }
    .btn-wizard-next:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(11,45,78,.3); }
    .btn-wizard-next:disabled { opacity: .65; transform: none; }
    .btn-wizard-skip {
      color: #94a3b8;
      font-size: .85rem;
      cursor: pointer;
      background: none;
      border: none;
      padding: 8px;
      display: block;
      width: 100%;
      text-align: center;
      margin-top: 8px;
    }
    .btn-wizard-skip:hover { color: #64748b; }
  </style>
</head>
<body>

<!-- Top bar -->
<div class="wizard-topbar">
  <div class="wizard-brand">
    <div class="icon"><i class="fas fa-cubes"></i></div>
    <?= APP_NAME ?>
  </div>
  <div class="wizard-welcome">
    <i class="fas fa-user-circle me-1"></i><?= e($user['name']) ?>
  </div>
</div>

<!-- Step progress -->
<div class="wizard-progress">
  <div class="step-item active" id="si-1">
    <div class="step-num">1</div>
    <div class="step-label">Your Profile</div>
  </div>
  <div class="step-item" id="si-2">
    <div class="step-num">2</div>
    <div class="step-label">Invite Team</div>
  </div>
  <div class="step-item" id="si-3">
    <div class="step-num"><i class="fas fa-check" style="font-size:.65rem"></i></div>
    <div class="step-label">All Set!</div>
  </div>
</div>

<!-- Wizard card -->
<div class="container px-3">
<div class="wizard-card">

  <!-- ── Step 1: Profile ─────────────────────────────────────── -->
  <div class="step-panel active" id="step1">
    <h2>Welcome, <?= e(explode(' ', $user['name'])[0]) ?>! <span style="font-size:1.4rem">👋</span></h2>
    <p class="subtitle">Let's set up <strong><?= e($user['org_name']) ?></strong>'s workspace. This takes under 2 minutes.</p>

    <div class="mb-4">
      <label class="form-label fw-600">Company Logo</label>
      <div class="d-flex align-items-center gap-3">
        <div class="logo-preview" onclick="document.getElementById('logoFile').click()" id="logoPreview">
          <?php if (!empty($org['logo'])): ?>
            <img src="<?= APP_URL ?>/<?= e($org['logo']) ?>" alt="logo" id="logoImg">
          <?php else: ?>
            <span class="placeholder"><i class="fas fa-building"></i></span>
          <?php endif; ?>
        </div>
        <div>
          <div class="fw-600 small mb-1">Upload your company logo</div>
          <div class="text-muted" style="font-size:.8rem">PNG, JPG or SVG · Max 2 MB</div>
          <button type="button" class="btn btn-sm btn-outline-secondary mt-2"
                  onclick="document.getElementById('logoFile').click()">
            <i class="fas fa-upload me-1"></i>Choose File
          </button>
        </div>
        <input type="file" id="logoFile" accept="image/*" class="d-none" onchange="previewLogo(this)">
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-sm-6">
        <label class="form-label">Business Phone</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-phone"></i></span>
          <input type="tel" id="p_phone" class="form-control" value="<?= e($org['phone'] ?? '') ?>" placeholder="+254 700 000 000">
        </div>
      </div>
      <div class="col-sm-6">
        <label class="form-label">City</label>
        <input type="text" id="p_city" class="form-control" value="<?= e($org['city'] ?? '') ?>" placeholder="Nairobi">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label">Business Address</label>
      <input type="text" id="p_address" class="form-control" value="<?= e($org['address'] ?? '') ?>" placeholder="Street, Building, Floor">
    </div>

    <div class="mb-4">
      <label class="form-label">Country</label>
      <select id="p_country" class="form-select">
        <option value="">Select country…</option>
        <?php foreach ($countries as $c): ?>
          <option value="<?= e($c) ?>" <?= ($org['country'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <button class="btn btn-wizard-next" onclick="saveStep1(this)">
      <i class="fas fa-arrow-right me-2"></i>Save & Continue
    </button>
    <button class="btn-wizard-skip" onclick="goStep(2)">Skip for now →</button>
  </div>

  <!-- ── Step 2: Invite Team ──────────────────────────────────── -->
  <div class="step-panel" id="step2">
    <h2>Invite Your Team</h2>
    <p class="subtitle">Add colleagues to <?= e($user['org_name']) ?>. They'll get an email with login details. You can also do this later.</p>

    <div id="inviteRows">
      <?php for ($i = 1; $i <= 3; $i++): ?>
      <div class="invite-row">
        <div class="invite-row-num">Member <?= $i ?></div>
        <div class="row g-2">
          <div class="col-sm-5">
            <input type="text" name="member_name[]" class="form-control form-control-sm" placeholder="Full name">
          </div>
          <div class="col-sm-5">
            <input type="email" name="member_email[]" class="form-control form-control-sm" placeholder="Email address">
          </div>
          <div class="col-sm-2">
            <select name="member_role[]" class="form-select form-select-sm" title="Role">
              <option value="client_user">Member</option>
              <option value="client_admin">Admin</option>
            </select>
          </div>
        </div>
      </div>
      <?php endfor; ?>
    </div>

    <div class="alert alert-light border small text-muted mt-3 mb-4">
      <i class="fas fa-info-circle me-1 text-primary"></i>
      Each invited member will receive a temporary password by email. They can change it after logging in.
    </div>

    <button class="btn btn-wizard-next" onclick="saveStep2(this)">
      <i class="fas fa-paper-plane me-2"></i>Send Invites & Continue
    </button>
    <button class="btn-wizard-skip" onclick="goStep(3)">Skip — I'll invite later →</button>
  </div>

  <!-- ── Step 3: All Set ──────────────────────────────────────── -->
  <div class="step-panel text-center" id="step3">
    <div class="success-icon">
      <i class="fas fa-check"></i>
    </div>
    <h2>You're All Set!</h2>
    <p class="subtitle"><?= e($user['org_name']) ?>'s workspace is ready. Here's what to do next:</p>

    <div class="text-start mb-4">
      <div class="checklist-item">
        <div class="ci-icon"><i class="fas fa-check"></i></div>
        <div><strong>Explore your modules</strong> — access your tools from the sidebar</div>
      </div>
      <div class="checklist-item">
        <div class="ci-icon"><i class="fas fa-check"></i></div>
        <div><strong>Add your first record</strong> — create a customer, student, or inventory item</div>
      </div>
      <div class="checklist-item">
        <div class="ci-icon"><i class="fas fa-check"></i></div>
        <div><strong>Customize settings</strong> — add your business logo and contact details</div>
      </div>
      <div class="checklist-item">
        <div class="ci-icon"><i class="fas fa-check"></i></div>
        <div><strong>Invite your team</strong> — go to Team → Invite to add more members</div>
      </div>
      <div class="checklist-item">
        <div class="ci-icon"><i class="fas fa-check"></i></div>
        <div><strong>Upgrade before trial ends</strong> — your 14-day free trial has started</div>
      </div>
    </div>

    <button class="btn btn-wizard-next" onclick="completeOnboarding(this)">
      <i class="fas fa-rocket me-2"></i>Enter My Dashboard
    </button>
  </div>

</div><!-- .wizard-card -->
</div><!-- .container -->

<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

// ── Logo preview ──────────────────────────────────────────────────
function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const prev = document.getElementById('logoPreview');
    prev.innerHTML = '<img src="' + e.target.result + '" alt="logo" style="width:100%;height:100%;object-fit:contain">';
  };
  reader.readAsDataURL(input.files[0]);
}

// ── Step navigation ───────────────────────────────────────────────
function goStep(n) {
  document.querySelectorAll('.step-panel').forEach((p, i) => {
    p.classList.toggle('active', i + 1 === n);
  });
  for (let i = 1; i <= 3; i++) {
    const si = document.getElementById('si-' + i);
    if (!si) continue;
    si.classList.toggle('active', i === n);
    si.classList.toggle('done', i < n);
  }
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Step 1: Save profile ──────────────────────────────────────────
function saveStep1(btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving…';

  const fd = new FormData();
  fd.append('ajax',     '1');
  fd.append('_token',   CSRF_TOKEN);
  fd.append('step',     'profile');
  fd.append('phone',    document.getElementById('p_phone').value);
  fd.append('address',  document.getElementById('p_address').value);
  fd.append('city',     document.getElementById('p_city').value);
  fd.append('country',  document.getElementById('p_country').value);
  const logoFile = document.getElementById('logoFile').files[0];
  if (logoFile) fd.append('logo', logoFile);

  fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        goStep(2);
      } else {
        Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Could not save profile.' });
      }
    })
    .catch(() => Swal.fire({ icon: 'error', title: 'Network error' }))
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-arrow-right me-2"></i>Save & Continue';
    });
}

// ── Step 2: Send invites ──────────────────────────────────────────
function saveStep2(btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sending Invites…';

  const fd = new FormData();
  fd.append('ajax',   '1');
  fd.append('_token', CSRF_TOKEN);
  fd.append('step',   'team');

  document.querySelectorAll('[name="member_name[]"]').forEach(el => fd.append('member_name[]', el.value));
  document.querySelectorAll('[name="member_email[]"]').forEach(el => fd.append('member_email[]', el.value));
  document.querySelectorAll('[name="member_role[]"]').forEach(el => fd.append('member_role[]', el.value));

  fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        if ((res.invited || 0) > 0) {
          Swal.fire({
            icon: 'success',
            title: res.invited + ' invite' + (res.invited > 1 ? 's' : '') + ' sent!',
            timer: 1500,
            showConfirmButton: false,
          }).then(() => goStep(3));
        } else {
          goStep(3);
        }
      } else {
        Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Could not send invites.' });
      }
    })
    .catch(() => Swal.fire({ icon: 'error', title: 'Network error' }))
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send Invites & Continue';
    });
}

// ── Step 3: Complete onboarding ───────────────────────────────────
function completeOnboarding(btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading Dashboard…';

  const fd = new FormData();
  fd.append('ajax',   '1');
  fd.append('_token', CSRF_TOKEN);
  fd.append('step',   'complete');

  fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success && res.redirect) {
        window.location.href = res.redirect;
      } else {
        Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Something went wrong.' });
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-rocket me-2"></i>Enter My Dashboard';
      }
    })
    .catch(() => {
      Swal.fire({ icon: 'error', title: 'Network error' });
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-rocket me-2"></i>Enter My Dashboard';
    });
}
</script>
</body>
</html>
