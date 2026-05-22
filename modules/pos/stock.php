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

    if($action==='adjust'){
        $productId=(int)($_POST['product_id']??0);
        $adjType=in_array($_POST['adjustment_type']??'',['in','out','damage','loss','correction','return'])?$_POST['adjustment_type']:'in';
        $qty=(float)($_POST['quantity']??0);
        $unitCost=(float)($_POST['unit_cost']??0);
        $reference=sanitize($_POST['reference']??'');
        $reason=sanitize($_POST['reason']??'');
        $notes=sanitize($_POST['notes']??'');
        if(!$productId||$qty<=0){setFlash('error','Please select a product and enter a valid quantity.');redirect('stock.php');}

        // Get current stock
        $p=$pdo->prepare("SELECT name,stock_quantity FROM pos_products WHERE id=? AND org_id=?");
        $p->execute([$productId,$orgId]);$product=$p->fetch();
        if(!$product){setFlash('error','Product not found.');redirect('stock.php');}

        $before=(float)$product['stock_quantity'];
        $delta=in_array($adjType,['out','damage','loss'])?-$qty:$qty;
        $after=max(0,$before+$delta);

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE pos_products SET stock_quantity=? WHERE id=? AND org_id=?")->execute([$after,$productId,$orgId]);
        $pdo->prepare("INSERT INTO pos_stock_adjustments (org_id,product_id,product_name,adjustment_type,quantity,quantity_before,quantity_after,unit_cost,reference,reason,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$orgId,$productId,$product['name'],$adjType,$qty,$before,$after,$unitCost,$reference,$reason,$notes,$user['id']]);
        $pdo->commit();
        setFlash('success','Stock adjustment recorded. '.$product['name'].' updated from '.$before.' → '.$after.' units.');
        redirect('stock.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];

$fType=$_GET['type']??'';
$fProd=(int)($_GET['product_id']??0);
$fFrom=$_GET['date_from']??date('Y-m-01');
$fTo=$_GET['date_to']??date('Y-m-d');

// Products for dropdown & stock levels
$products=[];
try{$s=$pdo->prepare("SELECT id,name,stock_quantity,reorder_level FROM pos_products WHERE org_id=? AND is_active=1 ORDER BY name");$s->execute([$orgId]);$products=$s->fetchAll();}catch(Exception $e){}

$lowStock=array_filter($products,fn($p)=>(int)$p['stock_quantity']<=(int)$p['reorder_level']);
$outOfStock=array_filter($products,fn($p)=>(int)$p['stock_quantity']<=0);

// Adjustment log
$where='a.org_id=?';$params=[$orgId];
if($fType){$where.=' AND a.adjustment_type=?';$params[]=$fType;}
if($fProd){$where.=' AND a.product_id=?';$params[]=$fProd;}
if($fFrom&&$fTo){$where.=' AND DATE(a.created_at) BETWEEN ? AND ?';$params[]=$fFrom;$params[]=$fTo;}

$log=[];
try{
    $s=$pdo->prepare("SELECT a.*,u.name AS user_name FROM pos_stock_adjustments a LEFT JOIN users u ON a.created_by=u.id WHERE $where ORDER BY a.created_at DESC LIMIT 100");
    $s->execute($params);$log=$s->fetchAll();
}catch(Exception $e){}
?>
<?= flashAlert() ?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-warehouse me-2" style="color:<?=$moduleColor?>"></i>Stock Management</h4><p class="text-muted mb-0">Track inventory levels and record stock adjustments</p></div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#adjModal"><i class="fas fa-plus me-2"></i>Adjust Stock</button>
</div>

<?php if(count($outOfStock)>0):?>
<div class="alert alert-danger d-flex align-items-center mb-3"><i class="fas fa-exclamation-circle me-2"></i><?=count($outOfStock)?> product(s) are <strong class="ms-1">out of stock</strong>.</div>
<?php endif;?>
<?php if(count($lowStock)>0):?>
<div class="alert alert-warning d-flex align-items-center mb-3"><i class="fas fa-exclamation-triangle me-2"></i><?=count($lowStock)?> product(s) are <strong class="ms-1">below reorder level</strong>.</div>
<?php endif;?>

<div class="row g-3 mb-4">
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-boxes"></i></div><div class="stat-body"><div class="stat-value"><?=count($products)?></div><div class="stat-label">Total Products</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-body"><div class="stat-value"><?=count($lowStock)?></div><div class="stat-label">Low Stock</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-times-circle"></i></div><div class="stat-body"><div class="stat-value"><?=count($outOfStock)?></div><div class="stat-label">Out of Stock</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-history"></i></div><div class="stat-body"><div class="stat-value"><?php try{echo countRows('pos_stock_adjustments','org_id=?',[$orgId]);}catch(Exception $e){echo 0;} ?></div><div class="stat-label">Total Adjustments</div></div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-boxes me-2" style="color:<?=$moduleColor?>"></i>Current Stock Levels</h6></div>
      <div class="card-body p-0" style="max-height:450px;overflow-y:auto"><table class="table table-hover mb-0 small">
        <thead class="table-light sticky-top"><tr><th>Product</th><th class="text-center">Stock</th><th class="text-center">Reorder</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($products as $p):
          $qty=(int)$p['stock_quantity'];$rl=(int)$p['reorder_level'];
          $rowC=$qty<=0?'table-danger':($qty<=$rl?'table-warning':'');
        ?>
        <tr class="<?=$rowC?>">
          <td class="fw-semibold"><?=e($p['name'])?></td>
          <td class="text-center fw-bold"><?=$qty?></td>
          <td class="text-center text-muted"><?=$rl?></td>
          <td><?php if($qty<=0):?><span class="badge bg-danger">Out</span><?php elseif($qty<=$rl):?><span class="badge bg-warning text-dark">Low</span><?php else:?><span class="badge bg-success">OK</span><?php endif;?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table></div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header with-filter">
        <h6 class="mb-0"><i class="fas fa-history me-2" style="color:<?=$moduleColor?>"></i>Adjustment Log</h6>
        <form class="row g-2" method="GET">
          <div class="col-sm-3"><select name="type" class="form-select form-select-sm"><option value="">All Types</option><?php foreach(['in'=>'Stock In','out'=>'Stock Out','damage'=>'Damage','loss'=>'Loss','correction'=>'Correction','return'=>'Return'] as $v=>$l):?><option value="<?=$v?>" <?=$fType===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select></div>
          <div class="col-sm-3"><select name="product_id" class="form-select form-select-sm"><option value="">All Products</option><?php foreach($products as $p):?><option value="<?=$p['id']?>" <?=$fProd==$p['id']?'selected':''?>><?=e($p['name'])?></option><?php endforeach;?></select></div>
          <div class="col-sm-2"><input type="date" name="date_from" class="form-control form-control-sm" value="<?=e($fFrom)?>"></div>
          <div class="col-sm-2"><input type="date" name="date_to" class="form-control form-control-sm" value="<?=e($fTo)?>"></div>
          <div class="col-auto"><button class="btn btn-sm btn-success"><i class="fas fa-filter"></i></button><a href="stock.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
        </form>
      </div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0 small">
        <thead class="table-light"><tr><th>Date</th><th>Product</th><th>Type</th><th class="text-center">Qty</th><th>Before → After</th><th>Reason</th><th>By</th></tr></thead>
        <tbody>
        <?php if(empty($log)):?><tr><td colspan="7" class="text-center text-muted py-4">No adjustments found.</td></tr>
        <?php else:foreach($log as $l):
          $typeColors=['in'=>'success','out'=>'warning','damage'=>'danger','loss'=>'danger','correction'=>'info','return'=>'primary'];
          $tc=$typeColors[$l['adjustment_type']]??'secondary';
          $typeLabels=['in'=>'Stock In','out'=>'Stock Out','damage'=>'Damage','loss'=>'Loss','correction'=>'Correction','return'=>'Return'];
        ?>
        <tr>
          <td><?=formatDate($l['created_at'])?><div class="text-muted" style="font-size:.7rem"><?=date('H:i',strtotime($l['created_at']))?></div></td>
          <td class="fw-semibold"><?=e($l['product_name'])?></td>
          <td><span class="badge bg-<?=$tc?>"><?=$typeLabels[$l['adjustment_type']]??ucfirst($l['adjustment_type'])?></span></td>
          <?php $isPos=in_array($l['adjustment_type'],['in','return','correction']); ?>
          <td class="text-center fw-bold <?=$isPos?'text-success':'text-danger'?>"><?=$isPos?'+':'-'?><?=number_format((float)$l['quantity'],1)?></td>
          <td class="text-muted"><?=number_format((float)$l['quantity_before'],1)?> → <?=number_format((float)$l['quantity_after'],1)?></td>
          <td><?=e($l['reason']??$l['reference']??'—')?></td>
          <td><?=e($l['user_name']??'System')?></td>
        </tr>
        <?php endforeach;endif;?>
        </tbody>
      </table></div></div>
    </div>
  </div>
</div>

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-warehouse me-2"></i>Adjust Stock</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="adjust">
    <div class="mb-3"><label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
      <select name="product_id" class="form-select" required>
        <option value="">— Select product —</option>
        <?php foreach($products as $p):?><option value="<?=$p['id']?>">
          <?=e($p['name'])?> (Stock: <?=$p['stock_quantity']?>)
        </option><?php endforeach;?>
      </select>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-6"><label class="form-label fw-semibold">Adjustment Type <span class="text-danger">*</span></label>
        <select name="adjustment_type" class="form-select">
          <option value="in">Stock In (Receive)</option>
          <option value="out">Stock Out (Manual)</option>
          <option value="damage">Damage</option>
          <option value="loss">Loss / Theft</option>
          <option value="correction">Correction</option>
          <option value="return">Return to Stock</option>
        </select>
      </div>
      <div class="col-6"><label class="form-label fw-semibold">Quantity <span class="text-danger">*</span></label><input type="number" name="quantity" class="form-control" min="0.1" step="0.01" required></div>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-6"><label class="form-label fw-semibold">Unit Cost (optional)</label><input type="number" name="unit_cost" class="form-control" min="0" step="0.01" placeholder="0.00"></div>
      <div class="col-6"><label class="form-label fw-semibold">Reference No.</label><input type="text" name="reference" class="form-control" placeholder="e.g. GRN-001"></div>
    </div>
    <div class="mb-3"><label class="form-label fw-semibold">Reason</label><input type="text" name="reason" class="form-control" placeholder="e.g. Physical count correction"></div>
    <div class="mb-3"><label class="form-label fw-semibold">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Record Adjustment</button></div>
  </form>
</div></div></div>

<?php require_once __DIR__.'/../../includes/footer.php';?>
