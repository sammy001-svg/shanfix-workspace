<?php
// ── TOUR: Holiday Packages Registry & CRUD ─────────────────────
$moduleSlug  = 'tour';
$moduleName  = 'Tour & Travel';
$moduleIcon  = 'fas fa-plane';
$moduleColor = '#2980b9';
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

    if ($action === 'add' || $action === 'edit') {
        $id            = (int)($_POST['id'] ?? 0);
        $destinationId = (int)($_POST['destination_id']  ?? 0);
        $name          = sanitize($_POST['name']         ?? '');
        $description   = sanitize($_POST['description']   ?? '');
        $durationDays  = (int)($_POST['duration_days']   ?? 1);
        $priceAdult    = (float)($_POST['price_per_adult'] ?? 0.00);
        $priceChild    = (float)($_POST['price_per_child'] ?? 0.00);
        $maxPax        = (int)($_POST['max_pax']         ?? 10);
        $includes      = sanitize($_POST['includes']     ?? '');
        $excludes      = sanitize($_POST['excludes']     ?? '');
        $image         = sanitize($_POST['image']        ?? '');
        $status        = sanitize($_POST['status']       ?? 'active');

        if ($destinationId <= 0 || empty($name) || $priceAdult <= 0 || $maxPax <= 0) {
            setFlash('danger', 'Destination, Package Name, Price per Adult, and Max Slots are required fields.');
            redirect('packages.php');
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO tour_packages (org_id, destination_id, name, description, duration_days, price_per_adult, price_per_child, max_pax, includes, excludes, image, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $destinationId, $name, $description, $durationDays, $priceAdult, $priceChild, $maxPax, $includes, $excludes, $image, $status]);
            setFlash('success', 'Tour Package created successfully.');
            logActivity('create', 'tour', "Created holiday package '$name' ($durationDays days)");
        } else {
            $stmt = $pdo->prepare("
                UPDATE tour_packages
                SET destination_id=?, name=?, description=?, duration_days=?, price_per_adult=?, price_per_child=?, max_pax=?, includes=?, excludes=?, image=?, status=?
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$destinationId, $name, $description, $durationDays, $priceAdult, $priceChild, $maxPax, $includes, $excludes, $image, $status, $id, $orgId]);
            setFlash('success', 'Tour Package updated successfully.');
            logActivity('update', 'tour', "Updated holiday package '$name' (#$id)");
        }
        redirect('packages.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Safety Lock: Prevent deleting package with active bookings
        $bookingCount = countRows('tour_bookings', 'package_id = ? AND org_id = ?', [$id, $orgId]);
        if ($bookingCount > 0) {
            setFlash('danger', 'Cannot delete this tour package because it already has ' . $bookingCount . ' client bookings.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM tour_packages WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            setFlash('success', 'Tour Package deleted successfully.');
            logActivity('delete', 'tour', "Deleted package #$id");
        }
        redirect('packages.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Retrieve destinations selector
$destinations = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, country FROM tour_destinations WHERE org_id=? AND status='active' ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $destinations = $stmt->fetchAll();
} catch (Exception $e) {}

// Retrieve packages list
$packages = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, d.name as dest_name, d.country as dest_country
        FROM tour_packages p
        JOIN tour_destinations d ON p.destination_id = d.id
        WHERE p.org_id = ?
        ORDER BY p.name ASC
    ");
    $stmt->execute([$orgId]);
    $packages = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats metrics
$totalPackagesCount = count($packages);
$activePackagesCount = countRows('tour_packages', "org_id=? AND status='active'", [$orgId]);
$inactivePackagesCount = $totalPackagesCount - $activePackagesCount;
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-suitcase me-2" style="color:<?= $moduleColor ?>"></i>Tour Packages</h4>
    <p class="text-muted mb-0">Design holiday itineraries, establish adult/child pricing metrics, and track capacities</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#pkgModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Create Package
  </button>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon blue-bg" style="background:rgba(41,128,185,0.15);color:#2980b9"><i class="fas fa-suitcase"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalPackagesCount ?></div><div class="stat-label">Total Packages</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activePackagesCount ?></div><div class="stat-label">Active Catalogs</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-pause-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $inactivePackagesCount ?></div><div class="stat-label">Inactive / Drafts</div></div>
    </div>
  </div>
</div>

<!-- Packages List Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="pkgTable">
        <thead class="table-light">
          <tr>
            <th>Package Title</th>
            <th>Destination Spot</th>
            <th class="text-center">Duration</th>
            <th class="text-end">Adult Price</th>
            <th class="text-end">Child Price</th>
            <th class="text-center">Max Slots</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($packages)): ?>
          <tr>
            <td colspan="8" class="text-center py-5 text-muted">
              <i class="fas fa-suitcase fa-3x mb-3 d-block"></i>No tour packages designed.
            </td>
          </tr>
          <?php else: foreach ($packages as $p): ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><?= e($p['name']) ?></div>
              <div class="small text-muted text-truncate" style="max-width:250px"><?= e($p['description'] ?: 'No Description') ?></div>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= e($p['dest_name']) ?></div>
              <div class="small text-muted"><?= e($p['dest_country']) ?></div>
            </td>
            <td class="text-center fw-bold text-primary"><?= (int)$p['duration_days'] ?> Days</td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$p['price_per_adult']) ?></td>
            <td class="text-end text-muted small"><?= formatCurrency((float)$p['price_per_child']) ?></td>
            <td class="text-center fw-semibold"><?= (int)$p['max_pax'] ?> Pax</td>
            <td>
              <span class="badge <?= $p['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"><?= ucfirst(e($p['status'])) ?></span>
            </td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick="editPkg(<?= e(json_encode($p)) ?>)" title="Edit Package">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this holiday package?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Package">
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

<!-- Add / Edit Package Modal -->
<div class="modal fade" id="pkgModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="pkgId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-suitcase me-2"></i>Create Package</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Package Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="pkgName" class="form-control" required placeholder="e.g. 5-Days Premium Mara Safari">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Destination Spot <span class="text-danger">*</span></label>
              <select name="destination_id" id="pkgDest" class="form-select" required>
                <option value="">-- Select Spot --</option>
                <?php foreach ($destinations as $ds): ?>
                <option value="<?= $ds['id'] ?>"><?= e($ds['name'] . ' (' . $ds['country'] . ')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Duration (Days) <span class="text-danger">*</span></label>
              <input type="number" name="duration_days" id="pkgDuration" class="form-control" required min="1" value="5">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Price per Adult (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="price_per_adult" id="pkgPriceAdult" class="form-control" required min="0" value="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Price per Child (<?= CURRENCY ?>)</label>
              <input type="number" step="0.01" name="price_per_child" id="pkgPriceChild" class="form-control" min="0" value="0.00">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Max Pax Capacity <span class="text-danger">*</span></label>
              <input type="number" name="max_pax" id="pkgMaxPax" class="form-control" required min="1" value="10">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="pkgStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-12">
              <label class="form-label fw-semibold">Image URL</label>
              <input type="text" name="image" id="pkgImage" class="form-control" placeholder="https://example.com/safari.jpg">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Includes (Highlights, separated by commas)</label>
              <textarea name="includes" id="pkgIncludes" class="form-control" rows="2" placeholder="e.g. Game drives, Professional guide, Park entrance fees"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Excludes (Highlights, separated by commas)</label>
              <textarea name="excludes" id="pkgExcludes" class="form-control" rows="2" placeholder="e.g. Flights, Tips, Medical insurance"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Detailed Description</label>
              <textarea name="description" id="pkgDescription" class="form-control" rows="3" placeholder="Provide full holiday day-to-day itinerary detail..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Package</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
  $("#pkgTable").DataTable({pageLength:10,order:[[0,"asc"]]});
});

function openAddModal() {
  $("#modalAction").val("add");
  $("#modalTitle").html("<i class=\"fas fa-suitcase me-2\"></i>Create Package");
  $("#pkgId").val("");
  $("#pkgDest").val("");
  $("#pkgName").val("");
  $("#pkgDescription").val("");
  $("#pkgDuration").val("5");
  $("#pkgPriceAdult").val("0.00");
  $("#pkgPriceChild").val("0.00");
  $("#pkgMaxPax").val("10");
  $("#pkgIncludes").val("");
  $("#pkgExcludes").val("");
  $("#pkgImage").val("");
  $("#pkgStatus").val("active");
}

function editPkg(p) {
  $("#modalAction").val("edit");
  $("#modalTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Package Details");
  $("#pkgId").val(p.id);
  $("#pkgDest").val(p.destination_id || "");
  $("#pkgName").val(p.name || "");
  $("#pkgDescription").val(p.description || "");
  $("#pkgDuration").val(p.duration_days || 5);
  $("#pkgPriceAdult").val(p.price_per_adult || "0.00");
  $("#pkgPriceChild").val(p.price_per_child || "0.00");
  $("#pkgMaxPax").val(p.max_pax || 10);
  $("#pkgIncludes").val(p.includes || "");
  $("#pkgExcludes").val(p.excludes || "");
  $("#pkgImage").val(p.image || "");
  $("#pkgStatus").val(p.status || "active");

  new bootstrap.Modal(document.getElementById("pkgModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
