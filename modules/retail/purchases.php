<?php
// ── Retail: Purchase Orders ───────────────────────────────────
$moduleSlug  = 'retail';
$moduleName  = 'Retail & Wholesale';
$moduleIcon  = 'fas fa-store';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'categories.php','icon' => 'fas fa-tags',           'label' => 'Categories'],
    ['url' => 'products.php',  'icon' => 'fas fa-boxes',          'label' => 'Products'],
    ['url' => 'suppliers.php', 'icon' => 'fas fa-truck',          'label' => 'Suppliers'],
    ['url' => 'purchases.php', 'icon' => 'fas fa-file-invoice',   'label' => 'Purchase Orders'],
    ['url' => 'sales.php',     'icon' => 'fas fa-cash-register',  'label' => 'Sales / POS'],
    ['url' => 'stock.php',     'icon' => 'fas fa-warehouse',      'label' => 'Stock Adjustments'],
    ['url' => 'pricing.php',   'icon' => 'fas fa-tags',           'label' => 'Pricing Rules'],
    ['url' => 'customers.php', 'icon' => 'fas fa-users',           'label' => 'Customers'],
    ['url' => 'expenses.php',  'icon' => 'fas fa-receipt',         'label' => 'Expenses'],
    ['url' => 'transfers.php', 'icon' => 'fas fa-exchange-alt',    'label' => 'Stock Transfers'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
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
        $id           = (int)($_POST['id'] ?? 0);
        $supplierId   = (int)($_POST['supplier_id'] ?? 0) ?: null;
        $orderDate    = sanitize($_POST['order_date'] ?? '') ?: date('Y-m-d');
        $expectedDate = sanitize($_POST['expected_date'] ?? '') ?: null;
        $totalAmount  = (float)($_POST['total_amount'] ?? 0);
        $status       = in_array($_POST['status'] ?? '', ['draft','ordered','received','cancelled']) ? $_POST['status'] : 'draft';
        $notes        = sanitize($_POST['notes'] ?? '');

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE retail_purchase_orders SET supplier_id=?, order_date=?, expected_date=?, total_amount=?, status=?, notes=? WHERE id=? AND org_id=?");
            $stmt->execute([$supplierId, $orderDate, $expectedDate ?: null, $totalAmount, $status, $notes, $id, $orgId]);
            setFlash('success', 'Purchase order updated.');
            logActivity('update', 'retail', "Updated PO #$id");
        } else {
            // Auto PO number: PO-YYYYMMDD-NNN
            $dateStr = date('Ymd');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM retail_purchase_orders WHERE org_id=? AND order_date=?");
            $stmt->execute([$orgId, date('Y-m-d')]);
            $seq  = (int)$stmt->fetchColumn() + 1;
            $poNo = 'PO-' . $dateStr . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("INSERT INTO retail_purchase_orders (org_id, po_no, supplier_id, order_date, expected_date, total_amount, status, notes) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $poNo, $supplierId, $orderDate, $expectedDate ?: null, $totalAmount, $status, $notes]);
            setFlash('success', "Purchase Order $poNo created.");
            logActivity('create', 'retail', "Created PO $poNo");
        }
        redirect('purchases.php');
    }

    if ($action === 'status_change') {
        $id        = (int)($_POST['id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $allowed   = ['draft','ordered','received','cancelled'];
        if (!in_array($newStatus, $allowed)) {
            setFlash('danger', 'Invalid status.');
            redirect('purchases.php');
        }
        $stmt = $pdo->prepare("UPDATE retail_purchase_orders SET status=? WHERE id=? AND org_id=?");
        $stmt->execute([$newStatus, $id, $orgId]);
        if ($newStatus === 'received') {
            setFlash('success', 'PO marked as Received. Update stock levels from the Products page.');
        } else {
            setFlash('success', 'Purchase order status updated to ' . ucfirst($newStatus) . '.');
        }
        logActivity('update', 'retail', "Changed PO #$id status to $newStatus");
        redirect('purchases.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT po_no, status FROM retail_purchase_orders WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $po = $stmt->fetch();
        if ($po && $po['status'] !== 'received') {
            $pdo->prepare("DELETE FROM retail_purchase_orders WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', "PO {$po['po_no']} deleted.");
            logActivity('delete', 'retail', "Deleted PO {$po['po_no']}");
        } elseif ($po && $po['status'] === 'received') {
            setFlash('danger', 'Cannot delete a received purchase order.');
        }
        redirect('purchases.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$orders = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*, s.name AS supplier_name
        FROM retail_purchase_orders po
        LEFT JOIN retail_suppliers s ON po.supplier_id = s.id
        WHERE po.org_id = ?
        ORDER BY po.order_date DESC, po.id DESC
    ");
    $stmt->execute([$orgId]);
    $orders = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats
$totalPOs   = count($orders);
$pendingPOs = count(array_filter($orders, fn($o) => in_array($o['status'], ['draft','ordered'])));
$receivedThisMonth = 0;
$totalValue = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM retail_purchase_orders WHERE org_id=? AND status='received' AND DATE_FORMAT(order_date,'%Y-%m')=?");
    $stmt->execute([$orgId, date('Y-m')]);
    $receivedThisMonth = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM retail_purchase_orders WHERE org_id=?");
    $stmt->execute([$orgId]);
    $totalValue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Suppliers for dropdown
$suppliers = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM retail_suppliers WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]);
    $suppliers = $stmt->fetchAll();
} catch (Exception $e) {}

// Status flow map
$nextStatus = [
    'draft'     => ['ordered'   => 'Mark as Ordered'],
    'ordered'   => ['received'  => 'Mark as Received', 'cancelled' => 'Cancel'],
    'received'  => [],
    'cancelled' => [],
];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Purchase Orders</h4>
    <p class="text-muted mb-0">Track and manage procurement from suppliers</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff"
    data-bs-toggle="modal" data-bs-target="#poModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>New PO
  </button>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-file-invoice"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalPOs ?></div><div class="stat-label">Total POs</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingPOs ?></div><div class="stat-label">Pending</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $receivedThisMonth ?></div><div class="stat-label">Received This Month</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1rem"><?= formatCurrency($totalValue) ?></div><div class="stat-label">Total Value</div></div>
    </div>
  </div>
</div>

<!-- POs Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Purchase Order List</h6>
    <span class="badge bg-secondary"><?= $totalPOs ?> orders</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>PO Number</th>
            <th>Supplier</th>
            <th>Order Date</th>
            <th>Expected</th>
            <th class="text-end">Total Amount</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            <i class="fas fa-file-invoice fa-2x mb-2 d-block"></i>No purchase orders yet.
          </td></tr>
          <?php else: foreach ($orders as $po): ?>
          <tr>
            <td class="fw-semibold" style="color:<?= $moduleColor ?>"><?= e($po['po_no']) ?></td>
            <td><?= e($po['supplier_name'] ?? '—') ?></td>
            <td><?= formatDate($po['order_date']) ?></td>
            <td>
              <?php if ($po['expected_date']): ?>
              <?php $isLate = $po['status'] === 'ordered' && strtotime($po['expected_date']) < time(); ?>
              <span class="<?= $isLate ? 'text-danger fw-semibold' : '' ?>"><?= formatDate($po['expected_date']) ?></span>
              <?php if ($isLate): ?><i class="fas fa-exclamation-circle text-danger ms-1" title="Overdue"></i><?php endif; ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$po['total_amount']) ?></td>
            <td><?= statusBadge($po['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <?php foreach (($nextStatus[$po['status']] ?? []) as $ns => $label): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status_change">
                <input type="hidden" name="id" value="<?= $po['id'] ?>">
                <input type="hidden" name="new_status" value="<?= $ns ?>">
                <button type="submit" class="btn btn-sm btn-outline-<?= $ns === 'received' ? 'success' : ($ns === 'cancelled' ? 'secondary' : 'warning') ?>" title="<?= e($label) ?>">
                  <i class="fas fa-<?= $ns === 'received' ? 'check' : ($ns === 'cancelled' ? 'ban' : 'arrow-right') ?>"></i>
                </button>
              </form>
              <?php endforeach; ?>
              <?php if ($po['status'] !== 'received'): ?>
              <button class="btn btn-sm btn-outline-primary ms-1"
                onclick='openEdit(<?= htmlspecialchars(json_encode($po), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="delPO(<?= $po['id'] ?>, '<?= e($po['po_no']) ?>')"
                title="Delete"><i class="fas fa-trash"></i></button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="poModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="poId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="poModalTitle"><i class="fas fa-file-invoice me-2"></i>New Purchase Order</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Supplier</label>
              <select name="supplier_id" id="poSupplier" class="form-select">
                <option value="">-- Select Supplier --</option>
                <?php foreach ($suppliers as $sup): ?>
                <option value="<?= $sup['id'] ?>"><?= e($sup['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Order Date</label>
              <input type="date" name="order_date" id="poOrderDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Expected Date</label>
              <input type="date" name="expected_date" id="poExpected" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Total Amount (KES)</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="total_amount" id="poAmount" class="form-control" step="0.01" min="0" value="0">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="poStatus" class="form-select">
                <option value="draft">Draft</option>
                <option value="ordered">Ordered</option>
                <option value="received">Received</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="poNotes" class="form-control" rows="3" placeholder="Order notes or special instructions…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save PO</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="delPOForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delPOId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('poModalTitle').innerHTML = '<i class="fas fa-file-invoice me-2"></i>New Purchase Order';
  document.getElementById('poId').value = '0';
  document.getElementById('poSupplier').value = '';
  document.getElementById('poOrderDate').value = new Date().toISOString().slice(0,10);
  document.getElementById('poExpected').value = '';
  document.getElementById('poAmount').value = '0';
  document.getElementById('poStatus').value = 'draft';
  document.getElementById('poNotes').value = '';
}
function openEdit(po) {
  document.getElementById('poModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit PO — ' + po.po_no;
  document.getElementById('poId').value        = po.id;
  document.getElementById('poSupplier').value  = po.supplier_id || '';
  document.getElementById('poOrderDate').value = po.order_date || '';
  document.getElementById('poExpected').value  = po.expected_date || '';
  document.getElementById('poAmount').value    = po.total_amount || '0';
  document.getElementById('poStatus').value    = po.status || 'draft';
  document.getElementById('poNotes').value     = po.notes || '';
  new bootstrap.Modal(document.getElementById('poModal')).show();
}
function delPO(id, poNo) {
  Swal.fire({
    title: 'Delete Purchase Order?',
    text: 'PO "' + poNo + '" will be permanently deleted.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('delPOId').value = id;
      document.getElementById('delPOForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
