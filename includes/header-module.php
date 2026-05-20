<?php
// Shared header for all module pages
// Requires: $moduleSlug, $moduleName, $moduleIcon, $moduleColor set before including
if (session_start() === PHP_SESSION_NONE || session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
requireModuleAccess($moduleSlug ?? '');
$user    = currentUser();
$modules = getOrgModules((int)$user['org_id']);
$pageTitle = ($moduleName ?? 'Module') . ' — ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($moduleName ?? 'Module') ?> — <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
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
    <?php foreach($moduleNav ?? [] as $nav): ?>
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
          <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
        </ul>
      </div>
    </div>
  </header>
  <main class="main-content">
    <?= flashAlert() ?>
