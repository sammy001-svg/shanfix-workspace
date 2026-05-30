<?php
$moduleSlug='pos';$moduleName='Point of Sale';$moduleIcon='fas fa-cash-register';$moduleColor='#e74c3c';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'terminal.php','icon'=>'fas fa-cash-register','label'=>'POS Terminal'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'categories.php','icon'=>'fas fa-tags','label'=>'Categories'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'suppliers.php','icon'=>'fas fa-truck','label'=>'Suppliers'],['url'=>'stock.php','icon'=>'fas fa-warehouse','label'=>'Stock'],['url'=>'purchases.php','icon'=>'fas fa-cart-arrow-down','label'=>'Purchases'],['url'=>'returns.php','icon'=>'fas fa-undo','label'=>'Returns'],['url'=>'shifts.php','icon'=>'fas fa-clock','label'=>'Shifts'],['url'=>'expenses.php','icon'=>'fas fa-wallet','label'=>'Expenses'],['url'=>'discounts.php','icon'=>'fas fa-percent','label'=>'Discounts'],['url'=>'sales.php','icon'=>'fas fa-receipt','label'=>'Sales History'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user=currentUser();$orgId=(int)$user['org_id'];
    $action=$_POST['action']??'';

    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $supplierId=(int)($_POST['supplier_id']??0)||null;
        $orderDate=sanitize($_POST['order_date']??date('Y-m-d'));
        $expectedDate=sanitize($_POST['expected_date']??'');
        $payMethod=sanitize($_POST['payment_method']??'cash');
        $status=in_array($_POST['status']??'',['draft','ordered','partial','received','cancelled'])?$_POST['status']:'draft';
        $notes=sanitize($_POST['notes']??'');
        $itemNames=$_POST['item_name']??[];
        $itemPids=$_POST['item_product_id']??[];
        $itemQtys=$_POST['item_qty']??[];
        $itemCosts=$_POST['item_cost']??[];

        $subtotal=0;
        $items=[];
        foreach($itemNames as $i=>$nm){
            $nm=sanitize($nm);if(!$nm)continue;
            $qty=(float)($itemQtys[$i]??1);$cost=(float)($itemCosts[$i]??0);$pid=(int)($itemPids[$i]??0);
            $lineTotal=round($qty*$cost,2);$subtotal+=$lineTotal;
            $items[]=['pid'=>$pid,'name'=>$nm,'qty'=>$qty,'cost'=>$cost,'total'=>$lineTotal];
        }
        if(empty($items)){setFlash('error','Add at least one item.');redirect('purchases.php');}

        $total=round($subtotal,2);

        $pdo->beginTransaction();
        if($id){
            $pdo->prepare("UPDATE pos_purchases SET supplier_id=?,order_date=?,expected_date=?,payment_method=?,status=?,subtotal=?,total=?,notes=? WHERE id=? AND org_id=?")
               ->execute([$supplierId,$orderDate,$expectedDate?:null,$payMethod,$status,$total,$total,$notes,$id,$orgId]);
            $pdo->prepare("DELETE FROM pos_purchase_items WHERE purchase_id=?")->execute([$id]);
        } else {
            $poCount=countRows('pos_purchases','org_id=?',[$orgId])+1;
            $poNumber='PO-'.date('ymd').'-'.str_pad($poCount,4,'0',STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO pos_purchases (org_id,supplier_id,po_number,order_date,expected_date,payment_method,status,subtotal,total,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$orgId,$supplierId,$poNumber,$orderDate,$expectedDate?:null,$payMethod,$status,$total,$total,$notes,$user['id']]);
            $id=(int)$pdo->lastInsertId();
        }
        foreach($items as $it){
            $pdo->prepare("INSERT INTO pos_purchase_items (purchase_id,product_id,product_name,quantity,quantity_received,unit_cost,total) VALUES (?,?,?,?,0,?,?)")
               ->execute([$id,$it['pid']?:null,$it['name'],$it['qty'],$it['cost'],$it['total']]);
        }
        // If received, update stock
        if($status==='received'){
            foreach($items as $it){
                if($it['pid']){
                    $p=$pdo->prepare("SELECT name,stock_quantity FROM pos_products WHERE id=? AND org_id=?");
                    $p->execute([$it['pid'],$orgId]);$prod=$p->fetch();
                    if($prod){
                        $before=(float)$prod['stock_quantity'];$after=$before+$it['qty'];
                        $pdo->prepare("UPDATE pos_products SET stock_quantity=?,cost_price=? WHERE id=? AND org_id=?")->execute([$after,$it['cost'],$it['pid'],$orgId]);
                        $pdo->prepare("INSERT INTO pos_stock_adjustments (org_id,product_id,product_name,adjustment_type,quantity,quantity_before,quantity_after,unit_cost,reference,reason,created_by) VALUES (?,?,?,'in',?,?,?,?,?,?,?)")
                           ->execute([$orgId,$it['pid'],$prod['name'],$it['qty'],$before,$after,$it['cost'],'PO receipt','Purchase Order',$user['id']]);
                    }
                }
            }
        }
        $pdo->commit();
        setFlash('success','Purchase order saved.');redirect('purchases.php');
    }
    if($action==='delete'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM pos_purchase_items WHERE purchase_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM pos_purchases WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Purchase order deleted.');redirect('purchases.php');
    }
    if($action==='mark_received'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("UPDATE pos_purchases SET status='received',received_date=CURDATE() WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        // Update stock for all items
        $items=$pdo->prepare("SELECT * FROM pos_purchase_items WHERE purchase_id=?");$items->execute([$id]);
        foreach($items->fetchAll() as $it){
            if($it['product_id']){
                $p=$pdo->prepare("SELECT name,stock_quantity FROM pos_products WHERE id=? AND org_id=?");
                $p->execute([$it['product_id'],$orgId]);$prod=$p->fetch();
                if($prod){
                    $before=(float)$prod['stock_quantity'];$after=$before+(float)$it['quantity'];
                    $pdo->prepare("UPDATE pos_products SET stock_quantity=? WHERE id=? AND org_id=?")->execute([$after,$it['product_id'],$orgId]);
                    $pdo->prepare("INSERT INTO pos_stock_adjustments (org_id,product_id,product_name,adjustment_type,quantity,quantity_before,quantity_after,unit_cost,reference,reason,created_by) VALUES (?,?,?,'in',?,?,?,?,?,?,?)")
                       ->execute([$orgId,$it['product_id'],$it['product_name'],$it['quantity'],$before,$after,$it['unit_cost'],'PO receipt','Purchase Order Received',$user['id']]);
                }
            }
        }
        setFlash('success','Purchase order marked as received and stock updated.');redirect('purchases.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];

$fStatus=$_GET['status']??'';$fSupplier=(int)($_GET['supplier_id']??0);$view=(int)($_GET['view']??0);

$suppliers=[];try{$s=$pdo->prepare("SELECT id,name FROM pos_suppliers WHERE org_id=? AND status='active' ORDER BY name");$s->execute([$orgId]);$suppliers=$s->fetchAll();}catch(Exception $e){}
$products=[];try{$s=$pdo->prepare("SELECT id,name,cost_price FROM pos_products WHERE org_id=? AND is_active=1 ORDER BY name");$s->execute([$orgId]);$products=$s->fetchAll();}catch(Exception $e){}

$where='p.org_id=?';$params=[$orgId];
if($fStatus){$where.=' AND p.status=?';$params[]=$fStatus;}
if($fSupplier){$where.=' AND p.supplier_id=?';$params[]=$fSupplier;}

$purchases=[];
try{$s=$pdo->prepare("SELECT p.*,s.name AS supplier_name FROM pos_purchases p LEFT JOIN pos_suppliers s ON p.supplier_id=s.id WHERE $where ORDER BY p.order_date DESC,p.id DESC");$s->execute($params);$purchases=$s->fetchAll();}catch(Exception $e){}

$viewData=null;$viewItems=[];
if($view){
    try{$s=$pdo->prepare("SELECT p.*,s.name AS supplier_name FROM pos_purchases p LEFT JOIN pos_suppliers s ON p.supplier_id=s.id WHERE p.id=? AND p.org_id=?");$s->execute([$view,$orgId]);$viewData=$s->fetch();}catch(Exception $e){}
    if($viewData){try{$s=$pdo->prepare("SELECT * FROM pos_purchase_items WHERE purchase_id=?");$s->execute([$view]);$viewItems=$s->fetchAll();}catch(Exception $e){}}
}
$stColors=['draft'=>'secondary','ordered'=>'primary','partial'=>'warning','received'=>'success','cancelled'=>'danger'];
?>
<?= flashAlert() ?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-cart-arrow-down me-2" style="color:<?=$moduleColor?>"></i>Purchase Orders</h4><p class="text-muted mb-0">Manage stock purchases from suppliers</p></div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#poModal"><i class="fas fa-plus me-2"></i>New Purchase Order</button>
</div>

<div class="row g-3 mb-3">
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-file-alt"></i></div><div class="stat-body"><div class="stat-value"><?=countRows('pos_purchases','org_id=?',[$orgId])?></div><div class="stat-label">Total POs</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?=countRows('pos_purchases','org_id=? AND status=?',[$orgId,'ordered'])?></div><div class="stat-label">Pending</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check"></i></div><div class="stat-body"><div class="stat-value"><?=countRows('pos_purchases','org_id=? AND status=?',[$orgId,'received'])?></div><div class="stat-label">Received</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-coins"></i></div><div class="stat-body"><div class="stat-value"><?php try{$s=$pdo->prepare("SELECT COALESCE(SUM(total),0) FROM pos_purchases WHERE org_id=? AND MONTH(order_date)=MONTH(CURDATE())");$s->execute([$orgId]);echo formatCurrency((float)$s->fetchColumn());}catch(Exception $e){echo formatCurrency(0);} ?></div><div class="stat-label">This Month</div></div></div></div>
</div>

<div class="row g-3">
  <div class="<?=$view?'col-lg-6':'col-12'?>">
    <div class="card">
      <div class="card-header">
        <form class="row g-2 align-items-center" method="GET">
          <div class="col-auto"><select name="status" class="form-select form-select-sm"><option value="">All Status</option><?php foreach(array_keys($stColors) as $s):?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
          <div class="col-auto"><select name="supplier_id" class="form-select form-select-sm"><option value="">All Suppliers</option><?php foreach($suppliers as $s):?><option value="<?=$s['id']?>" <?=$fSupplier==$s['id']?'selected':''?>><?=e($s['name'])?></option><?php endforeach;?></select></div>
          <div class="col-auto"><button class="btn btn-sm btn-success"><i class="fas fa-filter"></i></button><a href="purchases.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
        </form>
      </div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
        <thead class="table-light"><tr><th>PO #</th><th>Supplier</th><th>Date</th><th>Status</th><th class="text-end">Total</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($purchases)):?><tr><td colspan="6" class="text-center text-muted py-4">No purchase orders found.</td></tr>
        <?php else:foreach($purchases as $po):$sc=$stColors[$po['status']]??'secondary';?>
        <tr>
          <td class="fw-semibold"><?=e($po['po_number'])?></td>
          <td><?=e($po['supplier_name']??'Walk-in')?></td>
          <td><?=formatDate($po['order_date'])?></td>
          <td><span class="badge bg-<?=$sc?>"><?=ucfirst($po['status'])?></span></td>
          <td class="text-end fw-semibold"><?=formatCurrency($po['total'])?></td>
          <td>
            <a href="?view=<?=$po['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="fas fa-eye"></i></a>
            <?php if(in_array($po['status'],['draft','ordered'])):?>
            <form method="POST" class="d-inline">
              <?=csrfField()?><input type="hidden" name="action" value="mark_received"><input type="hidden" name="id" value="<?=$po['id']?>">
              <button type="submit" class="btn btn-xs btn-outline-success me-1 btn-confirm" data-msg="Mark PO <?=e($po['po_number'])?> as received? Stock will be updated."><i class="fas fa-check"></i></button>
            </form>
            <?php endif;?>
            <form method="POST" class="d-inline">
              <?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$po['id']?>">
              <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete this purchase order?"><i class="fas fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach;endif;?>
        </tbody>
      </table></div></div>
    </div>
  </div>

  <?php if($view && $viewData):?>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><?=e($viewData['po_number'])?> — <span class="badge bg-<?=$stColors[$viewData['status']]??'secondary'?>"><?=ucfirst($viewData['status'])?></span></h6>
        <a href="purchases.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
      </div>
      <div class="card-body">
        <div class="row g-2 small mb-3">
          <div class="col-6"><div class="text-muted">Supplier</div><div class="fw-semibold"><?=e($viewData['supplier_name']??'—')?></div></div>
          <div class="col-6"><div class="text-muted">Order Date</div><div><?=formatDate($viewData['order_date'])?></div></div>
          <div class="col-6"><div class="text-muted">Expected</div><div><?=$viewData['expected_date']?formatDate($viewData['expected_date']):'—'?></div></div>
          <div class="col-6"><div class="text-muted">Payment</div><div><?=ucfirst($viewData['payment_method']??'cash')?></div></div>
        </div>
        <table class="table table-sm table-bordered mb-3">
          <thead class="table-light"><tr><th>Product</th><th class="text-center">Qty</th><th class="text-end">Unit Cost</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php foreach($viewItems as $it):?>
          <tr>
            <td><?=e($it['product_name'])?></td>
            <td class="text-center"><?=number_format((float)$it['quantity'],2)?></td>
            <td class="text-end"><?=formatCurrency($it['unit_cost'])?></td>
            <td class="text-end fw-semibold"><?=formatCurrency($it['total'])?></td>
          </tr>
          <?php endforeach;?>
          </tbody>
          <tfoot class="table-light"><tr><th colspan="3" class="text-end">Total</th><th class="text-end fw-bold"><?=formatCurrency($viewData['total'])?></th></tr></tfoot>
        </table>
        <?php if($viewData['notes']):?><div class="small text-muted"><strong>Notes:</strong> <?=e($viewData['notes'])?></div><?php endif;?>
      </div>
    </div>
  </div>
  <?php endif;?>
</div>

<!-- New PO Modal -->
<div class="modal fade" id="poModal" tabindex="-1"><div class="modal-dialog modal-xl"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-cart-arrow-down me-2"></i>New Purchase Order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
    <div class="row g-3 mb-3">
      <div class="col-md-4"><label class="form-label fw-semibold">Supplier</label>
        <select name="supplier_id" class="form-select"><option value="">— Walk-in / No supplier —</option><?php foreach($suppliers as $s):?><option value="<?=$s['id']?>"><?=e($s['name'])?></option><?php endforeach;?></select>
      </div>
      <div class="col-md-3"><label class="form-label fw-semibold">Order Date <span class="text-danger">*</span></label><input type="date" name="order_date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
      <div class="col-md-3"><label class="form-label fw-semibold">Expected Delivery</label><input type="date" name="expected_date" class="form-control"></div>
      <div class="col-md-2"><label class="form-label fw-semibold">Status</label>
        <select name="status" class="form-select"><option value="draft">Draft</option><option value="ordered">Ordered</option><option value="received">Received</option></select>
      </div>
    </div>
    <!-- Items Table -->
    <div class="table-responsive mb-3"><table class="table table-bordered" id="poItemsTable">
      <thead class="table-light"><tr><th>Product</th><th style="width:130px">Quantity</th><th style="width:140px">Unit Cost</th><th style="width:130px">Line Total</th><th style="width:40px"></th></tr></thead>
      <tbody id="poItemsBody">
        <tr>
          <td><select name="item_product_id[]" class="form-select form-select-sm prod-sel"><option value="">— Type or pick —</option><?php foreach($products as $p):?><option value="<?=$p['id']?>" data-cost="<?=$p['cost_price']?>"><?=e($p['name'])?></option><?php endforeach;?></select>
              <input type="text" name="item_name[]" class="form-control form-control-sm mt-1 item-name-input" placeholder="Or type product name"></td>
          <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" value="1" min="0.01" step="0.01"></td>
          <td><input type="number" name="item_cost[]" class="form-control form-control-sm item-cost" value="0" min="0" step="0.01"></td>
          <td class="fw-semibold item-line-total">0.00</td>
          <td><button type="button" class="btn btn-xs btn-outline-danger remove-row"><i class="fas fa-times"></i></button></td>
        </tr>
      </tbody>
      <tfoot><tr><td colspan="3" class="text-end fw-bold">Grand Total</td><td class="fw-bold" id="poGrandTotal">0.00</td><td></td></tr></tfoot>
    </table></div>
    <div class="d-flex gap-2 mb-3">
      <button type="button" class="btn btn-sm btn-outline-success" id="addPoRow"><i class="fas fa-plus me-1"></i>Add Item</button>
    </div>
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label fw-semibold">Payment Method</label><select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank">Bank Transfer</option><option value="credit">Credit</option></select></div>
      <div class="col-md-8"><label class="form-label fw-semibold">Notes</label><input type="text" name="notes" class="form-control" placeholder="Optional notes"></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Purchase Order</button></div>
  </form>
</div></div></div>

<?php ob_start(); ?>
<script>
const prodsMap=<?= json_encode(array_column($products,null,'id')) ?>;
function recalcPO(){
  let gt=0;
  document.querySelectorAll('#poItemsBody tr').forEach(row=>{
    const qty=parseFloat(row.querySelector('.item-qty')?.value)||0;
    const cost=parseFloat(row.querySelector('.item-cost')?.value)||0;
    const lt=qty*cost;gt+=lt;
    const ltEl=row.querySelector('.item-line-total');if(ltEl)ltEl.textContent=lt.toLocaleString('en-KE',{minimumFractionDigits:2});
  });
  document.getElementById('poGrandTotal').textContent=gt.toLocaleString('en-KE',{minimumFractionDigits:2});
}
function rowHtml(){
  return `<tr>
    <td><select name="item_product_id[]" class="form-select form-select-sm prod-sel"><option value="">— Type or pick —</option><?php foreach($products as $p):?><option value="<?=$p['id']?>" data-cost="<?=$p['cost_price']?>"><?=e($p['name'])?></option><?php endforeach;?></select>
        <input type="text" name="item_name[]" class="form-control form-control-sm mt-1 item-name-input" placeholder="Or type product name"></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" value="1" min="0.01" step="0.01"></td>
    <td><input type="number" name="item_cost[]" class="form-control form-control-sm item-cost" value="0" min="0" step="0.01"></td>
    <td class="fw-semibold item-line-total">0.00</td>
    <td><button type="button" class="btn btn-xs btn-outline-danger remove-row"><i class="fas fa-times"></i></button></td>
  </tr>`;
}
document.getElementById('addPoRow').addEventListener('click',function(){
  document.getElementById('poItemsBody').insertAdjacentHTML('beforeend',rowHtml());
  bindPoRows();
});
function bindPoRows(){
  document.querySelectorAll('#poItemsBody .prod-sel').forEach(sel=>{
    sel.onchange=function(){
      const pid=this.value;const row=this.closest('tr');
      if(pid&&prodsMap[pid]){
        row.querySelector('.item-name-input').value=prodsMap[pid].name;
        row.querySelector('.item-cost').value=prodsMap[pid].cost_price||0;
      }
      recalcPO();
    };
  });
  document.querySelectorAll('#poItemsBody .item-qty, #poItemsBody .item-cost').forEach(i=>i.oninput=recalcPO);
  document.querySelectorAll('#poItemsBody .remove-row').forEach(btn=>btn.onclick=function(){
    if(document.querySelectorAll('#poItemsBody tr').length>1)this.closest('tr').remove();
    recalcPO();
  });
}
bindPoRows();
document.querySelectorAll('.btn-confirm').forEach(btn=>{
  btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});
});
</script>
<?php
$extraJs = ob_get_clean();
require_once __DIR__.'/../../includes/footer.php';
?>
