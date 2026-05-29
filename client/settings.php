<?php
// ── Bootstrap (POST handlers before HTML) ────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Helper: get/save org-scoped settings ────────────────────────
function getOrgSetting(int $orgId, string $key, string $default = ''): string {
    global $pdo;
    try {
        $s = $pdo->prepare("SELECT `value` FROM org_settings WHERE org_id=? AND `key`=? LIMIT 1");
        $s->execute([$orgId, $key]);
        $v = $s->fetchColumn();
        return ($v !== false) ? (string)$v : $default;
    } catch (Exception $e) { return $default; }
}

function saveOrgSetting(int $orgId, string $key, string $value): void {
    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS org_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            `key` VARCHAR(64) NOT NULL,
            `value` TEXT NOT NULL,
            UNIQUE KEY uq_org_key (org_id, `key`)
        )");
        $pdo->prepare("INSERT INTO org_settings (org_id, `key`, `value`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value`=?")
            ->execute([$orgId, $key, $value, $value]);
    } catch (Exception $e) {}
}

// ── POST Handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // General
    if ($action === 'save_general') {
        $name    = sanitize($_POST['name']    ?? '');
        $email   = sanitize($_POST['email']   ?? '');
        $phone   = sanitize($_POST['phone']   ?? '');
        $city    = sanitize($_POST['city']    ?? '');
        $country = sanitize($_POST['country'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        try {
            $pdo->prepare("UPDATE organizations SET name=?, email=?, phone=?, city=?, country=?, address=? WHERE id=?")
                ->execute([$name, $email, $phone, $city, $country, $address, $orgId]);
            logActivity('settings_update', 'settings', 'Updated general organisation settings.');
            setFlash('success', 'General settings saved.');
        } catch (Exception $e) {
            setFlash('danger', 'Save failed: ' . $e->getMessage());
        }
        redirect(APP_URL . '/client/settings.php?tab=general');
    }

    // Branding
    if ($action === 'save_branding') {
        $color   = sanitize($_POST['primary_color']  ?? '#1A8A4E');
        $tagline = sanitize($_POST['brand_tagline'] ?? '');

        // Validate color
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#1A8A4E';

        try {
            $pdo->prepare("UPDATE organizations SET primary_color=?, brand_tagline=? WHERE id=?")
                ->execute([$color, $tagline, $orgId]);
        } catch (Exception $e) {
            // columns may not exist yet — add them silently
            try {
                $pdo->exec("ALTER TABLE organizations ADD COLUMN IF NOT EXISTS primary_color VARCHAR(7) DEFAULT '#1A8A4E'");
                $pdo->exec("ALTER TABLE organizations ADD COLUMN IF NOT EXISTS brand_tagline VARCHAR(255) DEFAULT ''");
                $pdo->prepare("UPDATE organizations SET primary_color=?, brand_tagline=? WHERE id=?")
                    ->execute([$color, $tagline, $orgId]);
            } catch (Exception $e2) {}
        }

        // Logo upload
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg','jpeg','png','webp'];
            $ext     = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $maxSize = 2 * 1024 * 1024;
            if (!in_array($ext, $allowed)) {
                setFlash('danger', 'Logo must be JPG, PNG or WebP.');
                redirect(APP_URL . '/client/settings.php?tab=branding');
            }
            if ($_FILES['logo']['size'] > $maxSize) {
                setFlash('danger', 'Logo must be under 2MB.');
                redirect(APP_URL . '/client/settings.php?tab=branding');
            }
            $dir  = __DIR__ . '/../assets/uploads/logos/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $dest = $dir . $orgId . '.' . $ext;
            // Remove old logos for this org
            foreach (['jpg','jpeg','png','webp'] as $oldExt) {
                $old = $dir . $orgId . '.' . $oldExt;
                if (file_exists($old) && $old !== $dest) unlink($old);
            }
            move_uploaded_file($_FILES['logo']['tmp_name'], $dest);
            try {
                $pdo->prepare("UPDATE organizations SET logo=? WHERE id=?")->execute([$orgId . '.' . $ext, $orgId]);
            } catch (Exception $e) {}
        }

        logActivity('settings_update', 'settings', 'Updated branding settings.');
        setFlash('success', 'Branding settings saved.');
        redirect(APP_URL . '/client/settings.php?tab=branding');
    }

    // Security
    if ($action === 'save_security') {
        $require2fa = isset($_POST['require_2fa']) ? 1 : 0;
        $timeout    = in_array((int)($_POST['session_timeout'] ?? 4), [1,4,8,24]) ? (int)$_POST['session_timeout'] : 4;
        $minPwd     = max(6, min(32, (int)($_POST['min_password_length'] ?? 8)));

        try {
            $pdo->prepare("UPDATE organizations SET require_2fa=? WHERE id=?")->execute([$require2fa, $orgId]);
        } catch (Exception $e) {
            try {
                $pdo->exec("ALTER TABLE organizations ADD COLUMN IF NOT EXISTS require_2fa TINYINT(1) DEFAULT 0");
                $pdo->prepare("UPDATE organizations SET require_2fa=? WHERE id=?")->execute([$require2fa, $orgId]);
            } catch (Exception $e2) {}
        }
        saveOrgSetting($orgId, 'session_timeout',    (string)$timeout);
        saveOrgSetting($orgId, 'min_password_length', (string)$minPwd);

        logActivity('settings_update', 'settings', 'Updated security settings.');
        setFlash('success', 'Security settings saved.');
        redirect(APP_URL . '/client/settings.php?tab=security');
    }

    // Notifications
    if ($action === 'save_notifications') {
        $allowed = ['loan_approved','invoice_sent','subscription_expiry','new_member',
                    'payment_received','password_reset','loan_repayment_due','ticket_resolved'];
        $prefs = [];
        foreach ($allowed as $k) {
            $prefs[$k] = isset($_POST['notif'][$k]) ? 1 : 0;
        }
        saveOrgSetting($orgId, 'notif_prefs', json_encode($prefs));
        logActivity('settings_update', 'settings', 'Updated notification preferences.');
        setFlash('success', 'Notification preferences saved.');
        redirect(APP_URL . '/client/settings.php?tab=notifications');
    }
}

// ── Load org data ────────────────────────────────────────────────
$org = [];
try {
    $s = $pdo->prepare("SELECT * FROM organizations WHERE id=?");
    $s->execute([$orgId]);
    $org = $s->fetch() ?: [];
} catch (Exception $e) {}

// Org settings
$sessionTimeout    = (int)getOrgSetting($orgId, 'session_timeout', '4');
$minPwdLen         = (int)getOrgSetting($orgId, 'min_password_length', '8');
$notifPrefsRaw     = getOrgSetting($orgId, 'notif_prefs', '{}');
$notifPrefs        = json_decode($notifPrefsRaw, true) ?: [];

// Recent login activity
$loginActivity = [];
try {
    $s = $pdo->prepare("SELECT u.name, a.ip, a.created_at, a.description FROM activity_log a JOIN users u ON u.id=a.user_id WHERE a.org_id=? AND a.action='login' ORDER BY a.created_at DESC LIMIT 10");
    $s->execute([$orgId]);
    $loginActivity = $s->fetchAll();
} catch (Exception $e) {}

$activeTab     = $_GET['tab'] ?? 'general';
$primaryColor  = $org['primary_color'] ?? '#1A8A4E';
$brandTagline  = $org['brand_tagline'] ?? '';
$logoFile      = $org['logo'] ?? '';
$logoUrl       = $logoFile ? APP_URL . '/assets/uploads/logos/' . $logoFile : APP_URL . '/assets/images/logo.svg';

$notifEvents = [
    'loan_approved'       => ['icon' => 'fa-money-bill-wave', 'label' => 'Loan Approved'],
    'invoice_sent'        => ['icon' => 'fa-file-invoice-dollar', 'label' => 'Invoice Sent'],
    'subscription_expiry' => ['icon' => 'fa-calendar-times', 'label' => 'Subscription Expiry'],
    'new_member'          => ['icon' => 'fa-user-plus', 'label' => 'New Member Welcome'],
    'payment_received'    => ['icon' => 'fa-check-circle', 'label' => 'Payment Received'],
    'password_reset'      => ['icon' => 'fa-lock', 'label' => 'Password Reset'],
    'loan_repayment_due'  => ['icon' => 'fa-calendar-exclamation', 'label' => 'Loan Repayment Due'],
    'ticket_resolved'     => ['icon' => 'fa-ticket-alt', 'label' => 'Support Ticket Resolved'],
];

$pageTitle = 'Organization Settings';
require_once __DIR__ . '/../includes/header-client.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h4><i class="fas fa-cog me-2 text-green"></i>Organization Settings</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Settings</li>
    </ol></nav>
  </div>
</div>

<?= flashAlert() ?>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'general'       ? 'active' : '' ?>" href="?tab=general"><i class="fas fa-building me-1"></i>General</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'branding'      ? 'active' : '' ?>" href="?tab=branding"><i class="fas fa-palette me-1"></i>Branding</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'security'      ? 'active' : '' ?>" href="?tab=security"><i class="fas fa-shield-alt me-1"></i>Security</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'notifications' ? 'active' : '' ?>" href="?tab=notifications"><i class="fas fa-bell me-1"></i>Notifications</a></li>
</ul>

<!-- ── General Tab ──────────────────────────────────────────────── -->
<?php if ($activeTab === 'general'): ?>
<div class="card shadow-sm border-0">
  <div class="card-header"><i class="fas fa-building text-green me-2"></i>General Information</div>
  <div class="card-body">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_general">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Organisation Name</label>
          <input type="text" class="form-control" name="name" value="<?= e($org['name'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Email Address</label>
          <input type="email" class="form-control" name="email" value="<?= e($org['email'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Phone</label>
          <input type="text" class="form-control" name="phone" value="<?= e($org['phone'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">City</label>
          <input type="text" class="form-control" name="city" value="<?= e($org['city'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Country</label>
          <input type="text" class="form-control" name="country" value="<?= e($org['country'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Address</label>
          <textarea class="form-control" name="address" rows="2"><?= e($org['address'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save General Settings</button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Branding Tab ──────────────────────────────────────────────── -->
<?php if ($activeTab === 'branding'): ?>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card shadow-sm border-0">
      <div class="card-header"><i class="fas fa-palette text-green me-2"></i>Branding Settings</div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="save_branding">
          <div class="mb-3">
            <label class="form-label fw-semibold">Organisation Logo</label>
            <?php if ($logoFile): ?>
            <div class="mb-2"><img src="<?= e($logoUrl) ?>" alt="Current Logo" style="max-height:60px;max-width:200px;object-fit:contain;border:1px solid #e2e8f0;padding:6px;border-radius:8px"></div>
            <?php endif; ?>
            <input type="file" class="form-control" name="logo" id="logoInput" accept=".jpg,.jpeg,.png,.webp">
            <div class="form-text">JPG, PNG or WebP. Max 2MB.</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Primary Colour</label>
            <div class="d-flex align-items-center gap-2">
              <input type="color" class="form-control form-control-color" name="primary_color" id="colorPicker" value="<?= e($primaryColor) ?>" title="Pick a colour">
              <input type="text" class="form-control font-monospace" id="colorHex" value="<?= e($primaryColor) ?>" maxlength="7" style="width:120px">
              <div id="colorSwatch" style="width:40px;height:40px;border-radius:8px;background:<?= e($primaryColor) ?>;border:2px solid #e2e8f0"></div>
            </div>
          </div>
          <div class="mb-4">
            <label class="form-label fw-semibold">Brand Tagline</label>
            <input type="text" class="form-control" name="brand_tagline" id="taglineInput" value="<?= e($brandTagline) ?>" placeholder="e.g. Empowering your business" maxlength="255">
          </div>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Branding</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm border-0 sticky-top" style="top:80px">
      <div class="card-header"><i class="fas fa-eye text-green me-2"></i>Login Card Preview</div>
      <div class="card-body p-3" style="background:#f1f5f9;border-radius:0 0 12px 12px">
        <div id="previewCard" style="background:#fff;border-radius:12px;padding:24px;box-shadow:0 4px 16px rgba(0,0,0,.1);max-width:320px;margin:0 auto">
          <div class="text-center mb-3">
            <div id="previewLogoWrap" style="width:56px;height:56px;border-radius:14px;margin:0 auto 10px;display:flex;align-items:center;justify-content:center;background:<?= e($primaryColor) ?>20">
              <img id="previewLogo" src="<?= e($logoUrl) ?>" alt="Logo" style="max-width:44px;max-height:44px;object-fit:contain">
            </div>
            <strong id="previewOrgName" style="color:#0B2D4E;font-size:1rem"><?= e($org['name'] ?? 'Organisation Name') ?></strong><br>
            <small id="previewTagline" class="text-muted"><?= e($brandTagline ?: 'Your trusted platform') ?></small>
          </div>
          <div style="height:8px;border-radius:4px;margin-bottom:12px" id="previewBar" style="background:<?= e($primaryColor) ?>"></div>
          <div class="mb-2"><div style="height:36px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px"></div></div>
          <div class="mb-3"><div style="height:36px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px"></div></div>
          <div id="previewBtn" style="height:38px;border-radius:8px;background:<?= e($primaryColor) ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;font-weight:600">Sign In</div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Security Tab ──────────────────────────────────────────────── -->
<?php if ($activeTab === 'security'): ?>
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header"><i class="fas fa-shield-alt text-green me-2"></i>Security Settings</div>
  <div class="card-body">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_security">
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="require_2fa" id="req2fa" value="1" <?= !empty($org['require_2fa']) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="req2fa">Require 2FA for All Users</label>
            <div class="form-text">Enforce two-factor authentication org-wide.</div>
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Session Timeout</label>
          <select class="form-select" name="session_timeout">
            <?php foreach ([1=>'1 Hour',4=>'4 Hours',8=>'8 Hours',24=>'24 Hours'] as $h => $l): ?>
            <option value="<?= $h ?>" <?= $sessionTimeout === $h ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Min Password Length</label>
          <input type="number" class="form-control" name="min_password_length" value="<?= $minPwdLen ?>" min="6" max="32">
        </div>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Security Settings</button>
    </form>
  </div>
</div>

<div class="card shadow-sm border-0">
  <div class="card-header"><i class="fas fa-history text-green me-2"></i>Recent Login Activity</div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead><tr><th>User</th><th>IP Address</th><th>Time</th><th>Details</th></tr></thead>
      <tbody>
        <?php foreach ($loginActivity as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td><code><?= e($r['ip'] ?? '—') ?></code></td>
          <td><?= formatDateTime($r['created_at']) ?></td>
          <td class="text-muted small"><?= e($r['description'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($loginActivity)): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">No login activity recorded yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Notifications Tab ─────────────────────────────────────────── -->
<?php if ($activeTab === 'notifications'): ?>
<div class="card shadow-sm border-0">
  <div class="card-header"><i class="fas fa-bell text-green me-2"></i>Email Notification Preferences</div>
  <div class="card-body">
    <p class="text-muted small mb-4">Control which events trigger email notifications for your organisation.</p>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_notifications">
      <div class="row g-3">
        <?php foreach ($notifEvents as $key => $ev): ?>
        <div class="col-md-6">
          <div class="d-flex align-items-center justify-content-between p-3 rounded border">
            <div class="d-flex align-items-center gap-3">
              <div class="rounded-3 p-2" style="background:#e8f5e9"><i class="fas <?= $ev['icon'] ?> text-success"></i></div>
              <span class="fw-semibold small"><?= e($ev['label']) ?></span>
            </div>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="notif[<?= $key ?>]" value="1"
                <?= !empty($notifPrefs[$key]) ? 'checked' : '' ?> id="notif_<?= $key ?>">
              <label class="form-check-label" for="notif_<?= $key ?>"></label>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-4">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Notification Preferences</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$orgNameJs   = json_encode($org['name'] ?? '');
$extraJs = <<<JS
<script>
// Branding live preview
const colorPicker = document.getElementById('colorPicker');
const colorHex    = document.getElementById('colorHex');
const colorSwatch = document.getElementById('colorSwatch');
const previewBtn  = document.getElementById('previewBtn');
const previewBar  = document.getElementById('previewBar');
const previewTagline = document.getElementById('previewTagline');
const taglineInput   = document.getElementById('taglineInput');
const logoInput      = document.getElementById('logoInput');
const previewLogo    = document.getElementById('previewLogo');
const previewLogoWrap = document.getElementById('previewLogoWrap');

function applyColor(c) {
    if (colorSwatch) colorSwatch.style.background = c;
    if (previewBtn)  previewBtn.style.background = c;
    if (previewBar)  previewBar.style.background = c;
    if (previewLogoWrap) previewLogoWrap.style.background = c + '20';
}

if (colorPicker) {
    colorPicker.addEventListener('input', function() {
        colorHex.value = this.value; applyColor(this.value);
    });
}
if (colorHex) {
    colorHex.addEventListener('input', function() {
        if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
            if (colorPicker) colorPicker.value = this.value;
            applyColor(this.value);
        }
    });
}
if (taglineInput) {
    taglineInput.addEventListener('input', function() {
        if (previewTagline) previewTagline.textContent = this.value || 'Your trusted platform';
    });
}
if (logoInput) {
    logoInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file && previewLogo) {
            const reader = new FileReader();
            reader.onload = e => { previewLogo.src = e.target.result; };
            reader.readAsDataURL(file);
        }
    });
}
</script>
JS;
require_once __DIR__ . '/../includes/footer.php';
