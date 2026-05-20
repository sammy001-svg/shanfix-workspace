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

// ── Process sale POST ──────────────────────────────────────────
$receiptData = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $cartJson  = $_POST['cart']           ?? '[]';
    $custName  = sanitize($_POST['customer_name'] ?? '');
    $payMethod = in_array($_POST['payment_method'] ?? '', ['cash','mpesa','card','credit'])
                 ? $_POST['payment_method'] : 'cash';
    $mpesaRcpt = sanitize($_POST['mpesa_receipt'] ?? '');
    $amtPaid   = (float)($_POST['amount_paid'] ?? 0);
    $discount  = (float)($_POST['discount']    ?? 0);
    $taxRate   = 0.16; // 16% VAT (set to 0 if not applicable)

    $cart = json_decode($cartJson, true);
    if (!is_array($cart) || empty($cart)) {
        $error = 'Cart is empty.';
    } else {
        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += (float)$item['price'] * (int)$item['qty'];
        }
        $subtotal -= $discount;
        $tax   = round($subtotal * $taxRate, 2);
        $total = round($subtotal + $tax, 2);
        $change = max(0, $amtPaid - $total);

        // Generate receipt number
        $rcptCount = countRows('pos_sales', 'org_id=?', [$orgId]) + 1;
        $receiptNo = 'RCP-' . date('ymd') . '-' . str_pad($rcptCount, 4, '0', STR_PAD_LEFT);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO pos_sales (org_id, receipt_no, cashier_id, customer_name, subtotal, discount, tax, total, amount_paid, change_given, payment_method, mpesa_receipt, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'completed')");
            $stmt->execute([$orgId, $receiptNo, $user['id'], $custName, $subtotal + $discount, $discount, $tax, $total, $amtPaid, $change, $payMethod, $mpesaRcpt]);
            $saleId = $pdo->lastInsertId();

            foreach ($cart as $item) {
                $pid   = (int)($item['id']    ?? 0);
                $name  = sanitize($item['name'] ?? '');
                $qty   = (int)($item['qty']    ?? 1);
                $price = (float)($item['price'] ?? 0);
                $lineTotal = round($price * $qty, 2);

                $pdo->prepare("INSERT INTO pos_sale_items (sale_id, product_id, product_name, quantity, unit_price, total) VALUES (?,?,?,?,?,?)")
                    ->execute([$saleId, $pid ?: null, $name, $qty, $price, $lineTotal]);

                if ($pid) {
                    $pdo->prepare("UPDATE pos_products SET stock_quantity = stock_quantity - ? WHERE id=? AND org_id=? AND stock_quantity >= ?")
                        ->execute([$qty, $pid, $orgId, $qty]);
                }
            }

            $pdo->commit();
            logActivity('sale', 'pos', "Sale $receiptNo — KES " . number_format($total, 2));
            $receiptData = compact('receiptNo','custName','payMethod','mpesaRcpt','subtotal','discount','tax','total','amtPaid','change','cart');
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

$products = $pdo->prepare("SELECT p.*, c.name AS cat_name, c.color AS cat_color FROM pos_products p LEFT JOIN pos_categories c ON p.category_id=c.id WHERE p.org_id=? AND p.is_active=1 ORDER BY p.name");
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
  .pos-topbar { height: 48px; background: #1e293b; display: flex; align-items: center; justify-content: space-between; padding: 0 16px; border-bottom: 1px solid #334155; flex-shrink: 0; }
  .pos-topbar .brand { font-weight: 800; color: #e74c3c; font-size: 1rem; }
  .pos-topbar .user-info { font-size: .8rem; color: #94a3b8; }
  .pos-topbar .exit-btn { font-size: .78rem; padding: 4px 12px; }

  /* Layout */
  .pos-body { display: flex; height: calc(100vh - 48px); }

  /* Left panel — products */
  .pos-products { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #0f172a; }
  .pos-search-bar { padding: 10px 12px; background: #1e293b; border-bottom: 1px solid #334155; display: flex; gap: 8px; }
  .pos-search-bar input { flex: 1; background: #0f172a; border: 1px solid #334155; color: #e2e8f0; border-radius: 8px; padding: 6px 12px; font-size: .85rem; }
  .pos-search-bar input::placeholder { color: #475569; }
  .pos-search-bar input:focus { outline: none; border-color: #e74c3c; }
  .cat-tabs { display: flex; gap: 6px; padding: 8px 12px; background: #1e293b; overflow-x: auto; flex-shrink: 0; scrollbar-width: none; }
  .cat-tabs::-webkit-scrollbar { display: none; }
  .cat-tab { padding: 4px 14px; border-radius: 20px; font-size: .78rem; border: 1px solid #334155; cursor: pointer; white-space: nowrap; transition: all .15s; background: #0f172a; color: #94a3b8; }
  .cat-tab.active, .cat-tab:hover { background: #e74c3c; border-color: #e74c3c; color: white; }
  .products-grid { flex: 1; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; padding: 12px; align-content: start; }
  .prod-card { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 12px 10px; cursor: pointer; transition: all .15s; text-align: center; }
  .prod-card:hover { border-color: #e74c3c; background: #2a1a1a; transform: translateY(-1px); }
  .prod-card.out-of-stock { opacity: .45; cursor: not-allowed; }
  .prod-card .prod-name { font-size: .8rem; font-weight: 600; color: #e2e8f0; margin-bottom: 4px; line-height: 1.3; }
  .prod-card .prod-price { font-size: .95rem; font-weight: 800; color: #e74c3c; }
  .prod-card .prod-stock { font-size: .7rem; color: #64748b; margin-top: 2px; }
  .prod-card .prod-icon { font-size: 1.4rem; color: #475569; margin-bottom: 6px; }

  /* Right panel — cart */
  .pos-cart { width: 340px; flex-shrink: 0; background: #1e293b; display: flex; flex-direction: column; border-left: 1px solid #334155; }
  .cart-header { padding: 12px 16px; border-bottom: 1px solid #334155; font-weight: 700; font-size: .9rem; display: flex; align-items: center; justify-content: space-between; }
  .cart-items { flex: 1; overflow-y: auto; padding: 8px; }
  .cart-empty { text-align: center; padding: 40px 20px; color: #475569; }
  .cart-item { display: flex; align-items: center; gap: 8px; padding: 8px; background: #0f172a; border-radius: 8px; margin-bottom: 6px; }
  .cart-item-name { flex: 1; font-size: .8rem; font-weight: 600; color: #e2e8f0; line-height: 1.3; }
  .cart-item-price { font-size: .75rem; color: #94a3b8; }
  .qty-controls { display: flex; align-items: center; gap: 4px; }
  .qty-btn { width: 24px; height: 24px; border-radius: 6px; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; font-size: .85rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .1s; }
  .qty-btn:hover { background: #e74c3c; border-color: #e74c3c; }
  .qty-val { min-width: 24px; text-align: center; font-weight: 700; font-size: .85rem; }
  .remove-btn { color: #ef4444; cursor: pointer; padding: 2px 4px; }

  /* Totals & Payment */
  .cart-footer { padding: 12px; border-top: 1px solid #334155; }
  .totals-row { display: flex; justify-content: space-between; font-size: .82rem; padding: 3px 0; color: #94a3b8; }
  .totals-row.total { font-size: 1.1rem; font-weight: 800; color: #e2e8f0; padding-top: 8px; border-top: 1px solid #334155; margin-top: 4px; }
  .payment-row { display: flex; gap: 6px; margin: 10px 0 6px; }
  .pay-btn { flex: 1; padding: 6px; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: #94a3b8; font-size: .75rem; cursor: pointer; transition: all .15s; text-align: center; }
  .pay-btn.active { background: #e74c3c; border-color: #e74c3c; color: white; font-weight: 700; }
  .charge-btn { width: 100%; padding: 12px; border-radius: 10px; background: #e74c3c; border: none; color: white; font-weight: 800; font-size: 1rem; cursor: pointer; transition: opacity .15s; margin-top: 6px; }
  .charge-btn:hover { opacity: .9; }
  .charge-btn:disabled { opacity: .4; cursor: not-allowed; }
  .form-input-dark { background: #0f172a; border: 1px solid #334155; color: #e2e8f0; border-radius: 8px; padding: 6px 10px; width: 100%; font-size: .82rem; }
  .form-input-dark:focus { outline: none; border-color: #e74c3c; }
  .change-display { text-align: center; background: #052e16; border-radius: 8px; padding: 6px; margin-top: 6px; font-weight: 800; color: #4ade80; font-size: .9rem; display: none; }

  /* Receipt overlay */
  .receipt-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.75); z-index: 9999; display: flex; align-items: center; justify-content: center; }
  .receipt-box { background: white; color: #1e293b; width: 360px; border-radius: 16px; overflow: hidden; }
  .receipt-header { background: #e74c3c; color: white; padding: 16px; text-align: center; }
  .receipt-body { padding: 16px; font-size: .85rem; }
  .receipt-divider { border: none; border-top: 1px dashed #cbd5e1; margin: 10px 0; }
  .receipt-total { font-size: 1.3rem; font-weight: 800; color: #e74c3c; text-align: center; margin: 8px 0; }
  .receipt-footer { padding: 12px 16px; display: flex; gap: 8px; border-top: 1px solid #e2e8f0; }
  @media print {
    .pos-topbar, .pos-products, .pos-cart, .receipt-footer { display: none !important; }
    .receipt-overlay { position: static; background: none; }
    .receipt-box { width: 100%; border-radius: 0; box-shadow: none; }
  }
</style>
</head>
<body>

<!-- Top Bar -->
<div class="pos-topbar">
  <div class="brand"><i class="fas fa-cash-register me-2"></i><?= APP_NAME ?> — POS Terminal</div>
  <div class="user-info"><i class="fas fa-user me-1"></i><?= e($user['name']) ?> &bull; <?= date('D, d M Y') ?></div>
  <a href="sales.php" class="btn btn-sm btn-outline-secondary exit-btn"><i class="fas fa-times me-1"></i>Exit Terminal</a>
</div>

<?php if (isset($error)): ?>
<div style="position:fixed;top:60px;left:50%;transform:translateX(-50%);z-index:999;background:#7f1d1d;color:white;padding:10px 20px;border-radius:8px;font-size:.85rem">
  <i class="fas fa-exclamation-triangle me-2"></i><?= e($error) ?>
</div>
<?php endif; ?>

<div class="pos-body">

  <!-- Products Panel -->
  <div class="pos-products">
    <div class="pos-search-bar">
      <input type="text" id="productSearch" placeholder="Search product or scan barcode..." autocomplete="off" autofocus>
    </div>
    <div class="cat-tabs">
      <div class="cat-tab active" data-cat="all">All</div>
      <?php foreach($categories as $cat): ?>
      <div class="cat-tab" data-cat="<?= $cat['id'] ?>" style="--cat-color:<?= e($cat['color']) ?>">
        <?= e($cat['name']) ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="products-grid" id="productsGrid">
      <?php foreach($products as $p): ?>
      <div class="prod-card <?= $p['stock_quantity'] <= 0 ? 'out-of-stock' : '' ?>"
           data-id="<?= $p['id'] ?>"
           data-name="<?= e($p['name']) ?>"
           data-price="<?= $p['price'] ?>"
           data-stock="<?= $p['stock_quantity'] ?>"
           data-cat="<?= $p['category_id'] ?>"
           onclick="addToCart(this)">
        <div class="prod-icon"><i class="fas fa-box"></i></div>
        <div class="prod-name"><?= e($p['name']) ?></div>
        <div class="prod-price">KES <?= number_format((float)$p['price'], 2) ?></div>
        <div class="prod-stock"><?= $p['stock_quantity'] > 0 ? 'Stock: '.$p['stock_quantity'] : 'Out of stock' ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Cart Panel -->
  <div class="pos-cart">
    <div class="cart-header">
      <span><i class="fas fa-shopping-cart me-2 text-danger"></i>Cart</span>
      <button onclick="clearCart()" style="background:none;border:none;color:#64748b;font-size:.75rem;cursor:pointer">Clear All</button>
    </div>

    <div class="cart-items" id="cartItems">
      <div class="cart-empty" id="cartEmpty">
        <i class="fas fa-shopping-basket fa-2x mb-2 d-block"></i>
        Click products to add them
      </div>
    </div>

    <div class="cart-footer">
      <!-- Customer & Discount -->
      <input type="text" id="customerName" class="form-input-dark mb-2" placeholder="Customer name (optional)">
      <div class="d-flex gap-2 mb-2">
        <input type="number" id="discountInput" class="form-input-dark" placeholder="Discount (KES)" min="0" step="0.01" oninput="recalculate()">
      </div>

      <!-- Totals -->
      <div class="totals-row"><span>Subtotal</span><span id="subtotalDisplay">KES 0.00</span></div>
      <div class="totals-row"><span>Discount</span><span id="discountDisplay">KES 0.00</span></div>
      <div class="totals-row"><span>VAT (16%)</span><span id="taxDisplay">KES 0.00</span></div>
      <div class="totals-row total"><span>TOTAL</span><span id="totalDisplay">KES 0.00</span></div>

      <!-- Payment Method -->
      <div class="payment-row">
        <div class="pay-btn active" data-method="cash" onclick="setPayment('cash')"><i class="fas fa-money-bill-wave d-block mb-1"></i>Cash</div>
        <div class="pay-btn" data-method="mpesa" onclick="setPayment('mpesa')"><i class="fas fa-mobile-alt d-block mb-1"></i>M-Pesa</div>
        <div class="pay-btn" data-method="card" onclick="setPayment('card')"><i class="fas fa-credit-card d-block mb-1"></i>Card</div>
      </div>

      <div id="mpesaField" style="display:none" class="mb-2">
        <input type="text" id="mpesaReceipt" class="form-input-dark" placeholder="M-Pesa Receipt No.">
      </div>

      <input type="number" id="amountPaid" class="form-input-dark mb-2" placeholder="Amount tendered" min="0" step="0.01" oninput="showChange()">
      <div class="change-display" id="changeDisplay"></div>

      <!-- Hidden form -->
      <form method="POST" id="saleForm">
        <?= csrfField() ?>
        <input type="hidden" name="cart"           id="cartJson">
        <input type="hidden" name="customer_name"  id="fCustomer">
        <input type="hidden" name="payment_method" id="fPayMethod" value="cash">
        <input type="hidden" name="mpesa_receipt"  id="fMpesa">
        <input type="hidden" name="amount_paid"    id="fAmtPaid">
        <input type="hidden" name="discount"       id="fDiscount">
      </form>

      <button class="charge-btn" id="chargeBtn" onclick="chargeSale()" disabled>
        <i class="fas fa-bolt me-2"></i>CHARGE — <span id="chargeBtnAmt">KES 0.00</span>
      </button>
    </div>
  </div>
</div>

<?php if ($receiptData): ?>
<!-- Receipt Overlay -->
<div class="receipt-overlay" id="receiptOverlay">
  <div class="receipt-box">
    <div class="receipt-header">
      <div style="font-size:1.5rem"><i class="fas fa-check-circle"></i></div>
      <div style="font-weight:800;font-size:1.1rem;margin-top:4px">Payment Successful</div>
      <div style="font-size:.85rem;opacity:.85"><?= e($receiptData['receiptNo']) ?></div>
    </div>
    <div class="receipt-body">
      <?php if ($receiptData['custName']): ?>
      <div><strong>Customer:</strong> <?= e($receiptData['custName']) ?></div>
      <?php endif; ?>
      <div><strong>Cashier:</strong> <?= e($user['name']) ?></div>
      <div><strong>Date:</strong> <?= date('d M Y H:i') ?></div>
      <hr class="receipt-divider">
      <?php foreach($receiptData['cart'] as $item): ?>
      <div class="d-flex justify-content-between">
        <span><?= e($item['name']) ?> × <?= (int)$item['qty'] ?></span>
        <span>KES <?= number_format((float)$item['price'] * (int)$item['qty'], 2) ?></span>
      </div>
      <?php endforeach; ?>
      <hr class="receipt-divider">
      <div class="d-flex justify-content-between text-muted"><span>Subtotal</span><span>KES <?= number_format((float)$receiptData['subtotal'] + (float)$receiptData['discount'], 2) ?></span></div>
      <?php if ((float)$receiptData['discount'] > 0): ?>
      <div class="d-flex justify-content-between text-danger"><span>Discount</span><span>- KES <?= number_format((float)$receiptData['discount'], 2) ?></span></div>
      <?php endif; ?>
      <div class="d-flex justify-content-between text-muted"><span>VAT (16%)</span><span>KES <?= number_format((float)$receiptData['tax'], 2) ?></span></div>
      <div class="receipt-total">KES <?= number_format((float)$receiptData['total'], 2) ?></div>
      <div class="d-flex justify-content-between text-muted"><span>Paid</span><span>KES <?= number_format((float)$receiptData['amtPaid'], 2) ?></span></div>
      <?php if ((float)$receiptData['change'] > 0): ?>
      <div class="d-flex justify-content-between text-success fw-700"><span>Change</span><span>KES <?= number_format((float)$receiptData['change'], 2) ?></span></div>
      <?php endif; ?>
      <div class="text-center text-muted small mt-2" style="font-size:.75rem">Thank you for your purchase!</div>
    </div>
    <div class="receipt-footer">
      <button class="btn btn-outline-secondary flex-1" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
      <button class="btn btn-danger flex-1" onclick="document.getElementById('receiptOverlay').remove()"><i class="fas fa-plus me-1"></i>New Sale</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let cart = [];
let payMethod = 'cash';
const TAX_RATE = 0.16;

function fmt(n) { return 'KES ' + parseFloat(n).toLocaleString('en-KE', {minimumFractionDigits:2, maximumFractionDigits:2}); }

function addToCart(el) {
  if (el.classList.contains('out-of-stock')) return;
  const id    = parseInt(el.dataset.id);
  const name  = el.dataset.name;
  const price = parseFloat(el.dataset.price);
  const stock = parseInt(el.dataset.stock);
  const existing = cart.find(i => i.id === id);
  if (existing) {
    if (existing.qty >= stock) { return; }
    existing.qty++;
  } else {
    cart.push({ id, name, price, qty: 1, stock });
  }
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
  renderCart();
}

function renderCart() {
  const container = document.getElementById('cartItems');
  const empty = document.getElementById('cartEmpty');
  if (cart.length === 0) {
    container.innerHTML = '';
    container.appendChild(empty);
    document.getElementById('chargeBtn').disabled = true;
    recalculate();
    return;
  }
  let html = '';
  cart.forEach(item => {
    html += `<div class="cart-item">
      <div>
        <div class="cart-item-name">${item.name}</div>
        <div class="cart-item-price">${fmt(item.price)} each</div>
      </div>
      <div class="qty-controls">
        <div class="qty-btn" onclick="changeQty(${item.id}, -1)">−</div>
        <div class="qty-val">${item.qty}</div>
        <div class="qty-btn" onclick="changeQty(${item.id}, 1)">+</div>
      </div>
      <div class="cart-item-name text-end" style="min-width:70px">${fmt(item.price * item.qty)}</div>
      <span class="remove-btn" onclick="removeFromCart(${item.id})"><i class="fas fa-trash-alt"></i></span>
    </div>`;
  });
  container.innerHTML = html;
  document.getElementById('chargeBtn').disabled = false;
  recalculate();
}

function recalculate() {
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  let sub = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const afterDiscount = Math.max(0, sub - discount);
  const tax   = afterDiscount * TAX_RATE;
  const total = afterDiscount + tax;
  document.getElementById('subtotalDisplay').textContent = fmt(sub);
  document.getElementById('discountDisplay').textContent = fmt(discount);
  document.getElementById('taxDisplay').textContent      = fmt(tax);
  document.getElementById('totalDisplay').textContent    = fmt(total);
  document.getElementById('chargeBtnAmt').textContent    = fmt(total);
  showChange();
}

function showChange() {
  const paid  = parseFloat(document.getElementById('amountPaid').value) || 0;
  const total = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const disc  = parseFloat(document.getElementById('discountInput').value) || 0;
  const net   = Math.max(0, total - disc) * (1 + TAX_RATE);
  const chg   = paid - net;
  const el    = document.getElementById('changeDisplay');
  if (paid > 0) {
    el.style.display = 'block';
    el.textContent   = chg >= 0 ? 'Change: ' + fmt(chg) : 'Balance: ' + fmt(Math.abs(chg));
    el.style.color   = chg >= 0 ? '#4ade80' : '#f87171';
  } else {
    el.style.display = 'none';
  }
}

function setPayment(method) {
  payMethod = method;
  document.querySelectorAll('.pay-btn').forEach(b => b.classList.remove('active'));
  document.querySelector(`[data-method="${method}"]`).classList.add('active');
  document.getElementById('fPayMethod').value = method;
  document.getElementById('mpesaField').style.display = method === 'mpesa' ? 'block' : 'none';
}

function chargeSale() {
  if (cart.length === 0) return;
  const disc  = parseFloat(document.getElementById('discountInput').value) || 0;
  const sub   = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const net   = Math.max(0, sub - disc) * (1 + TAX_RATE);
  const paid  = parseFloat(document.getElementById('amountPaid').value) || 0;
  if (payMethod === 'cash' && paid < net) {
    alert('Amount tendered is less than total. Please enter the correct amount.');
    return;
  }
  document.getElementById('cartJson').value    = JSON.stringify(cart);
  document.getElementById('fCustomer').value   = document.getElementById('customerName').value;
  document.getElementById('fMpesa').value      = document.getElementById('mpesaReceipt').value;
  document.getElementById('fAmtPaid').value    = paid || net;
  document.getElementById('fDiscount').value   = disc;
  document.getElementById('saleForm').submit();
}

// Category filter
document.querySelectorAll('.cat-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    const cat = tab.dataset.cat;
    document.querySelectorAll('.prod-card').forEach(card => {
      card.style.display = (cat === 'all' || card.dataset.cat == cat) ? '' : 'none';
    });
  });
});

// Product search
document.getElementById('productSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('.prod-card').forEach(card => {
    card.style.display = card.dataset.name.toLowerCase().includes(q) ? '' : 'none';
  });
});

// Keyboard shortcut: F2 = focus search
document.addEventListener('keydown', e => {
  if (e.key === 'F2') { e.preventDefault(); document.getElementById('productSearch').focus(); }
  if (e.key === 'F10') { e.preventDefault(); chargeSale(); }
});
</script>
</body>
</html>
