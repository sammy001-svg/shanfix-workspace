<?php
// ── EVENTS: Event Registry & CRUD ──────────────────────────────
$moduleSlug  = 'events';
$moduleName  = 'Events Management';
$moduleIcon  = 'fas fa-calendar-alt';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'events.php',    'icon' => 'fas fa-calendar-alt',   'label' => 'Events'],
    ['url' => 'tickets.php',   'icon' => 'fas fa-ticket-alt',     'label' => 'Tickets'],
    ['url' => 'attendees.php', 'icon' => 'fas fa-users',          'label' => 'Attendees'],
    ['url' => 'schedule.php',  'icon' => 'fas fa-list-ol',        'label' => 'Schedule'],
    ['url' => 'budget.php',    'icon' => 'fas fa-wallet',         'label' => 'Budget'],
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

    if ($action === 'add' || $action === 'edit') {
        $id            = (int)($_POST['id'] ?? 0);
        $title         = sanitize($_POST['title']         ?? '');
        $description   = sanitize($_POST['description']   ?? '');
        $venue         = sanitize($_POST['venue']         ?? '');
        $venueCapacity = (int)($_POST['venue_capacity']   ?? 100);
        $startDate     = $_POST['start_date']             ?? '';
        $endDate       = $_POST['end_date']               ?? '';
        $ticketPrice   = (float)($_POST['ticket_price']   ?? 0.00);
        $isFree        = $ticketPrice <= 0 ? 1 : 0;
        $status        = sanitize($_POST['status']        ?? 'draft');
        $banner        = sanitize($_POST['banner']        ?? '');

        if (empty($title) || empty($startDate) || empty($endDate) || empty($venue)) {
            setFlash('danger', 'Event Title, Dates, and Venue are required fields.');
            redirect('events.php');
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO events (org_id, title, description, venue, venue_capacity, start_date, end_date, banner, ticket_price, is_free, status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $title, $description, $venue, $venueCapacity, $startDate, $endDate, $banner, $ticketPrice, $isFree, $status, $user['id']]);
            $eventId = $pdo->lastInsertId();

            // Auto-create a default ticket tier for easy onboarding
            $stmtTick = $pdo->prepare("INSERT INTO event_tickets (org_id, event_id, ticket_type, price, quantity, sold) VALUES (?,?, 'General Admission', ?,?, 0)");
            $stmtTick->execute([$orgId, $eventId, $ticketPrice, $venueCapacity]);

            setFlash('success', 'Event and default General Admission ticket created successfully.');
            logActivity('create', 'events', "Created event '$title' ($eventId)");
        } else {
            $stmt = $pdo->prepare("
                UPDATE events
                SET title=?, description=?, venue=?, venue_capacity=?, start_date=?, end_date=?, banner=?, ticket_price=?, is_free=?, status=?
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$title, $description, $venue, $venueCapacity, $startDate, $endDate, $banner, $ticketPrice, $isFree, $status, $id, $orgId]);
            
            setFlash('success', 'Event details updated successfully.');
            logActivity('update', 'events', "Updated event '$title' ($id)");
        }
        redirect('events.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Safety check: Prevent deleting events with registered attendees
        $attendeeCount = countRows('event_attendees', 'event_id = ? AND org_id = ?', [$id, $orgId]);
        if ($attendeeCount > 0) {
            setFlash('danger', 'Cannot delete this event because it already has ' . $attendeeCount . ' registered attendees.');
        } else {
            // Delete associated tickets and event
            $stmt = $pdo->prepare("DELETE FROM event_tickets WHERE event_id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);

            $stmt = $pdo->prepare("DELETE FROM events WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);

            setFlash('success', 'Event and linked tickets deleted successfully.');
            logActivity('delete', 'events', "Deleted event #$id");
        }
        redirect('events.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch filters
$filterStatus = sanitize($_GET['status'] ?? '');
$where = "org_id = ?";
$params = [$orgId];

if ($filterStatus !== '') {
    $where .= " AND status = ?";
    $params[] = $filterStatus;
}

$events = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE $where ORDER BY start_date DESC");
    $stmt->execute($params);
    $events = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats metrics
$totalEventsCount     = countRows('events', 'org_id=?', [$orgId]);
$publishedEventsCount = countRows('events', "org_id=? AND status='published'", [$orgId]);
$ongoingEventsCount   = countRows('events', "org_id=? AND status='ongoing'", [$orgId]);
$cancelledEventsCount = countRows('events', "org_id=? AND status='cancelled'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-alt me-2" style="color:<?= $moduleColor ?>"></i>Event Registry</h4>
    <p class="text-muted mb-0">Schedule conventions, manage attendance capacities, and organize ticketing plans</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#eventModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Create Event
  </button>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon purple-bg" style="background:rgba(142,68,173,0.15);color:#8e44ad"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalEventsCount ?></div><div class="stat-label">Total Events</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $publishedEventsCount ?></div><div class="stat-label">Published</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $ongoingEventsCount ?></div><div class="stat-label">Ongoing</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-times-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $cancelledEventsCount ?></div><div class="stat-label">Cancelled</div></div>
    </div>
  </div>
</div>

<!-- Filter Box -->
<div class="card mb-4 border-0 shadow-sm">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Filter Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="draft" <?= $filterStatus==='draft'?'selected':'' ?>>Draft</option>
          <option value="published" <?= $filterStatus==='published'?'selected':'' ?>>Published</option>
          <option value="ongoing" <?= $filterStatus==='ongoing'?'selected':'' ?>>Ongoing</option>
          <option value="completed" <?= $filterStatus==='completed'?'selected':'' ?>>Completed</option>
          <option value="cancelled" <?= $filterStatus==='cancelled'?'selected':'' ?>>Cancelled</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="events.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Events List Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="eventsTable">
        <thead class="table-light">
          <tr>
            <th>Event Title</th>
            <th>Venue</th>
            <th>Capacity limit</th>
            <th>Date & Schedule</th>
            <th class="text-end">Ticket Price</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($events)): ?>
          <tr>
            <td colspan="7" class="text-center py-5 text-muted">
              <i class="fas fa-calendar-times fa-3x mb-3 d-block"></i>No events recorded.
            </td>
          </tr>
          <?php else: foreach ($events as $ev): ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><?= e($ev['title']) ?></div>
              <div class="small text-muted text-truncate" style="max-width:250px"><?= e($ev['description'] ?: 'No Description Provided') ?></div>
            </td>
            <td><i class="fas fa-map-marker-alt me-1 text-muted"></i><?= e($ev['venue']) ?></td>
            <td>
              <span class="fw-bold"><?= $ev['venue_capacity'] ?></span> max slots
            </td>
            <td>
              <div class="small fw-semibold text-dark"><i class="fas fa-hourglass-start text-success me-1"></i><?= formatDateTime($ev['start_date']) ?></div>
              <div class="small text-muted"><i class="fas fa-hourglass-end text-danger me-1"></i><?= formatDateTime($ev['end_date']) ?></div>
            </td>
            <td class="text-end fw-bold">
              <?= $ev['is_free'] ? '<span class="badge bg-success">FREE</span>' : formatCurrency((float)$ev['ticket_price']) ?>
            </td>
            <td><?= statusBadge($ev['status']) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick="editEvent(<?= e(json_encode($ev)) ?>)" title="Edit Event">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Are you sure you want to delete this event and its default tickets?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $ev['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Event">
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

<!-- Add / Edit Event Modal -->
<div class="modal fade" id="eventModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="eventId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-calendar-plus me-2"></i>Create Event</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-12">
              <label class="form-label fw-semibold">Event Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="eventTitle" class="form-control" required placeholder="e.g. Annual Reseller Summit 2026">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Venue Location <span class="text-danger">*</span></label>
              <input type="text" name="venue" id="eventVenue" class="form-control" required placeholder="e.g. Tsavo Hall, KICC">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Venue Capacity (Seats) <span class="text-danger">*</span></label>
              <input type="number" name="venue_capacity" id="eventCapacity" class="form-control" required min="1" value="100">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Start Date & Time <span class="text-danger">*</span></label>
              <input type="datetime-local" name="start_date" id="eventStart" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">End Date & Time <span class="text-danger">*</span></label>
              <input type="datetime-local" name="end_date" id="eventEnd" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Base Ticket Price (<?= CURRENCY ?>)</label>
              <input type="number" step="0.01" name="ticket_price" id="eventPrice" class="form-control" min="0" value="0.00" placeholder="0.00 (Leave 0 for free)">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="eventStatus" class="form-select">
                <option value="draft">Draft</option>
                <option value="published">Published</option>
                <option value="ongoing">Ongoing</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-md-12">
              <label class="form-label fw-semibold">Banner Image URL</label>
              <input type="text" name="banner" id="eventBanner" class="form-control" placeholder="https://example.com/banner.jpg">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Event Description</label>
              <textarea name="description" id="eventDescription" class="form-control" rows="3" placeholder="Provide full itinerary or session schedules..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Event</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
  $("#eventsTable").DataTable({pageLength:10,order:[[3,"desc"]]});
});

function openAddModal() {
  $("#modalAction").val("add");
  $("#modalTitle").html("<i class=\"fas fa-calendar-plus me-2\"></i>Create Event");
  $("#eventId").val("");
  $("#eventTitle").val("");
  $("#eventVenue").val("");
  $("#eventCapacity").val("100");
  $("#eventStart").val("");
  $("#eventEnd").val("");
  $("#eventPrice").val("0.00");
  $("#eventStatus").val("draft");
  $("#eventBanner").val("");
  $("#eventDescription").val("");
}

function editEvent(ev) {
  $("#modalAction").val("edit");
  $("#modalTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Event Details");
  $("#eventId").val(ev.id);
  $("#eventTitle").val(ev.title || "");
  $("#eventVenue").val(ev.venue || "");
  $("#eventCapacity").val(ev.venue_capacity || 100);
  $("#eventStart").val(ev.start_date ? ev.start_date.replace(" ", "T") : "");
  $("#eventEnd").val(ev.end_date ? ev.end_date.replace(" ", "T") : "");
  $("#eventPrice").val(ev.ticket_price || "0.00");
  $("#eventStatus").val(ev.status || "draft");
  $("#eventBanner").val(ev.banner || "");
  $("#eventDescription").val(ev.description || "");

  new bootstrap.Modal(document.getElementById("eventModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
