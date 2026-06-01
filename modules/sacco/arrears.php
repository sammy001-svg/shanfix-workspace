<?php
// ── SACCO: Loan Arrears Tracker ─────────────────────────────────
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

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Mark overdue rows in schedule ───────────────────────────────
try {
    $pdo->prepare(
        "UPDATE sacco_loan_schedule s
         JOIN sacco_loans l ON s.loan_id = l.id
         SET s.status = 'overdue'
         WHERE l.org_id = ? AND s.status = 'pending' AND s.due_date < CURDATE()"
    )->execute([$orgId]);
} catch (Throwable $e) {}

// ── KPI counts ───────────────────────────────────────────────────
$totalOverdue = $totalArrears = $loansInArrears = 0;
try {
    $s = $pdo->prepare(
        "SELECT COUNT(DISTINCT s.loan_id) AS loans_count,
                COUNT(*) AS installments,
                COALESCE(SUM(s.amount_due - s.paid_amount),0) AS arrears_amt
         FROM sacco_loan_schedule s
         JOIN sacco_loans l ON s.loan_id = l.id
         WHERE l.org_id = ? AND s.status = 'overdue'"
    );
    $s->execute([$orgId]);
    $kpis = $s->fetch();
    $loansInArrears = (int)$kpis['loans_count'];
    $totalOverdue   = (int)$kpis['installments'];
    $totalArrears   = (float)$kpis['arrears_amt'];
} catch (Throwable $e) {}

// Fall back: also check loans with no schedule but past next_repayment_date
$legacyOverdue = 0;
try {
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM sacco_loans
         WHERE org_id=? AND status='active'
           AND next_repayment_date < CURDATE()
           AND id NOT IN (SELECT DISTINCT loan_id FROM sacco_loan_schedule WHERE status='overdue')"
    );
    $s->execute([$orgId]);
    $legacyOverdue = (int)$s->fetchColumn();
} catch (Throwable $e) {}

// ── Arrears detail rows (schedule-based) ────────────────────────
$rows = [];
try {
    $s = $pdo->prepare(
        "SELECT l.id AS loan_id, l.loan_no, l.balance AS outstanding,
                CONCAT(m.first_name,' ',m.last_name) AS member_name,
                m.member_no, m.phone AS member_phone, m.email AS member_email,
                COUNT(s.id) AS overdue_count,
                SUM(s.amount_due - s.paid_amount) AS arrears_amt,
                MIN(s.due_date) AS earliest_due
         FROM sacco_loans l
         JOIN sacco_members m ON l.member_id = m.id
         JOIN sacco_loan_schedule s ON s.loan_id = l.id
         WHERE l.org_id = ? AND s.status = 'overdue'
         GROUP BY l.id
         ORDER BY earliest_due ASC"
    );
    $s->execute([$orgId]);
    $rows = $s->fetchAll();
} catch (Throwable $e) {}

// ── Legacy arrears (loans without schedule) ──────────────────────
$legacyRows = [];
try {
    $s = $pdo->prepare(
        "SELECT l.id AS loan_id, l.loan_no, l.balance AS outstanding,
                l.next_repayment_date, l.monthly_payment,
                CONCAT(m.first_name,' ',m.last_name) AS member_name,
                m.member_no, m.phone AS member_phone, m.email AS member_email
         FROM sacco_loans l
         JOIN sacco_members m ON l.member_id = m.id
         WHERE l.org_id=? AND l.status='active'
           AND l.next_repayment_date < CURDATE()
           AND l.id NOT IN (SELECT DISTINCT loan_id FROM sacco_loan_schedule WHERE status='overdue')
         ORDER BY l.next_repayment_date ASC"
    );
    $s->execute([$orgId]);
    $legacyRows = $s->fetchAll();
} catch (Throwable $e) {}
?>

<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1">
      <i class="fas fa-exclamation-triangle me-2" style="color:#e74c3c"></i>Loan Arrears Tracker
    </h4>
    <p class="text-muted mb-0">Overdue installments and delinquent loan portfolio</p>
  </div>
  <div class="d-flex gap-2">
    <a href="penalties.php" class="btn btn-sm btn-outline-danger">
      <i class="fas fa-gavel me-1"></i>Manage Penalties
    </a>
    <a href="reports.php" class="btn btn-sm btn-outline-secondary">
      <i class="fas fa-chart-bar me-1"></i>Reports
    </a>
  </div>
</div>

<!-- KPI row -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="stat-body">
        <div class="stat-value text-danger"><?= formatCurrency($totalArrears) ?></div>
        <div class="stat-label">Total Arrears Amount</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hand-holding-usd"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $loansInArrears + $legacyOverdue ?></div>
        <div class="stat-label">Loans in Arrears</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-calendar-times"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalOverdue ?></div>
        <div class="stat-label">Overdue Installments</div>
      </div>
    </div>
  </div>
</div>

<?php if (empty($rows) && empty($legacyRows)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-check-circle fa-3x mb-3 d-block text-success opacity-50"></i>
    <h5 class="text-success">No Arrears</h5>
    <p class="small">All active loans are current — no overdue installments found.</p>
  </div>
</div>

<?php else: ?>

<!-- Schedule-based arrears -->
<?php if (!empty($rows)): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold text-danger">
      <i class="fas fa-exclamation-circle me-2"></i>Overdue Installments (<?= count($rows) ?> loans)
    </h6>
    <button class="btn btn-xs btn-outline-secondary" onclick="window.print()">
      <i class="fas fa-print me-1"></i>Print
    </button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Loan #</th>
            <th>Member</th>
            <th>Phone</th>
            <th class="text-center">Overdue Installments</th>
            <th class="text-end">Arrears Amount</th>
            <th class="text-end">Outstanding Balance</th>
            <th>Oldest Due Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $daysOverdue = (int)floor((time() - strtotime($r['earliest_due'])) / 86400);
          $severity    = $daysOverdue > 90 ? 'danger' : ($daysOverdue > 30 ? 'warning' : 'secondary');
        ?>
        <tr>
          <td>
            <a href="schedule.php?loan_id=<?= $r['loan_id'] ?>" class="fw-semibold text-decoration-none" style="color:<?= $moduleColor ?>">
              <?= e($r['loan_no']) ?>
            </a>
          </td>
          <td>
            <div class="fw-semibold small"><?= e($r['member_name']) ?></div>
            <div class="text-muted" style="font-size:.7rem"><?= e($r['member_no']) ?></div>
          </td>
          <td class="small"><?= e($r['member_phone'] ?? '—') ?></td>
          <td class="text-center">
            <span class="badge bg-danger"><?= $r['overdue_count'] ?></span>
          </td>
          <td class="text-end fw-bold text-danger"><?= formatCurrency($r['arrears_amt']) ?></td>
          <td class="text-end small"><?= formatCurrency($r['outstanding']) ?></td>
          <td>
            <div class="small"><?= date('d M Y', strtotime($r['earliest_due'])) ?></div>
            <span class="badge bg-<?= $severity ?> bg-opacity-25 text-<?= $severity ?>" style="font-size:.65rem">
              <?= $daysOverdue ?>d overdue
            </span>
          </td>
          <td>
            <a href="schedule.php?loan_id=<?= $r['loan_id'] ?>" class="btn btn-xs btn-outline-primary me-1">
              <i class="fas fa-calendar-alt"></i>
            </a>
            <a href="repayments.php?loan_id=<?= $r['loan_id'] ?>" class="btn btn-xs btn-outline-success">
              <i class="fas fa-undo"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Legacy arrears (loans without schedule table) -->
<?php if (!empty($legacyRows)): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header">
    <h6 class="mb-0 fw-bold text-warning">
      <i class="fas fa-history me-2"></i>Legacy Overdue Loans (no schedule generated)
    </h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr><th>Loan #</th><th>Member</th><th>Phone</th><th class="text-end">Outstanding</th><th>Next Due Was</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($legacyRows as $r):
          $daysLate = (int)floor((time() - strtotime($r['next_repayment_date'])) / 86400);
        ?>
        <tr>
          <td class="fw-semibold small"><?= e($r['loan_no']) ?></td>
          <td>
            <div class="small fw-semibold"><?= e($r['member_name']) ?></div>
            <div class="text-muted" style="font-size:.7rem"><?= e($r['member_no']) ?></div>
          </td>
          <td class="small"><?= e($r['member_phone'] ?? '—') ?></td>
          <td class="text-end fw-bold text-danger small"><?= formatCurrency($r['outstanding']) ?></td>
          <td>
            <div class="small"><?= date('d M Y', strtotime($r['next_repayment_date'])) ?></div>
            <span class="badge bg-warning text-dark" style="font-size:.65rem"><?= $daysLate ?>d overdue</span>
          </td>
          <td>
            <a href="schedule.php?loan_id=<?= $r['loan_id'] ?>" class="btn btn-xs btn-outline-info me-1" title="Generate schedule">
              <i class="fas fa-calendar-plus"></i>
            </a>
            <a href="repayments.php?loan_id=<?= $r['loan_id'] ?>" class="btn btn-xs btn-outline-success">
              <i class="fas fa-undo"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
