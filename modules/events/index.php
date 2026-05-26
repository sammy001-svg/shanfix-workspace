<?php
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
    ['url' => 'vendors.php',   'icon' => 'fas fa-store',          'label' => 'Vendors'],
    ['url' => 'sponsors.php',  'icon' => 'fas fa-handshake',      'label' => 'Sponsors'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-tasks',          'label' => 'Tasks'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalEvents    = countRows('events', 'org_id = ?', [$orgId]);
$upcomingEvents = countRows('events', 'org_id = ? AND start_date >= CURDATE() AND status != ?', [$orgId, 'cancelled']);
$totalAttendees = countRows('event_attendees', 'org_id = ?', [$orgId]);
$totalRevenue   = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(t.price), 0)
        FROM event_attendees a
        JOIN event_tickets t ON a.ticket_id = t.id
        WHERE a.org_id = ? AND a.payment_status = 'paid'
    ");
    $stmt->execute([$orgId]);
    $totalRevenue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Upcoming events list
$events = [];
try {
    $stmt = $pdo->prepare("
        SELECT e.*, 
               (SELECT COUNT(*) FROM event_attendees WHERE event_id = e.id) as attendees_count
        FROM events e
        WHERE e.org_id = ? AND e.start_date >= CURDATE()
        ORDER BY e.start_date ASC
        LIMIT 10
    ");
    $stmt->execute([$orgId]);
    $events = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Plan, manage, and track events and attendees</p>
  </div>
  <a href="events.php?action=add" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-plus me-2"></i>Create Event</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalEvents ?></div><div class="stat-label">Total Events</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $upcomingEvents ?></div><div class="stat-label">Upcoming</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalAttendees ?></div><div class="stat-label">Total Attendees</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-ticket-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Ticket Revenue</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-calendar-alt me-2" style="color:<?= $moduleColor ?>"></i>Upcoming Events</h6>
    <a href="events.php" class="btn btn-sm" style="border-color:<?= $moduleColor ?>;color:<?= $moduleColor ?>">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="eventsTable">
        <thead class="table-light">
          <tr><th>Event</th><th>Date</th><th>Venue</th><th>Capacity</th><th>Attendees</th><th>Ticket Price</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php if (empty($events)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-calendar-times fa-2x mb-2 d-block"></i>No upcoming events</td></tr>
          <?php else: foreach ($events as $ev): ?>
          <tr>
            <td class="fw-semibold"><?= e($ev['title'] ?? '—') ?></td>
            <td><?= formatDate($ev['start_date'] ?? '') ?></td>
            <td><?= e($ev['venue'] ?? '—') ?></td>
            <td><?= (int)($ev['venue_capacity'] ?? 0) ?></td>
            <td><?= (int)($ev['attendees_count'] ?? 0) ?></td>
            <td><?= formatCurrency((float)($ev['ticket_price'] ?? 0)) ?></td>
            <td><?= statusBadge($ev['status'] ?? 'scheduled') ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#eventsTable").DataTable({pageLength:10,order:[[1,"asc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>

