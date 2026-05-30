<?php
// ── Manufacturing: Work Orders ──────────────────────────────────
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
        $id              = (int)($_POST['id'] ?? 0);
        $productionId    = (int)($_POST['production_id'] ?? 0) ?: null;
        $productId       = (int)($_POST['product_id']    ?? 0);
        $machineId       = (int)($_POST['machine_id']    ?? 0) ?: null;
        $assignedTo      = sanitize($_POST['assigned_to']   ?? '');
        $priority        = sanitize($_POST['priority']       ?? 'normal');
        $scheduledStart  = $_POST['scheduled_start'] ?? null;
        $scheduledEnd    = $_POST['scheduled_end']   ?? null;
        $instructions    = sanitize($_POST['instructions']   ?? '');

        if ($productId <= 0) {
            setFlash('danger', 'Product is required.');
            redirect('workorders.php');
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE mfg_work_orders SET production_id=?, product_id=?, machine_id=?, assigned_to=?, priority=?, scheduled_start=?, scheduled_end=?, instructions=? WHERE id=? AND org_id=?");
                $stmt->execute([$productionId, $productId, $machineId, $assignedTo, $priority, $scheduledStart, $scheduledEnd, $instructions, $id, $orgId]);
                setFlash('success', 'Work order updated.');
                logActivity('update', 'manufacturing', "Updated work order #{$id}");
            } else {
                $yr   = date('Y');
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM mfg_work_orders WHERE org_id=? AND YEAR(created_at)=?");
                $stmt->execute([$orgId, $yr]);
                $seq  = (int)$stmt->fetchColumn() + 1;
                $woNo = 'WO-' . $yr . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

                $stmt = $pdo->prepare("INSERT INTO mfg_work_orders (org_id, wo_no, production_id, product_id, machine_id, assigned_to, priority, scheduled_start, scheduled_end, status, instructions) VALUES (?,?,?,?,?,?,?,?,?,'pending',?)");
                $stmt->execute([$orgId, $woNo, $productionId, $productId, $machineId, $assignedTo, $priority, $scheduledStart, $scheduledEnd, $instructions]);
                setFlash('success', "Work order {$woNo} created.");
                logActivity('create', 'manufacturing', "Created work order {$woNo}");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('workorders.php');
    }

    if ($action === 'update_status') {
        $wid    = (int)($_POST['wo_id'] ?? 0);
        $status = sanitize($_POST['status'] ?? '');
        $allowed = ['pending','in_progress','completed','on_hold','cancelled'];
        if (in_array($status, $allowed) && $wid > 0) {
            $updates = ['status=?'];
            $params  = [$status];
            if ($status === 'in_progress') { $updates[] = 'actual_start=NOW()'; }
            if ($status === 'completed')   { $updates[] = 'actual_end=NOW()'; }
            $params[] = $wid; $params[] = $orgId;
            $sql = "UPDATE mfg_work_orders SET " . implode(', ', $updates) . " WHERE id=? AND org_id=?";
            try {
                $pdo->prepare($sql)->execute($params);
                setFlash('success', 'Work order status updated to ' . ucfirst(str_replace('_',' ', $status)) . '.');
            } catch (Exception $e) {
                setFlash('danger', 'Error: ' . $e->getMessage());
            }
        }
        redirect('workorders.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Products and machines for dropdowns
$products = $machines = $productions = [];
try {
    $stmt = $pdo->prepare("SELECT id, product_name, sku FROM mfg_products WHERE org_id=? ORDER BY product_name");
    $stmt->execute([$orgId]); $products = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, name FROM mfg_machines WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]); $machines = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, order_no FROM mfg_production_orders WHERE org_id=? AND status IN ('pending','in_progress') ORDER BY order_no DESC");
    $stmt->execute([$orgId]); $productions = $stmt->fetchAll();
} catch (Exception $e) {}

// Work orders
$workorders = [];
try {
    $stmt = $pdo->prepare("
        SELECT w.*, p.product_name, p.sku, m.name AS machine_name, po.order_no AS production_no
        FROM mfg_work_orders w
        LEFT JOIN mfg_products p ON p.id = w.product_id
        LEFT JOIN mfg_machines m ON m.id = w.machine_id
        LEFT JOIN mfg_production_orders po ON po.id = w.production_id
        WHERE w.org_id = ?
        ORDER BY FIELD(w.priority,'urgent','high','normal','low'), w.scheduled_start ASC
    ");
    $stmt->execute([$orgId]);
    $workorders = $stmt->fetchAll();
} catch (Exception $e) {}

$countByStatus = array_count_values(array_column($workorders, 'status'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-clipboard-list me-2" style="color:<?= $moduleColor ?>"></i>Work Orders</h4>
    <p class="text-muted mb-0">Manage shop-floor work orders assigned to machines and operators</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#woModal" onclick="resetForm()">
    <i class="fas fa-plus-circle me-1"></i>New Work Order
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <?php
  $statDefs = [
    ['pending','Pending','warning-bg','fas fa-clock'],
    ['in_progress','In Progress','danger-bg','fas fa-play-circle'],
    ['completed','Completed','green-bg','fas fa-check-circle'],
    ['on_hold','On Hold','','fas fa-pause-circle'],
  ];
  foreach ($statDefs as [$s, $lbl, $cls, $ico]):
  ?>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $cls ?>" <?= !$cls ? 'style="background:rgba(108,117,125,0.12);color:#6c757d"' : '' ?>><i class="<?= $ico ?>"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $countByStatus[$s] ?? 0 ?></div><div class="stat-label"><?= $lbl ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Work Orders Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="woTable">
        <thead class="table-light">
          <tr>
            <th>WO No.</th>
            <th>Product</th>
            <th>Machine</th>
            <th>Assigned To</th>
            <th class="text-center">Priority</th>
            <th>Scheduled</th>
            <th class="text-center">Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($workorders)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-clipboard-list fa-3x mb-3 d-block"></i>No work orders yet.</td></tr>
          <?php else: foreach ($workorders as $wo):
            $priorityBadge = match($wo['priority']) {
              'urgent' => 'bg-danger', 'high' => 'bg-warning text-dark',
              'normal' => 'bg-primary', 'low' => 'bg-secondary', default => 'bg-light text-dark'
            };
            $statusBadge = match($wo['status']) {
              'pending'     => 'bg-warning text-dark',
              'in_progress' => 'bg-primary',
              'completed'   => 'bg-success',
              'on_hold'     => 'bg-secondary',
              'cancelled'   => 'bg-danger',
              default       => 'bg-light text-dark'
            };
          ?>
          <tr>
            <td><span class="badge bg-dark"><?= e($wo['wo_no']) ?></span></td>
            <td>
              <div class="fw-semibold"><?= e($wo['product_name']) ?></div>
              <div class="small text-muted"><?= e($wo['sku'] ?: '') ?></div>
            </td>
            <td><?= e($wo['machine_name'] ?: '—') ?></td>
            <td><?= e($wo['assigned_to'] ?: '—') ?></td>
            <td class="text-center"><span class="badge <?= $priorityBadge ?>"><?= ucfirst($wo['priority']) ?></span></td>
            <td>
              <div class="small"><?= $wo['scheduled_start'] ? formatDate($wo['scheduled_start']) : '—' ?></div>
              <div class="small text-muted"><?= $wo['scheduled_end'] ? '→ ' . formatDate($wo['scheduled_end']) : '' ?></div>
            </td>
            <td class="text-center"><span class="badge <?= $statusBadge ?>"><?= ucfirst(str_replace('_',' ', $wo['status'])) ?></span></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick='openEdit(<?= json_encode($wo) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                <?php if (!in_array($wo['status'], ['completed','cancelled'])): ?>
                <button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" title="Change Status"></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <?php foreach(['pending','in_progress','on_hold','completed','cancelled'] as $ns): if ($ns === $wo['status']) continue; ?>
                  <li>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="wo_id" value="<?= $wo['id'] ?>">
                      <input type="hidden" name="status" value="<?= $ns ?>">
                      <button class="dropdown-item" type="submit"><?= ucfirst(str_replace('_',' ',$ns)) ?></button>
                    </form>
                  </li>
                  <?php endforeach; ?>
                </ul>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- WO Modal -->
<div class="modal fade" id="woModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="woId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="woModalTitle"><i class="fas fa-clipboard-list me-2"></i>New Work Order</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
              <select name="product_id" id="woProduct" class="form-select" required>
                <option value="">-- Select Product --</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['product_name']) ?> <?= $p['sku'] ? '('.$p['sku'].')' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Machine</label>
              <select name="machine_id" id="woMachine" class="form-select">
                <option value="">-- None / Any --</option>
                <?php foreach ($machines as $mc): ?>
                <option value="<?= $mc['id'] ?>"><?= e($mc['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Production Order (Optional)</label>
              <select name="production_id" id="woProduction" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($productions as $po): ?>
                <option value="<?= $po['id'] ?>"><?= e($po['order_no']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Priority</label>
              <select name="priority" id="woPriority" class="form-select">
                <option value="normal">Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
                <option value="low">Low</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Assigned To</label>
              <input type="text" name="assigned_to" id="woAssigned" class="form-control" placeholder="Operator name">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Scheduled Start</label>
              <input type="datetime-local" name="scheduled_start" id="woStart" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Scheduled End</label>
              <input type="datetime-local" name="scheduled_end" id="woEnd" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Instructions / Notes</label>
              <textarea name="instructions" id="woInstructions" class="form-control" rows="3" placeholder="Work instructions..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Work Order</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#woTable").DataTable({pageLength:15, order:[[6,"asc"],[4,"asc"]]});
});
function resetForm() {
    $("#woId").val(0);
    $("#woModalTitle").html('<i class="fas fa-clipboard-list me-2"></i>New Work Order');
    document.querySelector("#woModal form").reset();
}
function openEdit(w) {
    $("#woId").val(w.id);
    $("#woModalTitle").html('<i class="fas fa-edit me-2"></i>Edit Work Order');
    $("#woProduct").val(w.product_id);
    $("#woMachine").val(w.machine_id);
    $("#woProduction").val(w.production_id);
    $("#woPriority").val(w.priority);
    $("#woAssigned").val(w.assigned_to);
    $("#woStart").val(w.scheduled_start ? w.scheduled_start.replace(' ','T').substr(0,16) : '');
    $("#woEnd").val(w.scheduled_end ? w.scheduled_end.replace(' ','T').substr(0,16) : '');
    $("#woInstructions").val(w.instructions);
    $("#woModal").modal("show");
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
