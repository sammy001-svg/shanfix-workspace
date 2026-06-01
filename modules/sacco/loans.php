<?php
// ── SACCO: Loan Portfolio & Applications ────────────────────────
$moduleSlug  = 'sacco';
$moduleName  = 'SACCO Management';
$moduleIcon  = 'fas fa-piggy-bank';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',      'label' => 'Dashboard'],
    ['url' => 'members.php',      'icon' => 'fas fa-users',               'label' => 'Members'],
    ['url' => 'savings.php',      'icon' => 'fas fa-piggy-bank',          'label' => 'Savings'],
    ['url' => 'loans.php',        'icon' => 'fas fa-hand-holding-usd',    'label' => 'Loans'],
    ['url' => 'schedule.php',     'icon' => 'fas fa-calendar-alt',        'label' => 'Schedules'],
    ['url' => 'arrears.php',      'icon' => 'fas fa-exclamation-triangle','label' => 'Arrears'],
    ['url' => 'shares.php',       'icon' => 'fas fa-certificate',         'label' => 'Shares'],
    ['url' => 'repayments.php',   'icon' => 'fas fa-undo',                'label' => 'Repayments'],
    ['url' => 'dividends.php',    'icon' => 'fas fa-percentage',          'label' => 'Dividends'],
    ['url' => 'statements.php',   'icon' => 'fas fa-file-invoice',        'label' => 'Statements'],
    ['url' => 'guarantors.php',   'icon' => 'fas fa-user-shield',         'label' => 'Guarantors'],
    ['url' => 'penalties.php',    'icon' => 'fas fa-exclamation-circle',  'label' => 'Penalties'],
    ['url' => 'communications.php','icon'=> 'fas fa-envelope',             'label' => 'Communications'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',           'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'request') {
        $memberId       = (int)($_POST['member_id']       ?? 0);
        $amount         = (float)($_POST['amount']         ?? 0.00);
        $interestRate   = (float)($_POST['interest_rate']   ?? 12.00); // Annual interest
        $termMonths     = (int)($_POST['term_months']     ?? 12);
        $purpose        = sanitize($_POST['purpose']       ?? '');
        $guarantorName  = sanitize($_POST['guarantor_name']?? '');
        $guarantorPhone = sanitize($_POST['guarantor_phone']?? '');

        if ($memberId <= 0 || $amount <= 0 || $termMonths <= 0) {
            setFlash('danger', 'Please provide a valid member, loan amount, and repayment term.');
            redirect('loans.php');
        }

        // Generate Loan Number
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM sacco_loans WHERE org_id = ?");
        $stmt->execute([$orgId]);
        $maxId = (int)$stmt->fetchColumn();
        $loanNo = 'LN-' . str_pad($maxId + 1, 4, '0', STR_PAD_LEFT);

        // EMI Calculation (Amortized Simple Interest)
        $totalInterest  = $amount * ($interestRate / 100) * ($termMonths / 12);
        $totalRepayable = $amount + $totalInterest;
        $monthlyPayment = $totalRepayable / $termMonths;

        $stmt = $pdo->prepare("
            INSERT INTO sacco_loans (org_id, member_id, loan_no, amount, interest_rate, term_months, monthly_payment, total_paid, balance, purpose, guarantor_name, guarantor_phone, status)
            VALUES (?,?,?,?,?,?,?, 0.00, ?,?,?,?,'pending')
        ");
        $stmt->execute([$orgId, $memberId, $loanNo, $amount, $interestRate, $termMonths, $monthlyPayment, $totalRepayable, $purpose, $guarantorName, $guarantorPhone]);

        setFlash('success', 'Loan request ' . $loanNo . ' submitted for ' . formatCurrency($amount) . '.');
        logActivity('create', 'sacco', "Loan Request $loanNo for member #$memberId");
        redirect('loans.php');
    }

    if ($action === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE sacco_loans SET status='approved', approved_by=? WHERE id=? AND org_id=? AND status='pending'");
        $stmt->execute([$user['id'], $id, $orgId]);
        setFlash('success', 'Loan approved successfully.');
        logActivity('update', 'sacco', "Approved loan #$id");
        redirect('loans.php');
    }

    if ($action === 'disburse') {
        $id = (int)($_POST['id'] ?? 0);

        // Fetch loan details before disbursing
        $lRow = $pdo->prepare("SELECT * FROM sacco_loans WHERE id=? AND org_id=? AND status='approved' LIMIT 1");
        $lRow->execute([$id, $orgId]);
        $loanData = $lRow->fetch();

        if (!$loanData) {
            setFlash('danger', 'Loan not found or already disbursed.');
            redirect('loans.php');
        }

        $disburseDate = date('Y-m-d');
        $pdo->beginTransaction();
        try {
            // Mark loan active
            $pdo->prepare("UPDATE sacco_loans SET status='active', disbursed_at=? WHERE id=? AND org_id=?")
                ->execute([$disburseDate, $id, $orgId]);

            // Generate amortization schedule
            $amt        = (float)$loanData['amount'];
            $rate       = (float)$loanData['interest_rate'];
            $term       = (int)$loanData['term_months'];
            $totalInt   = $amt * ($rate / 100) * ($term / 12);
            $totalRepay = $amt + $totalInt;
            $installAmt = round($totalRepay / $term, 2);
            $intPerInst = round($totalInt / $term, 2);
            $prinPerInst = round($amt / $term, 2);

            // Delete any existing schedule rows (idempotent)
            $pdo->prepare("DELETE FROM sacco_loan_schedule WHERE loan_id=?")->execute([$id]);

            $ins = $pdo->prepare(
                "INSERT INTO sacco_loan_schedule (org_id,loan_id,installment_no,due_date,amount_due,principal,interest,status)
                 VALUES (?,?,?,?,?,?,?,'pending')"
            );
            $firstDueDate = null;
            for ($i = 1; $i <= $term; $i++) {
                $due = date('Y-m-d', strtotime($disburseDate . " +$i months"));
                if ($i === 1) $firstDueDate = $due;
                // Last installment absorbs rounding
                $instAmt = ($i === $term)
                    ? round($totalRepay - ($installAmt * ($term - 1)), 2)
                    : $installAmt;
                $ins->execute([$orgId, $id, $i, $due, $instAmt, $prinPerInst, $intPerInst]);
            }

            // Set next_repayment_date on the loan
            if ($firstDueDate) {
                $pdo->prepare("UPDATE sacco_loans SET next_repayment_date=? WHERE id=?")
                    ->execute([$firstDueDate, $id]);
            }

            $pdo->commit();
            setFlash('success', 'Loan disbursed. Amortization schedule of ' . $term . ' installments generated.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            setFlash('danger', 'Disbursement failed: ' . $e->getMessage());
        }

        logActivity('update', 'sacco', "Disbursed loan #$id");
        redirect('loans.php');
    }

    if ($action === 'reject') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM sacco_loans WHERE id=? AND org_id=? AND status='pending'");
        $stmt->execute([$id, $orgId]);
        setFlash('success', 'Loan request rejected and removed.');
        logActivity('delete', 'sacco', "Rejected/Deleted loan request #$id");
        redirect('loans.php');
    }

    // ── Regenerate schedule for legacy loans ──────────────────────
    if ($action === 'regenerate_schedule') {
        $id   = (int)($_POST['id'] ?? 0);
        $lRow = $pdo->prepare("SELECT * FROM sacco_loans WHERE id=? AND org_id=? AND status='active' LIMIT 1");
        $lRow->execute([$id, $orgId]);
        $loanData = $lRow->fetch();

        if (!$loanData) {
            setFlash('danger', 'Active loan not found.'); redirect('schedule.php');
        }

        $disburseDate = $loanData['disbursed_at'] ?? date('Y-m-d');
        $amt        = (float)$loanData['amount'];
        $rate       = (float)$loanData['interest_rate'];
        $term       = (int)$loanData['term_months'];
        $totalInt   = $amt * ($rate / 100) * ($term / 12);
        $totalRepay = $amt + $totalInt;
        $installAmt = round($totalRepay / $term, 2);
        $intPerInst  = round($totalInt / $term, 2);
        $prinPerInst = round($amt / $term, 2);

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM sacco_loan_schedule WHERE loan_id=?")->execute([$id]);
            $ins = $pdo->prepare(
                "INSERT INTO sacco_loan_schedule (org_id,loan_id,installment_no,due_date,amount_due,principal,interest,status)
                 VALUES (?,?,?,?,?,?,?,'pending')"
            );
            $firstDue = null;
            $paidSoFar = (float)$loanData['total_paid'];
            for ($i = 1; $i <= $term; $i++) {
                $due  = date('Y-m-d', strtotime($disburseDate . " +$i months"));
                if ($i === 1) $firstDue = $due;
                $instAmt = ($i === $term) ? round($totalRepay - ($installAmt * ($term - 1)), 2) : $installAmt;
                // Mark past installments paid if total_paid covers them
                $cumulative = $instAmt * $i;
                $status = ($paidSoFar >= $cumulative && $due <= date('Y-m-d')) ? 'paid' : (($due < date('Y-m-d')) ? 'overdue' : 'pending');
                $ins->execute([$orgId, $id, $i, $due, $instAmt, $prinPerInst, $intPerInst]);
            }
            if ($firstDue) {
                $pdo->prepare("UPDATE sacco_loans SET next_repayment_date=? WHERE id=?")->execute([$firstDue, $id]);
            }
            $pdo->commit();
            setFlash('success', 'Amortization schedule regenerated for loan #' . $loanData['loan_no'] . '.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            setFlash('danger', 'Schedule regeneration failed: ' . $e->getMessage());
        }
        redirect('schedule.php?loan_id=' . $id);
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Get members for selector
$membersList = [];
try {
    $stmt = $pdo->prepare("SELECT id, member_no, first_name, last_name FROM sacco_members WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]);
    $membersList = $stmt->fetchAll();
} catch (Exception $e) {}

// Loans filtering
$filterStatus = sanitize($_GET['status'] ?? '');
$where = "l.org_id = ?";
$params = [$orgId];

if ($filterStatus !== '') {
    $where .= " AND l.status = ?";
    $params[] = $filterStatus;
}

$loans = [];
try {
    $stmt = $pdo->prepare("
        SELECT l.*, m.first_name, m.last_name, m.member_no, u.name as approved_by_name
        FROM sacco_loans l
        JOIN sacco_members m ON l.member_id = m.id
        LEFT JOIN users u ON l.approved_by = u.id
        WHERE $where
        ORDER BY l.created_at DESC
    ");
    $stmt->execute($params);
    $loans = $stmt->fetchAll();
} catch (Exception $e) {}

// Stat widgets metrics
$totalDisbursed   = 0;
$activeLoansCount = 0;
$portfolioBalance = 0;
$pendingApprovals = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sacco_loans WHERE org_id=? AND status IN ('active','completed')");
    $stmt->execute([$orgId]);
    $totalDisbursed = (float)$stmt->fetchColumn();

    $activeLoansCount = countRows('sacco_loans', "org_id = ? AND status = 'active'", [$orgId]);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM sacco_loans WHERE org_id=? AND status = 'active'");
    $stmt->execute([$orgId]);
    $portfolioBalance = (float)$stmt->fetchColumn();

    $pendingApprovals = countRows('sacco_loans', "org_id = ? AND status = 'pending'", [$orgId]);
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-hand-holding-usd me-2" style="color:<?= $moduleColor ?>"></i>Sacco Loans</h4>
    <p class="text-muted mb-0">Disburse credits, manage approval pipelines, and track loan portfolio repayments</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#loanRequestModal">
    <i class="fas fa-plus-circle me-1"></i>Request New Loan
  </button>
</div>

<!-- Stat widgets -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon purple-bg" style="background:rgba(142,68,173,0.15);color:#8e44ad"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalDisbursed) ?></div><div class="stat-label">Total Disbursed</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-handshake"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeLoansCount ?></div><div class="stat-label">Active Loans</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($portfolioBalance) ?></div><div class="stat-label">Outstanding Portfolio</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingApprovals ?></div><div class="stat-label">Pending Approvals</div></div>
    </div>
  </div>
</div>

<!-- Filter card -->
<div class="card mb-4 border-0 shadow-sm">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Filter Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Loans</option>
          <option value="pending" <?= $filterStatus==='pending'?'selected':'' ?>>Pending</option>
          <option value="approved" <?= $filterStatus==='approved'?'selected':'' ?>>Approved</option>
          <option value="active" <?= $filterStatus==='active'?'selected':'' ?>>Active</option>
          <option value="completed" <?= $filterStatus==='completed'?'selected':'' ?>>Completed</option>
          <option value="defaulted" <?= $filterStatus==='defaulted'?'selected':'' ?>>Defaulted</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="loans.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Loans Table Card -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="loansTable">
        <thead class="table-light">
          <tr>
            <th>Loan No</th>
            <th>Member</th>
            <th class="text-end">Principal</th>
            <th class="text-end">Monthly EMI</th>
            <th class="text-end">Total Repayable</th>
            <th class="text-end">Outstanding Bal</th>
            <th>Term</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($loans)): ?>
          <tr>
            <td colspan="9" class="text-center py-5 text-muted">
              <i class="fas fa-hand-holding-usd fa-3x mb-3 d-block"></i>No loans registered.
            </td>
          </tr>
          <?php else: foreach ($loans as $l): ?>
          <tr>
            <td class="fw-bold text-dark"><?= e($l['loan_no']) ?></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($l['first_name'] . ' ' . $l['last_name']) ?></div>
              <div class="small text-muted"><?= e($l['member_no']) ?></div>
            </td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$l['amount']) ?></td>
            <td class="text-end fw-semibold text-primary"><?= formatCurrency((float)$l['monthly_payment']) ?></td>
            <td class="text-end text-muted"><?= formatCurrency((float)($l['monthly_payment'] * $l['term_months'])) ?></td>
            <td class="text-end fw-bold text-danger"><?= formatCurrency((float)$l['balance']) ?></td>
            <td><?= $l['term_months'] ?> months</td>
            <td><?= statusBadge($l['status']) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <?php if ($l['status'] === 'pending'): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="id" value="<?= $l['id'] ?>">
                <button type="submit" class="btn btn-sm btn-success" title="Approve Loan">
                  <i class="fas fa-check"></i> Approve
                </button>
              </form>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Are you sure you want to reject this request?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" value="<?= $l['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Reject Loan">
                  <i class="fas fa-times"></i>
                </button>
              </form>
              <?php elseif ($l['status'] === 'approved'): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="disburse">
                <input type="hidden" name="id" value="<?= $l['id'] ?>">
                <button type="submit" class="btn btn-sm btn-primary" title="Disburse Cash">
                  <i class="fas fa-hand-holding-usd"></i> Disburse
                </button>
              </form>
              <?php else: ?>
              <button class="btn btn-sm btn-outline-secondary" onclick="viewLoanDetails(<?= e(json_encode($l)) ?>)" title="View Details">
                <i class="fas fa-eye"></i> View
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Request Loan Modal -->
<div class="modal fade" id="loanRequestModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="loanForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="request">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i>Request New Sacco Loan</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Select Member <span class="text-danger">*</span></label>
              <select name="member_id" class="form-select" required>
                <option value="">-- Choose Member --</option>
                <?php foreach ($membersList as $m): ?>
                <option value="<?= $m['id'] ?>"><?= e($m['first_name'].' '.$m['last_name'].' ('.$m['member_no'].')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Loan Amount (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="amount" id="loanAmount" class="form-control" required min="100" value="10000" oninput="calculateEMI()">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Annual Interest Rate (%) <span class="text-danger">*</span></label>
              <input type="number" step="0.1" name="interest_rate" id="loanInterest" class="form-control" required min="0" value="12.0" oninput="calculateEMI()">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Term Duration (Months) <span class="text-danger">*</span></label>
              <input type="number" name="term_months" id="loanTerm" class="form-control" required min="1" value="12" oninput="calculateEMI()">
            </div>

            <!-- Interactive EMI Preview Box -->
            <div class="col-12">
              <div class="p-3 bg-light rounded border border-secondary" style="border-style:dashed !important">
                <h6 class="fw-bold mb-3 text-dark"><i class="fas fa-calculator me-1 text-primary"></i>Repayment Calculator Preview</h6>
                <div class="row text-center">
                  <div class="col-4">
                    <div class="small text-muted">Total Interest</div>
                    <h5 class="fw-bold text-dark mb-0" id="previewInterest">KES 1,200.00</h5>
                  </div>
                  <div class="col-4">
                    <div class="small text-muted">Total Repayable</div>
                    <h5 class="fw-bold text-dark mb-0" id="previewTotal">KES 11,200.00</h5>
                  </div>
                  <div class="col-4 border-start">
                    <div class="small text-muted text-primary fw-bold">Monthly Payment (EMI)</div>
                    <h4 class="fw-bold text-primary mb-0" id="previewEMI">KES 933.33</h4>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Guarantor Full Name</label>
              <input type="text" name="guarantor_name" class="form-control" placeholder="e.g. Richard Hendricks">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Guarantor Phone Number</label>
              <input type="text" name="guarantor_phone" class="form-control" placeholder="e.g. 0722000000">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Purpose of the Loan</label>
              <textarea name="purpose" class="form-control" rows="2" placeholder="Brief details about the loan utilization..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Submit Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View Loan Details Modal -->
<div class="modal fade" id="loanDetailsModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-file-contract me-2"></i>Loan Agreement Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <table class="table table-striped table-hover mb-0">
          <tr>
            <td class="fw-bold ps-3" style="width:40%">Loan Number</td>
            <td id="detLoanNo" class="pe-3"></td>
          </tr>
          <tr>
            <td class="fw-bold ps-3">Member Name</td>
            <td id="detMember" class="pe-3"></td>
          </tr>
          <tr>
            <td class="fw-bold ps-3">Principal Disbursed</td>
            <td id="detPrincipal" class="pe-3"></td>
          </tr>
          <tr>
            <td class="fw-bold ps-3">Interest Rate</td>
            <td id="detRate" class="pe-3"></td>
          </tr>
          <tr>
            <td class="fw-bold ps-3">Repayment Term</td>
            <td id="detTerm" class="pe-3"></td>
          </tr>
          <tr>
            <td class="fw-bold ps-3">Monthly EMI Payment</td>
            <td id="detEMI" class="pe-3 text-primary fw-bold"></td>
          </tr>
          <tr>
            <td class="fw-bold ps-3">Total Paid Till Date</td>
            <td id="detPaid" class="pe-3 text-success fw-bold"></td>
          </tr>
          <tr>
            <td class="fw-bold ps-3">Remaining Balance</td>
            <td id="detBalance" class="pe-3 text-danger fw-bold"></td>
          </tr>
          <tr>
            <td class="fw-bold ps-3">Guarantor Name</td>
            <td id="detGuarName" class="pe-3"></td>
          </tr>
          <tr>
            <td class="fw-bold ps-3">Guarantor Phone</td>
            <td id="detGuarPhone" class="pe-3"></td>
          </tr>
          <tr>
            <td class="fw-bold ps-3">Disbursement Date</td>
            <td id="detDisbursed" class="pe-3"></td>
          </tr>
          <tr>
            <td class="fw-bold ps-3">Purpose</td>
            <td id="detPurpose" class="pe-3 text-muted small"></td>
          </tr>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
  $("#loansTable").DataTable({pageLength:10,order:[[0,"desc"]]});
  calculateEMI();
});

function calculateEMI() {
  var principal = parseFloat($("#loanAmount").val() || 0);
  var rate = parseFloat($("#loanInterest").val() || 0);
  var months = parseInt($("#loanTerm").val() || 1);

  if (principal > 0 && months > 0) {
    var interest = principal * (rate / 100) * (months / 12);
    var total = principal + interest;
    var emi = total / months;

    $("#previewInterest").text("KES " + interest.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,"));
    $("#previewTotal").text("KES " + total.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,"));
    $("#previewEMI").text("KES " + emi.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,"));
  }
}

function viewLoanDetails(l) {
  $("#detLoanNo").text(l.loan_no);
  $("#detMember").text(l.first_name + " " + l.last_name + " (" + l.member_no + ")");
  $("#detPrincipal").text("KES " + parseFloat(l.amount).toLocaleString(undefined, {minimumFractionDigits: 2}));
  $("#detRate").text(l.interest_rate + "% p.a.");
  $("#detTerm").text(l.term_months + " Months");
  $("#detEMI").text("KES " + parseFloat(l.monthly_payment).toLocaleString(undefined, {minimumFractionDigits: 2}));
  $("#detPaid").text("KES " + parseFloat(l.total_paid || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
  $("#detBalance").text("KES " + parseFloat(l.balance).toLocaleString(undefined, {minimumFractionDigits: 2}));
  $("#detGuarName").text(l.guarantor_name || "—");
  $("#detGuarPhone").text(l.guarantor_phone || "—");
  $("#detDisbursed").text(l.disbursed_at || "—");
  $("#detPurpose").text(l.purpose || "—");

  new bootstrap.Modal(document.getElementById("loanDetailsModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
