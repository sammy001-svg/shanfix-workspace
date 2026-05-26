<?php
// ── TOUR: Transport Vehicles ────────────────────────────────────
$moduleSlug  = 'tour';
$moduleName  = 'Tour & Travel';
$moduleIcon  = 'fas fa-plane';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'packages.php',     'icon' => 'fas fa-box-open',       'label' => 'Packages'],
    ['url' => 'destinations.php', 'icon' => 'fas fa-map-marker-alt', 'label' => 'Destinations'],
    ['url' => 'bookings.php',     'icon' => 'fas fa-calendar-check', 'label' => 'Bookings'],
    ['url' => 'customers.php',    'icon' => 'fas fa-user-friends',   'label' => 'Customers'],
    ['url' => 'guides.php',       'icon' => 'fas fa-hiking',         'label' => 'Guides'],
    ['url' => 'itineraries.php', 'icon' => 'fas fa-route',           'label' => 'Itineraries'],
    ['url' => 'vehicles.php',    'icon' => 'fas fa-bus',             'label' => 'Vehicles'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user = currentUser(); $orgId = (int)$user['org_id']; $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = sanitize($_POST['name'] ?? '');
        $type       = in_array($_POST['vehicle_type'] ?? '', ['minivan','bus','4x4','sedan','coaster','boat','aircraft','other']) ? $_POST['vehicle_type'] : 'minivan';
        $plate      = sanitize($_POST['plate_number'] ?? '');
        $capacity   = (int)($_POST['capacity'] ?? 0);
        $driver     = sanitize($_POST['driver_name'] ?? '');
        $driverPhone= sanitize($_POST['driver_phone'] ?? '');
        $status     = in_array($_POST['status'] ?? '', ['available','assigned','maintenance','retired']) ? $_POST['status'] : 'available';
        $notes      = sanitize($_POST['notes'] ?? '');
        if (!$name) { setFlash('error', 'Vehicle name is required.'); redirect('vehicles.php'); }
        if ($id > 0) {
            $pdo->prepare("UPDATE tour_vehicles SET name=?,vehicle_type=?,plate_number=?,capacity=?,driver_name=?,driver_phone=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$name,$type,$plate,$capacity,$driver,$driverPhone,$status,$notes,$id,$orgId]);
            setFlash('success', 'Vehicle updated.');
        } else {
            $pdo->prepare("INSERT INTO tour_vehicles(org_id,name,vehicle_type,plate_number,capacity,driver_name,driver_phone,status,notes)VALUES(?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$name,$type,$plate,$capacity,$driver,$driverPhone,$status,$notes]);
            setFlash('success', "Vehicle $name added.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'tour', "Vehicle: $name");
        redirect('vehicles.php');
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM tour_vehicles WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Vehicle removed.'); redirect('vehicles.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$fType   = $_GET['type'] ?? '';
$fStatus = $_GET['status'] ?? '';

$where = 'org_id=?'; $params = [$orgId];
if ($fType)   { $where .= ' AND vehicle_type=?'; $params[] = $fType; }
if ($fStatus) { $where .= ' AND status=?'; $params[] = $fStatus; }

$vehicles = [];
try { $s = $pdo->prepare("SELECT * FROM tour_vehicles WHERE $where ORDER BY name"); $s->execute($params); $vehicles = $s->fetchAll(); } catch (Exception $e) {}

$totalVehicles = 0; $availableCount = 0; $assignedCount = 0; $totalCapacity = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*),SUM(status='available'),SUM(status='assigned'),COALESCE(SUM(capacity),0) FROM tour_vehicles WHERE org_id=?");
    $s->execute([$orgId]); $r = $s->fetch(PDO::FETCH_NUM); $totalVehicles=(int)$r[0]; $availableCount=(int)$r[1]; $assignedCount=(int)$r[2]; $totalCapacity=(int)$r[3];
} catch (Exception $e) {}

$typeIcons  = ['minivan'=>'fa-shuttle-van','bus'=>'fa-bus','4x4'=>'fa-truck-monster','sedan'=>'fa-car','coaster'=>'fa-bus-alt','boat'=>'fa-ship','aircraft'=>'fa-plane','other'=>'fa-car-side'];
$typeColors = ['minivan'=>'info','bus'=>'primary','4x4'=>'warning','sedan'=>'secondary','coaster'=>'success','boat'=>'info','aircraft'=>'dark','other'=>'secondary'];
$statusColors = ['available'=>'success','assigned'=>'primary','maintenance'=>'warning','retired'=>'secondary'];
$vehicleTypes = ['minivan'=>'Minivan','bus'=>'Bus','4x4'=>'4×4 SUV','sedan'=>'Sedan','coaster'=>'Coaster','boat'=>'Boat','aircraft'=>'Aircraft','other'=>'Other'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bus me-2" style="color:<?=$moduleColor?>"></i>Vehicles</h4>
    <p class="text-muted mb-0">Manage tour transport fleet and driver assignments</p>
  </div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#vModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Vehicle
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-bus"></i></div><div class="stat-body"><div class="stat-value"><?=$totalVehicles?></div><div class="stat-label">Total Vehicles</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$availableCount?></div><div class="stat-label">Available</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#e3f2fd;color:<?=$moduleColor?>"><i class="fas fa-road"></i></div><div class="stat-body"><div class="stat-value"><?=$assignedCount?></div><div class="stat-label">On Assignment</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value"><?=$totalCapacity?></div><div class="stat-label">Total Capacity</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Type</label>
      <select name="type" class="form-select form-select-sm">
        <option value="">All Types</option>
        <?php foreach ($vehicleTypes as $k=>$v): ?><option value="<?=$k?>" <?=$fType===$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
      </select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <?php foreach (array_keys($statusColors) as $s): ?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="vehicles.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="row g-3">
<?php if (empty($vehicles)): ?>
  <div class="col-12 text-center text-muted py-5"><i class="fas fa-bus fa-3x mb-3 d-block"></i>No vehicles added yet.</div>
<?php else: foreach ($vehicles as $v): ?>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100 <?=$v['status']==='retired'?'opacity-75':''?>">
      <div class="card-body">
        <div class="d-flex align-items-start gap-3">
          <div style="width:50px;height:50px;border-radius:12px;background:<?=$moduleColor?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0">
            <i class="fas <?=$typeIcons[$v['vehicle_type']]??'fa-car'?>"></i>
          </div>
          <div class="flex-grow-1">
            <div class="fw-bold"><?=e($v['name'])?></div>
            <div class="d-flex gap-2 mt-1">
              <span class="badge bg-<?=$typeColors[$v['vehicle_type']]??'secondary'?>"><?=$vehicleTypes[$v['vehicle_type']]??e($v['vehicle_type'])?></span>
              <span class="badge bg-<?=$statusColors[$v['status']]??'secondary'?>"><?=ucfirst($v['status'])?></span>
            </div>
          </div>
        </div>
        <hr class="my-2">
        <div class="row g-1 small">
          <?php if ($v['plate_number']): ?><div class="col-6 text-muted">Plate:</div><div class="col-6 fw-semibold"><?=e($v['plate_number'])?></div><?php endif; ?>
          <div class="col-6 text-muted">Capacity:</div><div class="col-6 fw-semibold"><?=$v['capacity']?$v['capacity'].' pax':'—'?></div>
          <?php if ($v['driver_name']): ?><div class="col-6 text-muted">Driver:</div><div class="col-6"><?=e($v['driver_name'])?><?=$v['driver_phone']?'<br><span class="text-muted">'.e($v['driver_phone']).'</span>':''?></div><?php endif; ?>
        </div>
        <?php if ($v['notes']): ?><p class="small text-muted mt-2 mb-0"><?=e(mb_substr($v['notes'],0,80))?></p><?php endif; ?>
      </div>
      <div class="card-footer bg-transparent d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary flex-grow-1" onclick='openEdit(<?=htmlspecialchars(json_encode($v),ENT_QUOTES)?>)'><i class="fas fa-edit me-1"></i>Edit</button>
        <button class="btn btn-sm btn-outline-danger" onclick="delVehicle(<?=$v['id']?>,<?=json_encode($v['name'])?>)"><i class="fas fa-trash"></i></button>
      </div>
    </div>
  </div>
<?php endforeach; endif; ?>
</div>

<!-- Modal -->
<div class="modal fade" id="vModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="vId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="vTitle"><i class="fas fa-bus me-2"></i>Add Vehicle</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">Vehicle Name <span class="text-danger">*</span></label>
      <input type="text" name="name" id="vName" class="form-control" required maxlength="200"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Vehicle Type</label>
      <select name="vehicle_type" id="vType" class="form-select">
        <?php foreach ($vehicleTypes as $k=>$v2): ?><option value="<?=$k?>"><?=$v2?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Plate Number</label>
      <input type="text" name="plate_number" id="vPlate" class="form-control" maxlength="20"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Capacity (pax)</label>
      <input type="number" name="capacity" id="vCapacity" class="form-control" min="1" value="7"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="vStatus" class="form-select">
        <?php foreach (array_keys($statusColors) as $s): ?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Driver Name</label>
      <input type="text" name="driver_name" id="vDriver" class="form-control" maxlength="150"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Driver Phone</label>
      <input type="text" name="driver_phone" id="vDriverPhone" class="form-control" maxlength="50"></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label>
      <textarea name="notes" id="vNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Vehicle</button>
  </div></form>
</div></div></div>
<form method="POST" id="delVForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delVId"></form>

<?php $extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('vTitle').innerHTML='<i class="fas fa-bus me-2"></i>Add Vehicle';
  document.getElementById('vId').value='0';
  document.getElementById('vName').value='';
  document.getElementById('vType').value='minivan';
  document.getElementById('vPlate').value='';
  document.getElementById('vCapacity').value='7';
  document.getElementById('vStatus').value='available';
  document.getElementById('vDriver').value='';
  document.getElementById('vDriverPhone').value='';
  document.getElementById('vNotes').value='';
}
function openEdit(v){
  document.getElementById('vTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Vehicle';
  document.getElementById('vId').value=v.id;
  document.getElementById('vName').value=v.name||'';
  document.getElementById('vType').value=v.vehicle_type||'minivan';
  document.getElementById('vPlate').value=v.plate_number||'';
  document.getElementById('vCapacity').value=v.capacity||7;
  document.getElementById('vStatus').value=v.status||'available';
  document.getElementById('vDriver').value=v.driver_name||'';
  document.getElementById('vDriverPhone').value=v.driver_phone||'';
  document.getElementById('vNotes').value=v.notes||'';
  new bootstrap.Modal(document.getElementById('vModal')).show();
}
function delVehicle(id,name){
  Swal.fire({title:'Remove Vehicle?',text:name+' will be deleted.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delVId').value=id;document.getElementById('delVForm').submit();}});
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
