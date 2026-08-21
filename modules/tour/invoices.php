<?php
// ── TOUR: Invoices — bill a booking and track the balance to travel date ──
require_once __DIR__ . '/_nav.php';

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

    if ($action === 'save') {
        $id            = (int)($_POST['id'] ?? 0);
        $bookingId     = (int)($_POST['booking_id'] ?? 0) ?: null;
        $customerName  = sanitize($_POST['customer_name']  ?? '');
        $customerPhone = sanitize($_POST['customer_phone'] ?? '');
        $customerEmail = sanitize($_POST['customer_email'] ?? '');
        $issueDate     = $_POST['issue_date'] ?? date('Y-m-d');
        $dueDate       = ($_POST['due_date'] ?? '') ?: null;
        $discount      = max(0, (float)($_POST['discount'] ?? 0));
        $taxRate       = max(0, (float)($_POST['tax_rate'] ?? 0));
        $status        = sanitize($_POST['status'] ?? 'draft');
        $notes         = sanitize($_POST['notes'] ?? '');
        $terms         = sanitize($_POST['terms'] ?? '');

        if ($customerName === '') {
            setFlash('danger', 'A billing name is required.');
            redirect('invoices.php');
        }
        if ($dueDate && $dueDate < $issueDate) {
            setFlash('danger', 'The due date cannot fall before the issue date.');
            redirect('invoices.php');
        }

        // Pull the customer id off the booking so portal + statements line up
        $customerId = null;
        if ($bookingId) {
            $bk = $pdo->prepare("SELECT customer_id FROM tour_bookings WHERE id=? AND org_id=? LIMIT 1");
            $bk->execute([$bookingId, $orgId]);
            $row = $bk->fetch();
            if (!$row) {
                setFlash('danger', 'The selected booking is invalid.');
                redirect('invoices.php');
            }
            $customerId = $row['customer_id'] ?: null;
        }

        $descs  = $_POST['item_desc']  ?? [];
        $qtys   = $_POST['item_qty']   ?? [];
        $prices = $_POST['item_price'] ?? [];
        $items    = [];
        $subtotal = 0.0;
        foreach ($descs as $i => $desc) {
            $desc = sanitize((string)$desc);
            if ($desc === '') continue;
            $qty   = max(0, (float)($qtys[$i]   ?? 0));
            $price = max(0, (float)($prices[$i] ?? 0));
            $line  = round($qty * $price, 2);
            $subtotal += $line;
            $items[] = ['desc' => $desc, 'qty' => $qty, 'price' => $price, 'line' => $line];
        }
        if (empty($items)) {
            setFlash('danger', 'Add at least one billable line to the invoice.');
            redirect('invoices.php');
        }
        if ($discount > $subtotal) {
            setFlash('danger', 'Discount cannot exceed the invoice subtotal.');
            redirect('invoices.php');
        }

        $taxable   = $subtotal - $discount;
        $taxAmount = round($taxable * ($taxRate / 100), 2);
        $total     = round($taxable + $taxAmount, 2);

        try {
            $pdo->beginTransaction();

            if ($id === 0) {
                $invoiceNo = tourNextNumber($orgId, 'tour_invoices', 'invoice_no', tourConf($orgId, 't_invoice_prefix'));
                $stmt = $pdo->prepare("
                    INSERT INTO tour_invoices
                        (org_id, invoice_no, booking_id, customer_id, customer_name, customer_phone, customer_email,
                         issue_date, due_date, subtotal, discount, tax_rate, tax_amount, total_amount,
                         status, notes, terms, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([$orgId, $invoiceNo, $bookingId, $customerId, $customerName, $customerPhone, $customerEmail,
                                $issueDate, $dueDate, $subtotal, $discount, $taxRate, $taxAmount, $total,
                                $status, $notes, $terms, (int)$user['id']]);
                $id  = (int)$pdo->lastInsertId();
                $msg = 'Invoice ' . $invoiceNo . ' created.';
                logActivity('create', 'tour', "Raised invoice $invoiceNo for $customerName");
            } else {
                $stmt = $pdo->prepare("
                    UPDATE tour_invoices
                    SET booking_id=?, customer_id=?, customer_name=?, customer_phone=?, customer_email=?,
                        issue_date=?, due_date=?, subtotal=?, discount=?, tax_rate=?, tax_amount=?, total_amount=?,
                        status=?, notes=?, terms=?, updated_at=NOW()
                    WHERE id=? AND org_id=?
                ");
                $stmt->execute([$bookingId, $customerId, $customerName, $customerPhone, $customerEmail,
                                $issueDate, $dueDate, $subtotal, $discount, $taxRate, $taxAmount, $total,
                                $status, $notes, $terms, $id, $orgId]);
                $msg = 'Invoice updated.';
                logActivity('update', 'tour', "Updated invoice #$id");
            }

            $pdo->prepare("DELETE FROM tour_invoice_items WHERE invoice_id=? AND org_id=?")->execute([$id, $orgId]);
            $ins = $pdo->prepare("
                INSERT INTO tour_invoice_items (org_id, invoice_id, description, quantity, unit_price, line_total, sort_order)
                VALUES (?,?,?,?,?,?,?)
            ");
            foreach ($items as $sort => $it) {
                $ins->execute([$orgId, $id, $it['desc'], $it['qty'], $it['price'], $it['line'], $sort]);
            }

            $pdo->commit();
            tourSyncInvoice($orgId, $id);   // reconcile against receipted payments
            setFlash('success', $msg);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setFlash('danger', 'Could not save the invoice. Please try again.');
        }
        redirect('invoices.php');
    }

    if ($action === 'set_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = sanitize($_POST['status'] ?? '');
        if (!in_array($status, ['draft','sent','cancelled'], true)) {
            setFlash('danger', 'Paid and partial statuses are derived from receipted payments, not set by hand.');
            redirect('invoices.php');
        }
        $pdo->prepare("UPDATE tour_invoices SET status=?, updated_at=NOW() WHERE id=? AND org_id=?")
            ->execute([$status, $id, $orgId]);
        if ($status === 'sent') tourSyncInvoice($orgId, $id);
        setFlash('success', 'Invoice marked as ' . $status . '.');
        logActivity('update', 'tour', "Set invoice #$id to $status");
        redirect('invoices.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM tour_invoice_items WHERE invoice_id=? AND org_id=?")->execute([$id, $orgId]);
        $pdo->prepare("DELETE FROM tour_invoices WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Invoice deleted.');
        logActivity('delete', 'tour', "Deleted invoice #$id");
        redirect('invoices.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
require_once __DIR__ . '/_lib.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Keep paid/partial/overdue in step with receipted payments
try {
    $sync = $pdo->prepare("SELECT id FROM tour_invoices WHERE org_id=? AND status NOT IN ('draft','cancelled')");
    $sync->execute([$orgId]);
    foreach ($sync->fetchAll(PDO::FETCH_COLUMN) as $invId) {
        tourSyncInvoice($orgId, (int)$invId);
    }
} catch (Throwable $e) {}

$filterStatus = sanitize($_GET['status'] ?? '');
$where  = 'i.org_id = ?';
$params = [$orgId];
if (in_array($filterStatus, ['draft','sent','partial','paid','overdue','cancelled'], true)) {
    $where .= ' AND i.status = ?';
    $params[] = $filterStatus;
}

$invoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.*, b.booking_no, b.travel_date, p.name AS package_name
        FROM tour_invoices i
        LEFT JOIN tour_bookings b ON b.id = i.booking_id
        LEFT JOIN tour_packages p ON p.id = b.package_id
        WHERE $where
        ORDER BY i.issue_date DESC, i.id DESC
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
} catch (Throwable $e) {}

$itemsByInvoice = [];
if ($invoices) {
    try {
        $ids = array_column($invoices, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM tour_invoice_items WHERE org_id=? AND invoice_id IN ($in) ORDER BY sort_order");
        $stmt->execute(array_merge([$orgId], $ids));
        foreach ($stmt->fetchAll() as $it) {
            $itemsByInvoice[$it['invoice_id']][] = [
                'description' => $it['description'],
                'quantity'    => (float)$it['quantity'],
                'unit_price'  => (float)$it['unit_price'],
            ];
        }
    } catch (Throwable $e) {}
}

// Bookings available to bill, with their package pricing for prefill
$bookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.booking_no, b.customer_name, b.customer_phone, b.customer_email,
               b.adults, b.children, b.total_amount, b.travel_date,
               p.name AS package_name, p.price_per_adult, p.price_per_child,
               d.price_adult, d.price_child
        FROM tour_bookings b
        LEFT JOIN tour_packages p   ON p.id = b.package_id
        LEFT JOIN tour_departures d ON d.id = b.departure_id
        WHERE b.org_id=? AND b.status <> 'cancelled'
        ORDER BY b.travel_date DESC
        LIMIT 300
    ");
    $stmt->execute([$orgId]);
    $bookings = $stmt->fetchAll();
} catch (Throwable $e) {}

$bookingBook = [];
foreach ($bookings as $b) {
    $bookingBook[$b['id']] = [
        'name'     => $b['customer_name'],
        'phone'    => $b['customer_phone'],
        'email'    => $b['customer_email'],
        'adults'   => (int)$b['adults'],
        'children' => (int)$b['children'],
        'package'  => $b['package_name'] ?: 'Tour package',
        'adult'    => $b['price_adult'] !== null ? (float)$b['price_adult'] : (float)$b['price_per_adult'],
        'child'    => $b['price_child'] !== null ? (float)$b['price_child'] : (float)$b['price_per_child'],
        'travel'   => $b['travel_date'],
    ];
}

// Headline figures
$billedTotal = $collected = $outstanding = $overdueAmt = 0.0;
foreach ($invoices as $i) {
    if ($i['status'] === 'cancelled') continue;
    $billedTotal += (float)$i['total_amount'];
    $collected   += (float)$i['amount_paid'];
    $bal = (float)$i['total_amount'] - (float)$i['amount_paid'];
    if ($bal > 0) {
        $outstanding += $bal;
        if ($i['status'] === 'overdue') $overdueAmt += $bal;
    }
}

$defaultDueDays = (int)tourConf($orgId, 't_invoice_due_days');
$defaultTerms   = tourConf($orgId, 't_invoice_terms');
$defaultTaxRate = (float)tourConf($orgId, 't_tax_rate');
$taxLabel       = tourConf($orgId, 't_tax_label');
$depositPercent = (float)tourConf($orgId, 't_deposit_percent');
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Invoices</h4>
    <p class="text-muted mb-0">Bill confirmed bookings and watch the balance close as receipts come in</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" onclick="openInvoice()">
    <i class="fas fa-plus me-1"></i>New Invoice
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($billedTotal) ?></div><div class="stat-label">Total Billed</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-circle-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($collected) ?></div><div class="stat-label">Collected</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($outstanding) ?></div><div class="stat-label">Outstanding</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(231,76,60,.15);color:#e74c3c"><i class="fas fa-triangle-exclamation"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($overdueAmt) ?></div><div class="stat-label">Overdue</div></div>
    </div>
  </div>
</div>

<div class="mb-3 d-flex flex-wrap gap-2">
  <a href="invoices.php" class="btn btn-sm <?= $filterStatus === '' ? 'text-white' : 'btn-outline-secondary' ?>"
     style="<?= $filterStatus === '' ? 'background:' . $moduleColor : '' ?>">All</a>
  <?php foreach (['draft','sent','partial','paid','overdue','cancelled'] as $st): ?>
  <a href="invoices.php?status=<?= $st ?>" class="btn btn-sm <?= $filterStatus === $st ? 'text-white' : 'btn-outline-secondary' ?>"
     style="<?= $filterStatus === $st ? 'background:' . $moduleColor : '' ?>"><?= tourLabel($st) ?></a>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="invoicesTable">
        <thead class="table-light">
          <tr>
            <th>Invoice #</th>
            <th>Billed To</th>
            <th>Booking</th>
            <th>Issued</th>
            <th>Due</th>
            <th class="text-end">Total</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Balance</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($invoices)): ?>
          <tr><td colspan="10" class="text-center py-5 text-muted"><i class="fas fa-file-invoice fa-3x mb-3 d-block"></i>No invoices raised yet.</td></tr>
          <?php else: foreach ($invoices as $i):
              $bal = (float)$i['total_amount'] - (float)$i['amount_paid'];
          ?>
          <tr>
            <td><code class="text-dark bg-light px-2 py-1 rounded"><?= e($i['invoice_no']) ?></code></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($i['customer_name']) ?></div>
              <div class="small text-muted"><?= e($i['customer_phone'] ?: $i['customer_email'] ?: '—') ?></div>
            </td>
            <td class="small">
              <?php if ($i['booking_no']): ?>
                <code class="bg-light px-1 rounded"><?= e($i['booking_no']) ?></code>
                <div class="text-muted"><?= e($i['package_name'] ?: '—') ?></div>
              <?php else: ?>
                <span class="text-muted">Standalone</span>
              <?php endif; ?>
            </td>
            <td><?= formatDate($i['issue_date']) ?></td>
            <td class="<?= ($bal > 0 && $i['due_date'] && $i['due_date'] < date('Y-m-d')) ? 'text-danger fw-semibold' : '' ?>">
              <?= $i['due_date'] ? formatDate($i['due_date']) : '—' ?>
            </td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$i['total_amount']) ?></td>
            <td class="text-end text-success"><?= formatCurrency((float)$i['amount_paid']) ?></td>
            <td class="text-end fw-bold <?= $bal > 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency(max(0, $bal)) ?></td>
            <td><?= statusBadge($i['status']) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <a href="invoice-pdf.php?id=<?= (int)$i['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Download PDF">
                <i class="fas fa-file-pdf"></i>
              </a>
              <?php if ($i['booking_id']): ?>
              <a href="payments.php?booking_id=<?= (int)$i['booking_id'] ?>" class="btn btn-sm btn-outline-success ms-1" title="Record payment">
                <i class="fas fa-money-bill-wave"></i>
              </a>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-secondary ms-1"
                      onclick='editInvoice(<?= json_encode($i, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($itemsByInvoice[$i["id"]] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <div class="btn-group ms-1">
                <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" title="Status"><i class="fas fa-flag"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <?php foreach (['sent' => 'Mark as Sent', 'draft' => 'Back to Draft', 'cancelled' => 'Cancel Invoice'] as $st => $label): ?>
                  <li>
                    <form method="POST" class="m-0">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="set_status">
                      <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                      <input type="hidden" name="status" value="<?= $st ?>">
                      <button type="submit" class="dropdown-item small"><?= $label ?></button>
                    </form>
                  </li>
                  <?php endforeach; ?>
                </ul>
              </div>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this invoice and its lines?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
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

<!-- ── Invoice Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="iId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="iTitle"><i class="fas fa-plus me-2"></i>New Invoice</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Bill Against Booking</label>
              <select name="booking_id" id="iBooking" class="form-select" onchange="fillFromBooking()">
                <option value="">-- Standalone invoice --</option>
                <?php foreach ($bookings as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= e($b['booking_no'] . ' — ' . $b['customer_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Linking a booking lets receipted payments close this invoice automatically.</div>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Billed To <span class="text-danger">*</span></label>
              <input type="text" name="customer_name" id="iName" class="form-control" required>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="customer_phone" id="iPhone" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="customer_email" id="iEmail" class="form-control">
            </div>

            <div class="col-md-3">
              <label class="form-label fw-semibold">Issue Date <span class="text-danger">*</span></label>
              <input type="date" name="issue_date" id="iIssue" class="form-control" required value="<?= date('Y-m-d') ?>" onchange="autoDueDate()">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Due Date</label>
              <input type="date" name="due_date" id="iDue" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="iStatus" class="form-select">
                <option value="draft">Draft</option>
                <option value="sent">Sent</option>
                <option value="cancelled">Cancelled</option>
              </select>
              <div class="form-text">Paid / partial follow the receipts.</div>
            </div>

            <div class="col-12">
              <div class="d-flex align-items-center justify-content-between mt-2 mb-2">
                <label class="form-label fw-semibold mb-0">Invoice Lines</label>
                <div>
                  <button type="button" class="btn btn-sm btn-outline-primary" onclick="billFullTrip()">
                    <i class="fas fa-wand-magic-sparkles me-1"></i>Bill Full Trip
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-primary ms-1" onclick="billDeposit()">
                    <i class="fas fa-percent me-1"></i>Deposit Only (<?= (int)$depositPercent ?>%)
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="addLine()">
                    <i class="fas fa-plus me-1"></i>Add Line
                  </button>
                </div>
              </div>
              <div class="table-responsive border rounded">
                <table class="table table-sm align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="width:50%">Description</th>
                      <th style="width:12%" class="text-end">Qty</th>
                      <th style="width:18%" class="text-end">Unit Price</th>
                      <th style="width:15%" class="text-end">Line Total</th>
                      <th style="width:5%"></th>
                    </tr>
                  </thead>
                  <tbody id="iLines"></tbody>
                </table>
              </div>
            </div>

            <div class="col-md-7">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="iNotes" class="form-control" rows="2" placeholder="Payment instructions, booking reference, anything the client needs"></textarea>
              <label class="form-label fw-semibold mt-3">Terms</label>
              <textarea name="terms" id="iTerms" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-md-5">
              <div class="border rounded p-3 bg-light">
                <div class="d-flex justify-content-between mb-2">
                  <span class="text-muted">Subtotal</span>
                  <span class="fw-semibold" id="iSubtotal"><?= CURRENCY ?> 0.00</span>
                </div>
                <div class="row g-2 align-items-center mb-2">
                  <div class="col-6"><label class="form-label small mb-0">Discount (<?= CURRENCY ?>)</label></div>
                  <div class="col-6"><input type="number" step="0.01" min="0" name="discount" id="iDiscount" class="form-control form-control-sm text-end" value="0.00" oninput="recalc()"></div>
                </div>
                <div class="row g-2 align-items-center mb-2">
                  <div class="col-6"><label class="form-label small mb-0"><?= e($taxLabel) ?> (%)</label></div>
                  <div class="col-6"><input type="number" step="0.01" min="0" name="tax_rate" id="iTaxRate" class="form-control form-control-sm text-end" value="<?= $defaultTaxRate ?>" oninput="recalc()"></div>
                </div>
                <div class="d-flex justify-content-between mb-2">
                  <span class="text-muted"><?= e($taxLabel) ?> Amount</span>
                  <span id="iTaxAmount"><?= CURRENCY ?> 0.00</span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between">
                  <span class="fw-bold">Total Due</span>
                  <span class="fs-5 fw-bold text-success" id="iTotal"><?= CURRENCY ?> 0.00</span>
                </div>
              </div>
            </div>
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

<?php
$extraJs = '<script>
const bookingBook    = ' . json_encode($bookingBook) . ';
const CUR            = "' . CURRENCY . '";
const defaultDueDays = ' . $defaultDueDays . ';
const defaultTerms   = ' . json_encode($defaultTerms) . ';
const depositPercent = ' . $depositPercent . ';
let invModal;

$(document).ready(function(){
  $("#invoicesTable").DataTable({pageLength:15, order:[[3,"desc"]]});
  invModal = new bootstrap.Modal(document.getElementById("invoiceModal"));
});

function money(n) {
  return CUR + " " + (Number(n) || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,");
}

function lineRow(desc, qty, price) {
  return `<tr>
    <td><input type="text" name="item_desc[]" class="form-control form-control-sm" value="${desc || ""}" placeholder="e.g. Masai Mara 3-day safari, adult"></td>
    <td><input type="number" step="0.01" min="0" name="item_qty[]" class="form-control form-control-sm text-end line-qty" value="${qty != null ? qty : 1}" oninput="recalc()"></td>
    <td><input type="number" step="0.01" min="0" name="item_price[]" class="form-control form-control-sm text-end line-price" value="${price != null ? price : 0}" oninput="recalc()"></td>
    <td class="text-end fw-semibold line-total">${money((qty || 0) * (price || 0))}</td>
    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="$(this).closest(\'tr\').remove();recalc()"><i class="fas fa-times"></i></button></td>
  </tr>`;
}

function addLine(desc, qty, price) { $("#iLines").append(lineRow(desc, qty, price)); recalc(); }

function setLines(items) {
  $("#iLines").empty();
  if (!items || !items.length) { addLine("", 1, 0); return; }
  items.forEach(function(it){ $("#iLines").append(lineRow(it.description, it.quantity, it.unit_price)); });
  recalc();
}

function recalc() {
  let subtotal = 0;
  $("#iLines tr").each(function(){
    const qty   = parseFloat($(this).find(".line-qty").val())   || 0;
    const price = parseFloat($(this).find(".line-price").val()) || 0;
    const line  = qty * price;
    subtotal += line;
    $(this).find(".line-total").text(money(line));
  });
  const discount = parseFloat($("#iDiscount").val()) || 0;
  const taxRate  = parseFloat($("#iTaxRate").val())  || 0;
  const taxable  = Math.max(0, subtotal - discount);
  const tax      = taxable * (taxRate / 100);

  $("#iSubtotal").text(money(subtotal));
  $("#iTaxAmount").text(money(tax));
  $("#iTotal").text(money(taxable + tax));
}

function autoDueDate() {
  const issue = $("#iIssue").val();
  if (!issue) return;
  const d = new Date(issue + "T00:00:00");
  d.setDate(d.getDate() + defaultDueDays);
  $("#iDue").val(d.toISOString().slice(0, 10));
}

function fillFromBooking() {
  const b = bookingBook[$("#iBooking").val()];
  if (!b) return;
  $("#iName").val(b.name || "");
  $("#iPhone").val(b.phone || "");
  $("#iEmail").val(b.email || "");
}

/* Rebuild the lines as a full-price trip charge */
function billFullTrip() {
  const b = bookingBook[$("#iBooking").val()];
  if (!b) { alert("Select a booking first."); return; }
  $("#iLines").empty();
  if (b.adults > 0)   $("#iLines").append(lineRow(b.package + " — adult", b.adults, b.adult));
  if (b.children > 0) $("#iLines").append(lineRow(b.package + " — child", b.children, b.child));
  if (!b.adults && !b.children) $("#iLines").append(lineRow(b.package, 1, b.adult));
  recalc();
}

/* Single deposit line at the configured percentage of the full trip price */
function billDeposit() {
  const b = bookingBook[$("#iBooking").val()];
  if (!b) { alert("Select a booking first."); return; }
  const full = (b.adults * b.adult) + (b.children * b.child);
  const dep  = Math.round(full * (depositPercent / 100) * 100) / 100;
  $("#iLines").empty();
  $("#iLines").append(lineRow(depositPercent + "% deposit — " + b.package, 1, dep));
  recalc();
}

function openInvoice() {
  $("#iTitle").html("<i class=\"fas fa-plus me-2\"></i>New Invoice");
  $("#iId,#iBooking,#iName,#iPhone,#iEmail,#iNotes").val("");
  $("#iIssue").val("' . date('Y-m-d') . '");
  $("#iDiscount").val("0.00");
  $("#iTaxRate").val("' . $defaultTaxRate . '");
  $("#iStatus").val("draft");
  $("#iTerms").val(defaultTerms);
  autoDueDate();
  setLines([]);
  invModal.show();
}

function editInvoice(inv, items) {
  $("#iTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit " + (inv.invoice_no || "Invoice"));
  $("#iId").val(inv.id);
  $("#iBooking").val(inv.booking_id || "");
  $("#iName").val(inv.customer_name || "");
  $("#iPhone").val(inv.customer_phone || "");
  $("#iEmail").val(inv.customer_email || "");
  $("#iIssue").val(inv.issue_date || "");
  $("#iDue").val(inv.due_date || "");
  $("#iDiscount").val(inv.discount || "0.00");
  $("#iTaxRate").val(inv.tax_rate || "0");
  /* partial/paid/overdue are derived — edit under the nearest editable state */
  $("#iStatus").val(["draft","sent","cancelled"].includes(inv.status) ? inv.status : "sent");
  $("#iNotes").val(inv.notes || "");
  $("#iTerms").val(inv.terms || "");
  setLines(items);
  invModal.show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
