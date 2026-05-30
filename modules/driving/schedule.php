<?php
$moduleSlug='driving';$moduleName='Driving School';$moduleIcon='fas fa-car-side';$moduleColor='#1a237e';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'instructors.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Instructors'],['url'=>'vehicles.php','icon'=>'fas fa-car','label'=>'Vehicles'],['url'=>'classes.php','icon'=>'fas fa-calendar-alt','label'=>'Classes'],['url'=>'lessons.php','icon'=>'fas fa-road','label'=>'Lessons'],['url'=>'tests.php','icon'=>'fas fa-clipboard-check','label'=>'Tests'],['url'=>'licenses.php','icon'=>'fas fa-id-card','label'=>'Licenses'],['url'=>'schedule.php','icon'=>'fas fa-calendar-week','label'=>'Schedule'],['url'=>'payments.php','icon'=>'fas fa-money-bill','label'=>'Payments'],['url'=>'certificates.php','icon'=>'fas fa-certificate','label'=>'Certificates'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if (session_status()===PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug); $user=currentUser(); $orgId=(int)$user['org_id']; $action=$_POST['action']??'';

    if ($action==='save') {
        $id         =(int)($_POST['id']??0);
        $studentId  =(int)($_POST['student_id']??0);
        $instructorId=(int)($_POST['instructor_id']??0)?:null;
        $vehicleId  =(int)($_POST['vehicle_id']??0)?:null;
        $schedDate  =$_POST['schedule_date']??null;
        $startTime  =$_POST['start_time']??null;
        $endTime    =$_POST['end_time']??null;
        $type       =in_array($_POST['type']??'',['practical','theory','test','simulation'])?$_POST['type']:'practical';
        $location   =sanitize($_POST['location']??'');
        $notes      =sanitize($_POST['notes']??'');
        $status     =in_array($_POST['status']??'',['scheduled','confirmed','completed','cancelled','no_show'])?$_POST['status']:'scheduled';
        if (!$studentId) { setFlash('error','Student is required.'); redirect('schedule.php'); }
        if ($id>0) {
            $pdo->prepare("UPDATE driving_schedule SET student_id=?,instructor_id=?,vehicle_id=?,schedule_date=?,start_time=?,end_time=?,type=?,location=?,notes=?,status=? WHERE id=? AND org_id=?")
                ->execute([$studentId,$instructorId,$vehicleId,$schedDate,$startTime,$endTime,$type,$location,$notes,$status,$id,$orgId]);
            setFlash('success','Schedule updated.');
        } else {
            $pdo->prepare("INSERT INTO driving_schedule(org_id,student_id,instructor_id,vehicle_id,schedule_date,start_time,end_time,type,location,notes,status)VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$studentId,$instructorId,$vehicleId,$schedDate,$startTime,$endTime,$type,$location,$notes,$status]);
            setFlash('success','Session scheduled.');
        }
        logActivity($id>0?'update':'create','driving',"Schedule: student#$studentId on $schedDate");
        redirect('schedule.php');
    }
    if ($action==='update_status') {
        $id=(int)($_POST['id']??0);
        $st=in_array($_POST['status']??'',['scheduled','confirmed','completed','cancelled','no_show'])?$_POST['status']:'scheduled';
        $pdo->prepare("UPDATE driving_schedule SET status=? WHERE id=? AND org_id=?")->execute([$st,$id,$orgId]);
        setFlash('success','Status updated.'); redirect('schedule.php');
    }
    if ($action==='delete') {
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM driving_schedule WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Entry deleted.'); redirect('schedule.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser(); $orgId=(int)$user['org_id'];

$fDate  =$_GET['date']??'';
$fInst  =(int)($_GET['instructor_id']??0);
$fType  =$_GET['type']??'';
$fStatus=$_GET['status']??'';
$today  =date('Y-m-d');

$where='s.org_id=?'; $params=[$orgId];
if ($fDate)   { $where.=' AND s.schedule_date=?'; $params[]=$fDate; }
if ($fInst)   { $where.=' AND s.instructor_id=?'; $params[]=$fInst; }
if ($fType)   { $where.=' AND s.type=?'; $params[]=$fType; }
if ($fStatus) { $where.=' AND s.status=?'; $params[]=$fStatus; }

$sessions=[];
try {
    $s=$pdo->prepare("
        SELECT s.*,
               CONCAT(st.first_name,' ',st.last_name) AS student_name,
               i.name AS instructor_name,
               v.name AS vehicle_name
        FROM driving_schedule s
        LEFT JOIN driving_students st ON s.student_id=st.id
        LEFT JOIN driving_instructors i ON s.instructor_id=i.id
        LEFT JOIN driving_vehicles v ON s.vehicle_id=v.id
        WHERE $where
        ORDER BY s.schedule_date DESC, s.start_time DESC
    ");
    $s->execute($params); $sessions=$s->fetchAll();
} catch (Exception $e) {}

$students=[]; $instructors=[]; $vehicles=[];
try { $s=$pdo->prepare("SELECT id,first_name,last_name FROM driving_students WHERE org_id=? AND status='active' ORDER BY first_name,last_name"); $s->execute([$orgId]); $students=$s->fetchAll(); } catch (Exception $e) {}
try { $s=$pdo->prepare("SELECT id,name FROM driving_instructors WHERE org_id=? AND status='active' ORDER BY name"); $s->execute([$orgId]); $instructors=$s->fetchAll(); } catch (Exception $e) {}
try { $s=$pdo->prepare("SELECT id,name FROM driving_vehicles WHERE org_id=? AND status='active' ORDER BY name"); $s->execute([$orgId]); $vehicles=$s->fetchAll(); } catch (Exception $e) {}

$todayCount=0; $weekCount=0; $confirmedCount=0; $completedCount=0;
try {
    $s=$pdo->prepare("SELECT COUNT(*) FROM driving_schedule WHERE org_id=? AND schedule_date=?"); $s->execute([$orgId,$today]); $todayCount=(int)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) FROM driving_schedule WHERE org_id=? AND YEARWEEK(schedule_date,1)=YEARWEEK(?,1)"); $s->execute([$orgId,$today]); $weekCount=(int)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) FROM driving_schedule WHERE org_id=? AND status='confirmed'"); $s->execute([$orgId]); $confirmedCount=(int)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) FROM driving_schedule WHERE org_id=? AND status='completed'"); $s->execute([$orgId]); $completedCount=(int)$s->fetchColumn();
} catch (Exception $e) {}

$typeColors=['practical'=>'primary','theory'=>'info','test'=>'warning','simulation'=>'secondary'];
$statusColors=['scheduled'=>'secondary','confirmed'=>'primary','completed'=>'success','cancelled'=>'danger','no_show'=>'dark'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-week me-2" style="color:<?=$moduleColor?>"></i>Schedule</h4>
    <p class="text-muted mb-0">Manage driving session timetable for students</p>
  </div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#schModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Schedule Session
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-calendar-day"></i></div><div class="stat-body"><div class="stat-value"><?=$todayCount?></div><div class="stat-label">Today's Sessions</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-calendar-week"></i></div><div class="stat-body"><div class="stat-value"><?=$weekCount?></div><div class="stat-label">This Week</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#e3f2fd;color:<?=$moduleColor?>"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$confirmedCount?></div><div class="stat-label">Confirmed</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-road"></i></div><div class="stat-body"><div class="stat-value"><?=$completedCount?></div><div class="stat-label">Completed</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Date</label>
      <input type="date" name="date" class="form-control form-control-sm" value="<?=e($fDate)?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Instructor</label>
      <select name="instructor_id" class="form-select form-select-sm">
        <option value="">All Instructors</option>
        <?php foreach($instructors as $i):?><option value="<?=$i['id']?>" <?=$fInst==$i['id']?'selected':''?>><?=e($i['name'])?></option><?php endforeach;?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Type</label>
      <select name="type" class="form-select form-select-sm">
        <option value="">All Types</option>
        <?php foreach(['practical','theory','test','simulation'] as $t):?><option value="<?=$t?>" <?=$fType===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach;?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <?php foreach(['scheduled','confirmed','completed','cancelled','no_show'] as $s):?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucwords(str_replace('_',' ',$s))?></option><?php endforeach;?>
      </select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="schedule.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-calendar-week me-2" style="color:<?=$moduleColor?>"></i>Sessions</h6>
    <span class="badge bg-secondary"><?=count($sessions)?> records</span>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Date</th><th>Time</th><th>Student</th><th>Instructor</th><th>Vehicle</th><th>Type</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($sessions)):?>
      <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-calendar-week fa-2x mb-2 d-block"></i>No sessions found.</td></tr>
    <?php else: foreach($sessions as $ss):?>
      <tr>
        <td class="fw-semibold"><?=formatDate($ss['schedule_date'])?></td>
        <td class="small"><?=substr($ss['start_time']??'',0,5)?><?=$ss['end_time']?' – '.substr($ss['end_time'],0,5):''?></td>
        <td><?=e($ss['student_name']??'—')?></td>
        <td class="small"><?=e($ss['instructor_name']??'—')?></td>
        <td class="small"><?=e($ss['vehicle_name']??'—')?></td>
        <td><span class="badge bg-<?=$typeColors[$ss['type']]??'secondary'?>"><?=ucfirst($ss['type'])?></span></td>
        <td>
          <form method="POST" class="d-inline">
            <?=csrfField()?><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?=$ss['id']?>">
            <select name="status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
              <?php foreach(['scheduled','confirmed','completed','cancelled','no_show'] as $s):?>
              <option value="<?=$s?>" <?=$ss['status']===$s?'selected':''?>><?=ucwords(str_replace('_',' ',$s))?></option>
              <?php endforeach;?>
            </select>
          </form>
        </td>
        <td class="text-center" style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($ss),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger ms-1" onclick="delSch(<?=$ss['id']?>)" title="Delete"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    <?php endforeach; endif;?>
    </tbody>
  </table></div></div>
</div>

<!-- Schedule Modal -->
<div class="modal fade" id="schModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="schId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="schTitle"><i class="fas fa-calendar-week me-2"></i>Schedule Session</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">Student <span class="text-danger">*</span></label>
      <select name="student_id" id="schStudent" class="form-select" required>
        <option value="">— Select Student —</option>
        <?php foreach($students as $st):?><option value="<?=$st['id']?>"><?=e($st['first_name'].' '.$st['last_name'])?></option><?php endforeach;?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Instructor</label>
      <select name="instructor_id" id="schInstructor" class="form-select">
        <option value="">— None —</option>
        <?php foreach($instructors as $i):?><option value="<?=$i['id']?>"><?=e($i['name'])?></option><?php endforeach;?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Vehicle</label>
      <select name="vehicle_id" id="schVehicle" class="form-select">
        <option value="">— None —</option>
        <?php foreach($vehicles as $v):?><option value="<?=$v['id']?>"><?=e($v['name'])?></option><?php endforeach;?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Session Type</label>
      <select name="type" id="schType" class="form-select">
        <?php foreach(['practical','theory','test','simulation'] as $t):?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach;?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
      <input type="date" name="schedule_date" id="schDate" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Start Time</label>
      <input type="time" name="start_time" id="schStart" class="form-control"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">End Time</label>
      <input type="time" name="end_time" id="schEnd" class="form-control"></div>
    <div class="col-md-8"><label class="form-label fw-semibold">Location / Venue</label>
      <input type="text" name="location" id="schLocation" class="form-control" maxlength="200"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="schStatus" class="form-select">
        <?php foreach(['scheduled','confirmed','completed','cancelled','no_show'] as $s):?><option value="<?=$s?>"><?=ucwords(str_replace('_',' ',$s))?></option><?php endforeach;?>
      </select></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label>
      <textarea name="notes" id="schNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Session</button>
  </div></form>
</div></div></div>
<form method="POST" id="delSchForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delSchId"></form>

<?php $extraJs=<<<'JS'
<script>
function openAdd(){
  document.getElementById('schTitle').innerHTML='<i class="fas fa-calendar-week me-2"></i>Schedule Session';
  document.getElementById('schId').value='0';
  ['schStudent','schInstructor','schVehicle'].forEach(x=>document.getElementById(x).value='');
  document.getElementById('schType').value='practical';
  document.getElementById('schDate').value=new Date().toISOString().substring(0,10);
  ['schStart','schEnd','schLocation','schNotes'].forEach(x=>document.getElementById(x).value='');
  document.getElementById('schStatus').value='scheduled';
}
function openEdit(s){
  document.getElementById('schTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Session';
  document.getElementById('schId').value=s.id;
  document.getElementById('schStudent').value=s.student_id||'';
  document.getElementById('schInstructor').value=s.instructor_id||'';
  document.getElementById('schVehicle').value=s.vehicle_id||'';
  document.getElementById('schType').value=s.type||'practical';
  document.getElementById('schDate').value=s.schedule_date?s.schedule_date.substring(0,10):'';
  document.getElementById('schStart').value=s.start_time?s.start_time.substring(0,5):'';
  document.getElementById('schEnd').value=s.end_time?s.end_time.substring(0,5):'';
  document.getElementById('schLocation').value=s.location||'';
  document.getElementById('schStatus').value=s.status||'scheduled';
  document.getElementById('schNotes').value=s.notes||'';
  new bootstrap.Modal(document.getElementById('schModal')).show();
}
function delSch(id){
  Swal.fire({title:'Delete Session?',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delSchId').value=id;document.getElementById('delSchForm').submit();}});
}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
