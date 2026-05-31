<?php
// Output buffer prevents any stray output from corrupting redirects or JSON
ob_start();

// ── Bootstrap ────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// Staff members cannot access the module marketplace
if (($user['role'] ?? '') === 'staff') {
    ob_end_clean();
    setFlash('danger', 'Access denied. Module management is handled by your organisation administrator.');
    redirect(APP_URL . '/client/index.php');
}
$sub   = getOrgSubscription($orgId);

// Ensure CSRF token is initialised before any POST handler reads it
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── POST: Deactivate module ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deactivate') {
    verifyCsrf();
    $slug = sanitize($_POST['module_slug'] ?? '');
    if ($slug) {
        try {
            // Use org_id via subscriptions join — works regardless of which subscription the module is on
            $pdo->prepare("
                UPDATE subscription_modules sm
                INNER JOIN subscriptions s  ON sm.subscription_id = s.id
                INNER JOIN modules m        ON sm.module_id = m.id
                SET sm.status = 'inactive'
                WHERE s.org_id = ? AND m.slug = ?
            ")->execute([$orgId, $slug]);
            setFlash('info', 'Module deactivated. You can reactivate it anytime by purchasing again.');
        } catch (Throwable $e) {
            error_log('[deactivate_module] ' . $e->getMessage());
            setFlash('danger', 'Could not deactivate module. Please try again.');
        }
    }
    redirect(APP_URL . '/client/modules.php');
}

// ── POST: Add module ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_module') {
    verifyCsrf();

    $slug = sanitize($_POST['module_slug'] ?? '');
    $sub  = getOrgSubscription($orgId);

    if (!$sub) {
        setFlash('danger', 'You need an active subscription before adding modules.');
        redirect(APP_URL . '/client/modules.php');
    }

    // Load module
    $mod = null;
    try {
        $s = $pdo->prepare("SELECT * FROM modules WHERE slug = ? AND status = 'active'");
        $s->execute([$slug]);
        $mod = $s->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('[add_module mod] ' . $e->getMessage());
    }
    if (!$mod) {
        setFlash('danger', 'Module not found. Please refresh and try again.');
        redirect(APP_URL . '/client/modules.php');
    }

    // Already active?
    try {
        $chk = $pdo->prepare("
            SELECT sm.id FROM subscription_modules sm
            INNER JOIN subscriptions s ON sm.subscription_id = s.id
            WHERE s.org_id = ? AND sm.module_id = ? AND sm.status = 'active'
              AND s.status IN ('active','trial')
        ");
        $chk->execute([$orgId, $mod['id']]);
        if ($chk->fetch()) {
            setFlash('info', 'This module is already active on your workspace.');
            redirect(APP_URL . '/client/modules.php');
        }
    } catch (Throwable $e) {
        error_log('[add_module active_check] ' . $e->getMessage());
    }

    // Already has an unpaid invoice for this module?
    try {
        $dup = $pdo->prepare("
            SELECT id FROM invoices
            WHERE org_id = ? AND module_id = ? AND status IN ('draft','sent','overdue')
        ");
        $dup->execute([$orgId, $mod['id']]);
        if ($existing = $dup->fetch()) {
            setFlash('warning', 'You already have a pending invoice for this module. Complete that payment to activate it.');
            redirect(APP_URL . '/client/billing.php?tab=pay&inv=' . (int)$existing['id']);
        }
    } catch (Throwable $e) {
        // module_id column may not exist in this deployment — skip the duplicate check
        error_log('[add_module dup_check] ' . $e->getMessage());
    }

    // Build and insert invoice
    // Guard 1: module price must be realistic (no more than KES 999,999/month)
    $price = max(0.0, (float)$mod['monthly_price']);
    if ($price > 999_999) {
        error_log("[add_module] BLOCKED: module '{$mod['slug']}' has price KES {$price} which exceeds the KES 999,999 safety limit. Admin must fix module pricing.");
        setFlash('danger', 'This module cannot be subscribed right now due to a pricing configuration issue. Please contact support.');
        redirect(APP_URL . '/client/modules.php');
    }
    if ($price <= 0) {
        error_log("[add_module] BLOCKED: module '{$mod['slug']}' has a zero/negative price ({$price}).");
        setFlash('danger', 'This module has no price configured. Please contact support.');
        redirect(APP_URL . '/client/modules.php');
    }

    // Guard 2: tax rate must be 0–100 %
    $cfgTaxRaw = (float)(getSettings(['invoice_tax_rate'])['invoice_tax_rate'] ?? 16);
    $cfgTax    = max(0.0, min(100.0, $cfgTaxRaw));
    $prefix    = preg_replace('/[^A-Z0-9\-_]/i', '', strtoupper(getSettings(['invoice_prefix'])['invoice_prefix'] ?? 'INV')) ?: 'INV';
    $tax       = round($price * ($cfgTax / 100), 2);
    $total     = round($price + $tax, 2);

    $invoiceNo = $prefix . '-' . strtoupper(substr(md5(uniqid($orgId, true)), 0, 8));
    $dueDate   = date('Y-m-d', strtotime('+7 days'));
    $invoiceId = 0;

    try {
        $pdo->prepare("
            INSERT INTO invoices
                (org_id, subscription_id, module_id, invoice_number, amount, tax, total, status, due_date, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', ?, ?)
        ")->execute([
            $orgId, $sub['id'], $mod['id'],
            $invoiceNo, $price, $tax, $total, $dueDate,
            "Module activation: {$mod['name']}"
        ]);
        $invoiceId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[add_module insert1] ' . $e->getMessage());
        // Fallback: try without module_id (older schema)
        try {
            $pdo->prepare("
                INSERT INTO invoices
                    (org_id, subscription_id, invoice_number, amount, tax, total, status, due_date, notes)
                VALUES (?, ?, ?, ?, ?, ?, 'sent', ?, ?)
            ")->execute([
                $orgId, $sub['id'],
                $invoiceNo, $price, $tax, $total, $dueDate,
                "Module activation: {$mod['name']}"
            ]);
            $invoiceId = (int)$pdo->lastInsertId();
        } catch (Throwable $e2) {
            error_log('[add_module insert2] ' . $e2->getMessage());
            setFlash('danger', 'Could not create invoice: ' . htmlspecialchars($e2->getMessage()));
            redirect(APP_URL . '/client/modules.php');
        }
    }

    try { logActivity('create', 'billing', "Invoice {$invoiceNo} — {$mod['name']}"); } catch (Throwable $e) {}

    setFlash('success', "Invoice <strong>{$invoiceNo}</strong> created for <strong>" . htmlspecialchars($mod['name']) . "</strong>. Choose a payment method below.");
    redirect(APP_URL . '/client/billing.php?tab=pay&inv=' . $invoiceId);
}

// ── Render page ──────────────────────────────────────────────────
ob_end_clean(); // discard any stray bootstrap output before HTML starts
$pageTitle = 'Module Marketplace';
require_once __DIR__ . '/../includes/header-client.php';

$sub = getOrgSubscription($orgId);

// Active module slugs
$activeSlug = [];
try {
    $stmtAct = $pdo->prepare("
        SELECT m.slug FROM modules m
        INNER JOIN subscription_modules sm ON m.id = sm.module_id
        INNER JOIN subscriptions s ON sm.subscription_id = s.id
        WHERE s.org_id = ? AND s.status IN ('active','trial')
          AND sm.status = 'active' AND m.status = 'active'
    ");
    $stmtAct->execute([$orgId]);
    $activeSlug = array_column($stmtAct->fetchAll(), 'slug');
} catch (Throwable $e) { error_log('[modules active] ' . $e->getMessage()); }

// Pending-invoice module IDs / slugs
$pendingBySlug = [];
try {
    $stmtPend = $pdo->prepare("
        SELECT m.slug, i.id AS invoice_id FROM modules m
        INNER JOIN invoices i ON i.module_id = m.id
        WHERE i.org_id = ? AND i.status IN ('draft','sent','overdue')
    ");
    $stmtPend->execute([$orgId]);
    foreach ($stmtPend->fetchAll() as $row) {
        $pendingBySlug[$row['slug']] = (int)$row['invoice_id'];
    }
} catch (Throwable $e) { error_log('[modules pending] ' . $e->getMessage()); }

$allModules = [];
try {
    $allModules = $pdo->query("SELECT * FROM modules WHERE status='active' ORDER BY sort_order, name")->fetchAll();
} catch (Throwable $e) { error_log('[modules list] ' . $e->getMessage()); }
$categories   = array_unique(array_filter(array_column($allModules, 'category')));
$activeCount  = count($activeSlug);
$pendingCount = count($pendingBySlug);

// USD exchange rate — read from admin settings
$usdRate = max(1, (float)(getSetting('usd_rate', '130') ?: 130));

// Embed module details for JS modal (all modules, keyed by slug)
$moduleMap = [];
foreach ($allModules as $m) {
    $kesMo  = (float)$m['monthly_price'];
    $kesAnn = (float)$m['annual_price'];
    $moduleMap[$m['slug']] = [
        'name'          => $m['name'],
        'desc'          => $m['description'],
        'icon'          => $m['icon'],
        'color'         => $m['color'],
        'price'         => $kesMo,                                             // KES monthly
        'price_usd'     => $kesMo  > 0 ? round($kesMo  / $usdRate, 2) : 0,   // USD monthly
        'price_ann'     => $kesAnn,                                            // KES annual
        'price_ann_usd' => $kesAnn > 0 ? round($kesAnn / $usdRate, 2) : 0,    // USD annual
        'cat'           => $m['category'],
        'slug'          => $m['slug'],
    ];
}

$userPhone  = preg_replace('/[^0-9+]/', '', $user['phone'] ?? '');
$csrfToken  = $_SESSION['csrf_token']; // guaranteed set above
?>

<!-- Page Header -->
<div class="page-header d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4><i class="fas fa-store me-2 text-green"></i>Module Marketplace</h4>
    <p class="text-muted mb-0 small">Add modules to your workspace — pay instantly via M-Pesa</p>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <!-- Currency toggle -->
    <div style="display:inline-flex;background:#f1f5f9;border:1.5px solid #e2e8f0;border-radius:999px;overflow:hidden">
      <button id="mktBtnUSD" onclick="setCurrency('USD')"
              style="border:none;padding:.3rem .85rem;font-size:.75rem;font-weight:700;cursor:pointer;transition:all .18s;background:#0B2D4E;color:#fff;border-radius:999px">
        $ USD
      </button>
      <button id="mktBtnKES" onclick="setCurrency('KES')"
              style="border:none;padding:.3rem .85rem;font-size:.75rem;font-weight:700;cursor:pointer;transition:all .18s;background:transparent;color:#64748b;border-radius:999px">
        KES
      </button>
    </div>
    <div class="input-group input-group-sm" style="width:210px">
      <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted" style="font-size:.8rem"></i></span>
      <input type="text" id="moduleSearch" class="form-control border-start-0 ps-1" placeholder="Search modules…" style="font-size:.82rem">
    </div>
    <a href="<?= APP_URL ?>/client/billing.php?tab=invoices" class="btn btn-outline-primary btn-sm">
      <i class="fas fa-file-invoice me-1"></i>My Invoices
      <?php if ($pendingCount > 0): ?><span class="badge bg-warning text-dark ms-1"><?= $pendingCount ?></span><?php endif; ?>
    </a>
  </div>
</div>

<?php if (!$sub): ?>
<div class="alert alert-warning mb-3">
  <i class="fas fa-exclamation-triangle me-2"></i>
  No active subscription. <a href="<?= APP_URL ?>/client/billing.php" class="fw-bold">Choose a plan</a> to start adding modules.
</div>
<?php endif; ?>

<?php if ($pendingCount > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-3" style="border-radius:10px">
  <i class="fas fa-hourglass-half fa-lg flex-shrink-0 text-warning"></i>
  <div>
    <strong><?= $pendingCount ?> module<?= $pendingCount > 1 ? 's' : '' ?> pending payment.</strong>
    Complete payment to activate <?= $pendingCount > 1 ? 'them' : 'it' ?> instantly.
    <a href="<?= APP_URL ?>/client/billing.php?tab=invoices" class="fw-bold ms-2">Pay Now &rarr;</a>
  </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Active</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-store"></i></div>
      <div><div class="stat-value"><?= count($allModules) ?></div><div class="stat-label">Available</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card warning">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div><div class="stat-value"><?= $pendingCount ?></div><div class="stat-label">Pending Payment</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-puzzle-piece"></i></div>
      <div><div class="stat-value"><?= count($allModules) - $activeCount ?></div><div class="stat-label">Not Activated</div></div>
    </div>
  </div>
</div>

<!-- Category filter tabs -->
<div class="mb-3 d-flex gap-2 flex-wrap align-items-center">
  <button class="btn btn-sm btn-primary category-btn" data-cat="all">
    <i class="fas fa-th me-1"></i>All <span class="badge bg-white text-primary ms-1" style="font-size:.6rem"><?= count($allModules) ?></span>
  </button>
  <?php foreach ($categories as $cat):
    $catCount = count(array_filter($allModules, fn($m) => $m['category'] === $cat));
  ?>
  <button class="btn btn-sm btn-outline-secondary category-btn" data-cat="<?= e($cat) ?>">
    <?= e($cat) ?> <span class="badge bg-secondary ms-1" style="font-size:.6rem"><?= $catCount ?></span>
  </button>
  <?php endforeach; ?>
</div>

<!-- Search no-results indicator -->
<div id="noResults" class="d-none text-center py-5 text-muted">
  <i class="fas fa-search fa-2x mb-3 d-block opacity-40"></i>
  No modules match your search.
</div>

<!-- Modules grid -->
<div class="row g-3" id="modulesGrid">
  <?php foreach ($allModules as $m):
    $isActive  = in_array($m['slug'], $activeSlug);
    $isPending = isset($pendingBySlug[$m['slug']]);
    $pendingId = $isPending ? $pendingBySlug[$m['slug']] : 0;
    $kesMo        = (float)$m['monthly_price'];
    $usdMo        = $kesMo > 0 ? round($kesMo / $usdRate, 2) : 0;
    $taxRate      = 16;
    $displayTotal = $kesMo * 1.16; // KES total incl. VAT — used for M-Pesa (always KES)
  ?>
  <div class="col-6 col-md-4 col-lg-3 module-col"
       data-category="<?= e($m['category']) ?>"
       data-name="<?= e(strtolower($m['name'])) ?>">
    <div class="card h-100 border-0 shadow-sm position-relative module-card"
         style="border-radius:12px;transition:.15s;<?= $isActive ? 'border:2px solid #1A8A4E!important' : ($isPending ? 'border:2px solid #ffc107!important' : '') ?>">

      <!-- Status ribbon -->
      <?php if ($isActive): ?>
        <span class="position-absolute top-0 end-0 m-2 badge bg-success" style="font-size:.6rem;z-index:1"><i class="fas fa-check me-1"></i>ACTIVE</span>
      <?php elseif ($isPending): ?>
        <span class="position-absolute top-0 end-0 m-2 badge bg-warning text-dark" style="font-size:.6rem;z-index:1"><i class="fas fa-clock me-1"></i>PENDING</span>
      <?php endif; ?>

      <div class="card-body d-flex flex-column text-center p-3">
        <!-- Icon -->
        <div class="mx-auto mb-2 d-flex align-items-center justify-content-center"
             style="width:52px;height:52px;border-radius:14px;background:<?= e($m['color']) ?>1a;color:<?= e($m['color']) ?>;font-size:1.4rem;flex-shrink:0">
          <i class="<?= e($m['icon']) ?>"></i>
        </div>

        <h6 class="fw-700 mb-0" style="font-size:.88rem"><?= e($m['name']) ?></h6>
        <span class="badge bg-light text-secondary mb-2" style="font-size:.58rem"><?= e($m['category']) ?></span>

        <p class="text-muted flex-grow-1 mb-2" style="font-size:.7rem;line-height:1.4;min-height:36px">
          <?= e(mb_strimwidth($m['description'], 0, 72, '…')) ?>
        </p>
        <button class="btn btn-link btn-sm p-0 mb-2 text-muted" style="font-size:.68rem"
                onclick="openDetail('<?= e($m['slug']) ?>')">
          <i class="fas fa-info-circle me-1"></i>More details
        </button>

        <!-- Price — data-* attrs hold both currencies; JS switches display -->
        <?php if (!$isActive): ?>
        <div class="mod-price-display fw-bold text-dark mb-1" style="font-size:1.05rem"
             data-kes="<?= number_format($kesMo, 2) ?>"
             data-usd="<?= number_format($usdMo, 2) ?>">
          <span class="price-val">$ <?= number_format($usdMo, 2) ?></span>
          <span class="fw-normal text-muted" style="font-size:.66rem">+VAT/mo</span>
        </div>
        <div class="text-muted mb-2 mod-price-sub" style="font-size:.68rem"
             data-kes="<?= number_format($kesMo, 2) ?>"
             data-usd="<?= number_format($usdMo, 2) ?>">
          ≈ KES <?= number_format($kesMo, 2) ?>
        </div>
        <?php endif; ?>

        <!-- CTA -->
        <?php if ($isActive): ?>
          <p class="text-success small fw-600 mb-2"><i class="fas fa-check-circle me-1"></i>Module Active</p>
          <a href="<?= APP_URL ?>/modules/<?= e($m['slug']) ?>/index.php" class="btn btn-sm btn-primary w-100 mb-2">
            <i class="fas fa-external-link-alt me-1"></i>Open Module
          </a>
          <button type="button" class="btn btn-sm btn-outline-danger w-100"
                  onclick="confirmDeactivate('<?= e($m['slug']) ?>', '<?= e(addslashes($m['name'])) ?>')">
            <i class="fas fa-power-off me-1"></i>Deactivate
          </button>

        <?php elseif ($isPending): ?>
          <p class="fw-600 mb-2" style="color:#856404;font-size:.78rem"><i class="fas fa-hourglass-half me-1"></i>Invoice awaiting payment</p>
          <a href="<?= APP_URL ?>/client/billing.php?inv=<?= $pendingId ?>"
             class="btn btn-sm btn-warning w-100">
            <i class="fas fa-credit-card me-1"></i>Pay to Activate
          </a>

        <?php else: ?>
          <?php if (!$sub): ?>
            <a href="<?= APP_URL ?>/client/billing.php" class="btn btn-sm btn-outline-secondary w-100">
              <i class="fas fa-lock me-1"></i>Subscribe First
            </a>
          <?php else: ?>
            <form method="POST" action="">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="add_module">
              <input type="hidden" name="module_slug" value="<?= e($m['slug']) ?>">
              <button type="submit" class="btn btn-sm w-100 text-white fw-700"
                      style="background:<?= e($m['color']) ?>;border-radius:8px">
                <i class="fas fa-plus-circle me-1"></i>Add &amp; Pay
              </button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ═══ Module Detail Modal ══════════════════════════════════════ -->
<div class="modal fade" id="detailModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow" style="border-radius:16px;overflow:hidden">
      <div class="modal-header border-0 pb-0" id="detailHeader" style="background:#f8fafc">
        <div class="d-flex align-items-center gap-3 w-100">
          <div id="detailIcon" class="d-flex align-items-center justify-content-center flex-shrink-0"
               style="width:52px;height:52px;border-radius:14px;font-size:1.4rem"></div>
          <div>
            <h5 class="modal-title fw-800 mb-0" id="detailTitle"></h5>
            <span class="badge bg-light text-secondary mt-1" id="detailCat" style="font-size:.62rem"></span>
          </div>
        </div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-3">
        <p class="text-muted" id="detailDesc" style="font-size:.88rem;line-height:1.6"></p>
        <div class="d-flex justify-content-between align-items-center p-3 rounded-2" style="background:#f0fdf4" id="detailPriceRow">
          <span class="text-muted small">Monthly price</span>
          <div class="text-end">
            <div class="fw-800 text-dark" id="detailPrice" style="font-size:1.2rem"></div>
            <div class="text-muted" style="font-size:.68rem">+16% VAT/month</div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0 gap-2">
        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-sm text-white fw-700" id="detailAddBtn" style="border-radius:8px">
          <i class="fas fa-plus-circle me-1"></i>Add &amp; Pay
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Hidden forms submitted by JS — action="" posts to current URL, avoids http/https mismatch -->
<form method="POST" action="" id="deactivateForm">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="deactivate">
  <input type="hidden" name="module_slug" id="deactivateSlug">
</form>
<form method="POST" action="" id="addModuleForm">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="add_module">
  <input type="hidden" name="module_slug" id="addModuleSlug">
</form>

<script>
const MODULE_MAP = <?= json_encode($moduleMap, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' ?>;
const USD_RATE   = <?= (float)$usdRate ?>;

function fmtKES(n) {
  return 'KES ' + Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
function fmtUSD(n) {
  return '$ ' + Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// ── Currency toggle ───────────────────────────────────────────────
let activeCur = localStorage.getItem('mktCurrency') || 'USD';

function setCurrency(cur) {
  activeCur = cur;
  localStorage.setItem('mktCurrency', cur);
  updateMarketplacePrices();
}

function updateMarketplacePrices() {
  const isUSD = activeCur === 'USD';

  // Toggle button styles
  const uBtn = document.getElementById('mktBtnUSD');
  const kBtn = document.getElementById('mktBtnKES');
  if (uBtn && kBtn) {
    uBtn.style.background = isUSD  ? '#0B2D4E' : 'transparent';
    uBtn.style.color      = isUSD  ? '#fff'    : '#64748b';
    kBtn.style.background = !isUSD ? '#0B2D4E' : 'transparent';
    kBtn.style.color      = !isUSD ? '#fff'    : '#64748b';
  }

  // Update card primary price values
  document.querySelectorAll('.mod-price-display').forEach(function(el) {
    const priceEl = el.querySelector('.price-val');
    if (!priceEl) return;
    const val = isUSD ? el.dataset.usd : el.dataset.kes;
    priceEl.textContent = (isUSD ? '$ ' : 'KES ') + val;
  });

  // Update secondary "≈ ..." lines
  document.querySelectorAll('.mod-price-sub').forEach(function(el) {
    const altVal = isUSD ? el.dataset.kes : el.dataset.usd;
    el.textContent = isUSD ? '≈ KES ' + altVal : '≈ $ ' + altVal;
  });
}

// Apply saved preference on page load
updateMarketplacePrices();

// ── Category filter ───────────────────────────────────────────────
document.querySelectorAll('.category-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.category-btn').forEach(b => {
      b.classList.replace('btn-primary', 'btn-outline-secondary');
    });
    btn.classList.replace('btn-outline-secondary', 'btn-primary');
    applyFilters();
  });
});

// ── Search filter ─────────────────────────────────────────────────
document.getElementById('moduleSearch').addEventListener('input', applyFilters);

function applyFilters() {
  const cat     = document.querySelector('.category-btn.btn-primary')?.dataset.cat ?? 'all';
  const term    = document.getElementById('moduleSearch').value.trim().toLowerCase();
  let   visible = 0;
  document.querySelectorAll('.module-col').forEach(col => {
    const catMatch  = cat === 'all' || col.dataset.category === cat;
    const nameMatch = !term || col.dataset.name.includes(term);
    const show      = catMatch && nameMatch;
    col.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('noResults').classList.toggle('d-none', visible > 0);
}

// ── Module detail modal ───────────────────────────────────────────
function openDetail(slug) {
  const m = MODULE_MAP[slug];
  if (!m) return;
  const isUSD  = activeCur === 'USD';
  const primary   = isUSD ? fmtUSD(m.price_usd) : fmtKES(m.price);
  const secondary = isUSD ? '≈ ' + fmtKES(m.price) : '≈ ' + fmtUSD(m.price_usd);
  const annLine = m.price_ann > 0
    ? (isUSD
        ? '$ ' + Number(m.price_ann_usd).toFixed(2) + '/yr  (≈ KES ' + Number(m.price_ann).toLocaleString('en-KE') + ')'
        : 'KES ' + Number(m.price_ann).toLocaleString('en-KE') + '/yr  (≈ $ ' + Number(m.price_ann_usd).toFixed(2) + ')')
    : '';

  document.getElementById('detailTitle').textContent = m.name;
  document.getElementById('detailCat').textContent   = m.cat;
  document.getElementById('detailDesc').textContent  = m.desc;
  document.getElementById('detailPrice').innerHTML   =
    '<span class="fw-bold">' + primary + '</span>' +
    '<span class="text-muted ms-2 small">' + secondary + '</span>' +
    (annLine ? '<div class="text-muted mt-1" style="font-size:.72rem">' + annLine + '/yr</div>' : '');

  const iconEl = document.getElementById('detailIcon');
  iconEl.style.background = m.color + '1a';
  iconEl.style.color      = m.color;
  iconEl.innerHTML        = '<i class="' + m.icon + '"></i>';

  const addBtn = document.getElementById('detailAddBtn');
  addBtn.style.background = m.color;
  addBtn.onclick = function() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('detailModal')).hide();
    document.getElementById('addModuleSlug').value = slug;
    document.getElementById('addModuleForm').submit();
  };

  bootstrap.Modal.getOrCreateInstance(document.getElementById('detailModal')).show();
}

// ── Deactivate ───────────────────────────────────────────────────
function confirmDeactivate(slug, name) {
  if (!confirm('Deactivate ' + name + '?\n\nYour data will be preserved. You can reactivate by purchasing again.')) return;
  document.getElementById('deactivateSlug').value = slug;
  document.getElementById('deactivateForm').submit();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
