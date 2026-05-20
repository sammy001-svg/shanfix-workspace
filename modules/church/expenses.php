<?php
// ── CHURCH: Expenses & Disbursements ───────────────────────────
$moduleSlug  = 'church';
$moduleName  = 'Church Management';
$moduleIcon  = 'fas fa-church';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt',     'label' => 'Dashboard'],
    ['url' => 'members.php',   'icon' => 'fas fa-users',              'label' => 'Members'],
    ['url' => 'offerings.php', 'icon' => 'fas fa-hand-holding-heart', 'label' => 'Offerings'],
    ['url' => 'expenses.php',  'icon' => 'fas fa-money-bill-wave',    'label' => 'Expenses'],
    ['url' => 'attendance.php','icon' => 'fas fa-clipboard-check',    'label' => 'Attendance'],
    ['url' => 'sermons.php',   'icon' => 'fas fa-bible',              'label' => 'Sermons'],
    ['url' => 'prayers.php',   'icon' => 'fas fa-praying-hands',      'label' => 'Prayer Requests'],
    ['url' => 'pastoral.php',  'icon' => 'fas fa-hands-helping',      'label' => 'Pastoral Care'],
    ['url' => 'events.php',    'icon' => 'fas fa-calendar-alt',       'label' => 'Events'],
    ['url' => 'cells.php',     'icon' => 'fas fa-home',               'label' => 'Cells / Groups'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],
];

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id            = (int)($_POST['id'] ?? 0);
        $category      = sanitize($_POST['category']       ?? '');
        $description   = sanitize($_POST['description']    ?? '');
        $amount        = (float)($_POST['amount']           ?? 0);
        $expenseDate   = $_POST['expense_date']             ?? date('Y-m-d');
        $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
        $reference     = sanitize($_POST['reference']      ?? '');
        $approvedBy    = sanitize($_POST['approved_by']    ?? '');
        $notes         = sanitize($_POST['notes']          ?? '');

        if (!$category || !$description || $amount <= 0) {
            setFlash('danger', 'Category, description, and amount are required.');
            redirect('expenses.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE church_expenses SET category=?,description=?,amount=?,expense_date=?,payment_method=?,reference=?,approved_by=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$category, $description, $amount, $expenseDate, $paymentMethod, $reference, $approvedBy, $notes, $id, $orgId]);
            setFlash('success', 'Expense record updated.');
            logActivity('update', 'church', "Updated expense #$id");
        } else {
            $pdo->prepare("INSERT INTO church_expenses (org_id,category,description,amount,expense_date,payment_method,reference,approved_by,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $category, $description, $amount, $expenseDate, $paymentMethod, $reference, $approvedBy, $notes, $user['id']]);
            setFlash('success', "Expense of ".formatCurrency($amount)." recorded.");
            logActivity('create', 'church', "Expense: $category — $description");
        }
        redirect('expenses.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM church_expenses WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Expense deleted.');
        logActivity('delete', 'church', "Deleted expense #$id");
        redirect('expenses.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterCategory = $_GET['category'] ?? '';
$filterFrom     = $_GET['from']     ?? date('Y-m-01');
$filterTo       = $_GET['to']       ?? date('Y-m-d');

$where  = 'org_id = ?';
$params = [$orgId];
if ($filterCategory) { $where .= ' AND category = ?';         $params[] = $filterCategory; }
if ($filterFrom)     { $where .= ' AND expense_date >= ?';    $params[] = $filterFrom; }
if ($filterTo)       { $where .= ' AND expense_date <= ?';    $params[] = $filterTo; }

$expenses = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM church_expenses WHERE $where ORDER BY expense_date DESC");
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();
} catch (Exception $e) {}

$totalExpenses = array_sum(array_column($expenses, 'amount'));

// Category breakdown for current filter
$catBreakdown = [];
foreach ($expenses as $ex) {
    $catBreakdown[$ex['category']] = ($catBreakdown[$ex['category']] ?? 0) + (float)$ex['amount'];
}
arsort($catBreakdown);

// Total offerings in same period for balance
$totalOfferings = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM church_offerings WHERE org_id=? AND date >= ? AND date <= ?");
    $stmt->execute([$orgId, $filterFrom, $filterTo]);
    $totalOfferings = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$balance = $totalOfferings - $totalExpenses;

$categories = ['Salaries & Stipends','Utilities (Water/Electricity)','Rent & Premises','Maintenance & Repairs','Printing & Stationery','Transport & Travel','Outreach & Evangelism','Welfare & Benevolence','Food & Hospitality','Tithes Disbursement','Equipment & Assets','Other'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-bill-wave me-2" style="color:<?= $moduleColor ?>"></i>Church Expenses</h4>
    <p class="text-muted mb-0">Track church expenditures, disbursements, and balance against offerings</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#expModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Record Expense
  </button>
</div>

<!-- Balance Summary -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-hand-holding-heart"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalOfferings) ?></div><div class="stat-label">Offerings (Period)</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalExpenses) ?></div><div class="stat-label">Total Expenses</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $balance >= 0 ? 'navy-bg' : 'danger-bg' ?>"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body">
        <div class="stat-value <?= $balance >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatCurrency(abs($balance)) ?></div>
        <div class="stat-label">Net <?= $balance >= 0 ? 'Surplus' : 'Deficit' ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(142,68,173,.12);color:#8e44ad"><i class="fas fa-list"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($expenses) ?></div><div class="stat-label">Expense Records</div></div>
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">From Date</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= $filterFrom ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">To Date</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= $filterTo ?>">
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Category</label>
        <select name="category" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat ?>" <?= $filterCategory===$cat?'selected':'' ?>><?= $cat ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="expenses.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Expenses Table -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2" style="color:<?= $moduleColor ?>"></i>Expense Records</h6>
        <span class="badge bg-secondary"><?= count($expenses) ?> records</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover data-table mb-0">
            <thead class="table-light">
              <tr>
                <th>Date</th>
                <th>Category</th>
                <th>Description</th>
                <th>Approved By</th>
                <th>Method</th>
                <th class="text-end">Amount</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($expenses)): ?>
              <tr><td colspan="7" class="text-center py-5 text-muted">
                <i class="fas fa-money-bill fa-2x mb-2 d-block"></i>No expenses recorded in this period.
              </td></tr>
              <?php else: foreach ($expenses as $ex): ?>
              <tr>
                <td><?= formatDate($ex['expense_date']) ?></td>
                <td><span class="badge bg-light text-dark border small"><?= e($ex['category']) ?></span></td>
                <td>
                  <div class="fw-semibold small"><?= e($ex['description']) ?></div>
                  <?php if ($ex['reference']): ?><div class="small text-muted"><?= e($ex['reference']) ?></div><?php endif; ?>
                </td>
                <td class="small"><?= e($ex['approved_by'] ?: '—') ?></td>
                <td class="small"><span class="badge bg-secondary"><?= ucfirst($ex['payment_method']) ?></span></td>
                <td class="text-end fw-bold text-danger"><?= formatCurrency((float)$ex['amount']) ?></td>
                <td class="text-center" style="white-space:nowrap">
                  <button class="btn btn-sm btn-outline-primary"
                    onclick='openEdit(<?= htmlspecialchars(json_encode($ex), ENT_QUOTES) ?>)'
                    title="Edit"><i class="fas fa-edit"></i></button>
                  <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this expense?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Category Breakdown -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>By Category</h6>
      </div>
      <div class="card-body p-0">
        <?php if (empty($catBreakdown)): ?>
        <div class="text-center text-muted py-4">No data</div>
        <?php else: ?>
        <table class="table table-sm mb-0">
          <tbody>
            <?php foreach ($catBreakdown as $cat => $total):
              $pct = $totalExpenses > 0 ? round(($total / $totalExpenses) * 100) : 0;
            ?>
            <tr>
              <td class="small">
                <div class="fw-semibold"><?= e($cat) ?></div>
                <div class="progress mt-1" style="height:4px">
                  <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $moduleColor ?>"></div>
                </div>
              </td>
              <td class="text-end fw-semibold text-danger small" style="white-space:nowrap">
                <?= formatCurrency($total) ?>
                <div class="text-muted fw-normal"><?= $pct ?>%</div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light">
            <tr>
              <td class="fw-bold">Total</td>
              <td class="text-end fw-bold text-danger"><?= formatCurrency($totalExpenses) ?></td>
            </tr>
          </tfoot>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="expModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="exId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="exTitle"><i class="fas fa-money-bill-wave me-2"></i>Record Expense</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
              <select name="category" id="exCategory" class="form-select" required>
                <option value="">— Select Category —</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>"><?= $cat ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
              <input type="text" name="description" id="exDesc" class="form-control" required placeholder="e.g. Monthly electricity bill">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Amount (<?= CURRENCY_SYMBOL ?>) <span class="text-danger">*</span></label>
              <input type="number" name="amount" id="exAmount" class="form-control" step="0.01" min="0.01" required placeholder="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Date</label>
              <input type="date" name="expense_date" id="exDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Payment Method</label>
              <select name="payment_method" id="exMethod" class="form-select">
                <option value="cash">Cash</option>
                <option value="mpesa">M-Pesa</option>
                <option value="bank">Bank Transfer</option>
                <option value="cheque">Cheque</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Reference / Receipt No.</label>
              <input type="text" name="reference" id="exRef" class="form-control" placeholder="e.g. MPESA-RQT123, Receipt #089">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Approved By</label>
              <input type="text" name="approved_by" id="exApproved" class="form-control" placeholder="e.g. Senior Pastor / Treasurer">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="exNotes" class="form-control" rows="2" placeholder="Additional details or justification..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("exTitle").innerHTML = "<i class=\"fas fa-money-bill-wave me-2\"></i>Record Expense";
  document.getElementById("exId").value       = 0;
  document.getElementById("exCategory").value = "";
  document.getElementById("exDesc").value     = "";
  document.getElementById("exAmount").value   = "";
  document.getElementById("exDate").value     = "' . date('Y-m-d') . '";
  document.getElementById("exMethod").value   = "cash";
  document.getElementById("exRef").value      = "";
  document.getElementById("exApproved").value = "";
  document.getElementById("exNotes").value    = "";
}
function openEdit(e) {
  document.getElementById("exTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Expense";
  document.getElementById("exId").value       = e.id;
  document.getElementById("exCategory").value = e.category       || "";
  document.getElementById("exDesc").value     = e.description    || "";
  document.getElementById("exAmount").value   = e.amount         || "";
  document.getElementById("exDate").value     = e.expense_date   || "";
  document.getElementById("exMethod").value   = e.payment_method || "cash";
  document.getElementById("exRef").value      = e.reference      || "";
  document.getElementById("exApproved").value = e.approved_by    || "";
  document.getElementById("exNotes").value    = e.notes          || "";
  new bootstrap.Modal(document.getElementById("expModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
