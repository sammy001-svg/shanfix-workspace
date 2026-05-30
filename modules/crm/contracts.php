<?php
$moduleSlug  = 'crm';
$moduleName  = 'CRM — Customer Relations';
$moduleIcon  = 'fas fa-handshake';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt',       'label' => 'Dashboard'],
    ['url' => 'contacts.php',  'icon' => 'fas fa-address-book',         'label' => 'Contacts'],
    ['url' => 'companies.php', 'icon' => 'fas fa-building',             'label' => 'Companies'],
    ['url' => 'leads.php',     'icon' => 'fas fa-filter',               'label' => 'Leads'],
    ['url' => 'deals.php',     'icon' => 'fas fa-handshake',            'label' => 'Deals'],
    ['url' => 'pipeline.php',  'icon' => 'fas fa-columns',              'label' => 'Pipeline'],
    ['url' => 'quotes.php',    'icon' => 'fas fa-file-invoice',         'label' => 'Quotes'],
    ['url' => 'products.php',  'icon' => 'fas fa-box-open',             'label' => 'Products'],
    ['url' => 'activities.php','icon' => 'fas fa-tasks',                'label' => 'Activities'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-check-square',         'label' => 'Tasks'],
    ['url' => 'campaigns.php', 'icon' => 'fas fa-bullhorn',             'label' => 'Campaigns'],
    ['url' => 'contracts.php', 'icon' => 'fas fa-file-signature',       'label' => 'Contracts'],
    ['url' => 'tickets.php',   'icon' => 'fas fa-headset',              'label' => 'Support Tickets'],
    ['url' => 'email-log.php', 'icon' => 'fas fa-envelope-open-text',   'label' => 'Email Log'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',            'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $contactId  = (int)($_POST['contact_id'] ?? 0) ?: null;
        $dealId     = (int)($_POST['deal_id'] ?? 0) ?: null;
        $title      = sanitize($_POST['title'] ?? '');
        $ctype      = sanitize($_POST['contract_type'] ?? 'service');
        $value      = (float)($_POST['value'] ?? 0);
        $startDate  = $_POST['start_date'] ?? null;
        $endDate    = $_POST['end_date'] ?? null;
        $signedDate = $_POST['signed_date'] ?? null;
        $status     = in_array($_POST['status'] ?? '', ['draft','active','expired','terminated','renewed']) ? $_POST['status'] : 'draft';
        $notes      = sanitize($_POST['notes'] ?? '');

        if (empty($title)) { setFlash('danger', 'Contract title is required.'); redirect('contracts.php'); }

        if ($id > 0) {
            $pdo->prepare("UPDATE crm_contracts SET contact_id=?,deal_id=?,title=?,contract_type=?,value=?,start_date=?,end_date=?,signed_date=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$contactId,$dealId,$title,$ctype,$value,$startDate?:null,$endDate?:null,$signedDate?:null,$status,$notes,$id,$orgId]);
            setFlash('success', 'Contract updated.');
            logActivity('update', 'crm', "Updated contract: $title");
        } else {
            // Auto-ref CTR-YYYY-NNNN
            $year = date('Y');
            $seq  = 1;
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_contracts WHERE org_id=? AND ref LIKE ?");
                $stmt->execute([$orgId, "CTR-$year-%"]);
                $seq = (int)$stmt->fetchColumn() + 1;
            } catch (Exception $e) {}
            $ref = 'CTR-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO crm_contracts(org_id,ref,contact_id,deal_id,title,contract_type,value,start_date,end_date,signed_date,status,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$ref,$contactId,$dealId,$title,$ctype,$value,$startDate?:null,$endDate?:null,$signedDate?:null,$status,$notes]);
            setFlash('success', "Contract '$ref' created.");
            logActivity('create', 'crm', "Created contract: $title ($ref)");
        }
        redirect('contracts.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM crm_contracts WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Contract deleted.');
        redirect('contracts.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$contracts = [];
try {
    $stmt = $pdo->prepare("SELECT ct.*,
        CONCAT(c.first_name,' ',c.last_name) AS contact_name,
        d.title AS deal_title
        FROM crm_contracts ct
        LEFT JOIN crm_contacts c ON ct.contact_id=c.id
        LEFT JOIN crm_deals d ON ct.deal_id=d.id
        WHERE ct.org_id=? ORDER BY ct.created_at DESC");
    $stmt->execute([$orgId]);
    $contracts = $stmt->fetchAll();
} catch (Exception $e) {}

$contacts = [];
try {
    $stmt = $pdo->prepare("SELECT id,first_name,last_name,company FROM crm_contacts WHERE org_id=? ORDER BY first_name");
    $stmt->execute([$orgId]);
    $contacts = $stmt->fetchAll();
} catch (Exception $e) {}

$deals = [];
try {
    $stmt = $pdo->prepare("SELECT id,title FROM crm_deals WHERE org_id=? ORDER BY title");
    $stmt->execute([$orgId]);
    $deals = $stmt->fetchAll();
} catch (Exception $e) {}

$totalContracts = countRows('crm_contracts', 'org_id=?', [$orgId]);
$activeCount    = countRows('crm_contracts', 'org_id=? AND status=?', [$orgId, 'active']);
$activeValue    = 0;
$expiringSoon   = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(value),0) FROM crm_contracts WHERE org_id=? AND status='active'");
    $stmt->execute([$orgId]);
    $activeValue = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_contracts WHERE org_id=? AND status='active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)");
    $stmt->execute([$orgId]);
    $expiringSoon = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-signature me-2" style="color:<?= $moduleColor ?>"></i>Contracts</h4>
    <p class="text-muted mb-0">Manage customer contracts and agreements</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#ctModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>New Contract
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-file-signature"></i></div><div class="stat-body"><div class="stat-value"><?= $totalContracts ?></div><div class="stat-label">Total Contracts</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-dollar-sign"></i></div><div class="stat-body"><div class="stat-value"><?= formatCurrency($activeValue) ?></div><div class="stat-label">Active Value</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-body"><div class="stat-value"><?= $expiringSoon ?></div><div class="stat-label">Expiring in 30 Days</div></div></div></div>
</div>

<?php if ($expiringSoon > 0): ?>
<div class="alert alert-warning d-flex align-items-center mb-3 no-print">
  <i class="fas fa-exclamation-triangle me-2"></i>
  <strong><?= $expiringSoon ?> contract(s)</strong>&nbsp;expiring within the next 30 days. Review and renew as needed.
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-file-signature me-2" style="color:<?= $moduleColor ?>"></i>Contract Register</h6>
    <span class="badge bg-secondary"><?= count($contracts) ?> contracts</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="ctTable">
        <thead class="table-light">
          <tr><th>Ref</th><th>Title</th><th>Contact</th><th>Type</th><th>Value</th><th>Start</th><th>End</th><th>Status</th><th class="text-center no-print">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($contracts)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No contracts found.</td></tr>
          <?php else: foreach ($contracts as $ct): ?>
          <tr>
            <td><code class="bg-light px-2 py-1 rounded text-dark"><?= e($ct['ref'] ?? '—') ?></code></td>
            <td class="fw-semibold"><?= e($ct['title']) ?></td>
            <td><?= e($ct['contact_name'] ?? '—') ?></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst(str_replace('_',' ',$ct['contract_type'])) ?></span></td>
            <td class="fw-semibold text-success"><?= formatCurrency((float)$ct['value']) ?></td>
            <td><?= formatDate($ct['start_date'] ?? '') ?></td>
            <td><?= formatDate($ct['end_date'] ?? '') ?></td>
            <td><?= statusBadge($ct['status'] ?? 'draft') ?></td>
            <td class="text-center no-print" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='fillForm(<?= htmlspecialchars(json_encode($ct), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delContract(<?= $ct['id'] ?>,'<?= e($ct['ref'] ?? '') ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="ctModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="ctId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="ctModalTitle"><i class="fas fa-file-signature me-2"></i>New Contract</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12"><label class="form-label fw-semibold">Contract Title <span class="text-danger">*</span></label><input type="text" name="title" id="ctTitle" class="form-control" required maxlength="255"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Contact</label><select name="contact_id" id="ctContact" class="form-select"><option value="">-- None --</option><?php foreach ($contacts as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name'] . ($c['company'] ? ' (' . $c['company'] . ')' : '')) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Linked Deal</label><select name="deal_id" id="ctDeal" class="form-select"><option value="">-- None --</option><?php foreach ($deals as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['title']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Contract Type</label><input type="text" name="contract_type" id="ctType" class="form-control" list="ctypeList" placeholder="service"><datalist id="ctypeList"><option value="service"><option value="supply"><option value="nda"><option value="employment"><option value="partnership"><option value="other"></datalist></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Value (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="value" id="ctValue" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Start Date</label><input type="date" name="start_date" id="ctStart" class="form-control"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">End Date</label><input type="date" name="end_date" id="ctEnd" class="form-control"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Signed Date</label><input type="date" name="signed_date" id="ctSigned" class="form-control"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="ctStatus" class="form-select"><option value="draft">Draft</option><option value="active">Active</option><option value="expired">Expired</option><option value="terminated">Terminated</option><option value="renewed">Renewed</option></select></div>
            <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="ctNotes" class="form-control" rows="3"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Contract</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delCtForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delCtId"></form>
<?php
$extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('ctModalTitle').innerHTML='<i class="fas fa-file-signature me-2"></i>New Contract';
  ['ctId','ctTitle','ctNotes'].forEach(i=>document.getElementById(i).value=i==='ctId'?'0':'');
  ['ctStart','ctEnd','ctSigned'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('ctContact').value='';
  document.getElementById('ctDeal').value='';
  document.getElementById('ctType').value='service';
  document.getElementById('ctValue').value=0;
  document.getElementById('ctStatus').value='draft';
}
function fillForm(r){
  document.getElementById('ctModalTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Contract';
  document.getElementById('ctId').value=r.id;
  document.getElementById('ctTitle').value=r.title||'';
  document.getElementById('ctContact').value=r.contact_id||'';
  document.getElementById('ctDeal').value=r.deal_id||'';
  document.getElementById('ctType').value=r.contract_type||'service';
  document.getElementById('ctValue').value=r.value||0;
  document.getElementById('ctStart').value=r.start_date||'';
  document.getElementById('ctEnd').value=r.end_date||'';
  document.getElementById('ctSigned').value=r.signed_date||'';
  document.getElementById('ctStatus').value=r.status||'draft';
  document.getElementById('ctNotes').value=r.notes||'';
  new bootstrap.Modal(document.getElementById('ctModal')).show();
}
function delContract(id,ref){
  Swal.fire({title:'Delete Contract?',text:ref+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delCtId').value=id;document.getElementById('delCtForm').submit();}});
}
$(document).ready(function(){$('#ctTable').DataTable({pageLength:15,order:[[0,'desc']],language:{emptyTable:'No contracts found.'}});});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
