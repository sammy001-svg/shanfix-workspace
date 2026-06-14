<?php
/**
 * Teacher Portal — shared layout header.
 * Include at the top of every teacher/ page AFTER setting $pageTitle.
 * Provides auth guard, sidebar, and topbar.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

// ── Auth guard ────────────────────────────────────────────────────
$_tchOrgSlug  = $_SESSION['tch_org_slug'] ?? null;
$_tchLoginUrl = APP_URL . '/teacher/login.php' . ($_tchOrgSlug ? '?org=' . rawurlencode($_tchOrgSlug) : '');

if (empty($_SESSION['tch_id']) || empty($_SESSION['tch_org_id'])) {
    redirect($_tchLoginUrl);
}

// Session timeout
if (isset($_SESSION['tch_last_act']) && (time() - $_SESSION['tch_last_act']) > SESSION_LIFETIME) {
    $__slug = $_SESSION['tch_org_slug'] ?? null;
    session_unset(); session_destroy();
    redirect(APP_URL . '/teacher/login.php' . ($__slug ? '?org=' . rawurlencode($__slug) . '&expired=1' : '?expired=1'));
}
$_SESSION['tch_last_act'] = time();

$tchId      = (int)$_SESSION['tch_id'];
$tchOrgId   = (int)$_SESSION['tch_org_id'];
$tchName    = $_SESSION['tch_name'] ?? 'Teacher';
$schoolName = $_SESSION['tch_org_name'] ?? APP_NAME;
$pageTitle  = $pageTitle ?? 'Teacher Portal';
$currentPage = basename($_SERVER['PHP_SELF']);

// Load teacher record for subject/class scope info
$teacherRecord = [];
try {
    $__t = $pdo->prepare("SELECT * FROM sch_teachers WHERE id=? AND org_id=? LIMIT 1");
    $__t->execute([$tchId, $tchOrgId]);
    $teacherRecord = $__t->fetch() ?: [];
} catch (Throwable $e) {}

// Notification counts for sidebar badges
$tchAttNeeded = 0;
try {
    $s = $pdo->prepare(
        "SELECT COUNT(DISTINCT t.class_id)
         FROM sch_timetable t
         WHERE t.org_id=? AND t.staff_id=? AND t.day_of_week=?
           AND t.class_id NOT IN (
             SELECT DISTINCT class_id FROM sch_attendance WHERE org_id=? AND att_date=CURDATE()
           )"
    );
    $s->execute([$tchOrgId, $tchId, (int)date('N'), $tchOrgId]);
    $tchAttNeeded = (int)$s->fetchColumn();
} catch (Throwable $e) {}

$tchNoticeCount = 0;
try {
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM sch_notices
         WHERE org_id=? AND audience IN ('all','staff','teachers')
           AND (expiry_date IS NULL OR expiry_date >= CURDATE())
           AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $s->execute([$tchOrgId]);
    $tchNoticeCount = (int)$s->fetchColumn();
} catch (Throwable $e) {}

$tchNav = [
    ['url'=>'index.php',      'icon'=>'fas fa-th-large',       'label'=>'Dashboard'],
    ['url'=>'attendance.php', 'icon'=>'fas fa-clipboard-check','label'=>'Attendance', 'badge'=>$tchAttNeeded ?: null],
    ['url'=>'results.php',    'icon'=>'fas fa-graduation-cap', 'label'=>'Results'],
    ['url'=>'homework.php',   'icon'=>'fas fa-book-open',      'label'=>'Homework'],
    ['url'=>'timetable.php',      'icon'=>'fas fa-calendar-week', 'label'=>'Timetable'],
    ['url'=>'online-classes.php', 'icon'=>'fas fa-video',         'label'=>'Online Classes'],
    ['url'=>'students.php',       'icon'=>'fas fa-users',         'label'=>'My Students'],
    ['url'=>'notices.php',        'icon'=>'fas fa-bullhorn',      'label'=>'Notices', 'badge'=>$tchNoticeCount ?: null],
    ['url'=>'profile.php',    'icon'=>'fas fa-user-circle',    'label'=>'My Profile'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> &mdash; Teacher Portal | <?= e($schoolName) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<style>
:root {
  --tch-green: #1A8A4E;
  --tch-green-dark: #146038;
  --tch-green-pale: #f0fdf4;
  --tch-navy: #0B2D4E;
}
body { background: #f4f6f9; }

/* ── Sidebar ──────────────────────────────────────────────────── */
#tchSidebar {
  width: 240px; min-height: 100vh; background: #fff;
  border-right: 1px solid #e9ecef; position: fixed; top: 0; left: 0; z-index: 100;
  display: flex; flex-direction: column;
}
#tchSidebar .tch-brand {
  padding: 1.25rem 1.25rem .75rem;
  border-bottom: 1px solid #f1f3f5;
}
.tch-brand-label {
  font-size: .72rem; font-weight: 700; color: var(--tch-green);
  text-transform: uppercase; letter-spacing: .5px;
}
.tch-brand-school {
  font-size: .9rem; font-weight: 700; color: var(--tch-navy); margin-top: 2px;
  line-height: 1.3;
}
.tch-brand-teacher {
  font-size: .75rem; color: #6c757d; margin-top: 2px;
}

.tch-teacher-badge {
  margin: .75rem 1.25rem .25rem;
  background: var(--tch-green-pale);
  border: 1px solid #bbf7d0;
  border-radius: 10px;
  padding: 10px 12px;
}
.tch-teacher-badge .tch-tname {
  font-size: .82rem; font-weight: 700; color: var(--tch-navy); line-height: 1.3;
}
.tch-teacher-badge .tch-tmeta {
  font-size: .72rem; color: #6c757d;
}

#tchSidebar .nav-link {
  display: flex; align-items: center; gap: .75rem;
  padding: .55rem 1.25rem; color: #495057; font-size: .875rem;
  border-radius: 0; border-left: 3px solid transparent; transition: all .15s;
  text-decoration: none;
}
#tchSidebar .nav-link:hover {
  background: var(--tch-green-pale); color: var(--tch-green);
}
#tchSidebar .nav-link.active {
  background: var(--tch-green-pale); color: var(--tch-green);
  border-left-color: var(--tch-green); font-weight: 600;
}
#tchSidebar .nav-link i { width: 18px; text-align: center; font-size: .85rem; }
#tchSidebar .sidebar-footer {
  margin-top: auto; padding: .75rem 1.25rem; border-top: 1px solid #f1f3f5;
}

/* ── Main area ────────────────────────────────────────────────── */
#tchMain { margin-left: 240px; min-height: 100vh; }
#tchTopbar {
  background: #fff; border-bottom: 1px solid #e9ecef;
  padding: .75rem 1.5rem;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 90;
}
#tchContent { padding: 1.5rem; }

/* ── Reusable cards ───────────────────────────────────────────── */
.tch-stat-card {
  background: #fff; border-radius: 12px; padding: 1.25rem;
  box-shadow: 0 1px 3px rgba(0,0,0,.08); border: 1px solid #f1f3f5;
  height: 100%;
}
.tch-stat-icon {
  width: 44px; height: 44px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
  flex-shrink: 0;
}

/* ── Mobile ───────────────────────────────────────────────────── */
@media (max-width: 767px) {
  #tchSidebar { transform: translateX(-100%); transition: transform .25s; }
  #tchSidebar.show { transform: translateX(0); }
  #tchMain { margin-left: 0; }
  #tchContent { padding: 1rem; }
}
</style>
</head>
<body>

<!-- ── Sidebar ────────────────────────────────────────────────── -->
<div id="tchSidebar">
  <div class="tch-brand">
    <div class="tch-brand-label"><i class="fas fa-chalkboard-teacher me-1"></i>Teacher Portal</div>
    <div class="tch-brand-school"><?= e($schoolName) ?></div>
    <div class="tch-brand-teacher"><i class="fas fa-user me-1"></i><?= e($tchName) ?></div>
  </div>

  <!-- Teacher info badge -->
  <div class="tch-teacher-badge">
    <div class="tch-tname"><?= e($tchName) ?></div>
    <div class="tch-tmeta">
      <?php if (!empty($teacherRecord['employee_id'])): ?>
      <i class="fas fa-id-badge me-1"></i><?= e($teacherRecord['employee_id']) ?>
      <?php endif; ?>
      <?php if (!empty($teacherRecord['specialization'])): ?>
      <div class="mt-1"><i class="fas fa-book me-1"></i><?= e($teacherRecord['specialization']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <nav class="mt-1">
    <?php foreach ($tchNav as $nav): ?>
    <a href="<?= APP_URL ?>/teacher/<?= $nav['url'] ?>" class="nav-link <?= $currentPage === $nav['url'] ? 'active' : '' ?>">
      <i class="<?= $nav['icon'] ?>"></i>
      <span class="flex-grow-1"><?= $nav['label'] ?></span>
      <?php if (!empty($nav['badge'])): ?>
      <span class="badge rounded-pill text-white" style="background:#e74c3c;font-size:.58rem;min-width:18px;text-align:center"><?= (int)$nav['badge'] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="<?= APP_URL ?>/teacher/logout.php" class="nav-link text-danger">
      <i class="fas fa-sign-out-alt"></i>Sign Out
    </a>
  </div>
</div>

<!-- ── Main wrapper ───────────────────────────────────────────── -->
<div id="tchMain">
  <div id="tchTopbar">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-sm btn-outline-secondary d-md-none"
              onclick="document.getElementById('tchSidebar').classList.toggle('show')">
        <i class="fas fa-bars"></i>
      </button>
      <span class="fw-semibold text-dark"><?= e($pageTitle) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3">
      <span class="small text-muted d-none d-sm-inline">
        <i class="fas fa-calendar-day me-1"></i><?= date('l, d M Y') ?>
      </span>
      <?php $tchTotalBadge = $tchAttNeeded + $tchNoticeCount; ?>
      <div class="position-relative">
        <button class="btn btn-sm btn-light border d-flex align-items-center justify-content-center"
                style="border-radius:50%;width:34px;height:34px;padding:0"
                data-bs-toggle="dropdown" aria-expanded="false">
          <i class="fas fa-bell" style="font-size:.85rem;color:#6c757d"></i>
        </button>
        <?php if ($tchTotalBadge > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
              style="font-size:.58rem"><?= $tchTotalBadge ?></span>
        <?php endif; ?>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-1" style="min-width:260px">
          <?php if ($tchAttNeeded > 0): ?>
          <li>
            <a class="dropdown-item py-2" href="<?= APP_URL ?>/teacher/attendance.php">
              <div class="d-flex align-items-center gap-2">
                <i class="fas fa-clipboard-check text-danger" style="width:16px;font-size:.85rem"></i>
                <div>
                  <div class="fw-semibold small"><?= $tchAttNeeded ?> class<?= $tchAttNeeded!==1?'es':'' ?> need attendance</div>
                  <div class="text-muted" style="font-size:.7rem">Mark today's register</div>
                </div>
              </div>
            </a>
          </li>
          <?php endif; ?>
          <?php if ($tchNoticeCount > 0): ?>
          <li>
            <a class="dropdown-item py-2" href="<?= APP_URL ?>/teacher/notices.php">
              <div class="d-flex align-items-center gap-2">
                <i class="fas fa-bullhorn text-warning" style="width:16px;font-size:.85rem"></i>
                <div>
                  <div class="fw-semibold small"><?= $tchNoticeCount ?> new notice<?= $tchNoticeCount!==1?'s':'' ?></div>
                  <div class="text-muted" style="font-size:.7rem">School announcements this week</div>
                </div>
              </div>
            </a>
          </li>
          <?php endif; ?>
          <?php if ($tchTotalBadge === 0): ?>
          <li><div class="dropdown-item py-2 text-muted small">No new notifications</div></li>
          <?php endif; ?>
        </ul>
      </div>
      <div class="d-flex align-items-center gap-2">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
             style="width:32px;height:32px;background:var(--tch-green);font-size:.75rem">
          <?= strtoupper(substr($tchName, 0, 1)) ?>
        </div>
        <span class="small fw-semibold d-none d-sm-inline" style="color:var(--tch-navy)"><?= e(explode(' ', $tchName)[0]) ?></span>
      </div>
    </div>
  </div>

  <div id="tchContent">
    <?= flashAlert() ?>
