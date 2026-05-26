<?php
$moduleSlug  = 'manufacturing';
$moduleName  = 'Manufacturing';
$moduleIcon  = 'fas fa-industry';
$moduleColor = '#d35400';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'products.php',    'icon' => 'fas fa-box',            'label' => 'Products'],
    ['url' => 'materials.php',   'icon' => 'fas fa-cubes',          'label' => 'Raw Materials'],
    ['url' => 'bom.php',         'icon' => 'fas fa-list-alt',       'label' => 'Bill of Materials'],
    ['url' => 'production.php',  'icon' => 'fas fa-industry',       'label' => 'Production Orders'],
    ['url' => 'workorders.php',  'icon' => 'fas fa-clipboard-list', 'label' => 'Work Orders'],
    ['url' => 'machines.php',    'icon' => 'fas fa-cogs',           'label' => 'Machines'],
    ['url' => 'quality.php',     'icon' => 'fas fa-check-circle',   'label' => 'Quality Control'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $name         = sanitize($_POST['name'] ?? '');
        $unit         = sanitize($_POST['unit'] ?? '');
        $sellingPrice = (float)($_POST['selling_price'] ?? 0);
        $costPrice    = (float)($_POST['cost_price'] ?? 0);
        $stock        = (int)($_POST['stock'] ?? 0);
        $status       = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if (empty($name)) {
            setFlash('danger', 'Product name is required.');
            redirect('products.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE mfg_products SET name=?, unit=?, selling_price=?, cost_price=?, status=? WHERE id=? AND org_id=?")
                ->execute([$name, $unit, $sellingPrice, $costPrice, $status, $id, $orgId]);
            setFlash('success', 'Product updated.');
            logActivity('update', 'manufacturing', "Updated product: $name");
        } else {
            // Auto-generate code
            $last = $pdo->prepare("SELECT MAX(id) FROM mfg_products WHERE org_id=?");
            $last->execute([$orgId]);
            $nextId = (int)$last->fetchColumn() + 1;
            $code   = 'MFG-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO mfg_products (org_id, code, name, unit, selling_price, cost_price, stock, status) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $code, $name, $unit, $sellingPrice, $costPrice, $stock, $status]);
            setFlash('success', 'Product created.');
            logActivity('create', 'manufacturing', "Created product: $name");
        }
        redirect('products.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $usedOrders = countRows('mfg_production_orders', 'product_id = ? AND org_id = ?', [$id, $orgId]);
        $usedBom    = countRows('mfg_bom', 'product_id = ?', [$id]);
        if ($usedOrders > 0 || $usedBom > 0) {
            setFlash('danger', 'Cannot delete: product is used in production orders or BOM.');
        } else {
            $pdo->prepare("DELETE FROM mfg_products WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Product deleted.');
            logActivity('delete', 'manufacturing', "Deleted product #$id");
        }
        redirect('products.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$products = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM mfg_products WHERE org_id=? ORDER BY code, name");
    $stmt->execute([$orgId]);
    $products = $stmt->fetchAll();
} catch (Exception $e) {}

$totalProducts  = count($products);
$activeProducts = count(array_filter($products, fn($p) => $p['status'] === 'active'));
$lowStock       = count(array_filter($products, fn($p) => (int)$p['stock'] < 10));
$stockValue     = array_sum(array_map(fn($p) => (float)$p['cost_price'] * (int)$p['stock'], $products));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-box me-2" style="color:<?= $moduleColor ?>"></i>Products</h4>
    <p class="text-muted mb-0">Manage finished goods and product catalogue</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#prodModal" onclick="openAdd()">
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
      <div class="stat-body"><div class="stat-value"><?= $lowStock ?></div><div class="stat-label">Low Stock (&lt;10)</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($stockValue) ?></div><div class="stat-label">Stock Value</div></div>
    </div>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-box me-2" style="color:<?= $moduleColor ?>"></i>Product Catalogue</h6>
    <span class="badge bg-secondary"><?= $totalProducts ?> products</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Unit</th>
            <th class="text-end">Cost Price</th>
            <th class="text-end">Selling Price</th>
            <th class="text-end">Stock</th>
            <th class="text-end">Margin %</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($products)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No products found.</td></tr>
          <?php else: foreach ($products as $p):
            $margin = (float)$p['selling_price'] > 0
                ? round(((float)$p['selling_price'] - (float)$p['cost_price']) / (float)$p['selling_price'] * 100, 1)
                : 0;
            $lowStockRow = (int)$p['stock'] < 10;
          ?>
          <tr class="<?= $lowStockRow ? 'table-warning' : '' ?>">
            <td class="fw-semibold text-muted"><?= e($p['code'] ?? '') ?></td>
            <td class="fw-semibold">
              <?= e($p['name']) ?>
              <?php if ($lowStockRow): ?><span class="badge bg-warning text-dark ms-1 small"><i class="fas fa-exclamation-triangle"></i> Low</span><?php endif; ?>
            </td>
            <td><?= e($p['unit'] ?? '—') ?></td>
            <td class="text-end"><?= formatCurrency((float)$p['cost_price']) ?></td>
            <td class="text-end"><?= formatCurrency((float)$p['selling_price']) ?></td>
            <td class="text-end fw-semibold <?= (int)$p['stock'] < 10 ? 'text-warning' : 'text-dark' ?>"><?= (int)$p['stock'] ?></td>
            <td class="text-end <?= $margin >= 30 ? 'text-success' : ($margin >= 10 ? 'text-warning' : 'text-danger') ?>">
              <?= $margin ?>%
            </td>
            <td><?= statusBadge($p['status']) ?></td>
            <td class="text-center text-nowrap">
              <a href="bom.php?product_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View BOM"><i class="fas fa-list-alt"></i></a>
              <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEdit(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delProd(<?= $p['id'] ?>, '<?= e($p['name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
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
  <div class="modal-dialog modal-lg">
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
              <input type="text" name="name" id="prodName" class="form-control" placeholder="e.g. Steel Chair Model A" required maxlength="255">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Unit of Measure</label>
              <input type="text" name="unit" id="prodUnit" class="form-control" placeholder="e.g. pcs, kg, litres">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Cost Price</label>
              <input type="number" name="cost_price" id="prodCost" class="form-control" placeholder="0.00" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Selling Price</label>
              <input type="number" name="selling_price" id="prodSell" class="form-control" placeholder="0.00" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="prodStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-4" id="stockRow">
              <label class="form-label fw-semibold">Opening Stock</label>
              <input type="number" name="stock" id="prodStock" class="form-control" placeholder="0" min="0" value="0">
              <div class="form-text">Set initial stock. Updated automatically via production.</div>
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
<form method="POST" id="deleteForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('prodModalTitle').innerHTML = '<i class="fas fa-box me-2"></i>Add Product';
  document.getElementById('prodId').value     = '0';
  document.getElementById('prodName').value   = '';
  document.getElementById('prodUnit').value   = '';
  document.getElementById('prodCost').value   = '0';
  document.getElementById('prodSell').value   = '0';
  document.getElementById('prodStock').value  = '0';
  document.getElementById('prodStatus').value = 'active';
  document.getElementById('stockRow').style.display = '';
}
function openEdit(p) {
  document.getElementById('prodModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Product';
  document.getElementById('prodId').value     = p.id;
  document.getElementById('prodName').value   = p.name || '';
  document.getElementById('prodUnit').value   = p.unit || '';
  document.getElementById('prodCost').value   = p.cost_price || '0';
  document.getElementById('prodSell').value   = p.selling_price || '0';
  document.getElementById('prodStock').value  = p.stock || '0';
  document.getElementById('prodStatus').value = p.status || 'active';
  document.getElementById('stockRow').style.display = 'none';
  new bootstrap.Modal(document.getElementById('prodModal')).show();
}
function delProd(id, name) {
  Swal.fire({
    title: 'Delete Product?',
    text: '"' + name + '" will be permanently deleted.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteId').value = id;
      document.getElementById('deleteForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
