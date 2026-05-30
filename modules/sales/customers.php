<?php
$moduleSlug='sales';$moduleName='Sales Management';$moduleIcon='fas fa-chart-line';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'targets.php','icon'=>'fas fa-bullseye','label'=>'Targets'],['url'=>'returns.php','icon'=>'fas fa-undo-alt','label'=>'Returns'],['url'=>'payments.php','icon'=>'fas fa-money-check-alt','label'=>'Payments'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);$name=sanitize($_POST['name']??'');$email=sanitize($_POST['email']??'');
        $phone=sanitize($_POST['phone']??'');$address=sanitize($_POST['address']??'');
        $type=in_array($_POST['type']??'',['individual','business'])?$_POST['type']:'individual';
        $credit=(float)($_POST['credit_limit']??0);$status=($_POST['status']??'')==='inactive'?'inactive':'active';
        if($id>0){$pdo->prepare("UPDATE sales_customers SET name=?,email=?,phone=?,address=?,type=?,credit_limit=?,status=? WHERE id=? AND org_id=?")->execute([$name,$email,$phone,$address,$type,$credit,$status,$id,$orgId]);setFlash('success','Customer updated.');}
        else{$pdo->prepare("INSERT INTO sales_customers(org_id,name,email,phone,address,type,credit_limit,status)VALUES(?,?,?,?,?,?,?,?)")->execute([$orgId,$name,$email,$phone,$address,$type,$credit,$status]);setFlash('success',"Customer '$name' added.");}
        logActivity($id>0?'update':'create','sales',"Customer: $name");redirect('customers.php');
    }
    if($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM sales_customers WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Customer deleted.');redirect('customers.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fType=$_GET['type']??'';$fStatus=$_GET['status']??'';$fQ=trim($_GET['q']??'');
$where='org_id=?';$params=[$orgId];
if($fType){$where.=' AND type=?';$params[]=$fType;}
if($fStatus){$where.=' AND status=?';$params[]=$fStatus;}
if($fQ){$where.=' AND(name LIKE ? OR email LIKE ? OR phone LIKE ?)';$like="%$fQ%";array_push($params,$like,$like,$like);}
$customers=[];try{$stmt=$pdo->prepare("SELECT * FROM sales_customers WHERE $where ORDER BY name");$stmt->execute($params);$customers=$stmt->fetchAll();}catch(Exception $e){}
$total=countRows('sales_customers','org_id=?',[$orgId]);$active=countRows('sales_customers','org_id=? AND status=?',[$orgId,'active']);
$biz=countRows('sales_customers','org_id=? AND type=?',[$orgId,'business']);$ind=countRows('sales_customers','org_id=? AND type=?',[$orgId,'individual']);
$viewC=null;if(isset($_GET['view'])){$stmt=$pdo->prepare("SELECT * FROM sales_customers WHERE id=? AND org_id=?");$stmt->execute([(int)$_GET['view'],$orgId]);$viewC=$stmt->fetch();}
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-users me-2" style="color:<?=$moduleColor?>"></i>Customers</h4><p class="text-muted mb-0">Manage your customer base</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#cuModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Customer</button>
</div>
<div class="row g-3 mb-4">
  <?php foreach([['green-bg','fas fa-users',$total,'Total'],['navy-bg','fas fa-user-check',$active,'Active'],['warning-bg','fas fa-building',$biz,'Business'],['info-bg','fas fa-user',$ind,'Individual']] as [$cl,$ic,$v,$lb]):?>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon <?=$cl?>"><i class="<?=$ic?>"></i></div><div class="stat-body"><div class="stat-value"><?=$v?></div><div class="stat-label"><?=$lb?></div></div></div></div>
  <?php endforeach;?>
</div>
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Search</label><input type="text" name="q" class="form-control form-control-sm" placeholder="Name, email, phone…" value="<?=e($fQ)?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Type</label><select name="type" class="form-select form-select-sm"><option value="">All Types</option><option value="individual" <?=$fType==='individual'?'selected':''?>>Individual</option><option value="business" <?=$fType==='business'?'selected':''?>>Business</option></select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><option value="active" <?=$fStatus==='active'?'selected':''?>>Active</option><option value="inactive" <?=$fStatus==='inactive'?'selected':''?>>Inactive</option></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="customers.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>
<div class="card"><div class="card-header d-flex align-items-center justify-content-between"><h6 class="mb-0"><i class="fas fa-users me-2" style="color:<?=$moduleColor?>"></i>Customer List</h6><span class="badge bg-secondary"><?=count($customers)?></span></div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Name</th><th>Email</th><th>Phone</th><th>Type</th><th>Credit Limit</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($customers)):?><tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No customers found.</td></tr>
<?php else:foreach($customers as $c):?>
<tr>
  <td><div class="d-flex align-items-center gap-2"><div style="width:34px;height:34px;border-radius:50%;background:<?=$moduleColor?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:600;flex-shrink:0"><?=strtoupper(substr($c['name'],0,2))?></div><div class="fw-semibold"><?=e($c['name'])?></div></div></td>
  <td><?=e($c['email']??'—')?></td><td><?=e($c['phone']??'—')?></td>
  <td><span class="badge bg-info text-dark"><?=ucfirst($c['type'])?></span></td>
  <td><?=formatCurrency((float)($c['credit_limit']??0))?></td>
  <td><?=statusBadge($c['status']??'active')?></td>
  <td class="text-center" style="white-space:nowrap">
    <a href="?view=<?=$c['id']?>" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
    <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEdit(<?=htmlspecialchars(json_encode($c),ENT_QUOTES)?>)'><i class="fas fa-edit"></i></button>
    <button class="btn btn-sm btn-outline-danger ms-1" onclick="delCust(<?=$c['id']?>,'<?=e($c['name'])?>')"><i class="fas fa-trash"></i></button>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<?php if($viewC):?>
<div class="card mt-4">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:<?=$moduleColor?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-id-card me-2"></i>Customer: <?=e($viewC['name'])?></h6>
    <a href="customers.php" class="btn btn-sm btn-light"><i class="fas fa-times me-1"></i>Close</a>
  </div>
  <div class="card-body"><div class="row g-3">
    <div class="col-md-6"><table class="table table-sm">
      <tr><th class="text-muted w-40">Name</th><td class="fw-semibold"><?=e($viewC['name'])?></td></tr>
      <tr><th class="text-muted">Email</th><td><?=e($viewC['email']??'—')?></td></tr>
      <tr><th class="text-muted">Phone</th><td><?=e($viewC['phone']??'—')?></td></tr>
      <tr><th class="text-muted">Type</th><td><span class="badge bg-info text-dark"><?=ucfirst($viewC['type']??'individual')?></span></td></tr>
    </table></div>
    <div class="col-md-6"><table class="table table-sm">
      <tr><th class="text-muted w-40">Credit Limit</th><td><?=formatCurrency((float)($viewC['credit_limit']??0))?></td></tr>
      <tr><th class="text-muted">Status</th><td><?=statusBadge($viewC['status']??'active')?></td></tr>
      <tr><th class="text-muted">Since</th><td><?=formatDate($viewC['created_at'])?></td></tr>
    </table></div>
    <?php if(!empty($viewC['address'])):?><div class="col-12"><p class="text-muted mb-0"><strong>Address:</strong> <?=e($viewC['address'])?></p></div><?php endif;?>
  </div>
  <?php
  $custOrders=[];try{$stmt=$pdo->prepare("SELECT * FROM sales_orders WHERE org_id=? AND customer_id=? ORDER BY created_at DESC LIMIT 8");$stmt->execute([$orgId,$viewC['id']]);$custOrders=$stmt->fetchAll();}catch(Exception $e){}
  if(!empty($custOrders)):?>
  <h6 class="fw-semibold mt-3 mb-2">Recent Orders</h6>
  <div class="table-responsive"><table class="table table-sm table-bordered">
    <thead class="table-light"><tr><th>Order #</th><th>Date</th><th>Status</th><th class="text-end">Total</th></tr></thead>
    <tbody><?php foreach($custOrders as $o):?>
    <tr><td><?=e($o['order_no']??'#'.$o['id'])?></td><td><?=formatDate($o['created_at'])?></td><td><?=statusBadge($o['status']??'pending')?></td><td class="text-end fw-semibold"><?=formatCurrency((float)($o['total']??0))?></td></tr>
    <?php endforeach;?></tbody>
  </table></div>
  <?php endif;?>
  </div>
</div>
<?php endif;?>

<div class="modal fade" id="cuModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="cuId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="cuTitle"><i class="fas fa-users me-2"></i>Add Customer</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">Name <span class="text-danger">*</span></label><input type="text" name="name" id="cuName" class="form-control" required maxlength="255"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Type</label><select name="type" id="cuType" class="form-select"><option value="individual">Individual</option><option value="business">Business</option></select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" id="cuEmail" class="form-control"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" id="cuPhone" class="form-control" maxlength="25"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Credit Limit (<?=CURRENCY_SYMBOL?>)</label><input type="number" name="credit_limit" id="cuCredit" class="form-control" step="0.01" min="0" value="0"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select name="status" id="cuStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    <div class="col-12"><label class="form-label fw-semibold">Address</label><textarea name="address" id="cuAddress" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Customer</button></div>
  </form>
</div></div></div>
<form method="POST" id="delCuForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delCuId"></form>
<?php
$extraJs=<<<'JS'
<script>
function openAdd(){document.getElementById('cuTitle').innerHTML='<i class="fas fa-users me-2"></i>Add Customer';['cuId','cuName','cuEmail','cuPhone','cuAddress'].forEach(i=>document.getElementById(i).value=i==='cuId'?'0':'');document.getElementById('cuType').value='individual';document.getElementById('cuCredit').value=0;document.getElementById('cuStatus').value='active';}
function openEdit(c){document.getElementById('cuTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Customer';document.getElementById('cuId').value=c.id;document.getElementById('cuName').value=c.name||'';document.getElementById('cuType').value=c.type||'individual';document.getElementById('cuEmail').value=c.email||'';document.getElementById('cuPhone').value=c.phone||'';document.getElementById('cuCredit').value=c.credit_limit||0;document.getElementById('cuStatus').value=c.status||'active';document.getElementById('cuAddress').value=c.address||'';new bootstrap.Modal(document.getElementById('cuModal')).show();}
function delCust(id,name){Swal.fire({title:'Delete Customer?',text:name+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delCuId').value=id;document.getElementById('delCuForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
