<?php
// ── MEETINGS: Participants / External Contacts ─────────────────
$moduleSlug  = 'meetings';
$moduleName  = 'Meeting Management';
$moduleIcon  = 'fas fa-video';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'meetings.php',     'icon' => 'fas fa-video',          'label' => 'Meetings'],
    ['url' => 'minutes.php',      'icon' => 'fas fa-file-alt',       'label' => 'Minutes'],
    ['url' => 'actions.php',      'icon' => 'fas fa-tasks',          'label' => 'Action Items'],
    ['url' => 'participants.php', 'icon' => 'fas fa-address-book',   'label' => 'Participants'],
    ['url' => 'calendar.php',     'icon' => 'fas fa-calendar',       'label' => 'Calendar'],
    ['url' => 'agenda.php',      'icon' => 'fas fa-list-ul',         'label' => 'Agenda'],
    ['url' => 'recordings.php',  'icon' => 'fas fa-microphone',      'label' => 'Recordings'],
    ['url' => 'documents.php',   'icon' => 'fas fa-folder-open',     'label' => 'Documents'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
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
        $id           = (int)($_POST['id']           ?? 0);
        $name         = sanitize($_POST['name']         ?? '');
        $email        = sanitize($_POST['email']        ?? '');
        $phone        = sanitize($_POST['phone']        ?? '');
        $organization = sanitize($_POST['organization'] ?? '');
        $role         = sanitize($_POST['role']         ?? '');
        $notes        = sanitize($_POST['notes']        ?? '');
        $status       = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if (!$name) {
            setFlash('danger', 'Contact name is required.');
            redirect('participants.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE meeting_contacts SET name=?,email=?,phone=?,organization=?,role=?,notes=?,status=? WHERE id=? AND org_id=?")
                ->execute([$name, $email, $phone, $organization, $role, $notes, $status, $id, $orgId]);
            setFlash('success', 'Contact updated.');
            logActivity('update', 'meetings', "Updated contact: $name");
        } else {
            $pdo->prepare("INSERT INTO meeting_contacts (org_id,name,email,phone,organization,role,notes,status) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $name, $email, $phone, $organization, $role, $notes, $status]);
            setFlash('success', "Contact '$name' added.");
            logActivity('create', 'meetings', "Added contact: $name");
        }
        redirect('participants.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM meeting_contacts WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Contact deleted.');
        logActivity('delete', 'meetings', "Deleted contact #$id");
        redirect('participants.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterStatus = $_GET['status'] ?? '';
$search       = sanitize($_GET['q'] ?? '');
$where  = 'org_id = ?';
$params = [$orgId];
if ($filterStatus) { $where .= ' AND status = ?'; $params[] = $filterStatus; }
if ($search)       { $where .= ' AND (name LIKE ? OR email LIKE ? OR organization LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

$contacts = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM meeting_contacts WHERE $where ORDER BY name ASC");
    $stmt->execute($params);
    $contacts = $stmt->fetchAll();
} catch (Exception $e) {}

$totalCount    = countRows('meeting_contacts', 'org_id=?', [$orgId]);
$activeCount   = countRows('meeting_contacts', "org_id=? AND status='active'", [$orgId]);
$inactiveCount = countRows('meeting_contacts', "org_id=? AND status='inactive'", [$orgId]);

// Count unique organizations
$orgCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT organization) FROM meeting_contacts WHERE org_id=? AND organization != ''");
    $stmt->execute([$orgId]);
    $orgCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-address-book me-2" style="color:<?= $moduleColor ?>"></i>Meeting Participants</h4>
    <p class="text-muted mb-0">Manage your external contacts and regular meeting participants</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#contactModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Add Contact
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-address-book"></i></div>
      <div><div class="stat-value"><?= $totalCount ?></div><div class="stat-label">Total Contacts</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-user-check"></i></div>
      <div><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Active</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-user-clock"></i></div>
      <div><div class="stat-value"><?= $inactiveCount ?></div><div class="stat-label">Inactive</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(11,45,78,.12);color:#0B2D4E"><i class="fas fa-building"></i></div>
      <div><div class="stat-value"><?= $orgCount ?></div><div class="stat-label">Organizations</div></div></div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-5 col-md-4">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, email, organization..." value="<?= e($search) ?>">
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="active"   <?= $filterStatus==='active'   ?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $filterStatus==='inactive' ?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="participants.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-address-book me-2" style="color:<?= $moduleColor ?>"></i>Contact Directory</h6>
    <span class="badge bg-secondary"><?= count($contacts) ?> contacts</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Name</th>
            <th>Organization</th>
            <th>Role / Title</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($contacts)): ?>
          <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-address-book fa-2x mb-2 d-block"></i>No contacts found.</td></tr>
          <?php else: foreach ($contacts as $c): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($c['name']) ?></div>
              <?php if ($c['notes']): ?><div class="small text-muted"><?= e(mb_strimwidth($c['notes'], 0, 50, '…')) ?></div><?php endif; ?>
            </td>
            <td class="small"><?= e($c['organization'] ?: '—') ?></td>
            <td class="small text-muted"><?= e($c['role'] ?: '—') ?></td>
            <td>
              <?php if ($c['email']): ?>
                <a href="mailto:<?= e($c['email']) ?>" class="small text-decoration-none"><?= e($c['email']) ?></a>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= e($c['phone'] ?: '—') ?></td>
            <td><?= statusBadge($c['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this contact?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="contactModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="ctcId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="ctcTitle"><i class="fas fa-address-book me-2"></i>Add Contact</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="ctcName" class="form-control" required placeholder="Contact name">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Organization</label>
              <input type="text" name="organization" id="ctcOrg" class="form-control" placeholder="Company or institution">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="ctcEmail" class="form-control" placeholder="email@example.com">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="ctcPhone" class="form-control" placeholder="+254...">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Role / Title</label>
              <input type="text" name="role" id="ctcRole" class="form-control" placeholder="e.g. Director, Manager">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="ctcStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="ctcNotes" class="form-control" rows="2" placeholder="Any additional notes..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Contact</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("ctcTitle").innerHTML = "<i class=\"fas fa-address-book me-2\"></i>Add Contact";
  document.getElementById("ctcId").value     = 0;
  document.getElementById("ctcName").value   = "";
  document.getElementById("ctcOrg").value    = "";
  document.getElementById("ctcEmail").value  = "";
  document.getElementById("ctcPhone").value  = "";
  document.getElementById("ctcRole").value   = "";
  document.getElementById("ctcStatus").value = "active";
  document.getElementById("ctcNotes").value  = "";
}
function openEdit(c) {
  document.getElementById("ctcTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Contact";
  document.getElementById("ctcId").value     = c.id;
  document.getElementById("ctcName").value   = c.name         || "";
  document.getElementById("ctcOrg").value    = c.organization || "";
  document.getElementById("ctcEmail").value  = c.email        || "";
  document.getElementById("ctcPhone").value  = c.phone        || "";
  document.getElementById("ctcRole").value   = c.role         || "";
  document.getElementById("ctcStatus").value = c.status       || "active";
  document.getElementById("ctcNotes").value  = c.notes        || "";
  new bootstrap.Modal(document.getElementById("contactModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
