<?php
// ── Retail: Products ──────────────────────────────────────────
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

    if ($action === 'save') {
        $id             = (int)($_POST['id'] ?? 0);
        $categoryId     = (int)($_POST['category_id'] ?? 0) ?: null;
        $sku            = sanitize($_POST['sku'] ?? '');
        $barcode        = sanitize($_POST['barcode'] ?? '');
        $name           = sanitize($_POST['name'] ?? '');
        $unit           = sanitize($_POST['unit'] ?? '');
        $retailPrice    = (float)($_POST['retail_price'] ?? 0);
        $wholesalePrice = (float)($_POST['wholesale_price'] ?? 0);
        $costPrice      = (float)($_POST['cost_price'] ?? 0);
        $stock          = (int)($_POST['stock'] ?? 0);
        $reorderLevel   = (int)($_POST['reorder_level'] ?? 10);
        $status         = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        if (empty($name)) {
            setFlash('danger', 'Product name is required.');
            redirect('products.php');
        }

        // Auto-generate SKU if blank
        if (empty($sku)) {
            if ($id > 0) {
                $stmt = $pdo->prepare("SELECT sku FROM retail_products WHERE id=? AND org_id=?");
                $stmt->execute([$id, $orgId]);
                $existing = $stmt->fetch();
                $sku = $existing['sku'] ?? '';
            }
            if (empty($sku)) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM retail_products WHERE org_id=?");
                $stmt->execute([$orgId]);
                $count = (int)$stmt->fetchColumn() + 1;
                $sku = 'RTL-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            }
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE retail_products SET category_id=?, sku=?, barcode=?, name=?, unit=?, retail_price=?, wholesale_price=?, cost_price=?, stock=?, reorder_level=?, status=? WHERE id=? AND org_id=?");
            $stmt->execute([$categoryId, $sku, $barcode, $name, $unit, $retailPrice, $wholesalePrice, $costPrice, $stock, $reorderLevel, $status, $id, $orgId]);
            setFlash('success', "Product \"$name\" updated.");
            logActivity('update', 'retail', "Updated product: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO retail_products (org_id, category_id, sku, barcode, name, unit, retail_price, wholesale_price, cost_price, stock, reorder_level, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $categoryId, $sku, $barcode, $name, $unit, $retailPrice, $wholesalePrice, $costPrice, $stock, $reorderLevel, $status]);
            setFlash('success', "Product \"$name\" added.");
            logActivity('create', 'retail', "Added product: $name");
        }
        redirect('products.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT name FROM retail_products WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $prod = $stmt->fetch();
        if ($prod) {
            $pdo->prepare("DELETE FROM retail_products WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', "Product \"{$prod['name']}\" deleted.");
            logActivity('delete', 'retail', "Deleted product: {$prod['name']}");
        }
        redirect('products.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filters
$fCategory = (int)($_GET['category'] ?? 0);
$fStatus   = $_GET['status'] ?? '';
$fLowStock = isset($_GET['low_stock']);

$where  = 'p.org_id = ?';
$params = [$orgId];
if ($fCategory) { $where .= ' AND p.category_id = ?'; $params[] = $fCategory; }
if ($fStatus)   { $where .= ' AND p.status = ?'; $params[] = $fStatus; }
if ($fLowStock) { $where .= ' AND p.stock <= p.reorder_level'; }

$products = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS category_name
        FROM retail_products p
        LEFT JOIN retail_categories c ON p.category_id = c.id
        WHERE $where
        ORDER BY p.name ASC
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats
$totalProducts  = countRows('retail_products', 'org_id=?', [$orgId]);
$activeProducts = countRows('retail_products', 'org_id=? AND status=?', [$orgId, 'active']);
$lowStockCount  = 0;
$totalValue     = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM retail_products WHERE org_id=? AND stock <= reorder_level AND status='active'");
    $stmt->execute([$orgId]);
    $lowStockCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(stock * cost_price),0) FROM retail_products WHERE org_id=?");
    $stmt->execute([$orgId]);
    $totalValue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Categories for dropdown
$categories = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM retail_categories WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]);
    $categories = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-boxes me-2" style="color:<?= $moduleColor ?>"></i>Products</h4>
    <p class="text-muted mb-0">Manage your retail and wholesale product catalogue</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff"
    data-bs-toggle="modal" data-bs-target="#prodModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Product
  </button>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-boxes"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalProducts ?></div><div class="stat-label">Total Products</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeProducts ?></div><div class="stat-label">Active</div></div>
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
      <div class="stat-icon navy-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1.05rem"><?= formatCurrency($totalValue) ?></div><div class="stat-label">Inventory Value</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Category</label>
        <select name="category" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= $fCategory === (int)$cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-sm-3">
        <div class="form-check mt-3">
          <input type="checkbox" name="low_stock" id="chkLow" class="form-check-input" value="1" <?= $fLowStock ? 'checked' : '' ?>>
          <label class="form-check-label small" for="chkLow">Low Stock Only</label>
        </div>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="products.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Products Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-boxes me-2" style="color:<?= $moduleColor ?>"></i>Product List</h6>
    <span class="badge bg-secondary"><?= count($products) ?> products</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>SKU</th>
            <th>Name</th>
            <th>Category</th>
            <th class="text-end">Retail Price</th>
            <th class="text-end">Wholesale</th>
            <th class="text-center">Stock</th>
            <th class="text-end">Margin %</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($products)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5">
            <i class="fas fa-boxes fa-2x mb-2 d-block"></i>No products found.
          </td></tr>
          <?php else: foreach ($products as $prod):
            $isLow   = (int)$prod['stock'] <= (int)$prod['reorder_level'];
            $margin  = $prod['cost_price'] > 0
                ? round(((float)$prod['retail_price'] - (float)$prod['cost_price']) / (float)$prod['cost_price'] * 100, 1)
                : 0;
            $rowClass = $isLow ? 'table-warning' : '';
          ?>
          <tr class="<?= $rowClass ?>">
            <td class="fw-semibold text-muted small"><?= e($prod['sku'] ?? '—') ?></td>
            <td>
              <div class="fw-semibold"><?= e($prod['name']) ?></div>
              <?php if (!empty($prod['barcode'])): ?>
              <div class="small text-muted"><?= e($prod['barcode']) ?></div>
              <?php endif; ?>
            </td>
            <td class="small"><?= e($prod['category_name'] ?? '—') ?></td>
            <td class="text-end"><?= formatCurrency((float)$prod['retail_price']) ?></td>
            <td class="text-end"><?= formatCurrency((float)$prod['wholesale_price']) ?></td>
            <td class="text-center">
              <?php if ($isLow): ?>
              <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i><?= (int)$prod['stock'] ?></span>
              <?php else: ?>
              <span class="badge bg-success"><?= (int)$prod['stock'] ?></span>
              <?php endif; ?>
              <div class="small text-muted">min:<?= (int)$prod['reorder_level'] ?></div>
            </td>
            <td class="text-end <?= $margin >= 0 ? 'text-success' : 'text-danger' ?>"><?= $margin ?>%</td>
            <td><?= statusBadge($prod['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode($prod), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="delProd(<?= $prod['id'] ?>, '<?= e($prod['name']) ?>')"
                title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="prodModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="pId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="prodModalTitle"><i class="fas fa-boxes me-2"></i>Add Product</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="pName" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Category</label>
              <select name="category_id" id="pCategory" class="form-select">
                <option value="">-- No Category --</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">SKU <small class="text-muted">(auto if blank)</small></label>
              <input type="text" name="sku" id="pSku" class="form-control" maxlength="100" placeholder="e.g. RTL-0001">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Barcode</label>
              <input type="text" name="barcode" id="pBarcode" class="form-control" maxlength="100">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Unit</label>
              <input type="text" name="unit" id="pUnit" class="form-control" maxlength="30" placeholder="pcs, kg, ltr…">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="pStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Cost Price</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="cost_price" id="pCost" class="form-control" step="0.01" min="0" value="0">
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Retail Price</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="retail_price" id="pRetail" class="form-control" step="0.01" min="0" value="0">
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Wholesale Price</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="wholesale_price" id="pWholesale" class="form-control" step="0.01" min="0" value="0">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Initial Stock</label>
              <input type="number" name="stock" id="pStock" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Reorder Level</label>
              <input type="number" name="reorder_level" id="pReorder" class="form-control" min="0" value="10">
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
<form method="POST" id="delProdForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delProdId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('prodModalTitle').innerHTML = '<i class="fas fa-boxes me-2"></i>Add Product';
  ['pId','pSku','pBarcode','pName','pUnit'].forEach(function(i){ document.getElementById(i).value = i === 'pId' ? '0' : ''; });
  ['pCost','pRetail','pWholesale','pStock'].forEach(function(i){ document.getElementById(i).value = '0'; });
  document.getElementById('pReorder').value = '10';
  document.getElementById('pCategory').value = '';
  document.getElementById('pStatus').value = 'active';
}
function openEdit(p) {
  document.getElementById('prodModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Product';
  document.getElementById('pId').value        = p.id;
  document.getElementById('pName').value      = p.name || '';
  document.getElementById('pSku').value       = p.sku || '';
  document.getElementById('pBarcode').value   = p.barcode || '';
  document.getElementById('pUnit').value      = p.unit || '';
  document.getElementById('pCategory').value  = p.category_id || '';
  document.getElementById('pCost').value      = p.cost_price || '0';
  document.getElementById('pRetail').value    = p.retail_price || '0';
  document.getElementById('pWholesale').value = p.wholesale_price || '0';
  document.getElementById('pStock').value     = p.stock || '0';
  document.getElementById('pReorder').value   = p.reorder_level || '10';
  document.getElementById('pStatus').value    = p.status || 'active';
  new bootstrap.Modal(document.getElementById('prodModal')).show();
}
function delProd(id, name) {
  Swal.fire({
    title: 'Delete Product?',
    text: '"' + name + '" will be permanently deleted.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('delProdId').value = id;
      document.getElementById('delProdForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
