<?php
$moduleSlug  = 'finance';
$moduleName  = 'Finance & Budgeting';
$moduleIcon  = 'fas fa-wallet';
$moduleColor = '#16a085';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'accounts.php',       'icon' => 'fas fa-university',     'label' => 'Accounts'],
    ['url' => 'transactions.php',   'icon' => 'fas fa-exchange-alt',   'label' => 'Transactions'],
    ['url' => 'categories.php',     'icon' => 'fas fa-tags',           'label' => 'Categories'],
    ['url' => 'budgets.php',        'icon' => 'fas fa-bullseye',       'label' => 'Budgets'],
    ['url' => 'journals.php',       'icon' => 'fas fa-book',           'label' => 'Journals'],
    ['url' => 'reconciliation.php', 'icon' => 'fas fa-check-double',   'label' => 'Reconciliation'],
    ['url' => 'statements.php',     'icon' => 'fas fa-file-alt',       'label' => 'Statements'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],
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
        $id    = (int)($_POST['id'] ?? 0);
        $name  = sanitize($_POST['name'] ?? '');
        $type  = in_array($_POST['type'] ?? '', ['income','expense']) ? $_POST['type'] : 'expense';
        $color = sanitize($_POST['color'] ?? '#16a085');

        if (empty($name)) {
            setFlash('danger', 'Category name is required.');
            redirect('categories.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE fin_categories SET name=?, type=?, color=? WHERE id=? AND org_id=?")->execute([$name, $type, $color, $id, $orgId]);
            setFlash('success', 'Category updated.');
            logActivity('update', 'finance', "Updated category: $name");
        } else {
            $pdo->prepare("INSERT INTO fin_categories (org_id, name, type, color) VALUES (?,?,?,?)")->execute([$orgId, $name, $type, $color]);
            setFlash('success', 'Category created.');
            logActivity('create', 'finance', "Created category: $name");
        }
        redirect('categories.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = countRows('fin_transactions', 'category_id = ?', [$id]);
        if ($used > 0) {
            setFlash('danger', "Cannot delete: $used transaction(s) linked to this category.");
        } else {
            $pdo->prepare("DELETE FROM fin_categories WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Category deleted.');
            logActivity('delete', 'finance', "Deleted category #$id");
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
        SELECT c.*,
               COUNT(t.id) AS tx_count,
               COALESCE(SUM(t.amount), 0) AS tx_total
        FROM fin_categories c
        LEFT JOIN fin_transactions t ON t.category_id = c.id AND t.org_id = c.org_id
        WHERE c.org_id = ?
        GROUP BY c.id
        ORDER BY c.type, c.name
    ");
    $stmt->execute([$orgId]);
    $categories = $stmt->fetchAll();
} catch (Exception $e) {}

$incomeCategories  = array_filter($categories, fn($c) => $c['type'] === 'income');
$expenseCategories = array_filter($categories, fn($c) => $c['type'] === 'expense');
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-tags me-2" style="color:<?= $moduleColor ?>"></i>Categories</h4>
    <p class="text-muted mb-0">Organize income and expense transaction categories</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#catModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Category
  </button>
</div>

<div class="row g-4">
  <!-- Income Categories -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between" style="background:#27ae6015;border-bottom:2px solid #27ae60">
        <h6 class="mb-0 text-success"><i class="fas fa-arrow-up me-2"></i>Income Categories</h6>
        <span class="badge bg-success"><?= count($incomeCategories) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($incomeCategories)): ?>
        <div class="text-center text-muted py-4">
          <i class="fas fa-tags fa-2x mb-2 d-block opacity-25"></i>
          No income categories yet.
        </div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($incomeCategories as $cat): ?>
          <li class="list-group-item d-flex align-items-center justify-content-between py-3">
            <div class="d-flex align-items-center gap-3">
              <div style="width:36px;height:36px;border-radius:8px;background:<?= e($cat['color']) ?>22;border:2px solid <?= e($cat['color']) ?>;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-tag" style="color:<?= e($cat['color']) ?>"></i>
              </div>
              <div>
                <div class="fw-semibold"><?= e($cat['name']) ?></div>
                <div class="small text-muted">
                  <?= (int)$cat['tx_count'] ?> transaction<?= $cat['tx_count'] != 1 ? 's' : '' ?> &middot;
                  <span class="text-success"><?= formatCurrency((float)$cat['tx_total']) ?></span>
                </div>
              </div>
            </div>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger" onclick="delCat(<?= $cat['id'] ?>, '<?= e($cat['name']) ?>', <?= (int)$cat['tx_count'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
      <div class="card-footer text-center">
        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#catModal" onclick="openAdd('income')">
          <i class="fas fa-plus me-1"></i>Add Income Category
        </button>
      </div>
    </div>
  </div>

  <!-- Expense Categories -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between" style="background:#e74c3c15;border-bottom:2px solid #e74c3c">
        <h6 class="mb-0 text-danger"><i class="fas fa-arrow-down me-2"></i>Expense Categories</h6>
        <span class="badge bg-danger"><?= count($expenseCategories) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($expenseCategories)): ?>
        <div class="text-center text-muted py-4">
          <i class="fas fa-tags fa-2x mb-2 d-block opacity-25"></i>
          No expense categories yet.
        </div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($expenseCategories as $cat): ?>
          <li class="list-group-item d-flex align-items-center justify-content-between py-3">
            <div class="d-flex align-items-center gap-3">
              <div style="width:36px;height:36px;border-radius:8px;background:<?= e($cat['color']) ?>22;border:2px solid <?= e($cat['color']) ?>;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-tag" style="color:<?= e($cat['color']) ?>"></i>
              </div>
              <div>
                <div class="fw-semibold"><?= e($cat['name']) ?></div>
                <div class="small text-muted">
                  <?= (int)$cat['tx_count'] ?> transaction<?= $cat['tx_count'] != 1 ? 's' : '' ?> &middot;
                  <span class="text-danger"><?= formatCurrency((float)$cat['tx_total']) ?></span>
                </div>
              </div>
            </div>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger" onclick="delCat(<?= $cat['id'] ?>, '<?= e($cat['name']) ?>', <?= (int)$cat['tx_count'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
      <div class="card-footer text-center">
        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#catModal" onclick="openAdd('expense')">
          <i class="fas fa-plus me-1"></i>Add Expense Category
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="catModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="catId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="catModalTitle"><i class="fas fa-tag me-2"></i>Add Category</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="catName" class="form-control" placeholder="e.g. Office Supplies" required maxlength="100">
            </div>
            <div class="col-8">
              <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
              <select name="type" id="catType" class="form-select" required>
                <option value="expense">Expense</option>
                <option value="income">Income</option>
              </select>
            </div>
            <div class="col-4">
              <label class="form-label fw-semibold">Color</label>
              <input type="color" name="color" id="catColor" class="form-control form-control-color w-100" value="#16a085">
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
<form method="POST" id="deleteForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd(type) {
  document.getElementById('catModalTitle').innerHTML = '<i class="fas fa-tag me-2"></i>Add Category';
  document.getElementById('catId').value    = '0';
  document.getElementById('catName').value  = '';
  document.getElementById('catType').value  = type || 'expense';
  document.getElementById('catColor').value = '#16a085';
}
function openEdit(cat) {
  document.getElementById('catModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Category';
  document.getElementById('catId').value    = cat.id;
  document.getElementById('catName').value  = cat.name || '';
  document.getElementById('catType').value  = cat.type || 'expense';
  document.getElementById('catColor').value = cat.color || '#16a085';
  new bootstrap.Modal(document.getElementById('catModal')).show();
}
function delCat(id, name, txCount) {
  if (txCount > 0) {
    Swal.fire('Cannot Delete', '"' + name + '" has ' + txCount + ' linked transaction(s). Remove or re-categorize them first.', 'error');
    return;
  }
  Swal.fire({
    title: 'Delete Category?',
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
