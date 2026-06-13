<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireModuleAccess('school');
$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $category    = in_array($_POST['category'] ?? '', ['income','expense']) ? $_POST['category'] : 'expense';
        $type        = sanitize($_POST['type'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $budgeted    = max(0, (float)($_POST['budgeted_amount'] ?? 0));
        $actual      = max(0, (float)($_POST['actual_amount'] ?? 0));
        $txDate      = $_POST['transaction_date'] ?: date('Y-m-d');
        $yearId      = (int)($_POST['academic_year_id'] ?? 0) ?: null;
        $termId      = (int)($_POST['term_id'] ?? 0) ?: null;
        $notes       = sanitize($_POST['notes'] ?? '');

        if (!$type || !$description) {
            setFlash('danger', 'Type and description are required.');
            redirect('budget.php');
        }

        if ($id > 0) {
            requireOrgOwnership('sch_budget_items', $id, $orgId);
            $pdo->prepare(
                "UPDATE sch_budget_items SET
                    category=?, type=?, description=?, budgeted_amount=?, actual_amount=?,
                    transaction_date=?, academic_year_id=?, term_id=?, notes=?
                 WHERE id=? AND org_id=?"
            )->execute([$category,$type,$description,$budgeted,$actual,$txDate,$yearId,$termId,$notes,$id,$orgId]);
            setFlash('success', 'Budget entry updated.');
        } else {
            $pdo->prepare(
                "INSERT INTO sch_budget_items
                 (org_id,category,type,description,budgeted_amount,actual_amount,transaction_date,academic_year_id,term_id,recorded_by,notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$orgId,$category,$type,$description,$budgeted,$actual,$txDate,$yearId,$termId,$user['id'],$notes]);
            setFlash('success', 'Budget entry recorded.');
        }
        logActivity('create', 'school', "Budget entry: $category — $description");
        redirect('budget.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_budget_items', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_budget_items WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Entry deleted.');
        redirect('budget.php');
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$fYear     = (int)($_GET['year_id']  ?? 0);
$fTerm     = (int)($_GET['term_id']  ?? 0);
$fCategory = in_array($_GET['cat'] ?? '', ['income','expense']) ? $_GET['cat'] : '';

// ── Academic years / terms ────────────────────────────────────────────────────
$years = $terms = [];
try {
    $s = $pdo->prepare("SELECT id,name,is_current FROM sch_academic_years WHERE org_id=? ORDER BY start_date DESC");
    $s->execute([$orgId]); $years = $s->fetchAll();
} catch (Throwable $e) {}
try {
    $s = $pdo->prepare("SELECT id,name,academic_year_id FROM sch_terms WHERE org_id=? ORDER BY start_date DESC");
    $s->execute([$orgId]); $terms = $s->fetchAll();
} catch (Throwable $e) {}

// Default to current year
if (!$fYear) {
    foreach ($years as $y) { if ($y['is_current']) { $fYear = (int)$y['id']; break; } }
}

// ── Fetch entries ─────────────────────────────────────────────────────────────
$where  = 'b.org_id=?';
$params = [$orgId];
if ($fYear)     { $where .= ' AND b.academic_year_id=?'; $params[] = $fYear; }
if ($fTerm)     { $where .= ' AND b.term_id=?';          $params[] = $fTerm; }
if ($fCategory) { $where .= ' AND b.category=?';         $params[] = $fCategory; }

$entries = [];
try {
    $s = $pdo->prepare(
        "SELECT b.*, ay.name AS year_name, t.name AS term_name
         FROM sch_budget_items b
         LEFT JOIN sch_academic_years ay ON b.academic_year_id = ay.id
         LEFT JOIN sch_terms t           ON b.term_id           = t.id
         WHERE $where
         ORDER BY b.transaction_date DESC, b.id DESC"
    );
    $s->execute($params); $entries = $s->fetchAll();
} catch (Throwable $e) {}

// ── Summary totals ────────────────────────────────────────────────────────────
$totals = ['income_budgeted'=>0,'income_actual'=>0,'expense_budgeted'=>0,'expense_actual'=>0];
foreach ($entries as $e) {
    if ($e['category'] === 'income') {
        $totals['income_budgeted'] += $e['budgeted_amount'];
        $totals['income_actual']   += $e['actual_amount'];
    } else {
        $totals['expense_budgeted'] += $e['budgeted_amount'];
        $totals['expense_actual']   += $e['actual_amount'];
    }
}
$netBudgeted = $totals['income_budgeted'] - $totals['expense_budgeted'];
$netActual   = $totals['income_actual']   - $totals['expense_actual'];

// ── Edit mode ─────────────────────────────────────────────────────────────────
$editing = null;
if (!empty($_GET['edit'])) {
    try {
        $s = $pdo->prepare("SELECT * FROM sch_budget_items WHERE id=? AND org_id=?");
        $s->execute([(int)$_GET['edit'], $orgId]);
        $editing = $s->fetch() ?: null;
    } catch (Throwable $e) {}
}

$expenseTypes = ['Salaries & Wages','Utilities','Maintenance & Repairs','Supplies & Materials',
                 'Transport','Library','Technology','Marketing','Events','Insurance','Other'];
$incomeTypes  = ['Tuition Fees','Exam Fees','Activity Fees','Donations','Government Grant',
                 'Hostel Fees','Transport Fees','Other'];

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>School Budget &amp; Expenses</h4>
    <p class="text-muted mb-0 small">Track income and expenditure by term and academic year</p>
  </div>
  <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>"
          data-bs-toggle="modal" data-bs-target="#budgetModal">
    <i class="fas fa-plus me-1"></i>Add Entry
  </button>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-arrow-down"></i></div>
      <div class="stat-body">
        <div class="stat-value text-success"><?= formatCurrency($totals['income_actual']) ?></div>
        <div class="stat-label">Total Income (Actual)</div>
        <div class="small text-muted">Budgeted: <?= formatCurrency($totals['income_budgeted']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#fde8e8"><i class="fas fa-arrow-up" style="color:#e74c3c"></i></div>
      <div class="stat-body">
        <div class="stat-value text-danger"><?= formatCurrency($totals['expense_actual']) ?></div>
        <div class="stat-label">Total Expenses (Actual)</div>
        <div class="small text-muted">Budgeted: <?= formatCurrency($totals['expense_budgeted']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $netActual >= 0 ? 'green-bg' : '' ?>" style="<?= $netActual < 0 ? 'background:#fde8e8' : '' ?>">
        <i class="fas fa-balance-scale" style="<?= $netActual < 0 ? 'color:#e74c3c' : '' ?>"></i>
      </div>
      <div class="stat-body">
        <div class="stat-value <?= $netActual >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatCurrency(abs($netActual)) ?></div>
        <div class="stat-label">Net <?= $netActual >= 0 ? 'Surplus' : 'Deficit' ?></div>
        <div class="small text-muted">Budgeted: <?= formatCurrency($netBudgeted) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-list-alt"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= count($entries) ?></div>
        <div class="stat-label">Total Entries</div>
        <div class="small text-muted"><?= array_sum(array_column($entries, 'category') === 'income' ? [] : []) ?>&nbsp;</div>
      </div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-end" method="GET">
      <div class="col-md-3">
        <label class="form-label form-label-sm mb-1">Academic Year</label>
        <select name="year_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Years</option>
          <?php foreach ($years as $y): ?>
          <option value="<?= $y['id'] ?>" <?= $fYear == $y['id'] ? 'selected' : '' ?>>
            <?= e($y['name']) ?><?= $y['is_current'] ? ' (Current)' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label form-label-sm mb-1">Term</label>
        <select name="term_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Terms</option>
          <?php foreach ($terms as $t): ?>
          <option value="<?= $t['id'] ?>" <?= $fTerm == $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label form-label-sm mb-1">Category</label>
        <select name="cat" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All</option>
          <option value="income"  <?= $fCategory==='income'  ? 'selected' : '' ?>>Income</option>
          <option value="expense" <?= $fCategory==='expense' ? 'selected' : '' ?>>Expense</option>
        </select>
      </div>
      <div class="col-auto">
        <a href="budget.php" class="btn btn-sm btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Entries table -->
<div class="card border-0 shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6 class="mb-0 fw-bold"><i class="fas fa-table me-2"></i>Budget Entries</h6>
    <span class="badge bg-secondary"><?= count($entries) ?> records</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($entries)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-chart-pie fa-3x opacity-25 mb-3 d-block"></i>
      <p class="mb-1 fw-semibold">No budget entries yet</p>
      <p class="small">Click <strong>Add Entry</strong> to record income or expenses.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Category</th>
            <th>Type</th>
            <th>Description</th>
            <th>Term / Year</th>
            <th class="text-end">Budgeted</th>
            <th class="text-end">Actual</th>
            <th class="text-end">Variance</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $row):
            $variance = $row['actual_amount'] - $row['budgeted_amount'];
            $varClass = $row['category'] === 'income'
                ? ($variance >= 0 ? 'text-success' : 'text-danger')
                : ($variance <= 0 ? 'text-success' : 'text-danger');
          ?>
          <tr>
            <td class="small"><?= date('d M Y', strtotime($row['transaction_date'])) ?></td>
            <td>
              <span class="badge <?= $row['category']==='income' ? 'bg-success' : 'bg-danger' ?> bg-opacity-10
                    <?= $row['category']==='income' ? 'text-success' : 'text-danger' ?> border
                    <?= $row['category']==='income' ? 'border-success' : 'border-danger' ?>">
                <?= ucfirst($row['category']) ?>
              </span>
            </td>
            <td class="small fw-semibold"><?= e($row['type']) ?></td>
            <td class="small"><?= e($row['description']) ?></td>
            <td class="small text-muted"><?= e($row['term_name'] ?? ($row['year_name'] ?? '—')) ?></td>
            <td class="text-end small"><?= formatCurrency($row['budgeted_amount']) ?></td>
            <td class="text-end small fw-semibold"><?= formatCurrency($row['actual_amount']) ?></td>
            <td class="text-end small <?= $varClass ?>">
              <?= ($variance >= 0 ? '+' : '') . formatCurrency(abs($variance)) ?>
            </td>
            <td class="text-end">
              <a href="budget.php?edit=<?= $row['id'] ?>" class="btn btn-xs btn-outline-secondary me-1" title="Edit">
                <i class="fas fa-pen"></i>
              </a>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this entry?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <button class="btn btn-xs btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
          <tr>
            <td colspan="5" class="text-end small">Totals</td>
            <td class="text-end small"><?= formatCurrency($totals['income_budgeted'] + $totals['expense_budgeted']) ?></td>
            <td class="text-end small"><?= formatCurrency($totals['income_actual'] + $totals['expense_actual']) ?></td>
            <td class="text-end small <?= $netActual >= 0 ? 'text-success' : 'text-danger' ?>">
              Net: <?= ($netActual >= 0 ? '+' : '') . formatCurrency($netActual) ?>
            </td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="budgetModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="budgetModalTitle">
          <i class="fas fa-<?= $editing ? 'pen' : 'plus' ?> me-2"></i><?= $editing ? 'Edit' : 'Add' ?> Budget Entry
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing ? $editing['id'] : 0 ?>">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label form-label-sm fw-semibold">Category *</label>
              <select name="category" class="form-select form-select-sm" id="budgetCat" required onchange="updateTypes()">
                <option value="expense" <?= (!$editing || $editing['category']==='expense') ? 'selected' : '' ?>>Expense</option>
                <option value="income"  <?= ($editing && $editing['category']==='income') ? 'selected' : '' ?>>Income</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label form-label-sm fw-semibold">Type *</label>
              <select name="type" class="form-select form-select-sm" id="budgetType" required>
                <?php foreach ($expenseTypes as $t): ?>
                <option value="<?= e($t) ?>" <?= ($editing && $editing['type']===$t) ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label form-label-sm fw-semibold">Transaction Date *</label>
              <input type="date" name="transaction_date" class="form-control form-control-sm" required
                     value="<?= e($editing ? $editing['transaction_date'] : date('Y-m-d')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label form-label-sm fw-semibold">Description *</label>
              <input type="text" name="description" class="form-control form-control-sm" required maxlength="255"
                     placeholder="e.g. January teacher salaries"
                     value="<?= e($editing ? $editing['description'] : '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label form-label-sm fw-semibold">Budgeted Amount</label>
              <input type="number" name="budgeted_amount" class="form-control form-control-sm"
                     min="0" step="0.01" placeholder="0.00"
                     value="<?= $editing ? $editing['budgeted_amount'] : '' ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label form-label-sm fw-semibold">Actual Amount</label>
              <input type="number" name="actual_amount" class="form-control form-control-sm"
                     min="0" step="0.01" placeholder="0.00"
                     value="<?= $editing ? $editing['actual_amount'] : '' ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label form-label-sm fw-semibold">Academic Year</label>
              <select name="academic_year_id" class="form-select form-select-sm">
                <option value="">— None —</option>
                <?php foreach ($years as $y): ?>
                <option value="<?= $y['id'] ?>" <?= ($editing && $editing['academic_year_id']==$y['id']) || (!$editing && $y['is_current']) ? 'selected' : '' ?>>
                  <?= e($y['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label form-label-sm fw-semibold">Term</label>
              <select name="term_id" class="form-select form-select-sm">
                <option value="">— None —</option>
                <?php foreach ($terms as $t): ?>
                <option value="<?= $t['id'] ?>" <?= ($editing && $editing['term_id']==$t['id']) ? 'selected' : '' ?>>
                  <?= e($t['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label form-label-sm fw-semibold">Notes</label>
              <textarea name="notes" class="form-control form-control-sm" rows="2"
                        placeholder="Optional notes"><?= e($editing ? $editing['notes'] : '') ?></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm text-white" style="background:<?= $moduleColor ?>">
            <i class="fas fa-save me-1"></i><?= $editing ? 'Update' : 'Save' ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const expenseTypes = <?= json_encode($expenseTypes) ?>;
const incomeTypes  = <?= json_encode($incomeTypes) ?>;

function updateTypes() {
  const cat  = document.getElementById('budgetCat').value;
  const sel  = document.getElementById('budgetType');
  const list = cat === 'income' ? incomeTypes : expenseTypes;
  sel.innerHTML = list.map(t => `<option value="${t}">${t}</option>`).join('');
}

<?php if ($editing): ?>
// Auto-open modal in edit mode
document.addEventListener('DOMContentLoaded', () => {
  new bootstrap.Modal(document.getElementById('budgetModal')).show();
  // Set correct types for the category
  updateTypes();
  document.getElementById('budgetType').value = <?= json_encode($editing['type']) ?>;
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
