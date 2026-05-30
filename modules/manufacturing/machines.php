<?php
// ── Manufacturing: Machine Registry ────────────────────────────
$moduleSlug  = 'manufacturing';
$moduleName  = 'Manufacturing';
$moduleIcon  = 'fas fa-industry';
$moduleColor = '#d35400';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'products.php',    'icon' => 'fas fa-box',            'label' => 'Products'],
    ['url' => 'materials.php',   'icon' => 'fas fa-cubes',          'label' => 'Raw Materials'],
    ['url' => 'bom.php',         'icon' => 'fas fa-list-alt',       'label' => 'Bill of Materials'],
    ['url' => 'production.php',  'icon' => 'fas fa-industry',       'label' => 'Production Orders'],
    ['url' => 'workorders.php',  'icon' => 'fas fa-clipboard-list', 'label' => 'Work Orders'],
    ['url' => 'machines.php',    'icon' => 'fas fa-cogs',           'label' => 'Machines'],
    ['url' => 'quality.php',     'icon' => 'fas fa-check-circle',   'label' => 'Quality Control'],
    ['url' => 'suppliers.php',   'icon' => 'fas fa-truck',           'label' => 'Suppliers'],
    ['url' => 'inventory.php',   'icon' => 'fas fa-warehouse',       'label' => 'Inventory'],
    ['url' => 'procurement.php', 'icon' => 'fas fa-shopping-basket', 'label' => 'Procurement'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
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
        $id           = (int)($_POST['id'] ?? 0);
        $name         = sanitize($_POST['name']         ?? '');
        $code         = sanitize($_POST['code']         ?? '');
        $machineType  = sanitize($_POST['machine_type'] ?? '');
        $manufacturer = sanitize($_POST['manufacturer'] ?? '');
        $model        = sanitize($_POST['model']        ?? '');
        $serialNo     = sanitize($_POST['serial_no']    ?? '');
        $status       = sanitize($_POST['status']       ?? 'active');
        $location     = sanitize($_POST['location']     ?? '');
        $purchasedAt  = $_POST['purchased_at'] ?? null;
        $notes        = sanitize($_POST['notes'] ?? '');

        if (empty($name)) {
            setFlash('danger', 'Machine name is required.');
            redirect('machines.php');
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE mfg_machines SET name=?, code=?, machine_type=?, manufacturer=?, model=?, serial_no=?, status=?, location=?, purchased_at=?, notes=? WHERE id=? AND org_id=?");
                $stmt->execute([$name, $code, $machineType, $manufacturer, $model, $serialNo, $status, $location, $purchasedAt ?: null, $notes, $id, $orgId]);
                setFlash('success', "Machine '{$name}' updated.");
                logActivity('update', 'manufacturing', "Updated machine: {$name}");
            } else {
                $stmt = $pdo->prepare("INSERT INTO mfg_machines (org_id, name, code, machine_type, manufacturer, model, serial_no, status, location, purchased_at, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$orgId, $name, $code, $machineType, $manufacturer, $model, $serialNo, $status, $location, $purchasedAt ?: null, $notes]);
                setFlash('success', "Machine '{$name}' registered.");
                logActivity('create', 'manufacturing', "Registered machine: {$name}");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('machines.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$machines = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM mfg_machines WHERE org_id=? ORDER BY name");
    $stmt->execute([$orgId]);
    $machines = $stmt->fetchAll();
} catch (Exception $e) {}

$totalMachines  = count($machines);
$activeMachines = count(array_filter($machines, fn($m) => $m['status'] === 'active'));
$maintenance    = count(array_filter($machines, fn($m) => $m['status'] === 'maintenance'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-cogs me-2" style="color:<?= $moduleColor ?>"></i>Machine Registry</h4>
    <p class="text-muted mb-0">Track production machinery, equipment status, and maintenance schedules</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#machineModal" onclick="resetForm()">
    <i class="fas fa-plus-circle me-1"></i>Register Machine
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(211,84,0,0.12);color:#d35400"><i class="fas fa-cogs"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalMachines ?></div><div class="stat-label">Total Machines</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeMachines ?></div><div class="stat-label">Operational</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-wrench"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $maintenance ?></div><div class="stat-label">Under Maintenance</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="machinesTable">
        <thead class="table-light">
          <tr>
            <th>Machine</th>
            <th>Code</th>
            <th>Type</th>
            <th>Manufacturer / Model</th>
            <th>Serial No.</th>
            <th>Location</th>
            <th class="text-center">Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($machines)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-cogs fa-3x mb-3 d-block"></i>No machines registered.</td></tr>
          <?php else: foreach ($machines as $m):
            $statusBadge = match($m['status']) {
              'active'        => 'bg-success',
              'maintenance'   => 'bg-warning text-dark',
              'offline'       => 'bg-secondary',
              'decommissioned'=> 'bg-danger',
              default         => 'bg-light text-dark',
            };
          ?>
          <tr>
            <td class="fw-semibold"><?= e($m['name']) ?></td>
            <td><code><?= e($m['code'] ?: '—') ?></code></td>
            <td><?= e($m['machine_type'] ?: '—') ?></td>
            <td>
              <div><?= e($m['manufacturer'] ?: '—') ?></div>
              <div class="small text-muted"><?= e($m['model'] ?: '') ?></div>
            </td>
            <td class="small text-muted"><?= e($m['serial_no'] ?: '—') ?></td>
            <td><?= e($m['location'] ?: '—') ?></td>
            <td class="text-center"><span class="badge <?= $statusBadge ?>"><?= ucfirst(str_replace('_',' ', $m['status'])) ?></span></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= json_encode($m) ?>)' title="Edit">
                <i class="fas fa-edit"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Machine Modal -->
<div class="modal fade" id="machineModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="machineId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="machineModalTitle"><i class="fas fa-cogs me-2"></i>Register Machine</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Machine Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="machineName" class="form-control" required placeholder="e.g. CNC Lathe #1">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Machine Code</label>
              <input type="text" name="code" id="machineCode" class="form-control" placeholder="e.g. MCH-001">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="machineStatus" class="form-select">
                <option value="active">Active</option>
                <option value="maintenance">Maintenance</option>
                <option value="offline">Offline</option>
                <option value="decommissioned">Decommissioned</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Machine Type</label>
              <input type="text" name="machine_type" id="machineType" class="form-control" placeholder="e.g. CNC, Hydraulic Press">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Manufacturer</label>
              <input type="text" name="manufacturer" id="machineManufacturer" class="form-control" placeholder="e.g. Fanuc">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Model</label>
              <input type="text" name="model" id="machineModel" class="form-control" placeholder="Model number">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Serial Number</label>
              <input type="text" name="serial_no" id="machineSerial" class="form-control" placeholder="Serial No.">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Location / Line</label>
              <input type="text" name="location" id="machineLocation" class="form-control" placeholder="e.g. Production Line A">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Purchase Date</label>
              <input type="date" name="purchased_at" id="machinePurchased" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="machineNotes" class="form-control" rows="2" placeholder="Any relevant notes..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Machine</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#machinesTable").DataTable({pageLength:15, order:[[0,"asc"]]});
});
function resetForm() {
    $("#machineId").val(0);
    $("#machineModalTitle").html('<i class="fas fa-cogs me-2"></i>Register Machine');
    document.querySelector("form").reset();
}
function openEdit(m) {
    $("#machineId").val(m.id);
    $("#machineModalTitle").html('<i class="fas fa-edit me-2"></i>Edit Machine');
    $("#machineName").val(m.name);
    $("#machineCode").val(m.code);
    $("#machineStatus").val(m.status);
    $("#machineType").val(m.machine_type);
    $("#machineManufacturer").val(m.manufacturer);
    $("#machineModel").val(m.model);
    $("#machineSerial").val(m.serial_no);
    $("#machineLocation").val(m.location);
    $("#machinePurchased").val(m.purchased_at ? m.purchased_at.substr(0,10) : '');
    $("#machineNotes").val(m.notes);
    $("#machineModal").modal("show");
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
