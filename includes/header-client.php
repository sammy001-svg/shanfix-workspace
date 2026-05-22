<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
requireClientAdmin();
$user    = currentUser();
$modules = getOrgModules((int)$user['org_id']);
$pageTitle = $pageTitle ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
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
</style>
<script>
function markNotifsRead() {
  fetch('<?= APP_URL ?>/api/notifications.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=mark_read'});
  document.querySelectorAll('.notif-badge').forEach(b => b.remove());
}
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
    <div class="nav-label">OVERVIEW</div>
    <a href="<?= APP_URL ?>/client/index.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'index.php' && strpos($_SERVER['REQUEST_URI'],'/client/') !== false ? 'active' : '' ?>">
      <i class="fas fa-home"></i><span>Dashboard</span></a>
    <a href="<?= APP_URL ?>/client/modules.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'modules.php' ? 'active' : '' ?>">
      <i class="fas fa-th"></i><span>My Modules</span></a>

    <?php if (!empty($modules)): ?>
    <div class="nav-label">ACTIVE MODULES</div>
    <?php foreach ($modules as $mod): ?>
    <a href="<?= APP_URL ?>/modules/<?= $mod['slug'] ?>/index.php" class="nav-item">
      <i class="<?= e($mod['icon']) ?>"></i><span><?= e($mod['name']) ?></span></a>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="nav-label">ACCOUNT</div>
    <a href="<?= APP_URL ?>/client/billing.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'billing.php' ? 'active' : '' ?>">
      <i class="fas fa-file-invoice-dollar"></i><span>Billing</span></a>
    <a href="<?= APP_URL ?>/client/support.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'support.php' ? 'active' : '' ?>">
      <i class="fas fa-headset"></i><span>Support</span>
      <?php
      try {
        $__openTk = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE org_id=? AND status IN ('open','in_progress')");
        $__openTk->execute([(int)$user['org_id']]);
        $__tkCount = (int)$__openTk->fetchColumn();
        if ($__tkCount > 0) echo '<span class="badge bg-warning text-dark ms-auto" style="font-size:.6rem">' . $__tkCount . '</span>';
      } catch(Exception $e) {}
      ?>
    </a>
    <a href="<?= APP_URL ?>/client/search.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'search.php' ? 'active' : '' ?>">
      <i class="fas fa-search"></i><span>Search</span></a>
    <a href="<?= APP_URL ?>/client/profile.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'profile.php' ? 'active' : '' ?>">
      <i class="fas fa-user-circle"></i><span>Profile</span></a>
    <?php if (($user['role'] ?? '') === 'client_admin'): ?>
    <a href="<?= APP_URL ?>/client/users.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'users.php' ? 'active' : '' ?>">
      <i class="fas fa-users-cog"></i><span>Team</span></a>
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
          <li><a class="dropdown-item" href="<?= APP_URL ?>/client/billing.php"><i class="fas fa-credit-card me-2"></i>Billing</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
        </ul>
      </div>
    </div>
  </header>

  <main class="main-content">
    <?= flashAlert() ?>
