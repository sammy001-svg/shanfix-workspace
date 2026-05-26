<?php
$moduleSlug='driving';$moduleName='Driving School';$moduleIcon='fas fa-car';$moduleColor='#1a237e';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'instructors.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Instructors'],['url'=>'vehicles.php','icon'=>'fas fa-car','label'=>'Vehicles'],['url'=>'classes.php','icon'=>'fas fa-calendar-alt','label'=>'Classes'],['url'=>'lessons.php','icon'=>'fas fa-road','label'=>'Lessons'],['url'=>'tests.php','icon'=>'fas fa-clipboard-check','label'=>'Tests'],['url'=>'licenses.php','icon'=>'fas fa-id-card','label'=>'Licenses'],['url'=>'schedule.php','icon'=>'fas fa-calendar-week','label'=>'Schedule'],['url'=>'payments.php','icon'=>'fas fa-money-bill','label'=>'Payments'],['url'=>'certificates.php','icon'=>'fas fa-certificate','label'=>'Certificates'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_once __DIR__.'/../../config/database.php'; require_once __DIR__.'/../../includes/functions.php';
    if (session_status()===PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user=currentUser(); $orgId=(int)$user['org_id']; $action=$_POST['action']??'';
    if ($action==='save') {
        $id=(int)($_POST['id']??0);
        $name=sanitize($_POST['name']??'');
        $tt=in_array($_POST['training_type']??'',['theory','practical','both'])?$_POST['training_type']:'practical';
        $vt=in_array($_POST['vehicle_type']??'',['car','motorcycle','truck','bus','other'])?$_POST['vehicle_type']:'car';
        $instId=(int)($_POST['instructor_id']??0)?:null;
        $day=sanitize($_POST['schedule_day']??'');
        $time=$_POST['schedule_time']??null;
        $dur=(float)($_POST['duration_hours']??1);
        $cap=(int)($_POST['max_capacity']??10);
        $fee=(float)($_POST['fee']??0);
        $st=($_POST['status']??'')==='inactive'?'inactive':'active';
        $notes=sanitize($_POST['notes']??'');
        if ($id>0) {$pdo->prepare("UPDATE driving_classes SET name=?,training_type=?,vehicle_type=?,instructor_id=?,schedule_day=?,schedule_time=?,duration_hours=?,max_capacity=?,fee=?,status=?,notes=? WHERE id=? AND org_id=?")->execute([$name,$tt,$vt,$instId,$day,$time?:null,$dur,$cap,$fee,$st,$notes,$id,$orgId]);setFlash('success','Class updated.');}
        else {$pdo->prepare("INSERT INTO driving_classes(org_id,name,training_type,vehicle_type,instructor_id,schedule_day,schedule_time,duration_hours,max_capacity,fee,status,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$orgId,$name,$tt,$vt,$instId,$day,$time?:null,$dur,$cap,$fee,$st,$notes]);setFlash('success',"Class '$name' created.");}
        logActivity($id>0?'update':'create','driving',"Class: $name"); redirect('classes.php');
    }
    if ($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM driving_classes WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Class deleted.');redirect('classes.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fStatus=$_GET['status']??'';$fTt=$_GET['training_type']??'';
$where='c.org_id=?';$params=[$orgId];
if($fStatus){$where.=' AND c.status=?';$params[]=$fStatus;}
if($fTt){$where.=' AND c.training_type=?';$params[]=$fTt;}
$classes=[];
try{$s=$pdo->prepare("SELECT c.*,i.name AS instructor_name FROM driving_classes c LEFT JOIN driving_instructors i ON c.instructor_id=i.id WHERE $where ORDER BY c.name");$s->execute($params);$classes=$s->fetchAll();}catch(Exception $e){}
$instructors=[];
try{$s=$pdo->prepare("SELECT id,name FROM driving_instructors WHERE org_id=? AND status='active' ORDER BY name");$s->execute([$orgId]);$instructors=$s->fetchAll();}catch(Exception $e){}
$total=countRows('driving_classes','org_id=?',[$orgId]);
$active=countRows('driving_classes','org_id=? AND status=?',[$orgId,'active']);
$days=['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday','Mon-Fri','Mon-Sat','Weekends'];
$ttColors=['theory'=>'info','practical'=>'success','both'=>'primary'];
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-calendar-alt me-2" style="color:<?=$moduleColor?>"></i>Classes</h4><p class="text-muted mb-0">Create and manage class schedules with instructors and capacity</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#cModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Create Class</button>
</div>
<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-calendar-alt"></i></div><div class="stat-body"><div class="stat-value"><?=$total?></div><div class="stat-label">Total Classes</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$active?></div><div class="stat-label">Active Classes</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value"><?=countRows('driving_students','org_id=?',[$orgId])?></div><div class="stat-label">Total Students</div></div></div></div>
</div>
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><option value="active" <?=$fStatus==='active'?'selected':''?>>Active</option><option value="inactive" <?=$fStatus==='inactive'?'selected':''?>>Inactive</option></select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Training Type</label><select name="training_type" class="form-select form-select-sm"><option value="">All</option><?php foreach(['theory','practical','both'] as $t):?><option value="<?=$t?>" <?=$fTt===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach;?></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="classes.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="row g-3">
<?php if(empty($classes)):?>
<div class="col-12 text-center text-muted py-5"><i class="fas fa-calendar-alt fa-3x mb-3 d-block"></i>No classes created yet.</div>
<?php else: foreach($classes as $cl):
  $pct = $cl['max_capacity'] > 0 ? min(100, round($cl['current_enrolled']/$cl['max_capacity']*100)) : 0;
  $barColor = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success');
?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100 <?=$cl['status']==='inactive'?'opacity-75':''?>">
    <div class="card-header d-flex align-items-center justify-content-between" style="background:<?=$moduleColor?>;color:#fff">
      <div>
        <div class="fw-semibold"><?=e($cl['name'])?></div>
        <div class="small opacity-75"><?=$cl['schedule_day']?e($cl['schedule_day']):'No schedule set'?> <?=$cl['schedule_time']?htmlspecialchars(substr($cl['schedule_time'],0,5)):'—'?></div>
      </div>
      <span class="badge bg-white" style="color:<?=$moduleColor?>"><?=ucfirst($cl['training_type'])?></span>
    </div>
    <div class="card-body">
      <div class="row g-2 mb-3">
        <div class="col-6"><div class="text-muted small">Vehicle Type</div><div class="fw-semibold"><?=ucfirst($cl['vehicle_type'])?></div></div>
        <div class="col-6"><div class="text-muted small">Duration</div><div class="fw-semibold"><?=$cl['duration_hours']?> hr<?=$cl['duration_hours']>1?'s':''?></div></div>
        <div class="col-6"><div class="text-muted small">Instructor</div><div class="fw-semibold small"><?=e($cl['instructor_name']??'—')?></div></div>
        <div class="col-6"><div class="text-muted small">Fee</div><div class="fw-semibold text-success"><?=formatCurrency((float)$cl['fee'])?></div></div>
      </div>
      <div class="mb-1 d-flex justify-content-between small"><span>Capacity</span><span><?=$cl['current_enrolled']?>/<?=$cl['max_capacity']?></span></div>
      <div class="progress mb-2" style="height:8px"><div class="progress-bar bg-<?=$barColor?>" style="width:<?=$pct?>%"></div></div>
      <?=statusBadge($cl['status']??'active')?>
    </div>
    <div class="card-footer bg-transparent d-flex gap-2">
      <button class="btn btn-sm btn-outline-primary flex-grow-1" onclick='openEdit(<?=htmlspecialchars(json_encode($cl),ENT_QUOTES)?>)'><i class="fas fa-edit me-1"></i>Edit</button>
      <button class="btn btn-sm btn-outline-danger" onclick="delClass(<?=$cl['id']?>,'<?=e($cl['name'])?>')"><i class="fas fa-trash"></i></button>
    </div>
  </div>
</div>
<?php endforeach; endif;?>
</div>

<!-- Modal -->
<div class="modal fade" id="cModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="cId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="cTitle"><i class="fas fa-calendar-alt me-2"></i>Create Class</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12"><label class="form-label fw-semibold">Class Name <span class="text-danger">*</span></label><input type="text" name="name" id="cName" class="form-control" required maxlength="150"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Training Type</label><select name="training_type" id="cTt" class="form-select"><option value="theory">Theory</option><option value="practical" selected>Practical</option><option value="both">Both</option></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Vehicle Type</label><select name="vehicle_type" id="cVt" class="form-select"><option value="car">Car</option><option value="motorcycle">Motorcycle</option><option value="truck">Truck</option><option value="bus">Bus</option><option value="other">Other</option></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="cStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Instructor</label><select name="instructor_id" id="cInstructor" class="form-select"><option value="">— None —</option><?php foreach($instructors as $i):?><option value="<?=$i['id']?>"><?=e($i['name'])?></option><?php endforeach;?></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Schedule Day</label><select name="schedule_day" id="cDay" class="form-select"><option value="">— None —</option><?php foreach($days as $d):?><option value="<?=e($d)?>"><?=e($d)?></option><?php endforeach;?></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Start Time</label><input type="time" name="schedule_time" id="cTime" class="form-control"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Duration (hours)</label><input type="number" name="duration_hours" id="cDur" class="form-control" step="0.5" min="0.5" max="8" value="1"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Max Capacity</label><input type="number" name="max_capacity" id="cCap" class="form-control" min="1" value="10"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Class Fee (<?=CURRENCY_SYMBOL?>)</label><input type="number" name="fee" id="cFee" class="form-control" step="0.01" min="0" value="0"></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="cNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Class</button></div>
  </form>
</div></div></div>
<form method="POST" id="delCForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delCId"></form>
<?php $extraJs=<<<'JS'
<script>
function openAdd(){document.getElementById('cTitle').innerHTML='<i class="fas fa-calendar-alt me-2"></i>Create Class';['cId','cName','cNotes'].forEach(i=>document.getElementById(i).value=i==='cId'?'0':'');document.getElementById('cTt').value='practical';document.getElementById('cVt').value='car';document.getElementById('cStatus').value='active';document.getElementById('cInstructor').value='';document.getElementById('cDay').value='';document.getElementById('cTime').value='';document.getElementById('cDur').value=1;document.getElementById('cCap').value=10;document.getElementById('cFee').value=0;}
function openEdit(c){document.getElementById('cTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Class';document.getElementById('cId').value=c.id;document.getElementById('cName').value=c.name||'';document.getElementById('cTt').value=c.training_type||'practical';document.getElementById('cVt').value=c.vehicle_type||'car';document.getElementById('cStatus').value=c.status||'active';document.getElementById('cInstructor').value=c.instructor_id||'';document.getElementById('cDay').value=c.schedule_day||'';document.getElementById('cTime').value=c.schedule_time?c.schedule_time.substring(0,5):'';document.getElementById('cDur').value=c.duration_hours||1;document.getElementById('cCap').value=c.max_capacity||10;document.getElementById('cFee').value=c.fee||0;document.getElementById('cNotes').value=c.notes||'';new bootstrap.Modal(document.getElementById('cModal')).show();}
function delClass(id,name){Swal.fire({title:'Delete Class?',text:'"'+name+'" will be removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delCId').value=id;document.getElementById('delCForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
