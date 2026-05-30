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
        $category=sanitize($_POST['category']??'General');
        $description=sanitize($_POST['description']??'');
        $amount=(float)($_POST['amount']??0);
        $payMethod=sanitize($_POST['payment_method']??'cash');
        $receiptNo=sanitize($_POST['receipt_no']??'');
        $expDate=sanitize($_POST['expense_date']??date('Y-m-d'));
        $shiftId=(int)($_POST['shift_id']??0)||null;
        if(!$description||$amount<=0){setFlash('error','Description and amount are required.');redirect('expenses.php');}
        if($id){
            $pdo->prepare("UPDATE pos_expenses SET category=?,description=?,amount=?,payment_method=?,receipt_no=?,expense_date=?,shift_id=? WHERE id=? AND org_id=?")
               ->execute([$category,$description,$amount,$payMethod,$receiptNo,$expDate,$shiftId,$id,$orgId]);
            setFlash('success','Expense updated.');
        } else {
            $pdo->prepare("INSERT INTO pos_expenses (org_id,shift_id,category,description,amount,payment_method,receipt_no,expense_date,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$orgId,$shiftId,$category,$description,$amount,$payMethod,$receiptNo,$expDate,$user['id']]);
            setFlash('success','Expense recorded.');
        }
        redirect('expenses.php');
    }
    if($action==='delete'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM pos_expenses WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Expense deleted.');redirect('expenses.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];

$fFrom=$_GET['date_from']??date('Y-m-01');$fTo=$_GET['date_to']??date('Y-m-d');$fCat=$_GET['category']??'';

$where='org_id=?';$params=[$orgId];
if($fFrom&&$fTo){$where.=' AND expense_date BETWEEN ? AND ?';$params[]=$fFrom;$params[]=$fTo;}
if($fCat){$where.=' AND category=?';$params[]=$fCat;}

$expenses=[];
try{$s=$pdo->prepare("SELECT e.*,u.name AS user_name FROM pos_expenses e LEFT JOIN users u ON e.created_by=u.id WHERE $where ORDER BY e.expense_date DESC,e.id DESC");$s->execute($params);$expenses=$s->fetchAll();}catch(Exception $e){}

// Summary by category
$catSummary=[];
try{$s=$pdo->prepare("SELECT category,SUM(amount) AS total FROM pos_expenses WHERE org_id=? AND expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");$s->execute([$orgId,$fFrom,$fTo]);$catSummary=$s->fetchAll();}catch(Exception $e){}

$totalExpenses=array_sum(array_column($catSummary,'total'));
$todayExpenses=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_expenses WHERE org_id=? AND expense_date=CURDATE()");$s->execute([$orgId]);$todayExpenses=(float)$s->fetchColumn();}catch(Exception $e){}
$monthExpenses=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_expenses WHERE org_id=? AND MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())");$s->execute([$orgId]);$monthExpenses=(float)$s->fetchColumn();}catch(Exception $e){}

// Open shifts for dropdown
$openShifts=[];try{$s=$pdo->prepare("SELECT id,cashier_name,start_time FROM pos_shifts WHERE org_id=? AND status='open' ORDER BY start_time DESC");$s->execute([$orgId]);$openShifts=$s->fetchAll();}catch(Exception $e){}

// Categories list
$cats=[];try{$s=$pdo->prepare("SELECT DISTINCT category FROM pos_expenses WHERE org_id=? ORDER BY category");$s->execute([$orgId]);$cats=array_column($s->fetchAll(),null,'category');}catch(Exception $e){}
$defaultCats=['General','Rent','Utilities','Transport','Salaries','Supplies','Maintenance','Marketing','Petty Cash','Other'];
?>
<?= flashAlert() ?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-wallet me-2" style="color:<?=$moduleColor?>"></i>Expenses</h4><p class="text-muted mb-0">Track daily expenses and petty cash outflows</p></div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#expModal"><i class="fas fa-plus me-2"></i>Add Expense</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-calendar-day"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($todayExpenses)?></div><div class="stat-label">Today</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-calendar-alt"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($monthExpenses)?></div><div class="stat-label">This Month</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-filter"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($totalExpenses)?></div><div class="stat-label">Period Total</div></div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?=$moduleColor?>"></i>By Category (Period)</h6></div>
      <div class="card-body">
        <?php if(empty($catSummary)):?><p class="text-muted small">No expenses in this period.</p>
        <?php else:foreach($catSummary as $cs):$pct=$totalExpenses>0?round(100*$cs['total']/$totalExpenses):0;?>
        <div class="mb-3">
          <div class="d-flex justify-content-between small mb-1"><span class="fw-semibold"><?=e($cs['category'])?></span><span><?=formatCurrency($cs['total'])?> (<?=$pct?>%)</span></div>
          <div class="progress" style="height:8px"><div class="progress-bar" style="width:<?=$pct?>%;background:<?=$moduleColor?>"></div></div>
        </div>
        <?php endforeach;endif;?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <form class="row g-2 align-items-center" method="GET">
          <div class="col-sm-3"><input type="date" name="date_from" class="form-control form-control-sm" value="<?=e($fFrom)?>"></div>
          <div class="col-sm-3"><input type="date" name="date_to" class="form-control form-control-sm" value="<?=e($fTo)?>"></div>
          <div class="col-sm-3"><select name="category" class="form-select form-select-sm"><option value="">All Categories</option><?php foreach($defaultCats as $c):?><option value="<?=$c?>" <?=$fCat===$c?'selected':''?>><?=$c?></option><?php endforeach;?></select></div>
          <div class="col-auto"><button class="btn btn-sm btn-success"><i class="fas fa-filter"></i></button><a href="expenses.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
        </form>
      </div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Category</th><th>Description</th><th>Payment</th><th>Receipt #</th><th>By</th><th class="text-end">Amount</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($expenses)):?><tr><td colspan="8" class="text-center text-muted py-4">No expenses found.</td></tr>
        <?php else:foreach($expenses as $ex):?>
        <tr>
          <td><?=formatDate($ex['expense_date'])?></td>
          <td><span class="badge bg-secondary"><?=e($ex['category'])?></span></td>
          <td class="fw-semibold"><?=e($ex['description'])?></td>
          <td class="small"><?=ucfirst($ex['payment_method'])?></td>
          <td class="small text-muted"><?=e($ex['receipt_no']??'—')?></td>
          <td class="small"><?=e($ex['user_name']??'—')?></td>
          <td class="text-end fw-semibold text-danger"><?=formatCurrency($ex['amount'])?></td>
          <td>
            <button class="btn btn-xs btn-outline-secondary me-1 btn-edit"
              data-id="<?=$ex['id']?>" data-category="<?=e($ex['category'])?>"
              data-description="<?=e($ex['description'])?>" data-amount="<?=$ex['amount']?>"
              data-payment_method="<?=$ex['payment_method']?>" data-receipt_no="<?=e($ex['receipt_no']??'')?>"
              data-expense_date="<?=$ex['expense_date']?>" data-shift_id="<?=$ex['shift_id']??0?>"><i class="fas fa-edit"></i></button>
            <form method="POST" class="d-inline">
              <?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$ex['id']?>">
              <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete this expense?"><i class="fas fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach;endif;?>
        </tbody>
        <tfoot class="table-light"><tr><td colspan="6" class="text-end fw-bold">Period Total</td><td class="text-end fw-bold text-danger"><?=formatCurrency($totalExpenses)?></td><td></td></tr></tfoot>
      </table></div></div>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="expModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-wallet me-2"></i><span id="expModalTitle">Add Expense</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="expId" value="0">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label fw-semibold">Date</label><input type="date" name="expense_date" id="expDate" class="form-control" value="<?=date('Y-m-d')?>"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Category</label>
        <input type="text" name="category" id="expCategory" class="form-control" list="catList" required>
        <datalist id="catList"><?php foreach($defaultCats as $c):?><option value="<?=$c?>"><?php endforeach;?></datalist>
      </div>
      <div class="col-12"><label class="form-label fw-semibold">Description <span class="text-danger">*</span></label><input type="text" name="description" id="expDescription" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label><input type="number" name="amount" id="expAmount" class="form-control" min="0.01" step="0.01" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Payment Method</label>
        <select name="payment_method" id="expPayMethod" class="form-select"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="card">Card</option><option value="bank">Bank</option></select>
      </div>
      <div class="col-md-6"><label class="form-label fw-semibold">Receipt #</label><input type="text" name="receipt_no" id="expReceiptNo" class="form-control" placeholder="Optional"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Link to Shift</label>
        <select name="shift_id" id="expShiftId" class="form-select"><option value="">— No shift —</option>
          <?php foreach($openShifts as $sh):?><option value="<?=$sh['id']?>"><?=e($sh['cashier_name'])?> (<?=date('H:i',strtotime($sh['start_time']))?> )</option><?php endforeach;?>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Expense</button></div>
  </form>
</div></div></div>

<?php $extraJs=<<<JS
<script>
document.querySelectorAll('.btn-edit').forEach(btn=>{
  btn.addEventListener('click',function(){
    document.getElementById('expModalTitle').textContent='Edit Expense';
    document.getElementById('expId').value=this.dataset.id;
    document.getElementById('expCategory').value=this.dataset.category;
    document.getElementById('expDescription').value=this.dataset.description;
    document.getElementById('expAmount').value=this.dataset.amount;
    document.getElementById('expPayMethod').value=this.dataset.payment_method;
    document.getElementById('expReceiptNo').value=this.dataset.receipt_no;
    document.getElementById('expDate').value=this.dataset.expense_date;
    document.getElementById('expShiftId').value=this.dataset.shift_id||'';
    new bootstrap.Modal(document.getElementById('expModal')).show();
  });
});
document.querySelectorAll('.btn-confirm').forEach(btn=>{btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});});
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
