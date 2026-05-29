<?php
$moduleSlug  = 'crm';
$moduleName  = 'CRM — Customer Relations';
$moduleIcon  = 'fas fa-handshake';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt',       'label' => 'Dashboard'],
    ['url' => 'contacts.php',  'icon' => 'fas fa-address-book',         'label' => 'Contacts'],
    ['url' => 'companies.php', 'icon' => 'fas fa-building',             'label' => 'Companies'],
    ['url' => 'leads.php',     'icon' => 'fas fa-filter',               'label' => 'Leads'],
    ['url' => 'deals.php',     'icon' => 'fas fa-handshake',            'label' => 'Deals'],
    ['url' => 'pipeline.php',  'icon' => 'fas fa-columns',              'label' => 'Pipeline'],
    ['url' => 'quotes.php',    'icon' => 'fas fa-file-invoice',         'label' => 'Quotes'],
    ['url' => 'products.php',  'icon' => 'fas fa-box-open',             'label' => 'Products'],
    ['url' => 'activities.php','icon' => 'fas fa-tasks',                'label' => 'Activities'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-check-square',         'label' => 'Tasks'],
    ['url' => 'campaigns.php', 'icon' => 'fas fa-bullhorn',             'label' => 'Campaigns'],
    ['url' => 'contracts.php', 'icon' => 'fas fa-file-signature',       'label' => 'Contracts'],
    ['url' => 'tickets.php',   'icon' => 'fas fa-headset',              'label' => 'Support Tickets'],
    ['url' => 'email-log.php', 'icon' => 'fas fa-envelope-open-text',   'label' => 'Email Log'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',            'label' => 'Reports'],
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
        $id          = (int)($_POST['id'] ?? 0);
        $contactId   = (int)($_POST['contact_id'] ?? 0) ?: null;
        $subject     = sanitize($_POST['subject'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $category    = in_array($_POST['category'] ?? '', ['bug','billing','feature','question','other']) ? $_POST['category'] : 'question';
        $priority    = in_array($_POST['priority'] ?? '', ['low','medium','high','urgent']) ? $_POST['priority'] : 'medium';
        $status      = in_array($_POST['status'] ?? '', ['open','in_progress','resolved','closed']) ? $_POST['status'] : 'open';
        $assignedTo  = sanitize($_POST['assigned_to'] ?? '');
        $resolvedAt  = ($status === 'resolved' && $id === 0) ? date('Y-m-d H:i:s') : ($_POST['resolved_at'] ?? null);

        if (empty($subject)) { setFlash('danger', 'Subject is required.'); redirect('tickets.php'); }

        if ($id > 0) {
            // auto set resolved_at if status flipped to resolved
            $resolvedAt = ($status === 'resolved') ? date('Y-m-d H:i:s') : null;
            $pdo->prepare("UPDATE crm_tickets SET contact_id=?,subject=?,description=?,category=?,priority=?,status=?,assigned_to=?,resolved_at=? WHERE id=? AND org_id=?")
                ->execute([$contactId,$subject,$description,$category,$priority,$status,$assignedTo,$resolvedAt,$id,$orgId]);
            setFlash('success', 'Ticket updated.');
            logActivity('update', 'crm', "Updated ticket: $subject");
        } else {
            $year = date('Y');
            $seq  = 1;
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_tickets WHERE org_id=? AND ref LIKE ?");
                $stmt->execute([$orgId, "TKT-$year-%"]);
                $seq = (int)$stmt->fetchColumn() + 1;
            } catch (Exception $e) {}
            $ref = 'TKT-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $resolvedAt = ($status === 'resolved') ? date('Y-m-d H:i:s') : null;

            $pdo->prepare("INSERT INTO crm_tickets(org_id,ref,contact_id,subject,description,category,priority,status,assigned_to,resolved_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$ref,$contactId,$subject,$description,$category,$priority,$status,$assignedTo,$resolvedAt]);
            setFlash('success', "Ticket '$ref' created.");
            logActivity('create', 'crm', "Created ticket: $subject ($ref)");
        }
        redirect('tickets.php');
    }

    if ($action === 'status_update') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['open','in_progress','resolved','closed']) ? $_POST['status'] : 'open';
        $resolvedAt = ($status === 'resolved') ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE crm_tickets SET status=?,resolved_at=? WHERE id=? AND org_id=?")->execute([$status,$resolvedAt,$id,$orgId]);
        setFlash('success', 'Ticket status updated.');
        redirect('tickets.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM crm_tickets WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Ticket deleted.');
        redirect('tickets.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$tickets = [];
try {
    $stmt = $pdo->prepare("SELECT t.*,CONCAT(c.first_name,' ',c.last_name) AS contact_name
        FROM crm_tickets t
        LEFT JOIN crm_contacts c ON t.contact_id=c.id
        WHERE t.org_id=? ORDER BY t.created_at DESC");
    $stmt->execute([$orgId]);
    $tickets = $stmt->fetchAll();
} catch (Exception $e) {}

$contacts = [];
try {
    $stmt = $pdo->prepare("SELECT id,first_name,last_name,company FROM crm_contacts WHERE org_id=? ORDER BY first_name");
    $stmt->execute([$orgId]);
    $contacts = $stmt->fetchAll();
} catch (Exception $e) {}

$openCount     = countRows('crm_tickets', 'org_id=? AND status=?', [$orgId,'open']);
$inProgCount   = countRows('crm_tickets', 'org_id=? AND status=?', [$orgId,'in_progress']);
$urgentCount   = countRows('crm_tickets', 'org_id=? AND priority=? AND status NOT IN (?,?)', [$orgId,'urgent','resolved','closed']);
$resolvedToday = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_tickets WHERE org_id=? AND status='resolved' AND DATE(resolved_at)=CURDATE()");
    $stmt->execute([$orgId]);
    $resolvedToday = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-headset me-2" style="color:<?= $moduleColor ?>"></i>Support Tickets</h4>
    <p class="text-muted mb-0">Track and resolve customer support requests</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#tkModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>New Ticket
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-folder-open"></i></div><div class="stat-body"><div class="stat-value"><?= $openCount ?></div><div class="stat-label">Open</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-spinner"></i></div><div class="stat-body"><div class="stat-value"><?= $inProgCount ?></div><div class="stat-label">In Progress</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?= $resolvedToday ?></div><div class="stat-label">Resolved Today</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-fire"></i></div><div class="stat-body"><div class="stat-value"><?= $urgentCount ?></div><div class="stat-label">Urgent Open</div></div></div></div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-headset me-2" style="color:<?= $moduleColor ?>"></i>Ticket Queue</h6>
    <span class="badge bg-secondary"><?= count($tickets) ?> tickets</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="tkTable">
        <thead class="table-light">
          <tr><th>Ref</th><th>Subject</th><th>Contact</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Created</th><th class="text-center no-print">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($tickets)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No tickets found.</td></tr>
          <?php else: foreach ($tickets as $t):
            $prioColors = ['low'=>'success','medium'=>'info','high'=>'warning','urgent'=>'danger'];
            $prioColor  = $prioColors[$t['priority']] ?? 'secondary';
          ?>
          <tr>
            <td><code class="bg-light px-2 py-1 rounded text-dark"><?= e($t['ref'] ?? '—') ?></code></td>
            <td class="fw-semibold"><?= e($t['subject']) ?></td>
            <td><?= e($t['contact_name'] ?? '—') ?></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst($t['category']) ?></span></td>
            <td><span class="badge bg-<?= $prioColor ?> <?= in_array($prioColor,['warning','info'])?'text-dark':'' ?>"><?= ucfirst($t['priority']) ?></span></td>
            <td>
              <form method="POST" class="d-inline no-print">
                <?= csrfField() ?><input type="hidden" name="action" value="status_update"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                <select name="status" class="form-select form-select-sm" style="width:auto;display:inline-block" onchange="this.form.submit()">
                  <?php foreach (['open','in_progress','resolved','closed'] as $s): ?>
                  <option value="<?= $s ?>" <?= $t['status']===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td><?= e($t['assigned_to'] ?? '—') ?></td>
            <td><?= formatDate($t['created_at'] ?? '') ?></td>
            <td class="text-center no-print" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='fillForm(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delTicket(<?= $t['id'] ?>,'<?= e($t['ref'] ?? '') ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="tkModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="tkId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="tkModalTitle"><i class="fas fa-headset me-2"></i>New Ticket</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12"><label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label><input type="text" name="subject" id="tkSubject" class="form-control" required maxlength="255"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Contact</label><select name="contact_id" id="tkContact" class="form-select"><option value="">-- None --</option><?php foreach ($contacts as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name'] . ($c['company'] ? ' (' . $c['company'] . ')' : '')) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Assigned To</label><input type="text" name="assigned_to" id="tkAssigned" class="form-control" maxlength="100" placeholder="Agent name…"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Category</label><select name="category" id="tkCategory" class="form-select"><option value="bug">Bug</option><option value="billing">Billing</option><option value="feature">Feature Request</option><option value="question">Question</option><option value="other">Other</option></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Priority</label><select name="priority" id="tkPriority" class="form-select"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="tkStatus" class="form-select"><option value="open">Open</option><option value="in_progress">In Progress</option><option value="resolved">Resolved</option><option value="closed">Closed</option></select></div>
            <div class="col-12"><label class="form-label fw-semibold">Description</label><textarea name="description" id="tkDesc" class="form-control" rows="4"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Ticket</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delTkForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delTkId"></form>
<?php
$extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('tkModalTitle').innerHTML='<i class="fas fa-headset me-2"></i>New Ticket';
  ['tkId','tkSubject','tkAssigned','tkDesc'].forEach(i=>document.getElementById(i).value=i==='tkId'?'0':'');
  document.getElementById('tkContact').value='';
  document.getElementById('tkCategory').value='question';
  document.getElementById('tkPriority').value='medium';
  document.getElementById('tkStatus').value='open';
}
function fillForm(t){
  document.getElementById('tkModalTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Ticket';
  document.getElementById('tkId').value=t.id;
  document.getElementById('tkSubject').value=t.subject||'';
  document.getElementById('tkContact').value=t.contact_id||'';
  document.getElementById('tkAssigned').value=t.assigned_to||'';
  document.getElementById('tkCategory').value=t.category||'question';
  document.getElementById('tkPriority').value=t.priority||'medium';
  document.getElementById('tkStatus').value=t.status||'open';
  document.getElementById('tkDesc').value=t.description||'';
  new bootstrap.Modal(document.getElementById('tkModal')).show();
}
function delTicket(id,ref){
  Swal.fire({title:'Delete Ticket?',text:ref+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delTkId').value=id;document.getElementById('delTkForm').submit();}});
}
$(document).ready(function(){$('#tkTable').DataTable({pageLength:15,order:[[7,'desc']],language:{emptyTable:'No tickets found.'}});});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
