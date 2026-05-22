<?php
$pageTitle = 'System Settings';
require_once __DIR__ . '/../includes/header-admin.php';

// Load all settings once
$cfg = getSettings([
    'app_name','support_email','default_currency','default_timezone','trial_days','max_users',
    'smtp_host','smtp_port','smtp_user','smtp_pass','smtp_enc','mail_from','mail_from_name',
    'mpesa_consumer_key','mpesa_consumer_secret','mpesa_shortcode','mpesa_passkey','mpesa_env',
    'session_timeout','max_login_attempts',
]);
$s = fn(string $k, string $d = '') => htmlspecialchars($cfg[$k] ?? $d, ENT_QUOTES);
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-cog me-2 text-green"></i>System Settings</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="../admin/index.php">Dashboard</a></li><li class="breadcrumb-item active">Settings</li></ol></nav>
  </div>
</div>

<div id="settingsAlert"></div>

<div class="row g-4">
  <div class="col-lg-3">
    <div class="list-group" id="settingsTabs">
      <a href="#general"  class="list-group-item list-group-item-action active d-flex align-items-center gap-2"><i class="fas fa-sliders-h"></i> General</a>
      <a href="#email"    class="list-group-item list-group-item-action d-flex align-items-center gap-2"><i class="fas fa-envelope"></i> Email</a>
      <a href="#mpesa"    class="list-group-item list-group-item-action d-flex align-items-center gap-2"><i class="fas fa-mobile-alt"></i> M-Pesa</a>
      <a href="#security" class="list-group-item list-group-item-action d-flex align-items-center gap-2"><i class="fas fa-shield-alt"></i> Security</a>
    </div>
  </div>

  <div class="col-lg-9">

    <!-- General -->
    <div class="card mb-4" id="general">
      <div class="card-header"><i class="fas fa-sliders-h text-green me-2"></i>General Settings</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">System Name</label><input type="text" class="form-control" id="app_name" value="<?= $s('app_name', APP_NAME) ?>"></div>
          <div class="col-md-6"><label class="form-label">Support Email</label><input type="email" class="form-control" id="support_email" value="<?= $s('support_email', 'support@orbitdesk.co.ke') ?>"></div>
          <div class="col-md-6">
            <label class="form-label">Default Currency</label>
            <select class="form-select" id="default_currency">
              <?php foreach (['KES'=>'KES — Kenyan Shilling','USD'=>'USD — US Dollar','UGX'=>'UGX — Uganda Shilling','TZS'=>'TZS — Tanzania Shilling'] as $code=>$label): ?>
              <option value="<?= $code ?>" <?= ($cfg['default_currency']??'KES')===$code?'selected':'' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Default Timezone</label>
            <select class="form-select" id="default_timezone">
              <option value="Africa/Nairobi" <?= ($cfg['default_timezone']??'')==='Africa/Nairobi'?'selected':'' ?>>Africa/Nairobi (EAT +3)</option>
              <option value="UTC" <?= ($cfg['default_timezone']??'')==='UTC'?'selected':'' ?>>UTC</option>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Trial Period (days)</label><input type="number" class="form-control" id="trial_days" value="<?= $s('trial_days','14') ?>"></div>
          <div class="col-md-6"><label class="form-label">Max Users (default)</label><input type="number" class="form-control" id="max_users" value="<?= $s('max_users','5') ?>"></div>
          <div class="col-12">
            <button class="btn btn-primary" onclick="saveSection('general',['app_name','support_email','default_currency','default_timezone','trial_days','max_users'])">
              <i class="fas fa-save me-2"></i>Save General Settings
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Email / SMTP -->
    <div class="card mb-4" id="email">
      <div class="card-header"><i class="fas fa-envelope text-green me-2"></i>Email (SMTP) Settings</div>
      <div class="card-body">
        <div class="alert alert-info small"><i class="fas fa-info-circle me-2"></i>Configure SMTP so the system can send welcome emails, invoice reminders, and trial expiry alerts.</div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">SMTP Host</label><input type="text" class="form-control" id="smtp_host" value="<?= $s('smtp_host') ?>" placeholder="mail.yourdomain.com"></div>
          <div class="col-md-6"><label class="form-label">SMTP Port</label><input type="number" class="form-control" id="smtp_port" value="<?= $s('smtp_port','587') ?>"></div>
          <div class="col-md-6"><label class="form-label">SMTP Username</label><input type="email" class="form-control" id="smtp_user" value="<?= $s('smtp_user') ?>"></div>
          <div class="col-md-6"><label class="form-label">SMTP Password</label><input type="password" class="form-control" id="smtp_pass" placeholder="<?= $cfg['smtp_pass'] ? '••••••••' : 'Enter password' ?>"></div>
          <div class="col-md-6"><label class="form-label">From Name</label><input type="text" class="form-control" id="mail_from_name" value="<?= $s('mail_from_name', APP_NAME) ?>"></div>
          <div class="col-md-6"><label class="form-label">From Email</label><input type="email" class="form-control" id="mail_from" value="<?= $s('mail_from','noreply@orbitdesk.co.ke') ?>"></div>
          <div class="col-md-6">
            <label class="form-label">Encryption</label>
            <select class="form-select" id="smtp_enc">
              <?php foreach (['tls'=>'TLS','ssl'=>'SSL','none'=>'None'] as $val=>$label): ?>
              <option value="<?= $val ?>" <?= ($cfg['smtp_enc']??'tls')===$val?'selected':'' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 d-flex align-items-end">
            <div class="input-group">
              <input type="email" class="form-control" id="testEmailAddr" placeholder="Send test to...">
              <button class="btn btn-outline-secondary" type="button" onclick="sendTestEmail()">
                <i class="fas fa-paper-plane me-1"></i>Test
              </button>
            </div>
          </div>
          <div class="col-12">
            <button class="btn btn-primary" onclick="saveSection('email',['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_enc','mail_from','mail_from_name'])">
              <i class="fas fa-save me-2"></i>Save Email Settings
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- M-Pesa -->
    <div class="card mb-4" id="mpesa">
      <div class="card-header"><i class="fas fa-mobile-alt text-green me-2"></i>M-Pesa Integration (Daraja API)</div>
      <div class="card-body">
        <div class="alert alert-info small"><i class="fas fa-info-circle me-2"></i>Configure your Safaricom Daraja API credentials for M-Pesa STK push payments.</div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Consumer Key</label><input type="text" class="form-control" id="mpesa_consumer_key" value="<?= $s('mpesa_consumer_key') ?>" placeholder="Daraja consumer key"></div>
          <div class="col-md-6"><label class="form-label">Consumer Secret</label><input type="password" class="form-control" id="mpesa_consumer_secret" placeholder="<?= $cfg['mpesa_consumer_secret'] ? '••••••••' : 'Daraja consumer secret' ?>"></div>
          <div class="col-md-6"><label class="form-label">Shortcode (Paybill)</label><input type="text" class="form-control" id="mpesa_shortcode" value="<?= $s('mpesa_shortcode') ?>" placeholder="e.g. 174379"></div>
          <div class="col-md-6"><label class="form-label">Passkey</label><input type="password" class="form-control" id="mpesa_passkey" placeholder="<?= $cfg['mpesa_passkey'] ? '••••••••' : 'Enter passkey' ?>"></div>
          <div class="col-md-6">
            <label class="form-label">Environment</label>
            <select class="form-select" id="mpesa_env">
              <option value="sandbox" <?= ($cfg['mpesa_env']??'sandbox')==='sandbox'?'selected':'' ?>>Sandbox (Testing)</option>
              <option value="live"    <?= ($cfg['mpesa_env']??'')==='live'?'selected':'' ?>>Live (Production)</option>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Callback URL</label><input type="text" class="form-control" value="<?= APP_URL ?>/api/mpesa-callback.php" readonly></div>
          <div class="col-12">
            <button class="btn btn-primary" onclick="saveSection('mpesa',['mpesa_consumer_key','mpesa_consumer_secret','mpesa_shortcode','mpesa_passkey','mpesa_env'])">
              <i class="fas fa-save me-2"></i>Save M-Pesa Settings
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Security -->
    <div class="card" id="security">
      <div class="card-header"><i class="fas fa-shield-alt text-green me-2"></i>Security Settings</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Session Timeout (hours)</label><input type="number" class="form-control" id="session_timeout" value="<?= $s('session_timeout','8') ?>"></div>
          <div class="col-md-6"><label class="form-label">Max Login Attempts</label><input type="number" class="form-control" id="max_login_attempts" value="<?= $s('max_login_attempts','5') ?>"></div>
          <div class="col-12">
            <button class="btn btn-primary" onclick="saveSection('security',['session_timeout','max_login_attempts'])">
              <i class="fas fa-save me-2"></i>Save Security Settings
            </button>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
// Tab highlight on scroll
document.querySelectorAll('#settingsTabs a').forEach(a => {
  a.addEventListener('click', e => {
    e.preventDefault();
    document.querySelectorAll('#settingsTabs a').forEach(x => x.classList.remove('active'));
    a.classList.add('active');
    document.querySelector(a.getAttribute('href')).scrollIntoView({behavior:'smooth', block:'start'});
  });
});

function showAlert(type, msg) {
  const el = document.getElementById('settingsAlert');
  el.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show"><strong>${msg}</strong><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
  el.scrollIntoView({behavior:'smooth', block:'nearest'});
}

function gatherData(keys) {
  const data = {};
  keys.forEach(k => {
    const el = document.getElementById(k);
    if (el) data[k] = el.value;
  });
  return data;
}

function saveSection(section, keys) {
  const data = gatherData(keys);
  // Skip empty password fields so we don't overwrite with blank
  ['smtp_pass','mpesa_consumer_secret','mpesa_passkey'].forEach(k => {
    if (k in data && data[k] === '') delete data[k];
  });

  fetch('<?= APP_URL ?>/admin/ajax.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'save_settings', section, data})
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) showAlert('success', 'Settings saved successfully.');
    else showAlert('danger', res.error ?? 'Failed to save settings.');
  })
  .catch(() => showAlert('danger', 'Network error. Please try again.'));
}

function sendTestEmail() {
  const addr = document.getElementById('testEmailAddr').value.trim();
  if (!addr) { showAlert('warning', 'Enter an email address to send the test to.'); return; }

  fetch('<?= APP_URL ?>/admin/ajax.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'send_test_email', email: addr})
  })
  .then(r => r.json())
  .then(res => showAlert(res.success ? 'success' : 'danger', res.message ?? (res.error ?? 'Unknown error')))
  .catch(() => showAlert('danger', 'Network error. Please try again.'));
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
