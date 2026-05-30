<?php
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
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],
];

$createTable = "CREATE TABLE IF NOT EXISTS mall_service_charges (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    shop_id         INT NOT NULL,
    tenant_id       INT,
    charge_type     VARCHAR(100) NOT NULL,
    description     TEXT,
    amount          DECIMAL(10,2) NOT NULL,
    period          VARCHAR(20),
    due_date        DATE,
    payment_date    DATE DEFAULT NULL,
    payment_method  VARCHAR(50),
    reference       VARCHAR(100),
    status          ENUM('pending','paid','overdue','waived') DEFAULT 'pending',
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    try { $pdo->exec($createTable); } catch (Exception $e) {}
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int)($_POST['id']          ?? 0);
        $shopId     = (int)($_POST['shop_id']     ?? 0);
        $tenantId   = (int)($_POST['tenant_id']   ?? 0) ?: null;
        $chargeType = sanitize($_POST['charge_type']  ?? '');
        $desc       = sanitize($_POST['description']  ?? '');
        $amount     = (float)($_POST['amount']    ?? 0);
        $period     = sanitize($_POST['period']   ?? '');
        $dueDate    = $_POST['due_date']           ?? date('Y-m-d');
        $status     = in_array($_POST['status'] ?? '', ['pending','paid','overdue','waived']) ? $_POST['status'] : 'pending';

        if (!$shopId || !$chargeType || $amount <= 0) {
            setFlash('danger', 'Shop, charge type, and amount are required.');
            redirect('service-charges.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE mall_service_charges SET shop_id=?,tenant_id=?,charge_type=?,description=?,amount=?,period=?,due_date=?,status=? WHERE id=? AND org_id=?")
                ->execute([$shopId, $tenantId, $chargeType, $desc, $amount, $period, $dueDate, $status, $id, $orgId]);
            setFlash('success', 'Service charge updated.');
            logActivity('update', 'shopping-mall', "Updated service charge #$id");
        } else {
            $pdo->prepare("INSERT INTO mall_service_charges (org_id,shop_id,tenant_id,charge_type,description,amount,period,due_date,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $shopId, $tenantId, $chargeType, $desc, $amount, $period, $dueDate, $status, $user['id']]);
            setFlash('success', 'Service charge of ' . formatCurrency($amount) . ' raised.');
            logActivity('create', 'shopping-mall', "Raised service charge: $chargeType — " . number_format($amount, 2));
        }
        redirect('service-charges.php');
    }

    if ($action === 'mark_paid') {
        $id     = (int)($_POST['id'] ?? 0);
        $method = sanitize($_POST['payment_method'] ?? 'Cash');
        $ref    = sanitize($_POST['reference']      ?? '');
        $pdo->prepare("UPDATE mall_service_charges SET status='paid',payment_date=NOW(),payment_method=?,reference=? WHERE id=? AND org_id=?")
            ->execute([$method, $ref, $id, $orgId]);
        setFlash('success', 'Service charge marked as paid.');
        redirect('service-charges.php');
    }

    if ($action === 'bulk_raise') {
        // Raise a charge for all occupied shops
        $chargeType = sanitize($_POST['charge_type']  ?? '');
        $amount     = (float)($_POST['amount']    ?? 0);
        $period     = sanitize($_POST['period']   ?? date('Y-m'));
        $dueDate    = $_POST['due_date']           ?? date('Y-m-d');
        $desc       = sanitize($_POST['description'] ?? '');
        if ($chargeType && $amount > 0) {
            $shops = $pdo->prepare("SELECT s.id, t.id AS tenant_id FROM mall_shops s LEFT JOIN mall_tenants t ON t.shop_id=s.id AND t.status='active' WHERE s.org_id=? AND s.status='occupied'");
            $shops->execute([$orgId]);
            $ins = $pdo->prepare("INSERT INTO mall_service_charges (org_id,shop_id,tenant_id,charge_type,description,amount,period,due_date,status,created_by) VALUES (?,?,?,?,?,?,?,?,'pending',?)");
            $raised = 0;
            foreach ($shops->fetchAll() as $s) {
                $ins->execute([$orgId, $s['id'], $s['tenant_id'] ?: null, $chargeType, $desc, $amount, $period, $dueDate, $user['id']]);
                $raised++;
            }
            setFlash('success', "Bulk raised $raised service charge(s) for $chargeType.");
            logActivity('bulk_create', 'shopping-mall', "Bulk raised $raised service charges: $chargeType");
        }
        redirect('service-charges.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM mall_service_charges WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Service charge deleted.');
        redirect('service-charges.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];
try { $pdo->exec($createTable); } catch (Exception $e) {}

$filterStatus = $_GET['status'] ?? '';
$filterPeriod = $_GET['period'] ?? '';

$where  = 'sc.org_id=?';
$params = [$orgId];
if ($filterStatus) { $where .= ' AND sc.status=?'; $params[] = $filterStatus; }
if ($filterPeriod) { $where .= ' AND sc.period=?'; $params[] = $filterPeriod; }

$charges = [];
try {
    $stmt = $pdo->prepare("
        SELECT sc.*, s.shop_no, s.name AS shop_name, t.business_name AS tenant_name
        FROM mall_service_charges sc
        LEFT JOIN mall_shops s ON sc.shop_id=s.id
        LEFT JOIN mall_tenants t ON sc.tenant_id=t.id
        WHERE $where ORDER BY sc.due_date DESC, sc.id DESC
    ");
    $stmt->execute($params);
    $charges = $stmt->fetchAll();
} catch (Exception $e) {}

$totalPending = $totalPaid = $totalOverdue = 0;
foreach ($charges as $c) {
    if ($c['status'] === 'pending') $totalPending += (float)$c['amount'];
    if ($c['status'] === 'paid')    $totalPaid    += (float)$c['amount'];
    if ($c['status'] === 'overdue') $totalOverdue += (float)$c['amount'];
}

// Shops (occupied) for forms
$shops = [];
try {
    $stmt = $pdo->prepare("SELECT s.id, s.shop_no, s.name, t.id AS tenant_id, t.business_name FROM mall_shops s LEFT JOIN mall_tenants t ON t.shop_id=s.id AND t.status='active' WHERE s.org_id=? AND s.status='occupied' ORDER BY s.shop_no");
    $stmt->execute([$orgId]); $shops = $stmt->fetchAll();
} catch (Exception $e) {}

$commonCharges = ['Service Charge','Security Levy','Common Area Maintenance','Garbage Collection','Water Surcharge','Electricity Common Area','Marketing Levy','Parking Fee'];
$statusColors  = ['pending'=>'warning','paid'=>'success','overdue'=>'danger','waived'=>'secondary'];
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Service Charges</h4>
    <p class="text-muted mb-0">Manage and track all service charges billed to tenants</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#bulkModal">
      <i class="fas fa-layer-group me-1"></i>Bulk Raise
    </button>
    <button class="btn text-white btn-sm" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#chargeModal" onclick="openAdd()">
      <i class="fas fa-plus me-2"></i>New Charge
    </button>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPending) ?></div><div class="stat-label">Pending</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPaid) ?></div><div class="stat-label">Collected</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalOverdue) ?></div><div class="stat-label">Overdue</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['pending','paid','overdue','waived'] as $s): ?>
            <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Period (YYYY-MM)</label>
        <input type="month" name="period" class="form-control form-control-sm" value="<?= e($filterPeriod) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="service-charges.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>Service Charge Records</h6>
    <span class="badge bg-secondary"><?= count($charges) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr><th>Shop</th><th>Tenant</th><th>Charge Type</th><th>Period</th><th>Due Date</th><th class="text-end">Amount</th><th>Status</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($charges)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No service charges found.</td></tr>
          <?php else: foreach ($charges as $c): ?>
          <tr>
            <td class="fw-semibold"><?= e(($c['shop_no'] ? $c['shop_no'].' — ' : '') . ($c['shop_name'] ?? '')) ?></td>
            <td class="small"><?= e($c['tenant_name'] ?? '—') ?></td>
            <td><?= e($c['charge_type']) ?></td>
            <td class="small"><?= e($c['period'] ?? '—') ?></td>
            <td class="small"><?= formatDate($c['due_date']) ?></td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$c['amount']) ?></td>
            <td>
              <span class="badge bg-<?= $statusColors[$c['status']] ?? 'secondary' ?>"><?= ucfirst($c['status']) ?></span>
            </td>
            <td class="text-center" style="white-space:nowrap">
              <?php if (in_array($c['status'], ['pending','overdue'])): ?>
              <button class="btn btn-sm btn-outline-success" onclick='openPay(<?= $c['id'] ?>)' title="Mark Paid"><i class="fas fa-check"></i></button>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEdit(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delCharge(<?= $c['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="chargeModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="cId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="cModalTitle"><i class="fas fa-file-invoice me-2"></i>New Service Charge</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Shop <span class="text-danger">*</span></label>
              <select name="shop_id" id="cShop" class="form-select" onchange="autoFillTenant(this)" required>
                <option value="">-- Select shop --</option>
                <?php foreach ($shops as $s): ?>
                  <option value="<?= $s['id'] ?>" data-tenant="<?= $s['tenant_id'] ?>"><?= e(($s['shop_no'] ? $s['shop_no'].' — ' : '') . $s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Charge Type <span class="text-danger">*</span></label>
              <input type="text" name="charge_type" id="cType" class="form-control" list="chargeTypeList" required placeholder="e.g. Service Charge">
              <datalist id="chargeTypeList">
                <?php foreach ($commonCharges as $ch): ?><option value="<?= e($ch) ?>"><?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Amount (<?= CURRENCY_SYMBOL ?>) <span class="text-danger">*</span></label>
              <input type="number" name="amount" id="cAmount" class="form-control" step="0.01" min="0.01" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Period (YYYY-MM)</label>
              <input type="month" name="period" id="cPeriod" class="form-control" value="<?= date('Y-m') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Due Date</label>
              <input type="date" name="due_date" id="cDue" class="form-control" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="cStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="paid">Paid</option>
                <option value="overdue">Overdue</option>
                <option value="waived">Waived</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Description</label>
              <input type="text" name="description" id="cDesc" class="form-control" placeholder="Optional notes">
            </div>
            <input type="hidden" name="tenant_id" id="cTenant" value="">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Mark Paid Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="mark_paid">
        <input type="hidden" name="id" id="pId">
        <div class="modal-header bg-success text-white">
          <h6 class="modal-title"><i class="fas fa-check-circle me-2"></i>Mark as Paid</h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Payment Method</label>
            <select name="payment_method" class="form-select">
              <option>Cash</option><option>M-Pesa</option><option>Bank Transfer</option><option>Cheque</option>
            </select>
          </div>
          <div>
            <label class="form-label fw-semibold">Reference</label>
            <input type="text" name="reference" class="form-control" placeholder="Receipt / transaction no.">
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i>Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Bulk Raise Modal -->
<div class="modal fade" id="bulkModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="bulk_raise">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-layer-group me-2"></i>Bulk Raise Service Charges</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info small"><i class="fas fa-info-circle me-1"></i>This will raise a charge for <strong>all occupied shops</strong>.</div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Charge Type <span class="text-danger">*</span></label>
              <input type="text" name="charge_type" class="form-control" list="chargeTypeList" required placeholder="e.g. Service Charge">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Amount per Shop (<?= CURRENCY_SYMBOL ?>) <span class="text-danger">*</span></label>
              <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Period</label>
              <input type="month" name="period" class="form-control" value="<?= date('Y-m') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Due Date</label>
              <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <input type="text" name="description" class="form-control" placeholder="e.g. Q2 2026 service charge">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-layer-group me-1"></i>Raise for All Occupied Shops</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete form -->
<form method="POST" id="delForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delId">
</form>

<?php
$shopTenantMap = json_encode(array_column($shops, 'tenant_id', 'id'));
$extraJs = <<<JS
<script>
const SHOP_TENANT = $shopTenantMap;

function autoFillTenant(sel) {
  document.getElementById('cTenant').value = SHOP_TENANT[sel.value] || '';
}
function openAdd() {
  document.getElementById('cModalTitle').innerHTML = '<i class="fas fa-file-invoice me-2"></i>New Service Charge';
  document.getElementById('cId').value     = 0;
  document.getElementById('cShop').value   = '';
  document.getElementById('cType').value   = '';
  document.getElementById('cAmount').value = '';
  document.getElementById('cPeriod').value = new Date().toISOString().slice(0,7);
  document.getElementById('cDue').value    = '';
  document.getElementById('cStatus').value = 'pending';
  document.getElementById('cDesc').value   = '';
  document.getElementById('cTenant').value = '';
}
function openEdit(c) {
  document.getElementById('cModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Service Charge';
  document.getElementById('cId').value     = c.id;
  document.getElementById('cShop').value   = c.shop_id || '';
  document.getElementById('cType').value   = c.charge_type || '';
  document.getElementById('cAmount').value = c.amount || '';
  document.getElementById('cPeriod').value = c.period || '';
  document.getElementById('cDue').value    = c.due_date || '';
  document.getElementById('cStatus').value = c.status || 'pending';
  document.getElementById('cDesc').value   = c.description || '';
  document.getElementById('cTenant').value = c.tenant_id || '';
  new bootstrap.Modal(document.getElementById('chargeModal')).show();
}
function openPay(id) {
  document.getElementById('pId').value = id;
  new bootstrap.Modal(document.getElementById('payModal')).show();
}
function delCharge(id) {
  Swal.fire({ title:'Delete this charge?', icon:'warning', showCancelButton:true,
    confirmButtonColor:'#e74c3c', confirmButtonText:'Delete'
  }).then(r => { if (r.isConfirmed) { document.getElementById('delId').value=id; document.getElementById('delForm').submit(); } });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
