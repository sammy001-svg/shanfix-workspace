<?php
$moduleSlug='school';$moduleName='School Management';$moduleIcon='fas fa-school';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'parents.php','icon'=>'fas fa-users','label'=>'Parents'],['url'=>'staff.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Staff'],['url'=>'classes.php','icon'=>'fas fa-chalkboard','label'=>'Classes'],['url'=>'subjects.php','icon'=>'fas fa-book','label'=>'Subjects'],['url'=>'timetable.php','icon'=>'fas fa-calendar-alt','label'=>'Timetable'],['url'=>'attendance.php','icon'=>'fas fa-clipboard-check','label'=>'Attendance'],['url'=>'exams.php','icon'=>'fas fa-file-alt','label'=>'Exams'],['url'=>'results.php','icon'=>'fas fa-chart-line','label'=>'Results'],['url'=>'fees.php','icon'=>'fas fa-money-bill','label'=>'Fees'],['url'=>'library.php','icon'=>'fas fa-book-reader','label'=>'Library'],['url'=>'transport.php','icon'=>'fas fa-bus','label'=>'Transport'],['url'=>'events.php','icon'=>'fas fa-calendar-day','label'=>'Events'],['url'=>'notices.php','icon'=>'fas fa-bullhorn','label'=>'Notices'],['url'=>'grades.php','icon'=>'fas fa-star','label'=>'Grades'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';

    if($action==='save_results'){
        $examId=(int)($_POST['exam_id']??0);$classId=(int)($_POST['class_id']??0);$subjectId=(int)($_POST['subject_id']??0);
        $marks=$_POST['marks']??[];$remarks=$_POST['remarks']??[];$maxMarks=(float)($_POST['max_marks']??100);
        if(!$examId||!$classId||!$subjectId){setFlash('error','Exam, class and subject are required.');redirect('results.php');}
        // Fetch grade rules for org
        $gradeRules=[];try{$s=$pdo->prepare("SELECT * FROM sch_grades WHERE org_id=? ORDER BY min_mark DESC");$s->execute([$orgId]);$gradeRules=$s->fetchAll();}catch(Exception $e){}
        $getGrade=function($score,$max) use($gradeRules){
            if($max<=0)return'';$pct=($score/$max)*100;
            foreach($gradeRules as $g){if($pct>=(float)$g['min_mark'])return $g['grade'];}
            return '';
        };
        $pdo->beginTransaction();
        foreach($marks as $studentId=>$mark){
            $studentId=(int)$studentId;$mark=trim($mark)===''?null:(float)$mark;
            $remark=sanitize($remarks[$studentId]??'');
            $grade=$mark!==null?$getGrade($mark,$maxMarks):'';
            $pdo->prepare("INSERT INTO sch_results (org_id,exam_id,student_id,class_id,subject_id,marks,max_marks,grade,remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE marks=VALUES(marks),grade=VALUES(grade),remarks=VALUES(remarks),created_by=VALUES(created_by)")
               ->execute([$orgId,$examId,$studentId,$classId,$subjectId,$mark,$maxMarks,$grade,$remark,$user['id']]);
        }
        $pdo->commit();
        setFlash('success','Results saved.');
        redirect("results.php?exam_id=$examId&class_id=$classId&subject_id=$subjectId");
    }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fExam=(int)($_GET['exam_id']??0);$fClass=(int)($_GET['class_id']??0);$fSubject=(int)($_GET['subject_id']??0);
$reportMode=$_GET['mode']??'enter'; // enter | card

$exams=[];try{$s=$pdo->prepare("SELECT id,name,term,academic_year FROM sch_exams WHERE org_id=? ORDER BY created_at DESC");$s->execute([$orgId]);$exams=$s->fetchAll();}catch(Exception $e){}
$classes=[];try{$s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name");$s->execute([$orgId]);$classes=$s->fetchAll();}catch(Exception $e){}
$subjects=[];$maxMarks=100;
if($fExam&&$fClass){
    try{$s=$pdo->prepare("SELECT es.subject_id,es.max_marks,sub.name FROM sch_exam_schedule es JOIN sch_subjects sub ON es.subject_id=sub.id WHERE es.exam_id=? AND es.class_id=?");$s->execute([$fExam,$fClass]);$subjects=$s->fetchAll();}catch(Exception $e){}
    if($fSubject){foreach($subjects as $sub){if($sub['subject_id']==$fSubject){$maxMarks=$sub['max_marks'];break;}}}
}
if(empty($subjects)&&$fClass){
    try{$s=$pdo->prepare("SELECT id AS subject_id,100 AS max_marks,name FROM sch_subjects WHERE org_id=? AND status='active' ORDER BY name");$s->execute([$orgId]);$subjects=$s->fetchAll();}catch(Exception $e){}
}
$students=[];$existing=[];
if($fExam&&$fClass&&$fSubject){
    try{$s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name,admission_no FROM sch_students WHERE org_id=? AND class_id=? AND status='active' ORDER BY first_name");$s->execute([$orgId,$fClass]);$students=$s->fetchAll();}catch(Exception $e){}
    try{$s=$pdo->prepare("SELECT student_id,marks,grade,remarks FROM sch_results WHERE org_id=? AND exam_id=? AND class_id=? AND subject_id=?");$s->execute([$orgId,$fExam,$fClass,$fSubject]);foreach($s->fetchAll() as $r)$existing[$r['student_id']]=$r;}catch(Exception $e){}
}
// Report card: all subjects for a student
$reportCard=[];$reportStudents=[];
if($fExam&&$fClass&&$reportMode==='card'){
    try{$s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name,admission_no FROM sch_students WHERE org_id=? AND class_id=? AND status='active' ORDER BY first_name");$s->execute([$orgId,$fClass]);$reportStudents=$s->fetchAll();}catch(Exception $e){}
    try{$s=$pdo->prepare("SELECT r.*,sub.name AS subject_name FROM sch_results r JOIN sch_subjects sub ON r.subject_id=sub.id WHERE r.org_id=? AND r.exam_id=? AND r.class_id=? ORDER BY r.student_id,sub.name");$s->execute([$orgId,$fExam,$fClass]);foreach($s->fetchAll() as $r)$reportCard[$r['student_id']][]=$r;}catch(Exception $e){}
}
$gradeRules=[];try{$s=$pdo->prepare("SELECT * FROM sch_grades WHERE org_id=? ORDER BY min_mark DESC");$s->execute([$orgId]);$gradeRules=$s->fetchAll();}catch(Exception $e){}
$examName='';foreach($exams as $ex){if($ex['id']==$fExam)$examName=$ex['name'].($ex['term']?' — '.$ex['term']:'');}
$className='';foreach($classes as $c){if($c['id']==$fClass)$className=$c['name'];}
$subjectName='';foreach($subjects as $s){if($s['subject_id']==$fSubject)$subjectName=$s['name'];}
?>
<?=flashAlert()?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-chart-line me-2" style="color:<?=$moduleColor?>"></i>Exam Results</h4><p class="text-muted mb-0">Enter and view student exam results and report cards</p></div>
  <?php if($fExam&&$fClass):?>
  <div class="d-flex gap-2">
    <?php if($reportMode!=='card'):?><a href="results.php?exam_id=<?=$fExam?>&class_id=<?=$fClass?>&mode=card" class="btn btn-outline-info btn-sm"><i class="fas fa-id-card me-1"></i>Report Card</a><?php else:?><a href="results.php?exam_id=<?=$fExam?>&class_id=<?=$fClass?>&mode=enter" class="btn btn-outline-secondary btn-sm"><i class="fas fa-edit me-1"></i>Enter Marks</a><?php endif;?>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
  </div>
  <?php endif;?>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <input type="hidden" name="mode" value="<?=e($reportMode)?>">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Exam</label>
      <select name="exam_id" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">— Select Exam —</option><?php foreach($exams as $ex):?><option value="<?=$ex['id']?>" <?=$fExam==$ex['id']?'selected':''?>><?=e($ex['name'])?><?=$ex['term']?' ('.$ex['term'].')':''?></option><?php endforeach;?></select>
    </div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Class</label>
      <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">— Select Class —</option><?php foreach($classes as $c):?><option value="<?=$c['id']?>" <?=$fClass==$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select>
    </div>
    <?php if($reportMode==='enter'&&$fExam&&$fClass):?>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Subject</label>
      <select name="subject_id" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">— Select Subject —</option><?php foreach($subjects as $s):?><option value="<?=$s['subject_id']?>" <?=$fSubject==$s['subject_id']?'selected':''?>><?=e($s['name'])?></option><?php endforeach;?></select>
    </div>
    <?php endif;?>
    <div class="col-auto"><button class="btn btn-sm btn-success">Apply</button></div>
  </form>
</div></div>

<?php if(!$fExam||!$fClass):?>
<div class="text-center py-5 text-muted"><i class="fas fa-chart-line fa-3x mb-3 d-block"></i>Select an exam and class to enter or view results.</div>

<?php elseif($reportMode==='card'):?>
<!-- Report Card View -->
<?php if(empty($reportStudents)):?>
<div class="text-center py-5 text-muted"><i class="fas fa-users fa-3x mb-3 d-block"></i>No active students in this class.</div>
<?php else:foreach($reportStudents as $st):
  $rows=$reportCard[$st['id']]??[];$total=0;$maxTotal=0;$count=0;
  foreach($rows as $r){if($r['marks']!==null){$total+=$r['marks'];$maxTotal+=$r['max_marks'];$count++;}}
  $pct=$maxTotal>0?round(100*$total/$maxTotal):0;$pctC=$pct>=90?'success':($pct>=75?'primary':($pct>=60?'warning':'danger'));
?>
<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center" style="background:<?=$moduleColor?>15;border-left:4px solid <?=$moduleColor?>">
    <div>
      <h6 class="mb-0 fw-bold"><?=e($st['name'])?></h6>
      <small class="text-muted"><?=e($st['admission_no']??'')?> &bull; <?=e($className)?> &bull; <?=e($examName)?></small>
    </div>
    <div class="text-end">
      <span class="badge bg-<?=$pctC?> fs-6"><?=$pct?>%</span>
      <div class="small text-muted mt-1"><?=$total?>/<?=$maxTotal?> total marks</div>
    </div>
  </div>
  <div class="card-body p-0">
  <table class="table table-sm mb-0">
    <thead class="table-light"><tr><th>Subject</th><th class="text-center">Max</th><th class="text-center">Score</th><th class="text-center">%</th><th class="text-center">Grade</th><th>Remarks</th></tr></thead>
    <tbody>
    <?php if(empty($rows)):?><tr><td colspan="6" class="text-center text-muted py-3">No results recorded.</td></tr>
    <?php else:foreach($rows as $r):$p=$r['max_marks']>0?round(100*$r['marks']/$r['max_marks']):0;$pc=$p>=90?'success':($p>=75?'primary':($p>=60?'warning':'danger'));?>
    <tr>
      <td><?=e($r['subject_name'])?></td>
      <td class="text-center"><?=number_format($r['max_marks'],0)?></td>
      <td class="text-center fw-semibold"><?=$r['marks']!==null?number_format($r['marks'],1):'—'?></td>
      <td class="text-center"><span class="badge bg-<?=$pc?>"><?=$p?>%</span></td>
      <td class="text-center"><span class="fw-bold" style="color:<?=$moduleColor?>"><?=e($r['grade']??'—')?></span></td>
      <td class="small text-muted"><?=e($r['remarks']??'')?></td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach;endif;?>

<?php elseif(!$fSubject):?>
<div class="text-center py-5 text-muted"><i class="fas fa-book fa-3x mb-3 d-block"></i>Select a subject to enter marks.</div>

<?php else:?>
<!-- Enter Marks -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-pencil-alt me-2" style="color:<?=$moduleColor?>"></i>Enter Marks — <?=e($subjectName)?> | <?=e($className)?></h6>
    <span class="badge bg-secondary">Max: <?=number_format($maxMarks,0)?> marks</span>
  </div>
  <div class="card-body p-0">
  <?php if(empty($students)):?><div class="text-center py-4 text-muted">No active students in this class.</div>
  <?php else:?>
  <form method="POST">
    <?=csrfField()?><input type="hidden" name="action" value="save_results">
    <input type="hidden" name="exam_id" value="<?=$fExam?>"><input type="hidden" name="class_id" value="<?=$fClass?>">
    <input type="hidden" name="subject_id" value="<?=$fSubject?>"><input type="hidden" name="max_marks" value="<?=$maxMarks?>">
    <table class="table table-hover mb-0">
      <thead class="table-light"><tr><th>#</th><th>Student</th><th>Adm No</th><th style="width:120px">Marks (<?=number_format($maxMarks,0)?>)</th><th style="width:80px">Grade</th><th>Remarks</th></tr></thead>
      <tbody>
      <?php foreach($students as $i=>$st):
        $cur=$existing[$st['id']]??['marks'=>'','grade'=>'','remarks'=>''];?>
      <tr>
        <td class="text-muted"><?=$i+1?></td>
        <td class="fw-semibold"><?=e($st['name'])?></td>
        <td class="small text-muted"><?=e($st['admission_no']??'—')?></td>
        <td><input type="number" name="marks[<?=$st['id']?>]" class="form-control form-control-sm mark-input"
          data-max="<?=$maxMarks?>" data-student="<?=$st['id']?>"
          value="<?=$cur['marks']!==null&&$cur['marks']!==''?e($cur['marks']):'?>''" min="0" max="<?=$maxMarks?>" step="0.5" placeholder="—"></td>
        <td><span class="badge bg-secondary grade-badge" id="grade_<?=$st['id']?>"><?=e($cur['grade']??'—')?></span></td>
        <td><input type="text" name="remarks[<?=$st['id']?>]" class="form-control form-control-sm" value="<?=e($cur['remarks']??'')?>" placeholder="Optional"></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
    <div class="p-3 border-top"><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>"><i class="fas fa-save me-2"></i>Save Results</button></div>
  </form>
  <?php endif;?>
  </div>
</div>
<?php endif;?>

<?php ob_start();?>
<script>
var gradeRules=<?=json_encode(array_map(fn($g)=>['min'=>(float)$g['min_mark'],'grade'=>$g['grade']],$gradeRules))?>;
function getGrade(score,max){
  if(max<=0||score==='')return'—';
  var pct=(parseFloat(score)/parseFloat(max))*100;
  for(var i=0;i<gradeRules.length;i++){if(pct>=gradeRules[i].min)return gradeRules[i].grade;}
  return'—';
}
document.querySelectorAll('.mark-input').forEach(inp=>{
  inp.addEventListener('input',function(){
    var badge=document.getElementById('grade_'+this.dataset.student);
    if(badge)badge.textContent=getGrade(this.value,this.dataset.max);
  });
});
</script>
<?php $extraJs=ob_get_clean();
require_once __DIR__.'/../../includes/footer.php';?>
