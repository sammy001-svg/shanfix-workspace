<?php
// ── Shopping Mall: Floors ─────────────────────────────────────
$moduleSlug  = 'shopping-mall';
$moduleName  = 'Shopping Mall';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'floors.php',      'icon' => 'fas fa-layer-group',    'label' => 'Floors'],
    ['url' => 'shops.php',       'icon' => 'fas fa-store',          'label' => 'Shops'],
    ['url' => 'tenants.php',     'icon' => 'fas fa-user-tie',       'label' => 'Tenants'],
    ['url' => 'leases.php',      'icon' => 'fas fa-file-contract',  'label' => 'Leases'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-check',    'label' => 'Rent Payments'],
    ['url' => 'maintenance.php', 'icon' => 'fas fa-tools',          'label' => 'Maintenance'],
    ['url' => 'utilities.php',   'icon' => 'fas fa-bolt',           'label' => 'Utilities'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],
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
        $id    = (int)($_POST['id'] ?? 0);
        $name  = sanitize($_POST['name'] ?? '');
        $level = (int)($_POST['level'] ?? 0);

        if (empty($name)) {
            setFlash('danger', 'Floor name is required.');
            redirect('floors.php');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE mall_floors SET name=?, level=? WHERE id=? AND org_id=?");
            $stmt->execute([$name, $level, $id, $orgId]);
            setFlash('success', "Floor \"$name\" updated.");
            logActivity('update', 'shopping-mall', "Updated floor: $name");
        } else {
            $stmt = $pdo->prepare("INSERT INTO mall_floors (org_id, name, level) VALUES (?,?,?)");
            $stmt->execute([$orgId, $name, $level]);
            setFlash('success', "Floor \"$name\" added.");
            logActivity('create', 'shopping-mall', "Added floor: $name");
        }
        redirect('floors.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = $pdo->prepare("SELECT COUNT(*) FROM mall_shops WHERE floor_id=? AND org_id=?");
        $used->execute([$id, $orgId]);
        if ((int)$used->fetchColumn() > 0) {
            setFlash('danger', 'Cannot delete: this floor has shops assigned to it. Remove or reassign the shops first.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM mall_floors WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            setFlash('success', 'Floor deleted.');
            logActivity('delete', 'shopping-mall', "Deleted floor #$id");
        }
        redirect('floors.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$floors = [];
try {
    $stmt = $pdo->prepare("
        SELECT f.*, COUNT(s.id) AS shop_count,
               SUM(CASE WHEN s.status='occupied' THEN 1 ELSE 0 END) AS occupied_count,
               SUM(CASE WHEN s.status='vacant' THEN 1 ELSE 0 END) AS vacant_count
        FROM mall_floors f
        LEFT JOIN mall_shops s ON s.floor_id = f.id AND s.org_id = f.org_id
        WHERE f.org_id = ?
        GROUP BY f.id
        ORDER BY f.level ASC, f.name ASC
    ");
    $stmt->execute([$orgId]);
    $floors = $stmt->fetchAll();
} catch (Exception $e) {}

$total      = count($floors);
$totalShops = array_sum(array_column($floors, 'shop_count'));

// Level label helper
function levelLabel(int $level): string {
    if ($level === 0) return 'Ground Floor (G)';
    $suffix = match(true) {
        $level === 1 => 'st', $level === 2 => 'nd', $level === 3 => 'rd', default => 'th'
    };
    return "{$level}{$suffix} Floor";
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-layer-group me-2" style="color:<?= $moduleColor ?>"></i>Floors</h4>
    <p class="text-muted mb-0">Manage the floor levels of your shopping mall</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff"
    data-bs-toggle="modal" data-bs-target="#floorModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Floor
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-layer-group"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $total ?></div><div class="stat-label">Total Floors</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-store"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalShops ?></div><div class="stat-label">Total Shops</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-door-closed"></i></div>
      <div class="stat-body"><div class="stat-value"><?= array_sum(array_column($floors, 'occupied_count')) ?></div><div class="stat-label">Occupied</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-door-open"></i></div>
      <div class="stat-body"><div class="stat-value"><?= array_sum(array_column($floors, 'vacant_count')) ?></div><div class="stat-label">Vacant</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-layer-group me-2" style="color:<?= $moduleColor ?>"></i>All Floors</h6>
    <span class="badge bg-secondary"><?= $total ?> floors</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Level</th>
            <th>Floor Name</th>
            <th class="text-center">Total Shops</th>
            <th class="text-center">Occupied</th>
            <th class="text-center">Vacant</th>
            <th class="text-center">Occupancy</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($floors)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            <i class="fas fa-layer-group fa-2x mb-2 d-block"></i>No floors added yet.
          </td></tr>
          <?php else: foreach ($floors as $floor):
            $occ  = (int)$floor['occupied_count'];
            $tot  = (int)$floor['shop_count'];
            $pct  = $tot > 0 ? round($occ / $tot * 100) : 0;
          ?>
          <tr>
            <td>
              <span class="badge" style="background:<?= $moduleColor ?>"><?= (int)$floor['level'] === 0 ? 'G' : $floor['level'] ?></span>
            </td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div style="width:34px;height:34px;border-radius:8px;background:<?= $moduleColor ?>22;color:<?= $moduleColor ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <i class="fas fa-layer-group"></i>
                </div>
                <div>
                  <div class="fw-semibold"><?= e($floor['name']) ?></div>
                  <div class="small text-muted"><?= levelLabel((int)$floor['level']) ?></div>
                </div>
              </div>
            </td>
            <td class="text-center"><a href="shops.php?floor=<?= $floor['id'] ?>" class="badge bg-info text-decoration-none"><?= $tot ?></a></td>
            <td class="text-center"><span class="badge bg-success"><?= $occ ?></span></td>
            <td class="text-center"><span class="badge bg-secondary"><?= (int)$floor['vacant_count'] ?></span></td>
            <td class="text-center" style="min-width:120px">
              <div class="progress" style="height:8px">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $moduleColor ?>"></div>
              </div>
              <div class="small text-muted mt-1"><?= $pct ?>%</div>
            </td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode($floor), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="delFloor(<?= $floor['id'] ?>, '<?= e($floor['name']) ?>', <?= $tot ?>)"
                title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="floorModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="fId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="floorModalTitle"><i class="fas fa-layer-group me-2"></i>Add Floor</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Floor Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="fName" class="form-control" required maxlength="100"
              placeholder="e.g. Ground Floor, First Floor, Basement">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Level Number <small class="text-muted">(0 = Ground)</small></label>
            <input type="number" name="level" id="fLevel" class="form-control" min="-2" max="20" value="0">
            <div class="form-text">Use negative values for basements (e.g. -1 for B1).</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Floor</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="delFloorForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delFloorId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('floorModalTitle').innerHTML = '<i class="fas fa-layer-group me-2"></i>Add Floor';
  document.getElementById('fId').value = '0';
  document.getElementById('fName').value = '';
  document.getElementById('fLevel').value = '0';
}
function openEdit(f) {
  document.getElementById('floorModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Floor';
  document.getElementById('fId').value    = f.id;
  document.getElementById('fName').value  = f.name || '';
  document.getElementById('fLevel').value = f.level || '0';
  new bootstrap.Modal(document.getElementById('floorModal')).show();
}
function delFloor(id, name, count) {
  if (count > 0) {
    Swal.fire({ title: 'Cannot Delete', text: '"' + name + '" has ' + count + ' shop(s) on it. Move or delete them first.', icon: 'error' });
    return;
  }
  Swal.fire({
    title: 'Delete Floor?',
    text: '"' + name + '" will be permanently deleted.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('delFloorId').value = id;
      document.getElementById('delFloorForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
