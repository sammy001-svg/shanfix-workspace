<?php
// ── Sales: Sales Commissions ───────────────────────────────────
$moduleSlug='sales';$moduleName='Sales Management';$moduleIcon='fas fa-chart-line';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'targets.php','icon'=>'fas fa-bullseye','label'=>'Targets'],['url'=>'returns.php','icon'=>'fas fa-undo-alt','label'=>'Returns'],['url'=>'payments.php','icon'=>'fas fa-money-check-alt','label'=>'Payments'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $orderId        = (int)($_POST['order_id']        ?? 0) ?: null;
        $agentName      = sanitize($_POST['agent_name']    ?? '');
        $saleAmount     = (float)($_POST['sale_amount']    ?? 0);
        $commissionRate = (float)($_POST['commission_rate']?? 0);
        $commissionAmt  = $saleAmount * ($commissionRate / 100);
        $periodLabel    = sanitize($_POST['period_label']  ?? date('F Y'));
        $notes          = sanitize($_POST['notes']         ?? '');

        if (empty($agentName) || $saleAmount <= 0) {
            setFlash('danger', 'Agent name and sale amount are required.');
            redirect('commissions.php');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO sales_commissions (org_id, order_id, agent_name, sale_amount, commission_rate, commission_amount, period_label, status, notes)
                VALUES (?,?,?,?,?,?,'pending',?,?)
            ");
            // Fix: properly ordered values
            $pdo->prepare("
                INSERT INTO sales_commissions (org_id, order_id, agent_name, sale_amount, commission_rate, commission_amount, period_label, status, notes)
                VALUES (?,?,?,?,?,?,?,'pending',?)
            ")->execute([$orgId, $orderId, $agentName, $saleAmount, $commissionRate, $commissionAmt, $periodLabel, $notes]);
            setFlash('success', "Commission of " . formatCurrency($commissionAmt) . " recorded for {$agentName}.");
            logActivity('create', 'sales', "Commission recorded: {$agentName} {$commissionRate}% on " . formatCurrency($saleAmount));
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('commissions.php');
    }

    if ($action === 'pay_commission') {
        $cid = (int)($_POST['commission_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE sales_commissions SET status='paid', paid_at=NOW() WHERE id=? AND org_id=? AND status='pending'")->execute([$cid, $orgId]);
            setFlash('success', 'Commission marked as paid.');
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('commissions.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$orders = $commissions = [];
try {
    $stmt = $pdo->prepare("SELECT id, order_no, total FROM sales_orders WHERE org_id=? AND status='delivered' ORDER BY order_no DESC LIMIT 100");
    $stmt->execute([$orgId]); $orders = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM sales_commissions WHERE org_id=? ORDER BY created_at DESC");
    $stmt->execute([$orgId]); $commissions = $stmt->fetchAll();
} catch (Exception $e) {}

$totalPending = array_sum(array_column(array_filter($commissions, fn($c) => $c['status']==='pending'), 'commission_amount'));
$totalPaid    = array_sum(array_column(array_filter($commissions, fn($c) => $c['status']==='paid'), 'commission_amount'));

// Group by agent
$agentSummary = [];
foreach ($commissions as $c) {
    $ag = $c['agent_name'];
    if (!isset($agentSummary[$ag])) $agentSummary[$ag] = ['pending' => 0, 'paid' => 0, 'total' => 0];
    $agentSummary[$ag][$c['status']] = ($agentSummary[$ag][$c['status']] ?? 0) + (float)$c['commission_amount'];
    $agentSummary[$ag]['total'] += (float)$c['commission_amount'];
}
arsort($agentSummary);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-percent me-2" style="color:<?= $moduleColor ?>"></i>Sales Commissions</h4>
    <p class="text-muted mb-0">Track and pay sales agent commissions per period</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#commModal">
    <i class="fas fa-plus-circle me-1"></i>Record Commission
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPending) ?></div><div class="stat-label">Pending Commissions</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-hand-holding-usd"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPaid) ?></div><div class="stat-label">Paid Out</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(26,138,78,0.12);color:#1A8A4E"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($agentSummary) ?></div><div class="stat-label">Sales Agents</div></div>
    </div>
  </div>
</div>

<!-- Agent Summary -->
<?php if (!empty($agentSummary)): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white py-3">
    <h6 class="mb-0 fw-semibold"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Agent Leaderboard</h6>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th class="ps-3">Agent</th><th class="text-end">Pending</th><th class="text-end">Paid</th><th class="text-end pe-3">Total Earned</th></tr></thead>
      <tbody>
        <?php foreach ($agentSummary as $agent => $sums): ?>
        <tr>
          <td class="ps-3 fw-semibold"><?= e($agent) ?></td>
          <td class="text-end text-warning fw-semibold"><?= formatCurrency($sums['pending']) ?></td>
          <td class="text-end text-success"><?= formatCurrency($sums['paid']) ?></td>
          <td class="text-end pe-3 fw-bold"><?= formatCurrency($sums['total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Commission Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="commTable">
        <thead class="table-light">
          <tr><th>Period</th><th>Agent</th><th>Linked Order</th><th class="text-end">Sale Amount</th><th class="text-center">Rate</th><th class="text-end">Commission</th><th class="text-center">Status</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($commissions)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-percent fa-3x mb-3 d-block"></i>No commissions recorded.</td></tr>
          <?php else: foreach ($commissions as $c): ?>
          <tr>
            <td><?= e($c['period_label']) ?></td>
            <td class="fw-semibold"><?= e($c['agent_name']) ?></td>
            <td><?= $c['order_id'] ? '<span class="badge bg-secondary">Order #'.(int)$c['order_id'].'</span>' : '—' ?></td>
            <td class="text-end"><?= formatCurrency((float)$c['sale_amount']) ?></td>
            <td class="text-center"><?= number_format((float)$c['commission_rate'], 1) ?>%</td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$c['commission_amount']) ?></td>
            <td class="text-center">
              <span class="badge <?= $c['status']==='paid' ? 'bg-success' : 'bg-warning text-dark' ?>">
                <?= ucfirst($c['status']) ?>
              </span>
            </td>
            <td class="text-end">
              <?php if ($c['status'] === 'pending'): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Mark this commission as paid?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="pay_commission">
                <input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-success" type="submit"><i class="fas fa-check me-1"></i>Pay</button>
              </form>
              <?php else: ?>
              <small class="text-muted"><?= $c['paid_at'] ? formatDate($c['paid_at']) : '' ?></small>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Commission Modal -->
<div class="modal fade" id="commModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-header text-white" style="background:#1A8A4E">
          <h5 class="modal-title"><i class="fas fa-percent me-2"></i>Record Commission</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Agent Name <span class="text-danger">*</span></label>
              <input type="text" name="agent_name" class="form-control" required placeholder="Sales agent name">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Period</label>
              <input type="text" name="period_label" class="form-control" value="<?= date('F Y') ?>" placeholder="e.g. May 2026">
            </div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">Linked Order (Optional)</label>
              <select name="order_id" class="form-select" onchange="autoFillSale(this)">
                <option value="">— None —</option>
                <?php foreach ($orders as $o): ?>
                <option value="<?= $o['id'] ?>" data-total="<?= (float)$o['total'] ?>"><?= e($o['order_no']) ?> (<?= formatCurrency((float)$o['total']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Sale Amount (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="sale_amount" id="saleAmt" class="form-control" required min="0.01" onchange="calcComm()">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Commission Rate (%) <span class="text-danger">*</span></label>
              <input type="number" step="0.1" name="commission_rate" id="commRate" class="form-control" required min="0.1" max="100" value="5" onchange="calcComm()">
            </div>
            <div class="col-12">
              <div class="alert alert-success py-2 mb-0">
                Commission Amount: <strong id="commPreview">KES 0.00</strong>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:#1A8A4E"><i class="fas fa-save me-1"></i>Save Commission</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#commTable").DataTable({pageLength:15, order:[[0,"desc"]]});
});
function autoFillSale(sel) {
    var opt = $(sel).find("option:selected");
    var total = parseFloat(opt.data("total")) || 0;
    if (total > 0) { $("#saleAmt").val(total.toFixed(2)); calcComm(); }
}
function calcComm() {
    var sale = parseFloat($("#saleAmt").val()) || 0;
    var rate = parseFloat($("#commRate").val()) || 0;
    var comm = sale * rate / 100;
    $("#commPreview").text("KES " + comm.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, "$&,"));
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
