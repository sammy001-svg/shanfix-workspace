<?php
// ── Finance: Bank Reconciliation ───────────────────────────────
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
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',        'label' => 'Reports'],];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_reconciliation') {
        $accountId       = (int)($_POST['account_id'] ?? 0);
        $periodLabel     = sanitize($_POST['period_label'] ?? '');
        $statementDate   = $_POST['statement_date'] ?? date('Y-m-d');
        $statementBalance = (float)($_POST['statement_balance'] ?? 0);

        if ($accountId <= 0 || empty($periodLabel)) {
            setFlash('danger', 'Account and period label are required.');
            redirect('reconciliation.php');
        }

        // Calculate book balance: income transactions minus expense for that account
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END), 0)
                FROM fin_transactions
                WHERE org_id=? AND account_id=? AND DATE_FORMAT(transaction_date,'%Y-%m') <= ?
            ");
            $stmt->execute([$orgId, $accountId, date('Y-m', strtotime($statementDate))]);
            $bookBalance = (float)$stmt->fetchColumn();
        } catch (Exception $e) {
            $bookBalance = 0;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO fin_reconciliations (org_id, account_id, period_label, statement_date, statement_balance, book_balance)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE statement_date=VALUES(statement_date), statement_balance=VALUES(statement_balance), book_balance=VALUES(book_balance), updated_at=NOW()
            ");
            $stmt->execute([$orgId, $accountId, $periodLabel, $statementDate, $statementBalance, $bookBalance]);
            setFlash('success', 'Reconciliation saved for period ' . $periodLabel . '.');
            logActivity('create', 'finance', "Bank reconciliation saved: Account #{$accountId} period {$periodLabel}");
        } catch (Exception $e) {
            setFlash('danger', 'Error saving reconciliation: ' . $e->getMessage());
        }
        redirect('reconciliation.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Load bank/asset accounts
$bankAccounts = [];
try {
    $stmt = $pdo->prepare("SELECT id, account_name, account_code FROM fin_accounts WHERE org_id=? AND account_type IN ('asset','bank') ORDER BY account_code");
    $stmt->execute([$orgId]);
    $bankAccounts = $stmt->fetchAll();
    if (empty($bankAccounts)) {
        // fallback: all accounts
        $stmt = $pdo->prepare("SELECT id, account_name, account_code FROM fin_accounts WHERE org_id=? ORDER BY account_code");
        $stmt->execute([$orgId]);
        $bankAccounts = $stmt->fetchAll();
    }
} catch (Exception $e) {}

// Load existing reconciliations
$reconciliations = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, a.account_name, a.account_code,
               (r.statement_balance - r.book_balance) AS difference
        FROM fin_reconciliations r
        JOIN fin_accounts a ON a.id = r.account_id
        WHERE r.org_id = ?
        ORDER BY r.statement_date DESC
    ");
    $stmt->execute([$orgId]);
    $reconciliations = $stmt->fetchAll();
} catch (Exception $e) {}

// Summary stats
$matched    = count(array_filter($reconciliations, fn($r) => abs((float)$r['difference']) < 0.01));
$unmatched  = count($reconciliations) - $matched;
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-check-double me-2" style="color:<?= $moduleColor ?>"></i>Bank Reconciliation</h4>
    <p class="text-muted mb-0">Compare bank statements with book balances and identify discrepancies</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#reconcileModal">
    <i class="fas fa-plus-circle me-1"></i>New Reconciliation
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $matched ?></div><div class="stat-label">Matched Periods</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $unmatched ?></div><div class="stat-label">Periods With Discrepancy</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(22,160,133,0.12);color:#16a085"><i class="fas fa-university"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($bankAccounts) ?></div><div class="stat-label">Bank Accounts</div></div>
    </div>
  </div>
</div>

<!-- Reconciliation table -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white border-bottom py-3">
    <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>Reconciliation History</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="reconcileTable">
        <thead class="table-light">
          <tr>
            <th>Period</th>
            <th>Account</th>
            <th>Statement Date</th>
            <th class="text-end">Statement Balance</th>
            <th class="text-end">Book Balance</th>
            <th class="text-end">Difference</th>
            <th class="text-center">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($reconciliations)): ?>
          <tr>
            <td colspan="7" class="text-center py-5 text-muted">
              <i class="fas fa-check-double fa-3x mb-3 d-block"></i>No reconciliations recorded.
            </td>
          </tr>
          <?php else: foreach ($reconciliations as $r):
              $diff = (float)$r['difference'];
              $matched = abs($diff) < 0.01;
          ?>
          <tr>
            <td class="fw-semibold"><?= e($r['period_label']) ?></td>
            <td>
              <div class="fw-semibold"><?= e($r['account_name']) ?></div>
              <div class="small text-muted"><?= e($r['account_code']) ?></div>
            </td>
            <td><?= formatDate($r['statement_date']) ?></td>
            <td class="text-end fw-bold text-primary"><?= formatCurrency((float)$r['statement_balance']) ?></td>
            <td class="text-end"><?= formatCurrency((float)$r['book_balance']) ?></td>
            <td class="text-end fw-bold <?= $matched ? 'text-success' : ($diff > 0 ? 'text-warning' : 'text-danger') ?>">
              <?= $matched ? '—' : formatCurrency(abs($diff)) ?>
              <?= !$matched ? ($diff > 0 ? ' <small>(+)</small>' : ' <small>(–)</small>') : '' ?>
            </td>
            <td class="text-center">
              <?= $matched
                ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Reconciled</span>'
                : '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>Discrepancy</span>' ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- New Reconciliation Modal -->
<div class="modal fade" id="reconcileModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_reconciliation">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-check-double me-2"></i>New Bank Reconciliation</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info py-2 mb-4">
            <i class="fas fa-info-circle me-2"></i>The book balance is calculated automatically from recorded transactions for the selected account up to the statement date.
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Bank Account <span class="text-danger">*</span></label>
              <select name="account_id" class="form-select" required>
                <option value="">-- Select Account --</option>
                <?php foreach ($bankAccounts as $ac): ?>
                <option value="<?= $ac['id'] ?>"><?= e($ac['account_code'] . ' — ' . $ac['account_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Period Label <span class="text-danger">*</span></label>
              <input type="text" name="period_label" class="form-control" required placeholder="e.g. May 2026" value="<?= date('F Y') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Statement Date <span class="text-danger">*</span></label>
              <input type="date" name="statement_date" class="form-control" required value="<?= date('Y-m-t') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Statement Closing Balance (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="statement_balance" class="form-control form-control-lg fw-bold text-primary" required placeholder="0.00">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Reconciliation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function() {
    $("#reconcileTable").DataTable({pageLength:15, order:[[2,"desc"]]});
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
