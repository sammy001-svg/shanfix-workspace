<?php
// ── TOUR: Travel Destinations Registry & CRUD ──────────────────
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
        $id          = (int)($_POST['id'] ?? 0);
        $name        = sanitize($_POST['name']        ?? '');
        $country     = sanitize($_POST['country']     ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $image       = sanitize($_POST['image']       ?? '');
        $status      = sanitize($_POST['status']      ?? 'active');

        if (empty($name) || empty($country)) {
            setFlash('danger', 'Destination Name and Country are required.');
            redirect('destinations.php');
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO tour_destinations (org_id, name, country, description, image, status)
                VALUES (?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $name, $country, $description, $image, $status]);
            setFlash('success', 'Destination created successfully.');
            logActivity('create', 'tour', "Created travel destination '$name' ($country)");
        } else {
            $stmt = $pdo->prepare("
                UPDATE tour_destinations
                SET name=?, country=?, description=?, image=?, status=?
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$name, $country, $description, $image, $status, $id, $orgId]);
            setFlash('success', 'Destination details updated successfully.');
            logActivity('update', 'tour', "Updated travel destination '$name' (#$id)");
        }
        redirect('destinations.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Safety Lock: Check if packages exist under this destination
        $packageCount = countRows('tour_packages', 'destination_id = ? AND org_id = ?', [$id, $orgId]);
        if ($packageCount > 0) {
            setFlash('danger', 'Cannot delete this destination because it has ' . $packageCount . ' linked tour packages.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM tour_destinations WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            setFlash('success', 'Destination deleted successfully.');
            logActivity('delete', 'tour', "Deleted destination #$id");
        }
        redirect('destinations.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Retrieve destinations list
$destinations = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tour_destinations WHERE org_id = ? ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $destinations = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats metrics
$totalDestinationsCount = count($destinations);
$activeDestinationsCount = countRows('tour_destinations', "org_id=? AND status='active'", [$orgId]);
$inactiveDestinationsCount = $totalDestinationsCount - $activeDestinationsCount;
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-map-marker-alt me-2" style="color:<?= $moduleColor ?>"></i>Destinations Registry</h4>
    <p class="text-muted mb-0">Register geographical coordinates, scenic points, and coordinate holiday itineraries</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#destModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Add Destination
  </button>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon blue-bg" style="background:rgba(41,128,185,0.15);color:#2980b9"><i class="fas fa-globe-africa"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalDestinationsCount ?></div><div class="stat-label">Total Locations</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeDestinationsCount ?></div><div class="stat-label">Active Spots</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-pause-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $inactiveDestinationsCount ?></div><div class="stat-label">Inactive / Drafts</div></div>
    </div>
  </div>
</div>

<!-- Destinations Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="destTable">
        <thead class="table-light">
          <tr>
            <th>Destination Spot</th>
            <th>Country / Region</th>
            <th>Scenic Description</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($destinations)): ?>
          <tr>
            <td colspan="5" class="text-center py-5 text-muted">
              <i class="fas fa-map-marked fa-3x mb-3 d-block"></i>No travel spots defined.
            </td>
          </tr>
          <?php else: foreach ($destinations as $d): ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><i class="fas fa-location-arrow me-2 text-primary"></i><?= e($d['name']) ?></div>
            </td>
            <td><strong><?= e($d['country']) ?></strong></td>
            <td><div class="small text-muted text-truncate" style="max-width:350px"><?= e($d['description'] ?: '—') ?></div></td>
            <td>
              <span class="badge <?= $d['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"><?= ucfirst(e($d['status'])) ?></span>
            </td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick="editDest(<?= e(json_encode($d)) ?>)" title="Edit Location">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this destination spot?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Location">
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

<!-- Add / Edit Destination Modal -->
<div class="modal fade" id="destModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="destId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-map-marker-alt me-2"></i>Add Destination</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Destination Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="destName" class="form-control" required placeholder="e.g. Maasai Mara Game Reserve">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Country / Territory <span class="text-danger">*</span></label>
            <input type="text" name="country" id="destCountry" class="form-control" required placeholder="e.g. Kenya">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Image Preview URL</label>
            <input type="text" name="image" id="destImage" class="form-control" placeholder="https://example.com/mara.jpg">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="destStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Short Description</label>
            <textarea name="description" id="destDescription" class="form-control" rows="3" placeholder="Highlight primary tour features, beaches, or wildlife..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Location</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
  $("#destTable").DataTable({pageLength:10,order:[[0,"asc"]]});
});

function openAddModal() {
  $("#modalAction").val("add");
  $("#modalTitle").html("<i class=\"fas fa-map-marker-alt me-2\"></i>Add Destination");
  $("#destId").val("");
  $("#destName").val("");
  $("#destCountry").val("");
  $("#destImage").val("");
  $("#destStatus").val("active");
  $("#destDescription").val("");
}

function editDest(d) {
  $("#modalAction").val("edit");
  $("#modalTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Destination Details");
  $("#destId").val(d.id);
  $("#destName").val(d.name || "");
  $("#destCountry").val(d.country || "");
  $("#destImage").val(d.image || "");
  $("#destStatus").val(d.status || "active");
  $("#destDescription").val(d.description || "");

  new bootstrap.Modal(document.getElementById("destModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
