<?php
// ── Courier: Routes ───────────────────────────────────────────
$moduleSlug  = 'courier';
$moduleName  = 'Courier Management';
$moduleIcon  = 'fas fa-shipping-fast';
$moduleColor = '#1565c0';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'couriers.php',   'icon' => 'fas fa-box',             'label' => 'Couriers'],
    ['url' => 'tracking.php',   'icon' => 'fas fa-map-marker-alt',  'label' => 'Tracking'],
    ['url' => 'manifest.php',   'icon' => 'fas fa-clipboard-list',  'label' => 'Manifests'],
    ['url' => 'delivery.php',   'icon' => 'fas fa-truck',           'label' => 'Deliveries'],
    ['url' => 'routes.php',     'icon' => 'fas fa-route',           'label' => 'Routes'],
    ['url' => 'payments.php',   'icon' => 'fas fa-credit-card',     'label' => 'Payments'],
    ['url' => 'agents.php',     'icon' => 'fas fa-user-tie',        'label' => 'Agents'],
    ['url' => 'agreements.php', 'icon' => 'fas fa-file-contract',   'label' => 'Agreements'],
    ['url' => 'setup.php',      'icon' => 'fas fa-cog',             'label' => 'Setup'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $routeName   = sanitize($_POST['route_name'] ?? '');
        $origin      = sanitize($_POST['origin'] ?? '');
        $destination = sanitize($_POST['destination'] ?? '');
        $waypoints   = sanitize($_POST['waypoints'] ?? '');
        $agentId     = (int)($_POST['agent_id'] ?? 0);
        $frequency   = sanitize($_POST['frequency'] ?? '');
        $baseFare    = (float)($_POST['base_fare'] ?? 0);
        $estimatedKm = (float)($_POST['estimated_km'] ?? 0);
        $status      = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $notes       = sanitize($_POST['notes'] ?? '');

        if (!$routeName || !$origin || !$destination) {
            setFlash('danger', 'Route name, origin and destination are required.');
            redirect('routes.php');
        }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE courier_routes SET route_name=?, origin=?, destination=?, waypoints=?, agent_id=?, frequency=?, base_fare=?, estimated_km=?, status=?, notes=?, updated_at=NOW() WHERE id=? AND org_id=?")
                    ->execute([$routeName, $origin, $destination, $waypoints, $agentId ?: null, $frequency, $baseFare, $estimatedKm, $status, $notes, $id, $orgId]);
                setFlash('success', 'Route updated successfully.');
                logActivity('update', 'courier', "Route #{$id} updated");
            } else {
                $pdo->prepare("INSERT INTO courier_routes (org_id, route_name, origin, destination, waypoints, agent_id, frequency, base_fare, estimated_km, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $routeName, $origin, $destination, $waypoints, $agentId ?: null, $frequency, $baseFare, $estimatedKm, $status, $notes]);
                setFlash('success', "Route '{$routeName}' created.");
                logActivity('create', 'courier', "Route '{$routeName}' created");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('routes.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM courier_routes WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Route deleted.');
        redirect('routes.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';

$where  = 'r.org_id = ?';
$params = [$orgId];
if ($fStatus !== '') { $where .= ' AND r.status = ?'; $params[] = $fStatus; }

$routes = $agents = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, CONCAT(a.first_name,' ',a.last_name) AS agent_name,
               COUNT(c.id) AS total_deliveries
        FROM courier_routes r
        LEFT JOIN courier_agents a ON a.id = r.agent_id
        LEFT JOIN couriers c ON c.route_id = r.id
        WHERE {$where}
        GROUP BY r.id
        ORDER BY r.status ASC, r.route_name ASC
    ");
    $stmt->execute($params);
    $routes = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM courier_agents WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]);
    $agents = $stmt->fetchAll();
} catch (Exception $e) {}

$activeRoutes = countRows('courier_routes', "org_id=? AND status='active'", [$orgId]);
$totalRoutes  = countRows('courier_routes', 'org_id=?', [$orgId]);

$frequencies = ['Daily', 'Mon-Fri', 'Mon-Sat', 'Weekly', 'Bi-weekly', 'On Demand'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-route me-2" style="color:<?= $moduleColor ?>"></i>Delivery Routes</h4>
    <p class="text-muted mb-0">Define and manage courier routes, assigned agents and fare schedules</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#routeModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Add Route
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(21,101,192,.12);color:#1565c0"><i class="fas fa-route"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalRoutes ?></div><div class="stat-label">Total Routes</div></div>
    </div>
  </div>
  <div class="col-sm-6">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeRoutes ?></div><div class="stat-label">Active Routes</div></div>
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="active"   <?= $fStatus === 'active'   ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="routes.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 data-table">
        <thead class="table-light">
          <tr>
            <th>Route Name</th><th>Origin → Destination</th><th>Waypoints</th><th>Agent</th>
            <th>Frequency</th><th class="text-end">Base Fare</th><th class="text-end">Est. KM</th>
            <th class="text-center">Deliveries</th><th class="text-center">Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($routes)): ?>
          <tr><td colspan="10" class="text-center py-5 text-muted"><i class="fas fa-route fa-3x mb-3 d-block"></i>No routes configured yet.</td></tr>
          <?php else: foreach ($routes as $r): ?>
          <tr>
            <td class="fw-bold"><?= e($r['route_name']) ?></td>
            <td>
              <span class="badge bg-light text-dark border"><?= e($r['origin']) ?></span>
              <i class="fas fa-arrow-right mx-1 text-muted small"></i>
              <span class="badge bg-light text-dark border"><?= e($r['destination']) ?></span>
            </td>
            <td class="small text-muted"><?= $r['waypoints'] ? e($r['waypoints']) : '—' ?></td>
            <td class="small"><?= $r['agent_name'] ? e($r['agent_name']) : '<span class="text-muted">—</span>' ?></td>
            <td class="small"><?= e($r['frequency'] ?: '—') ?></td>
            <td class="text-end"><?= $r['base_fare'] > 0 ? formatCurrency((float)$r['base_fare']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-end"><?= $r['estimated_km'] > 0 ? number_format((float)$r['estimated_km'], 1) . ' km' : '—' ?></td>
            <td class="text-center"><span class="badge bg-light text-dark border"><?= $r['total_deliveries'] ?></span></td>
            <td class="text-center"><?= statusBadge($r['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this route?')">
                <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger ms-1"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Route Modal -->
<div class="modal fade" id="routeModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="rtId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="rtTitle"><i class="fas fa-route me-2"></i>Add Route</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Route Name <span class="text-danger">*</span></label>
              <input type="text" name="route_name" id="rtName" class="form-control" required maxlength="150" placeholder="e.g. Nairobi CBD – Westlands Loop">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="rtStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Frequency</label>
              <select name="frequency" id="rtFreq" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($frequencies as $f): ?>
                <option value="<?= $f ?>"><?= $f ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Origin <span class="text-danger">*</span></label>
              <input type="text" name="origin" id="rtOrigin" class="form-control" required maxlength="150" placeholder="Starting point">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Destination <span class="text-danger">*</span></label>
              <input type="text" name="destination" id="rtDest" class="form-control" required maxlength="150" placeholder="End point">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Waypoints / Stops</label>
              <input type="text" name="waypoints" id="rtWaypoints" class="form-control" maxlength="500" placeholder="Intermediate stops (comma-separated)">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Default Agent</label>
              <select name="agent_id" id="rtAgent" class="form-select">
                <option value="">-- No Default Agent --</option>
                <?php foreach ($agents as $a): ?>
                <option value="<?= $a['id'] ?>"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Base Fare (KES)</label>
              <input type="number" name="base_fare" id="rtFare" class="form-control" min="0" step="0.01" value="0">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Est. Distance (km)</label>
              <input type="number" name="estimated_km" id="rtKm" class="form-control" min="0" step="0.1" value="0">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="rtNotes" class="form-control" rows="2" placeholder="Special instructions, road conditions, etc."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Route</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function openAdd() {
    document.getElementById('rtTitle').innerHTML = '<i class="fas fa-route me-2"></i>Add Route';
    document.getElementById('rtId').value = '0';
    ['rtName','rtOrigin','rtDest','rtWaypoints','rtNotes'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('rtStatus').value = 'active';
    document.getElementById('rtFreq').value   = '';
    document.getElementById('rtAgent').value  = '';
    document.getElementById('rtFare').value   = '0';
    document.getElementById('rtKm').value     = '0';
}
function openEdit(r) {
    document.getElementById('rtTitle').innerHTML    = '<i class="fas fa-edit me-2"></i>Edit Route';
    document.getElementById('rtId').value           = r.id;
    document.getElementById('rtName').value         = r.route_name || '';
    document.getElementById('rtOrigin').value       = r.origin || '';
    document.getElementById('rtDest').value         = r.destination || '';
    document.getElementById('rtWaypoints').value    = r.waypoints || '';
    document.getElementById('rtStatus').value       = r.status || 'active';
    document.getElementById('rtFreq').value         = r.frequency || '';
    document.getElementById('rtAgent').value        = r.agent_id || '';
    document.getElementById('rtFare').value         = r.base_fare || '0';
    document.getElementById('rtKm').value           = r.estimated_km || '0';
    document.getElementById('rtNotes').value        = r.notes || '';
    new bootstrap.Modal(document.getElementById('routeModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
