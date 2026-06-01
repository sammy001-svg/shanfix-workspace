<?php
// ── SACCO: Loan Amortization Schedule ──────────────────────────
$moduleSlug  = 'sacco';
$moduleName  = 'SACCO Management';
$moduleIcon  = 'fas fa-piggy-bank';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',   'label' => 'Dashboard'],
    ['url' => 'members.php',      'icon' => 'fas fa-users',            'label' => 'Members'],
    ['url' => 'savings.php',      'icon' => 'fas fa-piggy-bank',       'label' => 'Savings'],
    ['url' => 'loans.php',        'icon' => 'fas fa-hand-holding-usd', 'label' => 'Loans'],
    ['url' => 'schedule.php',     'icon' => 'fas fa-calendar-alt',     'label' => 'Schedules'],
    ['url' => 'arrears.php',      'icon' => 'fas fa-exclamation-triangle','label' => 'Arrears'],
    ['url' => 'shares.php',       'icon' => 'fas fa-certificate',      'label' => 'Shares'],
    ['url' => 'repayments.php',   'icon' => 'fas fa-undo',             'label' => 'Repayments'],
    ['url' => 'dividends.php',    'icon' => 'fas fa-percentage',       'label' => 'Dividends'],
    ['url' => 'statements.php',   'icon' => 'fas fa-file-invoice',     'label' => 'Statements'],
    ['url' => 'guarantors.php',   'icon' => 'fas fa-user-shield',      'label' => 'Guarantors'],
    ['url' => 'penalties.php',    'icon' => 'fas fa-exclamation-circle','label' => 'Penalties'],
    ['url' => 'communications.php','icon'=> 'fas fa-envelope',          'label' => 'Communications'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',        'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$loanId = (int)($_GET['loan_id'] ?? 0);

// ── Active loans list for selector ──────────────────────────────
$activeLoans = [];
try {
    $s = $pdo->prepare(
        "SELECT l.id, l.loan_no, l.amount, l.balance, l.term_months, l.monthly_payment,
                CONCAT(m.first_name,' ',m.last_name) AS member_name, m.member_no
         FROM sacco_loans l
         JOIN sacco_members m ON l.member_id = m.id
         WHERE l.org_id=? AND l.status IN ('active','completed')
         ORDER BY l.disbursed_at DESC, l.id DESC"
    );
    $s->execute([$orgId]);
    $activeLoans = $s->fetchAll();
} catch (Throwable $e) {}

// ── Selected loan details ────────────────────────────────────────
$loan = null;
$schedule = [];
$paidTotal = $dueTotal = $overdueTotal = 0;

if ($loanId) {
    try {
        $s = $pdo->prepare(
            "SELECT l.*, CONCAT(m.first_name,' ',m.last_name) AS member_name, m.member_no, m.phone AS member_phone
             FROM sacco_loans l JOIN sacco_members m ON l.member_id=m.id
             WHERE l.id=? AND l.org_id=? LIMIT 1"
        );
        $s->execute([$loanId, $orgId]);
        $loan = $s->fetch() ?: null;
    } catch (Throwable $e) {}

    if ($loan) {
        try {
            // Mark overdue rows on the fly
            $pdo->prepare(
                "UPDATE sacco_loan_schedule SET status='overdue'
                 WHERE loan_id=? AND status='pending' AND due_date < CURDATE()"
            )->execute([$loanId]);

            $s = $pdo->prepare(
                "SELECT * FROM sacco_loan_schedule WHERE loan_id=? ORDER BY installment_no ASC"
            );
            $s->execute([$loanId]);
            $schedule = $s->fetchAll();

            foreach ($schedule as $row) {
                if ($row['status'] === 'paid')    $paidTotal   += $row['amount_due'];
                if ($row['status'] === 'overdue') $overdueTotal += $row['amount_due'];
                if (in_array($row['status'], ['pending','partial','overdue'])) $dueTotal += $row['amount_due'];
            }
        } catch (Throwable $e) {}
    }
}

$statusColors = [
    'paid'    => ['bg'=>'#d4edda','txt'=>'#155724','label'=>'Paid'],
    'partial' => ['bg'=>'#fff3cd','txt'=>'#856404','label'=>'Partial'],
    'overdue' => ['bg'=>'#f8d7da','txt'=>'#721c24','label'=>'Overdue'],
    'pending' => ['bg'=>'#f8f9fa','txt'=>'#6c757d','label'=>'Pending'],
];
?>

<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-alt me-2" style="color:<?= $moduleColor ?>"></i>Amortization Schedules</h4>
    <p class="text-muted mb-0">View full repayment schedule for any active loan</p>
  </div>
  <a href="loans.php" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-arrow-left me-1"></i>Back to Loans
  </a>
</div>

<!-- Loan selector -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
      <label class="fw-semibold small mb-0">Select Loan:</label>
      <select name="loan_id" class="form-select form-select-sm" style="max-width:380px" onchange="this.form.submit()">
        <option value="">— Choose a loan —</option>
        <?php foreach ($activeLoans as $l): ?>
        <option value="<?= $l['id'] ?>" <?= $loanId === (int)$l['id'] ? 'selected' : '' ?>>
          <?= e($l['loan_no']) ?> — <?= e($l['member_name']) ?>
          (<?= formatCurrency($l['balance']) ?> remaining / <?= $l['term_months'] ?>m)
        </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<?php if (!$loanId): ?>
<div class="card text-center py-5 border-0 shadow-sm">
  <div class="card-body text-muted">
    <i class="fas fa-calendar-alt fa-3x mb-3 d-block opacity-25"></i>
    <h6>Select a loan above to view its repayment schedule</h6>
  </div>
</div>

<?php elseif (!$loan): ?>
<div class="alert alert-warning">Loan not found.</div>

<?php elseif (empty($schedule)): ?>
<!-- No schedule yet — loan may have been created before this feature -->
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-calendar-times fa-3x mb-3 d-block opacity-25"></i>
    <h6>No schedule generated yet</h6>
    <p class="small">This loan was disbursed before the schedule feature was added.<br>
    Re-disburse or manually create a schedule for it.</p>
    <?php if ($loan['status'] === 'active'): ?>
    <form method="POST" action="loans.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="regenerate_schedule">
      <input type="hidden" name="id" value="<?= $loanId ?>">
      <button type="submit" class="btn btn-sm btn-outline-primary mt-2">
        <i class="fas fa-sync me-1"></i>Generate Schedule Now
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>

<!-- Loan summary -->
<div class="card border-0 shadow-sm mb-4" style="border-left:4px solid <?= $moduleColor ?>!important">
  <div class="card-body">
    <div class="row g-3 align-items-center">
      <div class="col-md-4">
        <div class="fw-700" style="color:<?= $moduleColor ?>;font-size:1.05rem"><?= e($loan['loan_no']) ?></div>
        <div class="text-muted small">
          <i class="fas fa-user me-1"></i><?= e($loan['member_name']) ?>
          &nbsp;·&nbsp; <?= e($loan['member_no'] ?? '') ?>
        </div>
        <?php if ($loan['member_phone']): ?>
        <div class="text-muted small"><i class="fas fa-phone me-1"></i><?= e($loan['member_phone']) ?></div>
        <?php endif; ?>
      </div>
      <div class="col-md-8">
        <div class="row g-2 text-center">
          <?php foreach ([
            ['Principal',  formatCurrency($loan['amount']),        'fa-money-bill',   '#3498db'],
            ['Monthly EMI',formatCurrency($loan['monthly_payment']),'fa-calendar-check','#1A8A4E'],
            ['Paid',       formatCurrency($paidTotal),             'fa-check-circle', '#27ae60'],
            ['Remaining',  formatCurrency($loan['balance']),       'fa-hourglass-half','#f39c12'],
            ['Overdue',    formatCurrency($overdueTotal),          'fa-exclamation',  '#e74c3c'],
          ] as [$lbl, $val, $ico, $clr]): ?>
          <div class="col">
            <div class="fw-700" style="color:<?= $clr ?>"><?= $val ?></div>
            <div class="text-muted" style="font-size:.68rem"><?= $lbl ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Schedule table -->
<div class="card border-0 shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold">
      <i class="fas fa-table me-2" style="color:<?= $moduleColor ?>"></i>
      Repayment Schedule — <?= count($schedule) ?> installments
    </h6>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
      <i class="fas fa-print me-1"></i>Print
    </button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th class="text-center">#</th>
            <th>Due Date</th>
            <th class="text-end">EMI Amount</th>
            <th class="text-end">Principal</th>
            <th class="text-end">Interest</th>
            <th class="text-end">Paid</th>
            <th class="text-center">Status</th>
            <th>Paid On</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $runningBalance = (float)$loan['amount'];
          foreach ($schedule as $row):
            $sc = $statusColors[$row['status']] ?? $statusColors['pending'];
            $isToday  = $row['due_date'] === date('Y-m-d');
            $isPast   = $row['due_date'] < date('Y-m-d') && $row['status'] !== 'paid';
          ?>
          <tr class="<?= $row['status'] === 'overdue' ? 'table-danger' : ($row['status'] === 'paid' ? 'table-success opacity-75' : '') ?>">
            <td class="text-center fw-semibold"><?= $row['installment_no'] ?></td>
            <td>
              <div class="small"><?= date('d M Y', strtotime($row['due_date'])) ?></div>
              <?php if ($isToday): ?>
              <span class="badge bg-warning text-dark" style="font-size:.6rem">Today</span>
              <?php elseif ($row['status'] === 'overdue'): ?>
              <span class="badge bg-danger" style="font-size:.6rem">
                <?= floor((time() - strtotime($row['due_date'])) / 86400) ?>d overdue
              </span>
              <?php endif; ?>
            </td>
            <td class="text-end fw-semibold small"><?= formatCurrency($row['amount_due']) ?></td>
            <td class="text-end text-muted small"><?= formatCurrency($row['principal']) ?></td>
            <td class="text-end text-muted small"><?= formatCurrency($row['interest']) ?></td>
            <td class="text-end small text-success"><?= $row['paid_amount'] > 0 ? formatCurrency($row['paid_amount']) : '—' ?></td>
            <td class="text-center">
              <span class="badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['txt'] ?>;font-size:.72rem">
                <?= $sc['label'] ?>
              </span>
            </td>
            <td class="small text-muted"><?= $row['paid_at'] ? date('d M Y', strtotime($row['paid_at'])) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-700">
          <tr>
            <td colspan="2">Totals</td>
            <td class="text-end"><?= formatCurrency(array_sum(array_column($schedule, 'amount_due'))) ?></td>
            <td class="text-end"><?= formatCurrency(array_sum(array_column($schedule, 'principal'))) ?></td>
            <td class="text-end"><?= formatCurrency(array_sum(array_column($schedule, 'interest'))) ?></td>
            <td class="text-end text-success"><?= formatCurrency($paidTotal) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
