<?php
// ── Retail: Pricing Rules ──────────────────────────────────────
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
        $ruleName     = sanitize($_POST['rule_name']     ?? '');
        $ruleType     = sanitize($_POST['rule_type']     ?? 'percentage');
        $discountValue= (float)($_POST['discount_value']  ?? 0);
        $minQty       = (int)($_POST['min_qty']           ?? 1);
        $productId    = (int)($_POST['product_id']        ?? 0) ?: null;
        $categoryId   = (int)($_POST['category_id']       ?? 0) ?: null;
        $startDate    = $_POST['start_date'] ?? null;
        $endDate      = $_POST['end_date']   ?? null;
        $isActive     = isset($_POST['is_active']) ? 1 : 0;

        if (empty($ruleName) || $discountValue <= 0) {
            setFlash('danger', 'Rule name and discount value are required.');
            redirect('pricing.php');
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE retail_pricing_rules SET rule_name=?, rule_type=?, discount_value=?, min_qty=?, product_id=?, category_id=?, start_date=?, end_date=?, is_active=? WHERE id=? AND org_id=?");
                $stmt->execute([$ruleName, $ruleType, $discountValue, $minQty, $productId, $categoryId, $startDate ?: null, $endDate ?: null, $isActive, $id, $orgId]);
                setFlash('success', "Pricing rule '{$ruleName}' updated.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO retail_pricing_rules (org_id, rule_name, rule_type, discount_value, min_qty, product_id, category_id, start_date, end_date, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$orgId, $ruleName, $ruleType, $discountValue, $minQty, $productId, $categoryId, $startDate ?: null, $endDate ?: null, $isActive]);
                setFlash('success', "Pricing rule '{$ruleName}' created.");
                logActivity('create', 'retail', "Created pricing rule: {$ruleName}");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('pricing.php');
    }

    if ($action === 'toggle') {
        $rid = (int)($_POST['rule_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE retail_pricing_rules SET is_active = NOT is_active WHERE id=? AND org_id=?")->execute([$rid, $orgId]);
            setFlash('success', 'Rule status toggled.');
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('pricing.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$products   = $categories = $rules = [];
try {
    $stmt = $pdo->prepare("SELECT id, product_name FROM retail_products WHERE org_id=? ORDER BY product_name");
    $stmt->execute([$orgId]); $products = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, name FROM retail_categories WHERE org_id=? ORDER BY name");
    $stmt->execute([$orgId]); $categories = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT r.*, p.product_name, c.name AS category_name
        FROM retail_pricing_rules r
        LEFT JOIN retail_products p ON p.id = r.product_id
        LEFT JOIN retail_categories c ON c.id = r.category_id
        WHERE r.org_id=?
        ORDER BY r.is_active DESC, r.rule_name
    ");
    $stmt->execute([$orgId]); $rules = $stmt->fetchAll();
} catch (Exception $e) {}

$activeRules = count(array_filter($rules, fn($r) => $r['is_active']));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-tags me-2" style="color:<?= $moduleColor ?>"></i>Pricing Rules</h4>
    <p class="text-muted mb-0">Configure discounts, bulk pricing, and promotional rules</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#ruleModal" onclick="resetForm()">
    <i class="fas fa-plus-circle me-1"></i>New Pricing Rule
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,0.12);color:#2980b9"><i class="fas fa-tags"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($rules) ?></div><div class="stat-label">Total Rules</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-toggle-on"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeRules ?></div><div class="stat-label">Active Rules</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-toggle-off"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($rules) - $activeRules ?></div><div class="stat-label">Inactive Rules</div></div>
    </div>
  </div>
</div>

<!-- Rules Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="rulesTable">
        <thead class="table-light">
          <tr>
            <th>Rule Name</th>
            <th>Type</th>
            <th class="text-end">Discount</th>
            <th class="text-center">Min Qty</th>
            <th>Applies To</th>
            <th>Validity</th>
            <th class="text-center">Active</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rules)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-tags fa-3x mb-3 d-block"></i>No pricing rules defined.</td></tr>
          <?php else: foreach ($rules as $r): ?>
          <tr>
            <td class="fw-semibold"><?= e($r['rule_name']) ?></td>
            <td>
              <?php if ($r['rule_type']==='percentage'): ?>
                <span class="badge bg-info text-dark">% Percentage</span>
              <?php elseif ($r['rule_type']==='fixed'): ?>
                <span class="badge bg-secondary">Fixed Amount</span>
              <?php else: ?>
                <span class="badge bg-light text-dark border">Buy X Get Y</span>
              <?php endif; ?>
            </td>
            <td class="text-end fw-bold">
              <?= $r['rule_type']==='percentage' ? number_format((float)$r['discount_value'],1).'%' : formatCurrency((float)$r['discount_value']) ?>
            </td>
            <td class="text-center"><?= (int)$r['min_qty'] ?> units</td>
            <td>
              <?php if ($r['product_name']): ?>
                <i class="fas fa-box me-1 text-muted"></i><?= e($r['product_name']) ?>
              <?php elseif ($r['category_name']): ?>
                <i class="fas fa-tag me-1 text-muted"></i><?= e($r['category_name']) ?> (category)
              <?php else: ?>
                <span class="text-muted">All products</span>
              <?php endif; ?>
            </td>
            <td class="small">
              <?= $r['start_date'] ? formatDate($r['start_date']) : '—' ?>
              <?= $r['end_date'] ? ' → ' . formatDate($r['end_date']) : '' ?>
            </td>
            <td class="text-center">
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="rule_id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm <?= $r['is_active'] ? 'btn-success' : 'btn-outline-secondary' ?>" type="submit" title="Toggle">
                  <i class="fas <?= $r['is_active'] ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                </button>
              </form>
            </td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= json_encode($r) ?>)'><i class="fas fa-edit"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="ruleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="ruleId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="ruleModalTitle"><i class="fas fa-tags me-2"></i>New Pricing Rule</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Rule Name <span class="text-danger">*</span></label>
              <input type="text" name="rule_name" id="ruleName" class="form-control" required placeholder="e.g. Bulk Buy Discount">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Discount Type</label>
              <select name="rule_type" id="ruleType" class="form-select">
                <option value="percentage">Percentage (%)</option>
                <option value="fixed">Fixed Amount</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Discount Value <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="discount_value" id="ruleValue" class="form-control" required min="0.01" placeholder="e.g. 10 or 500">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Min. Quantity</label>
              <input type="number" name="min_qty" id="ruleMinQty" class="form-control" min="1" value="1">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Apply to Product (Optional)</label>
              <select name="product_id" id="ruleProduct" class="form-select">
                <option value="">All Products</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['product_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">Apply to Category (Optional)</label>
              <select name="category_id" id="ruleCategory" class="form-select">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Start Date</label>
              <input type="date" name="start_date" id="ruleStart" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">End Date</label>
              <input type="date" name="end_date" id="ruleEnd" class="form-control">
            </div>
            <div class="col-md-3 d-flex align-items-end">
              <div class="form-check form-switch">
                <input type="checkbox" name="is_active" id="ruleActive" class="form-check-input" checked>
                <label class="form-check-label fw-semibold" for="ruleActive">Active</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Rule</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#rulesTable").DataTable({pageLength:15, order:[[6,"desc"],[0,"asc"]]});
});
function resetForm() {
    $("#ruleId").val(0);
    $("#ruleModalTitle").html('<i class="fas fa-tags me-2"></i>New Pricing Rule');
    document.querySelector("#ruleModal form").reset();
    $("#ruleActive").prop("checked", true);
}
function openEdit(r) {
    $("#ruleId").val(r.id);
    $("#ruleModalTitle").html('<i class="fas fa-edit me-2"></i>Edit Pricing Rule');
    $("#ruleName").val(r.rule_name);
    $("#ruleType").val(r.rule_type);
    $("#ruleValue").val(r.discount_value);
    $("#ruleMinQty").val(r.min_qty);
    $("#ruleProduct").val(r.product_id || "");
    $("#ruleCategory").val(r.category_id || "");
    $("#ruleStart").val(r.start_date ? r.start_date.substr(0,10) : "");
    $("#ruleEnd").val(r.end_date ? r.end_date.substr(0,10) : "");
    $("#ruleActive").prop("checked", r.is_active == 1);
    $("#ruleModal").modal("show");
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
