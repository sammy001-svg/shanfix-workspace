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
        $id              = (int)($_POST['id'] ?? 0);
        $vehicleId       = (int)($_POST['vehicle_id'] ?? 0);
        $customerId      = (int)($_POST['customer_id'] ?? 0) ?: null;
        $saleId          = (int)($_POST['sale_id'] ?? 0) ?: null;
        $deliveryDate    = $_POST['delivery_date'] ?? date('Y-m-d');
        $deliveryAddress = sanitize($_POST['delivery_address'] ?? '');
        $deliveryOfficer = sanitize($_POST['delivery_officer'] ?? '');
        $status          = in_array($_POST['status'] ?? '', ['scheduled','out_for_delivery','delivered','failed','rescheduled']) ? $_POST['status'] : 'scheduled';
        $notes           = sanitize($_POST['notes'] ?? '');

        if ($vehicleId <= 0 || empty($deliveryDate)) { setFlash('danger', 'Vehicle and delivery date are required.'); redirect('delivery.php'); }

        if ($id > 0) {
            $pdo->prepare("UPDATE caryard_delivery SET vehicle_id=?,customer_id=?,sale_id=?,delivery_date=?,delivery_address=?,delivery_officer=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$vehicleId,$customerId,$saleId,$deliveryDate,$deliveryAddress,$deliveryOfficer,$status,$notes,$id,$orgId]);
            setFlash('success', 'Delivery updated.');
            logActivity('update', 'caryard', "Updated delivery #$id");
        } else {
            $year = date('Y');
            $seq  = 1;
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM caryard_delivery WHERE org_id=? AND ref LIKE ?");
                $stmt->execute([$orgId, "DLV-$year-%"]);
                $seq = (int)$stmt->fetchColumn() + 1;
            } catch (Exception $e) {}
            $ref = 'DLV-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO caryard_delivery(org_id,ref,vehicle_id,customer_id,sale_id,delivery_date,delivery_address,delivery_officer,status,notes) VALUES(?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$ref,$vehicleId,$customerId,$saleId,$deliveryDate,$deliveryAddress,$deliveryOfficer,$status,$notes]);
            setFlash('success', "Delivery '$ref' scheduled.");
            logActivity('create', 'caryard', "Scheduled delivery: $ref");
        }
        redirect('delivery.php');
    }

    if ($action === 'status_update') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['scheduled','out_for_delivery','delivered','failed','rescheduled']) ? $_POST['status'] : 'scheduled';
        $pdo->prepare("UPDATE caryard_delivery SET status=? WHERE id=? AND org_id=?")->execute([$status,$id,$orgId]);
        setFlash('success', 'Delivery status updated.');
        redirect('delivery.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM caryard_delivery WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Delivery record deleted.');
        redirect('delivery.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$deliveries = [];
try {
    $stmt = $pdo->prepare("SELECT d.*,
        CONCAT(v.reg_number,' ',v.make,' ',v.model) AS vehicle_label,
        c.name AS customer_name
        FROM caryard_delivery d
        LEFT JOIN caryard_vehicles v ON d.vehicle_id=v.id
        LEFT JOIN caryard_customers c ON d.customer_id=c.id
        WHERE d.org_id=? ORDER BY d.delivery_date DESC");
    $stmt->execute([$orgId]);
    $deliveries = $stmt->fetchAll();
} catch (Exception $e) {}

$vehicles = [];
try {
    $stmt = $pdo->prepare("SELECT id,reg_number,make,model FROM caryard_vehicles WHERE org_id=? ORDER BY reg_number");
    $stmt->execute([$orgId]); $vehicles = $stmt->fetchAll();
} catch (Exception $e) {}

$customers = [];
try {
    $stmt = $pdo->prepare("SELECT id,name FROM caryard_customers WHERE org_id=? ORDER BY name");
    $stmt->execute([$orgId]); $customers = $stmt->fetchAll();
} catch (Exception $e) {}

$scheduledCount   = countRows('caryard_delivery', 'org_id=? AND status=?', [$orgId,'scheduled']);
$deliveredCount   = countRows('caryard_delivery', 'org_id=? AND status=?', [$orgId,'delivered']);
$failedCount      = countRows('caryard_delivery', 'org_id=? AND status=?', [$orgId,'failed']);
$todayCount       = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM caryard_delivery WHERE org_id=? AND DATE(delivery_date)=CURDATE()");
    $stmt->execute([$orgId]); $todayCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-truck-loading me-2" style="color:<?= $moduleColor ?>"></i>Vehicle Deliveries</h4>
    <p class="text-muted mb-0">Schedule and track vehicle delivery to customers</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#dlvModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Schedule Delivery
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?= $scheduledCount ?></div><div class="stat-label">Scheduled</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?= $deliveredCount ?></div><div class="stat-label">Delivered</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-times-circle"></i></div><div class="stat-body"><div class="stat-value"><?= $failedCount ?></div><div class="stat-label">Failed</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-calendar-day"></i></div><div class="stat-body"><div class="stat-value"><?= $todayCount ?></div><div class="stat-label">Today's Deliveries</div></div></div></div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-truck-loading me-2" style="color:<?= $moduleColor ?>"></i>Delivery Log</h6>
    <span class="badge bg-secondary"><?= count($deliveries) ?> deliveries</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="dlvTable">
        <thead class="table-light">
          <tr><th>Ref</th><th>Vehicle</th><th>Customer</th><th>Officer</th><th>Date</th><th>Address</th><th>Status</th><th class="text-center no-print">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($deliveries)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No delivery records found.</td></tr>
          <?php else: foreach ($deliveries as $dlv): ?>
          <tr>
            <td><code class="bg-light px-2 py-1 rounded text-dark"><?= e($dlv['ref'] ?? '—') ?></code></td>
            <td class="fw-semibold"><?= e($dlv['vehicle_label'] ?? '—') ?></td>
            <td><?= e($dlv['customer_name'] ?? '—') ?></td>
            <td><?= e($dlv['delivery_officer'] ?? '—') ?></td>
            <td><?= formatDate($dlv['delivery_date'] ?? '') ?></td>
            <td class="text-muted small" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($dlv['delivery_address'] ?? '—') ?></td>
            <td>
              <form method="POST" class="d-inline no-print">
                <?= csrfField() ?><input type="hidden" name="action" value="status_update"><input type="hidden" name="id" value="<?= $dlv['id'] ?>">
                <select name="status" class="form-select form-select-sm" style="width:auto;display:inline-block" onchange="this.form.submit()">
                  <?php foreach (['scheduled','out_for_delivery','delivered','failed','rescheduled'] as $s): ?>
                  <option value="<?= $s ?>" <?= $dlv['status']===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="text-center no-print" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='fillForm(<?= htmlspecialchars(json_encode($dlv), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delDlv(<?= $dlv['id'] ?>,'<?= e($dlv['ref'] ?? '') ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="dlvModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="dlvId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="dlvModalTitle"><i class="fas fa-truck-loading me-2"></i>Schedule Delivery</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label><select name="vehicle_id" id="dlvVehicle" class="form-select" required><option value="">-- Select Vehicle --</option><?php foreach ($vehicles as $v): ?><option value="<?= $v['id'] ?>"><?= e(($v['reg_number'] ?? '') . ' — ' . ($v['make'] ?? '') . ' ' . ($v['model'] ?? '')) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Customer</label><select name="customer_id" id="dlvCustomer" class="form-select"><option value="">-- None --</option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Delivery Date <span class="text-danger">*</span></label><input type="date" name="delivery_date" id="dlvDate" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Delivery Officer</label><input type="text" name="delivery_officer" id="dlvOfficer" class="form-control" maxlength="100"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="dlvStatus" class="form-select"><option value="scheduled">Scheduled</option><option value="out_for_delivery">Out for Delivery</option><option value="delivered">Delivered</option><option value="failed">Failed</option><option value="rescheduled">Rescheduled</option></select></div>
            <div class="col-12"><label class="form-label fw-semibold">Delivery Address</label><input type="text" name="delivery_address" id="dlvAddress" class="form-control" maxlength="255"></div>
            <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="dlvNotes" class="form-control" rows="3"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Delivery</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delDlvForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delDlvId"></form>
<?php
$extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('dlvModalTitle').innerHTML='<i class="fas fa-truck-loading me-2"></i>Schedule Delivery';
  document.getElementById('dlvId').value='0';
  document.getElementById('dlvVehicle').value='';
  document.getElementById('dlvCustomer').value='';
  document.getElementById('dlvDate').value=new Date().toISOString().split('T')[0];
  document.getElementById('dlvOfficer').value='';
  document.getElementById('dlvStatus').value='scheduled';
  document.getElementById('dlvAddress').value='';
  document.getElementById('dlvNotes').value='';
}
function fillForm(d){
  document.getElementById('dlvModalTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Delivery';
  document.getElementById('dlvId').value=d.id;
  document.getElementById('dlvVehicle').value=d.vehicle_id||'';
  document.getElementById('dlvCustomer').value=d.customer_id||'';
  document.getElementById('dlvDate').value=d.delivery_date||'';
  document.getElementById('dlvOfficer').value=d.delivery_officer||'';
  document.getElementById('dlvStatus').value=d.status||'scheduled';
  document.getElementById('dlvAddress').value=d.delivery_address||'';
  document.getElementById('dlvNotes').value=d.notes||'';
  new bootstrap.Modal(document.getElementById('dlvModal')).show();
}
function delDlv(id,ref){
  Swal.fire({title:'Delete Delivery?',text:ref+' record will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delDlvId').value=id;document.getElementById('delDlvForm').submit();}});
}
$(document).ready(function(){$('#dlvTable').DataTable({pageLength:15,order:[[4,'desc']],language:{emptyTable:'No delivery records found.'}});});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
