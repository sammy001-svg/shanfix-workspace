<?php
// ── Sales: Invoices ────────────────────────────────────────────
$moduleSlug='sales';$moduleName='Sales Management';$moduleIcon='fas fa-chart-line';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $orderId    = (int)($_POST['order_id']    ?? 0) ?: null;
        $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;
        $issueDate  = $_POST['issue_date'] ?? date('Y-m-d');
        $dueDate    = $_POST['due_date']   ?? date('Y-m-d', strtotime('+30 days'));
        $notes      = sanitize($_POST['notes'] ?? '');
        $items      = $_POST['items'] ?? [];

        if (empty($items)) {
            setFlash('danger', 'Invoice must have at least one line item.');
            redirect('invoices.php');
        }

        try {
            $pdo->beginTransaction();
            $yr   = date('Y');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_invoices WHERE org_id=? AND YEAR(issue_date)=?");
            $stmt->execute([$orgId, $yr]);
            $seq       = (int)$stmt->fetchColumn() + 1;
            $invoiceNo = 'INV-' . $yr . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);

            $subtotal = $taxTotal = 0;
            foreach ($items as $it) {
                $qty   = (float)($it['qty']   ?? 0);
                $price = (float)($it['price'] ?? 0);
                $tax   = (float)($it['tax']   ?? 0);
                $subtotal += $qty * $price;
                $taxTotal += $qty * $price * ($tax / 100);
            }
            $discount = (float)($_POST['discount'] ?? 0);
            $total    = $subtotal + $taxTotal - $discount;

            $stmt = $pdo->prepare("
                INSERT INTO sales_invoices (org_id, invoice_no, order_id, customer_id, issue_date, due_date, subtotal, tax_total, discount, total_amount, paid_amount, status, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,0,'unpaid',?)
            ");
            $stmt->execute([$orgId, $invoiceNo, $orderId, $customerId, $issueDate, $dueDate, $subtotal, $taxTotal, $discount, $total, $notes]);
            $invId = $pdo->lastInsertId();

            $stmtLine = $pdo->prepare("INSERT INTO sales_invoice_items (invoice_id, description, quantity, unit_price, tax_rate, total) VALUES (?,?,?,?,?,?)");
            foreach ($items as $it) {
                $qty   = (float)($it['qty']   ?? 0);
                $price = (float)($it['price'] ?? 0);
                $tax   = (float)($it['tax']   ?? 0);
                $desc  = sanitize($it['desc'] ?? '');
                if ($qty <= 0 || empty($desc)) continue;
                $stmtLine->execute([$invId, $desc, $qty, $price, $tax, $qty * $price]);
            }

            $pdo->commit();
            setFlash('success', "Invoice {$invoiceNo} created. Total: " . formatCurrency($total));
            logActivity('create', 'sales', "Created invoice {$invoiceNo}");
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('invoices.php');
    }

    if ($action === 'record_payment') {
        $invId   = (int)($_POST['invoice_id'] ?? 0);
        $payment = (float)($_POST['payment']  ?? 0);
        if ($invId > 0 && $payment > 0) {
            try {
                $stmt = $pdo->prepare("SELECT total_amount, paid_amount FROM sales_invoices WHERE id=? AND org_id=?");
                $stmt->execute([$invId, $orgId]);
                $inv = $stmt->fetch();
                if ($inv) {
                    $newPaid   = (float)$inv['paid_amount'] + $payment;
                    $newStatus = $newPaid >= (float)$inv['total_amount'] ? 'paid' : 'partial';
                    $pdo->prepare("UPDATE sales_invoices SET paid_amount=?, status=? WHERE id=? AND org_id=?")->execute([$newPaid, $newStatus, $invId, $orgId]);
                    setFlash('success', 'Payment recorded. New status: ' . ucfirst($newStatus));
                }
            } catch (Exception $e) {
                setFlash('danger', 'Error: ' . $e->getMessage());
            }
        }
        redirect('invoices.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$customers = $orders = $invoices = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM sales_customers WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]); $customers = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, order_no FROM sales_orders WHERE org_id=? AND status IN ('processing','shipped','delivered') ORDER BY order_no DESC LIMIT 50");
    $stmt->execute([$orgId]); $orders = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT i.*, c.name AS customer_name
        FROM sales_invoices i
        LEFT JOIN sales_customers c ON c.id = i.customer_id
        WHERE i.org_id=?
        ORDER BY i.issue_date DESC, i.created_at DESC
    ");
    $stmt->execute([$orgId]); $invoices = $stmt->fetchAll();
} catch (Exception $e) {}

$totalUnpaid = array_sum(array_map(fn($i) => (float)$i['total_amount'] - (float)$i['paid_amount'], array_filter($invoices, fn($i) => $i['status'] !== 'paid')));
$totalPaid   = array_sum(array_column(array_filter($invoices, fn($i) => $i['status'] === 'paid'), 'total_amount'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Sales Invoices</h4>
    <p class="text-muted mb-0">Create and manage client invoices, track payments</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#invModal">
    <i class="fas fa-plus-circle me-1"></i>New Invoice
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalUnpaid) ?></div><div class="stat-label">Outstanding Balance</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPaid) ?></div><div class="stat-label">Collected Revenue</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(26,138,78,0.12);color:#1A8A4E"><i class="fas fa-file-invoice"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($invoices) ?></div><div class="stat-label">Total Invoices</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="invTable">
        <thead class="table-light">
          <tr><th>Date</th><th>Invoice No.</th><th>Customer</th><th>Due Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th class="text-center">Status</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($invoices)): ?>
          <tr><td colspan="9" class="text-center py-5 text-muted"><i class="fas fa-file-invoice fa-3x mb-3 d-block"></i>No invoices yet.</td></tr>
          <?php else: foreach ($invoices as $inv):
            $balance = (float)$inv['total_amount'] - (float)$inv['paid_amount'];
            $overdue = $inv['status'] !== 'paid' && $inv['due_date'] < date('Y-m-d');
            $statusBadge = match($inv['status']) {
              'paid'    => 'bg-success',
              'partial' => 'bg-warning text-dark',
              'unpaid'  => $overdue ? 'bg-danger' : 'bg-secondary',
              default   => 'bg-light text-dark',
            };
            $statusLabel = $overdue && $inv['status'] !== 'paid' ? 'Overdue' : ucfirst($inv['status']);
          ?>
          <tr>
            <td><?= formatDate($inv['issue_date']) ?></td>
            <td><span class="badge" style="background:#1A8A4E"><?= e($inv['invoice_no']) ?></span></td>
            <td><?= e($inv['customer_name'] ?: '—') ?></td>
            <td class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>"><?= formatDate($inv['due_date']) ?></td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$inv['total_amount']) ?></td>
            <td class="text-end text-success"><?= formatCurrency((float)$inv['paid_amount']) ?></td>
            <td class="text-end fw-bold <?= $balance > 0 ? 'text-danger' : 'text-muted' ?>"><?= $balance > 0 ? formatCurrency($balance) : '—' ?></td>
            <td class="text-center"><span class="badge <?= $statusBadge ?>"><?= $statusLabel ?></span></td>
            <td class="text-end">
              <?php if ($inv['status'] !== 'paid'): ?>
              <button class="btn btn-sm btn-outline-success" onclick="openPayment(<?= $inv['id'] ?>,<?= $balance ?>)" title="Record Payment">
                <i class="fas fa-dollar-sign"></i>
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="record_payment">
        <input type="hidden" name="invoice_id" id="pmtInvId">
        <div class="modal-header text-white" style="background:#1A8A4E">
          <h5 class="modal-title"><i class="fas fa-dollar-sign me-2"></i>Record Payment</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Payment Amount (<?= CURRENCY ?>)</label>
            <input type="number" step="0.01" name="payment" id="pmtAmount" class="form-control form-control-lg fw-bold text-success" required min="0.01">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:#1A8A4E"><i class="fas fa-save me-1"></i>Save Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- New Invoice Modal -->
<div class="modal fade" id="invModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-header text-white" style="background:#1A8A4E">
          <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>New Invoice</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Customer</label>
              <select name="customer_id" class="form-select">
                <option value="">-- Walk-in / None --</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Linked Order</label>
              <select name="order_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($orders as $o): ?>
                <option value="<?= $o['id'] ?>"><?= e($o['order_no']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Issue Date</label>
              <input type="date" name="issue_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Due Date</label>
              <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
            </div>
            <div class="col-md-1">
              <label class="form-label fw-semibold">Discount</label>
              <input type="number" step="0.01" name="discount" class="form-control" value="0" min="0">
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0 fw-semibold">Line Items</h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addInvLine()"><i class="fas fa-plus me-1"></i>Add Line</button>
          </div>
          <table class="table table-sm border align-middle">
            <thead class="table-light"><tr><th>Description</th><th class="text-center" style="width:80px">Qty</th><th class="text-end" style="width:120px">Unit Price</th><th class="text-center" style="width:70px">Tax %</th><th class="text-end" style="width:120px">Total</th><th style="width:40px"></th></tr></thead>
            <tbody id="invLineBody"></tbody>
          </table>

          <div class="mb-3">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Payment terms, notes..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:#1A8A4E"><i class="fas fa-save me-1"></i>Create Invoice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#invTable").DataTable({pageLength:15, order:[[0,"desc"]]});
    addInvLine();
});

let invIdx = 0;
function addInvLine() {
    var idx = invIdx++;
    var row = `<tr id="invline_${idx}">
        <td><input type="text" name="items[${idx}][desc]" class="form-control form-control-sm" placeholder="Item description" required></td>
        <td><input type="number" name="items[${idx}][qty]" class="form-control form-control-sm text-center" value="1" min="0.01" step="0.01" onchange="calcInvLine(${idx})"></td>
        <td><input type="number" name="items[${idx}][price]" class="form-control form-control-sm text-end" value="0" step="0.01" min="0" onchange="calcInvLine(${idx})"></td>
        <td><input type="number" name="items[${idx}][tax]" class="form-control form-control-sm text-center" value="0" step="0.1" min="0" max="100" onchange="calcInvLine(${idx})"></td>
        <td class="text-end fw-semibold" id="invlinetot_${idx}">0.00</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="$('#invline_${idx}').remove()"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $("#invLineBody").append(row);
}
function calcInvLine(idx) {
    var qty = parseFloat($(`#invline_${idx} input[name*='[qty]']`).val()) || 0;
    var price = parseFloat($(`#invline_${idx} input[name*='[price]']`).val()) || 0;
    var tax = parseFloat($(`#invline_${idx} input[name*='[tax]']`).val()) || 0;
    var tot = qty * price * (1 + tax/100);
    $(`#invlinetot_${idx}`).text(tot.toFixed(2));
}

function openPayment(id, balance) {
    $("#pmtInvId").val(id);
    $("#pmtAmount").val(balance.toFixed(2));
    $("#paymentModal").modal("show");
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
