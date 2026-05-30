<?php
$moduleSlug  = 'finance';
$moduleName  = 'Finance & Budgeting';
$moduleIcon  = 'fas fa-wallet';
$moduleColor = '#16a085';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt',   'label' => 'Dashboard'],
    ['url' => 'income.php',         'icon' => 'fas fa-arrow-circle-down','label'=> 'Income'],
    ['url' => 'expenses.php',       'icon' => 'fas fa-arrow-circle-up',  'label'=> 'Expenses'],
    ['url' => 'accounts.php',       'icon' => 'fas fa-university',       'label' => 'Accounts'],
    ['url' => 'transactions.php',   'icon' => 'fas fa-exchange-alt',     'label' => 'All Transactions'],
    ['url' => 'categories.php',     'icon' => 'fas fa-tags',             'label' => 'Categories'],
    ['url' => 'budgets.php',        'icon' => 'fas fa-bullseye',         'label' => 'Budgets'],
    ['url' => 'journals.php',       'icon' => 'fas fa-book',             'label' => 'Journals'],
    ['url' => 'reconciliation.php', 'icon' => 'fas fa-check-double',     'label' => 'Reconciliation'],
    ['url' => 'statements.php',     'icon' => 'fas fa-file-alt',         'label' => 'Statements'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',        'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int)($_POST['id']          ?? 0);
        $accountId  = (int)($_POST['account_id']  ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $amount     = (float)($_POST['amount']     ?? 0);
        $desc       = sanitize($_POST['description'] ?? '');
        $date       = $_POST['date']               ?? date('Y-m-d');
        $ref        = sanitize($_POST['reference'] ?? '');

        // Attachment upload
        $attachPath = null;
        if (!empty($_FILES['attachment']['tmp_name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['attachment']['name'] ?? '', PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp','pdf'])) {
                $dir = __DIR__ . '/../../assets/uploads/fin-attachments/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'fin-exp-' . $orgId . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dir . $fname)) {
                    $attachPath = 'assets/uploads/fin-attachments/' . $fname;
                }
            }
        }

        if ($id > 0) {
            if ($attachPath) {
                $pdo->prepare("UPDATE fin_transactions SET account_id=?,category_id=?,amount=?,description=?,date=?,reference=?,attachment=? WHERE id=? AND org_id=? AND type='expense'")
                    ->execute([$accountId ?: null, $categoryId ?: null, $amount, $desc, $date, $ref, $attachPath, $id, $orgId]);
            } else {
                $pdo->prepare("UPDATE fin_transactions SET account_id=?,category_id=?,amount=?,description=?,date=?,reference=? WHERE id=? AND org_id=? AND type='expense'")
                    ->execute([$accountId ?: null, $categoryId ?: null, $amount, $desc, $date, $ref, $id, $orgId]);
            }
            setFlash('success', 'Expense updated.');
            logActivity('update', 'finance', "Updated expense #$id");
        } else {
            $pdo->prepare("INSERT INTO fin_transactions (org_id,account_id,category_id,type,amount,description,date,reference,attachment,created_by) VALUES (?,?,?,'expense',?,?,?,?,?,?)")
                ->execute([$orgId, $accountId ?: null, $categoryId ?: null, $amount, $desc, $date, $ref, $attachPath, $user['id']]);
            if ($accountId) {
                $pdo->prepare("UPDATE fin_accounts SET balance=balance-? WHERE id=? AND org_id=?")->execute([$amount, $accountId, $orgId]);
            }
            setFlash('success', 'Expense of ' . formatCurrency($amount) . ' recorded.');
            logActivity('create', 'finance', "Recorded expense: $desc — " . number_format($amount, 2));
        }
        redirect('expenses.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $tx = $pdo->prepare("SELECT account_id, amount FROM fin_transactions WHERE id=? AND org_id=? AND type='expense'");
        $tx->execute([$id, $orgId]);
        $tx = $tx->fetch();
        if ($tx && $tx['account_id']) {
            $pdo->prepare("UPDATE fin_accounts SET balance=balance+? WHERE id=? AND org_id=?")->execute([$tx['amount'], $tx['account_id'], $orgId]);
        }
        $pdo->prepare("DELETE FROM fin_transactions WHERE id=? AND org_id=? AND type='expense'")->execute([$id, $orgId]);
        setFlash('success', 'Expense deleted.');
        redirect('expenses.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterFrom = $_GET['from'] ?? date('Y-m-01');
$filterTo   = $_GET['to']   ?? date('Y-m-d');
$filterCat  = (int)($_GET['cat'] ?? 0);

$where  = "t.org_id=? AND t.type='expense' AND t.date BETWEEN ? AND ?";
$params = [$orgId, $filterFrom, $filterTo];
if ($filterCat) { $where .= ' AND t.category_id=?'; $params[] = $filterCat; }

$records = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, a.name AS account_name, c.name AS category_name, c.color AS cat_color
        FROM fin_transactions t
        LEFT JOIN fin_accounts a ON t.account_id=a.id
        LEFT JOIN fin_categories c ON t.category_id=c.id
        WHERE $where ORDER BY t.date DESC, t.id DESC
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll();
} catch (Exception $e) {}

$totalExpenses = array_sum(array_column($records, 'amount'));

$catBreakdown = [];
foreach ($records as $r) {
    $key = $r['category_name'] ?? 'Uncategorised';
    $catBreakdown[$key] = ($catBreakdown[$key] ?? 0) + (float)$r['amount'];
}
arsort($catBreakdown);

// Monthly trend
$monthlyTrend = [];
for ($i = 5; $i >= 0; $i--) {
    $mo  = date('Y-m', strtotime("-$i months"));
    $lbl = date('M Y', strtotime("-$i months"));
    $amt = 0;
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_transactions WHERE org_id=? AND type='expense' AND DATE_FORMAT(date,'%Y-%m')=?");
        $s->execute([$orgId, $mo]);
        $amt = (float)$s->fetchColumn();
    } catch (Exception $e) {}
    $monthlyTrend[] = ['label' => $lbl, 'amount' => $amt];
}

$accounts   = $pdo->prepare("SELECT id,name FROM fin_accounts WHERE org_id=? AND status='active' ORDER BY name");
$accounts->execute([$orgId]); $accounts = $accounts->fetchAll();
$categories = $pdo->prepare("SELECT id,name,color FROM fin_categories WHERE org_id=? AND type='expense' ORDER BY name");
$categories->execute([$orgId]); $categories = $categories->fetchAll();
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-arrow-circle-up me-2" style="color:#e74c3c"></i>Expenses</h4>
    <p class="text-muted mb-0">Record and manage all outgoing expenses</p>
  </div>
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#expModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Record Expense
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalExpenses) ?></div><div class="stat-label">Total Expenses</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-list-ol"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($records) ?></div><div class="stat-label">Records</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-tags"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($catBreakdown) ?></div><div class="stat-label">Categories</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-chart-line"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency(count($records) > 0 ? $totalExpenses / count($records) : 0) ?></div>
        <div class="stat-label">Avg per Record</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2 text-danger"></i>Monthly Expense Trend</h6></div>
      <div class="card-body"><canvas id="trendChart" height="120"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2 text-danger"></i>By Category</h6></div>
      <div class="card-body p-0">
        <?php if (empty($catBreakdown)): ?>
          <div class="text-center text-muted py-4">No data yet</div>
        <?php else: foreach ($catBreakdown as $cat => $amt): $pct = $totalExpenses > 0 ? round($amt/$totalExpenses*100) : 0; ?>
          <div class="px-3 py-2 border-bottom">
            <div class="d-flex justify-content-between mb-1">
              <span class="small fw-semibold"><?= e($cat) ?></span>
              <span class="small text-muted"><?= formatCurrency($amt) ?> (<?= $pct ?>%)</span>
            </div>
            <div class="progress" style="height:5px">
              <div class="progress-bar bg-danger" style="width:<?= $pct ?>%"></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($filterFrom) ?>">
      </div>
      <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($filterTo) ?>">
      </div>
      <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Category</label>
        <select name="cat" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filterCat == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="expenses.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-list me-2 text-danger"></i>Expense Records</h6>
    <span class="badge bg-secondary"><?= count($records) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr><th>Date</th><th>Description</th><th>Category</th><th>Account</th><th class="text-center">Attachment</th><th class="text-end">Amount</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($records)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No expense records for this period.</td></tr>
          <?php else: foreach ($records as $r): ?>
          <tr>
            <td><?= formatDate($r['date']) ?></td>
            <td class="fw-semibold"><?= e($r['description'] ?? '—') ?></td>
            <td>
              <?php if ($r['category_name']): ?>
                <span class="badge" style="background:<?= e($r['cat_color'] ?: '#e74c3c') ?>"><?= e($r['category_name']) ?></span>
              <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
            <td class="small text-muted"><?= e($r['account_name'] ?? '—') ?></td>
            <td class="text-center">
              <?php if (!empty($r['attachment'])): ?>
                <a href="<?= APP_URL ?>/<?= e($r['attachment']) ?>" target="_blank" class="btn btn-sm btn-outline-success" title="View attachment">
                  <i class="fas <?= str_ends_with($r['attachment'], '.pdf') ? 'fa-file-pdf' : 'fa-image' ?>"></i>
                </a>
              <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
            <td class="text-end fw-bold text-danger"><?= formatCurrency((float)$r['amount']) ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delRecord(<?= $r['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($records)): ?>
        <tfoot class="table-light">
          <tr>
            <td colspan="5" class="fw-bold text-end">Total:</td>
            <td class="text-end fw-bold text-danger"><?= formatCurrency($totalExpenses) ?></td>
            <td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="expModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="recId" value="0">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-arrow-circle-up me-2"></i>Record Expense</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
              <input type="text" name="description" id="recDesc" class="form-control" required placeholder="e.g. Office rent payment">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Amount (<?= CURRENCY_SYMBOL ?>) <span class="text-danger">*</span></label>
              <input type="number" name="amount" id="recAmount" class="form-control" step="0.01" min="0.01" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
              <input type="date" name="date" id="recDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Category</label>
              <select name="category_id" id="recCat" class="form-select">
                <option value="">-- No category --</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Debit Account</label>
              <select name="account_id" id="recAccount" class="form-select">
                <option value="">-- No account --</option>
                <?php foreach ($accounts as $a): ?>
                  <option value="<?= $a['id'] ?>"><?= e($a['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Reference</label>
              <input type="text" name="reference" id="recRef" class="form-control" placeholder="Receipt / voucher no.">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Attachment</label>
              <input type="file" name="attachment" class="form-control" accept="image/*,.pdf">
              <div class="form-text">Image or PDF · Max 5 MB</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<form method="POST" id="delForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delId">
</form>

<?php
$trendLabels  = json_encode(array_column($monthlyTrend, 'label'));
$trendAmounts = json_encode(array_column($monthlyTrend, 'amount'));
$extraJs = <<<JS
<script>
function openAdd() {
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-arrow-circle-up me-2"></i>Record Expense';
  document.getElementById('recId').value      = 0;
  document.getElementById('recDesc').value    = '';
  document.getElementById('recAmount').value  = '';
  document.getElementById('recDate').value    = new Date().toISOString().split('T')[0];
  document.getElementById('recCat').value     = '';
  document.getElementById('recAccount').value = '';
  document.getElementById('recRef').value     = '';
}

function openEdit(r) {
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Expense';
  document.getElementById('recId').value      = r.id;
  document.getElementById('recDesc').value    = r.description || '';
  document.getElementById('recAmount').value  = r.amount || '';
  document.getElementById('recDate').value    = r.date || '';
  document.getElementById('recCat').value     = r.category_id || '';
  document.getElementById('recAccount').value = r.account_id || '';
  document.getElementById('recRef').value     = r.reference || '';
  new bootstrap.Modal(document.getElementById('expModal')).show();
}

function delRecord(id) {
  Swal.fire({ title: 'Delete this expense?', icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Delete'
  }).then(r => { if (r.isConfirmed) { document.getElementById('delId').value = id; document.getElementById('delForm').submit(); } });
}

new Chart(document.getElementById('trendChart'), {
  type: 'bar',
  data: {
    labels: {$trendLabels},
    datasets: [{ label: 'Expenses', data: {$trendAmounts}, backgroundColor: '#e74c3c55', borderColor: '#e74c3c', borderWidth: 2, borderRadius: 6 }]
  },
  options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: v => 'KES ' + v.toLocaleString('en-KE') } } } }
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
