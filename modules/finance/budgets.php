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
        $id         = (int)($_POST['id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $period     = sanitize($_POST['period'] ?? date('Y-m'));
        $amount     = (float)($_POST['amount'] ?? 0);

        if ($categoryId <= 0 || $amount <= 0) {
            setFlash('danger', 'Category and budget amount are required.');
            redirect('budgets.php?period=' . urlencode($period));
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE fin_budgets SET category_id=?, period=?, amount=? WHERE id=? AND org_id=?")->execute([$categoryId, $period, $amount, $id, $orgId]);
            setFlash('success', 'Budget updated.');
            logActivity('update', 'finance', "Updated budget #$id");
        } else {
            // Check for duplicate
            $dup = $pdo->prepare("SELECT id FROM fin_budgets WHERE org_id=? AND category_id=? AND period=?");
            $dup->execute([$orgId, $categoryId, $period]);
            if ($dup->fetch()) {
                setFlash('danger', 'A budget for this category and period already exists.');
                redirect('budgets.php?period=' . urlencode($period));
            }
            $pdo->prepare("INSERT INTO fin_budgets (org_id, category_id, period, amount) VALUES (?,?,?,?)")->execute([$orgId, $categoryId, $period, $amount]);
            setFlash('success', 'Budget created.');
            logActivity('create', 'finance', "Created budget for category #$categoryId period $period");
        }
        redirect('budgets.php?period=' . urlencode($period));
    }

    if ($action === 'delete') {
        $id     = (int)($_POST['id'] ?? 0);
        $period = sanitize($_POST['period'] ?? date('Y-m'));
        $pdo->prepare("DELETE FROM fin_budgets WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Budget deleted.');
        logActivity('delete', 'finance', "Deleted budget #$id");
        redirect('budgets.php?period=' . urlencode($period));
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user   = currentUser();
$orgId  = (int)$user['org_id'];
$period = $_GET['period'] ?? date('Y-m');

// Validate period format
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}

// Fetch budgets for this period with actual spending
$budgets = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, c.name AS category_name, c.color AS category_color,
               COALESCE((
                   SELECT SUM(t.amount)
                   FROM fin_transactions t
                   WHERE t.org_id = b.org_id
                     AND t.category_id = b.category_id
                     AND t.type = 'expense'
                     AND DATE_FORMAT(t.date, '%Y-%m') = b.period
               ), 0) AS actual_spent
        FROM fin_budgets b
        LEFT JOIN fin_categories c ON b.category_id = c.id
        WHERE b.org_id = ? AND b.period = ?
        ORDER BY c.name
    ");
    $stmt->execute([$orgId, $period]);
    $budgets = $stmt->fetchAll();
} catch (Exception $e) {}

// Expense categories for dropdown
$expenseCategories = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM fin_categories WHERE org_id=? AND type='expense' ORDER BY name");
    $stmt->execute([$orgId]);
    $expenseCategories = $stmt->fetchAll();
} catch (Exception $e) {}

// Totals
$totalBudgeted = array_sum(array_column($budgets, 'amount'));
$totalSpent    = array_sum(array_column($budgets, 'actual_spent'));
$totalRemain   = $totalBudgeted - $totalSpent;

// Period display
$periodDisplay = date('F Y', strtotime($period . '-01'));

// Previous/Next periods
$prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));
$nextPeriod = date('Y-m', strtotime($period . '-01 +1 month'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bullseye me-2" style="color:<?= $moduleColor ?>"></i>Budgets</h4>
    <p class="text-muted mb-0">Set and track expense budgets by category</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#budgetModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Budget
  </button>
</div>

<!-- Period Selector -->
<div class="card mb-4">
  <div class="card-body py-2">
    <div class="d-flex align-items-center justify-content-between">
      <a href="?period=<?= urlencode($prevPeriod) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-left me-1"></i>Previous</a>
      <div class="text-center">
        <form method="GET" class="d-inline-flex align-items-center gap-2">
          <label class="fw-semibold text-muted mb-0">Period:</label>
          <input type="month" name="period" class="form-control form-control-sm" value="<?= e($period) ?>" onchange="this.form.submit()">
        </form>
        <div class="small text-muted mt-1">Showing budgets for <strong><?= $periodDisplay ?></strong></div>
      </div>
      <a href="?period=<?= urlencode($nextPeriod) ?>" class="btn btn-sm btn-outline-secondary">Next <i class="fas fa-chevron-right ms-1"></i></a>
    </div>
  </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-bullseye"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalBudgeted) ?></div><div class="stat-label">Total Budgeted</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-receipt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalSpent) ?></div><div class="stat-label">Total Spent</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon <?= $totalRemain >= 0 ? 'green-bg' : 'danger-bg' ?>"><i class="fas fa-wallet"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency(abs($totalRemain)) ?></div><div class="stat-label"><?= $totalRemain >= 0 ? 'Remaining' : 'Over Budget' ?></div></div>
    </div>
  </div>
</div>

<!-- Budget Cards -->
<?php if (empty($budgets)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
  <i class="fas fa-bullseye fa-3x mb-3 d-block opacity-25"></i>
  <h5>No budgets for <?= $periodDisplay ?></h5>
  <p>Create budgets to track your spending goals.</p>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#budgetModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Budget
  </button>
</div></div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($budgets as $b):
    $budgeted  = (float)$b['amount'];
    $spent     = (float)$b['actual_spent'];
    $remaining = $budgeted - $spent;
    $pct       = $budgeted > 0 ? min(100, round($spent / $budgeted * 100)) : 0;
    $overBudget = $spent > $budgeted;
    $barClass  = $overBudget ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-success');
  ?>
  <div class="col-md-6 col-xl-4">
    <div class="card shadow-sm <?= $overBudget ? 'border-danger' : '' ?>">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between mb-3">
          <div class="d-flex align-items-center gap-2">
            <div style="width:38px;height:38px;border-radius:9px;background:<?= e($b['category_color'] ?? '#16a085') ?>22;color:<?= e($b['category_color'] ?? '#16a085') ?>;display:flex;align-items:center;justify-content:center;">
              <i class="fas fa-tag"></i>
            </div>
            <div>
              <div class="fw-semibold"><?= e($b['category_name'] ?? 'Unknown') ?></div>
              <div class="small text-muted"><?= $periodDisplay ?></div>
            </div>
          </div>
          <?php if ($overBudget): ?>
          <span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>Over</span>
          <?php endif; ?>
        </div>

        <div class="mb-2">
          <div class="d-flex justify-content-between small mb-1">
            <span>Spent: <strong class="text-danger"><?= formatCurrency($spent) ?></strong></span>
            <span>Budget: <strong><?= formatCurrency($budgeted) ?></strong></span>
          </div>
          <div class="progress" style="height:10px">
            <div class="progress-bar <?= $barClass ?>" style="width:<?= $pct ?>%"></div>
          </div>
          <div class="d-flex justify-content-between small mt-1">
            <span class="text-muted"><?= $pct ?>% used</span>
            <span class="<?= $overBudget ? 'text-danger fw-semibold' : 'text-success' ?>">
              <?= $overBudget ? 'Over by ' . formatCurrency(abs($remaining)) : 'Left: ' . formatCurrency($remaining) ?>
            </span>
          </div>
        </div>

        <div class="d-flex gap-1 mt-3">
          <button class="btn btn-sm btn-outline-primary flex-fill" onclick='openEdit(<?= htmlspecialchars(json_encode($b), ENT_QUOTES) ?>)'>
            <i class="fas fa-edit me-1"></i>Edit
          </button>
          <button class="btn btn-sm btn-outline-danger" onclick="delBudget(<?= $b['id'] ?>, '<?= e($b['category_name'] ?? '') ?>', '<?= e($period) ?>')">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add/Edit Modal -->
<div class="modal fade" id="budgetModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="budgetId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="budgetModalTitle"><i class="fas fa-bullseye me-2"></i>Add Budget</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
              <select name="category_id" id="budgetCat" class="form-select" required>
                <option value="">-- Select Expense Category --</option>
                <?php foreach ($expenseCategories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (empty($expenseCategories)): ?>
              <div class="form-text text-warning"><i class="fas fa-exclamation-triangle me-1"></i>No expense categories found. <a href="categories.php">Create some first.</a></div>
              <?php endif; ?>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Period <span class="text-danger">*</span></label>
              <input type="month" name="period" id="budgetPeriod" class="form-control" value="<?= e($period) ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Budget Amount <span class="text-danger">*</span></label>
              <input type="number" name="amount" id="budgetAmount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Budget</button>
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
  <input type="hidden" name="period" id="deletePeriod" value="<?= e($period) ?>">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('budgetModalTitle').innerHTML = '<i class="fas fa-bullseye me-2"></i>Add Budget';
  document.getElementById('budgetId').value     = '0';
  document.getElementById('budgetCat').value    = '';
  document.getElementById('budgetAmount').value = '';
}
function openEdit(b) {
  document.getElementById('budgetModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Budget';
  document.getElementById('budgetId').value     = b.id;
  document.getElementById('budgetCat').value    = b.category_id || '';
  document.getElementById('budgetPeriod').value = b.period || '';
  document.getElementById('budgetAmount').value = b.amount || '';
  new bootstrap.Modal(document.getElementById('budgetModal')).show();
}
function delBudget(id, name, period) {
  Swal.fire({
    title: 'Delete Budget?',
    text: 'Budget for "' + name + '" will be deleted.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteId').value     = id;
      document.getElementById('deletePeriod').value = period;
      document.getElementById('deleteForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
