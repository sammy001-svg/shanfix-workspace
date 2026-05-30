<?php
// ── SACCO: Loan Repayments Ledger ──────────────────────────────
$moduleSlug  = 'sacco';
$moduleName  = 'SACCO Management';
$moduleIcon  = 'fas fa-piggy-bank';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',   'label' => 'Dashboard'],
    ['url' => 'members.php',      'icon' => 'fas fa-users',            'label' => 'Members'],
    ['url' => 'savings.php',      'icon' => 'fas fa-piggy-bank',       'label' => 'Savings'],
    ['url' => 'loans.php',        'icon' => 'fas fa-hand-holding-usd', 'label' => 'Loans'],
    ['url' => 'shares.php',       'icon' => 'fas fa-certificate',      'label' => 'Shares'],
    ['url' => 'repayments.php',   'icon' => 'fas fa-undo',             'label' => 'Repayments'],
    ['url' => 'dividends.php',    'icon' => 'fas fa-percentage',       'label' => 'Dividends'],
    ['url' => 'statements.php',   'icon' => 'fas fa-file-invoice',     'label' => 'Statements'],
    ['url' => 'guarantors.php',   'icon' => 'fas fa-user-shield',      'label' => 'Guarantors'],
    ['url' => 'penalties.php',    'icon' => 'fas fa-exclamation-circle','label' => 'Penalties'],
    ['url' => 'communications.php','icon'=> 'fas fa-envelope',          'label' => 'Communications'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',        'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'repay') {
        $loanId      = (int)($_POST['loan_id']       ?? 0);
        $amount      = (float)($_POST['amount']     ?? 0.00);
        $paymentDate = $_POST['payment_date']       ?? date('Y-m-d');
        $reference   = sanitize($_POST['reference']  ?? '');

        if ($loanId <= 0 || $amount <= 0) {
            setFlash('danger', 'Please select a valid loan and enter a repayment amount greater than 0.');
            redirect('repayments.php');
        }

        // Fetch loan outstanding details
        $stmt = $pdo->prepare("SELECT l.*, m.first_name, m.last_name FROM sacco_loans l JOIN sacco_members m ON l.member_id = m.id WHERE l.id=? AND l.org_id=? AND l.status='active'");
        $stmt->execute([$loanId, $orgId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            setFlash('danger', 'No active loan found matching this selection.');
            redirect('repayments.php');
        }

        $outstanding = (float)$loan['balance'];
        
        // Cap payment at outstanding balance
        if ($amount > $outstanding) {
            $amount = $outstanding;
        }

        // Proportional split into principal and interest based on simple amortized values
        $totalInterest  = (float)$loan['amount'] * ((float)$loan['interest_rate'] / 100) * ((int)$loan['term_months'] / 12);
        $totalRepayable = (float)$loan['amount'] + $totalInterest;
        $interestRatio  = $totalRepayable > 0 ? ($totalInterest / $totalRepayable) : 0;

        $interestPortion  = $amount * $interestRatio;
        $principalPortion = $amount - $interestPortion;
        $newBalance       = $outstanding - $amount;
        $newTotalPaid     = (float)$loan['total_paid'] + $amount;

        // Start transaction for atomicity
        try {
            $pdo->beginTransaction();

            // Record Repayment
            $stmt = $pdo->prepare("
                INSERT INTO sacco_loan_repayments (org_id, loan_id, amount, principal, interest, balance, payment_date, reference)
                VALUES (?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $loanId, $amount, $principalPortion, $interestPortion, $newBalance, $paymentDate, $reference]);

            // Update Loan balance & status
            $newStatus = $newBalance <= 0 ? 'completed' : 'active';
            $stmt = $pdo->prepare("UPDATE sacco_loans SET balance = ?, total_paid = ?, status = ? WHERE id = ?");
            $stmt->execute([$newBalance, $newTotalPaid, $newStatus, $loanId]);

            $pdo->commit();
            setFlash('success', 'Repayment of ' . formatCurrency($amount) . ' logged successfully.');
            logActivity('create', 'sacco', "Repayment of " . formatCurrency($amount) . " logged against loan #{$loan['loan_no']}");
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error updating repayment: ' . $e->getMessage());
        }
        redirect('repayments.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Get active loans list for repayment selector
$activeLoans = [];
try {
    $stmt = $pdo->prepare("
        SELECT l.id, l.loan_no, l.balance, m.first_name, m.last_name
        FROM sacco_loans l
        JOIN sacco_members m ON l.member_id = m.id
        WHERE l.org_id=? AND l.status='active'
        ORDER BY l.loan_no
    ");
    $stmt->execute([$orgId]);
    $activeLoans = $stmt->fetchAll();
} catch (Exception $e) {}

// Repayments listing
$repayments = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, l.loan_no, m.first_name, m.last_name, m.member_no
        FROM sacco_loan_repayments r
        JOIN sacco_loans l ON r.loan_id = l.id
        JOIN sacco_members m ON l.member_id = m.id
        WHERE r.org_id = ?
        ORDER BY r.payment_date DESC, r.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $repayments = $stmt->fetchAll();
} catch (Exception $e) {}

// Stat widgets metrics
$totalRepayments = 0.00;
$repaymentsCount = count($repayments);
$activeOutstanding = 0.00;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM sacco_loan_repayments WHERE org_id = ?");
    $stmt->execute([$orgId]);
    $totalRepayments = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(balance), 0) FROM sacco_loans WHERE org_id = ? AND status='active'");
    $stmt->execute([$orgId]);
    $activeOutstanding = (float)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-undo me-2" style="color:<?= $moduleColor ?>"></i>Loan Repayments</h4>
    <p class="text-muted mb-0">Record customer credit clearing transactions and view historical repayments logs</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#repayModal">
    <i class="fas fa-plus-circle me-1"></i>Post Loan Repayment
  </button>
</div>

<!-- Stat widgets -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon purple-bg" style="background:rgba(142,68,173,0.15);color:#8e44ad"><i class="fas fa-receipt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRepayments) ?></div><div class="stat-label">Total Repayments Collected</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($activeOutstanding) ?></div><div class="stat-label">Outstanding Balances</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-history"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $repaymentsCount ?></div><div class="stat-label">Total Repayment Count</div></div>
    </div>
  </div>
</div>

<!-- Repayments logs Table -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white border-bottom py-3">
    <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>Historical Repayments Ledger</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="repaymentsTable">
        <thead class="table-light">
          <tr>
            <th>Payment Date</th>
            <th>Member</th>
            <th>Loan Ref</th>
            <th class="text-end">Repaid Amount</th>
            <th class="text-end">Principal Share</th>
            <th class="text-end">Interest Share</th>
            <th class="text-end">Balance After</th>
            <th>Reference Code</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($repayments)): ?>
          <tr>
            <td colspan="8" class="text-center py-5 text-muted">
              <i class="fas fa-receipt fa-3x mb-3 d-block"></i>No loan repayments recorded.
            </td>
          </tr>
          <?php else: foreach ($repayments as $r): ?>
          <tr>
            <td><?= formatDate($r['payment_date']) ?></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></div>
              <div class="small text-muted"><?= e($r['member_no']) ?></div>
            </td>
            <td><span class="badge bg-secondary"><?= e($r['loan_no']) ?></span></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$r['amount']) ?></td>
            <td class="text-end text-muted small"><?= formatCurrency((float)$r['principal']) ?></td>
            <td class="text-end text-muted small"><?= formatCurrency((float)$r['interest']) ?></td>
            <td class="text-end fw-semibold text-danger"><?= formatCurrency((float)$r['balance']) ?></td>
            <td><code class="text-dark bg-light px-2 py-1 rounded"><?= e($r['reference'] ?: '—') ?></code></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Post Repayment Modal -->
<div class="modal fade" id="repayModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="repay">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Post Loan Repayment</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Select Active Loan <span class="text-danger">*</span></label>
            <select name="loan_id" id="repayLoan" class="form-select" required onchange="showOutstandingBalance()">
              <option value="">-- Select Active Credit --</option>
              <?php foreach ($activeLoans as $al): ?>
              <option value="<?= $al['id'] ?>" data-balance="<?= (float)$al['balance'] ?>">
                <?= e($al['first_name'].' '.$al['last_name'].' ('.$al['loan_no'].')') ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text text-danger fw-semibold mt-1" id="outstandingBalanceNotice" style="display:none">
              Remaining Balance Owed: <span id="outstandingBalanceSpan">KES 0.00</span>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Repayment Amount (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="amount" id="repayAmount" class="form-control form-control-lg fw-bold text-success" required min="0.01" placeholder="0.00">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
            <input type="date" name="payment_date" class="form-control" required value="<?= date('Y-m-d') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Payment Reference / Code (M-Pesa / Bank Slip)</label>
            <input type="text" name="reference" class="form-control" placeholder="e.g. MPESA-ABCDE12">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Post Repayment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
  $("#repaymentsTable").DataTable({pageLength:10,order:[[0,"desc"]]});
});

function showOutstandingBalance() {
  var selected = $("#repayLoan option:selected");
  var balance = parseFloat(selected.attr("data-balance") || 0);
  if (selected.val()) {
    $("#outstandingBalanceSpan").text("KES " + balance.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,"));
    $("#repayAmount").attr("max", balance);
    $("#outstandingBalanceNotice").show();
  } else {
    $("#outstandingBalanceNotice").hide();
  }
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
