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
        $saleId=(int)($_POST['sale_id']??0)||null;
        $returnDate=sanitize($_POST['return_date']??date('Y-m-d'));
        $customerName=sanitize($_POST['customer_name']??'');
        $reason=sanitize($_POST['return_reason']??'');
        $refundMethod=in_array($_POST['refund_method']??'',['cash','mpesa','card','credit','exchange'])?$_POST['refund_method']:'cash';
        $restock=(int)($_POST['restock']??1);
        $notes=sanitize($_POST['notes']??'');
        $itemPids=$_POST['item_product_id']??[];
        $itemNames=$_POST['item_name']??[];
        $itemQtys=$_POST['item_qty']??[];
        $itemPrices=$_POST['item_price']??[];

        $refundTotal=0;$items=[];
        foreach($itemNames as $i=>$nm){
            $nm=sanitize($nm);if(!$nm)continue;
            $qty=(float)($itemQtys[$i]??1);$price=(float)($itemPrices[$i]??0);$pid=(int)($itemPids[$i]??0);
            $lineTotal=round($qty*$price,2);$refundTotal+=$lineTotal;
            $items[]=['pid'=>$pid,'name'=>$nm,'qty'=>$qty,'price'=>$price,'total'=>$lineTotal];
        }
        if(empty($items)){setFlash('error','Add at least one return item.');redirect('returns.php');}

        $retCount=countRows('pos_returns','org_id=?',[$orgId])+1;
        $retNumber='RET-'.date('ymd').'-'.str_pad($retCount,4,'0',STR_PAD_LEFT);

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO pos_returns (org_id,sale_id,return_number,return_date,customer_name,return_reason,refund_method,refund_amount,restock,notes,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,'approved',?,?)")
           ->execute([$orgId,$saleId?:null,$retNumber,$returnDate,$customerName,$reason,$refundMethod,$refundTotal,$restock,$notes,$user['id']]);
        $retId=(int)$pdo->lastInsertId();

        foreach($items as $it){
            $pdo->prepare("INSERT INTO pos_return_items (return_id,product_id,product_name,quantity,unit_price,total) VALUES (?,?,?,?,?,?)")
               ->execute([$retId,$it['pid']?:null,$it['name'],$it['qty'],$it['price'],$it['total']]);
            if($restock&&$it['pid']){
                $p=$pdo->prepare("SELECT name,stock_quantity FROM pos_products WHERE id=? AND org_id=?");$p->execute([$it['pid'],$orgId]);$prod=$p->fetch();
                if($prod){
                    $before=(float)$prod['stock_quantity'];$after=$before+$it['qty'];
                    $pdo->prepare("UPDATE pos_products SET stock_quantity=? WHERE id=? AND org_id=?")->execute([$after,$it['pid'],$orgId]);
                    $pdo->prepare("INSERT INTO pos_stock_adjustments (org_id,product_id,product_name,adjustment_type,quantity,quantity_before,quantity_after,reference,reason,created_by) VALUES (?,?,?,'return',?,?,?,?,?,?)")
                       ->execute([$orgId,$it['pid'],$prod['name'],$it['qty'],$before,$after,$retNumber,'Customer Return',$user['id']]);
                }
            }
        }
        $pdo->commit();
        setFlash('success','Return '.$retNumber.' recorded. Refund: '.formatCurrency($refundTotal));
        redirect('returns.php');
    }
    if($action==='delete'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM pos_return_items WHERE return_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM pos_returns WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Return deleted.');redirect('returns.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];

$view=(int)($_GET['view']??0);$fFrom=$_GET['date_from']??date('Y-m-01');$fTo=$_GET['date_to']??date('Y-m-d');

$where='r.org_id=?';$params=[$orgId];
if($fFrom&&$fTo){$where.=' AND DATE(r.return_date) BETWEEN ? AND ?';$params[]=$fFrom;$params[]=$fTo;}

$returns=[];
try{$s=$pdo->prepare("SELECT r.*,s.receipt_no FROM pos_returns r LEFT JOIN pos_sales s ON r.sale_id=s.id WHERE $where ORDER BY r.return_date DESC,r.id DESC");$s->execute($params);$returns=$s->fetchAll();}catch(Exception $e){}

$viewData=null;$viewItems=[];
if($view){try{$s=$pdo->prepare("SELECT * FROM pos_returns WHERE id=? AND org_id=?");$s->execute([$view,$orgId]);$viewData=$s->fetch();}catch(Exception $e){}
    if($viewData){try{$s=$pdo->prepare("SELECT * FROM pos_return_items WHERE return_id=?");$s->execute([$view]);$viewItems=$s->fetchAll();}catch(Exception $e){}}
}

$products=[];try{$s=$pdo->prepare("SELECT id,name,price FROM pos_products WHERE org_id=? AND is_active=1 ORDER BY name");$s->execute([$orgId]);$products=$s->fetchAll();}catch(Exception $e){}

$totalReturns=countRows('pos_returns','org_id=?',[$orgId]);
$monthRefund=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(refund_amount),0) FROM pos_returns WHERE org_id=? AND MONTH(return_date)=MONTH(CURDATE())");$s->execute([$orgId]);$monthRefund=(float)$s->fetchColumn();}catch(Exception $e){}
?>
<?= flashAlert() ?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-undo me-2" style="color:<?=$moduleColor?>"></i>Returns & Refunds</h4><p class="text-muted mb-0">Process customer returns and issue refunds</p></div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#retModal"><i class="fas fa-plus me-2"></i>New Return</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-undo"></i></div><div class="stat-body"><div class="stat-value"><?=$totalReturns?></div><div class="stat-label">Total Returns</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-coins"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($monthRefund)?></div><div class="stat-label">Refunds This Month</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-calendar-day"></i></div><div class="stat-body"><div class="stat-value"><?=countRows('pos_returns','org_id=? AND DATE(return_date)=CURDATE()',[$orgId])?></div><div class="stat-label">Returns Today</div></div></div></div>
</div>

<div class="row g-3">
  <div class="<?=$view?'col-lg-7':'col-12'?>">
    <div class="card">
      <div class="card-header">
        <form class="row g-2 align-items-center" method="GET">
          <div class="col-sm-3"><input type="date" name="date_from" class="form-control form-control-sm" value="<?=e($fFrom)?>"></div>
          <div class="col-sm-3"><input type="date" name="date_to" class="form-control form-control-sm" value="<?=e($fTo)?>"></div>
          <div class="col-auto"><button class="btn btn-sm btn-success"><i class="fas fa-filter"></i></button><a href="returns.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
        </form>
      </div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
        <thead class="table-light"><tr><th>Return #</th><th>Original Sale</th><th>Customer</th><th>Date</th><th>Refund Method</th><th>Restocked</th><th class="text-end">Refund</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($returns)):?><tr><td colspan="8" class="text-center text-muted py-4">No returns found.</td></tr>
        <?php else:foreach($returns as $r):?>
        <tr>
          <td class="fw-semibold"><?=e($r['return_number'])?></td>
          <td class="small"><?=e($r['receipt_no']??($r['sale_id']?'#'.$r['sale_id']:'Walk-in'))?></td>
          <td><?=e($r['customer_name']??'—')?></td>
          <td><?=formatDate($r['return_date'])?></td>
          <td><span class="badge bg-secondary"><?=ucfirst($r['refund_method'])?></span></td>
          <td><?=$r['restock']?'<span class="badge bg-success">Yes</span>':'<span class="badge bg-secondary">No</span>'?></td>
          <td class="text-end fw-semibold text-danger"><?=formatCurrency($r['refund_amount'])?></td>
          <td>
            <a href="?view=<?=$r['id']?>&date_from=<?=$fFrom?>&date_to=<?=$fTo?>" class="btn btn-xs btn-outline-primary me-1"><i class="fas fa-eye"></i></a>
            <form method="POST" class="d-inline">
              <?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>">
              <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete return <?=e($r['return_number'])?>?"><i class="fas fa-trash"></i></button>
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
        <h6 class="mb-0"><i class="fas fa-undo me-2" style="color:<?=$moduleColor?>"></i><?=e($viewData['return_number'])?></h6>
        <a href="returns.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
      </div>
      <div class="card-body small">
        <div class="row g-2 mb-3">
          <div class="col-6"><div class="text-muted">Date</div><div><?=formatDate($viewData['return_date'])?></div></div>
          <div class="col-6"><div class="text-muted">Customer</div><div><?=e($viewData['customer_name']??'—')?></div></div>
          <div class="col-6"><div class="text-muted">Refund Method</div><div><?=ucfirst($viewData['refund_method'])?></div></div>
          <div class="col-6"><div class="text-muted">Restocked</div><div><?=$viewData['restock']?'Yes':'No'?></div></div>
          <div class="col-12"><div class="text-muted">Reason</div><div><?=e($viewData['return_reason']??'—')?></div></div>
        </div>
        <table class="table table-sm table-bordered mb-2">
          <thead class="table-light"><tr><th>Product</th><th class="text-center">Qty</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php foreach($viewItems as $it):?>
          <tr><td><?=e($it['product_name'])?></td><td class="text-center"><?=$it['quantity']?></td><td class="text-end"><?=formatCurrency($it['total'])?></td></tr>
          <?php endforeach;?>
          </tbody>
          <tfoot class="table-light"><tr><th colspan="2" class="text-end text-danger">Total Refund</th><th class="text-end fw-bold text-danger"><?=formatCurrency($viewData['refund_amount'])?></th></tr></tfoot>
        </table>
      </div>
    </div>
  </div>
  <?php endif;?>
</div>

<!-- New Return Modal -->
<div class="modal fade" id="retModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-undo me-2"></i>Process Return</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save">
    <div class="row g-3 mb-3">
      <div class="col-md-4"><label class="form-label fw-semibold">Return Date</label><input type="date" name="return_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Original Receipt # (optional)</label><input type="text" name="sale_id" class="form-control" placeholder="Leave blank if unknown"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Customer Name</label><input type="text" name="customer_name" class="form-control" placeholder="Optional"></div>
    </div>
    <!-- Return Items -->
    <div class="table-responsive mb-3"><table class="table table-bordered" id="retItemsTable">
      <thead class="table-light"><tr><th>Product</th><th style="width:110px">Qty</th><th style="width:130px">Unit Price</th><th style="width:120px">Total</th><th style="width:40px"></th></tr></thead>
      <tbody id="retItemsBody">
        <tr>
          <td><select name="item_product_id[]" class="form-select form-select-sm ret-prod-sel"><option value="">— Select product —</option><?php foreach($products as $p):?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=e($p['name'])?></option><?php endforeach;?></select>
              <input type="text" name="item_name[]" class="form-control form-control-sm mt-1 ret-name-input" placeholder="Or type name"></td>
          <td><input type="number" name="item_qty[]" class="form-control form-control-sm ret-qty" value="1" min="0.01" step="0.01"></td>
          <td><input type="number" name="item_price[]" class="form-control form-control-sm ret-price" value="0" min="0" step="0.01"></td>
          <td class="fw-semibold ret-line-total">0.00</td>
          <td><button type="button" class="btn btn-xs btn-outline-danger remove-ret-row"><i class="fas fa-times"></i></button></td>
        </tr>
      </tbody>
      <tfoot><tr><td colspan="3" class="text-end fw-bold text-danger">Total Refund</td><td class="fw-bold text-danger" id="retGrandTotal">0.00</td><td></td></tr></tfoot>
    </table></div>
    <button type="button" class="btn btn-sm btn-outline-success mb-3" id="addRetRow"><i class="fas fa-plus me-1"></i>Add Item</button>
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label fw-semibold">Refund Method</label><select name="refund_method" class="form-select"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="card">Card</option><option value="credit">Store Credit</option><option value="exchange">Exchange</option></select></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Restock Items?</label><select name="restock" class="form-select"><option value="1">Yes — return to stock</option><option value="0">No — damaged/dispose</option></select></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Return Reason</label><input type="text" name="return_reason" class="form-control" placeholder="e.g. Defective, Wrong item"></div>
      <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Process Return</button></div>
  </form>
</div></div></div>

<?php ob_start(); ?>
<script>
const retProdsMap=<?= json_encode(array_column($products,null,'id')) ?>;
function recalcRet(){
  let gt=0;
  document.querySelectorAll('#retItemsBody tr').forEach(row=>{
    const qty=parseFloat(row.querySelector('.ret-qty')?.value)||0;
    const price=parseFloat(row.querySelector('.ret-price')?.value)||0;
    const lt=qty*price;gt+=lt;
    const ltEl=row.querySelector('.ret-line-total');if(ltEl)ltEl.textContent=lt.toLocaleString('en-KE',{minimumFractionDigits:2});
  });
  document.getElementById('retGrandTotal').textContent=gt.toLocaleString('en-KE',{minimumFractionDigits:2});
}
function retRowHtml(){
  return `<tr>
    <td><select name="item_product_id[]" class="form-select form-select-sm ret-prod-sel"><option value="">— Select —</option><?php foreach($products as $p):?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=e($p['name'])?></option><?php endforeach;?></select>
        <input type="text" name="item_name[]" class="form-control form-control-sm mt-1 ret-name-input" placeholder="Or type name"></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm ret-qty" value="1" min="0.01" step="0.01"></td>
    <td><input type="number" name="item_price[]" class="form-control form-control-sm ret-price" value="0" min="0" step="0.01"></td>
    <td class="fw-semibold ret-line-total">0.00</td>
    <td><button type="button" class="btn btn-xs btn-outline-danger remove-ret-row"><i class="fas fa-times"></i></button></td>
  </tr>`;
}
document.getElementById('addRetRow').addEventListener('click',function(){
  document.getElementById('retItemsBody').insertAdjacentHTML('beforeend',retRowHtml());
  bindRetRows();
});
function bindRetRows(){
  document.querySelectorAll('#retItemsBody .ret-prod-sel').forEach(sel=>{
    sel.onchange=function(){
      const pid=this.value;const row=this.closest('tr');
      if(pid&&retProdsMap[pid]){
        row.querySelector('.ret-name-input').value=retProdsMap[pid].name;
        row.querySelector('.ret-price').value=retProdsMap[pid].price||0;
      }
      recalcRet();
    };
  });
  document.querySelectorAll('#retItemsBody .ret-qty, #retItemsBody .ret-price').forEach(i=>i.oninput=recalcRet);
  document.querySelectorAll('#retItemsBody .remove-ret-row').forEach(btn=>btn.onclick=function(){
    if(document.querySelectorAll('#retItemsBody tr').length>1)this.closest('tr').remove();
    recalcRet();
  });
}
bindRetRows();
document.querySelectorAll('.btn-confirm').forEach(btn=>{btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});});
</script>
<?php
$extraJs = ob_get_clean();
require_once __DIR__.'/../../includes/footer.php';
?>
