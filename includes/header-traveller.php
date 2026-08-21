<?php
/**
 * Traveller Portal — shared layout header.
 * Include at the top of every traveller/ page AFTER setting $pageTitle.
 * Provides the auth guard, trip switcher, sidebar and topbar.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../modules/tour/_lib.php';
sendSecurityHeaders();

// ── Auth guard ────────────────────────────────────────────────────
$_trvOrgSlug  = $_SESSION['trv_org_slug'] ?? null;
$_trvLoginUrl = APP_URL . '/traveller/login.php' . ($_trvOrgSlug ? '?org=' . rawurlencode($_trvOrgSlug) : '');

if (empty($_SESSION['trv_booking_id']) || empty($_SESSION['trv_org_id'])) {
    redirect($_trvLoginUrl);
}

// Session timeout
if (isset($_SESSION['trv_last_act']) && (time() - $_SESSION['trv_last_act']) > SESSION_LIFETIME) {
    $__slug = $_SESSION['trv_org_slug'] ?? null;
    session_unset(); session_destroy();
    redirect(APP_URL . '/traveller/login.php' . ($__slug ? '?org=' . rawurlencode($__slug) . '&expired=1' : '?expired=1'));
}
$_SESSION['trv_last_act'] = time();

$trvOrgId = (int)$_SESSION['trv_org_id'];
$trvName  = $_SESSION['trv_name'] ?? 'Traveller';
$trvBids  = array_map('intval', $_SESSION['trv_bids'] ?? []);
$trvBid   = (int)($_SESSION['trv_booking_id'] ?? 0);

// Allow switching between this traveller's own trips via ?bid=X
if (!empty($_GET['bid']) && in_array((int)$_GET['bid'], $trvBids, true)) {
    $trvBid = (int)$_GET['bid'];
    $_SESSION['trv_booking_id'] = $trvBid;
}

// The portal can be switched off by the operator at any time
if (tourConf($trvOrgId, 't_portal_enabled') !== '1') {
    session_unset(); session_destroy();
    redirect(APP_URL . '/traveller/login.php' . ($_trvOrgSlug ? '?org=' . rawurlencode($_trvOrgSlug) . '&closed=1' : '?closed=1'));
}

// ── Active booking ────────────────────────────────────────────────
$trip = [];
try {
    $__t = $pdo->prepare("
        SELECT b.*, p.name AS package_name, p.duration_days, p.includes, p.excludes, p.description AS package_desc,
               dest.name AS dest_name, dest.country AS dest_country,
               d.departure_code, d.start_date AS departure_start, d.end_date AS departure_end, d.meeting_point,
               g.name AS guide_name, g.phone AS guide_phone, g.languages AS guide_languages,
               v.name AS vehicle_name, v.reg_no AS vehicle_reg
        FROM tour_bookings b
        LEFT JOIN tour_packages p        ON p.id = b.package_id
        LEFT JOIN tour_destinations dest ON dest.id = p.destination_id
        LEFT JOIN tour_departures d      ON d.id = b.departure_id
        LEFT JOIN tour_guides g          ON g.id = d.guide_id
        LEFT JOIN tour_vehicles v        ON v.id = d.vehicle_id
        WHERE b.id = ? AND b.org_id = ? AND b.portal_enabled = 1
        LIMIT 1
    ");
    $__t->execute([$trvBid, $trvOrgId]);
    $trip = $__t->fetch() ?: [];
} catch (Throwable $e) {}

if (!$trip) {
    // Access revoked or the booking vanished while the session was open
    session_unset(); session_destroy();
    redirect(APP_URL . '/traveller/login.php' . ($_trvOrgSlug ? '?org=' . rawurlencode($_trvOrgSlug) . '&revoked=1' : '?revoked=1'));
}

// All trips belonging to this traveller, for the switcher
$allTrips = [];
if (count($trvBids) > 1) {
    try {
        $__in = implode(',', array_fill(0, count($trvBids), '?'));
        $__q  = $pdo->prepare("
            SELECT b.id, b.booking_no, b.travel_date, b.status, p.name AS package_name
            FROM tour_bookings b
            LEFT JOIN tour_packages p ON p.id = b.package_id
            WHERE b.id IN ($__in) AND b.org_id = ?
            ORDER BY b.travel_date DESC
        ");
        $__q->execute(array_merge($trvBids, [$trvOrgId]));
        $allTrips = $__q->fetchAll();
    } catch (Throwable $e) {}
}

// ── Money position ────────────────────────────────────────────────
$tripPaid    = tourBookingPaid($trvOrgId, $trvBid);
if ($tripPaid <= 0) $tripPaid = (float)($trip['paid_amount'] ?? 0);
$tripTotal   = (float)($trip['total_amount'] ?? 0);
$tripBalance = max(0, $tripTotal - $tripPaid);

$daysToGo = null;
if (!empty($trip['travel_date'])) {
    $__diff  = (strtotime($trip['travel_date']) - strtotime(date('Y-m-d'))) / 86400;
    $daysToGo = (int)round($__diff);
}

// ── Operator branding ─────────────────────────────────────────────
$operatorName = tourConf($trvOrgId, 't_company_name') ?: ($_SESSION['trv_org_name'] ?? APP_NAME);
$operatorLogo = $_SESSION['trv_org_logo'] ?? '';
$emergencyNo  = tourConf($trvOrgId, 't_emergency_phone');

$pageTitle   = $pageTitle ?? 'My Trip';
$currentPage = basename($_SERVER['PHP_SELF']);

$trvNav = [
    ['url' => 'index.php',     'icon' => 'fas fa-suitcase-rolling', 'label' => 'My Trip'],
    ['url' => 'itinerary.php', 'icon' => 'fas fa-route',            'label' => 'Itinerary'],
    ['url' => 'payments.php',  'icon' => 'fas fa-receipt',          'label' => 'Payments', 'badge' => $tripBalance > 0 ? '!' : null],
    ['url' => 'profile.php',   'icon' => 'fas fa-user-circle',      'label' => 'My Details'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — Traveller Portal | <?= e($operatorName) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<style>
:root {
  --trv-blue: #2980b9;
  --trv-blue-dark: #1f6391;
  --trv-blue-pale: #eff6fb;
  --trv-navy: #0B2D4E;
}
body { background: #f4f6f9; }

/* ── Sidebar ──────────────────────────────────────────────────── */
#trvSidebar {
  width: 240px; min-height: 100vh; background: #fff;
  border-right: 1px solid #e9ecef; position: fixed; top: 0; left: 0; z-index: 100;
  display: flex; flex-direction: column;
}
#trvSidebar .trv-brand { padding: 1.25rem 1.25rem .75rem; border-bottom: 1px solid #f1f3f5; }
.trv-brand-label {
  font-size: .72rem; font-weight: 700; color: var(--trv-blue);
  text-transform: uppercase; letter-spacing: .5px;
}
.trv-brand-org { font-size: .9rem; font-weight: 700; color: var(--trv-navy); margin-top: 2px; line-height: 1.3; }
.trv-brand-user { font-size: .75rem; color: #6c757d; margin-top: 2px; }

.trv-trip-badge {
  margin: .75rem 1.25rem .25rem;
  background: var(--trv-blue-pale); border: 1px solid #cfe4f3;
  border-radius: 10px; padding: 10px 12px;
}
.trv-trip-badge .trip-name { font-size: .82rem; font-weight: 700; color: var(--trv-navy); line-height: 1.3; }
.trv-trip-badge .trip-meta { font-size: .72rem; color: #6c757d; }
.trv-switch-link {
  display: block; text-align: center; font-size: .7rem; font-weight: 600;
  color: var(--trv-blue); margin-top: 6px; text-decoration: none;
}
.trv-switch-link:hover { text-decoration: underline; }

#trvSidebar .nav-link {
  display: flex; align-items: center; gap: .75rem;
  padding: .55rem 1.25rem; color: #495057; font-size: .875rem;
  border-left: 3px solid transparent; transition: all .15s; text-decoration: none;
}
#trvSidebar .nav-link:hover { background: var(--trv-blue-pale); color: var(--trv-blue); }
#trvSidebar .nav-link.active {
  background: var(--trv-blue-pale); color: var(--trv-blue);
  border-left-color: var(--trv-blue); font-weight: 600;
}
#trvSidebar .nav-link i { width: 18px; text-align: center; font-size: .85rem; }
#trvSidebar .sidebar-footer { margin-top: auto; padding: .75rem 1.25rem; border-top: 1px solid #f1f3f5; }

/* ── Main area ────────────────────────────────────────────────── */
#trvMain { margin-left: 240px; min-height: 100vh; }
#trvTopbar {
  background: #fff; border-bottom: 1px solid #e9ecef; padding: .75rem 1.5rem;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 90;
}
#trvContent { padding: 1.5rem; }

/* ── Reusable cards ───────────────────────────────────────────── */
.trv-card {
  background: #fff; border-radius: 12px; padding: 1.25rem;
  box-shadow: 0 1px 3px rgba(0,0,0,.08); border: 1px solid #f1f3f5; height: 100%;
}
.trv-stat-icon {
  width: 44px; height: 44px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;
}
.trv-hero {
  border-radius: 14px; padding: 1.75rem;
  background: linear-gradient(135deg, var(--trv-blue) 0%, var(--trv-navy) 100%);
  color: #fff;
}

/* ── Mobile ───────────────────────────────────────────────────── */
@media (max-width: 767px) {
  #trvSidebar { transform: translateX(-100%); transition: transform .25s; }
  #trvSidebar.show { transform: translateX(0); }
  #trvMain { margin-left: 0; }
  #trvContent { padding: 1rem; }
}
</style>
</head>
<body>

<!-- ── Sidebar ────────────────────────────────────────────────── -->
<div id="trvSidebar">
  <div class="trv-brand">
    <div class="trv-brand-label"><i class="fas fa-plane me-1"></i>Traveller Portal</div>
    <div class="trv-brand-org"><?= e($operatorName) ?></div>
    <div class="trv-brand-user"><i class="fas fa-user me-1"></i><?= e($trvName) ?></div>
  </div>

  <div class="trv-trip-badge">
    <div class="trip-name"><?= e($trip['package_name'] ?: 'Your trip') ?></div>
    <div class="trip-meta">
      <i class="fas fa-hashtag me-1"></i><?= e($trip['booking_no']) ?>
      <?php if (!empty($trip['travel_date'])): ?>
      &nbsp;&middot;&nbsp;<?= formatDate($trip['travel_date']) ?>
      <?php endif; ?>
    </div>
    <?php if (count($allTrips) > 1): ?>
    <a href="#" class="trv-switch-link" data-bs-toggle="collapse" data-bs-target="#tripSwitcher">
      <i class="fas fa-exchange-alt me-1"></i>Switch Trip
    </a>
    <div class="collapse mt-2" id="tripSwitcher">
      <?php foreach ($allTrips as $t): ?>
      <a href="?bid=<?= (int)$t['id'] ?>" class="d-block py-1 px-2 rounded small text-decoration-none"
         style="color:var(--trv-navy);background:<?= (int)$t['id'] === $trvBid ? '#dbeafe' : '' ?>">
        <i class="fas fa-suitcase me-1" style="color:var(--trv-blue)"></i>
        <?= e($t['package_name'] ?: $t['booking_no']) ?>
        <span class="text-muted" style="font-size:.65rem">(<?= formatDate($t['travel_date']) ?>)</span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <nav class="mt-1">
    <?php foreach ($trvNav as $nav): ?>
    <a href="<?= APP_URL ?>/traveller/<?= $nav['url'] ?>" class="nav-link <?= $currentPage === $nav['url'] ? 'active' : '' ?>">
      <i class="<?= $nav['icon'] ?>"></i>
      <span class="flex-grow-1"><?= $nav['label'] ?></span>
      <?php if (!empty($nav['badge'])): ?>
      <span class="badge rounded-pill text-white" style="background:#e74c3c;font-size:.58rem;min-width:18px;text-align:center"><?= $nav['badge'] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <?php if ($emergencyNo): ?>
    <div class="small text-muted mb-2">
      <div class="fw-semibold" style="font-size:.68rem;letter-spacing:.4px;text-transform:uppercase">24/7 Emergency</div>
      <a href="tel:<?= e($emergencyNo) ?>" class="text-decoration-none" style="color:var(--trv-blue)">
        <i class="fas fa-phone me-1"></i><?= e($emergencyNo) ?>
      </a>
    </div>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/traveller/logout.php" class="nav-link text-danger px-0">
      <i class="fas fa-sign-out-alt"></i>Sign Out
    </a>
  </div>
</div>

<!-- ── Main wrapper ───────────────────────────────────────────── -->
<div id="trvMain">
  <div id="trvTopbar">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-sm btn-outline-secondary d-md-none"
              onclick="document.getElementById('trvSidebar').classList.toggle('show')">
        <i class="fas fa-bars"></i>
      </button>
      <span class="fw-semibold text-dark"><?= e($pageTitle) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3">
      <?php if ($daysToGo !== null && $daysToGo >= 0 && ($trip['status'] ?? '') !== 'cancelled'): ?>
      <span class="badge rounded-pill" style="background:var(--trv-blue-pale);color:var(--trv-blue);font-weight:600">
        <i class="fas fa-clock me-1"></i>
        <?= $daysToGo === 0 ? 'Departing today' : $daysToGo . ' day' . ($daysToGo === 1 ? '' : 's') . ' to go' ?>
      </span>
      <?php endif; ?>
      <span class="small text-muted d-none d-sm-inline"><i class="fas fa-calendar-day me-1"></i><?= date('d M Y') ?></span>
      <div class="d-flex align-items-center gap-2">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
             style="width:32px;height:32px;background:var(--trv-blue);font-size:.75rem">
          <?= strtoupper(substr($trvName, 0, 1)) ?>
        </div>
        <span class="small fw-semibold d-none d-sm-inline" style="color:var(--trv-navy)"><?= e(explode(' ', $trvName)[0]) ?></span>
      </div>
    </div>
  </div>

  <div id="trvContent">
    <?= flashAlert() ?>
