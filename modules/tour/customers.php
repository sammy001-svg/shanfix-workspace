<?php
// ── TOUR: Customer Profiles ────────────────────────────────────
$moduleSlug  = 'tour';
$moduleName  = 'Tour Management';
$moduleIcon  = 'fas fa-map-marked-alt';
$moduleColor = '#16a085';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'packages.php',     'icon' => 'fas fa-box-open',       'label' => 'Packages'],
    ['url' => 'destinations.php', 'icon' => 'fas fa-map-marker-alt', 'label' => 'Destinations'],
    ['url' => 'bookings.php',     'icon' => 'fas fa-calendar-check', 'label' => 'Bookings'],
    ['url' => 'customers.php',    'icon' => 'fas fa-user-friends',   'label' => 'Customers'],
    ['url' => 'guides.php',       'icon' => 'fas fa-hiking',         'label' => 'Guides'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
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
        $id          = (int)($_POST['id']          ?? 0);
        $name        = sanitize($_POST['name']        ?? '');
        $phone       = sanitize($_POST['phone']       ?? '');
        $email       = sanitize($_POST['email']       ?? '');
        $idNumber    = sanitize($_POST['id_number']   ?? '');
        $nationality = sanitize($_POST['nationality'] ?? '');
        $address     = sanitize($_POST['address']     ?? '');
        $notes       = sanitize($_POST['notes']       ?? '');
        $status      = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if (!$name) {
            setFlash('danger', 'Customer name is required.');
            redirect('customers.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE tour_customers SET name=?,phone=?,email=?,id_number=?,nationality=?,address=?,notes=?,status=? WHERE id=? AND org_id=?")
                ->execute([$name, $phone, $email, $idNumber, $nationality, $address, $notes, $status, $id, $orgId]);
            setFlash('success', 'Customer updated.');
            logActivity('update', 'tour', "Updated customer: $name");
        } else {
            $pdo->prepare("INSERT INTO tour_customers (org_id,name,phone,email,id_number,nationality,address,notes,status) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $name, $phone, $email, $idNumber, $nationality, $address, $notes, $status]);
            setFlash('success', "Customer '$name' added.");
            logActivity('create', 'tour', "Added customer: $name");
        }
        redirect('customers.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM tour_customers WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Customer deleted.');
        logActivity('delete', 'tour', "Deleted customer #$id");
        redirect('customers.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterStatus = $_GET['status'] ?? '';
$search       = sanitize($_GET['q'] ?? '');
$where  = 'org_id = ?';
$params = [$orgId];
if ($filterStatus) { $where .= ' AND status = ?'; $params[] = $filterStatus; }
if ($search)       { $where .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR nationality LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

$customers = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tour_customers WHERE $where ORDER BY name ASC");
    $stmt->execute($params);
    $customers = $stmt->fetchAll();
} catch (Exception $e) {}

$totalCount    = countRows('tour_customers', 'org_id=?', [$orgId]);
$activeCount   = countRows('tour_customers', "org_id=? AND status='active'", [$orgId]);
$inactiveCount = countRows('tour_customers', "org_id=? AND status='inactive'", [$orgId]);

// Customers with at least 1 trip
$repeatCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tour_customers WHERE org_id=? AND total_trips > 1");
    $stmt->execute([$orgId]);
    $repeatCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-user-friends me-2" style="color:<?= $moduleColor ?>"></i>Tour Customers</h4>
    <p class="text-muted mb-0">Manage your customer profiles and travel history</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#custModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Add Customer
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(22,160,133,.12);color:#16a085"><i class="fas fa-user-friends"></i></div>
      <div><div class="stat-value"><?= $totalCount ?></div><div class="stat-label">Total Customers</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-user-check"></i></div>
      <div><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Active</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-star"></i></div>
      <div><div class="stat-value"><?= $repeatCount ?></div><div class="stat-label">Repeat Customers</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-user-slash"></i></div>
      <div><div class="stat-value"><?= $inactiveCount ?></div><div class="stat-label">Inactive</div></div></div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-5 col-md-4">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, email, phone, nationality..." value="<?= e($search) ?>">
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="active"   <?= $filterStatus==='active'   ?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $filterStatus==='inactive' ?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="customers.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-user-friends me-2" style="color:<?= $moduleColor ?>"></i>Customer List</h6>
    <span class="badge bg-secondary"><?= count($customers) ?> customers</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Customer</th>
            <th>Nationality</th>
            <th>Phone</th>
            <th>Email</th>
            <th class="text-center">Trips</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($customers)): ?>
          <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-user-friends fa-2x mb-2 d-block"></i>No customers added yet.</td></tr>
          <?php else: foreach ($customers as $c): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($c['name']) ?></div>
              <?php if ($c['id_number']): ?><div class="small text-muted">ID: <?= e($c['id_number']) ?></div><?php endif; ?>
            </td>
            <td class="small"><?= e($c['nationality'] ?: '—') ?></td>
            <td class="small"><?= e($c['phone'] ?: '—') ?></td>
            <td>
              <?php if ($c['email']): ?>
                <a href="mailto:<?= e($c['email']) ?>" class="small text-decoration-none"><?= e($c['email']) ?></a>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span class="badge <?= $c['total_trips'] > 1 ? 'bg-success' : 'bg-secondary' ?>"><?= (int)$c['total_trips'] ?></span>
            </td>
            <td><?= statusBadge($c['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this customer?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="custModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="cstId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="cstTitle"><i class="fas fa-user-friends me-2"></i>Add Customer</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="cstName" class="form-control" required placeholder="Customer's full name">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">ID / Passport Number</label>
              <input type="text" name="id_number" id="cstIdNum" class="form-control" placeholder="National ID or passport">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="cstPhone" class="form-control" placeholder="+254...">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="cstEmail" class="form-control" placeholder="email@example.com">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Nationality</label>
              <input type="text" name="nationality" id="cstNat" class="form-control" placeholder="e.g. Kenyan, British">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="cstStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Address</label>
              <input type="text" name="address" id="cstAddr" class="form-control" placeholder="Physical or postal address">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="cstNotes" class="form-control" rows="2" placeholder="Preferences, special requirements..."></textarea>
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
  document.getElementById("cstTitle").innerHTML = "<i class=\"fas fa-user-friends me-2\"></i>Add Customer";
  document.getElementById("cstId").value     = 0;
  document.getElementById("cstName").value   = "";
  document.getElementById("cstIdNum").value  = "";
  document.getElementById("cstPhone").value  = "";
  document.getElementById("cstEmail").value  = "";
  document.getElementById("cstNat").value    = "";
  document.getElementById("cstStatus").value = "active";
  document.getElementById("cstAddr").value   = "";
  document.getElementById("cstNotes").value  = "";
}
function openEdit(c) {
  document.getElementById("cstTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Customer";
  document.getElementById("cstId").value     = c.id;
  document.getElementById("cstName").value   = c.name        || "";
  document.getElementById("cstIdNum").value  = c.id_number   || "";
  document.getElementById("cstPhone").value  = c.phone       || "";
  document.getElementById("cstEmail").value  = c.email       || "";
  document.getElementById("cstNat").value    = c.nationality || "";
  document.getElementById("cstStatus").value = c.status      || "active";
  document.getElementById("cstAddr").value   = c.address     || "";
  document.getElementById("cstNotes").value  = c.notes       || "";
  new bootstrap.Modal(document.getElementById("custModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
