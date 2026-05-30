<?php
// ── Shopping Mall: Rent Payments ──────────────────────────────
$moduleSlug  = 'shopping-mall';
$moduleName  = 'Shopping Mall';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'floors.php',      'icon' => 'fas fa-layer-group',    'label' => 'Floors'],
    ['url' => 'shops.php',       'icon' => 'fas fa-store',          'label' => 'Shops'],
    ['url' => 'tenants.php',     'icon' => 'fas fa-user-tie',       'label' => 'Tenants'],
    ['url' => 'leases.php',      'icon' => 'fas fa-file-contract',  'label' => 'Leases'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-check',    'label' => 'Rent Payments'],
    ['url' => 'maintenance.php', 'icon' => 'fas fa-tools',          'label' => 'Maintenance'],
    ['url' => 'utilities.php',   'icon' => 'fas fa-bolt',           'label' => 'Utilities'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id            = (int)($_POST['id'] ?? 0);
        $shopId        = (int)($_POST['shop_id'] ?? 0);
        $tenantId      = (int)($_POST['tenant_id'] ?? 0);
        $amount        = (float)($_POST['amount'] ?? 0);
        $period        = sanitize($_POST['period'] ?? '');
        $paymentDate   = sanitize($_POST['payment_date'] ?? '') ?: date('Y-m-d');
        $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
        $reference     = sanitize($_POST['reference'] ?? '');
        $status        = in_array($_POST['status'] ?? '', ['paid','pending','partial']) ? $_POST['status'] : 'paid';

        if ($shopId <= 0 || $tenantId <= 0 || empty($period)) {
            setFlash('danger', 'Shop, tenant and period are required.');
            redirect('payments.php');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE mall_rent_payments SET shop_id=?, tenant_id=?, amount=?, period=?, payment_date=?, payment_method=?, reference=?, status=? WHERE id=? AND org_id=?");
            $stmt->execute([$shopId, $tenantId, $amount, $period, $paymentDate, $paymentMethod, $reference, $status, $id, $orgId]);
            setFlash('success', 'Payment updated.');
            logActivity('update', 'shopping-mall', "Updated rent payment #$id");
        } else {
            $stmt = $pdo->prepare("INSERT INTO mall_rent_payments (org_id, shop_id, tenant_id, amount, period, payment_date, payment_method, reference, status) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $shopId, $tenantId, $amount, $period, $paymentDate, $paymentMethod, $reference, $status]);
            setFlash('success', 'Payment recorded successfully.');
            logActivity('create', 'shopping-mall', "Recorded rent payment for shop #$shopId period $period");
        }
        redirect('payments.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM mall_rent_payments WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Payment deleted.');
        logActivity('delete', 'shopping-mall', "Deleted rent payment #$id");
        redirect('payments.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$currentMonth = date('Y-m');
$currentPeriod = date('Y-m');

// Filters
$fPeriod = $_GET['period'] ?? $currentMonth;
$fStatus = $_GET['status'] ?? '';
$fShop   = (int)($_GET['shop'] ?? 0);

$where  = 'rp.org_id = ?';
$params = [$orgId];
if ($fPeriod) { $where .= ' AND rp.period = ?'; $params[] = $fPeriod; }
if ($fStatus) { $where .= ' AND rp.status = ?'; $params[] = $fStatus; }
if ($fShop)   { $where .= ' AND rp.shop_id = ?'; $params[] = $fShop; }

$payments = [];
try {
    $stmt = $pdo->prepare("
        SELECT rp.*, s.shop_no, s.name AS shop_name, t.business_name AS tenant_name
        FROM mall_rent_payments rp
        LEFT JOIN mall_shops s ON rp.shop_id = s.id
        LEFT JOIN mall_tenants t ON rp.tenant_id = t.id
        WHERE $where
        ORDER BY rp.payment_date DESC, rp.id DESC
    ");
    $stmt->execute($params);
    $payments = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats
$collectedThisMonth = 0;
$pendingThisMonth   = 0;
$outstandingShops   = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM mall_rent_payments WHERE org_id=? AND period=? AND status IN ('paid','partial')");
    $stmt->execute([$orgId, $currentPeriod]);
    $collectedThisMonth = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM mall_rent_payments WHERE org_id=? AND period=? AND status='pending'");
    $stmt->execute([$orgId, $currentPeriod]);
    $pendingThisMonth = (float)$stmt->fetchColumn();

    // Occupied shops with no payment this month
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM mall_shops s
        WHERE s.org_id=? AND s.status='occupied'
        AND s.id NOT IN (SELECT shop_id FROM mall_rent_payments WHERE org_id=? AND period=?)
    ");
    $stmt->execute([$orgId, $orgId, $currentPeriod]);
    $outstandingShops = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Shops with active tenants (for dropdown + JS auto-fill)
$shopData = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.id AS shop_id, s.shop_no, s.name AS shop_name, s.monthly_rent,
               t.id AS tenant_id, t.business_name AS tenant_name
        FROM mall_shops s
        INNER JOIN mall_tenants t ON t.shop_id = s.id AND t.status = 'active' AND t.org_id = s.org_id
        WHERE s.org_id = ?
        ORDER BY s.shop_no ASC
    ");
    $stmt->execute([$orgId]);
    $shopData = $stmt->fetchAll();
} catch (Exception $e) {}

$paymentMethods = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'bank' => 'Bank Transfer', 'cheque' => 'Cheque'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-check me-2" style="color:<?= $moduleColor ?>"></i>Rent Payments</h4>
    <p class="text-muted mb-0">Record and track monthly rent collections</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff"
    data-bs-toggle="modal" data-bs-target="#payModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Record Payment
  </button>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1rem"><?= formatCurrency($collectedThisMonth) ?></div><div class="stat-label">Collected This Month</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1rem"><?= formatCurrency($pendingThisMonth) ?></div><div class="stat-label">Pending</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $outstandingShops ?></div><div class="stat-label">Outstanding Shops</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-list"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($payments) ?></div><div class="stat-label">Records (Filtered)</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Period</label>
        <input type="month" name="period" class="form-control form-control-sm" value="<?= e($fPeriod) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Shop</label>
        <select name="shop" class="form-select form-select-sm">
          <option value="">All Shops</option>
          <?php foreach ($shopData as $sh): ?>
          <option value="<?= $sh['shop_id'] ?>" <?= $fShop === (int)$sh['shop_id'] ? 'selected' : '' ?>><?= e($sh['shop_no'] . ' — ' . $sh['shop_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="paid" <?= $fStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
          <option value="partial" <?= $fStatus === 'partial' ? 'selected' : '' ?>>Partial</option>
          <option value="pending" <?= $fStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="payments.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Payments Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-money-check me-2" style="color:<?= $moduleColor ?>"></i>Payment History</h6>
    <span class="badge bg-secondary"><?= count($payments) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Shop</th>
            <th>Tenant</th>
            <th>Period</th>
            <th>Date</th>
            <th class="text-end">Amount</th>
            <th>Method</th>
            <th>Reference</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5">
            <i class="fas fa-money-check fa-2x mb-2 d-block"></i>No payments found.
          </td></tr>
          <?php else: foreach ($payments as $pay): ?>
          <tr>
            <td class="fw-bold" style="color:<?= $moduleColor ?>"><?= e($pay['shop_no'] ?? '—') ?></td>
            <td><?= e($pay['tenant_name'] ?? '—') ?></td>
            <td><?= e($pay['period']) ?></td>
            <td><?= formatDate($pay['payment_date']) ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$pay['amount']) ?></td>
            <td><?= e(ucfirst($pay['payment_method'] ?? '—')) ?></td>
            <td class="small text-muted"><?= e($pay['reference'] ?? '—') ?></td>
            <td><?= statusBadge($pay['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode($pay), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="delPay(<?= $pay['id'] ?>)"
                title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- JS data for auto-fill -->
<script id="shopJsonData" type="application/json"><?= json_encode(array_values($shopData)) ?></script>

<!-- Add/Edit Modal -->
<div class="modal fade" id="payModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="payId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="payModalTitle"><i class="fas fa-money-check me-2"></i>Record Payment</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Shop <span class="text-danger">*</span></label>
              <select name="shop_id" id="payShop" class="form-select" required onchange="fillTenantFromShop(this.value)">
                <option value="">-- Select Shop --</option>
                <?php foreach ($shopData as $sh): ?>
                <option value="<?= $sh['shop_id'] ?>"
                  data-tenant-id="<?= $sh['tenant_id'] ?>"
                  data-tenant-name="<?= e($sh['tenant_name']) ?>"
                  data-rent="<?= $sh['monthly_rent'] ?>">
                  <?= e($sh['shop_no'] . ' — ' . $sh['shop_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Tenant</label>
              <input type="text" id="payTenantDisplay" class="form-control" readonly placeholder="Auto-filled from shop" style="background:#f8f9fa">
              <input type="hidden" name="tenant_id" id="payTenantId">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Period <span class="text-danger">*</span></label>
              <input type="month" name="period" id="payPeriod" class="form-control" required value="<?= $currentPeriod ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Payment Date</label>
              <input type="date" name="payment_date" id="payDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Amount (KES)</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="amount" id="payAmount" class="form-control" step="0.01" min="0" value="0">
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Payment Method</label>
              <select name="payment_method" id="payMethod" class="form-select">
                <?php foreach ($paymentMethods as $key => $label): ?>
                <option value="<?= $key ?>"><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Reference</label>
              <input type="text" name="reference" id="payRef" class="form-control" maxlength="100" placeholder="Transaction ref, cheque no…">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="payStatus" class="form-select">
                <option value="paid">Paid</option>
                <option value="partial">Partial</option>
                <option value="pending">Pending</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="delPayForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delPayId">
</form>

<?php
$extraJs = <<<'JS'
<script>
var shopJson = JSON.parse(document.getElementById('shopJsonData').textContent || '[]');

function fillTenantFromShop(shopId) {
  var shop = shopJson.find(function(s){ return String(s.shop_id) === String(shopId); });
  if (shop) {
    document.getElementById('payTenantId').value      = shop.tenant_id;
    document.getElementById('payTenantDisplay').value = shop.tenant_name;
    // Only auto-fill amount if it's 0 (not already set)
    var curAmt = parseFloat(document.getElementById('payAmount').value) || 0;
    if (curAmt === 0) {
      document.getElementById('payAmount').value = shop.monthly_rent;
    }
  } else {
    document.getElementById('payTenantId').value      = '';
    document.getElementById('payTenantDisplay').value = '';
  }
}

function openAdd() {
  document.getElementById('payModalTitle').innerHTML = '<i class="fas fa-money-check me-2"></i>Record Payment';
  document.getElementById('payId').value            = '0';
  document.getElementById('payShop').value          = '';
  document.getElementById('payTenantId').value       = '';
  document.getElementById('payTenantDisplay').value  = '';
  document.getElementById('payAmount').value         = '0';
  document.getElementById('payPeriod').value         = new Date().toISOString().slice(0,7);
  document.getElementById('payDate').value           = new Date().toISOString().slice(0,10);
  document.getElementById('payMethod').value         = 'cash';
  document.getElementById('payRef').value            = '';
  document.getElementById('payStatus').value         = 'paid';
}

function openEdit(pay) {
  document.getElementById('payModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Payment';
  document.getElementById('payId').value            = pay.id;
  document.getElementById('payShop').value          = pay.shop_id;
  document.getElementById('payTenantId').value       = pay.tenant_id;
  document.getElementById('payTenantDisplay').value  = pay.tenant_name || '';
  document.getElementById('payAmount').value         = pay.amount || '0';
  document.getElementById('payPeriod').value         = pay.period || '';
  document.getElementById('payDate').value           = pay.payment_date || '';
  document.getElementById('payMethod').value         = pay.payment_method || 'cash';
  document.getElementById('payRef').value            = pay.reference || '';
  document.getElementById('payStatus').value         = pay.status || 'paid';
  new bootstrap.Modal(document.getElementById('payModal')).show();
}

function delPay(id) {
  Swal.fire({
    title: 'Delete Payment?',
    text: 'This payment record will be permanently deleted.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('delPayId').value = id;
      document.getElementById('delPayForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
