<?php
$moduleSlug  = 'hotel';
$moduleName  = 'Hotel Management';
$moduleIcon  = 'fas fa-hotel';
$moduleColor = '#d35400';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'room-types.php', 'icon' => 'fas fa-bed',             'label' => 'Room Types'],
    ['url' => 'rooms.php',      'icon' => 'fas fa-door-open',       'label' => 'Rooms'],
    ['url' => 'guests.php',     'icon' => 'fas fa-user-tie',        'label' => 'Guests'],
    ['url' => 'bookings.php',   'icon' => 'fas fa-calendar-check',  'label' => 'Bookings'],
    ['url' => 'checkin.php',    'icon' => 'fas fa-sign-in-alt',     'label' => 'Check-In/Out'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
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

    if ($action === 'add' || $action === 'edit') {
        $guestId        = (int)($_POST['guest_id'] ?? 0);
        $roomId         = (int)($_POST['room_id'] ?? 0);
        $checkIn        = sanitize($_POST['check_in'] ?? '');
        $checkOut       = sanitize($_POST['check_out'] ?? '');
        $adults         = max(1, (int)($_POST['adults'] ?? 1));
        $children       = max(0, (int)($_POST['children'] ?? 0));
        $ratePerNight   = (float)($_POST['rate_per_night'] ?? 0);
        $paidAmount     = (float)($_POST['paid_amount'] ?? 0);
        $extraCharges   = (float)($_POST['extra_charges'] ?? 0);
        $specialRequests= sanitize($_POST['special_requests'] ?? '');
        $status         = in_array($_POST['status'] ?? '', ['confirmed','checked_in','checked_out','cancelled','no_show']) ? $_POST['status'] : 'confirmed';

        if (!$guestId || !$roomId || !$checkIn || !$checkOut) {
            setFlash('danger', 'Guest, Room, Check-In date and Check-Out date are required.');
            redirect('bookings.php');
        }

        // Calculate nights
        $inTime  = strtotime($checkIn);
        $outTime = strtotime($checkOut);
        $nights  = max(1, round(($outTime - $inTime) / 86400));

        $totalAmount = ($ratePerNight * $nights) + $extraCharges;

        if ($action === 'add') {
            $bookingNo = 'BK-' . strtoupper(substr(uniqid(), 7, 6));
            $stmt = $pdo->prepare("
                INSERT INTO hotel_bookings 
                (org_id, booking_no, guest_id, room_id, check_in, check_out, nights, adults, children, rate_per_night, total_amount, paid_amount, extra_charges, special_requests, status) 
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $bookingNo, $guestId, $roomId, $checkIn, $checkOut, $nights, $adults, $children, $ratePerNight, $totalAmount, $paidAmount, $extraCharges, $specialRequests, $status]);
            
            // If checked in immediately, mark room occupied
            if ($status === 'checked_in') {
                $pdo->prepare("UPDATE hotel_rooms SET status='occupied' WHERE id=? AND org_id=?")->execute([$roomId, $orgId]);
            }
            
            logActivity('create', 'hotel', "Added booking: $bookingNo");
            setFlash('success', "Booking $bookingNo created successfully.");
        } else {
            $id = (int)$_POST['id'];
            
            // Get original booking room to revert status if needed
            $origStmt = $pdo->prepare("SELECT room_id, status FROM hotel_bookings WHERE id=? AND org_id=?");
            $origStmt->execute([$id, $orgId]);
            $orig = $origStmt->fetch();

            $stmt = $pdo->prepare("
                UPDATE hotel_bookings 
                SET guest_id=?, room_id=?, check_in=?, check_out=?, nights=?, adults=?, children=?, rate_per_night=?, total_amount=?, paid_amount=?, extra_charges=?, special_requests=?, status=? 
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$guestId, $roomId, $checkIn, $checkOut, $nights, $adults, $children, $ratePerNight, $totalAmount, $paidAmount, $extraCharges, $specialRequests, $status, $id, $orgId]);

            // Revert original room status if changed
            if ($orig) {
                if ($orig['room_id'] != $roomId) {
                    $pdo->prepare("UPDATE hotel_rooms SET status='available' WHERE id=? AND org_id=?")->execute([$orig['room_id'], $orgId]);
                }
                if ($status === 'checked_in') {
                    $pdo->prepare("UPDATE hotel_rooms SET status='occupied' WHERE id=? AND org_id=?")->execute([$roomId, $orgId]);
                } elseif ($status === 'checked_out' || $status === 'cancelled') {
                    $pdo->prepare("UPDATE hotel_rooms SET status='available' WHERE id=? AND org_id=?")->execute([$roomId, $orgId]);
                }
            }

            logActivity('update', 'hotel', "Updated booking ID: $id");
            setFlash('success', "Booking updated successfully.");
        }
        redirect('bookings.php');
    }

    if ($action === 'status') {
        $id     = (int)$_POST['id'];
        $status = $_POST['status'];

        $stmt = $pdo->prepare("SELECT * FROM hotel_bookings WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $b = $stmt->fetch();

        if ($b) {
            $stmt = $pdo->prepare("UPDATE hotel_bookings SET status=? WHERE id=? AND org_id=?");
            $stmt->execute([$status, $id, $orgId]);

            if ($status === 'checked_in') {
                $pdo->prepare("UPDATE hotel_rooms SET status='occupied' WHERE id=? AND org_id=?")->execute([$b['room_id'], $orgId]);
            } elseif ($status === 'checked_out' || $status === 'cancelled') {
                $pdo->prepare("UPDATE hotel_rooms SET status='available' WHERE id=? AND org_id=?")->execute([$b['room_id'], $orgId]);
            }

            logActivity('update', 'hotel', "Booking status changed to $status for ID: $id");
            setFlash('success', "Booking status changed to " . ucfirst($status));
        }
        redirect('bookings.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("SELECT booking_no, room_id FROM hotel_bookings WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare("DELETE FROM hotel_bookings WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            $pdo->prepare("UPDATE hotel_rooms SET status='available' WHERE id=? AND org_id=?")->execute([$row['room_id'], $orgId]);
            logActivity('delete', 'hotel', "Deleted booking: {$row['booking_no']}");
            setFlash('success', "Booking {$row['booking_no']} deleted.");
        }
        redirect('bookings.php');
    }

    redirect('bookings.php');
}

// ── Page setup ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filter tab
$filterStatus = $_GET['status'] ?? 'all';
$validStatuses = ['all', 'confirmed', 'checked_in', 'checked_out', 'cancelled'];
if (!in_array($filterStatus, $validStatuses)) $filterStatus = 'all';

// Fetch bookings
$bookings = [];
try {
    $where = 'b.org_id = ?';
    $params = [$orgId];
    if ($filterStatus !== 'all') {
        $where .= ' AND b.status = ?';
        $params[] = $filterStatus;
    }
    
    $stmt = $pdo->prepare("
        SELECT b.*, 
               CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
               g.phone AS guest_phone,
               r.room_no,
               rt.name AS room_type_name
        FROM hotel_bookings b
        LEFT JOIN hotel_guests g ON g.id = b.guest_id
        LEFT JOIN hotel_rooms r ON r.id = b.room_id
        LEFT JOIN hotel_room_types rt ON rt.id = r.type_id
        WHERE $where
        ORDER BY b.check_in DESC
    ");
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
} catch (Exception $e) {}

// Guests for dropdown
$guests = [];
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, id_number FROM hotel_guests WHERE org_id=? ORDER BY last_name, first_name");
    $stmt->execute([$orgId]);
    $guests = $stmt->fetchAll();
} catch (Exception $e) {}

// Rooms for dropdown with type/rate mapping
$rooms = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.id, r.room_no, rt.name AS type_name, rt.price_per_night 
        FROM hotel_rooms r
        LEFT JOIN hotel_room_types rt ON rt.id = r.type_id
        WHERE r.org_id=? AND r.status = 'available'
        ORDER BY r.room_no
    ");
    $stmt->execute([$orgId]);
    $rooms = $stmt->fetchAll();
} catch (Exception $e) {}

// Status badges and colors
$statusColors = ['confirmed'=>'#3498db','checked_in'=>'#2ecc71','checked_out'=>'#95a5a6','cancelled'=>'#e74c3c','no_show'=>'#f39c12'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-check me-2" style="color:<?= $moduleColor ?>"></i>Bookings</h4>
    <p class="text-muted mb-0">Manage guest bookings, checks and room assignments</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#bookingModal" onclick="resetForm()">
    <i class="fas fa-plus me-2"></i>New Booking
  </button>
</div>

<?= flashAlert() ?>

<!-- Filter Tabs -->
<ul class="nav nav-pills mb-4 flex-wrap gap-1">
  <?php foreach ($validStatuses as $s):
    $active = $filterStatus === $s ? 'active' : '';
    $color = $s === 'all' ? $moduleColor : ($statusColors[$s] ?? $moduleColor);
  ?>
  <li class="nav-item">
    <a class="nav-link <?= $active ?>" href="?status=<?= $s ?>"
       style="<?= $active ? "background:$color;color:#fff" : "color:$color;border:1px solid $color" ?>">
      <?= $s === 'all' ? 'All Bookings' : ucfirst(str_replace('_', ' ', $s)) ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<div class="card">
  <div class="card-header text-dark fw-bold"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>Booking Registry</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="bookingTable">
        <thead class="table-light">
          <tr>
            <th>Booking #</th>
            <th>Guest</th>
            <th>Room</th>
            <th>Stay Period</th>
            <th>Charges</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bookings as $b): ?>
          <tr>
            <td class="fw-bold text-dark"><?= e($b['booking_no']) ?></td>
            <td>
              <div class="fw-bold text-dark"><?= e($b['guest_name'] ?: '—') ?></div>
              <small class="text-muted"><?= e($b['guest_phone'] ?: '—') ?></small>
            </td>
            <td>
              <div class="fw-bold text-dark"><i class="fas fa-door-closed me-1 text-muted"></i><?= e($b['room_no'] ?: '—') ?></div>
              <small class="text-muted"><?= e($b['room_type_name'] ?: '—') ?></small>
            </td>
            <td>
              <div class="text-dark fw-semibold"><?= formatDate($b['check_in']) ?> to <?= formatDate($b['check_out']) ?></div>
              <small class="text-muted"><i class="fas fa-moon me-1"></i><?= (int)$b['nights'] ?> night<?= $b['nights'] != 1 ? 's' : '' ?></small>
            </td>
            <td>
              <div class="fw-bold text-dark"><?= formatCurrency((float)$b['total_amount']) ?></div>
              <small class="text-success"><i class="fas fa-check-circle me-1"></i>Paid: <?= formatCurrency((float)$b['paid_amount']) ?></small>
            </td>
            <td>
              <span class="badge" style="background:<?= $statusColors[$b['status']] ?? '#7f8c8d' ?>">
                <?= strtoupper(str_replace('_', ' ', $b['status'])) ?>
              </span>
            </td>
            <td class="text-end">
              <div class="d-flex justify-content-end gap-2">
                <?php if ($b['status'] === 'confirmed'): ?>
                <form method="post" class="mb-0">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= $b['id'] ?>">
                  <input type="hidden" name="status" value="checked_in">
                  <button type="submit" class="btn btn-sm btn-success text-white" title="Check-In Guest"><i class="fas fa-sign-in-alt me-1"></i>Check-In</button>
                </form>
                <?php elseif ($b['status'] === 'checked_in'): ?>
                <form method="post" class="mb-0">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= $b['id'] ?>">
                  <input type="hidden" name="status" value="checked_out">
                  <button type="submit" class="btn btn-sm btn-info text-white" title="Check-Out Guest"><i class="fas fa-sign-out-alt me-1"></i>Check-Out</button>
                </form>
                <?php endif; ?>
                
                <a href="?edit=<?= $b['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-edit"></i></a>
                <form method="post" class="mb-0">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $b['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete booking <?= e($b['booking_no']) ?>?"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Booking Modal -->
<div class="modal fade" id="bookingModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-calendar-alt me-2"></i><span id="modalTitle">New Booking</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-loading>
        <?= csrfField() ?>
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="id" id="formId" value="">
        <div class="modal-body text-dark">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Select Guest <span class="text-danger">*</span></label>
              <select name="guest_id" id="fieldGuest" class="form-select" required>
                <option value="">— Select Guest —</option>
                <?php foreach ($guests as $g): ?>
                <option value="<?= $g['id'] ?>"><?= e($g['last_name'] . ' ' . $g['first_name']) ?> (ID: <?= e($g['id_number']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="col-md-6">
              <label class="form-label fw-semibold">Select Room <span class="text-danger">*</span></label>
              <select name="room_id" id="fieldRoom" class="form-select" required onchange="updateRate()">
                <option value="">— Select Available Room —</option>
                <?php foreach ($rooms as $r): ?>
                <option value="<?= $r['id'] ?>" data-price="<?= $r['price_per_night'] ?>"><?= e($r['room_no']) ?> — <?= e($r['type_name']) ?> (KES <?= number_format($r['price_per_night'],2) ?>/night)</option>
                <?php endforeach; ?>
              </select>
              <div id="roomSelectWarning" class="form-text text-danger d-none">If editing, you may select a different room if needed.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Check-In Date <span class="text-danger">*</span></label>
              <input type="date" name="check_in" id="fieldCheckIn" class="form-control" required min="<?= date('Y-m-d') ?>" onchange="calculateTotal()">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Check-Out Date <span class="text-danger">*</span></label>
              <input type="date" name="check_out" id="fieldCheckOut" class="form-control" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>" onchange="calculateTotal()">
            </div>

            <div class="col-md-3">
              <label class="form-label fw-semibold">Adults</label>
              <input type="number" name="adults" id="fieldAdults" class="form-control" min="1" value="1">
            </div>

            <div class="col-md-3">
              <label class="form-label fw-semibold">Children</label>
              <input type="number" name="children" id="fieldChildren" class="form-control" min="0" value="0">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Rate Per Night (KES)</label>
              <input type="number" name="rate_per_night" id="fieldRate" class="form-control" min="0" step="0.01" readonly required>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Extra Charges (KES)</label>
              <input type="number" name="extra_charges" id="fieldExtra" class="form-control" min="0" step="0.01" value="0.00" onchange="calculateTotal()">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Amount Paid (KES)</label>
              <input type="number" name="paid_amount" id="fieldPaid" class="form-control" min="0" step="0.01" value="0.00">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Booking Status</label>
              <select name="status" id="fieldStatus" class="form-select">
                <option value="confirmed">Confirmed</option>
                <option value="checked_in">Checked-In</option>
                <option value="checked_out">Checked-Out</option>
                <option value="cancelled">Cancelled</option>
                <option value="no_show">No Show</option>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold">Special Requests / Notes</label>
              <textarea name="special_requests" id="fieldSpecial" class="form-control" rows="2" placeholder="Dietary requirements, extra bed, etc."></textarea>
            </div>

            <div class="col-12">
              <div class="p-3 bg-light rounded d-flex align-items-center justify-content-between">
                <div>
                  <h6 class="mb-0 fw-bold text-dark">Calculation Summary</h6>
                  <small id="calcSummary" class="text-muted">Select dates and room</small>
                </div>
                <div class="text-end">
                  <div class="fw-bold fs-4 text-dark" id="calcTotal">KES 0.00</div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>">
            <i class="fas fa-save me-2"></i>Save Booking
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
// If editing, fetch all rooms so we can include the currently booked room
$allRooms = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.id, r.room_no, rt.name AS type_name, rt.price_per_night 
        FROM hotel_rooms r
        LEFT JOIN hotel_room_types rt ON rt.id = r.type_id
        WHERE r.org_id=?
    ");
    $stmt->execute([$orgId]);
    $allRooms = $stmt->fetchAll();
} catch (Exception $e) {}

$editData = $editRow ? json_encode($editRow) : 'null';
$allRoomsJson = json_encode($allRooms);

$extraJs = '<script>
(function(){
  var editData = ' . $editData . ';
  var allRooms = ' . $allRoomsJson . ';
  var modal    = document.getElementById("bookingModal");

  window.updateRate = function() {
    var select = document.getElementById("fieldRoom");
    var option = select.options[select.selectedIndex];
    if (option && option.dataset.price) {
      document.getElementById("fieldRate").value = option.dataset.price;
    } else {
      document.getElementById("fieldRate").value = 0;
    }
    calculateTotal();
  };

  window.calculateTotal = function() {
    var checkIn = document.getElementById("fieldCheckIn").value;
    var checkOut = document.getElementById("fieldCheckOut").value;
    var rate = parseFloat(document.getElementById("fieldRate").value) || 0;
    var extra = parseFloat(document.getElementById("fieldExtra").value) || 0;
    
    if (checkIn && checkOut) {
      var d1 = new Date(checkIn);
      var d2 = new Date(checkOut);
      var diff = d2 - d1;
      var nights = Math.max(1, Math.round(diff / (1000 * 60 * 60 * 24)));
      
      if (nights > 0) {
        var roomTotal = rate * nights;
        var grandTotal = roomTotal + extra;
        document.getElementById("calcSummary").textContent = nights + " night(s) @ KES " + rate.toFixed(2) + "/night + KES " + extra.toFixed(2) + " extra";
        document.getElementById("calcTotal").textContent = "KES " + grandTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        return;
      }
    }
    document.getElementById("calcSummary").textContent = "Select dates and room";
    document.getElementById("calcTotal").textContent = "KES 0.00";
  };

  window.resetForm = function(){
    document.getElementById("modalTitle").textContent = "New Booking";
    document.getElementById("formAction").value       = "add";
    document.getElementById("formId").value           = "";
    document.getElementById("fieldGuest").value        = "";
    document.getElementById("fieldRoom").value         = "";
    document.getElementById("fieldCheckIn").value      = "";
    document.getElementById("fieldCheckOut").value     = "";
    document.getElementById("fieldAdults").value       = "1";
    document.getElementById("fieldChildren").value     = "0";
    document.getElementById("fieldRate").value         = "";
    document.getElementById("fieldExtra").value        = "0.00";
    document.getElementById("fieldPaid").value         = "0.00";
    document.getElementById("fieldStatus").value       = "confirmed";
    document.getElementById("fieldSpecial").value      = "";
    document.getElementById("roomSelectWarning").classList.add("d-none");
    calculateTotal();
  };

  window.fillForm = function(d){
    document.getElementById("modalTitle").textContent = "Edit Booking";
    document.getElementById("formAction").value       = "edit";
    document.getElementById("formId").value           = d.id;
    document.getElementById("fieldGuest").value        = d.guest_id || "";
    
    // Add current room if missing from available list
    var select = document.getElementById("fieldRoom");
    var found = false;
    for(var i=0; i<select.options.length; i++) {
      if(select.options[i].value == d.room_id) { found = true; break; }
    }
    if(!found) {
      // Find room in allRooms
      var rObj = allRooms.find(function(x){ return x.id == d.room_id; });
      if(rObj) {
        var opt = document.createElement("option");
        opt.value = rObj.id;
        opt.textContent = rObj.room_no + " — " + rObj.type_name + " (KES " + parseFloat(rObj.price_per_night).toFixed(2) + "/night)";
        opt.dataset.price = rObj.price_per_night;
        select.appendChild(opt);
      }
    }
    
    document.getElementById("fieldRoom").value         = d.room_id || "";
    document.getElementById("fieldCheckIn").value      = d.check_in || "";
    document.getElementById("fieldCheckOut").value     = d.check_out || "";
    document.getElementById("fieldAdults").value       = d.adults || "1";
    document.getElementById("fieldChildren").value     = d.children || "0";
    document.getElementById("fieldRate").value         = d.rate_per_night || 0;
    document.getElementById("fieldExtra").value        = d.extra_charges || 0;
    document.getElementById("fieldPaid").value         = d.paid_amount || 0;
    document.getElementById("fieldStatus").value       = d.status || "confirmed";
    document.getElementById("fieldSpecial").value      = d.special_requests || "";
    document.getElementById("roomSelectWarning").classList.remove("d-none");
    calculateTotal();
  };

  if(editData){
    fillForm(editData);
    new bootstrap.Modal(modal).show();
  }

  modal.addEventListener("hidden.bs.modal", function(){
    if(editData) history.replaceState(null,"","bookings.php");
    resetForm();
    editData = null;
  });

  $("#bookingTable").DataTable({pageLength:10,order:[[0,"desc"]]});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
