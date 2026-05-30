<?php
// ── Shopping Mall: Tenants ────────────────────────────────────
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
        $businessName  = sanitize($_POST['business_name'] ?? '');
        $contactPerson = sanitize($_POST['contact_person'] ?? '');
        $phone         = sanitize($_POST['phone'] ?? '');
        $email         = sanitize($_POST['email'] ?? '');
        $businessType  = sanitize($_POST['business_type'] ?? '');
        $leaseStart    = sanitize($_POST['lease_start'] ?? '') ?: null;
        $leaseEnd      = sanitize($_POST['lease_end'] ?? '') ?: null;
        $deposit       = (float)($_POST['deposit'] ?? 0);
        $status        = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        if (empty($businessName) || $shopId <= 0) {
            setFlash('danger', 'Business name and shop are required.');
            redirect('tenants.php');
        }

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $oldTenant = $pdo->prepare("SELECT shop_id, status FROM mall_tenants WHERE id=? AND org_id=?");
                $oldTenant->execute([$id, $orgId]);
                $old = $oldTenant->fetch();

                $stmt = $pdo->prepare("UPDATE mall_tenants SET shop_id=?, business_name=?, contact_person=?, phone=?, email=?, business_type=?, lease_start=?, lease_end=?, deposit=?, status=? WHERE id=? AND org_id=?");
                $stmt->execute([$shopId, $businessName, $contactPerson, $phone, $email, $businessType, $leaseStart, $leaseEnd, $deposit, $status, $id, $orgId]);

                // If changing shop or deactivating, update old shop to vacant
                if ($old && (int)$old['shop_id'] !== $shopId && $old['status'] === 'active') {
                    $pdo->prepare("UPDATE mall_shops SET status='vacant' WHERE id=? AND org_id=?")->execute([$old['shop_id'], $orgId]);
                }
                // Update new shop status based on tenant status
                if ($status === 'active') {
                    $pdo->prepare("UPDATE mall_shops SET status='occupied' WHERE id=? AND org_id=?")->execute([$shopId, $orgId]);
                } else {
                    // If deactivating, set shop vacant (only if no other active tenant)
                    $otherActive = $pdo->prepare("SELECT COUNT(*) FROM mall_tenants WHERE shop_id=? AND status='active' AND id!=? AND org_id=?");
                    $otherActive->execute([$shopId, $id, $orgId]);
                    if ((int)$otherActive->fetchColumn() === 0) {
                        $pdo->prepare("UPDATE mall_shops SET status='vacant' WHERE id=? AND org_id=?")->execute([$shopId, $orgId]);
                    }
                }

                setFlash('success', "Tenant \"$businessName\" updated.");
                logActivity('update', 'shopping-mall', "Updated tenant: $businessName");
            } else {
                // Check shop isn't already occupied by active tenant
                $occupied = $pdo->prepare("SELECT COUNT(*) FROM mall_tenants WHERE shop_id=? AND status='active' AND org_id=?");
                $occupied->execute([$shopId, $orgId]);
                if ((int)$occupied->fetchColumn() > 0) {
                    $pdo->rollBack();
                    setFlash('danger', 'This shop already has an active tenant. Deactivate the current tenant first.');
                    redirect('tenants.php');
                }

                $stmt = $pdo->prepare("INSERT INTO mall_tenants (org_id, shop_id, business_name, contact_person, phone, email, business_type, lease_start, lease_end, deposit, status) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$orgId, $shopId, $businessName, $contactPerson, $phone, $email, $businessType, $leaseStart, $leaseEnd, $deposit, $status]);

                // Set shop to occupied
                if ($status === 'active') {
                    $pdo->prepare("UPDATE mall_shops SET status='occupied' WHERE id=? AND org_id=?")->execute([$shopId, $orgId]);
                }

                setFlash('success', "Tenant \"$businessName\" added successfully.");
                logActivity('create', 'shopping-mall', "Added tenant: $businessName");
            }
            $pdo->commit();
        } catch (Exception $ex) {
            $pdo->rollBack();
            setFlash('danger', 'Error saving tenant: ' . $ex->getMessage());
        }
        redirect('tenants.php');
    }

    if ($action === 'deactivate') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT shop_id, business_name FROM mall_tenants WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $tenant = $stmt->fetch();
        if ($tenant) {
            $pdo->prepare("UPDATE mall_tenants SET status='inactive' WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            $pdo->prepare("UPDATE mall_shops SET status='vacant' WHERE id=? AND org_id=?")->execute([$tenant['shop_id'], $orgId]);
            setFlash('success', "Tenant \"{$tenant['business_name']}\" deactivated. Shop set to vacant.");
            logActivity('update', 'shopping-mall', "Deactivated tenant: {$tenant['business_name']}");
        }
        redirect('tenants.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT shop_id, business_name, status FROM mall_tenants WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $tenant = $stmt->fetch();
        if ($tenant) {
            $pdo->prepare("DELETE FROM mall_tenants WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            if ($tenant['status'] === 'active') {
                $pdo->prepare("UPDATE mall_shops SET status='vacant' WHERE id=? AND org_id=?")->execute([$tenant['shop_id'], $orgId]);
            }
            setFlash('success', "Tenant \"{$tenant['business_name']}\" deleted.");
            logActivity('delete', 'shopping-mall', "Deleted tenant: {$tenant['business_name']}");
        }
        redirect('tenants.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$tenants = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, s.shop_no, s.name AS shop_name, s.monthly_rent
        FROM mall_tenants t
        LEFT JOIN mall_shops s ON t.shop_id = s.id
        WHERE t.org_id = ?
        ORDER BY t.status DESC, t.business_name ASC
    ");
    $stmt->execute([$orgId]);
    $tenants = $stmt->fetchAll();
} catch (Exception $e) {}

$total  = count($tenants);
$active = count(array_filter($tenants, fn($t) => $t['status'] === 'active'));

// Shops for dropdown (show all shops with their status)
$allShops = [];
try {
    $stmt = $pdo->prepare("SELECT s.id, s.shop_no, s.name, s.status, s.monthly_rent FROM mall_shops s WHERE s.org_id=? ORDER BY s.shop_no ASC");
    $stmt->execute([$orgId]);
    $allShops = $stmt->fetchAll();
} catch (Exception $e) {}

// View tenant detail
$viewTenant = null;
$tenantPayments = [];
if (isset($_GET['view'])) {
    $vid = (int)$_GET['view'];
    try {
        $stmt = $pdo->prepare("SELECT t.*, s.shop_no, s.name AS shop_name, s.monthly_rent FROM mall_tenants t LEFT JOIN mall_shops s ON t.shop_id = s.id WHERE t.id=? AND t.org_id=?");
        $stmt->execute([$vid, $orgId]);
        $viewTenant = $stmt->fetch();
        if ($viewTenant) {
            $stmt = $pdo->prepare("SELECT * FROM mall_rent_payments WHERE tenant_id=? AND org_id=? ORDER BY payment_date DESC LIMIT 12");
            $stmt->execute([$vid, $orgId]);
            $tenantPayments = $stmt->fetchAll();
        }
    } catch (Exception $e) {}
}

// 30-day expiry threshold
$expiryThreshold = date('Y-m-d', strtotime('+30 days'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-user-tie me-2" style="color:<?= $moduleColor ?>"></i>Tenants</h4>
    <p class="text-muted mb-0">Manage shop tenants and lease agreements</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff"
    data-bs-toggle="modal" data-bs-target="#tenantModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Tenant
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-user-tie"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $total ?></div><div class="stat-label">Total Tenants</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $active ?></div><div class="stat-label">Active</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count(array_filter($tenants, fn($t) => $t['status'] === 'active' && $t['lease_end'] && $t['lease_end'] <= $expiryThreshold)) ?></div><div class="stat-label">Expiring (30 days)</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1rem"><?= formatCurrency(array_sum(array_column(array_filter($tenants, fn($t) => $t['status'] === 'active'), 'monthly_rent'))) ?></div><div class="stat-label">Monthly Rent Income</div></div>
    </div>
  </div>
</div>

<?php if ($viewTenant): ?>
<!-- Tenant Detail -->
<div class="card mb-4" id="tenantDetail">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:<?= $moduleColor ?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-user-tie me-2"></i><?= e($viewTenant['business_name']) ?></h6>
    <a href="tenants.php" class="btn btn-sm btn-light"><i class="fas fa-times me-1"></i>Close</a>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-5">
        <table class="table table-sm">
          <tr><th class="text-muted" style="width:40%">Business</th><td class="fw-semibold"><?= e($viewTenant['business_name']) ?></td></tr>
          <tr><th class="text-muted">Shop</th><td><?= e($viewTenant['shop_no'] . ' — ' . $viewTenant['shop_name']) ?></td></tr>
          <tr><th class="text-muted">Contact</th><td><?= e($viewTenant['contact_person'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Phone</th><td><?= e($viewTenant['phone'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Email</th><td><?= e($viewTenant['email'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Type</th><td><?= e($viewTenant['business_type'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Lease Start</th><td><?= formatDate($viewTenant['lease_start']) ?></td></tr>
          <tr><th class="text-muted">Lease End</th><td>
            <?php $isExp = $viewTenant['lease_end'] && $viewTenant['lease_end'] <= $expiryThreshold; ?>
            <span class="<?= $isExp ? 'text-danger fw-semibold' : '' ?>"><?= formatDate($viewTenant['lease_end']) ?></span>
            <?php if ($isExp): ?><span class="badge bg-danger ms-1">Expiring</span><?php endif; ?>
          </td></tr>
          <tr><th class="text-muted">Deposit</th><td><?= formatCurrency((float)$viewTenant['deposit']) ?></td></tr>
          <tr><th class="text-muted">Monthly Rent</th><td class="fw-bold"><?= formatCurrency((float)$viewTenant['monthly_rent']) ?></td></tr>
          <tr><th class="text-muted">Status</th><td><?= statusBadge($viewTenant['status']) ?></td></tr>
        </table>
      </div>
      <div class="col-md-7">
        <h6 class="fw-semibold mb-3">Payment History</h6>
        <?php if (empty($tenantPayments)): ?>
        <p class="text-muted">No payments recorded yet.</p>
        <?php else: ?>
        <table class="table table-sm table-hover">
          <thead class="table-light"><tr><th>Period</th><th>Date</th><th class="text-end">Amount</th><th>Method</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($tenantPayments as $pay): ?>
          <tr>
            <td><?= e($pay['period']) ?></td>
            <td><?= formatDate($pay['payment_date']) ?></td>
            <td class="text-end"><?= formatCurrency((float)$pay['amount']) ?></td>
            <td><?= e($pay['payment_method'] ?? '—') ?></td>
            <td><?= statusBadge($pay['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Tenants Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-user-tie me-2" style="color:<?= $moduleColor ?>"></i>All Tenants</h6>
    <span class="badge bg-secondary"><?= $total ?> tenants</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Shop</th>
            <th>Business Name</th>
            <th>Contact</th>
            <th>Phone</th>
            <th>Lease Start</th>
            <th>Lease End</th>
            <th class="text-end">Deposit</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($tenants)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5">
            <i class="fas fa-user-tie fa-2x mb-2 d-block"></i>No tenants yet.
          </td></tr>
          <?php else: foreach ($tenants as $t):
            $isExpiring = $t['status'] === 'active' && $t['lease_end'] && $t['lease_end'] <= $expiryThreshold;
            $isExpired  = $t['status'] === 'active' && $t['lease_end'] && $t['lease_end'] < date('Y-m-d');
            $rowClass   = $isExpired ? 'table-danger' : ($isExpiring ? 'table-warning' : '');
          ?>
          <tr class="<?= $rowClass ?>">
            <td><span class="fw-bold" style="color:<?= $moduleColor ?>"><?= e($t['shop_no'] ?? '—') ?></span></td>
            <td>
              <div class="fw-semibold"><?= e($t['business_name']) ?></div>
              <div class="small text-muted"><?= e($t['business_type'] ?? '') ?></div>
            </td>
            <td><?= e($t['contact_person'] ?? '—') ?></td>
            <td><?= e($t['phone'] ?? '—') ?></td>
            <td><?= formatDate($t['lease_start']) ?></td>
            <td>
              <?= formatDate($t['lease_end']) ?>
              <?php if ($isExpired): ?><span class="badge bg-danger ms-1">Expired</span><?php endif; ?>
              <?php if ($isExpiring && !$isExpired): ?><span class="badge bg-warning text-dark ms-1">Expiring</span><?php endif; ?>
            </td>
            <td class="text-end"><?= formatCurrency((float)$t['deposit']) ?></td>
            <td><?= statusBadge($t['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <a href="?view=<?= $t['id'] ?>#tenantDetail" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
              <button class="btn btn-sm btn-outline-primary ms-1"
                onclick='openEdit(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <?php if ($t['status'] === 'active'): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="deactivate">
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-warning ms-1" title="Deactivate"
                  onclick="return confirm('Deactivate this tenant and set shop to vacant?')">
                  <i class="fas fa-user-slash"></i>
                </button>
              </form>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="delTenant(<?= $t['id'] ?>, '<?= e($t['business_name']) ?>')"
                title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="tenantModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="tId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="tenantModalTitle"><i class="fas fa-user-tie me-2"></i>Add Tenant</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Shop <span class="text-danger">*</span></label>
              <select name="shop_id" id="tShop" class="form-select" required>
                <option value="">-- Select Shop --</option>
                <?php foreach ($allShops as $sh): ?>
                <option value="<?= $sh['id'] ?>"
                  data-rent="<?= $sh['monthly_rent'] ?>"
                  data-status="<?= $sh['status'] ?>">
                  <?= e($sh['shop_no'] . ' — ' . $sh['name']) ?>
                  (<?= ucfirst($sh['status']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Business Name <span class="text-danger">*</span></label>
              <input type="text" name="business_name" id="tBusiness" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Contact Person</label>
              <input type="text" name="contact_person" id="tContact" class="form-control" maxlength="255">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="tPhone" class="form-control" maxlength="25">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="tEmail" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Business Type</label>
              <input type="text" name="business_type" id="tType" class="form-control" maxlength="100" placeholder="e.g. Electronics, Clothing">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Lease Start</label>
              <input type="date" name="lease_start" id="tStart" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Lease End</label>
              <input type="date" name="lease_end" id="tEnd" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Security Deposit (KES)</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="deposit" id="tDeposit" class="form-control" step="0.01" min="0" value="0">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="tStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Tenant</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="delTenantForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delTenantId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('tenantModalTitle').innerHTML = '<i class="fas fa-user-tie me-2"></i>Add Tenant';
  ['tId','tBusiness','tContact','tPhone','tEmail','tType','tStart','tEnd'].forEach(function(i){
    document.getElementById(i).value = i==='tId' ? '0' : '';
  });
  document.getElementById('tDeposit').value = '0';
  document.getElementById('tShop').value    = '';
  document.getElementById('tStatus').value  = 'active';
}
function openEdit(t) {
  document.getElementById('tenantModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Tenant';
  document.getElementById('tId').value       = t.id;
  document.getElementById('tShop').value     = t.shop_id || '';
  document.getElementById('tBusiness').value = t.business_name || '';
  document.getElementById('tContact').value  = t.contact_person || '';
  document.getElementById('tPhone').value    = t.phone || '';
  document.getElementById('tEmail').value    = t.email || '';
  document.getElementById('tType').value     = t.business_type || '';
  document.getElementById('tStart').value    = t.lease_start || '';
  document.getElementById('tEnd').value      = t.lease_end || '';
  document.getElementById('tDeposit').value  = t.deposit || '0';
  document.getElementById('tStatus').value   = t.status || 'active';
  new bootstrap.Modal(document.getElementById('tenantModal')).show();
}
function delTenant(id, name) {
  Swal.fire({
    title: 'Delete Tenant?',
    text: '"' + name + '" will be permanently removed.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('delTenantId').value = id;
      document.getElementById('delTenantForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
