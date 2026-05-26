<?php
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
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity  = (int)($_POST['quantity'] ?? 0);
        $startDate = sanitize($_POST['start_date'] ?? '');
        $endDate   = sanitize($_POST['end_date'] ?? '');
        $status    = in_array($_POST['status'] ?? '', ['planned','in_progress','completed','cancelled']) ? $_POST['status'] : 'planned';
        $notes     = sanitize($_POST['notes'] ?? '');

        if ($productId <= 0 || $quantity <= 0) {
            setFlash('danger', 'Product and quantity are required.');
            redirect('production.php');
        }

        // Verify product belongs to org
        $chk = $pdo->prepare("SELECT id FROM mfg_products WHERE id=? AND org_id=?");
        $chk->execute([$productId, $orgId]);
        if (!$chk->fetch()) {
            setFlash('danger', 'Invalid product.');
            redirect('production.php');
        }

        if ($id > 0) {
            // Get old status before update
            $old = $pdo->prepare("SELECT status, product_id, quantity FROM mfg_production_orders WHERE id=? AND org_id=?");
            $old->execute([$id, $orgId]);
            $oldOrder = $old->fetch();
            $oldStatus = $oldOrder['status'] ?? '';

            $pdo->prepare("UPDATE mfg_production_orders SET product_id=?, quantity=?, start_date=?, end_date=?, status=?, notes=? WHERE id=? AND org_id=?")
                ->execute([$productId, $quantity, $startDate ?: null, $endDate ?: null, $status, $notes, $id, $orgId]);

            // If status changed to completed
            if ($status === 'completed' && $oldStatus !== 'completed') {
                // Deduct raw materials based on BOM
                $bomLines = $pdo->prepare("SELECT * FROM mfg_bom WHERE product_id=? AND org_id=?");
                $bomLines->execute([$productId, $orgId]);
                foreach ($bomLines->fetchAll() as $bl) {
                    $consume = (float)$bl['quantity_needed'] * $quantity;
                    $pdo->prepare("UPDATE mfg_raw_materials SET stock = GREATEST(0, stock - ?) WHERE id=? AND org_id=?")
                        ->execute([$consume, (int)$bl['material_id'], $orgId]);
                }
                // Add to product stock
                $pdo->prepare("UPDATE mfg_products SET stock = stock + ? WHERE id=? AND org_id=?")
                    ->execute([$quantity, $productId, $orgId]);
            }

            // If status changed away from completed (reverse)
            if ($oldStatus === 'completed' && $status !== 'completed') {
                $oldQty     = (int)$oldOrder['quantity'];
                $oldProduct = (int)$oldOrder['product_id'];
                $bomLines   = $pdo->prepare("SELECT * FROM mfg_bom WHERE product_id=? AND org_id=?");
                $bomLines->execute([$oldProduct, $orgId]);
                foreach ($bomLines->fetchAll() as $bl) {
                    $restore = (float)$bl['quantity_needed'] * $oldQty;
                    $pdo->prepare("UPDATE mfg_raw_materials SET stock = stock + ? WHERE id=? AND org_id=?")
                        ->execute([$restore, (int)$bl['material_id'], $orgId]);
                }
                $pdo->prepare("UPDATE mfg_products SET stock = GREATEST(0, stock - ?) WHERE id=? AND org_id=?")
                    ->execute([$oldQty, $oldProduct, $orgId]);
            }

            setFlash('success', 'Production order updated.');
            logActivity('update', 'manufacturing', "Updated production order #$id to $status");
        } else {
            // Auto order_no: PRD-YYYYMMDD-NNN
            $dateStr = date('Ymd');
            $count   = $pdo->prepare("SELECT COUNT(*) FROM mfg_production_orders WHERE org_id=? AND DATE(created_at) = CURDATE()");
            $count->execute([$orgId]);
            $seq     = (int)$count->fetchColumn() + 1;
            $orderNo = 'PRD-' . $dateStr . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO mfg_production_orders (org_id, order_no, product_id, quantity, start_date, end_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $orderNo, $productId, $quantity, $startDate ?: null, $endDate ?: null, $status, $notes, $user['id']]);
            setFlash('success', "Production order $orderNo created.");
            logActivity('create', 'manufacturing', "Created production order $orderNo");
        }
        redirect('production.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Check if completed (warn about irreversible stock effects)
        $ord = $pdo->prepare("SELECT status FROM mfg_production_orders WHERE id=? AND org_id=?");
        $ord->execute([$id, $orgId]);
        $ordRow = $ord->fetch();
        if ($ordRow && $ordRow['status'] === 'completed') {
            setFlash('danger', 'Cannot delete a completed order. Set status to cancelled first if needed.');
        } else {
            $pdo->prepare("DELETE FROM mfg_production_orders WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Production order deleted.');
            logActivity('delete', 'manufacturing', "Deleted production order #$id");
        }
        redirect('production.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$orders = [];
try {
    $stmt = $pdo->prepare("
        SELECT o.*, p.name AS product_name, p.code AS product_code
        FROM mfg_production_orders o
        LEFT JOIN mfg_products p ON o.product_id = p.id
        WHERE o.org_id = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $orders = $stmt->fetchAll();
} catch (Exception $e) {}

// Stat Cards
$totalOrders   = count($orders);
$inProgress    = count(array_filter($orders, fn($o) => $o['status'] === 'in_progress'));
$thisMonth     = date('Y-m');
$completedMonth = count(array_filter($orders, fn($o) => $o['status'] === 'completed' && str_starts_with($o['created_at'] ?? '', $thisMonth)));
$planned       = count(array_filter($orders, fn($o) => $o['status'] === 'planned'));

// Products for dropdown
$products = [];
try {
    $stmt = $pdo->prepare("SELECT id, code, name FROM mfg_products WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]);
    $products = $stmt->fetchAll();
} catch (Exception $e) {}

$statusConfig = [
    'planned'     => ['badge' => 'info',    'icon' => 'fa-clock',        'label' => 'Planned'],
    'in_progress' => ['badge' => 'warning', 'icon' => 'fa-cogs',         'label' => 'In Progress'],
    'completed'   => ['badge' => 'success', 'icon' => 'fa-check-circle', 'label' => 'Completed'],
    'cancelled'   => ['badge' => 'danger',  'icon' => 'fa-times-circle', 'label' => 'Cancelled'],
];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-industry me-2" style="color:<?= $moduleColor ?>"></i>Production Orders</h4>
    <p class="text-muted mb-0">Plan, track, and complete manufacturing runs</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#orderModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>New Order
  </button>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-industry"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalOrders ?></div><div class="stat-label">Total Orders</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-cogs"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $inProgress ?></div><div class="stat-label">In Progress</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $completedMonth ?></div><div class="stat-label">Completed This Month</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $planned ?></div><div class="stat-label">Planned</div></div>
    </div>
  </div>
</div>

<!-- Orders Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-industry me-2" style="color:<?= $moduleColor ?>"></i>Production Orders</h6>
    <span class="badge bg-secondary"><?= $totalOrders ?> orders</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Order No</th>
            <th>Product</th>
            <th class="text-end">Qty</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Status</th>
            <th>Created</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No production orders found.</td></tr>
          <?php else: foreach ($orders as $o):
            $sc = $statusConfig[$o['status']] ?? ['badge' => 'secondary', 'icon' => 'fa-circle', 'label' => ucfirst($o['status'])];
          ?>
          <tr>
            <td class="fw-semibold" style="color:<?= $moduleColor ?>"><?= e($o['order_no'] ?? '#'.$o['id']) ?></td>
            <td>
              <div class="fw-semibold"><?= e($o['product_name'] ?? '—') ?></div>
              <div class="small text-muted"><?= e($o['product_code'] ?? '') ?></div>
            </td>
            <td class="text-end fw-semibold"><?= number_format((int)$o['quantity']) ?></td>
            <td><?= $o['start_date'] ? formatDate($o['start_date']) : '—' ?></td>
            <td><?= $o['end_date'] ? formatDate($o['end_date']) : '—' ?></td>
            <td><span class="badge bg-<?= $sc['badge'] ?>"><i class="fas <?= $sc['icon'] ?> me-1"></i><?= $sc['label'] ?></span></td>
            <td class="small text-muted"><?= formatDate($o['created_at'] ?? '') ?></td>
            <td class="text-center text-nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>)' title="Edit/Update Status"><i class="fas fa-edit"></i></button>
              <?php if ($o['status'] !== 'completed'): ?>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delOrder(<?= $o['id'] ?>, '<?= e($o['order_no'] ?? '#'.$o['id']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Status Flow Info -->
<div class="card mt-3">
  <div class="card-body py-2">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <span class="small fw-semibold text-muted">Status Flow:</span>
      <span class="badge bg-info">Planned</span>
      <i class="fas fa-arrow-right text-muted small"></i>
      <span class="badge bg-warning text-dark">In Progress</span>
      <i class="fas fa-arrow-right text-muted small"></i>
      <span class="badge bg-success">Completed</span>
      <span class="text-muted small">(auto-deducts materials &amp; adds to stock)</span>
      <span class="text-muted">|</span>
      <span class="badge bg-danger">Cancelled</span>
      <span class="text-muted small">(at any stage)</span>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="orderModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="orderId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="orderModalTitle"><i class="fas fa-industry me-2"></i>New Production Order</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info py-2 mb-3 small" id="completionNote" style="display:none">
            <i class="fas fa-info-circle me-1"></i>
            Setting status to <strong>Completed</strong> will automatically deduct BOM materials from stock and add finished goods to product inventory.
          </div>
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
              <select name="product_id" id="orderProduct" class="form-select" required>
                <option value="">-- Select Product --</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['code'] ?? '') ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Quantity <span class="text-danger">*</span></label>
              <input type="number" name="quantity" id="orderQty" class="form-control" placeholder="0" min="1" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Start Date</label>
              <input type="date" name="start_date" id="orderStart" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">End Date</label>
              <input type="date" name="end_date" id="orderEnd" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="orderStatus" class="form-select" onchange="checkCompletion()">
                <option value="planned">Planned</option>
                <option value="in_progress">In Progress</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="orderNotes" class="form-control" rows="2" placeholder="Optional production notes"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Order</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function checkCompletion() {
  var status = document.getElementById('orderStatus').value;
  document.getElementById('completionNote').style.display = status === 'completed' ? '' : 'none';
}
function openAdd() {
  document.getElementById('orderModalTitle').innerHTML = '<i class="fas fa-industry me-2"></i>New Production Order';
  document.getElementById('orderId').value      = '0';
  document.getElementById('orderProduct').value = '';
  document.getElementById('orderQty').value     = '';
  document.getElementById('orderStart').value   = new Date().toISOString().split('T')[0];
  document.getElementById('orderEnd').value     = '';
  document.getElementById('orderStatus').value  = 'planned';
  document.getElementById('orderNotes').value   = '';
  document.getElementById('completionNote').style.display = 'none';
}
function openEdit(o) {
  document.getElementById('orderModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Production Order';
  document.getElementById('orderId').value      = o.id;
  document.getElementById('orderProduct').value = o.product_id || '';
  document.getElementById('orderQty').value     = o.quantity || '';
  document.getElementById('orderStart').value   = o.start_date || '';
  document.getElementById('orderEnd').value     = o.end_date || '';
  document.getElementById('orderStatus').value  = o.status || 'planned';
  document.getElementById('orderNotes').value   = o.notes || '';
  checkCompletion();
  new bootstrap.Modal(document.getElementById('orderModal')).show();
}
function delOrder(id, orderNo) {
  Swal.fire({
    title: 'Delete Order?',
    text: 'Order "' + orderNo + '" will be permanently deleted.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteId').value = id;
      document.getElementById('deleteForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
