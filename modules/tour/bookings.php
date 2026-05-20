<?php
// ── TOUR: Bookings Registry & Live Price Estimator ─────────────
$moduleSlug  = 'tour';
$moduleName  = 'Tour & Travel';
$moduleIcon  = 'fas fa-plane';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'packages.php',     'icon' => 'fas fa-box-open',       'label' => 'Packages'],
    ['url' => 'destinations.php', 'icon' => 'fas fa-map-marker-alt', 'label' => 'Destinations'],
    ['url' => 'bookings.php',     'icon' => 'fas fa-calendar-check', 'label' => 'Bookings'],
    ['url' => 'customers.php',    'icon' => 'fas fa-user-friends',   'label' => 'Customers'],
    ['url' => 'guides.php',       'icon' => 'fas fa-hiking',         'label' => 'Guides'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'book') {
        $id              = (int)($_POST['id'] ?? 0);
        $packageId       = (int)($_POST['package_id']       ?? 0);
        $customerName    = sanitize($_POST['customer_name']   ?? '');
        $customerPhone   = sanitize($_POST['customer_phone']  ?? '');
        $customerEmail   = sanitize($_POST['customer_email']  ?? '');
        $travelDate      = $_POST['travel_date']             ?? '';
        $adults          = (int)($_POST['adults']            ?? 1);
        $children        = (int)($_POST['children']          ?? 0);
        $paidAmount      = (float)($_POST['paid_amount']     ?? 0.00);
        $specialRequests = sanitize($_POST['special_requests'] ?? '');
        $status          = sanitize($_POST['status']         ?? 'pending');

        if ($packageId <= 0 || empty($customerName) || empty($travelDate) || $adults < 1) {
            setFlash('danger', 'Package, Customer Name, Travel Date, and at least 1 Adult are required.');
            redirect('bookings.php');
        }

        // Fetch package pricing details
        $stmt = $pdo->prepare("SELECT * FROM tour_packages WHERE id=? AND org_id=?");
        $stmt->execute([$packageId, $orgId]);
        $package = $stmt->fetch();

        if (!$package) {
            setFlash('danger', 'Selected holiday package is invalid.');
            redirect('bookings.php');
        }

        // Total passengers capacity check
        $totalPax = $adults + $children;
        if ($totalPax > (int)$package['max_pax']) {
            setFlash('danger', 'Registration failed. The selected holiday package allows a maximum capacity limit of ' . $package['max_pax'] . ' passengers.');
            redirect('bookings.php');
        }

        // Proportional price formula
        $totalAmount = ($adults * (float)$package['price_per_adult']) + ($children * (float)$package['price_per_child']);

        if ($id === 0) {
            // Generate booking ref serial
            $bookingNo = 'BK-' . strtoupper(substr(md5(uniqid(microtime(), true)), 0, 8));

            $stmt = $pdo->prepare("
                INSERT INTO tour_bookings (org_id, booking_no, package_id, customer_name, customer_phone, customer_email, travel_date, adults, children, total_amount, paid_amount, special_requests, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $bookingNo, $packageId, $customerName, $customerPhone, $customerEmail, $travelDate, $adults, $children, $totalAmount, $paidAmount, $specialRequests, $status]);
            setFlash('success', 'Booking ' . $bookingNo . ' processed successfully.');
            logActivity('create', 'tour', "Logged client booking '$bookingNo' for package #$packageId");
        } else {
            // Updating existing booking
            $stmt = $pdo->prepare("
                UPDATE tour_bookings
                SET package_id=?, customer_name=?, customer_phone=?, customer_email=?, travel_date=?, adults=?, children=?, total_amount=?, paid_amount=?, special_requests=?, status=?
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$packageId, $customerName, $customerPhone, $customerEmail, $travelDate, $adults, $children, $totalAmount, $paidAmount, $specialRequests, $status, $id, $orgId]);
            setFlash('success', 'Booking details updated successfully.');
            logActivity('update', 'tour', "Updated client booking details #$id");
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
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch packages selector and pricing mappings
$packagesList = [];
$packagesPricing = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, price_per_adult, price_per_child, max_pax FROM tour_packages WHERE org_id=? AND status='active'");
    $stmt->execute([$orgId]);
    $packagesList = $stmt->fetchAll();
    foreach ($packagesList as $pk) {
        $packagesPricing[$pk['id']] = [
            'adult_price' => (float)$pk['price_per_adult'],
            'child_price' => (float)$pk['price_per_child'],
            'max_pax'     => (int)$pk['max_pax']
        ];
    }
} catch (Exception $e) {}

// Fetch bookings list
$bookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, p.name as package_name, d.name as dest_name
        FROM tour_bookings b
        JOIN tour_packages p ON b.package_id = p.id
        JOIN tour_destinations d ON p.destination_id = d.id
        WHERE b.org_id = ?
        ORDER BY b.travel_date DESC, b.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $bookings = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats widgets metrics
$totalBookingsCount = count($bookings);
$totalConfirmedRev  = 0.00;
$totalExpectedPax   = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM tour_bookings WHERE org_id=? AND status='confirmed'");
    $stmt->execute([$orgId]);
    $totalConfirmedRev = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(adults + children), 0) FROM tour_bookings WHERE org_id=? AND status IN ('pending','confirmed')");
    $stmt->execute([$orgId]);
    $totalExpectedPax = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-check me-2" style="color:<?= $moduleColor ?>"></i>Bookings & Reservations</h4>
    <p class="text-muted mb-0">Record reservations, log partial deposit payments, and view itinerary manifests</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#bookingModal" onclick="openAddModal()">
    <i class="fas fa-plus me-1"></i>New Booking
  </button>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon blue-bg" style="background:rgba(41,128,185,0.15);color:#2980b9"><i class="fas fa-suitcase"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalBookingsCount ?></div><div class="stat-label">Total Reservations</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-wallet"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalConfirmedRev) ?></div><div class="stat-label">Confirmed Revenue Collected</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalExpectedPax ?></div><div class="stat-label">Enrouted Passengers</div></div>
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
            <th class="text-end">Paid Amount</th>
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
          <?php else: foreach ($bookings as $b): ?>
          <tr>
            <td><code class="text-dark bg-light px-2 py-1 rounded"><?= e($b['booking_no']) ?></code></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($b['customer_name']) ?></div>
              <div class="small text-muted"><i class="fas fa-phone me-1"></i><?= e($b['customer_phone'] ?: '—') ?></div>
              <div class="small text-muted"><i class="fas fa-envelope me-1"></i><?= e($b['customer_email'] ?: '—') ?></div>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= e($b['package_name']) ?></div>
              <div class="small text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= e($b['dest_name']) ?></div>
            </td>
            <td><?= formatDate($b['travel_date']) ?></td>
            <td class="text-center">
              <strong><?= (int)$b['adults'] + (int)$b['children'] ?></strong> pax
              <div class="small text-muted mt-1"><?= $b['adults'] ?> A / <?= $b['children'] ?> C</div>
            </td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$b['total_amount']) ?></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$b['paid_amount']) ?></td>
            <td><?= statusBadge($b['status']) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick="editBooking(<?= e(json_encode($b)) ?>)" title="Edit Details">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Cancel registration and delete reservation record?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $b['id'] ?>">
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
        <input type="hidden" name="action" id="modalAction" value="book">
        <input type="hidden" name="id" id="bookingId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus me-2"></i>New Booking</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Select Holiday Package <span class="text-danger">*</span></label>
              <select name="package_id" id="bookPackage" class="form-select" required onchange="updateEstimatedPrice()">
                <option value="">-- Select Package --</option>
                <?php foreach ($packagesList as $pl): ?>
                <option value="<?= $pl['id'] ?>"><?= e($pl['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Customer Full Name <span class="text-danger">*</span></label>
              <input type="text" name="customer_name" id="bookName" class="form-control" required placeholder="e.g. John Doe">
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
              <label class="form-label fw-semibold">Travel Date <span class="text-danger">*</span></label>
              <input type="date" name="travel_date" id="bookDate" class="form-control" required value="<?= date('Y-m-d') ?>">
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
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="bookStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="confirmed">Confirmed</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Paid Deposit (<?= CURRENCY ?>)</label>
              <input type="number" step="0.01" name="paid_amount" id="bookPaid" class="form-control" min="0" value="0.00">
            </div>
            <div class="col-md-6">
              <div class="p-3 bg-light rounded mt-2 border">
                <div class="small fw-semibold text-muted mb-1">Estimated Total Cost:</div>
                <div class="fs-4 fw-bold text-success" id="estimatedCostSpan">KES 0.00</div>
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
const pricingMap = ' . json_encode($packagesPricing) . ';

$(document).ready(function(){
  $("#bookingsTable").DataTable({pageLength:10,order:[[3,"desc"]]});
});

function openAddModal() {
  $("#modalAction").val("book");
  $("#modalTitle").html("<i class=\"fas fa-plus me-2\"></i>New Booking");
  $("#bookingId").val("");
  $("#bookPackage").val("");
  $("#bookName").val("");
  $("#bookEmail").val("");
  $("#bookPhone").val("");
  $("#bookDate").val("' . date('Y-m-d') . '");
  $("#bookAdults").val("1");
  $("#bookChildren").val("0");
  $("#bookPaid").val("0.00");
  $("#bookRequests").val("");
  $("#bookStatus").val("pending");
  $("#estimatedCostSpan").text("KES 0.00");
}

function editBooking(b) {
  $("#modalAction").val("book");
  $("#modalTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Booking Details");
  $("#bookingId").val(b.id);
  $("#bookPackage").val(b.package_id || "");
  $("#bookName").val(b.customer_name || "");
  $("#bookEmail").val(b.customer_email || "");
  $("#bookPhone").val(b.customer_phone || "");
  $("#bookDate").val(b.travel_date || "");
  $("#bookAdults").val(b.adults || 1);
  $("#bookChildren").val(b.children || 0);
  $("#bookPaid").val(b.paid_amount || "0.00");
  $("#bookRequests").val(b.special_requests || "");
  $("#bookStatus").val(b.status || "pending");
  
  updateEstimatedPrice();

  new bootstrap.Modal(document.getElementById("bookingModal")).show();
}

function updateEstimatedPrice() {
  var pkgId = $("#bookPackage").val();
  var adults = parseInt($("#bookAdults").val() || 0);
  var children = parseInt($("#bookChildren").val() || 0);

  if (!pkgId || !pricingMap[pkgId]) {
    $("#estimatedCostSpan").text("KES 0.00");
    return;
  }

  var prices = pricingMap[pkgId];
  var total = (adults * prices.adult_price) + (children * prices.child_price);
  
  $("#estimatedCostSpan").text("KES " + total.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,"));
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
