<?php
// ── Manufacturing: Quality Control ─────────────────────────────
$moduleSlug  = 'manufacturing';
$moduleName  = 'Manufacturing';
$moduleIcon  = 'fas fa-industry';
$moduleColor = '#d35400';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'products.php',    'icon' => 'fas fa-box',            'label' => 'Products'],
    ['url' => 'materials.php',   'icon' => 'fas fa-cubes',          'label' => 'Raw Materials'],
    ['url' => 'bom.php',         'icon' => 'fas fa-list-alt',       'label' => 'Bill of Materials'],
    ['url' => 'production.php',  'icon' => 'fas fa-industry',       'label' => 'Production Orders'],
    ['url' => 'workorders.php',  'icon' => 'fas fa-clipboard-list', 'label' => 'Work Orders'],
    ['url' => 'machines.php',    'icon' => 'fas fa-cogs',           'label' => 'Machines'],
    ['url' => 'quality.php',     'icon' => 'fas fa-check-circle',   'label' => 'Quality Control'],
    ['url' => 'suppliers.php',   'icon' => 'fas fa-truck',           'label' => 'Suppliers'],
    ['url' => 'inventory.php',   'icon' => 'fas fa-warehouse',       'label' => 'Inventory'],
    ['url' => 'procurement.php', 'icon' => 'fas fa-shopping-basket', 'label' => 'Procurement'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
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
        $workOrderId     = (int)($_POST['work_order_id'] ?? 0) ?: null;
        $productId       = (int)($_POST['product_id']    ?? 0);
        $checkType       = sanitize($_POST['check_type'] ?? 'final');
        $batchNo         = sanitize($_POST['batch_no']   ?? '');
        $qtyChecked      = (int)($_POST['qty_checked']   ?? 0);
        $qtyPassed       = (int)($_POST['qty_passed']    ?? 0);
        $qtyFailed       = $qtyChecked - $qtyPassed;
        $verdict         = sanitize($_POST['verdict']    ?? 'pass');
        $checkedBy       = sanitize($_POST['checked_by'] ?? '');
        $notes           = sanitize($_POST['notes']      ?? '');

        if ($productId <= 0 || $qtyChecked <= 0) {
            setFlash('danger', 'Product and quantity checked are required.');
            redirect('quality.php');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO mfg_quality_checks (org_id, work_order_id, product_id, check_type, batch_no, quantity_checked, quantity_passed, quantity_failed, verdict, checked_by, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $workOrderId, $productId, $checkType, $batchNo, $qtyChecked, $qtyPassed, $qtyFailed, $verdict, $checkedBy, $notes]);
            setFlash('success', "Quality check recorded. Verdict: " . ucfirst(str_replace('_',' ', $verdict)));
            logActivity('create', 'manufacturing', "Quality check: batch {$batchNo}, product #{$productId}, verdict: {$verdict}");
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('quality.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$products   = [];
$workOrders = [];
try {
    $stmt = $pdo->prepare("SELECT id, product_name, sku FROM mfg_products WHERE org_id=? ORDER BY product_name");
    $stmt->execute([$orgId]); $products = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, wo_no FROM mfg_work_orders WHERE org_id=? AND status IN ('in_progress','completed') ORDER BY wo_no DESC LIMIT 50");
    $stmt->execute([$orgId]); $workOrders = $stmt->fetchAll();
} catch (Exception $e) {}

$checks = [];
try {
    $stmt = $pdo->prepare("
        SELECT q.*, p.product_name, p.sku, w.wo_no
        FROM mfg_quality_checks q
        LEFT JOIN mfg_products p ON p.id = q.product_id
        LEFT JOIN mfg_work_orders w ON w.id = q.work_order_id
        WHERE q.org_id = ?
        ORDER BY q.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $checks = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats
$totalChecked = array_sum(array_column($checks, 'quantity_checked'));
$totalPassed  = array_sum(array_column($checks, 'quantity_passed'));
$totalFailed  = array_sum(array_column($checks, 'quantity_failed'));
$passRate     = $totalChecked > 0 ? round($totalPassed / $totalChecked * 100, 1) : 0;
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-check-circle me-2" style="color:<?= $moduleColor ?>"></i>Quality Control</h4>
    <p class="text-muted mb-0">Record inspection results, batch verdicts, and track defect rates</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#qcModal">
    <i class="fas fa-plus-circle me-1"></i>Record Inspection
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(211,84,0,0.12);color:#d35400"><i class="fas fa-boxes"></i></div>
      <div class="stat-body"><div class="stat-value"><?= number_format($totalChecked) ?></div><div class="stat-label">Units Inspected</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-thumbs-up"></i></div>
      <div class="stat-body"><div class="stat-value"><?= number_format($totalPassed) ?></div><div class="stat-label">Units Passed</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-thumbs-down"></i></div>
      <div class="stat-body"><div class="stat-value"><?= number_format($totalFailed) ?></div><div class="stat-label">Units Failed</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $passRate >= 95 ? 'green-bg' : ($passRate >= 80 ? 'warning-bg' : 'danger-bg') ?>"><i class="fas fa-percentage"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $passRate ?>%</div><div class="stat-label">Overall Pass Rate</div></div>
    </div>
  </div>
</div>

<!-- QC Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="qcTable">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Product</th>
            <th>Work Order</th>
            <th>Batch No.</th>
            <th>Check Type</th>
            <th class="text-center">Checked</th>
            <th class="text-center">Passed</th>
            <th class="text-center">Failed</th>
            <th class="text-center">Pass Rate</th>
            <th class="text-center">Verdict</th>
            <th>Inspector</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($checks)): ?>
          <tr><td colspan="11" class="text-center py-5 text-muted"><i class="fas fa-check-circle fa-3x mb-3 d-block"></i>No quality checks recorded.</td></tr>
          <?php else: foreach ($checks as $c):
            $pr = (int)$c['quantity_checked'] > 0 ? round((int)$c['quantity_passed'] / (int)$c['quantity_checked'] * 100, 1) : 0;
            $verdictBadge = match($c['verdict']) {
              'pass'              => 'bg-success',
              'fail'              => 'bg-danger',
              'conditional_pass'  => 'bg-warning text-dark',
              'rework'            => 'bg-info text-dark',
              default             => 'bg-secondary',
            };
          ?>
          <tr>
            <td><?= formatDate($c['created_at']) ?></td>
            <td>
              <div class="fw-semibold"><?= e($c['product_name']) ?></div>
              <div class="small text-muted"><?= e($c['sku'] ?: '') ?></div>
            </td>
            <td><?= $c['wo_no'] ? '<span class="badge bg-dark">'.e($c['wo_no']).'</span>' : '—' ?></td>
            <td><code><?= e($c['batch_no'] ?: '—') ?></code></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst($c['check_type']) ?></span></td>
            <td class="text-center fw-bold"><?= (int)$c['quantity_checked'] ?></td>
            <td class="text-center text-success fw-semibold"><?= (int)$c['quantity_passed'] ?></td>
            <td class="text-center text-danger fw-semibold"><?= (int)$c['quantity_failed'] ?></td>
            <td class="text-center">
              <div class="progress" style="height:8px;min-width:60px">
                <div class="progress-bar <?= $pr >= 95 ? 'bg-success' : ($pr >= 80 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= $pr ?>%"></div>
              </div>
              <small class="text-muted"><?= $pr ?>%</small>
            </td>
            <td class="text-center"><span class="badge <?= $verdictBadge ?>"><?= ucfirst(str_replace('_',' ',$c['verdict'])) ?></span></td>
            <td class="small"><?= e($c['checked_by'] ?: '—') ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- QC Modal -->
<div class="modal fade" id="qcModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Record Quality Inspection</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
              <select name="product_id" class="form-select" required>
                <option value="">-- Select Product --</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['product_name']) ?> <?= $p['sku'] ? '('.$p['sku'].')' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Work Order (Optional)</label>
              <select name="work_order_id" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($workOrders as $wo): ?>
                <option value="<?= $wo['id'] ?>"><?= e($wo['wo_no']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Batch / Lot Number</label>
              <input type="text" name="batch_no" class="form-control" placeholder="e.g. BATCH-2026-001">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Check Type</label>
              <select name="check_type" class="form-select">
                <option value="incoming">Incoming (Raw Material)</option>
                <option value="in_process">In-Process</option>
                <option value="final" selected>Final Inspection</option>
                <option value="outgoing">Outgoing</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Inspector Name</label>
              <input type="text" name="checked_by" class="form-control" placeholder="Inspector">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Quantity Checked <span class="text-danger">*</span></label>
              <input type="number" name="qty_checked" id="qtyChecked" class="form-control" required min="1" onchange="autoCalc()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Quantity Passed <span class="text-danger">*</span></label>
              <input type="number" name="qty_passed" id="qtyPassed" class="form-control" required min="0" onchange="autoCalc()">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Verdict</label>
              <select name="verdict" id="verdictSel" class="form-select">
                <option value="pass">Pass</option>
                <option value="fail">Fail</option>
                <option value="conditional_pass">Conditional Pass</option>
                <option value="rework">Rework Required</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes / Defect Description</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Observations, defect types, corrective actions..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Inspection</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#qcTable").DataTable({pageLength:15, order:[[0,"desc"]]});
});
function autoCalc() {
    var checked = parseInt($("#qtyChecked").val()) || 0;
    var passed  = parseInt($("#qtyPassed").val()) || 0;
    var failed  = checked - passed;
    if (failed < 0) { $("#qtyPassed").val(checked); failed = 0; }
    if (failed === 0 && checked > 0) $("#verdictSel").val("pass");
    else if (failed > 0 && passed === 0) $("#verdictSel").val("fail");
    else if (failed > 0 && passed > 0) $("#verdictSel").val("conditional_pass");
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
