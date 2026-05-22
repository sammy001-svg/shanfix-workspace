<?php
// ── POS: Sales History ─────────────────────────────────────────
$moduleSlug  = 'pos';
$moduleName  = 'Point of Sale';
$moduleIcon  = 'fas fa-cash-register';
$moduleColor = '#e74c3c';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'terminal.php','icon'=>'fas fa-cash-register','label'=>'POS Terminal'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'categories.php','icon'=>'fas fa-tags','label'=>'Categories'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'suppliers.php','icon'=>'fas fa-truck','label'=>'Suppliers'],['url'=>'stock.php','icon'=>'fas fa-warehouse','label'=>'Stock'],['url'=>'purchases.php','icon'=>'fas fa-cart-arrow-down','label'=>'Purchases'],['url'=>'returns.php','icon'=>'fas fa-undo','label'=>'Returns'],['url'=>'shifts.php','icon'=>'fas fa-clock','label'=>'Shifts'],['url'=>'expenses.php','icon'=>'fas fa-wallet','label'=>'Expenses'],['url'=>'discounts.php','icon'=>'fas fa-percent','label'=>'Discounts'],['url'=>'sales.php','icon'=>'fas fa-receipt','label'=>'Sales History'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'void_sale') {
        $id = (int)($_POST['sale_id'] ?? 0);
        // Restore stock for voided items
        $items = $pdo->prepare("SELECT product_id, quantity FROM pos_sale_items WHERE sale_id=?");
        $items->execute([$id]);
        foreach ($items->fetchAll() as $item) {
            if ($item['product_id']) {
                $pdo->prepare("UPDATE pos_products SET stock_quantity = stock_quantity + ? WHERE id=? AND org_id=?")
                    ->execute([$item['quantity'], $item['product_id'], $orgId]);
            }
        }
        $pdo->prepare("UPDATE pos_sales SET status='voided' WHERE id=? AND org_id=? AND status='completed'")
            ->execute([$id, $orgId]);
        setFlash('success', 'Sale voided and stock restored.');
        logActivity('void', 'pos', "Voided sale #$id");
        redirect('sales.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filters
$fromDate  = $_GET['from']    ?? date('Y-m-01');
$toDate    = $_GET['to']      ?? date('Y-m-d');
$filterPay = $_GET['payment'] ?? '';
$filterSts = $_GET['status']  ?? '';

$where  = "WHERE s.org_id=? AND DATE(s.created_at) BETWEEN ? AND ?";
$params = [$orgId, $fromDate, $toDate];
if ($filterPay) { $where .= " AND s.payment_method=?"; $params[] = $filterPay; }
if ($filterSts) { $where .= " AND s.status=?"; $params[] = $filterSts; }

$sales = $pdo->prepare("SELECT s.*, COUNT(si.id) AS item_count FROM pos_sales s LEFT JOIN pos_sale_items si ON s.id=si.sale_id $where GROUP BY s.id ORDER BY s.created_at DESC");
$sales->execute($params);
$sales = $sales->fetchAll();

// Today's stats
$todayStats = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS rev FROM pos_sales WHERE org_id=? AND DATE(created_at)=? AND status='completed'");
$todayStats->execute([$orgId, date('Y-m-d')]);
$today = $todayStats->fetch();

$monthStats = $pdo->prepare("SELECT COALESCE(SUM(total),0) AS rev, COUNT(*) AS cnt FROM pos_sales WHERE org_id=? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND status='completed'");
$monthStats->execute([$orgId]);
$month = $monthStats->fetch();

$voidedCount = $pdo->prepare("SELECT COUNT(*) AS cnt FROM pos_sales WHERE org_id=? AND status='voided'");
$voidedCount->execute([$orgId]);
$voided = $voidedCount->fetchColumn();
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-receipt me-2" style="color:<?= $moduleColor ?>"></i>Sales History</h4>
    <p class="text-muted mb-0">View and manage all POS transactions</p>
  </div>
  <a href="terminal.php" class="btn btn-primary" target="_blank">
    <i class="fas fa-desktop me-2"></i>Open POS Terminal
  </a>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-value text-success"><?= $today['cnt'] ?></div>
      <div class="stat-label">Today's Sales</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-value" style="color:<?= $moduleColor ?>"><?= formatCurrency((float)$today['rev']) ?></div>
      <div class="stat-label">Today's Revenue</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-value text-navy"><?= formatCurrency((float)$month['rev']) ?></div>
      <div class="stat-label">This Month</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-value text-warning"><?= $voided ?></div>
      <div class="stat-label">Voided Sales</div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label small mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fromDate) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label small mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($toDate) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label small mb-1">Payment</label>
        <select name="payment" class="form-select form-select-sm">
          <option value="">All Methods</option>
          <option value="cash" <?= $filterPay==='cash'?'selected':'' ?>>Cash</option>
          <option value="mpesa" <?= $filterPay==='mpesa'?'selected':'' ?>>M-Pesa</option>
          <option value="card" <?= $filterPay==='card'?'selected':'' ?>>Card</option>
          <option value="credit" <?= $filterPay==='credit'?'selected':'' ?>>Credit</option>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="completed" <?= $filterSts==='completed'?'selected':'' ?>>Completed</option>
          <option value="voided" <?= $filterSts==='voided'?'selected':'' ?>>Voided</option>
          <option value="refunded" <?= $filterSts==='refunded'?'selected':'' ?>>Refunded</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="sales.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Sales Table -->
<div class="card">
  <div class="card-body p-0">
    <table class="table data-table mb-0">
      <thead>
        <tr>
          <th>Receipt No</th>
          <th>Date/Time</th>
          <th>Customer</th>
          <th>Items</th>
          <th>Total</th>
          <th>Payment</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($sales as $s): ?>
        <tr>
          <td class="fw-600"><?= e($s['receipt_no']) ?></td>
          <td><?= formatDateTime($s['created_at']) ?></td>
          <td><?= e($s['customer_name'] ?: '—') ?></td>
          <td><span class="badge bg-secondary"><?= $s['item_count'] ?> items</span></td>
          <td class="fw-600"><?= formatCurrency((float)$s['total']) ?></td>
          <td>
            <span class="badge <?= $s['payment_method']==='mpesa'?'bg-success':($s['payment_method']==='card'?'bg-info':'bg-secondary') ?>">
              <?= strtoupper($s['payment_method']) ?>
            </span>
            <?php if ($s['mpesa_receipt']): ?>
            <small class="d-block text-muted"><?= e($s['mpesa_receipt']) ?></small>
            <?php endif; ?>
          </td>
          <td><?= statusBadge($s['status']) ?></td>
          <td>
            <button class="btn btn-xs btn-outline-primary" onclick="viewReceipt(<?= $s['id'] ?>)">
              <i class="fas fa-eye"></i>
            </button>
            <?php if ($s['status'] === 'completed'): ?>
            <form method="POST" class="d-inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="void_sale">
              <input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-xs btn-outline-danger" data-confirm="Void this sale and restore stock?">
                <i class="fas fa-times"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:<?= $moduleColor ?>;color:white">
        <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Sale Receipt</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="receiptContent">
        <div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
      </div>
    </div>
  </div>
</div>

<?php
// Encode sales with items for receipt modal
$receiptData = [];
foreach ($sales as $s) {
    $items = $pdo->prepare("SELECT * FROM pos_sale_items WHERE sale_id=?");
    $items->execute([$s['id']]);
    $s['items'] = $items->fetchAll();
    $receiptData[$s['id']] = $s;
}

$extraJs = '<script>
const receipts = ' . json_encode($receiptData) . ';
function viewReceipt(id) {
    const s = receipts[id];
    if (!s) return;
    let rows = s.items.map(i => `<tr><td>${i.product_name}</td><td class="text-center">${i.quantity}</td><td class="text-end">KES ${parseFloat(i.unit_price).toLocaleString("en-KE",{minimumFractionDigits:2})}</td><td class="text-end">KES ${parseFloat(i.total).toLocaleString("en-KE",{minimumFractionDigits:2})}</td></tr>`).join("");
    document.getElementById("receiptContent").innerHTML = `
        <div class="text-center mb-3">
            <h5 class="fw-800 text-navy">' . APP_NAME . '</h5>
            <div class="text-muted small">Point of Sale Receipt</div>
        </div>
        <table class="table table-sm">
            <tr><th>Receipt No</th><td>${s.receipt_no}</td></tr>
            <tr><th>Date</th><td>${s.created_at}</td></tr>
            ${s.customer_name ? `<tr><th>Customer</th><td>${s.customer_name}</td></tr>` : ""}
            <tr><th>Payment</th><td>${s.payment_method.toUpperCase()}${s.mpesa_receipt ? " — "+s.mpesa_receipt : ""}</td></tr>
            <tr><th>Status</th><td>${s.status.toUpperCase()}</td></tr>
        </table>
        <hr>
        <table class="table table-sm">
            <thead><tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>
        <hr>
        <div class="d-flex justify-content-between"><span>Subtotal</span><strong>KES ${parseFloat(s.subtotal).toLocaleString("en-KE",{minimumFractionDigits:2})}</strong></div>
        ${parseFloat(s.discount)>0 ? `<div class="d-flex justify-content-between text-danger"><span>Discount</span><strong>- KES ${parseFloat(s.discount).toLocaleString("en-KE",{minimumFractionDigits:2})}</strong></div>` : ""}
        ${parseFloat(s.tax)>0 ? `<div class="d-flex justify-content-between"><span>Tax</span><strong>KES ${parseFloat(s.tax).toLocaleString("en-KE",{minimumFractionDigits:2})}</strong></div>` : ""}
        <div class="d-flex justify-content-between fw-800 fs-5 text-success mt-2 border-top pt-2"><span>TOTAL</span><span>KES ${parseFloat(s.total).toLocaleString("en-KE",{minimumFractionDigits:2})}</span></div>
        ${parseFloat(s.change_given)>0 ? `<div class="d-flex justify-content-between text-muted small"><span>Change Given</span><span>KES ${parseFloat(s.change_given).toLocaleString("en-KE",{minimumFractionDigits:2})}</span></div>` : ""}
    `;
    new bootstrap.Modal(document.getElementById("receiptModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
