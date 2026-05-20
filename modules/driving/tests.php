<?php
$moduleSlug='driving';$moduleName='Driving School';$moduleIcon='fas fa-car';$moduleColor='#1a237e';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'instructors.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Instructors'],['url'=>'vehicles.php','icon'=>'fas fa-car','label'=>'Vehicles'],['url'=>'classes.php','icon'=>'fas fa-calendar-alt','label'=>'Classes'],['url'=>'lessons.php','icon'=>'fas fa-road','label'=>'Lessons'],['url'=>'tests.php','icon'=>'fas fa-clipboard-check','label'=>'Tests'],['url'=>'licenses.php','icon'=>'fas fa-id-card','label'=>'Licenses'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_once __DIR__.'/../../config/database.php'; require_once __DIR__.'/../../includes/functions.php';
    if (session_status()===PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user=currentUser(); $orgId=(int)$user['org_id']; $action=$_POST['action']??'';
    if ($action==='save') {
        $id     =(int)($_POST['id']??0);
        $stId   =(int)($_POST['student_id']??0);
        $instId =(int)($_POST['instructor_id']??0)?:null;
        $vehId  =(int)($_POST['vehicle_id']??0)?:null;
        $tDate  =$_POST['test_date']??date('Y-m-d');
        $tTime  =$_POST['test_time']??null;
        $tt     =in_array($_POST['test_type']??'',['theory','practical','both'])?$_POST['test_type']:'practical';
        $st     =in_array($_POST['status']??'',['scheduled','passed','failed','cancelled'])?$_POST['status']:'scheduled';
        $score  =$_POST['score']!==''?(float)$_POST['score']:null;
        $pass   =(float)($_POST['pass_mark']??70);
        $remarks=sanitize($_POST['remarks']??'');
        // Auto-set status based on score
        if ($score !== null && $st === 'scheduled') {
            $st = $score >= $pass ? 'passed' : 'failed';
        }
        if ($id>0) {
            $pdo->prepare("UPDATE driving_tests SET student_id=?,instructor_id=?,vehicle_id=?,test_date=?,test_time=?,test_type=?,status=?,score=?,pass_mark=?,remarks=? WHERE id=? AND org_id=?")
                ->execute([$stId,$instId,$vehId,$tDate,$tTime?:null,$tt,$st,$score,$pass,$remarks,$id,$orgId]);
            setFlash('success','Test updated.');
        } else {
            $pdo->prepare("INSERT INTO driving_tests(org_id,student_id,instructor_id,vehicle_id,test_date,test_time,test_type,status,score,pass_mark,remarks)VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$stId,$instId,$vehId,$tDate,$tTime?:null,$tt,$st,$score,$pass,$remarks]);
            setFlash('success','Test scheduled.');
        }
        logActivity($id>0?'update':'create','driving',"Test for student #$stId"); redirect('tests.php');
    }
    if ($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM driving_tests WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Test deleted.');redirect('tests.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fSt=$_GET['status']??'';$fTt=$_GET['test_type']??'';
$where='t.org_id=?';$params=[$orgId];
if($fSt){$where.=' AND t.status=?';$params[]=$fSt;}
if($fTt){$where.=' AND t.test_type=?';$params[]=$fTt;}
$tests=[];
try{$s=$pdo->prepare("SELECT t.*,CONCAT(st.first_name,' ',st.last_name) AS student_name,i.name AS instructor_name,v.name AS vehicle_name,v.number_plate FROM driving_tests t JOIN driving_students st ON t.student_id=st.id LEFT JOIN driving_instructors i ON t.instructor_id=i.id LEFT JOIN driving_vehicles v ON t.vehicle_id=v.id WHERE $where ORDER BY t.test_date DESC,t.test_time DESC");$s->execute($params);$tests=$s->fetchAll();}catch(Exception $e){}
$students=[];try{$s=$pdo->prepare("SELECT id,first_name,last_name FROM driving_students WHERE org_id=? AND status='active' ORDER BY first_name");$s->execute([$orgId]);$students=$s->fetchAll();}catch(Exception $e){}
$instructors=[];try{$s=$pdo->prepare("SELECT id,name FROM driving_instructors WHERE org_id=? AND status='active' ORDER BY name");$s->execute([$orgId]);$instructors=$s->fetchAll();}catch(Exception $e){}
$vehicles=[];try{$s=$pdo->prepare("SELECT id,name,number_plate FROM driving_vehicles WHERE org_id=? AND status='active' ORDER BY name");$s->execute([$orgId]);$vehicles=$s->fetchAll();}catch(Exception $e){}
$total=countRows('driving_tests','org_id=?',[$orgId]);
$sched=countRows('driving_tests','org_id=? AND status=?',[$orgId,'scheduled']);
$passed=countRows('driving_tests','org_id=? AND status=?',[$orgId,'passed']);
$failed=countRows('driving_tests','org_id=? AND status=?',[$orgId,'failed']);
$statusColors=['scheduled'=>'primary','passed'=>'success','failed'=>'danger','cancelled'=>'secondary'];
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-clipboard-check me-2" style="color:<?=$moduleColor?>"></i>Driving Tests</h4><p class="text-muted mb-0">Schedule tests, record scores and track student readiness</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#tModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Schedule Test</button>
</div>
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-clipboard-list"></i></div><div class="stat-body"><div class="stat-value"><?=$total?></div><div class="stat-label">Total Tests</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#cce5ff;color:#004085"><i class="fas fa-calendar-check"></i></div><div class="stat-body"><div class="stat-value"><?=$sched?></div><div class="stat-label">Scheduled</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-trophy"></i></div><div class="stat-body"><div class="stat-value"><?=$passed?></div><div class="stat-label">Passed</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#f8d7da;color:#721c24"><i class="fas fa-times-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$failed?></div><div class="stat-label">Failed</div></div></div></div>
</div>
<?php if ($total > 0 && ($passed+$failed) > 0): $pRate=round($passed/($passed+$failed)*100); ?>
<div class="alert alert-info d-flex align-items-center gap-3 mb-4">
  <i class="fas fa-chart-pie fa-lg"></i>
  <span>Pass rate: <strong><?=$pRate?>%</strong> (<?=$passed?> passed of <?=$passed+$failed?> completed tests)</span>
</div>
<?php endif; ?>
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach(['scheduled','passed','failed','cancelled'] as $s):?><option value="<?=$s?>" <?=$fSt===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Test Type</label><select name="test_type" class="form-select form-select-sm"><option value="">All</option><?php foreach(['theory','practical','both'] as $t):?><option value="<?=$t?>" <?=$fTt===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach;?></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="tests.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card"><div class="card-header d-flex align-items-center justify-content-between"><h6 class="mb-0"><i class="fas fa-clipboard-check me-2" style="color:<?=$moduleColor?>"></i>Test Records</h6><span class="badge bg-secondary"><?=count($tests)?> tests</span></div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Student</th><th>Date &amp; Time</th><th>Type</th><th>Instructor</th><th>Vehicle</th><th>Score</th><th>Pass Mark</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($tests)):?>
<tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-clipboard-check fa-2x mb-2 d-block"></i>No tests found.</td></tr>
<?php else:foreach($tests as $t):
  $scoreClass = $t['score'] !== null ? ((float)$t['score'] >= (float)$t['pass_mark'] ? 'text-success' : 'text-danger') : '';
?>
<tr>
  <td class="fw-semibold"><?=e($t['student_name'])?></td>
  <td><?=formatDate($t['test_date'])?><?php if($t['test_time']):?><br><small class="text-muted"><?=htmlspecialchars(substr($t['test_time'],0,5))?></small><?php endif;?></td>
  <td><span class="badge bg-info text-dark"><?=ucfirst($t['test_type'])?></span></td>
  <td class="small"><?=e($t['instructor_name']??'—')?></td>
  <td class="small"><?=e($t['vehicle_name']??'—')?><?php if($t['number_plate']):?><br><span class="badge bg-dark"><?=e($t['number_plate'])?></span><?php endif;?></td>
  <td><span class="fw-bold <?=$scoreClass?>"><?=$t['score']!==null?(float)$t['score'].'%':'—'?></span></td>
  <td class="small text-muted"><?=(float)$t['pass_mark']?>%</td>
  <td><span class="badge bg-<?=$statusColors[$t['status']]??'secondary'?>"><?=ucfirst($t['status'])?></span></td>
  <td class="text-center" style="white-space:nowrap">
    <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($t),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
    <button class="btn btn-sm btn-outline-danger ms-1" onclick="delTest(<?=$t['id']?>,'<?=e($t['student_name'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<!-- Modal -->
<div class="modal fade" id="tModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="tId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="tTitle"><i class="fas fa-clipboard-check me-2"></i>Schedule Test</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">Student <span class="text-danger">*</span></label><select name="student_id" id="tStudent" class="form-select" required><option value="">— Select —</option><?php foreach($students as $st):?><option value="<?=$st['id']?>"><?=e($st['first_name'].' '.$st['last_name'])?></option><?php endforeach;?></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Instructor</label><select name="instructor_id" id="tInstructor" class="form-select"><option value="">— None —</option><?php foreach($instructors as $i):?><option value="<?=$i['id']?>"><?=e($i['name'])?></option><?php endforeach;?></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Vehicle</label><select name="vehicle_id" id="tVehicle" class="form-select"><option value="">— None —</option><?php foreach($vehicles as $v):?><option value="<?=$v['id']?>"><?=e($v['name'].' ('.$v['number_plate'].')')?></option><?php endforeach;?></select></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Test Date <span class="text-danger">*</span></label><input type="date" name="test_date" id="tDate" class="form-control" value="<?=date('Y-m-d')?>" required></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Test Time</label><input type="time" name="test_time" id="tTime" class="form-control"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Test Type</label><select name="test_type" id="tType" class="form-select"><option value="theory">Theory</option><option value="practical" selected>Practical</option><option value="both">Both</option></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="tStatus" class="form-select"><option value="scheduled">Scheduled</option><option value="passed">Passed</option><option value="failed">Failed</option><option value="cancelled">Cancelled</option></select></div>
    <div class="col-md-2"><label class="form-label fw-semibold">Score (%)</label><input type="number" name="score" id="tScore" class="form-control" step="0.1" min="0" max="100" placeholder="e.g. 85"></div>
    <div class="col-md-2"><label class="form-label fw-semibold">Pass Mark (%)</label><input type="number" name="pass_mark" id="tPass" class="form-control" step="0.1" min="0" max="100" value="70"></div>
    <div class="col-12"><label class="form-label fw-semibold">Remarks / Evaluation Notes</label><textarea name="remarks" id="tRemarks" class="form-control" rows="3" placeholder="Examiner's observations and recommendations…"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Test</button></div>
  </form>
</div></div></div>
<form method="POST" id="delTForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delTId"></form>
<?php $extraJs=<<<'JS'
<script>
function openAdd(){document.getElementById('tTitle').innerHTML='<i class="fas fa-clipboard-check me-2"></i>Schedule Test';['tId','tScore','tRemarks','tTime'].forEach(i=>document.getElementById(i).value=i==='tId'?'0':'');document.getElementById('tStudent').value='';document.getElementById('tInstructor').value='';document.getElementById('tVehicle').value='';document.getElementById('tDate').value=new Date().toISOString().substring(0,10);document.getElementById('tType').value='practical';document.getElementById('tStatus').value='scheduled';document.getElementById('tPass').value=70;}
function openEdit(t){document.getElementById('tTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Test';document.getElementById('tId').value=t.id;document.getElementById('tStudent').value=t.student_id||'';document.getElementById('tInstructor').value=t.instructor_id||'';document.getElementById('tVehicle').value=t.vehicle_id||'';document.getElementById('tDate').value=t.test_date?t.test_date.substring(0,10):'';document.getElementById('tTime').value=t.test_time?t.test_time.substring(0,5):'';document.getElementById('tType').value=t.test_type||'practical';document.getElementById('tStatus').value=t.status||'scheduled';document.getElementById('tScore').value=t.score!==null?t.score:'';document.getElementById('tPass').value=t.pass_mark||70;document.getElementById('tRemarks').value=t.remarks||'';new bootstrap.Modal(document.getElementById('tModal')).show();}
function delTest(id,name){Swal.fire({title:'Delete Test?',text:name+"'s test will be removed.",icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delTId').value=id;document.getElementById('delTForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
