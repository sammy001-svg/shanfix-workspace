<?php
// ── Shopping Mall: Reports ────────────────────────────────────
$moduleSlug  = 'shopping-mall';
$moduleName  = 'Shopping Mall';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'floors.php',         'icon' => 'fas fa-layer-group',    'label' => 'Floors'],
    ['url' => 'shops.php',          'icon' => 'fas fa-store',          'label' => 'Shops'],
    ['url' => 'tenants.php',        'icon' => 'fas fa-user-tie',       'label' => 'Tenants'],
    ['url' => 'leases.php',         'icon' => 'fas fa-file-contract',  'label' => 'Leases'],
    ['url' => 'payments.php',       'icon' => 'fas fa-money-check',    'label' => 'Rent Payments'],
    ['url' => 'service-charges.php','icon' => 'fas fa-file-invoice',   'label' => 'Service Charges'],
    ['url' => 'notices.php',        'icon' => 'fas fa-bullhorn',       'label' => 'Notices'],
    ['url' => 'maintenance.php',    'icon' => 'fas fa-tools',          'label' => 'Maintenance'],
    ['url' => 'utilities.php',      'icon' => 'fas fa-bolt',           'label' => 'Utilities'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$currentPeriod = date('Y-m');

// KPIs
$totalRentPotential = 0;
$collectedThisMonth = 0;
$vacantRevenueLoss  = 0;
$totalShops = countRows('mall_shops', 'org_id=?', [$orgId]);
$occupied   = countRows('mall_shops', 'org_id=? AND status=?', [$orgId, 'occupied']);
$vacant     = countRows('mall_shops', 'org_id=? AND status=?', [$orgId, 'vacant']);
$maintenance = countRows('mall_shops', 'org_id=? AND status=?', [$orgId, 'maintenance']);

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(monthly_rent),0) FROM mall_shops WHERE org_id=? AND status='occupied'");
    $stmt->execute([$orgId]);
    $totalRentPotential = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM mall_rent_payments WHERE org_id=? AND period=? AND status IN ('paid','partial')");
    $stmt->execute([$orgId, $currentPeriod]);
    $collectedThisMonth = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(monthly_rent),0) FROM mall_shops WHERE org_id=? AND status='vacant'");
    $stmt->execute([$orgId]);
    $vacantRevenueLoss = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$collectionRate = $totalRentPotential > 0 ? round($collectedThisMonth / $totalRentPotential * 100, 1) : 0;

// Monthly rent collection — last 6 months
$months       = [];
$monthlyAmts  = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $months[] = date('M Y', strtotime("-$i months"));
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM mall_rent_payments WHERE org_id=? AND period=? AND status IN ('paid','partial')");
        $stmt->execute([$orgId, $m]);
        $monthlyAmts[] = round((float)$stmt->fetchColumn(), 2);
    } catch (Exception $e) {
        $monthlyAmts[] = 0;
    }
}

// Rent collection table: shops with tenants + this month's payment
$collectionTable = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.shop_no, s.name AS shop_name, s.monthly_rent,
               t.business_name AS tenant_name,
               COALESCE(p.amount, 0) AS paid_amount,
               p.status AS pay_status
        FROM mall_shops s
        INNER JOIN mall_tenants t ON t.shop_id = s.id AND t.status = 'active' AND t.org_id = s.org_id
        LEFT JOIN mall_rent_payments p ON p.shop_id = s.id AND p.period = ? AND p.org_id = s.org_id
        WHERE s.org_id = ? AND s.status = 'occupied'
        ORDER BY s.shop_no ASC
    ");
    $stmt->execute([$currentPeriod, $orgId]);
    $collectionTable = $stmt->fetchAll();
} catch (Exception $e) {}

// Lease expiry list (next 90 days) — from mall_leases (authoritative source)
$expiryDate = date('Y-m-d', strtotime('+90 days'));
$expiringLeases = [];
try {
    $stmt = $pdo->prepare("
        SELECT l.lease_no, l.end_date AS lease_end, l.monthly_rent,
               s.shop_no, s.name AS shop_name,
               t.business_name, t.contact_person, t.phone
        FROM mall_leases l
        JOIN mall_shops s ON s.id = l.shop_id
        JOIN mall_tenants t ON t.id = l.tenant_id
        WHERE l.org_id = ? AND l.status = 'active'
          AND l.end_date <= ?
        ORDER BY l.end_date ASC
    ");
    $stmt->execute([$orgId, $expiryDate]);
    $expiringLeases = $stmt->fetchAll();
} catch (Exception $e) {}

// Floor occupancy breakdown
$floorOccupancy = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(floor, 'Unassigned') AS floor,
            COUNT(*) AS total,
            SUM(CASE WHEN status='occupied' THEN 1 ELSE 0 END) AS occupied,
            SUM(CASE WHEN status='vacant' THEN 1 ELSE 0 END) AS vacant,
            COALESCE(SUM(CASE WHEN status='occupied' THEN monthly_rent ELSE 0 END), 0) AS rent_value
        FROM mall_shops
        WHERE org_id = ?
        GROUP BY floor
        ORDER BY floor ASC
    ");
    $stmt->execute([$orgId]);
    $floorOccupancy = $stmt->fetchAll();
} catch (Exception $e) {}

// Top 5 tenants by total rent paid (all time)
$topTenants = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.business_name, t.contact_person,
               s.shop_no, s.name AS shop_name,
               COALESCE(SUM(p.amount), 0) AS total_paid,
               COUNT(p.id) AS payment_count
        FROM mall_tenants t
        JOIN mall_shops s ON s.id = t.shop_id AND s.org_id = t.org_id
        LEFT JOIN mall_rent_payments p ON p.shop_id = s.id AND p.org_id = t.org_id AND p.status IN ('paid','partial')
        WHERE t.org_id = ? AND t.status = 'active'
        GROUP BY t.id
        ORDER BY total_paid DESC
        LIMIT 5
    ");
    $stmt->execute([$orgId]);
    $topTenants = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Mall Reports</h4>
    <p class="text-muted mb-0">Occupancy, revenue and lease analytics</p>
  </div>
  <span class="text-muted small">As of <?= date('d M Y') ?> &mdash; Period: <?= date('F Y') ?></span>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:.95rem"><?= formatCurrency($totalRentPotential) ?></div><div class="stat-label">Monthly Rent Potential</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:.95rem"><?= formatCurrency($collectedThisMonth) ?></div><div class="stat-label">Collected This Month</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-percentage"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $collectionRate ?>%</div><div class="stat-label">Collection Rate</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-door-open"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:.95rem"><?= formatCurrency($vacantRevenueLoss) ?></div><div class="stat-label">Vacant Revenue Loss</div></div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
  <!-- Occupancy Doughnut -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Occupancy Breakdown</h6></div>
      <div class="card-body d-flex flex-column align-items-center justify-content-center">
        <canvas id="occChart" height="250"></canvas>
        <div class="row g-2 mt-3 text-center w-100">
          <div class="col-4"><div class="fs-5 fw-bold text-success"><?= $occupied ?></div><small class="text-muted">Occupied</small></div>
          <div class="col-4"><div class="fs-5 fw-bold text-warning"><?= $vacant ?></div><small class="text-muted">Vacant</small></div>
          <div class="col-4"><div class="fs-5 fw-bold text-secondary"><?= $maintenance ?></div><small class="text-muted">Maintenance</small></div>
        </div>
      </div>
    </div>
  </div>
  <!-- Monthly Collection Bar -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Monthly Rent Collection — Last 6 Months</h6></div>
      <div class="card-body d-flex align-items-center">
        <canvas id="monthChart" height="200" style="width:100%"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- Rent Collection Table -->
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-table me-2" style="color:<?= $moduleColor ?>"></i>Rent Collection — <?= date('F Y') ?></h6>
    <span class="badge bg-secondary"><?= count($collectionTable) ?> occupied shops</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Shop No</th>
            <th>Shop Name</th>
            <th>Tenant</th>
            <th class="text-end">Rent Due</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Balance</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($collectionTable)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No occupied shops this period.</td></tr>
          <?php else: foreach ($collectionTable as $row):
            $balance    = (float)$row['monthly_rent'] - (float)$row['paid_amount'];
            $payStatus  = $row['pay_status'] ?? 'unpaid';
            $rowClass   = $payStatus === 'paid' ? '' : ($balance > 0 ? 'table-warning' : '');
          ?>
          <tr class="<?= $rowClass ?>">
            <td class="fw-bold" style="color:<?= $moduleColor ?>"><?= e($row['shop_no']) ?></td>
            <td><?= e($row['shop_name']) ?></td>
            <td><?= e($row['tenant_name']) ?></td>
            <td class="text-end"><?= formatCurrency((float)$row['monthly_rent']) ?></td>
            <td class="text-end text-success fw-semibold"><?= formatCurrency((float)$row['paid_amount']) ?></td>
            <td class="text-end <?= $balance > 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= formatCurrency(max(0, $balance)) ?></td>
            <td>
              <?php if (!$payStatus || $payStatus === 'unpaid'): ?>
              <span class="badge bg-danger">Unpaid</span>
              <?php else: ?>
              <?= statusBadge($payStatus) ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($collectionTable)): ?>
        <tfoot class="table-light fw-bold">
          <tr>
            <td colspan="3">Totals</td>
            <td class="text-end"><?= formatCurrency(array_sum(array_column($collectionTable, 'monthly_rent'))) ?></td>
            <td class="text-end text-success"><?= formatCurrency(array_sum(array_column($collectionTable, 'paid_amount'))) ?></td>
            <td class="text-end text-danger"><?= formatCurrency(max(0, array_sum(array_column($collectionTable, 'monthly_rent')) - array_sum(array_column($collectionTable, 'paid_amount')))) ?></td>
            <td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- Floor Occupancy & Top Tenants row -->
<div class="row g-4 mb-4">
  <!-- Floor breakdown -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-layer-group me-2" style="color:<?= $moduleColor ?>"></i>Occupancy by Floor / Wing</h6>
      </div>
      <div class="card-body p-0">
        <?php if (empty($floorOccupancy)): ?>
        <div class="text-center py-5 text-muted">
          <i class="fas fa-layer-group fa-2x mb-2 d-block"></i>No floor data available.
        </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Floor / Wing</th>
                <th class="text-center">Total</th>
                <th class="text-center">Occupied</th>
                <th class="text-center">Vacant</th>
                <th class="text-end">Rent Value</th>
                <th>Occupancy</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($floorOccupancy as $f):
                $rate = $f['total'] > 0 ? round($f['occupied'] / $f['total'] * 100) : 0;
                $barColor = $rate >= 80 ? '#2ecc71' : ($rate >= 50 ? '#f39c12' : '#e74c3c');
              ?>
              <tr>
                <td class="fw-semibold"><?= e($f['floor']) ?></td>
                <td class="text-center"><?= $f['total'] ?></td>
                <td class="text-center text-success fw-bold"><?= $f['occupied'] ?></td>
                <td class="text-center text-warning"><?= $f['vacant'] ?></td>
                <td class="text-end small"><?= formatCurrency((float)$f['rent_value']) ?></td>
                <td style="min-width:100px">
                  <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:8px">
                      <div class="progress-bar" style="width:<?= $rate ?>%;background:<?= $barColor ?>"></div>
                    </div>
                    <small class="fw-bold" style="color:<?= $barColor ?>;min-width:32px"><?= $rate ?>%</small>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Top tenants -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="mb-0"><i class="fas fa-star me-2" style="color:<?= $moduleColor ?>"></i>Top Tenants by Rent Paid</h6>
      </div>
      <div class="card-body p-0">
        <?php if (empty($topTenants)): ?>
        <div class="text-center py-5 text-muted">
          <i class="fas fa-user-tie fa-2x mb-2 d-block"></i>No payment data yet.
        </div>
        <?php else: ?>
        <?php
        $maxPaid = max(array_column($topTenants, 'total_paid')) ?: 1;
        foreach ($topTenants as $i => $tt):
          $pct = round($tt['total_paid'] / $maxPaid * 100);
          $medals = ['🥇','🥈','🥉','',''];
        ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
          <div class="fw-bold text-muted" style="width:24px;flex-shrink:0"><?= $medals[$i] ?: ($i+1) ?></div>
          <div class="flex-grow-1">
            <div class="fw-semibold small"><?= e($tt['business_name']) ?></div>
            <div class="text-muted" style="font-size:.7rem"><?= e($tt['shop_no']) ?> · <?= $tt['payment_count'] ?> payments</div>
            <div class="progress mt-1" style="height:5px">
              <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $moduleColor ?>"></div>
            </div>
          </div>
          <div class="fw-bold text-end small" style="min-width:70px"><?= formatCurrency((float)$tt['total_paid']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Lease Expiry -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-calendar-times me-2 text-warning"></i>Leases Expiring (Next 90 Days)</h6>
    <span class="badge bg-warning text-dark"><?= count($expiringLeases) ?></span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($expiringLeases)): ?>
    <div class="text-center py-5 text-success">
      <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
      No leases expiring in the next 60 days.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Lease #</th>
            <th>Shop</th>
            <th>Business Name</th>
            <th>Contact</th>
            <th class="text-end">Monthly Rent</th>
            <th>Lease End</th>
            <th>Days Left</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expiringLeases as $lease):
            $daysLeft = (int)floor((strtotime($lease['lease_end']) - time()) / 86400);
            $urgency  = $daysLeft <= 7 ? 'danger' : ($daysLeft <= 30 ? 'warning' : 'secondary');
          ?>
          <tr class="<?= $daysLeft <= 7 ? 'table-danger' : ($daysLeft <= 30 ? 'table-warning' : '') ?>">
            <td class="fw-semibold small"><?= e($lease['lease_no'] ?? '—') ?></td>
            <td class="fw-bold" style="color:<?= $moduleColor ?>"><?= e($lease['shop_no']) ?> <small class="text-muted"><?= e($lease['shop_name']) ?></small></td>
            <td><?= e($lease['business_name']) ?></td>
            <td class="small"><?= e($lease['contact_person'] ?? '—') ?><br><span class="text-muted"><?= e($lease['phone'] ?? '') ?></span></td>
            <td class="text-end"><?= formatCurrency((float)($lease['monthly_rent'] ?? 0)) ?></td>
            <td><?= formatDate($lease['lease_end']) ?></td>
            <td><span class="badge bg-<?= $urgency ?>"><?= $daysLeft >= 0 ? $daysLeft . ' days' : 'Expired' ?></span></td>
            <td><a href="leases.php" class="btn btn-xs btn-outline-success btn-sm py-0 px-1" title="Go to Leases to Renew"><i class="fas fa-redo-alt"></i></a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$mJson   = json_encode($months);
$maJson  = json_encode($monthlyAmts);
$extraJs = <<<JS
<script>
(function(){
  var c = '$moduleColor';

  new Chart(document.getElementById('occChart'), {
    type: 'doughnut',
    data: {
      labels: ['Occupied', 'Vacant', 'Maintenance'],
      datasets: [{
        data: [$occupied, $vacant, $maintenance],
        backgroundColor: ['#2c3e50', '#f39c12', '#95a5a6'],
        borderWidth: 2
      }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
  });

  new Chart(document.getElementById('monthChart'), {
    type: 'bar',
    data: {
      labels: $mJson,
      datasets: [{
        label: 'Collected (KES)',
        data: $maJson,
        backgroundColor: c + 'cc',
        borderColor: c,
        borderWidth: 1,
        borderRadius: 6
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });
})();
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
