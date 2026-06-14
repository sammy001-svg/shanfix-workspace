<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
require_once __DIR__ . '/_nav.php';

$user  = currentUser();
$orgId = (int)$user['org_id'];

// Ensure optional columns exist
try { $pdo->exec("ALTER TABLE organizations ADD COLUMN IF NOT EXISTS website VARCHAR(255) DEFAULT NULL AFTER country"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE organizations ADD COLUMN IF NOT EXISTS primary_color VARCHAR(10) DEFAULT NULL AFTER website"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE organizations ADD COLUMN IF NOT EXISTS brand_tagline VARCHAR(255) DEFAULT NULL AFTER primary_color"); } catch (Throwable $e) {}

// ── POST: save settings ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'save_profile';

    if ($action === 'save_profile') {
        $name     = sanitize($_POST['name'] ?? '');
        $email    = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $phone    = sanitize($_POST['phone'] ?? '');
        $address  = sanitize($_POST['address'] ?? '');
        $city     = sanitize($_POST['city'] ?? '');
        $country  = sanitize($_POST['country'] ?? '');
        $website  = sanitize($_POST['website'] ?? '');
        $tagline  = sanitize($_POST['brand_tagline'] ?? '');
        $color    = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['primary_color'] ?? '') ? $_POST['primary_color'] : null;

        if (!$name) { setFlash('danger', 'School name is required.'); redirect('settings.php'); }

        // Handle logo upload
        $logoPath = null;
        if (!empty($_FILES['logo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp','svg'])) {
                setFlash('danger', 'Logo must be JPG, PNG, WebP or SVG.'); redirect('settings.php');
            }
            if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                setFlash('danger', 'Logo must be under 2MB.'); redirect('settings.php');
            }
            $uploadDir = __DIR__ . '/../../assets/uploads/logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename = 'logo_' . $orgId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $filename)) {
                $logoPath = 'assets/uploads/logos/' . $filename;
            }
        }

        $logoSet = $logoPath ? ', logo=?' : '';
        $params  = [$name, $email, $phone, $address, $city, $country, $website, $tagline, $color, $orgId];
        if ($logoPath) array_splice($params, 9, 0, [$logoPath]);
        try {
            $pdo->prepare(
                "UPDATE organizations SET name=?,email=?,phone=?,address=?,city=?,country=?,website=?,brand_tagline=?,primary_color=?{$logoSet} WHERE id=?"
            )->execute($params);
            setFlash('success', 'School settings saved successfully.');
        } catch (Throwable $e) {
            setFlash('danger', 'Could not save settings. Please try again.');
        }
        redirect('settings.php');
    }

    if ($action === 'remove_logo') {
        try {
            $pdo->prepare("UPDATE organizations SET logo=NULL WHERE id=?")->execute([$orgId]);
            setFlash('success', 'Logo removed.');
        } catch (Throwable $e) {}
        redirect('settings.php');
    }
}

// ── Load org ──────────────────────────────────────────────────────
$org = [];
try {
    $s = $pdo->prepare("SELECT * FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$orgId]);
    $org = $s->fetch() ?: [];
} catch (Throwable $e) {}

// Load module stats for overview
$stats = [];
try {
    $stats['students'] = (int)$pdo->prepare("SELECT COUNT(*) FROM sch_students WHERE org_id=?")->execute([$orgId]) ? (int)$pdo->query("SELECT COUNT(*) FROM sch_students WHERE org_id=$orgId")->fetchColumn() : 0;
} catch (Throwable $e) { $stats['students'] = 0; }
foreach (['sch_teachers'=>'teachers','sch_classes'=>'classes','sch_parents'=>'parents'] as $table => $key) {
    try { $stats[$key] = (int)$pdo->query("SELECT COUNT(*) FROM $table WHERE org_id=$orgId")->fetchColumn(); } catch (Throwable $e) { $stats[$key] = 0; }
}

// Portal URLs
$orgSlug = $org['slug'] ?? '';
$teacherPortalUrl = $orgSlug ? APP_URL . '/teacher/login.php?org=' . rawurlencode($orgSlug) : '';
$parentPortalUrl  = $orgSlug ? APP_URL . '/parent/login.php?org=' . rawurlencode($orgSlug) : '';

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-cog me-2" style="color:<?= $moduleColor ?>"></i>School Settings</h4>
    <p class="text-muted mb-0">Manage your school profile, branding, and portal configuration</p>
  </div>
</div>

<div class="row g-4">

  <!-- Left: form -->
  <div class="col-lg-8">
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_profile">

      <!-- School Profile -->
      <div class="card mb-4">
        <div class="card-header">
          <h6 class="mb-0 fw-bold"><i class="fas fa-school me-2" style="color:<?= $moduleColor ?>"></i>School Profile</h6>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold small">School Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required
                     value="<?= e($org['name'] ?? '') ?>" placeholder="e.g. Greenwood International School">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Contact Email</label>
              <input type="email" name="email" class="form-control"
                     value="<?= e($org['email'] ?? '') ?>" placeholder="info@school.ac.ke">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Phone Number</label>
              <input type="tel" name="phone" class="form-control"
                     value="<?= e($org['phone'] ?? '') ?>" placeholder="+254 700 000 000">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Address</label>
              <textarea name="address" class="form-control" rows="2"
                        placeholder="Street address"><?= e($org['address'] ?? '') ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">City / Town</label>
              <input type="text" name="city" class="form-control"
                     value="<?= e($org['city'] ?? '') ?>" placeholder="Nairobi">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Country</label>
              <input type="text" name="country" class="form-control"
                     value="<?= e($org['country'] ?? 'Kenya') ?>" placeholder="Kenya">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Website</label>
              <input type="url" name="website" class="form-control"
                     value="<?= e($org['website'] ?? '') ?>" placeholder="https://school.ac.ke">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Tagline / Motto</label>
              <input type="text" name="brand_tagline" class="form-control"
                     value="<?= e($org['brand_tagline'] ?? '') ?>"
                     placeholder="e.g. Excellence in Education" maxlength="255">
            </div>
          </div>
        </div>
      </div>

      <!-- Branding -->
      <div class="card mb-4">
        <div class="card-header">
          <h6 class="mb-0 fw-bold"><i class="fas fa-paint-brush me-2" style="color:<?= $moduleColor ?>"></i>Branding</h6>
        </div>
        <div class="card-body">
          <div class="row g-3 align-items-start">
            <!-- Logo -->
            <div class="col-md-6">
              <label class="form-label fw-semibold small">School Logo</label>
              <?php if (!empty($org['logo'])): ?>
              <div class="d-flex align-items-center gap-3 mb-2">
                <img src="<?= APP_URL . '/' . e($org['logo']) ?>" alt="Logo"
                     style="height:56px;max-width:140px;object-fit:contain;border:1px solid #dee2e6;border-radius:8px;padding:4px;background:#fff">
                <form method="POST" class="d-inline" onsubmit="return confirm('Remove logo?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="remove_logo">
                  <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="fas fa-trash me-1"></i>Remove
                  </button>
                </form>
              </div>
              <?php else: ?>
              <div class="text-muted small mb-2"><i class="fas fa-image me-1"></i>No logo uploaded</div>
              <?php endif; ?>
              <input type="file" name="logo" class="form-control form-control-sm" accept="image/*"
                     onchange="previewLogo(this)">
              <div class="form-text">JPG, PNG, WebP or SVG. Max 2MB. Shown on login pages and portals.</div>
              <div class="mt-2 d-none" id="logoPreviewWrap">
                <img id="logoPreview" style="height:48px;border-radius:6px;border:1px solid #dee2e6">
              </div>
            </div>
            <!-- Brand color -->
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Brand Color</label>
              <div class="d-flex align-items-center gap-2">
                <input type="color" name="primary_color" class="form-control form-control-color"
                       value="<?= e($org['primary_color'] ?? $moduleColor) ?>" style="width:48px;padding:2px">
                <input type="text" id="colorHex" class="form-control form-control-sm"
                       value="<?= e($org['primary_color'] ?? $moduleColor) ?>"
                       pattern="#[0-9a-fA-F]{6}" maxlength="7"
                       oninput="document.querySelector('[name=primary_color]').value=this.value">
              </div>
              <div class="form-text">Used on dashboards and reports. Default: <?= $moduleColor ?>.</div>
              <div class="mt-2">
                <div class="rounded p-2 small fw-bold text-white text-center"
                     id="colorPreview"
                     style="background:<?= e($org['primary_color'] ?? $moduleColor) ?>">
                  Preview
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn text-white px-4" style="background:<?= $moduleColor ?>">
          <i class="fas fa-save me-1"></i>Save Settings
        </button>
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>

  <!-- Right: info panel -->
  <div class="col-lg-4">

    <!-- Portal URLs -->
    <div class="card mb-4">
      <div class="card-header">
        <h6 class="mb-0 fw-bold"><i class="fas fa-link me-2" style="color:<?= $moduleColor ?>"></i>Portal URLs</h6>
      </div>
      <div class="card-body">
        <?php if ($orgSlug): ?>
        <div class="mb-3">
          <div class="fw-semibold small mb-1"><i class="fas fa-users me-1 text-success"></i>Parent Portal</div>
          <code class="small d-block p-2 bg-light rounded text-break"><?= e($parentPortalUrl) ?></code>
          <button class="btn btn-sm btn-outline-secondary mt-1"
                  onclick="navigator.clipboard.writeText('<?= e($parentPortalUrl) ?>');this.textContent='Copied!'">
            <i class="fas fa-copy me-1"></i>Copy
          </button>
        </div>
        <div>
          <div class="fw-semibold small mb-1"><i class="fas fa-chalkboard-teacher me-1 text-primary"></i>Teacher Portal</div>
          <code class="small d-block p-2 bg-light rounded text-break"><?= e($teacherPortalUrl) ?></code>
          <button class="btn btn-sm btn-outline-secondary mt-1"
                  onclick="navigator.clipboard.writeText('<?= e($teacherPortalUrl) ?>');this.textContent='Copied!'">
            <i class="fas fa-copy me-1"></i>Copy
          </button>
        </div>
        <?php else: ?>
        <p class="text-muted small mb-0">Portal URLs require a school slug. Contact your system administrator.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- School overview -->
    <div class="card mb-4">
      <div class="card-header">
        <h6 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2" style="color:<?= $moduleColor ?>"></i>School Overview</h6>
      </div>
      <div class="card-body p-0">
        <?php foreach ([
          ['Students',  $stats['students'] ?? 0, 'fa-user-graduate', $moduleColor],
          ['Teachers',  $stats['teachers'] ?? 0, 'fa-chalkboard-teacher', '#3498db'],
          ['Classes',   $stats['classes']  ?? 0, 'fa-chalkboard', '#9b59b6'],
          ['Parents',   $stats['parents']  ?? 0, 'fa-users', '#f39c12'],
        ] as [$label, $val, $icon, $color]): ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
          <div class="rounded-circle d-flex align-items-center justify-content-center"
               style="width:32px;height:32px;background:<?= $color ?>18;flex-shrink:0">
            <i class="fas <?= $icon ?>" style="color:<?= $color ?>;font-size:.78rem"></i>
          </div>
          <div class="fw-semibold small flex-grow-1"><?= $label ?></div>
          <div class="fw-bold" style="color:<?= $color ?>"><?= $val ?></div>
        </div>
        <?php endforeach; ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2">
          <div class="rounded-circle d-flex align-items-center justify-content-center"
               style="width:32px;height:32px;background:#6c757d18;flex-shrink:0">
            <i class="fas fa-id-card" style="color:#6c757d;font-size:.78rem"></i>
          </div>
          <div class="fw-semibold small flex-grow-1">Slug</div>
          <code class="small"><?= e($orgSlug ?: '—') ?></code>
        </div>
      </div>
    </div>

    <!-- Quick links -->
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0 fw-bold"><i class="fas fa-bolt me-2" style="color:<?= $moduleColor ?>"></i>Quick Admin Links</h6>
      </div>
      <div class="list-group list-group-flush">
        <?php foreach ([
          ['portals.php?tab=teachers', 'fas fa-key',         'Manage Teacher Passwords'],
          ['portals.php?tab=parents',  'fas fa-key',         'Manage Parent PINs'],
          ['academic.php',             'fas fa-calendar-alt','Academic Terms'],
          ['teachers.php',             'fas fa-chalkboard-teacher','Teaching Staff'],
          ['students.php',             'fas fa-user-graduate','Students'],
        ] as [$url, $icon, $label]): ?>
        <a href="<?= $url ?>" class="list-group-item list-group-item-action small py-2">
          <i class="fas <?= $icon ?> me-2 text-muted"></i><?= $label ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
function previewLogo(input) {
    if (input.files && input.files[0]) {
        const r = new FileReader();
        r.onload = e => {
            document.getElementById('logoPreview').src = e.target.result;
            document.getElementById('logoPreviewWrap').classList.remove('d-none');
        };
        r.readAsDataURL(input.files[0]);
    }
}
document.querySelector('[name=primary_color]')?.addEventListener('input', function(){
    document.getElementById('colorHex').value = this.value;
    document.getElementById('colorPreview').style.background = this.value;
});
document.getElementById('colorHex')?.addEventListener('input', function(){
    if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
        document.querySelector('[name=primary_color]').value = this.value;
        document.getElementById('colorPreview').style.background = this.value;
    }
});
</script>
<?php $extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php'; ?>
