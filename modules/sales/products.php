<?php
$moduleSlug='sales';$moduleName='Sales Management';$moduleIcon='fas fa-chart-line';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);$name=sanitize($_POST['name']??'');$sku=sanitize($_POST['sku']??'');
        $cat=sanitize($_POST['category']??'');$unit=sanitize($_POST['unit']??'');
        $price=(float)($_POST['price']??0);$tax=(float)($_POST['tax_rate']??0);$stock=(int)($_POST['stock']??0);
        $status=($_POST['status']??'')==='inactive'?'inactive':'active';
        if($id>0){$pdo->prepare("UPDATE sales_products SET name=?,sku=?,category=?,unit=?,price=?,tax_rate=?,stock=?,status=? WHERE id=? AND org_id=?")->execute([$name,$sku,$cat,$unit,$price,$tax,$stock,$status,$id,$orgId]);setFlash('success','Product updated.');}
        else{
            if($sku===''){$c=countRows('sales_products','org_id=?',[$orgId]);$sku='PRD-'.str_pad($c+1,4,'0',STR_PAD_LEFT);}
            $pdo->prepare("INSERT INTO sales_products(org_id,name,sku,category,unit,price,tax_rate,stock,status)VALUES(?,?,?,?,?,?,?,?,?)")->execute([$orgId,$name,$sku,$cat,$unit,$price,$tax,$stock,$status]);
            setFlash('success',"Product '$name' added.");
        }
        logActivity($id>0?'update':'create','sales',"Product: $name");redirect('products.php');
    }
    if($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM sales_products WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Product deleted.');redirect('products.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fCat=$_GET['cat']??'';$fStatus=$_GET['status']??'';$fQ=trim($_GET['q']??'');
$where='org_id=?';$params=[$orgId];
if($fCat){$where.=' AND category=?';$params[]=$fCat;}
if($fStatus){$where.=' AND status=?';$params[]=$fStatus;}
if($fQ){$where.=' AND(name LIKE ? OR sku LIKE ?)';$like="%$fQ%";array_push($params,$like,$like);}
$products=[];try{$stmt=$pdo->prepare("SELECT * FROM sales_products WHERE $where ORDER BY name");$stmt->execute($params);$products=$stmt->fetchAll();}catch(Exception $e){}
$categories=[];try{$stmt=$pdo->prepare("SELECT DISTINCT category FROM sales_products WHERE org_id=? AND category!='' ORDER BY category");$stmt->execute([$orgId]);$categories=$stmt->fetchAll(PDO::FETCH_COLUMN);}catch(Exception $e){}
$total=countRows('sales_products','org_id=?',[$orgId]);$active=countRows('sales_products','org_id=? AND status=?',[$orgId,'active']);
$lowStock=0;try{$s=$pdo->prepare("SELECT COUNT(*) FROM sales_products WHERE org_id=? AND stock<10 AND status='active'");$s->execute([$orgId]);$lowStock=(int)$s->fetchColumn();}catch(Exception $e){}
$totalVal=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(price*stock),0) FROM sales_products WHERE org_id=? AND status='active'");$s->execute([$orgId]);$totalVal=(float)$s->fetchColumn();}catch(Exception $e){}
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-box me-2" style="color:<?=$moduleColor?>"></i>Products</h4><p class="text-muted mb-0">Manage your product catalog and pricing</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#pModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Product</button>
</div>
<div class="row g-3 mb-4">
  <?php foreach([['green-bg','fas fa-box',$total,'Total Products'],['navy-bg','fas fa-check-circle',$active,'Active'],['danger-bg','fas fa-exclamation-triangle',$lowStock,'Low Stock (<10)'],['warning-bg','fas fa-dollar-sign',formatCurrency($totalVal),'Stock Value']] as [$cl,$ic,$v,$lb]):?>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon <?=$cl?>"><i class="<?=$ic?>"></i></div><div class="stat-body"><div class="stat-value" style="font-size:1.1rem"><?=$v?></div><div class="stat-label"><?=$lb?></div></div></div></div>
  <?php endforeach;?>
</div>
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Search</label><input type="text" name="q" class="form-control form-control-sm" placeholder="Name or SKU…" value="<?=e($fQ)?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Category</label><select name="cat" class="form-select form-select-sm"><option value="">All Categories</option><?php foreach($categories as $cat):?><option value="<?=e($cat)?>" <?=$fCat===$cat?'selected':''?>><?=e($cat)?></option><?php endforeach;?></select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><option value="active" <?=$fStatus==='active'?'selected':''?>>Active</option><option value="inactive" <?=$fStatus==='inactive'?'selected':''?>>Inactive</option></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="products.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>
<div class="card"><div class="card-header d-flex align-items-center justify-content-between"><h6 class="mb-0"><i class="fas fa-box me-2" style="color:<?=$moduleColor?>"></i>Product Catalog</h6><span class="badge bg-secondary"><?=count($products)?> products</span></div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>SKU</th><th>Name</th><th>Category</th><th>Unit</th><th class="text-end">Price</th><th>Tax%</th><th class="text-end">Stock</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($products)):?><tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No products found.</td></tr>
<?php else:foreach($products as $p):?>
<tr>
  <td class="fw-semibold text-muted small"><?=e($p['sku']??'—')?></td>
  <td class="fw-semibold"><?=e($p['name'])?></td>
  <td><?=e($p['category']??'—')?></td>
  <td><?=e($p['unit']??'—')?></td>
  <td class="text-end"><?=formatCurrency((float)$p['price'])?></td>
  <td><?=$p['tax_rate']?>%</td>
  <td class="text-end <?=$p['stock']<10?'text-danger fw-bold':''?>"><?=(int)$p['stock']?></td>
  <td><?=statusBadge($p['status']??'active')?></td>
  <td class="text-center" style="white-space:nowrap">
    <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i></button>
    <button class="btn btn-sm btn-outline-danger ms-1" onclick="delProd(<?=$p['id']?>,'<?=e($p['name'])?>')"><i class="fas fa-trash"></i></button>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<div class="modal fade" id="pModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="pId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title" id="pTitle"><i class="fas fa-box me-2"></i>Add Product</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-8"><label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label><input type="text" name="name" id="pName" class="form-control" required maxlength="255"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">SKU <small class="text-muted">(auto if blank)</small></label><input type="text" name="sku" id="pSku" class="form-control" maxlength="100"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Category</label><input type="text" name="category" id="pCat" class="form-control" maxlength="100" placeholder="e.g. Electronics"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Unit</label><input type="text" name="unit" id="pUnit" class="form-control" maxlength="30" placeholder="e.g. pcs, kg, litre"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="pStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Selling Price (<?=CURRENCY_SYMBOL?>)</label><input type="number" name="price" id="pPrice" class="form-control" step="0.01" min="0" value="0"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Tax Rate (%)</label><input type="number" name="tax_rate" id="pTax" class="form-control" step="0.01" min="0" max="100" value="0"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Stock Qty</label><input type="number" name="stock" id="pStock" class="form-control" min="0" value="0"></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Product</button></div>
  </form>
</div></div></div>
<form method="POST" id="delPForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delPId"></form>
<?php
$extraJs=<<<'JS'
<script>
function openAdd(){document.getElementById('pTitle').innerHTML='<i class="fas fa-box me-2"></i>Add Product';['pId','pName','pSku','pCat','pUnit'].forEach(i=>document.getElementById(i).value=i==='pId'?'0':'');document.getElementById('pPrice').value=0;document.getElementById('pTax').value=0;document.getElementById('pStock').value=0;document.getElementById('pStatus').value='active';}
function openEdit(p){document.getElementById('pTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Product';document.getElementById('pId').value=p.id;document.getElementById('pName').value=p.name||'';document.getElementById('pSku').value=p.sku||'';document.getElementById('pCat').value=p.category||'';document.getElementById('pUnit').value=p.unit||'';document.getElementById('pPrice').value=p.price||0;document.getElementById('pTax').value=p.tax_rate||0;document.getElementById('pStock').value=p.stock||0;document.getElementById('pStatus').value=p.status||'active';new bootstrap.Modal(document.getElementById('pModal')).show();}
function delProd(id,name){Swal.fire({title:'Delete Product?',text:'"'+name+'" will be removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delPId').value=id;document.getElementById('delPForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
