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

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

// Get current year and month for calendar navigation
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$firstDayOfMonth = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int)date('t', $firstDayOfMonth);
$startOfWeek = (int)date('w', $firstDayOfMonth); // 0 (Sun) to 6 (Sat)

$monthName = date('F', $firstDayOfMonth);

// Fetch all meetings for this specific month/year
$meetings = [];
try {
    $stmt = $pdo->prepare("SELECT m.*, u.name AS organizer_name 
                           FROM meetings m 
                           LEFT JOIN users u ON m.organizer_id = u.id
                           WHERE m.org_id = ? AND YEAR(m.meeting_date) = ? AND MONTH(m.meeting_date) = ?
                           ORDER BY m.start_time ASC");
    $stmt->execute([$orgId, $year, $month]);
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($res as $row) {
        $dayNum = (int)date('j', strtotime($row['meeting_date']));
        $meetings[$dayNum][] = $row;
    }
} catch (Exception $e) {}

$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-alt me-2" style="color:<?= $moduleColor ?>"></i>Calendar View</h4>
    <p class="text-muted mb-0">Visualize meetings schedule in calendar layout and view today's active sessions</p>
  </div>
  <a href="meetings.php" class="btn btn-outline-primary"><i class="fas fa-list me-2"></i>Meetings List</a>
</div>

<div class="card">
  <div class="card-header bg-light d-flex align-items-center justify-content-between py-3">
    <div class="d-flex align-items-center">
      <h5 class="mb-0 fw-bold me-3 text-dark"><?= $monthName ?> <?= $year ?></h5>
      <div class="btn-group btn-group-sm">
        <a href="calendar.php?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn btn-outline-secondary" title="Previous Month"><i class="fas fa-chevron-left"></i></a>
        <a href="calendar.php?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="btn btn-outline-secondary">Today</a>
        <a href="calendar.php?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn btn-outline-secondary" title="Next Month"><i class="fas fa-chevron-right"></i></a>
      </div>
    </div>
    <div>
      <span class="badge bg-info p-2 me-1"><i class="fas fa-info-circle me-1"></i>Scheduled</span>
      <span class="badge bg-warning text-dark p-2 me-1"><i class="fas fa-play me-1"></i>Ongoing</span>
      <span class="badge bg-success p-2"><i class="fas fa-check-circle me-1"></i>Completed</span>
    </div>
  </div>
  <div class="card-body p-0">
    <!-- Calendar CSS Grid -->
    <div class="calendar-grid">
      <!-- Days of Week Headers -->
      <div class="calendar-header-day text-center fw-bold py-2 border-bottom bg-light text-muted">Sun</div>
      <div class="calendar-header-day text-center fw-bold py-2 border-bottom bg-light text-muted">Mon</div>
      <div class="calendar-header-day text-center fw-bold py-2 border-bottom bg-light text-muted">Tue</div>
      <div class="calendar-header-day text-center fw-bold py-2 border-bottom bg-light text-muted">Wed</div>
      <div class="calendar-header-day text-center fw-bold py-2 border-bottom bg-light text-muted">Thu</div>
      <div class="calendar-header-day text-center fw-bold py-2 border-bottom bg-light text-muted">Fri</div>
      <div class="calendar-header-day text-center fw-bold py-2 border-bottom bg-light text-muted">Sat</div>

      <!-- Blank cells for start of week -->
      <?php for ($i = 0; $i < $startOfWeek; $i++): ?>
        <div class="calendar-day-cell blank bg-light border-end border-bottom" style="opacity:0.4;"></div>
      <?php endfor; ?>

      <!-- Days cells -->
      <?php 
      $todayDate = date('Y-m-d');
      for ($day = 1; $day <= $daysInMonth; $day++): 
        $currentDateString = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $isToday = ($currentDateString === $todayDate);
      ?>
        <div class="calendar-day-cell border-end border-bottom p-2 <?= $isToday ? 'bg-light-blue border-primary' : '' ?>" style="min-height: 120px; position:relative;">
          <span class="day-number fw-bold <?= $isToday ? 'text-primary' : 'text-secondary' ?>" style="font-size:0.95rem;"><?= $day ?></span>
          
          <div class="mt-1 d-flex flex-column gap-1 overflow-auto" style="max-height:85px;">
            <?php if (isset($meetings[$day])): foreach ($meetings[$day] as $meet): 
              $statusColors = ['scheduled' => 'info', 'ongoing' => 'warning text-dark', 'completed' => 'success', 'cancelled' => 'danger'];
              $sc = $statusColors[$meet['status']] ?? 'secondary';
            ?>
              <div class="meeting-chip btn-sm bg-<?= $sc ?> p-1 rounded text-white text-truncate" 
                   style="font-size: 0.72rem; cursor: pointer; line-height: 1.2;" 
                   onclick="viewQuickDetails(<?= $meet['id'] ?>)" 
                   title="<?= e($meet['title']) ?>">
                <strong class="d-block" style="font-size:0.68rem; opacity:0.85;"><?= date('h:i A', strtotime($meet['start_time'])) ?></strong>
                <?= e($meet['title']) ?>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      <?php endfor; ?>

      <!-- Fill in the rest of the week if necessary -->
      <?php 
      $totalCells = $startOfWeek + $daysInMonth;
      $remainingCells = (7 - ($totalCells % 7)) % 7;
      for ($i = 0; $i < $remainingCells; $i++): ?>
        <div class="calendar-day-cell blank bg-light border-bottom" style="opacity:0.4;"></div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<!-- Quick Detail Modal -->
<div class="modal fade" id="quickDetailModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Meeting Details</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body py-4">
    <div id="quickSpinner" class="text-center py-4"><div class="spinner-border text-primary"></div></div>
    <div id="quickContent" style="display:none">
      <h5 class="fw-bold mb-1 text-dark" id="qTitle"></h5>
      <div class="text-muted small mb-3" id="qDesc"></div>
      
      <table class="table table-sm table-borderless mb-3">
        <tr>
          <td class="fw-semibold text-secondary" style="width:110px;"><i class="fas fa-calendar-day me-2"></i>Date:</td>
          <td id="qDate"></td>
        </tr>
        <tr>
          <td class="fw-semibold text-secondary"><i class="far fa-clock me-2"></i>Time:</td>
          <td id="qTime"></td>
        </tr>
        <tr>
          <td class="fw-semibold text-secondary"><i class="fas fa-map-marker-alt me-2"></i>Location:</td>
          <td id="qLoc"></td>
        </tr>
        <tr>
          <td class="fw-semibold text-secondary"><i class="fas fa-user-tie me-2"></i>Host:</td>
          <td id="qHost"></td>
        </tr>
        <tr>
          <td class="fw-semibold text-secondary"><i class="fas fa-check-circle me-2"></i>Status:</td>
          <td><span class="badge" id="qStatus"></span></td>
        </tr>
      </table>
      
      <div class="mt-4">
        <h6 class="fw-semibold border-bottom pb-1"><i class="fas fa-users me-2 text-primary"></i>Attendees List</h6>
        <ul class="list-group list-group-flush list-group-sm" id="qAttendees"></ul>
      </div>
    </div>
  </div>
  <div class="modal-footer bg-light d-flex justify-content-between">
    <a href="" id="editMBtn" class="btn btn-sm btn-primary"><i class="fas fa-edit me-1"></i>Open Edit Details</a>
    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
  </div>
</div></div></div>

<style>
.calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  width: 100%;
  background-color: #dee2e6;
}
.calendar-header-day {
  background-color: #f8f9fa;
  font-size: 0.85rem;
}
.calendar-day-cell {
  background-color: #fff;
}
.bg-light-blue {
  background-color: #f1f8ff !important;
}
.meeting-chip.bg-info {
  background-color: #0b5ed7 !important;
}
.meeting-chip.bg-warning {
  background-color: #ffca28 !important;
  color: #333 !important;
}
.meeting-chip.bg-success {
  background-color: #198754 !important;
}
.meeting-chip.bg-danger {
  background-color: #dc3545 !important;
}
</style>

<?php
$extraJs = <<<'JS'
<script>
function viewQuickDetails(id) {
  const modal = new bootstrap.Modal(document.getElementById('quickDetailModal'));
  modal.show();
  
  document.getElementById('quickSpinner').style.display = 'block';
  document.getElementById('quickContent').style.display = 'none';
  
  fetch('meetings.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('qTitle').textContent = data.title;
      document.getElementById('qDesc').textContent = data.description || 'No agenda outline provided.';
      document.getElementById('qDate').textContent = data.meeting_date;
      document.getElementById('qTime').textContent = data.start_time.substring(0, 5) + ' - ' + data.end_time.substring(0, 5);
      
      if (data.type === 'physical') {
        document.getElementById('qLoc').innerHTML = `<span class="badge bg-light text-dark"><i class="fas fa-building me-1"></i>${data.location || '—'}</span>`;
      } else {
        document.getElementById('qLoc').innerHTML = `<a href="${data.meeting_link}" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2 font-monospace"><i class="fas fa-external-link-alt me-1"></i>Launch Video</a>`;
      }
      
      document.getElementById('qHost').textContent = data.organizer_name || 'External Host';
      
      const stEl = document.getElementById('qStatus');
      stEl.className = 'badge';
      if (data.status === 'scheduled') { stEl.classList.add('bg-primary'); stEl.textContent = 'Scheduled'; }
      else if (data.status === 'ongoing') { stEl.classList.add('bg-warning', 'text-dark'); stEl.textContent = 'Ongoing'; }
      else if (data.status === 'completed') { stEl.classList.add('bg-success'); stEl.textContent = 'Completed'; }
      else { stEl.classList.add('bg-danger'); stEl.textContent = 'Cancelled'; }
      
      const attUl = document.getElementById('qAttendees');
      attUl.innerHTML = '';
      if (data.attendees && data.attendees.length > 0) {
        data.attendees.forEach(a => {
          const li = document.createElement('li');
          li.className = 'list-group-item d-flex align-items-center justify-content-between py-1 px-0 border-0 border-bottom';
          li.innerHTML = `
            <div class="fw-semibold text-dark" style="font-size:0.85rem;">${a.name}</div>
            <div class="text-muted small" style="font-size:0.8rem;">${a.email || '—'}</div>
          `;
          attUl.appendChild(li);
        });
      } else {
        attUl.innerHTML = '<li class="list-group-item text-muted text-center py-2 px-0 border-0">No attendees mapped.</li>';
      }
      
      document.getElementById('editMBtn').href = 'meetings.php?fetch_details=' + data.id; // Will trigger full edit modal loading trigger
      // Intercept the click on the button so it loads the edit in list page
      document.getElementById('editMBtn').onclick = (e) => {
        e.preventDefault();
        modal.hide();
        window.location.href = 'meetings.php';
      };
      
      document.getElementById('quickSpinner').style.display = 'none';
      document.getElementById('quickContent').style.display = 'block';
    });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
