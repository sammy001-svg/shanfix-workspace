<?php
// Shared header for all module pages
// Requires: $moduleSlug, $moduleName, $moduleIcon, $moduleColor set before including
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();
requireModuleAccess($moduleSlug ?? '');
$user    = currentUser();
// Staff see only their granted modules; admins/super_admin see all
$modules = ($user['role'] === 'staff')
    ? getUserAccessibleModules((int)$user['id'], (int)$user['org_id'])
    : getOrgModules((int)$user['org_id']);
$pageTitle = ($moduleName ?? 'Module') . ' — ' . APP_NAME;

// ── Page-level RBAC guard ─────────────────────────────────────────────────
// Derives page slug from the current PHP filename (e.g. prescription.php → "prescription")
// and checks if the user's module role allows access. Always passes for index pages.
$_currentPageSlug = pathinfo(basename($_SERVER['PHP_SELF'] ?? ''), PATHINFO_FILENAME);
if (!canAccessModulePage($moduleSlug ?? '', $_currentPageSlug)) {
    setFlash('danger', 'Your assigned role does not allow access to this section. Contact your administrator.');
    header('Location: ' . APP_URL . '/modules/' . ($moduleSlug ?? '') . '/index.php');
    exit;
}
$_isReadOnly = isModuleRoleReadOnly($moduleSlug ?? '');

$_modName      = $moduleName ?? 'Module';
$_accentHex    = ltrim($moduleColor ?? '1A8A4E', '#');
$ogTitle       ??= $_modName . ' — ' . APP_NAME;
$ogDescription ??= $_modName . ' — Manage your ' . strtolower($_modName) . ' operations with ' . APP_NAME;
$ogImage       ??= APP_URL . '/api/og-image.php'
                   . '?t=' . urlencode($_modName)
                   . '&s=' . urlencode($user['org_name'] . ' · ' . APP_NAME)
                   . '&c=' . urlencode($_accentHex);
$ogUrl         = APP_URL . ($_SERVER['REQUEST_URI'] ?? '/');
?>
<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($ogTitle) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta name="robots" content="noindex, nofollow">
<!-- Open Graph -->
<meta property="og:type"        content="website">
<meta property="og:site_name"   content="<?= e(APP_NAME) ?>">
<meta property="og:title"       content="<?= e($ogTitle) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:image"       content="<?= e($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height"content="630">
<meta property="og:url"         content="<?= e($ogUrl) ?>">
<!-- Twitter Card -->
<meta name="twitter:card"       content="summary_large_image">
<meta name="twitter:title"      content="<?= e($ogTitle) ?>">
<meta name="twitter:description"content="<?= e($ogDescription) ?>">
<meta name="twitter:image"      content="<?= e($ogImage) ?>">
<!-- PWA -->
<link rel="manifest" href="<?= APP_URL ?>/manifest.php">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<meta name="theme-color" content="#1A8A4E" id="pwaThemeColor">
<link rel="apple-touch-icon" href="<?= APP_URL ?>/api/pwa-icon.php?size=192">
<script>/* Prevent dark mode FOUC */(function(){const t=localStorage.getItem('odTheme');if(t)document.getElementById('htmlRoot')?.setAttribute('data-theme',t);})();</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/mobile.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/dark-mode.css" rel="stylesheet">
<?= orgBrandingStyle((int)$user['org_id']) ?>
<style>
/* ── Header search bar ───────────────────────────────────────── */
.header-search { position:relative; display:flex; align-items:center; }
.header-search-form { display:flex; align-items:center; background:#f1f5f9; border:1.5px solid transparent; border-radius:24px; padding:5px 12px; gap:6px; transition:width .25s cubic-bezier(.4,0,.2,1), border-color .2s, background .2s; width:38px; overflow:hidden; }
.header-search-form:focus-within { width:240px; background:#fff; border-color:var(--green, #1A8A4E); box-shadow:0 0 0 3px rgba(26,138,78,.1); }
.header-search-form .search-icon { color:#94a3b8; font-size:.85rem; flex-shrink:0; cursor:pointer; transition:color .15s; }
.header-search-form:focus-within .search-icon { color:var(--green, #1A8A4E); }
.header-search-input { border:none; background:transparent; outline:none; font-size:.82rem; color:#0B2D4E; width:100%; min-width:0; padding:0; }
.header-search-input::placeholder { color:#94a3b8; }
.header-search-dropdown { position:absolute; top:calc(100% + 8px); left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,.12); z-index:1050; max-height:320px; overflow-y:auto; display:none; min-width:280px; }
.header-search-dropdown.show { display:block; animation:fadeInDown .15s ease; }
@keyframes fadeInDown { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
.hsd-item { display:flex; align-items:center; gap:10px; padding:9px 14px; cursor:pointer; transition:background .1s; border-bottom:1px solid #f8fafc; text-decoration:none; color:inherit; }
.hsd-item:hover { background:#f0fdf4; }
.hsd-item:last-child { border-bottom:none; }
.hsd-icon { width:28px; height:28px; border-radius:8px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:.72rem; color:#64748b; flex-shrink:0; }
.hsd-label { font-size:.8rem; font-weight:600; color:#0B2D4E; }
.hsd-meta { font-size:.7rem; color:#94a3b8; }
.hsd-section { padding:6px 14px 4px; font-size:.65rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.06em; background:#fafafa; }
.hsd-empty { padding:18px 14px; text-align:center; color:#94a3b8; font-size:.8rem; }
@media(max-width:640px) { .header-search-form:focus-within { width:160px; } .header-title { display:none; } }
.notif-badge { position:absolute; top:-4px; right:-4px; background:#e74c3c; color:white; border-radius:50%; width:18px; height:18px; font-size:.65rem; display:flex; align-items:center; justify-content:center; font-weight:700; }
</style>
<script>
/* ── Header live-search ──────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
  const input    = document.getElementById('headerSearchInput');
  const dropdown = document.getElementById('headerSearchDropdown');
  const form     = document.getElementById('headerSearchForm');
  if (!input || !dropdown) return;

  let timer = null;

  const iconMap = {
    customer:'fa-user', contact:'fa-address-book', invoice:'fa-file-invoice',
    product:'fa-box', sale:'fa-shopping-cart', ticket:'fa-headset',
    user:'fa-user-circle', order:'fa-receipt', default:'fa-search'
  };

  function getIcon(type) {
    const k = Object.keys(iconMap).find(k => type && type.toLowerCase().includes(k));
    return iconMap[k || 'default'];
  }

  function showResults(results, query) {
    if (!results || !results.length) {
      dropdown.innerHTML = `<div class="hsd-empty"><i class="fas fa-search-minus me-1"></i>No results for "<strong>${escQ(query)}</strong>"</div>`;
    } else {
      let lastType = null;
      dropdown.innerHTML = results.slice(0, 12).map(r => {
        let sec = '';
        if (r.type !== lastType) {
          lastType = r.type;
          sec = `<div class="hsd-section">${escQ(r.type || 'Results')}</div>`;
        }
        return `${sec}<a class="hsd-item" href="${escQ(r.url || '#')}">
          <div class="hsd-icon"><i class="fas ${getIcon(r.type)}"></i></div>
          <div><div class="hsd-label">${escQ(r.title)}</div><div class="hsd-meta">${escQ(r.subtitle || '')}</div></div>
        </a>`;
      }).join('') +
      `<a class="hsd-item" href="<?= APP_URL ?>/client/search.php?q=${encodeURIComponent(query)}" style="justify-content:center;color:var(--green);font-size:.78rem;font-weight:600">
        <i class="fas fa-search me-2"></i>See all results for "${escQ(query)}"
      </a>`;
    }
    dropdown.classList.add('show');
  }

  function escQ(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function hideDropdown() {
    dropdown.classList.remove('show');
  }

  input.addEventListener('input', function() {
    clearTimeout(timer);
    const q = this.value.trim();
    if (q.length < 2) { hideDropdown(); return; }
    dropdown.innerHTML = `<div class="hsd-empty"><i class="fas fa-spinner fa-spin me-1"></i>Searching…</div>`;
    dropdown.classList.add('show');
    timer = setTimeout(() => {
      fetch('<?= APP_URL ?>/client/search.php?q=' + encodeURIComponent(q) + '&json=1')
        .then(r => r.ok ? r.json() : Promise.reject())
        .then(data => showResults(data.results || [], q))
        .catch(() => { dropdown.innerHTML = `<div class="hsd-empty">Could not reach search.</div>`; });
    }, 280);
  });

  input.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { hideDropdown(); this.blur(); }
  });

  document.addEventListener('mousedown', function(e) {
    if (!document.getElementById('headerSearch').contains(e.target)) hideDropdown();
  });

  // Prevent form submit on empty
  form.addEventListener('submit', function(e) {
    if (!input.value.trim()) e.preventDefault();
    hideDropdown();
  });
});
</script>
</head>
<body class="client-layout">

<nav class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-logo" style="background:<?= $moduleColor ?? 'var(--green)' ?>"><i class="<?= $moduleIcon ?? 'fas fa-cubes' ?>"></i></div>
    <div class="brand-text">
      <span class="brand-name"><?= e($moduleName ?? 'Module') ?></span>
      <span class="brand-role"><?= e($user['org_name']) ?></span>
    </div>
  </div>
  <div class="sidebar-nav">
    <div class="nav-label">MODULE</div>
    <?php foreach($moduleNav ?? [] as $nav):
      $_navSlug = pathinfo($nav['url'], PATHINFO_FILENAME);
      if (!canAccessModulePage($moduleSlug ?? '', $_navSlug)) continue;
    ?>
    <a href="<?= e($nav['url']) ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) === basename($nav['url']) ? 'active' : '' ?>">
      <i class="<?= e($nav['icon']) ?>"></i><span><?= e($nav['label']) ?></span></a>
    <?php endforeach; ?>
    <div class="nav-label">WORKSPACE</div>
    <a href="<?= APP_URL ?>/client/index.php" class="nav-item"><i class="fas fa-home"></i><span>Dashboard</span></a>
    <a href="<?= APP_URL ?>/client/modules.php" class="nav-item"><i class="fas fa-th"></i><span>All Modules</span></a>
    <div class="nav-label">OTHER MODULES</div>
    <?php foreach($modules as $m): ?>
    <?php if ($m['slug'] !== ($moduleSlug ?? '')): ?>
    <a href="<?= APP_URL ?>/modules/<?= $m['slug'] ?>/index.php" class="nav-item">
      <i class="<?= e($m['icon']) ?>" style="color:<?= e($m['color']) ?>"></i><span><?= e($m['name']) ?></span></a>
    <?php endif; endforeach; ?>
    <div class="nav-label">ACCOUNT</div>
    <a href="<?= APP_URL ?>/auth/logout.php" class="nav-item text-danger"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
  </div>
</nav>

<div class="main-wrapper">
  <header class="top-header">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <div class="header-title d-flex align-items-center gap-2">
      <div style="width:28px;height:28px;border-radius:7px;background:<?= $moduleColor ?? 'var(--green)' ?>1a;color:<?= $moduleColor ?? 'var(--green)' ?>;display:flex;align-items:center;justify-content:center;font-size:.85rem">
        <i class="<?= $moduleIcon ?? 'fas fa-cubes' ?>"></i>
      </div>
      <?= e($moduleName ?? 'Module') ?>
    </div>

    <!-- ── Global Search ─────────────────────────────────────── -->
    <div class="header-search ms-auto me-3" id="headerSearch">
      <form class="header-search-form" id="headerSearchForm"
            action="<?= APP_URL ?>/client/search.php" method="GET"
            autocomplete="off" role="search">
        <i class="fas fa-search search-icon" onclick="document.getElementById('headerSearchInput').focus()"></i>
        <input type="text" name="q" id="headerSearchInput"
               class="header-search-input"
               placeholder="Search anything…"
               aria-label="Global search"
               maxlength="120">
      </form>
      <div class="header-search-dropdown" id="headerSearchDropdown" role="listbox"></div>
    </div>

    <div class="header-actions">
      <a href="<?= APP_URL ?>/client/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-home me-1"></i> Dashboard
      </a>
      <div class="dropdown">
        <button class="user-pill" data-bs-toggle="dropdown">
          <div class="avatar-sm"><?= strtoupper(substr($user['name'],0,2)) ?></div>
          <div class="user-info">
            <span class="user-name"><?= e($user['name']) ?></span>
            <span class="user-role"><?= e($user['org_name']) ?></span>
          </div>
          <i class="fas fa-chevron-down ms-1 small"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= APP_URL ?>/client/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/client/index.php"><i class="fas fa-home me-2"></i>Dashboard</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><button class="dropdown-item" onclick="toggleDarkMode()"><i class="dm-icon fas fa-moon me-2"></i><span class="dm-label">Dark Mode</span></button></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/client/reminders.php"><i class="fas fa-tasks me-2"></i>Reminders</a></li>
          <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
        </ul>
      </div>
      <?php
      /* ── Reminders badge ─────────────────────────────────────── */
      $__remCount = 0;
      try {
        $__remStmt = $pdo->prepare("SELECT COUNT(*) FROM org_reminders WHERE user_id=? AND status='pending' AND (due_date IS NULL OR due_date <= CURDATE())");
        $__remStmt->execute([(int)$user['id']]);
        $__remCount = (int)$__remStmt->fetchColumn();
      } catch (Exception $e) {}
      if ($__remCount > 0):
      ?>
      <a href="<?= APP_URL ?>/client/reminders.php" class="btn-icon position-relative" title="<?= $__remCount ?> reminder(s) due" style="text-decoration:none">
        <i class="fas fa-tasks"></i>
        <span class="notif-badge"><?= $__remCount > 9 ? '9+' : $__remCount ?></span>
      </a>
      <?php endif; ?>
    </div>
  </header>
  <main class="main-content">
    <?= flashAlert() ?>
    <?php
    // Show role-context banner for staff on module index pages
    if (($user['role'] ?? '') === 'staff' && $_currentPageSlug === 'index'):
        echo renderStaffRoleBanner($moduleSlug ?? '', $user, $moduleNav ?? []);
    endif;
    ?>
    <?php require_once __DIR__ . '/_org-login-banner.php'; ?>
    <?php if ($_isReadOnly): ?>
    <div class="alert alert-warning alert-dismissible fade show d-flex align-items-center gap-2 py-2 mb-3" role="alert">
      <i class="fas fa-eye fa-fw"></i>
      <div class="small"><strong>Read-only mode.</strong> Your role in this module only allows viewing records — create, edit, and delete actions are disabled.</div>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
