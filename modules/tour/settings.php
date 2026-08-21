<?php
// ── TOUR: Module Settings ──────────────────────────────────────
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/_lib.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    denyIfReadOnly($moduleSlug);
    requireModuleRole($moduleSlug, ['admin']);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];

    if (($_POST['action'] ?? '') === 'save') {
        $defaults = tourSettingDefaults();

        // Free-text settings, saved as given
        $textKeys = [
            't_company_name', 't_tagline', 't_contact_phone', 't_contact_email', 't_emergency_phone',
            't_tax_label', 't_quote_terms', 't_invoice_terms', 't_portal_welcome',
        ];
        foreach ($textKeys as $key) {
            saveTourSetting($orgId, $key, sanitize($_POST[$key] ?? ''));
        }

        // Document prefixes — letters and digits only, they end up in document numbers
        foreach (['t_quote_prefix', 't_invoice_prefix', 't_departure_prefix'] as $key) {
            $val = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST[$key] ?? ''));
            saveTourSetting($orgId, $key, $val !== '' ? substr($val, 0, 6) : $defaults[$key]);
        }

        // Bounded numerics
        $numeric = [
            't_quote_validity'   => [1, 365],
            't_invoice_due_days' => [0, 365],
            't_deposit_percent'  => [0, 100],
            't_tax_rate'         => [0, 100],
        ];
        foreach ($numeric as $key => [$min, $max]) {
            $val = (float)($_POST[$key] ?? $defaults[$key]);
            $val = max($min, min($max, $val));
            saveTourSetting($orgId, $key, (string)$val);
        }

        saveTourSetting($orgId, 't_portal_enabled', !empty($_POST['t_portal_enabled']) ? '1' : '0');

        setFlash('success', 'Tour & Travel settings saved.');
        logActivity('update', 'tour', 'Updated Tour & Travel module settings');
        redirect('settings.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
require_once __DIR__ . '/_lib.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$isAdmin = hasModuleRole($moduleSlug, ['admin']);

$cfg = [];
foreach (array_keys(tourSettingDefaults()) as $key) {
    $cfg[$key] = tourConf($orgId, $key);
}

// Preview of the next document numbers under the current prefixes
$nextQuote     = tourNextNumber($orgId, 'tour_quotations', 'quote_no',       $cfg['t_quote_prefix']);
$nextInvoice   = tourNextNumber($orgId, 'tour_invoices',   'invoice_no',     $cfg['t_invoice_prefix']);
$nextDeparture = tourNextNumber($orgId, 'tour_departures', 'departure_code', $cfg['t_departure_prefix']);

$org = [];
try {
    $stmt = $pdo->prepare("SELECT name, slug, email, phone FROM organizations WHERE id=? LIMIT 1");
    $stmt->execute([$orgId]);
    $org = $stmt->fetch() ?: [];
} catch (Throwable $e) {}

$portalUrl = APP_URL . '/traveller/login.php' . (!empty($org['slug']) ? '?org=' . rawurlencode($org['slug']) : '');
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-cog me-2" style="color:<?= $moduleColor ?>"></i>Tour &amp; Travel Settings</h4>
    <p class="text-muted mb-0">Trading identity, document numbering and commercial defaults for this module</p>
  </div>
</div>

<?php if (!$isAdmin): ?>
<div class="alert alert-warning d-flex align-items-center">
  <i class="fas fa-lock me-2"></i>
  <div>These settings are read-only for your role. Ask a Tour Manager to make changes.</div>
</div>
<?php endif; ?>

<form method="POST">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save">
  <fieldset <?= $isAdmin ? '' : 'disabled' ?>>

  <div class="row g-4">
    <!-- ── Trading identity ─────────────────────────────────── -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white">
          <h6 class="mb-0"><i class="fas fa-id-badge me-2" style="color:<?= $moduleColor ?>"></i>Trading Identity</h6>
        </div>
        <div class="card-body">
          <p class="text-muted small">Shown on quotations, invoices and the traveller portal. Leave blank to use your organisation record (<strong><?= e($org['name'] ?? '—') ?></strong>).</p>
          <div class="mb-3">
            <label class="form-label fw-semibold">Trading Name</label>
            <input type="text" name="t_company_name" class="form-control" value="<?= e($cfg['t_company_name']) ?>" placeholder="<?= e($org['name'] ?? 'Your tour company') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Tagline</label>
            <input type="text" name="t_tagline" class="form-control" value="<?= e($cfg['t_tagline']) ?>" placeholder="e.g. Curated East African safaris since 2009">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Bookings Phone</label>
              <input type="text" name="t_contact_phone" class="form-control" value="<?= e($cfg['t_contact_phone']) ?>" placeholder="<?= e($org['phone'] ?? '+254 …') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Bookings Email</label>
              <input type="email" name="t_contact_email" class="form-control" value="<?= e($cfg['t_contact_email']) ?>" placeholder="<?= e($org['email'] ?? 'bookings@…') ?>">
            </div>
            <div class="col-md-12">
              <label class="form-label fw-semibold">24/7 Emergency Line</label>
              <input type="text" name="t_emergency_phone" class="form-control" value="<?= e($cfg['t_emergency_phone']) ?>" placeholder="Shown to travellers in the portal while they are on tour">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Document numbering ───────────────────────────────── -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white">
          <h6 class="mb-0"><i class="fas fa-hashtag me-2" style="color:<?= $moduleColor ?>"></i>Document Numbering</h6>
        </div>
        <div class="card-body">
          <p class="text-muted small">Numbers run per year and per organisation, e.g. <code>QT-<?= date('Y') ?>-0001</code>. Changing a prefix does not renumber existing documents.</p>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Quotation Prefix</label>
              <input type="text" name="t_quote_prefix" class="form-control text-uppercase" maxlength="6" value="<?= e($cfg['t_quote_prefix']) ?>">
              <div class="form-text">Next: <code><?= e($nextQuote) ?></code></div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Invoice Prefix</label>
              <input type="text" name="t_invoice_prefix" class="form-control text-uppercase" maxlength="6" value="<?= e($cfg['t_invoice_prefix']) ?>">
              <div class="form-text">Next: <code><?= e($nextInvoice) ?></code></div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Departure Prefix</label>
              <input type="text" name="t_departure_prefix" class="form-control text-uppercase" maxlength="6" value="<?= e($cfg['t_departure_prefix']) ?>">
              <div class="form-text">Next: <code><?= e($nextDeparture) ?></code></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Commercial defaults ──────────────────────────────── -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white">
          <h6 class="mb-0"><i class="fas fa-scale-balanced me-2" style="color:<?= $moduleColor ?>"></i>Commercial Defaults</h6>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Quotation Validity (days)</label>
              <input type="number" min="1" max="365" name="t_quote_validity" class="form-control" value="<?= e($cfg['t_quote_validity']) ?>">
              <div class="form-text">Quotes past this window auto-flag as expired.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Invoice Payment Terms (days)</label>
              <input type="number" min="0" max="365" name="t_invoice_due_days" class="form-control" value="<?= e($cfg['t_invoice_due_days']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Standard Deposit (%)</label>
              <input type="number" min="0" max="100" step="0.01" name="t_deposit_percent" class="form-control" value="<?= e($cfg['t_deposit_percent']) ?>">
              <div class="form-text">Used by the "Deposit Only" button on invoices.</div>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Tax Label</label>
              <input type="text" name="t_tax_label" class="form-control" value="<?= e($cfg['t_tax_label']) ?>" placeholder="VAT">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Default Rate (%)</label>
              <input type="number" min="0" max="100" step="0.01" name="t_tax_rate" class="form-control" value="<?= e($cfg['t_tax_rate']) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Traveller portal ─────────────────────────────────── -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white">
          <h6 class="mb-0"><i class="fas fa-id-card me-2" style="color:<?= $moduleColor ?>"></i>Traveller Portal</h6>
        </div>
        <div class="card-body">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="portalToggle" name="t_portal_enabled" value="1" <?= $cfg['t_portal_enabled'] === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="portalToggle">Allow travellers to sign in and track their trip</label>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Portal Welcome Message</label>
            <textarea name="t_portal_welcome" class="form-control" rows="2"><?= e($cfg['t_portal_welcome']) ?></textarea>
          </div>
          <label class="form-label fw-semibold">Portal Sign-in Link</label>
          <div class="input-group">
            <input type="text" class="form-control bg-light" id="portalUrl" value="<?= e($portalUrl) ?>" readonly>
            <button type="button" class="btn btn-outline-secondary" onclick="copyPortalUrl()"><i class="fas fa-copy"></i></button>
          </div>
          <div class="form-text">
            Travellers sign in with their booking reference and PIN.
            Issue PINs on the <a href="portals.php">Traveller Portal</a> page.
          </div>
        </div>
      </div>
    </div>

    <!-- ── Standard wording ─────────────────────────────────── -->
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
          <h6 class="mb-0"><i class="fas fa-file-lines me-2" style="color:<?= $moduleColor ?>"></i>Standard Wording</h6>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Default Quotation Terms</label>
              <textarea name="t_quote_terms" class="form-control" rows="4"><?= e($cfg['t_quote_terms']) ?></textarea>
              <div class="form-text">Prefilled on every new quotation; editable per quote.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Default Invoice Terms</label>
              <textarea name="t_invoice_terms" class="form-control" rows="4"><?= e($cfg['t_invoice_terms']) ?></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="d-flex justify-content-end mt-4">
    <button type="submit" class="btn text-white px-4" style="background:<?= $moduleColor ?>">
      <i class="fas fa-save me-1"></i>Save Settings
    </button>
  </div>
  <?php endif; ?>

  </fieldset>
</form>

<?php
$extraJs = '<script>
function copyPortalUrl() {
  const el = document.getElementById("portalUrl");
  el.select();
  el.setSelectionRange(0, 99999);
  navigator.clipboard.writeText(el.value).then(function(){
    if (window.Swal) Swal.fire({icon:"success", title:"Link copied", timer:1400, showConfirmButton:false});
  });
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
