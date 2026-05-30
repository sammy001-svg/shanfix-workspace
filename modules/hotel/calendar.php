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
    ['url' => 'calendar.php',     'icon' => 'fas fa-calendar-alt',   'label' => 'Availability'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

// ── POST handler: Quick Book ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];

    $action = $_POST['action'] ?? '';

    if ($action === 'quick_book') {
        $roomId   = (int)($_POST['room_id'] ?? 0);
        $guestId  = (int)($_POST['guest_id'] ?? 0);
        $checkIn  = sanitize($_POST['check_in'] ?? '');
        $checkOut = sanitize($_POST['check_out'] ?? '');
        $adults   = max(1, (int)($_POST['adults'] ?? 1));
        $source   = in_array($_POST['booking_source'] ?? '', ['walk-in','phone','online','agent'])
                    ? $_POST['booking_source'] : 'walk-in';
        $notes    = sanitize($_POST['notes'] ?? '');

        $errors = [];
        if (!$roomId)  $errors[] = 'Room is required.';
        if (!$guestId) $errors[] = 'Guest is required.';
        if (!$checkIn || !$checkOut) $errors[] = 'Check-in and check-out dates are required.';
        if ($checkIn && $checkOut && $checkOut <= $checkIn) $errors[] = 'Check-out must be after check-in.';

        if (empty($errors)) {
            try {
                // Get room price
                $rStmt = $pdo->prepare("SELECT r.room_no, rt.price_per_night FROM hotel_rooms r LEFT JOIN hotel_room_types rt ON rt.id=r.type_id WHERE r.id=? AND r.org_id=?");
                $rStmt->execute([$roomId, $orgId]);
                $roomRow = $rStmt->fetch();
                $nights  = max(1, (int)((strtotime($checkOut) - strtotime($checkIn)) / 86400));
                $total   = $roomRow ? (float)$roomRow['price_per_night'] * $nights : 0.00;

                $bkNo = 'BK-' . strtoupper(substr(uniqid(), -6));
                $stmt = $pdo->prepare("INSERT INTO hotel_bookings
                    (org_id, booking_no, room_id, guest_id, check_in, check_out, adults, total_amount, booking_source, notes, status, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,'confirmed',NOW())");
                $stmt->execute([$orgId, $bkNo, $roomId, $guestId, $checkIn, $checkOut, $adults, $total, $source, $notes]);

                // Mark room as reserved
                $pdo->prepare("UPDATE hotel_rooms SET status='reserved' WHERE id=? AND org_id=?")->execute([$roomId, $orgId]);
                logActivity('create', 'hotel', "Quick book: room #{$roomRow['room_no']} — $checkIn to $checkOut (Booking $bkNo)");
                setFlash('success', "Booking $bkNo created successfully for " . $nights . " night(s).");
            } catch (Exception $e) {
                setFlash('danger', 'Booking failed: ' . $e->getMessage());
            }
        } else {
            setFlash('danger', implode(' ', $errors));
        }
        redirect('calendar.php?' . http_build_query(['month' => date('m', strtotime($checkIn)), 'year' => date('Y', strtotime($checkIn))]));
    }

    redirect('calendar.php');
}

// ── Page setup ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Month/year navigation
$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));
// Clamp
if ($month < 1 || $month > 12) { $month = (int)date('m'); }
if ($year < 2000 || $year > 2099) { $year = (int)date('Y'); }

$daysInMonth  = (int)date('t', mktime(0,0,0,$month,1,$year));
$monthLabel   = date('F Y', mktime(0,0,0,$month,1,$year));

// Prev/next month
$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1)  { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1;  $nextYear++; }

// Fetch rooms
$rooms = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.id, r.room_no, r.floor, r.status AS room_status,
               rt.name AS type_name, rt.price_per_night
        FROM hotel_rooms r
        LEFT JOIN hotel_room_types rt ON rt.id = r.type_id
        WHERE r.org_id = ?
        ORDER BY r.floor ASC, r.room_no ASC
    ");
    $stmt->execute([$orgId]);
    $rooms = $stmt->fetchAll();
} catch (Exception $e) {}

// Fetch all bookings for this month (check_in < last_day+1 AND check_out > first_day)
$firstDay = sprintf('%04d-%02d-01', $year, $month);
$lastDay  = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
$bookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.room_id, b.check_in, b.check_out, b.status,
               g.first_name, g.last_name
        FROM hotel_bookings b
        LEFT JOIN hotel_guests g ON g.id = b.guest_id
        WHERE b.org_id = ?
          AND b.check_in  < ?
          AND b.check_out > ?
          AND b.status NOT IN ('cancelled','no_show')
        ORDER BY b.check_in
    ");
    $stmt->execute([$orgId, date('Y-m-d', strtotime($lastDay . ' +1 day')), $firstDay]);
    $bookings = $stmt->fetchAll();
} catch (Exception $e) {}

// Build lookup: [room_id][YYYY-MM-DD] => 'check_in'|'check_out'|'booked'
$calMap = [];
foreach ($bookings as $bk) {
    $ci = $bk['check_in'];
    $co = $bk['check_out'];  // exclusive end
    $guestName = trim(($bk['first_name'] ?? '') . ' ' . ($bk['last_name'] ?? ''));
    $cur = strtotime($ci);
    $end = strtotime($co);
    while ($cur < $end) {
        $d = date('Y-m-d', $cur);
        // Only fill cells within our month
        $dm = (int)date('m', $cur);
        $dy = (int)date('Y', $cur);
        if ($dm === $month && $dy === $year) {
            $rid = (int)$bk['room_id'];
            if ($d === $ci && $d === date('Y-m-d', strtotime($co . ' -1 day'))) {
                $calMap[$rid][$d] = ['type' => 'booked', 'guest' => $guestName];
            } elseif ($d === $ci) {
                $calMap[$rid][$d] = ['type' => 'check_in', 'guest' => $guestName];
            } elseif ($d === date('Y-m-d', strtotime($co . ' -1 day'))) {
                $calMap[$rid][$d] = ['type' => 'check_out', 'guest' => $guestName];
            } else {
                $calMap[$rid][$d] = ['type' => 'booked', 'guest' => $guestName];
            }
        }
        $cur = strtotime('+1 day', $cur);
    }
}

// KPI: today's stats
$today = date('Y-m-d');
$kpiAvailable = $kpiOccupied = $kpiCheckIn = $kpiCheckOut = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM hotel_rooms WHERE org_id=? AND status='available'");
    $s->execute([$orgId]); $kpiAvailable = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM hotel_rooms WHERE org_id=? AND status='occupied'");
    $s->execute([$orgId]); $kpiOccupied = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM hotel_bookings WHERE org_id=? AND check_in=? AND status NOT IN ('cancelled','no_show')");
    $s->execute([$orgId, $today]); $kpiCheckIn = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM hotel_bookings WHERE org_id=? AND check_out=? AND status NOT IN ('cancelled','no_show')");
    $s->execute([$orgId, $today]); $kpiCheckOut = (int)$s->fetchColumn();
} catch (Exception $e) {}

// Guests for quick-book modal
$guests = [];
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, phone FROM hotel_guests WHERE org_id=? ORDER BY first_name, last_name");
    $stmt->execute([$orgId]);
    $guests = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-alt me-2" style="color:<?= $moduleColor ?>"></i>Room Availability Calendar</h4>
    <p class="text-muted mb-0">Visual overview of room bookings — click any cell to quick-book</p>
  </div>
  <!-- Month navigation -->
  <div class="d-flex align-items-center gap-2">
    <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-chevron-left"></i></a>
    <span class="fw-bold text-dark" style="min-width:140px;text-align:center"><?= $monthLabel ?></span>
    <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-chevron-right"></i></a>
    <a href="?month=<?= date('m') ?>&year=<?= date('Y') ?>" class="btn btn-sm text-white ms-1" style="background:<?= $moduleColor ?>">Today</a>
  </div>
</div>

<?= flashAlert() ?>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm text-center py-3">
      <div class="fw-bold fs-3 text-success"><?= $kpiAvailable ?></div>
      <div class="small text-muted"><i class="fas fa-check-circle me-1 text-success"></i>Available Today</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm text-center py-3">
      <div class="fw-bold fs-3 text-danger"><?= $kpiOccupied ?></div>
      <div class="small text-muted"><i class="fas fa-times-circle me-1 text-danger"></i>Occupied Today</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm text-center py-3">
      <div class="fw-bold fs-3 text-primary"><?= $kpiCheckIn ?></div>
      <div class="small text-muted"><i class="fas fa-sign-in-alt me-1 text-primary"></i>Check-ins Today</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm text-center py-3">
      <div class="fw-bold fs-3 text-warning"><?= $kpiCheckOut ?></div>
      <div class="small text-muted"><i class="fas fa-sign-out-alt me-1 text-warning"></i>Check-outs Today</div>
    </div>
  </div>
</div>

<?php if (empty($rooms)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
  <i class="fas fa-door-open fa-3x mb-3 d-block opacity-25"></i>
  No rooms found. <a href="rooms.php">Add rooms</a> to view the calendar.
</div></div>
<?php else: ?>

<!-- Calendar Grid -->
<div class="card shadow-sm border-0">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-bordered mb-0" id="calendarTable" style="min-width:900px">
        <thead>
          <tr style="background:#f8f9fa">
            <th class="fw-bold text-dark" style="min-width:130px;position:sticky;left:0;background:#f8f9fa;z-index:2">Room</th>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
              $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
              $dow = date('D', strtotime($dateStr));
              $isToday = ($dateStr === date('Y-m-d'));
            ?>
            <th class="text-center fw-semibold <?= $isToday ? 'table-warning' : '' ?>" style="min-width:36px;padding:4px 2px;font-size:.72rem">
              <div><?= $d ?></div>
              <div class="text-muted" style="font-size:.65rem"><?= $dow ?></div>
            </th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rooms as $room):
            $rid = (int)$room['id'];
          ?>
          <tr>
            <td style="position:sticky;left:0;background:#fff;z-index:1;border-right:2px solid #dee2e6">
              <div class="fw-semibold text-dark" style="font-size:.82rem"><?= e($room['room_no']) ?></div>
              <div class="text-muted" style="font-size:.7rem"><?= e($room['type_name'] ?? 'Standard') ?></div>
              <?php if ($room['floor']): ?><div class="text-muted" style="font-size:.65rem">Fl. <?= e($room['floor']) ?></div><?php endif; ?>
            </td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
              $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
              $cell    = $calMap[$rid][$dateStr] ?? null;
              $isToday = ($dateStr === date('Y-m-d'));

              if ($cell) {
                  $type = $cell['type'];
                  if ($type === 'check_in') {
                      $bg = '#d1e7ff'; $border = '#0d6efd'; $icon = 'fas fa-sign-in-alt'; $title = 'Check-in: ' . $cell['guest'];
                  } elseif ($type === 'check_out') {
                      $bg = '#fff3cd'; $border = '#ffc107'; $icon = 'fas fa-sign-out-alt'; $title = 'Check-out: ' . $cell['guest'];
                  } else {
                      $bg = '#f8d7da'; $border = '#dc3545'; $icon = 'fas fa-ban'; $title = 'Booked: ' . $cell['guest'];
                  }
                  $clickable = false;
              } else {
                  $bg = '#d1e7dd'; $border = '#198754'; $icon = ''; $title = 'Available — click to book';
                  $clickable = true;
              }
            ?>
            <td class="text-center p-0 <?= $isToday ? 'table-warning' : '' ?>"
                style="background:<?= $bg ?>;border-left:3px solid <?= $border ?>;cursor:<?= $clickable ? 'pointer' : 'default' ?>;vertical-align:middle"
                <?php if ($clickable): ?>
                onclick="openQuickBook(<?= $rid ?>, '<?= e($room['room_no']) ?>', '<?= $dateStr ?>')"
                <?php endif; ?>
                title="<?= e($title) ?>">
              <?php if ($icon): ?>
              <i class="<?= $icon ?>" style="font-size:.65rem;color:<?= $border ?>"></i>
              <?php else: ?>
              <span style="font-size:.6rem;color:#198754">&#10003;</span>
              <?php endif; ?>
            </td>
            <?php endfor; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Legend -->
<div class="d-flex flex-wrap gap-3 mt-3 align-items-center">
  <span class="fw-semibold small text-muted">Legend:</span>
  <span class="badge" style="background:#d1e7dd;color:#198754;border:1px solid #198754;font-size:.78rem"><i class="fas fa-check me-1"></i>Available</span>
  <span class="badge" style="background:#f8d7da;color:#dc3545;border:1px solid #dc3545;font-size:.78rem"><i class="fas fa-ban me-1"></i>Booked</span>
  <span class="badge" style="background:#d1e7ff;color:#0d6efd;border:1px solid #0d6efd;font-size:.78rem"><i class="fas fa-sign-in-alt me-1"></i>Check-in Day</span>
  <span class="badge" style="background:#fff3cd;color:#664d03;border:1px solid #ffc107;font-size:.78rem"><i class="fas fa-sign-out-alt me-1"></i>Check-out Day</span>
  <span class="badge" style="background:#fff3cd;color:#664d03;border:1px solid #ffc107;font-size:.78rem"><i class="fas fa-star me-1"></i>Today</span>
</div>

<?php endif; ?>

<!-- Quick Book Modal -->
<div class="modal fade" id="quickBookModal" tabindex="-1">
  <div class="modal-dialog modal-md">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Quick Book</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-loading>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="quick_book">
        <input type="hidden" name="room_id" id="qbRoomId">
        <div class="modal-body text-dark">
          <div class="alert alert-info py-2 mb-3" id="qbRoomInfo"></div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Guest <span class="text-danger">*</span></label>
              <select name="guest_id" id="qbGuestId" class="form-select" required>
                <option value="">— Select guest —</option>
                <?php foreach ($guests as $g): ?>
                <option value="<?= $g['id'] ?>"><?= e($g['first_name'] . ' ' . $g['last_name']) ?><?= $g['phone'] ? ' (' . e($g['phone']) . ')' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Check-in Date <span class="text-danger">*</span></label>
              <input type="date" name="check_in" id="qbCheckIn" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Check-out Date <span class="text-danger">*</span></label>
              <input type="date" name="check_out" id="qbCheckOut" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Adults</label>
              <input type="number" name="adults" id="qbAdults" class="form-control" value="1" min="1" max="10">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Booking Source</label>
              <select name="booking_source" class="form-select">
                <option value="walk-in">Walk-in</option>
                <option value="phone">Phone</option>
                <option value="online">Online</option>
                <option value="agent">Agent</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Any special requests or notes…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>">
            <i class="fas fa-calendar-plus me-2"></i>Confirm Booking
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function openQuickBook(roomId, roomNo, dateStr) {
    document.getElementById('qbRoomId').value  = roomId;
    document.getElementById('qbCheckIn').value = dateStr;
    // Default checkout = next day
    var ci = new Date(dateStr);
    ci.setDate(ci.getDate() + 1);
    var co = ci.toISOString().split('T')[0];
    document.getElementById('qbCheckOut').value = co;
    document.getElementById('qbRoomInfo').innerHTML =
        '<i class="fas fa-door-open me-2"></i><strong>Room ' + roomNo + '</strong> — ' + dateStr;
    document.getElementById('qbGuestId').value = '';
    document.getElementById('qbAdults').value  = '1';
    new bootstrap.Modal(document.getElementById('quickBookModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
