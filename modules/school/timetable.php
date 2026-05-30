<?php
require_once __DIR__ . '/_nav.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $classId=(int)($_POST['class_id']??0);$subjectId=(int)($_POST['subject_id']??0);
        $staffId=(int)($_POST['staff_id']??0)||null;$day=(int)($_POST['day_of_week']??1);
        $period=(int)($_POST['period']??1);$start=sanitize($_POST['start_time']??'');
        $end=sanitize($_POST['end_time']??'');$room=sanitize($_POST['room']??'');
        if(!$classId||!$day||!$start||!$end){setFlash('error','Class, day and times are required.');redirect('timetable.php');}
        if($id){$pdo->prepare("UPDATE sch_timetable SET class_id=?,subject_id=?,staff_id=?,day_of_week=?,period=?,start_time=?,end_time=?,room=? WHERE id=? AND org_id=?")->execute([$classId,$subjectId,$staffId,$day,$period,$start,$end,$room,$id,$orgId]);setFlash('success','Slot updated.');}
        else{$pdo->prepare("INSERT INTO sch_timetable (org_id,class_id,subject_id,staff_id,day_of_week,period,start_time,end_time,room) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$orgId,$classId,$subjectId,$staffId,$day,$period,$start,$end,$room]);setFlash('success','Slot added.');}
        redirect('timetable.php'.($classId?"?class_id=$classId":''));
    }
    if($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM sch_timetable WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Slot deleted.');redirect('timetable.php'.($_POST['class_id']?'?class_id='.(int)$_POST['class_id']:'')); }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fClass=(int)($_GET['class_id']??0);
$classes=[];try{$s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name");$s->execute([$orgId]);$classes=$s->fetchAll();}catch(Exception $e){}
$subjects=[];try{$s=$pdo->prepare("SELECT id,name FROM sch_subjects WHERE org_id=? AND status='active' ORDER BY name");$s->execute([$orgId]);$subjects=$s->fetchAll();}catch(Exception $e){}
$staff=[];try{$s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name FROM sch_staff WHERE org_id=? ORDER BY first_name");$s->execute([$orgId]);$staff=$s->fetchAll();}catch(Exception $e){}
$slots=[];
if($fClass){
    try{$s=$pdo->prepare("SELECT t.*,sub.name AS subject_name,st.first_name,st.last_name FROM sch_timetable t LEFT JOIN sch_subjects sub ON t.subject_id=sub.id LEFT JOIN sch_staff st ON t.staff_id=st.id WHERE t.org_id=? AND t.class_id=? ORDER BY t.day_of_week,t.period");$s->execute([$orgId,$fClass]);$slots=$s->fetchAll();}catch(Exception $e){}
}
$days=[1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday'];
// Build grid: day => period => slot
$grid=[];foreach($slots as $slot){$grid[$slot['day_of_week']][$slot['period']]=$slot;}
$maxPeriod=!empty($slots)?max(array_column($slots,'period')):8;
$className='';foreach($classes as $c){if($c['id']==$fClass)$className=$c['name'];}
?>
<?=flashAlert()?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-calendar-alt me-2" style="color:<?=$moduleColor?>"></i>Timetable</h4><p class="text-muted mb-0">Weekly class schedule management</p></div>
  <?php if($fClass):?>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#slotModal"><i class="fas fa-plus me-2"></i>Add Slot</button>
  <?php endif;?>
</div>

<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Select Class</label>
      <select name="class_id" class="form-select" onchange="this.form.submit()">
        <option value="">â€” Choose a class â€”</option>
        <?php foreach($classes as $c):?><option value="<?=$c['id']?>" <?=$fClass==$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?>
      </select>
    </div>
    <?php if($fClass):?><div class="col-auto"><span class="badge fs-6 text-white" style="background:<?=$moduleColor?>"><?=e($className)?></span></div><?php endif;?>
  </form>
</div></div>

<?php if(!$fClass):?>
<div class="text-center py-5 text-muted"><i class="fas fa-calendar-alt fa-3x mb-3 d-block"></i>Select a class above to view its timetable.</div>
<?php else:?>
<div class="card"><div class="card-header d-flex align-items-center justify-content-between">
  <h6 class="mb-0"><i class="fas fa-table me-2" style="color:<?=$moduleColor?>"></i>Weekly Schedule â€” <?=e($className)?></h6>
  <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
</div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-bordered mb-0 text-center small">
  <thead class="table-light">
    <tr><th style="width:70px">Period</th><?php foreach($days as $d=>$dName):if($d<=5):?><th><?=$dName?></th><?php endif;endforeach;?></tr>
  </thead>
  <tbody>
  <?php for($p=1;$p<=$maxPeriod;$p++):?>
  <tr>
    <td class="fw-bold text-muted"><?=$p?></td>
    <?php foreach($days as $d=>$dName):if($d<=5):
      $slot=$grid[$d][$p]??null;?>
    <td class="align-middle p-1" style="min-width:120px">
      <?php if($slot):
        $timeRange=date('H:i',strtotime($slot['start_time'])).'â€“'.date('H:i',strtotime($slot['end_time']));?>
      <div class="p-1 rounded" style="background:<?=$moduleColor?>15;border-left:3px solid <?=$moduleColor?>">
        <div class="fw-semibold" style="color:<?=$moduleColor?>;font-size:.78rem"><?=e($slot['subject_name']??'â€”')?></div>
        <div class="text-muted" style="font-size:.7rem"><?=$timeRange?></div>
        <?php if($slot['first_name']):?><div class="text-muted" style="font-size:.7rem"><?=e($slot['first_name'].' '.$slot['last_name'])?></div><?php endif;?>
        <?php if($slot['room']):?><div class="text-muted" style="font-size:.68rem"><i class="fas fa-map-marker-alt me-1"></i><?=e($slot['room'])?></div><?php endif;?>
        <div class="mt-1">
          <button class="btn btn-xs btn-outline-secondary me-1 btn-edit-slot"
            data-id="<?=$slot['id']?>" data-class_id="<?=$fClass?>" data-subject_id="<?=$slot['subject_id']??0?>"
            data-staff_id="<?=$slot['staff_id']??0?>" data-day_of_week="<?=$slot['day_of_week']?>"
            data-period="<?=$slot['period']?>" data-start_time="<?=$slot['start_time']?>"
            data-end_time="<?=$slot['end_time']?>" data-room="<?=e($slot['room']??'')?>"><i class="fas fa-edit"></i></button>
          <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$slot['id']?>"><input type="hidden" name="class_id" value="<?=$fClass?>">
            <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Remove this slot?"><i class="fas fa-times"></i></button>
          </form>
        </div>
      </div>
      <?php else:?>
      <span class="text-muted small">â€”</span>
      <?php endif;?>
    </td>
    <?php endif;endforeach;?>
  </tr>
  <?php endfor;?>
  </tbody>
</table></div></div></div>
<?php endif;?>

<!-- Slot Modal -->
<div class="modal fade" id="slotModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-calendar-alt me-2"></i><span id="slotModalTitle">Add Timetable Slot</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="slotId" value="0">
    <input type="hidden" name="class_id" value="<?=$fClass?>">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label fw-semibold">Day <span class="text-danger">*</span></label>
        <select name="day_of_week" id="slotDay" class="form-select"><?php foreach($days as $d=>$dn):if($d<=5):?><option value="<?=$d?>"><?=$dn?></option><?php endif;endforeach;?></select>
      </div>
      <div class="col-md-6"><label class="form-label fw-semibold">Period #</label><input type="number" name="period" id="slotPeriod" class="form-control" min="1" max="10" value="1"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Start Time <span class="text-danger">*</span></label><input type="time" name="start_time" id="slotStart" class="form-control" value="08:00"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">End Time <span class="text-danger">*</span></label><input type="time" name="end_time" id="slotEnd" class="form-control" value="09:00"></div>
      <div class="col-md-8"><label class="form-label fw-semibold">Subject</label><select name="subject_id" id="slotSubject" class="form-select"><option value="">â€” Break / Free â€”</option><?php foreach($subjects as $s):?><option value="<?=$s['id']?>"><?=e($s['name'])?></option><?php endforeach;?></select></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Room</label><input type="text" name="room" id="slotRoom" class="form-control" placeholder="e.g. A101"></div>
      <div class="col-12"><label class="form-label fw-semibold">Teacher</label><select name="staff_id" id="slotStaff" class="form-select"><option value="">â€” Unassigned â€”</option><?php foreach($staff as $st):?><option value="<?=$st['id']?>"><?=e($st['name'])?></option><?php endforeach;?></select></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Slot</button></div>
  </form>
</div></div></div>
<?php $extraJs=<<<JS
<script>
document.querySelectorAll('.btn-edit-slot').forEach(btn=>{btn.addEventListener('click',function(){
  document.getElementById('slotModalTitle').textContent='Edit Slot';
  document.getElementById('slotId').value=this.dataset.id;
  document.getElementById('slotDay').value=this.dataset.day_of_week;
  document.getElementById('slotPeriod').value=this.dataset.period;
  document.getElementById('slotStart').value=this.dataset.start_time;
  document.getElementById('slotEnd').value=this.dataset.end_time;
  document.getElementById('slotSubject').value=this.dataset.subject_id||'';
  document.getElementById('slotStaff').value=this.dataset.staff_id||'';
  document.getElementById('slotRoom').value=this.dataset.room||'';
  new bootstrap.Modal(document.getElementById('slotModal')).show();
});});
document.querySelectorAll('.btn-confirm').forEach(btn=>{btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});});
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>

