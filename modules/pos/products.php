<?php
// ── POS: Products ─────────────────────────────────────────────
$moduleSlug  = 'pos';
$moduleName  = 'Point of Sale';
$moduleIcon  = 'fas fa-cash-register';
$moduleColor = '#e74c3c';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'terminal.php','icon'=>'fas fa-cash-register','label'=>'POS Terminal'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'categories.php','icon'=>'fas fa-tags','label'=>'Categories'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'suppliers.php','icon'=>'fas fa-truck','label'=>'Suppliers'],['url'=>'stock.php','icon'=>'fas fa-warehouse','label'=>'Stock'],['url'=>'purchases.php','icon'=>'fas fa-cart-arrow-down','label'=>'Purchases'],['url'=>'returns.php','icon'=>'fas fa-undo','label'=>'Returns'],['url'=>'shifts.php','icon'=>'fas fa-clock','label'=>'Shifts'],['url'=>'expenses.php','icon'=>'fas fa-wallet','label'=>'Expenses'],['url'=>'discounts.php','icon'=>'fas fa-percent','label'=>'Discounts'],['url'=>'sales.php','icon'=>'fas fa-receipt','label'=>'Sales History'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $categoryId  = (int)($_POST['category_id'] ?? 0);
        $name        = sanitize($_POST['name'] ?? '');
        $sku         = sanitize($_POST['sku'] ?? '');
        $barcode     = sanitize($_POST['barcode'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $price       = (float)($_POST['price'] ?? 0);
        $costPrice   = (float)($_POST['cost_price'] ?? 0);
        $stockQty    = (int)($_POST['stock_quantity'] ?? 0);
        $reorder     = (int)($_POST['reorder_level'] ?? 5);
        $unit        = sanitize($_POST['unit'] ?? 'pcs');
        $active      = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $price < 0) {
            setFlash('danger', 'Product name and a valid price are required.');
            redirect('products.php');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE pos_products SET category_id=?, name=?, sku=?, barcode=?, description=?, price=?, cost_price=?, stock_quantity=?, reorder_level=?, unit=?, is_active=? WHERE id=? AND org_id=?");
            $stmt->execute([$categoryId ?: null, $name, $sku, $barcode, $description, $price, $costPrice, $stockQty, $reorder, $unit, $active, $id, $orgId]);
            setFlash('success', "Product \"$name\" updated successfully.");
            logActivity('update', 'pos', "Updated product: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO pos_products (org_id, category_id, name, sku, barcode, description, price, cost_price, stock_quantity, reorder_level, unit, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $categoryId ?: null, $name, $sku, $barcode, $description, $price, $costPrice, $stockQty, $reorder, $unit, $active]);
            setFlash('success', "Product \"$name\" added successfully.");
            logActivity('create', 'pos', "Created product: $name");
        }
        redirect('products.php');
    }

    if ($action === 'adjust_stock') {
        $id     = (int)($_POST['id'] ?? 0);
        $adjust = (int)($_POST['adjust'] ?? 0);
        if ($id > 0 && $adjust !== 0) {
            $stmt = $pdo->prepare("UPDATE pos_products SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE id=? AND org_id=?");
            $stmt->execute([$adjust, $id, $orgId]);
            $dir = $adjust > 0 ? '+' . $adjust : (string)$adjust;
            setFlash('success', "Stock adjusted by $dir units.");
            logActivity('update', 'pos', "Stock adjustment ($dir) for product #$id");
        }
        redirect('products.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Soft-disable instead of hard delete to preserve sale history
        $stmt = $pdo->prepare("UPDATE pos_products SET is_active=0 WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        setFlash('success', 'Product deactivated.');
        logActivity('delete', 'pos', "Deactivated product #$id");
        redirect('products.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filters
$filterCat    = $_GET['cat']    ?? '';
$filterStatus = $_GET['status'] ?? '';

$where  = 'p.org_id = ?';
$params = [$orgId];
if ($filterCat)    { $where .= ' AND p.category_id = ?'; $params[] = $filterCat; }
if ($filterStatus === 'low') {
    $where .= ' AND p.stock_quantity <= p.reorder_level AND p.is_active = 1';
} elseif ($filterStatus === 'active') {
    $where .= ' AND p.is_active = 1';
} elseif ($filterStatus === 'inactive') {
    $where .= ' AND p.is_active = 0';
}

$products = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS category_name, c.color AS cat_color
        FROM pos_products p
        LEFT JOIN pos_categories c ON p.category_id = c.id
        WHERE $where
        ORDER BY p.name
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (Exception $e) {}

// Categories for filter/form
$categories = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, color FROM pos_categories WHERE org_id=? AND is_active=1 ORDER BY name");
    $stmt->execute([$orgId]);
    $categories = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats
$totalProducts = countRows('pos_products', 'org_id=?', [$orgId]);
$activeCount   = countRows('pos_products', 'org_id=? AND is_active=1', [$orgId]);
$lowStockCount = countRows('pos_products', 'org_id=? AND is_active=1 AND stock_quantity <= reorder_level', [$orgId]);
$stockValue    = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(price * stock_quantity),0) FROM pos_products WHERE org_id=? AND is_active=1");
    $stmt->execute([$orgId]);
    $stockValue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-box me-2" style="color:<?= $moduleColor ?>"></i>Products</h4>
    <p class="text-muted mb-0">Manage your product catalogue and stock levels</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#productModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Add Product
  </button>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(231,76,60,.1);color:#e74c3c"><i class="fas fa-box"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalProducts ?></div><div class="stat-label">Total Products</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Active</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $lowStockCount ?></div><div class="stat-label">Low Stock</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($stockValue) ?></div><div class="stat-label">Stock Value</div></div>
    </div>
  </div>
</div>

<?php if ($lowStockCount > 0 && !$filterStatus): ?>
<div class="alert alert-warning d-flex align-items-center mb-3">
  <i class="fas fa-exclamation-triangle me-2"></i>
  <span><?= $lowStockCount ?> product(s) are at or below their reorder level.
    <a href="?status=low" class="alert-link ms-1">View low-stock items</a>
  </span>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-5 col-md-4">
        <label class="form-label small fw-semibold mb-1">Category</label>
        <select name="cat" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filterCat == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          <option value="low"      <?= $filterStatus === 'low'      ? 'selected' : '' ?>>Low Stock</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="products.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Products Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-box me-2" style="color:<?= $moduleColor ?>"></i>Product List</h6>
    <span class="badge bg-secondary"><?= count($products) ?> products</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>SKU</th>
            <th>Product</th>
            <th>Category</th>
            <th class="text-end">Price</th>
            <th class="text-end">Cost</th>
            <th class="text-center">Stock</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($products)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No products found.
          </td></tr>
          <?php else: foreach ($products as $prod):
            $isLowStock = (int)$prod['stock_quantity'] <= (int)$prod['reorder_level'] && $prod['is_active'];
          ?>
          <tr>
            <td class="text-muted small fw-semibold"><?= e($prod['sku'] ?: '—') ?></td>
            <td>
              <div class="fw-semibold"><?= e($prod['name']) ?></div>
              <?php if ($prod['barcode']): ?><div class="small text-muted"><i class="fas fa-barcode me-1"></i><?= e($prod['barcode']) ?></div><?php endif; ?>
            </td>
            <td>
              <?php if ($prod['category_name']): ?>
              <span class="badge" style="background:<?= e($prod['cat_color'] ?? '#6c757d') ?>22;color:<?= e($prod['cat_color'] ?? '#6c757d') ?>;border:1px solid <?= e($prod['cat_color'] ?? '#6c757d') ?>44">
                <?= e($prod['category_name']) ?>
              </span>
              <?php else: ?>
              <span class="text-muted small">Uncategorised</span>
              <?php endif; ?>
            </td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$prod['price']) ?></td>
            <td class="text-end text-muted"><?= formatCurrency((float)$prod['cost_price']) ?></td>
            <td class="text-center">
              <div class="d-flex align-items-center justify-content-center gap-1">
                <?php if ($isLowStock): ?>
                <span class="badge bg-warning text-dark" title="Low stock — reorder at <?= (int)$prod['reorder_level'] ?>">
                  <i class="fas fa-exclamation-triangle me-1"></i><?= (int)$prod['stock_quantity'] ?> <?= e($prod['unit']) ?>
                </span>
                <?php else: ?>
                <span class="badge bg-light text-dark border"><?= (int)$prod['stock_quantity'] ?> <?= e($prod['unit']) ?></span>
                <?php endif; ?>
              </div>
              <!-- Quick stock adjust -->
              <form method="POST" class="d-inline mt-1" onsubmit="return confirm('Adjust stock?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="id" value="<?= $prod['id'] ?>">
                <div class="input-group input-group-sm justify-content-center mt-1" style="max-width:110px;margin:0 auto">
                  <input type="number" name="adjust" class="form-control form-control-sm text-center" placeholder="±qty" style="max-width:60px">
                  <button type="submit" class="btn btn-outline-secondary btn-sm px-1" title="Adjust"><i class="fas fa-sync-alt fa-xs"></i></button>
                </div>
              </form>
            </td>
            <td><?= statusBadge($prod['is_active'] ? 'active' : 'inactive') ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= htmlspecialchars(json_encode($prod), ENT_QUOTES) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="confirmDelete(<?= $prod['id'] ?>, '<?= e($prod['name']) ?>')" title="Deactivate">
                <i class="fas fa-ban"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Product Modal -->
<div class="modal fade" id="productModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="prodId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="prodModalTitle"><i class="fas fa-box me-2"></i>Add Product</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="prodName" class="form-control" required maxlength="200" placeholder="e.g. Coca Cola 500ml">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Category</label>
              <select name="category_id" id="prodCategory" class="form-select">
                <option value="">-- No Category --</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">SKU</label>
              <input type="text" name="sku" id="prodSku" class="form-control" maxlength="100" placeholder="e.g. BEV-001">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Barcode</label>
              <input type="text" name="barcode" id="prodBarcode" class="form-control" maxlength="100" placeholder="e.g. 5901234123457">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Unit</label>
              <input type="text" name="unit" id="prodUnit" class="form-control" maxlength="50" placeholder="pcs, kg, litre…" value="pcs">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Selling Price (<?= CURRENCY_SYMBOL ?>) <span class="text-danger">*</span></label>
              <input type="number" name="price" id="prodPrice" class="form-control" step="0.01" min="0" required placeholder="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Cost Price (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="cost_price" id="prodCost" class="form-control" step="0.01" min="0" placeholder="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Stock Quantity</label>
              <input type="number" name="stock_quantity" id="prodStock" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Reorder Level</label>
              <input type="number" name="reorder_level" id="prodReorder" class="form-control" min="0" value="5">
              <div class="form-text">Alert when stock falls to this level.</div>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" id="prodDesc" class="form-control" rows="2" placeholder="Optional product description"></textarea>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="prodActive" value="1" checked>
                <label class="form-check-label fw-semibold" for="prodActive">Active (visible on POS terminal)</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Product</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteProdForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteProdId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAddModal() {
  document.getElementById('prodModalTitle').innerHTML = '<i class="fas fa-box me-2"></i>Add Product';
  document.getElementById('prodId').value       = 0;
  document.getElementById('prodName').value     = '';
  document.getElementById('prodCategory').value = '';
  document.getElementById('prodSku').value      = '';
  document.getElementById('prodBarcode').value  = '';
  document.getElementById('prodUnit').value     = 'pcs';
  document.getElementById('prodPrice').value    = '';
  document.getElementById('prodCost').value     = '';
  document.getElementById('prodStock').value    = 0;
  document.getElementById('prodReorder').value  = 5;
  document.getElementById('prodDesc').value     = '';
  document.getElementById('prodActive').checked = true;
}

function openEditModal(p) {
  document.getElementById('prodModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Product';
  document.getElementById('prodId').value       = p.id;
  document.getElementById('prodName').value     = p.name         || '';
  document.getElementById('prodCategory').value = p.category_id  || '';
  document.getElementById('prodSku').value      = p.sku          || '';
  document.getElementById('prodBarcode').value  = p.barcode      || '';
  document.getElementById('prodUnit').value     = p.unit         || 'pcs';
  document.getElementById('prodPrice').value    = p.price        || '';
  document.getElementById('prodCost').value     = p.cost_price   || '';
  document.getElementById('prodStock').value    = p.stock_quantity || 0;
  document.getElementById('prodReorder').value  = p.reorder_level || 5;
  document.getElementById('prodDesc').value     = p.description  || '';
  document.getElementById('prodActive').checked = p.is_active == 1;
  var modal = new bootstrap.Modal(document.getElementById('productModal'));
  modal.show();
}

function confirmDelete(id, name) {
  Swal.fire({
    title: 'Deactivate Product?',
    text: '"' + name + '" will be deactivated. Stock history will be preserved.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, deactivate',
  }).then(function(result) {
    if (result.isConfirmed) {
      document.getElementById('deleteProdId').value = id;
      document.getElementById('deleteProdForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
