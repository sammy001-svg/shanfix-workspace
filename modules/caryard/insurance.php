<?php
$moduleSlug  = 'caryard';
$moduleName  = 'Car Yard Management';
$moduleIcon  = 'fas fa-car';
$moduleColor = '#e67e22';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'vehicles.php',       'icon' => 'fas fa-car',            'label' => 'Vehicles'],
    ['url' => 'sales.php',          'icon' => 'fas fa-handshake',      'label' => 'Sales'],
    ['url' => 'customers.php',      'icon' => 'fas fa-users',          'label' => 'Customers'],
    ['url' => 'finance.php',        'icon' => 'fas fa-university',     'label' => 'Finance'],
    ['url' => 'testdrives.php',     'icon' => 'fas fa-road',           'label' => 'Test Drives'],
    ['url' => 'services.php',       'icon' => 'fas fa-tools',          'label' => 'Services'],
    ['url' => 'reconditioning.php', 'icon' => 'fas fa-wrench',         'label' => 'Reconditioning'],
    ['url' => 'valuations.php',     'icon' => 'fas fa-search-dollar',  'label' => 'Valuations'],
    ['url' => 'inquiries.php',      'icon' => 'fas fa-comments',       'label' => 'Inquiries'],
    ['url' => 'insurance.php',      'icon' => 'fas fa-shield-alt',     'label' => 'Insurance'],
    ['url' => 'parts.php',          'icon' => 'fas fa-cogs',           'label' => 'Parts & Spares'],
    ['url' => 'delivery.php',       'icon' => 'fas fa-truck-loading',  'label' => 'Deliveries'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
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
        $id             = (int)($_POST['id'] ?? 0);
        $vehicleId      = (int)($_POST['vehicle_id'] ?? 0);
        $insurerName    = sanitize($_POST['insurer_name'] ?? '');
        $policyNumber   = sanitize($_POST['policy_number'] ?? '');
        $insuranceType  = in_array($_POST['insurance_type'] ?? '', ['comprehensive','third_party','fire_theft']) ? $_POST['insurance_type'] : 'comprehensive';
        $premiumAmount  = (float)($_POST['premium_amount'] ?? 0);
        $startDate      = $_POST['start_date'] ?? date('Y-m-d');
        $endDate        = $_POST['end_date'] ?? null;
        $status         = in_array($_POST['status'] ?? '', ['active','expired','cancelled']) ? $_POST['status'] : 'active';

        if ($vehicleId <= 0 || empty($insurerName) || empty($policyNumber)) {
            setFlash('danger', 'Vehicle, insurer and policy number are required.');
            redirect('insurance.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE caryard_insurance SET vehicle_id=?,insurer_name=?,policy_number=?,insurance_type=?,premium_amount=?,start_date=?,end_date=?,status=? WHERE id=? AND org_id=?")
                ->execute([$vehicleId,$insurerName,$policyNumber,$insuranceType,$premiumAmount,$startDate,$endDate?:null,$status,$id,$orgId]);
            setFlash('success', 'Insurance policy updated.');
            logActivity('update', 'caryard', "Updated insurance: $policyNumber");
        } else {
            $pdo->prepare("INSERT INTO caryard_insurance(org_id,vehicle_id,insurer_name,policy_number,insurance_type,premium_amount,start_date,end_date,status) VALUES(?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$vehicleId,$insurerName,$policyNumber,$insuranceType,$premiumAmount,$startDate,$endDate?:null,$status]);
            setFlash('success', "Policy '$policyNumber' added.");
            logActivity('create', 'caryard', "Added insurance: $policyNumber");
        }
        redirect('insurance.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM caryard_insurance WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Insurance policy deleted.');
        redirect('insurance.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$policies = [];
try {
    $stmt = $pdo->prepare("SELECT ins.*,
        CONCAT(v.reg_number,' ',v.make,' ',v.model) AS vehicle_label
        FROM caryard_insurance ins
        LEFT JOIN caryard_vehicles v ON ins.vehicle_id=v.id
        WHERE ins.org_id=? ORDER BY ins.created_at DESC");
    $stmt->execute([$orgId]);
    $policies = $stmt->fetchAll();
} catch (Exception $e) {}

$vehicles = [];
try {
    $stmt = $pdo->prepare("SELECT id,reg_number,make,model FROM caryard_vehicles WHERE org_id=? ORDER BY reg_number");
    $stmt->execute([$orgId]);
    $vehicles = $stmt->fetchAll();
} catch (Exception $e) {}

$activeCount  = countRows('caryard_insurance', 'org_id=? AND status=?', [$orgId,'active']);
$totalPremium = 0;
$expiringSoon = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(premium_amount),0) FROM caryard_insurance WHERE org_id=? AND status='active'");
    $stmt->execute([$orgId]); $totalPremium = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM caryard_insurance WHERE org_id=? AND status='active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)");
    $stmt->execute([$orgId]); $expiringSoon = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-shield-alt me-2" style="color:<?= $moduleColor ?>"></i>Vehicle Insurance</h4>
    <p class="text-muted mb-0">Manage vehicle insurance policies and renewal dates</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#insModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Policy
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-shield-alt"></i></div><div class="stat-body"><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Active Policies</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-body"><div class="stat-value"><?= $expiringSoon ?></div><div class="stat-label">Expiring in 30 Days</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-dollar-sign"></i></div><div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPremium) ?></div><div class="stat-label">Total Premium (Active)</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-car"></i></div><div class="stat-body"><div class="stat-value"><?= count($policies) ?></div><div class="stat-label">All Policies</div></div></div></div>
</div>

<?php if ($expiringSoon > 0): ?>
<div class="alert alert-warning d-flex align-items-center mb-3 no-print">
  <i class="fas fa-exclamation-triangle me-2"></i>
  <strong><?= $expiringSoon ?> policy/policies</strong>&nbsp;expiring within 30 days — please renew promptly.
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-shield-alt me-2" style="color:<?= $moduleColor ?>"></i>Insurance Policies</h6>
    <span class="badge bg-secondary"><?= count($policies) ?> policies</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="insTable">
        <thead class="table-light">
          <tr><th>Vehicle</th><th>Insurer</th><th>Policy No.</th><th>Type</th><th class="text-end">Premium</th><th>Start</th><th>End</th><th>Status</th><th class="text-center no-print">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($policies)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No insurance policies found.</td></tr>
          <?php else: foreach ($policies as $ins):
            $daysLeft = null;
            if (!empty($ins['end_date'])) {
                $daysLeft = (int)ceil((strtotime($ins['end_date']) - time()) / 86400);
            }
          ?>
          <tr class="<?= ($ins['status']==='active' && $daysLeft !== null && $daysLeft <= 30) ? 'table-warning' : '' ?>">
            <td class="fw-semibold"><?= e($ins['vehicle_label'] ?? '—') ?></td>
            <td><?= e($ins['insurer_name']) ?></td>
            <td><code class="bg-light px-2 py-1 rounded text-dark"><?= e($ins['policy_number']) ?></code></td>
            <td><span class="badge bg-light text-dark border"><?= ucwords(str_replace('_',' ',$ins['insurance_type'])) ?></span></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$ins['premium_amount']) ?></td>
            <td><?= formatDate($ins['start_date'] ?? '') ?></td>
            <td>
              <?= formatDate($ins['end_date'] ?? '') ?>
              <?php if ($ins['status']==='active' && $daysLeft !== null && $daysLeft <= 30 && $daysLeft >= 0): ?>
              <span class="badge bg-warning text-dark ms-1"><?= $daysLeft ?>d left</span>
              <?php endif; ?>
            </td>
            <td><?= statusBadge($ins['status'] ?? 'active') ?></td>
            <td class="text-center no-print" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='fillForm(<?= htmlspecialchars(json_encode($ins), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delIns(<?= $ins['id'] ?>,'<?= e($ins['policy_number']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="insModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="insId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="insModalTitle"><i class="fas fa-shield-alt me-2"></i>Add Policy</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label><select name="vehicle_id" id="insVehicle" class="form-select" required><option value="">-- Select Vehicle --</option><?php foreach ($vehicles as $v): ?><option value="<?= $v['id'] ?>"><?= e(($v['reg_number'] ?? '') . ' — ' . ($v['make'] ?? '') . ' ' . ($v['model'] ?? '')) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Insurer Name <span class="text-danger">*</span></label><input type="text" name="insurer_name" id="insInsurer" class="form-control" required maxlength="150"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Policy Number <span class="text-danger">*</span></label><input type="text" name="policy_number" id="insPolicyNo" class="form-control" required maxlength="100"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Insurance Type</label><select name="insurance_type" id="insType" class="form-select"><option value="comprehensive">Comprehensive</option><option value="third_party">Third Party</option><option value="fire_theft">Fire & Theft</option></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Premium Amount (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="premium_amount" id="insPremium" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Start Date</label><input type="date" name="start_date" id="insStart" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">End Date</label><input type="date" name="end_date" id="insEnd" class="form-control"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="insStatus" class="form-select"><option value="active">Active</option><option value="expired">Expired</option><option value="cancelled">Cancelled</option></select></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Policy</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delInsForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delInsId"></form>
<?php
$extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('insModalTitle').innerHTML='<i class="fas fa-shield-alt me-2"></i>Add Policy';
  document.getElementById('insId').value='0';
  document.getElementById('insVehicle').value='';
  document.getElementById('insInsurer').value='';
  document.getElementById('insPolicyNo').value='';
  document.getElementById('insType').value='comprehensive';
  document.getElementById('insPremium').value=0;
  document.getElementById('insStart').value=new Date().toISOString().split('T')[0];
  document.getElementById('insEnd').value='';
  document.getElementById('insStatus').value='active';
}
function fillForm(ins){
  document.getElementById('insModalTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Policy';
  document.getElementById('insId').value=ins.id;
  document.getElementById('insVehicle').value=ins.vehicle_id||'';
  document.getElementById('insInsurer').value=ins.insurer_name||'';
  document.getElementById('insPolicyNo').value=ins.policy_number||'';
  document.getElementById('insType').value=ins.insurance_type||'comprehensive';
  document.getElementById('insPremium').value=ins.premium_amount||0;
  document.getElementById('insStart').value=ins.start_date||'';
  document.getElementById('insEnd').value=ins.end_date||'';
  document.getElementById('insStatus').value=ins.status||'active';
  new bootstrap.Modal(document.getElementById('insModal')).show();
}
function delIns(id,policyNo){
  Swal.fire({title:'Delete Policy?',text:'Policy '+policyNo+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delInsId').value=id;document.getElementById('delInsForm').submit();}});
}
$(document).ready(function(){$('#insTable').DataTable({pageLength:15,order:[[6,'asc']],language:{emptyTable:'No insurance policies found.'}});});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
