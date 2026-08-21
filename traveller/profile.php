<?php
/**
 * Traveller Portal — contact details, trip requests and PIN change
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// ── POST is handled before any output so redirects work ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bid   = (int)($_SESSION['trv_booking_id'] ?? 0);
    $orgId = (int)($_SESSION['trv_org_id'] ?? 0);
    if ($bid <= 0 || $orgId <= 0) {
        redirect(APP_URL . '/traveller/login.php');
    }
    if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed. Please try again.');
        redirect(APP_URL . '/traveller/profile.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_contact') {
        $phone    = sanitize($_POST['customer_phone'] ?? '');
        $email    = sanitize($_POST['customer_email'] ?? '');
        $requests = sanitize($_POST['special_requests'] ?? '');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('danger', 'That email address does not look right.');
            redirect(APP_URL . '/traveller/profile.php');
        }

        try {
            $pdo->prepare("
                UPDATE tour_bookings
                SET customer_phone = ?, customer_email = ?, special_requests = ?, updated_at = NOW()
                WHERE id = ? AND org_id = ? AND portal_enabled = 1
            ")->execute([$phone, $email, $requests, $bid, $orgId]);
            setFlash('success', 'Your details have been updated. Your operator can see the change.');
        } catch (Throwable $e) {
            setFlash('danger', 'We could not save that just now. Please try again.');
        }
        redirect(APP_URL . '/traveller/profile.php');
    }

    if ($action === 'change_pin') {
        $current = (string)($_POST['current_pin'] ?? '');
        $new     = (string)($_POST['new_pin'] ?? '');
        $confirm = (string)($_POST['confirm_pin'] ?? '');

        $stmt = $pdo->prepare("SELECT portal_pin FROM tour_bookings WHERE id=? AND org_id=? AND portal_enabled=1 LIMIT 1");
        $stmt->execute([$bid, $orgId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($current, (string)$hash)) {
            setFlash('danger', 'Your current PIN is not correct.');
        } elseif (!ctype_digit($new) || strlen($new) < 4 || strlen($new) > 8) {
            setFlash('danger', 'Your new PIN must be 4 – 8 digits.');
        } elseif ($new !== $confirm) {
            setFlash('danger', 'The two new PINs do not match.');
        } else {
            $pdo->prepare("UPDATE tour_bookings SET portal_pin=? WHERE id=? AND org_id=?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $bid, $orgId]);
            setFlash('success', 'Your PIN has been changed.');
        }
        redirect(APP_URL . '/traveller/profile.php');
    }
}

$pageTitle = 'My Details';
require_once __DIR__ . '/../includes/header-traveller.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>

<div class="row g-3">
  <!-- ── Contact details ────────────────────────────────────── -->
  <div class="col-lg-7">
    <div class="trv-card">
      <h6 class="fw-bold mb-1"><i class="fas fa-address-card me-2" style="color:var(--trv-blue)"></i>Contact Details</h6>
      <p class="text-muted small mb-3">
        Keep these current so <?= e($operatorName) ?> can reach you before and during your trip.
      </p>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="update_contact">

        <div class="mb-3">
          <label class="form-label small fw-semibold">Full Name</label>
          <input type="text" class="form-control bg-light" value="<?= e($trip['customer_name']) ?>" readonly>
          <div class="form-text">Names on a booking can only be changed by your operator.</div>
        </div>

        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label small fw-semibold">Phone</label>
            <input type="text" name="customer_phone" class="form-control" value="<?= e($trip['customer_phone']) ?>" placeholder="e.g. +254 712 345678">
          </div>
          <div class="col-sm-6">
            <label class="form-label small fw-semibold">Email</label>
            <input type="email" name="customer_email" class="form-control" value="<?= e($trip['customer_email']) ?>">
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label small fw-semibold">Special Requests</label>
          <textarea name="special_requests" class="form-control" rows="3"
                    placeholder="Dietary needs, mobility support, room preferences, celebrations…"><?= e($trip['special_requests']) ?></textarea>
          <div class="form-text">Your operator sees these against booking <?= e($trip['booking_no']) ?>.</div>
        </div>

        <div class="text-end mt-3">
          <button type="submit" class="btn text-white" style="background:var(--trv-blue)">
            <i class="fas fa-save me-1"></i>Save Details
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Security + booking summary ─────────────────────────── -->
  <div class="col-lg-5">
    <div class="trv-card mb-3">
      <h6 class="fw-bold mb-1"><i class="fas fa-key me-2" style="color:var(--trv-blue)"></i>Change Your PIN</h6>
      <p class="text-muted small mb-3">You sign in with your booking reference and this PIN.</p>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="change_pin">

        <div class="mb-3">
          <label class="form-label small fw-semibold">Current PIN</label>
          <input type="password" name="current_pin" class="form-control" required inputmode="numeric" maxlength="8">
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">New PIN</label>
          <input type="password" name="new_pin" class="form-control" required inputmode="numeric"
                 pattern="\d{4,8}" maxlength="8" placeholder="4 – 8 digits">
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Confirm New PIN</label>
          <input type="password" name="confirm_pin" class="form-control" required inputmode="numeric" maxlength="8">
        </div>

        <div class="text-end">
          <button type="submit" class="btn btn-outline-primary">
            <i class="fas fa-shield-halved me-1"></i>Update PIN
          </button>
        </div>
      </form>
    </div>

    <div class="trv-card">
      <h6 class="fw-bold mb-3"><i class="fas fa-circle-info me-2" style="color:var(--trv-blue)"></i>Booking Summary</h6>
      <table class="table table-sm mb-0 small">
        <tr><td class="text-muted">Reference</td><td class="text-end fw-semibold"><?= e($trip['booking_no']) ?></td></tr>
        <tr><td class="text-muted">Package</td><td class="text-end fw-semibold"><?= e($trip['package_name'] ?: '—') ?></td></tr>
        <tr><td class="text-muted">Travel date</td><td class="text-end fw-semibold"><?= formatDate($trip['travel_date']) ?></td></tr>
        <tr><td class="text-muted">Travellers</td><td class="text-end fw-semibold"><?= (int)$trip['adults'] + (int)$trip['children'] ?></td></tr>
        <tr><td class="text-muted">Status</td><td class="text-end"><?= statusBadge($trip['status']) ?></td></tr>
        <tr><td class="text-muted">Balance</td>
            <td class="text-end fw-bold <?= $tripBalance > 0 ? 'text-danger' : 'text-success' ?>">
              <?= $tripBalance > 0 ? formatCurrency($tripBalance) : 'Settled' ?>
            </td></tr>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer-traveller.php'; ?>
