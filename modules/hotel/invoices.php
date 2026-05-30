<?php
// ── Hotel: Guest Invoices ─────────────────────────────────────
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $guestId     = (int)($_POST['guest_id'] ?? 0);
        $bookingId   = (int)($_POST['booking_id'] ?? 0) ?: null;
        $roomCharges = (float)($_POST['room_charges'] ?? 0);
        $restCharges = (float)($_POST['restaurant_charges'] ?? 0);
        $svcCharges  = (float)($_POST['service_charges'] ?? 0);
        $othCharges  = (float)($_POST['other_charges'] ?? 0);
        $taxAmount   = (float)($_POST['tax_amount'] ?? 0);
        $discount    = (float)($_POST['discount'] ?? 0);
        $paymentMode = sanitize($_POST['payment_mode'] ?? 'cash');
        $status      = sanitize($_POST['status'] ?? 'draft');
        $issuedDate  = sanitize($_POST['issued_date'] ?? '') ?: null;
        $dueDate     = sanitize($_POST['due_date'] ?? '') ?: null;
        $notes       = sanitize($_POST['notes'] ?? '');

        $total = $roomCharges + $restCharges + $svcCharges + $othCharges + $taxAmount - $discount;

        if ($guestId <= 0) { setFlash('danger', 'Guest is required.'); redirect('invoices.php'); }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE hotel_invoices SET guest_id=?, booking_id=?, room_charges=?, restaurant_charges=?, service_charges=?, other_charges=?, tax_amount=?, discount=?, total_amount=?, payment_mode=?, status=?, issued_date=?, due_date=?, notes=?, updated_at=NOW() WHERE id=? AND org_id=?")
                    ->execute([$guestId, $bookingId, $roomCharges, $restCharges, $svcCharges, $othCharges, $taxAmount, $discount, $total, $paymentMode, $status, $issuedDate, $dueDate, $notes, $id, $orgId]);
                setFlash('success', 'Invoice updated.');
            } else {
                $seq = $pdo->query("SELECT COUNT(*) FROM hotel_invoices WHERE org_id=$orgId")->fetchColumn() + 1;
                $invNo = 'HINV-' . date('Y') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO hotel_invoices (org_id, invoice_no, guest_id, booking_id, room_charges, restaurant_charges, service_charges, other_charges, tax_amount, discount, total_amount, payment_mode, status, issued_date, due_date, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $invNo, $guestId, $bookingId, $roomCharges, $restCharges, $svcCharges, $othCharges, $taxAmount, $discount, $total, $paymentMode, $status, $issuedDate, $dueDate, $notes]);
                setFlash('success', "Invoice {$invNo} created — Total: " . formatCurrency($total));
                logActivity('create', 'hotel', "Invoice {$invNo}");
            }
        } catch (Exception $e) { setFlash('danger', 'Error: ' . $e->getMessage()); }
        redirect('invoices.php');
    }

    if ($action === 'record_payment') {
        $id     = (int)($_POST['id'] ?? 0);
        $amount = (float)($_POST['pay_amount'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT total_amount, paid_amount FROM hotel_invoices WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            $inv = $stmt->fetch();
            if ($inv) {
                $newPaid = (float)$inv['paid_amount'] + $amount;
                $newStatus = $newPaid >= (float)$inv['total_amount'] ? 'paid' : 'partial';
                $pdo->prepare("UPDATE hotel_invoices SET paid_amount=?, status=?, updated_at=NOW() WHERE id=? AND org_id=?")
                    ->execute([$newPaid, $newStatus, $id, $orgId]);
                setFlash('success', 'Payment recorded. Status: ' . ucfirst($newStatus));
            }
        } catch (Exception $e) { setFlash('danger', 'Error: ' . $e->getMessage()); }
        redirect('invoices.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';
$where   = 'i.org_id = ?'; $params = [$orgId];
if ($fStatus !== '') { $where .= ' AND i.status = ?'; $params[] = $fStatus; }

$invoices = $guests = $bookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.*, CONCAT(g.first_name,' ',g.last_name) AS guest_name
        FROM hotel_invoices i
        JOIN hotel_guests g ON g.id = i.guest_id
        WHERE {$where}
        ORDER BY i.created_at DESC
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM hotel_guests WHERE org_id=? ORDER BY first_name");
    $stmt->execute([$orgId]);
    $guests = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, booking_ref, check_in, check_out FROM hotel_bookings WHERE org_id=? ORDER BY check_in DESC LIMIT 100");
    $stmt->execute([$orgId]);
    $bookings = $stmt->fetchAll();
} catch (Exception $e) {}

$totalRevenue = array_sum(array_column(array_filter($invoices, fn($i) => $i['status'] === 'paid'), 'total_amount'));
$outstanding  = array_sum(array_column(array_filter($invoices, fn($i) => in_array($i['status'], ['issued','partial'])), 'total_amount'))
              - array_sum(array_column(array_filter($invoices, fn($i) => in_array($i['status'], ['issued','partial'])), 'paid_amount'));

$statusColors = ['draft'=>'secondary','issued'=>'primary','paid'=>'success','partial'=>'warning','cancelled'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Guest Invoices</h4>
    <p class="text-muted mb-0">Generate consolidated guest bills including room, restaurant and service charges</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#invModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>New Invoice
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Paid Revenue</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($outstanding) ?></div><div class="stat-label">Outstanding</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(211,84,0,.12);color:#d35400"><i class="fas fa-file-invoice"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($invoices) ?></div><div class="stat-label">Total Invoices</div></div></div>
  </div>
</div>

<!-- Filter -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (['draft','issued','partial','paid','cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-auto">
      <button class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="invoices.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card border-0 shadow-sm"><div class="card-body p-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead class="table-light">
        <tr><th>Invoice #</th><th>Guest</th><th class="text-end">Room</th><th class="text-end">Food</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-center">Status</th><th class="text-center">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($invoices)): ?>
        <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-file-invoice fa-3x mb-3 d-block"></i>No invoices found.</td></tr>
        <?php else: foreach ($invoices as $inv): ?>
        <tr>
          <td class="fw-bold"><?= e($inv['invoice_no']) ?></td>
          <td><?= e($inv['guest_name']) ?></td>
          <td class="text-end"><?= formatCurrency((float)$inv['room_charges']) ?></td>
          <td class="text-end"><?= formatCurrency((float)$inv['restaurant_charges']) ?></td>
          <td class="text-end fw-bold"><?= formatCurrency((float)$inv['total_amount']) ?></td>
          <td class="text-end text-success"><?= formatCurrency((float)$inv['paid_amount']) ?></td>
          <td class="text-center"><span class="badge bg-<?= $statusColors[$inv['status']] ?? 'secondary' ?>"><?= ucfirst($inv['status']) ?></span></td>
          <td class="text-center" style="white-space:nowrap">
            <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($inv), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
            <?php if (in_array($inv['status'], ['issued','partial'])): ?>
            <button class="btn btn-sm btn-outline-success ms-1" data-bs-toggle="modal" data-bs-target="#payModal"
              onclick="document.getElementById('payInvId').value=<?= $inv['id'] ?>"><i class="fas fa-money-bill"></i></button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div></div>

<!-- Invoice Modal -->
<div class="modal fade" id="invModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="invId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="invTitle"><i class="fas fa-file-invoice me-2"></i>New Invoice</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Guest <span class="text-danger">*</span></label>
              <select name="guest_id" id="invGuest" class="form-select" required>
                <option value="">-- Select Guest --</option>
                <?php foreach ($guests as $g): ?><option value="<?= $g['id'] ?>"><?= e($g['first_name'] . ' ' . $g['last_name']) ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Booking (optional)</label>
              <select name="booking_id" id="invBooking" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($bookings as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['booking_ref'] . ' (' . $b['check_in'] . ')') ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Room Charges</label>
              <input type="number" name="room_charges" id="invRoom" class="form-control" min="0" step="0.01" value="0" oninput="calcInv()"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Restaurant Charges</label>
              <input type="number" name="restaurant_charges" id="invRest" class="form-control" min="0" step="0.01" value="0" oninput="calcInv()"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Service Charges</label>
              <input type="number" name="service_charges" id="invSvc" class="form-control" min="0" step="0.01" value="0" oninput="calcInv()"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Other Charges</label>
              <input type="number" name="other_charges" id="invOther" class="form-control" min="0" step="0.01" value="0" oninput="calcInv()"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Tax Amount</label>
              <input type="number" name="tax_amount" id="invTax" class="form-control" min="0" step="0.01" value="0" oninput="calcInv()"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Discount</label>
              <input type="number" name="discount" id="invDiscount" class="form-control" min="0" step="0.01" value="0" oninput="calcInv()"></div>
            <div class="col-12"><div class="alert alert-info py-2 mb-0">Total: <strong id="invTotal">KES 0.00</strong></div></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Payment Mode</label>
              <select name="payment_mode" id="invPay" class="form-select">
                <option value="cash">Cash</option>
                <option value="mpesa">M-Pesa</option>
                <option value="card">Card</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="room_account">Room Account</option>
              </select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Status</label>
              <select name="status" id="invStatus" class="form-select">
                <option value="draft">Draft</option>
                <option value="issued">Issued</option>
                <option value="paid">Paid</option>
                <option value="cancelled">Cancelled</option>
              </select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Issued Date</label>
              <input type="date" name="issued_date" id="invIssued" class="form-control"></div>
            <div class="col-12"><label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="invNotes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Invoice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="record_payment"><input type="hidden" name="id" id="payInvId">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-money-bill me-2"></i>Record Payment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label fw-semibold">Amount Received (KES) <span class="text-danger">*</span></label>
        <input type="number" name="pay_amount" class="form-control" min="0.01" step="0.01" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>Record</button>
      </div>
    </form>
  </div></div>
</div>

<?php $extraJs = <<<'JS'
<script>
function calcInv() {
    const vals = ['invRoom','invRest','invSvc','invOther','invTax','invDiscount'].map(id => parseFloat(document.getElementById(id).value)||0);
    const total = vals[0]+vals[1]+vals[2]+vals[3]+vals[4]-vals[5];
    document.getElementById('invTotal').textContent = 'KES ' + total.toFixed(2);
}
function openAdd() {
    document.getElementById('invTitle').innerHTML = '<i class="fas fa-file-invoice me-2"></i>New Invoice';
    document.getElementById('invId').value = '0';
    ['invGuest','invBooking','invNotes'].forEach(id => document.getElementById(id).value = '');
    ['invRoom','invRest','invSvc','invOther','invTax','invDiscount'].forEach(id => document.getElementById(id).value = '0');
    document.getElementById('invStatus').value = 'draft';
    document.getElementById('invPay').value    = 'cash';
    document.getElementById('invIssued').value = '';
    document.getElementById('invTotal').textContent = 'KES 0.00';
}
function openEdit(inv) {
    document.getElementById('invTitle').innerHTML          = '<i class="fas fa-edit me-2"></i>Edit Invoice';
    document.getElementById('invId').value                 = inv.id;
    document.getElementById('invGuest').value              = inv.guest_id;
    document.getElementById('invBooking').value            = inv.booking_id || '';
    document.getElementById('invRoom').value               = inv.room_charges;
    document.getElementById('invRest').value               = inv.restaurant_charges;
    document.getElementById('invSvc').value                = inv.service_charges;
    document.getElementById('invOther').value              = inv.other_charges;
    document.getElementById('invTax').value                = inv.tax_amount;
    document.getElementById('invDiscount').value           = inv.discount;
    document.getElementById('invStatus').value             = inv.status;
    document.getElementById('invPay').value                = inv.payment_mode;
    document.getElementById('invIssued').value             = inv.issued_date || '';
    document.getElementById('invNotes').value              = inv.notes || '';
    calcInv();
    new bootstrap.Modal(document.getElementById('invModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
