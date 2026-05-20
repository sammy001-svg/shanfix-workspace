<?php
$moduleSlug  = 'crm';
$moduleName  = 'CRM — Customer Relations';
$moduleIcon  = 'fas fa-handshake';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'contacts.php',  'icon' => 'fas fa-address-book',   'label' => 'Contacts'],
    ['url' => 'companies.php', 'icon' => 'fas fa-building',       'label' => 'Companies'],
    ['url' => 'leads.php',     'icon' => 'fas fa-filter',         'label' => 'Leads'],
    ['url' => 'deals.php',     'icon' => 'fas fa-handshake',      'label' => 'Deals'],
    ['url' => 'pipeline.php',  'icon' => 'fas fa-columns',        'label' => 'Pipeline'],
    ['url' => 'quotes.php',    'icon' => 'fas fa-file-invoice',   'label' => 'Quotes'],
    ['url' => 'products.php',  'icon' => 'fas fa-box-open',       'label' => 'Products'],
    ['url' => 'activities.php','icon' => 'fas fa-tasks',          'label' => 'Activities'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-check-square',   'label' => 'Tasks'],
    ['url' => 'campaigns.php', 'icon' => 'fas fa-bullhorn',       'label' => 'Campaigns'],
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
        $id     = (int)($_POST['id'] ?? 0);
        $name   = sanitize($_POST['name'] ?? '');
        $sku    = sanitize($_POST['sku'] ?? '');
        $desc   = sanitize($_POST['description'] ?? '');
        $price  = (float)($_POST['unit_price'] ?? 0);
        $unit   = sanitize($_POST['unit'] ?? 'unit');
        $cat    = sanitize($_POST['category'] ?? '');
        $tax    = (float)($_POST['tax_rate'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

        if ($id > 0) {
            $pdo->prepare("UPDATE crm_products SET name=?,sku=?,description=?,unit_price=?,unit=?,category=?,tax_rate=?,status=? WHERE id=? AND org_id=?")
                ->execute([$name,$sku,$desc,$price,$unit,$cat,$tax,$status,$id,$orgId]);
            setFlash('success', 'Product updated.');
        } else {
            $pdo->prepare("INSERT INTO crm_products (org_id,name,sku,description,unit_price,unit,category,tax_rate,status) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$name,$sku,$desc,$price,$unit,$cat,$tax,$status]);
            setFlash('success', "Product '$name' added.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'crm', "Product: $name");
        redirect('products.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM crm_products WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Product deleted.');
        redirect('products.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fCat    = $_GET['cat'] ?? '';
$fStatus = $_GET['status'] ?? '';
$fQ      = trim($_GET['q'] ?? '');
$where   = 'org_id=?';
$params  = [$orgId];
if ($fCat)    { $where .= ' AND category=?'; $params[] = $fCat; }
if ($fStatus) { $where .= ' AND status=?';   $params[] = $fStatus; }
if ($fQ)      { $where .= ' AND (name LIKE ? OR sku LIKE ? OR description LIKE ?)'; $like = "%$fQ%"; array_push($params,$like,$like,$like); }

$products = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM crm_products WHERE $where ORDER BY category, name ASC");
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (Exception $e) {}

$total  = countRows('crm_products', 'org_id=?', [$orgId]);
$active = countRows('crm_products', 'org_id=? AND status=?', [$orgId, 'active']);
$cats   = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT category FROM crm_products WHERE org_id=? AND category != '' ORDER BY category");
    $stmt->execute([$orgId]);
    $cats = array_column($stmt->fetchAll(), 'category');
} catch (Exception $e) {}

$totalValue = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(unit_price),0) FROM crm_products WHERE org_id=? AND status='active'");
    $stmt->execute([$orgId]);
    $totalValue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-box-open me-2" style="color:<?= $moduleColor ?>"></i>Products &amp; Services</h4>
    <p class="text-muted mb-0">Catalog of products and services used in quotes and deals</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#pModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Product
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-box"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $total ?></div><div class="stat-label">Total Products</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $active ?></div><div class="stat-label">Active</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-layer-group"></i></div>
    <div class="stat-body"><div class="stat-value"><?= count($cats) ?></div><div class="stat-label">Categories</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-tags"></i></div>
    <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalValue) ?></div><div class="stat-label">Avg Catalog Value</div></div></div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Search</label>
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, SKU…" value="<?= e($fQ) ?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Category</label>
      <select name="cat" class="form-select form-select-sm">
        <option value="">All Categories</option>
        <?php foreach ($cats as $c): ?>
        <option value="<?= e($c) ?>" <?= $fCat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="active"   <?= $fStatus==='active'   ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $fStatus==='inactive' ? 'selected' : '' ?>>Inactive</option>
      </select></div>
    <div class="col-auto">
      <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="products.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
    </div>
  </form>
</div></div>

<!-- Products Grid / Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-box-open me-2" style="color:<?= $moduleColor ?>"></i>Product Catalog</h6>
    <span class="badge bg-secondary"><?= count($products) ?> items</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr><th>Name</th><th>SKU</th><th>Category</th><th>Unit Price</th><th>Unit</th><th>Tax %</th><th>Status</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($products)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-box fa-2x mb-2 d-block"></i>No products found.</td></tr>
        <?php else: foreach ($products as $p): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($p['name']) ?></div>
              <?php if ($p['description']): ?>
              <div class="small text-muted"><?= e(substr($p['description'], 0, 60)) ?><?= strlen($p['description']) > 60 ? '…' : '' ?></div>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= e($p['sku'] ?? '—') ?></td>
            <td><?= $p['category'] ? '<span class="badge bg-info text-dark">'.e($p['category']).'</span>' : '—' ?></td>
            <td class="fw-semibold text-success"><?= formatCurrency((float)$p['unit_price']) ?></td>
            <td class="small"><?= e($p['unit'] ?? 'unit') ?></td>
            <td class="small"><?= number_format((float)$p['tax_rate'], 1) ?>%</td>
            <td><?= statusBadge($p['status'] ?? 'active') ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delProduct(<?= $p['id'] ?>,'<?= e($p['name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="pModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="pId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="pModalTitle"><i class="fas fa-box-open me-2"></i>Add Product</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Product / Service Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="pName" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">SKU / Code</label>
              <input type="text" name="sku" id="pSku" class="form-control" maxlength="100" placeholder="e.g. PRD-001">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Unit Price (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="unit_price" id="pPrice" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Unit</label>
              <select name="unit" id="pUnit" class="form-select">
                <option value="unit">Unit</option>
                <option value="hour">Hour</option>
                <option value="day">Day</option>
                <option value="month">Month</option>
                <option value="kg">Kg</option>
                <option value="litre">Litre</option>
                <option value="box">Box</option>
                <option value="piece">Piece</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Tax Rate (%)</label>
              <input type="number" name="tax_rate" id="pTax" class="form-control" step="0.1" min="0" max="100" value="16">
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Category</label>
              <input type="text" name="category" id="pCategory" class="form-control" list="catList" maxlength="100" placeholder="e.g. Software, Consulting…">
              <datalist id="catList"><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="pStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" id="pDesc" class="form-control" rows="3"></textarea>
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
<form method="POST" id="delPForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delPId"></form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('pModalTitle').innerHTML = '<i class="fas fa-box-open me-2"></i>Add Product';
  ['pId','pName','pSku','pCategory','pDesc'].forEach(i => document.getElementById(i).value = i==='pId' ? '0' : '');
  document.getElementById('pPrice').value = 0;
  document.getElementById('pTax').value   = 16;
  document.getElementById('pUnit').value  = 'unit';
  document.getElementById('pStatus').value= 'active';
}
function openEdit(p) {
  document.getElementById('pModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Product';
  document.getElementById('pId').value      = p.id;
  document.getElementById('pName').value    = p.name || '';
  document.getElementById('pSku').value     = p.sku || '';
  document.getElementById('pPrice').value   = p.unit_price || 0;
  document.getElementById('pUnit').value    = p.unit || 'unit';
  document.getElementById('pTax').value     = p.tax_rate || 16;
  document.getElementById('pCategory').value= p.category || '';
  document.getElementById('pStatus').value  = p.status || 'active';
  document.getElementById('pDesc').value    = p.description || '';
  new bootstrap.Modal(document.getElementById('pModal')).show();
}
function delProduct(id, name) {
  Swal.fire({title:'Delete Product?',text:'"'+name+'" will be removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r => { if (r.isConfirmed) { document.getElementById('delPId').value = id; document.getElementById('delPForm').submit(); } });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
