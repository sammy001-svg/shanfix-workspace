<?php
/**
 * White-label health portal header.
 * Replaces header-module.php when $_SESSION['health_portal_mode'] is active.
 * Shows ONLY health navigation with org branding — no OrbitDesk references.
 *
 * Included by header-module.php when portal mode is detected.
 * The calling page's $moduleNav array is used for navigation.
 */

// ── Auth guard ────────────────────────────────────────────────────
requireLogin();

$portalOrgId = (int)($_SESSION['health_portal_org_id'] ?? 0);
$user        = currentUser();

if ((int)$user['org_id'] !== $portalOrgId) {
    // Org mismatch — boot to portal login
    session_unset(); session_destroy();
    header('Location: /modules/health/portal-login.php'); exit;
}

// ── Branding ──────────────────────────────────────────────────────
$accent   = $_SESSION['health_portal_accent'] ?? '#e74c3c';
$ptitle   = $_SESSION['health_portal_title']  ?? ($user['org_name'] ?? 'Health Portal');
$logoUrl  = $_SESSION['health_portal_logo']   ?? '';
$initial  = strtoupper(substr($ptitle, 0, 1));

// Darken accent for hover/active states
function hpAdjust(string $hex, int $amt): string {
    $h = ltrim($hex, '#');
    return sprintf('#%02x%02x%02x',
        max(0, min(255, hexdec(substr($h,0,2)) + $amt)),
        max(0, min(255, hexdec(substr($h,2,2)) + $amt)),
        max(0, min(255, hexdec(substr($h,4,2)) + $amt))
    );
}
$accentDark = hpAdjust($accent, -30);
$accentPale = hpAdjust($accent, 60) . '22'; // transparent tint

$pageTitle  = ($moduleName ?? 'Health') . ' — ' . $ptitle;
$currentUrl = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="robots" content="noindex, nofollow">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/mobile.css" rel="stylesheet">
<style>
:root {
  --hp-accent:      <?= $accent ?>;
  --hp-accent-dark: <?= $accentDark ?>;
  --hp-accent-pale: <?= $accentPale ?>;
  --hp-sidebar-w:   240px;
}

/* ── Reset sidebar to portal colours ──────────────────────────── */
#hpSidebar {
  width: var(--hp-sidebar-w); min-height: 100vh;
  background: #0f1923; position: fixed; left: 0; top: 0; bottom: 0;
  display: flex; flex-direction: column; z-index: 1040;
  transition: transform .3s ease;
}
#hpMain { margin-left: var(--hp-sidebar-w); min-height: 100vh; background: #f5f6fa; }
#hpContent { padding: 1.5rem; }

/* ── Brand bar ─────────────────────────────────────────────────── */
.hp-brand {
  padding: 20px 16px 16px;
  background: var(--hp-accent);
  display: flex; align-items: center; gap: 12px; flex-shrink: 0;
}
.hp-brand-logo {
  width: 40px; height: 40px; border-radius: 10px;
  background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.3);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; font-weight: 900; color: #fff; overflow: hidden; flex-shrink: 0;
}
.hp-brand-logo img { width: 100%; height: 100%; object-fit: contain; padding: 6px; }
.hp-brand-name { color: #fff; font-size: .9rem; font-weight: 800; line-height: 1.2; }
.hp-brand-sub  { color: rgba(255,255,255,.65); font-size: .68rem; margin-top: 1px; }

/* ── Nav ───────────────────────────────────────────────────────── */
.hp-nav { flex: 1; overflow-y: auto; padding: 12px 0; }
.hp-nav::-webkit-scrollbar { width: 4px; }
.hp-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 2px; }

.hp-nav-section {
  font-size: .6rem; font-weight: 800; text-transform: uppercase; letter-spacing: .8px;
  color: rgba(255,255,255,.3); padding: 14px 16px 5px; margin-top: 4px;
}
.hp-nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 16px; font-size: .82rem; font-weight: 500;
  color: rgba(255,255,255,.65); text-decoration: none;
  border-left: 3px solid transparent; transition: all .15s;
  margin: 1px 0;
}
.hp-nav-item:hover {
  color: #fff; background: rgba(255,255,255,.07);
  border-left-color: rgba(255,255,255,.3);
}
.hp-nav-item.active {
  color: #fff; background: var(--hp-accent-pale);
  border-left-color: var(--hp-accent); font-weight: 700;
}
.hp-nav-item.active { background: <?= $accent ?>33; border-left-color: <?= $accent ?>; }
.hp-nav-icon { width: 16px; text-align: center; flex-shrink: 0; font-size: .82rem; }

/* ── User footer ────────────────────────────────────────────────── */
.hp-user-footer {
  padding: 12px 14px; border-top: 1px solid rgba(255,255,255,.08);
  display: flex; align-items: center; gap: 10px; flex-shrink: 0;
}
.hp-user-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--hp-accent); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .78rem; font-weight: 700; flex-shrink: 0;
}
.hp-user-name  { color: rgba(255,255,255,.85); font-size: .78rem; font-weight: 600; }
.hp-user-role  { color: rgba(255,255,255,.4); font-size: .68rem; }
.hp-logout-btn {
  margin-left: auto; color: rgba(255,255,255,.4); font-size: .85rem;
  background: none; border: none; cursor: pointer; padding: 4px 6px; border-radius: 6px;
  transition: all .15s; flex-shrink: 0;
}
.hp-logout-btn:hover { color: #fff; background: rgba(255,255,255,.1); }

/* ── Top bar ────────────────────────────────────────────────────── */
.hp-topbar {
  background: #fff; border-bottom: 1px solid #e9ecef;
  padding: 12px 20px; display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 100;
  box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.hp-topbar .hp-breadcrumb { font-size: .85rem; color: #6c757d; }
.hp-topbar .hp-breadcrumb strong { color: #1a1a2e; font-weight: 700; }
.hp-topbar .hp-portal-badge {
  display: flex; align-items: center; gap: 6px;
  background: <?= $accent ?>15; border: 1px solid <?= $accent ?>40;
  border-radius: 20px; padding: 4px 12px; font-size: .72rem; font-weight: 700;
  color: <?= $accent ?>;
}

/* ── Mobile backdrop ────────────────────────────────────────────── */
#hpBackdrop { display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1039; }
#hpBackdrop.show { display:block; }

@media (max-width: 991px) {
  #hpSidebar { transform: translateX(-100%); }
  #hpSidebar.open { transform: translateX(0); box-shadow: 4px 0 24px rgba(0,0,0,.3); }
  #hpMain { margin-left: 0; }
}

/* ── Flash alert ─────────────────────────────────────────────────── */
.alert { border-radius: 10px; }
</style>

<!-- Sidebar -->
<div id="hpSidebar">
  <!-- Brand -->
  <div class="hp-brand">
    <div class="hp-brand-logo">
      <?php if ($logoUrl): ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="">
      <?php else: ?>
        <?= $initial ?>
      <?php endif; ?>
    </div>
    <div>
      <div class="hp-brand-name"><?= htmlspecialchars($ptitle) ?></div>
      <div class="hp-brand-sub">Health Management</div>
    </div>
  </div>

  <!-- Nav -->
  <nav class="hp-nav" aria-label="Health navigation">
    <?php
    $navSections = [
      'Overview'    => ['index.php'],
      'Patients'    => ['patients.php','appointments.php','records.php','vitals.php'],
      'Clinical'    => ['doctors.php','staff.php','lab.php','pharmacy.php','nursing.php'],
      'Inpatient'   => ['wards.php','admissions.php','surgery.php','emergency.php'],
      'Finance'     => ['billing.php'],
      'Management'  => ['reports.php','settings.php'],
    ];
    $navByUrl = [];
    foreach ($moduleNav ?? [] as $item) { $navByUrl[$item['url']] = $item; }

    foreach ($navSections as $section => $urls):
      $sectionItems = array_filter(array_map(fn($u) => $navByUrl[$u] ?? null, $urls));
      if (empty($sectionItems)) continue;
    ?>
    <div class="hp-nav-section"><?= $section ?></div>
    <?php foreach ($sectionItems as $item):
      $isActive = $currentUrl === $item['url']; ?>
    <a href="<?= htmlspecialchars($item['url']) ?>" class="hp-nav-item <?= $isActive ? 'active' : '' ?>">
      <i class="<?= htmlspecialchars($item['icon']) ?> hp-nav-icon"></i>
      <?= htmlspecialchars($item['label']) ?>
    </a>
    <?php endforeach; endforeach; ?>
  </nav>

  <!-- User footer -->
  <div class="hp-user-footer">
    <div class="hp-user-avatar"><?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?></div>
    <div>
      <div class="hp-user-name"><?= htmlspecialchars($user['name'] ?? '') ?></div>
      <div class="hp-user-role"><?= ucfirst(str_replace('_',' ', $user['role'] ?? '')) ?></div>
    </div>
    <a href="/modules/health/portal-logout.php" class="hp-logout-btn" title="Sign out">
      <i class="fas fa-sign-out-alt"></i>
    </a>
  </div>
</div>
<div id="hpBackdrop" onclick="closeSidebar()"></div>

<!-- Main content area -->
<div id="hpMain">
  <!-- Top bar -->
  <div class="hp-topbar">
    <div class="d-flex align-items-center gap-3">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
      </button>
      <div class="hp-breadcrumb">
        <strong><?= htmlspecialchars($moduleName ?? 'Health') ?></strong>
        <span class="d-none d-sm-inline"> — <?= htmlspecialchars($ptitle) ?></span>
      </div>
    </div>
    <div class="hp-portal-badge">
      <i class="fas fa-circle" style="font-size:.45rem"></i>
      <?= htmlspecialchars($ptitle) ?>
    </div>
  </div>

  <!-- Page content -->
  <div id="hpContent">
  <?= flashAlert() ?>
