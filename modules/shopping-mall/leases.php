<?php
// ── Shopping Mall: Leases ─────────────────────────────────────
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
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $shopId     = (int)($_POST['shop_id'] ?? 0);
        $tenantId   = (int)($_POST['tenant_id'] ?? 0);
        $startDate  = sanitize($_POST['start_date'] ?? '');
        $endDate    = sanitize($_POST['end_date'] ?? '');
        $monthlyRent = (float)($_POST['monthly_rent'] ?? 0);
        $deposit    = (float)($_POST['deposit'] ?? 0);
        $terms      = sanitize($_POST['terms'] ?? '');
        $status     = in_array($_POST['status'] ?? '', ['active','expired','terminated','pending']) ? $_POST['status'] : 'active';

        if ($shopId <= 0 || $tenantId <= 0 || !$startDate || !$endDate || $monthlyRent <= 0) {
            setFlash('danger', 'Shop, tenant, dates and monthly rent are required.');
            redirect('leases.php');
        }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE mall_leases SET shop_id=?, tenant_id=?, start_date=?, end_date=?, monthly_rent=?, deposit=?, terms=?, status=?, updated_at=NOW() WHERE id=? AND org_id=?")
                    ->execute([$shopId, $tenantId, $startDate, $endDate, $monthlyRent, $deposit, $terms, $status, $id, $orgId]);
                setFlash('success', 'Lease updated successfully.');
                logActivity('update', 'shopping-mall', "Lease #{$id} updated");
            } else {
                // Auto-generate lease number: LS-YYYY-NNNN
                $seq = $pdo->query("SELECT COUNT(*) FROM mall_leases WHERE org_id=$orgId")->fetchColumn() + 1;
                $leaseNo = 'LS-' . date('Y') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

                $pdo->prepare("INSERT INTO mall_leases (org_id, lease_no, shop_id, tenant_id, start_date, end_date, monthly_rent, deposit, terms, status) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $leaseNo, $shopId, $tenantId, $startDate, $endDate, $monthlyRent, $deposit, $terms, $status]);

                // Update shop status to occupied
                $pdo->prepare("UPDATE mall_shops SET status='occupied', tenant_id=? WHERE id=? AND org_id=?")
                    ->execute([$tenantId, $shopId, $orgId]);

                setFlash('success', "Lease {$leaseNo} created successfully.");
                logActivity('create', 'shopping-mall', "Lease {$leaseNo} created");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('leases.php');
    }

    if ($action === 'terminate') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT shop_id FROM mall_leases WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            $lease = $stmt->fetch();
            if ($lease) {
                $pdo->prepare("UPDATE mall_leases SET status='terminated', updated_at=NOW() WHERE id=? AND org_id=?")
                    ->execute([$id, $orgId]);
                $pdo->prepare("UPDATE mall_shops SET status='vacant', tenant_id=NULL WHERE id=? AND org_id=?")
                    ->execute([$lease['shop_id'], $orgId]);
                setFlash('success', 'Lease terminated and shop marked as vacant.');
                logActivity('update', 'shopping-mall', "Lease #{$id} terminated");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('leases.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$leases  = [];
$shops   = [];
$tenants = [];
try {
    $stmt = $pdo->prepare("
        SELECT l.*, s.shop_no, s.name AS shop_name, t.business_name, t.contact_person, t.phone
        FROM mall_leases l
        JOIN mall_shops s ON s.id = l.shop_id
        JOIN mall_tenants t ON t.id = l.tenant_id
        WHERE l.org_id = ?
        ORDER BY l.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $leases = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, shop_no, name FROM mall_shops WHERE org_id=? ORDER BY shop_no");
    $stmt->execute([$orgId]);
    $shops = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, business_name, contact_person FROM mall_tenants WHERE org_id=? ORDER BY business_name");
    $stmt->execute([$orgId]);
    $tenants = $stmt->fetchAll();
} catch (Exception $e) {}

$activeLeases = array_filter($leases, fn($l) => $l['status'] === 'active');
$expiringLeases = array_filter($leases, function($l) {
    if ($l['status'] !== 'active') return false;
    $daysLeft = (strtotime($l['end_date']) - time()) / 86400;
    return $daysLeft >= 0 && $daysLeft <= 30;
});
$totalMonthlyRent = array_sum(array_column(array_filter($leases, fn($l) => $l['status'] === 'active'), 'monthly_rent'));

$statusColors = ['active' => 'success', 'expired' => 'secondary', 'terminated' => 'danger', 'pending' => 'warning'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-contract me-2" style="color:<?= $moduleColor ?>"></i>Lease Agreements</h4>
    <p class="text-muted mb-0">Manage shop lease contracts, tenancy terms and renewal tracking</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#leaseModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>New Lease
  </button>
</div>

<?php if (!empty($expiringLeases)): ?>
<div class="alert alert-warning d-flex align-items-center mb-4">
  <i class="fas fa-exclamation-triangle me-2"></i>
  <div><strong><?= count($expiringLeases) ?> lease(s)</strong> expiring within 30 days — review and renew promptly.</div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-file-contract"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($activeLeases) ?></div><div class="stat-label">Active Leases</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($expiringLeases) ?></div><div class="stat-label">Expiring in 30 Days</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(142,68,173,0.12);color:#8e44ad"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalMonthlyRent) ?></div><div class="stat-label">Monthly Rental Income</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 data-table">
        <thead class="table-light">
          <tr>
            <th>Lease #</th><th>Shop</th><th>Tenant</th><th>Start</th><th>End</th>
            <th class="text-end">Monthly Rent</th><th class="text-end">Deposit</th>
            <th class="text-center">Status</th><th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($leases)): ?>
          <tr><td colspan="9" class="text-center py-5 text-muted"><i class="fas fa-file-contract fa-3x mb-3 d-block"></i>No lease agreements found.</td></tr>
          <?php else: foreach ($leases as $l):
            $daysLeft = (strtotime($l['end_date']) - time()) / 86400;
            $expiring = $l['status'] === 'active' && $daysLeft >= 0 && $daysLeft <= 30;
          ?>
          <tr class="<?= $expiring ? 'table-warning' : '' ?>">
            <td class="fw-semibold"><?= e($l['lease_no']) ?></td>
            <td>
              <div class="fw-semibold"><?= e($l['shop_name']) ?></div>
              <div class="small text-muted"><?= e($l['shop_no']) ?></div>
            </td>
            <td>
              <div><?= e($l['business_name']) ?></div>
              <div class="small text-muted"><?= e($l['contact_person']) ?> · <?= e($l['phone']) ?></div>
            </td>
            <td class="small"><?= date('d M Y', strtotime($l['start_date'])) ?></td>
            <td class="small">
              <?= date('d M Y', strtotime($l['end_date'])) ?>
              <?php if ($expiring): ?>
              <span class="badge bg-warning text-dark ms-1"><?= ceil($daysLeft) ?>d left</span>
              <?php endif; ?>
            </td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$l['monthly_rent']) ?></td>
            <td class="text-end text-muted"><?= formatCurrency((float)$l['deposit']) ?></td>
            <td class="text-center"><span class="badge bg-<?= $statusColors[$l['status']] ?? 'secondary' ?>"><?= ucfirst($l['status']) ?></span></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($l), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <?php if ($l['status'] === 'active'): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Terminate this lease? The shop will be marked vacant.')">
                <?= csrfField() ?><input type="hidden" name="action" value="terminate"><input type="hidden" name="id" value="<?= $l['id'] ?>">
                <button class="btn btn-sm btn-outline-danger ms-1"><i class="fas fa-ban"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Lease Modal -->
<div class="modal fade" id="leaseModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="leaseId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="leaseTitle"><i class="fas fa-file-contract me-2"></i>New Lease</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Shop <span class="text-danger">*</span></label>
              <select name="shop_id" id="leaseShop" class="form-select" required>
                <option value="">-- Select Shop --</option>
                <?php foreach ($shops as $sh): ?>
                <option value="<?= $sh['id'] ?>"><?= e($sh['shop_no'] . ' – ' . $sh['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Tenant <span class="text-danger">*</span></label>
              <select name="tenant_id" id="leaseTenant" class="form-select" required>
                <option value="">-- Select Tenant --</option>
                <?php foreach ($tenants as $t): ?>
                <option value="<?= $t['id'] ?>"><?= e($t['business_name'] . ' (' . $t['contact_person'] . ')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
              <input type="date" name="start_date" id="leaseStart" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label>
              <input type="date" name="end_date" id="leaseEnd" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Monthly Rent (KES) <span class="text-danger">*</span></label>
              <input type="number" name="monthly_rent" id="leaseRent" class="form-control" min="0" step="0.01" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Security Deposit (KES)</label>
              <input type="number" name="deposit" id="leaseDeposit" class="form-control" min="0" step="0.01" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="leaseStatus" class="form-select">
                <option value="active">Active</option>
                <option value="pending">Pending</option>
                <option value="expired">Expired</option>
                <option value="terminated">Terminated</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Special Terms / Notes</label>
              <textarea name="terms" id="leaseTerms" class="form-control" rows="3" placeholder="Any special conditions, escalation clauses, etc."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Lease</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function openAdd() {
    document.getElementById('leaseTitle').innerHTML = '<i class="fas fa-file-contract me-2"></i>New Lease';
    document.getElementById('leaseId').value = '0';
    ['leaseShop','leaseTenant','leaseStart','leaseEnd','leaseRent','leaseDeposit','leaseTerms'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = (id === 'leaseRent' || id === 'leaseDeposit') ? '0' : '';
    });
    document.getElementById('leaseStatus').value = 'active';
}
function openEdit(l) {
    document.getElementById('leaseTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Lease';
    document.getElementById('leaseId').value      = l.id;
    document.getElementById('leaseShop').value    = l.shop_id;
    document.getElementById('leaseTenant').value  = l.tenant_id;
    document.getElementById('leaseStart').value   = l.start_date;
    document.getElementById('leaseEnd').value     = l.end_date;
    document.getElementById('leaseRent').value    = l.monthly_rent;
    document.getElementById('leaseDeposit').value = l.deposit;
    document.getElementById('leaseStatus').value  = l.status;
    document.getElementById('leaseTerms').value   = l.terms || '';
    new bootstrap.Modal(document.getElementById('leaseModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
