<?php
$moduleSlug  = 'manufacturing';
$moduleName  = 'Manufacturing';
$moduleIcon  = 'fas fa-industry';
$moduleColor = '#d35400';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'products.php',   'icon' => 'fas fa-box',            'label' => 'Products'],
    ['url' => 'materials.php',  'icon' => 'fas fa-cubes',          'label' => 'Raw Materials'],
    ['url' => 'bom.php',        'icon' => 'fas fa-list-alt',       'label' => 'Bill of Materials'],
    ['url' => 'production.php', 'icon' => 'fas fa-industry',       'label' => 'Production Orders'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_bom') {
        $id          = (int)($_POST['id'] ?? 0);
        $productId   = (int)($_POST['product_id'] ?? 0);
        $materialId  = (int)($_POST['material_id'] ?? 0);
        $qtyNeeded   = (float)($_POST['quantity_needed'] ?? 0);
        $unit        = sanitize($_POST['unit'] ?? '');

        if ($productId <= 0 || $materialId <= 0 || $qtyNeeded <= 0) {
            setFlash('danger', 'Product, material, and quantity are all required.');
            redirect('bom.php?product_id=' . $productId);
        }

        // Verify product belongs to org
        $chk = $pdo->prepare("SELECT id FROM mfg_products WHERE id=? AND org_id=?");
        $chk->execute([$productId, $orgId]);
        if (!$chk->fetch()) {
            setFlash('danger', 'Invalid product.');
            redirect('bom.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE mfg_bom SET material_id=?, quantity_needed=?, unit=? WHERE id=? AND product_id=? AND org_id=?")
                ->execute([$materialId, $qtyNeeded, $unit, $id, $productId, $orgId]);
            setFlash('success', 'BOM line updated.');
            logActivity('update', 'manufacturing', "Updated BOM line #$id");
        } else {
            // Check duplicate material on same product
            $dup = $pdo->prepare("SELECT id FROM mfg_bom WHERE product_id=? AND material_id=? AND org_id=?");
            $dup->execute([$productId, $materialId, $orgId]);
            if ($dup->fetch()) {
                setFlash('danger', 'This material is already in the BOM for this product. Edit the existing line instead.');
                redirect('bom.php?product_id=' . $productId);
            }
            $pdo->prepare("INSERT INTO mfg_bom (org_id, product_id, material_id, quantity_needed, unit) VALUES (?,?,?,?,?)")
                ->execute([$orgId, $productId, $materialId, $qtyNeeded, $unit]);
            setFlash('success', 'Material added to BOM.');
            logActivity('create', 'manufacturing', "Added material #$materialId to BOM for product #$productId");
        }
        redirect('bom.php?product_id=' . $productId);
    }

    if ($action === 'delete_bom') {
        $id        = (int)($_POST['id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $pdo->prepare("DELETE FROM mfg_bom WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'BOM line deleted.');
        logActivity('delete', 'manufacturing', "Deleted BOM line #$id");
        redirect('bom.php?product_id=' . $productId);
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user      = currentUser();
$orgId     = (int)$user['org_id'];
$productId = (int)($_GET['product_id'] ?? 0);

// All products for this org
$products = [];
try {
    $stmt = $pdo->prepare("SELECT id, code, name, selling_price, cost_price FROM mfg_products WHERE org_id=? ORDER BY code, name");
    $stmt->execute([$orgId]);
    $products = $stmt->fetchAll();
} catch (Exception $e) {}

// Selected product
$selectedProduct = null;
if ($productId > 0) {
    foreach ($products as $p) {
        if ((int)$p['id'] === $productId) { $selectedProduct = $p; break; }
    }
}

// BOM lines for selected product
$bomLines = [];
$totalMaterialCost = 0;
if ($selectedProduct) {
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, m.name AS material_name, m.unit AS material_unit, m.unit_cost, m.stock AS mat_stock
            FROM mfg_bom b
            LEFT JOIN mfg_raw_materials m ON b.material_id = m.id
            WHERE b.product_id = ? AND b.org_id = ?
            ORDER BY m.name
        ");
        $stmt->execute([$productId, $orgId]);
        $bomLines = $stmt->fetchAll();
        foreach ($bomLines as $bl) {
            $totalMaterialCost += (float)$bl['quantity_needed'] * (float)$bl['unit_cost'];
        }
    } catch (Exception $e) {}
}

// All materials for dropdown
$materials = [];
try {
    $stmt = $pdo->prepare("SELECT id, code, name, unit, unit_cost FROM mfg_raw_materials WHERE org_id=? ORDER BY name");
    $stmt->execute([$orgId]);
    $materials = $stmt->fetchAll();
} catch (Exception $e) {}

$sellingPrice  = $selectedProduct ? (float)$selectedProduct['selling_price'] : 0;
$margin        = $sellingPrice > 0 && $totalMaterialCost > 0
    ? round(($sellingPrice - $totalMaterialCost) / $sellingPrice * 100, 1)
    : ($sellingPrice > 0 ? 100 : 0);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-list-alt me-2" style="color:<?= $moduleColor ?>"></i>Bill of Materials</h4>
    <p class="text-muted mb-0">Define raw material requirements per finished product</p>
  </div>
  <?php if ($selectedProduct): ?>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#bomModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Material
  </button>
  <?php endif; ?>
</div>

<div class="row g-3">
  <!-- Left: Product List -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between" style="background:<?= $moduleColor ?>15;border-bottom:2px solid <?= $moduleColor ?>">
        <h6 class="mb-0" style="color:<?= $moduleColor ?>"><i class="fas fa-box me-2"></i>Products</h6>
        <span class="badge" style="background:<?= $moduleColor ?>"><?= count($products) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($products)): ?>
        <div class="text-center text-muted py-4">
          <i class="fas fa-box fa-2x mb-2 opacity-25"></i><br>
          No products found.<br>
          <a href="products.php" class="btn btn-sm btn-outline-secondary mt-2">Add Products</a>
        </div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($products as $p):
            $bomCount = countRows('mfg_bom', 'product_id = ? AND org_id = ?', [(int)$p['id'], $orgId]);
            $isSelected = (int)$p['id'] === $productId;
          ?>
          <li class="list-group-item <?= $isSelected ? 'active' : '' ?>" style="<?= $isSelected ? 'background:'.$moduleColor.';color:#fff;border-color:'.$moduleColor : '' ?>">
            <a href="bom.php?product_id=<?= $p['id'] ?>" class="text-decoration-none d-flex align-items-center justify-content-between"
               style="color:<?= $isSelected ? '#fff' : 'inherit' ?>">
              <div>
                <div class="fw-semibold"><?= e($p['name']) ?></div>
                <div class="small <?= $isSelected ? 'opacity-75' : 'text-muted' ?>"><?= e($p['code'] ?? '') ?></div>
              </div>
              <span class="badge <?= $isSelected ? 'bg-white text-dark' : 'bg-secondary' ?>"><?= $bomCount ?> mat<?= $bomCount != 1 ? 's' : '' ?></span>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: BOM Details -->
  <div class="col-lg-8">
    <?php if (!$selectedProduct): ?>
    <div class="card">
      <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-arrow-left fa-2x mb-3 opacity-25"></i>
        <h5>Select a product</h5>
        <p>Click a product on the left to view or edit its Bill of Materials.</p>
      </div>
    </div>
    <?php else: ?>

    <!-- Product Summary -->
    <div class="card mb-3" style="border-left:4px solid <?= $moduleColor ?>">
      <div class="card-body">
        <div class="row align-items-center">
          <div class="col-md-5">
            <h6 class="fw-bold mb-1"><?= e($selectedProduct['name']) ?></h6>
            <span class="text-muted small"><?= e($selectedProduct['code'] ?? '') ?></span>
          </div>
          <div class="col-md-7">
            <div class="row text-center">
              <div class="col-4">
                <div class="small text-muted">Material Cost</div>
                <div class="fw-bold"><?= formatCurrency($totalMaterialCost) ?></div>
              </div>
              <div class="col-4">
                <div class="small text-muted">Selling Price</div>
                <div class="fw-bold"><?= formatCurrency($sellingPrice) ?></div>
              </div>
              <div class="col-4">
                <div class="small text-muted">Gross Margin</div>
                <div class="fw-bold <?= $margin >= 30 ? 'text-success' : ($margin >= 10 ? 'text-warning' : 'text-danger') ?>"><?= $margin ?>%</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- BOM Table -->
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-list-alt me-2" style="color:<?= $moduleColor ?>"></i>Bill of Materials — <?= e($selectedProduct['name']) ?></h6>
        <button class="btn btn-sm" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#bomModal" onclick="openAdd()">
          <i class="fas fa-plus me-1"></i>Add
        </button>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Material</th>
                <th class="text-end">Qty Needed</th>
                <th>Unit</th>
                <th class="text-end">Unit Cost</th>
                <th class="text-end">Line Cost</th>
                <th class="text-end">Stock</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($bomLines)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">
                <i class="fas fa-list-alt fa-2x mb-2 d-block opacity-25"></i>No materials added yet.
              </td></tr>
              <?php else: foreach ($bomLines as $bl):
                $lineCost  = (float)$bl['quantity_needed'] * (float)$bl['unit_cost'];
                $stockOk   = (float)$bl['mat_stock'] >= (float)$bl['quantity_needed'];
              ?>
              <tr>
                <td class="fw-semibold"><?= e($bl['material_name'] ?? '—') ?></td>
                <td class="text-end"><?= number_format((float)$bl['quantity_needed'], 3) ?></td>
                <td><?= e($bl['unit'] ?: $bl['material_unit'] ?: '—') ?></td>
                <td class="text-end"><?= formatCurrency((float)$bl['unit_cost']) ?></td>
                <td class="text-end fw-semibold"><?= formatCurrency($lineCost) ?></td>
                <td class="text-end <?= $stockOk ? 'text-success' : 'text-danger' ?>">
                  <?= number_format((float)$bl['mat_stock'], 3) ?>
                  <?php if (!$stockOk): ?><i class="fas fa-exclamation-triangle ms-1" title="Insufficient stock"></i><?php endif; ?>
                </td>
                <td class="text-center text-nowrap">
                  <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($bl), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                  <button class="btn btn-sm btn-outline-danger ms-1" onclick="delBom(<?= $bl['id'] ?>, '<?= e($bl['material_name'] ?? '') ?>', <?= $productId ?>)" title="Delete"><i class="fas fa-trash"></i></button>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($bomLines)): ?>
            <tfoot class="table-light">
              <tr>
                <th colspan="4" class="text-end">Total Material Cost per Unit:</th>
                <th class="text-end"><?= formatCurrency($totalMaterialCost) ?></th>
                <th colspan="2"></th>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- BOM Add/Edit Modal -->
<?php if ($selectedProduct): ?>
<div class="modal fade" id="bomModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_bom">
        <input type="hidden" name="product_id" value="<?= $productId ?>">
        <input type="hidden" name="id" id="bomId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="bomModalTitle"><i class="fas fa-plus me-2"></i>Add to BOM</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Raw Material <span class="text-danger">*</span></label>
              <select name="material_id" id="bomMaterial" class="form-select" required onchange="fillUnit()">
                <option value="">-- Select Material --</option>
                <?php foreach ($materials as $mat): ?>
                <option value="<?= $mat['id'] ?>" data-unit="<?= e($mat['unit'] ?? '') ?>" data-cost="<?= (float)$mat['unit_cost'] ?>">
                  <?= e($mat['name']) ?> (<?= e($mat['code'] ?? '') ?>) — <?= formatCurrency((float)$mat['unit_cost']) ?>/<?= e($mat['unit'] ?? 'unit') ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Quantity Needed <span class="text-danger">*</span></label>
              <input type="number" name="quantity_needed" id="bomQty" class="form-control" placeholder="0.000" step="0.001" min="0.001" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Unit</label>
              <input type="text" name="unit" id="bomUnit" class="form-control" placeholder="Auto-filled">
            </div>
            <div class="col-12">
              <div class="alert alert-info py-2 mb-0 small" id="bomCostPreview" style="display:none">
                <i class="fas fa-calculator me-1"></i> Line cost: <strong id="bomLineCost">—</strong>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete BOM Form -->
<form method="POST" id="deleteBomForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete_bom">
  <input type="hidden" name="id" id="deleteBomId">
  <input type="hidden" name="product_id" id="deleteBomProduct" value="<?= $productId ?>">
</form>
<?php endif; ?>

<?php
$extraJs = <<<'JS'
<script>
function fillUnit() {
  var sel  = document.getElementById('bomMaterial');
  var opt  = sel.options[sel.selectedIndex];
  var unit = opt ? opt.getAttribute('data-unit') : '';
  var cost = opt ? parseFloat(opt.getAttribute('data-cost') || 0) : 0;
  document.getElementById('bomUnit').value = unit;
  updateLineCost(cost);
}
function updateLineCost(cost) {
  var qty = parseFloat(document.getElementById('bomQty').value || 0);
  if (qty > 0 && cost > 0) {
    document.getElementById('bomCostPreview').style.display = '';
    document.getElementById('bomLineCost').textContent = (qty * cost).toFixed(2);
  } else {
    document.getElementById('bomCostPreview').style.display = 'none';
  }
}
document.addEventListener('DOMContentLoaded', function() {
  var qtyEl = document.getElementById('bomQty');
  if (qtyEl) {
    qtyEl.addEventListener('input', function() {
      var sel  = document.getElementById('bomMaterial');
      var opt  = sel ? sel.options[sel.selectedIndex] : null;
      var cost = opt ? parseFloat(opt.getAttribute('data-cost') || 0) : 0;
      updateLineCost(cost);
    });
  }
});
function openAdd() {
  document.getElementById('bomModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>Add Material to BOM';
  document.getElementById('bomId').value       = '0';
  document.getElementById('bomMaterial').value = '';
  document.getElementById('bomQty').value      = '';
  document.getElementById('bomUnit').value     = '';
  document.getElementById('bomCostPreview').style.display = 'none';
}
function openEdit(bl) {
  document.getElementById('bomModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit BOM Line';
  document.getElementById('bomId').value       = bl.id;
  document.getElementById('bomMaterial').value = bl.material_id || '';
  document.getElementById('bomQty').value      = bl.quantity_needed || '';
  document.getElementById('bomUnit').value     = bl.unit || '';
  new bootstrap.Modal(document.getElementById('bomModal')).show();
}
function delBom(id, name, productId) {
  Swal.fire({
    title: 'Remove from BOM?',
    text: '"' + name + '" will be removed from this product\'s Bill of Materials.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, remove'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteBomId').value      = id;
      document.getElementById('deleteBomProduct').value = productId;
      document.getElementById('deleteBomForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
