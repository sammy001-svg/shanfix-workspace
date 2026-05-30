<?php
// ── Courier: Manifests ────────────────────────────────────────
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
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $routeId    = (int)($_POST['route_id'] ?? 0);
        $agentId    = (int)($_POST['agent_id'] ?? 0);
        $date       = sanitize($_POST['manifest_date'] ?? date('Y-m-d'));
        $notes      = sanitize($_POST['notes'] ?? '');
        $status     = in_array($_POST['status'] ?? '', ['draft','dispatched','in_transit','completed','cancelled']) ? $_POST['status'] : 'draft';
        $parcels    = array_filter(array_map('intval', (array)($_POST['parcel_ids'] ?? [])));

        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $pdo->prepare("UPDATE courier_manifests SET route_id=?, agent_id=?, manifest_date=?, notes=?, status=?, updated_at=NOW() WHERE id=? AND org_id=?")
                    ->execute([$routeId ?: null, $agentId ?: null, $date, $notes, $status, $id, $orgId]);
                $pdo->prepare("DELETE FROM courier_manifest_items WHERE manifest_id=?")->execute([$id]);
                setFlash('success', 'Manifest updated.');
                logActivity('update', 'courier', "Manifest #{$id} updated");
            } else {
                $seq = $pdo->query("SELECT COUNT(*) FROM courier_manifests WHERE org_id=$orgId")->fetchColumn() + 1;
                $mfNo = 'MF-' . date('Y') . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO courier_manifests (org_id, manifest_no, route_id, agent_id, manifest_date, notes, status) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$orgId, $mfNo, $routeId ?: null, $agentId ?: null, $date, $notes, $status]);
                $id = $pdo->lastInsertId();
                setFlash('success', "Manifest {$mfNo} created.");
                logActivity('create', 'courier', "Manifest {$mfNo} created");
            }
            // Re-link parcels
            if (!empty($parcels)) {
                $stmt = $pdo->prepare("INSERT INTO courier_manifest_items (manifest_id, courier_id) VALUES (?,?)");
                foreach ($parcels as $pid) { $stmt->execute([$id, $pid]); }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('manifest.php');
    }

    if ($action === 'dispatch') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE courier_manifests SET status='dispatched', updated_at=NOW() WHERE id=? AND org_id=?")
            ->execute([$id, $orgId]);
        setFlash('success', 'Manifest dispatched.');
        redirect('manifest.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$manifests = $routes = $agents = $unlinkedCouriers = [];
try {
    $stmt = $pdo->prepare("
        SELECT m.*, r.route_name, CONCAT(a.first_name,' ',a.last_name) AS agent_name,
               COUNT(mi.id) AS parcel_count
        FROM courier_manifests m
        LEFT JOIN courier_routes r ON r.id = m.route_id
        LEFT JOIN courier_agents a ON a.id = m.agent_id
        LEFT JOIN courier_manifest_items mi ON mi.manifest_id = m.id
        WHERE m.org_id = ?
        GROUP BY m.id
        ORDER BY m.manifest_date DESC, m.id DESC
    ");
    $stmt->execute([$orgId]);
    $manifests = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, route_name FROM courier_routes WHERE org_id=? AND status='active' ORDER BY route_name");
    $stmt->execute([$orgId]);
    $routes = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM courier_agents WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]);
    $agents = $stmt->fetchAll();

    // Couriers not yet on a manifest (pending/processing status)
    $stmt = $pdo->prepare("
        SELECT c.id, c.tracking_no, c.sender_name, c.recipient_name, c.destination
        FROM couriers c
        WHERE c.org_id=? AND c.status IN ('pending','processing')
        AND c.id NOT IN (SELECT courier_id FROM courier_manifest_items)
        ORDER BY c.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$orgId]);
    $unlinkedCouriers = $stmt->fetchAll();
} catch (Exception $e) {}

$draftCount     = count(array_filter($manifests, fn($m) => $m['status'] === 'draft'));
$dispatchedCount= count(array_filter($manifests, fn($m) => $m['status'] === 'dispatched'));
$completedCount = count(array_filter($manifests, fn($m) => $m['status'] === 'completed'));

$statusColors = ['draft' => 'secondary', 'dispatched' => 'primary', 'in_transit' => 'info', 'completed' => 'success', 'cancelled' => 'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-clipboard-list me-2" style="color:<?= $moduleColor ?>"></i>Dispatch Manifests</h4>
    <p class="text-muted mb-0">Group parcels into dispatch manifests, assign agents and routes</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#mfModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>New Manifest
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(21,101,192,.12);color:#1565c0"><i class="fas fa-clipboard-list"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($manifests) ?></div><div class="stat-label">Total Manifests</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $draftCount ?></div><div class="stat-label">Drafts</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $completedCount ?></div><div class="stat-label">Completed</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 data-table">
        <thead class="table-light">
          <tr><th>Manifest #</th><th>Date</th><th>Route</th><th>Agent</th><th class="text-center">Parcels</th><th class="text-center">Status</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($manifests)): ?>
          <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-clipboard-list fa-3x mb-3 d-block"></i>No manifests created yet.</td></tr>
          <?php else: foreach ($manifests as $m): ?>
          <tr>
            <td class="fw-bold"><?= e($m['manifest_no']) ?></td>
            <td><?= date('d M Y', strtotime($m['manifest_date'])) ?></td>
            <td><?= $m['route_name'] ? e($m['route_name']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $m['agent_name'] ? e($m['agent_name']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-center"><span class="badge bg-light text-dark border"><?= $m['parcel_count'] ?> parcels</span></td>
            <td class="text-center"><span class="badge bg-<?= $statusColors[$m['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$m['status'])) ?></span></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($m), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <?php if ($m['status'] === 'draft'): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Dispatch this manifest?')">
                <?= csrfField() ?><input type="hidden" name="action" value="dispatch"><input type="hidden" name="id" value="<?= $m['id'] ?>">
                <button class="btn btn-sm btn-outline-success ms-1" title="Dispatch"><i class="fas fa-paper-plane"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Manifest Modal -->
<div class="modal fade" id="mfModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="mfId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="mfTitle"><i class="fas fa-clipboard-list me-2"></i>New Manifest</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Manifest Date</label>
              <input type="date" name="manifest_date" id="mfDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Route</label>
              <select name="route_id" id="mfRoute" class="form-select">
                <option value="">-- No Route --</option>
                <?php foreach ($routes as $r): ?>
                <option value="<?= $r['id'] ?>"><?= e($r['route_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Assigned Agent</label>
              <select name="agent_id" id="mfAgent" class="form-select">
                <option value="">-- No Agent --</option>
                <?php foreach ($agents as $a): ?>
                <option value="<?= $a['id'] ?>"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="mfStatus" class="form-select">
                <option value="draft">Draft</option>
                <option value="dispatched">Dispatched</option>
                <option value="in_transit">In Transit</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Parcels to Include</label>
              <div class="border rounded p-2" style="max-height:200px;overflow-y:auto">
                <?php if (empty($unlinkedCouriers)): ?>
                <p class="text-muted small mb-0">No unassigned parcels available.</p>
                <?php else: foreach ($unlinkedCouriers as $c): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="parcel_ids[]" value="<?= $c['id'] ?>" id="p<?= $c['id'] ?>">
                  <label class="form-check-label small" for="p<?= $c['id'] ?>">
                    <strong><?= e($c['tracking_no']) ?></strong> — <?= e($c['recipient_name']) ?> → <?= e($c['destination']) ?>
                  </label>
                </div>
                <?php endforeach; endif; ?>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="mfNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Manifest</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function openAdd() {
    document.getElementById('mfTitle').innerHTML = '<i class="fas fa-clipboard-list me-2"></i>New Manifest';
    document.getElementById('mfId').value     = '0';
    document.getElementById('mfDate').value   = new Date().toISOString().slice(0,10);
    document.getElementById('mfRoute').value  = '';
    document.getElementById('mfAgent').value  = '';
    document.getElementById('mfStatus').value = 'draft';
    document.getElementById('mfNotes').value  = '';
    document.querySelectorAll('input[name="parcel_ids[]"]').forEach(cb => cb.checked = false);
}
function openEdit(m) {
    document.getElementById('mfTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Manifest';
    document.getElementById('mfId').value     = m.id;
    document.getElementById('mfDate').value   = m.manifest_date;
    document.getElementById('mfRoute').value  = m.route_id || '';
    document.getElementById('mfAgent').value  = m.agent_id || '';
    document.getElementById('mfStatus').value = m.status;
    document.getElementById('mfNotes').value  = m.notes || '';
    new bootstrap.Modal(document.getElementById('mfModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
