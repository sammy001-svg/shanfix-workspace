<?php
/**
 * Onboarding Wizard — shown once to new client admins after registration.
 * 5 steps: Profile → Choose Plan → Activate Modules → Invite Team → All Set
 * Standalone layout — does NOT use header-client.php.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    redirect(APP_URL . '/auth/login.php');
}

$user   = currentUser();
$orgId  = (int)$user['org_id'];
$userId = (int)$user['id'];

$row = $pdo->prepare("SELECT is_onboarded FROM users WHERE id=?");
$row->execute([$userId]);
if ((bool)$row->fetchColumn()) {
    redirect(APP_URL . '/client/index.php');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── AJAX handlers ─────────────────────────────────────────────────────────────
$isAjax = ($_POST['ajax'] ?? '') === '1';
if ($isAjax) {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Security token mismatch.']);
        exit;
    }
    $step = $_POST['step'] ?? '';

    // ── Step 1: Save org profile ──────────────────────────────────────────────
    if ($step === 'profile') {
        $phone   = sanitize($_POST['phone']   ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city    = sanitize($_POST['city']    ?? '');
        $country = sanitize($_POST['country'] ?? '');
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

    // ── Step 2: Save chosen plan ──────────────────────────────────────────────
    if ($step === 'plan') {
        $planId  = (int)($_POST['plan_id']      ?? 0);
        $billing = in_array($_POST['billing'] ?? '', ['monthly', 'annual']) ? $_POST['billing'] : 'monthly';
        if ($planId > 0) {
            $plan = $pdo->prepare("SELECT * FROM subscription_plans WHERE id=? AND status='active'");
            $plan->execute([$planId]);
            if ($plan->fetch()) {
                $pdo->prepare("UPDATE subscriptions SET plan_id=?, billing_cycle=? WHERE org_id=? AND status IN ('trial','active') ORDER BY created_at DESC LIMIT 1")
                    ->execute([$planId, $billing, $orgId]);
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Step 3: Activate modules ──────────────────────────────────────────────
    if ($step === 'modules') {
        $selectedSlugs = array_filter(array_map('trim', (array)($_POST['modules'] ?? [])));
        // Get current subscription
        $subStmt = $pdo->prepare("SELECT s.id, p.max_modules FROM subscriptions s LEFT JOIN subscription_plans p ON s.plan_id=p.id WHERE s.org_id=? AND s.status IN ('trial','active') ORDER BY s.created_at DESC LIMIT 1");
        $subStmt->execute([$orgId]);
        $sub = $subStmt->fetch();
        if (!$sub) { echo json_encode(['success' => false, 'error' => 'Subscription not found.']); exit; }
        $maxMods = (int)($sub['max_modules'] ?? 999);
        if (count($selectedSlugs) > $maxMods) {
            echo json_encode(['success' => false, 'error' => "Your plan allows up to {$maxMods} modules. Please deselect some."]);
            exit;
        }
        // Rebuild subscription_modules
        $subId = (int)$sub['id'];
        $pdo->prepare("DELETE FROM subscription_modules WHERE subscription_id=?")->execute([$subId]);
        $stmtMod = $pdo->prepare("SELECT id FROM modules WHERE slug=? AND status='active'");
        $stmtIns = $pdo->prepare("INSERT INTO subscription_modules (subscription_id, module_id) VALUES (?,?)");
        foreach ($selectedSlugs as $slug) {
            $stmtMod->execute([$slug]);
            $mod = $stmtMod->fetch();
            if ($mod) $stmtIns->execute([$subId, $mod['id']]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Step 4: Invite team members ───────────────────────────────────────────
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
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $chk->execute([$mEmail]);
            if ($chk->fetch()) continue;
            $tempPass = substr(str_shuffle('abcdefghijkmnpqrstuvwxyz23456789'), 0, 10);
            $hashed   = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("INSERT INTO users (org_id,name,email,password,role,status,is_onboarded) VALUES (?,?,?,?,?,'active',1)")
                ->execute([$orgId, $mName, $mEmail, $hashed, $mRole]);
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

    // ── Step 5: Complete onboarding ───────────────────────────────────────────
    if ($step === 'complete') {
        $pdo->prepare("UPDATE users SET is_onboarded=1 WHERE id=?")->execute([$userId]);
        logActivity('onboarding_complete', 'client', "Onboarding completed by {$user['name']}");
        echo json_encode(['success' => true, 'redirect' => APP_URL . '/client/index.php']);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown step']);
    exit;
}

// ── Page data ──────────────────────────────────────────────────────────────────
$orgRow = $pdo->prepare("SELECT * FROM organizations WHERE id=?");
$orgRow->execute([$orgId]);
$org = $orgRow->fetch() ?: [];

// Current subscription + plan
$subRow = $pdo->prepare("SELECT s.*, p.name as plan_name, p.max_modules, p.max_users FROM subscriptions s LEFT JOIN subscription_plans p ON s.plan_id=p.id WHERE s.org_id=? AND s.status IN ('trial','active') ORDER BY s.created_at DESC LIMIT 1");
$subRow->execute([$orgId]);
$sub = $subRow->fetch() ?: [];

// All active plans
$allPlans = $pdo->query("SELECT * FROM subscription_plans WHERE status='active' ORDER BY price_monthly")->fetchAll();

// All active modules
$allModules = $pdo->query("SELECT * FROM modules WHERE status='active' ORDER BY sort_order")->fetchAll();

// Currently active module slugs for org
$activeSlugs = [];
if (!empty($sub['id'])) {
    $slStmt = $pdo->prepare("SELECT m.slug FROM modules m JOIN subscription_modules sm ON m.id=sm.module_id WHERE sm.subscription_id=? AND sm.status='active'");
    $slStmt->execute([$sub['id']]);
    $activeSlugs = $slStmt->fetchAll(PDO::FETCH_COLUMN);
}

$countries = [
    'Kenya','Uganda','Tanzania','Rwanda','Ethiopia','Nigeria','Ghana','South Africa',
    'Egypt','Morocco','Senegal','Côte d\'Ivoire','Cameroon','Mozambique','Zambia',
    'Zimbabwe','Angola','United Kingdom','United States','Canada','Other',
];

// Group modules by category
$modulesByCategory = [];
foreach ($allModules as $m) {
    $modulesByCategory[$m['category']][] = $m;
}
ksort($modulesByCategory);

$currentPlanId = (int)($sub['plan_id'] ?? 0);
$maxModules    = (int)($sub['max_modules'] ?? 999);
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
    :root { --navy:#0B2D4E; --green:#1A8A4E; }
    body {
      min-height:100vh;
      background:linear-gradient(135deg,#0B2D4E 0%,#1A8A4E 100%);
      display:flex; flex-direction:column;
      font-family:'Segoe UI',Arial,sans-serif;
    }

    /* ── Top bar ── */
    .wizard-topbar { padding:16px 32px; display:flex; align-items:center; justify-content:space-between; }
    .wizard-brand  { color:white; font-size:1.25rem; font-weight:800; letter-spacing:-.5px; display:flex; align-items:center; gap:10px; }
    .wizard-brand .icon { width:36px; height:36px; background:rgba(255,255,255,.15); border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:1rem; }
    .wizard-welcome { color:rgba(255,255,255,.75); font-size:.9rem; }

    /* ── Progress stepper ── */
    .wizard-progress { display:flex; justify-content:center; gap:0; padding:0 32px 24px; overflow-x:auto; }
    .step-item { display:flex; align-items:center; gap:8px; flex-shrink:0; }
    .step-item + .step-item::before { content:''; width:40px; height:2px; background:rgba(255,255,255,.3); transition:background .4s; }
    .step-item.done + .step-item::before,
    .step-item.active + .step-item::before { background:rgba(255,255,255,.7); }
    .step-num { width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,.2); border:2px solid rgba(255,255,255,.4); color:white; font-size:.8rem; font-weight:700; display:flex; align-items:center; justify-content:center; transition:all .3s; }
    .step-item.active .step-num { background:white; color:var(--navy); border-color:white; }
    .step-item.done .step-num   { background:var(--green); border-color:var(--green); }
    .step-label { color:rgba(255,255,255,.6); font-size:.72rem; font-weight:600; white-space:nowrap; }
    .step-item.active .step-label { color:white; }
    .step-item.done .step-label   { color:rgba(255,255,255,.8); }

    /* ── Wizard card ── */
    .wizard-card { background:white; border-radius:20px; padding:40px; max-width:760px; margin:0 auto 48px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,.25); }
    .wizard-card h2 { color:var(--navy); font-weight:800; margin-bottom:4px; }
    .wizard-card .subtitle { color:#64748b; margin-bottom:28px; font-size:.95rem; }
    .step-panel { display:none; }
    .step-panel.active { display:block; }

    /* ── Plan cards ── */
    .plan-card { border:2px solid #e2e8f0; border-radius:16px; padding:24px; cursor:pointer; transition:all .2s; position:relative; }
    .plan-card:hover { border-color:#0B2D4E; transform:translateY(-2px); box-shadow:0 8px 24px rgba(11,45,78,.1); }
    .plan-card.selected { border-color:var(--navy); background:#f8fafc; box-shadow:0 0 0 3px rgba(11,45,78,.15); }
    .plan-card.popular::before { content:'Most Popular'; position:absolute; top:-12px; left:50%; transform:translateX(-50%); background:var(--green); color:white; font-size:.7rem; font-weight:700; padding:3px 12px; border-radius:20px; white-space:nowrap; }
    .plan-name  { font-size:1.1rem; font-weight:800; color:var(--navy); margin-bottom:4px; }
    .plan-price { font-size:1.6rem; font-weight:800; color:var(--navy); }
    .plan-price span { font-size:.85rem; font-weight:400; color:#64748b; }
    .plan-desc  { font-size:.82rem; color:#64748b; margin:8px 0; }
    .plan-limit { font-size:.78rem; color:#64748b; }
    .plan-limit i { color:var(--green); margin-right:4px; }
    .plan-check { position:absolute; top:12px; right:12px; width:24px; height:24px; border-radius:50%; background:var(--navy); color:white; display:none; align-items:center; justify-content:center; font-size:.7rem; }
    .plan-card.selected .plan-check { display:flex; }
    .billing-toggle { display:flex; align-items:center; gap:10px; margin-bottom:24px; }
    .billing-toggle .toggle-track { width:44px; height:24px; background:#e2e8f0; border-radius:12px; cursor:pointer; position:relative; transition:background .2s; }
    .billing-toggle .toggle-track.annual { background:var(--green); }
    .billing-toggle .toggle-thumb { width:18px; height:18px; background:white; border-radius:50%; position:absolute; top:3px; left:3px; transition:left .2s; box-shadow:0 1px 4px rgba(0,0,0,.2); }
    .billing-toggle .toggle-track.annual .toggle-thumb { left:23px; }
    .billing-toggle label { font-size:.9rem; color:#374151; cursor:pointer; }
    .save-badge { background:#fef9c3; color:#92400e; font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:20px; }

    /* ── Module cards ── */
    .module-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:10px; margin-bottom:16px; }
    .module-card { border:2px solid #e2e8f0; border-radius:12px; padding:14px 12px; cursor:pointer; transition:all .2s; text-align:center; position:relative; user-select:none; }
    .module-card:hover { border-color:var(--cardColor,#0B2D4E); transform:translateY(-1px); }
    .module-card.selected { border-color:var(--cardColor,#0B2D4E); background:color-mix(in srgb,var(--cardColor,#0B2D4E) 8%,white); }
    .module-card.disabled { opacity:.45; cursor:not-allowed; pointer-events:none; }
    .module-card .mc-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; margin:0 auto 8px; font-size:1rem; color:white; background:var(--cardColor,#0B2D4E); }
    .module-card .mc-name { font-size:.78rem; font-weight:700; color:#1e293b; line-height:1.3; }
    .module-card .mc-cat  { font-size:.68rem; color:#94a3b8; margin-top:2px; }
    .module-card .mc-chk  { position:absolute; top:6px; right:6px; width:18px; height:18px; border-radius:50%; background:var(--cardColor,#0B2D4E); color:white; display:none; align-items:center; justify-content:center; font-size:.6rem; }
    .module-card.selected .mc-chk { display:flex; }
    .module-counter { display:flex; align-items:center; gap:8px; padding:10px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:16px; }
    .counter-bar { flex:1; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden; }
    .counter-fill { height:100%; background:linear-gradient(90deg,var(--navy),var(--green)); border-radius:4px; transition:width .3s; }
    .category-label { font-size:.72rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.08em; margin:16px 0 8px; }

    /* ── Team invite rows ── */
    .invite-row { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:12px; }
    .invite-row-num { font-size:.75rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; margin-bottom:10px; }

    /* ── Logo preview ── */
    .logo-preview { width:80px; height:80px; border-radius:12px; border:2px dashed #cbd5e1; display:flex; align-items:center; justify-content:center; background:#f8fafc; cursor:pointer; transition:all .2s; overflow:hidden; }
    .logo-preview:hover { border-color:var(--green); background:#f0fdf4; }
    .logo-preview img { width:100%; height:100%; object-fit:contain; }
    .logo-preview .placeholder { color:#94a3b8; font-size:1.5rem; }

    /* ── Completion ── */
    @keyframes pop-in { 0%{transform:scale(0) rotate(-10deg);opacity:0} 70%{transform:scale(1.1) rotate(3deg)} 100%{transform:scale(1) rotate(0);opacity:1} }
    .success-icon { width:80px; height:80px; background:linear-gradient(135deg,var(--green),#2dd4bf); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px; animation:pop-in .5s cubic-bezier(.175,.885,.32,1.275) forwards; font-size:2rem; color:white; }
    .checklist-item { display:flex; align-items:flex-start; gap:10px; padding:10px 0; border-bottom:1px solid #f1f5f9; font-size:.9rem; }
    .checklist-item:last-child { border-bottom:none; }
    .checklist-item .ci-icon { width:24px; height:24px; border-radius:50%; background:#f0fdf4; color:var(--green); display:flex; align-items:center; justify-content:center; font-size:.7rem; flex-shrink:0; }

    /* ── Buttons ── */
    .btn-wizard-next { background:linear-gradient(135deg,var(--navy),#1a5276); color:white; border:none; padding:12px 32px; border-radius:50px; font-weight:700; font-size:1rem; width:100%; transition:all .2s; }
    .btn-wizard-next:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(11,45,78,.3); }
    .btn-wizard-next:disabled { opacity:.65; transform:none; }
    .btn-wizard-skip { color:#94a3b8; font-size:.85rem; cursor:pointer; background:none; border:none; padding:8px; display:block; width:100%; text-align:center; margin-top:8px; }
    .btn-wizard-skip:hover { color:#64748b; }
  </style>
</head>
<body>

<!-- Top bar -->
<div class="wizard-topbar">
  <div class="wizard-brand">
    <div class="icon"><i class="fas fa-cubes"></i></div>
    <?= APP_NAME ?>
  </div>
  <div class="wizard-welcome"><i class="fas fa-user-circle me-1"></i><?= e($user['name']) ?></div>
</div>

<!-- Step progress -->
<div class="wizard-progress">
  <?php
  $steps = [
      [1, 'Profile'],
      [2, 'Your Plan'],
      [3, 'Modules'],
      [4, 'Team'],
      [5, 'All Set!'],
  ];
  foreach ($steps as [$n, $label]):
  ?>
  <div class="step-item <?= $n === 1 ? 'active' : '' ?>" id="si-<?= $n ?>">
    <div class="step-num">
      <?php if ($n === 5): ?><i class="fas fa-check" style="font-size:.65rem"></i><?php else: echo $n; endif; ?>
    </div>
    <div class="step-label"><?= $label ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="container px-3">
<div class="wizard-card">

  <!-- ── Step 1: Profile ───────────────────────────────────────────────────── -->
  <div class="step-panel active" id="step1">
    <h2>Welcome, <?= e(explode(' ', $user['name'])[0]) ?>! <span style="font-size:1.4rem">👋</span></h2>
    <p class="subtitle">Let's set up <strong><?= e($user['org_name']) ?></strong>'s workspace. This takes under 3 minutes.</p>

    <div class="mb-4">
      <label class="form-label fw-semibold">Company Logo</label>
      <div class="d-flex align-items-center gap-3">
        <div class="logo-preview" onclick="document.getElementById('logoFile').click()" id="logoPreview">
          <?php if (!empty($org['logo'])): ?>
            <img src="<?= APP_URL ?>/<?= e($org['logo']) ?>" alt="logo" id="logoImg">
          <?php else: ?>
            <span class="placeholder"><i class="fas fa-building"></i></span>
          <?php endif; ?>
        </div>
        <div>
          <div class="fw-semibold small mb-1">Upload your company logo</div>
          <div class="text-muted" style="font-size:.8rem">PNG, JPG or SVG · Max 2 MB</div>
          <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="document.getElementById('logoFile').click()">
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

  <!-- ── Step 2: Choose Plan ───────────────────────────────────────────────── -->
  <div class="step-panel" id="step2">
    <h2>Choose Your Plan</h2>
    <p class="subtitle">You're on a 14-day free trial. Pick the plan that fits your organisation best.</p>

    <!-- Billing toggle -->
    <div class="billing-toggle">
      <label onclick="toggleBilling()">Monthly</label>
      <div class="toggle-track" id="billingToggle" onclick="toggleBilling()">
        <div class="toggle-thumb"></div>
      </div>
      <label onclick="toggleBilling()">Annual <span class="save-badge ms-1">Save 17%</span></label>
    </div>

    <div class="row g-3 mb-4" id="planCards">
      <?php foreach ($allPlans as $plan):
        $isSelected = ($plan['id'] == $currentPlanId);
        $isPopular  = !empty($plan['is_popular']);
      ?>
      <div class="col-md-4">
        <div class="plan-card <?= $isSelected ? 'selected' : '' ?> <?= $isPopular ? 'popular' : '' ?>"
             data-plan="<?= $plan['id'] ?>"
             data-monthly="<?= $plan['price_monthly'] ?>"
             data-annual="<?= $plan['price_annual'] ?>"
             data-maxmod="<?= $plan['max_modules'] ?>"
             data-maxusers="<?= $plan['max_users'] ?>"
             onclick="selectPlan(this)">
          <div class="plan-check"><i class="fas fa-check"></i></div>
          <div class="plan-name"><?= e($plan['name']) ?></div>
          <div class="plan-price mt-2 monthly-price">
            KES <?= number_format($plan['price_monthly']) ?><span>/mo</span>
          </div>
          <div class="plan-price mt-2 annual-price" style="display:none">
            KES <?= number_format(round($plan['price_annual'] / 12)) ?><span>/mo</span>
            <div style="font-size:.75rem;color:var(--green);font-weight:600">
              KES <?= number_format($plan['price_annual']) ?>/yr
            </div>
          </div>
          <div class="plan-desc"><?= e($plan['description']) ?></div>
          <div class="plan-limit"><i class="fas fa-check-circle"></i>Up to <?= $plan['max_modules'] ?> modules</div>
          <div class="plan-limit"><i class="fas fa-check-circle"></i>Up to <?= $plan['max_users'] ?> users</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <p class="text-muted small text-center mb-4">
      <i class="fas fa-shield-alt me-1 text-success"></i>
      All plans include a 14-day free trial. No credit card required during trial.
    </p>

    <button class="btn btn-wizard-next" onclick="saveStep2(this)">
      <i class="fas fa-arrow-right me-2"></i>Confirm Plan & Continue
    </button>
    <button class="btn-wizard-skip" onclick="goStep(3)">Skip — continue with current plan →</button>
  </div>

  <!-- ── Step 3: Activate Modules ─────────────────────────────────────────── -->
  <div class="step-panel" id="step3">
    <h2>Activate Your Modules</h2>
    <p class="subtitle">Choose which tools to enable for your workspace. You can change this any time from Settings.</p>

    <div class="module-counter" id="modCounter">
      <div class="fw-semibold small" style="white-space:nowrap">
        <span id="selCount">0</span> / <span id="maxCount"><?= $maxModules >= 100 ? '∞' : $maxModules ?></span> modules
      </div>
      <div class="counter-bar">
        <div class="counter-fill" id="counterFill" style="width:0%"></div>
      </div>
      <div class="small text-muted" id="counterHint">Select the modules your organisation needs</div>
    </div>

    <?php foreach ($modulesByCategory as $category => $mods): ?>
    <div class="category-label"><i class="fas fa-layer-group me-1"></i><?= e($category) ?></div>
    <div class="module-grid">
      <?php foreach ($mods as $m):
        $isActive = in_array($m['slug'], $activeSlugs, true);
      ?>
      <div class="module-card <?= $isActive ? 'selected' : '' ?>"
           style="--cardColor:<?= e($m['color']) ?>"
           data-slug="<?= e($m['slug']) ?>"
           onclick="toggleModule(this)">
        <div class="mc-chk"><i class="fas fa-check"></i></div>
        <div class="mc-icon"><i class="<?= e($m['icon']) ?>"></i></div>
        <div class="mc-name"><?= e($m['name']) ?></div>
        <div class="mc-cat"><?= e($m['category']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="mt-4">
      <button class="btn btn-wizard-next" onclick="saveStep3(this)">
        <i class="fas fa-arrow-right me-2"></i>Save Modules & Continue
      </button>
      <button class="btn-wizard-skip" onclick="goStep(4)">Skip — use current module selection →</button>
    </div>
  </div>

  <!-- ── Step 4: Invite Team ───────────────────────────────────────────────── -->
  <div class="step-panel" id="step4">
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

    <button class="btn btn-wizard-next" onclick="saveStep4(this)">
      <i class="fas fa-paper-plane me-2"></i>Send Invites & Continue
    </button>
    <button class="btn-wizard-skip" onclick="goStep(5)">Skip — I'll invite later →</button>
  </div>

  <!-- ── Step 5: All Set ───────────────────────────────────────────────────── -->
  <div class="step-panel text-center" id="step5">
    <div class="success-icon"><i class="fas fa-check"></i></div>
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
const CSRF_TOKEN   = <?= json_encode($csrfToken) ?>;
const PLAN_MAX_MOD = <?= json_encode(array_column($allPlans, 'max_modules', 'id')) ?>;
let   _billing     = 'monthly';
let   _selectedPlan = <?= $currentPlanId ?: 'null' ?>;
let   _maxModules  = <?= json_encode($maxModules >= 100 ? 9999 : $maxModules) ?>;

// ── Logo preview ──────────────────────────────────────────────────────────────
function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('logoPreview').innerHTML =
      '<img src="' + e.target.result + '" alt="logo" style="width:100%;height:100%;object-fit:contain">';
  };
  reader.readAsDataURL(input.files[0]);
}

// ── Step navigation ───────────────────────────────────────────────────────────
function goStep(n) {
  document.querySelectorAll('.step-panel').forEach((p, i) => p.classList.toggle('active', i + 1 === n));
  for (let i = 1; i <= 5; i++) {
    const si = document.getElementById('si-' + i);
    if (!si) continue;
    si.classList.toggle('active', i === n);
    si.classList.toggle('done', i < n);
  }
  window.scrollTo({ top: 0, behavior: 'smooth' });
  if (n === 3) updateModuleCounter();
}

// ── Billing toggle ────────────────────────────────────────────────────────────
function toggleBilling() {
  _billing = _billing === 'monthly' ? 'annual' : 'monthly';
  const track = document.getElementById('billingToggle');
  track.classList.toggle('annual', _billing === 'annual');
  document.querySelectorAll('.monthly-price').forEach(el => el.style.display = _billing === 'monthly' ? '' : 'none');
  document.querySelectorAll('.annual-price').forEach(el => el.style.display  = _billing === 'annual'  ? '' : 'none');
}

// ── Plan selection ────────────────────────────────────────────────────────────
function selectPlan(card) {
  document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
  card.classList.add('selected');
  _selectedPlan = parseInt(card.dataset.plan);
  const newMax  = parseInt(PLAN_MAX_MOD[_selectedPlan] ?? 9999);
  _maxModules   = newMax >= 100 ? 9999 : newMax;
  document.getElementById('maxCount').textContent = newMax >= 100 ? '∞' : newMax;
  updateModuleCounter();
}

// ── Module toggle ─────────────────────────────────────────────────────────────
function toggleModule(card) {
  if (card.classList.contains('disabled')) return;
  const wasSelected = card.classList.contains('selected');
  const selected    = document.querySelectorAll('.module-card.selected').length;
  if (!wasSelected && selected >= _maxModules) {
    Swal.fire({ icon: 'info', title: 'Limit reached', text: 'Your plan allows up to ' + _maxModules + ' modules. Upgrade your plan or deselect one first.', confirmButtonColor: '#0B2D4E' });
    return;
  }
  card.classList.toggle('selected');
  updateModuleCounter();
}

function updateModuleCounter() {
  const selected = document.querySelectorAll('.module-card.selected').length;
  document.getElementById('selCount').textContent = selected;
  const pct = _maxModules >= 9999 ? (selected > 0 ? 100 : 0) : Math.round((selected / _maxModules) * 100);
  document.getElementById('counterFill').style.width = Math.min(pct, 100) + '%';
  const hint = _maxModules >= 9999
    ? 'No module limit on your plan'
    : (selected >= _maxModules ? 'Module limit reached — upgrade to add more' : 'Select up to ' + _maxModules + ' modules');
  document.getElementById('counterHint').textContent = hint;
  document.getElementById('counterFill').style.background =
    selected >= _maxModules ? 'linear-gradient(90deg,#e74c3c,#c0392b)' : 'linear-gradient(90deg,#0B2D4E,#1A8A4E)';
}

// ── Step 1: Save profile ──────────────────────────────────────────────────────
function saveStep1(btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving…';
  const fd = new FormData();
  fd.append('ajax', '1'); fd.append('_token', CSRF_TOKEN); fd.append('step', 'profile');
  fd.append('phone',   document.getElementById('p_phone').value);
  fd.append('address', document.getElementById('p_address').value);
  fd.append('city',    document.getElementById('p_city').value);
  fd.append('country', document.getElementById('p_country').value);
  const logoFile = document.getElementById('logoFile').files[0];
  if (logoFile) fd.append('logo', logoFile);
  fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => { if (res.success) { goStep(2); } else { Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Could not save profile.' }); } })
    .catch(() => Swal.fire({ icon: 'error', title: 'Network error' }))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-arrow-right me-2"></i>Save & Continue'; });
}

// ── Step 2: Save plan ─────────────────────────────────────────────────────────
function saveStep2(btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving…';
  const fd = new FormData();
  fd.append('ajax', '1'); fd.append('_token', CSRF_TOKEN); fd.append('step', 'plan');
  if (_selectedPlan) fd.append('plan_id', _selectedPlan);
  fd.append('billing', _billing);
  fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) { goStep(3); }
      else { Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Could not save plan.' }); }
    })
    .catch(() => Swal.fire({ icon: 'error', title: 'Network error' }))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-arrow-right me-2"></i>Confirm Plan & Continue'; });
}

// ── Step 3: Save modules ──────────────────────────────────────────────────────
function saveStep3(btn) {
  const selected = document.querySelectorAll('.module-card.selected');
  if (selected.length === 0) {
    Swal.fire({ icon: 'warning', title: 'No modules selected', text: 'Please select at least one module to activate.', confirmButtonColor: '#0B2D4E' });
    return;
  }
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving…';
  const fd = new FormData();
  fd.append('ajax', '1'); fd.append('_token', CSRF_TOKEN); fd.append('step', 'modules');
  selected.forEach(card => fd.append('modules[]', card.dataset.slug));
  fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) { goStep(4); }
      else { Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Could not save modules.' }); }
    })
    .catch(() => Swal.fire({ icon: 'error', title: 'Network error' }))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-arrow-right me-2"></i>Save Modules & Continue'; });
}

// ── Step 4: Send invites ──────────────────────────────────────────────────────
function saveStep4(btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sending Invites…';
  const fd = new FormData();
  fd.append('ajax', '1'); fd.append('_token', CSRF_TOKEN); fd.append('step', 'team');
  document.querySelectorAll('[name="member_name[]"]').forEach(el => fd.append('member_name[]', el.value));
  document.querySelectorAll('[name="member_email[]"]').forEach(el => fd.append('member_email[]', el.value));
  document.querySelectorAll('[name="member_role[]"]').forEach(el => fd.append('member_role[]', el.value));
  fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        if ((res.invited || 0) > 0) {
          Swal.fire({ icon: 'success', title: res.invited + ' invite' + (res.invited > 1 ? 's' : '') + ' sent!', timer: 1500, showConfirmButton: false })
            .then(() => goStep(5));
        } else { goStep(5); }
      } else { Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Could not send invites.' }); }
    })
    .catch(() => Swal.fire({ icon: 'error', title: 'Network error' }))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send Invites & Continue'; });
}

// ── Step 5: Complete ──────────────────────────────────────────────────────────
function completeOnboarding(btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading Dashboard…';
  const fd = new FormData();
  fd.append('ajax', '1'); fd.append('_token', CSRF_TOKEN); fd.append('step', 'complete');
  fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success && res.redirect) { window.location.href = res.redirect; }
      else {
        Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Something went wrong.' });
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-rocket me-2"></i>Enter My Dashboard';
      }
    })
    .catch(() => {
      Swal.fire({ icon: 'error', title: 'Network error' });
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-rocket me-2"></i>Enter My Dashboard';
    });
}

// init counter on page load
updateModuleCounter();
</script>
</body>
</html>
