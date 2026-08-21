<?php
// ── TOUR: Trip Costs — supplier services bought in + direct expenses ──
require_once __DIR__ . '/_nav.php';

$SERVICE_TYPES = ['accommodation','flight','transport','activity','meals','guide','permit','insurance','visa','other'];
$EXPENSE_CATS  = ['accommodation','transport','fuel','meals','guide_fees','park_fees','permits','insurance','flights','marketing','other'];
$PAY_MODES     = ['cash','mpesa','bank','card','credit'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/_lib.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    denyIfReadOnly($moduleSlug);
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    // ── Supplier service booking ──────────────────────────────
    if ($action === 'save_service') {
        $id            = (int)($_POST['id']           ?? 0);
        $supplierId    = (int)($_POST['supplier_id']  ?? 0);
        $bookingId     = (int)($_POST['booking_id']   ?? 0) ?: null;
        $departureId   = (int)($_POST['departure_id'] ?? 0) ?: null;
        $serviceType   = sanitize($_POST['service_type'] ?? 'other');
        $description   = sanitize($_POST['description']  ?? '');
        $serviceDate   = ($_POST['service_date'] ?? '') ?: null;
        $pax           = max(1, (int)($_POST['pax']    ?? 1));
        $cost          = (float)($_POST['cost']        ?? 0);
        $amountPaid    = (float)($_POST['amount_paid'] ?? 0);
        $confirmation  = sanitize($_POST['confirmation_no'] ?? '');
        $status        = sanitize($_POST['status']     ?? 'pending');
        $notes         = sanitize($_POST['notes']      ?? '');

        if ($supplierId <= 0 || $description === '' || $cost < 0) {
            setFlash('danger', 'Supplier, description and a valid cost are required.');
            redirect('expenses.php');
        }
        if (!in_array($serviceType, $SERVICE_TYPES, true)) $serviceType = 'other';
        if ($amountPaid > $cost) {
            setFlash('danger', 'Amount paid cannot exceed the agreed cost.');
            redirect('expenses.php');
        }
        if (!assertOrgOwnership('tour_suppliers', $supplierId, $orgId)) {
            setFlash('danger', 'Selected supplier is invalid.');
            redirect('expenses.php');
        }

        if ($id === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO tour_supplier_bookings
                    (org_id, supplier_id, booking_id, departure_id, service_type, description,
                     service_date, pax, cost, amount_paid, confirmation_no, status, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $supplierId, $bookingId, $departureId, $serviceType, $description,
                            $serviceDate, $pax, $cost, $amountPaid, $confirmation, $status, $notes]);
            setFlash('success', 'Supplier service recorded.');
            logActivity('create', 'tour', "Booked supplier service '$description' from supplier #$supplierId");
        } else {
            $stmt = $pdo->prepare("
                UPDATE tour_supplier_bookings
                SET supplier_id=?, booking_id=?, departure_id=?, service_type=?, description=?,
                    service_date=?, pax=?, cost=?, amount_paid=?, confirmation_no=?, status=?, notes=?
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$supplierId, $bookingId, $departureId, $serviceType, $description,
                            $serviceDate, $pax, $cost, $amountPaid, $confirmation, $status, $notes, $id, $orgId]);
            setFlash('success', 'Supplier service updated.');
            logActivity('update', 'tour', "Updated supplier service #$id");
        }
        redirect('expenses.php');
    }

    if ($action === 'delete_service') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM tour_supplier_bookings WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Supplier service removed.');
        logActivity('delete', 'tour', "Deleted supplier service #$id");
        redirect('expenses.php');
    }

    // ── Direct expense ────────────────────────────────────────
    if ($action === 'save_expense') {
        $id          = (int)($_POST['id']           ?? 0);
        $bookingId   = (int)($_POST['booking_id']   ?? 0) ?: null;
        $departureId = (int)($_POST['departure_id'] ?? 0) ?: null;
        $supplierId  = (int)($_POST['supplier_id']  ?? 0) ?: null;
        $category    = sanitize($_POST['category']    ?? 'other');
        $description = sanitize($_POST['description'] ?? '');
        $expenseDate = $_POST['expense_date']         ?? '';
        $amount      = (float)($_POST['amount']       ?? 0);
        $paymentMode = sanitize($_POST['payment_mode']?? 'cash');
        $reference   = sanitize($_POST['reference']   ?? '');
        $notes       = sanitize($_POST['notes']       ?? '');

        if ($description === '' || $expenseDate === '' || $amount <= 0) {
            setFlash('danger', 'Description, date and a positive amount are required.');
            redirect('expenses.php?tab=expenses');
        }
        if (!in_array($category, $EXPENSE_CATS, true))  $category    = 'other';
        if (!in_array($paymentMode, $PAY_MODES, true))  $paymentMode = 'cash';

        if ($id === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO tour_expenses
                    (org_id, booking_id, departure_id, supplier_id, category, description,
                     expense_date, amount, payment_mode, reference, recorded_by, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $bookingId, $departureId, $supplierId, $category, $description,
                            $expenseDate, $amount, $paymentMode, $reference, (int)$user['id'], $notes]);
            setFlash('success', 'Expense logged.');
            logActivity('create', 'tour', "Logged trip expense '$description' (" . formatCurrency($amount) . ')');
        } else {
            $stmt = $pdo->prepare("
                UPDATE tour_expenses
                SET booking_id=?, departure_id=?, supplier_id=?, category=?, description=?,
                    expense_date=?, amount=?, payment_mode=?, reference=?, notes=?
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$bookingId, $departureId, $supplierId, $category, $description,
                            $expenseDate, $amount, $paymentMode, $reference, $notes, $id, $orgId]);
            setFlash('success', 'Expense updated.');
            logActivity('update', 'tour', "Updated trip expense #$id");
        }
        redirect('expenses.php?tab=expenses');
    }

    if ($action === 'delete_expense') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM tour_expenses WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Expense deleted.');
        logActivity('delete', 'tour', "Deleted trip expense #$id");
        redirect('expenses.php?tab=expenses');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
require_once __DIR__ . '/_lib.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$activeTab     = ($_GET['tab'] ?? 'services') === 'expenses' ? 'expenses' : 'services';
$filterSupplier = (int)($_GET['supplier_id'] ?? 0);

// ── Supplier services ─────────────────────────────────────────
$svcWhere  = 'sb.org_id = ?';
$svcParams = [$orgId];
if ($filterSupplier > 0) { $svcWhere .= ' AND sb.supplier_id = ?'; $svcParams[] = $filterSupplier; }

$services = [];
try {
    $stmt = $pdo->prepare("
        SELECT sb.*, s.name AS supplier_name, s.supplier_type,
               b.booking_no, b.customer_name,
               d.departure_code, p.name AS package_name
        FROM tour_supplier_bookings sb
        JOIN tour_suppliers s        ON s.id = sb.supplier_id
        LEFT JOIN tour_bookings b    ON b.id = sb.booking_id
        LEFT JOIN tour_departures d  ON d.id = sb.departure_id
        LEFT JOIN tour_packages p    ON p.id = d.package_id
        WHERE $svcWhere
        ORDER BY sb.service_date DESC, sb.id DESC
    ");
    $stmt->execute($svcParams);
    $services = $stmt->fetchAll();
} catch (Throwable $e) {}

// ── Direct expenses ───────────────────────────────────────────
$expenses = [];
try {
    $stmt = $pdo->prepare("
        SELECT ex.*, s.name AS supplier_name, b.booking_no, b.customer_name,
               d.departure_code, u.name AS recorded_by_name
        FROM tour_expenses ex
        LEFT JOIN tour_suppliers s   ON s.id = ex.supplier_id
        LEFT JOIN tour_bookings b    ON b.id = ex.booking_id
        LEFT JOIN tour_departures d  ON d.id = ex.departure_id
        LEFT JOIN users u            ON u.id = ex.recorded_by
        WHERE ex.org_id = ?
        ORDER BY ex.expense_date DESC, ex.id DESC
    ");
    $stmt->execute([$orgId]);
    $expenses = $stmt->fetchAll();
} catch (Throwable $e) {}

// ── Selectors ─────────────────────────────────────────────────
$suppliers = $bookings = $departures = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, supplier_type FROM tour_suppliers WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]);
    $suppliers = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT b.id, b.booking_no, b.customer_name, b.travel_date
        FROM tour_bookings b WHERE b.org_id=? AND b.status <> 'cancelled'
        ORDER BY b.travel_date DESC LIMIT 300
    ");
    $stmt->execute([$orgId]);
    $bookings = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT d.id, d.departure_code, d.start_date, p.name AS package_name
        FROM tour_departures d JOIN tour_packages p ON p.id = d.package_id
        WHERE d.org_id=? AND d.status <> 'cancelled'
        ORDER BY d.start_date DESC LIMIT 300
    ");
    $stmt->execute([$orgId]);
    $departures = $stmt->fetchAll();
} catch (Throwable $e) {}

// ── Totals ────────────────────────────────────────────────────
$svcCommitted = $svcSettled = 0.0;
foreach ($services as $s) {
    if ($s['status'] === 'cancelled') continue;
    $svcCommitted += (float)$s['cost'];
    $svcSettled   += (float)$s['amount_paid'];
}
$expTotal      = array_sum(array_map(fn($x) => (float)$x['amount'], $expenses));
$expThisMonth  = 0.0;
foreach ($expenses as $x) {
    if (substr((string)$x['expense_date'], 0, 7) === date('Y-m')) $expThisMonth += (float)$x['amount'];
}
$grandCost = $svcCommitted + $expTotal;

$typeIcons = [
    'accommodation'=>'fa-bed','flight'=>'fa-plane','transport'=>'fa-van-shuttle','activity'=>'fa-person-hiking',
    'meals'=>'fa-utensils','guide'=>'fa-user-tie','permit'=>'fa-stamp','insurance'=>'fa-shield-heart',
    'visa'=>'fa-passport','other'=>'fa-ellipsis',
];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-receipt me-2" style="color:<?= $moduleColor ?>"></i>Trip Costs</h4>
    <p class="text-muted mb-0">Everything you spend to deliver a trip — services bought from suppliers and direct expenses</p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-secondary" onclick="openExpense()"><i class="fas fa-plus me-1"></i>Log Expense</button>
    <button class="btn text-white" style="background:<?= $moduleColor ?>" onclick="openService()">
      <i class="fas fa-cart-plus me-1"></i>Book Supplier Service
    </button>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($grandCost) ?></div><div class="stat-label">Total Cost of Sales</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,.15);color:#2980b9"><i class="fas fa-file-contract"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($svcCommitted) ?></div><div class="stat-label">Committed To Suppliers</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hand-holding-dollar"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency(max(0, $svcCommitted - $svcSettled)) ?></div><div class="stat-label">Still Owed To Suppliers</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-calendar-day"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($expThisMonth) ?></div><div class="stat-label">Expenses This Month</div></div>
    </div>
  </div>
</div>

<ul class="nav nav-tabs mb-0" id="costTabs">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'services' ? 'active' : '' ?>" href="expenses.php?tab=services">
      <i class="fas fa-handshake me-1"></i>Supplier Services <span class="badge bg-secondary ms-1"><?= count($services) ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'expenses' ? 'active' : '' ?>" href="expenses.php?tab=expenses">
      <i class="fas fa-receipt me-1"></i>Direct Expenses <span class="badge bg-secondary ms-1"><?= count($expenses) ?></span>
    </a>
  </li>
</ul>

<?php if ($activeTab === 'services'): ?>
<!-- ── Supplier services ─────────────────────────────────────── -->
<div class="card border-0 shadow-sm" style="border-top-left-radius:0">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="servicesTable">
        <thead class="table-light">
          <tr>
            <th>Service</th>
            <th>Supplier</th>
            <th>Applied To</th>
            <th>Date</th>
            <th class="text-center">Pax</th>
            <th class="text-end">Cost</th>
            <th class="text-end">Balance</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($services)): ?>
          <tr><td colspan="9" class="text-center py-5 text-muted"><i class="fas fa-cart-shopping fa-3x mb-3 d-block"></i>No supplier services booked yet.</td></tr>
          <?php else: foreach ($services as $s):
              $bal = (float)$s['cost'] - (float)$s['amount_paid'];
          ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark">
                <i class="fas <?= $typeIcons[$s['service_type']] ?? 'fa-ellipsis' ?> me-1 text-muted"></i><?= e($s['description']) ?>
              </div>
              <div class="small text-muted">
                <?= tourLabel($s['service_type']) ?>
                <?= $s['confirmation_no'] ? ' · conf ' . e($s['confirmation_no']) : '' ?>
              </div>
            </td>
            <td><?= e($s['supplier_name']) ?></td>
            <td class="small">
              <?php if ($s['booking_no']): ?>
                <div><code class="bg-light px-1 rounded"><?= e($s['booking_no']) ?></code></div>
                <div class="text-muted"><?= e($s['customer_name']) ?></div>
              <?php elseif ($s['departure_code']): ?>
                <div><code class="bg-light px-1 rounded"><?= e($s['departure_code']) ?></code></div>
                <div class="text-muted"><?= e($s['package_name'] ?: '—') ?></div>
              <?php else: ?>
                <span class="text-muted">General overhead</span>
              <?php endif; ?>
            </td>
            <td><?= $s['service_date'] ? formatDate($s['service_date']) : '—' ?></td>
            <td class="text-center"><?= (int)$s['pax'] ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$s['cost']) ?></td>
            <td class="text-end fw-bold <?= $bal > 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency(max(0, $bal)) ?></td>
            <td><?= statusBadge($s['status']) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick="editService(<?= e(json_encode($s)) ?>)" title="Edit"><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this supplier service?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_service">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── Direct expenses ───────────────────────────────────────── -->
<div class="card border-0 shadow-sm" style="border-top-left-radius:0">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="expensesTable">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Description</th>
            <th>Category</th>
            <th>Applied To</th>
            <th>Paid Via</th>
            <th>Logged By</th>
            <th class="text-end">Amount</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($expenses)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-receipt fa-3x mb-3 d-block"></i>No expenses logged yet.</td></tr>
          <?php else: foreach ($expenses as $x): ?>
          <tr>
            <td><?= formatDate($x['expense_date']) ?></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($x['description']) ?></div>
              <?php if (!empty($x['supplier_name'])): ?>
              <div class="small text-muted"><i class="fas fa-handshake me-1"></i><?= e($x['supplier_name']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-light text-dark border"><?= tourLabel($x['category']) ?></span></td>
            <td class="small">
              <?php if ($x['booking_no']): ?>
                <code class="bg-light px-1 rounded"><?= e($x['booking_no']) ?></code>
              <?php elseif ($x['departure_code']): ?>
                <code class="bg-light px-1 rounded"><?= e($x['departure_code']) ?></code>
              <?php else: ?>
                <span class="text-muted">General overhead</span>
              <?php endif; ?>
            </td>
            <td class="small">
              <?= tourLabel($x['payment_mode']) ?>
              <?= $x['reference'] ? '<div class="text-muted">' . e($x['reference']) . '</div>' : '' ?>
            </td>
            <td class="small text-muted"><?= e($x['recorded_by_name'] ?: '—') ?></td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$x['amount']) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick="editExpense(<?= e(json_encode($x)) ?>)" title="Edit"><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this expense?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_expense">
                <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Supplier Service Modal ────────────────────────────────── -->
<div class="modal fade" id="serviceModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_service">
        <input type="hidden" name="id" id="svcId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="svcTitle"><i class="fas fa-cart-plus me-2"></i>Book Supplier Service</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
              <select name="supplier_id" id="svcSupplier" class="form-select" required>
                <option value="">-- Select Supplier --</option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= e($s['name'] . ' — ' . tourLabel($s['supplier_type'])) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (empty($suppliers)): ?>
              <div class="form-text text-danger">No active suppliers yet — <a href="suppliers.php">add one first</a>.</div>
              <?php endif; ?>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Service Type</label>
              <select name="service_type" id="svcType" class="form-select">
                <?php foreach ($SERVICE_TYPES as $t): ?>
                <option value="<?= $t ?>"><?= tourLabel($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Service Date</label>
              <input type="date" name="service_date" id="svcDate" class="form-control">
            </div>

            <div class="col-md-12">
              <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
              <input type="text" name="description" id="svcDesc" class="form-control" required placeholder="e.g. 2 nights half board, 3 twin rooms">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Charge To Booking</label>
              <select name="booking_id" id="svcBooking" class="form-select">
                <option value="">-- Not booking-specific --</option>
                <?php foreach ($bookings as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= e($b['booking_no'] . ' — ' . $b['customer_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Or Charge To Departure</label>
              <select name="departure_id" id="svcDeparture" class="form-select">
                <option value="">-- Not departure-specific --</option>
                <?php foreach ($departures as $d): ?>
                <option value="<?= (int)$d['id'] ?>"><?= e($d['departure_code'] . ' — ' . $d['package_name'] . ' (' . formatDate($d['start_date']) . ')') ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Leave both blank for general overhead.</div>
            </div>

            <div class="col-md-3">
              <label class="form-label fw-semibold">Pax Covered</label>
              <input type="number" name="pax" id="svcPax" class="form-control" min="1" value="1">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Agreed Cost (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" min="0" name="cost" id="svcCost" class="form-control" required value="0.00">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Amount Paid (<?= CURRENCY ?>)</label>
              <input type="number" step="0.01" min="0" name="amount_paid" id="svcPaid" class="form-control" value="0.00">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="svcStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="confirmed">Confirmed</option>
                <option value="paid">Paid</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Supplier Confirmation No.</label>
              <input type="text" name="confirmation_no" id="svcConf" class="form-control" placeholder="e.g. RES-99213">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Notes</label>
              <input type="text" name="notes" id="svcNotes" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Service</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Direct Expense Modal ──────────────────────────────────── -->
<div class="modal fade" id="expenseModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_expense">
        <input type="hidden" name="id" id="expId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="expTitle"><i class="fas fa-plus me-2"></i>Log Expense</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
              <input type="text" name="description" id="expDesc" class="form-control" required placeholder="e.g. Park entry fees — Maasai Mara">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
              <input type="date" name="expense_date" id="expDate" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Category</label>
              <select name="category" id="expCat" class="form-select">
                <?php foreach ($EXPENSE_CATS as $c): ?>
                <option value="<?= $c ?>"><?= tourLabel($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Amount (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" min="0.01" name="amount" id="expAmount" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Paid Via</label>
              <select name="payment_mode" id="expMode" class="form-select">
                <?php foreach ($PAY_MODES as $m): ?>
                <option value="<?= $m ?>"><?= tourLabel($m) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Charge To Booking</label>
              <select name="booking_id" id="expBooking" class="form-select">
                <option value="">-- Not booking-specific --</option>
                <?php foreach ($bookings as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= e($b['booking_no'] . ' — ' . $b['customer_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Or Charge To Departure</label>
              <select name="departure_id" id="expDeparture" class="form-select">
                <option value="">-- Not departure-specific --</option>
                <?php foreach ($departures as $d): ?>
                <option value="<?= (int)$d['id'] ?>"><?= e($d['departure_code'] . ' — ' . $d['package_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Supplier (optional)</label>
              <select name="supplier_id" id="expSupplier" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Receipt / Reference</label>
              <input type="text" name="reference" id="expRef" class="form-control" placeholder="e.g. RCT-0091 or M-Pesa code">
            </div>

            <div class="col-md-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="expNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
let svcModal, expModal;

$(document).ready(function(){
  if ($("#servicesTable").length) $("#servicesTable").DataTable({pageLength:15, order:[[3,"desc"]]});
  if ($("#expensesTable").length) $("#expensesTable").DataTable({pageLength:15, order:[[0,"desc"]]});
  svcModal = new bootstrap.Modal(document.getElementById("serviceModal"));
  expModal = new bootstrap.Modal(document.getElementById("expenseModal"));
});

function openService() {
  $("#svcTitle").html("<i class=\"fas fa-cart-plus me-2\"></i>Book Supplier Service");
  $("#svcId,#svcSupplier,#svcDate,#svcDesc,#svcBooking,#svcDeparture,#svcConf,#svcNotes").val("");
  $("#svcType").val("other");
  $("#svcPax").val("1");
  $("#svcCost").val("0.00");
  $("#svcPaid").val("0.00");
  $("#svcStatus").val("pending");
  svcModal.show();
}

function editService(s) {
  $("#svcTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Supplier Service");
  $("#svcId").val(s.id);
  $("#svcSupplier").val(s.supplier_id || "");
  $("#svcType").val(s.service_type || "other");
  $("#svcDate").val(s.service_date || "");
  $("#svcDesc").val(s.description || "");
  $("#svcBooking").val(s.booking_id || "");
  $("#svcDeparture").val(s.departure_id || "");
  $("#svcPax").val(s.pax || 1);
  $("#svcCost").val(s.cost || "0.00");
  $("#svcPaid").val(s.amount_paid || "0.00");
  $("#svcStatus").val(s.status || "pending");
  $("#svcConf").val(s.confirmation_no || "");
  $("#svcNotes").val(s.notes || "");
  svcModal.show();
}

function openExpense() {
  $("#expTitle").html("<i class=\"fas fa-plus me-2\"></i>Log Expense");
  $("#expId,#expDesc,#expAmount,#expBooking,#expDeparture,#expSupplier,#expRef,#expNotes").val("");
  $("#expDate").val("' . date('Y-m-d') . '");
  $("#expCat").val("other");
  $("#expMode").val("cash");
  expModal.show();
}

function editExpense(x) {
  $("#expTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Expense");
  $("#expId").val(x.id);
  $("#expDesc").val(x.description || "");
  $("#expDate").val(x.expense_date || "");
  $("#expCat").val(x.category || "other");
  $("#expAmount").val(x.amount || "");
  $("#expMode").val(x.payment_mode || "cash");
  $("#expBooking").val(x.booking_id || "");
  $("#expDeparture").val(x.departure_id || "");
  $("#expSupplier").val(x.supplier_id || "");
  $("#expRef").val(x.reference || "");
  $("#expNotes").val(x.notes || "");
  expModal.show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
