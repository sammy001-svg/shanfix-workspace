<?php
// ── Bootstrap (no HTML yet) ──────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$sub   = getOrgSubscription($orgId);

// ── TEMP DEBUG (remove after fixing) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $debugInfo = date('Y-m-d H:i:s') . "\n"
        . 'action='       . ($_POST['action']       ?? 'MISSING') . "\n"
        . 'module_slug='  . ($_POST['module_slug']  ?? 'MISSING') . "\n"
        . 'role='         . ($_SESSION['user_role'] ?? 'NO_ROLE') . "\n"
        . 'csrf_session=' . ($_SESSION['csrf_token'] ?? 'NO_CSRF_IN_SESSION') . "\n"
        . 'csrf_post='    . ($_POST['_token']        ?? 'NO_TOKEN_IN_POST') . "\n"
        . 'match='        . (($_SESSION['csrf_token'] ?? '') === ($_POST['_token'] ?? 'x') ? 'YES' : 'NO') . "\n\n";
    file_put_contents(__DIR__ . '/../debug_modules.txt', $debugInfo, FILE_APPEND);
}
// ── END TEMP DEBUG ───────────────────────────────────────────────

// ── POST: Deactivate module ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deactivate') {
    verifyCsrf();
    $slug = sanitize($_POST['module_slug'] ?? '');
    if ($sub && $slug) {
        $pdo->prepare("
            UPDATE subscription_modules sm
            INNER JOIN modules m ON sm.module_id = m.id
            SET sm.status = 'inactive'
            WHERE sm.subscription_id = ? AND m.slug = ?
        ")->execute([$sub['id'], $slug]);
        setFlash('info', 'Module deactivated. You can reactivate it anytime by purchasing again.');
    }
    redirect(APP_URL . '/client/modules.php');
}

// ── POST: Add module → auto-generate invoice ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_module') {
    verifyCsrf();
    $slug = sanitize($_POST['module_slug'] ?? '');

    if (!$sub) {
        setFlash('error', 'You need an active subscription before adding modules.');
        redirect(APP_URL . '/client/billing.php');
    }

    // Load module
    $stmtMod = $pdo->prepare("SELECT * FROM modules WHERE slug = ? AND status = 'active'");
    $stmtMod->execute([$slug]);
    $mod = $stmtMod->fetch();
    if (!$mod) {
        setFlash('error', 'Module not found.');
        redirect(APP_URL . '/client/modules.php');
    }

    // Already active?
    $stmtChk = $pdo->prepare("
        SELECT sm.id FROM subscription_modules sm
        INNER JOIN subscriptions s ON sm.subscription_id = s.id
        WHERE s.org_id = ? AND sm.module_id = ? AND sm.status = 'active'
          AND s.status IN ('active','trial')
    ");
    $stmtChk->execute([$orgId, $mod['id']]);
    if ($stmtChk->fetch()) {
        setFlash('info', 'This module is already active on your workspace.');
        redirect(APP_URL . '/client/modules.php');
    }

    // Already has an unpaid invoice for this module?
    $stmtDup = $pdo->prepare("
        SELECT id FROM invoices
        WHERE org_id = ? AND module_id = ? AND status IN ('draft','sent','overdue')
    ");
    $stmtDup->execute([$orgId, $mod['id']]);
    if ($stmtDup->fetch()) {
        setFlash('warning', 'You already have a pending invoice for this module. Please complete that payment first.');
        redirect(APP_URL . '/client/billing.php?tab=invoices');
    }

    // Create invoice
    $price     = (float)$mod['monthly_price'];
    $tax       = round($price * 0.16, 2);
    $total     = $price + $tax;
    $invoiceNo = 'INV-' . strtoupper(substr(md5(uniqid($orgId, true)), 0, 8));
    $dueDate   = date('Y-m-d', strtotime('+7 days'));

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
    logActivity('create', 'billing', "Invoice {$invoiceNo} — {$mod['name']}");
    setFlash('success', "Invoice <strong>{$invoiceNo}</strong> generated for <strong>{$mod['name']}</strong>. Complete payment below to activate it.");
    redirect(APP_URL . '/client/billing.php?tab=pay&inv=' . $invoiceId);
}

// ── Render page ──────────────────────────────────────────────────
$pageTitle = 'My Modules';
require_once __DIR__ . '/../includes/header-client.php';

// Reload $sub after header (header re-runs getOrgModules etc.)
$sub = getOrgSubscription($orgId);

// Active module slugs
$stmtAct = $pdo->prepare("
    SELECT m.slug FROM modules m
    INNER JOIN subscription_modules sm ON m.id = sm.module_id
    INNER JOIN subscriptions s ON sm.subscription_id = s.id
    WHERE s.org_id = ? AND s.status IN ('active','trial')
      AND sm.status = 'active' AND m.status = 'active'
");
$stmtAct->execute([$orgId]);
$activeSlug = array_column($stmtAct->fetchAll(), 'slug');

// Pending-invoice module slugs
$pendingSlug = [];
try {
    $stmtPend = $pdo->prepare("
        SELECT m.slug FROM modules m
        INNER JOIN invoices i ON i.module_id = m.id
        WHERE i.org_id = ? AND i.status IN ('draft','sent','overdue')
    ");
    $stmtPend->execute([$orgId]);
    $pendingSlug = array_column($stmtPend->fetchAll(), 'slug');
} catch (Exception $e) {}

$allModules  = $pdo->query("SELECT * FROM modules WHERE status='active' ORDER BY sort_order")->fetchAll();
$categories  = array_unique(array_column($allModules, 'category'));
$activeCount = count($activeSlug);
$pendingCount= count($pendingSlug);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4><i class="fas fa-th me-2 text-green"></i>Module Marketplace</h4>
    <p class="text-muted mb-0">Add, activate, and manage modules for your workspace</p>
  </div>
  <a href="<?= APP_URL ?>/client/billing.php?tab=invoices" class="btn btn-outline-primary btn-sm">
    <i class="fas fa-file-invoice me-1"></i>My Invoices
    <?php if ($pendingCount > 0): ?>
    <span class="badge bg-warning text-dark ms-1"><?= $pendingCount ?></span>
    <?php endif; ?>
  </a>
</div>

<?php if (!$sub): ?>
<div class="alert alert-warning">
  <i class="fas fa-exclamation-triangle me-2"></i>
  No active subscription found. <a href="<?= APP_URL ?>/client/billing.php" class="fw-bold">Choose a plan</a> to start adding modules.
</div>
<?php endif; ?>

<?php if ($pendingCount > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-3">
  <i class="fas fa-hourglass-half fa-lg flex-shrink-0"></i>
  <div>
    <strong><?= $pendingCount ?> module<?= $pendingCount > 1 ? 's' : '' ?> pending payment.</strong>
    Complete payment to activate <?= $pendingCount > 1 ? 'them' : 'it' ?> instantly.
    <a href="<?= APP_URL ?>/client/billing.php?tab=invoices" class="fw-bold ms-2">Pay Now →</a>
  </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Active Modules</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-puzzle-piece"></i></div>
      <div><div class="stat-value"><?= count($allModules) ?></div><div class="stat-label">Available</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card warning">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div><div class="stat-value"><?= $pendingCount ?></div><div class="stat-label">Awaiting Payment</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-layer-group"></i></div>
      <div><div class="stat-value"><?= count($allModules) - $activeCount ?></div><div class="stat-label">Not Activated</div></div>
    </div>
  </div>
</div>

<!-- Category filter -->
<div class="mb-3 d-flex gap-2 flex-wrap align-items-center">
  <span class="text-muted small">Filter:</span>
  <button class="btn btn-sm btn-primary category-btn" data-cat="all">All</button>
  <?php foreach ($categories as $cat): ?>
  <button class="btn btn-sm btn-outline-secondary category-btn" data-cat="<?= e($cat) ?>"><?= e($cat) ?></button>
  <?php endforeach; ?>
</div>

<!-- Modules grid -->
<div class="row g-3" id="modulesGrid">
  <?php foreach ($allModules as $m):
    $isActive  = in_array($m['slug'], $activeSlug);
    $isPending = in_array($m['slug'], $pendingSlug);
  ?>
  <div class="col-6 col-md-4 col-lg-3 module-col" data-category="<?= e($m['category']) ?>">
    <div class="module-card h-100 text-center position-relative <?= $isActive ? 'subscribed' : '' ?>"
         style="<?= $isPending ? 'border:2px solid #ffc107;' : '' ?>">

      <!-- Status badge -->
      <?php if ($isActive): ?>
        <span class="position-absolute top-0 end-0 badge bg-success m-2" style="font-size:.65rem">
          <i class="fas fa-check me-1"></i>ACTIVE
        </span>
      <?php elseif ($isPending): ?>
        <span class="position-absolute top-0 end-0 badge bg-warning text-dark m-2" style="font-size:.65rem">
          <i class="fas fa-clock me-1"></i>PENDING
        </span>
      <?php endif; ?>

      <!-- Icon -->
      <div class="module-icon mx-auto" style="background:<?= e($m['color']) ?>1a;color:<?= e($m['color']) ?>">
        <i class="<?= e($m['icon']) ?>"></i>
      </div>

      <h6 class="mb-1"><?= e($m['name']) ?></h6>
      <p class="mb-1" style="font-size:.7rem;color:#888"><?= e($m['category']) ?></p>
      <p class="mb-2 text-muted" style="font-size:.7rem;min-height:32px;line-height:1.3">
        <?= e(substr($m['description'], 0, 65)) ?>...
      </p>

      <?php if ($isActive): ?>
        <!-- ACTIVE -->
        <p class="mb-2 fw-bold text-success small"><i class="fas fa-check-circle me-1"></i>Module Active</p>
        <a href="<?= APP_URL ?>/modules/<?= e($m['slug']) ?>/index.php"
           class="btn btn-sm btn-primary w-100 mb-2">
          <i class="fas fa-external-link-alt me-1"></i>Open Module
        </a>
        <form method="POST" action="<?= APP_URL ?>/client/modules.php">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="deactivate">
          <input type="hidden" name="module_slug" value="<?= e($m['slug']) ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger w-100 btn-confirm"
                  data-msg="Deactivate this module? Your data will be preserved. You can reactivate by purchasing again.">
            <i class="fas fa-power-off me-1"></i>Deactivate
          </button>
        </form>

      <?php elseif ($isPending): ?>
        <!-- PENDING PAYMENT -->
        <p class="mb-2 fw-bold" style="color:#856404;font-size:.8rem">
          <i class="fas fa-hourglass-half me-1"></i>Invoice sent — awaiting payment
        </p>
        <div class="module-price mb-2">
          <?= formatCurrency((float)$m['monthly_price']) ?><span class="text-muted" style="font-size:.72rem">/mo</span>
        </div>
        <a href="<?= APP_URL ?>/client/billing.php?tab=invoices"
           class="btn btn-sm btn-warning w-100">
          <i class="fas fa-credit-card me-1"></i>Pay Invoice to Activate
        </a>

      <?php else: ?>
        <!-- AVAILABLE -->
        <div class="module-price mb-3">
          <?= formatCurrency((float)$m['monthly_price']) ?><span class="text-muted" style="font-size:.72rem">/mo</span>
        </div>
        <?php if (!$sub): ?>
          <a href="<?= APP_URL ?>/client/billing.php" class="btn btn-sm btn-outline-secondary w-100">
            <i class="fas fa-lock me-1"></i>Subscribe First
          </a>
        <?php else: ?>
          <form method="POST" action="<?= APP_URL ?>/client/modules.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_module">
            <input type="hidden" name="module_slug" value="<?= e($m['slug']) ?>">
            <button type="submit" class="btn btn-sm w-100 text-white fw-bold btn-confirm"
                    style="background:<?= e($m['color']) ?>"
                    data-msg="Add this module? An invoice will be generated. You can pay immediately to activate it.">
              <i class="fas fa-plus-circle me-1"></i>Add Module
            </button>
          </form>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
// Category filter
document.querySelectorAll('.category-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.category-btn').forEach(b => {
      b.classList.remove('btn-primary');
      b.classList.add('btn-outline-secondary');
    });
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-primary');
    const cat = btn.dataset.cat;
    document.querySelectorAll('.module-col').forEach(col => {
      col.style.display = (cat === 'all' || col.dataset.category === cat) ? '' : 'none';
    });
  });
});

// Confirmation before form submit
document.querySelectorAll('.btn-confirm').forEach(btn => {
  btn.addEventListener('click', function(e) {
    const msg = this.dataset.msg || 'Are you sure?';
    if (!window.confirm(msg)) {
      e.preventDefault();
    }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
