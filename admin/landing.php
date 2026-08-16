<?php
/**
 * admin/landing.php — Landing page hero carousel manager (super admin).
 *
 * Uploads/reorders/toggles the background slides shown behind the hero on
 * the public landing page, plus the carousel behaviour settings.
 */
$pageTitle = 'Landing Page';
require_once __DIR__ . '/../includes/header-admin.php';

$projectRoot = dirname(__DIR__);
$flashMsg    = null;
$flashType   = 'success';

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

    if ($added)  { $flashMsg = "$added image" . ($added > 1 ? 's' : '') . ' uploaded.'; }
    if ($errors) { $flashMsg = ($flashMsg ? $flashMsg . ' ' : '') . implode(' | ', $errors); $flashType = $added ? 'warning' : 'danger'; }
}

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

<?php if ($flashMsg): ?>
<div class="alert alert-<?= $flashType ?> alert-dismissible fade show">
  <?= $flashMsg ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

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
.preview-hero .cap { position:absolute; left:1rem; bottom:1rem; color:#fff; font-weight:800; z-index:2; text-shadow:0 2px 8px rgba(0,0,0,.6); }
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
                  <input type="text" class="form-control form-control-sm mb-2"
                         value="<?= htmlspecialchars($sl['alt_text'], ENT_QUOTES) ?>"
                         placeholder="Alt text (accessibility)"
                         onchange="updateSlide(<?= (int)$sl['id'] ?>,'alt_text',this.value,this)">
                  <input type="text" class="form-control form-control-sm mb-2"
                         value="<?= htmlspecialchars($sl['caption'], ENT_QUOTES) ?>"
                         placeholder="Caption (optional)"
                         onchange="updateSlide(<?= (int)$sl['id'] ?>,'caption',this.value,this)">
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
        <div class="preview-hero" id="previewHero">
          <div class="wash" id="previewWash"></div>
          <?php foreach (array_values(array_filter($slides, fn($s) => $s['status'] === 'active')) as $j => $sl): ?>
            <img src="<?= APP_URL . '/' . htmlspecialchars($sl['image_path'], ENT_QUOTES) ?>"
                 alt="" class="preview-slide <?= $j === 0 ? 'on' : '' ?>">
          <?php endforeach; ?>
          <div class="cap">Your headline sits here</div>
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

/* ── Slide CRUD ──────────────────────────────────────────── */
function updateSlide(id, field, value, el) {
  post({ action: 'hero_slide_update', id, field, value }).then(res => {
    if (res.success) { el.classList.add('is-valid'); setTimeout(() => el.classList.remove('is-valid'), 1200); }
    else toast('danger', res.error || 'Update failed.');
  }).catch(() => toast('danger', 'Network error.'));
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

/* ── Preview rotation ────────────────────────────────────── */
let previewTimer = null;
function restartPreview() {
  const imgs = document.querySelectorAll('.preview-slide');
  clearInterval(previewTimer);
  if (imgs.length < 2) return;
  let i = 0;
  previewTimer = setInterval(() => {
    imgs[i].classList.remove('on');
    i = (i + 1) % imgs.length;
    imgs[i].classList.add('on');
  }, Math.max(2000, +rInterval.value));
}
applyWash();
restartPreview();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
