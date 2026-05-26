<?php
// ── EVENTS: Session / Agenda Schedule ─────────────────────────
$moduleSlug  = 'events';
$moduleName  = 'Event Management';
$moduleIcon  = 'fas fa-calendar-alt';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'events.php',    'icon' => 'fas fa-calendar-alt',   'label' => 'Events'],
    ['url' => 'tickets.php',   'icon' => 'fas fa-ticket-alt',     'label' => 'Tickets'],
    ['url' => 'attendees.php', 'icon' => 'fas fa-users',          'label' => 'Attendees'],
    ['url' => 'schedule.php',  'icon' => 'fas fa-list-ol',        'label' => 'Schedule'],
    ['url' => 'budget.php',    'icon' => 'fas fa-wallet',         'label' => 'Budget'],
    ['url' => 'vendors.php',   'icon' => 'fas fa-store',          'label' => 'Vendors'],
    ['url' => 'sponsors.php',  'icon' => 'fas fa-handshake',      'label' => 'Sponsors'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-tasks',          'label' => 'Tasks'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
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
        $id          = (int)($_POST['id']        ?? 0);
        $eventId     = (int)($_POST['event_id']  ?? 0);
        $title       = sanitize($_POST['title']       ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $speaker     = sanitize($_POST['speaker']     ?? '');
        $location    = sanitize($_POST['location']    ?? '');
        $startTime   = $_POST['start_time'] ?? null;
        $endTime     = $_POST['end_time']   ?? null;
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);

        if (!$eventId || !$title) {
            setFlash('danger', 'Event and session title are required.');
            redirect('schedule.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE event_sessions SET event_id=?,title=?,description=?,speaker=?,location=?,start_time=?,end_time=?,sort_order=? WHERE id=? AND org_id=?")
                ->execute([$eventId, $title, $description, $speaker, $location, $startTime ?: null, $endTime ?: null, $sortOrder, $id, $orgId]);
            setFlash('success', 'Session updated.');
            logActivity('update', 'events', "Updated session: $title");
        } else {
            $pdo->prepare("INSERT INTO event_sessions (org_id,event_id,title,description,speaker,location,start_time,end_time,sort_order) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $eventId, $title, $description, $speaker, $location, $startTime ?: null, $endTime ?: null, $sortOrder]);
            setFlash('success', "Session '$title' added.");
            logActivity('create', 'events', "Added session: $title");
        }
        redirect('schedule.php' . ($eventId ? '?event_id='.$eventId : ''));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM event_sessions WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Session deleted.');
        logActivity('delete', 'events', "Deleted session #$id");
        redirect('schedule.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user    = currentUser();
$orgId   = (int)$user['org_id'];

$filterEvent = (int)($_GET['event_id'] ?? 0);
$where  = 's.org_id = ?';
$params = [$orgId];
if ($filterEvent) { $where .= ' AND s.event_id = ?'; $params[] = $filterEvent; }

$sessions = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, e.title AS event_title, e.start_date AS event_date
        FROM event_sessions s
        JOIN events e ON s.event_id = e.id
        WHERE $where ORDER BY s.event_id, s.sort_order, s.start_time
    ");
    $stmt->execute($params);
    $sessions = $stmt->fetchAll();
} catch (Exception $e) {}

$events = [];
try {
    $stmt = $pdo->prepare("SELECT id, title, start_date FROM events WHERE org_id=? AND status NOT IN ('cancelled') ORDER BY start_date DESC");
    $stmt->execute([$orgId]);
    $events = $stmt->fetchAll();
} catch (Exception $e) {}

$totalSessions = countRows('event_sessions', 'org_id=?', [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-list-ol me-2" style="color:<?= $moduleColor ?>"></i>Event Schedule</h4>
    <p class="text-muted mb-0">Manage sessions, agenda, and speakers per event</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#sessModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Add Session
  </button>
</div>

<!-- Event filter tabs -->
<div class="mb-3 d-flex gap-2 flex-wrap align-items-center">
  <span class="small text-muted fw-semibold me-1">Filter by event:</span>
  <a href="schedule.php" class="btn btn-sm <?= !$filterEvent ? 'btn-primary' : 'btn-outline-secondary' ?>">All Events</a>
  <?php foreach ($events as $ev): ?>
  <a href="schedule.php?event_id=<?= $ev['id'] ?>" class="btn btn-sm <?= $filterEvent == $ev['id'] ? 'btn-primary' : 'btn-outline-secondary' ?>">
    <?= e($ev['title']) ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-list-ol me-2" style="color:<?= $moduleColor ?>"></i>Sessions / Agenda</h6>
    <span class="badge bg-secondary"><?= count($sessions) ?> sessions</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Event</th>
            <th>Session Title</th>
            <th>Speaker</th>
            <th>Location / Room</th>
            <th>Start Time</th>
            <th>End Time</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sessions)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-list-ol fa-2x mb-2 d-block"></i>No sessions scheduled yet.</td></tr>
          <?php else: foreach ($sessions as $i => $s): ?>
          <tr>
            <td class="text-muted small"><?= $s['sort_order'] ?: $i+1 ?></td>
            <td>
              <div class="small fw-semibold"><?= e($s['event_title']) ?></div>
              <div class="text-muted" style="font-size:.7rem"><?= formatDate($s['event_date']) ?></div>
            </td>
            <td class="fw-semibold"><?= e($s['title']) ?></td>
            <td class="small"><?= e($s['speaker'] ?: '—') ?></td>
            <td class="small text-muted"><?= e($s['location'] ?: '—') ?></td>
            <td class="small"><?= $s['start_time'] ? date('d M, h:i A', strtotime($s['start_time'])) : '—' ?></td>
            <td class="small"><?= $s['end_time']   ? date('d M, h:i A', strtotime($s['end_time']))   : '—' ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this session?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="sessModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="sessId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="sessTitle"><i class="fas fa-list-ol me-2"></i>Add Session</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-12">
              <label class="form-label fw-semibold">Event <span class="text-danger">*</span></label>
              <select name="event_id" id="sessEvent" class="form-select" required>
                <option value="">— Select Event —</option>
                <?php foreach ($events as $ev): ?>
                <option value="<?= $ev['id'] ?>" <?= $filterEvent == $ev['id'] ? 'selected':'' ?>><?= e($ev['title']) ?> (<?= formatDate($ev['start_date']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Session Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="sessName" class="form-control" required placeholder="e.g. Opening Keynote">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Order #</label>
              <input type="number" name="sort_order" id="sessOrder" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Speaker / Presenter</label>
              <input type="text" name="speaker" id="sessSpeaker" class="form-control" placeholder="Speaker name">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Location / Room</label>
              <input type="text" name="location" id="sessLocation" class="form-control" placeholder="Room A, Main Hall...">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Start Date & Time</label>
              <input type="datetime-local" name="start_time" id="sessStart" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">End Date & Time</label>
              <input type="datetime-local" name="end_time" id="sessEnd" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" id="sessDesc" class="form-control" rows="2" placeholder="Brief description..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Session</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("sessTitle").innerHTML = "<i class=\"fas fa-list-ol me-2\"></i>Add Session";
  document.getElementById("sessId").value       = 0;
  document.getElementById("sessEvent").value    = "' . $filterEvent . '";
  document.getElementById("sessName").value     = "";
  document.getElementById("sessOrder").value    = 0;
  document.getElementById("sessSpeaker").value  = "";
  document.getElementById("sessLocation").value = "";
  document.getElementById("sessStart").value    = "";
  document.getElementById("sessEnd").value      = "";
  document.getElementById("sessDesc").value     = "";
}
function openEdit(s) {
  document.getElementById("sessTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Session";
  document.getElementById("sessId").value       = s.id;
  document.getElementById("sessEvent").value    = s.event_id    || "";
  document.getElementById("sessName").value     = s.title       || "";
  document.getElementById("sessOrder").value    = s.sort_order  || 0;
  document.getElementById("sessSpeaker").value  = s.speaker     || "";
  document.getElementById("sessLocation").value = s.location    || "";
  document.getElementById("sessStart").value    = s.start_time  ? s.start_time.replace(" ","T").substring(0,16) : "";
  document.getElementById("sessEnd").value      = s.end_time    ? s.end_time.replace(" ","T").substring(0,16)   : "";
  document.getElementById("sessDesc").value     = s.description || "";
  new bootstrap.Modal(document.getElementById("sessModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
