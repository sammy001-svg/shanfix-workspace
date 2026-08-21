<?php
// ── TOUR: Booking Payments & Revenue Tracking ───────────────────
require_once __DIR__ . '/_nav.php';

$PAY_TYPES = ['deposit', 'installment', 'full', 'refund', 'commission'];
$PAY_MODES = ['cash', 'mpesa', 'bank', 'card', 'online'];

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

    /** Recompute a booking's paid_amount from its receipts, then reconcile its invoices. */
    $reconcile = function (int $bookingId) use ($pdo, $orgId) {
        if ($bookingId <= 0) return;
        try {
            $paid = tourBookingPaid($orgId, $bookingId);
            $pdo->prepare("UPDATE tour_bookings SET paid_amount=?, updated_at=NOW() WHERE id=? AND org_id=?")
                ->execute([$paid, $bookingId, $orgId]);

            $inv = $pdo->prepare("SELECT id FROM tour_invoices WHERE org_id=? AND booking_id=? AND status NOT IN ('draft','cancelled')");
            $inv->execute([$orgId, $bookingId]);
            foreach ($inv->fetchAll(PDO::FETCH_COLUMN) as $invId) {
                tourSyncInvoice($orgId, (int)$invId);
            }
        } catch (Throwable $e) {}
    };

    if ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $amount    = (float)($_POST['amount'] ?? 0);
        $mode      = in_array($_POST['payment_mode'] ?? '', $PAY_MODES, true) ? $_POST['payment_mode'] : 'cash';
        $type      = in_array($_POST['payment_type'] ?? '', $PAY_TYPES, true) ? $_POST['payment_type'] : 'deposit';
        $ref       = sanitize($_POST['reference'] ?? '');
        $payDate   = $_POST['payment_date'] ?? date('Y-m-d');
        $notes     = sanitize($_POST['notes'] ?? '');

        if ($bookingId <= 0 || $amount <= 0) {
            setFlash('danger', 'Select a booking and enter an amount greater than zero.');
            redirect('payments.php');
        }

        // The booking must be ours; carry its customer onto the receipt
        $bk = $pdo->prepare("SELECT id, customer_id, booking_no FROM tour_bookings WHERE id=? AND org_id=? LIMIT 1");
        $bk->execute([$bookingId, $orgId]);
        $booking = $bk->fetch();
        if (!$booking) {
            setFlash('danger', 'That booking is invalid.');
            redirect('payments.php');
        }

        if ($id > 0) {
            // Reconcile the old booking too, in case the receipt was moved
            $old = $pdo->prepare("SELECT booking_id FROM tour_payments WHERE id=? AND org_id=? LIMIT 1");
            $old->execute([$id, $orgId]);
            $oldBookingId = (int)$old->fetchColumn();

            $pdo->prepare("
                UPDATE tour_payments
                SET booking_id=?, customer_id=?, amount=?, payment_type=?, payment_mode=?, reference=?, payment_date=?, notes=?
                WHERE id=? AND org_id=?
            ")->execute([$bookingId, $booking['customer_id'] ?: null, $amount, $type, $mode, $ref, $payDate, $notes, $id, $orgId]);

            if ($oldBookingId && $oldBookingId !== $bookingId) $reconcile($oldBookingId);
            setFlash('success', 'Payment updated.');
        } else {
            // Per-org receipt sequence; the unique key catches any race
            $receiptNo = '';
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $seqStmt = $pdo->prepare("
                    SELECT receipt_no FROM tour_payments
                    WHERE org_id=? AND receipt_no LIKE ?
                    ORDER BY LENGTH(receipt_no) DESC, receipt_no DESC LIMIT 1
                ");
                $stem = 'RCT-' . date('Y') . '-';
                $seqStmt->execute([$orgId, $stem . '%']);
                $last = (string)$seqStmt->fetchColumn();
                $next = $last !== '' ? (int)substr($last, strlen($stem)) + 1 : 1;
                $receiptNo = $stem . str_pad((string)($next + $attempt), 4, '0', STR_PAD_LEFT);

                try {
                    $pdo->prepare("
                        INSERT INTO tour_payments
                            (org_id, receipt_no, booking_id, customer_id, amount, payment_type, payment_mode, reference, payment_date, notes)
                        VALUES (?,?,?,?,?,?,?,?,?,?)
                    ")->execute([$orgId, $receiptNo, $bookingId, $booking['customer_id'] ?: null,
                                 $amount, $type, $mode, $ref, $payDate, $notes]);
                    break;
                } catch (PDOException $e) {
                    if ($attempt === 4) {
                        setFlash('danger', 'Could not allocate a receipt number. Please try again.');
                        redirect('payments.php');
                    }
                }
            }
            setFlash('success', 'Payment ' . $receiptNo . ' recorded against ' . $booking['booking_no'] . '.');
        }

        $reconcile($bookingId);
        logActivity($id > 0 ? 'update' : 'create', 'tour', 'Payment ' . formatCurrency($amount) . " for booking #$bookingId");
        redirect('payments.php' . ($bookingId ? '?booking_id=' . $bookingId : ''));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $old = $pdo->prepare("SELECT booking_id FROM tour_payments WHERE id=? AND org_id=? LIMIT 1");
        $old->execute([$id, $orgId]);
        $bookingId = (int)$old->fetchColumn();

        $pdo->prepare("DELETE FROM tour_payments WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        $reconcile($bookingId);
        setFlash('success', 'Payment deleted.');
        logActivity('delete', 'tour', "Deleted payment #$id");
        redirect('payments.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
require_once __DIR__ . '/_lib.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fBooking = (int)($_GET['booking_id'] ?? 0);
$fMode    = sanitize($_GET['mode'] ?? '');
$fMonth   = sanitize($_GET['month'] ?? '');

$where  = 'p.org_id = ?';
$params = [$orgId];
if ($fBooking)                                   { $where .= ' AND p.booking_id = ?';  $params[] = $fBooking; }
if ($fMode && in_array($fMode, $PAY_MODES, true)){ $where .= ' AND p.payment_mode = ?'; $params[] = $fMode; }
if ($fMonth)                                     { $where .= " AND DATE_FORMAT(p.payment_date,'%Y-%m') = ?"; $params[] = $fMonth; }

$payments = [];
try {
    $s = $pdo->prepare("
        SELECT p.*, b.booking_no, b.customer_name, b.total_amount, b.travel_date,
               pkg.name AS package_name
        FROM tour_payments p
        LEFT JOIN tour_bookings b   ON b.id = p.booking_id
        LEFT JOIN tour_packages pkg ON pkg.id = b.package_id
        WHERE $where
        ORDER BY p.payment_date DESC, p.id DESC
    ");
    $s->execute($params);
    $payments = $s->fetchAll();
} catch (Throwable $e) {}

// Bookings selector, with the live balance so staff can see what is left
$bookings = [];
try {
    $s = $pdo->prepare("
        SELECT b.id, b.booking_no, b.customer_name, b.total_amount, b.paid_amount, b.travel_date
        FROM tour_bookings b
        WHERE b.org_id = ? AND b.status <> 'cancelled'
        ORDER BY b.travel_date DESC
        LIMIT 300
    ");
    $s->execute([$orgId]);
    $bookings = $s->fetchAll();
} catch (Throwable $e) {}

$bookingBalances = [];
foreach ($bookings as $b) {
    $bookingBalances[$b['id']] = round(max(0, (float)$b['total_amount'] - (float)$b['paid_amount']), 2);
}

// The booking being filtered on
$focusBooking = null;
if ($fBooking > 0) {
    try {
        $s = $pdo->prepare("
            SELECT b.*, p.name AS package_name FROM tour_bookings b
            LEFT JOIN tour_packages p ON p.id = b.package_id
            WHERE b.id=? AND b.org_id=? LIMIT 1
        ");
        $s->execute([$fBooking, $orgId]);
        $focusBooking = $s->fetch() ?: null;
    } catch (Throwable $e) {}
}

// ── Totals ────────────────────────────────────────────────────
$totalRevenue = $monthRevenue = $refunded = 0.0;
$txnCount = 0;
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN payment_type='refund' THEN -amount ELSE amount END),0) FROM tour_payments WHERE org_id=?");
    $s->execute([$orgId]); $totalRevenue = (float)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN payment_type='refund' THEN -amount ELSE amount END),0) FROM tour_payments WHERE org_id=? AND DATE_FORMAT(payment_date,'%Y-%m')=?");
    $s->execute([$orgId, date('Y-m')]); $monthRevenue = (float)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM tour_payments WHERE org_id=? AND payment_type='refund'");
    $s->execute([$orgId]); $refunded = (float)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM tour_payments WHERE org_id=?");
    $s->execute([$orgId]); $txnCount = (int)$s->fetchColumn();
} catch (Throwable $e) {}

$modeColors = ['cash'=>'success','mpesa'=>'primary','bank'=>'info','card'=>'warning','online'=>'dark'];
$typeLabels = ['deposit'=>'Deposit','installment'=>'Installment','full'=>'Full Payment','refund'=>'Refund','commission'=>'Commission'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-bill-wave me-2" style="color:<?= $moduleColor ?>"></i>Payments</h4>
    <p class="text-muted mb-0">Receipt client payments — booking balances and linked invoices update automatically</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Record Payment
  </button>
</div>

<?php if ($focusBooking):
    $fbBalance = max(0, (float)$focusBooking['total_amount'] - (float)$focusBooking['paid_amount']);
?>
<div class="alert alert-light border shadow-sm d-flex flex-wrap align-items-center gap-3">
  <div class="flex-grow-1">
    <div class="fw-bold">
      <i class="fas fa-receipt me-1" style="color:<?= $moduleColor ?>"></i>
      Ledger for <?= e($focusBooking['booking_no']) ?> — <?= e($focusBooking['customer_name']) ?>
    </div>
    <div class="small text-muted">
      <?= e($focusBooking['package_name'] ?: '—') ?> ·
      Total <?= formatCurrency((float)$focusBooking['total_amount']) ?> ·
      Paid <?= formatCurrency((float)$focusBooking['paid_amount']) ?> ·
      <span class="<?= $fbBalance > 0 ? 'text-danger fw-semibold' : 'text-success fw-semibold' ?>">
        <?= $fbBalance > 0 ? 'Balance ' . formatCurrency($fbBalance) : 'Settled in full' ?>
      </span>
    </div>
  </div>
  <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>" onclick="openAdd(<?= (int)$focusBooking['id'] ?>)">
    <i class="fas fa-plus me-1"></i>Add Payment
  </button>
  <a href="payments.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-xmark me-1"></i>Clear filter</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Net Received</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($monthRevenue) ?></div><div class="stat-label">This Month</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-rotate-left"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($refunded) ?></div><div class="stat-label">Refunded</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon" style="background:#e3f2fd;color:<?= $moduleColor ?>"><i class="fas fa-receipt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $txnCount ?></div><div class="stat-label">Receipts Issued</div></div></div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1">Booking</label>
        <select name="booking_id" class="form-select form-select-sm">
          <option value="">All bookings</option>
          <?php foreach ($bookings as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $fBooking === (int)$b['id'] ? 'selected' : '' ?>>
            <?= e($b['booking_no'] . ' — ' . $b['customer_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Method</label>
        <select name="mode" class="form-select form-select-sm">
          <option value="">All methods</option>
          <?php foreach ($PAY_MODES as $m): ?>
          <option value="<?= $m ?>" <?= $fMode === $m ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Month</label>
        <input type="month" name="month" class="form-control form-control-sm" value="<?= e($fMonth) ?>">
      </div>
      <div class="col-sm-2 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-filter me-1"></i>Apply</button>
        <a href="payments.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-rotate-left"></i></a>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="paymentsTable">
        <thead class="table-light">
          <tr>
            <th>Receipt</th><th>Booking</th><th>Client</th><th>Date</th>
            <th>Type</th><th>Method</th><th>Reference</th>
            <th class="text-end">Amount</th><th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
          <tr><td colspan="9" class="text-center py-5 text-muted"><i class="fas fa-receipt fa-3x mb-3 d-block"></i>No payments recorded.</td></tr>
          <?php else: foreach ($payments as $p):
              $isRefund = $p['payment_type'] === 'refund';
          ?>
          <tr>
            <td><code class="text-dark bg-light px-2 py-1 rounded"><?= e($p['receipt_no']) ?></code></td>
            <td class="small">
              <?php if ($p['booking_no']): ?>
              <a href="payments.php?booking_id=<?= (int)$p['booking_id'] ?>" class="text-decoration-none"><?= e($p['booking_no']) ?></a>
              <div class="text-muted"><?= e($p['package_name'] ?: '—') ?></div>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td class="fw-semibold"><?= e($p['customer_name'] ?: '—') ?></td>
            <td class="small"><?= formatDate($p['payment_date']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= e($typeLabels[$p['payment_type']] ?? ucfirst($p['payment_type'])) ?></span></td>
            <td><span class="badge bg-<?= $modeColors[$p['payment_mode']] ?? 'secondary' ?>"><?= ucfirst($p['payment_mode']) ?></span></td>
            <td class="small text-muted"><?= e($p['reference'] ?: '—') ?></td>
            <td class="text-end fw-bold <?= $isRefund ? 'text-danger' : 'text-success' ?>">
              <?= $isRefund ? '&minus;' : '' ?><?= formatCurrency((float)$p['amount']) ?>
            </td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick='editPay(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this receipt? The booking balance will be recalculated.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
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

<!-- Payment Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="payId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="payTitle"><i class="fas fa-plus me-2"></i>Record Payment</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Booking <span class="text-danger">*</span></label>
              <select name="booking_id" id="payBooking" class="form-select" required onchange="showBalance()">
                <option value="">-- Select Booking --</option>
                <?php foreach ($bookings as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= e($b['booking_no'] . ' — ' . $b['customer_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" id="balanceHint"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Amount (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" min="0.01" name="amount" id="payAmount" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
              <input type="date" name="payment_date" id="payDate" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Type</label>
              <select name="payment_type" id="payType" class="form-select">
                <?php foreach ($typeLabels as $k => $label): ?>
                <option value="<?= $k ?>"><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Method</label>
              <select name="payment_mode" id="payMode" class="form-select">
                <?php foreach ($PAY_MODES as $m): ?>
                <option value="<?= $m ?>"><?= ucfirst($m) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Reference</label>
              <input type="text" name="reference" id="payRef" class="form-control" placeholder="e.g. M-Pesa code, bank slip no.">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="payNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
const balances = ' . json_encode($bookingBalances) . ';
const CUR      = "' . CURRENCY . '";
let payModal;

$(document).ready(function(){
  $("#paymentsTable").DataTable({pageLength:15, order:[[3,"desc"]]});
  payModal = new bootstrap.Modal(document.getElementById("payModal"));
});

function money(n) {
  return CUR + " " + (Number(n) || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,");
}

function showBalance() {
  const bal = balances[$("#payBooking").val()];
  if (bal === undefined) { $("#balanceHint").text(""); return; }
  $("#balanceHint").html(bal > 0
    ? "Outstanding balance: <strong>" + money(bal) + "</strong>"
    : "<span class=\"text-success\">This booking is settled in full.</span>");
}

function openAdd(bookingId) {
  $("#payTitle").html("<i class=\"fas fa-plus me-2\"></i>Record Payment");
  $("#payId,#payAmount,#payRef,#payNotes").val("");
  $("#payBooking").val(bookingId || "");
  $("#payDate").val("' . date('Y-m-d') . '");
  $("#payType").val("deposit");
  $("#payMode").val("cash");
  showBalance();
  payModal.show();
}

function editPay(p) {
  $("#payTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit " + (p.receipt_no || "Payment"));
  $("#payId").val(p.id);
  $("#payBooking").val(p.booking_id || "");
  $("#payAmount").val(p.amount || "");
  $("#payDate").val(p.payment_date || "");
  $("#payType").val(p.payment_type || "deposit");
  $("#payMode").val(p.payment_mode || "cash");
  $("#payRef").val(p.reference || "");
  $("#payNotes").val(p.notes || "");
  showBalance();
  payModal.show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
