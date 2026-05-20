<?php
// ── CARYARD: Customer Registry ─────────────────────────────────
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
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = sanitize($_POST['name']      ?? '');
        $phone   = sanitize($_POST['phone']     ?? '');
        $email   = sanitize($_POST['email']     ?? '');
        $idNo    = sanitize($_POST['id_number'] ?? '');
        $address = sanitize($_POST['address']   ?? '');
        $city    = sanitize($_POST['city']      ?? '');
        $notes   = sanitize($_POST['notes']     ?? '');

        if (empty($name)) {
            setFlash('danger', 'Customer name is required.');
            redirect('customers.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE caryard_customers SET name=?,phone=?,email=?,id_number=?,address=?,city=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$name, $phone, $email, $idNo, $address, $city, $notes, $id, $orgId]);
            setFlash('success', 'Customer updated.');
            logActivity('update', 'caryard', "Updated customer: $name");
        } else {
            $pdo->prepare("INSERT INTO caryard_customers (org_id,name,phone,email,id_number,address,city,notes) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $name, $phone, $email, $idNo, $address, $city, $notes]);
            setFlash('success', "Customer '$name' added.");
            logActivity('create', 'caryard', "Added customer: $name");
        }
        redirect('customers.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM caryard_customers WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Customer deleted.');
        logActivity('delete', 'caryard', "Deleted customer #$id");
        redirect('customers.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch customers with purchase history count
$customers = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*,
               COUNT(DISTINCT s.id)  AS purchase_count,
               COALESCE(SUM(s.sale_price), 0) AS total_spent,
               MAX(s.sale_date) AS last_purchase
        FROM caryard_customers c
        LEFT JOIN caryard_sales s ON s.buyer_email = c.email AND s.org_id = c.org_id
        WHERE c.org_id = ?
        GROUP BY c.id
        ORDER BY c.name ASC
    ");
    $stmt->execute([$orgId]);
    $customers = $stmt->fetchAll();
} catch (Exception $e) {}

$totalCustomers = count($customers);
$withPurchase   = count(array_filter($customers, fn($c) => (int)$c['purchase_count'] > 0));
$totalSpent     = array_sum(array_column($customers, 'total_spent'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Customer Registry</h4>
    <p class="text-muted mb-0">Manage your buyer database, track purchase history, and build relationships</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#custModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Add Customer
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(230,126,34,.12);color:#e67e22"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalCustomers ?></div><div class="stat-label">Total Customers</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-handshake"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $withPurchase ?></div><div class="stat-label">Customers with Purchases</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalSpent) ?></div><div class="stat-label">Total Revenue from Customers</div></div>
    </div>
  </div>
</div>

<!-- Customer Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>All Customers</h6>
    <span class="badge bg-secondary"><?= $totalCustomers ?> customers</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="custTable">
        <thead class="table-light">
          <tr>
            <th>Name</th>
            <th>Contact</th>
            <th>ID / Passport</th>
            <th>City</th>
            <th class="text-center">Purchases</th>
            <th class="text-end">Total Spent</th>
            <th>Last Purchase</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($customers)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted">
            <i class="fas fa-users fa-2x mb-2 d-block"></i>No customers yet. Add your first customer.
          </td></tr>
          <?php else: foreach ($customers as $c): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($c['name']) ?></div>
              <?php if ($c['city']): ?><div class="small text-muted"><?= e($c['city']) ?></div><?php endif; ?>
            </td>
            <td>
              <div class="small"><?= e($c['phone'] ?: '—') ?></div>
              <div class="small text-muted"><?= e($c['email'] ?: '') ?></div>
            </td>
            <td class="small text-muted"><?= e($c['id_number'] ?: '—') ?></td>
            <td class="small"><?= e($c['city'] ?: '—') ?></td>
            <td class="text-center">
              <?php if ((int)$c['purchase_count'] > 0): ?>
                <span class="badge" style="background:rgba(230,126,34,.15);color:#e67e22"><?= $c['purchase_count'] ?></span>
              <?php else: ?>
                <span class="badge bg-light text-muted">0</span>
              <?php endif; ?>
            </td>
            <td class="text-end fw-semibold <?= (float)$c['total_spent'] > 0 ? 'text-success' : 'text-muted' ?>">
              <?= formatCurrency((float)$c['total_spent']) ?>
            </td>
            <td class="small text-muted"><?= $c['last_purchase'] ? formatDate($c['last_purchase']) : '—' ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this customer?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="custModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="custId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="custTitle"><i class="fas fa-user-plus me-2"></i>Add Customer</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="custName" class="form-control" required placeholder="e.g. James Mwangi">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="custPhone" class="form-control" placeholder="+254 712 345678">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="custEmail" class="form-control" placeholder="email@example.com">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">National ID / Passport No.</label>
              <input type="text" name="id_number" id="custIdNo" class="form-control" placeholder="e.g. 12345678">
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Address</label>
              <input type="text" name="address" id="custAddress" class="form-control" placeholder="Physical address">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">City / Town</label>
              <input type="text" name="city" id="custCity" class="form-control" placeholder="e.g. Nairobi">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="custNotes" class="form-control" rows="2" placeholder="Additional notes about this customer..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Customer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("custTitle").innerHTML = "<i class=\"fas fa-user-plus me-2\"></i>Add Customer";
  document.getElementById("custId").value      = 0;
  document.getElementById("custName").value    = "";
  document.getElementById("custPhone").value   = "";
  document.getElementById("custEmail").value   = "";
  document.getElementById("custIdNo").value    = "";
  document.getElementById("custAddress").value = "";
  document.getElementById("custCity").value    = "";
  document.getElementById("custNotes").value   = "";
}
function openEdit(c) {
  document.getElementById("custTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Customer";
  document.getElementById("custId").value      = c.id;
  document.getElementById("custName").value    = c.name       || "";
  document.getElementById("custPhone").value   = c.phone      || "";
  document.getElementById("custEmail").value   = c.email      || "";
  document.getElementById("custIdNo").value    = c.id_number  || "";
  document.getElementById("custAddress").value = c.address    || "";
  document.getElementById("custCity").value    = c.city       || "";
  document.getElementById("custNotes").value   = c.notes      || "";
  new bootstrap.Modal(document.getElementById("custModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
