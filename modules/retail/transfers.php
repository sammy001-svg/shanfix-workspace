<?php
// ── Retail: Stock Transfers (Branch / Location) ─────────────────
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

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        // Auto-ref TRF-YYYY-NNNN
        $stmt = $pdo->prepare("SELECT COUNT(*)+1 FROM retail_transfers WHERE org_id=? AND YEAR(created_at)=YEAR(NOW())");
        $stmt->execute([$orgId]);
        $ref = 'TRF-' . date('Y') . '-' . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);

        $productId  = (int)($_POST['product_id'] ?? 0);
        $fromLoc    = sanitize($_POST['from_location'] ?? '');
        $toLoc      = sanitize($_POST['to_location'] ?? '');
        $qty        = (float)($_POST['qty'] ?? 0);
        $notes      = sanitize($_POST['notes'] ?? '');

        $pdo->prepare("INSERT INTO retail_transfers (org_id,ref,product_id,from_location,to_location,qty,status,notes) VALUES (?,?,?,?,?,?,'pending',?)")
            ->execute([$orgId,$ref,$productId,$fromLoc,$toLoc,$qty,$notes]);
        setFlash('success', "Transfer $ref created.");

    } elseif ($action === 'status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['pending','in_transit','received','cancelled']) ? $_POST['status'] : 'pending';
        $pdo->prepare("UPDATE retail_transfers SET status=? WHERE id=? AND org_id=?")->execute([$status,$id,$orgId]);
        setFlash('success', 'Transfer status updated.');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM retail_transfers WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Transfer deleted.');
    }
    redirect('transfers.php');
}

// Products for dropdown
$products = $pdo->prepare("SELECT id, name, sku FROM retail_products WHERE org_id=? ORDER BY name");
$products->execute([$orgId]); $products = $products->fetchAll();

// Fetch transfers
$statusFilter = sanitize($_GET['status'] ?? '');
$sql = "SELECT t.*, p.name as product_name, p.sku FROM retail_transfers t LEFT JOIN retail_products p ON t.product_id=p.id WHERE t.org_id=?";
$params = [$orgId];
if ($statusFilter) { $sql .= " AND t.status=?"; $params[] = $statusFilter; }
$sql .= " ORDER BY t.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$transfers = $stmt->fetchAll();

// KPIs
$totalTransfers   = countRows($pdo, 'retail_transfers', 'org_id=?', [$orgId]);
$pendingTransfers = countRows($pdo, 'retail_transfers', 'org_id=? AND status=?', [$orgId,'pending']);
$inTransit        = countRows($pdo, 'retail_transfers', 'org_id=? AND status=?', [$orgId,'in_transit']);

$statusColors = ['pending'=>'warning','in_transit'=>'info','received'=>'success','cancelled'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-exchange-alt me-2" style="color:<?= $moduleColor ?>"></i>Stock Transfers</h4>
    <p class="text-muted mb-0">Move stock between branches, warehouses, or locations</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#trfModal">
    <i class="fas fa-plus me-1"></i>New Transfer
  </button>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,0.12);color:#2980b9"><i class="fas fa-exchange-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalTransfers ?></div><div class="stat-label">Total Transfers</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingTransfers ?></div><div class="stat-label">Pending</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(23,162,184,0.12);color:#17a2b8"><i class="fas fa-truck-moving"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $inTransit ?></div><div class="stat-label">In Transit</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-3">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['pending','in_transit','received','cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if ($statusFilter): ?><div class="col-auto"><a href="transfers.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Ref</th>
            <th>Product</th>
            <th>From</th>
            <th>To</th>
            <th class="text-center">Qty</th>
            <th class="text-center">Status</th>
            <th class="text-center">Date</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($transfers)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No stock transfers found.</td></tr>
          <?php else: foreach ($transfers as $t): ?>
          <tr>
            <td class="ps-3"><code><?= e($t['ref']) ?></code></td>
            <td><strong><?= e($t['product_name'] ?? '—') ?></strong><br><small class="text-muted"><?= e($t['sku'] ?? '') ?></small></td>
            <td><?= e($t['from_location']) ?></td>
            <td><?= e($t['to_location']) ?></td>
            <td class="text-center fw-bold"><?= number_format($t['qty'],2) ?></td>
            <td class="text-center">
              <form method="post" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <select name="status" class="form-select form-select-sm d-inline-block w-auto border-0 bg-transparent fw-semibold text-<?= $statusColors[$t['status']] ?? 'secondary' ?>"
                        onchange="this.form.submit()">
                  <?php foreach (['pending','in_transit','received','cancelled'] as $s): ?>
                  <option value="<?= $s ?>" <?= $t['status']===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="text-center text-muted small"><?= formatDate($t['created_at']) ?></td>
            <td class="text-end pe-3">
              <form method="post" class="d-inline" onsubmit="return confirm('Delete transfer?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" <?= $t['status']==='received'?'disabled':'' ?>>
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Transfer Modal -->
<div class="modal fade" id="trfModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>New Stock Transfer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
              <select name="product_id" class="form-select" required>
                <option value="">— Select Product —</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">From Location</label>
              <input type="text" name="from_location" class="form-control" list="locList" placeholder="e.g. Main Store" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">To Location</label>
              <input type="text" name="to_location" class="form-control" list="locList" placeholder="e.g. Branch 2" required>
            </div>
            <datalist id="locList">
              <option value="Main Store"><option value="Branch 2"><option value="Warehouse">
              <option value="Showroom"><option value="Online Fulfillment">
            </datalist>
            <div class="col-12">
              <label class="form-label fw-semibold">Quantity to Transfer</label>
              <input type="number" name="qty" class="form-control" step="0.01" min="0.01" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Create Transfer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
