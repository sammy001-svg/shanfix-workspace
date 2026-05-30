<?php
// ── Retail: Customer Registry ──────────────────────────────────
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
        $id          = (int)($_POST['id'] ?? 0);
        $name        = sanitize($_POST['name'] ?? '');
        $phone       = sanitize($_POST['phone'] ?? '');
        $email       = sanitize($_POST['email'] ?? '');
        $address     = sanitize($_POST['address'] ?? '');
        $customerType= in_array($_POST['customer_type'] ?? '', ['retail','wholesale','vip','online']) ? $_POST['customer_type'] : 'retail';
        $creditLimit = (float)($_POST['credit_limit'] ?? 0);
        $notes       = sanitize($_POST['notes'] ?? '');

        if ($id) {
            $pdo->prepare("UPDATE retail_customers SET name=?,phone=?,email=?,address=?,customer_type=?,credit_limit=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$name,$phone,$email,$address,$customerType,$creditLimit,$notes,$id,$orgId]);
            setFlash('success', 'Customer updated.');
        } else {
            $pdo->prepare("INSERT INTO retail_customers (org_id,name,phone,email,address,customer_type,credit_limit,notes) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$name,$phone,$email,$address,$customerType,$creditLimit,$notes]);
            setFlash('success', 'Customer added.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM retail_customers WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Customer deleted.');
    }
    redirect('customers.php');
}

// Fetch
$typeFilter = sanitize($_GET['type'] ?? '');
$search     = sanitize($_GET['q'] ?? '');
$sql = "SELECT * FROM retail_customers WHERE org_id=?";
$params = [$orgId];
if ($typeFilter) { $sql .= " AND customer_type=?"; $params[] = $typeFilter; }
if ($search)     { $sql .= " AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$customers = $stmt->fetchAll();

// KPIs
$totalCustomers = countRows($pdo, 'retail_customers', 'org_id=?', [$orgId]);
$vipCustomers   = countRows($pdo, 'retail_customers', 'org_id=? AND customer_type=?', [$orgId,'vip']);
$wholesale      = countRows($pdo, 'retail_customers', 'org_id=? AND customer_type=?', [$orgId,'wholesale']);

$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM retail_customers WHERE id=? AND org_id=?");
    $stmt->execute([(int)$_GET['edit'],$orgId]); $editRow = $stmt->fetch();
}

$typeColors = ['retail'=>'primary','wholesale'=>'success','vip'=>'warning','online'=>'info'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Customer Registry</h4>
    <p class="text-muted mb-0">Manage retail and wholesale customer accounts and credit limits</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#custModal" onclick="resetForm()">
    <i class="fas fa-plus me-1"></i>Add Customer
  </button>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,0.12);color:#2980b9"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalCustomers ?></div><div class="stat-label">Total Customers</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-star"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $vipCustomers ?></div><div class="stat-label">VIP Customers</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-industry"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $wholesale ?></div><div class="stat-label">Wholesale Accounts</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, phone, email…" value="<?= e($search) ?>">
      </div>
      <div class="col-sm-3">
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <?php foreach (['retail','wholesale','vip','online'] as $t): ?>
          <option value="<?= $t ?>" <?= $typeFilter===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if ($typeFilter||$search): ?><div class="col-auto"><a href="customers.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
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
            <th class="ps-3">Name</th>
            <th>Phone</th>
            <th>Email</th>
            <th class="text-center">Type</th>
            <th class="text-end">Credit Limit</th>
            <th>Address</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($customers)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No customers registered yet.</td></tr>
          <?php else: foreach ($customers as $c): ?>
          <tr>
            <td class="ps-3 fw-semibold"><?= e($c['name']) ?></td>
            <td><?= e($c['phone']) ?></td>
            <td><?= e($c['email']) ?></td>
            <td class="text-center"><span class="badge bg-<?= $typeColors[$c['customer_type']] ?? 'secondary' ?>"><?= ucfirst($c['customer_type']) ?></span></td>
            <td class="text-end"><?= $c['credit_limit']>0 ? formatCurrency($c['credit_limit']) : '—' ?></td>
            <td class="text-muted small"><?= e($c['address']) ?></td>
            <td class="text-end pe-3">
              <a href="customers.php?edit=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary me-1"
                 data-bs-toggle="modal" data-bs-target="#custModal"
                 onclick="fillForm(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
              </a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete customer?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
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

<!-- Modal -->
<div class="modal fade" id="custModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-users me-2"></i><span id="modalTitle">Add Customer</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="fId" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="fName" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Customer Type</label>
              <select name="customer_type" id="fType" class="form-select">
                <option value="retail">Retail</option>
                <option value="wholesale">Wholesale</option>
                <option value="vip">VIP</option>
                <option value="online">Online</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="fPhone" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="fEmail" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Credit Limit (KES)</label>
              <input type="number" name="credit_limit" id="fCreditLimit" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Address</label>
              <input type="text" name="address" id="fAddress" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Customer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function resetForm() {
  document.getElementById('modalTitle').textContent = 'Add Customer';
  ['fId','fName','fPhone','fEmail','fAddress','fNotes'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('fType').value        = 'retail';
  document.getElementById('fCreditLimit').value = '0';
}
function fillForm(c) {
  document.getElementById('modalTitle').textContent = 'Edit Customer';
  document.getElementById('fId').value          = c.id;
  document.getElementById('fName').value        = c.name;
  document.getElementById('fPhone').value       = c.phone;
  document.getElementById('fEmail').value       = c.email;
  document.getElementById('fType').value        = c.customer_type;
  document.getElementById('fCreditLimit').value = c.credit_limit;
  document.getElementById('fAddress').value     = c.address;
  document.getElementById('fNotes').value       = c.notes;
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
