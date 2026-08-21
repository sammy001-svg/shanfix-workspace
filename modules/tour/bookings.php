<?php
// ── TOUR: Bookings Registry, Seat Control & Live Price Estimator ──
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/_lib.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'book') {
        $id              = (int)($_POST['id'] ?? 0);
        $packageId       = (int)($_POST['package_id']   ?? 0);
        $departureId     = (int)($_POST['departure_id'] ?? 0) ?: null;
        $customerId      = (int)($_POST['customer_id']  ?? 0) ?: null;
        $customerName    = sanitize($_POST['customer_name']   ?? '');
        $customerPhone   = sanitize($_POST['customer_phone']  ?? '');
        $customerEmail   = sanitize($_POST['customer_email']  ?? '');
        $travelDate      = $_POST['travel_date']             ?? '';
        $adults          = (int)($_POST['adults']            ?? 1);
        $children        = (int)($_POST['children']          ?? 0);
        $paidAmount      = (float)($_POST['paid_amount']     ?? 0.00);
        $specialRequests = sanitize($_POST['special_requests'] ?? '');
        $status          = sanitize($_POST['status']         ?? 'pending');

        if ($packageId <= 0 || $customerName === '' || $travelDate === '' || $adults < 1) {
            setFlash('danger', 'Package, Customer Name, Travel Date, and at least 1 Adult are required.');
            redirect('bookings.php');
        }

        // Package pricing baseline
        $stmt = $pdo->prepare("SELECT * FROM tour_packages WHERE id=? AND org_id=?");
        $stmt->execute([$packageId, $orgId]);
        $package = $stmt->fetch();
        if (!$package) {
            setFlash('danger', 'Selected holiday package is invalid.');
            redirect('bookings.php');
        }

        $totalPax   = $adults + $children;
        $priceAdult = (float)$package['price_per_adult'];
        $priceChild = (float)$package['price_per_child'];

        if ($departureId) {
            // Booking onto a fixed departure — capacity is the departure's, not the package's
            $ds = $pdo->prepare("SELECT * FROM tour_departures WHERE id=? AND org_id=? AND package_id=? LIMIT 1");
            $ds->execute([$departureId, $orgId, $packageId]);
            $departure = $ds->fetch();
            if (!$departure) {
                setFlash('danger', 'That departure does not belong to the selected package.');
                redirect('bookings.php');
            }
            if (in_array($departure['status'], ['cancelled', 'departed', 'completed'], true)) {
                setFlash('danger', 'Departure ' . $departure['departure_code'] . ' is ' . $departure['status'] . ' and cannot take new bookings.');
                redirect('bookings.php');
            }

            // Seats already sold, less this booking's own seats when editing
            $sold = tourSeatsBooked($orgId, $departureId);
            if ($id > 0) {
                $own = $pdo->prepare("SELECT adults + children FROM tour_bookings WHERE id=? AND org_id=? AND departure_id=? AND status <> 'cancelled'");
                $own->execute([$id, $orgId, $departureId]);
                $sold -= (int)$own->fetchColumn();
            }
            $available = max(0, (int)$departure['seats_total'] - $sold);
            if ($status !== 'cancelled' && $totalPax > $available) {
                setFlash('danger', 'Departure ' . $departure['departure_code'] . ' has only ' . $available . ' seat(s) left, but this booking needs ' . $totalPax . '.');
                redirect('bookings.php');
            }

            if ($departure['price_adult'] !== null) $priceAdult = (float)$departure['price_adult'];
            if ($departure['price_child'] !== null) $priceChild = (float)$departure['price_child'];
            $travelDate = $departure['start_date'];   // the departure date is authoritative
        } elseif ($totalPax > (int)$package['max_pax']) {
            setFlash('danger', 'Registration failed. The selected holiday package allows a maximum capacity limit of ' . $package['max_pax'] . ' passengers.');
            redirect('bookings.php');
        }

        $totalAmount = ($adults * $priceAdult) + ($children * $priceChild);

        if ($id === 0) {
            $bookingNo = 'BK-' . strtoupper(substr(md5(uniqid((string)microtime(true), true)), 0, 8));
            $stmt = $pdo->prepare("
                INSERT INTO tour_bookings
                    (org_id, booking_no, package_id, departure_id, customer_id, customer_name, customer_phone, customer_email,
                     travel_date, adults, children, total_amount, paid_amount, special_requests, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $bookingNo, $packageId, $departureId, $customerId, $customerName, $customerPhone, $customerEmail,
                            $travelDate, $adults, $children, $totalAmount, $paidAmount, $specialRequests, $status]);
            setFlash('success', 'Booking ' . $bookingNo . ' processed successfully.');
            logActivity('create', 'tour', "Logged client booking '$bookingNo' for package #$packageId");
        } else {
            $stmt = $pdo->prepare("
                UPDATE tour_bookings
                SET package_id=?, departure_id=?, customer_id=?, customer_name=?, customer_phone=?, customer_email=?,
                    travel_date=?, adults=?, children=?, total_amount=?, paid_amount=?, special_requests=?, status=?, updated_at=NOW()
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$packageId, $departureId, $customerId, $customerName, $customerPhone, $customerEmail,
                            $travelDate, $adults, $children, $totalAmount, $paidAmount, $specialRequests, $status, $id, $orgId]);
            setFlash('success', 'Booking details updated successfully.');
            logActivity('update', 'tour', "Updated client booking details #$id");
        }

        // A full departure should stop selling itself
        if ($departureId) {
            $dep = $pdo->prepare("SELECT seats_total, status FROM tour_departures WHERE id=? AND org_id=? LIMIT 1");
            $dep->execute([$departureId, $orgId]);
            $d = $dep->fetch();
            if ($d && in_array($d['status'], ['scheduled', 'guaranteed', 'full'], true)) {
                $remaining = (int)$d['seats_total'] - tourSeatsBooked($orgId, $departureId);
                $newStatus = $remaining <= 0 ? 'full' : ($d['status'] === 'full' ? 'scheduled' : $d['status']);
                if ($newStatus !== $d['status']) {
                    $pdo->prepare("UPDATE tour_departures SET status=?, updated_at=NOW() WHERE id=? AND org_id=?")
                        ->execute([$newStatus, $departureId, $orgId]);
                }
            }
        }
        redirect('bookings.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM tour_bookings WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        setFlash('success', 'Booking cancelled and record deleted.');
        logActivity('delete', 'tour', "Cancelled client booking #$id");
        redirect('bookings.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
require_once __DIR__ . '/_lib.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterDeparture = (int)($_GET['departure_id'] ?? 0);

// ── Selectors ─────────────────────────────────────────────────
$packagesList = $departuresList = $customersList = [];
$packagesPricing = $departurePricing = $customerBook = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, price_per_adult, price_per_child, max_pax FROM tour_packages WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]);
    $packagesList = $stmt->fetchAll();
    foreach ($packagesList as $pk) {
        $packagesPricing[$pk['id']] = [
            'adult_price' => (float)$pk['price_per_adult'],
            'child_price' => (float)$pk['price_per_child'],
            'max_pax'     => (int)$pk['max_pax'],
        ];
    }

    $stmt = $pdo->prepare("
        SELECT d.id, d.departure_code, d.package_id, d.start_date, d.seats_total, d.price_adult, d.price_child, d.status,
               p.name AS package_name
        FROM tour_departures d JOIN tour_packages p ON p.id = d.package_id
        WHERE d.org_id=? AND d.status IN ('scheduled','guaranteed','full') AND d.start_date >= CURDATE()
        ORDER BY d.start_date
    ");
    $stmt->execute([$orgId]);
    $departuresList = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, name, phone, email FROM tour_customers WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]);
    $customersList = $stmt->fetchAll();
    foreach ($customersList as $c) {
        $customerBook[$c['id']] = ['name' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email']];
    }
} catch (Throwable $e) {}

foreach ($departuresList as $d) {
    $seats = tourSeatStatus($orgId, (int)$d['id'], (int)$d['seats_total']);
    $departurePricing[$d['id']] = [
        'package_id' => (int)$d['package_id'],
        'adult'      => $d['price_adult'] !== null ? (float)$d['price_adult'] : null,
        'child'      => $d['price_child'] !== null ? (float)$d['price_child'] : null,
        'start'      => $d['start_date'],
        'available'  => $seats['available'],
    ];
}

// ── Bookings list ─────────────────────────────────────────────
$where  = 'b.org_id = ?';
$params = [$orgId];
if ($filterDeparture > 0) {
    $where .= ' AND b.departure_id = ?';
    $params[] = $filterDeparture;
}

$bookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, p.name AS package_name, dest.name AS dest_name,
               d.departure_code, d.start_date AS departure_start
        FROM tour_bookings b
        JOIN tour_packages p             ON b.package_id = p.id
        LEFT JOIN tour_destinations dest ON p.destination_id = dest.id
        LEFT JOIN tour_departures d      ON d.id = b.departure_id
        WHERE $where
        ORDER BY b.travel_date DESC, b.created_at DESC
    ");
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
} catch (Throwable $e) {}

// The departure being filtered on, so the header can show its load
$focusDeparture = null;
if ($filterDeparture > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT d.*, p.name AS package_name
            FROM tour_departures d JOIN tour_packages p ON p.id = d.package_id
            WHERE d.id=? AND d.org_id=? LIMIT 1
        ");
        $stmt->execute([$filterDeparture, $orgId]);
        $focusDeparture = $stmt->fetch() ?: null;
    } catch (Throwable $e) {}
}

// ── Stats ─────────────────────────────────────────────────────
$totalBookingsCount = count($bookings);
$totalConfirmedRev  = 0.00;
$totalExpectedPax   = 0;
$totalOutstanding   = 0.00;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM tour_bookings WHERE org_id=? AND status='confirmed'");
    $stmt->execute([$orgId]);
    $totalConfirmedRev = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(adults + children), 0) FROM tour_bookings WHERE org_id=? AND status IN ('pending','confirmed')");
    $stmt->execute([$orgId]);
    $totalExpectedPax = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(GREATEST(total_amount - paid_amount, 0)),0)
        FROM tour_bookings WHERE org_id=? AND status IN ('pending','confirmed')
    ");
    $stmt->execute([$orgId]);
    $totalOutstanding = (float)$stmt->fetchColumn();
} catch (Throwable $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-check me-2" style="color:<?= $moduleColor ?>"></i>Bookings &amp; Reservations</h4>
    <p class="text-muted mb-0">Record reservations against fixed departures or private dates, and track deposits to travel day</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" onclick="openAddModal()">
    <i class="fas fa-plus me-1"></i>New Booking
  </button>
</div>

<?php if ($focusDeparture):
    $fs = tourSeatStatus($orgId, (int)$focusDeparture['id'], (int)$focusDeparture['seats_total']);
?>
<div class="alert alert-light border shadow-sm d-flex flex-wrap align-items-center gap-3">
  <div class="flex-grow-1">
    <div class="fw-bold">
      <i class="fas fa-clipboard-list me-1" style="color:<?= $moduleColor ?>"></i>
      Manifest for <?= e($focusDeparture['departure_code']) ?> — <?= e($focusDeparture['package_name']) ?>
    </div>
    <div class="small text-muted">
      Departing <?= formatDate($focusDeparture['start_date']) ?> ·
      <?= $fs['booked'] ?> of <?= $fs['total'] ?> seats sold · <?= $fs['available'] ?> still open
    </div>
  </div>
  <a href="bookings.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-xmark me-1"></i>Clear filter</a>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,0.15);color:#2980b9"><i class="fas fa-suitcase"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalBookingsCount ?></div><div class="stat-label">Reservations In View</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-wallet"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalConfirmedRev) ?></div><div class="stat-label">Confirmed Revenue Collected</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalExpectedPax ?></div><div class="stat-label">Enrouted Passengers</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(231,76,60,.15);color:#e74c3c"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalOutstanding) ?></div><div class="stat-label">Balance Still Due</div></div>
    </div>
  </div>
</div>

<!-- Bookings Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="bookingsTable">
        <thead class="table-light">
          <tr>
            <th>Ref Code</th>
            <th>Customer Client</th>
            <th>Holiday Package</th>
            <th>Travel Date</th>
            <th class="text-center">Pax (A/C)</th>
            <th class="text-end">Total Amount</th>
            <th class="text-end">Balance</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($bookings)): ?>
          <tr>
            <td colspan="9" class="text-center py-5 text-muted">
              <i class="fas fa-receipt fa-3x mb-3 d-block"></i>No client travel bookings found.
            </td>
          </tr>
          <?php else: foreach ($bookings as $b):
              $balance = max(0, (float)$b['total_amount'] - (float)$b['paid_amount']);
          ?>
          <tr>
            <td>
              <code class="text-dark bg-light px-2 py-1 rounded"><?= e($b['booking_no']) ?></code>
              <?php if (!empty($b['portal_enabled'])): ?>
              <div class="small text-muted mt-1"><i class="fas fa-key me-1"></i>Portal access</div>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= e($b['customer_name']) ?></div>
              <div class="small text-muted"><i class="fas fa-phone me-1"></i><?= e($b['customer_phone'] ?: '—') ?></div>
              <div class="small text-muted"><i class="fas fa-envelope me-1"></i><?= e($b['customer_email'] ?: '—') ?></div>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= e($b['package_name']) ?></div>
              <div class="small text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= e($b['dest_name'] ?: '—') ?></div>
              <?php if (!empty($b['departure_code'])): ?>
              <div class="small"><span class="badge bg-light text-dark border"><i class="fas fa-plane-departure me-1"></i><?= e($b['departure_code']) ?></span></div>
              <?php endif; ?>
            </td>
            <td><?= formatDate($b['travel_date']) ?></td>
            <td class="text-center">
              <strong><?= (int)$b['adults'] + (int)$b['children'] ?></strong> pax
              <div class="small text-muted mt-1"><?= (int)$b['adults'] ?> A / <?= (int)$b['children'] ?> C</div>
            </td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$b['total_amount']) ?></td>
            <td class="text-end fw-bold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
              <?= $balance > 0 ? formatCurrency($balance) : 'Settled' ?>
            </td>
            <td><?= statusBadge($b['status']) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <a href="payments.php?booking_id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-success" title="Payments">
                <i class="fas fa-money-bill-wave"></i>
              </a>
              <button class="btn btn-sm btn-outline-secondary ms-1" onclick='editBooking(<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit Details">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Cancel registration and delete reservation record?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel Booking">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Booking Modal -->
<div class="modal fade" id="bookingModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="book">
        <input type="hidden" name="id" id="bookingId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus me-2"></i>New Booking</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Select Holiday Package <span class="text-danger">*</span></label>
              <select name="package_id" id="bookPackage" class="form-select" required onchange="onPackageChange()">
                <option value="">-- Select Package --</option>
                <?php foreach ($packagesList as $pl): ?>
                <option value="<?= (int)$pl['id'] ?>"><?= e($pl['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Fixed Departure</label>
              <select name="departure_id" id="bookDeparture" class="form-select" onchange="onDepartureChange()">
                <option value="">-- Private / flexible date --</option>
                <?php foreach ($departuresList as $d): ?>
                <option value="<?= (int)$d['id'] ?>" data-package="<?= (int)$d['package_id'] ?>">
                  <?= e($d['departure_code'] . ' — ' . formatDate($d['start_date'])) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" id="seatHint">Choosing a departure locks the travel date and checks seat availability.</div>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Existing Client</label>
              <select name="customer_id" id="bookCustomer" class="form-select" onchange="fillCustomer()">
                <option value="">-- New / walk-in --</option>
                <?php foreach ($customersList as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Customer Full Name <span class="text-danger">*</span></label>
              <input type="text" name="customer_name" id="bookName" class="form-control" required placeholder="e.g. John Doe">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Travel Date <span class="text-danger">*</span></label>
              <input type="date" name="travel_date" id="bookDate" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Customer Email</label>
              <input type="email" name="customer_email" id="bookEmail" class="form-control" placeholder="e.g. john.doe@example.com">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Customer Phone</label>
              <input type="text" name="customer_phone" id="bookPhone" class="form-control" placeholder="e.g. +254 712 345678">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="bookStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="confirmed">Confirmed</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Adult passengers <span class="text-danger">*</span></label>
              <input type="number" name="adults" id="bookAdults" class="form-control" required min="1" value="1" oninput="updateEstimatedPrice()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Child passengers</label>
              <input type="number" name="children" id="bookChildren" class="form-control" min="0" value="0" oninput="updateEstimatedPrice()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Paid Deposit (<?= CURRENCY ?>)</label>
              <input type="number" step="0.01" name="paid_amount" id="bookPaid" class="form-control" min="0" value="0.00">
            </div>

            <div class="col-md-12">
              <div class="p-3 bg-light rounded border d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                  <div class="small fw-semibold text-muted mb-1">Estimated Total Cost</div>
                  <div class="fs-4 fw-bold text-success" id="estimatedCostSpan"><?= CURRENCY ?> 0.00</div>
                </div>
                <div class="text-end small" id="capacityNote"></div>
              </div>
            </div>

            <div class="col-md-12">
              <label class="form-label fw-semibold">Special Requests / Notes</label>
              <textarea name="special_requests" id="bookRequests" class="form-control" rows="2" placeholder="e.g. Vegetarian meals, wheelchair support, double bed..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Booking</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
const pricingMap       = ' . json_encode($packagesPricing) . ';
const departurePricing = ' . json_encode($departurePricing) . ';
const customerBook     = ' . json_encode($customerBook) . ';
const CUR              = "' . CURRENCY . '";
let bookModal, editingSeats = 0;

$(document).ready(function(){
  $("#bookingsTable").DataTable({pageLength:15, order:[[3,"desc"]]});
  bookModal = new bootstrap.Modal(document.getElementById("bookingModal"));
});

function money(n) {
  return CUR + " " + (Number(n) || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,");
}

function fillCustomer() {
  const c = customerBook[$("#bookCustomer").val()];
  if (!c) return;
  $("#bookName").val(c.name || "");
  $("#bookPhone").val(c.phone || "");
  $("#bookEmail").val(c.email || "");
}

/* Only offer departures that belong to the chosen package */
function onPackageChange() {
  const pkg = $("#bookPackage").val();
  $("#bookDeparture option").each(function(){
    const dp = $(this).data("package");
    if (!dp) return;
    $(this).prop("hidden", !!pkg && String(dp) !== String(pkg));
  });
  if ($("#bookDeparture").find("option:selected").prop("hidden")) $("#bookDeparture").val("");
  onDepartureChange();
}

function onDepartureChange() {
  const dep = departurePricing[$("#bookDeparture").val()];
  if (dep) {
    $("#bookPackage").val(dep.package_id);
    $("#bookDate").val(dep.start).prop("readonly", true);
    $("#seatHint").html("<strong>" + (dep.available + editingSeats) + "</strong> seat(s) available on this departure.");
  } else {
    $("#bookDate").prop("readonly", false);
    $("#seatHint").text("Choosing a departure locks the travel date and checks seat availability.");
  }
  updateEstimatedPrice();
}

function updateEstimatedPrice() {
  const pkgId    = $("#bookPackage").val();
  const adults   = parseInt($("#bookAdults").val()   || 0);
  const children = parseInt($("#bookChildren").val() || 0);
  const prices   = pricingMap[pkgId];

  if (!prices) {
    $("#estimatedCostSpan").text(money(0));
    $("#capacityNote").html("");
    return;
  }

  const dep      = departurePricing[$("#bookDeparture").val()];
  const adultFee = dep && dep.adult !== null ? dep.adult : prices.adult_price;
  const childFee = dep && dep.child !== null ? dep.child : prices.child_price;
  const pax      = adults + children;

  $("#estimatedCostSpan").text(money((adults * adultFee) + (children * childFee)));

  /* Warn before the server does */
  if (dep) {
    const open = dep.available + editingSeats;
    $("#capacityNote").html(pax > open
      ? "<span class=\"text-danger fw-semibold\">Needs " + pax + " seats, only " + open + " open</span>"
      : "<span class=\"text-muted\">" + pax + " of " + open + " available seats</span>");
  } else if (pax > prices.max_pax) {
    $("#capacityNote").html("<span class=\"text-danger fw-semibold\">Package caps at " + prices.max_pax + " passengers</span>");
  } else {
    $("#capacityNote").html("<span class=\"text-muted\">Package caps at " + prices.max_pax + " passengers</span>");
  }
}

function openAddModal() {
  $("#modalTitle").html("<i class=\"fas fa-plus me-2\"></i>New Booking");
  editingSeats = 0;
  $("#bookingId,#bookPackage,#bookDeparture,#bookCustomer,#bookName,#bookEmail,#bookPhone,#bookRequests").val("");
  $("#bookDate").val("' . date('Y-m-d') . '").prop("readonly", false);
  $("#bookAdults").val("1");
  $("#bookChildren").val("0");
  $("#bookPaid").val("0.00");
  $("#bookStatus").val("pending");
  $("#bookDeparture option").prop("hidden", false);
  updateEstimatedPrice();
  bookModal.show();
}

function editBooking(b) {
  $("#modalTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Booking Details");
  /* This booking already holds seats on its departure — do not double-count them */
  editingSeats = b.departure_id ? (parseInt(b.adults || 0) + parseInt(b.children || 0)) : 0;
  $("#bookingId").val(b.id);
  $("#bookPackage").val(b.package_id || "");
  $("#bookDeparture option").prop("hidden", false);
  $("#bookDeparture").val(b.departure_id || "");
  $("#bookCustomer").val(b.customer_id || "");
  $("#bookName").val(b.customer_name || "");
  $("#bookEmail").val(b.customer_email || "");
  $("#bookPhone").val(b.customer_phone || "");
  $("#bookDate").val(b.travel_date || "");
  $("#bookAdults").val(b.adults || 1);
  $("#bookChildren").val(b.children || 0);
  $("#bookPaid").val(b.paid_amount || "0.00");
  $("#bookRequests").val(b.special_requests || "");
  $("#bookStatus").val(b.status || "pending");
  onDepartureChange();
  bookModal.show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
