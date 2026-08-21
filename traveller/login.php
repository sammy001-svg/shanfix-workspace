<?php
/**
 * Traveller Portal Login
 * URL: /traveller/login.php?org=SLUG
 * Login method: booking reference + traveller PIN
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/tour/_lib.php';
sendSecurityHeaders();

// ── Resolve the operator by slug ──────────────────────────────
$slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['org'] ?? '')));
if (!$slug) {
    setFlash('warning', 'No tour operator specified. Please use the link your operator sent you.');
    redirect(APP_URL . '/auth/login.php');
}

$orgStmt = $pdo->prepare("SELECT id, name, slug, status, logo, city, country FROM organizations WHERE slug=? LIMIT 1");
$orgStmt->execute([$slug]);
$org = $orgStmt->fetch();
if (!$org) {
    setFlash('warning', 'Traveller portal not found. Please check the link from your operator.');
    redirect(APP_URL . '/auth/login.php');
}

$orgId     = (int)$org['id'];
$orgActive = $org['status'] === 'active';
$portalOn  = tourConf($orgId, 't_portal_enabled') === '1';

$operatorName = tourConf($orgId, 't_company_name') ?: $org['name'];
$tagline      = tourConf($orgId, 't_tagline');
$welcome      = tourConf($orgId, 't_portal_welcome');
$contactPhone = tourConf($orgId, 't_contact_phone');
$contactEmail = tourConf($orgId, 't_contact_email');

// Already signed in with this operator
if (!empty($_SESSION['trv_booking_id']) && (int)($_SESSION['trv_org_id'] ?? 0) === $orgId) {
    redirect(APP_URL . '/traveller/index.php');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$notice = null;
if (!empty($_GET['expired']))     $notice = ['warning', 'fa-clock',            'Your session has expired. Please sign in again.'];
elseif (!empty($_GET['logout']))  $notice = ['success', 'fa-check-circle',     'You have been signed out.'];
elseif (!empty($_GET['revoked'])) $notice = ['warning', 'fa-user-lock',        'Access to that booking has been withdrawn. Contact your operator.'];
elseif (!empty($_GET['closed']))  $notice = ['warning', 'fa-triangle-exclamation', 'The traveller portal is currently closed.'];

$loginError = null;
$refInput   = '';

// ── POST: process sign-in ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $orgActive && $portalOn) {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $loginError = 'Security validation failed. Please refresh and try again.';
    } else {
        $refInput = trim($_POST['booking_no'] ?? '');
        $pin      = (string)($_POST['pin'] ?? '');
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!rateLimit('traveller_login', $ip, 12, 900)) {
            $loginError = 'Too many sign-in attempts. Please wait a few minutes and try again.';
        } elseif ($refInput === '' || $pin === '') {
            $loginError = 'Enter your booking reference and PIN.';
        } elseif (!ctype_digit($pin) || strlen($pin) < 4 || strlen($pin) > 8) {
            $loginError = 'Your PIN is 4 – 8 digits.';
        } else {
            $booking = null;
            try {
                $stmt = $pdo->prepare("
                    SELECT id, booking_no, customer_name, customer_phone, customer_email, customer_id, portal_pin
                    FROM tour_bookings
                    WHERE booking_no = ? AND org_id = ? AND portal_enabled = 1 AND portal_pin IS NOT NULL
                    LIMIT 1
                ");
                $stmt->execute([$refInput, $orgId]);
                $booking = $stmt->fetch();
            } catch (Throwable $e) {}

            if (!$booking || !password_verify($pin, $booking['portal_pin'])) {
                // Same message either way — never reveal which reference exists
                $loginError = 'That booking reference and PIN do not match. Please check with your operator.';
                try {
                    $pdo->prepare("INSERT INTO login_attempts (email,ip,success) VALUES (?,?,0)")
                        ->execute([substr($refInput, 0, 190), $ip]);
                } catch (Throwable $e) {}
            } else {
                // Collect this traveller's other bookings so they can switch trips
                $bids = [(int)$booking['id']];
                try {
                    $clauses = [];
                    $params  = [$orgId];
                    if (!empty($booking['customer_id'])) {
                        $clauses[] = 'customer_id = ?';
                        $params[]  = (int)$booking['customer_id'];
                    }
                    if (!empty($booking['customer_email'])) {
                        $clauses[] = 'customer_email = ?';
                        $params[]  = $booking['customer_email'];
                    }
                    if (!empty($booking['customer_phone'])) {
                        $clauses[] = 'customer_phone = ?';
                        $params[]  = $booking['customer_phone'];
                    }
                    if ($clauses) {
                        $sql  = "SELECT id FROM tour_bookings
                                 WHERE org_id = ? AND portal_enabled = 1 AND (" . implode(' OR ', $clauses) . ")";
                        $more = $pdo->prepare($sql);
                        $more->execute($params);
                        $bids = array_values(array_unique(array_merge(
                            $bids,
                            array_map('intval', $more->fetchAll(PDO::FETCH_COLUMN))
                        )));
                    }
                } catch (Throwable $e) {}

                try {
                    $pdo->prepare("UPDATE tour_bookings SET portal_last_login = NOW() WHERE id=? AND org_id=?")
                        ->execute([(int)$booking['id'], $orgId]);
                } catch (Throwable $e) {}

                session_regenerate_id(true);
                $_SESSION['trv_booking_id'] = (int)$booking['id'];
                $_SESSION['trv_bids']       = $bids;
                $_SESSION['trv_org_id']     = $orgId;
                $_SESSION['trv_org_slug']   = $org['slug'];
                $_SESSION['trv_org_name']   = $org['name'];
                $_SESSION['trv_org_logo']   = $org['logo'] ?? '';
                $_SESSION['trv_name']       = $booking['customer_name'] ?: 'Traveller';
                $_SESSION['trv_last_act']   = time();

                setFlash('success', 'Welcome back, ' . e(explode(' ', (string)$booking['customer_name'])[0] ?: 'traveller') . '!');
                redirect(APP_URL . '/traveller/index.php');
            }
        }
    }
}

$initials = strtoupper(implode('', array_map(
    fn($w) => substr($w, 0, 1),
    array_slice(explode(' ', trim($operatorName)), 0, 2)
)));
$location = implode(', ', array_filter([$org['city'] ?? null, $org['country'] ?? null]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Traveller Sign In — <?= e($operatorName) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
:root { --trv-blue: #2980b9; --trv-navy: #0B2D4E; }
body {
  min-height: 100vh; margin: 0;
  font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  background: linear-gradient(135deg, var(--trv-navy) 0%, var(--trv-blue) 100%);
  display: flex; align-items: center; justify-content: center; padding: 24px 16px;
}
.card-wrap { width: 100%; max-width: 420px; }
.login-card { background: #fff; border-radius: 16px; box-shadow: 0 12px 40px rgba(0,0,0,.22); overflow: hidden; }
.login-head { padding: 28px 30px 22px; text-align: center; border-bottom: 1px solid #f1f3f5; }
.logo {
  width: 62px; height: 62px; border-radius: 14px; margin: 0 auto 12px;
  background: var(--trv-blue); color: #fff; object-fit: cover;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; font-weight: 700; letter-spacing: .5px;
}
.op-name { font-size: 1.05rem; font-weight: 700; color: var(--trv-navy); }
.op-meta { font-size: .78rem; color: #6c757d; margin-top: 2px; }
.portal-tag {
  display: inline-block; margin-top: 10px; padding: 3px 12px; border-radius: 999px;
  background: #eff6fb; color: var(--trv-blue); font-size: .7rem;
  font-weight: 700; text-transform: uppercase; letter-spacing: .6px;
}
.login-body { padding: 26px 30px 30px; }
.form-label { font-size: .8rem; font-weight: 600; color: #334155; }
.form-control { padding: .65rem .85rem; }
.form-control:focus { border-color: var(--trv-blue); box-shadow: 0 0 0 .2rem rgba(41,128,185,.15); }
.pin-input { letter-spacing: 6px; font-size: 1.1rem; text-align: center; }
.btn-signin {
  width: 100%; padding: .7rem; border: 0; border-radius: 8px;
  background: var(--trv-blue); color: #fff; font-weight: 600;
}
.btn-signin:hover { background: #1f6391; }
.help { font-size: .74rem; color: #64748b; text-align: center; margin-top: 18px; line-height: 1.7; }
.help a { color: var(--trv-blue); text-decoration: none; }
</style>
</head>
<body>

<div class="card-wrap">
  <div class="login-card">
    <div class="login-head">
      <?php if (!empty($org['logo'])): ?>
        <img src="<?= e($org['logo']) ?>" alt="" class="logo">
      <?php else: ?>
        <div class="logo"><?= e($initials) ?></div>
      <?php endif; ?>
      <div class="op-name"><?= e($operatorName) ?></div>
      <?php if ($tagline): ?><div class="op-meta"><?= e($tagline) ?></div>
      <?php elseif ($location): ?><div class="op-meta"><?= e($location) ?></div><?php endif; ?>
      <div class="portal-tag"><i class="fas fa-plane me-1"></i>Traveller Portal</div>
    </div>

    <div class="login-body">
      <?php if ($notice): ?>
      <div class="alert alert-<?= $notice[0] ?> py-2 small d-flex align-items-center">
        <i class="fas <?= $notice[1] ?> me-2"></i><?= e($notice[2]) ?>
      </div>
      <?php endif; ?>

      <?php if (!$orgActive): ?>
        <div class="alert alert-secondary small mb-0">
          <i class="fas fa-circle-info me-1"></i>This operator's account is not active. Please contact them directly.
        </div>
      <?php elseif (!$portalOn): ?>
        <div class="alert alert-secondary small mb-0">
          <i class="fas fa-circle-info me-1"></i>
          <?= e($operatorName) ?> has not opened the traveller portal yet. Please contact them for your trip details.
        </div>
      <?php else: ?>

        <?php if ($welcome): ?>
        <p class="text-muted small mb-3"><?= e($welcome) ?></p>
        <?php endif; ?>

        <?php if ($loginError): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center">
          <i class="fas fa-circle-exclamation me-2"></i><?= e($loginError) ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

          <div class="mb-3">
            <label class="form-label">Booking Reference</label>
            <input type="text" name="booking_no" class="form-control text-uppercase" required
                   value="<?= e($refInput) ?>" placeholder="e.g. BK-4F2A9C1D" autofocus>
          </div>

          <div class="mb-4">
            <label class="form-label">Your PIN</label>
            <input type="password" name="pin" class="form-control pin-input" required
                   inputmode="numeric" pattern="\d{4,8}" maxlength="8" placeholder="••••••">
          </div>

          <button type="submit" class="btn-signin">
            <i class="fas fa-right-to-bracket me-1"></i>Sign In
          </button>
        </form>

        <div class="help">
          Your booking reference is on your confirmation and invoice.<br>
          Lost your PIN? Contact
          <?php if ($contactPhone): ?><a href="tel:<?= e($contactPhone) ?>"><?= e($contactPhone) ?></a><?php endif; ?>
          <?php if ($contactPhone && $contactEmail): ?> or <?php endif; ?>
          <?php if ($contactEmail): ?><a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a><?php endif; ?>
          <?php if (!$contactPhone && !$contactEmail): ?>your tour operator<?php endif; ?>.
        </div>

      <?php endif; ?>
    </div>
  </div>
</div>

</body>
</html>
