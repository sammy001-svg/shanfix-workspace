<?php
// ── Retail: Categories ────────────────────────────────────────
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

    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = sanitize($_POST['name'] ?? '');
        $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        if (empty($name)) {
            setFlash('danger', 'Category name is required.');
            redirect('categories.php');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE retail_categories SET name=?, status=? WHERE id=? AND org_id=?");
            $stmt->execute([$name, $status, $id, $orgId]);
            setFlash('success', 'Category updated successfully.');
            logActivity('update', 'retail', "Updated category: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO retail_categories (org_id, name, status) VALUES (?,?,?)");
            $stmt->execute([$orgId, $name, $status]);
            setFlash('success', "Category \"$name\" added.");
            logActivity('create', 'retail', "Added category: $name");
        }
        redirect('categories.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = $pdo->prepare("SELECT COUNT(*) FROM retail_products WHERE category_id=? AND org_id=?");
        $used->execute([$id, $orgId]);
        if ((int)$used->fetchColumn() > 0) {
            setFlash('danger', 'Cannot delete: this category has products assigned to it.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM retail_categories WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            setFlash('success', 'Category deleted.');
            logActivity('delete', 'retail', "Deleted category #$id");
        }
        redirect('categories.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$categories = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, COUNT(p.id) AS product_count
        FROM retail_categories c
        LEFT JOIN retail_products p ON p.category_id = c.id AND p.org_id = c.org_id
        WHERE c.org_id = ?
        GROUP BY c.id
        ORDER BY c.name ASC
    ");
    $stmt->execute([$orgId]);
    $categories = $stmt->fetchAll();
} catch (Exception $e) {}

$total  = count($categories);
$active = count(array_filter($categories, fn($c) => $c['status'] === 'active'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-tags me-2" style="color:<?= $moduleColor ?>"></i>Product Categories</h4>
    <p class="text-muted mb-0">Organise your products into categories</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff"
    data-bs-toggle="modal" data-bs-target="#catModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Category
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-tags"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $total ?></div><div class="stat-label">Total Categories</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $active ?></div><div class="stat-label">Active</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-times-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $total - $active ?></div><div class="stat-label">Inactive</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-boxes"></i></div>
      <div class="stat-body"><div class="stat-value"><?= array_sum(array_column($categories, 'product_count')) ?></div><div class="stat-label">Total Products</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-tags me-2" style="color:<?= $moduleColor ?>"></i>All Categories</h6>
    <span class="badge bg-secondary"><?= $total ?> categories</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Category Name</th>
            <th class="text-center">Products</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($categories)): ?>
          <tr><td colspan="5" class="text-center text-muted py-5">
            <i class="fas fa-tags fa-2x mb-2 d-block"></i>No categories yet. Add your first category.
          </td></tr>
          <?php else: foreach ($categories as $i => $cat): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div style="width:34px;height:34px;border-radius:8px;background:<?= $moduleColor ?>22;color:<?= $moduleColor ?>;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0">
                  <i class="fas fa-tag"></i>
                </div>
                <span class="fw-semibold"><?= e($cat['name']) ?></span>
              </div>
            </td>
            <td class="text-center">
              <a href="products.php?category=<?= $cat['id'] ?>" class="badge bg-info text-decoration-none">
                <?= (int)$cat['product_count'] ?> products
              </a>
            </td>
            <td><?= statusBadge($cat['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="delCat(<?= $cat['id'] ?>, '<?= e($cat['name']) ?>', <?= (int)$cat['product_count'] ?>)"
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
<div class="modal fade" id="catModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="catId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="catModalTitle"><i class="fas fa-tags me-2"></i>Add Category</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="catName" class="form-control" required maxlength="100" placeholder="e.g. Electronics">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="catStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Category</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="delCatForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delCatId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('catModalTitle').innerHTML = '<i class="fas fa-tags me-2"></i>Add Category';
  document.getElementById('catId').value = '0';
  document.getElementById('catName').value = '';
  document.getElementById('catStatus').value = 'active';
}
function openEdit(cat) {
  document.getElementById('catModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Category';
  document.getElementById('catId').value = cat.id;
  document.getElementById('catName').value = cat.name || '';
  document.getElementById('catStatus').value = cat.status || 'active';
  new bootstrap.Modal(document.getElementById('catModal')).show();
}
function delCat(id, name, count) {
  if (count > 0) {
    Swal.fire({ title: 'Cannot Delete', text: 'Category "' + name + '" has ' + count + ' product(s) assigned. Reassign or delete them first.', icon: 'error' });
    return;
  }
  Swal.fire({
    title: 'Delete Category?',
    text: '"' + name + '" will be permanently deleted.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('delCatId').value = id;
      document.getElementById('delCatForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
