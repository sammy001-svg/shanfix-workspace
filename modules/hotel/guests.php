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
        $firstName   = sanitize($_POST['first_name'] ?? '');
        $lastName    = sanitize($_POST['last_name'] ?? '');
        $idNumber    = sanitize($_POST['id_number'] ?? '');
        $nationality = sanitize($_POST['nationality'] ?? '');
        $phone       = sanitize($_POST['phone'] ?? '');
        $email       = sanitize($_POST['email'] ?? '');

        if ($firstName === '' || $lastName === '') {
            setFlash('danger', 'First name and Last name are required.');
            redirect('guests.php');
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO hotel_guests (org_id, first_name, last_name, id_number, nationality, phone, email) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$orgId, $firstName, $lastName, $idNumber, $nationality, $phone, $email]);
            logActivity('create', 'hotel', "Added guest: $firstName $lastName");
            setFlash('success', "Guest $firstName $lastName added successfully.");
        } else {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("UPDATE hotel_guests SET first_name=?, last_name=?, id_number=?, nationality=?, phone=?, email=? WHERE id=? AND org_id=?");
            $stmt->execute([$firstName, $lastName, $idNumber, $nationality, $phone, $email, $id, $orgId]);
            logActivity('update', 'hotel', "Updated guest profile: $firstName $lastName");
            setFlash('success', "Guest $firstName $lastName profile updated.");
        }
        redirect('guests.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        // Check if guest has bookings
        $bookingCount = countRows('hotel_bookings', 'org_id = ? AND guest_id = ?', [$orgId, $id]);
        if ($bookingCount > 0) {
            setFlash('danger', 'Cannot delete guest: they have ' . $bookingCount . ' booking(s) registered in the system.');
            redirect('guests.php');
        }
        
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM hotel_guests WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare("DELETE FROM hotel_guests WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            logActivity('delete', 'hotel', "Deleted guest: {$row['first_name']} {$row['last_name']}");
            setFlash('success', "Guest {$row['first_name']} {$row['last_name']} removed from the database.");
        }
        redirect('guests.php');
    }

    redirect('guests.php');
}

// ── Page setup ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch guests
$guests = [];
try {
    $stmt = $pdo->prepare("
        SELECT g.*, COUNT(b.id) AS booking_count
        FROM hotel_guests g
        LEFT JOIN hotel_bookings b ON b.guest_id = g.id AND b.org_id = g.org_id
        WHERE g.org_id = ?
        GROUP BY g.id
        ORDER BY g.last_name ASC, g.first_name ASC
    ");
    $stmt->execute([$orgId]);
    $guests = $stmt->fetchAll();
} catch (Exception $e) {}

// Edit target
$editId  = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId) {
    foreach ($guests as $g) {
        if ((int)$g['id'] === $editId) { $editRow = $g; break; }
    }
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-user-tie me-2" style="color:<?= $moduleColor ?>"></i>Guests</h4>
    <p class="text-muted mb-0">Manage guest database and history</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#guestModal" onclick="resetForm()">
    <i class="fas fa-plus me-2"></i>Add Guest
  </button>
</div>

<?= flashAlert() ?>

<div class="card">
  <div class="card-header text-dark fw-bold"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Guest Registry</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="guestTable">
        <thead class="table-light">
          <tr>
            <th>Guest Name</th>
            <th>ID / Passport</th>
            <th>Contact Details</th>
            <th>Nationality</th>
            <th class="text-center">Bookings</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($guests as $g): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle text-white d-flex align-items-center justify-content-center fw-bold" style="width:40px;height:40px;background:<?= $moduleColor ?>cc">
                  <?= strtoupper(substr($g['first_name'],0,1) . substr($g['last_name'],0,1)) ?>
                </div>
                <div>
                  <div class="fw-bold text-dark"><?= e($g['first_name'] . ' ' . $g['last_name']) ?></div>
                  <small class="text-muted">Registered: <?= date('d M Y', strtotime($g['created_at'])) ?></small>
                </div>
              </div>
            </td>
            <td class="fw-semibold text-dark"><?= e($g['id_number'] ?: '—') ?></td>
            <td>
              <div class="text-dark"><i class="fas fa-phone-alt text-muted me-1"></i><?= e($g['phone'] ?: '—') ?></div>
              <small class="text-muted"><i class="fas fa-envelope me-1"></i><?= e($g['email'] ?: '—') ?></small>
            </td>
            <td>
              <span class="badge bg-light text-dark border"><i class="fas fa-globe-africa text-primary me-1"></i><?= e($g['nationality'] ?: '—') ?></span>
            </td>
            <td class="text-center fw-bold text-dark">
              <span class="badge bg-dark rounded-pill"><?= (int)$g['booking_count'] ?></span>
            </td>
            <td class="text-end">
              <div class="d-flex justify-content-end gap-2">
                <a href="?edit=<?= $g['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit Profile"><i class="fas fa-edit"></i></a>
                <form method="post" class="mb-0">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $g['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Are you sure you want to delete guest <?= e($g['first_name'] . ' ' . $g['last_name']) ?>? This cannot be undone."><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Guest Modal -->
<div class="modal fade" id="guestModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i><span id="modalTitle">Add Guest</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-loading>
        <?= csrfField() ?>
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="id" id="formId" value="">
        <div class="modal-body text-dark">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" id="fieldFirstName" class="form-control" required placeholder="e.g. John">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
              <input type="text" name="last_name" id="fieldLastName" class="form-control" required placeholder="e.g. Doe">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">ID / Passport Number</label>
              <input type="text" name="id_number" id="fieldIdNumber" class="form-control" placeholder="e.g. 12345678">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Nationality</label>
              <input type="text" name="nationality" id="fieldNationality" class="form-control" placeholder="e.g. Kenyan" value="Kenyan">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone Number</label>
              <input type="text" name="phone" id="fieldPhone" class="form-control" placeholder="e.g. 0712345678">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email Address</label>
              <input type="email" name="email" id="fieldEmail" class="form-control" placeholder="e.g. guest@example.com">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>">
            <i class="fas fa-save me-2"></i>Save Guest
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
  var modal    = document.getElementById("guestModal");

  window.resetForm = function(){
    document.getElementById("modalTitle").textContent = "Add Guest";
    document.getElementById("formAction").value       = "add";
    document.getElementById("formId").value           = "";
    document.getElementById("fieldFirstName").value    = "";
    document.getElementById("fieldLastName").value     = "";
    document.getElementById("fieldIdNumber").value     = "";
    document.getElementById("fieldNationality").value  = "Kenyan";
    document.getElementById("fieldPhone").value        = "";
    document.getElementById("fieldEmail").value        = "";
  };

  window.fillForm = function(d){
    document.getElementById("modalTitle").textContent = "Edit Guest Profile";
    document.getElementById("formAction").value       = "edit";
    document.getElementById("formId").value           = d.id;
    document.getElementById("fieldFirstName").value    = d.first_name || "";
    document.getElementById("fieldLastName").value     = d.last_name || "";
    document.getElementById("fieldIdNumber").value     = d.id_number || "";
    document.getElementById("fieldNationality").value  = d.nationality || "";
    document.getElementById("fieldPhone").value        = d.phone || "";
    document.getElementById("fieldEmail").value        = d.email || "";
  };

  if(editData){
    fillForm(editData);
    new bootstrap.Modal(modal).show();
  }

  modal.addEventListener("hidden.bs.modal", function(){
    if(editData) history.replaceState(null,"","guests.php");
    resetForm();
    editData = null;
  });

  $("#guestTable").DataTable({pageLength:10,order:[[0,"asc"]]});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
