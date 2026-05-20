<?php
$moduleSlug = 'meetings';
$moduleName = 'Meetings & Minutes';
$moduleIcon = 'fas fa-video';
$moduleColor = '#0B2D4E';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'meetings.php',     'icon' => 'fas fa-video',          'label' => 'Meetings'],
    ['url' => 'minutes.php',      'icon' => 'fas fa-file-alt',       'label' => 'Minutes'],
    ['url' => 'actions.php',      'icon' => 'fas fa-tasks',          'label' => 'Action Items'],
    ['url' => 'participants.php', 'icon' => 'fas fa-address-book',   'label' => 'Participants'],
    ['url' => 'calendar.php',     'icon' => 'fas fa-calendar',       'label' => 'Calendar'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $organizerId = (int)($_POST['organizer_id'] ?? 0) ?: null;
        $location = sanitize($_POST['location'] ?? '');
        $date = $_POST['meeting_date'] ?? date('Y-m-d');
        $startTime = $_POST['start_time'] ?? date('H:i');
        $endTime = $_POST['end_time'] ?? date('H:i', strtotime('+1 hour'));
        $type = in_array($_POST['type'] ?? '', ['physical', 'virtual', 'hybrid']) ? $_POST['type'] : 'physical';
        $link = sanitize($_POST['meeting_link'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['scheduled', 'ongoing', 'completed', 'cancelled']) ? $_POST['status'] : 'scheduled';

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE meetings SET title = ?, description = ?, organizer_id = ?, location = ?, meeting_date = ?, start_time = ?, end_time = ?, type = ?, meeting_link = ?, status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$title, $description, $organizerId, $location, $date, $startTime, $endTime, $type, $link, $status, $id, $orgId]);
            setFlash('success', 'Meeting updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO meetings (org_id, title, description, organizer_id, location, meeting_date, start_time, end_time, type, meeting_link, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $title, $description, $organizerId, $location, $date, $startTime, $endTime, $type, $link, $status]);
            $id = (int)$pdo->lastInsertId();
            setFlash('success', "Meeting '$title' scheduled successfully.");
        }

        // Handle dynamic attendees
        $attendeeNames = $_POST['att_name'] ?? [];
        $attendeeEmails = $_POST['att_email'] ?? [];
        if (!empty($attendeeNames) || $id > 0) {
            $pdo->prepare("DELETE FROM meeting_attendees WHERE meeting_id = ?")->execute([$id]);
            $ins = $pdo->prepare("INSERT INTO meeting_attendees (meeting_id, name, email, rsvp) VALUES (?, ?, ?, 'pending')");
            foreach ($attendeeNames as $i => $name) {
                $name = sanitize($name);
                $email = sanitize($attendeeEmails[$i] ?? '');
                if ($name !== '') {
                    $ins->execute([$id, $name, $email]);
                }
            }
        }

        logActivity($id > 0 ? 'update' : 'create', 'meetings', "Meeting: $title");
        redirect('meetings.php');
    }

    if ($action === 'status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = sanitize($_POST['status'] ?? '');
        if (in_array($st, ['scheduled', 'ongoing', 'completed', 'cancelled'])) {
            $stmt = $pdo->prepare("UPDATE meetings SET status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$st, $id, $orgId]);
            setFlash('success', 'Meeting status updated.');
        }
        redirect('meetings.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM meeting_attendees WHERE meeting_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM meeting_minutes WHERE meeting_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM meetings WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Meeting deleted successfully.');
        redirect('meetings.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';
$fType = $_GET['type'] ?? '';
$fQ = trim($_GET['q'] ?? '');

$where = 'm.org_id = ?';
$params = [$orgId];

if ($fStatus !== '') {
    $where .= ' AND m.status = ?';
    $params[] = $fStatus;
}
if ($fType !== '') {
    $where .= ' AND m.type = ?';
    $params[] = $fType;
}
if ($fQ !== '') {
    $where .= ' AND (m.title LIKE ? OR m.description LIKE ? OR m.location LIKE ?)';
    $like = "%$fQ%";
    array_push($params, $like, $like, $like);
}

$meetingsList = [];
try {
    $stmt = $pdo->prepare("SELECT m.*, u.name AS organizer_name 
                           FROM meetings m 
                           LEFT JOIN users u ON m.organizer_id = u.id 
                           WHERE $where 
                           ORDER BY m.meeting_date DESC, m.start_time DESC");
    $stmt->execute($params);
    $meetingsList = $stmt->fetchAll();
} catch (Exception $e) {}

$usersList = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE org_id = ? ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $usersList = $stmt->fetchAll();
} catch (Exception $e) {}

// Fetch details for edit modal mapping
$meetingDetails = [];
if (isset($_GET['fetch_details'])) {
    $mid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM meetings WHERE id = ? AND org_id = ?");
        $stmt->execute([$mid, $orgId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m) {
            $stmt2 = $pdo->prepare("SELECT name, email FROM meeting_attendees WHERE meeting_id = ?");
            $stmt2->execute([$mid]);
            $m['attendees'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            header('Content-Type: application/json');
            echo json_encode($m);
            exit;
        }
    } catch (Exception $e) {}
}

$totalMeetings = countRows('meetings', 'org_id = ?', [$orgId]);
$scheduledCount = countRows('meetings', "org_id = ? AND status = 'scheduled'", [$orgId]);
$completedCount = countRows('meetings', "org_id = ? AND status = 'completed'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-video me-2" style="color:<?= $moduleColor ?>"></i>Meetings</h4>
    <p class="text-muted mb-0">Plan board sessions, virtual video chats, hybrid meetings and track attendees</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#mModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Schedule Meeting</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-video"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalMeetings ?></div>
        <div class="stat-label">Total Meetings Scheduled</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon info-bg"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $scheduledCount ?></div>
        <div class="stat-label">Scheduled / Upcoming</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $completedCount ?></div>
        <div class="stat-label">Completed Sessions</div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Title, location or description…" value="<?= e($fQ) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <option value="physical" <?= $fType === 'physical' ? 'selected' : '' ?>>Physical</option>
          <option value="virtual" <?= $fType === 'virtual' ? 'selected' : '' ?>>Virtual</option>
          <option value="hybrid" <?= $fType === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="scheduled" <?= $fStatus === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
          <option value="ongoing" <?= $fStatus === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
          <option value="completed" <?= $fStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
          <option value="cancelled" <?= $fStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="meetings.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-calendar-alt me-2" style="color:<?= $moduleColor ?>"></i>Meetings List</h6>
    <span class="badge bg-secondary"><?= count($meetingsList) ?> sessions</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Meeting Details</th>
            <th>Date & Time</th>
            <th>Type</th>
            <th>Location / Link</th>
            <th>Organizer</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($meetingsList)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No meetings found.</td></tr>
          <?php else: foreach ($meetingsList as $m): 
            $statusColors = ['scheduled' => 'info', 'ongoing' => 'warning text-dark', 'completed' => 'success', 'cancelled' => 'danger'];
            $sc = $statusColors[$m['status']] ?? 'secondary';
          ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><?= e($m['title']) ?></div>
              <small class="text-muted d-block text-truncate" style="max-width:250px;"><?= e($m['description'] ?: 'No description provided.') ?></small>
            </td>
            <td class="fw-semibold text-secondary">
              <?= formatDate($m['meeting_date']) ?><br>
              <small class="text-muted"><i class="far fa-clock me-1"></i><?= date('h:i A', strtotime($m['start_time'])) ?> - <?= date('h:i A', strtotime($m['end_time'])) ?></small>
            </td>
            <td>
              <span class="badge bg-light text-dark border"><?= ucfirst($m['type']) ?></span>
            </td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?php if ($m['type'] === 'physical'): ?>
              <i class="fas fa-map-marker-alt me-1 text-danger"></i><?= e($m['location'] ?: '—') ?>
              <?php else: ?>
              <a href="<?= e($m['meeting_link']) ?>" target="_blank" class="text-decoration-none text-primary"><i class="fas fa-link me-1"></i>Join Video</a>
              <?php endif; ?>
            </td>
            <td><?= e($m['organizer_name'] ?: 'External Host') ?></td>
            <td>
              <span class="badge bg-<?= $sc ?>"><?= ucfirst($m['status']) ?></span>
            </td>
            <td class="text-center" style="white-space:nowrap">
              <div class="btn-group btn-group-sm">
                <?php if ($m['status'] === 'scheduled'): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= $m['id'] ?>">
                  <input type="hidden" name="status" value="ongoing">
                  <?= csrfField() ?>
                  <button type="submit" class="btn btn-outline-warning" title="Start Meeting"><i class="fas fa-play"></i></button>
                </form>
                <?php elseif ($m['status'] === 'ongoing'): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= $m['id'] ?>">
                  <input type="hidden" name="status" value="completed">
                  <?= csrfField() ?>
                  <button type="submit" class="btn btn-outline-success" title="Complete / Checkout"><i class="fas fa-check"></i></button>
                </form>
                <?php endif; ?>
                
                <button class="btn btn-outline-primary ms-1" onclick="openEdit(<?= $m['id'] ?>)"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger ms-1" onclick="delMeeting(<?= $m['id'] ?>, '<?= e($m['title']) ?>')"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="mModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="mId" value="0">
  <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
    <h5 class="modal-title" id="mTitle"><i class="fas fa-video me-2"></i>Schedule Meeting</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Meeting Title <span class="text-danger">*</span></label>
        <input type="text" name="title" id="mTitleField" class="form-control" required placeholder="e.g. Annual Budget Review Board Session">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Organizer / Host <span class="text-danger">*</span></label>
        <select name="organizer_id" id="mOrganizer" class="form-select" required>
          <option value="">-- select host --</option>
          <?php foreach ($usersList as $ul): ?>
          <option value="<?= $ul['id'] ?>"><?= e($ul['name']) ?> (<?= e($ul['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
        <input type="date" name="meeting_date" id="mDate" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Start Time <span class="text-danger">*</span></label>
        <input type="time" name="start_time" id="mStart" class="form-control" required value="<?= date('H:i') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">End Time <span class="text-danger">*</span></label>
        <input type="time" name="end_time" id="mEnd" class="form-control" required value="<?= date('H:i', strtotime('+1 hour')) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Location Type <span class="text-danger">*</span></label>
        <select name="type" id="mType" class="form-select" onchange="toggleLocLink()">
          <option value="physical">Physical Room</option>
          <option value="virtual">Virtual / Video Call</option>
          <option value="hybrid">Hybrid</option>
        </select>
      </div>
      <div class="col-md-4" id="locGroup">
        <label class="form-label fw-semibold">Physical Location / Room Name</label>
        <input type="text" name="location" id="mLoc" class="form-control" placeholder="e.g. Main Boardroom, Floor 3">
      </div>
      <div class="col-md-4" id="linkGroup" style="display:none">
        <label class="form-label fw-semibold">Video Meeting Link (Zoom, Teams, Meet)</label>
        <input type="url" name="meeting_link" id="mLink" class="form-control" placeholder="e.g. https://meet.google.com/xyz-abc">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Status</label>
        <select name="status" id="mStatus" class="form-select">
          <option value="scheduled">Scheduled</option>
          <option value="ongoing">Ongoing</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Agenda / Description</label>
        <textarea name="description" id="mDesc" class="form-control" rows="3" placeholder="Brief outline of meeting agenda issues…"></textarea>
      </div>
    </div>
    
    <hr class="my-4">
    <h6 class="fw-semibold mb-2"><i class="fas fa-users-cog me-2 text-primary"></i>Manage Attendees</h6>
    <div class="table-responsive">
      <table class="table table-sm table-bordered">
        <thead class="table-light">
          <tr><th>Attendee Full Name</th><th>Email Address</th><th style="width:50px"></th></tr>
        </thead>
        <tbody id="attRows"></tbody>
      </table>
    </div>
    <button type="button" class="btn btn-sm btn-outline-success" onclick="addAttendeeRow()"><i class="fas fa-plus me-1"></i>Add Attendee</button>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Meeting</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delMForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delMId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function toggleLocLink() {
  const type = document.getElementById('mType').value;
  if (type === 'physical') {
    document.getElementById('locGroup').style.display = 'block';
    document.getElementById('linkGroup').style.display = 'none';
  } else if (type === 'virtual') {
    document.getElementById('locGroup').style.display = 'none';
    document.getElementById('linkGroup').style.display = 'block';
  } else {
    document.getElementById('locGroup').style.display = 'block';
    document.getElementById('linkGroup').style.display = 'block';
  }
}
function addAttendeeRow(name = '', email = '') {
  const tbody = document.getElementById('attRows');
  const row = tbody.insertRow();
  row.innerHTML = `
    <td><input type="text" name="att_name[]" class="form-control form-control-sm" required placeholder="e.g. John Doe" value="${name}"></td>
    <td><input type="email" name="att_email[]" class="form-control form-control-sm" placeholder="e.g. john@example.com" value="${email}"></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>
  `;
}
function openAdd() {
  document.getElementById('mTitle').innerHTML = '<i class="fas fa-video me-2"></i>Schedule Meeting';
  document.getElementById('mId').value = '0';
  document.getElementById('mTitleField').value = '';
  document.getElementById('mOrganizer').value = '';
  document.getElementById('mLoc').value = '';
  document.getElementById('mLink').value = '';
  document.getElementById('mDesc').value = '';
  document.getElementById('mStatus').value = 'scheduled';
  document.getElementById('mType').value = 'physical';
  
  // Set current date & time
  const now = new Date();
  document.getElementById('mDate').value = now.toISOString().split('T')[0];
  document.getElementById('mStart').value = now.toTimeString().substring(0, 5);
  
  const end = new Date(now.getTime() + 60*60*1000);
  document.getElementById('mEnd').value = end.toTimeString().substring(0, 5);
  
  document.getElementById('attRows').innerHTML = '';
  addAttendeeRow();
  toggleLocLink();
}
function openEdit(id) {
  fetch('meetings.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('mTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Meeting';
      document.getElementById('mId').value = data.id;
      document.getElementById('mTitleField').value = data.title;
      document.getElementById('mOrganizer').value = data.organizer_id || '';
      document.getElementById('mDate').value = data.meeting_date;
      document.getElementById('mStart').value = data.start_time.substring(0, 5);
      document.getElementById('mEnd').value = data.end_time.substring(0, 5);
      document.getElementById('mType').value = data.type;
      document.getElementById('mLoc').value = data.location || '';
      document.getElementById('mLink').value = data.meeting_link || '';
      document.getElementById('mStatus').value = data.status;
      document.getElementById('mDesc').value = data.description || '';
      
      const tbody = document.getElementById('attRows');
      tbody.innerHTML = '';
      if (data.attendees && data.attendees.length > 0) {
        data.attendees.forEach(a => addAttendeeRow(a.name, a.email));
      } else {
        addAttendeeRow();
      }
      
      toggleLocLink();
      new bootstrap.Modal(document.getElementById('mModal')).show();
    });
}
function delMeeting(id, title) {
  Swal.fire({
    title: 'Delete Meeting?',
    text: 'Permanently remove "' + title + '", its attendees roster, and minutes records?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delMId').value = id;
      document.getElementById('delMForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
