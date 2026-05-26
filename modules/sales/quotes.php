<?php
$moduleSlug='sales';$moduleName='Sales Management';$moduleIcon='fas fa-chart-line';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'targets.php','icon'=>'fas fa-bullseye','label'=>'Targets'],['url'=>'returns.php','icon'=>'fas fa-undo-alt','label'=>'Returns'],['url'=>'payments.php','icon'=>'fas fa-money-check-alt','label'=>'Payments'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);$custId=(int)($_POST['customer_id']??0)?:null;
        $quoteDate=$_POST['quote_date']??date('Y-m-d');$validUntil=$_POST['valid_until']??null;
        $discount=(float)($_POST['discount']??0);$notes=sanitize($_POST['notes']??'');
        $status=in_array($_POST['status']??'',['draft','sent','accepted','rejected','expired'])?$_POST['status']:'draft';
        $descs=$_POST['item_desc']??[];$qtys=$_POST['item_qty']??[];$prices=$_POST['item_price']??[];$taxes=$_POST['item_tax']??[];
        $subtotal=0;$taxTotal=0;$items=[];
        foreach($descs as $i=>$desc){$desc=sanitize($desc);if($desc==='')continue;$qty=(float)($qtys[$i]??1);$price=(float)($prices[$i]??0);$tax=(float)($taxes[$i]??0);$lineTotal=round($qty*$price*(1+$tax/100),2);$subtotal+=$qty*$price;$taxTotal+=round($qty*$price*$tax/100,2);$items[]=[$desc,$qty,$price,$tax,$lineTotal];}
        $total=round($subtotal+$taxTotal-$discount,2);
        if($id>0){$pdo->prepare("UPDATE sales_quotes SET customer_id=?,quote_date=?,valid_until=?,subtotal=?,discount=?,tax=?,total=?,status=?,notes=? WHERE id=? AND org_id=?")->execute([$custId,$quoteDate,$validUntil?:null,round($subtotal,2),$discount,round($taxTotal,2),$total,$status,$notes,$id,$orgId]);$pdo->prepare("DELETE FROM sales_quote_items WHERE quote_id=?")->execute([$id]);setFlash('success','Quote updated.');}
        else{$count=countRows('sales_quotes','org_id=?',[$orgId]);$qNo='QUO-'.str_pad($count+1,5,'0',STR_PAD_LEFT);$pdo->prepare("INSERT INTO sales_quotes(org_id,quote_no,customer_id,quote_date,valid_until,subtotal,discount,tax,total,status,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?)")->execute([$orgId,$qNo,$custId,$quoteDate,$validUntil?:null,round($subtotal,2),$discount,round($taxTotal,2),$total,$status,$notes]);$id=(int)$pdo->lastInsertId();setFlash('success',"Quote $qNo created.");}
        $stmt=$pdo->prepare("INSERT INTO sales_quote_items(quote_id,description,qty,unit_price,tax_rate,total)VALUES(?,?,?,?,?,?)");foreach($items as $it)$stmt->execute([$id,...$it]);
        redirect('quotes.php');
    }
    if($action==='convert'){
        $id=(int)($_POST['id']??0);$stmt=$pdo->prepare("SELECT * FROM sales_quotes WHERE id=? AND org_id=?");$stmt->execute([$id,$orgId]);$q=$stmt->fetch();
        if($q){$count=countRows('sales_orders','org_id=?',[$orgId]);$oNo='ORD-'.str_pad($count+1,5,'0',STR_PAD_LEFT);$pdo->prepare("INSERT INTO sales_orders(org_id,order_no,customer_id,quote_id,order_date,subtotal,discount,tax,total,paid,status)VALUES(?,?,?,?,?,?,?,?,?,0,'pending')")->execute([$orgId,$oNo,$q['customer_id'],$id,date('Y-m-d'),$q['subtotal'],$q['discount'],$q['tax'],$q['total']]);$oid=(int)$pdo->lastInsertId();$items=$pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id=?");$items->execute([$id]);$ins=$pdo->prepare("INSERT INTO sales_order_items(order_id,description,qty,unit_price,tax_rate,total)VALUES(?,?,?,?,?,?)");foreach($items->fetchAll() as $it)$ins->execute([$oid,$it['description'],$it['qty'],$it['unit_price'],$it['tax_rate'],$it['total']]);$pdo->prepare("UPDATE sales_quotes SET status='accepted' WHERE id=?")->execute([$id]);setFlash('success',"Quote converted to Order $oNo.");}
        redirect('quotes.php');
    }
    if($action==='delete'){$id=(int)($_POST['id']??0);$pdo->prepare("DELETE FROM sales_quote_items WHERE quote_id=?")->execute([$id]);$pdo->prepare("DELETE FROM sales_quotes WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Quote deleted.');redirect('quotes.php');}
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fStatus=$_GET['status']??'';
$where='q.org_id=?';$params=[$orgId];if($fStatus){$where.=' AND q.status=?';$params[]=$fStatus;}
$quotes=[];try{$stmt=$pdo->prepare("SELECT q.*,c.name AS customer_name FROM sales_quotes q LEFT JOIN sales_customers c ON q.customer_id=c.id WHERE $where ORDER BY q.created_at DESC");$stmt->execute($params);$quotes=$stmt->fetchAll();}catch(Exception $e){}
$customers=[];try{$stmt=$pdo->prepare("SELECT id,name FROM sales_customers WHERE org_id=? AND status='active' ORDER BY name");$stmt->execute([$orgId]);$customers=$stmt->fetchAll();}catch(Exception $e){}
$products=[];try{$stmt=$pdo->prepare("SELECT id,name,price,tax_rate FROM sales_products WHERE org_id=? AND status='active' ORDER BY name");$stmt->execute([$orgId]);$products=$stmt->fetchAll();}catch(Exception $e){}
$viewQ=null;$viewQI=[];
if(isset($_GET['view'])){$qid=(int)$_GET['view'];try{$stmt=$pdo->prepare("SELECT q.*,c.name AS customer_name FROM sales_quotes q LEFT JOIN sales_customers c ON q.customer_id=c.id WHERE q.id=? AND q.org_id=?");$stmt->execute([$qid,$orgId]);$viewQ=$stmt->fetch();$stmt=$pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id=?");$stmt->execute([$qid]);$viewQI=$stmt->fetchAll();}catch(Exception $e){}}
$tDraft=countRows('sales_quotes','org_id=? AND status=?',[$orgId,'draft']);$tSent=countRows('sales_quotes','org_id=? AND status=?',[$orgId,'sent']);$tAcc=countRows('sales_quotes','org_id=? AND status=?',[$orgId,'accepted']);
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-file-alt me-2" style="color:<?=$moduleColor?>"></i>Quotes</h4><p class="text-muted mb-0">Create quotes and convert them to orders</p></div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#qModal" onclick="initQForm()"><i class="fas fa-plus me-2"></i>New Quote</button>
</div>
<div class="row g-3 mb-4">
  <?php foreach([['info-bg','fas fa-file-alt',$tDraft,'Drafts'],['warning-bg','fas fa-paper-plane',$tSent,'Sent'],['green-bg','fas fa-check-circle',$tAcc,'Accepted'],['navy-bg','fas fa-list',countRows('sales_quotes','org_id=?',[$orgId]),'Total']] as [$cl,$ic,$v,$lb]):?>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon <?=$cl?>"><i class="<?=$ic?>"></i></div><div class="stat-body"><div class="stat-value"><?=$v?></div><div class="stat-label"><?=$lb?></div></div></div></div>
  <?php endforeach;?>
</div>
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach(['draft','sent','accepted','rejected','expired'] as $s):?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="quotes.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<?php if($viewQ):?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:<?=$moduleColor?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-file-invoice me-2"></i>Quote <?=e($viewQ['quote_no'])?> — <?=e($viewQ['customer_name']??'—')?></h6>
    <div class="d-flex gap-2">
      <?php if(!in_array($viewQ['status'],['accepted','rejected'])):?>
      <form method="POST" class="d-inline"><input type="hidden" name="action" value="convert"><?=csrfField()?><input type="hidden" name="id" value="<?=$viewQ['id']?>"><button type="submit" class="btn btn-sm btn-light" onclick="return confirm('Convert to Order?')"><i class="fas fa-exchange-alt me-1"></i>Convert to Order</button></form>
      <?php endif;?>
      <a href="quotes.php" class="btn btn-sm btn-light"><i class="fas fa-times me-1"></i>Close</a>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-3 mb-3">
      <div class="col-md-3"><strong>Customer:</strong> <?=e($viewQ['customer_name']??'—')?></div>
      <div class="col-md-3"><strong>Quote Date:</strong> <?=formatDate($viewQ['quote_date']??$viewQ['created_at'])?></div>
      <div class="col-md-3"><strong>Valid Until:</strong> <?=formatDate($viewQ['valid_until'])?></div>
      <div class="col-md-3"><strong>Status:</strong> <?=statusBadge($viewQ['status']??'draft')?></div>
    </div>
    <div class="table-responsive"><table class="table table-sm table-bordered">
      <thead class="table-light"><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Tax%</th><th class="text-end">Total</th></tr></thead>
      <tbody><?php if(empty($viewQI)):?><tr><td colspan="5" class="text-center text-muted">No items</td></tr><?php else:foreach($viewQI as $it):?><tr><td><?=e($it['description'])?></td><td class="text-end"><?=$it['qty']?></td><td class="text-end"><?=formatCurrency((float)$it['unit_price'])?></td><td class="text-end"><?=$it['tax_rate']?>%</td><td class="text-end fw-semibold"><?=formatCurrency((float)$it['total'])?></td></tr><?php endforeach;endif;?></tbody>
      <tfoot class="table-light">
        <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end"><?=formatCurrency((float)$viewQ['subtotal'])?></td></tr>
        <tr><td colspan="4" class="text-end">Tax</td><td class="text-end"><?=formatCurrency((float)$viewQ['tax'])?></td></tr>
        <tr><td colspan="4" class="text-end">Discount</td><td class="text-end text-danger">-<?=formatCurrency((float)$viewQ['discount'])?></td></tr>
        <tr><td colspan="4" class="text-end fw-bold">TOTAL</td><td class="text-end fw-bold text-success"><?=formatCurrency((float)$viewQ['total'])?></td></tr>
      </tfoot>
    </table></div>
  </div>
</div>
<?php endif;?>

<div class="card"><div class="card-header d-flex align-items-center justify-content-between"><h6 class="mb-0"><i class="fas fa-file-alt me-2" style="color:<?=$moduleColor?>"></i>Quote List</h6><span class="badge bg-secondary"><?=count($quotes)?> quotes</span></div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Quote #</th><th>Customer</th><th>Date</th><th>Valid Until</th><th>Status</th><th class="text-end">Total</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(empty($quotes)):?><tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No quotes found.</td></tr>
<?php else:foreach($quotes as $q):?>
<tr>
  <td class="fw-semibold"><?=e($q['quote_no'])?></td><td><?=e($q['customer_name']??'—')?></td>
  <td><?=formatDate($q['created_at'])?></td><td><?=formatDate($q['valid_until'])?></td>
  <td><?=statusBadge($q['status']??'draft')?></td>
  <td class="text-end fw-semibold"><?=formatCurrency((float)$q['total'])?></td>
  <td class="text-center" style="white-space:nowrap">
    <a href="?view=<?=$q['id']?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a>
    <?php if(!in_array($q['status'],['accepted','rejected'])):?>
    <form method="POST" class="d-inline"><input type="hidden" name="action" value="convert"><?=csrfField()?><input type="hidden" name="id" value="<?=$q['id']?>"><button type="submit" class="btn btn-sm btn-outline-success ms-1" title="Convert to Order" onclick="return confirm('Convert to Order?')"><i class="fas fa-exchange-alt"></i></button></form>
    <?php endif;?>
    <button class="btn btn-sm btn-outline-danger ms-1" onclick="delQ(<?=$q['id']?>,'<?=e($q['quote_no'])?>')"><i class="fas fa-trash"></i></button>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<!-- New Quote Modal -->
<div class="modal fade" id="qModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff"><h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>New Quote</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="row g-3 mb-3">
      <div class="col-md-4"><label class="form-label fw-semibold">Customer</label><select name="customer_id" class="form-select"><option value="">Walk-in</option><?php foreach($customers as $c):?><option value="<?=$c['id']?>"><?=e($c['name'])?></option><?php endforeach;?></select></div>
      <div class="col-md-3"><label class="form-label fw-semibold">Quote Date</label><input type="date" name="quote_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
      <div class="col-md-3"><label class="form-label fw-semibold">Valid Until</label><input type="date" name="valid_until" class="form-control"></div>
      <div class="col-md-2"><label class="form-label fw-semibold">Status</label><select name="status" class="form-select"><option value="draft">Draft</option><option value="sent">Sent</option></select></div>
      <div class="col-md-3"><label class="form-label fw-semibold">Discount (<?=CURRENCY_SYMBOL?>)</label><input type="number" name="discount" id="qDiscInput" class="form-control" value="0" step="0.01" min="0" onchange="qCalcTotal()"></div>
    </div>
    <h6 class="fw-semibold mb-2">Line Items</h6>
    <div class="table-responsive"><table class="table table-sm table-bordered"><thead class="table-light"><tr><th>Product / Description</th><th style="width:80px">Qty</th><th style="width:130px">Unit Price</th><th style="width:80px">Tax%</th><th style="width:110px">Total</th><th style="width:40px"></th></tr></thead><tbody id="qItemsBody"></tbody></table></div>
    <button type="button" class="btn btn-sm btn-outline-success" onclick="qAddRow()"><i class="fas fa-plus me-1"></i>Add Line</button>
    <div class="text-end mt-3 p-3 bg-light rounded"><span class="me-4">Subtotal: <strong id="qDispSub">KES 0.00</strong></span><span class="me-4">Tax: <strong id="qDispTax">KES 0.00</strong></span><span class="fs-5">Total: <strong class="text-success" id="qDispTotal">KES 0.00</strong></span></div>
    <div class="mt-3"><label class="form-label fw-semibold">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Quote</button></div>
  </form>
</div></div></div>
<form method="POST" id="delQForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delQId"></form>
<?php
$prodsJson=json_encode(array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['name'],'price'=>(float)$p['price'],'tax'=>(float)$p['tax_rate']],$products));
$sym=CURRENCY_SYMBOL;
$extraJs=<<<JS
<script>
const qProds=$prodsJson;const sym='{$sym}';
function qFmt(v){return sym+parseFloat(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function qAddRow(){const b=document.getElementById('qItemsBody');const r=b.insertRow();const i=b.rows.length-1;let opts=qProds.map(p=>`<option value="\${p.name}" data-price="\${p.price}" data-tax="\${p.tax}">\${p.name}</option>`).join('');r.innerHTML=`<td><select class="form-select form-select-sm" name="item_desc[]" onchange="qFillRow(this,\${i})" required><option value="">-- select --</option>\${opts}</select></td><td><input type="number" name="item_qty[]" id="qq\${i}" class="form-control form-control-sm" value="1" min="0.01" step="0.01" onchange="qCalcLine(\${i});qCalcTotal()"></td><td><input type="number" name="item_price[]" id="qp\${i}" class="form-control form-control-sm" value="0" step="0.01" onchange="qCalcLine(\${i});qCalcTotal()"></td><td><input type="number" name="item_tax[]" id="qt\${i}" class="form-control form-control-sm" value="0" step="0.01" onchange="qCalcLine(\${i});qCalcTotal()"></td><td><input type="text" id="ql\${i}" class="form-control form-control-sm text-end" readonly value="0.00"></td><td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove();qCalcTotal()"><i class="fas fa-times"></i></button></td>`;qCalcTotal();}
function qFillRow(sel,i){const o=sel.options[sel.selectedIndex];if(!o.dataset.price)return;document.getElementById('qp'+i).value=o.dataset.price;document.getElementById('qt'+i).value=o.dataset.tax;qCalcLine(i);qCalcTotal();}
function qCalcLine(i){const q=parseFloat(document.getElementById('qq'+i)?.value||0);const p=parseFloat(document.getElementById('qp'+i)?.value||0);const t=parseFloat(document.getElementById('qt'+i)?.value||0);const el=document.getElementById('ql'+i);if(el)el.value=(q*p*(1+t/100)).toFixed(2);}
function qCalcTotal(){let sub=0,tax=0;document.getElementById('qItemsBody').querySelectorAll('tr').forEach((r,i)=>{const q=parseFloat(r.querySelector('[name="item_qty[]"]')?.value||0);const p=parseFloat(r.querySelector('[name="item_price[]"]')?.value||0);const t=parseFloat(r.querySelector('[name="item_tax[]"]')?.value||0);sub+=q*p;tax+=q*p*t/100;});const disc=parseFloat(document.getElementById('qDiscInput')?.value||0);document.getElementById('qDispSub').textContent=qFmt(sub);document.getElementById('qDispTax').textContent=qFmt(tax);document.getElementById('qDispTotal').textContent=qFmt(sub+tax-disc);}
function initQForm(){document.getElementById('qItemsBody').innerHTML='';qAddRow();}
function delQ(id,no){Swal.fire({title:'Delete Quote?',text:'Quote '+no+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delQId').value=id;document.getElementById('delQForm').submit();}});}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
