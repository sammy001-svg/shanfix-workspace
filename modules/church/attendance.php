<?php
// ── CHURCH: Service Attendance ─────────────────────────────────
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

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'record') {
        $serviceDate = $_POST['service_date'] ?? date('Y-m-d');
        $serviceType = sanitize($_POST['service_type'] ?? 'Sunday Service');
        $statuses    = $_POST['status'] ?? [];   // array keyed by member_id

        $upsert = $pdo->prepare("
            INSERT INTO church_attendance (org_id, service_date, service_type, member_id, status)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status)
        ");
        $count = 0;
        foreach ($statuses as $memberId => $st) {
            $memberId = (int)$memberId;
            $st = in_array($st, ['present','absent','excused']) ? $st : 'absent';
            if ($memberId > 0) {
                $upsert->execute([$orgId, $serviceDate, $serviceType, $memberId, $st]);
                $count++;
            }
        }
        setFlash('success', "Attendance saved for $count members on ".formatDate($serviceDate)." ($serviceType).");
        logActivity('create', 'church', "Attendance recorded: $serviceDate — $serviceType");
        redirect('attendance.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Mode: record new or view history
$mode        = $_GET['mode'] ?? 'history'; // 'record' or 'history'
$serviceDate = $_GET['date'] ?? date('Y-m-d');
$serviceType = $_GET['type'] ?? 'Sunday Service';

$serviceTypes = ['Sunday Service','Wednesday Service','Friday Service','Youth Service','Special Service','Prayer Meeting','Revival'];

// Active members list for recording
$activeMembers = [];
try {
    $stmt = $pdo->prepare("SELECT id, member_no, first_name, last_name, cell_group, department FROM church_members WHERE org_id=? AND status='active' ORDER BY first_name ASC");
    $stmt->execute([$orgId]);
    $activeMembers = $stmt->fetchAll();
} catch (Exception $e) {}

// Existing attendance for this date+type (for pre-fill)
$existingAtt = [];
if ($mode === 'record') {
    try {
        $stmt = $pdo->prepare("SELECT member_id, status FROM church_attendance WHERE org_id=? AND service_date=? AND service_type=?");
        $stmt->execute([$orgId, $serviceDate, $serviceType]);
        foreach ($stmt->fetchAll() as $row) {
            $existingAtt[$row['member_id']] = $row['status'];
        }
    } catch (Exception $e) {}
}

// History: distinct service dates with counts
$history = [];
try {
    $stmt = $pdo->prepare("
        SELECT service_date, service_type,
               COUNT(*) AS total,
               SUM(status='present') AS present_count,
               SUM(status='absent')  AS absent_count,
               SUM(status='excused') AS excused_count
        FROM church_attendance
        WHERE org_id=?
        GROUP BY service_date, service_type
        ORDER BY service_date DESC
        LIMIT 50
    ");
    $stmt->execute([$orgId]);
    $history = $stmt->fetchAll();
} catch (Exception $e) {}

// Quick stats
$totalServices  = count($history);
$avgAttendance  = 0;
if ($totalServices > 0) {
    $avgAttendance = round(array_sum(array_column($history, 'present_count')) / $totalServices);
}
$thisWeekPresent = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(status='present'),0) FROM church_attendance WHERE org_id=? AND service_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    $stmt->execute([$orgId]);
    $thisWeekPresent = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-clipboard-check me-2" style="color:<?= $moduleColor ?>"></i>Service Attendance</h4>
    <p class="text-muted mb-0">Record and track congregation attendance for every service</p>
  </div>
  <?php if ($mode === 'record'): ?>
  <a href="attendance.php" class="btn btn-outline-secondary"><i class="fas fa-history me-1"></i>View History</a>
  <?php else: ?>
  <a href="attendance.php?mode=record" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-plus me-1"></i>Take Attendance</a>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(142,68,173,.12);color:#8e44ad"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalServices ?></div><div class="stat-label">Services Recorded</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $avgAttendance ?></div><div class="stat-label">Avg. Attendance per Service</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-user-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $thisWeekPresent ?></div><div class="stat-label">Attended This Week</div></div>
    </div>
  </div>
</div>

<?php if ($mode === 'record'): ?>
<!-- ── Recording Form ──────────────────────────────────────────── -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="mode" value="record">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Service Date</label>
        <input type="date" name="date" class="form-control form-control-sm" value="<?= $serviceDate ?>">
      </div>
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1">Service Type</label>
        <select name="type" class="form-select form-select-sm">
          <?php foreach ($serviceTypes as $st): ?>
          <option value="<?= $st ?>" <?= $serviceType === $st ? 'selected' : '' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-arrow-right me-1"></i>Load Members</button>
      </div>
    </form>
  </div>
</div>

<form method="POST">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="record">
  <input type="hidden" name="service_date" value="<?= e($serviceDate) ?>">
  <input type="hidden" name="service_type" value="<?= e($serviceType) ?>">

  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h6 class="mb-0"><i class="fas fa-clipboard-check me-2" style="color:<?= $moduleColor ?>"></i>
        <?= e($serviceType) ?> — <?= formatDate($serviceDate) ?>
        <?php if (!empty($existingAtt)): ?><span class="badge bg-success ms-2">Previously saved</span><?php endif; ?>
      </h6>
      <div>
        <button type="button" class="btn btn-sm btn-outline-success me-1" onclick="markAll('present')"><i class="fas fa-check-double me-1"></i>All Present</button>
        <button type="button" class="btn btn-sm btn-outline-danger me-1" onclick="markAll('absent')"><i class="fas fa-times me-1"></i>All Absent</button>
        <button type="submit" class="btn btn-sm text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Attendance</button>
      </div>
    </div>
    <div class="card-body p-0">
      <?php if (empty($activeMembers)): ?>
      <div class="text-center text-muted py-5"><i class="fas fa-users fa-2x mb-2 d-block"></i>No active members found. <a href="members.php">Register members first.</a></div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0" id="attTable">
          <thead class="table-light">
            <tr>
              <th style="width:30px">#</th>
              <th>Member</th>
              <th>Cell / Dept</th>
              <th class="text-center" style="width:220px">
                <span class="me-3 text-success">Present</span>
                <span class="me-3 text-danger">Absent</span>
                <span class="text-warning">Excused</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($activeMembers as $i => $m):
              $saved = $existingAtt[$m['id']] ?? 'present';
            ?>
            <tr>
              <td class="text-muted small"><?= $i + 1 ?></td>
              <td>
                <div class="fw-semibold"><?= e($m['first_name'].' '.$m['last_name']) ?></div>
                <div class="small text-muted"><?= e($m['member_no']) ?></div>
              </td>
              <td class="small text-muted"><?= e($m['cell_group'] ?: '—') ?><?= $m['department'] ? ' · '.e($m['department']) : '' ?></td>
              <td class="text-center">
                <div class="btn-group btn-group-sm att-group" data-member="<?= $m['id'] ?>">
                  <input type="radio" class="btn-check" name="status[<?= $m['id'] ?>]" id="p<?= $m['id'] ?>" value="present" <?= $saved === 'present' ? 'checked' : '' ?>>
                  <label class="btn btn-outline-success" for="p<?= $m['id'] ?>"><i class="fas fa-check"></i></label>

                  <input type="radio" class="btn-check" name="status[<?= $m['id'] ?>]" id="a<?= $m['id'] ?>" value="absent" <?= $saved === 'absent' ? 'checked' : '' ?>>
                  <label class="btn btn-outline-danger" for="a<?= $m['id'] ?>"><i class="fas fa-times"></i></label>

                  <input type="radio" class="btn-check" name="status[<?= $m['id'] ?>]" id="e<?= $m['id'] ?>" value="excused" <?= $saved === 'excused' ? 'checked' : '' ?>>
                  <label class="btn btn-outline-warning" for="e<?= $m['id'] ?>"><i class="fas fa-clock"></i></label>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="p-3 d-flex justify-content-end">
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Attendance (<?= count($activeMembers) ?> members)</button>
      </div>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php else: ?>
<!-- ── History View ────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-history me-2" style="color:<?= $moduleColor ?>"></i>Attendance History</h6>
    <span class="badge bg-secondary"><?= count($history) ?> services</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Service Type</th>
            <th class="text-center text-success">Present</th>
            <th class="text-center text-danger">Absent</th>
            <th class="text-center text-warning">Excused</th>
            <th class="text-center">Total</th>
            <th class="text-center">Attendance %</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($history)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted">
            <i class="fas fa-clipboard fa-2x mb-2 d-block"></i>No attendance records yet.
          </td></tr>
          <?php else: foreach ($history as $h):
            $pct = $h['total'] > 0 ? round(($h['present_count'] / $h['total']) * 100) : 0;
          ?>
          <tr>
            <td class="fw-semibold"><?= formatDate($h['service_date']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= e($h['service_type']) ?></span></td>
            <td class="text-center fw-bold text-success"><?= $h['present_count'] ?></td>
            <td class="text-center text-danger"><?= $h['absent_count'] ?></td>
            <td class="text-center text-warning"><?= $h['excused_count'] ?></td>
            <td class="text-center"><?= $h['total'] ?></td>
            <td class="text-center">
              <div class="progress" style="height:8px;min-width:80px">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $moduleColor ?>"></div>
              </div>
              <small class="text-muted"><?= $pct ?>%</small>
            </td>
            <td class="text-center">
              <a href="attendance.php?mode=record&date=<?= urlencode($h['service_date']) ?>&type=<?= urlencode($h['service_type']) ?>"
                 class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = '<script>
function markAll(status) {
  document.querySelectorAll("input[type=radio][value=" + status + "]").forEach(function(r){
    r.checked = true;
  });
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
