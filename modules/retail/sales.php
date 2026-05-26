<?php
// ── Retail: Sales / POS ────────────────────────────────────────
$moduleSlug  = 'retail';
$moduleName  = 'Retail & Wholesale';
$moduleIcon  = 'fas fa-store';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'categories.php','icon' => 'fas fa-tags',           'label' => 'Categories'],
    ['url' => 'products.php',  'icon' => 'fas fa-boxes',          'label' => 'Products'],
    ['url' => 'suppliers.php', 'icon' => 'fas fa-truck',          'label' => 'Suppliers'],
    ['url' => 'purchases.php', 'icon' => 'fas fa-file-invoice',   'label' => 'Purchase Orders'],
    ['url' => 'sales.php',     'icon' => 'fas fa-cash-register',  'label' => 'Sales / POS'],
    ['url' => 'stock.php',     'icon' => 'fas fa-warehouse',      'label' => 'Stock Adjustments'],
    ['url' => 'pricing.php',   'icon' => 'fas fa-tags',           'label' => 'Pricing Rules'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'pos_sale') {
        $items       = $_POST['items']        ?? [];
        $paymentMode = sanitize($_POST['payment_mode'] ?? 'cash');
        $customerName= sanitize($_POST['customer_name'] ?? '');
        $saleDate    = $_POST['sale_date'] ?? date('Y-m-d');
        $discount    = (float)($_POST['discount'] ?? 0);

        if (empty($items)) {
            setFlash('danger', 'Cannot post an empty sale.');
            redirect('sales.php');
        }

        try {
            $pdo->beginTransaction();

            // Generate sale number
            $yr   = date('Y');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM retail_sales WHERE org_id=? AND YEAR(sale_date)=?");
            $stmt->execute([$orgId, $yr]);
            $seq     = (int)$stmt->fetchColumn() + 1;
            $saleNo  = 'SL-' . $yr . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);

            $subtotal = 0;
            foreach ($items as $it) {
                $subtotal += (float)($it['qty'] ?? 0) * (float)($it['unit_price'] ?? 0);
            }
            $totalAmount = $subtotal - $discount;

            $stmt = $pdo->prepare("
                INSERT INTO retail_sales (org_id, sale_no, customer_name, sale_date, payment_mode, subtotal, discount, total_amount, status)
                VALUES (?,?,?,?,?,?,?,?,'completed')
            ");
            $stmt->execute([$orgId, $saleNo, $customerName, $saleDate, $paymentMode, $subtotal, $discount, $totalAmount]);
            $saleId = $pdo->lastInsertId();

            $stmtItem = $pdo->prepare("
                INSERT INTO retail_sale_items (sale_id, product_id, quantity, unit_price, total_price)
                VALUES (?,?,?,?,?)
            ");
            $stmtStock = $pdo->prepare("UPDATE retail_products SET stock_qty = stock_qty - ? WHERE id=? AND org_id=? AND stock_qty >= ?");

            foreach ($items as $it) {
                $pid   = (int)($it['product_id'] ?? 0);
                $qty   = (int)($it['qty']        ?? 0);
                $price = (float)($it['unit_price']?? 0);
                if ($pid <= 0 || $qty <= 0) continue;

                $stmtItem->execute([$saleId, $pid, $qty, $price, $qty * $price]);
                $stmtStock->execute([$qty, $pid, $orgId, $qty]);
            }

            $pdo->commit();
            setFlash('success', "Sale {$saleNo} posted. Total: " . formatCurrency($totalAmount));
            logActivity('create', 'retail', "POS sale {$saleNo} totalling " . formatCurrency($totalAmount));
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error posting sale: ' . $e->getMessage());
        }
        redirect('sales.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Products for POS panel
$products = [];
try {
    $stmt = $pdo->prepare("SELECT id, product_name, sku, selling_price, stock_qty FROM retail_products WHERE org_id=? AND stock_qty>0 ORDER BY product_name");
    $stmt->execute([$orgId]);
    $products = $stmt->fetchAll();
} catch (Exception $e) {}

// Sales history
$sales = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, COUNT(i.id) as item_count
        FROM retail_sales s
        LEFT JOIN retail_sale_items i ON i.sale_id = s.id
        WHERE s.org_id = ?
        GROUP BY s.id
        ORDER BY s.sale_date DESC, s.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $sales = $stmt->fetchAll();
} catch (Exception $e) {}

$todaySales    = array_filter($sales, fn($s) => substr($s['sale_date'],0,10) === date('Y-m-d'));
$todayRevenue  = array_sum(array_column(array_values($todaySales), 'total_amount'));
$monthRevenue  = array_sum(array_column(array_filter($sales, fn($s) => substr($s['sale_date'],0,7) === date('Y-m')), 'total_amount'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-cash-register me-2" style="color:<?= $moduleColor ?>"></i>Sales & POS</h4>
    <p class="text-muted mb-0">Record point-of-sale transactions and view sales history</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#posModal">
    <i class="fas fa-plus-circle me-1"></i>New Sale
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-receipt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($todaySales) ?></div><div class="stat-label">Sales Today</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,0.12);color:#2980b9"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($todayRevenue) ?></div><div class="stat-label">Today's Revenue</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($monthRevenue) ?></div><div class="stat-label">This Month Revenue</div></div>
    </div>
  </div>
</div>

<!-- Sales Table -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white border-bottom py-3">
    <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>Sales History</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="salesTable">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Sale No.</th>
            <th>Customer</th>
            <th>Payment</th>
            <th class="text-center">Items</th>
            <th class="text-end">Subtotal</th>
            <th class="text-end">Discount</th>
            <th class="text-end">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sales)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-cash-register fa-3x mb-3 d-block"></i>No sales recorded yet.</td></tr>
          <?php else: foreach ($sales as $s): ?>
          <tr>
            <td><?= formatDate($s['sale_date']) ?></td>
            <td><span class="badge bg-primary"><?= e($s['sale_no']) ?></span></td>
            <td><?= e($s['customer_name'] ?: 'Walk-in') ?></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst($s['payment_mode']) ?></span></td>
            <td class="text-center"><?= (int)$s['item_count'] ?></td>
            <td class="text-end"><?= formatCurrency((float)$s['subtotal']) ?></td>
            <td class="text-end text-danger"><?= (float)$s['discount'] > 0 ? '- '.formatCurrency((float)$s['discount']) : '—' ?></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$s['total_amount']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- POS Modal -->
<div class="modal fade" id="posModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST" id="posForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="pos_sale">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-cash-register me-2"></i>New Sale</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Customer Name</label>
              <input type="text" name="customer_name" class="form-control" placeholder="Walk-in / Customer name">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Sale Date</label>
              <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="cash">Cash</option>
                <option value="mpesa">M-Pesa</option>
                <option value="card">Card</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="credit">Credit / Invoice</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Discount (<?= CURRENCY ?>)</label>
              <input type="number" step="0.01" name="discount" id="posDiscount" class="form-control" value="0" min="0" onchange="calcTotal()">
            </div>
          </div>

          <!-- Product selector -->
          <div class="mb-3 d-flex gap-2">
            <select id="productPicker" class="form-select">
              <option value="">-- Add Product --</option>
              <?php foreach ($products as $p): ?>
              <option value="<?= $p['id'] ?>"
                data-name="<?= e($p['product_name']) ?>"
                data-price="<?= (float)$p['selling_price'] ?>"
                data-stock="<?= (int)$p['stock_qty'] ?>">
                <?= e($p['product_name']) ?> — <?= formatCurrency((float)$p['selling_price']) ?> (Stock: <?= (int)$p['stock_qty'] ?>)
              </option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline-secondary" onclick="addCartItem()"><i class="fas fa-plus"></i></button>
          </div>

          <!-- Cart -->
          <div class="table-responsive">
            <table class="table table-sm border align-middle" id="cartTable">
              <thead class="table-light">
                <tr><th>Product</th><th class="text-center" style="width:100px">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Line Total</th><th style="width:40px"></th></tr>
              </thead>
              <tbody id="cartBody">
                <tr id="emptyCart"><td colspan="5" class="text-center text-muted py-3">No items added yet.</td></tr>
              </tbody>
              <tfoot>
                <tr class="table-light fw-bold">
                  <td colspan="3" class="text-end">Subtotal:</td>
                  <td class="text-end" id="cartSubtotal">KES 0.00</td>
                  <td></td>
                </tr>
                <tr class="fw-bold fs-5">
                  <td colspan="3" class="text-end text-success">TOTAL:</td>
                  <td class="text-end text-success" id="cartTotal">KES 0.00</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-cash-register me-1"></i>Post Sale</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#salesTable").DataTable({pageLength:15, order:[[0,"desc"]]});
});
let cartIdx = 0;
function addCartItem() {
    var opt = $("#productPicker option:selected");
    if (!opt.val()) return;
    var idx   = cartIdx++;
    var pid   = opt.val();
    var name  = opt.data("name");
    var price = parseFloat(opt.data("price")) || 0;
    var stock = parseInt(opt.data("stock")) || 999;

    $("#emptyCart").remove();
    var row = `<tr id="cart_${idx}">
        <td>${name}<input type="hidden" name="items[${idx}][product_id]" value="${pid}"></td>
        <td><input type="number" name="items[${idx}][qty]" class="form-control form-control-sm text-center" min="1" max="${stock}" value="1" onchange="updateLine(${idx},${price})"></td>
        <td class="text-end">${price.toFixed(2)}<input type="hidden" name="items[${idx}][unit_price]" value="${price}"></td>
        <td class="text-end fw-semibold" id="line_${idx}">${price.toFixed(2)}</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(${idx})"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $("#cartBody").append(row);
    $("#productPicker").val("");
    calcTotal();
}
function updateLine(idx, price) {
    var qty = parseInt($(`#cart_${idx} input[type=number]`).val()) || 0;
    $(`#line_${idx}`).text((qty * price).toFixed(2));
    calcTotal();
}
function removeItem(idx) {
    $(`#cart_${idx}`).remove();
    if ($("#cartBody tr").length === 0) {
        $("#cartBody").append('<tr id="emptyCart"><td colspan="5" class="text-center text-muted py-3">No items added yet.</td></tr>');
    }
    calcTotal();
}
function calcTotal() {
    var sub = 0;
    $("#cartBody tr:not(#emptyCart)").each(function() {
        sub += parseFloat($(this).find("td:nth-child(4)").text()) || 0;
    });
    var disc = parseFloat($("#posDiscount").val()) || 0;
    var total = sub - disc;
    $("#cartSubtotal").text("KES " + sub.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,"));
    $("#cartTotal").text("KES " + total.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,"));
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
