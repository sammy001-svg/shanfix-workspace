<?php
// ── CARYARD: Test Drives & Customer Experience ─────────────────
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
        $vehicleId   = (int)($_POST['vehicle_id']   ?? 0);
        $clientName  = sanitize($_POST['client_name']   ?? '');
        $clientPhone = sanitize($_POST['client_phone']  ?? '');
        $clientIdNo  = sanitize($_POST['client_id_no']  ?? '');
        $scheduledAt = $_POST['scheduled_at']           ?? '';
        $status      = sanitize($_POST['status']        ?? 'scheduled');
        $notes       = sanitize($_POST['notes']         ?? '');

        if ($vehicleId <= 0 || empty($clientName) || empty($scheduledAt)) {
            setFlash('danger', 'Vehicle, Client Name, and Scheduled Date-Time are required.');
            redirect('testdrives.php');
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO caryard_test_drives (org_id, vehicle_id, client_name, client_phone, client_id_no, scheduled_at, status, notes)
                VALUES (?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $vehicleId, $clientName, $clientPhone, $clientIdNo, $scheduledAt, $status, $notes]);
            setFlash('success', 'Test drive scheduled successfully.');
            logActivity('create', 'caryard', "Scheduled test drive for client '$clientName'");
        } else {
            $stmt = $pdo->prepare("
                UPDATE caryard_test_drives
                SET vehicle_id=?, client_name=?, client_phone=?, client_id_no=?, scheduled_at=?, status=?, notes=?
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$vehicleId, $clientName, $clientPhone, $clientIdNo, $scheduledAt, $status, $notes, $id, $orgId]);
            setFlash('success', 'Test drive schedule updated.');
            logActivity('update', 'caryard', "Updated test drive booking #$id");
        }
        redirect('testdrives.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM caryard_test_drives WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        setFlash('success', 'Test drive booking deleted.');
        logActivity('delete', 'caryard', "Deleted test drive booking #$id");
        redirect('testdrives.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Retrieve vehicles list for drop-downs
$availableVehicles = [];
try {
    $stmt = $pdo->prepare("SELECT id, stock_no, make, model, year FROM caryard_vehicles WHERE org_id = ? ORDER BY stock_no ASC");
    $stmt->execute([$orgId]);
    $availableVehicles = $stmt->fetchAll();
} catch (Exception $e) {}

// Retrieve test drives logs
$testDrives = [];
try {
    $stmt = $pdo->prepare("
        SELECT td.*, v.stock_no, v.make, v.model, v.year, v.color
        FROM caryard_test_drives td
        JOIN caryard_vehicles v ON td.vehicle_id = v.id
        WHERE td.org_id = ?
        ORDER BY td.scheduled_at DESC
    ");
    $stmt->execute([$orgId]);
    $testDrives = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats metrics
$totalScheduled = countRows('caryard_test_drives', "org_id=? AND status='scheduled'", [$orgId]);
$totalCompleted = countRows('caryard_test_drives', "org_id=? AND status='completed'", [$orgId]);
$totalCancelled = countRows('caryard_test_drives', "org_id=? AND status='cancelled'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-road me-2" style="color:<?= $moduleColor ?>"></i>Test Drives Registry</h4>
    <p class="text-muted mb-0">Book prospective buyers for road tests, track license check-ins, and record client experience ratings</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#testDriveModal" onclick="openAddModal()">
    <i class="fas fa-plus me-1"></i>Schedule Test Drive
  </button>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalScheduled ?></div><div class="stat-label">Pending / Scheduled</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalCompleted ?></div><div class="stat-label">Drives Completed</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-calendar-times"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalCancelled ?></div><div class="stat-label">Drives Cancelled</div></div>
    </div>
  </div>
</div>

<!-- Test Drives List Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="testDriveTable">
        <thead class="table-light">
          <tr>
            <th>Client Name</th>
            <th>Requested Car</th>
            <th>Scheduled Time</th>
            <th>ID / Passport No</th>
            <th>Drive Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($testDrives)): ?>
          <tr>
            <td colspan="6" class="text-center py-5 text-muted">
              <i class="fas fa-road fa-3x mb-3 d-block"></i>No test drives scheduled yet.
            </td>
          </tr>
          <?php else: foreach ($testDrives as $td): ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><i class="fas fa-user-circle me-2 text-warning"></i><?= e($td['client_name']) ?></div>
              <div class="small text-muted"><i class="fas fa-phone me-1"></i><?= e($td['client_phone'] ?: '—') ?></div>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= e($td['make'] . ' ' . $td['model']) ?> (<?= $td['year'] ?>)</div>
              <div class="small text-muted"><code class="text-dark bg-light px-2 py-0.5 rounded">Stock: <?= e($td['stock_no']) ?></code></div>
            </td>
            <td><strong><?= date('M d, Y h:i A', strtotime($td['scheduled_at'])) ?></strong></td>
            <td><code class="bg-light px-2 py-1 rounded text-dark fw-bold"><?= e($td['client_id_no'] ?: '—') ?></code></td>
            <td>
              <?php if ($td['status'] === 'scheduled'): ?>
                <span class="badge bg-warning text-dark">Scheduled</span>
              <?php elseif ($td['status'] === 'completed'): ?>
                <span class="badge bg-success">Completed</span>
              <?php else: ?>
                <span class="badge bg-danger">Cancelled</span>
              <?php endif; ?>
            </td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick="editTestDrive(<?= e(json_encode($td)) ?>)" title="Edit Drive">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Remove this test drive booking?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $td['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Drive">
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

<!-- Modal -->
<div class="modal fade" id="testDriveModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="testDriveId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-road me-2"></i>Schedule Test Drive</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Select Showroom Car <span class="text-danger">*</span></label>
              <select name="vehicle_id" id="tdVehicle" class="form-select" required>
                <option value="">-- Select Spot --</option>
                <?php foreach ($availableVehicles as $av): ?>
                <option value="<?= $av['id'] ?>">
                  <?= e($av['stock_no'] . ' - ' . $av['make'] . ' ' . $av['model'] . ' (' . $av['year'] . ')') ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Client Name <span class="text-danger">*</span></label>
              <input type="text" name="client_name" id="tdClientName" class="form-control" required placeholder="e.g. Samuel Kamau">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Client Phone</label>
              <input type="text" name="client_phone" id="tdClientPhone" class="form-control" placeholder="e.g. +254 712 345678">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">License / ID Number</label>
              <input type="text" name="client_id_no" id="tdClientIdNo" class="form-control" placeholder="e.g. DL-12345678">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Scheduled Date & Time <span class="text-danger">*</span></label>
              <input type="datetime-local" name="scheduled_at" id="tdScheduledAt" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Drive Status</label>
              <select name="status" id="tdStatus" class="form-select">
                <option value="scheduled">Scheduled</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Driver Comments / Feedback</label>
              <textarea name="notes" id="tdNotes" class="form-control" rows="2" placeholder="Record buyer driving experience, performance feedback, or purchase intent level..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-calendar-check me-1"></i>Save Schedule</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
  $("#testDriveTable").DataTable({pageLength:10,order:[[2,"desc"]]});
});

function openAddModal() {
  $("#modalAction").val("add");
  $("#modalTitle").html("<i class=\"fas fa-road me-2\"></i>Schedule Test Drive");
  $("#testDriveId").val("");
  $("#tdVehicle").val("");
  $("#tdClientName").val("");
  $("#tdClientPhone").val("");
  $("#tdClientIdNo").val("");
  $("#tdScheduledAt").val("");
  $("#tdStatus").val("scheduled");
  $("#tdNotes").val("");
}

function editTestDrive(td) {
  $("#modalAction").val("edit");
  $("#modalTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Test Drive Schedule");
  $("#testDriveId").val(td.id);
  $("#tdVehicle").val(td.vehicle_id || "");
  $("#tdClientName").val(td.client_name || "");
  $("#tdClientPhone").val(td.client_phone || "");
  $("#tdClientIdNo").val(td.client_id_no || "");
  
  if (td.scheduled_at) {
    var dt = td.scheduled_at.replace(" ", "T").substring(0, 16);
    $("#tdScheduledAt").val(dt);
  } else {
    $("#tdScheduledAt").val("");
  }
  
  $("#tdStatus").val(td.status || "scheduled");
  $("#tdNotes").val(td.notes || "");

  new bootstrap.Modal(document.getElementById("testDriveModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
