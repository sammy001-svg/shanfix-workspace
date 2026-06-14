<?php
/**
 * Student Portal — shared layout header.
 * Include at the top of every student/ page AFTER setting $pageTitle.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

// ── Auth guard ────────────────────────────────────────────────────
$_stuOrgSlug  = $_SESSION['stu_org_slug'] ?? null;
$_stuLoginUrl = APP_URL . '/student/login.php' . ($_stuOrgSlug ? '?org=' . rawurlencode($_stuOrgSlug) : '');

if (empty($_SESSION['stu_id']) || empty($_SESSION['stu_org_id'])) {
    redirect($_stuLoginUrl);
}

if (isset($_SESSION['stu_last_act']) && (time() - $_SESSION['stu_last_act']) > SESSION_LIFETIME) {
    $__slug = $_SESSION['stu_org_slug'] ?? null;
    session_unset(); session_destroy();
    redirect(APP_URL . '/student/login.php' . ($__slug ? '?org=' . rawurlencode($__slug) . '&expired=1' : '?expired=1'));
}
$_SESSION['stu_last_act'] = time();

$stuId        = (int)$_SESSION['stu_id'];
$stuOrgId     = (int)$_SESSION['stu_org_id'];
$stuName      = $_SESSION['stu_name']         ?? 'Student';
$stuClassId   = (int)($_SESSION['stu_class_id']  ?? 0);
$stuClassName = $_SESSION['stu_class_name']   ?? '';
$stuAdmNo     = $_SESSION['stu_admission_no'] ?? '';
$schoolName   = $_SESSION['stu_org_name']     ?? APP_NAME;
$pageTitle    = $pageTitle ?? 'Student Portal';
$currentPage  = basename($_SERVER['PHP_SELF']);

$stuNav = [
    ['url'=>'index.php',          'icon'=>'fas fa-th-large',       'label'=>'Dashboard'],
    ['url'=>'results.php',        'icon'=>'fas fa-graduation-cap', 'label'=>'My Results'],
    ['url'=>'fees.php',           'icon'=>'fas fa-receipt',        'label'=>'My Fees'],
    ['url'=>'attendance.php',     'icon'=>'fas fa-clipboard-check','label'=>'Attendance'],
    ['url'=>'homework.php',       'icon'=>'fas fa-book-open',      'label'=>'Homework'],
    ['url'=>'online-classes.php', 'icon'=>'fas fa-video',          'label'=>'Online Classes'],
    ['url'=>'online-exams.php',   'icon'=>'fas fa-laptop',         'label'=>'Online Exams'],
    ['url'=>'timetable.php',      'icon'=>'fas fa-calendar-week',  'label'=>'Timetable'],
    ['url'=>'notices.php',        'icon'=>'fas fa-bullhorn',       'label'=>'Notices'],
    ['url'=>'profile.php',        'icon'=>'fas fa-user-circle',    'label'=>'My Profile'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> &mdash; Student Portal | <?= e($schoolName) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<style>
:root {
  --stu-blue: #1d4ed8;
  --stu-blue-dark: #1e3a8a;
  --stu-blue-pale: #eff6ff;
  --stu-navy: #0B2D4E;
}
body { background: #f4f6f9; }

#stuSidebar {
  width: 240px; min-height: 100vh; background: #fff;
  border-right: 1px solid #e9ecef; position: fixed; top: 0; left: 0; z-index: 100;
  display: flex; flex-direction: column;
}
#stuSidebar .stu-brand {
  padding: 1.25rem 1.25rem .75rem;
  border-bottom: 1px solid #f1f3f5;
}
.stu-brand-label {
  font-size: .72rem; font-weight: 700; color: var(--stu-blue);
  text-transform: uppercase; letter-spacing: .5px;
}
.stu-brand-school { font-size: .9rem; font-weight: 700; color: var(--stu-navy); margin-top: 2px; line-height: 1.3; }
.stu-brand-student { font-size: .75rem; color: #6c757d; margin-top: 2px; }

.stu-id-badge {
  margin: .75rem 1.25rem .25rem;
  background: var(--stu-blue-pale);
  border: 1px solid #bfdbfe;
  border-radius: 10px; padding: 10px 12px;
}
.stu-id-badge .stu-sname { font-size: .82rem; font-weight: 700; color: var(--stu-navy); line-height: 1.3; }
.stu-id-badge .stu-smeta { font-size: .72rem; color: #6c757d; }

#stuSidebar .nav-link {
  display: flex; align-items: center; gap: .75rem;
  padding: .55rem 1.25rem; color: #495057; font-size: .875rem;
  border-radius: 0; border-left: 3px solid transparent; transition: all .15s;
  text-decoration: none;
}
#stuSidebar .nav-link:hover { background: var(--stu-blue-pale); color: var(--stu-blue); }
#stuSidebar .nav-link.active {
  background: var(--stu-blue-pale); color: var(--stu-blue);
  border-left-color: var(--stu-blue); font-weight: 600;
}
#stuSidebar .nav-link i { width: 18px; text-align: center; font-size: .85rem; }
#stuSidebar .sidebar-footer { margin-top: auto; padding: .75rem 1.25rem; border-top: 1px solid #f1f3f5; }

#stuMain { margin-left: 240px; min-height: 100vh; }
#stuTopbar {
  background: #fff; border-bottom: 1px solid #e9ecef;
  padding: .75rem 1.5rem;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 90;
}
#stuContent { padding: 1.5rem; }

.stu-stat-card {
  background: #fff; border-radius: 12px; padding: 1.25rem;
  box-shadow: 0 1px 3px rgba(0,0,0,.08); border: 1px solid #f1f3f5; height: 100%;
}

@media (max-width: 767px) {
  #stuSidebar { transform: translateX(-100%); transition: transform .25s; }
  #stuSidebar.show { transform: translateX(0); }
  #stuMain { margin-left: 0; }
  #stuContent { padding: 1rem; }
}
</style>
</head>
<body>

<div id="stuSidebar">
  <div class="stu-brand">
    <div class="stu-brand-label"><i class="fas fa-user-graduate me-1"></i>Student Portal</div>
    <div class="stu-brand-school"><?= e($schoolName) ?></div>
    <div class="stu-brand-student"><i class="fas fa-user me-1"></i><?= e($stuName) ?></div>
  </div>

  <div class="stu-id-badge">
    <div class="stu-sname"><?= e($stuName) ?></div>
    <div class="stu-smeta">
      <i class="fas fa-id-badge me-1"></i><?= e($stuAdmNo ?: '—') ?>
      <?php if ($stuClassName): ?>
      <span class="ms-1">&middot; <?= e($stuClassName) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <nav class="mt-1">
    <?php foreach ($stuNav as $nav): ?>
    <a href="<?= APP_URL ?>/student/<?= $nav['url'] ?>" class="nav-link <?= $currentPage === $nav['url'] ? 'active' : '' ?>">
      <i class="<?= $nav['icon'] ?>"></i><?= $nav['label'] ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="<?= APP_URL ?>/student/logout.php" class="nav-link text-danger">
      <i class="fas fa-sign-out-alt"></i>Sign Out
    </a>
  </div>
</div>

<div id="stuMain">
  <div id="stuTopbar">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-sm btn-outline-secondary d-md-none"
              onclick="document.getElementById('stuSidebar').classList.toggle('show')">
        <i class="fas fa-bars"></i>
      </button>
      <span class="fw-semibold text-dark"><?= e($pageTitle) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3">
      <span class="small text-muted d-none d-sm-inline">
        <i class="fas fa-calendar-day me-1"></i><?= date('l, d M Y') ?>
      </span>
      <div class="d-flex align-items-center gap-2">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
             style="width:32px;height:32px;background:var(--stu-blue);font-size:.75rem">
          <?= strtoupper(substr($stuName, 0, 1)) ?>
        </div>
        <span class="small fw-semibold d-none d-sm-inline" style="color:var(--stu-navy)"><?= e(explode(' ', $stuName)[0]) ?></span>
      </div>
    </div>
  </div>

  <div id="stuContent">
    <?= flashAlert() ?>
