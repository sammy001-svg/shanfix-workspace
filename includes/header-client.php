<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();
requireClientAdmin();
$user    = currentUser();

// Onboarding guard — redirect first-time client_admin registrants to setup wizard
// Staff accounts are pre-created by admins and skip onboarding entirely
if ($user['role'] !== 'staff' && !str_ends_with($_SERVER['PHP_SELF'], '/onboarding.php')) {
    $_obStmt = $pdo->prepare("SELECT is_onboarded FROM users WHERE id=?");
    $_obStmt->execute([(int)$user['id']]);
    if (!(bool)$_obStmt->fetchColumn()) {
        redirect(APP_URL . '/client/onboarding.php');
    }
}

// Initialise branch session context (sets active_branch_id based on user assignment)
initBranchSession($user);
$__branches      = getOrgBranches((int)$user['org_id']);
$__activeBranchId = getActiveBranchId();
$__activeBranch   = null;
foreach ($__branches as $__b) {
    if ((int)$__b['id'] === $__activeBranchId) { $__activeBranch = $__b; break; }
}
$__isMultiBranch = count($__branches) > 0;

// Staff see only their granted modules; admins see all
$modules = ($user['role'] === 'staff')
    ? getUserAccessibleModules((int)$user['id'], (int)$user['org_id'])
    : getOrgModules((int)$user['org_id']);
$pageTitle = $pageTitle ?? APP_NAME;

// ── SEO / OG defaults (pages can override before including this header) ──────
$ogTitle       ??= $pageTitle . ' — ' . APP_NAME;
$ogDescription ??= APP_TAGLINE;
$ogImage       ??= APP_URL . '/api/og-image.php?t=' . urlencode($pageTitle) . '&s=' . urlencode(APP_NAME) . '&c=1A8A4E';
$ogUrl         = APP_URL . ($_SERVER['REQUEST_URI'] ?? '/');
?>
<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($ogTitle) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<link rel="canonical" href="<?= e($ogUrl) ?>">
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
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
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
.notif-bell { position:relative; }
.notif-badge { position:absolute; top:-4px; right:-4px; background:#e74c3c; color:white; border-radius:50%; width:18px; height:18px; font-size:.65rem; display:flex; align-items:center; justify-content:center; font-weight:700; }
.notif-item { padding:10px 14px; border-bottom:1px solid #f1f5f9; cursor:pointer; transition:background .1s; }
.notif-item:hover { background:#f8fafc; }
.notif-item.unread { background:#eff6ff; }
.notif-item .notif-title { font-size:.82rem; font-weight:600; color:#0B2D4E; }
.notif-item .notif-msg { font-size:.75rem; color:#64748b; }
.notif-item .notif-time { font-size:.7rem; color:#94a3b8; }
.notif-type-success { border-left:3px solid #1A8A4E; }
.notif-type-warning { border-left:3px solid #f59e0b; }
.notif-type-danger  { border-left:3px solid #ef4444; }
.notif-type-info    { border-left:3px solid #3b82f6; }
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
</style>
<script>
function markNotifsRead() {
  fetch('<?= APP_URL ?>/api/notifications.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=mark_read'});
  document.querySelectorAll('.notif-badge').forEach(b => b.remove());
}
</script>
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

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-logo"><i class="fas fa-cubes"></i></div>
    <div class="brand-text">
      <span class="brand-name"><?= e($user['org_name'] ?: APP_NAME) ?></span>
      <span class="brand-role">Client Portal</span>
    </div>
  </div>

  <div class="sidebar-nav">
    <?php $__isStaff = ($user['role'] ?? '') === 'staff'; ?>

    <div class="nav-label">OVERVIEW</div>
    <a href="<?= APP_URL ?>/client/index.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'index.php' && strpos($_SERVER['REQUEST_URI'],'/client/') !== false ? 'active' : '' ?>">
      <i class="fas fa-home"></i><span>Dashboard</span></a>
    <?php if (!$__isStaff): ?>
    <a href="<?= APP_URL ?>/client/modules.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'modules.php' ? 'active' : '' ?>">
      <i class="fas fa-th"></i><span>My Modules</span></a>
    <?php endif; ?>

    <?php if (!empty($modules)): ?>
    <div class="nav-label"><?= $__isStaff ? 'MY MODULES' : 'ACTIVE MODULES' ?></div>
    <?php foreach ($modules as $mod): ?>
    <a href="<?= APP_URL ?>/modules/<?= $mod['slug'] ?>/index.php" class="nav-item">
      <i class="<?= e($mod['icon']) ?>"></i><span><?= e($mod['name']) ?></span></a>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!$__isStaff): ?>
    <div class="nav-label">TOOLS</div>
    <a href="<?= APP_URL ?>/client/analytics.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'active' : '' ?>">
      <i class="fas fa-chart-line"></i><span>Analytics</span></a>
    <a href="<?= APP_URL ?>/client/reminders.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'reminders.php' ? 'active' : '' ?>">
      <i class="fas fa-bell"></i><span>Reminders</span></a>
    <a href="<?= APP_URL ?>/client/reports.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'reports.php' ? 'active' : '' ?>">
      <i class="fas fa-file-chart-bar"></i><span>Reports</span></a>
    <a href="<?= APP_URL ?>/client/audit-trail.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'audit-trail.php' ? 'active' : '' ?>">
      <i class="fas fa-history"></i><span>Audit Trail</span></a>
    <?php endif; ?>

    <div class="nav-label">ACCOUNT</div>
    <?php if (!$__isStaff): ?>
    <a href="<?= APP_URL ?>/client/billing.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'billing.php' ? 'active' : '' ?>">
      <i class="fas fa-file-invoice-dollar"></i><span>Billing</span></a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/client/chat.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'chat.php' ? 'active' : '' ?>">
      <i class="fas fa-comments"></i><span>Team Chat</span>
      <?php
      try {
        $__chatUnread = $pdo->prepare("
          SELECT COUNT(*) FROM chat_messages cm
          JOIN chat_participants cp ON cm.conversation_id = cp.conversation_id AND cp.user_id = ?
          WHERE cm.sender_id != ? AND cm.created_at > COALESCE(cp.last_read_at,'2000-01-01')
        ");
        $__chatUnread->execute([(int)$user['id'], (int)$user['id']]);
        $__chatCnt = (int)$__chatUnread->fetchColumn();
        if ($__chatCnt > 0) echo '<span class="badge bg-success ms-auto" style="font-size:.6rem">' . ($__chatCnt > 9 ? '9+' : $__chatCnt) . '</span>';
      } catch(Exception $e) {}
      ?>
    </a>
    <a href="<?= APP_URL ?>/client/support.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'support.php' ? 'active' : '' ?>">
      <i class="fas fa-headset"></i><span>Support</span>
      <?php
      try {
        $__tkWhere  = $__isStaff ? 'org_id=? AND user_id=? AND status IN (\'open\',\'in_progress\')' : 'org_id=? AND status IN (\'open\',\'in_progress\')';
        $__tkParams = $__isStaff ? [(int)$user['org_id'], (int)$user['id']] : [(int)$user['org_id']];
        $__openTk   = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE {$__tkWhere}");
        $__openTk->execute($__tkParams);
        $__tkCount  = (int)$__openTk->fetchColumn();
        if ($__tkCount > 0) echo '<span class="badge bg-warning text-dark ms-auto" style="font-size:.6rem">' . $__tkCount . '</span>';
      } catch(Exception $e) {}
      ?>
    </a>
    <a href="<?= APP_URL ?>/client/profile.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'profile.php' ? 'active' : '' ?>">
      <i class="fas fa-user-circle"></i><span>Profile</span></a>
    <?php if (!$__isStaff): ?>
    <a href="<?= APP_URL ?>/client/users.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'users.php' ? 'active' : '' ?>">
      <i class="fas fa-users-cog"></i><span>Team</span></a>
    <a href="<?= APP_URL ?>/client/branches.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'branches.php' ? 'active' : '' ?>">
      <i class="fas fa-code-branch"></i><span>Branches</span></a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/auth/logout.php" class="nav-item text-danger">
      <i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
  </div>
</nav>

<!-- Main content -->
<div class="main-wrapper">
  <header class="top-header">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <div class="header-title"><?= e($pageTitle) ?></div>

    <!-- ── Branch selector (multi-branch orgs, admin only) ──── -->
    <?php if ($__isMultiBranch && ($user['role'] ?? '') === 'client_admin'): ?>
    <form method="POST" action="<?= APP_URL ?>/client/set-branch.php" class="d-flex align-items-center">
      <?= csrfField() ?>
      <div class="input-group input-group-sm" style="max-width:200px">
        <span class="input-group-text" style="background:var(--navy);color:#fff;border-color:var(--navy)">
          <i class="fas fa-code-branch" style="font-size:.75rem"></i>
        </span>
        <select name="branch_id" class="form-select form-select-sm border-start-0"
                onchange="this.form.submit()"
                style="font-size:.78rem;border-color:#dee2e6">
          <option value="0" <?= !$__activeBranchId ? 'selected' : '' ?>>All Branches</option>
          <?php foreach ($__branches as $__b): ?>
          <option value="<?= $__b['id'] ?>" <?= $__activeBranchId === (int)$__b['id'] ? 'selected' : '' ?>>
            <?= e($__b['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
    <?php elseif ($__isMultiBranch && ($user['role'] ?? '') === 'staff' && $__activeBranch): ?>
    <!-- Staff see their branch as a read-only badge -->
    <span class="badge d-flex align-items-center gap-1" style="background:var(--navy);font-size:.72rem">
      <i class="fas fa-code-branch"></i><?= e($__activeBranch['name']) ?>
    </span>
    <?php endif; ?>

    <!-- ── Global Search ─────────────────────────────────────── -->
    <div class="header-search" id="headerSearch">
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
      <?php
      require_once __DIR__ . '/notifications.php';
      $unreadCount = getUnreadCount((int)$user['id']);
      ?>
      <div class="dropdown notif-bell">
        <button class="btn-icon" data-bs-toggle="dropdown" id="notifBell" onclick="markNotifsRead()">
          <i class="fas fa-bell"></i>
          <?php if ($unreadCount > 0): ?>
          <span class="notif-badge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
          <?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end p-0" style="width:320px;max-height:400px;overflow-y:auto" id="notifDropdown">
          <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
            <strong class="small">Notifications</strong>
            <a href="<?= APP_URL ?>/client/notifications.php" class="small text-primary">View All</a>
          </div>
          <?php
          $notifs = getUnreadNotifications((int)$user['id'], 8);
          if (empty($notifs)): ?>
          <div class="p-3 text-center text-muted small">No new notifications</div>
          <?php else: foreach($notifs as $n): ?>
          <div class="notif-item unread notif-type-<?= e($n['type']) ?>" onclick="<?= $n['link'] ? "window.location='" . e($n['link']) . "'" : '' ?>">
            <div class="notif-title"><?= e($n['title']) ?></div>
            <div class="notif-msg"><?= e($n['message']) ?></div>
            <div class="notif-time"><?= timeAgo($n['created_at']) ?></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <div class="dropdown">
        <button class="user-pill" data-bs-toggle="dropdown">
          <div class="avatar-sm"><?= strtoupper(substr($user['name'], 0, 2)) ?></div>
          <div class="user-info">
            <span class="user-name"><?= e($user['name']) ?></span>
            <span class="user-role"><?= ucfirst(str_replace('_',' ',$user['role'])) ?></span>
          </div>
          <i class="fas fa-chevron-down ms-1 small"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= APP_URL ?>/client/profile.php"><i class="fas fa-user me-2"></i>My Profile</a></li>
          <?php if (!$__isStaff): ?>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/client/billing.php"><i class="fas fa-credit-card me-2"></i>Billing</a></li>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/client/settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><button class="dropdown-item" onclick="toggleDarkMode()"><i class="dm-icon fas fa-moon me-2"></i><span class="dm-label">Dark Mode</span></button></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
        </ul>
      </div>
      <?php
      // Reminders badge
      $__remCount = 0;
      try {
        $__rq = $pdo->prepare("SELECT COUNT(*) FROM org_reminders WHERE user_id=? AND status='pending' AND (due_date IS NULL OR due_date <= CURDATE())");
        $__rq->execute([(int)$user['id']]);
        $__remCount = (int)$__rq->fetchColumn();
      } catch (Exception $e) {}
      if ($__remCount > 0):
      ?>
      <a href="<?= APP_URL ?>/client/reminders.php" class="btn-icon position-relative" title="<?= $__remCount ?> reminder(s) due">
        <i class="fas fa-tasks"></i>
        <span class="notif-badge"><?= $__remCount > 9 ? '9+' : $__remCount ?></span>
      </a>
      <?php endif; ?>
    </div>
  </header>

  <main class="main-content">
    <?= flashAlert() ?>
    <?php require_once __DIR__ . '/_org-login-banner.php'; ?>
    <?php
    $__subWarn = getSubscriptionWarning((int)$user['org_id']);
    if ($__subWarn):
        $__wIcon = $__subWarn['type'] === 'trial' ? 'fas fa-hourglass-half' : 'fas fa-calendar-exclamation';
        $__wText = $__subWarn['type'] === 'trial'
            ? ($__subWarn['days'] === 0 ? 'Your free trial <strong>expires today</strong>.' : 'Your free trial expires in <strong>' . $__subWarn['days'] . ' day' . ($__subWarn['days'] > 1 ? 's' : '') . '</strong> on ' . $__subWarn['date'] . '.')
            : ($__subWarn['days'] === 0 ? 'Your subscription <strong>expires today</strong>.' : 'Your subscription expires in <strong>' . $__subWarn['days'] . ' day' . ($__subWarn['days'] > 1 ? 's' : '') . '</strong> on ' . $__subWarn['date'] . '.');
    ?>
    <div class="alert alert-<?= $__subWarn['severity'] ?> alert-dismissible d-flex align-items-center gap-3 py-2 mb-3 rounded-2" role="alert" style="font-size:.9rem">
      <i class="<?= $__wIcon ?> flex-shrink-0"></i>
      <div><?= $__wText ?> <a href="<?= APP_URL ?>/client/billing.php?tab=plans" class="fw-bold alert-link ms-2">Upgrade now →</a></div>
      <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
