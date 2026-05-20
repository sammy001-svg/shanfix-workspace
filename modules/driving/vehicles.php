<?php
$moduleSlug='driving';$moduleName='Driving School';$moduleIcon='fas fa-car';$moduleColor='#1a237e';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'instructors.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Instructors'],['url'=>'vehicles.php','icon'=>'fas fa-car','label'=>'Vehicles'],['url'=>'classes.php','icon'=>'fas fa-calendar-alt','label'=>'Classes'],['url'=>'lessons.php','icon'=>'fas fa-road','label'=>'Lessons'],['url'=>'tests.php','icon'=>'fas fa-clipboard-check','label'=>'Tests'],['url'=>'licenses.php','icon'=>'fas fa-id-card','label'=>'Licenses'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_once __DIR__.'/../../config/database.php'; require_once __DIR__.'/../../includes/functions.php';
    if (session_status()===PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user=currentUser(); $orgId=(int)$user['org_id']; $action=$_POST['action']??'';
    if ($action==='save') {
        $id=(int)($_POST['id']??0);$name=sanitize($_POST['name']??'');$plate=sanitize($_POST['number_plate']??'');
        $make=sanitize($_POST['make']??'');$model=sanitize($_POST['model']??'');
        $year=(int)($_POST['year']??0)?:null;
        $type=in_array($_POST['type']??'',['car','motorcycle','truck','bus','other'])?$_POST['type']:'car';
        $trans=in_array($_POST['transmission']??'',['manual','automatic'])?$_POST['transmission']:'manual';
        $instId=(int)($_POST['instructor_id']??0)?:null;
        $st=in_array($_POST['status']??'',['active','inactive','maintenance'])?$_POST['status']:'active';
        $notes=sanitize($_POST['notes']??'');
        if ($id>0) {$pdo->prepare("UPDATE driving_vehicles SET name=?,number_plate=?,make=?,model=?,year=?,type=?,transmission=?,instructor_id=?,status=?,notes=? WHERE id=? AND org_id=?")->execute([$name,$plate,$make,$model,$year,$type,$trans,$instId,$st,$notes,$id,$orgId]);setFlash('success','Vehicle updated.');}
        else {$pdo->prepare("INSERT INTO driving_vehicles(org_id,name,number_plate,make,model,year,type,transmission,instructor_id,status,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?)")->execute([$orgId,$name,$plate,$make,$model,$year,$type,$trans,$instId,$st,$notes]);setFlash('success',"Vehicle $name added.");}
        logActivity($id>0?'update':'create','driving',"Vehicle: $name"); redirect('vehicles.php');
    }
    if ($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM driving_vehicles WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Vehicle removed.');redirect('vehicles.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fStatus=$_GET['status']??'';$fType=$_GET['type']??'';
$where='org_id=?';$params=[$orgId];
if($fStatus){$where.=' AND status=?';$params[]=$fStatus;}
if($fType){$where.=' AND type=?';$params[]=$fType;}
$vehicles=[];
try{$s=$pdo->prepare("SELECT v.*,i.name AS instructor_name FROM driving_vehicles v LEFT JOIN driving_instructors i ON v.instructor_id=i.id WHERE v.$where ORDER BY v.name");$s->execute($params);$vehicles=$s->fetchAll();}catch(Exception $e){}
$instructors=[];
try{$s=$pdo->prepare("SELECT id,name FROM driving_instructors WHERE org_id=? AND status='active' ORDER BY name");$s->execute([$orgId]);$instructors=$s->fetchAll();}catch(Exception $e){}
$total=countRows('driving_vehicles','org_id=?',[$orgId]);
$active=countRows('driving_vehicles','org_id=? AND status=?',[$orgId,'active']);
$maint=countRows('driving_vehicles','org_id=? AND status=?',[$orgId,'maintenance']);
$statusColors=['active'=>'success','inactive'=>'secondary','maintenance'=>'warning'];
$typeIcons=['car'=>'fas fa-car','motorcycle'=>'fas fa-motorcycle','truck'=>'fas fa-truck','bus'=>'fas fa-bus','other'=>'fas fa-car-side'];
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-car me-2" style="color:<?=$moduleColor?>"></i>Vehicles</h4><p class="text-muted mb-0">Manage training vehicles, status and instructor assignments</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#vModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Vehicle</button>
</div>
<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-car"></i></div><div class="stat-body"><div class="stat-value"><?=$total?></div><div class="stat-label">Total Vehicles</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$active?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-tools"></i></div><div class="stat-body"><div class="stat-value"><?=$maint?></div><div class="stat-label">Under Maintenance</div></div></div></div>
</div>
<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach(['active','inactive','maintenance'] as $s):?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Type</label>
      <select name="type" class="form-select form-select-sm"><option value="">All Types</option><?php foreach(array_keys($typeIcons) as $t):?><option value="<?=$t?>" <?=$fType===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach;?></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="vehicles.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card"><div class="card-header d-flex align-items-center justify-content-between">
  <h6 class="mb-0"><i class="fas fa-car me-2" style="color:<?=$moduleColor?>"></i>Vehicle Fleet</h6>
  <span class="badge bg-secondary"><?=count($vehicles)?> vehicles</span>
</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Vehicle</th><th>Plate</th><th>Type</th><th>Transmission</th><th>Assigned Instructor</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($vehicles)):?>
  <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-car fa-2x mb-2 d-block"></i>No vehicles found.</td></tr>
<?php else:foreach($vehicles as $v):?>
  <tr class="<?=$v['status']==='maintenance'?'table-warning bg-opacity-25':''?>">
    <td><div class="d-flex align-items-center gap-2">
      <div style="width:38px;height:38px;border-radius:8px;background:<?=$moduleColor?>1a;color:<?=$moduleColor?>;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="<?=$typeIcons[$v['type']]??'fas fa-car'?>"></i></div>
      <div><div class="fw-semibold"><?=e($v['name'])?></div>
      <div class="small text-muted"><?=e(trim(($v['make']??'').' '.($v['model']??'').' '.($v['year']??'')))?></div></div>
    </div></td>
    <td><span class="badge bg-dark"><?=e($v['number_plate'])?></span></td>
    <td><span class="badge bg-info text-dark"><i class="<?=$typeIcons[$v['type']]??'fas fa-car'?> me-1"></i><?=ucfirst($v['type'])?></span></td>
    <td class="small"><?=ucfirst($v['transmission']??'manual')?></td>
    <td class="small"><?=e($v['instructor_name']??'— Unassigned')?></td>
    <td><span class="badge bg-<?=$statusColors[$v['status']]??'secondary'?> <?=$v['status']==='maintenance'?'text-dark':''?>"><?=$v['status']==='maintenance'?'Under Maintenance':ucfirst($v['status'])?></span></td>
    <td class="text-center" style="white-space:nowrap">
      <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($v),ENT_QUOTES)?>)'><i class="fas fa-edit"></i></button>
      <button class="btn btn-sm btn-outline-danger ms-1" onclick="delVehicle(<?=$v['id']?>,'<?=e($v['name'])?>')"><i class="fas fa-trash"></i></button>
    </td>
  </tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<!-- Modal -->
<div class="modal fade" id="vModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="vId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="vTitle"><i class="fas fa-car me-2"></i>Add Vehicle</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">Vehicle Name <span class="text-danger">*</span></label><input type="text" name="name" id="vName" class="form-control" required placeholder="e.g. Toyota Corolla" maxlength="150"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Number Plate <span class="text-danger">*</span></label><input type="text" name="number_plate" id="vPlate" class="form-control" required placeholder="e.g. KAB 123X" maxlength="50"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Make</label><input type="text" name="make" id="vMake" class="form-control" placeholder="Toyota, Honda…" maxlength="100"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Model</label><input type="text" name="model" id="vModel" class="form-control" placeholder="Corolla, Civic…" maxlength="100"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Year</label><input type="number" name="year" id="vYear" class="form-control" min="1990" max="<?=date('Y')+1?>" placeholder="<?=date('Y')?>"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Vehicle Type</label>
      <select name="type" id="vType" class="form-select"><?php foreach(array_keys($typeIcons) as $t):?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach;?></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Transmission</label>
      <select name="transmission" id="vTrans" class="form-select"><option value="manual">Manual</option><option value="automatic">Automatic</option></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="vStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option><option value="maintenance">Under Maintenance</option></select></div>
    <div class="col-12"><label class="form-label fw-semibold">Assign to Instructor</label>
      <select name="instructor_id" id="vInstructor" class="form-select"><option value="">— None —</option><?php foreach($instructors as $i):?><option value="<?=$i['id']?>"><?=e($i['name'])?></option><?php endforeach;?></select></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="vNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Vehicle</button></div>
  </form>
</div></div></div>
<form method="POST" id="delVForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delVId"></form>
<?php $extraJs=<<<'JS'
<script>
function openAdd(){document.getElementById('vTitle').innerHTML='<i class="fas fa-car me-2"></i>Add Vehicle';['vId','vName','vPlate','vMake','vModel','vYear','vNotes'].forEach(i=>document.getElementById(i).value=i==='vId'?'0':'');document.getElementById('vType').value='car';document.getElementById('vTrans').value='manual';document.getElementById('vStatus').value='active';document.getElementById('vInstructor').value='';}
function openEdit(v){document.getElementById('vTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Vehicle';document.getElementById('vId').value=v.id;document.getElementById('vName').value=v.name||'';document.getElementById('vPlate').value=v.number_plate||'';document.getElementById('vMake').value=v.make||'';document.getElementById('vModel').value=v.model||'';document.getElementById('vYear').value=v.year||'';document.getElementById('vType').value=v.type||'car';document.getElementById('vTrans').value=v.transmission||'manual';document.getElementById('vStatus').value=v.status||'active';document.getElementById('vInstructor').value=v.instructor_id||'';document.getElementById('vNotes').value=v.notes||'';new bootstrap.Modal(document.getElementById('vModal')).show();}
function delVehicle(id,name){Swal.fire({title:'Remove Vehicle?',text:name+' will be deleted.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delVId').value=id;document.getElementById('delVForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
