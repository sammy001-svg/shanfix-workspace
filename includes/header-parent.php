<?php
/**
 * Parent Portal — shared layout header.
 * Include at the top of every parent/ page AFTER setting $pageTitle.
 * Provides auth guard, student switcher, sidebar, and topbar.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

// ── Auth guard ────────────────────────────────────────────────────
$_parOrgSlug  = $_SESSION['par_org_slug'] ?? null;
$_parLoginUrl = APP_URL . '/parent/login.php' . ($_parOrgSlug ? '?org=' . rawurlencode($_parOrgSlug) : '');

if (empty($_SESSION['par_id']) || empty($_SESSION['par_org_id'])) {
    redirect($_parLoginUrl);
}

// Session timeout
if (isset($_SESSION['par_last_act']) && (time() - $_SESSION['par_last_act']) > SESSION_LIFETIME) {
    $__slug = $_SESSION['par_org_slug'] ?? null;
    session_unset(); session_destroy();
    redirect(APP_URL . '/parent/login.php' . ($__slug ? '?org=' . rawurlencode($__slug) . '&expired=1' : '?expired=1'));
}
$_SESSION['par_last_act'] = time();

$parId     = (int)$_SESSION['par_id'];
$parOrgId  = (int)$_SESSION['par_org_id'];
$parName   = $_SESSION['par_name'] ?? 'Parent';
$parSids   = $_SESSION['par_sids'] ?? [];
$parActive = (int)($_SESSION['par_active'] ?? ($parSids[0] ?? 0));

// Allow switching active child via GET ?sid=X
if (!empty($_GET['sid']) && in_array((int)$_GET['sid'], $parSids, true)) {
    $parActive = (int)$_GET['sid'];
    $_SESSION['par_active'] = $parActive;
}

// Load active student info
$activeStudent = [];
if ($parActive && $parOrgId) {
    try {
        $__s = $pdo->prepare(
            "SELECT s.*, c.name AS class_name
             FROM sch_students s
             LEFT JOIN sch_classes c ON s.class_id = c.id
             WHERE s.id = ? AND s.org_id = ? LIMIT 1"
        );
        $__s->execute([$parActive, $parOrgId]);
        $activeStudent = $__s->fetch() ?: [];
    } catch (Throwable $e) {}
}

// Load all children names for switcher (if > 1)
$allChildren = [];
if (count($parSids) > 1) {
    try {
        $__in   = implode(',', array_fill(0, count($parSids), '?'));
        $__kids = $pdo->prepare("SELECT id, first_name, last_name, admission_no FROM sch_students WHERE id IN ($__in) AND org_id=?");
        $__kids->execute(array_merge($parSids, [$parOrgId]));
        $allChildren = $__kids->fetchAll();
    } catch (Throwable $e) {}
}

$schoolName  = $_SESSION['par_org_name'] ?? APP_NAME;
$orgSlug     = $_SESSION['par_org_slug'] ?? '';
$pageTitle   = $pageTitle ?? 'Parent Portal';
$currentPage = basename($_SERVER['PHP_SELF']);

$parNav = [
    ['url'=>'index.php',      'icon'=>'fas fa-th-large',       'label'=>'Dashboard'],
    ['url'=>'results.php',    'icon'=>'fas fa-graduation-cap', 'label'=>'Results'],
    ['url'=>'fees.php',       'icon'=>'fas fa-receipt',        'label'=>'Fees'],
    ['url'=>'attendance.php', 'icon'=>'fas fa-clipboard-check','label'=>'Attendance'],
    ['url'=>'homework.php',   'icon'=>'fas fa-book-open',      'label'=>'Homework'],
    ['url'=>'notices.php',    'icon'=>'fas fa-bullhorn',       'label'=>'Notices'],
    ['url'=>'timetable.php',  'icon'=>'fas fa-calendar-week',  'label'=>'Timetable'],
    ['url'=>'profile.php',    'icon'=>'fas fa-user-circle',    'label'=>'My Profile'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — Parent Portal | <?= e($schoolName) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<style>
:root {
  --par-green: #1A8A4E;
  --par-green-dark: #146038;
  --par-green-pale: #f0fdf4;
  --par-navy: #0B2D4E;
}
body { background: #f4f6f9; }

/* ── Sidebar ──────────────────────────────────────────────────── */
#parSidebar {
  width: 240px; min-height: 100vh; background: #fff;
  border-right: 1px solid #e9ecef; position: fixed; top: 0; left: 0; z-index: 100;
  display: flex; flex-direction: column;
}
#parSidebar .par-brand {
  padding: 1.25rem 1.25rem .75rem;
  border-bottom: 1px solid #f1f3f5;
}
.par-brand-label {
  font-size: .72rem; font-weight: 700; color: var(--par-green);
  text-transform: uppercase; letter-spacing: .5px;
}
.par-brand-school {
  font-size: .9rem; font-weight: 700; color: var(--par-navy); margin-top: 2px;
  line-height: 1.3;
}
.par-brand-parent {
  font-size: .75rem; color: #6c757d; margin-top: 2px;
}

.par-student-badge {
  margin: .75rem 1.25rem .25rem;
  background: var(--par-green-pale);
  border: 1px solid #bbf7d0;
  border-radius: 10px;
  padding: 10px 12px;
}
.par-student-badge .stu-name {
  font-size: .82rem; font-weight: 700; color: var(--par-navy); line-height: 1.3;
}
.par-student-badge .stu-meta {
  font-size: .72rem; color: #6c757d;
}
.par-switch-link {
  display: block; text-align: center; font-size: .7rem;
  color: var(--par-green); margin-top: 6px; text-decoration: none;
  font-weight: 600;
}
.par-switch-link:hover { text-decoration: underline; }

#parSidebar .nav-link {
  display: flex; align-items: center; gap: .75rem;
  padding: .55rem 1.25rem; color: #495057; font-size: .875rem;
  border-radius: 0; border-left: 3px solid transparent; transition: all .15s;
  text-decoration: none;
}
#parSidebar .nav-link:hover {
  background: var(--par-green-pale); color: var(--par-green);
}
#parSidebar .nav-link.active {
  background: var(--par-green-pale); color: var(--par-green);
  border-left-color: var(--par-green); font-weight: 600;
}
#parSidebar .nav-link i { width: 18px; text-align: center; font-size: .85rem; }
#parSidebar .sidebar-footer {
  margin-top: auto; padding: .75rem 1.25rem; border-top: 1px solid #f1f3f5;
}

/* ── Main area ────────────────────────────────────────────────── */
#parMain { margin-left: 240px; min-height: 100vh; }
#parTopbar {
  background: #fff; border-bottom: 1px solid #e9ecef;
  padding: .75rem 1.5rem;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 90;
}
#parContent { padding: 1.5rem; }

/* ── Reusable cards ───────────────────────────────────────────── */
.par-stat-card {
  background: #fff; border-radius: 12px; padding: 1.25rem;
  box-shadow: 0 1px 3px rgba(0,0,0,.08); border: 1px solid #f1f3f5;
  height: 100%;
}
.par-stat-icon {
  width: 44px; height: 44px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
  flex-shrink: 0;
}

/* ── Mobile ───────────────────────────────────────────────────── */
@media (max-width: 767px) {
  #parSidebar { transform: translateX(-100%); transition: transform .25s; }
  #parSidebar.show { transform: translateX(0); }
  #parMain { margin-left: 0; }
  #parContent { padding: 1rem; }
}
</style>
</head>
<body>

<!-- ── Sidebar ────────────────────────────────────────────────── -->
<div id="parSidebar">
  <div class="par-brand">
    <div class="par-brand-label"><i class="fas fa-school me-1"></i>Parent Portal</div>
    <div class="par-brand-school"><?= e($schoolName) ?></div>
    <div class="par-brand-parent"><i class="fas fa-user me-1"></i><?= e($parName) ?></div>
  </div>

  <!-- Active student badge -->
  <?php if ($activeStudent): ?>
  <div class="par-student-badge">
    <div class="stu-name"><?= e(($activeStudent['first_name'] ?? '') . ' ' . ($activeStudent['last_name'] ?? '')) ?></div>
    <div class="stu-meta">
      <i class="fas fa-id-badge me-1"></i><?= e($activeStudent['admission_no'] ?? '—') ?>
      <?php if (!empty($activeStudent['class_name'])): ?>
      &nbsp;&middot;&nbsp; <?= e($activeStudent['class_name']) ?>
      <?php endif; ?>
    </div>
    <?php if (count($parSids) > 1): ?>
    <a href="#" class="par-switch-link" data-bs-toggle="collapse" data-bs-target="#childSwitcher">
      <i class="fas fa-exchange-alt me-1"></i>Switch Child
    </a>
    <div class="collapse mt-2" id="childSwitcher">
      <?php foreach ($allChildren as $child): ?>
      <a href="?sid=<?= $child['id'] ?>" class="d-block py-1 px-2 rounded small text-decoration-none
                     <?= $child['id'] == $parActive ? 'fw-700' : '' ?>"
         style="color:var(--par-navy);background:<?= $child['id'] == $parActive ? '#d1fae5' : '' ?>">
        <i class="fas fa-child me-1 text-green"></i>
        <?= e($child['first_name'] . ' ' . $child['last_name']) ?>
        <span class="text-muted" style="font-size:.65rem">(<?= e($child['admission_no']) ?>)</span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <nav class="mt-1">
    <?php foreach ($parNav as $nav): ?>
    <a href="<?= APP_URL ?>/parent/<?= $nav['url'] ?>" class="nav-link <?= $currentPage === $nav['url'] ? 'active' : '' ?>">
      <i class="<?= $nav['icon'] ?>"></i><?= $nav['label'] ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="<?= APP_URL ?>/parent/logout.php" class="nav-link text-danger">
      <i class="fas fa-sign-out-alt"></i>Sign Out
    </a>
  </div>
</div>

<!-- ── Main wrapper ───────────────────────────────────────────── -->
<div id="parMain">
  <div id="parTopbar">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-sm btn-outline-secondary d-md-none"
              onclick="document.getElementById('parSidebar').classList.toggle('show')">
        <i class="fas fa-bars"></i>
      </button>
      <span class="fw-semibold text-dark"><?= e($pageTitle) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3">
      <span class="small text-muted d-none d-sm-inline">
        <i class="fas fa-calendar-day me-1"></i><?= date('d M Y') ?>
      </span>
      <div class="d-flex align-items-center gap-2">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-700"
             style="width:32px;height:32px;background:var(--par-green);font-size:.75rem">
          <?= strtoupper(substr($parName, 0, 1)) ?>
        </div>
        <span class="small fw-600 d-none d-sm-inline" style="color:var(--par-navy)"><?= e(explode(' ', $parName)[0]) ?></span>
      </div>
    </div>
  </div>

  <div id="parContent">
    <?= flashAlert() ?>
