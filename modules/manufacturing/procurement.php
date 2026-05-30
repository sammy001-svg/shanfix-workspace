<?php
// ── Manufacturing: Purchase Orders / Procurement ────────────────
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

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        // Auto-generate PO reference
        $stmt = $pdo->prepare("SELECT COUNT(*)+1 FROM mfg_procurement WHERE org_id=? AND YEAR(created_at)=YEAR(NOW())");
        $stmt->execute([$orgId]);
        $seq   = (int)$stmt->fetchColumn();
        $poRef = 'PO-' . date('Y') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

        $supplierId    = (int)($_POST['supplier_id'] ?? 0);
        $expectedDate  = sanitize($_POST['expected_date'] ?? '');
        $notes         = sanitize($_POST['notes'] ?? '');
        $items         = $_POST['items'] ?? [];

        $totalAmount = 0;
        foreach ($items as $it) {
            $totalAmount += ((float)($it['qty'] ?? 0)) * ((float)($it['unit_price'] ?? 0));
        }

        $pdo->prepare("INSERT INTO mfg_procurement (org_id,po_ref,supplier_id,expected_date,total_amount,status,notes) VALUES (?,?,?,?,?,'draft',?)")
            ->execute([$orgId,$poRef,$supplierId,$expectedDate,$totalAmount,$notes]);
        $poId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO mfg_procurement_items (procurement_id,item_name,qty,unit,unit_price,total_price) VALUES (?,?,?,?,?,?)");
        foreach ($items as $it) {
            $qty   = (float)($it['qty'] ?? 0);
            $price = (float)($it['unit_price'] ?? 0);
            if ($qty > 0) {
                $ins->execute([$poId, sanitize($it['item_name'] ?? ''), $qty, sanitize($it['unit'] ?? ''), $price, $qty * $price]);
            }
        }
        setFlash('success', "Purchase Order $poRef created.");

    } elseif ($action === 'status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['draft','sent','partial','received','cancelled']) ? $_POST['status'] : 'draft';
        $pdo->prepare("UPDATE mfg_procurement SET status=? WHERE id=? AND org_id=?")->execute([$status,$id,$orgId]);
        setFlash('success', 'PO status updated.');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM mfg_procurement_items WHERE procurement_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM mfg_procurement WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Purchase order deleted.');
    }
    redirect('procurement.php');
}

// Suppliers dropdown
$suppliers = $pdo->prepare("SELECT id,name FROM mfg_suppliers WHERE org_id=? AND status='active' ORDER BY name");
$suppliers->execute([$orgId]);
$suppliers = $suppliers->fetchAll();

// Filters
$statusFilter = sanitize($_GET['status'] ?? '');
$search       = sanitize($_GET['q'] ?? '');
$sql = "SELECT p.*, s.name as supplier_name FROM mfg_procurement p LEFT JOIN mfg_suppliers s ON p.supplier_id=s.id WHERE p.org_id=?";
$params = [$orgId];
if ($statusFilter) { $sql .= " AND p.status=?"; $params[] = $statusFilter; }
if ($search)       { $sql .= " AND (p.po_ref LIKE ? OR s.name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY p.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$orders = $stmt->fetchAll();

// KPIs
$totalPOs   = countRows($pdo, 'mfg_procurement', 'org_id=?', [$orgId]);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM mfg_procurement WHERE org_id=? AND status='draft'"); $stmt->execute([$orgId]);
$draftPOs = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM mfg_procurement WHERE org_id=? AND status NOT IN ('cancelled')"); $stmt->execute([$orgId]);
$totalValue = (float)$stmt->fetchColumn();

// View PO items
$viewItems = [];
$viewPO    = null;
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name FROM mfg_procurement p LEFT JOIN mfg_suppliers s ON p.supplier_id=s.id WHERE p.id=? AND p.org_id=?");
    $stmt->execute([(int)$_GET['view'], $orgId]);
    $viewPO = $stmt->fetch();
    if ($viewPO) {
        $stmt = $pdo->prepare("SELECT * FROM mfg_procurement_items WHERE procurement_id=?");
        $stmt->execute([$viewPO['id']]);
        $viewItems = $stmt->fetchAll();
    }
}

$statusColors = ['draft'=>'secondary','sent'=>'info','partial'=>'warning','received'=>'success','cancelled'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-shopping-basket me-2" style="color:<?= $moduleColor ?>"></i>Purchase Orders</h4>
    <p class="text-muted mb-0">Create and track procurement orders from suppliers</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#poModal">
    <i class="fas fa-plus me-1"></i>New Purchase Order
  </button>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(211,84,0,0.12);color:#d35400"><i class="fas fa-shopping-basket"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalPOs ?></div><div class="stat-label">Total POs</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-file-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $draftPOs ?></div><div class="stat-label">Draft / Pending</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalValue) ?></div><div class="stat-label">Total PO Value</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search PO ref or supplier…" value="<?= e($search) ?>">
      </div>
      <div class="col-sm-3">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['draft','sent','partial','received','cancelled'] as $st): ?>
          <option value="<?= $st ?>" <?= $statusFilter===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if ($statusFilter||$search): ?><div class="col-auto"><a href="procurement.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<!-- PO Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr>
            <th class="ps-3">PO Reference</th>
            <th>Supplier</th>
            <th class="text-center">Expected Date</th>
            <th class="text-end">Total Value</th>
            <th class="text-center">Status</th>
            <th class="text-center">Created</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No purchase orders found.</td></tr>
          <?php else: foreach ($orders as $po): ?>
          <tr>
            <td class="ps-3 fw-bold"><code><?= e($po['po_ref']) ?></code></td>
            <td><?= e($po['supplier_name'] ?? '—') ?></td>
            <td class="text-center"><?= $po['expected_date'] ? formatDate($po['expected_date']) : '—' ?></td>
            <td class="text-end fw-bold"><?= formatCurrency($po['total_amount']) ?></td>
            <td class="text-center">
              <form method="post" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="id" value="<?= $po['id'] ?>">
                <select name="status" class="form-select form-select-sm d-inline-block w-auto border-0 bg-transparent text-<?= $statusColors[$po['status']] ?? 'secondary' ?> fw-semibold"
                        onchange="this.form.submit()">
                  <?php foreach (['draft','sent','partial','received','cancelled'] as $st): ?>
                  <option value="<?= $st ?>" <?= $po['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="text-center text-muted small"><?= formatDate($po['created_at']) ?></td>
            <td class="text-end pe-3">
              <a href="procurement.php?view=<?= $po['id'] ?>" class="btn btn-sm btn-outline-info me-1" title="View Items">
                <i class="fas fa-eye"></i>
              </a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this PO?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $po['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" <?= $po['status']==='received'?'disabled':'' ?>>
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

<?php if ($viewPO): ?>
<!-- PO Detail Card -->
<div class="card border-0 shadow-sm mt-4">
  <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
    <h6 class="mb-0 fw-semibold"><i class="fas fa-file-invoice me-2 text-primary"></i>
      PO Detail — <?= e($viewPO['po_ref']) ?> | Supplier: <?= e($viewPO['supplier_name']) ?></h6>
    <a href="procurement.php" class="btn btn-sm btn-outline-secondary">Close</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-bordered align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Item</th>
            <th class="text-center">Qty</th>
            <th class="text-center">Unit</th>
            <th class="text-end">Unit Price</th>
            <th class="text-end pe-3">Line Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($viewItems as $li): ?>
          <tr>
            <td class="ps-3"><?= e($li['item_name']) ?></td>
            <td class="text-center"><?= number_format($li['qty'],2) ?></td>
            <td class="text-center"><?= e($li['unit']) ?></td>
            <td class="text-end"><?= formatCurrency($li['unit_price']) ?></td>
            <td class="text-end pe-3 fw-bold"><?= formatCurrency($li['total_price']) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr class="table-light fw-bold">
            <td colspan="4" class="text-end ps-3">Grand Total</td>
            <td class="text-end pe-3"><?= formatCurrency($viewPO['total_amount']) ?></td>
          </tr>
        </tbody>
      </table>
    </div>
    <?php if ($viewPO['notes']): ?>
    <div class="p-3 border-top"><small class="text-muted"><strong>Notes:</strong> <?= e($viewPO['notes']) ?></small></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Create PO Modal -->
<div class="modal fade" id="poModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-shopping-basket me-2"></i>New Purchase Order</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-body">
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
              <select name="supplier_id" class="form-select" required>
                <option value="">— Select Supplier —</option>
                <?php foreach ($suppliers as $sup): ?>
                <option value="<?= $sup['id'] ?>"><?= e($sup['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Expected Delivery</label>
              <input type="date" name="expected_date" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Notes</label>
              <input type="text" name="notes" class="form-control" placeholder="Optional notes">
            </div>
          </div>

          <!-- Line Items -->
          <h6 class="fw-semibold mb-2">Order Items</h6>
          <div class="table-responsive">
            <table class="table table-bordered align-middle" id="lineTable">
              <thead class="table-light">
                <tr>
                  <th>Item / Material</th>
                  <th style="width:100px">Qty</th>
                  <th style="width:80px">Unit</th>
                  <th style="width:130px">Unit Price (KES)</th>
                  <th style="width:130px">Line Total</th>
                  <th style="width:50px"></th>
                </tr>
              </thead>
              <tbody id="lineBody">
                <tr>
                  <td><input type="text" name="items[0][item_name]" class="form-control form-control-sm" required></td>
                  <td><input type="number" name="items[0][qty]" class="form-control form-control-sm qty" min="0" step="0.01" value="0" oninput="calcLine(this)"></td>
                  <td><input type="text" name="items[0][unit]" class="form-control form-control-sm" value="pcs"></td>
                  <td><input type="number" name="items[0][unit_price]" class="form-control form-control-sm price" min="0" step="0.01" value="0" oninput="calcLine(this)"></td>
                  <td><input type="text" class="form-control form-control-sm line-total bg-light" readonly value="0.00"></td>
                  <td></td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="4" class="text-end fw-bold">Grand Total</td>
                  <td><input type="text" id="grandTotal" class="form-control form-control-sm fw-bold bg-light" readonly value="0.00"></td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addLine()">
            <i class="fas fa-plus me-1"></i>Add Line
          </button>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Create PO</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
var lineIndex = 1;

function calcLine(el) {
  var row   = el.closest('tr');
  var qty   = parseFloat(row.querySelector('.qty').value)   || 0;
  var price = parseFloat(row.querySelector('.price').value) || 0;
  row.querySelector('.line-total').value = (qty * price).toFixed(2);
  updateGrand();
}

function updateGrand() {
  var total = 0;
  document.querySelectorAll('.line-total').forEach(function(el) {
    total += parseFloat(el.value) || 0;
  });
  document.getElementById('grandTotal').value = total.toFixed(2);
}

function addLine() {
  var i = lineIndex++;
  var row = document.createElement('tr');
  row.innerHTML =
    '<td><input type="text" name="items['+i+'][item_name]" class="form-control form-control-sm" required></td>' +
    '<td><input type="number" name="items['+i+'][qty]" class="form-control form-control-sm qty" min="0" step="0.01" value="0" oninput="calcLine(this)"></td>' +
    '<td><input type="text" name="items['+i+'][unit]" class="form-control form-control-sm" value="pcs"></td>' +
    '<td><input type="number" name="items['+i+'][unit_price]" class="form-control form-control-sm price" min="0" step="0.01" value="0" oninput="calcLine(this)"></td>' +
    '<td><input type="text" class="form-control form-control-sm line-total bg-light" readonly value="0.00"></td>' +
    '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove(); updateGrand()"><i class="fas fa-times"></i></button></td>';
  document.getElementById('lineBody').appendChild(row);
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
