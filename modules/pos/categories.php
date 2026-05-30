<?php
// ── POS: Categories ───────────────────────────────────────────
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
        $id     = (int)($_POST['id'] ?? 0);
        $name   = sanitize($_POST['name'] ?? '');
        $desc   = sanitize($_POST['description'] ?? '');
        $color  = preg_match('/^#[0-9a-fA-F]{3,6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#1A8A4E';
        $icon   = sanitize($_POST['icon'] ?? 'fas fa-tag');
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            setFlash('danger', 'Category name is required.');
            redirect('categories.php');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE pos_categories SET name=?, description=?, color=?, icon=?, is_active=? WHERE id=? AND org_id=?");
            $stmt->execute([$name, $desc, $color, $icon, $active, $id, $orgId]);
            setFlash('success', "Category \"$name\" updated successfully.");
            logActivity('update', 'pos', "Updated category: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO pos_categories (org_id, name, description, color, icon, is_active) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$orgId, $name, $desc, $color, $icon, $active]);
            setFlash('success', "Category \"$name\" created successfully.");
            logActivity('create', 'pos', "Created category: $name");
        }
        redirect('categories.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Check if used by products
        $used = $pdo->prepare("SELECT COUNT(*) FROM pos_products WHERE category_id=? AND org_id=?");
        $used->execute([$id, $orgId]);
        if ((int)$used->fetchColumn() > 0) {
            setFlash('danger', 'Cannot delete: this category has products assigned to it.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM pos_categories WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            setFlash('success', 'Category deleted successfully.');
            logActivity('delete', 'pos', "Deleted category #$id");
        }
        redirect('categories.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch categories with product count
$categories = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*,
               COUNT(p.id) AS total_products,
               SUM(CASE WHEN p.is_active=1 THEN 1 ELSE 0 END) AS active_products
        FROM pos_categories c
        LEFT JOIN pos_products p ON p.category_id = c.id AND p.org_id = c.org_id
        WHERE c.org_id = ?
        GROUP BY c.id
        ORDER BY c.name
    ");
    $stmt->execute([$orgId]);
    $categories = $stmt->fetchAll();
} catch (Exception $e) {}

$totalActive   = count(array_filter($categories, fn($c) => $c['is_active']));
$totalInactive = count($categories) - $totalActive;
$viewMode      = $_GET['view'] ?? 'grid'; // grid or list
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-tags me-2" style="color:<?= $moduleColor ?>"></i>Product Categories</h4>
    <p class="text-muted mb-0">Organise your products into categories</p>
  </div>
  <div class="d-flex gap-2">
    <a href="?view=<?= $viewMode === 'grid' ? 'list' : 'grid' ?>" class="btn btn-outline-secondary">
      <i class="fas fa-<?= $viewMode === 'grid' ? 'list' : 'th' ?>"></i>
    </a>
    <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#catModal" onclick="openAddModal()">
      <i class="fas fa-plus me-2"></i>Add Category
    </button>
  </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(231,76,60,.1);color:#e74c3c"><i class="fas fa-tags"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($categories) ?></div><div class="stat-label">Total Categories</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalActive ?></div><div class="stat-label">Active</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-times-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalInactive ?></div><div class="stat-label">Inactive</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-box"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= array_sum(array_column($categories, 'total_products')) ?></div>
        <div class="stat-label">Total Products</div>
      </div>
    </div>
  </div>
</div>

<?php if ($viewMode === 'list'): ?>
<!-- List View -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>All Categories</h6>
    <span class="badge bg-secondary"><?= count($categories) ?> categories</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Category</th>
            <th>Icon</th>
            <th>Color</th>
            <th>Description</th>
            <th class="text-center">Products</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($categories)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No categories yet. Add your first category.
          </td></tr>
          <?php else: foreach ($categories as $cat): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div style="width:34px;height:34px;border-radius:8px;background:<?= e($cat['color']) ?>22;color:<?= e($cat['color']) ?>;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0">
                  <i class="<?= e($cat['icon'] ?? 'fas fa-tag') ?>"></i>
                </div>
                <span class="fw-semibold"><?= e($cat['name']) ?></span>
              </div>
            </td>
            <td><small class="text-muted"><?= e($cat['icon'] ?? 'fas fa-tag') ?></small></td>
            <td>
              <span class="d-inline-flex align-items-center gap-1">
                <span style="display:inline-block;width:16px;height:16px;border-radius:4px;background:<?= e($cat['color']) ?>"></span>
                <code class="small"><?= e($cat['color']) ?></code>
              </span>
            </td>
            <td class="text-muted small"><?= e(mb_substr($cat['description'] ?? '', 0, 60)) ?><?= strlen($cat['description'] ?? '') > 60 ? '…' : '' ?></td>
            <td class="text-center">
              <span class="badge bg-secondary"><?= (int)$cat['active_products'] ?> active</span>
              <span class="text-muted small">/ <?= (int)$cat['total_products'] ?></span>
            </td>
            <td><?= statusBadge($cat['is_active'] ? 'active' : 'inactive') ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="confirmDelete(<?= $cat['id'] ?>, '<?= e($cat['name']) ?>')" title="Delete">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php else: ?>
<!-- Grid View -->
<?php if (empty($categories)): ?>
<div class="text-center py-5 text-muted">
  <i class="fas fa-tags fa-3x mb-3"></i>
  <p>No categories yet. Add your first category to get started.</p>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($categories as $cat): ?>
  <div class="col-sm-6 col-md-4 col-xl-3">
    <div class="card border-0 shadow-sm h-100" style="border-top:3px solid <?= e($cat['color']) ?> !important">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between mb-3">
          <div style="width:44px;height:44px;border-radius:10px;background:<?= e($cat['color']) ?>22;color:<?= e($cat['color']) ?>;display:flex;align-items:center;justify-content:center;font-size:1.1rem">
            <i class="<?= e($cat['icon'] ?? 'fas fa-tag') ?>"></i>
          </div>
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-primary p-1 px-2" onclick='openEditModal(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES) ?>)' title="Edit">
              <i class="fas fa-edit fa-sm"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger p-1 px-2" onclick="confirmDelete(<?= $cat['id'] ?>, '<?= e($cat['name']) ?>')" title="Delete">
              <i class="fas fa-trash fa-sm"></i>
            </button>
          </div>
        </div>
        <h6 class="fw-bold mb-1"><?= e($cat['name']) ?></h6>
        <?php if ($cat['description']): ?>
        <p class="text-muted small mb-2"><?= e(mb_substr($cat['description'], 0, 70)) ?><?= strlen($cat['description']) > 70 ? '…' : '' ?></p>
        <?php endif; ?>
        <div class="d-flex align-items-center justify-content-between mt-auto pt-2 border-top">
          <div class="text-muted small">
            <i class="fas fa-box me-1"></i><?= (int)$cat['active_products'] ?> product<?= $cat['active_products'] != 1 ? 's' : '' ?>
          </div>
          <?= statusBadge($cat['is_active'] ? 'active' : 'inactive') ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

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
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="catName" class="form-control" placeholder="e.g. Beverages" required maxlength="100">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Icon Class</label>
              <input type="text" name="icon" id="catIcon" class="form-control" placeholder="fas fa-tag" maxlength="50">
              <div class="form-text">FontAwesome class, e.g. <code>fas fa-coffee</code></div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Color</label>
              <div class="input-group">
                <input type="color" name="color" id="catColor" class="form-control form-control-color" value="#1A8A4E" style="max-width:60px">
                <input type="text" id="catColorText" class="form-control" value="#1A8A4E" maxlength="7" placeholder="#1A8A4E">
              </div>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" id="catDesc" class="form-control" rows="2" placeholder="Optional description"></textarea>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="catActive" value="1" checked>
                <label class="form-check-label fw-semibold" for="catActive">Active</label>
              </div>
            </div>
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
<form method="POST" id="deleteCatForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteCatId">
</form>

<?php
$extraJs = <<<'JS'
<script>
// Sync color picker with text input
document.getElementById('catColor').addEventListener('input', function() {
  document.getElementById('catColorText').value = this.value;
});
document.getElementById('catColorText').addEventListener('input', function() {
  if (/^#[0-9a-fA-F]{3,6}$/.test(this.value)) {
    document.getElementById('catColor').value = this.value;
  }
});

function openAddModal() {
  document.getElementById('catModalTitle').innerHTML = '<i class="fas fa-tags me-2"></i>Add Category';
  document.getElementById('catId').value     = 0;
  document.getElementById('catName').value   = '';
  document.getElementById('catIcon').value   = 'fas fa-tag';
  document.getElementById('catColor').value  = '#1A8A4E';
  document.getElementById('catColorText').value = '#1A8A4E';
  document.getElementById('catDesc').value   = '';
  document.getElementById('catActive').checked = true;
}

function openEditModal(cat) {
  document.getElementById('catModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Category';
  document.getElementById('catId').value     = cat.id;
  document.getElementById('catName').value   = cat.name || '';
  document.getElementById('catIcon').value   = cat.icon || 'fas fa-tag';
  var c = cat.color || '#1A8A4E';
  document.getElementById('catColor').value  = c;
  document.getElementById('catColorText').value = c;
  document.getElementById('catDesc').value   = cat.description || '';
  document.getElementById('catActive').checked = cat.is_active == 1;
  var modal = new bootstrap.Modal(document.getElementById('catModal'));
  modal.show();
}

function confirmDelete(id, name) {
  Swal.fire({
    title: 'Delete Category?',
    text: '"' + name + '" will be permanently deleted. This cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete',
  }).then(function(result) {
    if (result.isConfirmed) {
      document.getElementById('deleteCatId').value = id;
      document.getElementById('deleteCatForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
