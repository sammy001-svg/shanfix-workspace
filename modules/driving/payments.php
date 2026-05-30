<?php
$moduleSlug='driving';$moduleName='Driving School';$moduleIcon='fas fa-car-side';$moduleColor='#1a237e';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'instructors.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Instructors'],['url'=>'vehicles.php','icon'=>'fas fa-car','label'=>'Vehicles'],['url'=>'classes.php','icon'=>'fas fa-calendar-alt','label'=>'Classes'],['url'=>'lessons.php','icon'=>'fas fa-road','label'=>'Lessons'],['url'=>'tests.php','icon'=>'fas fa-clipboard-check','label'=>'Tests'],['url'=>'licenses.php','icon'=>'fas fa-id-card','label'=>'Licenses'],['url'=>'schedule.php','icon'=>'fas fa-calendar-week','label'=>'Schedule'],['url'=>'payments.php','icon'=>'fas fa-money-bill','label'=>'Payments'],['url'=>'certificates.php','icon'=>'fas fa-certificate','label'=>'Certificates'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if (session_status()===PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug); $user=currentUser(); $orgId=(int)$user['org_id']; $action=$_POST['action']??'';

    if ($action==='save') {
        $id        =(int)($_POST['id']??0);
        $studentId =(int)($_POST['student_id']??0);
        $amount    =(float)($_POST['amount']??0);
        $method    =in_array($_POST['payment_method']??'',['cash','mpesa','bank','card','cheque'])?$_POST['payment_method']:'cash';
        $ref       =sanitize($_POST['reference']??'');
        $type      =in_array($_POST['payment_type']??'',['registration','tuition','test_fee','license_fee','other'])?$_POST['payment_type']:'tuition';
        $payDate   =$_POST['payment_date']??date('Y-m-d');
        $notes     =sanitize($_POST['notes']??'');
        $status    =in_array($_POST['status']??'',['pending','paid','cancelled'])?$_POST['status']:'paid';
        if (!$studentId || $amount<=0) { setFlash('error','Student and amount are required.'); redirect('payments.php'); }

        if ($id>0) {
            $pdo->prepare("UPDATE driving_payments SET student_id=?,amount=?,payment_method=?,reference=?,payment_type=?,payment_date=?,notes=?,status=? WHERE id=? AND org_id=?")
                ->execute([$studentId,$amount,$method,$ref,$type,$payDate,$notes,$status,$id,$orgId]);
            setFlash('success','Payment updated.');
        } else {
            // auto reference if empty
            if (!$ref) {
                $seq=(int)$pdo->query("SELECT COUNT(*)+1 FROM driving_payments WHERE org_id=$orgId")->fetchColumn();
                $ref='DRV-'.date('Y').'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            }
            $pdo->prepare("INSERT INTO driving_payments(org_id,student_id,amount,payment_method,reference,payment_type,payment_date,notes,status)VALUES(?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$studentId,$amount,$method,$ref,$type,$payDate,$notes,$status]);
            setFlash('success','Payment recorded.');
        }
        logActivity($id>0?'update':'create','driving',"Payment: $amount for student#$studentId");
        redirect('payments.php');
    }
    if ($action==='delete') {
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM driving_payments WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Payment deleted.'); redirect('payments.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser(); $orgId=(int)$user['org_id'];

$fStudent=(int)($_GET['student_id']??0);
$fMethod =$_GET['method']??'';
$fType   =$_GET['type']??'';
$fMonth  =$_GET['month']??'';

$where='p.org_id=?'; $params=[$orgId];
if ($fStudent) { $where.=' AND p.student_id=?'; $params[]=$fStudent; }
if ($fMethod)  { $where.=' AND p.payment_method=?'; $params[]=$fMethod; }
if ($fType)    { $where.=' AND p.payment_type=?'; $params[]=$fType; }
if ($fMonth)   { $where.=' AND DATE_FORMAT(p.payment_date,\'%Y-%m\')=?'; $params[]=$fMonth; }

$payments=[];
try {
    $s=$pdo->prepare("
        SELECT p.*,CONCAT(st.first_name,' ',st.last_name) AS student_name
        FROM driving_payments p
        LEFT JOIN driving_students st ON p.student_id=st.id
        WHERE $where ORDER BY p.payment_date DESC, p.id DESC
    ");
    $s->execute($params); $payments=$s->fetchAll();
} catch (Exception $e) {}

$students=[];
try { $s=$pdo->prepare("SELECT id,first_name,last_name FROM driving_students WHERE org_id=? ORDER BY first_name,last_name"); $s->execute([$orgId]); $students=$s->fetchAll(); } catch (Exception $e) {}

$totalRevenue=0; $monthRevenue=0; $pendingAmount=0; $txnCount=0;
try {
    $s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM driving_payments WHERE org_id=? AND status='paid'"); $s->execute([$orgId]); $totalRevenue=(float)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM driving_payments WHERE org_id=? AND status='paid' AND DATE_FORMAT(payment_date,'%Y-%m')=?"); $s->execute([$orgId,date('Y-m')]); $monthRevenue=(float)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM driving_payments WHERE org_id=? AND status='pending'"); $s->execute([$orgId]); $pendingAmount=(float)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) FROM driving_payments WHERE org_id=?"); $s->execute([$orgId]); $txnCount=(int)$s->fetchColumn();
} catch (Exception $e) {}

$methodColors=['cash'=>'success','mpesa'=>'primary','bank'=>'info','card'=>'warning','cheque'=>'secondary'];
$typeLabels=['registration'=>'Registration','tuition'=>'Tuition','test_fee'=>'Test Fee','license_fee'=>'License Fee','other'=>'Other'];
$statusColors=['pending'=>'warning','paid'=>'success','cancelled'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-bill me-2" style="color:<?=$moduleColor?>"></i>Payments</h4>
    <p class="text-muted mb-0">Track student fees and payment transactions</p>
  </div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#payModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Record Payment
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-coins"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($totalRevenue)?></div><div class="stat-label">Total Revenue</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-calendar-alt"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($monthRevenue)?></div><div class="stat-label">This Month</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($pendingAmount)?></div><div class="stat-label">Pending</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#e3f2fd;color:<?=$moduleColor?>"><i class="fas fa-receipt"></i></div><div class="stat-body"><div class="stat-value"><?=$txnCount?></div><div class="stat-label">Transactions</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Student</label>
      <select name="student_id" class="form-select form-select-sm">
        <option value="">All Students</option>
        <?php foreach($students as $st):?><option value="<?=$st['id']?>" <?=$fStudent==$st['id']?'selected':''?>><?=e($st['first_name'].' '.$st['last_name'])?></option><?php endforeach;?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Method</label>
      <select name="method" class="form-select form-select-sm">
        <option value="">All Methods</option>
        <?php foreach(['cash','mpesa','bank','card','cheque'] as $m):?><option value="<?=$m?>" <?=$fMethod===$m?'selected':''?>><?=ucfirst($m)?></option><?php endforeach;?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Type</label>
      <select name="type" class="form-select form-select-sm">
        <option value="">All Types</option>
        <?php foreach($typeLabels as $k=>$v):?><option value="<?=$k?>" <?=$fType===$k?'selected':''?>><?=$v?></option><?php endforeach;?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Month</label>
      <input type="month" name="month" class="form-control form-control-sm" value="<?=e($fMonth)?>"></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="payments.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?=$moduleColor?>"></i>Payment Records</h6>
    <span class="badge bg-secondary"><?=count($payments)?> records</span>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Reference</th><th>Student</th><th>Date</th><th>Type</th><th>Method</th><th>Amount</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($payments)):?>
      <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-money-bill fa-2x mb-2 d-block"></i>No payments recorded.</td></tr>
    <?php else: foreach($payments as $p):?>
      <tr>
        <td><span class="badge bg-secondary font-monospace"><?=e($p['reference']??'')?></span></td>
        <td class="fw-semibold"><?=e($p['student_name']??'—')?></td>
        <td><?=formatDate($p['payment_date'])?></td>
        <td><?=$typeLabels[$p['payment_type']]??e($p['payment_type'])?></td>
        <td><span class="badge bg-<?=$methodColors[$p['payment_method']]??'secondary'?>"><?=strtoupper($p['payment_method']??'')?></span></td>
        <td class="fw-bold text-success"><?=formatCurrency($p['amount'])?></td>
        <td><span class="badge bg-<?=$statusColors[$p['status']]??'secondary'?>"><?=ucfirst($p['status'])?></span></td>
        <td class="text-center" style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger ms-1" onclick="delPay(<?=$p['id']?>)" title="Delete"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    <?php endforeach; endif;?>
    </tbody>
  </table></div></div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="payModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="payId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="payTitle"><i class="fas fa-money-bill me-2"></i>Record Payment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">Student <span class="text-danger">*</span></label>
      <select name="student_id" id="payStudent" class="form-select" required>
        <option value="">— Select Student —</option>
        <?php foreach($students as $st):?><option value="<?=$st['id']?>"><?=e($st['first_name'].' '.$st['last_name'])?></option><?php endforeach;?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Payment Type</label>
      <select name="payment_type" id="payType" class="form-select">
        <?php foreach($typeLabels as $k=>$v):?><option value="<?=$k?>"><?=$v?></option><?php endforeach;?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
      <input type="number" name="amount" id="payAmount" class="form-control" step="0.01" min="0.01" required></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Payment Method</label>
      <select name="payment_method" id="payMethod" class="form-select">
        <?php foreach(['cash','mpesa','bank','card','cheque'] as $m):?><option value="<?=$m?>"><?=ucfirst($m)?></option><?php endforeach;?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Date</label>
      <input type="date" name="payment_date" id="payDate" class="form-control"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Reference No. <small class="text-muted">(auto-generated if blank)</small></label>
      <input type="text" name="reference" id="payRef" class="form-control" maxlength="100"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="payStatus" class="form-select">
        <?php foreach(['paid','pending','cancelled'] as $s):?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach;?>
      </select></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label>
      <textarea name="notes" id="payNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Payment</button>
  </div></form>
</div></div></div>
<form method="POST" id="delPayForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delPayId"></form>

<?php $extraJs=<<<'JS'
<script>
function openAdd(){
  document.getElementById('payTitle').innerHTML='<i class="fas fa-money-bill me-2"></i>Record Payment';
  document.getElementById('payId').value='0';
  document.getElementById('payStudent').value='';
  document.getElementById('payType').value='tuition';
  document.getElementById('payAmount').value='';
  document.getElementById('payMethod').value='cash';
  document.getElementById('payDate').value=new Date().toISOString().substring(0,10);
  document.getElementById('payRef').value='';
  document.getElementById('payStatus').value='paid';
  document.getElementById('payNotes').value='';
}
function openEdit(p){
  document.getElementById('payTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Payment';
  document.getElementById('payId').value=p.id;
  document.getElementById('payStudent').value=p.student_id||'';
  document.getElementById('payType').value=p.payment_type||'tuition';
  document.getElementById('payAmount').value=p.amount||'';
  document.getElementById('payMethod').value=p.payment_method||'cash';
  document.getElementById('payDate').value=p.payment_date?p.payment_date.substring(0,10):'';
  document.getElementById('payRef').value=p.reference||'';
  document.getElementById('payStatus').value=p.status||'paid';
  document.getElementById('payNotes').value=p.notes||'';
  new bootstrap.Modal(document.getElementById('payModal')).show();
}
function delPay(id){
  Swal.fire({title:'Delete Payment?',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delPayId').value=id;document.getElementById('delPayForm').submit();}});
}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
