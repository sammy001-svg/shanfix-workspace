<?php
// ── SACCO: Savings Portfolio & Transactions ────────────────────
$moduleSlug  = 'sacco';
$moduleName  = 'SACCO Management';
$moduleIcon  = 'fas fa-piggy-bank';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'members.php',    'icon' => 'fas fa-users',          'label' => 'Members'],
    ['url' => 'savings.php',    'icon' => 'fas fa-piggy-bank',     'label' => 'Savings'],
    ['url' => 'loans.php',      'icon' => 'fas fa-hand-holding-usd','label' => 'Loans'],
    ['url' => 'repayments.php', 'icon' => 'fas fa-undo',           'label' => 'Repayments'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'deposit' || $action === 'withdrawal') {
        $memberId    = (int)($_POST['member_id']   ?? 0);
        $amount      = (float)($_POST['amount']     ?? 0.00);
        $reference   = sanitize($_POST['reference']  ?? '');
        $description = sanitize($_POST['description']?? '');

        if ($memberId <= 0 || $amount <= 0) {
            setFlash('danger', 'Please select a valid member and enter an amount greater than 0.');
            redirect('savings.php');
        }

        // Fetch current savings balance
        $stmt = $pdo->prepare("SELECT total_savings, first_name, last_name FROM sacco_members WHERE id=? AND org_id=?");
        $stmt->execute([$memberId, $orgId]);
        $member = $stmt->fetch();

        if (!$member) {
            setFlash('danger', 'Selected member does not exist.');
            redirect('savings.php');
        }

        $currentSavings = (float)$member['total_savings'];

        if ($action === 'deposit') {
            $newBalance = $currentSavings + $amount;
            
            // Insert savings transaction
            $stmt = $pdo->prepare("INSERT INTO sacco_savings (org_id, member_id, type, amount, balance_after, reference, description, created_by) VALUES (?,?, 'deposit', ?,?,?,?,?)");
            $stmt->execute([$orgId, $memberId, $amount, $newBalance, $reference, $description, $user['id']]);

            // Update member balance
            $stmt = $pdo->prepare("UPDATE sacco_members SET total_savings=? WHERE id=? AND org_id=?");
            $stmt->execute([$newBalance, $memberId, $orgId]);

            setFlash('success', 'Deposit of ' . formatCurrency($amount) . ' recorded successfully.');
            logActivity('create', 'sacco', "Savings Deposit: " . formatCurrency($amount) . " for {$member['first_name']} {$member['last_name']}");
        } else {
            // Withdrawal logic with safety check
            if ($amount > $currentSavings) {
                setFlash('danger', 'Insufficient savings balance. Member only has ' . formatCurrency($currentSavings) . ' available.');
                redirect('savings.php');
            }

            $newBalance = $currentSavings - $amount;

            // Insert savings transaction
            $stmt = $pdo->prepare("INSERT INTO sacco_savings (org_id, member_id, type, amount, balance_after, reference, description, created_by) VALUES (?,?, 'withdrawal', ?,?,?,?,?)");
            $stmt->execute([$orgId, $memberId, $amount, $newBalance, $reference, $description, $user['id']]);

            // Update member balance
            $stmt = $pdo->prepare("UPDATE sacco_members SET total_savings=? WHERE id=? AND org_id=?");
            $stmt->execute([$newBalance, $memberId, $orgId]);

            setFlash('success', 'Withdrawal of ' . formatCurrency($amount) . ' recorded successfully.');
            logActivity('create', 'sacco', "Savings Withdrawal: " . formatCurrency($amount) . " for {$member['first_name']} {$member['last_name']}");
        }
        redirect('savings.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Get members list for dropdown
$membersList = [];
try {
    $stmt = $pdo->prepare("SELECT id, member_no, first_name, last_name, total_savings FROM sacco_members WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]);
    $membersList = $stmt->fetchAll();
} catch (Exception $e) {}

// Transactions filter parameters
$filterMember = (int)($_GET['member_id'] ?? 0);
$filterType   = sanitize($_GET['type'] ?? '');
$where = "s.org_id = ?";
$params = [$orgId];

if ($filterMember > 0) {
    $where .= " AND s.member_id = ?";
    $params[] = $filterMember;
}
if ($filterType !== '') {
    $where .= " AND s.type = ?";
    $params[] = $filterType;
}

$transactions = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, m.first_name, m.last_name, m.member_no, u.name as cashier_name
        FROM sacco_savings s
        JOIN sacco_members m ON s.member_id = m.id
        LEFT JOIN users u ON s.created_by = u.id
        WHERE $where
        ORDER BY s.created_at DESC
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
} catch (Exception $e) {}

// Stat widgets metrics
$totalSavingsPortfolio = 0;
$totalDeposits         = 0;
$totalWithdrawals      = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_savings),0) FROM sacco_members WHERE org_id=?");
    $stmt->execute([$orgId]);
    $totalSavingsPortfolio = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sacco_savings WHERE org_id=? AND type='deposit'");
    $stmt->execute([$orgId]);
    $totalDeposits = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sacco_savings WHERE org_id=? AND type='withdrawal'");
    $stmt->execute([$orgId]);
    $totalWithdrawals = (float)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-piggy-bank me-2" style="color:<?= $moduleColor ?>"></i>Sacco Savings</h4>
    <p class="text-muted mb-0">Record and track Sacco deposits, withdrawals, and account statement transactions</p>
  </div>
  <div>
    <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#depositModal">
      <i class="fas fa-plus-circle me-1"></i>New Deposit
    </button>
    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#withdrawalModal">
      <i class="fas fa-minus-circle me-1"></i>Withdraw Savings
    </button>
  </div>
</div>

<!-- Stat widgets -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon purple-bg" style="background:rgba(142,68,173,0.15);color:#8e44ad"><i class="fas fa-wallet"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalSavingsPortfolio) ?></div><div class="stat-label">Active Savings Balance</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-arrow-alt-circle-down"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalDeposits) ?></div><div class="stat-label">Cumulative Deposits</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-arrow-alt-circle-up"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalWithdrawals) ?></div><div class="stat-label">Cumulative Withdrawals</div></div>
    </div>
  </div>
</div>

<!-- Filters Panel -->
<div class="card mb-4 border-0 shadow-sm">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Select Member</label>
        <select name="member_id" class="form-select form-select-sm">
          <option value="">All Members</option>
          <?php foreach ($membersList as $m): ?>
          <option value="<?= $m['id'] ?>" <?= $filterMember===$m['id']?'selected':'' ?>><?= e($m['first_name'].' '.$m['last_name'].' ('.$m['member_no'].')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <option value="deposit" <?= $filterType==='deposit'?'selected':'' ?>>Deposit</option>
          <option value="withdrawal" <?= $filterType==='withdrawal'?'selected':'' ?>>Withdrawal</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="savings.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Savings transaction logs table -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white border-bottom py-3">
    <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-history me-2" style="color:<?= $moduleColor ?>"></i>Savings Transaction Statement</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="savingsTable">
        <thead class="table-light">
          <tr>
            <th>Tx Date</th>
            <th>Member</th>
            <th>Tx Type</th>
            <th class="text-end">Amount</th>
            <th class="text-end">Balance After</th>
            <th>Payment Ref</th>
            <th>Description</th>
            <th>Recorded By</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($transactions)): ?>
          <tr>
            <td colspan="8" class="text-center py-5 text-muted">
              <i class="fas fa-file-invoice-dollar fa-3x mb-3 d-block"></i>No transactions recorded.
            </td>
          </tr>
          <?php else: foreach ($transactions as $t): ?>
          <tr>
            <td><?= formatDateTime($t['created_at']) ?></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></div>
              <div class="small text-muted"><?= e($t['member_no']) ?></div>
            </td>
            <td>
              <?php if ($t['type'] === 'deposit'): ?>
              <span class="badge bg-success"><i class="fas fa-arrow-down me-1"></i>DEPOSIT</span>
              <?php else: ?>
              <span class="badge bg-danger"><i class="fas fa-arrow-up me-1"></i>WITHDRAWAL</span>
              <?php endif; ?>
            </td>
            <td class="text-end fw-bold <?= $t['type']==='deposit'?'text-success':'text-danger' ?>">
              <?= ($t['type']==='deposit'?'+':'-') . formatCurrency((float)$t['amount']) ?>
            </td>
            <td class="text-end fw-semibold text-dark"><?= formatCurrency((float)$t['balance_after']) ?></td>
            <td><code class="text-dark bg-light px-2 py-1 rounded"><?= e($t['reference'] ?: '—') ?></code></td>
            <td class="small text-muted"><?= e($t['description'] ?: '—') ?></td>
            <td><small class="text-muted"><i class="fas fa-user-shield me-1"></i><?= e($t['cashier_name'] ?? 'System') ?></small></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Deposit Modal -->
<div class="modal fade" id="depositModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="deposit">
        <div class="modal-header text-white bg-success">
          <h5 class="modal-title"><i class="fas fa-arrow-alt-circle-down me-2"></i>New Savings Deposit</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Select Member <span class="text-danger">*</span></label>
            <select name="member_id" class="form-select" required>
              <option value="">-- Choose Member --</option>
              <?php foreach ($membersList as $m): ?>
              <option value="<?= $m['id'] ?>"><?= e($m['first_name'].' '.$m['last_name'].' ('.$m['member_no'].')') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Deposit Amount (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="amount" class="form-control form-control-lg fw-bold" required min="0.01" placeholder="0.00">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Payment Reference (M-Pesa, Bank Receipt, etc)</label>
            <input type="text" name="reference" class="form-control" placeholder="e.g. QWE123RTY">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Notes / Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="e.g. Monthly savings contribution"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Deposit</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Withdrawal Modal -->
<div class="modal fade" id="withdrawalModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="withdrawal">
        <div class="modal-header text-white bg-danger">
          <h5 class="modal-title"><i class="fas fa-arrow-alt-circle-up me-2"></i>Withdraw Member Savings</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Select Member <span class="text-danger">*</span></label>
            <select name="member_id" id="withdrawalMember" class="form-select" required onchange="showAvailableSavings()">
              <option value="">-- Choose Member --</option>
              <?php foreach ($membersList as $m): ?>
              <option value="<?= $m['id'] ?>" data-savings="<?= (float)$m['total_savings'] ?>">
                <?= e($m['first_name'].' '.$m['last_name'].' ('.$m['member_no'].')') ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text text-success fw-semibold mt-1" id="availableSavingsNotice" style="display:none">
              Available Balance: <span id="availableSavingsSpan">KES 0.00</span>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Withdrawal Amount (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="amount" id="withdrawalAmount" class="form-control form-control-lg fw-bold" required min="0.01" placeholder="0.00">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Transaction Reference / Voucher No</label>
            <input type="text" name="reference" class="form-control" placeholder="e.g. WDR-1234">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Reason for Withdrawal</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Briefly specify the withdrawal purpose"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-hand-holding-usd me-1"></i>Process Withdrawal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
  $("#savingsTable").DataTable({pageLength:10,order:[[0,"desc"]]});
});

function showAvailableSavings() {
  var selected = $("#withdrawalMember option:selected");
  var savings = parseFloat(selected.attr("data-savings") || 0);
  if (selected.val()) {
    $("#availableSavingsSpan").text("KES " + savings.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,"));
    $("#withdrawalAmount").attr("max", savings);
    $("#availableSavingsNotice").show();
  } else {
    $("#availableSavingsNotice").hide();
  }
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
