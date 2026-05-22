<?php
$moduleSlug='pos';$moduleName='Point of Sale';$moduleIcon='fas fa-cash-register';$moduleColor='#e74c3c';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'terminal.php','icon'=>'fas fa-cash-register','label'=>'POS Terminal'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'categories.php','icon'=>'fas fa-tags','label'=>'Categories'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'suppliers.php','icon'=>'fas fa-truck','label'=>'Suppliers'],['url'=>'stock.php','icon'=>'fas fa-warehouse','label'=>'Stock'],['url'=>'purchases.php','icon'=>'fas fa-cart-arrow-down','label'=>'Purchases'],['url'=>'returns.php','icon'=>'fas fa-undo','label'=>'Returns'],['url'=>'shifts.php','icon'=>'fas fa-clock','label'=>'Shifts'],['url'=>'expenses.php','icon'=>'fas fa-wallet','label'=>'Expenses'],['url'=>'discounts.php','icon'=>'fas fa-percent','label'=>'Discounts'],['url'=>'sales.php','icon'=>'fas fa-receipt','label'=>'Sales History'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();
    $user=currentUser();$orgId=(int)$user['org_id'];
    $action=$_POST['action']??'';

    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $name=sanitize($_POST['name']??'');
        $phone=sanitize($_POST['phone']??'');
        $email=sanitize($_POST['email']??'');
        $address=sanitize($_POST['address']??'');
        $creditLimit=(float)($_POST['credit_limit']??0);
        $notes=sanitize($_POST['notes']??'');
        $status=in_array($_POST['status']??'',['active','inactive'])?$_POST['status']:'active';
        if(!$name){setFlash('error','Customer name is required.');redirect('customers.php');}
        if($id){
            $pdo->prepare("UPDATE pos_customers SET name=?,phone=?,email=?,address=?,credit_limit=?,notes=?,status=? WHERE id=? AND org_id=?")
               ->execute([$name,$phone,$email,$address,$creditLimit,$notes,$status,$id,$orgId]);
            setFlash('success','Customer updated.');
        } else {
            $pdo->prepare("INSERT INTO pos_customers (org_id,name,phone,email,address,credit_limit,notes,status) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$orgId,$name,$phone,$email,$address,$creditLimit,$notes,$status]);
            setFlash('success','Customer added.');
        }
        redirect('customers.php');
    }
    if($action==='delete'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM pos_customers WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Customer deleted.');
        redirect('customers.php');
    }
    if($action==='add_points'){
        $id=(int)($_POST['id']??0);
        $pts=(int)($_POST['points']??0);
        $pdo->prepare("UPDATE pos_customers SET loyalty_points=loyalty_points+? WHERE id=? AND org_id=?")->execute([$pts,$id,$orgId]);
        setFlash('success','Loyalty points updated.');
        redirect('customers.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];

$search=sanitize($_GET['q']??'');
$fStatus=$_GET['status']??'';
$view=(int)($_GET['view']??0);

$where='org_id=?';$params=[$orgId];
if($search){$where.=' AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)';$s="%$search%";$params=array_merge($params,[$s,$s,$s]);}
if($fStatus){$where.=' AND status=?';$params[]=$fStatus;}

$customers=[];
try{$s=$pdo->prepare("SELECT * FROM pos_customers WHERE $where ORDER BY name");$s->execute($params);$customers=$s->fetchAll();}catch(Exception $e){}

$viewData=null;$viewSales=[];
if($view){
    try{$s=$pdo->prepare("SELECT * FROM pos_customers WHERE id=? AND org_id=?");$s->execute([$view,$orgId]);$viewData=$s->fetch();}catch(Exception $e){}
    if($viewData){
        try{$s=$pdo->prepare("SELECT * FROM pos_sales WHERE org_id=? AND customer_id=? ORDER BY created_at DESC LIMIT 10");$s->execute([$orgId,$view]);$viewSales=$s->fetchAll();}catch(Exception $e){}
    }
}

$totalCustomers=countRows('pos_customers','org_id=?',[$orgId]);
$activeCustomers=countRows('pos_customers','org_id=? AND status=?',[$orgId,'active']);
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-users me-2" style="color:<?=$moduleColor?>"></i>Customers</h4><p class="text-muted mb-0">Manage customer profiles, loyalty points and credit accounts</p></div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#custModal"><i class="fas fa-plus me-2"></i>Add Customer</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value"><?=$totalCustomers?></div><div class="stat-label">Total Customers</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-user-check"></i></div><div class="stat-body"><div class="stat-value"><?=$activeCustomers?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-star"></i></div><div class="stat-body"><div class="stat-value"><?php try{$s=$pdo->prepare("SELECT COALESCE(SUM(loyalty_points),0) FROM pos_customers WHERE org_id=?");$s->execute([$orgId]);echo number_format((int)$s->fetchColumn());}catch(Exception $e){echo 0;} ?></div><div class="stat-label">Total Loyalty Points</div></div></div></div>
</div>

<div class="row g-3">
  <div class="<?=$view?'col-lg-7':'col-12'?>">
    <div class="card">
      <div class="card-header">
        <form class="row g-2 align-items-center" method="GET">
          <div class="col"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, phone, email..." value="<?=e($search)?>"></div>
          <div class="col-auto"><select name="status" class="form-select form-select-sm"><option value="">All Status</option><option value="active" <?=$fStatus==='active'?'selected':''?>>Active</option><option value="inactive" <?=$fStatus==='inactive'?'selected':''?>>Inactive</option></select></div>
          <div class="col-auto"><button class="btn btn-sm btn-success"><i class="fas fa-search"></i></button><a href="customers.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
        </form>
      </div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
        <thead class="table-light"><tr><th>Customer</th><th>Phone</th><th>Points</th><th>Credit</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($customers)):?><tr><td colspan="6" class="text-center text-muted py-4">No customers found.</td></tr>
        <?php else:foreach($customers as $c):?>
        <tr>
          <td><div class="fw-semibold"><?=e($c['name'])?></div><div class="small text-muted"><?=e($c['email'])?></div></td>
          <td><?=e($c['phone']??'—')?></td>
          <td><span class="badge bg-warning text-dark"><i class="fas fa-star me-1"></i><?=number_format($c['loyalty_points'])?></span></td>
          <td class="small"><?=formatCurrency($c['credit_limit'])?></td>
          <td><?=statusBadge($c['status'])?></td>
          <td>
            <a href="?view=<?=$c['id']?>" class="btn btn-xs btn-outline-primary me-1" title="View"><i class="fas fa-eye"></i></a>
            <button class="btn btn-xs btn-outline-secondary me-1 btn-edit"
              data-id="<?=$c['id']?>" data-name="<?=e($c['name'])?>" data-phone="<?=e($c['phone']??'')?>"
              data-email="<?=e($c['email']??'')?>" data-address="<?=e($c['address']??'')?>"
              data-credit_limit="<?=$c['credit_limit']?>" data-notes="<?=e($c['notes']??'')?>"
              data-status="<?=$c['status']?>"><i class="fas fa-edit"></i></button>
            <form method="POST" class="d-inline">
              <?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$c['id']?>">
              <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete customer <?=e($c['name'])?>?"><i class="fas fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach;endif;?>
        </tbody>
      </table></div></div>
    </div>
  </div>

  <?php if($view && $viewData):?>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-user me-2" style="color:<?=$moduleColor?>"></i><?=e($viewData['name'])?></h6>
        <a href="customers.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
      </div>
      <div class="card-body">
        <div class="row g-2 mb-3">
          <div class="col-6"><div class="small text-muted">Phone</div><div><?=e($viewData['phone']??'—')?></div></div>
          <div class="col-6"><div class="small text-muted">Email</div><div><?=e($viewData['email']??'—')?></div></div>
          <div class="col-6"><div class="small text-muted">Credit Limit</div><div class="fw-semibold"><?=formatCurrency($viewData['credit_limit'])?></div></div>
          <div class="col-6"><div class="small text-muted">Credit Used</div><div class="fw-semibold text-danger"><?=formatCurrency($viewData['credit_balance'])?></div></div>
          <div class="col-12"><div class="small text-muted">Address</div><div><?=e($viewData['address']??'—')?></div></div>
        </div>
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="fw-semibold"><i class="fas fa-star text-warning me-1"></i>Loyalty Points: <?=number_format($viewData['loyalty_points'])?></span>
          <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#pointsModal" data-id="<?=$viewData['id']?>" data-name="<?=e($viewData['name'])?>">Add Points</button>
        </div>
        <hr>
        <h6 class="small fw-bold text-muted mb-2">RECENT PURCHASES</h6>
        <?php if(empty($viewSales)):?><p class="text-muted small">No purchases yet.</p>
        <?php else:foreach($viewSales as $vs):?>
        <div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
          <div><span class="fw-semibold"><?=e($vs['receipt_no']??$vs['receipt_number']??('#'.$vs['id']))?></span><span class="text-muted ms-2"><?=formatDate($vs['created_at']??'')?></span></div>
          <span class="fw-semibold text-success"><?=formatCurrency($vs['total']??0)?></span>
        </div>
        <?php endforeach;endif;?>
      </div>
    </div>
  </div>
  <?php endif;?>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="custModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-user me-2"></i><span id="custModalTitle">Add Customer</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="custId" value="0">
    <div class="mb-3"><label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label><input type="text" name="name" id="custName" class="form-control" required></div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" id="custPhone" class="form-control"></div>
      <div class="col-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" id="custEmail" class="form-control"></div>
    </div>
    <div class="mb-3"><label class="form-label fw-semibold">Address</label><textarea name="address" id="custAddress" class="form-control" rows="2"></textarea></div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="form-label fw-semibold">Credit Limit</label><input type="number" name="credit_limit" id="custCredit" class="form-control" min="0" step="0.01" value="0"></div>
      <div class="col-6"><label class="form-label fw-semibold">Status</label><select name="status" id="custStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    </div>
    <div class="mb-3"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="custNotes" class="form-control" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Customer</button></div>
  </form>
</div></div></div>

<!-- Add Points Modal -->
<div class="modal fade" id="pointsModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-star me-2 text-warning"></i>Add Loyalty Points</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="add_points"><input type="hidden" name="id" id="ptsCustomerId">
    <p class="small text-muted mb-3">Adding points for: <strong id="ptsCustomerName"></strong></p>
    <div class="mb-3"><label class="form-label fw-semibold">Points to Add</label><input type="number" name="points" class="form-control" min="1" value="10" required></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning btn-sm text-dark">Add Points</button></div>
  </form>
</div></div></div>

<?php
$extraJs=<<<JS
<script>
document.querySelectorAll('.btn-edit').forEach(btn=>{
  btn.addEventListener('click',function(){
    document.getElementById('custModalTitle').textContent='Edit Customer';
    document.getElementById('custId').value=this.dataset.id;
    document.getElementById('custName').value=this.dataset.name;
    document.getElementById('custPhone').value=this.dataset.phone;
    document.getElementById('custEmail').value=this.dataset.email;
    document.getElementById('custAddress').value=this.dataset.address;
    document.getElementById('custCredit').value=this.dataset.credit_limit;
    document.getElementById('custNotes').value=this.dataset.notes;
    document.getElementById('custStatus').value=this.dataset.status;
    new bootstrap.Modal(document.getElementById('custModal')).show();
  });
});
document.getElementById('pointsModal').addEventListener('show.bs.modal',function(e){
  const btn=e.relatedTarget;
  document.getElementById('ptsCustomerId').value=btn.dataset.id;
  document.getElementById('ptsCustomerName').textContent=btn.dataset.name;
});
document.querySelectorAll('.btn-confirm').forEach(btn=>{
  btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});
});
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
