<?php
// ── Courier: Deliveries ───────────────────────────────────────
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

    if ($action === 'update_status') {
        $courierId = (int)($_POST['courier_id'] ?? 0);
        $newStatus = sanitize($_POST['new_status'] ?? '');
        $note      = sanitize($_POST['note'] ?? '');
        $allowed   = ['out_for_delivery', 'delivered', 'failed_delivery', 'returned'];

        if ($courierId <= 0 || !in_array($newStatus, $allowed)) {
            setFlash('danger', 'Invalid status update.');
            redirect('delivery.php');
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE couriers SET status=?, updated_at=NOW() WHERE id=? AND org_id=?")
                ->execute([$newStatus, $courierId, $orgId]);

            // Log the status update event
            $pdo->prepare("INSERT INTO courier_tracking_events (courier_id, status, note, created_at) VALUES (?,?,?,NOW())")
                ->execute([$courierId, $newStatus, $note]);

            $pdo->commit();
            setFlash('success', 'Delivery status updated to: ' . ucfirst(str_replace('_', ' ', $newStatus)));
            logActivity('update', 'courier', "Courier #{$courierId} → {$newStatus}");
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('delivery.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? 'out_for_delivery';
$fDate   = $_GET['date']   ?? date('Y-m-d');

$where  = 'c.org_id = ?';
$params = [$orgId];
if ($fStatus !== '') { $where .= ' AND c.status = ?'; $params[] = $fStatus; }
if ($fDate   !== '') { $where .= ' AND DATE(c.updated_at) = ?'; $params[] = $fDate; }

$deliveries = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, CONCAT(a.first_name,' ',a.last_name) AS agent_name
        FROM couriers c
        LEFT JOIN courier_agents a ON a.id = c.agent_id
        WHERE {$where}
        ORDER BY c.updated_at DESC
    ");
    $stmt->execute($params);
    $deliveries = $stmt->fetchAll();
} catch (Exception $e) {}

$outCount       = countRows('couriers', "org_id=? AND status='out_for_delivery'", [$orgId]);
$deliveredToday = countRows('couriers', "org_id=? AND status='delivered' AND DATE(updated_at)=CURDATE()", [$orgId]);
$failedToday    = countRows('couriers', "org_id=? AND status='failed_delivery' AND DATE(updated_at)=CURDATE()", [$orgId]);

$statusColors = [
    'out_for_delivery' => 'primary',
    'delivered'        => 'success',
    'failed_delivery'  => 'danger',
    'returned'         => 'warning',
    'pending'          => 'secondary',
    'processing'       => 'info',
];
$statusOptions = ['out_for_delivery', 'delivered', 'failed_delivery', 'returned'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-truck me-2" style="color:<?= $moduleColor ?>"></i>Delivery Management</h4>
    <p class="text-muted mb-0">Track last-mile delivery, update parcel status and record delivery outcomes</p>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(21,101,192,.12);color:#1565c0"><i class="fas fa-truck"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $outCount ?></div><div class="stat-label">Out for Delivery</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $deliveredToday ?></div><div class="stat-label">Delivered Today</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(231,76,60,.12);color:#e74c3c"><i class="fas fa-times-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $failedToday ?></div><div class="stat-label">Failed Today</div></div>
    </div>
  </div>
</div>

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Delivery Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['out_for_delivery','delivered','failed_delivery','returned','pending','processing'] as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Date</label>
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($fDate) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="delivery.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 data-table">
        <thead class="table-light">
          <tr><th>Tracking #</th><th>Recipient</th><th>Destination</th><th>Agent</th><th class="text-center">Status</th><th>Last Updated</th><th class="text-center">Update</th></tr>
        </thead>
        <tbody>
          <?php if (empty($deliveries)): ?>
          <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-truck fa-3x mb-3 d-block"></i>No deliveries found for selected filters.</td></tr>
          <?php else: foreach ($deliveries as $d): ?>
          <tr>
            <td class="fw-bold"><?= e($d['tracking_no']) ?></td>
            <td>
              <div class="fw-semibold"><?= e($d['recipient_name']) ?></div>
              <div class="small text-muted"><?= e($d['recipient_phone'] ?? '') ?></div>
            </td>
            <td class="small"><?= e($d['destination']) ?></td>
            <td class="small"><?= $d['agent_name'] ? e($d['agent_name']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-center"><span class="badge bg-<?= $statusColors[$d['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$d['status'])) ?></span></td>
            <td class="small text-muted"><?= $d['updated_at'] ? date('d M, h:i A', strtotime($d['updated_at'])) : '—' ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#statusModal"
                onclick="setUpdate(<?= $d['id'] ?>, '<?= e($d['tracking_no']) ?>')">
                <i class="fas fa-exchange-alt"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="courier_id" id="upCourierId">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Update Delivery Status</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3">Parcel: <strong id="upTrackingNo"></strong></p>
          <div class="mb-3">
            <label class="form-label fw-semibold">New Status <span class="text-danger">*</span></label>
            <select name="new_status" class="form-select" required>
              <?php foreach ($statusOptions as $so): ?>
              <option value="<?= $so ?>"><?= ucfirst(str_replace('_',' ',$so)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Note / Reason</label>
            <textarea name="note" class="form-control" rows="2" placeholder="e.g. Recipient not available, delivered to gate, etc."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function setUpdate(id, trackNo) {
    document.getElementById('upCourierId').value   = id;
    document.getElementById('upTrackingNo').textContent = trackNo;
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
