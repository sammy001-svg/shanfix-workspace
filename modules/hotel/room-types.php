<?php
$moduleSlug  = 'hotel';
$moduleName  = 'Hotel Management';
$moduleIcon  = 'fas fa-hotel';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'room-types.php', 'icon' => 'fas fa-bed',             'label' => 'Room Types'],
    ['url' => 'rooms.php',      'icon' => 'fas fa-door-open',       'label' => 'Rooms'],
    ['url' => 'guests.php',     'icon' => 'fas fa-user-tie',        'label' => 'Guests'],
    ['url' => 'bookings.php',   'icon' => 'fas fa-calendar-check',  'label' => 'Bookings'],
    ['url' => 'checkin.php',    'icon' => 'fas fa-sign-in-alt',     'label' => 'Check-In/Out'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];

// ── POST handling ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];

    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name         = sanitize($_POST['name'] ?? '');
        $description  = sanitize($_POST['description'] ?? '');
        $price        = (float)($_POST['price_per_night'] ?? 0);
        $capacity     = max(1, (int)($_POST['capacity'] ?? 1));
        $amenities    = sanitize($_POST['amenities'] ?? '');
        $isActive     = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            setFlash('danger', 'Room type name is required.');
            redirect('room-types.php');
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO hotel_room_types (org_id, name, description, price_per_night, capacity, amenities, is_active) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $name, $description, $price, $capacity, $amenities, $isActive]);
            logActivity('create', 'hotel', "Added room type: $name");
            setFlash('success', "Room type \"$name\" added successfully.");
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE hotel_room_types SET name=?, description=?, price_per_night=?, capacity=?, amenities=?, is_active=? WHERE id=? AND org_id=?");
            $stmt->execute([$name, $description, $price, $capacity, $amenities, $isActive, $id, $orgId]);
            logActivity('update', 'hotel', "Updated room type: $name");
            setFlash('success', "Room type \"$name\" updated.");
        }
        redirect('room-types.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Block if rooms exist for this type
        $roomCount = countRows('hotel_rooms', 'org_id = ? AND room_type_id = ?', [$orgId, $id]);
        if ($roomCount > 0) {
            setFlash('danger', 'Cannot delete: ' . $roomCount . ' room(s) are linked to this type. Reassign them first.');
            redirect('room-types.php');
        }
        $stmt = $pdo->prepare("SELECT name FROM hotel_room_types WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $rt = $stmt->fetch();
        if ($rt) {
            $pdo->prepare("DELETE FROM hotel_room_types WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            logActivity('delete', 'hotel', "Deleted room type: {$rt['name']}");
            setFlash('success', "Room type \"{$rt['name']}\" deleted.");
        }
        redirect('room-types.php');
    }

    redirect('room-types.php');
}

// ── Page setup ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch room types with room counts
$roomTypes = [];
try {
    $stmt = $pdo->prepare("
        SELECT rt.*, COUNT(r.id) AS room_count
        FROM hotel_room_types rt
        LEFT JOIN hotel_rooms r ON r.room_type_id = rt.id AND r.org_id = rt.org_id
        WHERE rt.org_id = ?
        GROUP BY rt.id
        ORDER BY rt.name ASC
    ");
    $stmt->execute([$orgId]);
    $roomTypes = $stmt->fetchAll();
} catch (Exception $e) {}

// Edit target
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId) {
    foreach ($roomTypes as $rt) {
        if ((int)$rt['id'] === $editId) { $editRow = $rt; break; }
    }
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bed me-2" style="color:<?= $moduleColor ?>"></i>Room Types</h4>
    <p class="text-muted mb-0">Manage hotel room categories, pricing and amenities</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#addModal">
    <i class="fas fa-plus me-2"></i>Add Room Type
  </button>
</div>

<?= flashAlert() ?>

<?php if (empty($roomTypes)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
  <i class="fas fa-bed fa-3x mb-3"></i><br>No room types yet. Click <strong>Add Room Type</strong> to create your first one.
</div></div>
<?php else: ?>

<div class="row g-3">
  <?php foreach ($roomTypes as $rt): ?>
  <?php $amenList = array_filter(array_map('trim', explode(',', $rt['amenities'] ?? ''))); ?>
  <div class="col-sm-6 col-xl-4">
    <div class="card h-100 <?= $rt['is_active'] ? '' : 'opacity-75' ?>">
      <div class="card-header d-flex align-items-center justify-content-between" style="background:<?= $moduleColor ?>18;border-bottom:2px solid <?= $moduleColor ?>">
        <div>
          <h6 class="mb-0 fw-bold"><?= e($rt['name']) ?></h6>
          <?php if (!$rt['is_active']): ?>
            <span class="badge bg-secondary mt-1">Inactive</span>
          <?php endif; ?>
        </div>
        <span class="badge rounded-pill" style="background:<?= $moduleColor ?>">
          <?= $rt['room_count'] ?> room<?= $rt['room_count'] !== 1 ? 's' : '' ?>
        </span>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div>
            <div class="fw-bold fs-5" style="color:<?= $moduleColor ?>"><?= formatCurrency((float)$rt['price_per_night']) ?></div>
            <small class="text-muted">per night</small>
          </div>
          <div class="ms-auto text-center">
            <div class="fw-semibold"><?= (int)$rt['capacity'] ?></div>
            <small class="text-muted"><i class="fas fa-user"></i> Capacity</small>
          </div>
        </div>
        <?php if ($rt['description']): ?>
        <p class="text-muted small mb-2"><?= e($rt['description']) ?></p>
        <?php endif; ?>
        <?php if (!empty($amenList)): ?>
        <div class="mb-2">
          <?php foreach ($amenList as $a): ?>
          <span class="badge bg-light text-dark border me-1 mb-1"><i class="fas fa-check-circle text-success me-1"></i><?= e(trim($a)) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="card-footer bg-transparent d-flex gap-2">
        <a href="?edit=<?= $rt['id'] ?>" class="btn btn-sm btn-outline-secondary flex-fill" data-bs-toggle="modal" data-bs-target="#addModal">
          <i class="fas fa-edit me-1"></i>Edit
        </a>
        <form method="post" class="flex-fill">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $rt['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger w-100"
            data-confirm="Delete room type \"<?= e($rt['name']) ?>\"? This cannot be undone."
            data-href="">
            <i class="fas fa-trash me-1"></i>Delete
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- Add / Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
        <h5 class="modal-title"><i class="fas fa-bed me-2"></i><span id="modalTitle">Add Room Type</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-loading>
        <?= csrfField() ?>
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="id" id="formId" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Room Type Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="fieldName" class="form-control" required placeholder="e.g. Deluxe Suite">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Is Active</label>
              <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" name="is_active" id="fieldActive" value="1" checked>
                <label class="form-check-label" for="fieldActive">Active</label>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Price Per Night <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="price_per_night" id="fieldPrice" class="form-control" min="0" step="0.01" required placeholder="0.00">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Guest Capacity</label>
              <input type="number" name="capacity" id="fieldCapacity" class="form-control" min="1" value="2">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" id="fieldDescription" class="form-control" rows="2" placeholder="Brief description of this room type"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Amenities <small class="text-muted">(comma-separated)</small></label>
              <textarea name="amenities" id="fieldAmenities" class="form-control" rows="2" placeholder="WiFi, TV, Air Conditioning, Mini Bar, ..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff">
            <i class="fas fa-save me-2"></i>Save Room Type
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
// Prefill edit data as JSON
$editData = $editRow ? json_encode($editRow) : 'null';
$extraJs = '<script>
(function(){
  var editData = ' . $editData . ';
  var modal    = document.getElementById("addModal");

  function resetForm() {
    document.getElementById("modalTitle").textContent  = "Add Room Type";
    document.getElementById("formAction").value        = "add";
    document.getElementById("formId").value            = "";
    document.getElementById("fieldName").value         = "";
    document.getElementById("fieldPrice").value        = "";
    document.getElementById("fieldCapacity").value     = "2";
    document.getElementById("fieldDescription").value  = "";
    document.getElementById("fieldAmenities").value    = "";
    document.getElementById("fieldActive").checked     = true;
  }

  function fillForm(d) {
    document.getElementById("modalTitle").textContent  = "Edit Room Type";
    document.getElementById("formAction").value        = "edit";
    document.getElementById("formId").value            = d.id;
    document.getElementById("fieldName").value         = d.name || "";
    document.getElementById("fieldPrice").value        = d.price_per_night || "";
    document.getElementById("fieldCapacity").value     = d.capacity || 2;
    document.getElementById("fieldDescription").value  = d.description || "";
    document.getElementById("fieldAmenities").value    = d.amenities || "";
    document.getElementById("fieldActive").checked     = d.is_active == 1;
  }

  // Auto-open edit modal if URL has ?edit=
  if (editData) {
    fillForm(editData);
    new bootstrap.Modal(modal).show();
  }

  // Edit buttons
  document.querySelectorAll("a[href*=\"?edit=\"]").forEach(function(btn){
    btn.addEventListener("click", function(e){
      e.preventDefault();
      // Redirect to fill form — editData already injected if matching
      // For a simpler UX just navigate to the link which triggers PHP prefill
      window.location.href = btn.href;
    });
  });

  modal.addEventListener("hidden.bs.modal", function(){
    if (editData) history.replaceState(null,"","room-types.php");
    resetForm();
    editData = null;
  });

  // Delete via form submit — confirm handled by app.js data-confirm
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
