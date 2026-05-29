<?php
$moduleSlug  = 'crm';
$moduleName  = 'CRM — Customer Relations';
$moduleIcon  = 'fas fa-handshake';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'contacts.php',  'icon' => 'fas fa-address-book',   'label' => 'Contacts'],
    ['url' => 'companies.php', 'icon' => 'fas fa-building',       'label' => 'Companies'],
    ['url' => 'leads.php',     'icon' => 'fas fa-filter',         'label' => 'Leads'],
    ['url' => 'deals.php',     'icon' => 'fas fa-handshake',      'label' => 'Deals'],
    ['url' => 'pipeline.php',  'icon' => 'fas fa-columns',        'label' => 'Pipeline'],
    ['url' => 'quotes.php',    'icon' => 'fas fa-file-invoice',   'label' => 'Quotes'],
    ['url' => 'products.php',  'icon' => 'fas fa-box-open',       'label' => 'Products'],
    ['url' => 'activities.php','icon' => 'fas fa-tasks',          'label' => 'Activities'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-check-square',   'label' => 'Tasks'],
    ['url' => 'campaigns.php', 'icon' => 'fas fa-bullhorn',       'label' => 'Campaigns'],
    ['url' => 'contracts.php', 'icon' => 'fas fa-file-signature',  'label' => 'Contracts'],
    ['url' => 'tickets.php',   'icon' => 'fas fa-headset',          'label' => 'Support Tickets'],
    ['url' => 'email-log.php', 'icon' => 'fas fa-envelope-open-text','label' => 'Email Log'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',        'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';

$orgId = (int)$user['org_id'];

$totalContacts  = countRows('crm_contacts', 'org_id = ?', [$orgId]);
$activeLeads    = countRows('crm_leads', 'org_id = ? AND status = ?', [$orgId, 'active']);
$dealsWon       = countRows('crm_deals', 'org_id = ? AND stage = ?', [$orgId, 'won']);
$pipelineValue  = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(value),0) FROM crm_deals WHERE org_id=? AND stage NOT IN ('won','lost')");
    $stmt->execute([$orgId]);
    $pipelineValue = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// Recent contacts
$contacts = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM crm_contacts WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$orgId]);
    $contacts = $stmt->fetchAll();
} catch (Exception $e) {}

// Deal stages for bar chart
$stages      = ['Prospect','Qualified','Proposal','Negotiation','Won','Lost'];
$stageCounts = [];
foreach ($stages as $s) {
    try {
        $stageCounts[] = countRows('crm_deals', 'org_id = ? AND stage = ?', [$orgId, strtolower($s)]);
    } catch (Exception $e) { $stageCounts[] = 0; }
}

// Contacts by type
$contactTypes  = ['customer','prospect','partner','vendor'];
$contactCounts = [];
foreach ($contactTypes as $ct) {
    try {
        $contactCounts[] = countRows('crm_contacts', 'org_id = ? AND type = ?', [$orgId, $ct]);
    } catch (Exception $e) { $contactCounts[] = 0; }
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Manage relationships, leads, and deals</p>
  </div>
  <a href="contacts.php?action=add" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-plus me-2"></i>Add Contact</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalContacts ?></div><div class="stat-label">Total Contacts</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-filter"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeLeads ?></div><div class="stat-label">Active Leads</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-trophy"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $dealsWon ?></div><div class="stat-label">Deals Won</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($pipelineValue) ?></div><div class="stat-label">Pipeline Value</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Deal Stages Pipeline</h6></div>
      <div class="card-body"><canvas id="dealChart" height="110"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?= $moduleColor ?>"></i>Contacts by Type</h6></div>
      <div class="card-body d-flex align-items-center justify-content-center"><canvas id="contactChart" height="220"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-address-book me-2" style="color:<?= $moduleColor ?>"></i>Recent Contacts</h6>
    <a href="contacts.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="crmTable">
        <thead class="table-light">
          <tr><th>Name</th><th>Email</th><th>Phone</th><th>Type</th><th>Status</th><th>Date Added</th></tr>
        </thead>
        <tbody>
          <?php if (empty($contacts)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No contacts found</td></tr>
          <?php else: foreach ($contacts as $c): ?>
          <tr>
            <td class="fw-semibold"><?= e($c['name'] ?? '—') ?></td>
            <td><?= e($c['email'] ?? '—') ?></td>
            <td><?= e($c['phone'] ?? '—') ?></td>
            <td><span class="badge bg-secondary"><?= ucfirst($c['type'] ?? 'contact') ?></span></td>
            <td><?= statusBadge($c['status'] ?? 'active') ?></td>
            <td><?= formatDate($c['created_at'] ?? '') ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
(function(){
  new Chart(document.getElementById("dealChart"),{
    type:"bar",
    data:{labels:' . json_encode($stages) . ',datasets:[{label:"Deals",data:' . json_encode($stageCounts) . ',backgroundColor:"#0B2D4E",borderRadius:6}]},
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
  });
  new Chart(document.getElementById("contactChart"),{
    type:"doughnut",
    data:{labels:["Customer","Prospect","Partner","Vendor"],datasets:[{data:' . json_encode($contactCounts) . ',backgroundColor:["#0B2D4E","#1A8A4E","#f39c12","#e74c3c"]}]},
    options:{responsive:true,plugins:{legend:{position:"bottom"}}}
  });
  $("#crmTable").DataTable({pageLength:10,order:[[5,"desc"]]});
})();
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
