<?php
$moduleSlug  = 'caryard';
$moduleName  = 'Car Yard Management';
$moduleIcon  = 'fas fa-car';
$moduleColor = '#e67e22';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'vehicles.php',       'icon' => 'fas fa-car',            'label' => 'Vehicles'],
    ['url' => 'sales.php',          'icon' => 'fas fa-handshake',      'label' => 'Sales'],
    ['url' => 'customers.php',      'icon' => 'fas fa-users',          'label' => 'Customers'],
    ['url' => 'finance.php',        'icon' => 'fas fa-university',     'label' => 'Finance'],
    ['url' => 'testdrives.php',     'icon' => 'fas fa-road',           'label' => 'Test Drives'],
    ['url' => 'services.php',       'icon' => 'fas fa-tools',          'label' => 'Services'],
    ['url' => 'reconditioning.php', 'icon' => 'fas fa-wrench',         'label' => 'Reconditioning'],
    ['url' => 'valuations.php',     'icon' => 'fas fa-search-dollar',  'label' => 'Valuations'],
    ['url' => 'inquiries.php',      'icon' => 'fas fa-comments',       'label' => 'Inquiries'],
    ['url' => 'insurance.php',      'icon' => 'fas fa-shield-alt',     'label' => 'Insurance'],
    ['url' => 'parts.php',          'icon' => 'fas fa-cogs',           'label' => 'Parts & Spares'],
    ['url' => 'delivery.php',       'icon' => 'fas fa-truck-loading',  'label' => 'Deliveries'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
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
        $partName     = sanitize($_POST['part_name'] ?? '');
        $partNumber   = sanitize($_POST['part_number'] ?? '');
        $category     = sanitize($_POST['category'] ?? 'other');
        $supplier     = sanitize($_POST['supplier'] ?? '');
        $unitCost     = (float)($_POST['unit_cost'] ?? 0);
        $qtyInStock   = (int)($_POST['qty_in_stock'] ?? 0);
        $reorderLevel = (int)($_POST['reorder_level'] ?? 0);
        $location     = sanitize($_POST['location'] ?? '');

        if (empty($partName)) { setFlash('danger', 'Part name is required.'); redirect('parts.php'); }

        if ($id > 0) {
            $pdo->prepare("UPDATE caryard_parts SET part_name=?,part_number=?,category=?,supplier=?,unit_cost=?,qty_in_stock=?,reorder_level=?,location=? WHERE id=? AND org_id=?")
                ->execute([$partName,$partNumber,$category,$supplier,$unitCost,$qtyInStock,$reorderLevel,$location,$id,$orgId]);
            setFlash('success', 'Part updated.');
            logActivity('update', 'caryard', "Updated part: $partName");
        } else {
            $pdo->prepare("INSERT INTO caryard_parts(org_id,part_name,part_number,category,supplier,unit_cost,qty_in_stock,reorder_level,location) VALUES(?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$partName,$partNumber,$category,$supplier,$unitCost,$qtyInStock,$reorderLevel,$location]);
            setFlash('success', "Part '$partName' added.");
            logActivity('create', 'caryard', "Added part: $partName");
        }
        redirect('parts.php');
    }

    if ($action === 'adjust') {
        $id        = (int)($_POST['id'] ?? 0);
        $adjType   = $_POST['adj_type'] ?? 'add';
        $adjQty    = (int)($_POST['adj_qty'] ?? 0);
        if ($adjQty > 0) {
            $op = ($adjType === 'add') ? '+' : '-';
            $pdo->prepare("UPDATE caryard_parts SET qty_in_stock = GREATEST(qty_in_stock $op ?, 0) WHERE id=? AND org_id=?")
                ->execute([$adjQty,$id,$orgId]);
            setFlash('success', 'Stock adjusted.');
            logActivity('update', 'caryard', "Stock adjustment: part #$id $adjType $adjQty");
        }
        redirect('parts.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM caryard_parts WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Part deleted.');
        redirect('parts.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$parts = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM caryard_parts WHERE org_id=? ORDER BY part_name ASC");
    $stmt->execute([$orgId]);
    $parts = $stmt->fetchAll();
} catch (Exception $e) {}

$totalParts  = count($parts);
$lowStock    = 0;
$totalValue  = 0;
foreach ($parts as $p) {
    $totalValue += (float)$p['unit_cost'] * (int)$p['qty_in_stock'];
    if ((int)$p['qty_in_stock'] <= (int)$p['reorder_level']) $lowStock++;
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-cogs me-2" style="color:<?= $moduleColor ?>"></i>Parts & Spares Inventory</h4>
    <p class="text-muted mb-0">Manage spare parts stock, reorder levels and suppliers</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#partModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Part
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-cogs"></i></div><div class="stat-body"><div class="stat-value"><?= $totalParts ?></div><div class="stat-label">Total Parts</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-body"><div class="stat-value"><?= $lowStock ?></div><div class="stat-label">Low Stock</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-dollar-sign"></i></div><div class="stat-body"><div class="stat-value"><?= formatCurrency($totalValue) ?></div><div class="stat-label">Inventory Value</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-boxes"></i></div><div class="stat-body"><div class="stat-value"><?= array_sum(array_column($parts, 'qty_in_stock')) ?></div><div class="stat-label">Total Units</div></div></div></div>
</div>

<?php if ($lowStock > 0): ?>
<div class="alert alert-warning d-flex align-items-center mb-3 no-print">
  <i class="fas fa-exclamation-triangle me-2"></i>
  <strong><?= $lowStock ?> part(s)</strong>&nbsp;at or below reorder level. Consider restocking.
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-cogs me-2" style="color:<?= $moduleColor ?>"></i>Parts List</h6>
    <span class="badge bg-secondary"><?= count($parts) ?> parts</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="partTable">
        <thead class="table-light">
          <tr><th>Part Name</th><th>Part No.</th><th>Category</th><th>Supplier</th><th class="text-end">Unit Cost</th><th class="text-center">In Stock</th><th class="text-center">Reorder Lvl</th><th>Location</th><th class="text-center no-print">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($parts)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No parts found.</td></tr>
          <?php else: foreach ($parts as $p):
            $isLow = (int)$p['qty_in_stock'] <= (int)$p['reorder_level'];
          ?>
          <tr class="<?= $isLow ? 'table-warning' : '' ?>">
            <td class="fw-semibold"><?= e($p['part_name']) ?><?= $isLow ? ' <span class="badge bg-warning text-dark ms-1">Low</span>' : '' ?></td>
            <td><code class="bg-light px-2 py-1 rounded text-dark"><?= e($p['part_number'] ?? '—') ?></code></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst(str_replace('_',' ',$p['category'])) ?></span></td>
            <td><?= e($p['supplier'] ?? '—') ?></td>
            <td class="text-end"><?= formatCurrency((float)$p['unit_cost']) ?></td>
            <td class="text-center fw-bold <?= $isLow?'text-danger':'text-success' ?>"><?= (int)$p['qty_in_stock'] ?></td>
            <td class="text-center text-muted"><?= (int)$p['reorder_level'] ?></td>
            <td><?= e($p['location'] ?? '—') ?></td>
            <td class="text-center no-print" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick='openAdjust(<?= $p['id'] ?>, "<?= e($p['part_name']) ?>")' title="Adjust Stock"><i class="fas fa-sliders-h"></i></button>
              <button class="btn btn-sm btn-outline-primary ms-1" onclick='fillForm(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delPart(<?= $p['id'] ?>,'<?= e($p['part_name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="partModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="partId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="partModalTitle"><i class="fas fa-cogs me-2"></i>Add Part</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Part Name <span class="text-danger">*</span></label><input type="text" name="part_name" id="partName" class="form-control" required maxlength="255"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Part Number</label><input type="text" name="part_number" id="partNumber" class="form-control" maxlength="100"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Category</label><select name="category" id="partCat" class="form-select"><option value="engine">Engine</option><option value="transmission">Transmission</option><option value="brakes">Brakes</option><option value="electrical">Electrical</option><option value="body">Body</option><option value="interior">Interior</option><option value="tyres">Tyres</option><option value="other">Other</option></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Supplier</label><input type="text" name="supplier" id="partSupplier" class="form-control" maxlength="150"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Unit Cost (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="unit_cost" id="partCost" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Qty in Stock</label><input type="number" name="qty_in_stock" id="partQty" class="form-control" min="0" value="0"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Reorder Level</label><input type="number" name="reorder_level" id="partReorder" class="form-control" min="0" value="5"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Location (Shelf/Bin)</label><input type="text" name="location" id="partLocation" class="form-control" maxlength="100" placeholder="e.g. A2-B5"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Part</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Stock Adjust Modal -->
<div class="modal fade" id="adjModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="adjust">
        <input type="hidden" name="id" id="adjPartId">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-sliders-h me-2"></i>Stock Adjustment — <span id="adjPartName"></span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Adjustment Type</label><select name="adj_type" class="form-select"><option value="add">Add Stock (+)</option><option value="subtract">Remove Stock (−)</option></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Quantity</label><input type="number" name="adj_qty" class="form-control" min="1" value="1" required></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-check me-1"></i>Apply Adjustment</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delPartForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delPartId"></form>
<?php
$extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('partModalTitle').innerHTML='<i class="fas fa-cogs me-2"></i>Add Part';
  document.getElementById('partId').value='0';
  document.getElementById('partName').value='';
  document.getElementById('partNumber').value='';
  document.getElementById('partCat').value='other';
  document.getElementById('partSupplier').value='';
  document.getElementById('partCost').value=0;
  document.getElementById('partQty').value=0;
  document.getElementById('partReorder').value=5;
  document.getElementById('partLocation').value='';
}
function fillForm(p){
  document.getElementById('partModalTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Part';
  document.getElementById('partId').value=p.id;
  document.getElementById('partName').value=p.part_name||'';
  document.getElementById('partNumber').value=p.part_number||'';
  document.getElementById('partCat').value=p.category||'other';
  document.getElementById('partSupplier').value=p.supplier||'';
  document.getElementById('partCost').value=p.unit_cost||0;
  document.getElementById('partQty').value=p.qty_in_stock||0;
  document.getElementById('partReorder').value=p.reorder_level||5;
  document.getElementById('partLocation').value=p.location||'';
  new bootstrap.Modal(document.getElementById('partModal')).show();
}
function openAdjust(id,name){
  document.getElementById('adjPartId').value=id;
  document.getElementById('adjPartName').textContent=name;
  new bootstrap.Modal(document.getElementById('adjModal')).show();
}
function delPart(id,name){
  Swal.fire({title:'Delete Part?',text:name+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delPartId').value=id;document.getElementById('delPartForm').submit();}});
}
$(document).ready(function(){$('#partTable').DataTable({pageLength:20,order:[[0,'asc']],language:{emptyTable:'No parts found.'}});});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
