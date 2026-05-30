<?php
$moduleSlug='crm';$moduleName='CRM — Customer Relations';$moduleIcon='fas fa-handshake';$moduleColor='#0B2D4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'contacts.php','icon'=>'fas fa-address-book','label'=>'Contacts'],['url'=>'companies.php','icon'=>'fas fa-building','label'=>'Companies'],['url'=>'leads.php','icon'=>'fas fa-filter','label'=>'Leads'],['url'=>'deals.php','icon'=>'fas fa-handshake','label'=>'Deals'],['url'=>'pipeline.php','icon'=>'fas fa-columns','label'=>'Pipeline'],['url'=>'quotes.php','icon'=>'fas fa-file-invoice','label'=>'Quotes'],['url'=>'products.php','icon'=>'fas fa-box-open','label'=>'Products'],['url'=>'activities.php','icon'=>'fas fa-tasks','label'=>'Activities'],['url'=>'tasks.php','icon'=>'fas fa-check-square','label'=>'Tasks'],['url'=>'campaigns.php','icon'=>'fas fa-bullhorn','label'=>'Campaigns'],['url'=>'contracts.php','icon'=>'fas fa-file-signature','label'=>'Contracts'],['url'=>'tickets.php','icon'=>'fas fa-headset','label'=>'Support Tickets'],['url'=>'email-log.php','icon'=>'fas fa-envelope-open-text','label'=>'Email Log'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);$fn=sanitize($_POST['first_name']??'');$ln=sanitize($_POST['last_name']??'');
        $em=sanitize($_POST['email']??'');$ph=sanitize($_POST['phone']??'');$co=sanitize($_POST['company']??'');
        $so=sanitize($_POST['source']??'');$no=sanitize($_POST['notes']??'');
        $st=in_array($_POST['status']??'',['new','contacted','qualified','converted','lost'])?$_POST['status']:'new';
        if($id>0){$pdo->prepare("UPDATE crm_leads SET first_name=?,last_name=?,email=?,phone=?,company=?,source=?,status=?,notes=? WHERE id=? AND org_id=?")->execute([$fn,$ln,$em,$ph,$co,$so,$st,$no,$id,$orgId]);setFlash('success','Lead updated.');}
        else{$pdo->prepare("INSERT INTO crm_leads(org_id,first_name,last_name,email,phone,company,source,status,notes)VALUES(?,?,?,?,?,?,?,?,?)")->execute([$orgId,$fn,$ln,$em,$ph,$co,$so,$st,$no]);setFlash('success',"Lead $fn $ln added.");}
        logActivity($id>0?'update':'create','crm',"Lead: $fn $ln");redirect('leads.php');
    }
    if($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM crm_leads WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Lead deleted.');redirect('leads.php');}
    if($action==='convert'){
        // Convert lead to contact
        $id=(int)($_POST['id']??0);$stmt=$pdo->prepare("SELECT * FROM crm_leads WHERE id=? AND org_id=?");$stmt->execute([$id,$orgId]);$lead=$stmt->fetch();
        if($lead){$pdo->prepare("INSERT INTO crm_contacts(org_id,first_name,last_name,email,phone,company,source,type,status)VALUES(?,?,?,?,?,?,?,'customer','active')")->execute([$orgId,$lead['first_name'],$lead['last_name'],$lead['email'],$lead['phone'],$lead['company'],$lead['source']]);$pdo->prepare("UPDATE crm_leads SET status='converted' WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Lead converted to contact.');}
        redirect('leads.php');
    }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fStatus=$_GET['status']??'';$fQ=trim($_GET['q']??'');
$where='org_id=?';$params=[$orgId];
if($fStatus){$where.=' AND status=?';$params[]=$fStatus;}
if($fQ){$where.=' AND(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ?)';$like="%$fQ%";array_push($params,$like,$like,$like,$like);}
$leads=[];try{$stmt=$pdo->prepare("SELECT * FROM crm_leads WHERE $where ORDER BY created_at DESC");$stmt->execute($params);$leads=$stmt->fetchAll();}catch(Exception $e){}
$sNew=countRows('crm_leads','org_id=? AND status=?',[$orgId,'new']);
$sCon=countRows('crm_leads','org_id=? AND status=?',[$orgId,'contacted']);
$sQual=countRows('crm_leads','org_id=? AND status=?',[$orgId,'qualified']);
$sConv=countRows('crm_leads','org_id=? AND status=?',[$orgId,'converted']);
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-filter me-2" style="color:<?=$moduleColor?>"></i>Leads</h4><p class="text-muted mb-0">Track and manage your incoming leads pipeline</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#lModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Lead</button>
</div>
<!-- Pipeline summary cards -->
<div class="row g-3 mb-4">
  <?php foreach([['warning-bg','fas fa-star',$sNew,'New'],['info-bg','fas fa-phone',$sCon,'Contacted'],['navy-bg','fas fa-check-circle',$sQual,'Qualified'],['green-bg','fas fa-exchange-alt',$sConv,'Converted']] as [$cls,$ic,$val,$lbl]):?>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon <?=$cls?>"><i class="<?=$ic?>"></i></div><div class="stat-body"><div class="stat-value"><?=$val?></div><div class="stat-label"><?=$lbl?></div></div></div></div>
  <?php endforeach;?>
</div>
<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-5"><label class="form-label small fw-semibold mb-1">Search</label><input type="text" name="q" class="form-control form-control-sm" placeholder="Name, email, company…" value="<?=e($fQ)?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm"><option value="">All Statuses</option><?php foreach(['new','contacted','qualified','converted','lost'] as $s):?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="leads.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>
<!-- Table -->
<div class="card"><div class="card-header d-flex align-items-center justify-content-between"><h6 class="mb-0"><i class="fas fa-filter me-2" style="color:<?=$moduleColor?>"></i>Lead Pipeline</h6><span class="badge bg-secondary"><?=count($leads)?> leads</span></div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Name</th><th>Company</th><th>Email</th><th>Phone</th><th>Source</th><th>Status</th><th>Added</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($leads)):?><tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No leads found.</td></tr>
<?php else:foreach($leads as $l):
$statusColors=['new'=>'warning','contacted'=>'info','qualified'=>'primary','converted'=>'success','lost'=>'danger'];$sc=$statusColors[$l['status']]??'secondary';?>
<tr>
  <td class="fw-semibold"><?=e($l['first_name'].' '.$l['last_name'])?></td>
  <td><?=e($l['company']??'—')?></td><td><?=e($l['email']??'—')?></td><td><?=e($l['phone']??'—')?></td>
  <td class="small text-muted"><?=e($l['source']??'—')?></td>
  <td><span class="badge bg-<?=$sc?> <?=$sc==='warning'||$sc==='info'?'text-dark':''?>"><?=ucfirst($l['status'])?></span></td>
  <td><?=formatDate($l['created_at'])?></td>
  <td class="text-center" style="white-space:nowrap">
    <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($l),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
    <?php if($l['status']!=='converted'&&$l['status']!=='lost'):?>
    <form method="POST" class="d-inline" onsubmit="return confirm('Convert this lead to a contact?')"><input type="hidden" name="action" value="convert"><?=csrfField()?><input type="hidden" name="id" value="<?=$l['id']?>"><button type="submit" class="btn btn-sm btn-outline-success ms-1" title="Convert"><i class="fas fa-exchange-alt"></i></button></form>
    <?php endif;?>
    <button class="btn btn-sm btn-outline-danger ms-1" onclick="delLead(<?=$l['id']?>,'<?=e($l['first_name'].' '.$l['last_name'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<!-- Modal -->
<div class="modal fade" id="lModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="lId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="lTitle"><i class="fas fa-filter me-2"></i>Add Lead</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label><input type="text" name="first_name" id="lFirst" class="form-control" required maxlength="100"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Last Name</label><input type="text" name="last_name" id="lLast" class="form-control" maxlength="100"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" id="lEmail" class="form-control"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" id="lPhone" class="form-control" maxlength="25"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Company</label><input type="text" name="company" id="lCompany" class="form-control" maxlength="255"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Source</label><input type="text" name="source" id="lSource" class="form-control" placeholder="Website, Cold Call, Referral…" maxlength="100"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="lStatus" class="form-select"><option value="new">New</option><option value="contacted">Contacted</option><option value="qualified">Qualified</option><option value="lost">Lost</option></select></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="lNotes" class="form-control" rows="3"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Lead</button></div>
  </form>
</div></div></div>
<form method="POST" id="delLForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delLId"></form>
<?php
$extraJs=<<<'JS'
<script>
function openAdd(){document.getElementById('lTitle').innerHTML='<i class="fas fa-filter me-2"></i>Add Lead';['lId','lFirst','lLast','lEmail','lPhone','lCompany','lSource','lNotes'].forEach(i=>document.getElementById(i).value=i==='lId'?'0':'');document.getElementById('lStatus').value='new';}
function openEdit(l){document.getElementById('lTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Lead';document.getElementById('lId').value=l.id;document.getElementById('lFirst').value=l.first_name||'';document.getElementById('lLast').value=l.last_name||'';document.getElementById('lEmail').value=l.email||'';document.getElementById('lPhone').value=l.phone||'';document.getElementById('lCompany').value=l.company||'';document.getElementById('lSource').value=l.source||'';document.getElementById('lStatus').value=l.status||'new';document.getElementById('lNotes').value=l.notes||'';new bootstrap.Modal(document.getElementById('lModal')).show();}
function delLead(id,name){Swal.fire({title:'Delete Lead?',text:name+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delLId').value=id;document.getElementById('delLForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
