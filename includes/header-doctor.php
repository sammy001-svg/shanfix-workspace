<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

// ── Doctor auth guard ─────────────────────────────────────────────
if (empty($_SESSION['doc_id'])) {
    $slug = $_SESSION['doc_org_slug'] ?? '';
    redirect(APP_URL . '/doctor/login.php' . ($slug ? '?org='.$slug : ''));
}
$timeout = SESSION_LIFETIME;
if (isset($_SESSION['doc_last_act']) && (time() - $_SESSION['doc_last_act']) > $timeout) {
    $slug = $_SESSION['doc_org_slug'] ?? '';
    session_unset(); session_destroy();
    redirect(APP_URL . '/doctor/login.php?expired=1' . ($slug ? '&org='.$slug : ''));
}
$_SESSION['doc_last_act'] = time();

$docId      = (int)$_SESSION['doc_id'];
$docOrgId   = (int)$_SESSION['doc_org_id'];
$docName    = $_SESSION['doc_name']    ?? 'Doctor';
$docSpecialty = $_SESSION['doc_specialty'] ?? '';
$pageTitle  = $pageTitle ?? 'Doctor Portal';

// Fetch org branding
$docOrg = [];
try {
    $s = $pdo->prepare("SELECT name, logo, city, country FROM organizations WHERE id=? LIMIT 1");
    $s->execute([$docOrgId]);
    $docOrg = $s->fetch() ?: [];
} catch (Throwable $e) {}

$currentPage = basename($_SERVER['PHP_SELF']);
$docNav = [
    ['url'=>'index.php',        'icon'=>'fas fa-th-large',       'label'=>'Dashboard'],
    ['url'=>'appointments.php', 'icon'=>'fas fa-calendar-check', 'label'=>'My Appointments'],
    ['url'=>'patients.php',     'icon'=>'fas fa-procedures',     'label'=>'My Patients'],
    ['url'=>'records.php',      'icon'=>'fas fa-file-medical',   'label'=>'Medical Records'],
    ['url'=>'prescriptions.php','icon'=>'fas fa-prescription',   'label'=>'Prescriptions'],
    ['url'=>'profile.php',      'icon'=>'fas fa-user-md',        'label'=>'My Profile'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — Doctor Portal</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<style>
:root { --doc-blue:#1a4e7c; --doc-blue-dark:#12375a; --doc-blue-pale:#e8f0f8; --doc-accent:#2980b9; }

body { background:#f4f6f9; }

#docSidebar {
  width:240px; min-height:100vh; background:#fff;
  border-right:1px solid #e9ecef; position:fixed; top:0; left:0; z-index:100;
  display:flex; flex-direction:column;
}
#docSidebar .sidebar-brand {
  padding:1.25rem 1.25rem .75rem;
  border-bottom:1px solid #f1f3f5;
  background:linear-gradient(135deg, var(--doc-blue) 0%, var(--doc-blue-dark) 100%);
}
#docSidebar .sidebar-brand .brand-clinic {
  font-size:.72rem; font-weight:700; color:rgba(255,255,255,.7); text-transform:uppercase; letter-spacing:.5px;
}
#docSidebar .sidebar-brand .doc-name {
  font-size:1rem; font-weight:700; color:#fff; line-height:1.2; margin-top:.3rem;
}
#docSidebar .sidebar-brand .doc-specialty {
  font-size:.72rem; color:rgba(255,255,255,.6); margin-top:.15rem;
}
#docSidebar .nav-link {
  display:flex; align-items:center; gap:.75rem;
  padding:.6rem 1.25rem; color:#495057; font-size:.875rem;
  border-radius:0; border-left:3px solid transparent; transition:all .15s;
}
#docSidebar .nav-link:hover { background:var(--doc-blue-pale); color:var(--doc-blue); }
#docSidebar .nav-link.active { background:var(--doc-blue-pale); color:var(--doc-blue); border-left-color:var(--doc-blue); font-weight:600; }
#docSidebar .nav-link i { width:18px; text-align:center; font-size:.85rem; }
#docSidebar .sidebar-footer {
  margin-top:auto; padding:.75rem 1.25rem; border-top:1px solid #f1f3f5;
}

#docMain { margin-left:240px; min-height:100vh; }
#docTopbar {
  background:#fff; border-bottom:1px solid #e9ecef;
  padding:.75rem 1.5rem; display:flex; align-items:center; justify-content:space-between;
  position:sticky; top:0; z-index:90;
}
#docContent { padding:1.5rem; }

.doc-stat-card {
  background:#fff; border-radius:12px; padding:1.25rem;
  box-shadow:0 1px 3px rgba(0,0,0,.08); border:1px solid #f1f3f5;
}
.doc-stat-icon {
  width:44px; height:44px; border-radius:10px;
  display:flex; align-items:center; justify-content:center; font-size:1.1rem;
}

@media (max-width:767px) {
  #docSidebar { transform:translateX(-100%); transition:transform .25s; }
  #docSidebar.show { transform:translateX(0); }
  #docMain { margin-left:0; }
}
</style>
</head>
<body>

<!-- Sidebar -->
<div id="docSidebar">
  <div class="sidebar-brand">
    <div class="brand-clinic"><i class="fas fa-heartbeat me-1"></i><?= e($docOrg['name'] ?? APP_NAME) ?></div>
    <div class="doc-name"><?= e($docName) ?></div>
    <?php if ($docSpecialty): ?><div class="doc-specialty"><?= e($docSpecialty) ?></div><?php endif; ?>
  </div>

  <nav class="mt-2">
    <?php foreach ($docNav as $nav): ?>
    <a href="<?= APP_URL ?>/doctor/<?= $nav['url'] ?>" class="nav-link <?= $currentPage === $nav['url'] ? 'active' : '' ?>">
      <i class="<?= $nav['icon'] ?>"></i><?= $nav['label'] ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="<?= APP_URL ?>/doctor/logout.php" class="nav-link text-danger">
      <i class="fas fa-sign-out-alt"></i>Sign Out
    </a>
  </div>
</div>

<!-- Main -->
<div id="docMain">
  <div id="docTopbar">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-sm btn-outline-secondary d-md-none" onclick="document.getElementById('docSidebar').classList.toggle('show')">
        <i class="fas fa-bars"></i>
      </button>
      <span class="fw-semibold text-dark"><?= e($pageTitle) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3">
      <span class="badge" style="background:var(--doc-blue-pale);color:var(--doc-blue);font-size:.72rem;padding:.35rem .7rem">
        <i class="fas fa-stethoscope me-1"></i>Doctor Portal
      </span>
      <span class="small text-muted d-none d-md-inline">
        <i class="fas fa-calendar-day me-1"></i><?= date('d M Y') ?>
      </span>
    </div>
  </div>

  <div id="docContent">
    <?= flashAlert() ?>
