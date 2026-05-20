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
        $licNo  =sanitize($_POST['license_number']??'');
        $licCls =sanitize($_POST['license_class']??'B');
        $issue  =$_POST['issue_date']??null;
        $expiry =$_POST['expiry_date']??null;
        $auth   =sanitize($_POST['issuing_authority']??'');
        $st     =in_array($_POST['status']??'',['pending','approved','rejected','expired'])?$_POST['status']:'pending';
        $notes  =sanitize($_POST['notes']??'');
        if ($id>0) {$pdo->prepare("UPDATE driving_licenses SET student_id=?,license_number=?,license_class=?,issue_date=?,expiry_date=?,issuing_authority=?,status=?,notes=? WHERE id=? AND org_id=?")->execute([$stId,$licNo,$licCls,$issue?:null,$expiry?:null,$auth,$st,$notes,$id,$orgId]);setFlash('success','License record updated.');}
        else {$pdo->prepare("INSERT INTO driving_licenses(org_id,student_id,license_number,license_class,issue_date,expiry_date,issuing_authority,status,notes)VALUES(?,?,?,?,?,?,?,?,?)")->execute([$orgId,$stId,$licNo,$licCls,$issue?:null,$expiry?:null,$auth,$st,$notes]);setFlash('success','License record added.');}
        logActivity($id>0?'update':'create','driving',"License for student #$stId"); redirect('licenses.php');
    }
    if ($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM driving_licenses WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','License record deleted.');redirect('licenses.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fSt=$_GET['status']??'';
$where='l.org_id=?';$params=[$orgId];
if($fSt){$where.=' AND l.status=?';$params[]=$fSt;}
$licenses=[];
try{$s=$pdo->prepare("SELECT l.*,CONCAT(st.first_name,' ',st.last_name) AS student_name,st.phone AS student_phone FROM driving_licenses l JOIN driving_students st ON l.student_id=st.id WHERE $where ORDER BY l.created_at DESC");$s->execute($params);$licenses=$s->fetchAll();}catch(Exception $e){}
$students=[];try{$s=$pdo->prepare("SELECT id,first_name,last_name FROM driving_students WHERE org_id=? ORDER BY first_name");$s->execute([$orgId]);$students=$s->fetchAll();}catch(Exception $e){}
$total   =countRows('driving_licenses','org_id=?',[$orgId]);
$pending =countRows('driving_licenses','org_id=? AND status=?',[$orgId,'pending']);
$approved=countRows('driving_licenses','org_id=? AND status=?',[$orgId,'approved']);
$rejected=countRows('driving_licenses','org_id=? AND status=?',[$orgId,'rejected']);
$today   =date('Y-m-d');
$expiring=0;
try{$s=$pdo->prepare("SELECT COUNT(*) FROM driving_licenses WHERE org_id=? AND status='approved' AND expiry_date BETWEEN ? AND DATE_ADD(?,INTERVAL 30 DAY)");$s->execute([$orgId,$today,$today]);$expiring=(int)$s->fetchColumn();}catch(Exception $e){}
$statusColors=['pending'=>'warning','approved'=>'success','rejected'=>'danger','expired'=>'secondary'];
$licCats=['A','B','C','D','E','CE','BE'];
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-id-card me-2" style="color:<?=$moduleColor?>"></i>License Tracking</h4><p class="text-muted mb-0">Manage student licensing process from application to approval</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#licModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add License Record</button>
</div>
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-id-card"></i></div><div class="stat-body"><div class="stat-value"><?=$total?></div><div class="stat-label">Total Records</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div><div class="stat-body"><div class="stat-value"><?=$pending?></div><div class="stat-label">Pending</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$approved?></div><div class="stat-label">Approved</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#f8d7da;color:#721c24"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?=$expiring?></div><div class="stat-label">Expiring (30d)</div></div></div></div>
</div>
<?php if ($expiring > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-3">
  <i class="fas fa-exclamation-triangle fa-lg"></i>
  <span><strong><?=$expiring?> license<?=$expiring>1?'s':''?></strong> expiring within 30 days. Please notify the respective students.</span>
</div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach(['pending','approved','rejected','expired'] as $s):?><option value="<?=$s?>" <?=$fSt===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="licenses.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card"><div class="card-header d-flex align-items-center justify-content-between"><h6 class="mb-0"><i class="fas fa-id-card me-2" style="color:<?=$moduleColor?>"></i>License Records</h6><span class="badge bg-secondary"><?=count($licenses)?> records</span></div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Student</th><th>License No.</th><th>Class</th><th>Issue Date</th><th>Expiry Date</th><th>Issuing Authority</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($licenses)):?>
<tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-id-card fa-2x mb-2 d-block"></i>No license records found.</td></tr>
<?php else:foreach($licenses as $lic):
  $isExpiring = $lic['expiry_date'] && $lic['expiry_date'] <= date('Y-m-d', strtotime('+30 days')) && $lic['status']==='approved';
?>
<tr class="<?=$isExpiring?'table-warning bg-opacity-25':''?>">
  <td><div class="fw-semibold"><?=e($lic['student_name'])?></div><div class="small text-muted"><?=e($lic['student_phone']??'')?></div></td>
  <td><?=$lic['license_number']?'<span class="badge bg-dark">'.e($lic['license_number']).'</span>':'<span class="text-muted">—</span>'?></td>
  <td><span class="badge bg-primary">Class <?=e($lic['license_class']??'B')?></span></td>
  <td><?=$lic['issue_date']?formatDate($lic['issue_date']):'—'?></td>
  <td>
    <?php if($lic['expiry_date']): ?>
      <span class="<?=$isExpiring?'fw-bold text-warning':''?>"><?=formatDate($lic['expiry_date'])?></span>
      <?php if($isExpiring):?><span class="badge bg-warning text-dark ms-1">Expiring Soon</span><?php endif;?>
    <?php else:?>—<?php endif;?>
  </td>
  <td class="small"><?=e($lic['issuing_authority']??'—')?></td>
  <td><span class="badge bg-<?=$statusColors[$lic['status']]??'secondary'?> <?=$lic['status']==='pending'?'text-dark':''?>"><?=ucfirst($lic['status'])?></span></td>
  <td class="text-center" style="white-space:nowrap">
    <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($lic),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
    <button class="btn btn-sm btn-outline-danger ms-1" onclick="delLic(<?=$lic['id']?>,'<?=e($lic['student_name'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<!-- Modal -->
<div class="modal fade" id="licModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="licId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="licTitle"><i class="fas fa-id-card me-2"></i>Add License Record</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">Student <span class="text-danger">*</span></label><select name="student_id" id="licStudent" class="form-select" required><option value="">— Select —</option><?php foreach($students as $st):?><option value="<?=$st['id']?>"><?=e($st['first_name'].' '.$st['last_name'])?></option><?php endforeach;?></select></div>
    <div class="col-md-3"><label class="form-label fw-semibold">License Class</label><select name="license_class" id="licClass" class="form-select"><?php foreach($licCats as $lc):?><option value="<?=$lc?>" <?=$lc==='B'?'selected':''?>><?=$lc?></option><?php endforeach;?></select></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Status</label><select name="status" id="licStatus" class="form-select"><?php foreach(['pending','approved','rejected','expired'] as $s):?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <div class="col-12"><label class="form-label fw-semibold">License Number</label><input type="text" name="license_number" id="licNo" class="form-control" maxlength="50" placeholder="Leave blank if not yet issued"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Issue Date</label><input type="date" name="issue_date" id="licIssue" class="form-control"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Expiry Date</label><input type="date" name="expiry_date" id="licExpiry" class="form-control"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Issuing Authority</label><input type="text" name="issuing_authority" id="licAuth" class="form-control" maxlength="150" placeholder="e.g. NTSA"></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="licNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save License</button></div>
  </form>
</div></div></div>
<form method="POST" id="delLicForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delLicId"></form>
<?php $extraJs=<<<'JS'
<script>
function openAdd(){document.getElementById('licTitle').innerHTML='<i class="fas fa-id-card me-2"></i>Add License Record';['licId','licNo','licIssue','licExpiry','licAuth','licNotes'].forEach(i=>document.getElementById(i).value=i==='licId'?'0':'');document.getElementById('licStudent').value='';document.getElementById('licClass').value='B';document.getElementById('licStatus').value='pending';}
function openEdit(l){document.getElementById('licTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit License';document.getElementById('licId').value=l.id;document.getElementById('licStudent').value=l.student_id||'';document.getElementById('licClass').value=l.license_class||'B';document.getElementById('licStatus').value=l.status||'pending';document.getElementById('licNo').value=l.license_number||'';document.getElementById('licIssue').value=l.issue_date?l.issue_date.substring(0,10):'';document.getElementById('licExpiry').value=l.expiry_date?l.expiry_date.substring(0,10):'';document.getElementById('licAuth').value=l.issuing_authority||'';document.getElementById('licNotes').value=l.notes||'';new bootstrap.Modal(document.getElementById('licModal')).show();}
function delLic(id,name){Swal.fire({title:'Delete License Record?',text:name+"'s license will be removed.",icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delLicId').value=id;document.getElementById('delLicForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
