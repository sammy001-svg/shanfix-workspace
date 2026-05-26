<?php
$moduleSlug  = 'rental';
$moduleName  = 'Rental & Property';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'properties.php',  'icon' => 'fas fa-building',       'label' => 'Properties'],
    ['url' => 'units.php',       'icon' => 'fas fa-door-open',      'label' => 'Units'],
    ['url' => 'tenants.php',     'icon' => 'fas fa-users',          'label' => 'Tenants'],
    ['url' => 'leases.php',      'icon' => 'fas fa-file-contract',  'label' => 'Leases'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-bill',     'label' => 'Payments'],
    ['url' => 'maintenance.php', 'icon' => 'fas fa-tools',          'label' => 'Maintenance'],
    ['url' => 'invoices.php',    'icon' => 'fas fa-file-invoice',   'label' => 'Invoices'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalProperties = countRows('rental_properties', 'org_id = ?', [$orgId]);
$totalUnits      = countRows('rental_units', 'org_id = ?', [$orgId]);
$occupiedUnits   = countRows('rental_units', 'org_id = ? AND status = ?', [$orgId, 'occupied']);
$vacantUnits     = countRows('rental_units', 'org_id = ? AND status = ?', [$orgId, 'vacant']);
$monthRevenue    = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM rental_payments WHERE org_id=? AND DATE_FORMAT(payment_date,'%Y-%m')=?");
    $stmt->execute([$orgId, date('Y-m')]);
    $monthRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Recent payments
$payments = [];
try {
    $stmt = $pdo->prepare("SELECT r.*, 
                                  CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                                  u.unit_no AS unit_name
                           FROM rental_payments r
                           LEFT JOIN rental_tenants t ON r.tenant_id = t.id
                           LEFT JOIN rental_units u ON r.unit_id = u.id
                           WHERE r.org_id=? 
                           ORDER BY r.payment_date DESC 
                           LIMIT 10");
    $stmt->execute([$orgId]);
    $payments = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage properties estates, unit listings, tenant lease records, and rent billing cycles</p>
  </div>
  <a href="payments.php" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-plus me-2"></i>Record Payment</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-building"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalProperties ?></div><div class="stat-label">Properties Estates</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-door-closed"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalUnits ?></div><div class="stat-label">Total Units</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-user-check"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $occupiedUnits ?> <small class="text-muted fs-6">/ <?= $totalUnits ?></small></div>
        <div class="stat-label">Occupied | Vacant: <?= $vacantUnits ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($monthRevenue) ?></div><div class="stat-label">This Month Collections</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-money-bill me-2" style="color:<?= $moduleColor ?>"></i>Recent Rent Payments Ledger</h6>
    <a href="payments.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="rentalTable">
        <thead class="table-light">
          <tr><th>Receipt / Ref</th><th>Tenant Name</th><th>Assigned Unit</th><th>Rent Period</th><th>Payment Date</th><th>Method</th><th class="text-end">Amount Paid</th></tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No rent payments recorded yet.</td></tr>
          <?php else: foreach ($payments as $p): ?>
          <tr>
            <td class="fw-semibold text-dark"><?= e($p['reference'] ?? '#' . $p['id']) ?></td>
            <td class="fw-bold text-dark"><?= e($p['tenant_name'] ?? '—') ?></td>
            <td class="fw-semibold text-dark"><span class="badge bg-light text-dark border"><?= e($p['unit_name'] ?? '—') ?></span></td>
            <td><?= e($p['period'] ?? '—') ?></td>
            <td><?= formatDate($p['payment_date'] ?? '') ?></td>
            <td><span class="badge bg-secondary"><?= ucfirst($p['payment_method'] ?? 'cash') ?></span></td>
            <td class="text-end fw-bold text-dark"><?= formatCurrency((float)($p['amount'] ?? 0)) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#rentalTable").DataTable({pageLength:10,order:[[4,"desc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
