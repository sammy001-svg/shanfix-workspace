<?php
$moduleSlug='pos';$moduleName='Point of Sale';$moduleIcon='fas fa-cash-register';$moduleColor='#e74c3c';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'terminal.php','icon'=>'fas fa-cash-register','label'=>'POS Terminal'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'categories.php','icon'=>'fas fa-tags','label'=>'Categories'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'suppliers.php','icon'=>'fas fa-truck','label'=>'Suppliers'],['url'=>'stock.php','icon'=>'fas fa-warehouse','label'=>'Stock'],['url'=>'purchases.php','icon'=>'fas fa-cart-arrow-down','label'=>'Purchases'],['url'=>'returns.php','icon'=>'fas fa-undo','label'=>'Returns'],['url'=>'shifts.php','icon'=>'fas fa-clock','label'=>'Shifts'],['url'=>'expenses.php','icon'=>'fas fa-wallet','label'=>'Expenses'],['url'=>'discounts.php','icon'=>'fas fa-percent','label'=>'Discounts'],['url'=>'sales.php','icon'=>'fas fa-receipt','label'=>'Sales History'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];

$fFrom=$_GET['date_from']??date('Y-m-01');
$fTo=$_GET['date_to']??date('Y-m-d');
$fReport=$_GET['report']??'sales';

// ── Period KPIs ───────────────────────────────────────────────────
$periodSales=0;$periodRevenue=0;$periodTax=0;$periodDiscount=0;$periodTransactions=0;
try{
    $s=$pdo->prepare("SELECT COUNT(*) AS cnt,COALESCE(SUM(total),0) AS rev,COALESCE(SUM(tax),0) AS tax,COALESCE(SUM(discount),0) AS disc FROM pos_sales WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ? AND status!='void'");
    $s->execute([$orgId,$fFrom,$fTo]);$r=$s->fetch();
    $periodTransactions=(int)$r['cnt'];$periodRevenue=(float)$r['rev'];$periodTax=(float)$r['tax'];$periodDiscount=(float)$r['disc'];
}catch(Exception $e){}

$periodExpenses=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_expenses WHERE org_id=? AND expense_date BETWEEN ? AND ?");$s->execute([$orgId,$fFrom,$fTo]);$periodExpenses=(float)$s->fetchColumn();}catch(Exception $e){}
$periodReturns=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(refund_amount),0) FROM pos_returns WHERE org_id=? AND return_date BETWEEN ? AND ?");$s->execute([$orgId,$fFrom,$fTo]);$periodReturns=(float)$s->fetchColumn();}catch(Exception $e){}
$netProfit=$periodRevenue-$periodExpenses-$periodReturns;
$avgTicket=$periodTransactions>0?$periodRevenue/$periodTransactions:0;

// ── Daily sales trend (period) ────────────────────────────────────
$trendLabels=[];$trendRevData=[];$trendCntData=[];
try{
    $s=$pdo->prepare("SELECT DATE(created_at) AS d,COUNT(*) AS cnt,SUM(total) AS rev FROM pos_sales WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ? AND status!='void' GROUP BY d ORDER BY d");
    $s->execute([$orgId,$fFrom,$fTo]);
    foreach($s->fetchAll() as $r){$trendLabels[]=date('d M',strtotime($r['d']));$trendRevData[]=(float)$r['rev'];$trendCntData[]=(int)$r['cnt'];}
}catch(Exception $e){}

// ── Payment method breakdown ──────────────────────────────────────
$payMethods=['cash'=>0,'mpesa'=>0,'card'=>0,'credit'=>0,'other'=>0];
try{$s=$pdo->prepare("SELECT payment_method,COALESCE(SUM(total),0) AS rev FROM pos_sales WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ? AND status!='void' GROUP BY payment_method");$s->execute([$orgId,$fFrom,$fTo]);foreach($s->fetchAll() as $r){$k=$r['payment_method']??'other';if(!isset($payMethods[$k]))$payMethods['other']+=$r['rev'];else $payMethods[$k]+=$r['rev'];}}catch(Exception $e){}

// ── Top selling products ──────────────────────────────────────────
$topProducts=[];
try{$s=$pdo->prepare("SELECT i.product_name,SUM(i.quantity) AS total_qty,SUM(i.total) AS total_rev,COUNT(DISTINCT i.sale_id) AS sales FROM pos_sale_items i JOIN pos_sales s ON i.sale_id=s.id WHERE s.org_id=? AND DATE(s.created_at) BETWEEN ? AND ? AND s.status!='void' GROUP BY i.product_name ORDER BY total_rev DESC LIMIT 10");$s->execute([$orgId,$fFrom,$fTo]);$topProducts=$s->fetchAll();}catch(Exception $e){}

// ── Cashier performance ───────────────────────────────────────────
$cashierPerf=[];
try{$s=$pdo->prepare("SELECT cashier_name,COUNT(*) AS txns,SUM(total) AS rev,AVG(total) AS avg_ticket FROM pos_sales WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ? AND status!='void' GROUP BY cashier_name ORDER BY rev DESC");$s->execute([$orgId,$fFrom,$fTo]);$cashierPerf=$s->fetchAll();}catch(Exception $e){}

// ── Hourly sales heatmap ──────────────────────────────────────────
$hourlyRev=array_fill(0,24,0);$hourlyCnt=array_fill(0,24,0);
try{$s=$pdo->prepare("SELECT HOUR(created_at) AS h,COUNT(*) AS cnt,SUM(total) AS rev FROM pos_sales WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ? AND status!='void' GROUP BY h");$s->execute([$orgId,$fFrom,$fTo]);foreach($s->fetchAll() as $r){$hourlyRev[(int)$r['h']]=(float)$r['rev'];$hourlyCnt[(int)$r['h']]=(int)$r['cnt'];}}catch(Exception $e){}

// ── Category sales ────────────────────────────────────────────────
$catSales=[];
try{$s=$pdo->prepare("SELECT c.name AS cat,COALESCE(SUM(i.total),0) AS rev,COALESCE(SUM(i.quantity),0) AS qty FROM pos_sale_items i JOIN pos_sales s ON i.sale_id=s.id JOIN pos_products p ON i.product_id=p.id JOIN pos_categories c ON p.category_id=c.id WHERE s.org_id=? AND DATE(s.created_at) BETWEEN ? AND ? AND s.status!='void' GROUP BY c.name ORDER BY rev DESC LIMIT 8");$s->execute([$orgId,$fFrom,$fTo]);$catSales=$s->fetchAll();}catch(Exception $e){}
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?=$moduleColor?>"></i>Reports & Analytics</h4><p class="text-muted mb-0">Sales performance, product analysis and financial summary</p></div>
  <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
</div>

<!-- Filter -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">From</label><input type="date" name="date_from" class="form-control form-control-sm" value="<?=e($fFrom)?>"></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">To</label><input type="date" name="date_to" class="form-control form-control-sm" value="<?=e($fTo)?>"></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="reports.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
    <div class="col-auto ms-auto">
      <span class="small text-muted">Period: <strong><?=formatDate($fFrom)?> — <?=formatDate($fTo)?></strong></span>
    </div>
  </form>
</div></div>

<!-- KPIs -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-2"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-receipt"></i></div><div class="stat-body"><div class="stat-value"><?=$periodTransactions?></div><div class="stat-label">Transactions</div></div></div></div>
  <div class="col-sm-6 col-xl-2"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-dollar-sign"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($periodRevenue)?></div><div class="stat-label">Gross Revenue</div></div></div></div>
  <div class="col-sm-6 col-xl-2"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-ticket-alt"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($avgTicket)?></div><div class="stat-label">Avg Ticket</div></div></div></div>
  <div class="col-sm-6 col-xl-2"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-wallet"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($periodExpenses)?></div><div class="stat-label">Expenses</div></div></div></div>
  <div class="col-sm-6 col-xl-2"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-undo"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($periodReturns)?></div><div class="stat-label">Refunds</div></div></div></div>
  <div class="col-sm-6 col-xl-2"><div class="stat-card"><div class="stat-icon <?=$netProfit>=0?'green':'danger'?>-bg"><i class="fas fa-chart-line"></i></div><div class="stat-body"><div class="stat-value <?=$netProfit>=0?'':'text-danger'?>"><?=formatCurrency($netProfit)?></div><div class="stat-label">Net Profit</div></div></div></div>
</div>

<!-- Revenue + Payment charts -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100"><div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-line me-2" style="color:<?=$moduleColor?>"></i>Daily Revenue Trend</h6></div>
    <div class="card-body"><canvas id="revChart" height="100"></canvas></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?=$moduleColor?>"></i>Payment Methods</h6></div>
    <div class="card-body d-flex align-items-center justify-content-center"><canvas id="payChart" height="220"></canvas></div></div>
  </div>
</div>

<!-- Top Products + Category -->
<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-trophy me-2" style="color:<?=$moduleColor?>"></i>Top 10 Products by Revenue</h6></div>
    <div class="card-body p-0"><table class="table table-hover mb-0 small">
      <thead class="table-light"><tr><th>#</th><th>Product</th><th class="text-center">Qty Sold</th><th class="text-center">Txns</th><th class="text-end">Revenue</th></tr></thead>
      <tbody>
      <?php if(empty($topProducts)):?><tr><td colspan="5" class="text-center text-muted py-3">No sales data.</td></tr>
      <?php else:foreach($topProducts as $i=>$p):?>
      <tr>
        <td><span class="badge bg-<?=$i<3?'warning text-dark':'light text-dark'?>"><?=$i+1?></span></td>
        <td class="fw-semibold"><?=e($p['product_name'])?></td>
        <td class="text-center"><?=number_format((float)$p['total_qty'],1)?></td>
        <td class="text-center"><?=$p['sales']?></td>
        <td class="text-end fw-semibold"><?=formatCurrency($p['total_rev'])?></td>
      </tr>
      <?php endforeach;endif;?>
      </tbody>
    </table></div></div>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-tags me-2" style="color:<?=$moduleColor?>"></i>Sales by Category</h6></div>
    <div class="card-body">
      <?php if(empty($catSales)):?><p class="text-muted small">No data.</p>
      <?php else:
        $maxCatRev=max(array_column($catSales,'rev'));
        foreach($catSales as $cs):$pct=$maxCatRev>0?round(100*$cs['rev']/$maxCatRev):0;?>
      <div class="mb-3">
        <div class="d-flex justify-content-between small mb-1"><span class="fw-semibold"><?=e($cs['cat'])?></span><span><?=formatCurrency($cs['rev'])?></span></div>
        <div class="progress" style="height:8px"><div class="progress-bar" style="width:<?=$pct?>%;background:<?=$moduleColor?>"></div></div>
      </div>
      <?php endforeach;endif;?>
    </div></div>
  </div>
</div>

<!-- Cashier + Hourly -->
<div class="row g-3 mb-4">
  <div class="col-lg-5">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-user-tie me-2" style="color:<?=$moduleColor?>"></i>Cashier Performance</h6></div>
    <div class="card-body p-0"><table class="table table-hover mb-0 small">
      <thead class="table-light"><tr><th>Cashier</th><th class="text-center">Txns</th><th class="text-end">Revenue</th><th class="text-end">Avg Ticket</th></tr></thead>
      <tbody>
      <?php if(empty($cashierPerf)):?><tr><td colspan="4" class="text-center text-muted py-3">No data.</td></tr>
      <?php else:foreach($cashierPerf as $cp):?>
      <tr>
        <td class="fw-semibold"><?=e($cp['cashier_name']??'—')?></td>
        <td class="text-center"><?=$cp['txns']?></td>
        <td class="text-end fw-semibold"><?=formatCurrency($cp['rev'])?></td>
        <td class="text-end"><?=formatCurrency($cp['avg_ticket'])?></td>
      </tr>
      <?php endforeach;endif;?>
      </tbody>
    </table></div></div>
  </div>
  <div class="col-lg-7">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-clock me-2" style="color:<?=$moduleColor?>"></i>Hourly Sales Volume</h6></div>
    <div class="card-body"><canvas id="hourlyChart" height="120"></canvas></div></div>
  </div>
</div>

<!-- Financial Summary -->
<div class="card mb-3">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-file-invoice-dollar me-2" style="color:<?=$moduleColor?>"></i>Financial Summary — <?=formatDate($fFrom)?> to <?=formatDate($fTo)?></h6></div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6"><table class="table table-sm table-bordered">
        <tbody>
          <tr><td class="text-muted">Gross Revenue</td><td class="text-end fw-semibold"><?=formatCurrency($periodRevenue)?></td></tr>
          <tr><td class="text-muted">Total Discounts Given</td><td class="text-end text-danger">(<?=formatCurrency($periodDiscount)?>)</td></tr>
          <tr><td class="text-muted">VAT / Tax Collected</td><td class="text-end"><?=formatCurrency($periodTax)?></td></tr>
          <tr><td class="text-muted">Returns / Refunds</td><td class="text-end text-danger">(<?=formatCurrency($periodReturns)?>)</td></tr>
          <tr class="table-light"><td class="fw-bold">Net Revenue</td><td class="text-end fw-bold"><?=formatCurrency($periodRevenue-$periodDiscount-$periodReturns)?></td></tr>
        </tbody>
      </table></div>
      <div class="col-md-6"><table class="table table-sm table-bordered">
        <tbody>
          <tr><td class="text-muted">Total Expenses</td><td class="text-end text-danger">(<?=formatCurrency($periodExpenses)?>)</td></tr>
          <tr><td class="text-muted">Average Daily Sales</td><td class="text-end"><?php $days=max(1,(strtotime($fTo)-strtotime($fFrom))/86400+1);echo formatCurrency($periodRevenue/$days);?></td></tr>
          <tr><td class="text-muted">Average Ticket Size</td><td class="text-end"><?=formatCurrency($avgTicket)?></td></tr>
          <tr><td class="text-muted">Transactions</td><td class="text-end"><?=number_format($periodTransactions)?></td></tr>
          <tr class="<?=$netProfit>=0?'table-success':'table-danger'?>"><td class="fw-bold">Estimated Net Profit</td><td class="text-end fw-bold"><?=formatCurrency($netProfit)?></td></tr>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<?php
$revLabelsJson=json_encode($trendLabels);
$revDataJson=json_encode($trendRevData);
$cntDataJson=json_encode($trendCntData);
$payLabels=json_encode(['Cash','M-Pesa','Card','Credit','Other']);
$payData=json_encode(array_values($payMethods));
$hourLabels=json_encode(array_map(fn($h)=>date('ha',mktime($h,0,0)),range(0,23)));
$hourDataJson=json_encode(array_values($hourlyCnt));

$extraJs=<<<JS
<script>
(function(){
  new Chart(document.getElementById('revChart'),{
    type:'bar',
    data:{
      labels:{$revLabelsJson},
      datasets:[
        {type:'line',label:'Revenue',data:{$revDataJson},borderColor:'<?=$moduleColor?>',backgroundColor:'transparent',tension:.4,pointRadius:4,yAxisID:'y'},
        {label:'Transactions',data:{$cntDataJson},backgroundColor:'<?=$moduleColor?>33',yAxisID:'y1',borderRadius:4}
      ]
    },
    options:{responsive:true,interaction:{mode:'index'},plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true,position:'left'},y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},ticks:{stepSize:1}}}}
  });
  new Chart(document.getElementById('payChart'),{
    type:'doughnut',
    data:{labels:{$payLabels},datasets:[{data:{$payData},backgroundColor:['#1A8A4E','#0B2D4E','#f39c12','#8e44ad','#95a5a6'],borderWidth:2}]},
    options:{responsive:true,cutout:'65%',plugins:{legend:{position:'bottom'}}}
  });
  new Chart(document.getElementById('hourlyChart'),{
    type:'bar',
    data:{labels:{$hourLabels},datasets:[{label:'Transactions',data:{$hourDataJson},backgroundColor:'<?=$moduleColor?>',borderRadius:4}]},
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
  });
})();
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
