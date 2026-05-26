<?php
$moduleSlug = 'salon';
$moduleName = 'Salon & Spa';
$moduleIcon = 'fas fa-cut';
$moduleColor = '#c0392b';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'appointments.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Appointments'],
    ['url' => 'clients.php',      'icon' => 'fas fa-users',          'label' => 'Clients'],
    ['url' => 'services.php',     'icon' => 'fas fa-concierge-bell', 'label' => 'Services'],
    ['url' => 'staff.php',        'icon' => 'fas fa-user-tie',       'label' => 'Staff'],
    ['url' => 'packages.php',     'icon' => 'fas fa-gift',           'label' => 'Packages'],
    ['url' => 'inventory.php',    'icon' => 'fas fa-boxes',          'label' => 'Inventory'],
    ['url' => 'loyalty.php',      'icon' => 'fas fa-star',           'label' => 'Loyalty'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $gender = in_array($_POST['gender'] ?? '', ['male', 'female', 'other']) ? $_POST['gender'] : 'male';
        $dob = $_POST['dob'] ?? null;
        $notes = sanitize($_POST['notes'] ?? '');

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE salon_clients SET name = ?, phone = ?, email = ?, gender = ?, dob = ?, notes = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$name, $phone, $email, $gender, $dob ?: null, $notes, $id, $orgId]);
            setFlash('success', 'Client updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO salon_clients (org_id, name, phone, email, gender, dob, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $name, $phone, $email, $gender, $dob ?: null, $notes]);
            setFlash('success', "Client '$name' added successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'salon', "Client: $name");
        redirect('clients.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM salon_clients WHERE id = ? AND org_id = ?");
        $stmt->execute([$id, $orgId]);
        setFlash('success', 'Client deleted successfully.');
        redirect('clients.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fQ = trim($_GET['q'] ?? '');
$where = 'org_id = ?';
$params = [$orgId];

if ($fQ !== '') {
    $where .= ' AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)';
    $like = "%$fQ%";
    array_push($params, $like, $like, $like);
}

$clients = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM salon_clients WHERE $where ORDER BY name ASC");
    $stmt->execute($params);
    $clients = $stmt->fetchAll();
} catch (Exception $e) {}

$totalClients = countRows('salon_clients', 'org_id = ?', [$orgId]);
$maleClients = countRows('salon_clients', "org_id = ? AND gender = 'male'", [$orgId]);
$femaleClients = countRows('salon_clients', "org_id = ? AND gender = 'female'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Clients</h4>
    <p class="text-muted mb-0">Manage customer records and details</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#cModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Add Client</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalClients ?></div>
        <div class="stat-label">Total Clients</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon info-bg"><i class="fas fa-mars"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $maleClients ?></div>
        <div class="stat-label">Male Clients</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg" style="background-color:rgba(192,57,43,0.1);color:#c0392b"><i class="fas fa-venus"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $femaleClients ?></div>
        <div class="stat-label">Female Clients</div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-8">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, phone, or email…" value="<?= e($fQ) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="clients.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Client List</h6>
    <span class="badge bg-secondary"><?= count($clients) ?> clients</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Name</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Gender</th>
            <th>DOB</th>
            <th>Notes</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($clients)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No clients found.</td></tr>
          <?php else: foreach ($clients as $c): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div style="width:34px;height:34px;border-radius:50%;background:<?= $moduleColor ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:600;flex-shrink:0">
                  <?= strtoupper(substr($c['name'], 0, 2)) ?>
                </div>
                <div class="fw-semibold"><?= e($c['name']) ?></div>
              </div>
            </td>
            <td><?= e($c['phone'] ?? '—') ?></td>
            <td><?= e($c['email'] ?? '—') ?></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst($c['gender'] ?? '—') ?></span></td>
            <td><?= $c['dob'] ? formatDate($c['dob']) : '—' ?></td>
            <td class="small text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($c['notes'] ?? '—') ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delClient(<?= $c['id'] ?>, '<?= e($c['name']) ?>')"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="cModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="cId" value="0">
  <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
    <h5 class="modal-title" id="cTitle"><i class="fas fa-users me-2"></i>Add Client</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Client Name <span class="text-danger">*</span></label>
        <input type="text" name="name" id="cName" class="form-control" required maxlength="255">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label>
        <input type="text" name="phone" id="cPhone" class="form-control" required maxlength="25">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Email</label>
        <input type="email" name="email" id="cEmail" class="form-control" maxlength="255">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Gender</label>
        <select name="gender" id="cGender" class="form-select">
          <option value="male">Male</option>
          <option value="female">Female</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Date of Birth</label>
        <input type="date" name="dob" id="cDob" class="form-control">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Notes / Allergies / Preferences</label>
        <textarea name="notes" id="cNotes" class="form-control" rows="3" placeholder="Styling preferences, chemical sensitivities, hair types…"></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Client</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delCForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delCId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('cTitle').innerHTML = '<i class="fas fa-users me-2"></i>Add Client';
  ['cId', 'cName', 'cPhone', 'cEmail', 'cDob', 'cNotes'].forEach(i => document.getElementById(i).value = i === 'cId' ? '0' : '');
  document.getElementById('cGender').value = 'male';
}
function openEdit(c) {
  document.getElementById('cTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Client';
  document.getElementById('cId').value = c.id;
  document.getElementById('cName').value = c.name || '';
  document.getElementById('cPhone').value = c.phone || '';
  document.getElementById('cEmail').value = c.email || '';
  document.getElementById('cGender').value = c.gender || 'male';
  document.getElementById('cDob').value = c.dob || '';
  document.getElementById('cNotes').value = c.notes || '';
  new bootstrap.Modal(document.getElementById('cModal')).show();
}
function delClient(id, name) {
  Swal.fire({
    title: 'Delete Client?',
    text: name + ' and their entire appointment history will be removed.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delCId').value = id;
      document.getElementById('delCForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
