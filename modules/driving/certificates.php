<?php
$moduleSlug='driving';$moduleName='Driving School';$moduleIcon='fas fa-car-side';$moduleColor='#1a237e';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'instructors.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Instructors'],['url'=>'vehicles.php','icon'=>'fas fa-car','label'=>'Vehicles'],['url'=>'classes.php','icon'=>'fas fa-calendar-alt','label'=>'Classes'],['url'=>'lessons.php','icon'=>'fas fa-road','label'=>'Lessons'],['url'=>'tests.php','icon'=>'fas fa-clipboard-check','label'=>'Tests'],['url'=>'licenses.php','icon'=>'fas fa-id-card','label'=>'Licenses'],['url'=>'schedule.php','icon'=>'fas fa-calendar-week','label'=>'Schedule'],['url'=>'payments.php','icon'=>'fas fa-money-bill','label'=>'Payments'],['url'=>'certificates.php','icon'=>'fas fa-certificate','label'=>'Certificates'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if (session_status()===PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug); $user=currentUser(); $orgId=(int)$user['org_id']; $action=$_POST['action']??'';

    if ($action==='issue') {
        $studentId=(int)($_POST['student_id']??0);
        $type     =in_array($_POST['cert_type']??'',['completion','driving','defensive','theory'])?$_POST['cert_type']:'completion';
        $issueDate=$_POST['issue_date']??date('Y-m-d');
        $expiryDate=$_POST['expiry_date']??null;
        $grade    =sanitize($_POST['grade']??'');
        $notes    =sanitize($_POST['notes']??'');
        if (!$studentId) { setFlash('error','Student is required.'); redirect('certificates.php'); }
        // generate cert number
        $seq=(int)$pdo->query("SELECT COUNT(*)+1 FROM driving_certificates WHERE org_id=$orgId")->fetchColumn();
        $certNo='CERT-'.date('Y').'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO driving_certificates(org_id,student_id,cert_number,cert_type,issue_date,expiry_date,grade,notes)VALUES(?,?,?,?,?,?,?,?)")
            ->execute([$orgId,$studentId,$certNo,$type,$issueDate,$expiryDate?:null,$grade,$notes]);
        setFlash('success',"Certificate $certNo issued.");
        logActivity('create','driving',"Certificate: $certNo for student#$studentId");
        redirect('certificates.php');
    }
    if ($action==='update') {
        $id        =(int)($_POST['id']??0);
        $grade     =sanitize($_POST['grade']??'');
        $issueDate =$_POST['issue_date']??null;
        $expiryDate=$_POST['expiry_date']??null;
        $notes     =sanitize($_POST['notes']??'');
        $status    =in_array($_POST['status']??'',['active','expired','revoked'])?$_POST['status']:'active';
        $pdo->prepare("UPDATE driving_certificates SET grade=?,issue_date=?,expiry_date=?,notes=?,status=? WHERE id=? AND org_id=?")
            ->execute([$grade,$issueDate,$expiryDate?:null,$notes,$status,$id,$orgId]);
        setFlash('success','Certificate updated.');
        redirect('certificates.php');
    }
    if ($action==='revoke') {
        $id=(int)($_POST['id']??0);
        $pdo->prepare("UPDATE driving_certificates SET status='revoked' WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Certificate revoked.'); redirect('certificates.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser(); $orgId=(int)$user['org_id'];

$fStudent=(int)($_GET['student_id']??0);
$fType   =$_GET['type']??'';
$fStatus =$_GET['status']??'';

$where='c.org_id=?'; $params=[$orgId];
if ($fStudent) { $where.=' AND c.student_id=?'; $params[]=$fStudent; }
if ($fType)    { $where.=' AND c.cert_type=?'; $params[]=$fType; }
if ($fStatus)  { $where.=' AND c.status=?'; $params[]=$fStatus; }

$certs=[];
try {
    $s=$pdo->prepare("
        SELECT c.*,CONCAT(st.first_name,' ',st.last_name) AS student_name, st.license_category
        FROM driving_certificates c
        LEFT JOIN driving_students st ON c.student_id=st.id
        WHERE $where ORDER BY c.issue_date DESC, c.id DESC
    ");
    $s->execute($params); $certs=$s->fetchAll();
} catch (Exception $e) {}

$students=[];
try { $s=$pdo->prepare("SELECT id,first_name,last_name FROM driving_students WHERE org_id=? ORDER BY first_name,last_name"); $s->execute([$orgId]); $students=$s->fetchAll(); } catch (Exception $e) {}

$totalCerts=0; $activeCerts=0; $expiringCerts=0; $revokedCerts=0;
try {
    $s=$pdo->prepare("SELECT COUNT(*) FROM driving_certificates WHERE org_id=?"); $s->execute([$orgId]); $totalCerts=(int)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) FROM driving_certificates WHERE org_id=? AND status='active'"); $s->execute([$orgId]); $activeCerts=(int)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) FROM driving_certificates WHERE org_id=? AND status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY)"); $s->execute([$orgId]); $expiringCerts=(int)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) FROM driving_certificates WHERE org_id=? AND status='revoked'"); $s->execute([$orgId]); $revokedCerts=(int)$s->fetchColumn();
} catch (Exception $e) {}

// Expiring soon list for alert
$expiringSoon=[];
try {
    $s=$pdo->prepare("SELECT c.cert_number,c.expiry_date,CONCAT(st.first_name,' ',st.last_name) AS student_name FROM driving_certificates c LEFT JOIN driving_students st ON c.student_id=st.id WHERE c.org_id=? AND c.status='active' AND c.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY) ORDER BY c.expiry_date LIMIT 5");
    $s->execute([$orgId]); $expiringSoon=$s->fetchAll();
} catch (Exception $e) {}

$typeLabels=['completion'=>'Course Completion','driving'=>'Driving Competency','defensive'=>'Defensive Driving','theory'=>'Theory'];
$statusColors=['active'=>'success','expired'=>'secondary','revoked'=>'danger'];
$typeColors=['completion'=>'primary','driving'=>'success','defensive'=>'info','theory'=>'warning'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-certificate me-2" style="color:<?=$moduleColor?>"></i>Certificates</h4>
    <p class="text-muted mb-0">Issue and manage student completion certificates</p>
  </div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#certModal" onclick="openIssue()">
    <i class="fas fa-plus me-2"></i>Issue Certificate
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-certificate"></i></div><div class="stat-body"><div class="stat-value"><?=$totalCerts?></div><div class="stat-label">Total Issued</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$activeCerts?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-body"><div class="stat-value"><?=$expiringCerts?></div><div class="stat-label">Expiring (60 days)</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#fde8e8;color:#c0392b"><i class="fas fa-ban"></i></div><div class="stat-body"><div class="stat-value"><?=$revokedCerts?></div><div class="stat-label">Revoked</div></div></div></div>
</div>

<?php if ($expiringSoon):?>
<div class="alert alert-warning d-flex align-items-start mb-4">
  <i class="fas fa-exclamation-triangle me-3 mt-1"></i>
  <div><strong>Expiring Soon:</strong>
    <?php foreach($expiringSoon as $es):?>
    <span class="badge bg-warning text-dark me-1"><?=e($es['student_name'])?> — <?=e($es['cert_number'])?> (<?=formatDate($es['expiry_date'])?>)</span>
    <?php endforeach;?>
  </div>
</div>
<?php endif;?>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Student</label>
      <select name="student_id" class="form-select form-select-sm">
        <option value="">All Students</option>
        <?php foreach($students as $st):?><option value="<?=$st['id']?>" <?=$fStudent==$st['id']?'selected':''?>><?=e($st['first_name'].' '.$st['last_name'])?></option><?php endforeach;?>
      </select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Type</label>
      <select name="type" class="form-select form-select-sm">
        <option value="">All Types</option>
        <?php foreach($typeLabels as $k=>$v):?><option value="<?=$k?>" <?=$fType===$k?'selected':''?>><?=$v?></option><?php endforeach;?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <?php foreach(['active','expired','revoked'] as $s):?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?>
      </select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="certificates.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-certificate me-2" style="color:<?=$moduleColor?>"></i>Certificate Registry</h6>
    <span class="badge bg-secondary"><?=count($certs)?> records</span>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Cert No.</th><th>Student</th><th>Type</th><th>Grade</th><th>Issued</th><th>Expires</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($certs)):?>
      <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-certificate fa-2x mb-2 d-block"></i>No certificates issued yet.</td></tr>
    <?php else: foreach($certs as $c):
        $isExpired=$c['expiry_date'] && $c['expiry_date']<date('Y-m-d') && $c['status']==='active';
    ?>
      <tr class="<?=$isExpired?'table-warning':''?>">
        <td><span class="badge bg-dark font-monospace"><?=e($c['cert_number'])?></span></td>
        <td class="fw-semibold"><?=e($c['student_name']??'—')?></td>
        <td><span class="badge bg-<?=$typeColors[$c['cert_type']]??'secondary'?>"><?=$typeLabels[$c['cert_type']]??e($c['cert_type'])?></span></td>
        <td><?=$c['grade']?'<span class="fw-bold text-success">'.e($c['grade']).'</span>':'<span class="text-muted">—</span>'?></td>
        <td><?=formatDate($c['issue_date'])?></td>
        <td><?=$c['expiry_date']?formatDate($c['expiry_date']):'<span class="text-muted">No expiry</span>'?></td>
        <td>
          <?php if ($isExpired):?>
            <span class="badge bg-secondary">Expired</span>
          <?php else:?>
            <span class="badge bg-<?=$statusColors[$c['status']]??'secondary'?>"><?=ucfirst($c['status'])?></span>
          <?php endif;?>
        </td>
        <td class="text-center" style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($c),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
          <?php if ($c['status']==='active'):?>
          <button class="btn btn-sm btn-outline-danger ms-1" onclick="revokeCert(<?=$c['id']?>,<?=json_encode($c['cert_number'])?>)" title="Revoke"><i class="fas fa-ban"></i></button>
          <?php endif;?>
        </td>
      </tr>
    <?php endforeach; endif;?>
    </tbody>
  </table></div></div>
</div>

<!-- Issue Modal -->
<div class="modal fade" id="certModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <form method="POST" id="certForm"><?=csrfField()?><input type="hidden" name="action" id="certAction" value="issue"><input type="hidden" name="id" id="certId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="certTitle"><i class="fas fa-certificate me-2"></i>Issue Certificate</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12" id="studentRow"><label class="form-label fw-semibold">Student <span class="text-danger">*</span></label>
      <select name="student_id" id="certStudent" class="form-select">
        <option value="">— Select Student —</option>
        <?php foreach($students as $st):?><option value="<?=$st['id']?>"><?=e($st['first_name'].' '.$st['last_name'])?></option><?php endforeach;?>
      </select></div>
    <div class="col-12"><label class="form-label fw-semibold">Certificate Type</label>
      <select name="cert_type" id="certType" class="form-select">
        <?php foreach($typeLabels as $k=>$v):?><option value="<?=$k?>"><?=$v?></option><?php endforeach;?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Issue Date</label>
      <input type="date" name="issue_date" id="certIssue" class="form-control" value="<?=date('Y-m-d')?>"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Expiry Date <small class="text-muted">(optional)</small></label>
      <input type="date" name="expiry_date" id="certExpiry" class="form-control"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Grade / Score</label>
      <input type="text" name="grade" id="certGrade" class="form-control" maxlength="20" placeholder="e.g. A, 85%"></div>
    <div class="col-md-6" id="statusRow" style="display:none"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="certStatus" class="form-select">
        <?php foreach(['active','expired','revoked'] as $s):?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach;?>
      </select></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label>
      <textarea name="notes" id="certNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i><span id="certBtnLabel">Issue Certificate</span></button>
  </div></form>
</div></div></div>
<form method="POST" id="revokeForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" id="revokeId"></form>

<?php $extraJs=<<<'JS'
<script>
function openIssue(){
  document.getElementById('certTitle').innerHTML='<i class="fas fa-certificate me-2"></i>Issue Certificate';
  document.getElementById('certAction').value='issue';
  document.getElementById('certId').value='0';
  document.getElementById('certStudent').value='';
  document.getElementById('certType').value='completion';
  document.getElementById('certIssue').value=new Date().toISOString().substring(0,10);
  document.getElementById('certExpiry').value='';
  document.getElementById('certGrade').value='';
  document.getElementById('certNotes').value='';
  document.getElementById('studentRow').style.display='';
  document.getElementById('statusRow').style.display='none';
  document.getElementById('certBtnLabel').textContent='Issue Certificate';
}
function openEdit(c){
  document.getElementById('certTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Certificate';
  document.getElementById('certAction').value='update';
  document.getElementById('certId').value=c.id;
  document.getElementById('certType').value=c.cert_type||'completion';
  document.getElementById('certIssue').value=c.issue_date?c.issue_date.substring(0,10):'';
  document.getElementById('certExpiry').value=c.expiry_date?c.expiry_date.substring(0,10):'';
  document.getElementById('certGrade').value=c.grade||'';
  document.getElementById('certStatus').value=c.status||'active';
  document.getElementById('certNotes').value=c.notes||'';
  document.getElementById('studentRow').style.display='none';
  document.getElementById('statusRow').style.display='';
  document.getElementById('certBtnLabel').textContent='Save Changes';
  new bootstrap.Modal(document.getElementById('certModal')).show();
}
function revokeCert(id,num){
  Swal.fire({title:'Revoke Certificate?',text:num+' will be marked revoked.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, revoke'})
    .then(r=>{if(r.isConfirmed){document.getElementById('revokeId').value=id;document.getElementById('revokeForm').submit();}});
}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
