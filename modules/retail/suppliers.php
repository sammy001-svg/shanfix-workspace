<?php
// ── Retail: Suppliers ─────────────────────────────────────────
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
        $id            = (int)($_POST['id'] ?? 0);
        $name          = sanitize($_POST['name'] ?? '');
        $contactPerson = sanitize($_POST['contact_person'] ?? '');
        $phone         = sanitize($_POST['phone'] ?? '');
        $email         = sanitize($_POST['email'] ?? '');
        $address       = sanitize($_POST['address'] ?? '');
        $status        = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        if (empty($name)) {
            setFlash('danger', 'Supplier name is required.');
            redirect('suppliers.php');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE retail_suppliers SET name=?, contact_person=?, phone=?, email=?, address=?, status=? WHERE id=? AND org_id=?");
            $stmt->execute([$name, $contactPerson, $phone, $email, $address, $status, $id, $orgId]);
            setFlash('success', "Supplier \"$name\" updated.");
            logActivity('update', 'retail', "Updated supplier: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO retail_suppliers (org_id, name, contact_person, phone, email, address, status) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $name, $contactPerson, $phone, $email, $address, $status]);
            setFlash('success', "Supplier \"$name\" added.");
            logActivity('create', 'retail', "Added supplier: $name");
        }
        redirect('suppliers.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = $pdo->prepare("SELECT COUNT(*) FROM retail_purchase_orders WHERE supplier_id=? AND org_id=?");
        $used->execute([$id, $orgId]);
        if ((int)$used->fetchColumn() > 0) {
            setFlash('danger', 'Cannot delete: this supplier has purchase orders.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM retail_suppliers WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            setFlash('success', 'Supplier deleted.');
            logActivity('delete', 'retail', "Deleted supplier #$id");
        }
        redirect('suppliers.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$suppliers = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, COUNT(po.id) AS po_count,
               COALESCE(SUM(po.total_amount),0) AS total_spent
        FROM retail_suppliers s
        LEFT JOIN retail_purchase_orders po ON po.supplier_id = s.id AND po.org_id = s.org_id
        WHERE s.org_id = ?
        GROUP BY s.id
        ORDER BY s.name ASC
    ");
    $stmt->execute([$orgId]);
    $suppliers = $stmt->fetchAll();
} catch (Exception $e) {}

$total  = count($suppliers);
$active = count(array_filter($suppliers, fn($s) => $s['status'] === 'active'));

// View supplier detail
$viewSupplier = null;
$recentPOs    = [];
if (isset($_GET['view'])) {
    $vid = (int)$_GET['view'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM retail_suppliers WHERE id=? AND org_id=?");
        $stmt->execute([$vid, $orgId]);
        $viewSupplier = $stmt->fetch();
        if ($viewSupplier) {
            $stmt = $pdo->prepare("SELECT * FROM retail_purchase_orders WHERE supplier_id=? AND org_id=? ORDER BY order_date DESC LIMIT 10");
            $stmt->execute([$vid, $orgId]);
            $recentPOs = $stmt->fetchAll();
        }
    } catch (Exception $e) {}
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-truck me-2" style="color:<?= $moduleColor ?>"></i>Suppliers</h4>
    <p class="text-muted mb-0">Manage your product suppliers and vendors</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff"
    data-bs-toggle="modal" data-bs-target="#supModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Supplier
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-truck"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $total ?></div><div class="stat-label">Total Suppliers</div></div>
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
      <div class="stat-icon warning-bg"><i class="fas fa-file-invoice"></i></div>
      <div class="stat-body"><div class="stat-value"><?= array_sum(array_column($suppliers, 'po_count')) ?></div><div class="stat-label">Total POs</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value" style="font-size:1rem"><?= formatCurrency(array_sum(array_column($suppliers, 'total_spent'))) ?></div><div class="stat-label">Total Purchased</div></div>
    </div>
  </div>
</div>

<?php if ($viewSupplier): ?>
<!-- Supplier Detail Card -->
<div class="card mb-4" id="supplierDetail">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:<?= $moduleColor ?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-building me-2"></i><?= e($viewSupplier['name']) ?></h6>
    <a href="suppliers.php" class="btn btn-sm btn-light"><i class="fas fa-times me-1"></i>Close</a>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <table class="table table-sm">
          <tr><th class="text-muted" style="width:40%">Supplier</th><td class="fw-semibold"><?= e($viewSupplier['name']) ?></td></tr>
          <tr><th class="text-muted">Contact</th><td><?= e($viewSupplier['contact_person'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Phone</th><td><?= e($viewSupplier['phone'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Email</th><td><?= e($viewSupplier['email'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Status</th><td><?= statusBadge($viewSupplier['status']) ?></td></tr>
        </table>
        <?php if (!empty($viewSupplier['address'])): ?>
        <p class="small text-muted"><strong>Address:</strong> <?= nl2br(e($viewSupplier['address'])) ?></p>
        <?php endif; ?>
      </div>
      <div class="col-md-6">
        <h6 class="fw-semibold mb-3">Recent Purchase Orders</h6>
        <?php if (empty($recentPOs)): ?>
        <p class="text-muted">No purchase orders yet.</p>
        <?php else: ?>
        <table class="table table-sm table-hover">
          <thead class="table-light"><tr><th>PO No</th><th>Date</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recentPOs as $po): ?>
          <tr>
            <td class="fw-semibold"><?= e($po['po_no']) ?></td>
            <td><?= formatDate($po['order_date']) ?></td>
            <td class="text-end"><?= formatCurrency((float)$po['total_amount']) ?></td>
            <td><?= statusBadge($po['status']) ?></td>
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

<!-- Suppliers Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-truck me-2" style="color:<?= $moduleColor ?>"></i>All Suppliers</h6>
    <span class="badge bg-secondary"><?= $total ?> suppliers</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Supplier</th>
            <th>Contact Person</th>
            <th>Phone</th>
            <th>Email</th>
            <th class="text-center">POs</th>
            <th class="text-end">Total Spent</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($suppliers)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">
            <i class="fas fa-truck fa-2x mb-2 d-block"></i>No suppliers yet.
          </td></tr>
          <?php else: foreach ($suppliers as $sup): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div style="width:34px;height:34px;border-radius:8px;background:<?= $moduleColor ?>22;color:<?= $moduleColor ?>;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0">
                  <i class="fas fa-truck"></i>
                </div>
                <div>
                  <div class="fw-semibold"><?= e($sup['name']) ?></div>
                  <?php if (!empty($sup['address'])): ?>
                  <div class="small text-muted"><?= e(mb_substr($sup['address'], 0, 40)) ?><?= mb_strlen($sup['address']) > 40 ? '…' : '' ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td><?= e($sup['contact_person'] ?? '—') ?></td>
            <td><?= e($sup['phone'] ?? '—') ?></td>
            <td><?= e($sup['email'] ?? '—') ?></td>
            <td class="text-center">
              <a href="?view=<?= $sup['id'] ?>#supplierDetail" class="badge bg-info text-decoration-none"><?= (int)$sup['po_count'] ?> POs</a>
            </td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$sup['total_spent']) ?></td>
            <td><?= statusBadge($sup['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <a href="?view=<?= $sup['id'] ?>#supplierDetail" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
              <button class="btn btn-sm btn-outline-primary ms-1"
                onclick='openEdit(<?= htmlspecialchars(json_encode($sup), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="delSup(<?= $sup['id'] ?>, '<?= e($sup['name']) ?>', <?= (int)$sup['po_count'] ?>)"
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
<div class="modal fade" id="supModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="sId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="supModalTitle"><i class="fas fa-truck me-2"></i>Add Supplier</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Supplier Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="sName" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="sStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Contact Person</label>
              <input type="text" name="contact_person" id="sContact" class="form-control" maxlength="255">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="sPhone" class="form-control" maxlength="25">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="sEmail" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Address</label>
              <textarea name="address" id="sAddress" class="form-control" rows="2" placeholder="Physical or postal address"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Supplier</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="delSupForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delSupId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('supModalTitle').innerHTML = '<i class="fas fa-truck me-2"></i>Add Supplier';
  ['sId','sName','sContact','sPhone','sEmail','sAddress'].forEach(function(i){ document.getElementById(i).value = i==='sId'?'0':''; });
  document.getElementById('sStatus').value = 'active';
}
function openEdit(s) {
  document.getElementById('supModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Supplier';
  document.getElementById('sId').value      = s.id;
  document.getElementById('sName').value    = s.name || '';
  document.getElementById('sContact').value = s.contact_person || '';
  document.getElementById('sPhone').value   = s.phone || '';
  document.getElementById('sEmail').value   = s.email || '';
  document.getElementById('sAddress').value = s.address || '';
  document.getElementById('sStatus').value  = s.status || 'active';
  new bootstrap.Modal(document.getElementById('supModal')).show();
}
function delSup(id, name, count) {
  if (count > 0) {
    Swal.fire({ title: 'Cannot Delete', text: '"' + name + '" has ' + count + ' purchase order(s). Remove those first.', icon: 'error' });
    return;
  }
  Swal.fire({
    title: 'Delete Supplier?',
    text: '"' + name + '" will be permanently deleted.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('delSupId').value = id;
      document.getElementById('delSupForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
