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

    if($action==='open'){
        // Check no open shift for this cashier today
        $existing=countRows('pos_shifts','org_id=? AND cashier_id=? AND status=? AND shift_date=?',[$orgId,$user['id'],'open',date('Y-m-d')]);
        if($existing){setFlash('error','You already have an open shift today. Close it first.');redirect('shifts.php');}
        $float=(float)($_POST['opening_float']??0);
        $notes=sanitize($_POST['notes']??'');
        $pdo->prepare("INSERT INTO pos_shifts (org_id,cashier_id,cashier_name,shift_date,start_time,opening_float,status,notes) VALUES (?,?,?,CURDATE(),NOW(),?,?,?)")
           ->execute([$orgId,$user['id'],e($user['name']),$float,'open',$notes]);
        setFlash('success','Shift opened. Float: '.formatCurrency($float));
        redirect('shifts.php');
    }
    if($action==='close'){
        $id=(int)($_POST['id']??0);
        $closingFloat=(float)($_POST['closing_float']??0);
        $notes=sanitize($_POST['notes']??'');
        // Calculate shift totals
        $shift=$pdo->prepare("SELECT * FROM pos_shifts WHERE id=? AND org_id=?");$shift->execute([$id,$orgId]);$shift=$shift->fetch();
        if(!$shift){setFlash('error','Shift not found.');redirect('shifts.php');}
        $s=$pdo->prepare("SELECT COALESCE(SUM(total),0) AS ts,COALESCE(SUM(CASE WHEN payment_method='cash' THEN total ELSE 0 END),0) AS tc,COALESCE(SUM(CASE WHEN payment_method='mpesa' THEN total ELSE 0 END),0) AS tm,COALESCE(SUM(CASE WHEN payment_method='card' THEN total ELSE 0 END),0) AS tcard,COUNT(*) AS cnt FROM pos_sales WHERE org_id=? AND shift_id=? AND status!='void'");
        $s->execute([$orgId,$id]);$totals=$s->fetch();
        $expenses=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_expenses WHERE org_id=? AND shift_id=?");$expenses->execute([$orgId,$id]);$expTotal=(float)$expenses->fetchColumn();
        $returns=$pdo->prepare("SELECT COALESCE(SUM(refund_amount),0) FROM pos_returns WHERE org_id=? AND DATE(created_at)=?");$returns->execute([$orgId,$shift['shift_date']]);$retTotal=(float)$returns->fetchColumn();
        $pdo->prepare("UPDATE pos_shifts SET end_time=NOW(),closing_float=?,total_sales=?,total_cash=?,total_mpesa=?,total_card=?,total_expenses=?,total_returns=?,transactions=?,status='closed',notes=CONCAT(IFNULL(notes,''),' | Close: ',?) WHERE id=? AND org_id=?")
           ->execute([$closingFloat,$totals['ts'],$totals['tc'],$totals['tm'],$totals['tcard'],$expTotal,$retTotal,$totals['cnt'],$notes,$id,$orgId]);
        setFlash('success','Shift closed. Total sales: '.formatCurrency($totals['ts']));
        redirect('shifts.php');
    }
}

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$view=(int)($_GET['view']??0);

// Current open shift for this user
$openShift=null;
try{$s=$pdo->prepare("SELECT * FROM pos_shifts WHERE org_id=? AND cashier_id=? AND status='open' ORDER BY id DESC LIMIT 1");$s->execute([$orgId,$user['id']]);$openShift=$s->fetch();}catch(Exception $e){}

$shifts=[];
try{$s=$pdo->prepare("SELECT * FROM pos_shifts WHERE org_id=? ORDER BY shift_date DESC,id DESC");$s->execute([$orgId]);$shifts=$s->fetchAll();}catch(Exception $e){}

$viewData=null;$viewSales=[];$viewExpenses=[];
if($view){
    try{$s=$pdo->prepare("SELECT * FROM pos_shifts WHERE id=? AND org_id=?");$s->execute([$view,$orgId]);$viewData=$s->fetch();}catch(Exception $e){}
    if($viewData){
        try{$s=$pdo->prepare("SELECT * FROM pos_sales WHERE org_id=? AND shift_id=? ORDER BY created_at DESC");$s->execute([$orgId,$view]);$viewSales=$s->fetchAll();}catch(Exception $e){}
        try{$s=$pdo->prepare("SELECT * FROM pos_expenses WHERE org_id=? AND shift_id=? ORDER BY created_at");$s->execute([$orgId,$view]);$viewExpenses=$s->fetchAll();}catch(Exception $e){}
    }
}
$openShifts=countRows('pos_shifts','org_id=? AND status=?',[$orgId,'open']);

// Pre-fetch live totals for all open shifts → passed to JS for reconciliation modal
$openShiftTotals = [];
try {
    $openIds = array_values(array_map(fn($s) => $s['id'], array_filter($shifts, fn($s) => $s['status'] === 'open')));
    foreach ($openIds as $sid) {
        $ts = $pdo->prepare(
            "SELECT COALESCE(SUM(total),0) AS total_sales,
                    COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total ELSE 0 END),0) AS cash_sales,
                    COALESCE(SUM(CASE WHEN payment_method='mpesa' THEN total ELSE 0 END),0) AS mpesa_sales,
                    COALESCE(SUM(CASE WHEN payment_method='card'  THEN total ELSE 0 END),0) AS card_sales,
                    COUNT(*) AS txn_count
             FROM pos_sales WHERE org_id=? AND shift_id=? AND status!='void'"
        );
        $ts->execute([$orgId, $sid]);
        $totRow = $ts->fetch();

        $te = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_expenses WHERE org_id=? AND shift_id=?");
        $te->execute([$orgId, $sid]);
        $expTotal = (float)$te->fetchColumn();

        $openShiftTotals[$sid] = [
            'total_sales' => (float)$totRow['total_sales'],
            'cash_sales'  => (float)$totRow['cash_sales'],
            'mpesa_sales' => (float)$totRow['mpesa_sales'],
            'card_sales'  => (float)$totRow['card_sales'],
            'txn_count'   => (int)$totRow['txn_count'],
            'expenses'    => $expTotal,
        ];
    }
} catch (Exception $e) {}

// Index open shift rows by id for JS (opening_float lookup)
$openShiftFloats = [];
foreach ($shifts as $sh) {
    if ($sh['status'] === 'open') {
        $openShiftFloats[$sh['id']] = (float)$sh['opening_float'];
    }
}
?>
<?= flashAlert() ?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-clock me-2" style="color:<?=$moduleColor?>"></i>Cashier Shifts</h4><p class="text-muted mb-0">Manage cash sessions, float tracking and shift reconciliation</p></div>
  <?php if(!$openShift):?>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#openShiftModal"><i class="fas fa-play me-2"></i>Open Shift</button>
  <?php else:?>
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#closeShiftModal" data-id="<?=$openShift['id']?>"><i class="fas fa-stop me-2"></i>Close Current Shift</button>
  <?php endif;?>
</div>

<?php if($openShift):?>
<div class="alert alert-success d-flex align-items-center mb-3">
  <i class="fas fa-check-circle me-2 fs-5"></i>
  <div><strong>Shift Active</strong> — Opened <?=date('H:i',strtotime($openShift['start_time']))?> | Float: <?=formatCurrency($openShift['opening_float'])?>
  <a href="?view=<?=$openShift['id']?>" class="ms-2 btn btn-sm btn-outline-success">View Details</a></div>
</div>
<?php endif;?>

<div class="row g-3 mb-4">
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-history"></i></div><div class="stat-body"><div class="stat-value"><?=count($shifts)?></div><div class="stat-label">Total Shifts</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-door-open"></i></div><div class="stat-body"><div class="stat-value"><?=$openShifts?></div><div class="stat-label">Open Now</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-coins"></i></div><div class="stat-body"><div class="stat-value"><?php try{$s=$pdo->prepare("SELECT COALESCE(SUM(total_sales),0) FROM pos_shifts WHERE org_id=? AND shift_date=CURDATE()");$s->execute([$orgId]);echo formatCurrency((float)$s->fetchColumn());}catch(Exception $e){echo formatCurrency(0);} ?></div><div class="stat-label">Today's Sales</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-wallet"></i></div><div class="stat-body"><div class="stat-value"><?php try{$s=$pdo->prepare("SELECT COALESCE(SUM(total_expenses),0) FROM pos_shifts WHERE org_id=? AND shift_date=CURDATE()");$s->execute([$orgId]);echo formatCurrency((float)$s->fetchColumn());}catch(Exception $e){echo formatCurrency(0);} ?></div><div class="stat-label">Today's Expenses</div></div></div></div>
</div>

<div class="row g-3">
  <div class="<?=$view?'col-lg-6':'col-12'?>">
    <div class="card">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?=$moduleColor?>"></i>Shift History</h6></div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Cashier</th><th>Open</th><th>Close</th><th>Float</th><th>Sales</th><th>Txns</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if(empty($shifts)):?><tr><td colspan="9" class="text-center text-muted py-4">No shifts recorded.</td></tr>
        <?php else:foreach($shifts as $sh):?>
        <tr class="<?=$sh['status']==='open'?'table-success':''?>">
          <td><?=formatDate($sh['shift_date'])?></td>
          <td class="fw-semibold"><?=e($sh['cashier_name'])?></td>
          <td class="small"><?=date('H:i',strtotime($sh['start_time']))?></td>
          <td class="small"><?=$sh['end_time']?date('H:i',strtotime($sh['end_time'])):'—'?></td>
          <td><?=formatCurrency($sh['opening_float'])?></td>
          <td class="fw-semibold"><?=formatCurrency($sh['total_sales'])?></td>
          <td class="text-center"><?=$sh['transactions']?></td>
          <td><?=$sh['status']==='open'?'<span class="badge bg-success">Open</span>':'<span class="badge bg-secondary">Closed</span>'?></td>
          <td>
            <a href="?view=<?=$sh['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="fas fa-eye"></i></a>
            <?php if($sh['status']==='open'&&$sh['cashier_id']==$user['id']):?>
            <button class="btn btn-xs btn-outline-danger close-shift-btn" data-id="<?=$sh['id']?>" data-bs-toggle="modal" data-bs-target="#closeShiftModal"><i class="fas fa-stop"></i></button>
            <?php endif;?>
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
        <h6 class="mb-0">Shift — <?=formatDate($viewData['shift_date'])?> | <?=e($viewData['cashier_name'])?></h6>
        <a href="shifts.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
      </div>
      <div class="card-body">
        <div class="row g-2 mb-3 small text-center">
          <?php foreach([['Opening Float',formatCurrency($viewData['opening_float']),'navy'],['Total Sales',formatCurrency($viewData['total_sales']),'success'],['Cash Sales',formatCurrency($viewData['total_cash']),'info'],['M-Pesa',formatCurrency($viewData['total_mpesa']),'success'],['Card',formatCurrency($viewData['total_card']),'primary'],['Expenses',formatCurrency($viewData['total_expenses']),'danger'],['Returns',formatCurrency($viewData['total_returns']),'warning'],['Transactions',$viewData['transactions'].' txns','secondary']] as [$label,$val,$col]):?>
          <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-muted"><?=$label?></div><div class="fw-bold text-<?=$col?>"><?=$val?></div></div></div>
          <?php endforeach;?>
        </div>
        <?php if(!empty($viewSales)):?>
        <h6 class="small fw-bold text-muted mb-1">SALES (<?=count($viewSales)?>)</h6>
        <div style="max-height:150px;overflow-y:auto" class="mb-2">
        <?php foreach($viewSales as $sv):?>
        <div class="d-flex justify-content-between small border-bottom py-1">
          <span><?=e($sv['receipt_no']??$sv['receipt_number']??('#'.$sv['id']))?> <span class="text-muted"><?=date('H:i',strtotime($sv['created_at']))?></span></span>
          <span class="fw-semibold"><?=formatCurrency($sv['total'])?></span>
        </div>
        <?php endforeach;?>
        </div>
        <?php endif;?>
        <?php if(!empty($viewExpenses)):?>
        <h6 class="small fw-bold text-muted mb-1">EXPENSES (<?=count($viewExpenses)?>)</h6>
        <?php foreach($viewExpenses as $ex):?>
        <div class="d-flex justify-content-between small border-bottom py-1">
          <span><?=e($ex['description'])?></span><span class="text-danger fw-semibold"><?=formatCurrency($ex['amount'])?></span>
        </div>
        <?php endforeach;?>
        <?php endif;?>
      </div>
    </div>
  </div>
  <?php endif;?>
</div>

<!-- Open Shift Modal -->
<div class="modal fade" id="openShiftModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-play me-2 text-success"></i>Open Shift</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="open">
    <div class="mb-3"><label class="form-label fw-semibold">Opening Float (Cash in Drawer)</label><input type="number" name="opening_float" class="form-control" min="0" step="0.01" value="0" required></div>
    <div class="mb-3"><label class="form-label fw-semibold">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Open Shift</button></div>
  </form>
</div></div></div>

<!-- Close Shift Modal -->
<div class="modal fade" id="closeShiftModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="fas fa-stop me-2"></i>Close Shift — Cash Reconciliation</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?>
    <input type="hidden" name="action" value="close">
    <input type="hidden" name="id" id="closeShiftId" value="<?=$openShift['id']??0?>">

    <!-- Reconciliation summary (populated by JS) -->
    <div class="mb-3 p-3 rounded border" style="background:#f8f9fa;font-size:.85rem" id="reconSummary">
      <div class="fw-semibold mb-2 text-navy"><i class="fas fa-calculator me-2"></i>Shift Summary</div>
      <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Opening Float</span><span id="rOpenFloat">—</span></div>
      <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted"><i class="fas fa-money-bill-wave me-1 text-success"></i>Cash Sales</span><span id="rCashSales" class="text-success">—</span></div>
      <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted"><i class="fas fa-mobile-alt me-1 text-info"></i>M-Pesa Sales</span><span id="rMpesaSales" class="text-info">—</span></div>
      <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted"><i class="fas fa-credit-card me-1 text-primary"></i>Card Sales</span><span id="rCardSales" class="text-primary">—</span></div>
      <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted"><i class="fas fa-wallet me-1 text-danger"></i>Cash Expenses</span><span id="rExpenses" class="text-danger">—</span></div>
      <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Transactions</span><span id="rTxns">—</span></div>
      <div class="d-flex justify-content-between pt-2 fw-bold"><span>Expected Cash in Drawer</span><span id="rExpected" class="text-success">—</span></div>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">Actual Cash Counted <span class="text-danger">*</span></label>
      <input type="number" name="closing_float" id="closingFloatInput" class="form-control" min="0" step="0.01" value="0" required oninput="updateVariance()">
      <div class="mt-2 p-2 rounded text-center fw-bold" id="varianceDisplay" style="display:none;font-size:.9rem"></div>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Handover Notes</label>
      <textarea name="notes" class="form-control" rows="2" placeholder="Observations, discrepancies, handover instructions…"></textarea>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <a href="shift-report.php?id=<?=$openShift['id']??0?>" target="_blank" id="reportLink" class="btn btn-outline-secondary">
      <i class="fas fa-file-alt me-1"></i>Preview Report
    </a>
    <button type="submit" class="btn btn-danger"><i class="fas fa-stop me-1"></i>Close Shift</button>
  </div>
  </form>
</div></div></div>

<?php
$jsShiftTotals  = json_encode($openShiftTotals,  JSON_UNESCAPED_UNICODE);
$jsShiftFloats  = json_encode($openShiftFloats,   JSON_UNESCAPED_UNICODE);
$currSymbol     = addslashes(CURRENCY_SYMBOL);
$extraJs = <<<JS
<script>
const SHIFT_TOTALS = $jsShiftTotals;
const SHIFT_FLOATS = $jsShiftFloats;
const CURR = '$currSymbol';

function fmt(n) {
  return CURR + parseFloat(n||0).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function populateCloseModal(shiftId) {
  document.getElementById('closeShiftId').value = shiftId;

  const reportLink = document.getElementById('reportLink');
  if (reportLink) reportLink.href = 'shift-report.php?id=' + shiftId;

  const t = SHIFT_TOTALS[shiftId];
  const float = SHIFT_FLOATS[shiftId] || 0;

  if (!t) {
    document.getElementById('reconSummary').style.display = 'none';
    return;
  }
  document.getElementById('reconSummary').style.display = '';
  const expected = float + t.cash_sales - t.expenses;

  document.getElementById('rOpenFloat').textContent  = fmt(float);
  document.getElementById('rCashSales').textContent  = fmt(t.cash_sales);
  document.getElementById('rMpesaSales').textContent = fmt(t.mpesa_sales);
  document.getElementById('rCardSales').textContent  = fmt(t.card_sales);
  document.getElementById('rExpenses').textContent   = '− ' + fmt(t.expenses);
  document.getElementById('rTxns').textContent        = t.txn_count + ' transactions';
  document.getElementById('rExpected').textContent   = fmt(expected);
  document.getElementById('rExpected').dataset.expected = expected;

  // Preset closing float to expected and trigger variance
  document.getElementById('closingFloatInput').value = expected.toFixed(2);
  updateVariance();
}

function updateVariance() {
  const el       = document.getElementById('varianceDisplay');
  const expected = parseFloat(document.getElementById('rExpected').dataset.expected || 0);
  const actual   = parseFloat(document.getElementById('closingFloatInput').value) || 0;
  const variance = actual - expected;

  el.style.display = 'block';
  if (Math.abs(variance) < 0.01) {
    el.style.background = '#d1fae5';
    el.style.color      = '#065f46';
    el.textContent      = '✓ Balanced — no variance';
  } else if (variance > 0) {
    el.style.background = '#dbeafe';
    el.style.color      = '#1e40af';
    el.textContent      = 'Over by ' + fmt(variance) + ' (cash surplus)';
  } else {
    el.style.background = '#fee2e2';
    el.style.color      = '#991b1b';
    el.textContent      = 'Short by ' + fmt(Math.abs(variance)) + ' (cash deficit)';
  }
}

// Wire all close-shift buttons
document.querySelectorAll('.close-shift-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    populateCloseModal(parseInt(this.dataset.id));
  });
});

// Wire the header "Close Current Shift" button
const headerCloseBtn = document.querySelector('[data-bs-target="#closeShiftModal"][data-id]');
if (headerCloseBtn) {
  headerCloseBtn.addEventListener('click', function() {
    populateCloseModal(parseInt(this.dataset.id));
  });
}
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
