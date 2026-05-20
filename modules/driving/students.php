<?php
$moduleSlug='driving';$moduleName='Driving School';$moduleIcon='fas fa-car';$moduleColor='#1a237e';
$moduleNav=[
    ['url'=>'index.php',       'icon'=>'fas fa-tachometer-alt',    'label'=>'Dashboard'],
    ['url'=>'students.php',    'icon'=>'fas fa-user-graduate',      'label'=>'Students'],
    ['url'=>'instructors.php', 'icon'=>'fas fa-chalkboard-teacher', 'label'=>'Instructors'],
    ['url'=>'vehicles.php',    'icon'=>'fas fa-car',                'label'=>'Vehicles'],
    ['url'=>'classes.php',     'icon'=>'fas fa-calendar-alt',       'label'=>'Classes'],
    ['url'=>'lessons.php',     'icon'=>'fas fa-road',               'label'=>'Lessons'],
    ['url'=>'tests.php',       'icon'=>'fas fa-clipboard-check',    'label'=>'Tests'],
    ['url'=>'licenses.php',    'icon'=>'fas fa-id-card',            'label'=>'Licenses'],
    ['url'=>'reports.php',     'icon'=>'fas fa-chart-bar',          'label'=>'Reports'],
];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if (session_status()===PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user=currentUser(); $orgId=(int)$user['org_id']; $action=$_POST['action']??'';

    if ($action==='save') {
        $id   =(int)($_POST['id']??0);
        $fn   =sanitize($_POST['first_name']??'');
        $ln   =sanitize($_POST['last_name']??'');
        $em   =sanitize($_POST['email']??'');
        $ph   =sanitize($_POST['phone']??'');
        $idn  =sanitize($_POST['id_number']??'');
        $dob  =$_POST['date_of_birth']??null;
        $addr =sanitize($_POST['address']??'');
        $ec   =sanitize($_POST['emergency_contact']??'');
        $ep   =sanitize($_POST['emergency_phone']??'');
        $instId=(int)($_POST['instructor_id']??0)?:null;
        $enDate=$_POST['enrollment_date']??date('Y-m-d');
        $lcat =sanitize($_POST['license_category']??'B');
        $st   =in_array($_POST['status']??'',['active','inactive','completed','suspended'])?$_POST['status']:'active';
        $notes=sanitize($_POST['notes']??'');
        if ($id>0) {
            $pdo->prepare("UPDATE driving_students SET first_name=?,last_name=?,email=?,phone=?,id_number=?,date_of_birth=?,address=?,emergency_contact=?,emergency_phone=?,instructor_id=?,enrollment_date=?,license_category=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$fn,$ln,$em,$ph,$idn,$dob?:null,$addr,$ec,$ep,$instId,$enDate,$lcat,$st,$notes,$id,$orgId]);
            setFlash('success','Student updated.');
        } else {
            $pdo->prepare("INSERT INTO driving_students(org_id,first_name,last_name,email,phone,id_number,date_of_birth,address,emergency_contact,emergency_phone,instructor_id,enrollment_date,license_category,status,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$fn,$ln,$em,$ph,$idn,$dob?:null,$addr,$ec,$ep,$instId,$enDate,$lcat,$st,$notes]);
            setFlash('success',"Student $fn $ln enrolled.");
        }
        logActivity($id>0?'update':'create','driving',"Student: $fn $ln");
        redirect('students.php');
    }
    if ($action==='delete') {
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM driving_students WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Student removed.'); redirect('students.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser(); $orgId=(int)$user['org_id'];
$fStatus=$_GET['status']??''; $fInst=(int)($_GET['instructor_id']??0); $fQ=trim($_GET['q']??'');
$where='org_id=?'; $params=[$orgId];
if ($fStatus) { $where.=' AND status=?'; $params[]=$fStatus; }
if ($fInst)   { $where.=' AND instructor_id=?'; $params[]=$fInst; }
if ($fQ)      { $where.=' AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR id_number LIKE ?)'; $like="%$fQ%"; array_push($params,$like,$like,$like,$like); }

$students=[];
try {
    $stmt=$pdo->prepare("SELECT s.*,i.name AS instructor_name FROM driving_students s LEFT JOIN driving_instructors i ON s.instructor_id=i.id WHERE s.$where ORDER BY s.created_at DESC");
    $stmt->execute($params); $students=$stmt->fetchAll();
} catch (Exception $e) {}

$instructors=[];
try { $s=$pdo->prepare("SELECT id,name FROM driving_instructors WHERE org_id=? AND status='active' ORDER BY name"); $s->execute([$orgId]); $instructors=$s->fetchAll(); } catch (Exception $e) {}

$total   =countRows('driving_students','org_id=?',[$orgId]);
$active  =countRows('driving_students','org_id=? AND status=?',[$orgId,'active']);
$completed=countRows('driving_students','org_id=? AND status=?',[$orgId,'completed']);

$viewS=null;
if (isset($_GET['view'])) {
    try { $s=$pdo->prepare("SELECT st.*,i.name AS instructor_name FROM driving_students st LEFT JOIN driving_instructors i ON st.instructor_id=i.id WHERE st.id=? AND st.org_id=?"); $s->execute([(int)$_GET['view'],$orgId]); $viewS=$s->fetch(); } catch (Exception $e) {}
}

$licCats=['A','B','C','D','E','CE','BE'];
$statusColors=['active'=>'success','inactive'=>'secondary','completed'=>'info','suspended'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-user-graduate me-2" style="color:<?=$moduleColor?>"></i>Students</h4>
  <p class="text-muted mb-0">Manage student enrollments and profiles</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#sModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Enroll Student
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value"><?=$total?></div><div class="stat-label">Total Students</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-user-check"></i></div><div class="stat-body"><div class="stat-value"><?=$active?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-graduation-cap"></i></div><div class="stat-body"><div class="stat-value"><?=$completed?></div><div class="stat-label">Completed</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Search</label>
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, email, ID number…" value="<?=e($fQ)?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <?php foreach(['active','inactive','completed','suspended'] as $s):?>
        <option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst($s)?></option>
        <?php endforeach;?>
      </select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Instructor</label>
      <select name="instructor_id" class="form-select form-select-sm">
        <option value="">All Instructors</option>
        <?php foreach($instructors as $i):?>
        <option value="<?=$i['id']?>" <?=$fInst===$i['id']?'selected':''?>><?=e($i['name'])?></option>
        <?php endforeach;?>
      </select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="students.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<?php if ($viewS): ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:<?=$moduleColor?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-id-card me-2"></i><?=e($viewS['first_name'].' '.$viewS['last_name'])?></h6>
    <a href="students.php" class="btn btn-sm btn-light">Close</a>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <table class="table table-sm">
          <tr><th class="text-muted w-40">Full Name</th><td class="fw-semibold"><?=e($viewS['first_name'].' '.$viewS['last_name'])?></td></tr>
          <tr><th class="text-muted">Email</th><td><?=e($viewS['email']??'—')?></td></tr>
          <tr><th class="text-muted">Phone</th><td><?=e($viewS['phone']??'—')?></td></tr>
          <tr><th class="text-muted">ID Number</th><td><?=e($viewS['id_number']??'—')?></td></tr>
          <tr><th class="text-muted">Date of Birth</th><td><?=$viewS['date_of_birth']?formatDate($viewS['date_of_birth']):'—'?></td></tr>
          <tr><th class="text-muted">Address</th><td><?=e($viewS['address']??'—')?></td></tr>
        </table>
      </div>
      <div class="col-md-6">
        <table class="table table-sm">
          <tr><th class="text-muted w-40">Instructor</th><td><?=e($viewS['instructor_name']??'Not assigned')?></td></tr>
          <tr><th class="text-muted">Enrollment</th><td><?=$viewS['enrollment_date']?formatDate($viewS['enrollment_date']):'—'?></td></tr>
          <tr><th class="text-muted">License Class</th><td><span class="badge bg-primary">Class <?=e($viewS['license_category']??'B')?></span></td></tr>
          <tr><th class="text-muted">Status</th><td><?=statusBadge($viewS['status']??'active')?></td></tr>
          <tr><th class="text-muted">Emergency</th><td><?=e(($viewS['emergency_contact']??'').' '.($viewS['emergency_phone']??''))?></td></tr>
        </table>
      </div>
      <?php
      // Lesson summary for this student
      $sLessons=[]; $lessonCount=0; $doneCount=0;
      try {
          $s=$pdo->prepare("SELECT COUNT(*) FROM driving_lessons WHERE student_id=? AND org_id=?"); $s->execute([$viewS['id'],$orgId]); $lessonCount=(int)$s->fetchColumn();
          $s=$pdo->prepare("SELECT COUNT(*) FROM driving_lessons WHERE student_id=? AND org_id=? AND status='completed'"); $s->execute([$viewS['id'],$orgId]); $doneCount=(int)$s->fetchColumn();
          $s=$pdo->prepare("SELECT l.*,i.name AS ins FROM driving_lessons l LEFT JOIN driving_instructors i ON l.instructor_id=i.id WHERE l.student_id=? AND l.org_id=? ORDER BY l.lesson_date DESC LIMIT 5"); $s->execute([$viewS['id'],$orgId]); $sLessons=$s->fetchAll();
      } catch (Exception $e) {}
      ?>
      <div class="col-12">
        <div class="d-flex gap-3 mb-2">
          <span class="badge bg-info text-dark fs-6"><?=$lessonCount?> Total Lessons</span>
          <span class="badge bg-success fs-6"><?=$doneCount?> Completed</span>
          <?php if ($lessonCount>0): ?><span class="badge bg-secondary fs-6"><?=round($doneCount/$lessonCount*100)?>% Done</span><?php endif; ?>
        </div>
        <?php if ($sLessons): ?>
        <table class="table table-sm table-bordered"><thead class="table-light"><tr><th>#</th><th>Date</th><th>Topic</th><th>Instructor</th><th>Status</th></tr></thead><tbody>
        <?php foreach($sLessons as $sl): ?><tr><td><?=$sl['lesson_number']?></td><td><?=formatDate($sl['lesson_date'])?></td><td><?=e($sl['topic']??'—')?></td><td><?=e($sl['ins']??'—')?></td><td><span class="badge bg-<?=['draft'=>'secondary','started'=>'primary','completed'=>'success','cancelled'=>'danger'][$sl['status']]??'secondary'?>"><?=ucfirst($sl['status'])?></span></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
      <?php if ($viewS['notes']): ?><div class="col-12"><p class="text-muted small mb-0"><strong>Notes:</strong> <?=nl2br(e($viewS['notes']))?></p></div><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-user-graduate me-2" style="color:<?=$moduleColor?>"></i>Student List</h6>
    <span class="badge bg-secondary"><?=count($students)?> students</span>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Name</th><th>Phone</th><th>ID No.</th><th>Class</th><th>Instructor</th><th>Enrolled</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($students)): ?>
      <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-user-graduate fa-2x mb-2 d-block"></i>No students found.</td></tr>
    <?php else: foreach($students as $st): ?>
      <tr>
        <td><div class="d-flex align-items-center gap-2">
          <div style="width:34px;height:34px;border-radius:50%;background:<?=$moduleColor?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0"><?=strtoupper(substr($st['first_name'],0,1).substr($st['last_name']??'',0,1))?></div>
          <div><div class="fw-semibold"><?=e($st['first_name'].' '.$st['last_name'])?></div><div class="small text-muted"><?=e($st['email']??'')?></div></div>
        </div></td>
        <td><?=e($st['phone']??'—')?></td>
        <td class="small text-muted"><?=e($st['id_number']??'—')?></td>
        <td><span class="badge bg-primary">Class <?=e($st['license_category']??'B')?></span></td>
        <td class="small"><?=e($st['instructor_name']??'—')?></td>
        <td class="small"><?=$st['enrollment_date']?formatDate($st['enrollment_date']):'—'?></td>
        <td><span class="badge bg-<?=$statusColors[$st['status']]??'secondary'?>"><?=ucfirst($st['status'])?></span></td>
        <td class="text-center" style="white-space:nowrap">
          <a href="?view=<?=$st['id']?>" class="btn btn-sm btn-outline-info" title="View Profile"><i class="fas fa-eye"></i></a>
          <a href="lessons.php?student_id=<?=$st['id']?>" class="btn btn-sm btn-outline-success ms-1" title="Lessons"><i class="fas fa-road"></i></a>
          <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEdit(<?=htmlspecialchars(json_encode($st),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger ms-1" onclick="delStudent(<?=$st['id']?>,'<?=e($st['first_name'].' '.$st['last_name'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>
</div>

<!-- Modal -->
<div class="modal fade" id="sModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="sId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="sModalTitle"><i class="fas fa-user-graduate me-2"></i>Enroll Student</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-4"><label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label><input type="text" name="first_name" id="sFirst" class="form-control" required maxlength="100"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Last Name</label><input type="text" name="last_name" id="sLast" class="form-control" maxlength="100"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">ID Number / Passport</label><input type="text" name="id_number" id="sIdNum" class="form-control" maxlength="50"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Email</label><input type="email" name="email" id="sEmail" class="form-control"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" id="sPhone" class="form-control" maxlength="50"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Date of Birth</label><input type="date" name="date_of_birth" id="sDob" class="form-control"></div>
    <div class="col-12"><label class="form-label fw-semibold">Address</label><input type="text" name="address" id="sAddr" class="form-control"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Emergency Contact Name</label><input type="text" name="emergency_contact" id="sEc" class="form-control" maxlength="100"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Emergency Phone</label><input type="text" name="emergency_phone" id="sEp" class="form-control" maxlength="50"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Assign Instructor</label>
      <select name="instructor_id" id="sInstructor" class="form-select">
        <option value="">— None —</option>
        <?php foreach($instructors as $i):?><option value="<?=$i['id']?>"><?=e($i['name'])?></option><?php endforeach;?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Enrollment Date</label><input type="date" name="enrollment_date" id="sEnroll" class="form-control" value="<?=date('Y-m-d')?>"></div>
    <div class="col-md-2"><label class="form-label fw-semibold">License Class</label>
      <select name="license_category" id="sLcat" class="form-select">
        <?php foreach($licCats as $lc):?><option value="<?=$lc?>" <?=$lc==='B'?'selected':''?>><?=$lc?></option><?php endforeach;?>
      </select></div>
    <div class="col-md-2"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="sStatus" class="form-select">
        <?php foreach(['active','inactive','completed','suspended'] as $s):?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach;?>
      </select></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="sNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Student</button>
  </div></form>
</div></div></div>
<form method="POST" id="delSForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delSId"></form>
<?php
$extraJs=<<<'JS'
<script>
function openAdd(){
  document.getElementById('sModalTitle').innerHTML='<i class="fas fa-user-graduate me-2"></i>Enroll Student';
  ['sId','sFirst','sLast','sIdNum','sEmail','sPhone','sDob','sAddr','sEc','sEp','sNotes'].forEach(i=>document.getElementById(i).value=i==='sId'?'0':'');
  document.getElementById('sInstructor').value='';
  document.getElementById('sEnroll').value=new Date().toISOString().substring(0,10);
  document.getElementById('sLcat').value='B';
  document.getElementById('sStatus').value='active';
}
function openEdit(s){
  document.getElementById('sModalTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Student';
  document.getElementById('sId').value=s.id;
  document.getElementById('sFirst').value=s.first_name||'';
  document.getElementById('sLast').value=s.last_name||'';
  document.getElementById('sIdNum').value=s.id_number||'';
  document.getElementById('sEmail').value=s.email||'';
  document.getElementById('sPhone').value=s.phone||'';
  document.getElementById('sDob').value=s.date_of_birth?s.date_of_birth.substring(0,10):'';
  document.getElementById('sAddr').value=s.address||'';
  document.getElementById('sEc').value=s.emergency_contact||'';
  document.getElementById('sEp').value=s.emergency_phone||'';
  document.getElementById('sInstructor').value=s.instructor_id||'';
  document.getElementById('sEnroll').value=s.enrollment_date?s.enrollment_date.substring(0,10):'';
  document.getElementById('sLcat').value=s.license_category||'B';
  document.getElementById('sStatus').value=s.status||'active';
  document.getElementById('sNotes').value=s.notes||'';
  new bootstrap.Modal(document.getElementById('sModal')).show();
}
function delStudent(id,name){
  Swal.fire({title:'Remove Student?',text:name+' will be deleted.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delSId').value=id;document.getElementById('delSForm').submit();}});
}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
