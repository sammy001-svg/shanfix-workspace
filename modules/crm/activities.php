<?php
$moduleSlug='crm';$moduleName='CRM — Customer Relations';$moduleIcon='fas fa-handshake';$moduleColor='#0B2D4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'contacts.php','icon'=>'fas fa-address-book','label'=>'Contacts'],['url'=>'companies.php','icon'=>'fas fa-building','label'=>'Companies'],['url'=>'leads.php','icon'=>'fas fa-filter','label'=>'Leads'],['url'=>'deals.php','icon'=>'fas fa-handshake','label'=>'Deals'],['url'=>'pipeline.php','icon'=>'fas fa-columns','label'=>'Pipeline'],['url'=>'quotes.php','icon'=>'fas fa-file-invoice','label'=>'Quotes'],['url'=>'products.php','icon'=>'fas fa-box-open','label'=>'Products'],['url'=>'activities.php','icon'=>'fas fa-tasks','label'=>'Activities'],['url'=>'tasks.php','icon'=>'fas fa-check-square','label'=>'Tasks'],['url'=>'campaigns.php','icon'=>'fas fa-bullhorn','label'=>'Campaigns'],['url'=>'contracts.php','icon'=>'fas fa-file-signature','label'=>'Contracts'],['url'=>'tickets.php','icon'=>'fas fa-headset','label'=>'Support Tickets'],['url'=>'email-log.php','icon'=>'fas fa-envelope-open-text','label'=>'Email Log'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);$contactId=(int)($_POST['contact_id']??0)?:null;$dealId=(int)($_POST['deal_id']??0)?:null;
        $type=in_array($_POST['type']??'',['call','email','meeting','note','task'])?$_POST['type']:'note';
        $subject=sanitize($_POST['subject']??'');$desc=sanitize($_POST['description']??'');
        $due=$_POST['due_date']??null;$done=isset($_POST['done'])?1:0;
        if($id>0){$pdo->prepare("UPDATE crm_activities SET contact_id=?,deal_id=?,type=?,subject=?,description=?,due_date=?,done=? WHERE id=? AND org_id=?")->execute([$contactId,$dealId,$type,$subject,$desc,$due?:null,$done,$id,$orgId]);setFlash('success','Activity updated.');}
        else{$pdo->prepare("INSERT INTO crm_activities(org_id,contact_id,deal_id,type,subject,description,due_date,done,created_by)VALUES(?,?,?,?,?,?,?,?,?)")->execute([$orgId,$contactId,$dealId,$type,$subject,$desc,$due?:null,$done,$user['id']??0]);setFlash('success','Activity logged.');}
        logActivity($id>0?'update':'create','crm',"Activity: $subject");redirect('activities.php');
    }
    if($action==='toggle'){$id=(int)($_POST['id']??0);$pdo->prepare("UPDATE crm_activities SET done=NOT done WHERE id=? AND org_id=?")->execute([$id,$orgId]);redirect('activities.php');}
    if($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM crm_activities WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Activity deleted.');redirect('activities.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fType=$_GET['type']??'';$fDone=$_GET['done']??'';
$where='org_id=?';$params=[$orgId];
if($fType){$where.=' AND type=?';$params[]=$fType;}
if($fDone!==''){$where.=' AND done=?';$params[]=(int)$fDone;}
$activities=[];
try{
    $stmt=$pdo->prepare("SELECT a.*,CONCAT(c.first_name,' ',c.last_name) AS contact_name,d.title AS deal_title FROM crm_activities a LEFT JOIN crm_contacts c ON a.contact_id=c.id LEFT JOIN crm_deals d ON a.deal_id=d.id WHERE $where ORDER BY a.due_date ASC,a.created_at DESC");
    $stmt->execute($params);$activities=$stmt->fetchAll();
}catch(Exception $e){}
$contacts=[];try{$stmt=$pdo->prepare("SELECT id,first_name,last_name FROM crm_contacts WHERE org_id=? AND status='active' ORDER BY first_name");$stmt->execute([$orgId]);$contacts=$stmt->fetchAll();}catch(Exception $e){}
$deals=[];try{$stmt=$pdo->prepare("SELECT id,title FROM crm_deals WHERE org_id=? AND status='open' ORDER BY title");$stmt->execute([$orgId]);$deals=$stmt->fetchAll();}catch(Exception $e){}
$total=countRows('crm_activities','org_id=?',[$orgId]);
$pending=countRows('crm_activities','org_id=? AND done=?',[$orgId,0]);
$done=countRows('crm_activities','org_id=? AND done=?',[$orgId,1]);
$typeIcons=['call'=>'fas fa-phone','email'=>'fas fa-envelope','meeting'=>'fas fa-calendar-alt','note'=>'fas fa-sticky-note','task'=>'fas fa-tasks'];
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-tasks me-2" style="color:<?=$moduleColor?>"></i>Activities</h4><p class="text-muted mb-0">Log calls, emails, meetings, notes and tasks</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#aModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Log Activity</button>
</div>
<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-list-alt"></i></div><div class="stat-body"><div class="stat-value"><?=$total?></div><div class="stat-label">Total Activities</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?=$pending?></div><div class="stat-label">Pending</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$done?></div><div class="stat-label">Completed</div></div></div></div>
</div>
<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Type</label><select name="type" class="form-select form-select-sm"><option value="">All Types</option><?php foreach(array_keys($typeIcons) as $t):?><option value="<?=$t?>" <?=$fType===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach;?></select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label><select name="done" class="form-select form-select-sm"><option value="">All</option><option value="0" <?=$fDone==='0'?'selected':''?>>Pending</option><option value="1" <?=$fDone==='1'?'selected':''?>>Done</option></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="activities.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>
<!-- Table -->
<div class="card"><div class="card-header d-flex align-items-center justify-content-between"><h6 class="mb-0"><i class="fas fa-tasks me-2" style="color:<?=$moduleColor?>"></i>Activity Log</h6><span class="badge bg-secondary"><?=count($activities)?> activities</span></div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Type</th><th>Subject</th><th>Contact</th><th>Deal</th><th>Due Date</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($activities)):?><tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No activities logged yet.</td></tr>
<?php else:foreach($activities as $a):?>
<tr class="<?=$a['done']?'table-light text-muted':''?>">
  <td><span class="badge bg-secondary"><i class="<?=$typeIcons[$a['type']]??'fas fa-circle'?> me-1"></i><?=ucfirst($a['type'])?></span></td>
  <td class="<?=$a['done']?'text-decoration-line-through text-muted':''?> fw-semibold"><?=e($a['subject'])?></td>
  <td><?=e($a['contact_name']??'—')?></td>
  <td><?=e($a['deal_title']??'—')?></td>
  <td><?=$a['due_date']?formatDateTime($a['due_date']):'—'?></td>
  <td><?=$a['done']?'<span class="badge bg-success">Done</span>':'<span class="badge bg-warning text-dark">Pending</span>'?></td>
  <td class="text-center" style="white-space:nowrap">
    <form method="POST" class="d-inline"><input type="hidden" name="action" value="toggle"><?=csrfField()?><input type="hidden" name="id" value="<?=$a['id']?>"><button type="submit" class="btn btn-sm <?=$a['done']?'btn-outline-warning':'btn-outline-success'?>" title="<?=$a['done']?'Mark Pending':'Mark Done'?>"><i class="fas <?=$a['done']?'fa-undo':'fa-check'?>"></i></button></form>
    <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEdit(<?=htmlspecialchars(json_encode($a),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
    <button class="btn btn-sm btn-outline-danger ms-1" onclick="delAct(<?=$a['id']?>,'<?=e($a['subject'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<!-- Modal -->
<div class="modal fade" id="aModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="aId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="aModalTitle"><i class="fas fa-tasks me-2"></i>Log Activity</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-4"><label class="form-label fw-semibold">Type</label><select name="type" id="aType" class="form-select"><?php foreach(array_keys($typeIcons) as $t):?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach;?></select></div>
    <div class="col-md-8"><label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label><input type="text" name="subject" id="aSubject" class="form-control" required maxlength="255"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Contact</label><select name="contact_id" id="aContact" class="form-select"><option value="">-- None --</option><?php foreach($contacts as $c):?><option value="<?=$c['id']?>"><?=e($c['first_name'].' '.$c['last_name'])?></option><?php endforeach;?></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Deal</label><select name="deal_id" id="aDeal" class="form-select"><option value="">-- None --</option><?php foreach($deals as $d):?><option value="<?=$d['id']?>"><?=e($d['title'])?></option><?php endforeach;?></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Due Date &amp; Time</label><input type="datetime-local" name="due_date" id="aDue" class="form-control"></div>
    <div class="col-md-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="done" id="aDone" value="1"><label class="form-check-label fw-semibold" for="aDone">Mark as Done</label></div></div>
    <div class="col-12"><label class="form-label fw-semibold">Description / Notes</label><textarea name="description" id="aDesc" class="form-control" rows="3"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Activity</button></div>
  </form>
</div></div></div>
<form method="POST" id="delAForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delAId"></form>
<?php
$extraJs=<<<'JS'
<script>
function openAdd(){document.getElementById('aModalTitle').innerHTML='<i class="fas fa-tasks me-2"></i>Log Activity';['aId','aSubject','aDesc','aDue'].forEach(i=>document.getElementById(i).value=i==='aId'?'0':'');document.getElementById('aType').value='note';document.getElementById('aContact').value='';document.getElementById('aDeal').value='';document.getElementById('aDone').checked=false;}
function openEdit(a){document.getElementById('aModalTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Activity';document.getElementById('aId').value=a.id;document.getElementById('aType').value=a.type||'note';document.getElementById('aSubject').value=a.subject||'';document.getElementById('aContact').value=a.contact_id||'';document.getElementById('aDeal').value=a.deal_id||'';document.getElementById('aDue').value=a.due_date?a.due_date.replace(' ','T').substring(0,16):'';document.getElementById('aDone').checked=a.done==1;document.getElementById('aDesc').value=a.description||'';new bootstrap.Modal(document.getElementById('aModal')).show();}
function delAct(id,name){Swal.fire({title:'Delete Activity?',text:'"'+name+'" will be removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delAId').value=id;document.getElementById('delAForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
