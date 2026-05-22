<?php
$moduleSlug='school';$moduleName='School Management';$moduleIcon='fas fa-school';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'parents.php','icon'=>'fas fa-users','label'=>'Parents'],['url'=>'staff.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Staff'],['url'=>'classes.php','icon'=>'fas fa-chalkboard','label'=>'Classes'],['url'=>'subjects.php','icon'=>'fas fa-book','label'=>'Subjects'],['url'=>'timetable.php','icon'=>'fas fa-calendar-alt','label'=>'Timetable'],['url'=>'attendance.php','icon'=>'fas fa-clipboard-check','label'=>'Attendance'],['url'=>'exams.php','icon'=>'fas fa-file-alt','label'=>'Exams'],['url'=>'results.php','icon'=>'fas fa-chart-line','label'=>'Results'],['url'=>'fees.php','icon'=>'fas fa-money-bill','label'=>'Fees'],['url'=>'library.php','icon'=>'fas fa-book-reader','label'=>'Library'],['url'=>'transport.php','icon'=>'fas fa-bus','label'=>'Transport'],['url'=>'events.php','icon'=>'fas fa-calendar-day','label'=>'Events'],['url'=>'notices.php','icon'=>'fas fa-bullhorn','label'=>'Notices'],['url'=>'grades.php','icon'=>'fas fa-star','label'=>'Grades'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';

    if($action==='save_route'){
        $id=(int)($_POST['id']??0);
        $name=sanitize($_POST['route_name']??'');$vehicle=sanitize($_POST['vehicle_no']??'');
        $driver=sanitize($_POST['driver_name']??'');$dPhone=sanitize($_POST['driver_phone']??'');
        $conductor=sanitize($_POST['conductor']??'');$capacity=max(1,(int)($_POST['capacity']??40));
        $morning=sanitize($_POST['morning_time']??'');$evening=sanitize($_POST['evening_time']??'');
        $stops=sanitize($_POST['stops']??'');$fee=(float)($_POST['term_fee']??0);
        $status=sanitize($_POST['status']??'active');
        if(!$name){setFlash('error','Route name is required.');redirect('transport.php');}
        if($id){
            $pdo->prepare("UPDATE sch_transport_routes SET route_name=?,vehicle_no=?,driver_name=?,driver_phone=?,conductor=?,capacity=?,morning_time=?,evening_time=?,stops=?,term_fee=?,status=? WHERE id=? AND org_id=?")
               ->execute([$name,$vehicle,$driver,$dPhone,$conductor,$capacity,$morning?:null,$evening?:null,$stops,$fee,$status,$id,$orgId]);
            setFlash('success','Route updated.');
        } else {
            $pdo->prepare("INSERT INTO sch_transport_routes (org_id,route_name,vehicle_no,driver_name,driver_phone,conductor,capacity,morning_time,evening_time,stops,term_fee,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$orgId,$name,$vehicle,$driver,$dPhone,$conductor,$capacity,$morning?:null,$evening?:null,$stops,$fee,$status]);
            setFlash('success','Route added.');
        }
        redirect('transport.php');
    }

    if($action==='delete_route'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM sch_transport_students WHERE route_id=? AND org_id=?")->execute([$id,$orgId]);
        $pdo->prepare("DELETE FROM sch_transport_routes WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Route deleted.');redirect('transport.php');
    }

    if($action==='assign_student'){
        $routeId=(int)($_POST['route_id']??0);$studentId=(int)($_POST['student_id']??0);$stop=sanitize($_POST['pickup_stop']??'');
        if(!$routeId||!$studentId){setFlash('error','Route and student are required.');redirect("transport.php?view=$routeId");}
        try{$pdo->prepare("INSERT INTO sch_transport_students (org_id,route_id,student_id,pickup_stop,status) VALUES (?,?,?,?,'active') ON DUPLICATE KEY UPDATE pickup_stop=VALUES(pickup_stop),status='active'")->execute([$orgId,$routeId,$studentId,$stop]);}catch(Exception $e){}
        setFlash('success','Student assigned.');redirect("transport.php?view=$routeId");
    }

    if($action==='remove_student'){
        $id=(int)($_POST['id']??0);$routeId=(int)($_POST['route_id']??0);
        $pdo->prepare("DELETE FROM sch_transport_students WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Student removed from route.');redirect("transport.php?view=$routeId");
    }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$viewId=(int)($_GET['view']??0);

$routes=[];try{$s=$pdo->prepare("SELECT r.*,COUNT(ts.id) AS assigned_count FROM sch_transport_routes r LEFT JOIN sch_transport_students ts ON r.id=ts.route_id AND ts.status='active' WHERE r.org_id=? GROUP BY r.id ORDER BY r.route_name");$s->execute([$orgId]);$routes=$s->fetchAll();}catch(Exception $e){}
$totalRoutes=count($routes);$activeRoutes=count(array_filter($routes,fn($r)=>$r['status']==='active'));
$totalAssigned=array_sum(array_column($routes,'assigned_count'));

$viewRoute=null;$routeStudents=[];$allStudents=[];
if($viewId){
    try{$s=$pdo->prepare("SELECT * FROM sch_transport_routes WHERE id=? AND org_id=?");$s->execute([$viewId,$orgId]);$viewRoute=$s->fetch();}catch(Exception $e){}
    if($viewRoute){
        try{$s=$pdo->prepare("SELECT ts.*,CONCAT(st.first_name,' ',st.last_name) AS name,st.admission_no,c.name AS class_name FROM sch_transport_students ts JOIN sch_students st ON ts.student_id=st.id LEFT JOIN sch_classes c ON st.class_id=c.id WHERE ts.route_id=? AND ts.org_id=? AND ts.status='active' ORDER BY st.first_name");$s->execute([$viewId,$orgId]);$routeStudents=$s->fetchAll();}catch(Exception $e){}
        try{$s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name,admission_no FROM sch_students WHERE org_id=? AND status='active' ORDER BY first_name");$s->execute([$orgId]);$allStudents=$s->fetchAll();}catch(Exception $e){}
    }
}
?>
<?=flashAlert()?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-bus me-2" style="color:<?=$moduleColor?>"></i>Transport Management</h4><p class="text-muted mb-0">Bus routes, drivers and student transport assignments</p></div>
  <?php if(!$viewRoute):?>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#routeModal"><i class="fas fa-plus me-2"></i>Add Route</button>
  <?php else:?>
  <a href="transport.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Routes</a>
  <?php endif;?>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-route"></i></div><div class="stat-body"><div class="stat-value"><?=$totalRoutes?></div><div class="stat-label">Total Routes</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-bus"></i></div><div class="stat-body"><div class="stat-value"><?=$activeRoutes?></div><div class="stat-label">Active Routes</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-child"></i></div><div class="stat-body"><div class="stat-value"><?=$totalAssigned?></div><div class="stat-label">Students Assigned</div></div></div></div>
</div>

<?php if(!$viewRoute):?>
<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?=$moduleColor?>"></i>Bus Routes</h6></div>
  <div class="card-body p-0">
  <table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Route Name</th><th>Vehicle</th><th>Driver</th><th>Stops</th><th class="text-center">Capacity</th><th class="text-center">Assigned</th><th>Term Fee</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if(empty($routes)):?><tr><td colspan="9" class="text-center text-muted py-4">No routes added yet.</td></tr>
    <?php else:foreach($routes as $rt):$fill=min(100,$rt['capacity']>0?round(100*$rt['assigned_count']/$rt['capacity']):0);$fillC=$fill>=90?'danger':($fill>=70?'warning':'success');?>
    <tr>
      <td class="fw-semibold"><a href="transport.php?view=<?=$rt['id']?>" class="text-decoration-none" style="color:<?=$moduleColor?>"><?=e($rt['route_name'])?></a></td>
      <td class="small"><?=e($rt['vehicle_no']??'—')?></td>
      <td><?=e($rt['driver_name']??'—')?><?php if($rt['driver_phone']):?><div class="text-muted" style="font-size:.75rem"><?=e($rt['driver_phone'])?></div><?php endif;?></td>
      <td class="small text-muted"><?=$rt['stops']?e(implode(', ',array_slice(explode(',',$rt['stops']),0,3))).(str_word_count($rt['stops'])>3?'…':''):'—'?></td>
      <td class="text-center"><?=$rt['capacity']?></td>
      <td class="text-center">
        <div class="d-flex align-items-center gap-1">
          <span class="badge bg-<?=$fillC?>"><?=$rt['assigned_count']?></span>
          <div class="progress flex-grow-1" style="height:5px"><div class="progress-bar bg-<?=$fillC?>" style="width:<?=$fill?>%"></div></div>
        </div>
      </td>
      <td><?=isset($rt['term_fee'])?'KES '.number_format($rt['term_fee'],2):'—'?></td>
      <td><?=$rt['status']==='active'?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>'?></td>
      <td class="text-end">
        <a href="transport.php?view=<?=$rt['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="fas fa-users"></i></a>
        <button class="btn btn-xs btn-outline-secondary me-1 btn-edit-route"
          data-id="<?=$rt['id']?>" data-name="<?=e($rt['route_name'])?>" data-vehicle="<?=e($rt['vehicle_no']??'')?>"
          data-driver="<?=e($rt['driver_name']??'')?>" data-dphone="<?=e($rt['driver_phone']??'')?>"
          data-conductor="<?=e($rt['conductor']??'')?>" data-capacity="<?=$rt['capacity']?>"
          data-morning="<?=$rt['morning_time']??''?>" data-evening="<?=$rt['evening_time']??''?>"
          data-stops="<?=e($rt['stops']??'')?>" data-fee="<?=$rt['term_fee']?>" data-status="<?=$rt['status']?>"><i class="fas fa-edit"></i></button>
        <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="delete_route"><input type="hidden" name="id" value="<?=$rt['id']?>">
          <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete this route?"><i class="fas fa-trash"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
  </table>
  </div>
</div>

<?php else:?>
<!-- Route Detail -->
<?php $stops=array_filter(array_map('trim',explode(',',$viewRoute['stops']??'')));$fill=min(100,$viewRoute['capacity']>0?round(100*count($routeStudents)/$viewRoute['capacity']):0);$fillC=$fill>=90?'danger':($fill>=70?'warning':'success');?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header" style="background:<?=$moduleColor?>15;border-left:4px solid <?=$moduleColor?>">
        <h6 class="mb-0 fw-bold"><i class="fas fa-bus me-2" style="color:<?=$moduleColor?>"></i><?=e($viewRoute['route_name'])?></h6>
      </div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-2 small">
          <tr><td class="text-muted" style="width:45%">Vehicle</td><td class="fw-semibold"><?=e($viewRoute['vehicle_no']??'—')?></td></tr>
          <tr><td class="text-muted">Driver</td><td class="fw-semibold"><?=e($viewRoute['driver_name']??'—')?></td></tr>
          <?php if($viewRoute['driver_phone']):?><tr><td class="text-muted">Phone</td><td><?=e($viewRoute['driver_phone'])?></td></tr><?php endif;?>
          <?php if($viewRoute['conductor']):?><tr><td class="text-muted">Conductor</td><td><?=e($viewRoute['conductor'])?></td></tr><?php endif;?>
          <tr><td class="text-muted">Morning</td><td><?=$viewRoute['morning_time']?date('H:i',strtotime($viewRoute['morning_time'])):'—'?></td></tr>
          <tr><td class="text-muted">Evening</td><td><?=$viewRoute['evening_time']?date('H:i',strtotime($viewRoute['evening_time'])):'—'?></td></tr>
          <tr><td class="text-muted">Term Fee</td><td class="fw-semibold">KES <?=number_format($viewRoute['term_fee'],2)?></td></tr>
        </table>
        <div class="mb-1 d-flex justify-content-between small"><span>Capacity</span><span class="fw-bold"><?=count($routeStudents)?>/<?=$viewRoute['capacity']?></span></div>
        <div class="progress mb-2" style="height:8px"><div class="progress-bar bg-<?=$fillC?>" style="width:<?=$fill?>%"></div></div>
        <?php if(!empty($stops)):?>
        <div class="mt-2"><div class="small text-muted fw-semibold mb-1">Stops:</div><?php foreach($stops as $stop):?><span class="badge bg-light text-dark border me-1 mb-1"><?=e($stop)?></span><?php endforeach;?></div>
        <?php endif;?>
        <div class="mt-3">
          <button class="btn btn-sm btn-outline-secondary w-100 btn-edit-route"
            data-id="<?=$viewRoute['id']?>" data-name="<?=e($viewRoute['route_name'])?>" data-vehicle="<?=e($viewRoute['vehicle_no']??'')?>"
            data-driver="<?=e($viewRoute['driver_name']??'')?>" data-dphone="<?=e($viewRoute['driver_phone']??'')?>"
            data-conductor="<?=e($viewRoute['conductor']??'')?>" data-capacity="<?=$viewRoute['capacity']?>"
            data-morning="<?=$viewRoute['morning_time']??''?>" data-evening="<?=$viewRoute['evening_time']??''?>"
            data-stops="<?=e($viewRoute['stops']??'')?>" data-fee="<?=$viewRoute['term_fee']?>" data-status="<?=$viewRoute['status']?>"><i class="fas fa-edit me-1"></i>Edit Route</button>
        </div>
      </div>
    </div>

    <!-- Assign student -->
    <div class="card">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-user-plus me-2" style="color:<?=$moduleColor?>"></i>Assign Student</h6></div>
      <div class="card-body">
        <form method="POST">
          <?=csrfField()?><input type="hidden" name="action" value="assign_student"><input type="hidden" name="route_id" value="<?=$viewRoute['id']?>">
          <div class="mb-2"><label class="form-label small fw-semibold">Student</label>
            <select name="student_id" class="form-select form-select-sm"><option value="">— Select student —</option><?php foreach($allStudents as $st):?><option value="<?=$st['id']?>"><?=e($st['name'])?> (<?=e($st['admission_no']??'')?>)</option><?php endforeach;?></select>
          </div>
          <div class="mb-2"><label class="form-label small fw-semibold">Pickup Stop</label>
            <input type="text" name="pickup_stop" class="form-control form-control-sm" list="stopList" placeholder="Stop name">
            <?php if(!empty($stops)):?><datalist id="stopList"><?php foreach($stops as $s):?><option><?=e($s)?></option><?php endforeach;?></datalist><?php endif;?>
          </div>
          <button type="submit" class="btn btn-sm text-white w-100" style="background:<?=$moduleColor?>"><i class="fas fa-plus me-1"></i>Assign</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-users me-2" style="color:<?=$moduleColor?>"></i>Assigned Students (<?=count($routeStudents)?>)</h6></div>
      <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>#</th><th>Student</th><th>Admission No</th><th>Class</th><th>Pickup Stop</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php if(empty($routeStudents)):?><tr><td colspan="6" class="text-center text-muted py-4">No students assigned to this route.</td></tr>
        <?php else:foreach($routeStudents as $i=>$rs):?>
        <tr>
          <td class="text-muted"><?=$i+1?></td>
          <td class="fw-semibold"><?=e($rs['name']??'')?></td>
          <td class="small text-muted"><?=e($rs['admission_no']??'—')?></td>
          <td><?=e($rs['class_name']??'—')?></td>
          <td><?=e($rs['pickup_stop']??'—')?></td>
          <td class="text-end">
            <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="remove_student"><input type="hidden" name="id" value="<?=$rs['id']?>"><input type="hidden" name="route_id" value="<?=$viewRoute['id']?>">
              <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Remove this student from route?"><i class="fas fa-times"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach;endif;?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>
<?php endif;?>

<!-- Route Modal -->
<div class="modal fade" id="routeModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-bus me-2"></i><span id="routeModalTitle">Add Route</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save_route"><input type="hidden" name="id" id="routeId" value="0">
    <div class="row g-3">
      <div class="col-md-8"><label class="form-label fw-semibold">Route Name <span class="text-danger">*</span></label><input type="text" name="route_name" id="routeName" class="form-control" placeholder="e.g. Eastlands Route" required></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Vehicle No</label><input type="text" name="vehicle_no" id="routeVehicle" class="form-control" placeholder="e.g. KCA 001X"></div>
      <div class="col-md-5"><label class="form-label fw-semibold">Driver Name</label><input type="text" name="driver_name" id="routeDriver" class="form-control"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Driver Phone</label><input type="text" name="driver_phone" id="routeDPhone" class="form-control"></div>
      <div class="col-md-3"><label class="form-label fw-semibold">Capacity</label><input type="number" name="capacity" id="routeCap" class="form-control" value="40" min="1"></div>
      <div class="col-md-5"><label class="form-label fw-semibold">Conductor</label><input type="text" name="conductor" id="routeConductor" class="form-control"></div>
      <div class="col-md-3"><label class="form-label fw-semibold">Morning Time</label><input type="time" name="morning_time" id="routeMorning" class="form-control"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Evening Time</label><input type="time" name="evening_time" id="routeEvening" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Term Fee (KES)</label><input type="number" name="term_fee" id="routeFee" class="form-control" value="0" min="0" step="0.01"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select name="status" id="routeStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      <div class="col-12"><label class="form-label fw-semibold">Stops <small class="text-muted">(comma-separated)</small></label><input type="text" name="stops" id="routeStops" class="form-control" placeholder="Stop 1, Stop 2, Stop 3"></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Route</button></div>
  </form>
</div></div></div>

<?php ob_start();?>
<script>
document.querySelectorAll('.btn-edit-route').forEach(btn=>{btn.addEventListener('click',function(){
  document.getElementById('routeModalTitle').textContent='Edit Route';
  document.getElementById('routeId').value=this.dataset.id;
  document.getElementById('routeName').value=this.dataset.name||'';
  document.getElementById('routeVehicle').value=this.dataset.vehicle||'';
  document.getElementById('routeDriver').value=this.dataset.driver||'';
  document.getElementById('routeDPhone').value=this.dataset.dphone||'';
  document.getElementById('routeConductor').value=this.dataset.conductor||'';
  document.getElementById('routeCap').value=this.dataset.capacity||40;
  document.getElementById('routeMorning').value=this.dataset.morning||'';
  document.getElementById('routeEvening').value=this.dataset.evening||'';
  document.getElementById('routeStops').value=this.dataset.stops||'';
  document.getElementById('routeFee').value=this.dataset.fee||0;
  document.getElementById('routeStatus').value=this.dataset.status||'active';
  new bootstrap.Modal(document.getElementById('routeModal')).show();
});});
document.querySelectorAll('.btn-confirm').forEach(btn=>{btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});});
</script>
<?php $extraJs=ob_get_clean();
require_once __DIR__.'/../../includes/footer.php';?>
