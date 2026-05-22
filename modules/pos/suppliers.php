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
        $fields=['name','contact_person','phone','email','address','tax_pin','payment_terms','notes'];
        $vals=array_map(fn($f)=>sanitize($_POST[$f]??''),$fields);
        $status=in_array($_POST['status']??'',['active','inactive'])?$_POST['status']:'active';
        if(!$vals[0]){setFlash('error','Supplier name required.');redirect('suppliers.php');}
        if($id){
            $pdo->prepare("UPDATE pos_suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,tax_pin=?,payment_terms=?,notes=?,status=? WHERE id=? AND org_id=?")
               ->execute([...$vals,$status,$id,$orgId]);
            setFlash('success','Supplier updated.');
        } else {
            $pdo->prepare("INSERT INTO pos_suppliers (org_id,name,contact_person,phone,email,address,tax_pin,payment_terms,notes,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$orgId,...$vals,$status]);
            setFlash('success','Supplier added.');
        }
        redirect('suppliers.php');
    }
    if($action==='delete'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM pos_suppliers WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Supplier deleted.');redirect('suppliers.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$search=sanitize($_GET['q']??'');
$fStatus=$_GET['status']??'';
$view=(int)($_GET['view']??0);

$where='org_id=?';$params=[$orgId];
if($search){$where.=' AND (name LIKE ? OR contact_person LIKE ? OR phone LIKE ?)';$s="%$search%";$params=array_merge($params,[$s,$s,$s]);}
if($fStatus){$where.=' AND status=?';$params[]=$fStatus;}

$suppliers=[];
try{$st=$pdo->prepare("SELECT * FROM pos_suppliers WHERE $where ORDER BY name");$st->execute($params);$suppliers=$st->fetchAll();}catch(Exception $e){}

$viewData=null;$viewPOs=[];
if($view){
    try{$st=$pdo->prepare("SELECT * FROM pos_suppliers WHERE id=? AND org_id=?");$st->execute([$view,$orgId]);$viewData=$st->fetch();}catch(Exception $e){}
    if($viewData){
        try{$st=$pdo->prepare("SELECT * FROM pos_purchases WHERE org_id=? AND supplier_id=? ORDER BY order_date DESC LIMIT 8");$st->execute([$orgId,$view]);$viewPOs=$st->fetchAll();}catch(Exception $e){}
    }
}
?>
<?= flashAlert() ?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-truck me-2" style="color:<?=$moduleColor?>"></i>Suppliers</h4><p class="text-muted mb-0">Manage your product suppliers and vendors</p></div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#supModal"><i class="fas fa-plus me-2"></i>Add Supplier</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-truck"></i></div><div class="stat-body"><div class="stat-value"><?=countRows('pos_suppliers','org_id=?',[$orgId])?></div><div class="stat-label">Total Suppliers</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=countRows('pos_suppliers','org_id=? AND status=?',[$orgId,'active'])?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-shopping-cart"></i></div><div class="stat-body"><div class="stat-value"><?=countRows('pos_purchases','org_id=?',[$orgId])?></div><div class="stat-label">Purchase Orders</div></div></div></div>
</div>

<div class="row g-3">
  <div class="<?=$view?'col-lg-7':'col-12'?>">
    <div class="card">
      <div class="card-header">
        <form class="row g-2 align-items-center" method="GET">
          <div class="col"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, contact, phone..." value="<?=e($search)?>"></div>
          <div class="col-auto"><select name="status" class="form-select form-select-sm"><option value="">All Status</option><option value="active" <?=$fStatus==='active'?'selected':''?>>Active</option><option value="inactive" <?=$fStatus==='inactive'?'selected':''?>>Inactive</option></select></div>
          <div class="col-auto"><button class="btn btn-sm btn-success"><i class="fas fa-search"></i></button><a href="suppliers.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
        </form>
      </div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
        <thead class="table-light"><tr><th>Supplier</th><th>Contact</th><th>Phone</th><th>Payment Terms</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($suppliers)):?><tr><td colspan="6" class="text-center text-muted py-4">No suppliers found.</td></tr>
        <?php else:foreach($suppliers as $sup):?>
        <tr>
          <td><div class="fw-semibold"><?=e($sup['name'])?></div><div class="small text-muted"><?=e($sup['email']??'')?></div></td>
          <td><?=e($sup['contact_person']??'—')?></td>
          <td><?=e($sup['phone']??'—')?></td>
          <td class="small"><?=e($sup['payment_terms']??'—')?></td>
          <td><?=statusBadge($sup['status'])?></td>
          <td>
            <a href="?view=<?=$sup['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="fas fa-eye"></i></a>
            <button class="btn btn-xs btn-outline-secondary me-1 btn-edit"
              data-id="<?=$sup['id']?>" data-name="<?=e($sup['name'])?>"
              data-contact_person="<?=e($sup['contact_person']??'')?>" data-phone="<?=e($sup['phone']??'')?>"
              data-email="<?=e($sup['email']??'')?>" data-address="<?=e($sup['address']??'')?>"
              data-tax_pin="<?=e($sup['tax_pin']??'')?>" data-payment_terms="<?=e($sup['payment_terms']??'')?>"
              data-notes="<?=e($sup['notes']??'')?>" data-status="<?=$sup['status']?>"><i class="fas fa-edit"></i></button>
            <form method="POST" class="d-inline">
              <?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$sup['id']?>">
              <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete supplier <?=e($sup['name'])?>?"><i class="fas fa-trash"></i></button>
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
        <h6 class="mb-0"><i class="fas fa-truck me-2" style="color:<?=$moduleColor?>"></i><?=e($viewData['name'])?></h6>
        <a href="suppliers.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
      </div>
      <div class="card-body">
        <div class="row g-2 mb-3 small">
          <div class="col-6"><div class="text-muted">Contact Person</div><div class="fw-semibold"><?=e($viewData['contact_person']??'—')?></div></div>
          <div class="col-6"><div class="text-muted">Phone</div><div><?=e($viewData['phone']??'—')?></div></div>
          <div class="col-6"><div class="text-muted">Email</div><div><?=e($viewData['email']??'—')?></div></div>
          <div class="col-6"><div class="text-muted">Tax PIN</div><div><?=e($viewData['tax_pin']??'—')?></div></div>
          <div class="col-6"><div class="text-muted">Payment Terms</div><div><?=e($viewData['payment_terms']??'—')?></div></div>
          <div class="col-12"><div class="text-muted">Address</div><div><?=e($viewData['address']??'—')?></div></div>
        </div>
        <a href="purchases.php?supplier_id=<?=$viewData['id']?>" class="btn btn-sm text-white mb-3" style="background:<?=$moduleColor?>"><i class="fas fa-plus me-1"></i>New Purchase Order</a>
        <h6 class="small fw-bold text-muted mb-2">RECENT PURCHASE ORDERS</h6>
        <?php if(empty($viewPOs)):?><p class="text-muted small">No purchase orders yet.</p>
        <?php else:foreach($viewPOs as $po):
          $stColor=['draft'=>'secondary','ordered'=>'primary','partial'=>'warning','received'=>'success','cancelled'=>'danger'][$po['status']]??'secondary';?>
        <div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
          <div><span class="fw-semibold"><?=e($po['po_number'])?></span><span class="text-muted ms-2"><?=formatDate($po['order_date']??'')?></span></div>
          <div><span class="badge bg-<?=$stColor?> me-2"><?=ucfirst($po['status'])?></span><span class="fw-semibold"><?=formatCurrency($po['total'])?></span></div>
        </div>
        <?php endforeach;endif;?>
      </div>
    </div>
  </div>
  <?php endif;?>
</div>

<!-- Modal -->
<div class="modal fade" id="supModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-truck me-2"></i><span id="supModalTitle">Add Supplier</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="supId" value="0">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label fw-semibold">Supplier Name <span class="text-danger">*</span></label><input type="text" name="name" id="supName" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Contact Person</label><input type="text" name="contact_person" id="supContact" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" id="supPhone" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" id="supEmail" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Tax PIN</label><input type="text" name="tax_pin" id="supTaxPin" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Payment Terms</label><input type="text" name="payment_terms" id="supTerms" class="form-control" placeholder="e.g. Net 30, COD"></div>
      <div class="col-12"><label class="form-label fw-semibold">Address</label><textarea name="address" id="supAddress" class="form-control" rows="2"></textarea></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select name="status" id="supStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="supNotes" class="form-control" rows="2"></textarea></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Supplier</button></div>
  </form>
</div></div></div>

<?php $extraJs=<<<JS
<script>
document.querySelectorAll('.btn-edit').forEach(btn=>{
  btn.addEventListener('click',function(){
    document.getElementById('supModalTitle').textContent='Edit Supplier';
    ['id','name','contact_person','phone','email','address','tax_pin','payment_terms','notes','status'].forEach(f=>{
      const el=document.getElementById('sup'+f.charAt(0).toUpperCase()+f.slice(1).replace('_p','P').replace('_t','T').replace('_pin','Pin').replace('_terms','Terms').replace('contact_person','Contact'));
      if(el)el.value=this.dataset[f]??'';
    });
    document.getElementById('supId').value=this.dataset.id;
    document.getElementById('supContact').value=this.dataset.contact_person||'';
    document.getElementById('supTaxPin').value=this.dataset.tax_pin||'';
    document.getElementById('supTerms').value=this.dataset.payment_terms||'';
    new bootstrap.Modal(document.getElementById('supModal')).show();
  });
});
document.querySelectorAll('.btn-confirm').forEach(btn=>{
  btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});
});
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
