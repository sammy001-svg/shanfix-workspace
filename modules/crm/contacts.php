<?php
$moduleSlug='crm';$moduleName='CRM — Customer Relations';$moduleIcon='fas fa-handshake';$moduleColor='#0B2D4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'contacts.php','icon'=>'fas fa-address-book','label'=>'Contacts'],['url'=>'companies.php','icon'=>'fas fa-building','label'=>'Companies'],['url'=>'leads.php','icon'=>'fas fa-filter','label'=>'Leads'],['url'=>'deals.php','icon'=>'fas fa-handshake','label'=>'Deals'],['url'=>'pipeline.php','icon'=>'fas fa-columns','label'=>'Pipeline'],['url'=>'quotes.php','icon'=>'fas fa-file-invoice','label'=>'Quotes'],['url'=>'products.php','icon'=>'fas fa-box-open','label'=>'Products'],['url'=>'activities.php','icon'=>'fas fa-tasks','label'=>'Activities'],['url'=>'tasks.php','icon'=>'fas fa-check-square','label'=>'Tasks'],['url'=>'campaigns.php','icon'=>'fas fa-bullhorn','label'=>'Campaigns'],['url'=>'contracts.php','icon'=>'fas fa-file-signature','label'=>'Contracts'],['url'=>'tickets.php','icon'=>'fas fa-headset','label'=>'Support Tickets'],['url'=>'email-log.php','icon'=>'fas fa-envelope-open-text','label'=>'Email Log'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);$fn=sanitize($_POST['first_name']??'');$ln=sanitize($_POST['last_name']??'');
        $em=sanitize($_POST['email']??'');$ph=sanitize($_POST['phone']??'');$co=sanitize($_POST['company']??'');
        $po=sanitize($_POST['position']??'');$ty=in_array($_POST['type']??'',['lead','contact','customer','partner'])?$_POST['type']:'contact';
        $so=sanitize($_POST['source']??'');$ta=sanitize($_POST['tags']??'');$no=sanitize($_POST['notes']??'');
        $st=($_POST['status']??'')==='inactive'?'inactive':'active';
        if($id>0){$pdo->prepare("UPDATE crm_contacts SET first_name=?,last_name=?,email=?,phone=?,company=?,position=?,type=?,source=?,tags=?,notes=?,status=? WHERE id=? AND org_id=?")->execute([$fn,$ln,$em,$ph,$co,$po,$ty,$so,$ta,$no,$st,$id,$orgId]);setFlash('success','Contact updated.');logActivity('update','crm',"Updated contact: $fn $ln");}
        else{$pdo->prepare("INSERT INTO crm_contacts(org_id,first_name,last_name,email,phone,company,position,type,source,tags,notes,status)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$orgId,$fn,$ln,$em,$ph,$co,$po,$ty,$so,$ta,$no,$st]);setFlash('success',"Contact $fn $ln added.");logActivity('create','crm',"Added contact: $fn $ln");}
        redirect('contacts.php');
    }
    if($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM crm_contacts WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Contact deleted.');redirect('contacts.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fType=$_GET['type']??'';$fStatus=$_GET['status']??'';$fQ=trim($_GET['q']??'');
$where='org_id=?';$params=[$orgId];
if($fType){$where.=' AND type=?';$params[]=$fType;}
if($fStatus){$where.=' AND status=?';$params[]=$fStatus;}
if($fQ){$where.=' AND(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ?)';$like="%$fQ%";array_push($params,$like,$like,$like,$like);}
$contacts=[];try{$stmt=$pdo->prepare("SELECT * FROM crm_contacts WHERE $where ORDER BY created_at DESC");$stmt->execute($params);$contacts=$stmt->fetchAll();}catch(Exception $e){}
$total=countRows('crm_contacts','org_id=?',[$orgId]);$active=countRows('crm_contacts','org_id=? AND status=?',[$orgId,'active']);$cust=countRows('crm_contacts','org_id=? AND type=?',[$orgId,'customer']);$part=countRows('crm_contacts','org_id=? AND type=?',[$orgId,'partner']);
$viewC=null;if(isset($_GET['view'])){$stmt=$pdo->prepare("SELECT * FROM crm_contacts WHERE id=? AND org_id=?");$stmt->execute([(int)$_GET['view'],$orgId]);$viewC=$stmt->fetch();}
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-address-book me-2" style="color:<?=$moduleColor?>"></i>Contacts</h4><p class="text-muted mb-0">Manage customers, prospects and partners</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#cModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Contact</button>
</div>
<div class="row g-3 mb-4">
  <?php foreach([['navy-bg','fas fa-users',$total,'Total Contacts'],['green-bg','fas fa-user-check',$active,'Active'],['warning-bg','fas fa-shopping-bag',$cust,'Customers'],['navy-bg','fas fa-handshake',$part,'Partners']] as [$cls,$ic,$val,$lbl]):?>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon <?=$cls?>"><i class="<?=$ic?>"></i></div><div class="stat-body"><div class="stat-value"><?=$val?></div><div class="stat-label"><?=$lbl?></div></div></div></div>
  <?php endforeach;?>
</div>
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Search</label><input type="text" name="q" class="form-control form-control-sm" placeholder="Name, email, company…" value="<?=e($fQ)?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Type</label><select name="type" class="form-select form-select-sm"><option value="">All Types</option><?php foreach(['lead','contact','customer','partner'] as $t):?><option value="<?=$t?>" <?=$fType===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach;?></select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><option value="active" <?=$fStatus==='active'?'selected':''?>>Active</option><option value="inactive" <?=$fStatus==='inactive'?'selected':''?>>Inactive</option></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="contacts.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>
<div class="card"><div class="card-header d-flex align-items-center justify-content-between"><h6 class="mb-0"><i class="fas fa-address-book me-2" style="color:<?=$moduleColor?>"></i>Contact List</h6><span class="badge bg-secondary"><?=count($contacts)?> contacts</span></div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Name</th><th>Company</th><th>Email</th><th>Phone</th><th>Type</th><th>Status</th><th>Added</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($contacts)):?><tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No contacts found.</td></tr>
<?php else:foreach($contacts as $c):?>
<tr>
  <td><div class="d-flex align-items-center gap-2"><div style="width:34px;height:34px;border-radius:50%;background:<?=$moduleColor?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:600;flex-shrink:0"><?=strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1))?></div><div><div class="fw-semibold"><?=e($c['first_name'].' '.$c['last_name'])?></div><div class="small text-muted"><?=e($c['position']??'')?></div></div></div></td>
  <td><?=e($c['company']??'—')?></td><td><?=e($c['email']??'—')?></td><td><?=e($c['phone']??'—')?></td>
  <td><span class="badge bg-info text-dark"><?=ucfirst($c['type']??'contact')?></span></td>
  <td><?=statusBadge($c['status']??'active')?></td><td><?=formatDate($c['created_at'])?></td>
  <td class="text-center" style="white-space:nowrap">
    <a href="?view=<?=$c['id']?>" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
    <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEdit(<?=htmlspecialchars(json_encode($c),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
    <button class="btn btn-sm btn-outline-danger ms-1" onclick="delContact(<?=$c['id']?>,'<?=e($c['first_name'].' '.$c['last_name'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<?php if($viewC):?>
<div class="card mt-4">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:<?=$moduleColor?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-id-card me-2"></i>Profile — <?=e($viewC['first_name'].' '.$viewC['last_name'])?></h6>
    <a href="contacts.php" class="btn btn-sm btn-light"><i class="fas fa-times me-1"></i>Close</a>
  </div>
  <div class="card-body"><div class="row g-3">
    <div class="col-md-6"><table class="table table-sm">
      <tr><th class="text-muted w-40">Name</th><td class="fw-semibold"><?=e($viewC['first_name'].' '.$viewC['last_name'])?></td></tr>
      <tr><th class="text-muted">Email</th><td><?=e($viewC['email']??'—')?></td></tr>
      <tr><th class="text-muted">Phone</th><td><?=e($viewC['phone']??'—')?></td></tr>
      <tr><th class="text-muted">Company</th><td><?=e($viewC['company']??'—')?></td></tr>
      <tr><th class="text-muted">Position</th><td><?=e($viewC['position']??'—')?></td></tr>
    </table></div>
    <div class="col-md-6"><table class="table table-sm">
      <tr><th class="text-muted w-40">Type</th><td><span class="badge bg-info text-dark"><?=ucfirst($viewC['type']??'contact')?></span></td></tr>
      <tr><th class="text-muted">Source</th><td><?=e($viewC['source']??'—')?></td></tr>
      <tr><th class="text-muted">Tags</th><td><?=e($viewC['tags']??'—')?></td></tr>
      <tr><th class="text-muted">Status</th><td><?=statusBadge($viewC['status']??'active')?></td></tr>
      <tr><th class="text-muted">Added</th><td><?=formatDate($viewC['created_at'])?></td></tr>
    </table></div>
    <?php if(!empty($viewC['notes'])):?><div class="col-12"><p class="text-muted mb-0"><strong>Notes:</strong> <?=nl2br(e($viewC['notes']))?></p></div><?php endif;?>
  </div></div>
</div>
<?php endif;?>

<!-- Modal -->
<div class="modal fade" id="cModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="cId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="cTitle"><i class="fas fa-address-book me-2"></i>Add Contact</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label><input type="text" name="first_name" id="cFirst" class="form-control" required maxlength="100"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Last Name</label><input type="text" name="last_name" id="cLast" class="form-control" maxlength="100"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" id="cEmail" class="form-control"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" id="cPhone" class="form-control" maxlength="25"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Company</label><input type="text" name="company" id="cCompany" class="form-control" maxlength="255"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Position</label><input type="text" name="position" id="cPosition" class="form-control" maxlength="100"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Type</label><select name="type" id="cType" class="form-select"><option value="contact">Contact</option><option value="lead">Lead</option><option value="customer">Customer</option><option value="partner">Partner</option></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Source</label><input type="text" name="source" id="cSource" class="form-control" placeholder="Website, Referral…" maxlength="100"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="cStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    <div class="col-12"><label class="form-label fw-semibold">Tags <small class="text-muted">(comma separated)</small></label><input type="text" name="tags" id="cTags" class="form-control" maxlength="500"></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="cNotes" class="form-control" rows="3"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Contact</button></div>
  </form>
</div></div></div>
<form method="POST" id="delCForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delCId"></form>
<?php
$extraJs=<<<'JS'
<script>
function openAdd(){document.getElementById('cTitle').innerHTML='<i class="fas fa-address-book me-2"></i>Add Contact';['cId','cFirst','cLast','cEmail','cPhone','cCompany','cPosition','cSource','cTags','cNotes'].forEach(i=>document.getElementById(i).value=i==='cId'?'0':'');document.getElementById('cType').value='contact';document.getElementById('cStatus').value='active';}
function openEdit(c){document.getElementById('cTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Contact';document.getElementById('cId').value=c.id;document.getElementById('cFirst').value=c.first_name||'';document.getElementById('cLast').value=c.last_name||'';document.getElementById('cEmail').value=c.email||'';document.getElementById('cPhone').value=c.phone||'';document.getElementById('cCompany').value=c.company||'';document.getElementById('cPosition').value=c.position||'';document.getElementById('cType').value=c.type||'contact';document.getElementById('cSource').value=c.source||'';document.getElementById('cStatus').value=c.status||'active';document.getElementById('cTags').value=c.tags||'';document.getElementById('cNotes').value=c.notes||'';new bootstrap.Modal(document.getElementById('cModal')).show();}
function delContact(id,name){Swal.fire({title:'Delete Contact?',text:name+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delCId').value=id;document.getElementById('delCForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
