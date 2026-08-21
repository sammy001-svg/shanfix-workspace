<?php
/**
 * admin/landing.php — Landing page hero carousel manager (super admin).
 *
 * Uploads/reorders/toggles the background slides shown behind the hero on
 * the public landing page, plus the carousel behaviour settings.
 */
// Bootstrap before any output so uploads can POST-redirect-GET.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

$projectRoot = dirname(__DIR__);

// ── Ensure the table exists so the page is usable before the migration ──
try {
    $pdo->query("SELECT 1 FROM landing_hero_slides LIMIT 1");
    $tableReady = true;
} catch (Throwable $e) {
    $tableReady = false;
}

// ── Handle uploads (multipart — the AJAX endpoint handles the rest) ─────
if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['slide_images'])) {
    $files    = $_FILES['slide_images'];
    $count    = is_array($files['name']) ? count($files['name']) : 0;
    $added    = 0;
    $errors   = [];

    // Next sort_order
    $nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM landing_hero_slides")->fetchColumn();

    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $one = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
        try {
            $rel = uploadFile(
                $one,
                $projectRoot . '/assets/uploads/hero',
                ['image/jpeg', 'image/png', 'image/webp'],
                8_388_608,           // 8 MB — hero images are large
                'hero_'
            );
            $alt = pathinfo($one['name'], PATHINFO_FILENAME);
            $alt = ucfirst(trim(preg_replace('/[_\-]+/', ' ', $alt)));

            $stmt = $pdo->prepare(
                "INSERT INTO landing_hero_slides (image_path, alt_text, sort_order, status)
                 VALUES (?, ?, ?, 'active')"
            );
            $stmt->execute([$rel, mb_substr($alt, 0, 200), ++$nextOrder]);
            $added++;
        } catch (Throwable $ex) {
            $errors[] = htmlspecialchars($one['name']) . ': ' . $ex->getMessage();
        }
    }

    $msg  = $added ? "$added image" . ($added > 1 ? 's' : '') . ' uploaded.' : '';
    $type = 'success';
    if ($errors) {
        $msg  = trim($msg . ' ' . implode(' | ', $errors));
        $type = $added ? 'warning' : 'danger';
    }
    setFlash($type, $msg ?: 'Nothing was uploaded.');

    // POST-redirect-GET so a browser refresh can't re-submit the upload
    header('Location: ' . APP_URL . '/admin/landing.php');
    exit;
}

$pageTitle = 'Landing Page';
require_once __DIR__ . '/../includes/header-admin.php';

// ── Load current state ─────────────────────────────────────────────────
$slides = [];
if ($tableReady) {
    $slides = $pdo->query(
        "SELECT * FROM landing_hero_slides ORDER BY sort_order ASC, id ASC"
    )->fetchAll();
}

$cfg = getSettings([
    'hero_carousel_enabled', 'hero_carousel_interval',
    'hero_carousel_effect',  'hero_overlay_opacity',
]);
$enabled  = ($cfg['hero_carousel_enabled']  ?? '1') === '1';
$interval = (int)($cfg['hero_carousel_interval'] ?? 6000);
$effect   = $cfg['hero_carousel_effect']    ?? 'fade';
$overlay  = (int)($cfg['hero_overlay_opacity'] ?? 86);
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-images me-2 text-green"></i>Landing Page — Hero Carousel</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Landing Page</li>
    </ol></nav>
  </div>
  <div>
    <a href="<?= APP_URL ?>/" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
      <i class="fas fa-external-link-alt me-1"></i>View Landing Page
    </a>
  </div>
</div>

<div class="position-fixed top-0 end-0 p-3" style="z-index:9999">
  <div id="landingToast" class="toast align-items-center border-0 shadow" role="alert" aria-live="assertive" data-bs-delay="4000">
    <div class="d-flex">
      <div class="toast-body fw-semibold" id="toastBody"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<?php if (!$tableReady): ?>
<div class="alert alert-warning">
  <i class="fas fa-database me-2"></i>
  <strong>Migration required.</strong> Import
  <code>database/landing_hero_migration.sql</code> to enable the hero carousel manager.
</div>
<?php endif; ?>
<?php /* Upload result is shown by flashAlert() in header-admin.php */ ?>

<style>
.slide-card { border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; background:#fff; transition:box-shadow .2s, border-color .2s; }
.slide-card:hover { box-shadow:0 8px 24px rgba(11,45,78,.10); border-color:#c6e8d7; }
.slide-thumb { width:100%; aspect-ratio:16/9; object-fit:cover; background:#f1f5f9; display:block; }
.slide-card.is-inactive .slide-thumb { filter:grayscale(1); opacity:.5; }
.slide-body { padding:.85rem; }
.slide-badge { position:absolute; top:.5rem; left:.5rem; z-index:2; }
.slide-order { position:absolute; top:.5rem; right:.5rem; z-index:2; background:rgba(5,15,31,.85); color:#fff; border-radius:6px; padding:.1rem .5rem; font-size:.72rem; font-weight:700; }
.dropzone { border:2px dashed #cbd5e1; border-radius:12px; padding:2rem; text-align:center; background:#f8fafc; transition:border-color .2s, background .2s; cursor:pointer; }
.dropzone.dragover { border-color:#1A8A4E; background:#e6f5ee; }
.preview-hero { position:relative; border-radius:12px; overflow:hidden; aspect-ratio:21/9; background:#050f1f; }
.preview-hero img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; opacity:0; transition:opacity .8s ease; }
.preview-hero img.on { opacity:1; }
.preview-hero .wash { position:absolute; inset:0; background:#050f1f; }
.preview-hero .cap { position:absolute; left:1rem; right:1rem; bottom:.9rem; color:#fff; z-index:2; text-shadow:0 2px 8px rgba(0,0,0,.7); }
.pv-eyebrow { font-size:.62rem; font-weight:700; color:#4ade93; text-transform:uppercase; letter-spacing:.5px; min-height:.8rem; }
.pv-headline { font-size:.95rem; font-weight:800; line-height:1.25; margin:.15rem 0 .3rem; }
.pv-ctas { display:flex; gap:.3rem; flex-wrap:wrap; }
.pv-ctas span { font-size:.6rem; font-weight:700; padding:.15rem .5rem; border-radius:4px; }
.pv-ctas span.p { background:#1A8A4E; color:#fff; }
.pv-ctas span.s { background:rgba(255,255,255,.18); color:#fff; border:1px solid rgba(255,255,255,.3); }
.slide-copy { min-height:74px; }
</style>

<div class="row g-4">
  <!-- ── Slides ─────────────────────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-images text-green me-2"></i>Carousel Slides
          <span class="badge bg-secondary ms-2"><?= count($slides) ?></span></span>
        <small class="text-muted">Drag order with the arrows · click a thumbnail to edit</small>
      </div>
      <div class="card-body">

        <!-- Upload -->
        <form method="post" enctype="multipart/form-data" id="uploadForm" class="mb-4">
          <label class="dropzone d-block mb-0" id="dropzone">
            <input type="file" name="slide_images[]" id="slideInput" accept="image/jpeg,image/png,image/webp"
                   multiple hidden <?= $tableReady ? '' : 'disabled' ?>>
            <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2 d-block"></i>
            <div class="fw-semibold">Drop images here, or click to browse</div>
            <div class="text-muted small mt-1">
              JPG, PNG or WebP · up to 8 MB each · 1920&times;1080 or wider recommended
            </div>
            <div id="fileList" class="small text-green mt-2"></div>
          </label>
          <button class="btn btn-primary mt-3 d-none" id="uploadBtn" type="submit">
            <i class="fas fa-upload me-2"></i>Upload Selected
          </button>
        </form>

        <?php if (!$slides): ?>
          <div class="text-center text-muted py-4">
            <i class="fas fa-image fa-2x mb-2 d-block opacity-50"></i>
            No slides yet. Upload images above to build the carousel.
          </div>
        <?php else: ?>
          <div class="row g-3" id="slideGrid">
            <?php foreach ($slides as $i => $sl): ?>
            <div class="col-sm-6 col-xl-4" data-id="<?= (int)$sl['id'] ?>">
              <div class="slide-card position-relative <?= $sl['status'] === 'inactive' ? 'is-inactive' : '' ?>">
                <span class="slide-order">#<?= $i + 1 ?></span>
                <span class="badge slide-badge <?= $sl['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                  <?= ucfirst($sl['status']) ?>
                </span>
                <img src="<?= APP_URL . '/' . htmlspecialchars($sl['image_path'], ENT_QUOTES) ?>"
                     alt="<?= htmlspecialchars($sl['alt_text'], ENT_QUOTES) ?>"
                     class="slide-thumb" loading="lazy">
                <div class="slide-body">
                  <div class="slide-copy mb-2">
                    <?php if (!empty($sl['eyebrow'])): ?>
                      <span class="badge bg-light text-success border border-success-subtle mb-1"><?= htmlspecialchars($sl['eyebrow'], ENT_QUOTES) ?></span>
                    <?php endif; ?>
                    <div class="fw-bold text-truncate" title="<?= htmlspecialchars($sl['headline'] ?? '', ENT_QUOTES) ?>">
                      <?= $sl['headline'] !== '' ? htmlspecialchars($sl['headline'], ENT_QUOTES) : '<span class="text-muted fst-italic fw-normal">No headline set</span>' ?>
                    </div>
                    <div class="small text-muted text-truncate">
                      <?= htmlspecialchars(mb_substr($sl['subheadline'] ?? '', 0, 70), ENT_QUOTES) ?>
                    </div>
                    <div class="small mt-1">
                      <?php foreach ([[$sl['cta1_label'] ?? '', $sl['cta1_url'] ?? ''], [$sl['cta2_label'] ?? '', $sl['cta2_url'] ?? '']] as $cta): ?>
                        <?php if ($cta[0] !== ''): ?>
                          <span class="badge bg-secondary-subtle text-dark fw-normal me-1">
                            <i class="fas fa-link fa-xs me-1"></i><?= htmlspecialchars($cta[0], ENT_QUOTES) ?>
                          </span>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <button class="btn btn-sm btn-outline-primary w-100 mb-2"
                          onclick='openSlideEditor(<?= json_encode($sl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                    <i class="fas fa-pen me-1"></i>Edit Message &amp; Buttons
                  </button>

                  <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-outline-secondary" title="Move up"
                            onclick="moveSlide(<?= (int)$sl['id'] ?>,'up')" <?= $i === 0 ? 'disabled' : '' ?>>
                      <i class="fas fa-arrow-up"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" title="Move down"
                            onclick="moveSlide(<?= (int)$sl['id'] ?>,'down')" <?= $i === count($slides) - 1 ? 'disabled' : '' ?>>
                      <i class="fas fa-arrow-down"></i></button>
                    <button class="btn btn-sm <?= $sl['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?> flex-grow-1"
                            onclick="toggleSlide(<?= (int)$sl['id'] ?>)">
                      <i class="fas fa-<?= $sl['status'] === 'active' ? 'eye-slash' : 'eye' ?> me-1"></i>
                      <?= $sl['status'] === 'active' ? 'Hide' : 'Show' ?>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" title="Delete"
                            onclick="deleteSlide(<?= (int)$sl['id'] ?>)">
                      <i class="fas fa-trash"></i></button>
                  </div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Settings + live preview ────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-eye text-green me-2"></i>Live Preview</div>
      <div class="card-body">
        <?php $activeSlides = array_values(array_filter($slides, fn($s) => $s['status'] === 'active')); ?>
        <div class="preview-hero" id="previewHero">
          <div class="wash" id="previewWash"></div>
          <?php foreach ($activeSlides as $j => $sl): ?>
            <img src="<?= APP_URL . '/' . htmlspecialchars($sl['image_path'], ENT_QUOTES) ?>"
                 alt="" class="preview-slide <?= $j === 0 ? 'on' : '' ?>"
                 data-eyebrow="<?= htmlspecialchars($sl['eyebrow'] ?? '', ENT_QUOTES) ?>"
                 data-headline="<?= htmlspecialchars($sl['headline'] ?? '', ENT_QUOTES) ?>"
                 data-cta1="<?= htmlspecialchars($sl['cta1_label'] ?? '', ENT_QUOTES) ?>"
                 data-cta2="<?= htmlspecialchars($sl['cta2_label'] ?? '', ENT_QUOTES) ?>">
          <?php endforeach; ?>
          <div class="cap">
            <div class="pv-eyebrow" id="pvEyebrow"><?= htmlspecialchars($activeSlides[0]['eyebrow'] ?? '', ENT_QUOTES) ?></div>
            <div class="pv-headline" id="pvHeadline"><?= htmlspecialchars($activeSlides[0]['headline'] ?? 'Your headline sits here', ENT_QUOTES) ?></div>
            <div class="pv-ctas" id="pvCtas"></div>
          </div>
        </div>
        <p class="text-muted small mb-0 mt-2">
          Preview reflects the overlay darkness and interval below.
        </p>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-sliders-h text-green me-2"></i>Carousel Settings</div>
      <div class="card-body">
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" id="hero_carousel_enabled" <?= $enabled ? 'checked' : '' ?>>
          <label class="form-check-label" for="hero_carousel_enabled">Enable carousel</label>
          <div class="form-text">When off, only the first active slide shows (static).</div>
        </div>

        <label class="form-label">Slide interval
          <span class="text-muted small">(<span id="intervalLabel"><?= number_format($interval / 1000, 1) ?></span>s)</span>
        </label>
        <input type="range" class="form-range" id="hero_carousel_interval"
               min="2000" max="15000" step="500" value="<?= $interval ?>">

        <label class="form-label mt-3">Transition</label>
        <select class="form-select" id="hero_carousel_effect">
          <option value="fade"  <?= $effect === 'fade'  ? 'selected' : '' ?>>Fade (recommended)</option>
          <option value="slide" <?= $effect === 'slide' ? 'selected' : '' ?>>Slide across</option>
        </select>

        <label class="form-label mt-3">Overlay darkness
          <span class="text-muted small">(<span id="overlayLabel"><?= $overlay ?></span>%)</span>
        </label>
        <input type="range" class="form-range" id="hero_overlay_opacity"
               min="40" max="98" step="1" value="<?= $overlay ?>">
        <div class="form-text">Higher = darker wash, more readable headline text.</div>

        <button class="btn btn-primary w-100 mt-3" onclick="saveCarousel(this)">
          <i class="fas fa-save me-2"></i>Save Settings
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ Slide copy editor ══════════════════════════════════════════ -->
<div class="modal fade" id="slideEditor" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-pen text-green me-2"></i>Slide Message &amp; Buttons</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="ed_id">
        <div class="row g-3">
          <div class="col-12">
            <img id="ed_thumb" src="" alt="" class="w-100 rounded" style="max-height:150px;object-fit:cover">
          </div>

          <div class="col-md-6">
            <label class="form-label">Eyebrow <span class="text-muted small">(small pill above headline)</span></label>
            <input type="text" class="form-control" id="ed_eyebrow" maxlength="80" placeholder="e.g. CRM &amp; Sales">
          </div>
          <div class="col-md-6">
            <label class="form-label">Alt text <span class="text-muted small">(accessibility)</span></label>
            <input type="text" class="form-control" id="ed_alt_text" maxlength="200" placeholder="Describe the photo">
          </div>

          <div class="col-12">
            <label class="form-label">Headline <span class="text-danger">*</span></label>
            <input type="text" class="form-control fw-bold" id="ed_headline" maxlength="160"
                   placeholder="e.g. Turn Every Lead Into Revenue.">
            <div class="form-text">Shown as the big hero heading while this slide is visible.</div>
          </div>

          <div class="col-12">
            <label class="form-label">Sub-headline</label>
            <textarea class="form-control" id="ed_subheadline" rows="2" maxlength="320"
                      placeholder="One or two lines explaining the benefit."></textarea>
          </div>

          <div class="col-12"><hr class="my-1"><strong class="small text-muted">CALL-TO-ACTION BUTTONS</strong></div>

          <div class="col-md-6">
            <label class="form-label">Primary button label</label>
            <input type="text" class="form-control" id="ed_cta1_label" maxlength="60" placeholder="Start Free Trial">
          </div>
          <div class="col-md-6">
            <label class="form-label">Primary button link</label>
            <input type="text" class="form-control" id="ed_cta1_url" maxlength="255" placeholder="/auth/register.php">
          </div>
          <div class="col-md-6">
            <label class="form-label">Secondary button label</label>
            <input type="text" class="form-control" id="ed_cta2_label" maxlength="60" placeholder="Browse Modules">
          </div>
          <div class="col-md-6">
            <label class="form-label">Secondary button link</label>
            <input type="text" class="form-control" id="ed_cta2_url" maxlength="255" placeholder="#modules">
          </div>

          <div class="col-12">
            <div class="alert alert-light border small mb-0">
              <i class="fas fa-info-circle me-1 text-green"></i>
              Links must be a page anchor (<code>#pricing</code>), a site path
              (<code>/auth/register.php</code>), or a full <code>https://</code> URL.
              Anything else is rejected for security. Leave a label blank to hide that button.
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">Photo caption <span class="text-muted small">(small text, bottom-left of hero)</span></label>
            <input type="text" class="form-control" id="ed_caption" maxlength="160" placeholder="Optional">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="edSaveBtn" onclick="saveSlideEditor(this)">
          <i class="fas fa-save me-2"></i>Save Slide
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const AJAX = '<?= APP_URL ?>/admin/ajax.php';

function toast(type, msg) {
  const el = document.getElementById('landingToast');
  el.className = 'toast align-items-center border-0 shadow text-bg-' + (type === 'success' ? 'success' : 'danger');
  document.getElementById('toastBody').textContent = msg;
  bootstrap.Toast.getOrCreateInstance(el).show();
}

function post(payload) {
  return fetch(AJAX, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  }).then(r => r.json());
}

/* ── Upload UX ───────────────────────────────────────────── */
const dz    = document.getElementById('dropzone');
const input = document.getElementById('slideInput');
const list  = document.getElementById('fileList');
const upBtn = document.getElementById('uploadBtn');

function showFiles() {
  if (!input.files.length) { list.textContent = ''; upBtn.classList.add('d-none'); return; }
  list.textContent = Array.from(input.files).map(f => f.name).join(', ');
  upBtn.classList.remove('d-none');
}
if (input) {
  input.addEventListener('change', showFiles);
  ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => {
    e.preventDefault(); dz.classList.add('dragover');
  }));
  ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => {
    e.preventDefault(); dz.classList.remove('dragover');
  }));
  dz.addEventListener('drop', e => { input.files = e.dataTransfer.files; showFiles(); });
}

/* ── Slide copy editor ───────────────────────────────────── */
const ED_FIELDS = ['eyebrow','headline','subheadline','cta1_label','cta1_url',
                   'cta2_label','cta2_url','alt_text','caption'];

function openSlideEditor(slide) {
  document.getElementById('ed_id').value = slide.id;
  document.getElementById('ed_thumb').src = '<?= APP_URL ?>/' + slide.image_path;
  document.getElementById('ed_thumb').alt = slide.alt_text || '';
  ED_FIELDS.forEach(f => { document.getElementById('ed_' + f).value = slide[f] || ''; });
  bootstrap.Modal.getOrCreateInstance(document.getElementById('slideEditor')).show();
}

function saveSlideEditor(btn) {
  const id = +document.getElementById('ed_id').value;
  if (!id) return;
  const headline = document.getElementById('ed_headline').value.trim();
  if (!headline) {
    toast('danger', 'A headline is required.');
    document.getElementById('ed_headline').focus();
    return;
  }
  const fields = {};
  ED_FIELDS.forEach(f => { fields[f] = document.getElementById('ed_' + f).value; });

  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving…';

  post({ action: 'hero_slide_save', id, fields }).then(res => {
    btn.disabled = false; btn.innerHTML = orig;
    if (!res.success) { toast('danger', res.error || 'Save failed.'); return; }
    if (res.warning) { toast('danger', res.warning); return; }   // keep modal open
    bootstrap.Modal.getInstance(document.getElementById('slideEditor')).hide();
    location.reload();
  }).catch(() => {
    btn.disabled = false; btn.innerHTML = orig;
    toast('danger', 'Network error.');
  });
}

function toggleSlide(id) {
  post({ action: 'hero_slide_toggle', id }).then(res => {
    if (res.success) location.reload(); else toast('danger', res.error || 'Failed.');
  }).catch(() => toast('danger', 'Network error.'));
}

function moveSlide(id, dir) {
  post({ action: 'hero_slide_move', id, dir }).then(res => {
    if (res.success) location.reload(); else toast('danger', res.error || 'Failed.');
  }).catch(() => toast('danger', 'Network error.'));
}

function deleteSlide(id) {
  if (!confirm('Delete this slide? The image file is removed from the server too.')) return;
  post({ action: 'hero_slide_delete', id }).then(res => {
    if (res.success) location.reload(); else toast('danger', res.error || 'Failed.');
  }).catch(() => toast('danger', 'Network error.'));
}

/* ── Settings ────────────────────────────────────────────── */
const rInterval = document.getElementById('hero_carousel_interval');
const rOverlay  = document.getElementById('hero_overlay_opacity');

rInterval.addEventListener('input', () => {
  document.getElementById('intervalLabel').textContent = (rInterval.value / 1000).toFixed(1);
  restartPreview();
});
rOverlay.addEventListener('input', () => {
  document.getElementById('overlayLabel').textContent = rOverlay.value;
  applyWash();
});

function applyWash() {
  document.getElementById('previewWash').style.opacity = (rOverlay.value / 100).toFixed(2);
}

function saveCarousel(btn) {
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving…';
  post({
    action: 'save_settings',
    section: 'landing_hero',
    data: {
      hero_carousel_enabled:  document.getElementById('hero_carousel_enabled').checked ? '1' : '0',
      hero_carousel_interval: rInterval.value,
      hero_carousel_effect:   document.getElementById('hero_carousel_effect').value,
      hero_overlay_opacity:   rOverlay.value
    }
  }).then(res => {
    btn.disabled = false; btn.innerHTML = orig;
    toast(res.success ? 'success' : 'danger', res.success ? 'Carousel settings saved.' : (res.error || 'Save failed.'));
  }).catch(() => { btn.disabled = false; btn.innerHTML = orig; toast('danger', 'Network error.'); });
}

/* ── Preview rotation (image + copy) ─────────────────────── */
let previewTimer = null;

function paintPreviewCopy(img) {
  if (!img) return;
  document.getElementById('pvEyebrow').textContent  = img.dataset.eyebrow  || '';
  document.getElementById('pvHeadline').textContent = img.dataset.headline || 'Your headline sits here';
  const ctas = [];
  if (img.dataset.cta1) ctas.push('<span class="p"></span>');
  if (img.dataset.cta2) ctas.push('<span class="s"></span>');
  const box = document.getElementById('pvCtas');
  box.innerHTML = ctas.join('');
  // set label text safely (never inject admin-entered strings as HTML)
  if (img.dataset.cta1 && box.children[0]) box.children[0].textContent = img.dataset.cta1;
  if (img.dataset.cta2 && box.children[ctas.length - 1]) box.children[ctas.length - 1].textContent = img.dataset.cta2;
}

function restartPreview() {
  const imgs = document.querySelectorAll('.preview-slide');
  clearInterval(previewTimer);
  if (!imgs.length) return;
  paintPreviewCopy(imgs[0]);
  if (imgs.length < 2) return;
  let i = 0;
  previewTimer = setInterval(() => {
    imgs[i].classList.remove('on');
    i = (i + 1) % imgs.length;
    imgs[i].classList.add('on');
    paintPreviewCopy(imgs[i]);
  }, Math.max(2000, +rInterval.value));
}
applyWash();
restartPreview();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
