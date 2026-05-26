<?php
$moduleSlug='sales';$moduleName='Sales Management';$moduleIcon='fas fa-chart-line';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);$custId=(int)($_POST['customer_id']??0)?:null;
        $orderDate=$_POST['order_date']??date('Y-m-d');$delDate=$_POST['delivery_date']??null;
        $discount=(float)($_POST['discount']??0);$notes=sanitize($_POST['notes']??'');
        $status=in_array($_POST['status']??'',['pending','processing','shipped','delivered','cancelled'])?$_POST['status']:'pending';
        // Calc totals from line items
        $descs=$_POST['item_desc']??[];$qtys=$_POST['item_qty']??[];$prices=$_POST['item_price']??[];$taxes=$_POST['item_tax']??[];
        $subtotal=0;$taxTotal=0;$items=[];
        foreach($descs as $i=>$desc){
            $desc=sanitize($desc);if($desc==='')continue;
            $qty=(float)($qtys[$i]??1);$price=(float)($prices[$i]??0);$tax=(float)($taxes[$i]??0);
            $lineTotal=round($qty*$price*(1+$tax/100),2);$subtotal+=$qty*$price;$taxTotal+=round($qty*$price*$tax/100,2);
            $items[]=[$desc,$qty,$price,$tax,$lineTotal];
        }
        $total=round($subtotal+$taxTotal-$discount,2);
        if($id>0){
            $pdo->prepare("UPDATE sales_orders SET customer_id=?,order_date=?,delivery_date=?,subtotal=?,discount=?,tax=?,total=?,status=?,notes=? WHERE id=? AND org_id=?")->execute([$custId,$orderDate,$delDate?:null,round($subtotal,2),$discount,round($taxTotal,2),$total,$status,$notes,$id,$orgId]);
            $pdo->prepare("DELETE FROM sales_order_items WHERE order_id=?")->execute([$id]);
            setFlash('success','Order updated.');
        } else {
            $count=countRows('sales_orders','org_id=?',[$orgId]);$orderNo='ORD-'.str_pad($count+1,5,'0',STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO sales_orders(org_id,order_no,customer_id,order_date,delivery_date,subtotal,discount,tax,total,paid,status,notes)VALUES(?,?,?,?,?,?,?,?,?,0,?,?)")->execute([$orgId,$orderNo,$custId,$orderDate,$delDate?:null,round($subtotal,2),$discount,round($taxTotal,2),$total,$status,$notes]);
            $id=(int)$pdo->lastInsertId();setFlash('success',"Order $orderNo created.");
        }
        $stmt=$pdo->prepare("INSERT INTO sales_order_items(order_id,description,qty,unit_price,tax_rate,total)VALUES(?,?,?,?,?,?)");
        foreach($items as $it)$stmt->execute([$id,...$it]);
        logActivity('create','sales',"Order #$id");redirect('orders.php');
    }
    if($action==='status'){$id=(int)($_POST['id']??0);$st=sanitize($_POST['status']??'');if(in_array($st,['pending','processing','shipped','delivered','cancelled'])){$pdo->prepare("UPDATE sales_orders SET status=? WHERE id=? AND org_id=?")->execute([$st,$id,$orgId]);setFlash('success','Order status updated.');}redirect('orders.php');}
    if($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM sales_order_items WHERE order_id=?")->execute([$id]);$pdo->prepare("DELETE FROM sales_orders WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Order deleted.');redirect('orders.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fStatus=$_GET['status']??'';$fQ=trim($_GET['q']??'');
$where='o.org_id=?';$params=[$orgId];
if($fStatus){$where.=' AND o.status=?';$params[]=$fStatus;}
if($fQ){$where.=' AND(o.order_no LIKE ? OR c.name LIKE ?)';$like="%$fQ%";array_push($params,$like,$like);}
$orders=[];try{$stmt=$pdo->prepare("SELECT o.*,c.name AS customer_name FROM sales_orders o LEFT JOIN sales_customers c ON o.customer_id=c.id WHERE $where ORDER BY o.created_at DESC");$stmt->execute($params);$orders=$stmt->fetchAll();}catch(Exception $e){}
$customers=[];try{$stmt=$pdo->prepare("SELECT id,name FROM sales_customers WHERE org_id=? AND status='active' ORDER BY name");$stmt->execute([$orgId]);$customers=$stmt->fetchAll();}catch(Exception $e){}
$products=[];try{$stmt=$pdo->prepare("SELECT id,name,price,tax_rate FROM sales_products WHERE org_id=? AND status='active' ORDER BY name");$stmt->execute([$orgId]);$products=$stmt->fetchAll();}catch(Exception $e){}
$tTotal=countRows('sales_orders','org_id=?',[$orgId]);$tPending=countRows('sales_orders','org_id=? AND status=?',[$orgId,'pending']);$tDelivered=countRows('sales_orders','org_id=? AND status=?',[$orgId,'delivered']);
$revenue=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(total),0) FROM sales_orders WHERE org_id=? AND status='delivered'");$s->execute([$orgId]);$revenue=(float)$s->fetchColumn();}catch(Exception $e){}
// View single order
$viewOrder=null;$viewItems=[];
if(isset($_GET['view'])){$oid=(int)$_GET['view'];try{$stmt=$pdo->prepare("SELECT o.*,c.name AS customer_name FROM sales_orders o LEFT JOIN sales_customers c ON o.customer_id=c.id WHERE o.id=? AND o.org_id=?");$stmt->execute([$oid,$orgId]);$viewOrder=$stmt->fetch();$stmt=$pdo->prepare("SELECT * FROM sales_order_items WHERE order_id=?");$stmt->execute([$oid]);$viewItems=$stmt->fetchAll();}catch(Exception $e){}}
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-shopping-cart me-2" style="color:<?=$moduleColor?>"></i>Orders</h4><p class="text-muted mb-0">Create and manage customer orders</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#oModal" onclick="initForm()"><i class="fas fa-plus me-2"></i>New Order</button>
</div>
<div class="row g-3 mb-4">
  <?php foreach([['navy-bg','fas fa-shopping-cart',$tTotal,'Total Orders'],['warning-bg','fas fa-clock',$tPending,'Pending'],['green-bg','fas fa-truck',$tDelivered,'Delivered'],['green-bg','fas fa-dollar-sign',formatCurrency($revenue),'Revenue']] as [$cl,$ic,$v,$lb]):?>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon <?=$cl?>"><i class="<?=$ic?>"></i></div><div class="stat-body"><div class="stat-value" style="font-size:1.1rem"><?=$v?></div><div class="stat-label"><?=$lb?></div></div></div></div>
  <?php endforeach;?>
</div>
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Search</label><input type="text" name="q" class="form-control form-control-sm" placeholder="Order # or customer…" value="<?=e($fQ)?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach(['pending','processing','shipped','delivered','cancelled'] as $s):?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="orders.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<?php if($viewOrder):?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:<?=$moduleColor?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-receipt me-2"></i>Order <?=e($viewOrder['order_no'])?> — <?=e($viewOrder['customer_name']??'Walk-in')?></h6>
    <div class="d-flex gap-2">
      <form method="POST" class="d-flex gap-1"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$viewOrder['id']?>"><?=csrfField()?><select name="status" class="form-select form-select-sm" style="width:auto"><?php foreach(['pending','processing','shipped','delivered','cancelled'] as $s):?><option value="<?=$s?>" <?=$viewOrder['status']===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select><button type="submit" class="btn btn-sm btn-light">Update</button></form>
      <a href="orders.php" class="btn btn-sm btn-light"><i class="fas fa-times me-1"></i>Close</a>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-3 mb-3">
      <div class="col-md-4"><strong>Customer:</strong> <?=e($viewOrder['customer_name']??'Walk-in')?></div>
      <div class="col-md-4"><strong>Order Date:</strong> <?=formatDate($viewOrder['order_date']??$viewOrder['created_at'])?></div>
      <div class="col-md-4"><strong>Delivery Date:</strong> <?=formatDate($viewOrder['delivery_date'])?></div>
    </div>
    <div class="table-responsive"><table class="table table-sm table-bordered">
      <thead class="table-light"><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Tax%</th><th class="text-end">Line Total</th></tr></thead>
      <tbody><?php if(empty($viewItems)):?><tr><td colspan="5" class="text-muted text-center">No line items</td></tr><?php else:foreach($viewItems as $it):?><tr><td><?=e($it['description'])?></td><td class="text-end"><?=$it['qty']?></td><td class="text-end"><?=formatCurrency((float)$it['unit_price'])?></td><td class="text-end"><?=$it['tax_rate']?>%</td><td class="text-end fw-semibold"><?=formatCurrency((float)$it['total'])?></td></tr><?php endforeach;endif;?></tbody>
      <tfoot class="table-light">
        <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end"><?=formatCurrency((float)$viewOrder['subtotal'])?></td></tr>
        <tr><td colspan="4" class="text-end">Tax</td><td class="text-end"><?=formatCurrency((float)$viewOrder['tax'])?></td></tr>
        <tr><td colspan="4" class="text-end">Discount</td><td class="text-end text-danger">-<?=formatCurrency((float)$viewOrder['discount'])?></td></tr>
        <tr><td colspan="4" class="text-end fw-bold">TOTAL</td><td class="text-end fw-bold text-success"><?=formatCurrency((float)$viewOrder['total'])?></td></tr>
      </tfoot>
    </table></div>
    <?php if(!empty($viewOrder['notes'])):?><p class="text-muted mt-2"><strong>Notes:</strong> <?=e($viewOrder['notes'])?></p><?php endif;?>
  </div>
</div>
<?php endif;?>

<div class="card"><div class="card-header d-flex align-items-center justify-content-between"><h6 class="mb-0"><i class="fas fa-shopping-cart me-2" style="color:<?=$moduleColor?>"></i>Order List</h6><span class="badge bg-secondary"><?=count($orders)?> orders</span></div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Delivery</th><th>Status</th><th class="text-end">Total</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($orders)):?><tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No orders found.</td></tr>
<?php else:foreach($orders as $o):?>
<tr>
  <td class="fw-semibold"><?=e($o['order_no'])?></td>
  <td><?=e($o['customer_name']??'Walk-in')?></td>
  <td><?=formatDate($o['created_at'])?></td>
  <td><?=formatDate($o['delivery_date'])?></td>
  <td><?=statusBadge($o['status']??'pending')?></td>
  <td class="text-end fw-semibold"><?=formatCurrency((float)$o['total'])?></td>
  <td class="text-center" style="white-space:nowrap">
    <a href="?view=<?=$o['id']?>" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
    <button class="btn btn-sm btn-outline-danger ms-1" onclick="delOrd(<?=$o['id']?>,'<?=e($o['order_no'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<!-- New Order Modal -->
<div class="modal fade" id="oModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content">
  <form method="POST" id="orderForm"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title"><i class="fas fa-shopping-cart me-2"></i>New Order</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="row g-3 mb-3">
      <div class="col-md-4"><label class="form-label fw-semibold">Customer</label><select name="customer_id" class="form-select"><option value="">Walk-in</option><?php foreach($customers as $c):?><option value="<?=$c['id']?>"><?=e($c['name'])?></option><?php endforeach;?></select></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Order Date</label><input type="date" name="order_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Delivery Date</label><input type="date" name="delivery_date" class="form-control"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" class="form-select"><option value="pending">Pending</option><option value="processing">Processing</option><option value="shipped">Shipped</option><option value="delivered">Delivered</option></select></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Discount (<?=CURRENCY_SYMBOL?>)</label><input type="number" name="discount" id="discountInput" class="form-control" value="0" step="0.01" min="0" onchange="calcTotal()"></div>
    </div>
    <h6 class="fw-semibold mb-2">Line Items</h6>
    <div class="table-responsive"><table class="table table-sm table-bordered" id="itemsTable">
      <thead class="table-light"><tr><th>Product / Description</th><th style="width:80px">Qty</th><th style="width:130px">Unit Price</th><th style="width:80px">Tax%</th><th style="width:110px">Line Total</th><th style="width:40px"></th></tr></thead>
      <tbody id="itemsBody"></tbody>
    </table></div>
    <button type="button" class="btn btn-sm btn-outline-success" onclick="addRow()"><i class="fas fa-plus me-1"></i>Add Line</button>
    <div class="text-end mt-3 p-3 bg-light rounded">
      <span class="me-4">Subtotal: <strong id="dispSub">KES 0.00</strong></span>
      <span class="me-4">Tax: <strong id="dispTax">KES 0.00</strong></span>
      <span class="me-4">Discount: <strong id="dispDisc">KES 0.00</strong></span>
      <span class="fs-5">Total: <strong class="text-success" id="dispTotal">KES 0.00</strong></span>
    </div>
    <div class="mt-3"><label class="form-label fw-semibold">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Create Order</button></div>
  </form>
</div></div></div>
<form method="POST" id="delOForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delOId"></form>
<?php
$prodsJson=json_encode(array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['name'],'price'=>(float)$p['price'],'tax'=>(float)$p['tax_rate']],$products));
$sym=CURRENCY_SYMBOL;
$extraJs=<<<JS
<script>
const salesProducts=$prodsJson;
const sym='{$sym}';
function fmt(v){return sym+parseFloat(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function addRow(desc='',qty=1,price=0,tax=0){
  const b=document.getElementById('itemsBody');const r=b.insertRow();const i=b.rows.length-1;
  let opts=salesProducts.map(p=>`<option value="\${p.id}" data-price="\${p.price}" data-tax="\${p.tax}">\${p.name}</option>`).join('');
  r.innerHTML=`<td><select class="form-select form-select-sm prod-sel" name="item_desc[]" onchange="fillRow(this,\${i})" required><option value="">-- select product --</option>\${opts}</select></td><td><input type="number" name="item_qty[]" id="qty\${i}" class="form-control form-control-sm" value="\${qty}" min="0.01" step="0.01" onchange="calcLine(\${i});calcTotal()"></td><td><input type="number" name="item_price[]" id="price\${i}" class="form-control form-control-sm" value="\${price}" step="0.01" onchange="calcLine(\${i});calcTotal()"></td><td><input type="number" name="item_tax[]" id="tax\${i}" class="form-control form-control-sm" value="\${tax}" step="0.01" onchange="calcLine(\${i});calcTotal()"></td><td><input type="text" id="line\${i}" class="form-control form-control-sm text-end" readonly value="0.00"></td><td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove();calcTotal()"><i class="fas fa-times"></i></button></td>`;
  calcTotal();
}
function fillRow(sel,i){
  const o=sel.options[sel.selectedIndex];if(!o.dataset.price)return;
  document.getElementById('price'+i).value=o.dataset.price;
  document.getElementById('tax'+i).value=o.dataset.tax;
  calcLine(i);calcTotal();
}
function calcLine(i){
  const q=parseFloat(document.getElementById('qty'+i)?.value||0);
  const p=parseFloat(document.getElementById('price'+i)?.value||0);
  const t=parseFloat(document.getElementById('tax'+i)?.value||0);
  const l=q*p*(1+t/100);const el=document.getElementById('line'+i);
  if(el)el.value=l.toFixed(2);
}
function calcTotal(){
  let sub=0,tax=0;
  document.getElementById('itemsBody').querySelectorAll('tr').forEach((r,i)=>{
    const q=parseFloat(r.querySelector('[name="item_qty[]"]')?.value||0);
    const p=parseFloat(r.querySelector('[name="item_price[]"]')?.value||0);
    const t=parseFloat(r.querySelector('[name="item_tax[]"]')?.value||0);
    sub+=q*p;tax+=q*p*t/100;
  });
  const disc=parseFloat(document.getElementById('discountInput')?.value||0);
  document.getElementById('dispSub').textContent=fmt(sub);
  document.getElementById('dispTax').textContent=fmt(tax);
  document.getElementById('dispDisc').textContent=fmt(disc);
  document.getElementById('dispTotal').textContent=fmt(sub+tax-disc);
}
function initForm(){document.getElementById('itemsBody').innerHTML='';addRow();calcTotal();}
function delOrd(id,no){Swal.fire({title:'Delete Order?',text:'Order '+no+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delOId').value=id;document.getElementById('delOForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
