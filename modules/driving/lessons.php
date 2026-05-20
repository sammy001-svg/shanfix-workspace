<?php
$moduleSlug='driving';$moduleName='Driving School';$moduleIcon='fas fa-car';$moduleColor='#1a237e';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'instructors.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Instructors'],['url'=>'vehicles.php','icon'=>'fas fa-car','label'=>'Vehicles'],['url'=>'classes.php','icon'=>'fas fa-calendar-alt','label'=>'Classes'],['url'=>'lessons.php','icon'=>'fas fa-road','label'=>'Lessons'],['url'=>'tests.php','icon'=>'fas fa-clipboard-check','label'=>'Tests'],['url'=>'licenses.php','icon'=>'fas fa-id-card','label'=>'Licenses'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_once __DIR__.'/../../config/database.php'; require_once __DIR__.'/../../includes/functions.php';
    if (session_status()===PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user=currentUser(); $orgId=(int)$user['org_id']; $action=$_POST['action']??'';

    if ($action==='save') {
        $id       =(int)($_POST['id']??0);
        $stId     =(int)($_POST['student_id']??0);
        $instId   =(int)($_POST['instructor_id']??0)?:null;
        $vehId    =(int)($_POST['vehicle_id']??0)?:null;
        $clsId    =(int)($_POST['class_id']??0)?:null;
        $lNum     =(int)($_POST['lesson_number']??1);
        $lDate    =$_POST['lesson_date']??date('Y-m-d');
        $sTime    =$_POST['start_time']??null;
        $eTime    =$_POST['end_time']??null;
        $dur      =(float)($_POST['duration_hours']??1);
        $topic    =sanitize($_POST['topic']??'');
        $st       =in_array($_POST['status']??'',['draft','started','completed','cancelled'])?$_POST['status']:'draft';
        $notes    =sanitize($_POST['instructor_notes']??'');
        $feedback =sanitize($_POST['feedback']??'');
        $score    =$_POST['score']!==''?(float)$_POST['score']:null;

        if ($id>0) {
            $pdo->prepare("UPDATE driving_lessons SET student_id=?,instructor_id=?,vehicle_id=?,class_id=?,lesson_number=?,lesson_date=?,start_time=?,end_time=?,duration_hours=?,topic=?,status=?,instructor_notes=?,feedback=?,score=? WHERE id=? AND org_id=?")
                ->execute([$stId,$instId,$vehId,$clsId,$lNum,$lDate,$sTime?:null,$eTime?:null,$dur,$topic,$st,$notes,$feedback,$score,$id,$orgId]);
            setFlash('success','Lesson updated.');
        } else {
            // Auto-number
            $nxt = 1;
            try { $s=$pdo->prepare("SELECT COALESCE(MAX(lesson_number),0)+1 FROM driving_lessons WHERE student_id=? AND org_id=?"); $s->execute([$stId,$orgId]); $nxt=(int)$s->fetchColumn(); } catch(Exception $e){}
            $pdo->prepare("INSERT INTO driving_lessons(org_id,student_id,instructor_id,vehicle_id,class_id,lesson_number,lesson_date,start_time,end_time,duration_hours,topic,status,instructor_notes,feedback,score)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$stId,$instId,$vehId,$clsId,$nxt,$lDate,$sTime?:null,$eTime?:null,$dur,$topic,$st,$notes,$feedback,$score]);
            setFlash('success','Lesson scheduled.');
        }
        logActivity($id>0?'update':'create','driving',"Lesson for student #$stId"); redirect('lessons.php');
    }
    if ($action==='setstatus') {
        $id=(int)($_POST['id']??0);
        $st=in_array($_POST['new_status']??'',['draft','started','completed','cancelled'])?$_POST['new_status']:'draft';
        $pdo->prepare("UPDATE driving_lessons SET status=? WHERE id=? AND org_id=?")->execute([$st,$id,$orgId]);
        setFlash('success','Lesson status updated.'); redirect('lessons.php');
    }
    if ($action==='delete') {
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM driving_lessons WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Lesson deleted.'); redirect('lessons.php');
    }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fSt=$_GET['status']??'';$fStId=(int)($_GET['student_id']??0);$fDate=$_GET['date']??'';
$where='l.org_id=?';$params=[$orgId];
if($fSt)   {$where.=' AND l.status=?';$params[]=$fSt;}
if($fStId) {$where.=' AND l.student_id=?';$params[]=$fStId;}
if($fDate) {$where.=' AND l.lesson_date=?';$params[]=$fDate;}
$lessons=[];
try {
    $s=$pdo->prepare("SELECT l.*,CONCAT(st.first_name,' ',st.last_name) AS student_name,i.name AS instructor_name,v.name AS vehicle_name,v.number_plate FROM driving_lessons l JOIN driving_students st ON l.student_id=st.id LEFT JOIN driving_instructors i ON l.instructor_id=i.id LEFT JOIN driving_vehicles v ON l.vehicle_id=v.id WHERE $where ORDER BY l.lesson_date DESC,l.start_time DESC");
    $s->execute($params);$lessons=$s->fetchAll();
} catch(Exception $e){}

$students=[];try{$s=$pdo->prepare("SELECT id,first_name,last_name FROM driving_students WHERE org_id=? AND status='active' ORDER BY first_name");$s->execute([$orgId]);$students=$s->fetchAll();}catch(Exception $e){}
$instructors=[];try{$s=$pdo->prepare("SELECT id,name FROM driving_instructors WHERE org_id=? AND status='active' ORDER BY name");$s->execute([$orgId]);$instructors=$s->fetchAll();}catch(Exception $e){}
$vehicles=[];try{$s=$pdo->prepare("SELECT id,name,number_plate FROM driving_vehicles WHERE org_id=? AND status='active' ORDER BY name");$s->execute([$orgId]);$vehicles=$s->fetchAll();}catch(Exception $e){}
$classes=[];try{$s=$pdo->prepare("SELECT id,name FROM driving_classes WHERE org_id=? AND status='active' ORDER BY name");$s->execute([$orgId]);$classes=$s->fetchAll();}catch(Exception $e){}

$total=countRows('driving_lessons','org_id=?',[$orgId]);
$draft=countRows('driving_lessons','org_id=? AND status=?',[$orgId,'draft']);
$started=countRows('driving_lessons','org_id=? AND status=?',[$orgId,'started']);
$completed=countRows('driving_lessons','org_id=? AND status=?',[$orgId,'completed']);

$statusColors=['draft'=>'secondary','started'=>'primary','completed'=>'success','cancelled'=>'danger'];
$preStudentId = (int)($_GET['student_id'] ?? 0);
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-road me-2" style="color:<?=$moduleColor?>"></i>Driving Lessons</h4><p class="text-muted mb-0">Schedule and track all driving lesson sessions</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#lModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Schedule Lesson</button>
</div>
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-book-open"></i></div><div class="stat-body"><div class="stat-value"><?=$total?></div><div class="stat-label">Total Lessons</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div><div class="stat-body"><div class="stat-value"><?=$draft?></div><div class="stat-label">Scheduled</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#cce5ff;color:#004085"><i class="fas fa-play"></i></div><div class="stat-body"><div class="stat-value"><?=$started?></div><div class="stat-label">In Progress</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div><div class="stat-body"><div class="stat-value"><?=$completed?></div><div class="stat-label">Completed</div></div></div></div>
</div>
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Student</label>
      <select name="student_id" class="form-select form-select-sm"><option value="">All Students</option><?php foreach($students as $st):?><option value="<?=$st['id']?>" <?=$fStId===$st['id']?'selected':''?>><?=e($st['first_name'].' '.$st['last_name'])?></option><?php endforeach;?></select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach(['draft','started','completed','cancelled'] as $s):?><option value="<?=$s?>" <?=$fSt===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Date</label><input type="date" name="date" class="form-control form-control-sm" value="<?=e($fDate)?>"></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="lessons.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card"><div class="card-header d-flex align-items-center justify-content-between">
  <h6 class="mb-0"><i class="fas fa-road me-2" style="color:<?=$moduleColor?>"></i>Lesson Schedule</h6>
  <span class="badge bg-secondary"><?=count($lessons)?> lessons</span>
</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>#</th><th>Student</th><th>Date &amp; Time</th><th>Instructor</th><th>Vehicle</th><th>Topic</th><th>Score</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($lessons)):?>
<tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-road fa-2x mb-2 d-block"></i>No lessons found.</td></tr>
<?php else: foreach($lessons as $l):?>
<tr class="<?=$l['status']==='cancelled'?'table-light text-muted':''?>">
  <td><span class="badge bg-secondary">#<?=$l['lesson_number']?></span></td>
  <td class="fw-semibold"><?=e($l['student_name'])?></td>
  <td><?=formatDate($l['lesson_date'])?><?php if($l['start_time']):?><br><small class="text-muted"><?=htmlspecialchars(substr($l['start_time'],0,5))?><?php if($l['end_time']):?> – <?=htmlspecialchars(substr($l['end_time'],0,5))?><?php endif;?></small><?php endif;?></td>
  <td class="small"><?=e($l['instructor_name']??'—')?></td>
  <td class="small"><?=e($l['vehicle_name']??'—')?><?php if($l['number_plate']):?><br><span class="badge bg-dark"><?=e($l['number_plate'])?></span><?php endif;?></td>
  <td class="small"><?=e($l['topic']??'—')?></td>
  <td><?=$l['score']!==null?'<span class="fw-bold '.((float)$l['score']>=70?'text-success':'text-danger').'">'.(float)$l['score'].'%</span>':'—'?></td>
  <td><span class="badge bg-<?=$statusColors[$l['status']]??'secondary'?>"><?=ucfirst($l['status'])?></span></td>
  <td class="text-center" style="white-space:nowrap">
    <!-- Quick status buttons -->
    <?php if($l['status']==='draft'):?>
    <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="setstatus"><input type="hidden" name="id" value="<?=$l['id']?>"><input type="hidden" name="new_status" value="started"><button type="submit" class="btn btn-xs btn-outline-primary" title="Start"><i class="fas fa-play"></i></button></form>
    <?php elseif($l['status']==='started'):?>
    <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="setstatus"><input type="hidden" name="id" value="<?=$l['id']?>"><input type="hidden" name="new_status" value="completed"><button type="submit" class="btn btn-xs btn-outline-success" title="Complete"><i class="fas fa-check"></i></button></form>
    <?php endif;?>
    <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEdit(<?=htmlspecialchars(json_encode($l),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
    <button class="btn btn-sm btn-outline-danger ms-1" onclick="delLesson(<?=$l['id']?>,'Lesson #<?=$l['lesson_number']?>')" title="Delete"><i class="fas fa-trash"></i></button>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<!-- Modal -->
<div class="modal fade" id="lModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="lId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="lTitle"><i class="fas fa-road me-2"></i>Schedule Lesson</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">Student <span class="text-danger">*</span></label>
      <select name="student_id" id="lStudent" class="form-select" required><option value="">— Select Student —</option><?php foreach($students as $st):?><option value="<?=$st['id']?>"><?=e($st['first_name'].' '.$st['last_name'])?></option><?php endforeach;?></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Instructor</label>
      <select name="instructor_id" id="lInstructor" class="form-select"><option value="">— None —</option><?php foreach($instructors as $i):?><option value="<?=$i['id']?>"><?=e($i['name'])?></option><?php endforeach;?></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Vehicle</label>
      <select name="vehicle_id" id="lVehicle" class="form-select"><option value="">— None —</option><?php foreach($vehicles as $v):?><option value="<?=$v['id']?>"><?=e($v['name'].' ('.$v['number_plate'].')')?></option><?php endforeach;?></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Class (optional)</label>
      <select name="class_id" id="lClass" class="form-select"><option value="">— None —</option><?php foreach($classes as $cl):?><option value="<?=$cl['id']?>"><?=e($cl['name'])?></option><?php endforeach;?></select></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Lesson Date <span class="text-danger">*</span></label><input type="date" name="lesson_date" id="lDate" class="form-control" value="<?=date('Y-m-d')?>" required></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Start Time</label><input type="time" name="start_time" id="lStart" class="form-control"></div>
    <div class="col-md-3"><label class="form-label fw-semibold">End Time</label><input type="time" name="end_time" id="lEnd" class="form-control"></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Duration (hrs)</label><input type="number" name="duration_hours" id="lDur" class="form-control" step="0.5" min="0.5" max="8" value="1"></div>
    <div class="col-md-8"><label class="form-label fw-semibold">Topic / Lesson Focus</label><input type="text" name="topic" id="lTopic" class="form-control" placeholder="e.g. Parking, Highway driving, Reversing…" maxlength="255"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="lStatus" class="form-select"><option value="draft">Draft / Scheduled</option><option value="started">Started</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Score (%)</label><input type="number" name="score" id="lScore" class="form-control" step="0.1" min="0" max="100" placeholder="Leave blank if not scored"></div>
    <div class="col-12"><label class="form-label fw-semibold">Instructor Notes</label><textarea name="instructor_notes" id="lNotes" class="form-control" rows="2" placeholder="Observations during the lesson…"></textarea></div>
    <div class="col-12"><label class="form-label fw-semibold">Student Feedback</label><textarea name="feedback" id="lFeedback" class="form-control" rows="2" placeholder="Feedback for the student…"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Lesson</button></div>
  </form>
</div></div></div>
<form method="POST" id="delLForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delLId"></form>
<?php $preStId = $preStudentId; $extraJs=<<<JS
<script>
const preStudentId = {$preStId};
function openAdd(){
  document.getElementById('lTitle').innerHTML='<i class="fas fa-road me-2"></i>Schedule Lesson';
  ['lId','lTopic','lNotes','lFeedback','lStart','lEnd','lScore'].forEach(i=>document.getElementById(i).value=i==='lId'?'0':'');
  document.getElementById('lStudent').value=preStudentId||'';
  document.getElementById('lInstructor').value='';document.getElementById('lVehicle').value='';document.getElementById('lClass').value='';
  document.getElementById('lDate').value=new Date().toISOString().substring(0,10);
  document.getElementById('lDur').value=1;document.getElementById('lStatus').value='draft';
}
function openEdit(l){
  document.getElementById('lTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Lesson';
  document.getElementById('lId').value=l.id;
  document.getElementById('lStudent').value=l.student_id||'';
  document.getElementById('lInstructor').value=l.instructor_id||'';
  document.getElementById('lVehicle').value=l.vehicle_id||'';
  document.getElementById('lClass').value=l.class_id||'';
  document.getElementById('lDate').value=l.lesson_date?l.lesson_date.substring(0,10):'';
  document.getElementById('lStart').value=l.start_time?l.start_time.substring(0,5):'';
  document.getElementById('lEnd').value=l.end_time?l.end_time.substring(0,5):'';
  document.getElementById('lDur').value=l.duration_hours||1;
  document.getElementById('lTopic').value=l.topic||'';
  document.getElementById('lStatus').value=l.status||'draft';
  document.getElementById('lScore').value=l.score!==null?l.score:'';
  document.getElementById('lNotes').value=l.instructor_notes||'';
  document.getElementById('lFeedback').value=l.feedback||'';
  new bootstrap.Modal(document.getElementById('lModal')).show();
}
function delLesson(id,lbl){Swal.fire({title:'Delete Lesson?',text:lbl+' will be removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delLId').value=id;document.getElementById('delLForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
