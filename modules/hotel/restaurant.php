<?php
// ── Hotel: Restaurant / In-room Dining ───────────────────────
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'place_order') {
        $roomId      = (int)($_POST['room_id'] ?? 0) ?: null;
        $guestId     = (int)($_POST['guest_id'] ?? 0) ?: null;
        $orderType   = in_array($_POST['order_type'] ?? '', ['in_room','dine_in','takeaway']) ? $_POST['order_type'] : 'dine_in';
        $paymentMode = sanitize($_POST['payment_mode'] ?? 'cash');
        $notes       = sanitize($_POST['notes'] ?? '');
        $items       = (array)($_POST['items'] ?? []);
        $qtys        = (array)($_POST['qtys'] ?? []);
        $prices      = (array)($_POST['prices'] ?? []);

        $subtotal = 0;
        $lineItems = [];
        foreach ($items as $i => $name) {
            $name = sanitize($name);
            if (!$name) continue;
            $qty   = max(1, (int)($qtys[$i] ?? 1));
            $price = max(0, (float)($prices[$i] ?? 0));
            $total = $qty * $price;
            $subtotal += $total;
            $lineItems[] = [$name, $qty, $price, $total];
        }

        $taxRate  = 0.16;
        $tax      = round($subtotal * $taxRate, 2);
        $grand    = $subtotal + $tax;

        try {
            $pdo->beginTransaction();
            $seq = $pdo->query("SELECT COUNT(*) FROM hotel_restaurant_orders WHERE org_id=$orgId")->fetchColumn() + 1;
            $orderNo = 'ORD-' . date('Ymd') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO hotel_restaurant_orders (org_id, order_no, room_id, guest_id, order_type, total_amount, tax_amount, grand_total, payment_mode, notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $orderNo, $roomId, $guestId, $orderType, $subtotal, $tax, $grand, $paymentMode, $notes]);
            $orderId = $pdo->lastInsertId();
            foreach ($lineItems as [$name, $qty, $price, $total]) {
                $pdo->prepare("INSERT INTO hotel_restaurant_items (order_id, item_name, qty, unit_price, total) VALUES (?,?,?,?,?)")
                    ->execute([$orderId, $name, $qty, $price, $total]);
            }

            // Auto-post to room folio when payment_mode = room_charge
            $folioMsg = '';
            if ($paymentMode === 'room_charge' && $roomId) {
                $bkSt = $pdo->prepare(
                    "SELECT id, guest_id FROM hotel_bookings
                     WHERE org_id=? AND room_id=? AND status IN ('confirmed','checked_in')
                     ORDER BY check_in DESC LIMIT 1"
                );
                $bkSt->execute([$orgId, $roomId]);
                $booking = $bkSt->fetch();

                if ($booking) {
                    $invSt = $pdo->prepare(
                        "SELECT id, restaurant_charges, total_amount FROM hotel_invoices
                         WHERE org_id=? AND booking_id=? AND status IN ('draft','partial')
                         ORDER BY id DESC LIMIT 1"
                    );
                    $invSt->execute([$orgId, $booking['id']]);
                    $inv = $invSt->fetch();

                    if ($inv) {
                        $newRest = (float)$inv['restaurant_charges'] + $grand;
                        $pdo->prepare(
                            "UPDATE hotel_invoices
                             SET restaurant_charges=?, total_amount=total_amount+?, updated_at=NOW()
                             WHERE id=?"
                        )->execute([$newRest, $grand, $inv['id']]);
                        $folioMsg = " — charged to folio HINV#{$inv['id']}";
                    } else {
                        // Create a new draft invoice for this booking
                        $invSeq = (int)$pdo->query("SELECT COUNT(*)+1 FROM hotel_invoices WHERE org_id=$orgId")->fetchColumn();
                        $invNo  = 'HINV-' . date('Y') . '-' . str_pad($invSeq, 4, '0', STR_PAD_LEFT);
                        $pdo->prepare(
                            "INSERT INTO hotel_invoices
                             (org_id, invoice_no, guest_id, booking_id, restaurant_charges, total_amount, status)
                             VALUES (?,?,?,?,?,?,'draft')"
                        )->execute([$orgId, $invNo, $booking['guest_id'], $booking['id'], $grand, $grand]);
                        $folioMsg = " — new folio {$invNo} created";
                    }
                } else {
                    $folioMsg = " — no active booking found for this room";
                }
            }

            $pdo->commit();
            setFlash('success', "Order {$orderNo} placed — Total: " . formatCurrency($grand) . $folioMsg);
            logActivity('create', 'hotel', "Restaurant order {$orderNo}");
        } catch (Exception $e) { $pdo->rollBack(); setFlash('danger', 'Error: ' . $e->getMessage()); }
        redirect('restaurant.php');
    }

    if ($action === 'update_status') {
        $id  = (int)($_POST['id'] ?? 0);
        $st  = sanitize($_POST['status'] ?? '');
        $allowed = ['pending','preparing','served','paid','cancelled'];
        if (in_array($st, $allowed)) {
            $servedAt = $st === 'served' ? ', served_at=NOW()' : '';
            $pdo->prepare("UPDATE hotel_restaurant_orders SET status=?, updated_at=NOW(){$servedAt} WHERE id=? AND org_id=?")
                ->execute([$st, $id, $orgId]);
            setFlash('success', 'Order status updated.');
        }
        redirect('restaurant.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fDate   = $_GET['date'] ?? date('Y-m-d');
$fStatus = $_GET['status'] ?? '';

$where  = 'o.org_id = ?';
$params = [$orgId];
$where .= ' AND DATE(o.ordered_at) = ?'; $params[] = $fDate;
if ($fStatus !== '') { $where .= ' AND o.status = ?'; $params[] = $fStatus; }

$orders = $rooms = $guests = [];
try {
    $stmt = $pdo->prepare("
        SELECT o.*, r.room_no, CONCAT(g.first_name,' ',g.last_name) AS guest_name
        FROM hotel_restaurant_orders o
        LEFT JOIN hotel_rooms r ON r.id = o.room_id
        LEFT JOIN hotel_guests g ON g.id = o.guest_id
        WHERE {$where}
        ORDER BY o.ordered_at DESC
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, room_no FROM hotel_rooms WHERE org_id=? AND status='occupied' ORDER BY room_no");
    $stmt->execute([$orgId]);
    $rooms = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM hotel_guests WHERE org_id=? ORDER BY first_name");
    $stmt->execute([$orgId]);
    $guests = $stmt->fetchAll();
} catch (Exception $e) {}

$todayRevenue = array_sum(array_column(array_filter($orders, fn($o) => $o['status'] === 'paid'), 'grand_total'));
$pending  = count(array_filter($orders, fn($o) => in_array($o['status'], ['pending','preparing'])));
$statusColors = ['pending'=>'secondary','preparing'=>'warning','served'=>'info','paid'=>'success','cancelled'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-utensils me-2" style="color:<?= $moduleColor ?>"></i>Restaurant / Dining</h4>
    <p class="text-muted mb-0">Manage dine-in, in-room and takeaway food orders</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#orderModal">
    <i class="fas fa-plus me-1"></i>New Order
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(211,84,0,.12);color:#d35400"><i class="fas fa-utensils"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($orders) ?></div><div class="stat-label">Today's Orders</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pending ?></div><div class="stat-label">Active</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-cash-register"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($todayRevenue) ?></div><div class="stat-label">Today's Revenue</div></div></div>
  </div>
</div>

<!-- Filter -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Date</label>
      <input type="date" name="date" class="form-control form-control-sm" value="<?= e($fDate) ?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (['pending','preparing','served','paid','cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-auto">
      <button class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="restaurant.php" class="btn btn-sm btn-outline-secondary ms-1">Today</a></div>
  </form>
</div></div>

<div class="card border-0 shadow-sm"><div class="card-body p-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead class="table-light">
        <tr><th>Order #</th><th>Type</th><th>Room/Guest</th><th class="text-end">Total</th><th>Payment</th><th class="text-center">Status</th><th>Time</th><th class="text-center">Update</th></tr>
      </thead>
      <tbody>
        <?php if (empty($orders)): ?>
        <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-utensils fa-3x mb-3 d-block"></i>No orders found.</td></tr>
        <?php else: foreach ($orders as $o): ?>
        <tr>
          <td class="fw-bold"><?= e($o['order_no']) ?></td>
          <td><span class="badge bg-light text-dark border"><?= ucfirst(str_replace('_',' ',$o['order_type'])) ?></span></td>
          <td class="small">
            <?= $o['room_no'] ? 'Room ' . e($o['room_no']) : '' ?>
            <?= $o['guest_name'] ? '<br><span class="text-muted">' . e($o['guest_name']) . '</span>' : '' ?>
            <?= (!$o['room_no'] && !$o['guest_name']) ? '<span class="text-muted">Walk-in</span>' : '' ?>
          </td>
          <td class="text-end fw-bold"><?= formatCurrency((float)$o['grand_total']) ?></td>
          <td class="small">
            <?= ucfirst(str_replace('_',' ',$o['payment_mode'])) ?>
            <?php if ($o['payment_mode'] === 'room_charge'): ?>
            <span class="badge bg-primary ms-1" style="font-size:.65rem" title="Charged to room folio">FOLIO</span>
            <?php endif; ?>
          </td>
          <td class="text-center"><span class="badge bg-<?= $statusColors[$o['status']] ?? 'secondary' ?>"><?= ucfirst($o['status']) ?></span></td>
          <td class="small text-muted"><?= date('h:i A', strtotime($o['ordered_at'])) ?></td>
          <td class="text-center">
            <div class="dropdown">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="fas fa-exchange-alt"></i></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <?php foreach (['preparing','served','paid','cancelled'] as $ns): ?>
                <form method="POST" class="d-block">
                  <?= csrfField() ?><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="status" value="<?= $ns ?>">
                  <li><button type="submit" class="dropdown-item small"><?= ucfirst($ns) ?></button></li>
                </form>
                <?php endforeach; ?>
              </ul>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div></div>

<!-- New Order Modal -->
<div class="modal fade" id="orderModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="place_order">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-utensils me-2"></i>New Order</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Order Type</label>
              <select name="order_type" class="form-select">
                <option value="dine_in">Dine In</option>
                <option value="in_room">In-Room</option>
                <option value="takeaway">Takeaway</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Room (optional)</label>
              <select name="room_id" class="form-select">
                <option value="">-- Walk-in / None --</option>
                <?php foreach ($rooms as $r): ?><option value="<?= $r['id'] ?>">Room <?= e($r['room_no']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Guest (optional)</label>
              <select name="guest_id" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($guests as $g): ?><option value="<?= $g['id'] ?>"><?= e($g['first_name'] . ' ' . $g['last_name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <!-- Item lines -->
          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm" id="itemsTable">
              <thead class="table-light"><tr><th>Item Name</th><th>Qty</th><th>Unit Price</th><th>Total</th><th></th></tr></thead>
              <tbody id="itemsBody">
                <tr>
                  <td><input type="text" name="items[]" class="form-control form-control-sm" placeholder="e.g. Grilled Chicken"></td>
                  <td><input type="number" name="qtys[]" class="form-control form-control-sm item-qty" min="1" value="1" oninput="calcItems()"></td>
                  <td><input type="number" name="prices[]" class="form-control form-control-sm item-price" min="0" step="0.01" value="0" oninput="calcItems()"></td>
                  <td class="item-total fw-semibold">0.00</td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(this)"><i class="fas fa-times"></i></button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary mb-3" onclick="addItem()"><i class="fas fa-plus me-1"></i>Add Item</button>
          <div class="row g-2 justify-content-end mb-2">
            <div class="col-auto"><span class="fw-semibold">Subtotal: <span id="subtotalDisplay">KES 0.00</span></span></div>
            <div class="col-auto"><span class="text-muted">+16% Tax: <span id="taxDisplay">KES 0.00</span></span></div>
            <div class="col-auto"><span class="fw-bold text-success">Total: <span id="grandDisplay">KES 0.00</span></span></div>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="cash">Cash</option>
                <option value="room_charge">Room Charge</option>
                <option value="mpesa">M-Pesa</option>
                <option value="card">Card</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Notes</label>
              <input type="text" name="notes" class="form-control" placeholder="Special requests, allergies…">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-paper-plane me-1"></i>Place Order</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function addItem() {
    const tbody = document.getElementById('itemsBody');
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><input type="text" name="items[]" class="form-control form-control-sm" placeholder="Item name"></td>
        <td><input type="number" name="qtys[]" class="form-control form-control-sm item-qty" min="1" value="1" oninput="calcItems()"></td>
        <td><input type="number" name="prices[]" class="form-control form-control-sm item-price" min="0" step="0.01" value="0" oninput="calcItems()"></td>
        <td class="item-total fw-semibold">0.00</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(this)"><i class="fas fa-times"></i></button></td>`;
    tbody.appendChild(row);
}
function removeItem(btn) {
    btn.closest('tr').remove();
    calcItems();
}
function calcItems() {
    let sub = 0;
    const qtys   = document.querySelectorAll('.item-qty');
    const prices = document.querySelectorAll('.item-price');
    const totals = document.querySelectorAll('.item-total');
    qtys.forEach((q, i) => {
        const t = (parseFloat(q.value)||0) * (parseFloat(prices[i].value)||0);
        totals[i].textContent = t.toFixed(2);
        sub += t;
    });
    const tax   = sub * 0.16;
    const grand = sub + tax;
    document.getElementById('subtotalDisplay').textContent = 'KES ' + sub.toFixed(2);
    document.getElementById('taxDisplay').textContent      = 'KES ' + tax.toFixed(2);
    document.getElementById('grandDisplay').textContent    = 'KES ' + grand.toFixed(2);
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
