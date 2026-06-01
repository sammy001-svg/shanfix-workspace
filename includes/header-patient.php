<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

// ── Patient auth guard ────────────────────────────────────────────
if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'patient') {
    redirect(APP_URL . '/auth/login.php');
}
$timeout = SESSION_LIFETIME;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset(); session_destroy();
    redirect(APP_URL . '/auth/login.php?expired=1');
}
$_SESSION['last_activity'] = time();

// Recover patient_id if missing from session
if (empty($_SESSION['patient_id'])) {
    try {
        $pt = $pdo->prepare("SELECT id FROM health_patients WHERE user_id=? AND org_id=? LIMIT 1");
        $pt->execute([(int)$_SESSION['user_id'], (int)$_SESSION['org_id']]);
        $pid = $pt->fetchColumn();
        if ($pid) $_SESSION['patient_id'] = (int)$pid;
        else { session_unset(); session_destroy(); redirect(APP_URL . '/auth/login.php'); }
    } catch (Throwable $e) { redirect(APP_URL . '/auth/login.php'); }
}

$patientId = (int)$_SESSION['patient_id'];
$orgId     = (int)$_SESSION['org_id'];
$patName   = $_SESSION['user_name'] ?? 'Patient';
$pageTitle = $pageTitle ?? 'Patient Portal';

// Fetch minimal patient record for display
$patientRow = [];
try {
    $s = $pdo->prepare("SELECT first_name, last_name, patient_no, blood_group FROM health_patients WHERE id=? AND org_id=? LIMIT 1");
    $s->execute([$patientId, $orgId]);
    $patientRow = $s->fetch() ?: [];
} catch (Throwable $e) {}

$currentPage = basename($_SERVER['PHP_SELF']);
$patNav = [
    ['url'=>'index.php',        'icon'=>'fas fa-th-large',      'label'=>'Dashboard'],
    ['url'=>'appointments.php', 'icon'=>'fas fa-calendar-check','label'=>'My Appointments'],
    ['url'=>'records.php',      'icon'=>'fas fa-file-medical',  'label'=>'Medical Records'],
    ['url'=>'lab-results.php',  'icon'=>'fas fa-flask',         'label'=>'Lab Results'],
    ['url'=>'prescriptions.php','icon'=>'fas fa-prescription',  'label'=>'Prescriptions'],
    ['url'=>'bills.php',        'icon'=>'fas fa-receipt',       'label'=>'My Bills'],
    ['url'=>'vitals.php',       'icon'=>'fas fa-heartbeat',     'label'=>'Vital Signs'],
    ['url'=>'profile.php',      'icon'=>'fas fa-user-circle',   'label'=>'My Profile'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — Patient Portal</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<style>
:root { --pat-red:#e74c3c; --pat-red-dark:#c0392b; --pat-red-pale:#fde8e8; }

body { background:#f4f6f9; }

/* Sidebar */
#patSidebar {
  width:240px; min-height:100vh; background:#fff;
  border-right:1px solid #e9ecef; position:fixed; top:0; left:0; z-index:100;
  display:flex; flex-direction:column;
}
#patSidebar .sidebar-brand {
  padding:1.25rem 1.25rem .75rem;
  border-bottom:1px solid #f1f3f5;
}
#patSidebar .sidebar-brand .brand-name {
  font-size:.8rem; font-weight:700; color:var(--pat-red); text-transform:uppercase; letter-spacing:.5px;
}
#patSidebar .sidebar-brand .patient-name {
  font-size:1rem; font-weight:700; color:#1a1a2e; line-height:1.2; margin-top:.25rem;
}
#patSidebar .sidebar-brand .patient-no {
  font-size:.72rem; color:#6c757d;
}
#patSidebar .nav-link {
  display:flex; align-items:center; gap:.75rem;
  padding:.6rem 1.25rem; color:#495057; font-size:.875rem;
  border-radius:0; border-left:3px solid transparent; transition:all .15s;
}
#patSidebar .nav-link:hover { background:var(--pat-red-pale); color:var(--pat-red); }
#patSidebar .nav-link.active { background:var(--pat-red-pale); color:var(--pat-red); border-left-color:var(--pat-red); font-weight:600; }
#patSidebar .nav-link i { width:18px; text-align:center; font-size:.85rem; }
#patSidebar .sidebar-footer {
  margin-top:auto; padding:.75rem 1.25rem; border-top:1px solid #f1f3f5;
}

/* Main content */
#patMain {
  margin-left:240px; min-height:100vh;
}
#patTopbar {
  background:#fff; border-bottom:1px solid #e9ecef;
  padding:.75rem 1.5rem; display:flex; align-items:center; justify-content:space-between;
  position:sticky; top:0; z-index:90;
}
#patContent { padding:1.5rem; }

/* Cards */
.pat-stat-card {
  background:#fff; border-radius:12px; padding:1.25rem;
  box-shadow:0 1px 3px rgba(0,0,0,.08); border:1px solid #f1f3f5;
}
.pat-stat-icon {
  width:44px; height:44px; border-radius:10px;
  display:flex; align-items:center; justify-content:center; font-size:1.1rem;
}

/* Mobile toggle */
@media (max-width:767px) {
  #patSidebar { transform:translateX(-100%); transition:transform .25s; }
  #patSidebar.show { transform:translateX(0); }
  #patMain { margin-left:0; }
}
</style>
</head>
<body>

<!-- Sidebar -->
<div id="patSidebar">
  <div class="sidebar-brand">
    <div class="brand-name"><i class="fas fa-heartbeat me-1"></i><?= APP_NAME ?></div>
    <div class="patient-name"><?= e($patName) ?></div>
    <div class="patient-no"><?= $patientRow['patient_no'] ? 'Patient #' . e($patientRow['patient_no']) : 'Patient Portal' ?></div>
  </div>

  <nav class="mt-2">
    <?php foreach ($patNav as $nav): ?>
    <a href="<?= APP_URL ?>/patient/<?= $nav['url'] ?>" class="nav-link <?= $currentPage === $nav['url'] ? 'active' : '' ?>">
      <i class="<?= $nav['icon'] ?>"></i><?= $nav['label'] ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="<?= APP_URL ?>/patient/logout.php" class="nav-link text-danger">
      <i class="fas fa-sign-out-alt"></i>Sign Out
    </a>
  </div>
</div>

<!-- Main -->
<div id="patMain">
  <!-- Topbar -->
  <div id="patTopbar">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-sm btn-outline-secondary d-md-none" onclick="document.getElementById('patSidebar').classList.toggle('show')">
        <i class="fas fa-bars"></i>
      </button>
      <span class="fw-semibold text-dark"><?= e($pageTitle) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3">
      <?php if (!empty($patientRow['blood_group'])): ?>
      <span class="badge rounded-circle" style="background:var(--pat-red);width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;font-size:.65rem">
        <?= e($patientRow['blood_group']) ?>
      </span>
      <?php endif; ?>
      <span class="small text-muted d-none d-md-inline">
        <i class="fas fa-calendar-day me-1"></i><?= date('d M Y') ?>
      </span>
    </div>
  </div>

  <!-- Page content -->
  <div id="patContent">
    <?= flashAlert() ?>
