<?php
// ── EVENTS: Task & To-Do Management ────────────────────────────
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user = currentUser(); $orgId = (int)$user['org_id']; $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $eventId  = (int)($_POST['event_id'] ?? 0) ?: null;
        $title    = sanitize($_POST['title'] ?? '');
        $desc     = sanitize($_POST['description'] ?? '');
        $assignee = sanitize($_POST['assigned_to'] ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['low','medium','high','critical']) ? $_POST['priority'] : 'medium';
        $dueDate  = $_POST['due_date'] ?? null;
        $status   = in_array($_POST['status'] ?? '', ['todo','in_progress','done','cancelled']) ? $_POST['status'] : 'todo';
        if (!$title) { setFlash('error', 'Task title is required.'); redirect('tasks.php'); }
        if ($id > 0) {
            $pdo->prepare("UPDATE event_tasks SET event_id=?,title=?,description=?,assigned_to=?,priority=?,due_date=?,status=? WHERE id=? AND org_id=?")
                ->execute([$eventId,$title,$desc,$assignee,$priority,$dueDate?:null,$status,$id,$orgId]);
            setFlash('success', 'Task updated.');
        } else {
            $pdo->prepare("INSERT INTO event_tasks(org_id,event_id,title,description,assigned_to,priority,due_date,status)VALUES(?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$eventId,$title,$desc,$assignee,$priority,$dueDate?:null,$status]);
            setFlash('success', "Task '$title' created.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'events', "Task: $title");
        redirect('tasks.php');
    }
    if ($action === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $cur = sanitize($_POST['current_status'] ?? 'todo');
        $new = $cur === 'done' ? 'todo' : 'done';
        $pdo->prepare("UPDATE event_tasks SET status=? WHERE id=? AND org_id=?")->execute([$new,$id,$orgId]);
        redirect('tasks.php');
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM event_tasks WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Task deleted.'); redirect('tasks.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$fEvent    = (int)($_GET['event_id'] ?? 0);
$fPriority = $_GET['priority'] ?? '';
$fStatus   = $_GET['status'] ?? '';

$where = 't.org_id=?'; $params = [$orgId];
if ($fEvent)    { $where .= ' AND t.event_id=?'; $params[] = $fEvent; }
if ($fPriority) { $where .= ' AND t.priority=?'; $params[] = $fPriority; }
if ($fStatus)   { $where .= ' AND t.status=?'; $params[] = $fStatus; }

$tasks = [];
try {
    $s = $pdo->prepare("SELECT t.*,e.title AS event_title FROM event_tasks t LEFT JOIN events e ON t.event_id=e.id WHERE $where ORDER BY FIELD(t.priority,'critical','high','medium','low'), t.due_date ASC, t.id DESC");
    $s->execute($params); $tasks = $s->fetchAll();
} catch (Exception $e) {}

$events = [];
try { $s = $pdo->prepare("SELECT id,title FROM events WHERE org_id=? ORDER BY start_date DESC LIMIT 50"); $s->execute([$orgId]); $events = $s->fetchAll(); } catch (Exception $e) {}

$todoCount = 0; $inProgressCount = 0; $doneCount = 0; $overdueCount = 0;
try {
    $today = date('Y-m-d');
    $s = $pdo->prepare("SELECT COUNT(*) FROM event_tasks WHERE org_id=? AND status='todo'"); $s->execute([$orgId]); $todoCount=(int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM event_tasks WHERE org_id=? AND status='in_progress'"); $s->execute([$orgId]); $inProgressCount=(int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM event_tasks WHERE org_id=? AND status='done'"); $s->execute([$orgId]); $doneCount=(int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM event_tasks WHERE org_id=? AND status NOT IN('done','cancelled') AND due_date IS NOT NULL AND due_date < ?"); $s->execute([$orgId,$today]); $overdueCount=(int)$s->fetchColumn();
} catch (Exception $e) {}

$priorityColors = ['low'=>'success','medium'=>'info','high'=>'warning','critical'=>'danger'];
$statusColors   = ['todo'=>'secondary','in_progress'=>'primary','done'=>'success','cancelled'=>'dark'];
$statusLabels   = ['todo'=>'To Do','in_progress'=>'In Progress','done'=>'Done','cancelled'=>'Cancelled'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-tasks me-2" style="color:<?=$moduleColor?>"></i>Tasks</h4>
    <p class="text-muted mb-0">Track event preparation tasks and assignments</p>
  </div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#taskModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Task
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#f3e5f5;color:<?=$moduleColor?>"><i class="fas fa-list"></i></div><div class="stat-body"><div class="stat-value"><?=$todoCount?></div><div class="stat-label">To Do</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-spinner"></i></div><div class="stat-body"><div class="stat-value"><?=$inProgressCount?></div><div class="stat-label">In Progress</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div><div class="stat-body"><div class="stat-value"><?=$doneCount?></div><div class="stat-label">Done</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#fde8e8;color:#c0392b"><i class="fas fa-exclamation-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$overdueCount?></div><div class="stat-label">Overdue</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Event</label>
      <select name="event_id" class="form-select form-select-sm">
        <option value="">All Events</option>
        <?php foreach ($events as $ev): ?><option value="<?=$ev['id']?>" <?=$fEvent==$ev['id']?'selected':''?>><?=e($ev['title'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Priority</label>
      <select name="priority" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (array_keys($priorityColors) as $p): ?><option value="<?=$p?>" <?=$fPriority===$p?'selected':''?>><?=ucfirst($p)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach ($statusLabels as $k=>$v): ?><option value="<?=$k?>" <?=$fStatus===$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
      </select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="tasks.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-tasks me-2" style="color:<?=$moduleColor?>"></i>Task List</h6>
    <span class="badge bg-secondary"><?=count($tasks)?> tasks</span>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th style="width:40px"></th><th>Task</th><th>Event</th><th>Assigned To</th><th>Priority</th><th>Due Date</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($tasks)): ?>
      <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-tasks fa-2x mb-2 d-block"></i>No tasks yet.</td></tr>
    <?php else: foreach ($tasks as $t):
        $today = date('Y-m-d');
        $overdue = $t['due_date'] && $t['due_date'] < $today && $t['status'] !== 'done' && $t['status'] !== 'cancelled';
    ?>
      <tr class="<?=$overdue?'table-warning':($t['status']==='done'?'opacity-75':'')?>">
        <td class="text-center">
          <form method="POST" class="d-inline">
            <?=csrfField()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$t['id']?>"><input type="hidden" name="current_status" value="<?=$t['status']?>">
            <button type="submit" class="btn btn-sm <?=$t['status']==='done'?'btn-success':'btn-outline-secondary'?>" title="Toggle done"><i class="fas fa-check"></i></button>
          </form>
        </td>
        <td>
          <div class="fw-semibold <?=$t['status']==='done'?'text-decoration-line-through text-muted':''?>"><?=e($t['title'])?></div>
          <?php if ($t['description']): ?><div class="small text-muted"><?=e(mb_substr($t['description'],0,80)).(mb_strlen($t['description'])>80?'…':'')?></div><?php endif; ?>
        </td>
        <td class="small"><?=e($t['event_title']??'General')?></td>
        <td class="small"><?=e($t['assigned_to']??'—')?></td>
        <td><span class="badge bg-<?=$priorityColors[$t['priority']]??'secondary'?>"><?=ucfirst($t['priority'])?></span></td>
        <td class="small <?=$overdue?'text-danger fw-bold':''?>"><?=$t['due_date']?formatDate($t['due_date']).''.($overdue?' <i class="fas fa-exclamation-triangle"></i>':''):'—'?></td>
        <td><span class="badge bg-<?=$statusColors[$t['status']]??'secondary'?>"><?=$statusLabels[$t['status']]??e($t['status'])?></span></td>
        <td class="text-center" style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($t),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger ms-1" onclick="delTask(<?=$t['id']?>,<?=json_encode($t['title'])?>)" title="Delete"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>
</div>

<!-- Task Modal -->
<div class="modal fade" id="taskModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="tId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="tTitle"><i class="fas fa-tasks me-2"></i>Add Task</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12"><label class="form-label fw-semibold">Task Title <span class="text-danger">*</span></label>
      <input type="text" name="title" id="tTitle2" class="form-control" required maxlength="255"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Event</label>
      <select name="event_id" id="tEvent" class="form-select">
        <option value="">— General —</option>
        <?php foreach ($events as $ev): ?><option value="<?=$ev['id']?>"><?=e($ev['title'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Assigned To</label>
      <input type="text" name="assigned_to" id="tAssignee" class="form-control" maxlength="150" placeholder="Name or team"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Priority</label>
      <select name="priority" id="tPriority" class="form-select">
        <?php foreach (array_keys($priorityColors) as $p): ?><option value="<?=$p?>" <?=$p==='medium'?'selected':''?>><?=ucfirst($p)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Due Date</label>
      <input type="date" name="due_date" id="tDue" class="form-control"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="tStatus" class="form-select">
        <?php foreach ($statusLabels as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
      </select></div>
    <div class="col-12"><label class="form-label fw-semibold">Description</label>
      <textarea name="description" id="tDesc" class="form-control" rows="3"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Task</button>
  </div></form>
</div></div></div>
<form method="POST" id="delTaskForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delTaskId"></form>

<?php $extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('tTitle').innerHTML='<i class="fas fa-tasks me-2"></i>Add Task';
  document.getElementById('tId').value='0';
  document.getElementById('tTitle2').value='';
  document.getElementById('tEvent').value='';
  document.getElementById('tAssignee').value='';
  document.getElementById('tPriority').value='medium';
  document.getElementById('tDue').value='';
  document.getElementById('tStatus').value='todo';
  document.getElementById('tDesc').value='';
}
function openEdit(t){
  document.getElementById('tTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Task';
  document.getElementById('tId').value=t.id;
  document.getElementById('tTitle2').value=t.title||'';
  document.getElementById('tEvent').value=t.event_id||'';
  document.getElementById('tAssignee').value=t.assigned_to||'';
  document.getElementById('tPriority').value=t.priority||'medium';
  document.getElementById('tDue').value=t.due_date?t.due_date.substring(0,10):'';
  document.getElementById('tStatus').value=t.status||'todo';
  document.getElementById('tDesc').value=t.description||'';
  new bootstrap.Modal(document.getElementById('taskModal')).show();
}
function delTask(id,title){
  Swal.fire({title:'Delete Task?',text:'"'+title+'" will be removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delTaskId').value=id;document.getElementById('delTaskForm').submit();}});
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
