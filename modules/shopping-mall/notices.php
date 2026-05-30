<?php
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
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];

    // Ensure notices table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS mall_notices (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        org_id      INT NOT NULL,
        title       VARCHAR(255) NOT NULL,
        body        TEXT,
        type        ENUM('general','urgent','maintenance','billing','event') DEFAULT 'general',
        audience    ENUM('all','specific_floor') DEFAULT 'all',
        floor_id    INT DEFAULT NULL,
        posted_by   INT,
        status      ENUM('active','archived') DEFAULT 'active',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int)($_POST['id']       ?? 0);
        $title    = sanitize($_POST['title'] ?? '');
        $body     = sanitize($_POST['body']  ?? '');
        $type     = in_array($_POST['type'] ?? '', ['general','urgent','maintenance','billing','event']) ? $_POST['type'] : 'general';
        $audience = in_array($_POST['audience'] ?? '', ['all','specific_floor']) ? $_POST['audience'] : 'all';
        $floorId  = (int)($_POST['floor_id'] ?? 0) ?: null;

        if (!$title) { setFlash('danger', 'Title is required.'); redirect('notices.php'); }

        if ($id > 0) {
            $pdo->prepare("UPDATE mall_notices SET title=?,body=?,type=?,audience=?,floor_id=? WHERE id=? AND org_id=?")
                ->execute([$title, $body, $type, $audience, $floorId, $id, $orgId]);
            setFlash('success', 'Notice updated.');
            logActivity('update', 'shopping-mall', "Updated notice: $title");
        } else {
            $pdo->prepare("INSERT INTO mall_notices (org_id,title,body,type,audience,floor_id,posted_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$orgId, $title, $body, $type, $audience, $floorId, $user['id']]);
            setFlash('success', 'Notice posted successfully.');
            logActivity('create', 'shopping-mall', "Posted notice: $title");
        }
        redirect('notices.php');
    }

    if ($action === 'archive') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE mall_notices SET status='archived' WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Notice archived.');
        redirect('notices.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM mall_notices WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Notice deleted.');
        redirect('notices.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Ensure table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mall_notices (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        org_id      INT NOT NULL,
        title       VARCHAR(255) NOT NULL,
        body        TEXT,
        type        ENUM('general','urgent','maintenance','billing','event') DEFAULT 'general',
        audience    ENUM('all','specific_floor') DEFAULT 'all',
        floor_id    INT DEFAULT NULL,
        posted_by   INT,
        status      ENUM('active','archived') DEFAULT 'active',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
} catch (Exception $e) {}

$filterStatus = $_GET['status'] ?? 'active';

$notices = [];
try {
    $stmt = $pdo->prepare("
        SELECT n.*, f.name AS floor_name, u.name AS posted_by_name
        FROM mall_notices n
        LEFT JOIN mall_floors f ON n.floor_id=f.id
        LEFT JOIN users u ON n.posted_by=u.id
        WHERE n.org_id=? AND n.status=?
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$orgId, $filterStatus]);
    $notices = $stmt->fetchAll();
} catch (Exception $e) {}

$floors = [];
try {
    $stmt = $pdo->prepare("SELECT id,name FROM mall_floors WHERE org_id=? ORDER BY level,name");
    $stmt->execute([$orgId]); $floors = $stmt->fetchAll();
} catch (Exception $e) {}

$activeCount   = 0; $urgentCount = 0; $archivedCount = 0;
try {
    $stmt = $pdo->prepare("SELECT status, type, COUNT(*) c FROM mall_notices WHERE org_id=? GROUP BY status, type");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $r) {
        if ($r['status'] === 'active')   $activeCount++;
        if ($r['status'] === 'archived') $archivedCount++;
        if ($r['type'] === 'urgent' && $r['status'] === 'active') $urgentCount += (int)$r['c'];
    }
} catch (Exception $e) {}

$typeMeta = [
    'general'     => ['label' => 'General',     'color' => '#0B2D4E', 'icon' => 'fa-info-circle'],
    'urgent'      => ['label' => 'Urgent',       'color' => '#e74c3c', 'icon' => 'fa-exclamation-triangle'],
    'maintenance' => ['label' => 'Maintenance',  'color' => '#f39c12', 'icon' => 'fa-tools'],
    'billing'     => ['label' => 'Billing',      'color' => '#27ae60', 'icon' => 'fa-file-invoice-dollar'],
    'event'       => ['label' => 'Event',        'color' => '#8e44ad', 'icon' => 'fa-calendar-check'],
];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bullhorn me-2" style="color:<?= $moduleColor ?>"></i>Tenant Notices</h4>
    <p class="text-muted mb-0">Post notices and communications to tenants</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#noticeModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Post Notice
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-bullhorn"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Active Notices</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $urgentCount ?></div><div class="stat-label">Urgent Notices</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-archive"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $archivedCount ?></div><div class="stat-label">Archived</div></div>
    </div>
  </div>
</div>

<!-- Status tabs -->
<div class="mb-3">
  <a href="notices.php?status=active"   class="btn btn-sm <?= $filterStatus === 'active'   ? 'btn-primary' : 'btn-outline-secondary' ?> me-1">Active</a>
  <a href="notices.php?status=archived" class="btn btn-sm <?= $filterStatus === 'archived' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Archived</a>
</div>

<!-- Notices grid -->
<?php if (empty($notices)): ?>
<div class="card">
  <div class="card-body text-center text-muted py-5">
    <i class="fas fa-bullhorn fa-3x mb-3 d-block opacity-25"></i>
    <p class="mb-0">No <?= $filterStatus ?> notices. Post one to communicate with tenants.</p>
  </div>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($notices as $n):
    $tm = $typeMeta[$n['type'] ?? 'general'] ?? $typeMeta['general'];
  ?>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100" style="border-top:4px solid <?= $tm['color'] ?>">
      <div class="card-body">
        <div class="d-flex align-items-start gap-2 mb-2">
          <div class="d-flex align-items-center justify-content-center rounded-3 flex-shrink-0"
               style="width:36px;height:36px;background:<?= $tm['color'] ?>1a;color:<?= $tm['color'] ?>">
            <i class="fas <?= $tm['icon'] ?> small"></i>
          </div>
          <div class="flex-fill">
            <div class="fw-bold"><?= e($n['title']) ?></div>
            <div class="d-flex gap-1 mt-1 flex-wrap">
              <span class="badge" style="background:<?= $tm['color'] ?>;font-size:.65rem"><?= $tm['label'] ?></span>
              <?php if ($n['audience'] === 'specific_floor' && $n['floor_name']): ?>
                <span class="badge bg-secondary" style="font-size:.65rem"><i class="fas fa-layer-group me-1"></i><?= e($n['floor_name']) ?></span>
              <?php else: ?>
                <span class="badge bg-info text-dark" style="font-size:.65rem">All Tenants</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php if ($n['body']): ?>
          <p class="small text-muted mb-2" style="line-height:1.5"><?= e(mb_substr($n['body'], 0, 160)) . (mb_strlen($n['body']) > 160 ? '…' : '') ?></p>
        <?php endif; ?>
        <div class="small text-muted">
          <i class="fas fa-user me-1"></i><?= e($n['posted_by_name'] ?? 'Admin') ?>
          &nbsp;·&nbsp;
          <i class="fas fa-clock me-1"></i><?= formatDate($n['created_at']) ?>
        </div>
      </div>
      <div class="card-footer bg-transparent py-2 d-flex gap-1">
        <button class="btn btn-sm btn-outline-primary flex-fill" onclick='openEdit(<?= htmlspecialchars(json_encode($n), ENT_QUOTES) ?>)'>
          <i class="fas fa-edit me-1"></i>Edit
        </button>
        <?php if ($n['status'] === 'active'): ?>
        <form method="POST" class="flex-fill" onsubmit="return confirm('Archive this notice?')">
          <?= csrfField() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?= $n['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary w-100"><i class="fas fa-archive me-1"></i>Archive</button>
        </form>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-danger" onclick="delNotice(<?= $n['id'] ?>)"><i class="fas fa-trash"></i></button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal -->
<div class="modal fade" id="noticeModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="notId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="notTitle"><i class="fas fa-bullhorn me-2"></i>Post Notice</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="notTitleIn" class="form-control" required placeholder="e.g. Mall Closure Notice — Public Holiday">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Notice Type</label>
              <select name="type" id="notType" class="form-select">
                <?php foreach ($typeMeta as $k => $tm): ?>
                  <option value="<?= $k ?>"><?= $tm['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Audience</label>
              <select name="audience" id="notAudience" class="form-select" onchange="toggleFloor(this.value)">
                <option value="all">All Tenants</option>
                <option value="specific_floor">Specific Floor</option>
              </select>
            </div>
            <div class="col-12" id="floorRow" style="display:none">
              <label class="form-label fw-semibold">Floor</label>
              <select name="floor_id" id="notFloor" class="form-select">
                <option value="">-- Select floor --</option>
                <?php foreach ($floors as $f): ?>
                  <option value="<?= $f['id'] ?>"><?= e($f['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Message Body</label>
              <textarea name="body" id="notBody" class="form-control" rows="5" placeholder="Write the notice content here…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-paper-plane me-1"></i>Post Notice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete form -->
<form method="POST" id="delForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function toggleFloor(v) {
  document.getElementById('floorRow').style.display = v === 'specific_floor' ? '' : 'none';
}
function openAdd() {
  document.getElementById('notTitle').innerHTML = '<i class="fas fa-bullhorn me-2"></i>Post Notice';
  document.getElementById('notId').value      = 0;
  document.getElementById('notTitleIn').value = '';
  document.getElementById('notType').value    = 'general';
  document.getElementById('notAudience').value= 'all';
  document.getElementById('notBody').value    = '';
  document.getElementById('notFloor') && (document.getElementById('notFloor').value = '');
  toggleFloor('all');
}
function openEdit(n) {
  document.getElementById('notTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Notice';
  document.getElementById('notId').value      = n.id;
  document.getElementById('notTitleIn').value = n.title || '';
  document.getElementById('notType').value    = n.type || 'general';
  document.getElementById('notAudience').value= n.audience || 'all';
  document.getElementById('notBody').value    = n.body || '';
  if (document.getElementById('notFloor')) document.getElementById('notFloor').value = n.floor_id || '';
  toggleFloor(n.audience || 'all');
  new bootstrap.Modal(document.getElementById('noticeModal')).show();
}
function delNotice(id) {
  Swal.fire({ title:'Delete this notice?', icon:'warning', showCancelButton:true,
    confirmButtonColor:'#e74c3c', confirmButtonText:'Delete'
  }).then(r => { if (r.isConfirmed) { document.getElementById('delId').value=id; document.getElementById('delForm').submit(); } });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
