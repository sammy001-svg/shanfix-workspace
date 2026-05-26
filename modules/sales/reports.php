<?php
$moduleSlug='sales';$moduleName='Sales Management';$moduleIcon='fas fa-chart-line';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];

// KPIs
$tOrders=countRows('sales_orders','org_id=?',[$orgId]);$tCustomers=countRows('sales_customers','org_id=?',[$orgId]);$tProducts=countRows('sales_products','org_id=?',[$orgId]);$tQuotes=countRows('sales_quotes','org_id=?',[$orgId]);
$revenue=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(total),0) FROM sales_orders WHERE org_id=? AND status='delivered'");$s->execute([$orgId]);$revenue=(float)$s->fetchColumn();}catch(Exception $e){}
$pending=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(total),0) FROM sales_orders WHERE org_id=? AND status='pending'");$s->execute([$orgId]);$pending=(float)$s->fetchColumn();}catch(Exception $e){}
$avgOrder=$tOrders>0?$revenue/$tOrders:0;
$lowStock=0;try{$s=$pdo->prepare("SELECT COUNT(*) FROM sales_products WHERE org_id=? AND stock<10 AND status='active'");$s->execute([$orgId]);$lowStock=(int)$s->fetchColumn();}catch(Exception $e){}

// Monthly sales (6 months)
$months=[];$monthlySales=[];$monthlyOrders=[];
for($i=5;$i>=0;$i--){$m=date('Y-m',strtotime("-$i months"));$months[]=date('M Y',strtotime("-$i months"));
    try{$s=$pdo->prepare("SELECT COALESCE(SUM(total),0),COUNT(*) FROM sales_orders WHERE org_id=? AND DATE_FORMAT(created_at,'%Y-%m')=?");$s->execute([$orgId,$m]);$row=$s->fetch(PDO::FETCH_NUM);$monthlySales[]=(float)$row[0];$monthlyOrders[]=(int)$row[1];}catch(Exception $e){$monthlySales[]=0;$monthlyOrders[]=0;}}

// Order status breakdown
$statuses=['pending','processing','shipped','delivered','cancelled'];$statusCounts=[];
foreach($statuses as $s){try{$st=$pdo->prepare("SELECT COUNT(*) FROM sales_orders WHERE org_id=? AND status=?");$st->execute([$orgId,$s]);$statusCounts[]=(int)$st->fetchColumn();}catch(Exception $e){$statusCounts[]=0;}}

// Top 5 products by revenue
$topProducts=[];try{$s=$pdo->prepare("SELECT sp.name,COALESCE(SUM(si.total),0) as rev FROM sales_order_items si JOIN sales_products sp ON si.product_id=sp.id JOIN sales_orders o ON si.order_id=o.id WHERE o.org_id=? GROUP BY si.product_id ORDER BY rev DESC LIMIT 5");$s->execute([$orgId]);$topProducts=$s->fetchAll();}catch(Exception $e){}

// Top 5 customers
$topCustomers=[];try{$s=$pdo->prepare("SELECT c.name,COALESCE(SUM(o.total),0) as rev,COUNT(o.id) as cnt FROM sales_orders o JOIN sales_customers c ON o.customer_id=c.id WHERE o.org_id=? GROUP BY o.customer_id ORDER BY rev DESC LIMIT 5");$s->execute([$orgId]);$topCustomers=$s->fetchAll();}catch(Exception $e){}
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?=$moduleColor?>"></i>Sales Reports</h4><p class="text-muted mb-0">Revenue analytics and performance overview</p></div>
  <span class="text-muted small">As of <?=date('d M Y')?></span>
</div>
<!-- KPIs -->
<div class="row g-3 mb-4">
  <?php foreach([['green-bg','fas fa-dollar-sign',formatCurrency($revenue),'Total Revenue'],['warning-bg','fas fa-shopping-cart',$tOrders,'Total Orders'],['navy-bg','fas fa-users',$tCustomers,'Customers'],['info-bg','fas fa-box',$tProducts,'Products'],['warning-bg','fas fa-clock',formatCurrency($pending),'Pending Value'],['green-bg','fas fa-chart-line',formatCurrency($avgOrder),'Avg Order Value'],['danger-bg','fas fa-exclamation',$lowStock,'Low Stock Items'],['info-bg','fas fa-file-alt',$tQuotes,'Quotes Sent']] as [$cl,$ic,$v,$lb]):?>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon <?=$cl?>"><i class="<?=$ic?>"></i></div><div class="stat-body"><div class="stat-value" style="font-size:1.1rem"><?=$v?></div><div class="stat-label"><?=$lb?></div></div></div></div>
  <?php endforeach;?>
</div>
<!-- Monthly Revenue Chart -->
<div class="card mb-4">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-area me-2" style="color:<?=$moduleColor?>"></i>Monthly Revenue — Last 6 Months</h6></div>
  <div class="card-body"><canvas id="revenueChart" height="90"></canvas></div>
</div>
<div class="row g-4 mb-4">
  <!-- Order Status Donut -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?=$moduleColor?>"></i>Orders by Status</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="statusChart" height="230"></canvas></div>
    </div>
  </div>
  <!-- Monthly Orders Volume -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?=$moduleColor?>"></i>Monthly Order Volume</h6></div>
      <div class="card-body"><canvas id="volChart" height="160"></canvas></div>
    </div>
  </div>
</div>
<div class="row g-4">
  <!-- Top Products -->
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-box me-2" style="color:<?=$moduleColor?>"></i>Top 5 Products by Revenue</h6></div>
    <div class="card-body p-0"><table class="table table-sm table-hover mb-0">
      <thead class="table-light"><tr><th>#</th><th>Product</th><th class="text-end">Revenue</th></tr></thead>
      <tbody><?php if(empty($topProducts)):?><tr><td colspan="3" class="text-center text-muted py-4">No data yet</td></tr><?php else:foreach($topProducts as $i=>$p):?><tr><td><?=$i+1?></td><td><?=e($p['name'])?></td><td class="text-end fw-semibold"><?=formatCurrency((float)$p['rev'])?></td></tr><?php endforeach;endif;?></tbody>
    </table></div></div>
  </div>
  <!-- Top Customers -->
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-users me-2" style="color:<?=$moduleColor?>"></i>Top 5 Customers</h6></div>
    <div class="card-body p-0"><table class="table table-sm table-hover mb-0">
      <thead class="table-light"><tr><th>#</th><th>Customer</th><th class="text-end">Orders</th><th class="text-end">Total Spend</th></tr></thead>
      <tbody><?php if(empty($topCustomers)):?><tr><td colspan="4" class="text-center text-muted py-4">No data yet</td></tr><?php else:foreach($topCustomers as $i=>$c):?><tr><td><?=$i+1?></td><td><?=e($c['name'])?></td><td class="text-end"><?=$c['cnt']?></td><td class="text-end fw-semibold"><?=formatCurrency((float)$c['rev'])?></td></tr><?php endforeach;endif;?></tbody>
    </table></div></div>
  </div>
</div>
<?php
$mJ=json_encode($months);$msJ=json_encode($monthlySales);$moJ=json_encode($monthlyOrders);
$stJ=json_encode(array_map('ucfirst',$statuses));$scJ=json_encode($statusCounts);
$extraJs=<<<JS
<script>
(function(){
  const c='<?=$moduleColor?>';
  new Chart(document.getElementById('revenueChart'),{type:'line',data:{labels:$mJ,datasets:[{label:'Revenue (KES)',data:$msJ,borderColor:c,backgroundColor:c+'22',fill:true,tension:0.4,pointBackgroundColor:c,pointRadius:5}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});
  new Chart(document.getElementById('statusChart'),{type:'doughnut',data:{labels:$stJ,datasets:[{data:$scJ,backgroundColor:['#ffc107','#17a2b8','#6c757d','#1a8a4e','#e74c3c']}]},options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
  new Chart(document.getElementById('volChart'),{type:'bar',data:{labels:$mJ,datasets:[{label:'# Orders',data:$moJ,backgroundColor:c+'cc',borderRadius:6}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
})();
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
