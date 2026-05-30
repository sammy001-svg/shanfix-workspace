<?php
$moduleSlug='crm';$moduleName='CRM — Customer Relations';$moduleIcon='fas fa-handshake';$moduleColor='#0B2D4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'contacts.php','icon'=>'fas fa-address-book','label'=>'Contacts'],['url'=>'companies.php','icon'=>'fas fa-building','label'=>'Companies'],['url'=>'leads.php','icon'=>'fas fa-filter','label'=>'Leads'],['url'=>'deals.php','icon'=>'fas fa-handshake','label'=>'Deals'],['url'=>'pipeline.php','icon'=>'fas fa-columns','label'=>'Pipeline'],['url'=>'quotes.php','icon'=>'fas fa-file-invoice','label'=>'Quotes'],['url'=>'products.php','icon'=>'fas fa-box-open','label'=>'Products'],['url'=>'activities.php','icon'=>'fas fa-tasks','label'=>'Activities'],['url'=>'tasks.php','icon'=>'fas fa-check-square','label'=>'Tasks'],['url'=>'campaigns.php','icon'=>'fas fa-bullhorn','label'=>'Campaigns'],['url'=>'contracts.php','icon'=>'fas fa-file-signature','label'=>'Contracts'],['url'=>'tickets.php','icon'=>'fas fa-headset','label'=>'Support Tickets'],['url'=>'email-log.php','icon'=>'fas fa-envelope-open-text','label'=>'Email Log'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);$title=sanitize($_POST['title']??'');$contactId=(int)($_POST['contact_id']??0)?:null;
        $value=(float)($_POST['value']??0);$stage=sanitize($_POST['stage']??'prospect');
        $prob=(int)($_POST['probability']??0);$close=$_POST['expected_close']??null;$desc=sanitize($_POST['description']??'');
        $status=in_array($_POST['status']??'',['open','won','lost'])?$_POST['status']:'open';
        if($id>0){$pdo->prepare("UPDATE crm_deals SET title=?,contact_id=?,value=?,stage=?,probability=?,expected_close=?,description=?,status=? WHERE id=? AND org_id=?")->execute([$title,$contactId,$value,$stage,$prob,$close?:null,$desc,$status,$id,$orgId]);setFlash('success','Deal updated.');}
        else{$pdo->prepare("INSERT INTO crm_deals(org_id,title,contact_id,value,stage,probability,expected_close,description,status)VALUES(?,?,?,?,?,?,?,?,?)")->execute([$orgId,$title,$contactId,$value,$stage,$prob,$close?:null,$desc,$status]);setFlash('success',"Deal '$title' added.");}
        logActivity($id>0?'update':'create','crm',"Deal: $title");redirect('deals.php');
    }
    if($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM crm_deals WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Deal deleted.');redirect('deals.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fStage=$_GET['stage']??'';$fStatus=$_GET['status']??'';
$where='org_id=?';$params=[$orgId];
if($fStage){$where.=' AND stage=?';$params[]=$fStage;}
if($fStatus){$where.=' AND status=?';$params[]=$fStatus;}
$deals=[];try{$stmt=$pdo->prepare("SELECT d.*,CONCAT(c.first_name,' ',c.last_name) AS contact_name FROM crm_deals d LEFT JOIN crm_contacts c ON d.contact_id=c.id WHERE $where ORDER BY d.created_at DESC");$stmt->execute($params);$deals=$stmt->fetchAll();}catch(Exception $e){}
$contacts=[];try{$stmt=$pdo->prepare("SELECT id,first_name,last_name,company FROM crm_contacts WHERE org_id=? AND status='active' ORDER BY first_name");$stmt->execute([$orgId]);$contacts=$stmt->fetchAll();}catch(Exception $e){}
$pipeline=0;try{$stmt=$pdo->prepare("SELECT COALESCE(SUM(value),0) FROM crm_deals WHERE org_id=? AND status='open'");$stmt->execute([$orgId]);$pipeline=(float)$stmt->fetchColumn();}catch(Exception $e){}
$won=0;try{$stmt=$pdo->prepare("SELECT COALESCE(SUM(value),0) FROM crm_deals WHERE org_id=? AND status='won'");$stmt->execute([$orgId]);$won=(float)$stmt->fetchColumn();}catch(Exception $e){}
$openCount=countRows('crm_deals','org_id=? AND status=?',[$orgId,'open']);
$wonCount=countRows('crm_deals','org_id=? AND status=?',[$orgId,'won']);
$stages=['prospect','qualified','proposal','negotiation','won','lost'];
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-handshake me-2" style="color:<?=$moduleColor?>"></i>Deals</h4><p class="text-muted mb-0">Manage your sales pipeline and deal stages</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#dModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Deal</button>
</div>
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-briefcase"></i></div><div class="stat-body"><div class="stat-value"><?=$openCount?></div><div class="stat-label">Open Deals</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-dollar-sign"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($pipeline)?></div><div class="stat-label">Pipeline Value</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-trophy"></i></div><div class="stat-body"><div class="stat-value"><?=$wonCount?></div><div class="stat-label">Deals Won</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($won)?></div><div class="stat-label">Won Value</div></div></div></div>
</div>
<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Stage</label><select name="stage" class="form-select form-select-sm"><option value="">All Stages</option><?php foreach($stages as $s):?><option value="<?=$s?>" <?=$fStage===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><option value="open" <?=$fStatus==='open'?'selected':''?>>Open</option><option value="won" <?=$fStatus==='won'?'selected':''?>>Won</option><option value="lost" <?=$fStatus==='lost'?'selected':''?>>Lost</option></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="deals.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>
<!-- Deals Table -->
<div class="card"><div class="card-header d-flex align-items-center justify-content-between"><h6 class="mb-0"><i class="fas fa-handshake me-2" style="color:<?=$moduleColor?>"></i>Deals</h6><span class="badge bg-secondary"><?=count($deals)?> deals</span></div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Title</th><th>Contact</th><th>Value</th><th>Stage</th><th>Probability</th><th>Close Date</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($deals)):?><tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No deals found.</td></tr>
<?php else:foreach($deals as $d):
$stageColors=['prospect'=>'secondary','qualified'=>'info','proposal'=>'warning','negotiation'=>'primary','won'=>'success','lost'=>'danger'];$sc=$stageColors[$d['stage']]??'secondary';?>
<tr>
  <td class="fw-semibold"><?=e($d['title'])?></td>
  <td><?=e($d['contact_name']??'—')?></td>
  <td class="fw-semibold text-success"><?=formatCurrency((float)$d['value'])?></td>
  <td><span class="badge bg-<?=$sc?> <?=in_array($sc,['warning','info'])?'text-dark':''?>"><?=ucfirst($d['stage'])?></span></td>
  <td><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:6px"><div class="progress-bar" style="width:<?=$d['probability']?>%;background:<?=$moduleColor?>"></div></div><small><?=$d['probability']?>%</small></div></td>
  <td><?=formatDate($d['expected_close'])?></td>
  <td><?=statusBadge($d['status']??'open')?></td>
  <td class="text-center" style="white-space:nowrap">
    <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($d),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
    <button class="btn btn-sm btn-outline-danger ms-1" onclick="delDeal(<?=$d['id']?>,'<?=e($d['title'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<!-- Modal -->
<div class="modal fade" id="dModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="dId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="dTitle"><i class="fas fa-handshake me-2"></i>Add Deal</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12"><label class="form-label fw-semibold">Deal Title <span class="text-danger">*</span></label><input type="text" name="title" id="dTitle2" class="form-control" required maxlength="255"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Contact</label><select name="contact_id" id="dContact" class="form-select"><option value="">-- None --</option><?php foreach($contacts as $c):?><option value="<?=$c['id']?>"><?=e($c['first_name'].' '.$c['last_name'].($c['company']?' ('.$c['company'].')':''))?></option><?php endforeach;?></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Deal Value (<?=CURRENCY_SYMBOL?>)</label><input type="number" name="value" id="dValue" class="form-control" step="0.01" min="0" value="0"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Stage</label><select name="stage" id="dStage" class="form-select"><?php foreach($stages as $s):?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Probability (%)</label><input type="number" name="probability" id="dProb" class="form-control" min="0" max="100" value="50"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="dStatus" class="form-select"><option value="open">Open</option><option value="won">Won</option><option value="lost">Lost</option></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Expected Close Date</label><input type="date" name="expected_close" id="dClose" class="form-control"></div>
    <div class="col-12"><label class="form-label fw-semibold">Description</label><textarea name="description" id="dDesc" class="form-control" rows="3"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Deal</button></div>
  </form>
</div></div></div>
<form method="POST" id="delDForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delDId"></form>
<?php
$extraJs=<<<'JS'
<script>
function openAdd(){document.getElementById('dTitle').innerHTML='<i class="fas fa-handshake me-2"></i>Add Deal';['dId','dTitle2','dDesc','dClose'].forEach(i=>document.getElementById(i).value=i==='dId'?'0':'');document.getElementById('dContact').value='';document.getElementById('dValue').value=0;document.getElementById('dStage').value='prospect';document.getElementById('dProb').value=50;document.getElementById('dStatus').value='open';}
function openEdit(d){document.getElementById('dTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Deal';document.getElementById('dId').value=d.id;document.getElementById('dTitle2').value=d.title||'';document.getElementById('dContact').value=d.contact_id||'';document.getElementById('dValue').value=d.value||0;document.getElementById('dStage').value=d.stage||'prospect';document.getElementById('dProb').value=d.probability||50;document.getElementById('dStatus').value=d.status||'open';document.getElementById('dClose').value=d.expected_close||'';document.getElementById('dDesc').value=d.description||'';new bootstrap.Modal(document.getElementById('dModal')).show();}
function delDeal(id,name){Swal.fire({title:'Delete Deal?',text:'"'+name+'" will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delDId').value=id;document.getElementById('delDForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
