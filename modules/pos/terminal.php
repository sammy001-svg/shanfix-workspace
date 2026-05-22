<?php
/**
 * POS Live Terminal — full-screen, no sidebar layout
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireModuleAccess('pos');
$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Open shift detection ───────────────────────────────────────
$openShift = null;
try {
    $s = $pdo->prepare("SELECT * FROM pos_shifts WHERE org_id=? AND cashier_id=? AND status='open' ORDER BY id DESC LIMIT 1");
    $s->execute([$orgId, $user['id']]);
    $openShift = $s->fetch();
} catch (Exception $e) {}

// ── Live shift totals for cash-up panel ────────────────────────
$shiftTotals = ['cnt' => 0, 'total_sales' => 0, 'cash_sales' => 0, 'mpesa_sales' => 0, 'card_sales' => 0, 'expenses' => 0];
if ($openShift) {
    try {
        $s = $pdo->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(total),0) AS total_sales,
                    COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total ELSE 0 END),0) AS cash_sales,
                    COALESCE(SUM(CASE WHEN payment_method='mpesa' THEN total ELSE 0 END),0) AS mpesa_sales,
                    COALESCE(SUM(CASE WHEN payment_method='card'  THEN total ELSE 0 END),0) AS card_sales
             FROM pos_sales WHERE org_id=? AND shift_id=? AND status!='void'"
        );
        $s->execute([$orgId, $openShift['id']]);
        $row = $s->fetch();
        if ($row) $shiftTotals = array_merge($shiftTotals, $row);

        $e2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_expenses WHERE org_id=? AND shift_id=?");
        $e2->execute([$orgId, $openShift['id']]);
        $shiftTotals['expenses'] = (float)$e2->fetchColumn();
    } catch (Exception $e) {}
}

// ── Process sale POST ──────────────────────────────────────────
$receiptData = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $cartJson   = $_POST['cart']            ?? '[]';
    $custName   = sanitize($_POST['customer_name']  ?? '');
    $payMethod  = in_array($_POST['payment_method'] ?? '', ['cash','mpesa','card','credit'])
                  ? $_POST['payment_method'] : 'cash';
    $mpesaRcpt  = sanitize($_POST['mpesa_receipt']  ?? '');
    $amtPaid    = (float)($_POST['amount_paid']     ?? 0);
    $discount   = (float)($_POST['discount']        ?? 0);
    $shiftId    = (int)($_POST['shift_id']          ?? 0) ?: null;
    $taxRate    = 0.16;

    $cart = json_decode($cartJson, true);
    if (!is_array($cart) || empty($cart)) {
        $error = 'Cart is empty.';
    } else {
        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += (float)$item['price'] * (int)$item['qty'];
        }
        $subtotal -= $discount;
        $tax    = round($subtotal * $taxRate, 2);
        $total  = round($subtotal + $tax, 2);
        $change = max(0, $amtPaid - $total);

        $rcptCount = countRows('pos_sales', 'org_id=?', [$orgId]) + 1;
        $receiptNo = 'RCP-' . date('ymd') . '-' . str_pad($rcptCount, 4, '0', STR_PAD_LEFT);

        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO pos_sales
                    (org_id, receipt_no, cashier_id, shift_id, customer_name,
                     subtotal, discount, tax, total, amount_paid, change_given,
                     payment_method, mpesa_receipt, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'completed')"
            )->execute([$orgId, $receiptNo, $user['id'], $shiftId, $custName,
                        $subtotal + $discount, $discount, $tax, $total,
                        $amtPaid, $change, $payMethod, $mpesaRcpt]);
            $saleId = $pdo->lastInsertId();

            foreach ($cart as $item) {
                $pid   = (int)($item['id']    ?? 0);
                $name  = sanitize($item['name'] ?? '');
                $qty   = (int)($item['qty']    ?? 1);
                $price = (float)($item['price'] ?? 0);
                $lineTotal = round($price * $qty, 2);

                $pdo->prepare(
                    "INSERT INTO pos_sale_items (sale_id, product_id, product_name, quantity, unit_price, total)
                     VALUES (?,?,?,?,?,?)"
                )->execute([$saleId, $pid ?: null, $name, $qty, $price, $lineTotal]);

                if ($pid) {
                    $pdo->prepare(
                        "UPDATE pos_products SET stock_quantity = stock_quantity - ?
                         WHERE id=? AND org_id=? AND stock_quantity >= ?"
                    )->execute([$qty, $pid, $orgId, $qty]);
                }
            }

            $pdo->commit();
            logActivity('sale', 'pos', "Sale $receiptNo — KES " . number_format($total, 2));
            $receiptData = compact('receiptNo','custName','payMethod','mpesaRcpt',
                                   'subtotal','discount','tax','total','amtPaid','change','cart');

            // Refresh shift totals after sale
            if ($shiftId) {
                $s = $pdo->prepare(
                    "SELECT COUNT(*) AS cnt,
                            COALESCE(SUM(total),0) AS total_sales,
                            COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total ELSE 0 END),0) AS cash_sales,
                            COALESCE(SUM(CASE WHEN payment_method='mpesa' THEN total ELSE 0 END),0) AS mpesa_sales,
                            COALESCE(SUM(CASE WHEN payment_method='card'  THEN total ELSE 0 END),0) AS card_sales
                     FROM pos_sales WHERE org_id=? AND shift_id=? AND status!='void'"
                );
                $s->execute([$orgId, $shiftId]);
                $row = $s->fetch();
                if ($row) $shiftTotals = array_merge($shiftTotals, $row);
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Sale failed. Please try again.';
            error_log('[POS Terminal] ' . $e->getMessage());
        }
    }
}

// ── Fetch products & categories ────────────────────────────────
$categories = $pdo->prepare("SELECT * FROM pos_categories WHERE org_id=? AND is_active=1 ORDER BY name");
$categories->execute([$orgId]);
$categories = $categories->fetchAll();

$products = $pdo->prepare(
    "SELECT p.*, c.name AS cat_name, c.color AS cat_color
     FROM pos_products p
     LEFT JOIN pos_categories c ON p.category_id = c.id
     WHERE p.org_id=? AND p.is_active=1
     ORDER BY p.name"
);
$products->execute([$orgId]);
$products = $products->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS Terminal — <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; background: #0f172a; color: #e2e8f0; font-family: 'Segoe UI', Arial, sans-serif; overflow: hidden; }

  /* Top bar */
  .pos-topbar { height: 52px; background: #1e293b; display: flex; align-items: center; justify-content: space-between; padding: 0 14px; border-bottom: 1px solid #334155; flex-shrink: 0; gap: 10px; }
  .pos-topbar .brand { font-weight: 800; color: #e74c3c; font-size: .95rem; white-space: nowrap; }
  .pos-topbar .shift-pill { display: flex; align-items: center; gap: 6px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 4px 10px; font-size: .75rem; }
  .pos-topbar .shift-pill.no-shift { border-color: #f59e0b; color: #f59e0b; }
  .pos-topbar .shift-pill.active { border-color: #4ade80; color: #4ade80; }
  .pos-topbar .topbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
  .topbar-btn { font-size: .75rem; padding: 4px 12px; }

  /* Layout */
  .pos-body { display: flex; height: calc(100vh - 52px); }

  /* Left panel */
  .pos-products { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #0f172a; }
  .pos-search-bar { padding: 8px 12px; background: #1e293b; border-bottom: 1px solid #334155; display: flex; gap: 8px; align-items: center; }
  .pos-search-bar input { flex: 1; background: #0f172a; border: 1px solid #334155; color: #e2e8f0; border-radius: 8px; padding: 7px 12px; font-size: .85rem; }
  .pos-search-bar input::placeholder { color: #475569; }
  .pos-search-bar input:focus { outline: none; border-color: #e74c3c; }
  .barcode-indicator { font-size: .7rem; color: #64748b; white-space: nowrap; }
  .barcode-indicator.scanning { color: #4ade80; }
  .cat-tabs { display: flex; gap: 6px; padding: 8px 12px; background: #1e293b; overflow-x: auto; flex-shrink: 0; scrollbar-width: none; }
  .cat-tabs::-webkit-scrollbar { display: none; }
  .cat-tab { padding: 4px 14px; border-radius: 20px; font-size: .78rem; border: 1px solid #334155; cursor: pointer; white-space: nowrap; transition: all .15s; background: #0f172a; color: #94a3b8; }
  .cat-tab.active, .cat-tab:hover { background: #e74c3c; border-color: #e74c3c; color: white; }
  .products-grid { flex: 1; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; padding: 12px; align-content: start; }
  .prod-card { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 12px 10px; cursor: pointer; transition: all .15s; text-align: center; position: relative; }
  .prod-card:hover { border-color: #e74c3c; background: #2a1a1a; transform: translateY(-1px); }
  .prod-card.out-of-stock { opacity: .45; cursor: not-allowed; }
  .prod-card.flash-added { border-color: #4ade80 !important; background: #052e16 !important; }
  .prod-card .prod-name { font-size: .8rem; font-weight: 600; color: #e2e8f0; margin-bottom: 4px; line-height: 1.3; }
  .prod-card .prod-price { font-size: .95rem; font-weight: 800; color: #e74c3c; }
  .prod-card .prod-stock { font-size: .7rem; color: #64748b; margin-top: 2px; }
  .prod-card .prod-sku { font-size: .65rem; color: #475569; }
  .prod-card .prod-icon { font-size: 1.4rem; color: #475569; margin-bottom: 6px; }
  .low-stock-dot { position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; border-radius: 50%; background: #f59e0b; }

  /* Right panel — cart */
  .pos-cart { width: 360px; flex-shrink: 0; background: #1e293b; display: flex; flex-direction: column; border-left: 1px solid #334155; }
  .cart-header { padding: 10px 16px; border-bottom: 1px solid #334155; font-weight: 700; font-size: .9rem; display: flex; align-items: center; justify-content: space-between; }
  .cart-items { flex: 1; overflow-y: auto; padding: 8px; }
  .cart-empty { text-align: center; padding: 40px 20px; color: #475569; }
  .cart-item { display: flex; align-items: center; gap: 8px; padding: 8px; background: #0f172a; border-radius: 8px; margin-bottom: 6px; }
  .cart-item-name { flex: 1; font-size: .8rem; font-weight: 600; color: #e2e8f0; line-height: 1.3; }
  .cart-item-price { font-size: .72rem; color: #94a3b8; }
  .qty-controls { display: flex; align-items: center; gap: 4px; }
  .qty-btn { width: 24px; height: 24px; border-radius: 6px; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; font-size: .85rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .1s; }
  .qty-btn:hover { background: #e74c3c; border-color: #e74c3c; }
  .qty-val { min-width: 24px; text-align: center; font-weight: 700; font-size: .85rem; }
  .remove-btn { color: #ef4444; cursor: pointer; padding: 2px 4px; font-size: .85rem; }

  /* Totals & Payment */
  .cart-footer { padding: 10px 12px; border-top: 1px solid #334155; }
  .totals-row { display: flex; justify-content: space-between; font-size: .82rem; padding: 2px 0; color: #94a3b8; }
  .totals-row.total { font-size: 1.05rem; font-weight: 800; color: #e2e8f0; padding-top: 6px; border-top: 1px solid #334155; margin-top: 4px; }
  .payment-row { display: flex; gap: 6px; margin: 8px 0 5px; }
  .pay-btn { flex: 1; padding: 5px 4px; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: #94a3b8; font-size: .72rem; cursor: pointer; transition: all .15s; text-align: center; }
  .pay-btn.active { background: #e74c3c; border-color: #e74c3c; color: white; font-weight: 700; }
  .pay-btn i { display: block; margin-bottom: 2px; font-size: .9rem; }
  .denoms { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 6px; }
  .denom-btn { padding: 3px 8px; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: #94a3b8; font-size: .72rem; cursor: pointer; transition: all .12s; }
  .denom-btn:hover { background: #334155; color: #e2e8f0; }
  .charge-btn { width: 100%; padding: 11px; border-radius: 10px; background: #e74c3c; border: none; color: white; font-weight: 800; font-size: .95rem; cursor: pointer; transition: opacity .15s; margin-top: 5px; }
  .charge-btn:hover { opacity: .9; }
  .charge-btn:disabled { opacity: .4; cursor: not-allowed; }
  .form-input-dark { background: #0f172a; border: 1px solid #334155; color: #e2e8f0; border-radius: 8px; padding: 6px 10px; width: 100%; font-size: .82rem; }
  .form-input-dark:focus { outline: none; border-color: #e74c3c; }
  .change-display { text-align: center; background: #052e16; border-radius: 8px; padding: 5px; margin-top: 4px; font-weight: 800; color: #4ade80; font-size: .88rem; display: none; }

  /* Receipt modal */
  .receipt-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.8); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
  .receipt-box { background: white; color: #1e293b; width: 380px; border-radius: 16px; overflow: hidden; max-height: 90vh; display: flex; flex-direction: column; }
  .receipt-header { background: #e74c3c; color: white; padding: 16px; text-align: center; flex-shrink: 0; }
  .receipt-body { padding: 16px; font-size: .83rem; overflow-y: auto; flex: 1; }
  .receipt-divider { border: none; border-top: 1px dashed #cbd5e1; margin: 8px 0; }
  .receipt-total { font-size: 1.4rem; font-weight: 800; color: #e74c3c; text-align: center; margin: 6px 0; }
  .receipt-footer { padding: 10px 14px; display: flex; gap: 8px; border-top: 1px solid #e2e8f0; flex-shrink: 0; }
  .receipt-footer .btn { flex: 1; }

  /* Cash-up modal */
  .cashup-modal { position: fixed; inset: 0; background: rgba(0,0,0,.8); z-index: 9998; display: none; align-items: center; justify-content: center; }
  .cashup-modal.open { display: flex; }
  .cashup-box { background: #1e293b; border: 1px solid #334155; border-radius: 16px; width: 420px; overflow: hidden; }
  .cashup-header { background: #0f172a; padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #334155; }
  .cashup-body { padding: 18px; }
  .cashup-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #1e3a2a; font-size: .9rem; }
  .cashup-row.total-row { font-size: 1.05rem; font-weight: 800; border-bottom: none; padding-top: 10px; }
  .cashup-row .label { color: #94a3b8; }
  .cashup-footer { padding: 14px 18px; border-top: 1px solid #334155; display: flex; gap: 8px; }

  /* No-shift banner */
  .noshift-banner { background: #431407; border-bottom: 2px solid #f59e0b; padding: 8px 16px; font-size: .82rem; color: #fcd34d; display: flex; align-items: center; gap: 8px; }

  @media print {
    .pos-topbar, .pos-products, .pos-cart, .receipt-footer, .noshift-banner { display: none !important; }
    .receipt-overlay { position: static; background: none; padding: 0; }
    .receipt-box { width: 100%; border-radius: 0; box-shadow: none; border: none; }
    .receipt-body { overflow: visible; }
  }
</style>
</head>
<body>

<!-- ── Top Bar ─────────────────────────────────────────────────── -->
<div class="pos-topbar">
  <div class="brand"><i class="fas fa-cash-register me-2"></i><?= APP_NAME ?></div>

  <?php if ($openShift): ?>
  <div class="shift-pill active">
    <i class="fas fa-circle" style="font-size:.5rem"></i>
    Shift #<?= $openShift['id'] ?>
    &bull; Float: <?= formatCurrency((float)$openShift['opening_float']) ?>
    &bull; Sales: <?= formatCurrency((float)$shiftTotals['total_sales']) ?>
    &bull; <?= $shiftTotals['cnt'] ?> txns
  </div>
  <?php else: ?>
  <div class="shift-pill no-shift">
    <i class="fas fa-exclamation-triangle"></i> No open shift — <a href="shifts.php" style="color:inherit;text-decoration:underline">open one first</a>
  </div>
  <?php endif; ?>

  <div class="topbar-right">
    <span style="font-size:.73rem;color:#64748b"><i class="fas fa-user me-1"></i><?= e($user['name']) ?> &bull; <?= date('D d M') ?></span>
    <?php if ($openShift): ?>
    <button class="btn btn-sm btn-outline-success topbar-btn" onclick="openCashUp()">
      <i class="fas fa-calculator me-1"></i>Cash-Up
    </button>
    <?php endif; ?>
    <a href="shifts.php" class="btn btn-sm btn-outline-secondary topbar-btn"><i class="fas fa-clock me-1"></i>Shifts</a>
    <a href="sales.php"  class="btn btn-sm btn-outline-secondary topbar-btn"><i class="fas fa-times"></i></a>
  </div>
</div>

<?php if (!$openShift): ?>
<div class="noshift-banner">
  <i class="fas fa-exclamation-triangle"></i>
  <strong>Warning:</strong> You have no open shift. Sales will not be linked to a shift report.
  <a href="shifts.php" style="color:#fcd34d;text-decoration:underline;margin-left:4px">Open a shift</a>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div style="position:fixed;top:60px;left:50%;transform:translateX(-50%);z-index:999;background:#7f1d1d;color:white;padding:10px 20px;border-radius:8px;font-size:.85rem">
  <i class="fas fa-exclamation-triangle me-2"></i><?= e($error) ?>
</div>
<?php endif; ?>

<!-- ── POS Body ───────────────────────────────────────────────── -->
<div class="pos-body">

  <!-- Products Panel -->
  <div class="pos-products">
    <div class="pos-search-bar">
      <input type="text" id="productSearch"
             placeholder="&#xF02A;  Search or scan barcode (Enter to add)..."
             autocomplete="off" autofocus>
      <span class="barcode-indicator" id="barcodeIndicator">
        <i class="fas fa-barcode me-1"></i>Ready
      </span>
    </div>
    <div class="cat-tabs">
      <div class="cat-tab active" data-cat="all">All</div>
      <?php foreach ($categories as $cat): ?>
      <div class="cat-tab" data-cat="<?= $cat['id'] ?>">
        <?= e($cat['name']) ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="products-grid" id="productsGrid">
      <?php foreach ($products as $p):
        $lowStock = ($p['stock_quantity'] > 0 && $p['stock_quantity'] <= 5);
      ?>
      <div class="prod-card <?= $p['stock_quantity'] <= 0 ? 'out-of-stock' : '' ?>"
           data-id="<?= $p['id'] ?>"
           data-name="<?= e($p['name']) ?>"
           data-price="<?= $p['price'] ?>"
           data-stock="<?= $p['stock_quantity'] ?>"
           data-cat="<?= $p['category_id'] ?>"
           data-sku="<?= e($p['sku'] ?? '') ?>"
           data-barcode="<?= e($p['barcode'] ?? '') ?>"
           onclick="addToCart(this)">
        <?php if ($lowStock): ?><div class="low-stock-dot" title="Low stock"></div><?php endif; ?>
        <div class="prod-icon"><i class="fas fa-box"></i></div>
        <div class="prod-name"><?= e($p['name']) ?></div>
        <div class="prod-price"><?= CURRENCY_SYMBOL ?><?= number_format((float)$p['price'], 2) ?></div>
        <div class="prod-stock">
          <?php if ($p['stock_quantity'] > 0): ?>
            <?= $lowStock ? '<span style="color:#f59e0b">Low: ' . $p['stock_quantity'] . '</span>' : 'Stock: ' . $p['stock_quantity'] ?>
          <?php else: ?>
            <span style="color:#ef4444">Out of stock</span>
          <?php endif; ?>
        </div>
        <?php if (!empty($p['sku'])): ?>
        <div class="prod-sku"><?= e($p['sku']) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Cart Panel -->
  <div class="pos-cart">
    <div class="cart-header">
      <span><i class="fas fa-shopping-cart me-2 text-danger"></i>Cart</span>
      <button onclick="clearCart()" style="background:none;border:none;color:#64748b;font-size:.73rem;cursor:pointer">
        <i class="fas fa-trash me-1"></i>Clear
      </button>
    </div>

    <div class="cart-items" id="cartItems">
      <div class="cart-empty" id="cartEmpty">
        <i class="fas fa-shopping-basket fa-2x mb-2 d-block" style="color:#334155"></i>
        <div>Click products or scan barcode</div>
        <div style="font-size:.73rem;color:#475569;margin-top:4px">F2 = focus search &bull; F10 = charge &bull; F3 = cash-up</div>
      </div>
    </div>

    <div class="cart-footer">
      <input type="text" id="customerName" class="form-input-dark mb-2" placeholder="Customer name (optional)">
      <input type="number" id="discountInput" class="form-input-dark mb-2" placeholder="Discount (<?= CURRENCY_SYMBOL ?>)" min="0" step="0.01" oninput="recalculate()">

      <div class="totals-row"><span>Subtotal</span><span id="subtotalDisplay"><?= CURRENCY_SYMBOL ?>0.00</span></div>
      <div class="totals-row"><span>Discount</span><span id="discountDisplay"><?= CURRENCY_SYMBOL ?>0.00</span></div>
      <div class="totals-row"><span>VAT (16%)</span><span id="taxDisplay"><?= CURRENCY_SYMBOL ?>0.00</span></div>
      <div class="totals-row total"><span>TOTAL</span><span id="totalDisplay"><?= CURRENCY_SYMBOL ?>0.00</span></div>

      <div class="payment-row">
        <div class="pay-btn active" data-method="cash"  onclick="setPayment('cash')"><i class="fas fa-money-bill-wave"></i>Cash</div>
        <div class="pay-btn"       data-method="mpesa"  onclick="setPayment('mpesa')"><i class="fas fa-mobile-alt"></i>M-Pesa</div>
        <div class="pay-btn"       data-method="card"   onclick="setPayment('card')"><i class="fas fa-credit-card"></i>Card</div>
        <div class="pay-btn"       data-method="credit" onclick="setPayment('credit')"><i class="fas fa-file-invoice"></i>Credit</div>
      </div>

      <div id="mpesaField" style="display:none" class="mb-2">
        <input type="text" id="mpesaReceipt" class="form-input-dark" placeholder="M-Pesa receipt number (e.g. QJK4T...)">
      </div>

      <input type="number" id="amountPaid" class="form-input-dark mb-1" placeholder="Amount tendered" min="0" step="0.01" oninput="showChange()">

      <!-- Denomination quick buttons -->
      <div class="denoms" id="denomRow">
        <?php foreach ([50,100,200,500,1000] as $d): ?>
        <button type="button" class="denom-btn" onclick="setDenom(<?= $d ?>)"><?= $d ?></button>
        <?php endforeach; ?>
        <button type="button" class="denom-btn" onclick="setExact()" style="margin-left:auto">Exact</button>
      </div>

      <div class="change-display" id="changeDisplay"></div>

      <form method="POST" id="saleForm">
        <?= csrfField() ?>
        <input type="hidden" name="cart"            id="cartJson">
        <input type="hidden" name="customer_name"   id="fCustomer">
        <input type="hidden" name="payment_method"  id="fPayMethod" value="cash">
        <input type="hidden" name="mpesa_receipt"   id="fMpesa">
        <input type="hidden" name="amount_paid"     id="fAmtPaid">
        <input type="hidden" name="discount"        id="fDiscount">
        <input type="hidden" name="shift_id"        id="fShiftId" value="<?= $openShift['id'] ?? 0 ?>">
      </form>

      <button class="charge-btn" id="chargeBtn" onclick="chargeSale()" disabled>
        <i class="fas fa-bolt me-2"></i>CHARGE — <span id="chargeBtnAmt"><?= CURRENCY_SYMBOL ?>0.00</span>
      </button>
    </div>
  </div>
</div>

<!-- ── Receipt Modal ─────────────────────────────────────────── -->
<?php if ($receiptData): ?>
<div class="receipt-overlay" id="receiptOverlay">
  <div class="receipt-box">
    <div class="receipt-header">
      <div style="font-size:2rem"><i class="fas fa-check-circle"></i></div>
      <div style="font-weight:800;font-size:1.05rem;margin-top:4px">Payment Successful</div>
      <div style="font-size:.82rem;opacity:.85"><?= e($receiptData['receiptNo']) ?></div>
    </div>
    <div class="receipt-body">
      <div class="text-center mb-2" style="font-weight:700;font-size:.9rem"><?= e(APP_NAME) ?></div>
      <div class="d-flex justify-content-between text-muted mb-1">
        <span><?= date('d M Y H:i') ?></span>
        <span>Cashier: <?= e($user['name']) ?></span>
      </div>
      <?php if ($receiptData['custName']): ?>
      <div class="mb-1"><span class="text-muted">Customer:</span> <strong><?= e($receiptData['custName']) ?></strong></div>
      <?php endif; ?>
      <hr class="receipt-divider">
      <?php foreach ($receiptData['cart'] as $item): ?>
      <div class="d-flex justify-content-between py-1">
        <span><?= e($item['name']) ?> <span class="text-muted">× <?= (int)$item['qty'] ?></span></span>
        <span><?= CURRENCY_SYMBOL ?><?= number_format((float)$item['price'] * (int)$item['qty'], 2) ?></span>
      </div>
      <?php endforeach; ?>
      <hr class="receipt-divider">
      <div class="d-flex justify-content-between text-muted">
        <span>Subtotal</span>
        <span><?= CURRENCY_SYMBOL ?><?= number_format((float)$receiptData['subtotal'] + (float)$receiptData['discount'], 2) ?></span>
      </div>
      <?php if ((float)$receiptData['discount'] > 0): ?>
      <div class="d-flex justify-content-between text-danger">
        <span>Discount</span><span>− <?= CURRENCY_SYMBOL ?><?= number_format((float)$receiptData['discount'], 2) ?></span>
      </div>
      <?php endif; ?>
      <div class="d-flex justify-content-between text-muted">
        <span>VAT (16%)</span><span><?= CURRENCY_SYMBOL ?><?= number_format((float)$receiptData['tax'], 2) ?></span>
      </div>
      <div class="receipt-total"><?= CURRENCY_SYMBOL ?><?= number_format((float)$receiptData['total'], 2) ?></div>
      <hr class="receipt-divider">
      <?php
        $pmLabel = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'card' => 'Card', 'credit' => 'Credit'];
        $pm = $receiptData['payMethod'];
      ?>
      <div class="d-flex justify-content-between">
        <span class="text-muted">Payment</span>
        <span class="fw-semibold">
          <?= $pmLabel[$pm] ?? ucfirst($pm) ?>
          <?php if ($pm === 'mpesa' && $receiptData['mpesaRcpt']): ?>
          <span class="text-muted">(<?= e($receiptData['mpesaRcpt']) ?>)</span>
          <?php endif; ?>
        </span>
      </div>
      <?php if ($pm === 'cash'): ?>
      <div class="d-flex justify-content-between text-muted">
        <span>Tendered</span><span><?= CURRENCY_SYMBOL ?><?= number_format((float)$receiptData['amtPaid'], 2) ?></span>
      </div>
      <?php if ((float)$receiptData['change'] > 0): ?>
      <div class="d-flex justify-content-between fw-bold text-success">
        <span>Change</span><span><?= CURRENCY_SYMBOL ?><?= number_format((float)$receiptData['change'], 2) ?></span>
      </div>
      <?php endif; ?>
      <?php endif; ?>
      <div class="text-center text-muted mt-3" style="font-size:.73rem">Thank you for your business!</div>
    </div>
    <div class="receipt-footer">
      <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
      <button class="btn btn-outline-primary"   onclick="copyReceipt()"><i class="fas fa-copy me-1"></i>Copy</button>
      <button class="btn btn-danger"            onclick="document.getElementById('receiptOverlay').remove()">
        <i class="fas fa-plus me-1"></i>New Sale
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Cash-Up Modal ─────────────────────────────────────────── -->
<?php if ($openShift): ?>
<div class="cashup-modal" id="cashUpModal">
  <div class="cashup-box">
    <div class="cashup-header">
      <div>
        <div style="font-weight:700;color:#e2e8f0"><i class="fas fa-calculator me-2 text-success"></i>Shift Cash-Up Summary</div>
        <div style="font-size:.75rem;color:#64748b">
          Shift #<?= $openShift['id'] ?> &bull; <?= e($openShift['cashier_name']) ?> &bull;
          Started <?= date('H:i', strtotime($openShift['start_time'])) ?>
        </div>
      </div>
      <button onclick="closeCashUp()" style="background:none;border:none;color:#64748b;font-size:1.2rem;cursor:pointer">&times;</button>
    </div>
    <div class="cashup-body">
      <?php
        $expectedCash = (float)$openShift['opening_float']
                      + (float)$shiftTotals['cash_sales']
                      - (float)$shiftTotals['expenses'];
      ?>
      <div class="cashup-row">
        <span class="label">Opening Float</span>
        <span><?= formatCurrency((float)$openShift['opening_float']) ?></span>
      </div>
      <div class="cashup-row">
        <span class="label"><i class="fas fa-money-bill-wave me-1 text-success"></i>Cash Sales</span>
        <span class="text-success"><?= formatCurrency((float)$shiftTotals['cash_sales']) ?></span>
      </div>
      <div class="cashup-row">
        <span class="label"><i class="fas fa-mobile-alt me-1 text-info"></i>M-Pesa Sales</span>
        <span style="color:#38bdf8"><?= formatCurrency((float)$shiftTotals['mpesa_sales']) ?></span>
      </div>
      <div class="cashup-row">
        <span class="label"><i class="fas fa-credit-card me-1 text-primary"></i>Card Sales</span>
        <span style="color:#818cf8"><?= formatCurrency((float)$shiftTotals['card_sales']) ?></span>
      </div>
      <div class="cashup-row">
        <span class="label"><i class="fas fa-wallet me-1 text-danger"></i>Cash Expenses</span>
        <span class="text-danger">− <?= formatCurrency((float)$shiftTotals['expenses']) ?></span>
      </div>
      <div class="cashup-row" style="border-top:1px solid #334155;padding-top:8px;margin-top:4px">
        <span class="label">Total Sales</span>
        <span class="fw-bold"><?= formatCurrency((float)$shiftTotals['total_sales']) ?></span>
      </div>
      <div class="cashup-row">
        <span class="label">Transactions</span>
        <span><?= $shiftTotals['cnt'] ?></span>
      </div>
      <div class="cashup-row total-row">
        <span class="label" style="color:#e2e8f0">Expected Cash in Drawer</span>
        <span style="color:#4ade80;font-size:1.1rem"><?= formatCurrency($expectedCash) ?></span>
      </div>
    </div>
    <div class="cashup-footer">
      <a href="shift-report.php?id=<?= $openShift['id'] ?>" target="_blank"
         class="btn btn-sm btn-outline-success flex-fill">
        <i class="fas fa-file-alt me-1"></i>Print Shift Report
      </a>
      <a href="shifts.php" class="btn btn-sm btn-danger flex-fill">
        <i class="fas fa-stop me-1"></i>Close Shift
      </a>
      <button onclick="closeCashUp()" class="btn btn-sm btn-secondary">Back</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let cart = [];
let payMethod = 'cash';
const TAX_RATE = 0.16;
const CURR = '<?= addslashes(CURRENCY_SYMBOL) ?>';

function fmt(n) {
  return CURR + parseFloat(n).toLocaleString('en-KE', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// ── Cart management ─────────────────────────────────────────────
function addToCart(el) {
  if (el.classList.contains('out-of-stock')) return;
  const id    = parseInt(el.dataset.id);
  const name  = el.dataset.name;
  const price = parseFloat(el.dataset.price);
  const stock = parseInt(el.dataset.stock);
  const existing = cart.find(i => i.id === id);
  if (existing) {
    if (existing.qty >= stock) return;
    existing.qty++;
  } else {
    cart.push({ id, name, price, qty: 1, stock });
  }
  // Flash the card green
  el.classList.add('flash-added');
  setTimeout(() => el.classList.remove('flash-added'), 300);
  renderCart();
}

function removeFromCart(id) {
  cart = cart.filter(i => i.id !== id);
  renderCart();
}

function changeQty(id, delta) {
  const item = cart.find(i => i.id === id);
  if (!item) return;
  item.qty = Math.max(1, Math.min(item.stock, item.qty + delta));
  renderCart();
}

function clearCart() {
  cart = [];
  document.getElementById('discountInput').value = '';
  document.getElementById('customerName').value  = '';
  document.getElementById('amountPaid').value    = '';
  document.getElementById('changeDisplay').style.display = 'none';
  renderCart();
}

function renderCart() {
  const container = document.getElementById('cartItems');
  if (cart.length === 0) {
    container.innerHTML = '';
    container.appendChild(document.getElementById('cartEmpty'));
    document.getElementById('chargeBtn').disabled = true;
    recalculate();
    return;
  }
  let html = '';
  cart.forEach(item => {
    html += `<div class="cart-item">
      <div style="flex:1">
        <div class="cart-item-name">${item.name}</div>
        <div class="cart-item-price">${fmt(item.price)} each &bull; Line: ${fmt(item.price * item.qty)}</div>
      </div>
      <div class="qty-controls">
        <div class="qty-btn" onclick="changeQty(${item.id}, -1)">−</div>
        <div class="qty-val">${item.qty}</div>
        <div class="qty-btn" onclick="changeQty(${item.id}, 1)">+</div>
      </div>
      <span class="remove-btn" onclick="removeFromCart(${item.id})"><i class="fas fa-trash-alt"></i></span>
    </div>`;
  });
  container.innerHTML = html;
  document.getElementById('chargeBtn').disabled = false;
  recalculate();
}

function recalculate() {
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  const sub      = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const afterDisc = Math.max(0, sub - discount);
  const tax       = afterDisc * TAX_RATE;
  const total     = afterDisc + tax;
  document.getElementById('subtotalDisplay').textContent = fmt(sub);
  document.getElementById('discountDisplay').textContent = fmt(discount);
  document.getElementById('taxDisplay').textContent      = fmt(tax);
  document.getElementById('totalDisplay').textContent    = fmt(total);
  document.getElementById('chargeBtnAmt').textContent    = fmt(total);
  showChange();
}

function showChange() {
  const paid  = parseFloat(document.getElementById('amountPaid').value) || 0;
  const sub   = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const disc  = parseFloat(document.getElementById('discountInput').value) || 0;
  const net   = Math.max(0, sub - disc) * (1 + TAX_RATE);
  const chg   = paid - net;
  const el    = document.getElementById('changeDisplay');
  if (paid > 0) {
    el.style.display = 'block';
    el.textContent   = chg >= 0 ? 'Change: ' + fmt(chg) : 'Balance due: ' + fmt(Math.abs(chg));
    el.style.color   = chg >= 0 ? '#4ade80' : '#f87171';
  } else {
    el.style.display = 'none';
  }
}

// ── Payment ─────────────────────────────────────────────────────
function setPayment(method) {
  payMethod = method;
  document.querySelectorAll('.pay-btn').forEach(b => b.classList.remove('active'));
  document.querySelector(`[data-method="${method}"]`).classList.add('active');
  document.getElementById('fPayMethod').value = method;
  document.getElementById('mpesaField').style.display = method === 'mpesa' ? 'block' : 'none';
  // Show denominations only for cash
  document.getElementById('denomRow').style.display = method === 'cash' ? '' : 'none';
}

function setDenom(val) {
  const sub   = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const disc  = parseFloat(document.getElementById('discountInput').value) || 0;
  const net   = Math.max(0, sub - disc) * (1 + TAX_RATE);
  // Round up to next multiple of val
  const amount = Math.ceil(net / val) * val;
  document.getElementById('amountPaid').value = amount.toFixed(2);
  showChange();
}

function setExact() {
  const sub  = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const disc = parseFloat(document.getElementById('discountInput').value) || 0;
  const net  = Math.max(0, sub - disc) * (1 + TAX_RATE);
  document.getElementById('amountPaid').value = net.toFixed(2);
  showChange();
}

function chargeSale() {
  if (cart.length === 0) return;
  const disc  = parseFloat(document.getElementById('discountInput').value) || 0;
  const sub   = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const net   = Math.max(0, sub - disc) * (1 + TAX_RATE);
  const paid  = parseFloat(document.getElementById('amountPaid').value) || 0;
  if (payMethod === 'cash' && paid < net - 0.01) {
    alert('Amount tendered is less than total due.');
    document.getElementById('amountPaid').focus();
    return;
  }
  document.getElementById('cartJson').value  = JSON.stringify(cart);
  document.getElementById('fCustomer').value = document.getElementById('customerName').value;
  document.getElementById('fMpesa').value    = document.getElementById('mpesaReceipt').value || '';
  document.getElementById('fAmtPaid').value  = paid || net.toFixed(2);
  document.getElementById('fDiscount').value = disc;
  document.getElementById('saleForm').submit();
}

// ── Barcode scan detection ──────────────────────────────────────
let lastKeyTime  = 0;
let scanBuffer   = '';
let scannerMode  = false;

document.getElementById('productSearch').addEventListener('keydown', function(e) {
  const now = Date.now();
  if (e.key === 'Enter') {
    e.preventDefault();
    const query = this.value.trim();
    if (!query) return;

    // Find product by exact SKU or barcode match first
    const allCards = [...document.querySelectorAll('.prod-card')];
    const byCode = allCards.find(c =>
      (c.dataset.sku && c.dataset.sku === query) ||
      (c.dataset.barcode && c.dataset.barcode === query)
    );
    if (byCode) {
      addToCart(byCode);
      this.value = '';
      flashBarcodeIndicator(true, 'Added: ' + byCode.dataset.name);
      return;
    }

    // Fall back: find unique name match
    const byName = allCards.filter(c => c.dataset.name.toLowerCase().includes(query.toLowerCase()));
    if (byName.length === 1) {
      addToCart(byName[0]);
      this.value = '';
      flashBarcodeIndicator(true, 'Added: ' + byName[0].dataset.name);
    } else {
      flashBarcodeIndicator(false, 'Not found: ' + query);
    }
    return;
  }

  // Detect scanner (very fast keystrokes, < 50ms apart)
  if (now - lastKeyTime < 50 && e.key.length === 1) {
    scannerMode = true;
    document.getElementById('barcodeIndicator').classList.add('scanning');
    document.getElementById('barcodeIndicator').innerHTML = '<i class="fas fa-barcode me-1"></i>Scanning…';
  } else if (now - lastKeyTime > 300) {
    scannerMode = false;
    document.getElementById('barcodeIndicator').classList.remove('scanning');
    document.getElementById('barcodeIndicator').innerHTML = '<i class="fas fa-barcode me-1"></i>Ready';
  }
  lastKeyTime = now;
});

function flashBarcodeIndicator(success, msg) {
  const el = document.getElementById('barcodeIndicator');
  el.classList.add('scanning');
  el.style.color = success ? '#4ade80' : '#f87171';
  el.innerHTML = '<i class="fas fa-' + (success ? 'check' : 'times') + ' me-1"></i>' + msg.substring(0, 30);
  setTimeout(() => {
    el.classList.remove('scanning');
    el.style.color = '';
    el.innerHTML = '<i class="fas fa-barcode me-1"></i>Ready';
  }, 1500);
}

// ── Category filter ─────────────────────────────────────────────
document.querySelectorAll('.cat-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    const cat = tab.dataset.cat;
    document.querySelectorAll('.prod-card').forEach(card => {
      card.style.display = (cat === 'all' || card.dataset.cat == cat) ? '' : 'none';
    });
    document.getElementById('productSearch').value = '';
  });
});

// ── Product search (live filter) ────────────────────────────────
document.getElementById('productSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  if (!q) {
    document.querySelectorAll('.prod-card').forEach(c => c.style.display = '');
    return;
  }
  document.querySelectorAll('.prod-card').forEach(card => {
    const match = card.dataset.name.toLowerCase().includes(q)
               || (card.dataset.sku    && card.dataset.sku.toLowerCase().includes(q))
               || (card.dataset.barcode && card.dataset.barcode.includes(q));
    card.style.display = match ? '' : 'none';
  });
});

// ── Cash-Up modal ───────────────────────────────────────────────
function openCashUp() {
  document.getElementById('cashUpModal').classList.add('open');
}
function closeCashUp() {
  document.getElementById('cashUpModal').classList.remove('open');
}

// ── Receipt copy (plain text) ───────────────────────────────────
function copyReceipt() {
  const body = document.querySelector('.receipt-body');
  if (body && navigator.clipboard) {
    navigator.clipboard.writeText(body.innerText).then(() => alert('Receipt copied to clipboard.'));
  }
}

// ── Keyboard shortcuts ──────────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.key === 'F2')  { e.preventDefault(); document.getElementById('productSearch').focus(); }
  if (e.key === 'F10') { e.preventDefault(); chargeSale(); }
  if (e.key === 'F3')  { e.preventDefault(); openCashUp(); }
  if (e.key === 'Escape') { closeCashUp(); }
});
</script>
</body>
</html>
