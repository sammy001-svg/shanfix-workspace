<?php
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
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalMembers   = countRows('sacco_members', 'org_id = ?', [$orgId]);
$activeLoans    = countRows('sacco_loans', 'org_id = ? AND status = ?', [$orgId, 'active']);
$totalSavings   = 0;
$loanPortfolio  = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_savings),0) FROM sacco_members WHERE org_id=?");
    $stmt->execute([$orgId]);
    $totalSavings = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM sacco_loans WHERE org_id=? AND status='active'");
    $stmt->execute([$orgId]);
    $loanPortfolio = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Recent members
$members = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM sacco_members WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$orgId]);
    $members = $stmt->fetchAll();
} catch (Exception $e) {}

// Savings trend (6 months)
$savingLabels = [];
$savingData   = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $savingLabels[] = date('M Y', strtotime("-$i months"));
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sacco_savings WHERE org_id=? AND type='deposit' AND DATE_FORMAT(created_at,'%Y-%m')=?");
        $stmt->execute([$orgId, $month]);
        $savingData[] = (float)$stmt->fetchColumn();
    } catch (Exception $e) { $savingData[] = 0; }
}

// Loan status
$loanActive    = countRows('sacco_loans', 'org_id = ? AND status = ?', [$orgId, 'active']);
$loanCompleted = countRows('sacco_loans', 'org_id = ? AND status = ?', [$orgId, 'completed']);
$loanDefaulted = countRows('sacco_loans', 'org_id = ? AND status = ?', [$orgId, 'defaulted']);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage members, savings, and loans</p>
  </div>
  <a href="members.php?action=add" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-plus me-2"></i>Add Member</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalMembers ?></div><div class="stat-label">Members</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-piggy-bank"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalSavings) ?></div><div class="stat-label">Total Savings</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hand-holding-usd"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeLoans ?></div><div class="stat-label">Active Loans</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($loanPortfolio) ?></div><div class="stat-label">Loan Portfolio</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-line me-2" style="color:<?= $moduleColor ?>"></i>Savings Trend (6 months)</h6></div>
      <div class="card-body"><canvas id="savingsChart" height="120"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Loan Status</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="loanChart" height="220"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Recent Members</h6>
    <a href="members.php" class="btn btn-sm" style="border-color:<?= $moduleColor ?>;color:<?= $moduleColor ?>">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="saccoTable">
        <thead class="table-light">
          <tr><th>Member No</th><th>Name</th><th>Phone</th><th>Savings Balance</th><th>Status</th><th>Joined</th></tr>
        </thead>
        <tbody>
          <?php if (empty($members)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No members found</td></tr>
          <?php else: foreach ($members as $m): ?>
          <tr>
            <td class="fw-semibold"><?= e($m['member_number'] ?? '#' . $m['id']) ?></td>
            <td><?= e($m['name'] ?? '—') ?></td>
            <td><?= e($m['phone'] ?? '—') ?></td>
            <td><?= formatCurrency((float)($m['savings_balance'] ?? 0)) ?></td>
            <td><?= statusBadge($m['status'] ?? 'active') ?></td>
            <td><?= formatDate($m['created_at'] ?? '') ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
(function(){
  new Chart(document.getElementById("savingsChart"),{
    type:"line",
    data:{
      labels:' . json_encode($savingLabels) . ',
      datasets:[{label:"Deposits",data:' . json_encode($savingData) . ',borderColor:"#8e44ad",backgroundColor:"rgba(142,68,173,.15)",tension:.4,fill:true}]
    },
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}
  });
  new Chart(document.getElementById("loanChart"),{
    type:"doughnut",
    data:{labels:["Active","Completed","Defaulted"],datasets:[{data:[' . $loanActive . ',' . $loanCompleted . ',' . $loanDefaulted . '],backgroundColor:["#f39c12","#1A8A4E","#e74c3c"]}]},
    options:{responsive:true,plugins:{legend:{position:"bottom"}}}
  });
  $("#saccoTable").DataTable({pageLength:10,order:[[5,"desc"]]});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
