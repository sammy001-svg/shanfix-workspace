<?php
$moduleSlug  = 'hotel';
$moduleName  = 'Hotel Management';
$moduleIcon  = 'fas fa-hotel';
$moduleColor = '#d35400';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'room-types.php',   'icon' => 'fas fa-bed',            'label' => 'Room Types'],
    ['url' => 'rooms.php',        'icon' => 'fas fa-door-open',      'label' => 'Rooms'],
    ['url' => 'guests.php',       'icon' => 'fas fa-user-tie',       'label' => 'Guests'],
    ['url' => 'bookings.php',     'icon' => 'fas fa-calendar-check', 'label' => 'Bookings'],
    ['url' => 'checkin.php',      'icon' => 'fas fa-sign-in-alt',    'label' => 'Check-In/Out'],
    ['url' => 'housekeeping.php', 'icon' => 'fas fa-broom',          'label' => 'Housekeeping'],
    ['url' => 'restaurant.php',   'icon' => 'fas fa-utensils',       'label' => 'Restaurant'],
    ['url' => 'invoices.php',     'icon' => 'fas fa-file-invoice',   'label' => 'Invoices'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
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
        $roomNo   = sanitize($_POST['room_no'] ?? '');
        $typeId   = (int)($_POST['type_id'] ?? 0) ?: null;
        $floor    = sanitize($_POST['floor'] ?? '');
        $status   = in_array($_POST['status'] ?? '', ['available','occupied','maintenance','reserved']) ? $_POST['status'] : 'available';
        $notes    = sanitize($_POST['notes'] ?? '');

        if ($roomNo === '') {
            setFlash('danger', 'Room number is required.');
            redirect('rooms.php');
        }

        if ($action === 'add') {
            // Check duplicate room number in org
            $dup = countRows('hotel_rooms', 'org_id = ? AND room_no = ?', [$orgId, $roomNo]);
            if ($dup > 0) {
                setFlash('danger', "Room number \"$roomNo\" already exists.");
                redirect('rooms.php');
            }
            $stmt = $pdo->prepare("INSERT INTO hotel_rooms (org_id, type_id, room_no, floor, status, notes) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$orgId, $typeId, $roomNo, $floor, $status, $notes]);
            logActivity('create', 'hotel', "Added room: $roomNo");
            setFlash('success', "Room $roomNo added successfully.");
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE hotel_rooms SET type_id=?, room_no=?, floor=?, status=?, notes=? WHERE id=? AND org_id=?");
            $stmt->execute([$typeId, $roomNo, $floor, $status, $notes, $id, $orgId]);
            logActivity('update', 'hotel', "Updated room: $roomNo");
            setFlash('success', "Room $roomNo updated.");
        }
        redirect('rooms.php');
    }

    if ($action === 'status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['available','occupied','maintenance','reserved']) ? $_POST['status'] : 'available';
        $stmt   = $pdo->prepare("UPDATE hotel_rooms SET status=? WHERE id=? AND org_id=?");
        $stmt->execute([$status, $id, $orgId]);
        logActivity('update', 'hotel', "Room status changed to $status (id=$id)");
        setFlash('success', 'Room status updated.');
        redirect('rooms.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT room_no FROM hotel_rooms WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare("DELETE FROM hotel_rooms WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            logActivity('delete', 'hotel', "Deleted room: {$row['room_no']}");
            setFlash('success', "Room {$row['room_no']} deleted.");
        }
        redirect('rooms.php');
    }

    redirect('rooms.php');
}

// ── Page setup ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Active filter tab
$filterStatus = $_GET['status'] ?? 'all';
$validStatuses = ['all', 'available', 'occupied', 'maintenance', 'reserved'];
if (!in_array($filterStatus, $validStatuses)) $filterStatus = 'all';

// Fetch rooms with type name
$rooms = [];
try {
    $where = 'r.org_id = ?';
    $params = [$orgId];
    if ($filterStatus !== 'all') {
        $where .= ' AND r.status = ?';
        $params[] = $filterStatus;
    }
    $stmt = $pdo->prepare("
        SELECT r.*, rt.name AS type_name, rt.price_per_night
        FROM hotel_rooms r
        LEFT JOIN hotel_room_types rt ON rt.id = r.type_id
        WHERE $where
        ORDER BY r.floor ASC, r.room_no ASC
    ");
    $stmt->execute($params);
    $rooms = $stmt->fetchAll();
} catch (Exception $e) {}

// Room types for dropdown
$roomTypes = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, price_per_night FROM hotel_room_types WHERE org_id=? AND is_active=1 ORDER BY name");
    $stmt->execute([$orgId]);
    $roomTypes = $stmt->fetchAll();
} catch (Exception $e) {}

// Counts per status
$counts = [];
foreach (['available','occupied','maintenance','reserved'] as $s) {
    $counts[$s] = countRows('hotel_rooms', 'org_id = ? AND status = ?', [$orgId, $s]);
}
$counts['all'] = array_sum($counts);

// Edit target
$editId  = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId) {
    foreach ($rooms as $r) {
        if ((int)$r['id'] === $editId) { $editRow = $r; break; }
    }
    // If filtered out, fetch directly
    if (!$editRow) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM hotel_rooms WHERE id=? AND org_id=?");
            $stmt->execute([$editId, $orgId]);
            $editRow = $stmt->fetch() ?: null;
        } catch (Exception $e) {}
    }
}

// Status colors
$statusColors = ['available'=>'#1A8A4E','occupied'=>'#e74c3c','maintenance'=>'#f39c12','reserved'=>'#3498db'];
$statusIcons  = ['available'=>'fas fa-check-circle','occupied'=>'fas fa-times-circle','maintenance'=>'fas fa-tools','reserved'=>'fas fa-clock'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-door-open me-2" style="color:<?= $moduleColor ?>"></i>Rooms</h4>
    <p class="text-muted mb-0">Manage hotel rooms, status and assignments</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#roomModal" onclick="resetForm()">
    <i class="fas fa-plus me-2"></i>Add Room
  </button>
</div>

<?= flashAlert() ?>

<!-- Status filter tabs -->
<ul class="nav nav-pills mb-4 flex-wrap gap-1">
  <?php
  $tabLabels = ['all'=>'All','available'=>'Available','occupied'=>'Occupied','maintenance'=>'Maintenance','reserved'=>'Reserved'];
  foreach ($tabLabels as $key => $label):
    $active = $filterStatus === $key ? 'active' : '';
    $color  = $key === 'all' ? $moduleColor : ($statusColors[$key] ?? $moduleColor);
  ?>
  <li class="nav-item">
    <a class="nav-link <?= $active ?> d-flex align-items-center gap-1" href="?status=<?= $key ?>"
       style="<?= $active ? "background:$color;color:#fff" : "color:$color;border:1px solid $color" ?>">
      <?= $label ?>
      <span class="badge rounded-pill" style="background:<?= $active ? '#fff3' : $color ?>;color: #fff"><?= $counts[$key] ?? 0 ?></span>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<?php if (empty($rooms)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
  <i class="fas fa-door-open fa-3x mb-3"></i><br>
  No rooms <?= $filterStatus !== 'all' ? "with status \"$filterStatus\"" : '' ?> found.
  <?php if ($filterStatus === 'all'): ?>Click <strong>Add Room</strong> to create your first room.<?php endif; ?>
</div></div>
<?php else: ?>

<div class="row g-3">
  <?php foreach ($rooms as $r):
    $sc = $statusColors[$r['status']] ?? '#6c757d';
  ?>
  <div class="col-sm-6 col-lg-4 col-xl-3">
    <div class="card h-100 border-0 shadow-sm" style="border-top:4px solid <?= $sc ?> !important;border-top-style:solid !important">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between mb-2">
          <div>
            <h5 class="mb-0 fw-bold text-dark"><?= e($r['room_no']) ?></h5>
            <?php if ($r['floor']): ?><small class="text-muted">Floor <?= e($r['floor']) ?></small><?php endif; ?>
          </div>
          <span class="badge" style="background:<?= $sc ?>"><?= ucfirst($r['status']) ?></span>
        </div>
        <div class="mb-2">
          <small class="text-muted">Type: </small>
          <span class="fw-bold text-dark"><?= e($r['type_name'] ?? 'Unassigned') ?></span>
        </div>
        <?php if ($r['price_per_night']): ?>
        <div class="text-muted small mb-2 fw-bold"><?= formatCurrency((float)$r['price_per_night']) ?>/night</div>
        <?php endif; ?>
        <?php if ($r['notes']): ?>
        <p class="text-muted small mb-2"><em><?= e($r['notes']) ?></em></p>
        <?php endif; ?>

        <!-- Quick status change -->
        <form method="post" class="mb-0">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <div class="input-group input-group-sm">
            <select name="status" class="form-select form-select-sm">
              <?php foreach (['available','occupied','maintenance','reserved'] as $s): ?>
              <option value="<?= $s ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary text-dark" title="Update status"><i class="fas fa-check"></i></button>
          </div>
        </form>
      </div>
      <div class="card-footer bg-transparent d-flex gap-2 pt-0">
        <a href="?edit=<?= $r['id'] ?>&status=<?= urlencode($filterStatus) ?>" class="btn btn-sm btn-outline-secondary flex-fill">
          <i class="fas fa-edit me-1"></i>Edit
        </a>
        <form method="post" class="flex-fill">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger w-100"
            data-confirm="Delete room <?= e($r['room_no']) ?>? This cannot be undone.">
            <i class="fas fa-trash"></i>
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- Add/Edit Modal -->
<div class="modal fade" id="roomModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-door-open me-2"></i><span id="modalTitle">Add Room</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-loading>
        <?= csrfField() ?>
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="id" id="formId" value="">
        <div class="modal-body text-dark">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Room Number <span class="text-danger">*</span></label>
              <input type="text" name="room_no" id="fieldRoomNo" class="form-control" required placeholder="e.g. 101">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Floor</label>
              <input type="text" name="floor" id="fieldFloor" class="form-control" placeholder="e.g. 1, Ground, 2nd">
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Room Type</label>
              <select name="type_id" id="fieldRoomType" class="form-select">
                <option value="">— Select type —</option>
                <?php foreach ($roomTypes as $rt): ?>
                <option value="<?= $rt['id'] ?>"><?= e($rt['name']) ?> (<?= formatCurrency((float)$rt['price_per_night']) ?>/night)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="fieldStatus" class="form-select">
                <option value="available">Available</option>
                <option value="occupied">Occupied</option>
                <option value="maintenance">Maintenance</option>
                <option value="reserved">Reserved</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="fieldNotes" class="form-control" rows="2" placeholder="Any special notes about this room"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>">
            <i class="fas fa-save me-2"></i>Save Room
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$editData = $editRow ? json_encode($editRow) : 'null';
$extraJs  = '<script>
(function(){
  var editData = ' . $editData . ';
  var modal    = document.getElementById("roomModal");

  window.resetForm = function(){
    document.getElementById("modalTitle").textContent = "Add Room";
    document.getElementById("formAction").value       = "add";
    document.getElementById("formId").value           = "";
    document.getElementById("fieldRoomNo").value      = "";
    document.getElementById("fieldFloor").value       = "";
    document.getElementById("fieldRoomType").value    = "";
    document.getElementById("fieldStatus").value      = "available";
    document.getElementById("fieldNotes").value       = "";
  };

  window.fillForm = function(d){
    document.getElementById("modalTitle").textContent = "Edit Room";
    document.getElementById("formAction").value       = "edit";
    document.getElementById("formId").value           = d.id;
    document.getElementById("fieldRoomNo").value      = d.room_no || "";
    document.getElementById("fieldFloor").value       = d.floor || "";
    document.getElementById("fieldRoomType").value    = d.type_id || "";
    document.getElementById("fieldStatus").value      = d.status || "available";
    document.getElementById("fieldNotes").value       = d.notes || "";
  };

  if(editData){
    fillForm(editData);
    new bootstrap.Modal(modal).show();
  }

  modal.addEventListener("hidden.bs.modal", function(){
    if(editData) history.replaceState(null,"","rooms.php");
    resetForm();
    editData = null;
  });
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
