<?php
// ── Shopping Mall: Maintenance ────────────────────────────────
$moduleSlug  = 'shopping-mall';
$moduleName  = 'Shopping Mall';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'floors.php',         'icon' => 'fas fa-layer-group',    'label' => 'Floors'],
    ['url' => 'shops.php',          'icon' => 'fas fa-store',          'label' => 'Shops'],
    ['url' => 'tenants.php',        'icon' => 'fas fa-user-tie',       'label' => 'Tenants'],
    ['url' => 'leases.php',         'icon' => 'fas fa-file-contract',  'label' => 'Leases'],
    ['url' => 'payments.php',       'icon' => 'fas fa-money-check',    'label' => 'Rent Payments'],
    ['url' => 'service-charges.php','icon' => 'fas fa-file-invoice',   'label' => 'Service Charges'],
    ['url' => 'notices.php',        'icon' => 'fas fa-bullhorn',       'label' => 'Notices'],
    ['url' => 'maintenance.php',    'icon' => 'fas fa-tools',          'label' => 'Maintenance'],
    ['url' => 'utilities.php',      'icon' => 'fas fa-bolt',           'label' => 'Utilities'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $shopId      = (int)($_POST['shop_id'] ?? 0);
        $category    = sanitize($_POST['category'] ?? '');
        $title       = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $priority    = in_array($_POST['priority'] ?? '', ['low','normal','high','urgent']) ? $_POST['priority'] : 'normal';
        $reportedBy  = sanitize($_POST['reported_by'] ?? '');
        $cost        = (float)($_POST['cost'] ?? 0);
        $status      = in_array($_POST['status'] ?? '', ['open','in_progress','completed','closed']) ? $_POST['status'] : 'open';
        $scheduledDate = sanitize($_POST['scheduled_date'] ?? '') ?: null;

        if (!$title || !$category) {
            setFlash('danger', 'Title and category are required.');
            redirect('maintenance.php');
        }

        try {
            if ($id > 0) {
                $completedAt = ($status === 'completed') ? ', completed_at = IF(completed_at IS NULL, NOW(), completed_at)' : '';
                $pdo->prepare("UPDATE mall_maintenance SET shop_id=?, category=?, title=?, description=?, priority=?, reported_by=?, cost=?, status=?, scheduled_date=?, updated_at=NOW() {$completedAt} WHERE id=? AND org_id=?")
                    ->execute([$shopId ?: null, $category, $title, $description, $priority, $reportedBy, $cost, $status, $scheduledDate, $id, $orgId]);
                setFlash('success', 'Maintenance request updated.');
                logActivity('update', 'shopping-mall', "Maintenance #{$id} updated");
            } else {
                $seq = $pdo->query("SELECT COUNT(*) FROM mall_maintenance WHERE org_id=$orgId")->fetchColumn() + 1;
                $refNo = 'MNT-' . date('Y') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO mall_maintenance (org_id, ref_no, shop_id, category, title, description, priority, reported_by, cost, status, scheduled_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $refNo, $shopId ?: null, $category, $title, $description, $priority, $reportedBy, $cost, $status, $scheduledDate]);
                setFlash('success', "Maintenance request {$refNo} logged.");
                logActivity('create', 'shopping-mall', "Maintenance {$refNo} created");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('maintenance.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fStatus   = $_GET['status']   ?? '';
$fPriority = $_GET['priority'] ?? '';

$where  = 'm.org_id = ?';
$params = [$orgId];
if ($fStatus !== '') { $where .= ' AND m.status = ?'; $params[] = $fStatus; }
if ($fPriority !== '') { $where .= ' AND m.priority = ?'; $params[] = $fPriority; }

$requests = [];
$shops    = [];
try {
    $stmt = $pdo->prepare("
        SELECT m.*, s.shop_no, s.name AS shop_name
        FROM mall_maintenance m
        LEFT JOIN mall_shops s ON s.id = m.shop_id
        WHERE {$where}
        ORDER BY FIELD(m.priority,'urgent','high','normal','low'), m.created_at DESC
    ");
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, shop_no, name FROM mall_shops WHERE org_id=? ORDER BY shop_no");
    $stmt->execute([$orgId]);
    $shops = $stmt->fetchAll();
} catch (Exception $e) {}

$openCount      = countRows('mall_maintenance', "org_id=? AND status='open'",        [$orgId]);
$inProgressCount= countRows('mall_maintenance', "org_id=? AND status='in_progress'", [$orgId]);
$urgentCount    = countRows('mall_maintenance', "org_id=? AND priority='urgent' AND status NOT IN ('completed','closed')", [$orgId]);

$priorityColors = ['urgent' => 'danger', 'high' => 'warning', 'normal' => 'info', 'low' => 'secondary'];
$statusColors   = ['open' => 'danger', 'in_progress' => 'warning', 'completed' => 'success', 'closed' => 'secondary'];
$categories = ['Electrical', 'Plumbing', 'HVAC', 'Structural', 'Cleaning', 'Security', 'IT/Network', 'Lift/Escalator', 'Painting', 'Other'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-tools me-2" style="color:<?= $moduleColor ?>"></i>Maintenance Requests</h4>
    <p class="text-muted mb-0">Track repair requests, facility issues and scheduled maintenance work</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#mntModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Log Request
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(231,76,60,.12);color:#e74c3c"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $openCount ?></div><div class="stat-label">Open Requests</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-spinner"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $inProgressCount ?></div><div class="stat-label">In Progress</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(142,68,173,.12);color:#8e44ad"><i class="fas fa-fire"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $urgentCount ?></div><div class="stat-label">Urgent / Unresolved</div></div>
    </div>
  </div>
</div>

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['open','in_progress','completed','closed'] as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Priority</label>
        <select name="priority" class="form-select form-select-sm">
          <option value="">All Priorities</option>
          <?php foreach (['urgent','high','normal','low'] as $p): ?>
          <option value="<?= $p ?>" <?= $fPriority === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="maintenance.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 data-table">
        <thead class="table-light">
          <tr><th>Ref #</th><th>Title</th><th>Shop</th><th>Category</th><th class="text-center">Priority</th><th class="text-center">Status</th><th class="text-end">Cost</th><th>Scheduled</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($requests)): ?>
          <tr><td colspan="9" class="text-center py-5 text-muted"><i class="fas fa-tools fa-3x mb-3 d-block"></i>No maintenance requests found.</td></tr>
          <?php else: foreach ($requests as $r): ?>
          <tr>
            <td class="fw-semibold small"><?= e($r['ref_no']) ?></td>
            <td>
              <div class="fw-semibold"><?= e($r['title']) ?></div>
              <?php if ($r['reported_by']): ?><div class="small text-muted">By: <?= e($r['reported_by']) ?></div><?php endif; ?>
            </td>
            <td class="small"><?= $r['shop_name'] ? e($r['shop_no'] . ' – ' . $r['shop_name']) : '<span class="text-muted">Common Area</span>' ?></td>
            <td><span class="badge bg-light text-dark border"><?= e($r['category']) ?></span></td>
            <td class="text-center"><span class="badge bg-<?= $priorityColors[$r['priority']] ?? 'secondary' ?>"><?= ucfirst($r['priority']) ?></span></td>
            <td class="text-center"><span class="badge bg-<?= $statusColors[$r['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
            <td class="text-end"><?= $r['cost'] > 0 ? formatCurrency((float)$r['cost']) : '<span class="text-muted">—</span>' ?></td>
            <td class="small text-muted"><?= $r['scheduled_date'] ? date('d M Y', strtotime($r['scheduled_date'])) : '—' ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Maintenance Modal -->
<div class="modal fade" id="mntModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="mntId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="mntTitle"><i class="fas fa-tools me-2"></i>Log Maintenance Request</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Title / Issue <span class="text-danger">*</span></label>
              <input type="text" name="title" id="mntTitle2" class="form-control" required maxlength="255" placeholder="Brief description of the issue">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
              <select name="category" id="mntCat" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>"><?= $cat ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Shop / Location</label>
              <select name="shop_id" id="mntShop" class="form-select">
                <option value="">Common Area / General</option>
                <?php foreach ($shops as $sh): ?>
                <option value="<?= $sh['id'] ?>"><?= e($sh['shop_no'] . ' – ' . $sh['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Priority</label>
              <select name="priority" id="mntPriority" class="form-select">
                <option value="normal">Normal</option>
                <option value="low">Low</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="mntStatus" class="form-select">
                <option value="open">Open</option>
                <option value="in_progress">In Progress</option>
                <option value="completed">Completed</option>
                <option value="closed">Closed</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Reported By</label>
              <input type="text" name="reported_by" id="mntReportedBy" class="form-control" maxlength="100" placeholder="Name of person reporting">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Estimated Cost (KES)</label>
              <input type="number" name="cost" id="mntCost" class="form-control" min="0" step="0.01" value="0">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Scheduled Date</label>
              <input type="date" name="scheduled_date" id="mntScheduled" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" id="mntDesc" class="form-control" rows="3" placeholder="Detailed description of the problem…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function openAdd() {
    document.getElementById('mntTitle').innerHTML = '<i class="fas fa-tools me-2"></i>Log Maintenance Request';
    document.getElementById('mntId').value = '0';
    ['mntTitle2','mntDesc','mntReportedBy','mntScheduled'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('mntShop').value     = '';
    document.getElementById('mntCat').value      = '';
    document.getElementById('mntPriority').value = 'normal';
    document.getElementById('mntStatus').value   = 'open';
    document.getElementById('mntCost').value     = '0';
}
function openEdit(r) {
    document.getElementById('mntTitle').innerHTML    = '<i class="fas fa-edit me-2"></i>Edit Maintenance Request';
    document.getElementById('mntId').value           = r.id;
    document.getElementById('mntTitle2').value       = r.title || '';
    document.getElementById('mntCat').value          = r.category || '';
    document.getElementById('mntShop').value         = r.shop_id || '';
    document.getElementById('mntPriority').value     = r.priority || 'normal';
    document.getElementById('mntStatus').value       = r.status || 'open';
    document.getElementById('mntReportedBy').value   = r.reported_by || '';
    document.getElementById('mntCost').value         = r.cost || '0';
    document.getElementById('mntScheduled').value    = r.scheduled_date || '';
    document.getElementById('mntDesc').value         = r.description || '';
    new bootstrap.Modal(document.getElementById('mntModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
