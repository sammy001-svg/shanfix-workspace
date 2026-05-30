<?php
// ── Shopping Mall: Utilities ──────────────────────────────────
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
        $id          = (int)($_POST['id'] ?? 0);
        $shopId      = (int)($_POST['shop_id'] ?? 0);
        $utilityType = sanitize($_POST['utility_type'] ?? '');
        $period      = sanitize($_POST['period'] ?? '');     // e.g. 2025-05
        $prevReading = (float)($_POST['prev_reading'] ?? 0);
        $currReading = (float)($_POST['curr_reading'] ?? 0);
        $unitRate    = (float)($_POST['unit_rate'] ?? 0);
        $fixedCharge = (float)($_POST['fixed_charge'] ?? 0);
        $notes       = sanitize($_POST['notes'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['unpaid','paid','waived']) ? $_POST['status'] : 'unpaid';

        if ($shopId <= 0 || !$utilityType || !$period) {
            setFlash('danger', 'Shop, utility type and billing period are required.');
            redirect('utilities.php');
        }

        $unitsUsed = max(0, $currReading - $prevReading);
        $amount    = round(($unitsUsed * $unitRate) + $fixedCharge, 2);

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE mall_utilities SET shop_id=?, utility_type=?, period=?, prev_reading=?, curr_reading=?, units_used=?, unit_rate=?, fixed_charge=?, amount=?, notes=?, status=?, updated_at=NOW() WHERE id=? AND org_id=?")
                    ->execute([$shopId, $utilityType, $period, $prevReading, $currReading, $unitsUsed, $unitRate, $fixedCharge, $amount, $notes, $status, $id, $orgId]);
                setFlash('success', 'Utility bill updated.');
                logActivity('update', 'shopping-mall', "Utility bill #{$id} updated");
            } else {
                $pdo->prepare("INSERT INTO mall_utilities (org_id, shop_id, utility_type, period, prev_reading, curr_reading, units_used, unit_rate, fixed_charge, amount, notes, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $shopId, $utilityType, $period, $prevReading, $currReading, $unitsUsed, $unitRate, $fixedCharge, $amount, $notes, $status]);
                setFlash('success', 'Utility bill recorded. Amount: ' . formatCurrency($amount));
                logActivity('create', 'shopping-mall', "Utility bill for shop #{$shopId} period {$period}");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('utilities.php');
    }

    if ($action === 'mark_paid') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE mall_utilities SET status='paid', updated_at=NOW() WHERE id=? AND org_id=?")
            ->execute([$id, $orgId]);
        setFlash('success', 'Utility bill marked as paid.');
        redirect('utilities.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fPeriod = $_GET['period'] ?? date('Y-m');
$fType   = $_GET['type']   ?? '';

$where  = 'u.org_id = ?';
$params = [$orgId];
if ($fPeriod !== '') { $where .= ' AND u.period = ?'; $params[] = $fPeriod; }
if ($fType   !== '') { $where .= ' AND u.utility_type = ?'; $params[] = $fType; }

$bills = [];
$shops = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.*, s.shop_no, s.name AS shop_name
        FROM mall_utilities u
        JOIN mall_shops s ON s.id = u.shop_id
        WHERE {$where}
        ORDER BY u.period DESC, s.shop_no ASC
    ");
    $stmt->execute($params);
    $bills = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, shop_no, name FROM mall_shops WHERE org_id=? ORDER BY shop_no");
    $stmt->execute([$orgId]);
    $shops = $stmt->fetchAll();
} catch (Exception $e) {}

$totalBilled  = array_sum(array_column($bills, 'amount'));
$totalUnpaid  = array_sum(array_column(array_filter($bills, fn($b) => $b['status'] === 'unpaid'), 'amount'));
$billCount    = count($bills);
$unpaidCount  = count(array_filter($bills, fn($b) => $b['status'] === 'unpaid'));

$utilityTypes = ['Electricity', 'Water', 'Gas', 'Internet', 'Waste Management', 'Common Area Charge', 'Other'];
$statusColors = ['unpaid' => 'danger', 'paid' => 'success', 'waived' => 'secondary'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bolt me-2" style="color:<?= $moduleColor ?>"></i>Utility Billing</h4>
    <p class="text-muted mb-0">Record meter readings, compute bills and track utility payments by shop</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#utilModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Add Bill
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $billCount ?></div><div class="stat-label">Bills This Period</div></div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-bolt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalBilled) ?></div><div class="stat-label">Total Billed</div></div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(231,76,60,.12);color:#e74c3c"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $unpaidCount ?></div><div class="stat-label">Unpaid Bills</div></div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalUnpaid) ?></div><div class="stat-label">Amount Outstanding</div></div>
    </div>
  </div>
</div>

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Billing Period</label>
        <input type="month" name="period" class="form-control form-control-sm" value="<?= e($fPeriod) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Utility Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <?php foreach ($utilityTypes as $ut): ?>
          <option value="<?= $ut ?>" <?= $fType === $ut ? 'selected' : '' ?>><?= $ut ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="utilities.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 data-table">
        <thead class="table-light">
          <tr>
            <th>Shop</th><th>Utility</th><th>Period</th>
            <th class="text-end">Prev Reading</th><th class="text-end">Curr Reading</th>
            <th class="text-end">Units</th><th class="text-end">Rate</th>
            <th class="text-end">Amount</th><th class="text-center">Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($bills)): ?>
          <tr><td colspan="10" class="text-center py-5 text-muted"><i class="fas fa-bolt fa-3x mb-3 d-block"></i>No utility bills found for this period.</td></tr>
          <?php else: foreach ($bills as $b): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($b['shop_name']) ?></div>
              <div class="small text-muted"><?= e($b['shop_no']) ?></div>
            </td>
            <td><span class="badge bg-light text-dark border"><i class="fas fa-<?= $b['utility_type'] === 'Water' ? 'tint' : ($b['utility_type'] === 'Gas' ? 'fire' : 'bolt') ?> me-1"></i><?= e($b['utility_type']) ?></span></td>
            <td class="small"><?= e($b['period']) ?></td>
            <td class="text-end small"><?= number_format((float)$b['prev_reading'], 2) ?></td>
            <td class="text-end small"><?= number_format((float)$b['curr_reading'], 2) ?></td>
            <td class="text-end fw-semibold"><?= number_format((float)$b['units_used'], 2) ?></td>
            <td class="text-end small text-muted"><?= number_format((float)$b['unit_rate'], 2) ?></td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$b['amount']) ?></td>
            <td class="text-center"><span class="badge bg-<?= $statusColors[$b['status']] ?? 'secondary' ?>"><?= ucfirst($b['status']) ?></span></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($b), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <?php if ($b['status'] === 'unpaid'): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?= $b['id'] ?>">
                <button class="btn btn-sm btn-outline-success ms-1" title="Mark Paid"><i class="fas fa-check"></i></button>
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

<!-- Utility Modal -->
<div class="modal fade" id="utilModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="utilId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="utilTitle"><i class="fas fa-bolt me-2"></i>Add Utility Bill</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Shop <span class="text-danger">*</span></label>
              <select name="shop_id" id="utilShop" class="form-select" required>
                <option value="">-- Select Shop --</option>
                <?php foreach ($shops as $sh): ?>
                <option value="<?= $sh['id'] ?>"><?= e($sh['shop_no'] . ' – ' . $sh['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Utility Type <span class="text-danger">*</span></label>
              <select name="utility_type" id="utilType" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($utilityTypes as $ut): ?>
                <option value="<?= $ut ?>"><?= $ut ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Billing Period <span class="text-danger">*</span></label>
              <input type="month" name="period" id="utilPeriod" class="form-control" value="<?= date('Y-m') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Previous Reading</label>
              <input type="number" name="prev_reading" id="utilPrev" class="form-control" min="0" step="0.01" value="0" oninput="calcUtil()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Current Reading</label>
              <input type="number" name="curr_reading" id="utilCurr" class="form-control" min="0" step="0.01" value="0" oninput="calcUtil()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Unit Rate (KES)</label>
              <input type="number" name="unit_rate" id="utilRate" class="form-control" min="0" step="0.01" value="0" oninput="calcUtil()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Fixed Charge (KES)</label>
              <input type="number" name="fixed_charge" id="utilFixed" class="form-control" min="0" step="0.01" value="0" oninput="calcUtil()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Computed Amount</label>
              <div class="form-control bg-light fw-bold text-success" id="utilAmountDisplay">KES 0.00</div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="utilStatus" class="form-select">
                <option value="unpaid">Unpaid</option>
                <option value="paid">Paid</option>
                <option value="waived">Waived</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Notes</label>
              <input type="text" name="notes" id="utilNotes" class="form-control" placeholder="Optional notes">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Bill</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function calcUtil() {
    const prev  = parseFloat(document.getElementById('utilPrev').value)  || 0;
    const curr  = parseFloat(document.getElementById('utilCurr').value)  || 0;
    const rate  = parseFloat(document.getElementById('utilRate').value)  || 0;
    const fixed = parseFloat(document.getElementById('utilFixed').value) || 0;
    const units = Math.max(0, curr - prev);
    const amt   = (units * rate) + fixed;
    document.getElementById('utilAmountDisplay').textContent = 'KES ' + amt.toFixed(2);
}
function openAdd() {
    document.getElementById('utilTitle').innerHTML = '<i class="fas fa-bolt me-2"></i>Add Utility Bill';
    document.getElementById('utilId').value      = '0';
    document.getElementById('utilShop').value    = '';
    document.getElementById('utilType').value    = '';
    document.getElementById('utilPeriod').value  = new Date().toISOString().slice(0,7);
    document.getElementById('utilPrev').value    = '0';
    document.getElementById('utilCurr').value    = '0';
    document.getElementById('utilRate').value    = '0';
    document.getElementById('utilFixed').value   = '0';
    document.getElementById('utilStatus').value  = 'unpaid';
    document.getElementById('utilNotes').value   = '';
    document.getElementById('utilAmountDisplay').textContent = 'KES 0.00';
}
function openEdit(b) {
    document.getElementById('utilTitle').innerHTML  = '<i class="fas fa-edit me-2"></i>Edit Utility Bill';
    document.getElementById('utilId').value         = b.id;
    document.getElementById('utilShop').value       = b.shop_id;
    document.getElementById('utilType').value       = b.utility_type;
    document.getElementById('utilPeriod').value     = b.period;
    document.getElementById('utilPrev').value       = b.prev_reading;
    document.getElementById('utilCurr').value       = b.curr_reading;
    document.getElementById('utilRate').value       = b.unit_rate;
    document.getElementById('utilFixed').value      = b.fixed_charge;
    document.getElementById('utilStatus').value     = b.status;
    document.getElementById('utilNotes').value      = b.notes || '';
    document.getElementById('utilAmountDisplay').textContent = 'KES ' + parseFloat(b.amount).toFixed(2);
    new bootstrap.Modal(document.getElementById('utilModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
