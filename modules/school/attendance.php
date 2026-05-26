<?php
require_once __DIR__ . '/_nav.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';
    if($action==='save_bulk'){
        $classId=(int)($_POST['class_id']??0);$attDate=sanitize($_POST['att_date']??date('Y-m-d'));
        $statuses=$_POST['statuses']??[];$remarks=$_POST['remarks']??[];
        if(!$classId){setFlash('error','Select a class.');redirect('attendance.php');}
        $pdo->beginTransaction();
        foreach($statuses as $studentId=>$status){
            $studentId=(int)$studentId;
            $validStatus=in_array($status,['present','absent','late','excused'])?$status:'present';
            $remark=sanitize($remarks[$studentId]??'');
            $pdo->prepare("INSERT INTO sch_attendance (org_id,student_id,class_id,att_date,status,remarks,recorded_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),remarks=VALUES(remarks),recorded_by=VALUES(recorded_by)")
               ->execute([$orgId,$studentId,$classId,$attDate,$validStatus,$remark,$user['id']]);
        }
        $pdo->commit();
        setFlash('success','Attendance saved for '.formatDate($attDate).'.');
        redirect("attendance.php?class_id=$classId&att_date=$attDate");
    }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fClass=(int)($_GET['class_id']??0);$fDate=$_GET['att_date']??date('Y-m-d');
$fFrom=$_GET['date_from']??date('Y-m-01');$fTo=$_GET['date_to']??date('Y-m-d');$mode=$_GET['mode']??'mark';
$classes=[];try{$s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name");$s->execute([$orgId]);$classes=$s->fetchAll();}catch(Exception $e){}
$students=[];$existing=[];
if($fClass){
    try{$s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name,admission_no FROM sch_students WHERE org_id=? AND class_id=? AND status='active' ORDER BY first_name");$s->execute([$orgId,$fClass]);$students=$s->fetchAll();}catch(Exception $e){}
    try{$s=$pdo->prepare("SELECT student_id,status,remarks FROM sch_attendance WHERE org_id=? AND class_id=? AND att_date=?");$s->execute([$orgId,$fClass,$fDate]);foreach($s->fetchAll() as $r){$existing[$r['student_id']]=$r;}}catch(Exception $e){}
}
// Summary stats for selected class/period
$summary=[];$absences=[];
if($fClass&&$mode==='view'){
    try{$s=$pdo->prepare("SELECT student_id,SUM(status='present') AS p,SUM(status='absent') AS a,SUM(status='late') AS l,SUM(status='excused') AS e,COUNT(*) AS tot FROM sch_attendance WHERE org_id=? AND class_id=? AND att_date BETWEEN ? AND ? GROUP BY student_id");$s->execute([$orgId,$fClass,$fFrom,$fTo]);$summary=$s->fetchAll(PDO::FETCH_UNIQUE);}catch(Exception $e){}
    try{$s=$pdo->prepare("SELECT a.att_date,COUNT(*) AS cnt FROM sch_attendance a WHERE a.org_id=? AND a.class_id=? AND a.status='absent' AND a.att_date BETWEEN ? AND ? GROUP BY a.att_date ORDER BY a.att_date DESC LIMIT 30");$s->execute([$orgId,$fClass,$fFrom,$fTo]);$absences=$s->fetchAll();}catch(Exception $e){}
}
$totalStudents=$fClass?count($students):0;
$todayPresent=0;$todayAbsent=0;
if($fClass){
    try{$s=$pdo->prepare("SELECT SUM(status='present') AS p,SUM(status='absent') AS a FROM sch_attendance WHERE org_id=? AND class_id=? AND att_date=?");$s->execute([$orgId,$fClass,$fDate]);$r=$s->fetch();$todayPresent=(int)($r['p']??0);$todayAbsent=(int)($r['a']??0);}catch(Exception $e){}
}
$statusColors=['present'=>'success','absent'=>'danger','late'=>'warning','excused'=>'info'];
?>
<?=flashAlert()?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-clipboard-check me-2" style="color:<?=$moduleColor?>"></i>Attendance</h4><p class="text-muted mb-0">Daily student attendance tracking and reporting</p></div>
</div>

<!-- Controls -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Class</label>
      <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">â€” Select class â€”</option><?php foreach($classes as $c):?><option value="<?=$c['id']?>" <?=$fClass==$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select>
    </div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Mode</label>
      <select name="mode" class="form-select form-select-sm" onchange="this.form.submit()"><option value="mark" <?=$mode==='mark'?'selected':''?>>Mark Attendance</option><option value="view" <?=$mode==='view'?'selected':''?>>View Report</option></select>
    </div>
    <?php if($mode==='mark'):?>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Date</label><input type="date" name="att_date" class="form-control form-control-sm" value="<?=e($fDate)?>" max="<?=date('Y-m-d')?>"></div>
    <?php else:?>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">From</label><input type="date" name="date_from" class="form-control form-control-sm" value="<?=e($fFrom)?>"></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">To</label><input type="date" name="date_to" class="form-control form-control-sm" value="<?=e($fTo)?>"></div>
    <?php endif;?>
    <div class="col-auto"><button class="btn btn-sm btn-success">Apply</button></div>
  </form>
</div></div>

<?php if(!$fClass):?>
<div class="text-center py-5 text-muted"><i class="fas fa-clipboard-check fa-3x mb-3 d-block"></i>Select a class to mark or view attendance.</div>
<?php elseif($mode==='mark'):?>
<!-- KPI row -->
<div class="row g-3 mb-3">
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value"><?=$totalStudents?></div><div class="stat-label">Total Students</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check"></i></div><div class="stat-body"><div class="stat-value"><?=$todayPresent?></div><div class="stat-label">Present Today</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-times"></i></div><div class="stat-body"><div class="stat-value"><?=$todayAbsent?></div><div class="stat-label">Absent Today</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-percent"></i></div><div class="stat-body"><div class="stat-value"><?=$totalStudents>0?round(100*$todayPresent/$totalStudents):0?>%</div><div class="stat-label">Attendance Rate</div></div></div></div>
</div>
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-clipboard-check me-2" style="color:<?=$moduleColor?>"></i>Mark Attendance â€” <?=formatDate($fDate)?></h6>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-success" onclick="markAll('present')">All Present</button>
      <button type="button" class="btn btn-sm btn-outline-danger" onclick="markAll('absent')">All Absent</button>
    </div>
  </div>
  <div class="card-body p-0">
    <?php if(empty($students)):?><div class="text-center py-4 text-muted">No active students in this class.</div>
    <?php else:?>
    <form method="POST">
      <?=csrfField()?><input type="hidden" name="action" value="save_bulk"><input type="hidden" name="class_id" value="<?=$fClass?>"><input type="hidden" name="att_date" value="<?=e($fDate)?>">
      <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th style="width:40px">#</th><th>Student</th><th>Adm No</th><th style="width:300px">Status</th><th>Remarks</th></tr></thead>
        <tbody>
        <?php foreach($students as $i=>$st):
          $cur=$existing[$st['id']]??['status'=>'present','remarks'=>''];
        ?>
        <tr>
          <td class="text-muted"><?=$i+1?></td>
          <td class="fw-semibold"><?=e($st['name'])?></td>
          <td class="small text-muted"><?=e($st['admission_no']??'â€”')?></td>
          <td>
            <div class="d-flex gap-1 att-radio-group" data-student="<?=$st['id']?>">
              <?php foreach(['present'=>'Present','absent'=>'Absent','late'=>'Late','excused'=>'Excused'] as $val=>$lbl):
                $checked=$cur['status']===$val?'checked':'';$btnC=['present'=>'success','absent'=>'danger','late'=>'warning','excused'=>'info'][$val];?>
              <div class="form-check form-check-inline mb-0">
                <input class="form-check-input att-status" type="radio" name="statuses[<?=$st['id']?>]" value="<?=$val?>" <?=$checked?> id="st<?=$st['id']?>_<?=$val?>">
                <label class="form-check-label small badge bg-<?=$btnC?>" for="st<?=$st['id']?>_<?=$val?>" style="cursor:pointer"><?=$lbl?></label>
              </div>
              <?php endforeach;?>
            </div>
          </td>
          <td><input type="text" name="remarks[<?=$st['id']?>]" class="form-control form-control-sm" value="<?=e($cur['remarks']??'')?>" placeholder="Optional"></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
      <div class="p-3 border-top"><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>"><i class="fas fa-save me-2"></i>Save Attendance</button></div>
    </form>
    <?php endif;?>
  </div>
</div>

<?php else: // VIEW mode ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?=$moduleColor?>"></i>Attendance Summary â€” <?=formatDate($fFrom)?> to <?=formatDate($fTo)?></h6></div>
    <div class="card-body p-0"><table class="table table-hover data-table mb-0">
      <thead class="table-light"><tr><th>Student</th><th class="text-center">Days</th><th class="text-center text-success">Present</th><th class="text-center text-danger">Absent</th><th class="text-center text-warning">Late</th><th class="text-center text-info">Excused</th><th class="text-center">Rate</th></tr></thead>
      <tbody>
      <?php if(empty($students)):?><tr><td colspan="7" class="text-center text-muted py-4">No students.</td></tr>
      <?php else:foreach($students as $st):
        $s=$summary[$st['id']]??['p'=>0,'a'=>0,'l'=>0,'e'=>0,'tot'=>0];
        $rate=$s['tot']>0?round(100*$s['p']/$s['tot']):0;
        $rateC=$rate>=90?'success':($rate>=75?'warning':'danger');
      ?>
      <tr>
        <td class="fw-semibold"><?=e($st['name'])?></td>
        <td class="text-center"><?=$s['tot']?></td>
        <td class="text-center text-success fw-semibold"><?=$s['p']?></td>
        <td class="text-center text-danger fw-semibold"><?=$s['a']?></td>
        <td class="text-center text-warning fw-semibold"><?=$s['l']?></td>
        <td class="text-center text-info fw-semibold"><?=$s['e']?></td>
        <td class="text-center"><span class="badge bg-<?=$rateC?>"><?=$rate?>%</span></td>
      </tr>
      <?php endforeach;endif;?>
      </tbody>
    </table></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-calendar-times me-2 text-danger"></i>Days with Absences</h6></div>
    <div class="card-body p-0"><table class="table table-hover mb-0 small">
      <thead class="table-light"><tr><th>Date</th><th class="text-center">Absent Count</th></tr></thead>
      <tbody>
      <?php if(empty($absences)):?><tr><td colspan="2" class="text-center text-muted py-3">No absences recorded.</td></tr>
      <?php else:foreach($absences as $ab):?>
      <tr><td><?=formatDate($ab['att_date'])?></td><td class="text-center"><span class="badge bg-danger"><?=$ab['cnt']?></span></td></tr>
      <?php endforeach;endif;?>
      </tbody>
    </table></div></div>
  </div>
</div>
<?php endif;?>

<?php $extraJs=<<<JS
<script>
function markAll(status){
  document.querySelectorAll('input[type=radio]').forEach(r=>{if(r.value===status)r.checked=true;});
}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>

