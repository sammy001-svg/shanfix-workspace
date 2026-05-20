<?php
// ── MEETINGS: Action Items ─────────────────────────────────────
$moduleSlug  = 'meetings';
$moduleName  = 'Meeting Management';
$moduleIcon  = 'fas fa-video';
$moduleColor = '#0B2D4E';
$moduleNav   = [
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
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id']         ?? 0);
        $meetingId   = (int)($_POST['meeting_id'] ?? 0);
        $description = sanitize($_POST['description']  ?? '');
        $assignedTo  = sanitize($_POST['assigned_to']  ?? '');
        $dueDate     = $_POST['due_date'] ?? null;
        $priority    = in_array($_POST['priority'] ?? '', ['low','medium','high']) ? $_POST['priority'] : 'medium';
        $status      = in_array($_POST['status'] ?? '', ['pending','in_progress','done','cancelled']) ? $_POST['status'] : 'pending';

        if (!$meetingId || !$description) {
            setFlash('danger', 'Meeting and description are required.');
            redirect('actions.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE meeting_action_items SET meeting_id=?,description=?,assigned_to=?,due_date=?,priority=?,status=? WHERE id=? AND org_id=?")
                ->execute([$meetingId, $description, $assignedTo, $dueDate ?: null, $priority, $status, $id, $orgId]);
            setFlash('success', 'Action item updated.');
            logActivity('update', 'meetings', "Updated action item #$id");
        } else {
            $pdo->prepare("INSERT INTO meeting_action_items (org_id,meeting_id,description,assigned_to,due_date,priority,status) VALUES (?,?,?,?,?,?,?)")
                ->execute([$orgId, $meetingId, $description, $assignedTo, $dueDate ?: null, $priority, $status]);
            setFlash('success', 'Action item added.');
            logActivity('create', 'meetings', "Added action item: $description");
        }
        redirect('actions.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM meeting_action_items WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Action item deleted.');
        logActivity('delete', 'meetings', "Deleted action item #$id");
        redirect('actions.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterStatus   = $_GET['status']   ?? '';
$filterPriority = $_GET['priority'] ?? '';
$where  = 'a.org_id = ?';
$params = [$orgId];
if ($filterStatus)   { $where .= ' AND a.status = ?';   $params[] = $filterStatus; }
if ($filterPriority) { $where .= ' AND a.priority = ?'; $params[] = $filterPriority; }

$actions = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, m.title AS meeting_title, m.meeting_date
        FROM meeting_action_items a
        JOIN meetings m ON a.meeting_id = m.id
        WHERE $where ORDER BY
          FIELD(a.priority,'high','medium','low'),
          FIELD(a.status,'pending','in_progress','done','cancelled'),
          a.due_date ASC
    ");
    $stmt->execute($params);
    $actions = $stmt->fetchAll();
} catch (Exception $e) {}

$meetings = [];
try {
    $stmt = $pdo->prepare("SELECT id, title, meeting_date FROM meetings WHERE org_id=? ORDER BY meeting_date DESC LIMIT 50");
    $stmt->execute([$orgId]);
    $meetings = $stmt->fetchAll();
} catch (Exception $e) {}

$pendingCount  = countRows('meeting_action_items', "org_id=? AND status='pending'",     [$orgId]);
$inProgCount   = countRows('meeting_action_items', "org_id=? AND status='in_progress'", [$orgId]);
$doneCount     = countRows('meeting_action_items', "org_id=? AND status='done'",        [$orgId]);
$overdueCount  = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM meeting_action_items WHERE org_id=? AND status NOT IN ('done','cancelled') AND due_date < CURDATE()");
    $stmt->execute([$orgId]);
    $overdueCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$priorityColors = ['high' => 'danger', 'medium' => 'warning', 'low' => 'secondary'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-tasks me-2" style="color:<?= $moduleColor ?>"></i>Action Items</h4>
    <p class="text-muted mb-0">Track tasks and follow-ups from your meetings</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#actModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Add Action
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div><div class="stat-value"><?= $pendingCount ?></div><div class="stat-label">Pending</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-spinner"></i></div>
      <div><div class="stat-value"><?= $inProgCount ?></div><div class="stat-label">In Progress</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div>
      <div><div class="stat-value"><?= $doneCount ?></div><div class="stat-label">Completed</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-exclamation-circle"></i></div>
      <div><div class="stat-value"><?= $overdueCount ?></div><div class="stat-label">Overdue</div></div></div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="pending"     <?= $filterStatus==='pending'     ?'selected':'' ?>>Pending</option>
          <option value="in_progress" <?= $filterStatus==='in_progress' ?'selected':'' ?>>In Progress</option>
          <option value="done"        <?= $filterStatus==='done'        ?'selected':'' ?>>Done</option>
          <option value="cancelled"   <?= $filterStatus==='cancelled'   ?'selected':'' ?>>Cancelled</option>
        </select>
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Priority</label>
        <select name="priority" class="form-select form-select-sm">
          <option value="">All Priorities</option>
          <option value="high"   <?= $filterPriority==='high'   ?'selected':'' ?>>High</option>
          <option value="medium" <?= $filterPriority==='medium' ?'selected':'' ?>>Medium</option>
          <option value="low"    <?= $filterPriority==='low'    ?'selected':'' ?>>Low</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="actions.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-tasks me-2" style="color:<?= $moduleColor ?>"></i>Action Items List</h6>
    <span class="badge bg-secondary"><?= count($actions) ?> items</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Action Item</th>
            <th>Meeting</th>
            <th>Assigned To</th>
            <th>Due Date</th>
            <th>Priority</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($actions)): ?>
          <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-tasks fa-2x mb-2 d-block"></i>No action items found.</td></tr>
          <?php else: foreach ($actions as $a): ?>
          <?php $isOverdue = $a['due_date'] && $a['due_date'] < date('Y-m-d') && !in_array($a['status'],['done','cancelled']); ?>
          <tr class="<?= $isOverdue ? 'table-danger' : '' ?>">
            <td>
              <div class="fw-semibold"><?= e($a['description']) ?></div>
              <?php if ($isOverdue): ?><div class="small text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Overdue</div><?php endif; ?>
            </td>
            <td>
              <div class="small fw-semibold"><?= e($a['meeting_title']) ?></div>
              <div class="text-muted" style="font-size:.7rem"><?= formatDate($a['meeting_date']) ?></div>
            </td>
            <td class="small"><?= e($a['assigned_to'] ?: '—') ?></td>
            <td class="small <?= $isOverdue ? 'text-danger fw-bold' : '' ?>"><?= $a['due_date'] ? formatDate($a['due_date']) : '—' ?></td>
            <td><span class="badge bg-<?= $priorityColors[$a['priority']] ?? 'secondary' ?>"><?= ucfirst($a['priority']) ?></span></td>
            <td><?= statusBadge($a['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this action item?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $a['id'] ?>">
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

<div class="modal fade" id="actModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="actId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="actTitle"><i class="fas fa-tasks me-2"></i>Add Action Item</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-12">
              <label class="form-label fw-semibold">Meeting <span class="text-danger">*</span></label>
              <select name="meeting_id" id="actMeeting" class="form-select" required>
                <option value="">— Select Meeting —</option>
                <?php foreach ($meetings as $m): ?>
                <option value="<?= $m['id'] ?>"><?= e($m['title']) ?> — <?= formatDate($m['meeting_date']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-12">
              <label class="form-label fw-semibold">Action Item Description <span class="text-danger">*</span></label>
              <textarea name="description" id="actDesc" class="form-control" rows="2" required placeholder="What needs to be done?"></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Assigned To</label>
              <input type="text" name="assigned_to" id="actAssigned" class="form-control" placeholder="Person responsible">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Due Date</label>
              <input type="date" name="due_date" id="actDue" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Priority</label>
              <select name="priority" id="actPriority" class="form-select">
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="low">Low</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="actStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="in_progress">In Progress</option>
                <option value="done">Done</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Action</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("actTitle").innerHTML    = "<i class=\"fas fa-tasks me-2\"></i>Add Action Item";
  document.getElementById("actId").value       = 0;
  document.getElementById("actMeeting").value  = "";
  document.getElementById("actDesc").value     = "";
  document.getElementById("actAssigned").value = "";
  document.getElementById("actDue").value      = "";
  document.getElementById("actPriority").value = "medium";
  document.getElementById("actStatus").value   = "pending";
}
function openEdit(a) {
  document.getElementById("actTitle").innerHTML    = "<i class=\"fas fa-edit me-2\"></i>Edit Action Item";
  document.getElementById("actId").value       = a.id;
  document.getElementById("actMeeting").value  = a.meeting_id   || "";
  document.getElementById("actDesc").value     = a.description  || "";
  document.getElementById("actAssigned").value = a.assigned_to  || "";
  document.getElementById("actDue").value      = a.due_date     || "";
  document.getElementById("actPriority").value = a.priority     || "medium";
  document.getElementById("actStatus").value   = a.status       || "pending";
  new bootstrap.Modal(document.getElementById("actModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
