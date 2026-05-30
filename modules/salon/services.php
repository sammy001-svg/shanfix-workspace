<?php
$moduleSlug = 'salon';
$moduleName = 'Salon & Spa';
$moduleIcon = 'fas fa-cut';
$moduleColor = '#c0392b';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'appointments.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Appointments'],
    ['url' => 'clients.php',      'icon' => 'fas fa-users',          'label' => 'Clients'],
    ['url' => 'services.php',     'icon' => 'fas fa-concierge-bell', 'label' => 'Services'],
    ['url' => 'staff.php',        'icon' => 'fas fa-user-tie',       'label' => 'Staff'],
    ['url' => 'packages.php',     'icon' => 'fas fa-gift',           'label' => 'Packages'],
    ['url' => 'inventory.php',    'icon' => 'fas fa-boxes',          'label' => 'Inventory'],
    ['url' => 'loyalty.php',      'icon' => 'fas fa-star',           'label' => 'Loyalty'],
    ['url' => 'payments.php',     'icon' => 'fas fa-money-bill-wave','label' => 'Payments'],
    ['url' => 'expenses.php',     'icon' => 'fas fa-receipt',        'label' => 'Expenses'],
    ['url' => 'promotions.php',   'icon' => 'fas fa-tag',            'label' => 'Promotions'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $category = sanitize($_POST['category'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $duration = (int)($_POST['duration_min'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE salon_services SET name = ?, category = ?, price = ?, duration_min = ?, status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$name, $category, $price, $duration, $status, $id, $orgId]);
            setFlash('success', 'Service updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO salon_services (org_id, name, category, price, duration_min, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $name, $category, $price, $duration, $status]);
            setFlash('success', "Service '$name' added successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'salon', "Service: $name");
        redirect('services.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM salon_services WHERE id = ? AND org_id = ?");
        $stmt->execute([$id, $orgId]);
        setFlash('success', 'Service deleted successfully.');
        redirect('services.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fCat = $_GET['cat'] ?? '';
$fStatus = $_GET['status'] ?? '';
$fQ = trim($_GET['q'] ?? '');

$where = 'org_id = ?';
$params = [$orgId];

if ($fCat !== '') {
    $where .= ' AND category = ?';
    $params[] = $fCat;
}
if ($fStatus !== '') {
    $where .= ' AND status = ?';
    $params[] = $fStatus;
}
if ($fQ !== '') {
    $where .= ' AND (name LIKE ? OR category LIKE ?)';
    $like = "%$fQ%";
    array_push($params, $like, $like);
}

$services = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM salon_services WHERE $where ORDER BY category ASC, name ASC");
    $stmt->execute($params);
    $services = $stmt->fetchAll();
} catch (Exception $e) {}

$categories = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT category FROM salon_services WHERE org_id = ? AND category != '' ORDER BY category ASC");
    $stmt->execute([$orgId]);
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$totalServices = countRows('salon_services', 'org_id = ?', [$orgId]);
$activeServices = countRows('salon_services', "org_id = ? AND status = 'active'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-concierge-bell me-2" style="color:<?= $moduleColor ?>"></i>Services</h4>
    <p class="text-muted mb-0">Define hair cuts, massage therapies, facials, and beauty treatments</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#sModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Service</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-concierge-bell"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalServices ?></div>
        <div class="stat-label">Total Services Offered</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $activeServices ?></div>
        <div class="stat-label">Active & Bookable</div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Service name…" value="<?= e($fQ) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Category</label>
        <select name="cat" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= e($cat) ?>" <?= $fCat === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="services.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-concierge-bell me-2" style="color:<?= $moduleColor ?>"></i>Service Menu</h6>
    <span class="badge bg-secondary"><?= count($services) ?> items</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Service Name</th>
            <th>Category</th>
            <th class="text-end">Price</th>
            <th class="text-end">Duration</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($services)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No services found.</td></tr>
          <?php else: foreach ($services as $s): ?>
          <tr>
            <td class="fw-semibold"><?= e($s['name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= e($s['category'] ?: 'General') ?></span></td>
            <td class="text-end fw-semibold text-success"><?= formatCurrency((float)$s['price']) ?></td>
            <td class="text-end"><?= (int)$s['duration_min'] ?> mins</td>
            <td><?= statusBadge($s['status'] ?? 'active') ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delService(<?= $s['id'] ?>, '<?= e($s['name']) ?>')"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="sModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="sId" value="0">
  <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
    <h5 class="modal-title" id="sTitle"><i class="fas fa-concierge-bell me-2"></i>Add Service</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Service Name <span class="text-danger">*</span></label>
        <input type="text" name="name" id="sName" class="form-control" required maxlength="255" placeholder="e.g. Balayage Hair Painting">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
        <input type="text" name="category" id="sCat" class="form-control" required maxlength="100" placeholder="e.g. Haircut, Massage, Nails">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Price (<?= CURRENCY_SYMBOL ?>) <span class="text-danger">*</span></label>
        <input type="number" name="price" id="sPrice" class="form-control" step="0.01" min="0" required value="0">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Duration (Minutes) <span class="text-danger">*</span></label>
        <input type="number" name="duration_min" id="sDuration" class="form-control" required min="1" value="30">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Status</label>
        <select name="status" id="sStatus" class="form-select">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Service</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delSForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delSId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('sTitle').innerHTML = '<i class="fas fa-concierge-bell me-2"></i>Add Service';
  ['sId', 'sName', 'sCat'].forEach(i => document.getElementById(i).value = i === 'sId' ? '0' : '');
  document.getElementById('sPrice').value = 0;
  document.getElementById('sDuration').value = 30;
  document.getElementById('sStatus').value = 'active';
}
function openEdit(s) {
  document.getElementById('sTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Service';
  document.getElementById('sId').value = s.id;
  document.getElementById('sName').value = s.name || '';
  document.getElementById('sCat').value = s.category || '';
  document.getElementById('sPrice').value = s.price || 0;
  document.getElementById('sDuration').value = s.duration_min || 30;
  document.getElementById('sStatus').value = s.status || 'active';
  new bootstrap.Modal(document.getElementById('sModal')).show();
}
function delService(id, name) {
  Swal.fire({
    title: 'Delete Service?',
    text: '"' + name + '" will be permanently deleted and cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delSId').value = id;
      document.getElementById('delSForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
