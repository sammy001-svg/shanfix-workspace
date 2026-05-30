<?php
// ── Hotel: Housekeeping ───────────────────────────────────────
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $roomId      = (int)($_POST['room_id'] ?? 0);
        $taskDate    = sanitize($_POST['task_date'] ?? date('Y-m-d'));
        $taskType    = sanitize($_POST['task_type'] ?? 'daily_clean');
        $assignedTo  = sanitize($_POST['assigned_to'] ?? '');
        $priority    = in_array($_POST['priority'] ?? '', ['low','normal','high','urgent']) ? $_POST['priority'] : 'normal';
        $status      = in_array($_POST['status'] ?? '', ['pending','in_progress','done','skipped']) ? $_POST['status'] : 'pending';
        $notes       = sanitize($_POST['notes'] ?? '');
        $completedAt = $status === 'done' ? 'NOW()' : 'NULL';

        if ($roomId <= 0) { setFlash('danger', 'Room is required.'); redirect('housekeeping.php'); }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE hotel_housekeeping SET room_id=?, task_date=?, task_type=?, assigned_to=?, priority=?, status=?, notes=?, updated_at=NOW(), completed_at=IF(?='done',NOW(),completed_at) WHERE id=? AND org_id=?")
                    ->execute([$roomId, $taskDate, $taskType, $assignedTo, $priority, $status, $notes, $status, $id, $orgId]);
                setFlash('success', 'Task updated.');
            } else {
                $pdo->prepare("INSERT INTO hotel_housekeeping (org_id, room_id, task_date, task_type, assigned_to, priority, status, notes) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $roomId, $taskDate, $taskType, $assignedTo, $priority, $status, $notes]);
                setFlash('success', 'Housekeeping task logged.');
            }
            logActivity('update', 'hotel', "Housekeeping task room#{$roomId}");
        } catch (Exception $e) { setFlash('danger', 'Error: ' . $e->getMessage()); }
        redirect('housekeeping.php');
    }

    if ($action === 'mark_done') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE hotel_housekeeping SET status='done', completed_at=NOW(), updated_at=NOW() WHERE id=? AND org_id=?")
            ->execute([$id, $orgId]);
        setFlash('success', 'Task marked as done.');
        redirect('housekeeping.php');
    }

    if ($action === 'generate_daily') {
        // Auto-generate daily clean tasks for all occupied/dirty rooms
        $date = sanitize($_POST['gen_date'] ?? date('Y-m-d'));
        try {
            $rooms = $pdo->prepare("SELECT id FROM hotel_rooms WHERE org_id=? AND status IN ('occupied','dirty')");
            $rooms->execute([$orgId]);
            $rooms = $rooms->fetchAll();
            $inserted = 0;
            foreach ($rooms as $r) {
                $exists = $pdo->prepare("SELECT id FROM hotel_housekeeping WHERE org_id=? AND room_id=? AND task_date=? AND task_type='daily_clean'");
                $exists->execute([$orgId, $r['id'], $date]);
                if (!$exists->fetch()) {
                    $pdo->prepare("INSERT INTO hotel_housekeeping (org_id, room_id, task_date, task_type, priority, status) VALUES (?,?,?,'daily_clean','normal','pending')")
                        ->execute([$orgId, $r['id'], $date]);
                    $inserted++;
                }
            }
            setFlash('success', "{$inserted} housekeeping tasks generated for {$date}.");
        } catch (Exception $e) { setFlash('danger', 'Error: ' . $e->getMessage()); }
        redirect('housekeeping.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fDate   = $_GET['date']   ?? date('Y-m-d');
$fStatus = $_GET['status'] ?? '';

$where  = 'h.org_id = ?';
$params = [$orgId];
if ($fDate   !== '') { $where .= ' AND h.task_date = ?'; $params[] = $fDate; }
if ($fStatus !== '') { $where .= ' AND h.status = ?'; $params[] = $fStatus; }

$tasks = $rooms = [];
try {
    $stmt = $pdo->prepare("
        SELECT h.*, r.room_no, r.floor
        FROM hotel_housekeeping h
        JOIN hotel_rooms r ON r.id = h.room_id
        WHERE {$where}
        ORDER BY FIELD(h.priority,'urgent','high','normal','low'), r.room_no
    ");
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, room_no, floor, status FROM hotel_rooms WHERE org_id=? ORDER BY room_no");
    $stmt->execute([$orgId]);
    $rooms = $stmt->fetchAll();
} catch (Exception $e) {}

$pending   = count(array_filter($tasks, fn($t) => $t['status'] === 'pending'));
$inProgress= count(array_filter($tasks, fn($t) => $t['status'] === 'in_progress'));
$done      = count(array_filter($tasks, fn($t) => $t['status'] === 'done'));

$priorityColors = ['urgent'=>'danger','high'=>'warning','normal'=>'info','low'=>'secondary'];
$statusColors   = ['pending'=>'secondary','in_progress'=>'warning','done'=>'success','skipped'=>'light border'];
$taskTypes = ['daily_clean'=>'Daily Clean','deep_clean'=>'Deep Clean','turndown'=>'Turndown','inspection'=>'Inspection','maintenance'=>'Maintenance'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-broom me-2" style="color:<?= $moduleColor ?>"></i>Housekeeping</h4>
    <p class="text-muted mb-0">Assign and track room cleaning tasks, inspections and turndown service</p>
  </div>
  <div class="d-flex gap-2">
    <form method="POST" class="d-inline">
      <?= csrfField() ?><input type="hidden" name="action" value="generate_daily">
      <input type="hidden" name="gen_date" value="<?= $fDate ?>">
      <button class="btn btn-outline-warning"><i class="fas fa-magic me-1"></i>Auto-Generate</button>
    </form>
    <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#hkModal" onclick="openAdd()">
      <i class="fas fa-plus me-1"></i>Add Task
    </button>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(211,84,0,.12);color:#d35400"><i class="fas fa-broom"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pending ?></div><div class="stat-label">Pending</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-spinner"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $inProgress ?></div><div class="stat-label">In Progress</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $done ?></div><div class="stat-label">Completed</div></div></div>
  </div>
</div>

<!-- Filter bar -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Date</label>
      <input type="date" name="date" class="form-control form-control-sm" value="<?= e($fDate) ?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (['pending','in_progress','done','skipped'] as $s): ?>
        <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-auto">
      <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="housekeeping.php" class="btn btn-sm btn-outline-secondary ms-1">Today</a></div>
  </form>
</div></div>

<div class="card border-0 shadow-sm"><div class="card-body p-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead class="table-light">
        <tr><th>Room</th><th>Task Type</th><th>Assigned To</th><th>Date</th><th class="text-center">Priority</th><th class="text-center">Status</th><th>Completed</th><th class="text-center">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($tasks)): ?>
        <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-broom fa-3x mb-3 d-block"></i>No tasks found.</td></tr>
        <?php else: foreach ($tasks as $t): ?>
        <tr>
          <td class="fw-semibold"><?= e('Room ' . $t['room_no'] . ' (Fl.' . $t['floor'] . ')') ?></td>
          <td><span class="badge bg-light text-dark border"><?= $taskTypes[$t['task_type']] ?? e($t['task_type']) ?></span></td>
          <td><?= $t['assigned_to'] ? e($t['assigned_to']) : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= date('d M Y', strtotime($t['task_date'])) ?></td>
          <td class="text-center"><span class="badge bg-<?= $priorityColors[$t['priority']] ?? 'secondary' ?>"><?= ucfirst($t['priority']) ?></span></td>
          <td class="text-center"><span class="badge bg-<?= $statusColors[$t['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$t['status'])) ?></span></td>
          <td class="small text-muted"><?= $t['completed_at'] ? date('d M, h:i A', strtotime($t['completed_at'])) : '—' ?></td>
          <td class="text-center" style="white-space:nowrap">
            <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
            <?php if ($t['status'] !== 'done'): ?>
            <form method="POST" class="d-inline">
              <?= csrfField() ?><input type="hidden" name="action" value="mark_done"><input type="hidden" name="id" value="<?= $t['id'] ?>">
              <button class="btn btn-sm btn-outline-success ms-1" title="Mark Done"><i class="fas fa-check"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div></div>

<!-- Housekeeping Modal -->
<div class="modal fade" id="hkModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="hkId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="hkTitle"><i class="fas fa-broom me-2"></i>Add Task</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Room <span class="text-danger">*</span></label>
              <select name="room_id" id="hkRoom" class="form-select" required>
                <option value="">-- Select Room --</option>
                <?php foreach ($rooms as $r): ?>
                <option value="<?= $r['id'] ?>">Room <?= e($r['room_no']) ?> (Fl.<?= $r['floor'] ?>) – <?= ucfirst($r['status']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Task Type</label>
              <select name="task_type" id="hkType" class="form-select">
                <?php foreach ($taskTypes as $k => $v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Date</label>
              <input type="date" name="task_date" id="hkDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Priority</label>
              <select name="priority" id="hkPriority" class="form-select">
                <option value="normal">Normal</option>
                <option value="low">Low</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Assigned To</label>
              <input type="text" name="assigned_to" id="hkAssigned" class="form-control" placeholder="Staff name">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="hkStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="in_progress">In Progress</option>
                <option value="done">Done</option>
                <option value="skipped">Skipped</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="hkNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function openAdd() {
    document.getElementById('hkTitle').innerHTML = '<i class="fas fa-broom me-2"></i>Add Task';
    document.getElementById('hkId').value       = '0';
    document.getElementById('hkRoom').value     = '';
    document.getElementById('hkType').value     = 'daily_clean';
    document.getElementById('hkDate').value     = new Date().toISOString().slice(0,10);
    document.getElementById('hkPriority').value = 'normal';
    document.getElementById('hkAssigned').value = '';
    document.getElementById('hkStatus').value   = 'pending';
    document.getElementById('hkNotes').value    = '';
}
function openEdit(t) {
    document.getElementById('hkTitle').innerHTML    = '<i class="fas fa-edit me-2"></i>Edit Task';
    document.getElementById('hkId').value           = t.id;
    document.getElementById('hkRoom').value         = t.room_id;
    document.getElementById('hkType').value         = t.task_type;
    document.getElementById('hkDate').value         = t.task_date;
    document.getElementById('hkPriority').value     = t.priority;
    document.getElementById('hkAssigned').value     = t.assigned_to || '';
    document.getElementById('hkStatus').value       = t.status;
    document.getElementById('hkNotes').value        = t.notes || '';
    new bootstrap.Modal(document.getElementById('hkModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
