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
        $id           = (int)($_POST['id'] ?? 0);
        $name         = sanitize($_POST['name'] ?? '');
        $unit         = sanitize($_POST['unit'] ?? '');
        $stock        = (float)($_POST['stock'] ?? 0);
        $reorderLevel = (float)($_POST['reorder_level'] ?? 0);
        $unitCost     = (float)($_POST['unit_cost'] ?? 0);
        $supplier     = sanitize($_POST['supplier'] ?? '');

        if (empty($name)) {
            setFlash('danger', 'Material name is required.');
            redirect('materials.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE mfg_raw_materials SET name=?, unit=?, reorder_level=?, unit_cost=?, supplier=? WHERE id=? AND org_id=?")
                ->execute([$name, $unit, $reorderLevel, $unitCost, $supplier, $id, $orgId]);
            setFlash('success', 'Material updated.');
            logActivity('update', 'manufacturing', "Updated material: $name");
        } else {
            $last = $pdo->prepare("SELECT MAX(id) FROM mfg_raw_materials WHERE org_id=?");
            $last->execute([$orgId]);
            $nextId = (int)$last->fetchColumn() + 1;
            $code   = 'MAT-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO mfg_raw_materials (org_id, code, name, unit, stock, reorder_level, unit_cost, supplier) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $code, $name, $unit, $stock, $reorderLevel, $unitCost, $supplier]);
            setFlash('success', 'Material created.');
            logActivity('create', 'manufacturing', "Created material: $name");
        }
        redirect('materials.php');
    }

    if ($action === 'adjust') {
        $id     = (int)($_POST['id'] ?? 0);
        $change = (float)($_POST['change'] ?? 0);
        $dir    = ($_POST['dir'] ?? 'add') === 'sub' ? -1 : 1;
        $adjust = $change * $dir;
        $pdo->prepare("UPDATE mfg_raw_materials SET stock = GREATEST(0, stock + ?) WHERE id=? AND org_id=?")
            ->execute([$adjust, $id, $orgId]);
        setFlash('success', 'Stock adjusted.');
        logActivity('update', 'manufacturing', "Stock adjustment on material #$id: $adjust");
        redirect('materials.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $inBom = countRows('mfg_bom', 'material_id = ?', [$id]);
        if ($inBom > 0) {
            setFlash('danger', 'Cannot delete: material is used in Bill of Materials.');
        } else {
            $pdo->prepare("DELETE FROM mfg_raw_materials WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Material deleted.');
            logActivity('delete', 'manufacturing', "Deleted material #$id");
        }
        redirect('materials.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$materials = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM mfg_raw_materials WHERE org_id=? ORDER BY code, name");
    $stmt->execute([$orgId]);
    $materials = $stmt->fetchAll();
} catch (Exception $e) {}

$totalMats  = count($materials);
$lowStockMats = count(array_filter($materials, fn($m) => (float)$m['stock'] <= (float)$m['reorder_level']));
$totalValue = array_sum(array_map(fn($m) => (float)$m['unit_cost'] * (float)$m['stock'], $materials));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-cubes me-2" style="color:<?= $moduleColor ?>"></i>Raw Materials</h4>
    <p class="text-muted mb-0">Manage raw material inventory and reorder levels</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#matModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Material
  </button>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-cubes"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalMats ?></div><div class="stat-label">Total Materials</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $lowStockMats ?></div><div class="stat-label">Low Stock</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalValue) ?></div><div class="stat-label">Inventory Value</div></div>
    </div>
  </div>
</div>

<?php if ($lowStockMats > 0): ?>
<div class="alert alert-warning d-flex align-items-center mb-3">
  <i class="fas fa-exclamation-triangle me-2 fs-5"></i>
  <span><strong><?= $lowStockMats ?> material<?= $lowStockMats > 1 ? 's are' : ' is' ?></strong> at or below reorder level. Rows highlighted in red below.</span>
</div>
<?php endif; ?>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-cubes me-2" style="color:<?= $moduleColor ?>"></i>Material Inventory</h6>
    <span class="badge bg-secondary"><?= $totalMats ?> materials</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Unit</th>
            <th class="text-end">Stock</th>
            <th class="text-end">Reorder Level</th>
            <th class="text-end">Unit Cost</th>
            <th class="text-end">Total Value</th>
            <th>Supplier</th>
            <th class="text-center">Adjust Stock</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($materials)): ?>
          <tr><td colspan="10" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No materials found.</td></tr>
          <?php else: foreach ($materials as $m):
            $stockVal = (float)$m['unit_cost'] * (float)$m['stock'];
            $isLow    = (float)$m['stock'] <= (float)$m['reorder_level'];
          ?>
          <tr class="<?= $isLow ? 'table-danger' : '' ?>">
            <td class="fw-semibold text-muted"><?= e($m['code'] ?? '') ?></td>
            <td class="fw-semibold">
              <?= e($m['name']) ?>
              <?php if ($isLow): ?><span class="badge bg-danger ms-1 small"><i class="fas fa-arrow-down"></i> Low</span><?php endif; ?>
            </td>
            <td><?= e($m['unit'] ?? '—') ?></td>
            <td class="text-end fw-semibold <?= $isLow ? 'text-danger' : 'text-dark' ?>"><?= number_format((float)$m['stock'], 3) ?></td>
            <td class="text-end text-muted"><?= number_format((float)$m['reorder_level'], 3) ?></td>
            <td class="text-end"><?= formatCurrency((float)$m['unit_cost']) ?></td>
            <td class="text-end"><?= formatCurrency($stockVal) ?></td>
            <td class="small"><?= e($m['supplier'] ?? '—') ?></td>
            <td class="text-center" style="min-width:200px">
              <form method="POST" class="d-inline-flex gap-1 align-items-center">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <select name="dir" class="form-select form-select-sm" style="width:80px">
                  <option value="add">+ Add</option>
                  <option value="sub">- Sub</option>
                </select>
                <input type="number" name="change" class="form-control form-control-sm" style="width:80px" placeholder="Qty" min="0.001" step="0.001" required>
                <button type="submit" class="btn btn-sm" style="background:<?= $moduleColor ?>;color:#fff">Go</button>
              </form>
            </td>
            <td class="text-center text-nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($m), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delMat(<?= $m['id'] ?>, '<?= e($m['name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="matModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="matId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="matModalTitle"><i class="fas fa-cubes me-2"></i>Add Material</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Material Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="matName" class="form-control" placeholder="e.g. Sheet Metal 2mm" required maxlength="255">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Unit</label>
              <input type="text" name="unit" id="matUnit" class="form-control" placeholder="e.g. kg, m, pcs">
            </div>
            <div class="col-md-4" id="matStockRow">
              <label class="form-label fw-semibold">Opening Stock</label>
              <input type="number" name="stock" id="matStock" class="form-control" placeholder="0.000" step="0.001" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Reorder Level</label>
              <input type="number" name="reorder_level" id="matReorder" class="form-control" placeholder="0.000" step="0.001" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Unit Cost</label>
              <input type="number" name="unit_cost" id="matCost" class="form-control" placeholder="0.00" step="0.01" min="0" value="0">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Supplier</label>
              <input type="text" name="supplier" id="matSupplier" class="form-control" placeholder="e.g. Nairobi Steel Suppliers" maxlength="255">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Material</button>
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
function openAdd() {
  document.getElementById('matModalTitle').innerHTML = '<i class="fas fa-cubes me-2"></i>Add Material';
  document.getElementById('matId').value       = '0';
  document.getElementById('matName').value     = '';
  document.getElementById('matUnit').value     = '';
  document.getElementById('matStock').value    = '0';
  document.getElementById('matReorder').value  = '0';
  document.getElementById('matCost').value     = '0';
  document.getElementById('matSupplier').value = '';
  document.getElementById('matStockRow').style.display = '';
}
function openEdit(m) {
  document.getElementById('matModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Material';
  document.getElementById('matId').value       = m.id;
  document.getElementById('matName').value     = m.name || '';
  document.getElementById('matUnit').value     = m.unit || '';
  document.getElementById('matStock').value    = m.stock || '0';
  document.getElementById('matReorder').value  = m.reorder_level || '0';
  document.getElementById('matCost').value     = m.unit_cost || '0';
  document.getElementById('matSupplier').value = m.supplier || '';
  document.getElementById('matStockRow').style.display = 'none';
  new bootstrap.Modal(document.getElementById('matModal')).show();
}
function delMat(id, name) {
  Swal.fire({
    title: 'Delete Material?',
    text: '"' + name + '" will be permanently deleted.',
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
