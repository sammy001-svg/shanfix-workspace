<?php
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
        $id          = (int)($_POST['id'] ?? 0);
        $name        = sanitize($_POST['name'] ?? '');
        $email       = sanitize($_POST['email'] ?? '');
        $phone       = sanitize($_POST['phone'] ?? '');
        $branchId    = (int)($_POST['branch_id'] ?? 0) ?: null;
        $serviceArea = sanitize($_POST['service_area'] ?? '');
        $bio         = sanitize($_POST['bio'] ?? '');
        $status      = $_POST['status'] === 'inactive' ? 'inactive' : 'active';

        // Photo upload
        $photo = '';
        if (!empty($_FILES['photo']['name'])) {
            $uploadDir = __DIR__ . '/../../uploads/courier/agents/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $fname = 'agent_' . time() . '_' . mt_rand(100,999) . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $fname)) {
                    $photo = $fname;
                }
            }
        }

        if ($id > 0) {
            $sql = "UPDATE courier_agents SET name=?, email=?, phone=?, branch_id=?, service_area=?, bio=?, status=?";
            $params = [$name, $email, $phone, $branchId, $serviceArea, $bio, $status];
            if ($photo !== '') { $sql .= ', photo=?'; $params[] = $photo; }
            $sql .= ' WHERE id=? AND org_id=?'; $params[] = $id; $params[] = $orgId;
            $pdo->prepare($sql)->execute($params);
            setFlash('success', 'Agent profile updated.');
        } else {
            $pdo->prepare("INSERT INTO courier_agents (org_id, name, email, phone, branch_id, service_area, photo, bio, status)
                VALUES (?,?,?,?,?,?,?,?,?)")->execute([$orgId, $name, $email, $phone, $branchId, $serviceArea, $photo, $bio, $status]);
            setFlash('success', "Agent '$name' added successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'courier', "Agent: $name");
        redirect('agents.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM courier_agents WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Agent removed.');
        redirect('agents.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$branches = [];
try {
    $st = $pdo->prepare("SELECT id,name FROM courier_branches WHERE org_id=? AND status='active' ORDER BY name");
    $st->execute([$orgId]);
    $branches = $st->fetchAll();
} catch (Exception $e) {}

$fStatus = $_GET['status'] ?? '';
$where   = 'a.org_id = ?';
$params  = [$orgId];
if ($fStatus !== '') { $where .= ' AND a.status = ?'; $params[] = $fStatus; }

$agentsList = [];
try {
    $stmt = $pdo->prepare("SELECT a.*, b.name AS branch_name,
        (SELECT COUNT(*) FROM couriers c WHERE c.agent_id = a.id AND c.org_id = a.org_id) AS total_assigned,
        (SELECT COUNT(*) FROM couriers c WHERE c.agent_id = a.id AND c.status = 'delivered' AND c.org_id = a.org_id) AS total_delivered
        FROM courier_agents a
        LEFT JOIN courier_branches b ON a.branch_id = b.id
        WHERE $where ORDER BY a.name ASC");
    $stmt->execute($params);
    $agentsList = $stmt->fetchAll();
} catch (Exception $e) {}

if (isset($_GET['fetch_agent'])) {
    $aid  = (int)$_GET['fetch_agent'];
    $stmt = $pdo->prepare("SELECT * FROM courier_agents WHERE id=? AND org_id=?");
    $stmt->execute([$aid, $orgId]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { header('Content-Type: application/json'); echo json_encode($row); exit; }
}

$totalAgents  = countRows('courier_agents', 'org_id = ?', [$orgId]);
$activeAgents = countRows('courier_agents', "org_id = ? AND status='active'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-user-tie me-2" style="color:<?= $moduleColor ?>"></i>Courier Agents</h4>
    <p class="text-muted mb-0">Manage delivery team profiles, branch assignments, and service areas</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#agentModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Agent</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-user-tie"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalAgents ?></div><div class="stat-label">Total Agents</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-user-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeAgents ?></div><div class="stat-label">Active Agents</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-user-times"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalAgents - $activeAgents ?></div><div class="stat-label">Inactive Agents</div></div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Agents</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="agents.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="row g-3">
  <?php if (empty($agentsList)): ?>
  <div class="col-12">
    <div class="card"><div class="card-body text-center text-muted py-5">
      <i class="fas fa-user-tie fa-2x mb-2 d-block"></i>No agents found.
    </div></div>
  </div>
  <?php else: foreach ($agentsList as $ag):
    $delivRate = $ag['total_assigned'] > 0 ? round(($ag['total_delivered'] / $ag['total_assigned']) * 100) : 0;
  ?>
  <div class="col-sm-6 col-xl-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex align-items-start mb-3">
          <div class="me-3">
            <?php if ($ag['photo']): ?>
            <img src="../../uploads/courier/agents/<?= e($ag['photo']) ?>" alt="<?= e($ag['name']) ?>" class="rounded-circle" style="width:60px;height:60px;object-fit:cover;">
            <?php else: ?>
            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="width:60px;height:60px;background:<?= $moduleColor ?>;font-size:22px">
              <?= strtoupper(substr($ag['name'], 0, 1)) ?>
            </div>
            <?php endif; ?>
          </div>
          <div class="flex-grow-1">
            <div class="fw-bold text-dark"><?= e($ag['name']) ?></div>
            <small class="text-muted"><i class="fas fa-phone me-1"></i><?= e($ag['phone'] ?: '—') ?></small><br>
            <small class="text-muted"><i class="fas fa-envelope me-1"></i><?= e($ag['email'] ?: '—') ?></small>
          </div>
          <span class="badge bg-<?= $ag['status'] === 'active' ? 'success' : 'secondary' ?>"><?= strtoupper($ag['status']) ?></span>
        </div>
        <div class="mb-2 small">
          <i class="fas fa-code-branch me-1 text-muted"></i><strong>Branch:</strong> <?= e($ag['branch_name'] ?? 'Unassigned') ?>
        </div>
        <?php if ($ag['service_area']): ?>
        <div class="mb-2 small text-muted"><i class="fas fa-map me-1"></i><?= e($ag['service_area']) ?></div>
        <?php endif; ?>
        <div class="mb-2 small">
          <div class="d-flex justify-content-between"><span>Deliveries: <?= $ag['total_delivered'] ?>/<?= $ag['total_assigned'] ?></span><span><?= $delivRate ?>%</span></div>
          <div class="progress" style="height:4px"><div class="progress-bar" style="width:<?= $delivRate ?>%;background:<?= $moduleColor ?>"></div></div>
        </div>
        <?php if ($ag['bio']): ?><p class="text-muted small mb-2"><?= e($ag['bio']) ?></p><?php endif; ?>
        <div class="d-flex gap-2 mt-2">
          <button class="btn btn-sm btn-outline-primary flex-grow-1" onclick="openEdit(<?= $ag['id'] ?>)"><i class="fas fa-edit me-1"></i>Edit</button>
          <button class="btn btn-sm btn-outline-danger" onclick="delAgent(<?= $ag['id'] ?>)"><i class="fas fa-trash"></i></button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Agent Modal -->
<div class="modal fade" id="agentModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="agentId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="agentModalTitle"><i class="fas fa-user-tie me-2"></i>Add Agent</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
        <input type="text" name="name" id="agentName" class="form-control" required placeholder="Agent full name">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Phone</label>
        <input type="text" name="phone" id="agentPhone" class="form-control" placeholder="+263...">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Email</label>
        <input type="email" name="email" id="agentEmail" class="form-control" placeholder="agent@courier.com">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Branch</label>
        <select name="branch_id" id="agentBranch" class="form-select">
          <option value="">No Branch</option>
          <?php foreach ($branches as $b): ?>
          <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Status</label>
        <select name="status" id="agentStatus" class="form-select">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Profile Photo</label>
        <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.webp">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Service Area / Routes</label>
        <input type="text" name="service_area" id="agentArea" class="form-control" placeholder="e.g. Harare CBD, Chitungwiza, Epworth">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Bio / Notes</label>
        <textarea name="bio" id="agentBio" class="form-control" rows="3" placeholder="Short description or notes about this agent..."></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Agent</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delAgentForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delAgentId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('agentModalTitle').innerHTML = '<i class="fas fa-user-tie me-2"></i>Add Agent';
  document.getElementById('agentId').value = '0';
  ['agentName','agentPhone','agentEmail','agentArea','agentBio'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('agentBranch').value = '';
  document.getElementById('agentStatus').value = 'active';
}
function openEdit(id) {
  fetch('agents.php?fetch_agent=' + id)
    .then(r => r.json())
    .then(d => {
      document.getElementById('agentModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Agent — ' + d.name;
      document.getElementById('agentId').value = d.id;
      document.getElementById('agentName').value = d.name || '';
      document.getElementById('agentPhone').value = d.phone || '';
      document.getElementById('agentEmail').value = d.email || '';
      document.getElementById('agentBranch').value = d.branch_id || '';
      document.getElementById('agentStatus').value = d.status || 'active';
      document.getElementById('agentArea').value = d.service_area || '';
      document.getElementById('agentBio').value = d.bio || '';
      new bootstrap.Modal(document.getElementById('agentModal')).show();
    });
}
function delAgent(id) {
  Swal.fire({
    title: 'Remove Agent?', text: 'This agent will be permanently deleted.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, remove'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delAgentId').value = id;
      document.getElementById('delAgentForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
