<?php
$moduleSlug  = 'church';
$moduleName  = 'Church Management';
$moduleIcon  = 'fas fa-church';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt',     'label' => 'Dashboard'],
    ['url' => 'members.php',   'icon' => 'fas fa-users',              'label' => 'Members'],
    ['url' => 'offerings.php', 'icon' => 'fas fa-hand-holding-heart', 'label' => 'Offerings'],
    ['url' => 'expenses.php',  'icon' => 'fas fa-money-bill-wave',    'label' => 'Expenses'],
    ['url' => 'attendance.php','icon' => 'fas fa-clipboard-check',    'label' => 'Attendance'],
    ['url' => 'sermons.php',   'icon' => 'fas fa-bible',              'label' => 'Sermons'],
    ['url' => 'prayers.php',   'icon' => 'fas fa-praying-hands',      'label' => 'Prayer Requests'],
    ['url' => 'pastoral.php',  'icon' => 'fas fa-hands-helping',      'label' => 'Pastoral Care'],
    ['url' => 'events.php',    'icon' => 'fas fa-calendar-alt',       'label' => 'Events'],
    ['url' => 'cells.php',     'icon' => 'fas fa-home',               'label' => 'Cells / Groups'],
    ['url' => 'pledges.php',   'icon' => 'fas fa-handshake',          'label' => 'Pledges'],
    ['url' => 'projects.php',  'icon' => 'fas fa-project-diagram',    'label' => 'Projects'],
    ['url' => 'notices.php',   'icon' => 'fas fa-bell',               'label' => 'Notices'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],
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
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $location = sanitize($_POST['location'] ?? 'Main Sanctuary');
        $startDate = $_POST['start_date'] ?? date('Y-m-d H:i');
        $endDate = $_POST['end_date'] ?? date('Y-m-d H:i', strtotime('+2 hours'));
        $status = in_array($_POST['status'] ?? '', ['upcoming','ongoing','completed','cancelled']) ? $_POST['status'] : 'upcoming';

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE church_events SET title = ?, description = ?, location = ?, start_date = ?, end_date = ?, status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$title, $description, $location, $startDate, $endDate, $status, $id, $orgId]);
            setFlash('success', 'Worship event updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO church_events (org_id, title, description, location, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $title, $description, $location, $startDate, $endDate, $status]);
            setFlash('success', "Event '$title' scheduled successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'church', "Ministry Event: $title, Date: $startDate");
        redirect('events.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM church_events WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Event schedule removed.');
        redirect('events.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';
$where = 'org_id = ?';
$params = [$orgId];

if ($fStatus !== '') {
    $where .= ' AND status = ?';
    $params[] = $fStatus;
}

$eventsList = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM church_events WHERE $where ORDER BY start_date ASC");
    $stmt->execute($params);
    $eventsList = $stmt->fetchAll();
} catch (Exception $e) {}

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $eid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM church_events WHERE id = ? AND org_id = ?");
        $stmt->execute([$eid, $orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            header('Content-Type: application/json');
            echo json_encode($row);
            exit;
        }
    } catch (Exception $e) {}
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-alt me-2" style="color:<?= $moduleColor ?>"></i>Ministry Calendar Events</h4>
    <p class="text-muted mb-0">Schedule church services, special revival crusades, youth seminars, and leadership workshops</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#eventModal" onclick="openAdd()"><i class="fas fa-calendar-plus me-2"></i>Schedule Event</button>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Event Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="upcoming" <?= $fStatus === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
          <option value="ongoing" <?= $fStatus === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
          <option value="completed" <?= $fStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
          <option value="cancelled" <?= $fStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="events.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-calendar-alt me-2 text-primary"></i>Ministry Calendar</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Event Title</th>
            <th>Description</th>
            <th>Event Location</th>
            <th>Date & Time Interval</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($eventsList)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5"><i class="fas fa-calendar-times fa-2x mb-2 d-block"></i>No ministry events scheduled.</td></tr>
          <?php else: foreach ($eventsList as $ev): 
            $stColors = ['upcoming' => 'info', 'ongoing' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];
            $sc = $stColors[$ev['status']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-bold text-dark fs-6"><?= e($ev['title']) ?></td>
            <td style="max-width:300px;" class="small text-muted"><?= nl2br(e($ev['description'])) ?></td>
            <td class="fw-semibold text-dark"><i class="fas fa-map-marker-alt me-1 text-danger"></i><?= e($ev['location']) ?></td>
            <td>
              <div class="small fw-bold text-dark">From: <?= date('Y-m-d H:i', strtotime($ev['start_date'])) ?></div>
              <small class="text-muted">Till: <?= date('Y-m-d H:i', strtotime($ev['end_date'])) ?></small>
            </td>
            <td><span class="badge bg-<?= $sc ?>"><?= strtoupper($ev['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $ev['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delEvent(<?= $ev['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="eventModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="eventId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="eventTitle"><i class="fas fa-calendar-alt me-2"></i>Schedule Ministry Event</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Event Title <span class="text-danger">*</span></label>
        <input type="text" name="title" id="eventTitleInput" class="form-control" required placeholder="e.g. Sunday Morning Worship Service">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Location Sanctuary / Hall <span class="text-danger">*</span></label>
        <input type="text" name="location" id="eventLocation" class="form-control" required placeholder="e.g. Main Sanctuary, Fellowship Hall">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Start Date & Time <span class="text-danger">*</span></label>
        <input type="datetime-local" name="start_date" id="eventStart" class="form-control" required>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">End Date & Time <span class="text-danger">*</span></label>
        <input type="datetime-local" name="end_date" id="eventEnd" class="form-control" required>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Event Description / Order of Service</label>
        <textarea name="description" id="eventDesc" class="form-control" rows="3" placeholder="e.g. Preacher: Rev. John, Topic: Grace. Full choir presentation expected."></textarea>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Event Status</label>
        <select name="status" id="eventStatus" class="form-select">
          <option value="upcoming">Upcoming</option>
          <option value="ongoing">Ongoing</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Schedule Event</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delEventForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delEventId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('eventTitle').innerHTML = '<i class="fas fa-calendar-alt me-2"></i>Schedule Ministry Event';
  document.getElementById('eventId').value = '0';
  document.getElementById('eventTitleInput').value = '';
  document.getElementById('eventLocation').value = 'Main Sanctuary';
  document.getElementById('eventDesc').value = '';
  document.getElementById('eventStatus').value = 'upcoming';

  const now = new Date();
  now.setMinutes(0);
  const startStr = new Date(now.getTime() + 60*60*1000).toISOString().slice(0, 16);
  const endStr = new Date(now.getTime() + 3*60*60*1000).toISOString().slice(0, 16);
  document.getElementById('eventStart').value = startStr;
  document.getElementById('eventEnd').value = endStr;
}
function openEdit(id) {
  fetch('events.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('eventTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Ministry Event';
      document.getElementById('eventId').value = data.id;
      document.getElementById('eventTitleInput').value = data.title;
      document.getElementById('eventLocation').value = data.location;
      document.getElementById('eventDesc').value = data.description || '';
      
      // Convert start/end MySQL format (YYYY-MM-DD HH:MM:SS) to (YYYY-MM-DDTHH:MM)
      document.getElementById('eventStart').value = data.start_date.replace(' ', 'T').slice(0, 16);
      document.getElementById('eventEnd').value = data.end_date.replace(' ', 'T').slice(0, 16);
      document.getElementById('eventStatus').value = data.status;
      
      new bootstrap.Modal(document.getElementById('eventModal')).show();
    });
}
function delEvent(id) {
  Swal.fire({
    title: 'Cancel & Delete Event?',
    text: 'Remove this event schedule from the ministry calendar?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delEventId').value = id;
      document.getElementById('delEventForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
