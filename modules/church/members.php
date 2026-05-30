<?php
$moduleSlug  = 'church';
$moduleName  = 'Church Management';
$moduleIcon  = 'fas fa-church';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt',     'label' => 'Dashboard'],
    ['url' => 'members.php',   'icon' => 'fas fa-users',              'label' => 'Members'],
    ['url' => 'offerings.php', 'icon' => 'fas fa-hand-holding-heart', 'label' => 'Offerings'],
    ['url' => 'expenses.php',  'icon' => 'fas fa-money-bill-wave',    'label' => 'Expenses'],
    ['url' => 'attendance.php','icon' => 'fas fa-clipboard-check',    'label' => 'Attendance'],
    ['url' => 'sermons.php',   'icon' => 'fas fa-bible',              'label' => 'Sermons'],
    ['url' => 'prayers.php',   'icon' => 'fas fa-praying-hands',      'label' => 'Prayer Requests'],
    ['url' => 'pastoral.php',  'icon' => 'fas fa-hands-helping',      'label' => 'Pastoral Care'],
    ['url' => 'events.php',    'icon' => 'fas fa-calendar-alt',       'label' => 'Events'],
    ['url' => 'cells.php',     'icon' => 'fas fa-home',               'label' => 'Cells / Groups'],
    ['url' => 'pledges.php',   'icon' => 'fas fa-handshake',          'label' => 'Pledges'],
    ['url' => 'projects.php',  'icon' => 'fas fa-project-diagram',    'label' => 'Projects'],
    ['url' => 'notices.php',   'icon' => 'fas fa-bell',               'label' => 'Notices'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $memberNo = sanitize($_POST['member_no'] ?? '');
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $gender = in_array($_POST['gender'] ?? '', ['male', 'female']) ? $_POST['gender'] : 'male';
        $dob = $_POST['dob'] ?? null;
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $maritalStatus = sanitize($_POST['marital_status'] ?? 'Single');
        $cellGroup = sanitize($_POST['cell_group'] ?? 'Main');
        $department = sanitize($_POST['department'] ?? 'Choir');
        $baptized = isset($_POST['baptized']) ? 1 : 0;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'visitor']) ? $_POST['status'] : 'active';
        $joinedAt = $_POST['joined_at'] ?? date('Y-m-d');

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE church_members SET member_no = ?, first_name = ?, last_name = ?, gender = ?, dob = ?, phone = ?, email = ?, address = ?, marital_status = ?, cell_group = ?, department = ?, baptized = ?, status = ?, joined_at = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$memberNo, $firstName, $lastName, $gender, $dob, $phone, $email, $address, $maritalStatus, $cellGroup, $department, $baptized, $status, $joinedAt, $id, $orgId]);
            setFlash('success', 'Church member profile updated.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO church_members (org_id, member_no, first_name, last_name, gender, dob, phone, email, address, marital_status, cell_group, department, baptized, status, joined_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $memberNo, $firstName, $lastName, $gender, $dob, $phone, $email, $address, $maritalStatus, $cellGroup, $department, $baptized, $status, $joinedAt]);
            setFlash('success', "Member '$firstName $lastName' registered successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'church', "Member Profile: $firstName $lastName (No: $memberNo)");
        redirect('members.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM church_members WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Member profile deleted from registry.');
        redirect('members.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';
$fGroup = $_GET['cell_group'] ?? '';
$where = 'org_id = ?';
$params = [$orgId];

if ($fStatus !== '') {
    $where .= ' AND status = ?';
    $params[] = $fStatus;
}
if ($fGroup !== '') {
    $where .= ' AND cell_group = ?';
    $params[] = $fGroup;
}

$membersList = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM church_members WHERE $where ORDER BY first_name ASC");
    $stmt->execute($params);
    $membersList = $stmt->fetchAll();
} catch (Exception $e) {}

// Get unique cell groups for filters
$cellGroups = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT cell_group FROM church_members WHERE org_id = ? AND cell_group != '' ORDER BY cell_group ASC");
    $stmt->execute([$orgId]);
    $cellGroups = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $mid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM church_members WHERE id = ? AND org_id = ?");
        $stmt->execute([$mid, $orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            header('Content-Type: application/json');
            echo json_encode($row);
            exit;
        }
    } catch (Exception $e) {}
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Church Members Registry</h4>
    <p class="text-muted mb-0">Record church members, assign cell fellowships, track ministerial departments, and baptism records</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#memberModal" onclick="openAdd()"><i class="fas fa-user-plus me-2"></i>Register Member</button>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Filter by Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Active Member</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          <option value="visitor" <?= $fStatus === 'visitor' ? 'selected' : '' ?>>Visitor</option>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Filter by Cell Fellowship</label>
        <select name="cell_group" class="form-select form-select-sm">
          <option value="">All Cell Groups</option>
          <?php foreach ($cellGroups as $cg): ?>
          <option value="<?= e($cg) ?>" <?= $fGroup === $cg ? 'selected' : '' ?>><?= e($cg) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="members.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-users me-2 text-primary"></i>Congregation Directory</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Member Name</th>
            <th>Member No</th>
            <th>Gender / Age</th>
            <th>Contacts Details</th>
            <th>Marital Status</th>
            <th>Cell & Department</th>
            <th>Baptism</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($membersList)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-user-friends fa-2x mb-2 d-block"></i>No congregation members registered.</td></tr>
          <?php else: foreach ($membersList as $m): 
            $age = $m['dob'] ? date_diff(date_create($m['dob']), date_create('today'))->y . ' yrs' : '—';
          ?>
          <tr>
            <td>
              <div class="fw-bold text-dark fs-6"><?= e($m['first_name'] . ' ' . $m['last_name']) ?></div>
              <small class="text-muted">Joined: <?= formatDate($m['joined_at']) ?></small>
            </td>
            <td class="fw-semibold text-dark"><span class="badge bg-light text-dark border"><?= e($m['member_no']) ?></span></td>
            <td>
              <div class="fw-semibold"><?= ucfirst($m['gender']) ?></div>
              <small class="text-muted">Age: <?= $age ?></small>
            </td>
            <td>
              <div><i class="fas fa-phone text-muted me-1 small"></i><?= e($m['phone']) ?></div>
              <small class="text-muted"><i class="fas fa-envelope me-1 small"></i><?= e($m['email'] ?: '—') ?></small>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($m['marital_status']) ?></span></td>
            <td>
              <div class="small fw-bold text-dark"><i class="fas fa-home me-1 text-muted"></i><?= e($m['cell_group'] ?: 'Main') ?></div>
              <small class="text-muted"><i class="fas fa-users-cog me-1"></i><?= e($m['department'] ?: 'Choir') ?></small>
            </td>
            <td>
              <?php if ($m['baptized']): ?>
              <span class="badge bg-success"><i class="fas fa-water me-1"></i>Baptized</span>
              <?php else: ?>
              <span class="badge bg-secondary">Unbaptized</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-<?= $m['status'] === 'active' ? 'success' : ($m['status'] === 'visitor' ? 'info' : 'secondary') ?>">
                <?= ucfirst($m['status']) ?>
              </span>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $m['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delMember(<?= $m['id'] ?>, '<?= e($m['first_name'] . ' ' . $m['last_name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="memberModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="memberId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="memberTitle"><i class="fas fa-user-plus me-2"></i>Register Member</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label fw-semibold">Member Number / Code <span class="text-danger">*</span></label>
        <input type="text" name="member_no" id="memberNo" class="form-control" required placeholder="e.g. CH-0091">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
        <input type="text" name="first_name" id="memberFirst" class="form-control" required placeholder="e.g. Sarah">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
        <input type="text" name="last_name" id="memberLast" class="form-control" required placeholder="e.g. Jenkins">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
        <select name="gender" id="memberGender" class="form-select" required>
          <option value="male">Male</option>
          <option value="female">Female</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Date of Birth</label>
        <input type="date" name="dob" id="memberDob" class="form-control">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Primary Phone Contact <span class="text-danger">*</span></label>
        <input type="tel" name="phone" id="memberPhone" class="form-control" required placeholder="e.g. +254 712 345 678">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Active Email Address</label>
        <input type="email" name="email" id="memberEmail" class="form-control" placeholder="e.g. sarah.jenkins@example.com">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Marital Status</label>
        <select name="marital_status" id="memberMarital" class="form-select">
          <option value="Single">Single</option>
          <option value="Married">Married</option>
          <option value="Widowed">Widowed</option>
          <option value="Divorced">Divorced</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Date Joined Congregation</label>
        <input type="date" name="joined_at" id="memberJoined" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Cell Fellowship / Home Group</label>
        <input type="text" name="cell_group" id="memberCell" class="form-control" placeholder="e.g. Bethlehem Fellowship Cell, Eden Cell">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Church Ministry / Department</label>
        <input type="text" name="department" id="memberDept" class="form-control" placeholder="e.g. Praise & Worship, Ushers, Youth Ministry">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Residential / Home Address</label>
        <input type="text" name="address" id="memberAddress" class="form-control" placeholder="e.g. Valley Road estate, Apartment Block B">
      </div>
      <div class="col-md-6 d-flex align-items-center mt-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="baptized" id="memberBaptized" value="1">
          <label class="form-check-label fw-semibold text-dark" for="memberBaptized">Confirmed Holy Baptism Water Received</label>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Membership Classification</label>
        <select name="status" id="memberStatus" class="form-select">
          <option value="active">Active Member</option>
          <option value="inactive">Inactive</option>
          <option value="visitor">Visitor / Guest</option>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Member</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delMemberForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delMemberId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('memberTitle').innerHTML = '<i class="fas fa-user-plus me-2"></i>Register Member';
  document.getElementById('memberId').value = '0';
  document.getElementById('memberNo').value = 'CH-' + Math.floor(1000 + Math.random() * 9000);
  document.getElementById('memberFirst').value = '';
  document.getElementById('memberLast').value = '';
  document.getElementById('memberGender').value = 'female';
  document.getElementById('memberDob').value = '';
  document.getElementById('memberPhone').value = '';
  document.getElementById('memberEmail').value = '';
  document.getElementById('memberAddress').value = '';
  document.getElementById('memberMarital').value = 'Single';
  document.getElementById('memberCell').value = '';
  document.getElementById('memberDept').value = '';
  document.getElementById('memberBaptized').checked = false;
  document.getElementById('memberStatus').value = 'active';
  document.getElementById('memberJoined').value = new Date().toISOString().split('T')[0];
}
function openEdit(id) {
  fetch('members.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('memberTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Member Profile';
      document.getElementById('memberId').value = data.id;
      document.getElementById('memberNo').value = data.member_no;
      document.getElementById('memberFirst').value = data.first_name;
      document.getElementById('memberLast').value = data.last_name;
      document.getElementById('memberGender').value = data.gender;
      document.getElementById('memberDob').value = data.dob || '';
      document.getElementById('memberPhone').value = data.phone;
      document.getElementById('memberEmail').value = data.email || '';
      document.getElementById('memberAddress').value = data.address || '';
      document.getElementById('memberMarital').value = data.marital_status;
      document.getElementById('memberCell').value = data.cell_group || '';
      document.getElementById('memberDept').value = data.department || '';
      document.getElementById('memberBaptized').checked = parseInt(data.baptized) === 1;
      document.getElementById('memberStatus').value = data.status;
      document.getElementById('memberJoined').value = data.joined_at;
      
      new bootstrap.Modal(document.getElementById('memberModal')).show();
    });
}
function delMember(id, name) {
  Swal.fire({
    title: 'Delete Member Profile?',
    text: 'Remove "' + name + '" from church membership registry?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delMemberId').value = id;
      document.getElementById('delMemberForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
