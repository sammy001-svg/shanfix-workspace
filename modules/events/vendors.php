<?php
// ── EVENTS: Vendor Management ───────────────────────────────────
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
    verifyCsrf();denyIfReadOnly($moduleSlug); $user = currentUser(); $orgId = (int)$user['org_id']; $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $eventId  = (int)($_POST['event_id'] ?? 0) ?: null;
        $name     = sanitize($_POST['name'] ?? '');
        $category = sanitize($_POST['category'] ?? '');
        $contact  = sanitize($_POST['contact_person'] ?? '');
        $phone    = sanitize($_POST['phone'] ?? '');
        $email    = sanitize($_POST['email'] ?? '');
        $service  = sanitize($_POST['service_description'] ?? '');
        $amount   = (float)($_POST['contracted_amount'] ?? 0);
        $paid     = (float)($_POST['amount_paid'] ?? 0);
        $status   = in_array($_POST['status'] ?? '', ['pending','confirmed','active','completed','cancelled']) ? $_POST['status'] : 'pending';
        $notes    = sanitize($_POST['notes'] ?? '');
        if (!$name) { setFlash('error', 'Vendor name is required.'); redirect('vendors.php'); }
        if ($id > 0) {
            $pdo->prepare("UPDATE event_vendors SET event_id=?,name=?,category=?,contact_person=?,phone=?,email=?,service_description=?,contracted_amount=?,amount_paid=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$eventId,$name,$category,$contact,$phone,$email,$service,$amount,$paid,$status,$notes,$id,$orgId]);
            setFlash('success', 'Vendor updated.');
        } else {
            $pdo->prepare("INSERT INTO event_vendors(org_id,event_id,name,category,contact_person,phone,email,service_description,contracted_amount,amount_paid,status,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$eventId,$name,$category,$contact,$phone,$email,$service,$amount,$paid,$status,$notes]);
            setFlash('success', "Vendor $name added.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'events', "Vendor: $name");
        redirect('vendors.php');
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM event_vendors WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Vendor removed.'); redirect('vendors.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$fEvent  = (int)($_GET['event_id'] ?? 0);
$fStatus = $_GET['status'] ?? '';

$where = 'v.org_id=?'; $params = [$orgId];
if ($fEvent)  { $where .= ' AND v.event_id=?'; $params[] = $fEvent; }
if ($fStatus) { $where .= ' AND v.status=?'; $params[] = $fStatus; }

$vendors = [];
try {
    $s = $pdo->prepare("SELECT v.*, e.title AS event_title FROM event_vendors v LEFT JOIN events e ON v.event_id=e.id WHERE $where ORDER BY v.created_at DESC");
    $s->execute($params); $vendors = $s->fetchAll();
} catch (Exception $e) {}

$events = [];
try { $s = $pdo->prepare("SELECT id,title FROM events WHERE org_id=? ORDER BY start_date DESC LIMIT 50"); $s->execute([$orgId]); $events = $s->fetchAll(); } catch (Exception $e) {}

$totalVendors = 0; $totalContracted = 0; $totalPaid = 0; $activeCount = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*),COALESCE(SUM(contracted_amount),0),COALESCE(SUM(amount_paid),0) FROM event_vendors WHERE org_id=?"); $s->execute([$orgId]); $r = $s->fetch(PDO::FETCH_NUM); $totalVendors=(int)$r[0]; $totalContracted=(float)$r[1]; $totalPaid=(float)$r[2];
    $s = $pdo->prepare("SELECT COUNT(*) FROM event_vendors WHERE org_id=? AND status IN('confirmed','active')"); $s->execute([$orgId]); $activeCount=(int)$s->fetchColumn();
} catch (Exception $e) {}

$categories = ['Catering','Decoration','Audio/Visual','Photography','Security','Transport','Entertainment','Venue','Printing','Other'];
$statusColors = ['pending'=>'warning','confirmed'=>'primary','active'=>'success','completed'=>'info','cancelled'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-store me-2" style="color:<?= $moduleColor ?>"></i>Vendors</h4>
    <p class="text-muted mb-0">Manage service vendors and suppliers for events</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#vModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Vendor
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#f3e5f5;color:<?=$moduleColor?>"><i class="fas fa-store"></i></div><div class="stat-body"><div class="stat-value"><?=$totalVendors?></div><div class="stat-label">Total Vendors</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$activeCount?></div><div class="stat-label">Active/Confirmed</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-file-contract"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($totalContracted)?></div><div class="stat-label">Total Contracted</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-coins"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($totalPaid)?></div><div class="stat-label">Total Paid</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-5"><label class="form-label small fw-semibold mb-1">Event</label>
      <select name="event_id" class="form-select form-select-sm">
        <option value="">All Events</option>
        <?php foreach ($events as $ev): ?><option value="<?=$ev['id']?>" <?=$fEvent==$ev['id']?'selected':''?>><?=e($ev['title'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <?php foreach (array_keys($statusColors) as $s): ?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="vendors.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-store me-2" style="color:<?=$moduleColor?>"></i>Vendor List</h6>
    <span class="badge bg-secondary"><?=count($vendors)?> vendors</span>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Vendor</th><th>Event</th><th>Category</th><th>Contact</th><th>Contracted</th><th>Paid</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($vendors)): ?>
      <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-store fa-2x mb-2 d-block"></i>No vendors added yet.</td></tr>
    <?php else: foreach ($vendors as $v): $balance = $v['contracted_amount'] - $v['amount_paid']; ?>
      <tr>
        <td><div class="fw-semibold"><?=e($v['name'])?></div><div class="small text-muted"><?=e($v['email']??'')?></div></td>
        <td class="small"><?=e($v['event_title']??'General')?></td>
        <td><span class="badge bg-secondary"><?=e($v['category']??'—')?></span></td>
        <td class="small"><?=e($v['contact_person']??'—')?><?=$v['phone']?'<br><span class="text-muted">'.e($v['phone']).'</span>':''?></td>
        <td class="fw-semibold"><?=formatCurrency($v['contracted_amount'])?></td>
        <td><?=formatCurrency($v['amount_paid'])?><?=$balance>0?'<br><small class="text-danger">Balance: '.formatCurrency($balance).'</small>':''?></td>
        <td><span class="badge bg-<?=$statusColors[$v['status']]??'secondary'?>"><?=ucfirst($v['status'])?></span></td>
        <td class="text-center" style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($v),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger ms-1" onclick="delVendor(<?=$v['id']?>,<?=json_encode($v['name'])?>)" title="Delete"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>
</div>

<!-- Modal -->
<div class="modal fade" id="vModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="vId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="vTitle"><i class="fas fa-store me-2"></i>Add Vendor</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-8"><label class="form-label fw-semibold">Vendor Name <span class="text-danger">*</span></label>
      <input type="text" name="name" id="vName" class="form-control" required maxlength="200"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Category</label>
      <input type="text" name="category" id="vCat" class="form-control" list="catList" maxlength="100">
      <datalist id="catList"><?php foreach ($categories as $cat): ?><option value="<?=e($cat)?>"><?php endforeach; ?></datalist></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Event</label>
      <select name="event_id" id="vEvent" class="form-select">
        <option value="">— General (no specific event) —</option>
        <?php foreach ($events as $ev): ?><option value="<?=$ev['id']?>"><?=e($ev['title'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="vStatus" class="form-select">
        <?php foreach (array_keys($statusColors) as $s): ?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Contact Person</label>
      <input type="text" name="contact_person" id="vContact" class="form-control" maxlength="150"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Phone</label>
      <input type="text" name="phone" id="vPhone" class="form-control" maxlength="50"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Email</label>
      <input type="email" name="email" id="vEmail" class="form-control"></div>
    <div class="col-12"><label class="form-label fw-semibold">Service Description</label>
      <textarea name="service_description" id="vService" class="form-control" rows="2"></textarea></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Contracted Amount</label>
      <input type="number" name="contracted_amount" id="vAmount" class="form-control" step="0.01" min="0" value="0"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Amount Paid</label>
      <input type="number" name="amount_paid" id="vPaid" class="form-control" step="0.01" min="0" value="0"></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label>
      <textarea name="notes" id="vNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Vendor</button>
  </div></form>
</div></div></div>
<form method="POST" id="delVForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delVId"></form>

<?php $extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('vTitle').innerHTML='<i class="fas fa-store me-2"></i>Add Vendor';
  document.getElementById('vId').value='0';
  ['vName','vCat','vContact','vPhone','vEmail','vService','vNotes'].forEach(x=>document.getElementById(x).value='');
  document.getElementById('vEvent').value='';
  document.getElementById('vStatus').value='pending';
  document.getElementById('vAmount').value='0';
  document.getElementById('vPaid').value='0';
}
function openEdit(v){
  document.getElementById('vTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Vendor';
  document.getElementById('vId').value=v.id;
  document.getElementById('vName').value=v.name||'';
  document.getElementById('vCat').value=v.category||'';
  document.getElementById('vEvent').value=v.event_id||'';
  document.getElementById('vStatus').value=v.status||'pending';
  document.getElementById('vContact').value=v.contact_person||'';
  document.getElementById('vPhone').value=v.phone||'';
  document.getElementById('vEmail').value=v.email||'';
  document.getElementById('vService').value=v.service_description||'';
  document.getElementById('vAmount').value=v.contracted_amount||'0';
  document.getElementById('vPaid').value=v.amount_paid||'0';
  document.getElementById('vNotes').value=v.notes||'';
  new bootstrap.Modal(document.getElementById('vModal')).show();
}
function delVendor(id,name){
  Swal.fire({title:'Remove Vendor?',text:name+' will be deleted.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delVId').value=id;document.getElementById('delVForm').submit();}});
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
