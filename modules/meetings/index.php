<?php
$moduleSlug  = 'meetings';
$moduleName  = 'Meetings & Minutes';
$moduleIcon  = 'fas fa-video';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'meetings.php',     'icon' => 'fas fa-video',          'label' => 'Meetings'],
    ['url' => 'minutes.php',      'icon' => 'fas fa-file-alt',       'label' => 'Minutes'],
    ['url' => 'actions.php',      'icon' => 'fas fa-tasks',          'label' => 'Action Items'],
    ['url' => 'participants.php', 'icon' => 'fas fa-address-book',   'label' => 'Participants'],
    ['url' => 'calendar.php',     'icon' => 'fas fa-calendar',       'label' => 'Calendar'],
    ['url' => 'agenda.php',      'icon' => 'fas fa-list-ul',         'label' => 'Agenda'],
    ['url' => 'recordings.php',  'icon' => 'fas fa-microphone',      'label' => 'Recordings'],
    ['url' => 'documents.php',   'icon' => 'fas fa-folder-open',     'label' => 'Documents'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalMeetings    = countRows('meetings', 'org_id = ?', [$orgId]);
$todayMeetings    = countRows('meetings', 'org_id = ? AND meeting_date = CURDATE()', [$orgId]);
$upcomingMeetings = countRows('meetings', 'org_id = ? AND (meeting_date > CURDATE() OR (meeting_date = CURDATE() AND start_time >= CURTIME())) AND status != ?', [$orgId, 'cancelled']);
$completedMeetings= countRows('meetings', 'org_id = ? AND status = ?', [$orgId, 'completed']);

// Upcoming meetings list
$meetings = [];
try {
    $stmt = $pdo->prepare("SELECT m.*, u.name AS organizer_name 
                           FROM meetings m 
                           LEFT JOIN users u ON m.organizer_id = u.id
                           WHERE m.org_id=? AND (m.meeting_date > CURDATE() OR (m.meeting_date = CURDATE() AND m.start_time >= CURTIME()))
                           ORDER BY m.meeting_date ASC, m.start_time ASC 
                           LIMIT 10");
    $stmt->execute([$orgId]);
    $meetings = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Schedule and track meetings and minutes</p>
  </div>
  <a href="meetings.php" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-plus me-2"></i>Schedule Meeting</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-video"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalMeetings ?></div><div class="stat-label">Total Meetings</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-calendar-day"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $todayMeetings ?></div><div class="stat-label">Today</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $upcomingMeetings ?></div><div class="stat-label">Upcoming</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $completedMeetings ?></div><div class="stat-label">Completed</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-calendar-alt me-2" style="color:<?= $moduleColor ?>"></i>Upcoming Meetings</h6>
    <a href="meetings.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="meetingsTable">
        <thead class="table-light">
          <tr><th>Title</th><th>Date & Time</th><th>Location / Link</th><th>Organizer</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php if (empty($meetings)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-calendar-times fa-2x mb-2 d-block"></i>No upcoming meetings</td></tr>
          <?php else: foreach ($meetings as $m): ?>
          <tr>
            <td class="fw-semibold"><?= e($m['title'] ?? '—') ?></td>
            <td>
              <?= formatDate($m['meeting_date'] ?? '') ?><br>
              <small class="text-muted"><i class="far fa-clock me-1"></i><?= date('h:i A', strtotime($m['start_time'] ?? 'now')) ?></small>
            </td>
            <td>
              <?php if ($m['type'] === 'physical'): ?>
              <i class="fas fa-map-marker-alt text-danger me-1"></i><?= e($m['location'] ?: '—') ?>
              <?php else: ?>
              <a href="<?= e($m['meeting_link']) ?>" target="_blank" class="text-decoration-none text-primary"><i class="fas fa-link me-1"></i>Join Video</a>
              <?php endif; ?>
            </td>
            <td><?= e($m['organizer_name'] ?? 'External Host') ?></td>
            <td>
              <?php
              $statusColors = ['scheduled' => 'info', 'ongoing' => 'warning text-dark', 'completed' => 'success', 'cancelled' => 'danger'];
              $sc = $statusColors[$m['status']] ?? 'secondary';
              ?>
              <span class="badge bg-<?= $sc ?>"><?= ucfirst($m['status'] ?? 'scheduled') ?></span>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#meetingsTable").DataTable({pageLength:10,order:[[1,"asc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
