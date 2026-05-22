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
        $code=strtoupper(sanitize($_POST['code']??''));
        $name=sanitize($_POST['name']??'');
        $type=in_array($_POST['type']??'',['percentage','fixed'])?$_POST['type']:'percentage';
        $value=(float)($_POST['value']??0);
        $minPurchase=(float)($_POST['min_purchase']??0);
        $maxUses=$_POST['max_uses']!==''?(int)$_POST['max_uses']:null;
        $validFrom=sanitize($_POST['valid_from']??'')?:null;
        $validTo=sanitize($_POST['valid_to']??'')?:null;
        $status=in_array($_POST['status']??'',['active','inactive','expired'])?$_POST['status']:'active';
        if(!$code||!$name||$value<=0){setFlash('error','Code, name and value are required.');redirect('discounts.php');}
        if($type==='percentage'&&$value>100){setFlash('error','Percentage cannot exceed 100%.');redirect('discounts.php');}
        try{
            if($id){
                $pdo->prepare("UPDATE pos_discounts SET code=?,name=?,type=?,value=?,min_purchase=?,max_uses=?,valid_from=?,valid_to=?,status=? WHERE id=? AND org_id=?")
                   ->execute([$code,$name,$type,$value,$minPurchase,$maxUses,$validFrom,$validTo,$status,$id,$orgId]);
                setFlash('success','Discount updated.');
            } else {
                $pdo->prepare("INSERT INTO pos_discounts (org_id,code,name,type,value,min_purchase,max_uses,valid_from,valid_to,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$orgId,$code,$name,$type,$value,$minPurchase,$maxUses,$validFrom,$validTo,$status]);
                setFlash('success','Discount code created.');
            }
        } catch(Exception $e){
            setFlash('error','Code already exists for this organisation.');
        }
        redirect('discounts.php');
    }
    if($action==='delete'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM pos_discounts WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Discount deleted.');redirect('discounts.php');
    }
    if($action==='toggle'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("UPDATE pos_discounts SET status=IF(status='active','inactive','active') WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        redirect('discounts.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fStatus=$_GET['status']??'';

$where='org_id=?';$params=[$orgId];
if($fStatus){$where.=' AND status=?';$params[]=$fStatus;}

$discounts=[];
try{$s=$pdo->prepare("SELECT * FROM pos_discounts WHERE $where ORDER BY created_at DESC");$s->execute($params);$discounts=$s->fetchAll();}catch(Exception $e){}

$active=countRows('pos_discounts','org_id=? AND status=?',[$orgId,'active']);
$expired=countRows('pos_discounts','org_id=? AND status=?',[$orgId,'expired']);
$totalUsed=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(uses_count),0) FROM pos_discounts WHERE org_id=?");$s->execute([$orgId]);$totalUsed=(int)$s->fetchColumn();}catch(Exception $e){}
?>
<?= flashAlert() ?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-percent me-2" style="color:<?=$moduleColor?>"></i>Discounts & Promotions</h4><p class="text-muted mb-0">Create discount codes and promotional offers</p></div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#discModal"><i class="fas fa-plus me-2"></i>New Discount</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-tags"></i></div><div class="stat-body"><div class="stat-value"><?=countRows('pos_discounts','org_id=?',[$orgId])?></div><div class="stat-label">Total Codes</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$active?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?=$expired?></div><div class="stat-label">Expired</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-bolt"></i></div><div class="stat-body"><div class="stat-value"><?=$totalUsed?></div><div class="stat-label">Total Uses</div></div></div></div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-tags me-2" style="color:<?=$moduleColor?>"></i>Discount Codes</h6>
    <form class="d-flex gap-2" method="GET">
      <select name="status" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()"><option value="">All Status</option><option value="active" <?=$fStatus==='active'?'selected':''?>>Active</option><option value="inactive" <?=$fStatus==='inactive'?'selected':''?>>Inactive</option><option value="expired" <?=$fStatus==='expired'?'selected':''?>>Expired</option></select>
    </form>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Code</th><th>Name</th><th>Type</th><th>Value</th><th>Min Purchase</th><th>Valid Period</th><th>Uses</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($discounts)):?><tr><td colspan="9" class="text-center text-muted py-4">No discount codes found.</td></tr>
    <?php else:foreach($discounts as $d):
      $now=date('Y-m-d');
      $isExpired=$d['valid_to']&&$d['valid_to']<$now;
      $notStarted=$d['valid_from']&&$d['valid_from']>$now;
      $maxReached=$d['max_uses']!==null&&$d['uses_count']>=$d['max_uses'];
      $effectiveStatus=$isExpired?'expired':($maxReached?'exhausted':$d['status']);
      $stC=['active'=>'success','inactive'=>'secondary','expired'=>'danger','exhausted'=>'warning'][$effectiveStatus]??'secondary';
    ?>
    <tr class="<?=$effectiveStatus==='expired'||$effectiveStatus==='exhausted'?'table-light':''?>">
      <td><code class="fs-6 fw-bold" style="color:<?=$moduleColor?>"><?=e($d['code'])?></code></td>
      <td class="fw-semibold"><?=e($d['name'])?></td>
      <td><span class="badge bg-<?=$d['type']==='percentage'?'info':'success'?>"><?=ucfirst($d['type'])?></span></td>
      <td class="fw-bold"><?=$d['type']==='percentage'?$d['value'].'%':formatCurrency($d['value'])?></td>
      <td class="small"><?=$d['min_purchase']>0?formatCurrency($d['min_purchase']):'No minimum'?></td>
      <td class="small"><?=($d['valid_from']?formatDate($d['valid_from']):'Any').' → '.($d['valid_to']?formatDate($d['valid_to']):'No expiry')?></td>
      <td class="text-center"><?=$d['uses_count']?>/<?=$d['max_uses']??'∞'?></td>
      <td><span class="badge bg-<?=$stC?>"><?=ucfirst($effectiveStatus)?></span></td>
      <td>
        <form method="POST" class="d-inline">
          <?=csrfField()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$d['id']?>">
          <button type="submit" class="btn btn-xs btn-outline-<?=$d['status']==='active'?'warning':'success'?> me-1" title="<?=$d['status']==='active'?'Deactivate':'Activate'?>"><i class="fas fa-<?=$d['status']==='active'?'pause':'play'?>"></i></button>
        </form>
        <button class="btn btn-xs btn-outline-secondary me-1 btn-edit"
          data-id="<?=$d['id']?>" data-code="<?=e($d['code'])?>" data-name="<?=e($d['name'])?>"
          data-type="<?=$d['type']?>" data-value="<?=$d['value']?>"
          data-min_purchase="<?=$d['min_purchase']?>" data-max_uses="<?=$d['max_uses']??''?>"
          data-valid_from="<?=$d['valid_from']??''?>" data-valid_to="<?=$d['valid_to']??''?>"
          data-status="<?=$d['status']?>"><i class="fas fa-edit"></i></button>
        <form method="POST" class="d-inline">
          <?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$d['id']?>">
          <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete code <?=e($d['code'])?>?"><i class="fas fa-trash"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
  </table></div></div>
</div>

<!-- Modal -->
<div class="modal fade" id="discModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-percent me-2"></i><span id="discModalTitle">New Discount Code</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="discId" value="0">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label fw-semibold">Discount Code <span class="text-danger">*</span></label>
        <div class="input-group"><input type="text" name="code" id="discCode" class="form-control text-uppercase" required placeholder="e.g. SAVE20"><button type="button" class="btn btn-outline-secondary" onclick="genCode()"><i class="fas fa-dice"></i></button></div>
      </div>
      <div class="col-md-8"><label class="form-label fw-semibold">Discount Name <span class="text-danger">*</span></label><input type="text" name="name" id="discName" class="form-control" required placeholder="e.g. 20% Off All Items"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
        <select name="type" id="discType" class="form-select" onchange="updateValueLabel()"><option value="percentage">Percentage (%)</option><option value="fixed">Fixed Amount (KES)</option></select>
      </div>
      <div class="col-md-4"><label class="form-label fw-semibold"><span id="discValueLabel">Percentage</span> <span class="text-danger">*</span></label><input type="number" name="value" id="discValue" class="form-control" min="0.01" step="0.01" required></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Minimum Purchase (KES)</label><input type="number" name="min_purchase" id="discMinPurchase" class="form-control" min="0" step="0.01" value="0"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Max Uses (blank = unlimited)</label><input type="number" name="max_uses" id="discMaxUses" class="form-control" min="1" placeholder="Unlimited"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Valid From</label><input type="date" name="valid_from" id="discValidFrom" class="form-control"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Valid To (Expiry)</label><input type="date" name="valid_to" id="discValidTo" class="form-control"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="discStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Discount</button></div>
  </form>
</div></div></div>

<?php $extraJs=<<<JS
<script>
function updateValueLabel(){
  const t=document.getElementById('discType').value;
  document.getElementById('discValueLabel').textContent=t==='percentage'?'Percentage (%)':'Fixed Amount (KES)';
}
function genCode(){
  const chars='ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  let code='';for(let i=0;i<8;i++)code+=chars[Math.floor(Math.random()*chars.length)];
  document.getElementById('discCode').value=code;
}
document.querySelectorAll('.btn-edit').forEach(btn=>{
  btn.addEventListener('click',function(){
    document.getElementById('discModalTitle').textContent='Edit Discount';
    document.getElementById('discId').value=this.dataset.id;
    document.getElementById('discCode').value=this.dataset.code;
    document.getElementById('discName').value=this.dataset.name;
    document.getElementById('discType').value=this.dataset.type;
    document.getElementById('discValue').value=this.dataset.value;
    document.getElementById('discMinPurchase').value=this.dataset.min_purchase;
    document.getElementById('discMaxUses').value=this.dataset.max_uses;
    document.getElementById('discValidFrom').value=this.dataset.valid_from;
    document.getElementById('discValidTo').value=this.dataset.valid_to;
    document.getElementById('discStatus').value=this.dataset.status;
    updateValueLabel();
    new bootstrap.Modal(document.getElementById('discModal')).show();
  });
});
document.querySelectorAll('.btn-confirm').forEach(btn=>{btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});});
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
