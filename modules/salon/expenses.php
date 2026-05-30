<?php
// ── Salon: Business Expenses ────────────────────────────────────
$moduleSlug = 'salon';
$moduleName = 'Salon & Spa';
$moduleIcon = 'fas fa-cut';
$moduleColor = '#c0392b';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'appointments.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Appointments'],
    ['url' => 'clients.php',      'icon' => 'fas fa-users',          'label' => 'Clients'],
    ['url' => 'services.php',     'icon' => 'fas fa-concierge-bell', 'label' => 'Services'],
    ['url' => 'staff.php',        'icon' => 'fas fa-user-tie',       'label' => 'Staff'],
    ['url' => 'packages.php',     'icon' => 'fas fa-gift',           'label' => 'Packages'],
    ['url' => 'inventory.php',    'icon' => 'fas fa-boxes',          'label' => 'Inventory'],
    ['url' => 'loyalty.php',      'icon' => 'fas fa-star',           'label' => 'Loyalty'],
    ['url' => 'payments.php',     'icon' => 'fas fa-money-bill-wave','label' => 'Payments'],
    ['url' => 'expenses.php',     'icon' => 'fas fa-receipt',        'label' => 'Expenses'],
    ['url' => 'promotions.php',   'icon' => 'fas fa-tag',            'label' => 'Promotions'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $category    = sanitize($_POST['category'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $amount      = (float)($_POST['amount'] ?? 0);
        $method      = in_array($_POST['method'] ?? '', ['cash','mpesa','card','bank']) ? $_POST['method'] : 'cash';
        $expDate     = sanitize($_POST['expense_date'] ?? date('Y-m-d'));
        $notes       = sanitize($_POST['notes'] ?? '');

        if ($id) {
            $pdo->prepare("UPDATE salon_expenses SET category=?,description=?,amount=?,method=?,expense_date=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$category,$description,$amount,$method,$expDate,$notes,$id,$orgId]);
            setFlash('success', 'Expense updated.');
        } else {
            $pdo->prepare("INSERT INTO salon_expenses (org_id,category,description,amount,method,expense_date,notes) VALUES (?,?,?,?,?,?,?)")
                ->execute([$orgId,$category,$description,$amount,$method,$expDate,$notes]);
            setFlash('success', 'Expense recorded.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM salon_expenses WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Expense deleted.');
    }
    redirect('expenses.php');
}

// Filters
$catFilter   = sanitize($_GET['category'] ?? '');
$monthFilter = sanitize($_GET['month'] ?? '');
$sql = "SELECT * FROM salon_expenses WHERE org_id=?";
$params = [$orgId];
if ($catFilter)   { $sql .= " AND category=?"; $params[] = $catFilter; }
if ($monthFilter) { $sql .= " AND DATE_FORMAT(expense_date,'%Y-%m')=?"; $params[] = $monthFilter; }
$sql .= " ORDER BY expense_date DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$expenses = $stmt->fetchAll();

// Categories for filter
$cats = $pdo->prepare("SELECT DISTINCT category FROM salon_expenses WHERE org_id=? ORDER BY category"); $cats->execute([$orgId]); $cats = $cats->fetchAll(PDO::FETCH_COLUMN);

// KPIs
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM salon_expenses WHERE org_id=?"); $stmt->execute([$orgId]);
$totalExpenses = (float)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM salon_expenses WHERE org_id=? AND DATE_FORMAT(expense_date,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')"); $stmt->execute([$orgId]);
$thisMonth = (float)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT category, SUM(amount) as total FROM salon_expenses WHERE org_id=? GROUP BY category ORDER BY total DESC LIMIT 1"); $stmt->execute([$orgId]);
$topCat = $stmt->fetch();

// Edit prefill
$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM salon_expenses WHERE id=? AND org_id=?");
    $stmt->execute([(int)$_GET['edit'], $orgId]);
    $editRow = $stmt->fetch();
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-receipt me-2" style="color:<?= $moduleColor ?>"></i>Business Expenses</h4>
    <p class="text-muted mb-0">Record and categorise salon operating costs</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#expModal" onclick="resetForm()">
    <i class="fas fa-plus me-1"></i>Add Expense
  </button>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(192,57,43,0.12);color:#c0392b"><i class="fas fa-receipt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalExpenses) ?></div><div class="stat-label">Total Expenses</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($thisMonth) ?></div><div class="stat-label">This Month</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-tag"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $topCat ? e($topCat['category']) : '—' ?></div><div class="stat-label">Top Category</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-3">
        <select name="category" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <?php foreach ($cats as $c): ?><option value="<?= e($c) ?>" <?= $catFilter===$c?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3"><input type="month" name="month" class="form-control form-control-sm" value="<?= e($monthFilter) ?>"></div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if ($catFilter||$monthFilter): ?><div class="col-auto"><a href="expenses.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Date</th>
            <th>Category</th>
            <th>Description</th>
            <th class="text-center">Method</th>
            <th class="text-end">Amount</th>
            <th>Notes</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($expenses)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No expenses recorded.</td></tr>
          <?php else: foreach ($expenses as $exp): ?>
          <tr>
            <td class="ps-3"><?= formatDate($exp['expense_date']) ?></td>
            <td><span class="badge bg-secondary"><?= e($exp['category']) ?></span></td>
            <td><?= e($exp['description']) ?></td>
            <td class="text-center"><?= strtoupper($exp['method']) ?></td>
            <td class="text-end fw-bold text-danger"><?= formatCurrency($exp['amount']) ?></td>
            <td class="text-muted small"><?= e($exp['notes']) ?></td>
            <td class="text-end pe-3">
              <a href="expenses.php?edit=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-primary me-1"
                 data-bs-toggle="modal" data-bs-target="#expModal"
                 onclick="fillForm(<?= htmlspecialchars(json_encode($exp), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
              </a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this expense?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $exp['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="expModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-receipt me-2"></i><span id="modalTitle">Add Expense</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="fId" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
              <input type="text" name="category" id="fCategory" class="form-control" list="catList" required>
              <datalist id="catList">
                <option value="Rent"><option value="Utilities"><option value="Salaries">
                <option value="Products/Stock"><option value="Equipment"><option value="Marketing">
                <option value="Maintenance"><option value="Insurance"><option value="Other">
              </datalist>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Date</label>
              <input type="date" name="expense_date" id="fDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
              <input type="text" name="description" id="fDesc" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Amount (KES)</label>
              <input type="number" name="amount" id="fAmount" class="form-control" step="0.01" min="0" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Payment Method</label>
              <select name="method" id="fMethod" class="form-select">
                <option value="cash">Cash</option>
                <option value="mpesa">M-Pesa</option>
                <option value="card">Card</option>
                <option value="bank">Bank</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function resetForm() {
  document.getElementById('modalTitle').textContent = 'Add Expense';
  document.getElementById('fId').value       = '0';
  document.getElementById('fCategory').value = '';
  document.getElementById('fDate').value     = new Date().toISOString().substr(0,10);
  document.getElementById('fDesc').value     = '';
  document.getElementById('fAmount').value   = '';
  document.getElementById('fMethod').value   = 'cash';
  document.getElementById('fNotes').value    = '';
}
function fillForm(e) {
  document.getElementById('modalTitle').textContent = 'Edit Expense';
  document.getElementById('fId').value       = e.id;
  document.getElementById('fCategory').value = e.category;
  document.getElementById('fDate').value     = e.expense_date;
  document.getElementById('fDesc').value     = e.description;
  document.getElementById('fAmount').value   = e.amount;
  document.getElementById('fMethod').value   = e.method;
  document.getElementById('fNotes').value    = e.notes;
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
