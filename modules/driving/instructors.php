<?php
$moduleSlug='driving';$moduleName='Driving School';$moduleIcon='fas fa-car';$moduleColor='#1a237e';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'instructors.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Instructors'],['url'=>'vehicles.php','icon'=>'fas fa-car','label'=>'Vehicles'],['url'=>'classes.php','icon'=>'fas fa-calendar-alt','label'=>'Classes'],['url'=>'lessons.php','icon'=>'fas fa-road','label'=>'Lessons'],['url'=>'tests.php','icon'=>'fas fa-clipboard-check','label'=>'Tests'],['url'=>'licenses.php','icon'=>'fas fa-id-card','label'=>'Licenses'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_once __DIR__.'/../../config/database.php'; require_once __DIR__.'/../../includes/functions.php';
    if (session_status()===PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user=currentUser(); $orgId=(int)$user['org_id']; $action=$_POST['action']??'';
    if ($action==='save') {
        $id=(int)($_POST['id']??0);$name=sanitize($_POST['name']??'');$em=sanitize($_POST['email']??'');
        $ph=sanitize($_POST['phone']??'');$lic=sanitize($_POST['license_number']??'');
        $spec=sanitize($_POST['specialization']??'');$notes=sanitize($_POST['notes']??'');
        $st=($_POST['status']??'')==='inactive'?'inactive':'active';
        if ($id>0) {$pdo->prepare("UPDATE driving_instructors SET name=?,email=?,phone=?,license_number=?,specialization=?,notes=?,status=? WHERE id=? AND org_id=?")->execute([$name,$em,$ph,$lic,$spec,$notes,$st,$id,$orgId]);setFlash('success','Instructor updated.');}
        else {$pdo->prepare("INSERT INTO driving_instructors(org_id,name,email,phone,license_number,specialization,notes,status)VALUES(?,?,?,?,?,?,?,?)")->execute([$orgId,$name,$em,$ph,$lic,$spec,$notes,$st]);setFlash('success',"Instructor $name added.");}
        logActivity($id>0?'update':'create','driving',"Instructor: $name"); redirect('instructors.php');
    }
    if ($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM driving_instructors WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Instructor removed.');redirect('instructors.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$instructors=[];
try{$s=$pdo->prepare("SELECT i.*,(SELECT COUNT(*) FROM driving_students st WHERE st.instructor_id=i.id) AS student_count,(SELECT COUNT(*) FROM driving_lessons l WHERE l.instructor_id=i.id AND l.status='completed') AS lessons_done FROM driving_instructors i WHERE i.org_id=? ORDER BY i.name");$s->execute([$orgId]);$instructors=$s->fetchAll();}catch(Exception $e){}
$total=countRows('driving_instructors','org_id=?',[$orgId]);$active=countRows('driving_instructors','org_id=? AND status=?',[$orgId,'active']);
$specs=['Manual Cars','Automatic Cars','Motorcycles','Trucks','Buses','Theory','Defensive Driving'];
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-chalkboard-teacher me-2" style="color:<?=$moduleColor?>"></i>Instructors</h4><p class="text-muted mb-0">Manage driving instructors and their assignments</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#iModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Instructor</button>
</div>
<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-chalkboard-teacher"></i></div><div class="stat-body"><div class="stat-value"><?=$total?></div><div class="stat-label">Total Instructors</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$active?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-user-graduate"></i></div><div class="stat-body"><div class="stat-value"><?=countRows('driving_students','org_id=? AND status=?',[$orgId,'active'])?></div><div class="stat-label">Active Students</div></div></div></div>
</div>

<div class="row g-3" id="instructorGrid">
<?php if(empty($instructors)):?>
<div class="col-12 text-center text-muted py-5"><i class="fas fa-chalkboard-teacher fa-3x mb-3 d-block"></i>No instructors yet. Add your first instructor.</div>
<?php else: foreach($instructors as $inst): ?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100 <?=$inst['status']==='inactive'?'opacity-75':''?>">
    <div class="card-body text-center pt-4">
      <div class="mx-auto mb-3" style="width:60px;height:60px;border-radius:50%;background:<?=$moduleColor?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700">
        <?=strtoupper(substr($inst['name'],0,2))?>
      </div>
      <h6 class="fw-bold mb-1"><?=e($inst['name'])?></h6>
      <?php if($inst['specialization']):?><div class="small text-muted mb-2"><?=e($inst['specialization'])?></div><?php endif;?>
      <div class="d-flex justify-content-center gap-2 mb-3">
        <?=statusBadge($inst['status']??'active')?>
        <?php if($inst['license_number']):?><span class="badge bg-info text-dark"><?=e($inst['license_number'])?></span><?php endif;?>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6 text-center border-end">
          <div class="fw-bold fs-5" style="color:<?=$moduleColor?>"><?=$inst['student_count']?></div><div class="small text-muted">Students</div>
        </div>
        <div class="col-6 text-center">
          <div class="fw-bold fs-5 text-success"><?=$inst['lessons_done']?></div><div class="small text-muted">Lessons Done</div>
        </div>
      </div>
      <?php if($inst['phone']||$inst['email']):?>
      <div class="small text-muted mb-2">
        <?php if($inst['phone']):?><i class="fas fa-phone me-1"></i><?=e($inst['phone'])?><br><?php endif;?>
        <?php if($inst['email']):?><i class="fas fa-envelope me-1"></i><?=e($inst['email'])?><?php endif;?>
      </div>
      <?php endif;?>
    </div>
    <div class="card-footer bg-transparent d-flex gap-2">
      <button class="btn btn-sm btn-outline-primary flex-grow-1" onclick='openEdit(<?=htmlspecialchars(json_encode($inst),ENT_QUOTES)?>)'><i class="fas fa-edit me-1"></i>Edit</button>
      <button class="btn btn-sm btn-outline-danger" onclick="delInst(<?=$inst['id']?>,'<?=e($inst['name'])?>')"><i class="fas fa-trash"></i></button>
    </div>
  </div>
</div>
<?php endforeach; endif;?>
</div>

<!-- Modal -->
<div class="modal fade" id="iModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="iId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="iTitle"><i class="fas fa-chalkboard-teacher me-2"></i>Add Instructor</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-8"><label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label><input type="text" name="name" id="iName" class="form-control" required maxlength="150"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="iStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" id="iEmail" class="form-control"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" id="iPhone" class="form-control" maxlength="50"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Instructor License No.</label><input type="text" name="license_number" id="iLic" class="form-control" maxlength="50"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Specialization</label>
      <input type="text" name="specialization" id="iSpec" class="form-control" list="specList" maxlength="100">
      <datalist id="specList"><?php foreach($specs as $sp):?><option value="<?=e($sp)?>"><?php endforeach;?></datalist></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="iNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Instructor</button></div>
  </form>
</div></div></div>
<form method="POST" id="delIForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delIId"></form>
<?php $extraJs=<<<'JS'
<script>
function openAdd(){document.getElementById('iTitle').innerHTML='<i class="fas fa-chalkboard-teacher me-2"></i>Add Instructor';['iId','iName','iEmail','iPhone','iLic','iSpec','iNotes'].forEach(i=>document.getElementById(i).value=i==='iId'?'0':'');document.getElementById('iStatus').value='active';}
function openEdit(i){document.getElementById('iTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Instructor';document.getElementById('iId').value=i.id;document.getElementById('iName').value=i.name||'';document.getElementById('iEmail').value=i.email||'';document.getElementById('iPhone').value=i.phone||'';document.getElementById('iLic').value=i.license_number||'';document.getElementById('iSpec').value=i.specialization||'';document.getElementById('iStatus').value=i.status||'active';document.getElementById('iNotes').value=i.notes||'';new bootstrap.Modal(document.getElementById('iModal')).show();}
function delInst(id,name){Swal.fire({title:'Remove Instructor?',text:name+' will be deleted.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delIId').value=id;document.getElementById('delIForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
