<?php
$moduleSlug='crm';$moduleName='CRM — Customer Relations';$moduleIcon='fas fa-handshake';$moduleColor='#0B2D4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'contacts.php','icon'=>'fas fa-address-book','label'=>'Contacts'],['url'=>'companies.php','icon'=>'fas fa-building','label'=>'Companies'],['url'=>'leads.php','icon'=>'fas fa-filter','label'=>'Leads'],['url'=>'deals.php','icon'=>'fas fa-handshake','label'=>'Deals'],['url'=>'pipeline.php','icon'=>'fas fa-columns','label'=>'Pipeline'],['url'=>'quotes.php','icon'=>'fas fa-file-invoice','label'=>'Quotes'],['url'=>'products.php','icon'=>'fas fa-box-open','label'=>'Products'],['url'=>'activities.php','icon'=>'fas fa-tasks','label'=>'Activities'],['url'=>'tasks.php','icon'=>'fas fa-check-square','label'=>'Tasks'],['url'=>'campaigns.php','icon'=>'fas fa-bullhorn','label'=>'Campaigns'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];

// --- Data ---
// Contact types breakdown
$contactTypes=['lead','contact','customer','partner'];$contactCounts=[];
foreach($contactTypes as $t){try{$s=$pdo->prepare("SELECT COUNT(*) FROM crm_contacts WHERE org_id=? AND type=?");$s->execute([$orgId,$t]);$contactCounts[]=(int)$s->fetchColumn();}catch(Exception $e){$contactCounts[]=0;}}

// Deal stages
$stages=['prospect','qualified','proposal','negotiation','won','lost'];$stageCounts=[];$stageValues=[];
foreach($stages as $s){
    try{$st=$pdo->prepare("SELECT COUNT(*),COALESCE(SUM(value),0) FROM crm_deals WHERE org_id=? AND stage=?");$st->execute([$orgId,$s]);$row=$st->fetch(PDO::FETCH_NUM);$stageCounts[]=(int)$row[0];$stageValues[]=(float)$row[1];}
    catch(Exception $e){$stageCounts[]=0;$stageValues[]=0;}
}

// Lead status
$leadStatuses=['new','contacted','qualified','converted','lost'];$leadCounts=[];
foreach($leadStatuses as $s){try{$st=$pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE org_id=? AND status=?");$st->execute([$orgId,$s]);$leadCounts[]=(int)$st->fetchColumn();}catch(Exception $e){$leadCounts[]=0;}}

// Activities by type
$actTypes=['call','email','meeting','note','task'];$actCounts=[];
foreach($actTypes as $t){try{$st=$pdo->prepare("SELECT COUNT(*) FROM crm_activities WHERE org_id=? AND type=?");$st->execute([$orgId,$t]);$actCounts[]=(int)$st->fetchColumn();}catch(Exception $e){$actCounts[]=0;}}

// Monthly contacts growth (last 6 months)
$months=[];$monthCounts=[];
for($i=5;$i>=0;$i--){$m=date('Y-m',strtotime("-$i months"));$months[]=date('M Y',strtotime("-$i months"));
    try{$st=$pdo->prepare("SELECT COUNT(*) FROM crm_contacts WHERE org_id=? AND DATE_FORMAT(created_at,'%Y-%m')=?");$st->execute([$orgId,$m]);$monthCounts[]=(int)$st->fetchColumn();}catch(Exception $e){$monthCounts[]=0;}}

// Summary KPIs
$totalContacts=countRows('crm_contacts','org_id=?',[$orgId]);
$totalLeads=countRows('crm_leads','org_id=?',[$orgId]);
$totalDeals=countRows('crm_deals','org_id=?',[$orgId]);
$wonDeals=countRows('crm_deals','org_id=? AND status=?',[$orgId,'won']);
$pipeline=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(value),0) FROM crm_deals WHERE org_id=? AND status='open'");$s->execute([$orgId]);$pipeline=(float)$s->fetchColumn();}catch(Exception $e){}
$wonValue=0;try{$s=$pdo->prepare("SELECT COALESCE(SUM(value),0) FROM crm_deals WHERE org_id=? AND status='won'");$s->execute([$orgId]);$wonValue=(float)$s->fetchColumn();}catch(Exception $e){}
$convRate=$totalLeads>0?round(($leadCounts[3]/$totalLeads)*100,1):0;
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?=$moduleColor?>"></i>CRM Reports</h4><p class="text-muted mb-0">Analytics overview — contacts, leads, deals and activities</p></div>
  <span class="text-muted small">As of <?=date('d M Y')?></span>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <?php foreach([
    ['navy-bg','fas fa-users',$totalContacts,'Total Contacts'],
    ['warning-bg','fas fa-filter',$totalLeads,'Total Leads'],
    ['info-bg','fas fa-handshake',$totalDeals,'Total Deals'],
    ['green-bg','fas fa-trophy',$wonDeals,'Deals Won'],
    ['navy-bg','fas fa-dollar-sign',formatCurrency($pipeline),'Pipeline Value'],
    ['green-bg','fas fa-check-double',formatCurrency($wonValue),'Won Value'],
    ['warning-bg','fas fa-percentage',$convRate.'%','Lead Conv. Rate'],
    ['danger-bg','fas fa-tasks',countRows('crm_activities','org_id=? AND done=?',[$orgId,0]),'Pending Tasks'],
  ] as [$cls,$ic,$val,$lbl]):?>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon <?=$cls?>"><i class="<?=$ic?>"></i></div><div class="stat-body"><div class="stat-value" style="font-size:1.2rem"><?=$val?></div><div class="stat-label"><?=$lbl?></div></div></div></div>
  <?php endforeach;?>
</div>

<div class="row g-4 mb-4">
  <!-- Deal Stage Pipeline Chart -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-funnel-dollar me-2" style="color:<?=$moduleColor?>"></i>Deal Stage Pipeline (Count &amp; Value)</h6></div>
      <div class="card-body"><canvas id="stageChart" height="130"></canvas></div>
    </div>
  </div>
  <!-- Contact Type Donut -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?=$moduleColor?>"></i>Contacts by Type</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="contactChart" height="220"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Lead Status Chart -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-filter me-2" style="color:<?=$moduleColor?>"></i>Lead Status Breakdown</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="leadChart" height="250"></canvas></div>
    </div>
  </div>
  <!-- Activity Types -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-tasks me-2" style="color:<?=$moduleColor?>"></i>Activities by Type</h6></div>
      <div class="card-body"><canvas id="actChart" height="180"></canvas></div>
    </div>
  </div>
</div>

<!-- Monthly Contacts Growth -->
<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-line me-2" style="color:<?=$moduleColor?>"></i>Contact Growth — Last 6 Months</h6></div>
  <div class="card-body"><canvas id="growthChart" height="100"></canvas></div>
</div>

<?php
$stagesJ=json_encode(array_map('ucfirst',$stages));
$stageCountsJ=json_encode($stageCounts);
$stageValuesJ=json_encode($stageValues);
$contactTypesJ=json_encode(array_map('ucfirst',$contactTypes));
$contactCountsJ=json_encode($contactCounts);
$leadStatusesJ=json_encode(array_map('ucfirst',$leadStatuses));
$leadCountsJ=json_encode($leadCounts);
$actTypesJ=json_encode(array_map('ucfirst',$actTypes));
$actCountsJ=json_encode($actCounts);
$monthsJ=json_encode($months);
$monthCountsJ=json_encode($monthCounts);
$extraJs=<<<JS
<script>
(function(){
  const c='<?=$moduleColor?>';
  // Stage bar chart
  new Chart(document.getElementById('stageChart'),{type:'bar',data:{labels:$stagesJ,datasets:[{label:'# Deals',data:$stageCountsJ,backgroundColor:c+'cc',borderRadius:6,yAxisID:'y'},{label:'Value (KES)',data:$stageValuesJ,backgroundColor:'#1a8a4e88',borderRadius:6,yAxisID:'y1'}]},options:{responsive:true,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true,title:{display:true,text:'Count'}},y1:{beginAtZero:true,position:'right',title:{display:true,text:'Value (KES)'},grid:{drawOnChartArea:false}}}}});
  // Contact donut
  new Chart(document.getElementById('contactChart'),{type:'doughnut',data:{labels:$contactTypesJ,datasets:[{data:$contactCountsJ,backgroundColor:['#0B2D4E','#1a8a4e','#f39c12','#e74c3c']}]},options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
  // Lead pie
  new Chart(document.getElementById('leadChart'),{type:'pie',data:{labels:$leadStatusesJ,datasets:[{data:$leadCountsJ,backgroundColor:['#ffc107','#17a2b8','#0B2D4E','#1a8a4e','#e74c3c']}]},options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
  // Activity bar
  new Chart(document.getElementById('actChart'),{type:'bar',data:{labels:$actTypesJ,datasets:[{label:'Activities',data:$actCountsJ,backgroundColor:['#0B2D4E','#17a2b8','#f39c12','#6c757d','#1a8a4e'],borderRadius:6}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
  // Growth line
  new Chart(document.getElementById('growthChart'),{type:'line',data:{labels:$monthsJ,datasets:[{label:'New Contacts',data:$monthCountsJ,borderColor:c,backgroundColor:c+'22',fill:true,tension:0.4,pointBackgroundColor:c}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
})();
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';
?>
