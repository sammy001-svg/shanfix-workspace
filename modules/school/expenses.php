<?php
require_once __DIR__ . '/../../modules/school/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user   = currentUser();
$orgId  = (int)$user['org_id'];
$userId = (int)$user['id'];
$pageTitle = 'Expenses';

// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sch_expenses (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,category VARCHAR(100) NOT NULL,description TEXT NOT NULL,amount DECIMAL(14,2) NOT NULL DEFAULT 0,currency VARCHAR(10) NOT NULL DEFAULT 'KES',expense_date DATE NOT NULL,vendor VARCHAR(200) DEFAULT NULL,receipt_no VARCHAR(100) DEFAULT NULL,payment_method VARCHAR(50) DEFAULT NULL,status ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',approved_by INT DEFAULT NULL,approved_at DATETIME DEFAULT NULL,notes TEXT,created_by INT NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id),KEY idx_exp_org (org_id),KEY idx_exp_date (org_id,expense_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$saveMsg = null; $saveErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_expense') {
        $id      = (int)($_POST['id'] ?? 0);
        $cat     = trim($_POST['category'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $amount  = (float)($_POST['amount'] ?? 0);
        $curr    = trim($_POST['currency'] ?? 'KES');
        $date    = $_POST['expense_date'] ?? date('Y-m-d');
        $vendor  = trim($_POST['vendor'] ?? '');
        $receipt = trim($_POST['receipt_no'] ?? '');
        $method  = trim($_POST['payment_method'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');
        if (!$cat || !$desc || $amount <= 0) {
            $saveErr = 'Category, description and a positive amount are required.';
        } elseif ($id) {
            $pdo->prepare("UPDATE sch_expenses SET category=?,description=?,amount=?,currency=?,expense_date=?,vendor=?,receipt_no=?,payment_method=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$cat,$desc,$amount,$curr,$date,$vendor,$receipt,$method,$notes,$id,$orgId]);
            $saveMsg = 'Expense updated.';
        } else {
            $pdo->prepare("INSERT INTO sch_expenses (org_id,category,description,amount,currency,expense_date,vendor,receipt_no,payment_method,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$cat,$desc,$amount,$curr,$date,$vendor,$receipt,$method,$notes,$userId]);
            $saveMsg = 'Expense recorded.';
        }
    }

    elseif ($action === 'update_expense_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['new_status']??'', ['pending','approved','paid','rejected']) ? $_POST['new_status'] : 'pending';
        $pdo->prepare("UPDATE sch_expenses SET status=?,approved_by=?,approved_at=NOW() WHERE id=? AND org_id=?")
            ->execute([$status,$userId,$id,$orgId]);
        $saveMsg = 'Expense status updated to '.ucfirst($status).'.';
    }

    elseif ($action === 'delete_expense') {
        $id = (int)($_POST['id'] ?? 0);
        // Only pending expenses can be deleted
        $chk = $pdo->prepare("SELECT status FROM sch_expenses WHERE id=? AND org_id=?");
        $chk->execute([$id,$orgId]);
        if ($chk->fetchColumn() === 'pending') {
            $pdo->prepare("DELETE FROM sch_expenses WHERE id=? AND org_id=?")->execute([$id,$orgId]);
            $saveMsg = 'Expense deleted.';
        } else {
            $saveErr = 'Only pending expenses can be deleted.';
        }
    }
}

// Filters
$filterStatus = in_array($_GET['fs']??'', ['pending','approved','paid','rejected','all']) ? ($_GET['fs']??'all') : 'all';
$filterMonth  = (int)($_GET['fm'] ?? date('n'));
$filterYear   = (int)($_GET['fy'] ?? date('Y'));

// Load expenses
$expenses = [];
try {
    $where  = "org_id=?";
    $params = [$orgId];
    if ($filterStatus !== 'all') { $where .= " AND status=?"; $params[] = $filterStatus; }
    if ($filterYear) { $where .= " AND YEAR(expense_date)=?"; $params[] = $filterYear; }
    if ($filterMonth) { $where .= " AND MONTH(expense_date)=?"; $params[] = $filterMonth; }
    $s = $pdo->prepare("SELECT * FROM sch_expenses WHERE $where ORDER BY expense_date DESC LIMIT 200");
    $s->execute($params); $expenses = $s->fetchAll();
} catch (Throwable $e) {}

// Summary totals by category
$catTotals = [];
try {
    $s = $pdo->prepare("SELECT category, SUM(amount) AS total, COUNT(*) AS cnt FROM sch_expenses WHERE org_id=? AND YEAR(expense_date)=? AND status IN ('approved','paid') GROUP BY category ORDER BY total DESC");
    $s->execute([$orgId,$filterYear]); $catTotals = $s->fetchAll();
} catch (Throwable $e) {}

$ytdTotal = array_sum(array_column($catTotals, 'total'));

// Edit item
$editExp = null;
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    try {
        $s = $pdo->prepare("SELECT * FROM sch_expenses WHERE id=? AND org_id=?");
        $s->execute([$eid,$orgId]); $editExp = $s->fetch() ?: null;
    } catch (Throwable $e) {}
}

$cats = ['Stationery & Supplies','Utilities','Maintenance & Repairs','Transport','Catering','Salaries','Equipment','Events & Activities','Marketing','Insurance','Rent/Lease','Cleaning & Sanitation','IT & Technology','Medical & First Aid','Other'];
$methods = ['Cash','Bank Transfer','Cheque','Mobile Money','Card','Other'];
$monthNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$statusColors = ['pending'=>'warning','approved'=>'success','paid'=>'primary','rejected'=>'danger'];

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-receipt me-2" style="color:#1A8A4E"></i>Expense Management</h5>
    <div class="text-muted small mt-1">Record, review and approve school expenditures</div>
  </div>
  <button class="btn btn-success btn-sm px-3" data-bs-toggle="modal" data-bs-target="#expenseModal" onclick="openExpenseModal()">
    <i class="fas fa-plus me-1"></i>Record Expense
  </button>
</div>

<?php if ($saveMsg): ?>
<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?= e($saveMsg) ?></div>
<?php endif; ?>
<?php if ($saveErr): ?>
<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?= e($saveErr) ?></div>
<?php endif; ?>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <?php
    $pending  = count(array_filter($expenses, fn($e)=>$e['status']==='pending'));
    $approved = array_sum(array_column(array_filter($expenses, fn($e)=>in_array($e['status'],['approved','paid'])), 'amount'));
    $paid     = array_sum(array_column(array_filter($expenses, fn($e)=>$e['status']==='paid'), 'amount'));
  ?>
  <?php foreach ([
    ['val'=>'KES '.number_format($ytdTotal,2), 'lbl'=>'YTD Approved/Paid', 'icon'=>'fas fa-chart-line','bg'=>'#f0fdf4','ic'=>'#1A8A4E'],
    ['val'=>'KES '.number_format($approved,2), 'lbl'=>'This Period',        'icon'=>'fas fa-check-circle','bg'=>'#eff6ff','ic'=>'#1d4ed8'],
    ['val'=>$pending,   'lbl'=>'Pending Approval',  'icon'=>'fas fa-clock',       'bg'=>'#fffbeb','ic'=>'#f59e0b'],
    ['val'=>count($catTotals), 'lbl'=>'Categories (YTD)', 'icon'=>'fas fa-tags',  'bg'=>'#fdf4ff','ic'=>'#9333ea'],
  ] as $k): ?>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100" style="background:<?= $k['bg'] ?>">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="rounded d-flex align-items-center justify-content-center"
             style="width:44px;height:44px;background:<?= $k['ic'] ?>22">
          <i class="<?= $k['icon'] ?>" style="color:<?= $k['ic'] ?>;font-size:1.1rem"></i>
        </div>
        <div>
          <div class="fw-bold" style="font-size:1.1rem;line-height:1.2"><?= $k['val'] ?></div>
          <div class="text-muted small"><?= $k['lbl'] ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
  <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
    <select name="fm" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <option value="0">All Months</option>
      <?php for ($m=1;$m<=12;$m++): ?>
      <option value="<?= $m ?>" <?= $filterMonth===$m?'selected':'' ?>><?= $monthNames[$m] ?></option>
      <?php endfor; ?>
    </select>
    <select name="fy" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <?php for ($y=date('Y');$y>=2020;$y--): ?>
      <option value="<?= $y ?>" <?= $filterYear===$y?'selected':'' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
    <?php foreach (['all'=>'All','pending'=>'Pending','approved'=>'Approved','paid'=>'Paid','rejected'=>'Rejected'] as $f=>$lbl): ?>
    <a href="?fs=<?= $f ?>&fm=<?= $filterMonth ?>&fy=<?= $filterYear ?>"
       class="btn btn-sm <?= $filterStatus===$f?'btn-success':'btn-outline-secondary' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </form>
</div>

<div class="row g-4">
  <!-- Expenses table -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <?php if (empty($expenses)): ?>
            <tr><td colspan="6" class="text-center text-muted py-5 small">No expenses found for the selected filters.</td></tr>
            <?php else: foreach ($expenses as $exp): ?>
            <tr>
              <td class="small text-muted"><?= date('d M Y', strtotime($exp['expense_date'])) ?></td>
              <td class="small fw-semibold"><?= e($exp['category']) ?></td>
              <td class="small" style="max-width:200px">
                <div><?= e(mb_strimwidth($exp['description'],0,60,'…')) ?></div>
                <?php if ($exp['vendor']): ?><div class="text-muted" style="font-size:.68rem"><?= e($exp['vendor']) ?></div><?php endif; ?>
              </td>
              <td class="fw-semibold small"><?= $exp['currency'] ?>&nbsp;<?= number_format((float)$exp['amount'],2) ?></td>
              <td><span class="badge bg-<?= $statusColors[$exp['status']] ?>"><?= ucfirst($exp['status']) ?></span></td>
              <td class="text-end">
                <?php if ($exp['status'] === 'pending'): ?>
                <button class="btn btn-sm btn-outline-secondary"
                        onclick='openExpenseModal(<?= htmlspecialchars(json_encode($exp),ENT_QUOTES) ?>)'
                        data-bs-toggle="modal" data-bs-target="#expenseModal">
                  <i class="fas fa-edit"></i>
                </button>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="update_expense_status">
                  <input type="hidden" name="id" value="<?= $exp['id'] ?>">
                  <input type="hidden" name="new_status" value="approved">
                  <button class="btn btn-sm btn-outline-success" title="Approve">✓</button>
                </form>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this expense?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_expense">
                  <input type="hidden" name="id" value="<?= $exp['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                </form>
                <?php elseif ($exp['status'] === 'approved'): ?>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="update_expense_status">
                  <input type="hidden" name="id" value="<?= $exp['id'] ?>">
                  <input type="hidden" name="new_status" value="paid">
                  <button class="btn btn-sm btn-outline-primary">Mark Paid</button>
                </form>
                <?php else: ?>
                <span class="text-muted small"><?= $exp['approved_at'] ? date('d M', strtotime($exp['approved_at'])) : '' ?></span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Category breakdown -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-bold small"><?= $filterYear ?> Expenses by Category</div>
      <div class="card-body p-0">
        <?php if (empty($catTotals)): ?>
        <div class="text-center text-muted py-4 small">No approved/paid expenses yet.</div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($catTotals as $ct): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-2">
            <div>
              <div class="small fw-semibold"><?= e($ct['category']) ?></div>
              <div class="text-muted" style="font-size:.68rem"><?= $ct['cnt'] ?> expense(s)</div>
            </div>
            <span class="fw-bold small">KES <?= number_format((float)$ct['total'],2) ?></span>
          </li>
          <?php endforeach; ?>
          <li class="list-group-item d-flex justify-content-between bg-light py-2">
            <span class="fw-bold small">Total</span>
            <span class="fw-bold small">KES <?= number_format($ytdTotal,2) ?></span>
          </li>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h6 class="modal-title fw-bold" id="expModalTitle">Record Expense</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <div class="modal-body">
          <input type="hidden" name="action" value="save_expense">
          <input type="hidden" name="id" id="expId" value="0">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Category <span class="text-danger">*</span></label>
              <select class="form-select form-select-sm" name="category" id="expCat" required>
                <option value="">— Select Category —</option>
                <?php foreach ($cats as $c): ?>
                <option value="<?= e($c) ?>"><?= e($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold small">Amount <span class="text-danger">*</span></label>
              <input type="number" step="0.01" min="0.01" class="form-control form-control-sm" name="amount" id="expAmount" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold small">Currency</label>
              <select class="form-select form-select-sm" name="currency" id="expCurr">
                <?php foreach(['KES','USD','GBP','EUR','UGX','TZS'] as $c): ?>
                <option value="<?= $c ?>"><?= $c ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Description <span class="text-danger">*</span></label>
              <textarea class="form-control form-control-sm" name="description" id="expDesc" rows="2" required></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control form-control-sm" name="expense_date" id="expDate" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Vendor / Supplier</label>
              <input class="form-control form-control-sm" name="vendor" id="expVendor">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Receipt No.</label>
              <input class="form-control form-control-sm" name="receipt_no" id="expReceipt">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Payment Method</label>
              <select class="form-select form-select-sm" name="payment_method" id="expMethod">
                <option value="">— Select —</option>
                <?php foreach ($methods as $m): ?>
                <option value="<?= e($m) ?>"><?= e($m) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold small">Internal Notes</label>
              <input class="form-control form-control-sm" name="notes" id="expNotes">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-success px-4"><i class="fas fa-save me-1"></i>Save Expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openExpenseModal(exp) {
    var today = new Date().toISOString().split('T')[0];
    document.getElementById('expModalTitle').textContent = exp ? 'Edit Expense' : 'Record Expense';
    document.getElementById('expId').value       = exp ? exp.id : 0;
    document.getElementById('expCat').value      = exp ? exp.category : '';
    document.getElementById('expAmount').value   = exp ? exp.amount : '';
    document.getElementById('expCurr').value     = exp ? exp.currency : 'KES';
    document.getElementById('expDesc').value     = exp ? exp.description : '';
    document.getElementById('expDate').value     = exp ? exp.expense_date : today;
    document.getElementById('expVendor').value   = exp ? (exp.vendor||'') : '';
    document.getElementById('expReceipt').value  = exp ? (exp.receipt_no||'') : '';
    document.getElementById('expMethod').value   = exp ? (exp.payment_method||'') : '';
    document.getElementById('expNotes').value    = exp ? (exp.notes||'') : '';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
