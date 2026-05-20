<?php
// ── Shopping Mall: Shops ──────────────────────────────────────
$moduleSlug  = 'shopping-mall';
$moduleName  = 'Shopping Mall';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',    'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'floors.php',   'icon' => 'fas fa-layer-group',    'label' => 'Floors'],
    ['url' => 'shops.php',    'icon' => 'fas fa-store',          'label' => 'Shops'],
    ['url' => 'tenants.php',  'icon' => 'fas fa-user-tie',       'label' => 'Tenants'],
    ['url' => 'payments.php', 'icon' => 'fas fa-money-check',    'label' => 'Rent Payments'],
    ['url' => 'reports.php',  'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],
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
        $id          = (int)($_POST['id'] ?? 0);
        $floorId     = (int)($_POST['floor_id'] ?? 0) ?: null;
        $shopNo      = sanitize($_POST['shop_no'] ?? '');
        $name        = sanitize($_POST['name'] ?? '');
        $category    = sanitize($_POST['category'] ?? '');
        $sizeSqm     = (float)($_POST['size_sqm'] ?? 0);
        $monthlyRent = (float)($_POST['monthly_rent'] ?? 0);
        $status      = in_array($_POST['status'] ?? '', ['vacant','occupied','maintenance']) ? $_POST['status'] : 'vacant';

        if (empty($shopNo) || empty($name)) {
            setFlash('danger', 'Shop number and name are required.');
            redirect('shops.php');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE mall_shops SET floor_id=?, shop_no=?, name=?, category=?, size_sqm=?, monthly_rent=?, status=? WHERE id=? AND org_id=?");
            $stmt->execute([$floorId, $shopNo, $name, $category, $sizeSqm, $monthlyRent, $status, $id, $orgId]);
            setFlash('success', "Shop $shopNo updated.");
            logActivity('update', 'shopping-mall', "Updated shop: $shopNo $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO mall_shops (org_id, floor_id, shop_no, name, category, size_sqm, monthly_rent, status) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $floorId, $shopNo, $name, $category, $sizeSqm, $monthlyRent, $status]);
            setFlash('success', "Shop $shopNo \"$name\" added.");
            logActivity('create', 'shopping-mall', "Added shop: $shopNo $name");
        }
        redirect('shops.php');
    }

    if ($action === 'quick_status') {
        $id        = (int)($_POST['id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if (!in_array($newStatus, ['vacant','occupied','maintenance'])) {
            setFlash('danger', 'Invalid status.');
            redirect('shops.php');
        }
        $stmt = $pdo->prepare("UPDATE mall_shops SET status=? WHERE id=? AND org_id=?");
        $stmt->execute([$newStatus, $id, $orgId]);
        setFlash('success', 'Shop status updated to ' . ucfirst($newStatus) . '.');
        logActivity('update', 'shopping-mall', "Changed shop #$id status to $newStatus");
        redirect('shops.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Check for active tenants
        $used = $pdo->prepare("SELECT COUNT(*) FROM mall_tenants WHERE shop_id=? AND org_id=? AND status='active'");
        $used->execute([$id, $orgId]);
        if ((int)$used->fetchColumn() > 0) {
            setFlash('danger', 'Cannot delete: this shop has an active tenant. Deactivate the tenant first.');
        } else {
            $stmt = $pdo->prepare("SELECT shop_no FROM mall_shops WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            $shop = $stmt->fetch();
            $pdo->prepare("DELETE FROM mall_shops WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', "Shop {$shop['shop_no']} deleted.");
            logActivity('delete', 'shopping-mall', "Deleted shop {$shop['shop_no']}");
        }
        redirect('shops.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Filter
$fStatus = $_GET['status'] ?? '';
$fFloor  = (int)($_GET['floor'] ?? 0);

$where  = 's.org_id = ?';
$params = [$orgId];
if ($fStatus) { $where .= ' AND s.status = ?'; $params[] = $fStatus; }
if ($fFloor)  { $where .= ' AND s.floor_id = ?'; $params[] = $fFloor; }

$shops = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, f.name AS floor_name, f.level AS floor_level,
               t.business_name AS tenant_name
        FROM mall_shops s
        LEFT JOIN mall_floors f ON s.floor_id = f.id
        LEFT JOIN mall_tenants t ON t.shop_id = s.id AND t.status = 'active' AND t.org_id = s.org_id
        WHERE $where
        ORDER BY f.level ASC, s.shop_no ASC
    ");
    $stmt->execute($params);
    $shops = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats
$totalShops  = countRows('mall_shops', 'org_id=?', [$orgId]);
$vacant      = countRows('mall_shops', 'org_id=? AND status=?', [$orgId, 'vacant']);
$occupied    = countRows('mall_shops', 'org_id=? AND status=?', [$orgId, 'occupied']);
$maintenance = countRows('mall_shops', 'org_id=? AND status=?', [$orgId, 'maintenance']);

// Floors for filter and form
$floors = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM mall_floors WHERE org_id=? ORDER BY level ASC, name ASC");
    $stmt->execute([$orgId]);
    $floors = $stmt->fetchAll();
} catch (Exception $e) {}

// Group shops by floor for map view
$shopsByFloor = [];
foreach ($shops as $sh) {
    $key = $sh['floor_name'] ?? 'Unassigned';
    $shopsByFloor[$key][] = $sh;
}

$statusColors = ['vacant' => '#27ae60', 'occupied' => '#2c3e50', 'maintenance' => '#f39c12'];
$statusTextColors = ['vacant' => '#fff', 'occupied' => '#fff', 'maintenance' => '#333'];

$shopCategories = ['Electronics', 'Fashion & Clothing', 'Food & Beverage', 'Supermarket', 'Pharmacy', 'Beauty & Salon', 'Jewelry', 'Sports', 'Books & Stationery', 'Other'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-store me-2" style="color:<?= $moduleColor ?>"></i>Shops</h4>
    <p class="text-muted mb-0">Manage shop units, rentals and statuses</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff"
    data-bs-toggle="modal" data-bs-target="#shopModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Shop
  </button>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-store"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalShops ?></div><div class="stat-label">Total Shops</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-door-open"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $vacant ?></div><div class="stat-label">Vacant</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-door-closed"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $occupied ?></div><div class="stat-label">Occupied</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-tools"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $maintenance ?></div><div class="stat-label">Maintenance</div></div>
    </div>
  </div>
</div>

<!-- Status Filter Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $fStatus === '' && !$fFloor ? 'active' : '' ?>" href="shops.php">All <span class="badge bg-secondary ms-1"><?= $totalShops ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $fStatus === 'vacant' ? 'active' : '' ?>" href="shops.php?status=vacant">Vacant <span class="badge bg-success ms-1"><?= $vacant ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $fStatus === 'occupied' ? 'active' : '' ?>" href="shops.php?status=occupied">Occupied <span class="badge bg-primary ms-1"><?= $occupied ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $fStatus === 'maintenance' ? 'active' : '' ?>" href="shops.php?status=maintenance">Maintenance <span class="badge bg-warning text-dark ms-1"><?= $maintenance ?></span></a>
  </li>
  <?php foreach ($floors as $fl): ?>
  <li class="nav-item">
    <a class="nav-link <?= $fFloor === (int)$fl['id'] ? 'active' : '' ?>" href="shops.php?floor=<?= $fl['id'] ?>"><?= e($fl['name']) ?></a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- Floor Map View -->
<?php if (!empty($shopsByFloor)): ?>
<?php foreach ($shopsByFloor as $floorName => $floorShops): ?>
<div class="card mb-4">
  <div class="card-header" style="background:<?= $moduleColor ?>11;border-left:4px solid <?= $moduleColor ?>">
    <h6 class="mb-0"><i class="fas fa-layer-group me-2" style="color:<?= $moduleColor ?>"></i><?= e($floorName) ?>
      <span class="badge ms-2" style="background:<?= $moduleColor ?>"><?= count($floorShops) ?> shops</span>
    </h6>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <?php foreach ($floorShops as $sh):
        $bgColor   = $statusColors[$sh['status']] ?? '#95a5a6';
        $txtColor  = $statusTextColors[$sh['status']] ?? '#fff';
      ?>
      <div class="col-sm-6 col-md-4 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-top:3px solid <?= $bgColor ?> !important;border-top-width:3px !important">
          <div class="card-body p-3">
            <div class="d-flex align-items-start justify-content-between mb-2">
              <span class="badge" style="background:<?= $bgColor ?>;color:<?= $txtColor ?>"><?= ucfirst($sh['status']) ?></span>
              <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary py-0 px-1" data-bs-toggle="dropdown">
                  <i class="fas fa-ellipsis-v"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><button class="dropdown-item" onclick='openEdit(<?= htmlspecialchars(json_encode($sh), ENT_QUOTES) ?>)'><i class="fas fa-edit me-2"></i>Edit Shop</button></li>
                  <li><hr class="dropdown-divider"></li>
                  <?php if ($sh['status'] !== 'vacant'): ?>
                  <li>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="quick_status">
                      <input type="hidden" name="id" value="<?= $sh['id'] ?>">
                      <input type="hidden" name="new_status" value="vacant">
                      <button type="submit" class="dropdown-item text-success"><i class="fas fa-door-open me-2"></i>Set Vacant</button>
                    </form>
                  </li>
                  <?php endif; ?>
                  <?php if ($sh['status'] !== 'maintenance'): ?>
                  <li>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="quick_status">
                      <input type="hidden" name="id" value="<?= $sh['id'] ?>">
                      <input type="hidden" name="new_status" value="maintenance">
                      <button type="submit" class="dropdown-item text-warning"><i class="fas fa-tools me-2"></i>Set Maintenance</button>
                    </form>
                  </li>
                  <?php endif; ?>
                  <li><hr class="dropdown-divider"></li>
                  <li><button class="dropdown-item text-danger" onclick="delShop(<?= $sh['id'] ?>, '<?= e($sh['shop_no']) ?>')"><i class="fas fa-trash me-2"></i>Delete</button></li>
                </ul>
              </div>
            </div>
            <div class="fw-bold fs-5 mb-0" style="color:<?= $moduleColor ?>"><?= e($sh['shop_no']) ?></div>
            <div class="fw-semibold small text-truncate" title="<?= e($sh['name']) ?>"><?= e($sh['name']) ?></div>
            <div class="small text-muted"><?= e($sh['category'] ?? '') ?></div>
            <?php if ($sh['tenant_name']): ?>
            <div class="mt-2 small"><i class="fas fa-user-tie me-1 text-muted"></i><?= e($sh['tenant_name']) ?></div>
            <?php endif; ?>
            <div class="mt-2 d-flex justify-content-between small text-muted">
              <span><i class="fas fa-ruler-combined me-1"></i><?= number_format((float)$sh['size_sqm'], 1) ?> sqm</span>
              <span class="fw-semibold text-dark"><?= formatCurrency((float)$sh['monthly_rent']) ?>/mo</span>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="text-center py-5 text-muted">
  <i class="fas fa-store fa-3x mb-3 d-block"></i>No shops found. Add your first shop.
</div>
<?php endif; ?>

<!-- Full Table View -->
<div class="card mt-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-table me-2" style="color:<?= $moduleColor ?>"></i>Table View</h6>
    <span class="badge bg-secondary"><?= count($shops) ?> shops</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Shop No</th>
            <th>Name</th>
            <th>Floor</th>
            <th>Category</th>
            <th class="text-end">Size (sqm)</th>
            <th class="text-end">Monthly Rent</th>
            <th>Tenant</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($shops)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No shops found.</td></tr>
          <?php else: foreach ($shops as $sh): ?>
          <tr>
            <td class="fw-bold" style="color:<?= $moduleColor ?>"><?= e($sh['shop_no']) ?></td>
            <td><?= e($sh['name']) ?></td>
            <td><?= e($sh['floor_name'] ?? '—') ?></td>
            <td><?= e($sh['category'] ?? '—') ?></td>
            <td class="text-end"><?= number_format((float)$sh['size_sqm'], 1) ?></td>
            <td class="text-end"><?= formatCurrency((float)$sh['monthly_rent']) ?></td>
            <td><?= $sh['tenant_name'] ? e($sh['tenant_name']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= statusBadge($sh['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode($sh), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="delShop(<?= $sh['id'] ?>, '<?= e($sh['shop_no']) ?>')"
                title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="shopModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="shId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="shopModalTitle"><i class="fas fa-store me-2"></i>Add Shop</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label fw-semibold">Shop No <span class="text-danger">*</span></label>
              <input type="text" name="shop_no" id="shNo" class="form-control" required maxlength="50" placeholder="e.g. G-01">
            </div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">Shop Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="shName" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Floor</label>
              <select name="floor_id" id="shFloor" class="form-select">
                <option value="">-- No Floor --</option>
                <?php foreach ($floors as $fl): ?>
                <option value="<?= $fl['id'] ?>"><?= e($fl['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Category</label>
              <select name="category" id="shCategory" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($shopCategories as $cat): ?>
                <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Size (sqm)</label>
              <input type="number" name="size_sqm" id="shSize" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Monthly Rent (KES)</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="monthly_rent" id="shRent" class="form-control" step="0.01" min="0" value="0">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="shStatus" class="form-select">
                <option value="vacant">Vacant</option>
                <option value="occupied">Occupied</option>
                <option value="maintenance">Maintenance</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Shop</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="delShopForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delShopId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('shopModalTitle').innerHTML = '<i class="fas fa-store me-2"></i>Add Shop';
  ['shId','shNo','shName'].forEach(function(i){ document.getElementById(i).value = i==='shId'?'0':''; });
  ['shSize','shRent'].forEach(function(i){ document.getElementById(i).value = '0'; });
  document.getElementById('shFloor').value    = '';
  document.getElementById('shCategory').value = '';
  document.getElementById('shStatus').value   = 'vacant';
}
function openEdit(s) {
  document.getElementById('shopModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Shop — ' + s.shop_no;
  document.getElementById('shId').value       = s.id;
  document.getElementById('shNo').value       = s.shop_no || '';
  document.getElementById('shName').value     = s.name || '';
  document.getElementById('shFloor').value    = s.floor_id || '';
  document.getElementById('shCategory').value = s.category || '';
  document.getElementById('shSize').value     = s.size_sqm || '0';
  document.getElementById('shRent').value     = s.monthly_rent || '0';
  document.getElementById('shStatus').value   = s.status || 'vacant';
  new bootstrap.Modal(document.getElementById('shopModal')).show();
}
function delShop(id, shopNo) {
  Swal.fire({
    title: 'Delete Shop?',
    text: 'Shop "' + shopNo + '" and all its payment history will be deleted.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('delShopId').value = id;
      document.getElementById('delShopForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
