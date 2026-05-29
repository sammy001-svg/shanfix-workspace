<?php
$moduleSlug  = 'accounting';
$moduleName  = 'Accounting & Bookkeeping';
$moduleIcon  = 'fas fa-calculator';
$moduleColor = '#1A8A4E';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'accounts.php',       'icon' => 'fas fa-list',            'label' => 'Chart of Accounts'],
    ['url' => 'transactions.php',   'icon' => 'fas fa-exchange-alt',    'label' => 'Journal Entries'],
    ['url' => 'invoices.php',       'icon' => 'fas fa-file-invoice',    'label' => 'Invoices'],
    ['url' => 'payments.php',       'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'expenses.php',       'icon' => 'fas fa-receipt',         'label' => 'Expenses'],
    ['url' => 'bills.php',          'icon' => 'fas fa-file-import',     'label' => 'Vendor Bills'],
    ['url' => 'budgets.php',        'icon' => 'fas fa-bullseye',        'label' => 'Budgets'],
    ['url' => 'taxes.php',          'icon' => 'fas fa-percentage',      'label' => 'Tax Rates'],
    ['url' => 'assets.php',         'icon' => 'fas fa-building',        'label' => 'Fixed Assets'],
    ['url' => 'payroll-journal.php','icon' => 'fas fa-file-alt',        'label' => 'Payroll Journal'],
    ['url' => 'audit.php',          'icon' => 'fas fa-history',         'label' => 'Audit Trail'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
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
        $assetName    = sanitize($_POST['asset_name'] ?? '');
        $category     = sanitize($_POST['category'] ?? 'equipment');
        $purchaseDate = $_POST['purchase_date'] ?? date('Y-m-d');
        $purchaseCost = (float)($_POST['purchase_cost'] ?? 0);
        $salvageValue = (float)($_POST['salvage_value'] ?? 0);
        $usefulLife   = (int)($_POST['useful_life_years'] ?? 1);
        $deprMethod   = in_array($_POST['depreciation_method'] ?? '', ['straight_line','declining_balance']) ? $_POST['depreciation_method'] : 'straight_line';
        $accDepr      = (float)($_POST['accumulated_depreciation'] ?? 0);
        $status       = in_array($_POST['status'] ?? '', ['active','disposed','written_off']) ? $_POST['status'] : 'active';

        if (empty($assetName) || $purchaseCost <= 0) { setFlash('danger', 'Asset name and purchase cost are required.'); redirect('assets.php'); }

        if ($id > 0) {
            $pdo->prepare("UPDATE acc_assets SET asset_name=?,category=?,purchase_date=?,purchase_cost=?,salvage_value=?,useful_life_years=?,depreciation_method=?,accumulated_depreciation=?,status=? WHERE id=? AND org_id=?")
                ->execute([$assetName,$category,$purchaseDate,$purchaseCost,$salvageValue,$usefulLife,$deprMethod,$accDepr,$status,$id,$orgId]);
            setFlash('success', 'Asset updated.');
            logActivity('update', 'accounting', "Updated asset: $assetName");
        } else {
            $seq = 1;
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM acc_assets WHERE org_id=?");
                $stmt->execute([$orgId]);
                $seq = (int)$stmt->fetchColumn() + 1;
            } catch (Exception $e) {}
            $assetCode = 'AST-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO acc_assets(org_id,asset_code,asset_name,category,purchase_date,purchase_cost,salvage_value,useful_life_years,depreciation_method,accumulated_depreciation,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$assetCode,$assetName,$category,$purchaseDate,$purchaseCost,$salvageValue,$usefulLife,$deprMethod,$accDepr,$status]);
            setFlash('success', "Asset '$assetCode' registered.");
            logActivity('create', 'accounting', "Registered asset: $assetName ($assetCode)");
        }
        redirect('assets.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM acc_assets WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Asset deleted.');
        redirect('assets.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$assets = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM acc_assets WHERE org_id=? ORDER BY created_at DESC");
    $stmt->execute([$orgId]);
    $assets = $stmt->fetchAll();
} catch (Exception $e) {}

$totalAssets  = count($assets);
$disposedCount = 0;
$totalCost     = 0;
$totalBookVal  = 0;
foreach ($assets as $a) {
    $totalCost    += (float)$a['purchase_cost'];
    $bookVal       = (float)$a['purchase_cost'] - (float)$a['accumulated_depreciation'];
    $totalBookVal += max($bookVal, 0);
    if ($a['status'] === 'disposed') $disposedCount++;
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-building me-2" style="color:<?= $moduleColor ?>"></i>Fixed Asset Register</h4>
    <p class="text-muted mb-0">Track, depreciate and manage organisation assets</p>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#assetModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Asset
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-building"></i></div><div class="stat-body"><div class="stat-value"><?= $totalAssets ?></div><div class="stat-label">Total Assets</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-dollar-sign"></i></div><div class="stat-body"><div class="stat-value"><?= formatCurrency($totalCost) ?></div><div class="stat-label">Total Cost</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-chart-line"></i></div><div class="stat-body"><div class="stat-value"><?= formatCurrency($totalBookVal) ?></div><div class="stat-label">Total Book Value</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-trash-alt"></i></div><div class="stat-body"><div class="stat-value"><?= $disposedCount ?></div><div class="stat-label">Disposed</div></div></div></div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-building me-2" style="color:<?= $moduleColor ?>"></i>Assets</h6>
    <span class="badge bg-secondary"><?= count($assets) ?> assets</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="assetTable">
        <thead class="table-light">
          <tr><th>Code</th><th>Asset Name</th><th>Category</th><th>Purchase Date</th><th class="text-end">Cost</th><th class="text-end">Accum. Depr.</th><th class="text-end">Book Value</th><th>Annual Depr.</th><th>Method</th><th>Status</th><th class="text-center no-print">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($assets)): ?>
          <tr><td colspan="11" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No assets registered.</td></tr>
          <?php else: foreach ($assets as $a):
            $bookVal   = max((float)$a['purchase_cost'] - (float)$a['accumulated_depreciation'], 0);
            $depreciable = (float)$a['purchase_cost'] - (float)$a['salvage_value'];
            $annualDepr  = $a['useful_life_years'] > 0 ? $depreciable / (int)$a['useful_life_years'] : 0;
          ?>
          <tr>
            <td><code class="bg-light px-2 py-1 rounded text-dark"><?= e($a['asset_code'] ?? '—') ?></code></td>
            <td class="fw-semibold"><?= e($a['asset_name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst(str_replace('_',' ',$a['category'])) ?></span></td>
            <td><?= formatDate($a['purchase_date'] ?? '') ?></td>
            <td class="text-end"><?= formatCurrency((float)$a['purchase_cost']) ?></td>
            <td class="text-end text-danger"><?= formatCurrency((float)$a['accumulated_depreciation']) ?></td>
            <td class="text-end fw-semibold text-success"><?= formatCurrency($bookVal) ?></td>
            <td class="text-end text-muted"><?= formatCurrency($annualDepr) ?>/yr</td>
            <td><span class="badge bg-light text-dark border"><?= ucwords(str_replace('_',' ',$a['depreciation_method'])) ?></span></td>
            <td><?= statusBadge($a['status'] ?? 'active') ?></td>
            <td class="text-center no-print" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='fillForm(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delAsset(<?= $a['id'] ?>,'<?= e($a['asset_name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="assetModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="assetId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="assetModalTitle"><i class="fas fa-building me-2"></i>Register Asset</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label fw-semibold">Asset Name <span class="text-danger">*</span></label><input type="text" name="asset_name" id="assetName" class="form-control" required maxlength="255"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Category</label><select name="category" id="assetCat" class="form-select"><option value="land">Land</option><option value="building">Building</option><option value="vehicle">Vehicle</option><option value="equipment">Equipment</option><option value="furniture">Furniture</option><option value="computer">Computer</option><option value="other">Other</option></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Purchase Date</label><input type="date" name="purchase_date" id="assetPDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Purchase Cost (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="purchase_cost" id="assetCost" class="form-control" step="0.01" min="0" value="0" required></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Salvage Value (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="salvage_value" id="assetSalvage" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Useful Life (Years)</label><input type="number" name="useful_life_years" id="assetLife" class="form-control" min="1" value="5"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Depreciation Method</label><select name="depreciation_method" id="assetDeprMethod" class="form-select"><option value="straight_line">Straight Line</option><option value="declining_balance">Declining Balance</option></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Accumulated Depreciation (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="accumulated_depreciation" id="assetAccDepr" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="assetStatus" class="form-select"><option value="active">Active</option><option value="disposed">Disposed</option><option value="written_off">Written Off</option></select></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Asset</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delAssetForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delAssetId"></form>
<?php
$extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('assetModalTitle').innerHTML='<i class="fas fa-building me-2"></i>Register Asset';
  document.getElementById('assetId').value='0';
  document.getElementById('assetName').value='';
  document.getElementById('assetCat').value='equipment';
  document.getElementById('assetPDate').value=new Date().toISOString().split('T')[0];
  document.getElementById('assetCost').value=0;
  document.getElementById('assetSalvage').value=0;
  document.getElementById('assetLife').value=5;
  document.getElementById('assetDeprMethod').value='straight_line';
  document.getElementById('assetAccDepr').value=0;
  document.getElementById('assetStatus').value='active';
}
function fillForm(a){
  document.getElementById('assetModalTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Asset';
  document.getElementById('assetId').value=a.id;
  document.getElementById('assetName').value=a.asset_name||'';
  document.getElementById('assetCat').value=a.category||'equipment';
  document.getElementById('assetPDate').value=a.purchase_date||'';
  document.getElementById('assetCost').value=a.purchase_cost||0;
  document.getElementById('assetSalvage').value=a.salvage_value||0;
  document.getElementById('assetLife').value=a.useful_life_years||5;
  document.getElementById('assetDeprMethod').value=a.depreciation_method||'straight_line';
  document.getElementById('assetAccDepr').value=a.accumulated_depreciation||0;
  document.getElementById('assetStatus').value=a.status||'active';
  new bootstrap.Modal(document.getElementById('assetModal')).show();
}
function delAsset(id,name){
  Swal.fire({title:'Delete Asset?',text:name+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delAssetId').value=id;document.getElementById('delAssetForm').submit();}});
}
$(document).ready(function(){$('#assetTable').DataTable({pageLength:15,order:[[3,'desc']],language:{emptyTable:'No assets registered.'}});});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
