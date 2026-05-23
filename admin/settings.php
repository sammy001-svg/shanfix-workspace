<?php
$pageTitle = 'System Settings';
require_once __DIR__ . '/../includes/header-admin.php';

// Load all settings once
$cfg = getSettings([
    'app_name','app_tagline','support_email','default_currency','default_timezone','trial_days','max_users',
    'company_address','company_website',
    'smtp_host','smtp_port','smtp_user','smtp_pass','smtp_enc','mail_from','mail_from_name',
    'kopokopo_client_id','kopokopo_client_secret','kopokopo_till_number','kopokopo_api_secret','kopokopo_env',
    'invoice_prefix','invoice_tax_rate','invoice_footer','invoice_notes',
    'mpesa_paybill','mpesa_account_ref','bank_name','bank_account','bank_branch',
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
      <a href="#company"  class="list-group-item list-group-item-action d-flex align-items-center gap-2"><i class="fas fa-building"></i> Company</a>
      <a href="#billing"  class="list-group-item list-group-item-action d-flex align-items-center gap-2"><i class="fas fa-file-invoice-dollar"></i> Billing</a>
      <a href="#email"    class="list-group-item list-group-item-action d-flex align-items-center gap-2"><i class="fas fa-envelope"></i> Email / SMTP</a>
      <a href="#kopokopo" class="list-group-item list-group-item-action d-flex align-items-center gap-2"><i class="fas fa-mobile-alt"></i> KopoKopo (M-Pesa)</a>
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
          <div class="col-12"><label class="form-label">Tagline <span class="text-muted small">(shown on invoices, emails, and login page)</span></label><input type="text" class="form-control" id="app_tagline" value="<?= $s('app_tagline', defined('APP_TAGLINE') ? APP_TAGLINE : '') ?>" placeholder="e.g. Powering African Businesses"></div>
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
            <button class="btn btn-primary" onclick="saveSection('general',['app_name','app_tagline','support_email','default_currency','default_timezone','trial_days','max_users'])">
              <i class="fas fa-save me-2"></i>Save General Settings
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Company / Branding -->
    <div class="card mb-4" id="company">
      <div class="card-header"><i class="fas fa-building text-green me-2"></i>Company / Branding</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Company Address <span class="text-muted small">(shown on invoices)</span></label>
            <textarea class="form-control" id="company_address" rows="2" placeholder="P.O. Box 00100, Nairobi, Kenya"><?= $s('company_address') ?></textarea>
          </div>
          <div class="col-md-6"><label class="form-label">Company Website</label>
            <input type="url" class="form-control" id="company_website" value="<?= $s('company_website') ?>" placeholder="https://yourdomain.co.ke">
          </div>
          <div class="col-12">
            <button class="btn btn-primary" onclick="saveSection('company',['company_address','company_website'])">
              <i class="fas fa-save me-2"></i>Save Company Settings
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Billing & Invoicing -->
    <div class="card mb-4" id="billing">
      <div class="card-header"><i class="fas fa-file-invoice-dollar text-green me-2"></i>Billing &amp; Invoicing</div>
      <div class="card-body">
        <h6 class="fw-700 text-navy mb-3">Invoice Settings</h6>
        <div class="row g-3 mb-4">
          <div class="col-md-4">
            <label class="form-label">Invoice Prefix</label>
            <input type="text" class="form-control" id="invoice_prefix" value="<?= $s('invoice_prefix','INV') ?>" placeholder="INV">
            <div class="form-text">Invoice numbers will look like <strong><?= $s('invoice_prefix','INV') ?>-XXXXXXXX</strong></div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Tax Rate (%)</label>
            <div class="input-group">
              <input type="number" class="form-control" id="invoice_tax_rate" value="<?= $s('invoice_tax_rate','16') ?>" min="0" max="100" step="0.5">
              <span class="input-group-text">%</span>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">Invoice Footer Text</label>
            <textarea class="form-control" id="invoice_footer" rows="2"><?= $s('invoice_footer','Thank you for your business.') ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Invoice Notes / Payment Terms</label>
            <textarea class="form-control" id="invoice_notes" rows="2"><?= $s('invoice_notes','Payment is due within 30 days of invoice date.') ?></textarea>
          </div>
        </div>

        <h6 class="fw-700 text-navy mb-3 border-top pt-3">Payment Details <span class="text-muted fw-400 small">(shown on invoices to clients)</span></h6>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label"><i class="fas fa-mobile-alt text-success me-1"></i>M-Pesa Paybill</label>
            <input type="text" class="form-control" id="mpesa_paybill" value="<?= $s('mpesa_paybill') ?>" placeholder="e.g. 400200">
          </div>
          <div class="col-md-4">
            <label class="form-label">M-Pesa Account Ref</label>
            <input type="text" class="form-control" id="mpesa_account_ref" value="<?= $s('mpesa_account_ref','Invoice Number') ?>" placeholder="Invoice Number">
          </div>
          <div class="col-md-4">
            <label class="form-label">Bank Name</label>
            <input type="text" class="form-control" id="bank_name" value="<?= $s('bank_name') ?>" placeholder="e.g. Equity Bank">
          </div>
          <div class="col-md-6">
            <label class="form-label">Bank Account Number</label>
            <input type="text" class="form-control" id="bank_account" value="<?= $s('bank_account') ?>" placeholder="e.g. 1234567890">
          </div>
          <div class="col-md-6">
            <label class="form-label">Bank Branch</label>
            <input type="text" class="form-control" id="bank_branch" value="<?= $s('bank_branch') ?>" placeholder="e.g. Nairobi CBD">
          </div>
          <div class="col-12">
            <button class="btn btn-primary" onclick="saveSection('billing',['invoice_prefix','invoice_tax_rate','invoice_footer','invoice_notes','mpesa_paybill','mpesa_account_ref','bank_name','bank_account','bank_branch'])">
              <i class="fas fa-save me-2"></i>Save Billing Settings
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

    <!-- KopoKopo (M-Pesa) -->
    <div class="card mb-4" id="kopokopo">
      <div class="card-header"><i class="fas fa-mobile-alt text-green me-2"></i>KopoKopo M-Pesa Integration</div>
      <div class="card-body">
        <div class="alert alert-info small"><i class="fas fa-info-circle me-2"></i>Configure your <strong>KopoKopo</strong> API credentials for M-Pesa STK push payments. Obtain credentials from <a href="https://app.kopokopo.com" target="_blank" rel="noopener">app.kopokopo.com</a>.</div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Client ID</label>
            <input type="text" class="form-control" id="kopokopo_client_id" value="<?= $s('kopokopo_client_id') ?>" placeholder="KopoKopo OAuth client_id">
          </div>
          <div class="col-md-6">
            <label class="form-label">Client Secret</label>
            <input type="password" class="form-control" id="kopokopo_client_secret" placeholder="<?= !empty($cfg['kopokopo_client_secret']) ? '••••••••' : 'KopoKopo client_secret' ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Till Number</label>
            <input type="text" class="form-control" id="kopokopo_till_number" value="<?= $s('kopokopo_till_number') ?>" placeholder="e.g. 000000">
            <div class="form-text">Your Buy Goods till number registered on KopoKopo.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">API Secret <span class="text-muted small">(webhook signature)</span></label>
            <input type="password" class="form-control" id="kopokopo_api_secret" placeholder="<?= !empty($cfg['kopokopo_api_secret']) ? '••••••••' : 'Webhook signing secret' ?>">
            <div class="form-text">Used to verify <code>X-KopoKopo-Signature</code> on incoming webhooks.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Environment</label>
            <select class="form-select" id="kopokopo_env">
              <option value="sandbox"    <?= ($cfg['kopokopo_env']??'sandbox')==='sandbox'?'selected':'' ?>>Sandbox (Testing)</option>
              <option value="production" <?= ($cfg['kopokopo_env']??'')==='production'?'selected':'' ?>>Production</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Webhook Callback URL</label>
            <input type="text" class="form-control" value="<?= APP_URL ?>/api/mpesa-callback.php" readonly>
            <div class="form-text">Add this URL in your KopoKopo dashboard under Webhooks.</div>
          </div>
          <div class="col-12">
            <button class="btn btn-primary" onclick="saveSection('kopokopo',['kopokopo_client_id','kopokopo_client_secret','kopokopo_till_number','kopokopo_api_secret','kopokopo_env'])">
              <i class="fas fa-save me-2"></i>Save KopoKopo Settings
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
  ['smtp_pass','kopokopo_client_secret','kopokopo_api_secret'].forEach(k => {
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
