<?php
// ── Manufacturing: Inventory / Stock Levels ─────────────────────
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

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// POST handler — stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $itemName    = sanitize($_POST['item_name'] ?? '');
        $itemType    = in_array($_POST['item_type'] ?? '', ['raw_material','finished_good','component','consumable']) ? $_POST['item_type'] : 'raw_material';
        $sku         = sanitize($_POST['sku'] ?? '');
        $unit        = sanitize($_POST['unit'] ?? '');
        $qtyOnHand   = (float)($_POST['qty_on_hand'] ?? 0);
        $reorderQty  = (float)($_POST['reorder_qty'] ?? 0);
        $unitCost    = (float)($_POST['unit_cost'] ?? 0);
        $location    = sanitize($_POST['location'] ?? '');
        $supplierId  = (int)($_POST['supplier_id'] ?? 0) ?: null;

        if ($id) {
            $pdo->prepare("UPDATE mfg_inventory SET item_name=?,item_type=?,sku=?,unit=?,qty_on_hand=?,reorder_qty=?,unit_cost=?,location=?,supplier_id=? WHERE id=? AND org_id=?")
                ->execute([$itemName,$itemType,$sku,$unit,$qtyOnHand,$reorderQty,$unitCost,$location,$supplierId,$id,$orgId]);
            setFlash('success', 'Inventory record updated.');
        } else {
            $pdo->prepare("INSERT INTO mfg_inventory (org_id,item_name,item_type,sku,unit,qty_on_hand,reorder_qty,unit_cost,location,supplier_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$itemName,$itemType,$sku,$unit,$qtyOnHand,$reorderQty,$unitCost,$location,$supplierId]);
            setFlash('success', 'Item added to inventory.');
        }
    } elseif ($action === 'adjust') {
        $id     = (int)($_POST['id'] ?? 0);
        $delta  = (float)($_POST['delta'] ?? 0);
        $pdo->prepare("UPDATE mfg_inventory SET qty_on_hand = qty_on_hand + ? WHERE id=? AND org_id=?")->execute([$delta,$id,$orgId]);
        setFlash('success', 'Stock adjusted.');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM mfg_inventory WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Item removed.');
    }
    redirect('inventory.php');
}

// Suppliers for dropdown
$suppliers = $pdo->prepare("SELECT id, name FROM mfg_suppliers WHERE org_id=? AND status='active' ORDER BY name");
$suppliers->execute([$orgId]);
$suppliers = $suppliers->fetchAll();

// Fetch inventory
$typeFilter = sanitize($_GET['type'] ?? '');
$search     = sanitize($_GET['q'] ?? '');
$lowOnly    = isset($_GET['low']);
$sql = "SELECT i.*, s.name as supplier_name FROM mfg_inventory i LEFT JOIN mfg_suppliers s ON i.supplier_id=s.id WHERE i.org_id=?";
$params = [$orgId];
if ($typeFilter) { $sql .= " AND i.item_type=?"; $params[] = $typeFilter; }
if ($search)     { $sql .= " AND (i.item_name LIKE ? OR i.sku LIKE ? OR i.location LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($lowOnly)    { $sql .= " AND i.qty_on_hand <= i.reorder_qty"; }
$sql .= " ORDER BY i.item_name ASC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$inventory = $stmt->fetchAll();

// KPIs
$totalItems  = countRows($pdo, 'mfg_inventory', 'org_id=?', [$orgId]);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM mfg_inventory WHERE org_id=? AND qty_on_hand <= reorder_qty"); $stmt->execute([$orgId]);
$lowStock = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(qty_on_hand * unit_cost),0) FROM mfg_inventory WHERE org_id=?"); $stmt->execute([$orgId]);
$stockValue = (float)$stmt->fetchColumn();

// Edit prefill
$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM mfg_inventory WHERE id=? AND org_id=?");
    $stmt->execute([(int)$_GET['edit'], $orgId]);
    $editRow = $stmt->fetch();
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-warehouse me-2" style="color:<?= $moduleColor ?>"></i>Inventory Management</h4>
    <p class="text-muted mb-0">Track raw materials, components, and finished goods stock levels</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#invModal" onclick="resetForm()">
    <i class="fas fa-plus me-1"></i>Add Item
  </button>
</div>

<?php if ($lowStock > 0): ?>
<div class="alert alert-warning d-flex align-items-center mb-4">
  <i class="fas fa-exclamation-triangle me-2 fs-5"></i>
  <span><strong><?= $lowStock ?> item<?= $lowStock>1?'s':'' ?></strong> at or below reorder level.
  <a href="?low=1" class="alert-link ms-1">View low-stock items</a></span>
</div>
<?php endif; ?>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(211,84,0,0.12);color:#d35400"><i class="fas fa-warehouse"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalItems ?></div><div class="stat-label">Total Items</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $lowStock ?></div><div class="stat-label">Low Stock Alerts</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($stockValue) ?></div><div class="stat-label">Total Stock Value</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, SKU, location…" value="<?= e($search) ?>">
      </div>
      <div class="col-sm-3">
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <option value="raw_material" <?= $typeFilter==='raw_material'?'selected':'' ?>>Raw Material</option>
          <option value="finished_good" <?= $typeFilter==='finished_good'?'selected':'' ?>>Finished Good</option>
          <option value="component" <?= $typeFilter==='component'?'selected':'' ?>>Component</option>
          <option value="consumable" <?= $typeFilter==='consumable'?'selected':'' ?>>Consumable</option>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if ($lowOnly): ?>
      <div class="col-auto"><span class="badge bg-warning text-dark">Showing Low Stock</span> <a href="inventory.php" class="btn btn-sm btn-link">Clear</a></div>
      <?php elseif ($typeFilter||$search): ?>
      <div class="col-auto"><a href="inventory.php" class="btn btn-sm btn-link">Clear</a></div>
      <?php endif; ?>
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
            <th class="ps-3">Item Name</th>
            <th>SKU</th>
            <th>Type</th>
            <th class="text-center">Qty on Hand</th>
            <th class="text-center">Reorder Qty</th>
            <th class="text-end">Unit Cost</th>
            <th>Location</th>
            <th>Supplier</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($inventory)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No inventory items found.</td></tr>
          <?php else: foreach ($inventory as $inv): ?>
          <?php $isLow = $inv['qty_on_hand'] <= $inv['reorder_qty']; ?>
          <tr class="<?= $isLow ? 'table-warning' : '' ?>">
            <td class="ps-3 fw-semibold">
              <?= e($inv['item_name']) ?>
              <?php if ($isLow): ?><span class="badge bg-danger ms-1">Low</span><?php endif; ?>
            </td>
            <td><code><?= e($inv['sku']) ?></code></td>
            <td><?php
              $typeLabel = ['raw_material'=>'Raw Material','finished_good'=>'Finished Good','component'=>'Component','consumable'=>'Consumable'];
              echo '<span class="badge bg-secondary">'.($typeLabel[$inv['item_type']]??$inv['item_type']).'</span>';
            ?></td>
            <td class="text-center fw-bold <?= $isLow?'text-danger':'' ?>"><?= number_format($inv['qty_on_hand'],2) ?> <?= e($inv['unit']) ?></td>
            <td class="text-center text-muted"><?= number_format($inv['reorder_qty'],2) ?></td>
            <td class="text-end"><?= formatCurrency($inv['unit_cost']) ?></td>
            <td><?= e($inv['location']) ?></td>
            <td><?= e($inv['supplier_name'] ?? '—') ?></td>
            <td class="text-end pe-3">
              <!-- Quick Adjust -->
              <button class="btn btn-sm btn-outline-success me-1" data-bs-toggle="modal" data-bs-target="#adjustModal"
                onclick="setAdjust(<?= $inv['id'] ?>, '<?= e($inv['item_name']) ?>')">
                <i class="fas fa-plus-minus"></i>
              </button>
              <a href="inventory.php?edit=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-primary me-1"
                 data-bs-toggle="modal" data-bs-target="#invModal"
                 onclick="fillForm(<?= htmlspecialchars(json_encode($inv), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
              </a>
              <form method="post" class="d-inline" onsubmit="return confirm('Remove this item?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="invModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-warehouse me-2"></i><span id="modalTitle">Add Inventory Item</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="fId" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Item Name <span class="text-danger">*</span></label>
              <input type="text" name="item_name" id="fName" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">SKU</label>
              <input type="text" name="sku" id="fSku" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Item Type</label>
              <select name="item_type" id="fType" class="form-select">
                <option value="raw_material">Raw Material</option>
                <option value="finished_good">Finished Good</option>
                <option value="component">Component</option>
                <option value="consumable">Consumable</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Qty on Hand</label>
              <input type="number" name="qty_on_hand" id="fQty" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Reorder Qty</label>
              <input type="number" name="reorder_qty" id="fReorder" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Unit</label>
              <input type="text" name="unit" id="fUnit" class="form-control" list="unitList" placeholder="kg, pcs, litre…">
              <datalist id="unitList">
                <option value="kg"><option value="g"><option value="litre"><option value="ml">
                <option value="pcs"><option value="carton"><option value="roll"><option value="metre">
              </datalist>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Unit Cost (KES)</label>
              <input type="number" name="unit_cost" id="fCost" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Warehouse / Location</label>
              <input type="text" name="location" id="fLocation" class="form-control" placeholder="e.g. Shelf A3, Cold Storage">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Primary Supplier</label>
              <select name="supplier_id" id="fSupplier" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($suppliers as $sup): ?>
                <option value="<?= $sup['id'] ?>"><?= e($sup['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Item</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Stock Adjust Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-sliders-h me-2"></i>Adjust Stock — <span id="adjItemName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="adjust">
        <input type="hidden" name="id" id="adjId">
        <div class="modal-body">
          <label class="form-label fw-semibold">Quantity Change (positive = add, negative = remove)</label>
          <input type="number" name="delta" class="form-control" step="0.01" required placeholder="e.g. 50 or -10">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>Apply Adjustment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function resetForm() {
  document.getElementById('modalTitle').textContent = 'Add Inventory Item';
  ['fId','fName','fSku','fUnit','fLocation'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('fType').value     = 'raw_material';
  document.getElementById('fQty').value      = '0';
  document.getElementById('fReorder').value  = '0';
  document.getElementById('fCost').value     = '0';
  document.getElementById('fSupplier').value = '';
  document.getElementById('fId').value       = '0';
}
function fillForm(inv) {
  document.getElementById('modalTitle').textContent = 'Edit Inventory Item';
  document.getElementById('fId').value       = inv.id;
  document.getElementById('fName').value     = inv.item_name;
  document.getElementById('fSku').value      = inv.sku;
  document.getElementById('fType').value     = inv.item_type;
  document.getElementById('fQty').value      = inv.qty_on_hand;
  document.getElementById('fReorder').value  = inv.reorder_qty;
  document.getElementById('fUnit').value     = inv.unit;
  document.getElementById('fCost').value     = inv.unit_cost;
  document.getElementById('fLocation').value = inv.location;
  document.getElementById('fSupplier').value = inv.supplier_id ?? '';
}
function setAdjust(id, name) {
  document.getElementById('adjId').value       = id;
  document.getElementById('adjItemName').textContent = name;
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
