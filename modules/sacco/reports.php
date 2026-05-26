<?php
// ── SACCO: Analytical Reports & Charts ─────────────────────────
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
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',        'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch Core Totals
$totalSavingsVal     = 0.00;
$totalOutstandingVal = 0.00;
$totalSharesVal      = 0.00;
$totalRepaymentsVal  = 0.00;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_savings), 0), COALESCE(SUM(shares * share_value), 0) FROM sacco_members WHERE org_id = ?");
    $stmt->execute([$orgId]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    $totalSavingsVal = (float)$row[0];
    $totalSharesVal  = (float)$row[1];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(balance), 0) FROM sacco_loans WHERE org_id = ? AND status='active'");
    $stmt->execute([$orgId]);
    $totalOutstandingVal = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM sacco_loan_repayments WHERE org_id = ?");
    $stmt->execute([$orgId]);
    $totalRepaymentsVal = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Monthly trends over last 6 months
$months = [];
$savingsTrend = [];
$loansTrend = [];

for ($i = 5; $i >= 0; $i--) {
    $date = date('Y-m', strtotime("-$i months"));
    $months[] = date('M Y', strtotime("-$i months"));
    
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM sacco_savings WHERE org_id=? AND type='deposit' AND DATE_FORMAT(created_at, '%Y-%m') = ?");
        $stmt->execute([$orgId, $date]);
        $savingsTrend[] = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM sacco_loans WHERE org_id=? AND status IN ('active','completed') AND DATE_FORMAT(disbursed_at, '%Y-%m') = ?");
        $stmt->execute([$orgId, $date]);
        $loansTrend[] = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        $savingsTrend[] = 0.00;
        $loansTrend[] = 0.00;
    }
}

// Loan Status Distribution Metrics
$loanStatusCounts = [
    'pending' => 0, 'approved' => 0, 'active' => 0, 'completed' => 0, 'defaulted' => 0
];
try {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM sacco_loans WHERE org_id=? GROUP BY status");
    $stmt->execute([$orgId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $loanStatusCounts[$r['status']] = (int)$r['cnt'];
    }
} catch (Exception $e) {}

// Savings Deposits vs Withdrawals count & sum
$savingsSummary = ['deposit_sum' => 0, 'deposit_cnt' => 0, 'withdraw_sum' => 0, 'withdraw_cnt' => 0];
try {
    $stmt = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) as total_sum, COUNT(*) as total_cnt FROM sacco_savings WHERE org_id=? GROUP BY type");
    $stmt->execute([$orgId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        if ($r['type'] === 'deposit') {
            $savingsSummary['deposit_sum'] = (float)$r['total_sum'];
            $savingsSummary['deposit_cnt'] = (int)$r['total_cnt'];
        } else {
            $savingsSummary['withdraw_sum'] = (float)$r['total_sum'];
            $savingsSummary['withdraw_cnt'] = (int)$r['total_cnt'];
        }
    }
} catch (Exception $e) {}

// Repayments summary
$repaymentsSummary = ['principal' => 0, 'interest' => 0, 'cnt' => 0];
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(principal),0), COALESCE(SUM(interest),0), COUNT(*) FROM sacco_loan_repayments WHERE org_id=?");
    $stmt->execute([$orgId]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    $repaymentsSummary['principal'] = (float)$row[0];
    $repaymentsSummary['interest']  = (float)$row[1];
    $repaymentsSummary['cnt']       = (int)$row[2];
} catch (Exception $e) {}
?>

<div class="page-header mb-4">
  <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Sacco Analytical Reports</h4>
  <p class="text-muted mb-0">Track financial liquidity, member shares growth, and credit portfolio performance metrics</p>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon purple-bg" style="background:rgba(142,68,173,0.15);color:#8e44ad"><i class="fas fa-piggy-bank"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalSavingsVal) ?></div><div class="stat-label">Total Savings Balance</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-hand-holding-usd"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalOutstandingVal) ?></div><div class="stat-label">Outstanding Loans Portfolio</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-chart-pie"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalSharesVal) ?></div><div class="stat-label">Total Share Capital</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-undo"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRepaymentsVal) ?></div><div class="stat-label">Cumulative Repayments</div></div>
    </div>
  </div>
</div>

<!-- Interactive charts grid -->
<div class="row g-4 mb-4">
  <!-- Monthly savings vs loans trend -->
  <div class="col-lg-8 col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-line me-2 text-primary"></i>Savings Deposits vs. Loans Disbursed (Last 6 Months)</h6>
      </div>
      <div class="card-body">
        <div style="height:320px">
          <canvas id="monthlyTrendChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Loan status doughnut -->
  <div class="col-lg-4 col-md-6 col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-pie me-2 text-danger"></i>Loans Distribution Status</h6>
      </div>
      <div class="card-body">
        <div style="height:320px;display:flex;align-items:center;justify-content:center">
          <canvas id="loanStatusChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Savings Deposit vs Withdrawal Ratio -->
  <div class="col-lg-4 col-md-6 col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-exchange-alt me-2 text-success"></i>Savings Transactions Breakdown</h6>
      </div>
      <div class="card-body">
        <div style="height:250px;display:flex;align-items:center;justify-content:center">
          <canvas id="savingsRatioChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Detailed Ledger card -->
  <div class="col-lg-8 col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-table me-2 text-secondary"></i>Detailed Financial Ledger Summary</h6>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Account category</th>
              <th class="text-center">Count / Tx Count</th>
              <th class="text-end pe-3">Cumulative Value</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="fw-semibold text-dark ps-3"><i class="fas fa-arrow-down text-success me-2"></i>Savings Deposits</td>
              <td class="text-center"><?= $savingsSummary['deposit_cnt'] ?> Deposits</td>
              <td class="text-end pe-3 fw-bold text-success"><?= formatCurrency($savingsSummary['deposit_sum']) ?></td>
            </tr>
            <tr>
              <td class="fw-semibold text-dark ps-3"><i class="fas fa-arrow-up text-danger me-2"></i>Savings Withdrawals</td>
              <td class="text-center"><?= $savingsSummary['withdraw_cnt'] ?> Withdrawals</td>
              <td class="text-end pe-3 fw-bold text-danger"><?= formatCurrency($savingsSummary['withdraw_sum']) ?></td>
            </tr>
            <tr>
              <td class="fw-semibold text-dark ps-3"><i class="fas fa-hand-holding-usd text-primary me-2"></i>Disbursed Credits</td>
              <td class="text-center"><?= countRows('sacco_loans', 'org_id=?', [$orgId]) ?> Loans</td>
              <td class="text-end pe-3 fw-bold text-primary"><?= formatCurrency($totalDisbursed = $totalOutstandingVal + $totalRepaymentsVal) ?></td>
            </tr>
            <tr>
              <td class="fw-semibold text-dark ps-3"><i class="fas fa-undo text-success me-2"></i>Paid Principal Portion</td>
              <td class="text-center" rowspan="2" style="vertical-align:middle"><?= $repaymentsSummary['cnt'] ?> Repayments</td>
              <td class="text-end pe-3 fw-bold text-success"><?= formatCurrency($repaymentsSummary['principal']) ?></td>
            </tr>
            <tr>
              <td class="fw-semibold text-dark ps-3"><i class="fas fa-percentage text-info me-2"></i>Paid Interest Portion</td>
              <td class="text-end pe-3 fw-bold text-info"><?= formatCurrency($repaymentsSummary['interest']) ?></td>
            </tr>
            <tr class="table-light">
              <td class="fw-bold text-dark ps-3">Total Net Sacco Reserves</td>
              <td class="text-center">—</td>
              <td class="text-end pe-3 fw-bold text-dark fs-5"><?= formatCurrency($totalSharesVal + $totalSavingsVal - $totalOutstandingVal) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function(){
  // 1. Savings vs. Loans Monthly Trend Chart
  var trendCtx = document.getElementById("monthlyTrendChart").getContext("2d");
  new Chart(trendCtx, {
    type: "bar",
    data: {
      labels: ' . json_encode($months) . ',
      datasets: [
        {
          label: "Deposits",
          data: ' . json_encode($savingsTrend) . ',
          backgroundColor: "rgba(46, 204, 113, 0.75)",
          borderColor: "#2ecc71",
          borderWidth: 1
        },
        {
          label: "Disbursed Loans",
          data: ' . json_encode($loansTrend) . ',
          backgroundColor: "rgba(142, 68, 173, 0.75)",
          borderColor: "#8e44ad",
          borderWidth: 1
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function(value) {
              return "KES " + value.toLocaleString();
            }
          }
        }
      }
    }
  });

  // 2. Loan Status Doughnut Chart
  var loanCtx = document.getElementById("loanStatusChart").getContext("2d");
  new Chart(loanCtx, {
    type: "doughnut",
    data: {
      labels: ["Pending", "Approved", "Active", "Completed", "Defaulted"],
      datasets: [{
        data: [' . implode(',', array_values($loanStatusCounts)) . '],
        backgroundColor: [
          "#f1c40f", // Pending - Warning Yellow
          "#3498db", // Approved - Blue
          "#e67e22", // Active - Orange
          "#2ecc71", // Completed - Green
          "#e74c3c"  // Defaulted - Red
        ],
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      }
    }
  });

  // 3. Savings Ratio Pie Chart
  var savingsCtx = document.getElementById("savingsRatioChart").getContext("2d");
  new Chart(savingsCtx, {
    type: "pie",
    data: {
      labels: ["Deposits", "Withdrawals"],
      datasets: [{
        data: [' . $savingsSummary['deposit_sum'] . ',' . $savingsSummary['withdraw_sum'] . '],
        backgroundColor: [
          "#2ecc71", // Deposits - Green
          "#e74c3c"  // Withdrawals - Red
        ],
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      }
    }
  });
});
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
