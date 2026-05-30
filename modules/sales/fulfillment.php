<?php
// ── Sales: Order Fulfillment ───────────────────────────────────
$moduleSlug='sales';$moduleName='Sales Management';$moduleIcon='fas fa-chart-line';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'targets.php','icon'=>'fas fa-bullseye','label'=>'Targets'],['url'=>'returns.php','icon'=>'fas fa-undo-alt','label'=>'Returns'],['url'=>'payments.php','icon'=>'fas fa-money-check-alt','label'=>'Payments'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $orderId       = (int)($_POST['order_id']      ?? 0);
        $warehousedBy  = sanitize($_POST['warehoused_by'] ?? '');
        $shippedBy     = sanitize($_POST['shipped_by']    ?? '');
        $trackingNo    = sanitize($_POST['tracking_no']   ?? '');
        $carrier       = sanitize($_POST['carrier']       ?? '');
        $shippedDate   = $_POST['shipped_date']   ?? null;
        $expectedDate  = $_POST['expected_date']  ?? null;
        $notes         = sanitize($_POST['notes'] ?? '');

        if ($orderId <= 0) {
            setFlash('danger', 'Order is required.');
            redirect('fulfillment.php');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO sales_fulfillments (org_id, order_id, warehoused_by, shipped_by, tracking_no, carrier, shipped_date, expected_delivery_date, status, notes)
                VALUES (?,?,?,?,?,?,?,?,'shipped',?)
                ON DUPLICATE KEY UPDATE warehoused_by=VALUES(warehoused_by), shipped_by=VALUES(shipped_by), tracking_no=VALUES(tracking_no), carrier=VALUES(carrier), shipped_date=VALUES(shipped_date), expected_delivery_date=VALUES(expected_delivery_date), notes=VALUES(notes)
            ");
            $stmt->execute([$orgId, $orderId, $warehousedBy, $shippedBy, $trackingNo, $carrier, $shippedDate ?: null, $expectedDate ?: null, $notes]);

            // Update order status to shipped
            $pdo->prepare("UPDATE sales_orders SET status='shipped' WHERE id=? AND org_id=?")->execute([$orderId, $orgId]);

            setFlash('success', "Fulfillment record saved. Order marked as shipped.");
            logActivity('create', 'sales', "Fulfillment updated for order #{$orderId}, tracking: {$trackingNo}");
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('fulfillment.php');
    }

    if ($action === 'mark_delivered') {
        $fid = (int)($_POST['fulfillment_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT order_id FROM sales_fulfillments WHERE id=? AND org_id=?");
            $stmt->execute([$fid, $orgId]);
            $f = $stmt->fetch();
            if ($f) {
                $pdo->prepare("UPDATE sales_fulfillments SET status='delivered', actual_delivery_date=NOW() WHERE id=? AND org_id=?")->execute([$fid, $orgId]);
                $pdo->prepare("UPDATE sales_orders SET status='delivered' WHERE id=? AND org_id=?")->execute([$f['order_id'], $orgId]);
                setFlash('success', 'Order marked as delivered.');
            }
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('fulfillment.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$pendingOrders = $fulfillments = [];
try {
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_no, o.total, c.name AS customer_name
        FROM sales_orders o
        LEFT JOIN sales_customers c ON c.id = o.customer_id
        WHERE o.org_id=? AND o.status IN ('processing','pending')
        ORDER BY o.order_date DESC
    ");
    $stmt->execute([$orgId]); $pendingOrders = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT f.*, o.order_no, c.name AS customer_name, o.total AS order_total
        FROM sales_fulfillments f
        JOIN sales_orders o ON o.id = f.order_id
        LEFT JOIN sales_customers c ON c.id = o.customer_id
        WHERE f.org_id=?
        ORDER BY f.shipped_date DESC, f.created_at DESC
    ");
    $stmt->execute([$orgId]); $fulfillments = $stmt->fetchAll();
} catch (Exception $e) {}

$shipped   = count(array_filter($fulfillments, fn($f) => $f['status'] === 'shipped'));
$delivered = count(array_filter($fulfillments, fn($f) => $f['status'] === 'delivered'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-truck me-2" style="color:<?= $moduleColor ?>"></i>Order Fulfillment</h4>
    <p class="text-muted mb-0">Manage shipping, tracking, and delivery confirmation</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#fulfillModal">
    <i class="fas fa-plus-circle me-1"></i>Create Shipment
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-box"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($pendingOrders) ?></div><div class="stat-label">Pending Shipment</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(26,138,78,0.12);color:#1A8A4E"><i class="fas fa-truck"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $shipped ?></div><div class="stat-label">In Transit</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $delivered ?></div><div class="stat-label">Delivered</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="fulfillTable">
        <thead class="table-light">
          <tr><th>Order</th><th>Customer</th><th>Carrier</th><th>Tracking No.</th><th>Shipped</th><th>Expected</th><th class="text-center">Status</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($fulfillments)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-truck fa-3x mb-3 d-block"></i>No fulfillments recorded.</td></tr>
          <?php else: foreach ($fulfillments as $f): ?>
          <tr>
            <td><span class="badge bg-secondary"><?= e($f['order_no']) ?></span></td>
            <td><?= e($f['customer_name'] ?: '—') ?></td>
            <td><?= e($f['carrier'] ?: '—') ?></td>
            <td><code><?= e($f['tracking_no'] ?: '—') ?></code></td>
            <td><?= $f['shipped_date'] ? formatDate($f['shipped_date']) : '—' ?></td>
            <td><?= $f['expected_delivery_date'] ? formatDate($f['expected_delivery_date']) : '—' ?></td>
            <td class="text-center">
              <?php if ($f['status']==='delivered'): ?>
                <span class="badge bg-success">Delivered</span>
              <?php elseif ($f['status']==='shipped'): ?>
                <span class="badge bg-primary">In Transit</span>
              <?php else: ?>
                <span class="badge bg-secondary"><?= ucfirst($f['status']) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if ($f['status'] === 'shipped'): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mark_delivered">
                <input type="hidden" name="fulfillment_id" value="<?= $f['id'] ?>">
                <button class="btn btn-sm btn-success" type="submit" title="Mark Delivered"><i class="fas fa-check me-1"></i>Delivered</button>
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

<!-- Modal -->
<div class="modal fade" id="fulfillModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-header text-white" style="background:#1A8A4E">
          <h5 class="modal-title"><i class="fas fa-truck me-2"></i>Create Shipment</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Order <span class="text-danger">*</span></label>
              <select name="order_id" class="form-select" required>
                <option value="">-- Select Order --</option>
                <?php foreach ($pendingOrders as $po): ?>
                <option value="<?= $po['id'] ?>"><?= e($po['order_no']) ?> — <?= e($po['customer_name'] ?: 'Walk-in') ?> (<?= formatCurrency((float)$po['total']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Carrier</label>
              <input type="text" name="carrier" class="form-control" placeholder="e.g. DHL, PostBank">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Tracking Number</label>
              <input type="text" name="tracking_no" class="form-control" placeholder="Tracking / waybill no.">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Shipped By</label>
              <input type="text" name="shipped_by" class="form-control" placeholder="Staff name">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Warehoused By</label>
              <input type="text" name="warehoused_by" class="form-control" placeholder="Warehouse staff">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Shipped Date</label>
              <input type="date" name="shipped_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Expected Delivery</label>
              <input type="date" name="expected_date" class="form-control" value="<?= date('Y-m-d', strtotime('+3 days')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Delivery instructions..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:#1A8A4E"><i class="fas fa-save me-1"></i>Save Shipment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#fulfillTable").DataTable({pageLength:15, order:[[4,"desc"]]});
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
