<?php
$moduleSlug  = 'hotel';
$moduleName  = 'Hotel Management';
$moduleIcon  = 'fas fa-hotel';
$moduleColor = '#d35400';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'room-types.php',   'icon' => 'fas fa-bed',            'label' => 'Room Types'],
    ['url' => 'rooms.php',        'icon' => 'fas fa-door-open',      'label' => 'Rooms'],
    ['url' => 'guests.php',       'icon' => 'fas fa-user-tie',       'label' => 'Guests'],
    ['url' => 'bookings.php',     'icon' => 'fas fa-calendar-check', 'label' => 'Bookings'],
    ['url' => 'checkin.php',      'icon' => 'fas fa-sign-in-alt',    'label' => 'Check-In/Out'],
    ['url' => 'housekeeping.php', 'icon' => 'fas fa-broom',          'label' => 'Housekeeping'],
    ['url' => 'restaurant.php',   'icon' => 'fas fa-utensils',       'label' => 'Restaurant'],
    ['url' => 'invoices.php',     'icon' => 'fas fa-file-invoice',   'label' => 'Invoices'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

// ── POST handling ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];

    $action = $_POST['action'] ?? '';

    if ($action === 'checkin') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("SELECT * FROM hotel_bookings WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $b = $stmt->fetch();
        if ($b) {
            $pdo->prepare("UPDATE hotel_bookings SET status='checked_in' WHERE id=?")->execute([$id]);
            $pdo->prepare("UPDATE hotel_rooms SET status='occupied' WHERE id=?")->execute([$b['room_id']]);
            logActivity('update', 'hotel', "Checked in booking: {$b['booking_no']}");
            setFlash('success', "Guest checked in successfully.");
        }
        redirect('checkin.php');
    }

    if ($action === 'checkout') {
        $id = (int)$_POST['id'];
        $payment = (float)($_POST['payment_amount'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM hotel_bookings WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $b = $stmt->fetch();
        if ($b) {
            $newPaid = (float)$b['paid_amount'] + $payment;
            $pdo->prepare("UPDATE hotel_bookings SET status='checked_out', paid_amount=? WHERE id=?")->execute([$newPaid, $id]);
            $pdo->prepare("UPDATE hotel_rooms SET status='available' WHERE id=?")->execute([$b['room_id']]);
            
            // Log payment inside acc_transactions if available
            try {
                $stmtAcc = $pdo->prepare("INSERT INTO acc_transactions (org_id, date, type, category, description, amount, status) VALUES (?, CURDATE(), 'income', 'Hotel Booking Checkout', ?, ?, 'cleared')");
                $stmtAcc->execute([$orgId, "Room payment checkout for " . $b['booking_no'], $payment]);
            } catch(Exception $e){}

            logActivity('update', 'hotel', "Checked out booking: {$b['booking_no']}, paid KES {$payment}");
            setFlash('success', "Guest checked out successfully. Total payment recorded: KES " . number_format($newPaid, 2));
        }
        redirect('checkin.php');
    }

    redirect('checkin.php');
}

// ── Page setup ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Today's arrivals
$arrivals = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, 
               CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
               r.room_no
        FROM hotel_bookings b
        LEFT JOIN hotel_guests g ON g.id = b.guest_id
        LEFT JOIN hotel_rooms r ON r.id = b.room_id
        WHERE b.org_id = ? AND b.status = 'confirmed' AND DATE(b.check_in) = CURDATE()
    ");
    $stmt->execute([$orgId]);
    $arrivals = $stmt->fetchAll();
} catch (Exception $e) {}

// Currently Checked-In
$inHouse = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, 
               CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
               r.room_no
        FROM hotel_bookings b
        LEFT JOIN hotel_guests g ON g.id = b.guest_id
        LEFT JOIN hotel_rooms r ON r.id = b.room_id
        WHERE b.org_id = ? AND b.status = 'checked_in'
        ORDER BY r.room_no ASC
    ");
    $stmt->execute([$orgId]);
    $inHouse = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-sign-in-alt me-2" style="color:<?= $moduleColor ?>"></i>Check-In / Check-Out</h4>
    <p class="text-muted mb-0">Handle front desk arrivals, departures, and room allocations</p>
  </div>
</div>

<?= flashAlert() ?>

<div class="row g-4">
  <!-- Arrivals Today -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header bg-transparent"><h6 class="mb-0 text-dark fw-bold"><i class="fas fa-plane-arrival text-primary me-2"></i>Arrivals Today (Confirmed)</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Guest</th><th>Room</th><th>Stay</th><th class="text-end">Action</th></tr>
            </thead>
            <tbody>
              <?php if (empty($arrivals)): ?>
              <tr><td colspan="4" class="text-center py-4 text-muted"><i class="fas fa-bell-slash fa-2x mb-2 d-block"></i>No arrivals scheduled for today</td></tr>
              <?php else: foreach ($arrivals as $a): ?>
              <tr>
                <td class="fw-bold text-dark"><?= e($a['guest_name']) ?></td>
                <td><span class="badge bg-light text-dark border"><?= e($a['room_no']) ?></span></td>
                <td><?= (int)$a['nights'] ?> night(s)</td>
                <td class="text-end">
                  <form method="post" class="mb-0">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="checkin">
                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success text-white"><i class="fas fa-sign-in-alt me-1"></i>Check-In</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- In House / Checked-In Guests -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header bg-transparent"><h6 class="mb-0 text-dark fw-bold"><i class="fas fa-key text-success me-2"></i>In-House Guests (Checked-In)</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Room</th><th>Guest</th><th>Departure</th><th class="text-end">Action</th></tr>
            </thead>
            <tbody>
              <?php if (empty($inHouse)): ?>
              <tr><td colspan="4" class="text-center py-4 text-muted"><i class="fas fa-door-closed fa-2x mb-2 d-block"></i>No guests currently checked in</td></tr>
              <?php else: foreach ($inHouse as $i):
                $bal = (float)$i['total_amount'] - (float)$i['paid_amount'];
              ?>
              <tr>
                <td class="fw-bold text-dark"><span class="badge bg-dark"><?= e($i['room_no']) ?></span></td>
                <td class="fw-bold text-dark"><?= e($i['guest_name']) ?></td>
                <td><?= formatDate($i['check_out']) ?></td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-info text-white" 
                          onclick="showCheckoutModal(<?= $i['id'] ?>, '<?= e($i['booking_no']) ?>', '<?= e($i['guest_name']) ?>', '<?= e($i['room_no']) ?>', <?= $i['total_amount'] ?>, <?= $i['paid_amount'] ?>)">
                    <i class="fas fa-sign-out-alt me-1"></i>Check-Out
                  </button>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Checkout Payment Modal -->
<div class="modal fade" id="checkoutModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title"><i class="fas fa-sign-out-alt me-2"></i>Guest Checkout & Settlement</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="checkout">
        <input type="hidden" name="id" id="checkoutBookingId">
        <div class="modal-body text-dark">
          <div class="mb-3">
            <h6 class="fw-bold mb-1">Booking: <span id="checkoutBookingNo" class="text-info"></span></h6>
            <p class="text-muted mb-0">Guest: <span id="checkoutGuestName" class="fw-semibold"></span> | Room: <span id="checkoutRoomNo" class="fw-semibold"></span></p>
          </div>
          
          <hr>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label text-muted small">Total Billing</label>
              <div class="fw-bold fs-5 text-dark" id="checkoutTotal">KES 0.00</div>
            </div>
            <div class="col-6">
              <label class="form-label text-muted small">Amount Already Paid</label>
              <div class="fw-bold fs-5 text-success" id="checkoutPaid">KES 0.00</div>
            </div>
          </div>

          <div class="p-3 bg-light rounded mb-3 d-flex justify-content-between align-items-center">
            <div class="fw-semibold text-muted">Outstanding Balance:</div>
            <div class="fw-bold fs-4 text-danger" id="checkoutBalance">KES 0.00</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Receive Settlement Payment (KES) <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text">KES</span>
              <input type="number" name="payment_amount" id="checkoutPaymentAmount" class="form-control form-control-lg fw-bold text-success" min="0" step="0.01" required>
            </div>
            <div class="form-text">Input 0.00 if already fully paid or settled externally.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-info text-white"><i class="fas fa-check-double me-2"></i>Complete Checkout</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function showCheckoutModal(id, bookingNo, guestName, roomNo, total, paid) {
  var balance = Math.max(0, total - paid);
  document.getElementById("checkoutBookingId").value = id;
  document.getElementById("checkoutBookingNo").textContent = bookingNo;
  document.getElementById("checkoutGuestName").textContent = guestName;
  document.getElementById("checkoutRoomNo").textContent = roomNo;
  document.getElementById("checkoutTotal").textContent = "KES " + total.toLocaleString(undefined, {minimumFractionDigits:2});
  document.getElementById("checkoutPaid").textContent = "KES " + paid.toLocaleString(undefined, {minimumFractionDigits:2});
  document.getElementById("checkoutBalance").textContent = "KES " + balance.toLocaleString(undefined, {minimumFractionDigits:2});
  document.getElementById("checkoutPaymentAmount").value = balance.toFixed(2);
  
  new bootstrap.Modal(document.getElementById("checkoutModal")).show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
