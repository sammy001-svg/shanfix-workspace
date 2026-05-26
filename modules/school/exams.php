<?php
require_once __DIR__ . '/_nav.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';

    if($action==='save_exam'){
        $id=(int)($_POST['id']??0);
        $name=sanitize($_POST['name']??'');$term=sanitize($_POST['term']??'');
        $year=sanitize($_POST['academic_year']??'');$start=sanitize($_POST['start_date']??'');
        $end=sanitize($_POST['end_date']??'');$status=sanitize($_POST['status']??'upcoming');
        $desc=sanitize($_POST['description']??'');
        if(!$name){setFlash('error','Exam name is required.');redirect('exams.php');}
        if($id){
            $pdo->prepare("UPDATE sch_exams SET name=?,term=?,academic_year=?,start_date=?,end_date=?,status=?,description=? WHERE id=? AND org_id=?")
               ->execute([$name,$term,$year,$start?:null,$end?:null,$status,$desc,$id,$orgId]);
            setFlash('success','Exam updated.');
        } else {
            $pdo->prepare("INSERT INTO sch_exams (org_id,name,term,academic_year,start_date,end_date,status,description) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$orgId,$name,$term,$year,$start?:null,$end?:null,$status,$desc]);
            setFlash('success','Exam created.');
        }
        redirect('exams.php');
    }

    if($action==='delete_exam'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM sch_exam_schedule WHERE exam_id IN (SELECT id FROM sch_exams WHERE id=? AND org_id=?)")->execute([$id,$orgId]);
        $pdo->prepare("DELETE FROM sch_exams WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Exam deleted.');redirect('exams.php');
    }

    if($action==='save_schedule'){
        $examId=(int)($_POST['exam_id']??0);$classId=(int)($_POST['class_id']??0);
        $subjectId=(int)($_POST['subject_id']??0);$examDate=sanitize($_POST['exam_date']??'');
        $startTime=sanitize($_POST['start_time']??'');$endTime=sanitize($_POST['end_time']??'');
        $room=sanitize($_POST['room']??'');$maxMarks=(float)($_POST['max_marks']??100);
        $schedId=(int)($_POST['sched_id']??0);
        if(!$examId||!$classId||!$subjectId){setFlash('error','Exam, class and subject are required.');redirect("exams.php?view=$examId");}
        if($schedId){
            // Verify schedule entry belongs to an exam owned by this org
            if(!assertParentOwnership('sch_exams','exam_id','sch_exam_schedule',$schedId,$orgId)){
                setFlash('danger','Access denied.');redirect("exams.php?view=$examId");
            }
            $pdo->prepare("UPDATE sch_exam_schedule SET class_id=?,subject_id=?,exam_date=?,start_time=?,end_time=?,room=?,max_marks=? WHERE id=?")->execute([$classId,$subjectId,$examDate?:null,$startTime?:null,$endTime?:null,$room,$maxMarks,$schedId]);
        } else {
            $pdo->prepare("INSERT INTO sch_exam_schedule (exam_id,class_id,subject_id,exam_date,start_time,end_time,room,max_marks) VALUES (?,?,?,?,?,?,?,?)")->execute([$examId,$classId,$subjectId,$examDate?:null,$startTime?:null,$endTime?:null,$room,$maxMarks]);
        }
        setFlash('success','Schedule saved.');redirect("exams.php?view=$examId");
    }

    if($action==='delete_schedule'){
        $sid=(int)($_POST['sched_id']??0);$eid=(int)($_POST['exam_id']??0);
        // Verify the parent exam belongs to this org before deleting schedule entry
        if(!assertParentOwnership('sch_exams','exam_id','sch_exam_schedule',$sid,$orgId)){
            setFlash('danger','Access denied.');redirect("exams.php?view=$eid");
        }
        $pdo->prepare("DELETE FROM sch_exam_schedule WHERE id=?")->execute([$sid]);
        setFlash('success','Schedule entry removed.');redirect("exams.php?view=$eid");
    }

    if($action==='update_status'){
        $id=(int)($_POST['id']??0);$status=sanitize($_POST['status']??'');
        $valid=['upcoming','ongoing','completed','cancelled'];
        if(in_array($status,$valid)){$pdo->prepare("UPDATE sch_exams SET status=? WHERE id=? AND org_id=?")->execute([$status,$id,$orgId]);setFlash('success','Status updated.');}
        redirect('exams.php');
    }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$viewId=(int)($_GET['view']??0);

$classes=[];try{$s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name");$s->execute([$orgId]);$classes=$s->fetchAll();}catch(Exception $e){}
$subjects=[];try{$s=$pdo->prepare("SELECT id,name FROM sch_subjects WHERE org_id=? AND status='active' ORDER BY name");$s->execute([$orgId]);$subjects=$s->fetchAll();}catch(Exception $e){}
$exams=[];try{$s=$pdo->prepare("SELECT * FROM sch_exams WHERE org_id=? ORDER BY FIELD(status,'ongoing','upcoming','completed','cancelled'),start_date DESC");$s->execute([$orgId]);$exams=$s->fetchAll();}catch(Exception $e){}

$viewExam=null;$schedule=[];
if($viewId){
    try{$s=$pdo->prepare("SELECT * FROM sch_exams WHERE id=? AND org_id=?");$s->execute([$viewId,$orgId]);$viewExam=$s->fetch();}catch(Exception $e){}
    if($viewExam){
        try{$s=$pdo->prepare("SELECT es.*,c.name AS class_name,sub.name AS subject_name FROM sch_exam_schedule es LEFT JOIN sch_classes c ON es.class_id=c.id LEFT JOIN sch_subjects sub ON es.subject_id=sub.id WHERE es.exam_id=? ORDER BY es.exam_date,c.name,sub.name");$s->execute([$viewId]);$schedule=$s->fetchAll();}catch(Exception $e){}
    }
}

$statusColors=['upcoming'=>'primary','ongoing'=>'success','completed'=>'secondary','cancelled'=>'danger'];
$countByStatus=[];foreach(['upcoming','ongoing','completed','cancelled'] as $st){$countByStatus[$st]=count(array_filter($exams,fn($e)=>$e['status']===$st));}
?>
<?=flashAlert()?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-file-alt me-2" style="color:<?=$moduleColor?>"></i>Examinations</h4><p class="text-muted mb-0">Manage exam schedules and track examination periods</p></div>
  <?php if(!$viewExam):?>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#examModal"><i class="fas fa-plus me-2"></i>New Exam</button>
  <?php else:?>
  <div class="d-flex gap-2">
    <a href="exams.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Exams</a>
    <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#schedModal"><i class="fas fa-plus me-2"></i>Add Schedule Entry</button>
  </div>
  <?php endif;?>
</div>

<?php if(!$viewExam):?>
<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-file-alt"></i></div><div class="stat-body"><div class="stat-value"><?=count($exams)?></div><div class="stat-label">Total Exams</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-spinner"></i></div><div class="stat-body"><div class="stat-value"><?=$countByStatus['ongoing']?></div><div class="stat-label">Ongoing</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?=$countByStatus['upcoming']?></div><div class="stat-label">Upcoming</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$countByStatus['completed']?></div><div class="stat-label">Completed</div></div></div></div>
</div>

<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?=$moduleColor?>"></i>All Examinations</h6></div>
  <div class="card-body p-0">
  <table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Exam Name</th><th>Term</th><th>Academic Year</th><th>Period</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if(empty($exams)):?><tr><td colspan="6" class="text-center text-muted py-4">No exams created yet.</td></tr>
    <?php else:foreach($exams as $ex):$sc=$statusColors[$ex['status']]??'secondary';?>
    <tr>
      <td class="fw-semibold"><?=e($ex['name'])?></td>
      <td><?=e($ex['term']??'â€”')?></td>
      <td><?=e($ex['academic_year']??'â€”')?></td>
      <td class="small text-muted">
        <?php if($ex['start_date']):?><?=formatDate($ex['start_date'])?><?php if($ex['end_date']):?> â€“ <?=formatDate($ex['end_date'])?><?php endif;?><?php else:?>â€”<?php endif;?>
      </td>
      <td><span class="badge bg-<?=$sc?>"><?=ucfirst($ex['status'])?></span></td>
      <td class="text-end">
        <a href="exams.php?view=<?=$ex['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="fas fa-calendar-alt"></i> Schedule</a>
        <button class="btn btn-xs btn-outline-secondary me-1 btn-edit-exam"
          data-id="<?=$ex['id']?>" data-name="<?=e($ex['name'])?>" data-term="<?=e($ex['term']??'')?>"
          data-year="<?=e($ex['academic_year']??'')?>" data-start="<?=$ex['start_date']??''?>"
          data-end="<?=$ex['end_date']??''?>" data-status="<?=$ex['status']?>"
          data-desc="<?=e($ex['description']??'')?>"><i class="fas fa-edit"></i></button>
        <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="delete_exam"><input type="hidden" name="id" value="<?=$ex['id']?>">
          <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete this exam and all its schedule entries?"><i class="fas fa-trash"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
  </table>
  </div>
</div>

<?php else:?>
<!-- Exam Detail / Schedule View -->
<?php $sc=$statusColors[$viewExam['status']]??'secondary';?>
<div class="card mb-3">
  <div class="card-body">
    <div class="row align-items-center">
      <div class="col-md-6">
        <h5 class="mb-1 fw-bold"><?=e($viewExam['name'])?></h5>
        <div class="text-muted small">
          <?php if($viewExam['term']):?><span class="me-3"><i class="fas fa-calendar-check me-1"></i><?=e($viewExam['term'])?></span><?php endif;?>
          <?php if($viewExam['academic_year']):?><span class="me-3"><i class="fas fa-graduation-cap me-1"></i><?=e($viewExam['academic_year'])?></span><?php endif;?>
          <?php if($viewExam['start_date']):?><span><i class="fas fa-clock me-1"></i><?=formatDate($viewExam['start_date'])?><?php if($viewExam['end_date']):?> â€“ <?=formatDate($viewExam['end_date'])?><?php endif;?></span><?php endif;?>
        </div>
        <?php if($viewExam['description']):?><p class="text-muted small mt-1 mb-0"><?=e($viewExam['description'])?></p><?php endif;?>
      </div>
      <div class="col-md-6 text-md-end mt-2 mt-md-0">
        <span class="badge bg-<?=$sc?> fs-6 me-2"><?=ucfirst($viewExam['status'])?></span>
        <form method="POST" class="d-inline">
          <?=csrfField()?><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?=$viewExam['id']?>">
          <select name="status" class="form-select form-select-sm d-inline-block w-auto me-1" onchange="this.form.submit()">
            <?php foreach(['upcoming','ongoing','completed','cancelled'] as $st):?><option value="<?=$st?>" <?=$viewExam['status']===$st?'selected':''?>><?=ucfirst($st)?></option><?php endforeach;?>
          </select>
        </form>
        <a href="results.php?exam_id=<?=$viewExam['id']?>" class="btn btn-sm btn-outline-success"><i class="fas fa-chart-line me-1"></i>Enter Results</a>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-calendar-alt me-2" style="color:<?=$moduleColor?>"></i>Exam Schedule â€” <?=count($schedule)?> entries</h6></div>
  <div class="card-body p-0">
  <table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Class</th><th>Subject</th><th>Date</th><th>Time</th><th>Room</th><th>Max Marks</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if(empty($schedule)):?><tr><td colspan="7" class="text-center text-muted py-4">No schedule entries. Add subjects and classes for this exam.</td></tr>
    <?php else:foreach($schedule as $sl):?>
    <tr>
      <td class="fw-semibold"><?=e($sl['class_name']??'â€”')?></td>
      <td><?=e($sl['subject_name']??'â€”')?></td>
      <td><?=$sl['exam_date']?formatDate($sl['exam_date']):'â€”'?></td>
      <td class="small text-muted"><?=$sl['start_time']?date('H:i',strtotime($sl['start_time'])):'â€”'?><?=$sl['end_time']?' â€“ '.date('H:i',strtotime($sl['end_time'])):''?></td>
      <td><?=e($sl['room']??'â€”')?></td>
      <td class="fw-semibold"><?=number_format($sl['max_marks'],0)?></td>
      <td class="text-end">
        <button class="btn btn-xs btn-outline-secondary me-1 btn-edit-sched"
          data-id="<?=$sl['id']?>" data-class_id="<?=$sl['class_id']?>" data-subject_id="<?=$sl['subject_id']?>"
          data-exam_date="<?=$sl['exam_date']??''?>" data-start_time="<?=$sl['start_time']??''?>"
          data-end_time="<?=$sl['end_time']??''?>" data-room="<?=e($sl['room']??'')?>" data-max_marks="<?=$sl['max_marks']?>"><i class="fas fa-edit"></i></button>
        <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="delete_schedule"><input type="hidden" name="sched_id" value="<?=$sl['id']?>"><input type="hidden" name="exam_id" value="<?=$viewId?>">
          <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Remove this schedule entry?"><i class="fas fa-times"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
  </table>
  </div>
</div>

<!-- Schedule Modal -->
<div class="modal fade" id="schedModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i><span id="schedModalTitle">Add Schedule Entry</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save_schedule"><input type="hidden" name="exam_id" value="<?=$viewId?>"><input type="hidden" name="sched_id" id="schedId" value="0">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label fw-semibold">Class <span class="text-danger">*</span></label>
        <select name="class_id" id="schedClass" class="form-select"><option value="">â€” Select â€”</option><?php foreach($classes as $c):?><option value="<?=$c['id']?>"><?=e($c['name'])?></option><?php endforeach;?></select>
      </div>
      <div class="col-md-6"><label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
        <select name="subject_id" id="schedSubject" class="form-select"><option value="">â€” Select â€”</option><?php foreach($subjects as $s):?><option value="<?=$s['id']?>"><?=e($s['name'])?></option><?php endforeach;?></select>
      </div>
      <div class="col-md-4"><label class="form-label fw-semibold">Date</label><input type="date" name="exam_date" id="schedDate" class="form-control"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Start Time</label><input type="time" name="start_time" id="schedStart" class="form-control"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">End Time</label><input type="time" name="end_time" id="schedEnd" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Room</label><input type="text" name="room" id="schedRoom" class="form-control" placeholder="e.g. Hall A"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Max Marks</label><input type="number" name="max_marks" id="schedMax" class="form-control" value="100" min="1"></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Entry</button></div>
  </form>
</div></div></div>
<?php endif;?>

<!-- Exam Modal -->
<div class="modal fade" id="examModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-alt me-2"></i><span id="examModalTitle">Create Exam</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save_exam"><input type="hidden" name="id" id="examId" value="0">
    <div class="row g-3">
      <div class="col-12"><label class="form-label fw-semibold">Exam Name <span class="text-danger">*</span></label><input type="text" name="name" id="examName" class="form-control" placeholder="e.g. End Term Exam 1" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Term</label><input type="text" name="term" id="examTerm" class="form-control" placeholder="e.g. Term 1"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Academic Year</label><input type="text" name="academic_year" id="examYear" class="form-control" placeholder="e.g. 2025/2026"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Start Date</label><input type="date" name="start_date" id="examStart" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">End Date</label><input type="date" name="end_date" id="examEnd" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Status</label>
        <select name="status" id="examStatus" class="form-select"><option value="upcoming">Upcoming</option><option value="ongoing">Ongoing</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select>
      </div>
      <div class="col-12"><label class="form-label fw-semibold">Description</label><textarea name="description" id="examDesc" class="form-control" rows="2" placeholder="Optional notes"></textarea></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Exam</button></div>
  </form>
</div></div></div>

<?php ob_start();?>
<script>
document.querySelectorAll('.btn-edit-exam').forEach(btn=>{btn.addEventListener('click',function(){
  document.getElementById('examModalTitle').textContent='Edit Exam';
  document.getElementById('examId').value=this.dataset.id;
  document.getElementById('examName').value=this.dataset.name||'';
  document.getElementById('examTerm').value=this.dataset.term||'';
  document.getElementById('examYear').value=this.dataset.year||'';
  document.getElementById('examStart').value=this.dataset.start||'';
  document.getElementById('examEnd').value=this.dataset.end||'';
  document.getElementById('examStatus').value=this.dataset.status||'upcoming';
  document.getElementById('examDesc').value=this.dataset.desc||'';
  new bootstrap.Modal(document.getElementById('examModal')).show();
});});
document.querySelectorAll('.btn-edit-sched').forEach(btn=>{btn.addEventListener('click',function(){
  document.getElementById('schedModalTitle').textContent='Edit Schedule Entry';
  document.getElementById('schedId').value=this.dataset.id;
  document.getElementById('schedClass').value=this.dataset.class_id||'';
  document.getElementById('schedSubject').value=this.dataset.subject_id||'';
  document.getElementById('schedDate').value=this.dataset.exam_date||'';
  document.getElementById('schedStart').value=this.dataset.start_time||'';
  document.getElementById('schedEnd').value=this.dataset.end_time||'';
  document.getElementById('schedRoom').value=this.dataset.room||'';
  document.getElementById('schedMax').value=this.dataset.max_marks||100;
  new bootstrap.Modal(document.getElementById('schedModal')).show();
});});
document.querySelectorAll('.btn-confirm').forEach(btn=>{btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});});
</script>
<?php $extraJs=ob_get_clean();
require_once __DIR__.'/../../includes/footer.php';?>

