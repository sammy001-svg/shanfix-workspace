<?php
$moduleSlug  = 'sacco';
$moduleName  = 'SACCO Management';
$moduleIcon  = 'fas fa-piggy-bank';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',    'label' => 'Dashboard'],
    ['url' => 'members.php',      'icon' => 'fas fa-users',             'label' => 'Members'],
    ['url' => 'savings.php',      'icon' => 'fas fa-piggy-bank',        'label' => 'Savings'],
    ['url' => 'loans.php',        'icon' => 'fas fa-hand-holding-usd',  'label' => 'Loans'],
    ['url' => 'shares.php',       'icon' => 'fas fa-certificate',       'label' => 'Shares'],
    ['url' => 'repayments.php',   'icon' => 'fas fa-undo',              'label' => 'Repayments'],
    ['url' => 'dividends.php',    'icon' => 'fas fa-percentage',        'label' => 'Dividends'],
    ['url' => 'statements.php',   'icon' => 'fas fa-file-invoice',      'label' => 'Statements'],
    ['url' => 'guarantors.php',   'icon' => 'fas fa-user-shield',       'label' => 'Guarantors'],
    ['url' => 'penalties.php',    'icon' => 'fas fa-exclamation-circle', 'label' => 'Penalties'],
    ['url' => 'communications.php','icon'=> 'fas fa-envelope',           'label' => 'Communications'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',         'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

// Filters
$memberId  = (int)($_GET['member_id'] ?? 0);
$dateFrom  = $_GET['date_from'] ?? date('Y-01-01');
$dateTo    = $_GET['date_to']   ?? date('Y-m-d');

$members = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, member_no FROM sacco_members WHERE org_id=? AND status='active' ORDER BY first_name");
$members->execute([$orgId]);
$members = $members->fetchAll();

$member = null;
$savingsLedger = $loansLedger = $sharesLedger = $dividendLedger = [];
$savingsBal = $loanBal = $sharesBal = $divBal = 0;

if ($memberId) {
    $stmt = $pdo->prepare("SELECT *, CONCAT(first_name,' ',m.last_name) AS full_name FROM sacco_members m WHERE id=? AND org_id=?");
    $stmt->execute([$memberId, $orgId]);
    $member = $stmt->fetch();

    // Savings ledger
    try {
        $stmt = $pdo->prepare("SELECT transaction_date AS txn_date, transaction_type, amount, balance_after, notes, reference
                               FROM sacco_savings WHERE member_id=? AND org_id=? AND transaction_date BETWEEN ? AND ?
                               ORDER BY transaction_date, id");
        $stmt->execute([$memberId, $orgId, $dateFrom, $dateTo]);
        $savingsLedger = $stmt->fetchAll();
        $savingsBal = $member['total_savings'] ?? 0;
    } catch (Exception $e) {}

    // Shares ledger
    try {
        $stmt = $pdo->prepare("SELECT transaction_date AS txn_date, transaction_type, shares, total_amount AS amount, balance_shares, certificate_no, notes
                               FROM sacco_shares WHERE member_id=? AND org_id=? AND transaction_date BETWEEN ? AND ?
                               ORDER BY transaction_date, id");
        $stmt->execute([$memberId, $orgId, $dateFrom, $dateTo]);
        $sharesLedger = $stmt->fetchAll();
        $sharesBal = $member['total_shares'] ?? 0;
    } catch (Exception $e) {}

    // Loans ledger
    try {
        $stmt = $pdo->prepare("SELECT disbursed_date AS txn_date, 'disbursement' AS transaction_type, amount, outstanding_balance AS balance_after, purpose AS notes, loan_no AS reference
                               FROM sacco_loans WHERE member_id=? AND org_id=? AND status IN ('active','completed') AND disbursed_date BETWEEN ? AND ?
                               ORDER BY disbursed_date");
        $stmt->execute([$memberId, $orgId, $dateFrom, $dateTo]);
        $loansLedger = $stmt->fetchAll();

        $rp = $pdo->prepare("SELECT r.payment_date AS txn_date, 'repayment' AS transaction_type, r.amount, r.balance_after, r.notes, l.loan_no AS reference
                              FROM sacco_repayments r JOIN sacco_loans l ON r.loan_id=l.id
                              WHERE r.member_id=? AND r.org_id=? AND r.payment_date BETWEEN ? AND ?
                              ORDER BY r.payment_date");
        $rp->execute([$memberId, $orgId, $dateFrom, $dateTo]);
        $loansLedger = array_merge($loansLedger, $rp->fetchAll());
        usort($loansLedger, fn($a,$b) => strcmp($a['txn_date'], $b['txn_date']));

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(outstanding_balance),0) FROM sacco_loans WHERE member_id=? AND org_id=? AND status='active'");
        $stmt->execute([$memberId, $orgId]);
        $loanBal = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}

    // Dividends
    try {
        $stmt = $pdo->prepare("SELECT d.period_label, d.declared_at AS txn_date, p.total_payout AS amount, p.dividend_amount, p.interest_amount, p.status
                               FROM sacco_dividend_payouts p JOIN sacco_dividends d ON p.dividend_id=d.id
                               WHERE p.member_id=? AND d.org_id=? AND d.declared_at BETWEEN ? AND ?
                               ORDER BY d.declared_at");
        $stmt->execute([$memberId, $orgId, $dateFrom, $dateTo]);
        $dividendLedger = $stmt->fetchAll();
        $divBal = array_sum(array_column($dividendLedger, 'amount'));
    } catch (Exception $e) {}
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Member Statements</h4>
    <p class="text-muted mb-0">Full account statement for any member across savings, shares, loans, and dividends</p>
  </div>
  <?php if ($member): ?>
  <button class="btn btn-outline-secondary" onclick="window.print()">
    <i class="fas fa-print me-2"></i>Print Statement
  </button>
  <?php endif; ?>
</div>

<!-- Filter Bar -->
<div class="card mb-4 no-print">
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label fw-semibold">Member</label>
        <select name="member_id" class="form-select">
          <option value="">— Select member —</option>
          <?php foreach ($members as $m): ?>
          <option value="<?= $m['id'] ?>" <?= $memberId === (int)$m['id'] ? 'selected' : '' ?>>
            <?= e($m['name']) ?> (<?= e($m['member_no']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">From</label>
        <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">To</label>
        <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn w-100 text-white" style="background:<?= $moduleColor ?>">
          <i class="fas fa-search me-1"></i>Generate
        </button>
      </div>
    </form>
  </div>
</div>

<?php if (!$memberId): ?>
<div class="card"><div class="card-body text-center text-muted py-5">
  <i class="fas fa-user-circle fa-3x mb-3 d-block opacity-25"></i>
  Select a member above to generate their account statement.
</div></div>
<?php elseif (!$member): ?>
<div class="alert alert-danger">Member not found.</div>
<?php else: ?>

<!-- Statement Header -->
<div class="card mb-4">
  <div class="card-body">
    <div class="row align-items-center">
      <div class="col-md-8">
        <h5 class="fw-bold mb-1" style="color:<?= $moduleColor ?>"><?= e($member['full_name']) ?></h5>
        <div class="text-muted small">
          Member No: <strong><?= e($member['member_no']) ?></strong> &nbsp;|&nbsp;
          Period: <strong><?= formatDate($dateFrom) ?> — <?= formatDate($dateTo) ?></strong>
        </div>
      </div>
      <div class="col-md-4 text-md-end mt-2 mt-md-0">
        <div class="small text-muted">Generated: <?= date('d M Y, H:i') ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <?php foreach ([
    ['label'=>'Savings Balance',  'val'=>formatCurrency($savingsBal), 'icon'=>'fas fa-piggy-bank',        'col'=>'success'],
    ['label'=>'Total Shares',     'val'=>number_format($sharesBal).' shares',  'icon'=>'fas fa-certificate', 'col'=>'primary'],
    ['label'=>'Outstanding Loans','val'=>formatCurrency($loanBal),   'icon'=>'fas fa-hand-holding-usd',  'col'=>'danger'],
    ['label'=>'Dividends Earned', 'val'=>formatCurrency($divBal),    'icon'=>'fas fa-percentage',         'col'=>'warning'],
  ] as $s): ?>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-<?= $s['col'] ?> border-opacity-25 h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="text-<?= $s['col'] ?> fs-4"><i class="<?= $s['icon'] ?>"></i></div>
        <div>
          <div class="fw-bold"><?= $s['val'] ?></div>
          <div class="small text-muted"><?= $s['label'] ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php
// Helper to render ledger table
function renderLedger(string $title, string $icon, array $rows, array $cols, string $color): void { ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="<?= $icon ?>" style="color:<?= $color ?>"></i>
    <h6 class="mb-0 fw-semibold"><?= $title ?></h6>
    <span class="badge ms-auto" style="background:<?= $color ?>"><?= count($rows) ?> entries</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light">
          <tr><?php foreach ($cols as $c): ?><th class="<?= $c['class'] ?? '' ?>"><?= $c['label'] ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
          <tr><td colspan="<?= count($cols) ?>" class="text-center text-muted py-3">No transactions in this period.</td></tr>
          <?php else: foreach ($rows as $r): echo '<tr>'; foreach ($cols as $c) { echo '<td class="' . ($c['class'] ?? '') . ' small">' . ($c['render']($r)) . '</td>'; } echo '</tr>'; endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php } ?>

<?php
renderLedger('Savings Ledger', 'fas fa-piggy-bank', $savingsLedger, [
    ['label'=>'Date',         'render'=>fn($r)=>formatDate($r['txn_date'])],
    ['label'=>'Type',         'render'=>fn($r)=>'<span class="badge bg-'.($r['transaction_type']==='deposit'?'success':'danger').'">'.ucfirst($r['transaction_type']).'</span>'],
    ['label'=>'Reference',    'render'=>fn($r)=>e($r['reference']??'-')],
    ['label'=>'Notes',        'render'=>fn($r)=>e($r['notes']??'-')],
    ['label'=>'Amount',       'class'=>'text-end', 'render'=>fn($r)=>formatCurrency($r['amount'])],
    ['label'=>'Balance',      'class'=>'text-end fw-semibold', 'render'=>fn($r)=>formatCurrency($r['balance_after'])],
], $moduleColor);

renderLedger('Share Transactions', 'fas fa-certificate', $sharesLedger, [
    ['label'=>'Date',         'render'=>fn($r)=>formatDate($r['txn_date'])],
    ['label'=>'Type',         'render'=>fn($r)=>'<span class="badge bg-secondary">'.str_replace('_',' ',ucfirst($r['transaction_type'])).'</span>'],
    ['label'=>'Certificate',  'render'=>fn($r)=>'<code>'.(e($r['certificate_no']??'-')).'</code>'],
    ['label'=>'Shares',       'class'=>'text-end', 'render'=>fn($r)=>number_format($r['shares'])],
    ['label'=>'Amount',       'class'=>'text-end', 'render'=>fn($r)=>formatCurrency($r['amount'])],
    ['label'=>'Balance (shares)', 'class'=>'text-end fw-semibold', 'render'=>fn($r)=>number_format($r['balance_shares'])],
], $moduleColor);

renderLedger('Loan & Repayment History', 'fas fa-hand-holding-usd', $loansLedger, [
    ['label'=>'Date',         'render'=>fn($r)=>formatDate($r['txn_date'])],
    ['label'=>'Type',         'render'=>fn($r)=>'<span class="badge bg-'.($r['transaction_type']==='disbursement'?'primary':'success').'">'.ucfirst($r['transaction_type']).'</span>'],
    ['label'=>'Reference',    'render'=>fn($r)=>e($r['reference']??'-')],
    ['label'=>'Notes',        'render'=>fn($r)=>e($r['notes']??'-')],
    ['label'=>'Amount',       'class'=>'text-end', 'render'=>fn($r)=>formatCurrency($r['amount'])],
    ['label'=>'Balance After','class'=>'text-end fw-semibold', 'render'=>fn($r)=>formatCurrency($r['balance_after'])],
], $moduleColor);

renderLedger('Dividend History', 'fas fa-percentage', $dividendLedger, [
    ['label'=>'Period',       'render'=>fn($r)=>e($r['period_label'])],
    ['label'=>'Date',         'render'=>fn($r)=>formatDate($r['txn_date'])],
    ['label'=>'Dividend',     'class'=>'text-end', 'render'=>fn($r)=>formatCurrency($r['dividend_amount'])],
    ['label'=>'Interest',     'class'=>'text-end', 'render'=>fn($r)=>formatCurrency($r['interest_amount'])],
    ['label'=>'Total Payout', 'class'=>'text-end fw-bold', 'render'=>fn($r)=>formatCurrency($r['amount'])],
    ['label'=>'Status',       'render'=>fn($r)=>'<span class="badge bg-'.($r['status']==='paid'?'success':'warning').'">'.ucfirst($r['status']).'</span>'],
], $moduleColor);
?>

<?php endif; ?>

<style>
@media print {
  .no-print, nav, .sidebar, header, footer { display:none!important; }
  .card { border:1px solid #ddd!important; box-shadow:none!important; }
}
</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
