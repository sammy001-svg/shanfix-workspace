<?php
$moduleSlug  = 'crm';
$moduleName  = 'CRM — Customer Relations';
$moduleIcon  = 'fas fa-handshake';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'contacts.php',  'icon' => 'fas fa-address-book',   'label' => 'Contacts'],
    ['url' => 'companies.php', 'icon' => 'fas fa-building',       'label' => 'Companies'],
    ['url' => 'leads.php',     'icon' => 'fas fa-filter',         'label' => 'Leads'],
    ['url' => 'deals.php',     'icon' => 'fas fa-handshake',      'label' => 'Deals'],
    ['url' => 'pipeline.php',  'icon' => 'fas fa-columns',        'label' => 'Pipeline'],
    ['url' => 'quotes.php',    'icon' => 'fas fa-file-invoice',   'label' => 'Quotes'],
    ['url' => 'products.php',  'icon' => 'fas fa-box-open',       'label' => 'Products'],
    ['url' => 'activities.php','icon' => 'fas fa-tasks',          'label' => 'Activities'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-check-square',   'label' => 'Tasks'],
    ['url' => 'campaigns.php', 'icon' => 'fas fa-bullhorn',       'label' => 'Campaigns'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $title     = sanitize($_POST['title'] ?? '');
        $desc      = sanitize($_POST['description'] ?? '');
        $priority  = in_array($_POST['priority'] ?? '', ['low','medium','high','urgent']) ? $_POST['priority'] : 'medium';
        $status    = in_array($_POST['status'] ?? '', ['todo','in_progress','done','cancelled']) ? $_POST['status'] : 'todo';
        $contactId = (int)($_POST['contact_id'] ?? 0) ?: null;
        $dealId    = (int)($_POST['deal_id'] ?? 0) ?: null;
        $dueDate   = $_POST['due_date'] ?? null;
        $dueTime   = $_POST['due_time'] ?: null;

        if ($id > 0) {
            $pdo->prepare("UPDATE crm_tasks SET title=?,description=?,priority=?,status=?,contact_id=?,deal_id=?,due_date=?,due_time=? WHERE id=? AND org_id=?")
                ->execute([$title,$desc,$priority,$status,$contactId,$dealId,$dueDate?:null,$dueTime,$id,$orgId]);
            setFlash('success', 'Task updated.');
        } else {
            $pdo->prepare("INSERT INTO crm_tasks (org_id,title,description,priority,status,contact_id,deal_id,due_date,due_time,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$title,$desc,$priority,$status,$contactId,$dealId,$dueDate?:null,$dueTime,$user['id']??0]);
            setFlash('success', "Task '$title' created.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'crm', "Task: $title");
        redirect('tasks.php');
    }

    if ($action === 'done') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE crm_tasks SET status='done' WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Task marked as done.');
        redirect('tasks.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM crm_tasks WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Task deleted.');
        redirect('tasks.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fStatus   = $_GET['status']   ?? '';
$fPriority = $_GET['priority'] ?? '';
$fQ        = trim($_GET['q']   ?? '');
$where     = 'org_id=?';
$params    = [$orgId];
if ($fStatus)   { $where .= ' AND status=?';   $params[] = $fStatus; }
if ($fPriority) { $where .= ' AND priority=?'; $params[] = $fPriority; }
if ($fQ)        { $where .= ' AND title LIKE ?'; $params[] = "%$fQ%"; }

$tasks = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*,
               CONCAT(c.first_name,' ',c.last_name) AS contact_name,
               d.title AS deal_title
        FROM crm_tasks t
        LEFT JOIN crm_contacts c ON t.contact_id = c.id
        LEFT JOIN crm_deals    d ON t.deal_id    = d.id
        WHERE t.$where ORDER BY
          FIELD(t.priority,'urgent','high','medium','low'),
          t.due_date ASC, t.created_at DESC
    ");
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
} catch (Exception $e) {}

$contacts = [];
try {
    $s = $pdo->prepare("SELECT id,first_name,last_name FROM crm_contacts WHERE org_id=? AND status='active' ORDER BY first_name");
    $s->execute([$orgId]);
    $contacts = $s->fetchAll();
} catch (Exception $e) {}

$deals = [];
try {
    $s = $pdo->prepare("SELECT id,title FROM crm_deals WHERE org_id=? AND status='open' ORDER BY title");
    $s->execute([$orgId]);
    $deals = $s->fetchAll();
} catch (Exception $e) {}

$cTodo  = countRows('crm_tasks', 'org_id=? AND status=?', [$orgId,'todo']);
$cProg  = countRows('crm_tasks', 'org_id=? AND status=?', [$orgId,'in_progress']);
$cDone  = countRows('crm_tasks', 'org_id=? AND status=?', [$orgId,'done']);
$cUrgent= countRows('crm_tasks', "org_id=? AND priority='urgent' AND status NOT IN ('done','cancelled')", [$orgId]);

$today    = date('Y-m-d');
$overdue  = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM crm_tasks WHERE org_id=? AND due_date < ? AND status NOT IN ('done','cancelled')");
    $s->execute([$orgId, $today]);
    $overdue = (int)$s->fetchColumn();
} catch (Exception $e) {}

$priorityColors = ['low'=>'success','medium'=>'info','high'=>'warning','urgent'=>'danger'];
$statusColors   = ['todo'=>'secondary','in_progress'=>'primary','done'=>'success','cancelled'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-check-square me-2" style="color:<?= $moduleColor ?>"></i>Tasks</h4>
    <p class="text-muted mb-0">Track and manage CRM-related tasks with priorities and deadlines</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#tModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>New Task
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-list-ul"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $cTodo ?></div><div class="stat-label">To Do</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-spinner"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $cProg ?></div><div class="stat-label">In Progress</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $cDone ?></div><div class="stat-label">Completed</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon" style="background:#dc3545;color:#fff"><i class="fas fa-exclamation-circle"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= $overdue ?></div>
      <div class="stat-label">Overdue</div>
    </div></div>
  </div>
</div>

<?php if ($overdue): ?>
<div class="alert alert-danger d-flex align-items-center gap-3 mb-3">
  <i class="fas fa-exclamation-triangle fa-lg"></i>
  <span><strong><?= $overdue ?> overdue task<?= $overdue > 1 ? 's' : '' ?></strong> — please review and update them.</span>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Search</label>
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Task title…" value="<?= e($fQ) ?>"></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (['todo','in_progress','done','cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= $fStatus===$s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Priority</label>
      <select name="priority" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (['low','medium','high','urgent'] as $p): ?>
        <option value="<?= $p ?>" <?= $fPriority===$p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-auto">
      <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="tasks.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
    </div>
  </form>
</div></div>

<!-- Task Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-check-square me-2" style="color:<?= $moduleColor ?>"></i>Task List</h6>
    <span class="badge bg-secondary"><?= count($tasks) ?> tasks</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr><th>Title</th><th>Priority</th><th>Status</th><th>Contact</th><th>Deal</th><th>Due Date</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($tasks)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-check-square fa-2x mb-2 d-block"></i>No tasks found.</td></tr>
        <?php else: foreach ($tasks as $t):
            $isOverdue = $t['due_date'] && $t['due_date'] < $today && !in_array($t['status'], ['done','cancelled']);
        ?>
          <tr class="<?= $t['status']==='done' ? 'table-light' : ($isOverdue ? 'table-danger bg-opacity-10' : '') ?>">
            <td>
              <div class="<?= $t['status']==='done' ? 'text-decoration-line-through text-muted' : 'fw-semibold' ?>"><?= e($t['title']) ?></div>
              <?php if ($t['description']): ?>
              <div class="small text-muted"><?= e(substr($t['description'],0,60)) ?><?= strlen($t['description'])>60?'…':'' ?></div>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $priorityColors[$t['priority']] ?? 'secondary' ?> <?= $t['priority']==='medium'?'text-dark':'' ?>"><?= ucfirst($t['priority']) ?></span></td>
            <td><span class="badge bg-<?= $statusColors[$t['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$t['status'])) ?></span></td>
            <td class="small"><?= e($t['contact_name'] ?? '—') ?></td>
            <td class="small"><?= e($t['deal_title'] ?? '—') ?></td>
            <td>
              <?php if ($t['due_date']): ?>
                <span class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
                  <?= formatDate($t['due_date']) ?>
                  <?php if ($t['due_time']): ?><br><small class="text-muted"><?= htmlspecialchars(substr($t['due_time'],0,5)) ?></small><?php endif; ?>
                  <?php if ($isOverdue): ?><span class="badge bg-danger ms-1">Overdue</span><?php endif; ?>
                </span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="text-center" style="white-space:nowrap">
              <?php if ($t['status'] !== 'done' && $t['status'] !== 'cancelled'): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?><input type="hidden" name="action" value="done"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-success" title="Mark Done"><i class="fas fa-check"></i></button>
              </form>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEdit(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delTask(<?= $t['id'] ?>,'<?= e($t['title']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="tModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="tId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="tModalTitle"><i class="fas fa-check-square me-2"></i>New Task</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Task Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="tTitle" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Priority</label>
              <select name="priority" id="tPriority" class="form-select">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="tStatus" class="form-select">
                <option value="todo">To Do</option>
                <option value="in_progress">In Progress</option>
                <option value="done">Done</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Due Date</label>
              <input type="date" name="due_date" id="tDueDate" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Due Time</label>
              <input type="time" name="due_time" id="tDueTime" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Linked Contact</label>
              <select name="contact_id" id="tContact" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($contacts as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['first_name'].' '.$c['last_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Linked Deal</label>
              <select name="deal_id" id="tDeal" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($deals as $d): ?>
                <option value="<?= $d['id'] ?>"><?= e($d['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description / Notes</label>
              <textarea name="description" id="tDesc" class="form-control" rows="3"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Task</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delTForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delTId"></form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('tModalTitle').innerHTML = '<i class="fas fa-check-square me-2"></i>New Task';
  ['tId','tTitle','tDueDate','tDueTime','tDesc'].forEach(i => document.getElementById(i).value = i==='tId' ? '0' : '');
  document.getElementById('tPriority').value = 'medium';
  document.getElementById('tStatus').value   = 'todo';
  document.getElementById('tContact').value  = '';
  document.getElementById('tDeal').value     = '';
}
function openEdit(t) {
  document.getElementById('tModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Task';
  document.getElementById('tId').value       = t.id;
  document.getElementById('tTitle').value    = t.title || '';
  document.getElementById('tPriority').value = t.priority || 'medium';
  document.getElementById('tStatus').value   = t.status || 'todo';
  document.getElementById('tDueDate').value  = t.due_date ? t.due_date.substring(0,10) : '';
  document.getElementById('tDueTime').value  = t.due_time ? t.due_time.substring(0,5) : '';
  document.getElementById('tContact').value  = t.contact_id || '';
  document.getElementById('tDeal').value     = t.deal_id || '';
  document.getElementById('tDesc').value     = t.description || '';
  new bootstrap.Modal(document.getElementById('tModal')).show();
}
function delTask(id, title) {
  Swal.fire({title:'Delete Task?',text:'"'+title+'" will be removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r => { if (r.isConfirmed) { document.getElementById('delTId').value = id; document.getElementById('delTForm').submit(); } });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
