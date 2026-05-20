<?php
// ── CHURCH: Prayer Requests ─────────────────────────────────────
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
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],
];

// ── Action Handling ────────────────────────────────────────────
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
        $memberId    = (int)($_POST['member_id'] ?? 0) ?: null;
        $name        = sanitize($_POST['name']         ?? '');
        $request     = sanitize($_POST['request']      ?? '');
        $category    = sanitize($_POST['category']     ?? 'General');
        $assignedTo  = sanitize($_POST['assigned_to']  ?? '');
        $status      = in_array($_POST['status'] ?? '', ['pending','in_prayer','answered','closed']) ? $_POST['status'] : 'pending';
        $submittedAt = $_POST['submitted_at']           ?? date('Y-m-d');
        $notes       = sanitize($_POST['notes']        ?? '');

        if (!$name || !$request) {
            setFlash('danger', 'Name and prayer request are required.');
            redirect('prayers.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE church_prayers SET member_id=?,name=?,request=?,category=?,assigned_to=?,status=?,submitted_at=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$memberId, $name, $request, $category, $assignedTo, $status, $submittedAt, $notes, $id, $orgId]);
            setFlash('success', 'Prayer request updated.');
            logActivity('update', 'church', "Updated prayer request #$id");
        } else {
            $pdo->prepare("INSERT INTO church_prayers (org_id,member_id,name,request,category,assigned_to,status,submitted_at,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $memberId, $name, $request, $category, $assignedTo, $status, $submittedAt, $notes]);
            setFlash('success', "Prayer request from $name logged.");
            logActivity('create', 'church', "New prayer request: $name");
        }
        redirect('prayers.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM church_prayers WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Prayer request deleted.');
        logActivity('delete', 'church', "Deleted prayer request #$id");
        redirect('prayers.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterStatus   = $_GET['status']   ?? '';
$filterCategory = $_GET['category'] ?? '';
$where  = 'p.org_id = ?';
$params = [$orgId];
if ($filterStatus)   { $where .= ' AND p.status = ?';   $params[] = $filterStatus; }
if ($filterCategory) { $where .= ' AND p.category = ?'; $params[] = $filterCategory; }

$prayers = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, CONCAT(m.first_name,' ',m.last_name) AS member_name
        FROM church_prayers p
        LEFT JOIN church_members m ON p.member_id = m.id
        WHERE $where ORDER BY p.submitted_at DESC, p.created_at DESC
    ");
    $stmt->execute($params);
    $prayers = $stmt->fetchAll();
} catch (Exception $e) {}

$members = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, member_no FROM church_members WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]);
    $members = $stmt->fetchAll();
} catch (Exception $e) {}

$pendingCount   = countRows('church_prayers', "org_id=? AND status='pending'",   [$orgId]);
$inPrayerCount  = countRows('church_prayers', "org_id=? AND status='in_prayer'", [$orgId]);
$answeredCount  = countRows('church_prayers', "org_id=? AND status='answered'",  [$orgId]);

$categories = ['General','Healing','Financial Breakthrough','Family','Marriage','Job/Career','Protection','Salvation','Thanksgiving','Other'];
$statusColors = ['pending' => 'warning', 'in_prayer' => 'info', 'answered' => 'success', 'closed' => 'secondary'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-praying-hands me-2" style="color:<?= $moduleColor ?>"></i>Prayer Requests</h4>
    <p class="text-muted mb-0">Record, track, and follow up on congregation prayer requests</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#prayerModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Log Prayer Request
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingCount ?></div><div class="stat-label">Pending</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(52,152,219,.12);color:#3498db"><i class="fas fa-praying-hands"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $inPrayerCount ?></div><div class="stat-label">In Prayer</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $answeredCount ?></div><div class="stat-label">Answered Prayers</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(142,68,173,.12);color:#8e44ad"><i class="fas fa-list"></i></div>
      <div class="stat-body"><div class="stat-value"><?= countRows('church_prayers', 'org_id=?', [$orgId]) ?></div><div class="stat-label">Total Requests</div></div>
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="pending"   <?= $filterStatus==='pending'   ?'selected':'' ?>>Pending</option>
          <option value="in_prayer" <?= $filterStatus==='in_prayer' ?'selected':'' ?>>In Prayer</option>
          <option value="answered"  <?= $filterStatus==='answered'  ?'selected':'' ?>>Answered</option>
          <option value="closed"    <?= $filterStatus==='closed'    ?'selected':'' ?>>Closed</option>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Category</label>
        <select name="category" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat ?>" <?= $filterCategory===$cat?'selected':'' ?>><?= $cat ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="prayers.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Prayer Requests Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-praying-hands me-2" style="color:<?= $moduleColor ?>"></i>Prayer Request Log</h6>
    <span class="badge bg-secondary"><?= count($prayers) ?> requests</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Name / Member</th>
            <th>Request</th>
            <th>Category</th>
            <th>Assigned To</th>
            <th>Date</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($prayers)): ?>
          <tr><td colspan="7" class="text-center py-5 text-muted">
            <i class="fas fa-praying-hands fa-2x mb-2 d-block"></i>No prayer requests logged yet.
          </td></tr>
          <?php else: foreach ($prayers as $pr):
            $sc = $statusColors[$pr['status']] ?? 'secondary';
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($pr['name']) ?></div>
              <?php if ($pr['member_name']): ?><div class="small text-muted"><?= e($pr['member_name']) ?></div><?php endif; ?>
            </td>
            <td style="max-width:260px" class="small"><?= e(mb_strimwidth($pr['request'], 0, 100, '…')) ?></td>
            <td><span class="badge bg-light text-dark border small"><?= e($pr['category']) ?></span></td>
            <td class="small"><?= e($pr['assigned_to'] ?: '—') ?></td>
            <td><?= formatDate($pr['submitted_at']) ?></td>
            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst(str_replace('_',' ',$pr['status'])) ?></span></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode($pr), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this prayer request?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $pr['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="prayerModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="prId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="prTitle"><i class="fas fa-praying-hands me-2"></i>Log Prayer Request</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="prName" class="form-control" required placeholder="e.g. Mary Wanjiku">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Link to Member (optional)</label>
              <select name="member_id" id="prMember" class="form-select">
                <option value="">— Non-member / Anonymous —</option>
                <?php foreach ($members as $m): ?>
                <option value="<?= $m['id'] ?>"><?= e($m['name']) ?> (<?= e($m['member_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Prayer Request <span class="text-danger">*</span></label>
              <textarea name="request" id="prRequest" class="form-control" rows="3" required placeholder="Describe the prayer need..."></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Category</label>
              <select name="category" id="prCategory" class="form-select">
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>"><?= $cat ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Assigned Intercessor</label>
              <input type="text" name="assigned_to" id="prAssigned" class="form-control" placeholder="Name of intercessor/pastor">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Date Submitted</label>
              <input type="date" name="submitted_at" id="prDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="prStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="in_prayer">In Prayer</option>
                <option value="answered">Answered</option>
                <option value="closed">Closed</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Notes / Follow-up</label>
              <textarea name="notes" id="prNotes" class="form-control" rows="2" placeholder="Updates, testimony, outcome notes..."></textarea>
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

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("prTitle").innerHTML = "<i class=\"fas fa-praying-hands me-2\"></i>Log Prayer Request";
  document.getElementById("prId").value       = 0;
  document.getElementById("prName").value     = "";
  document.getElementById("prMember").value   = "";
  document.getElementById("prRequest").value  = "";
  document.getElementById("prCategory").value = "General";
  document.getElementById("prAssigned").value = "";
  document.getElementById("prDate").value     = "' . date('Y-m-d') . '";
  document.getElementById("prStatus").value   = "pending";
  document.getElementById("prNotes").value    = "";
}
function openEdit(p) {
  document.getElementById("prTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Prayer Request";
  document.getElementById("prId").value       = p.id;
  document.getElementById("prName").value     = p.name         || "";
  document.getElementById("prMember").value   = p.member_id    || "";
  document.getElementById("prRequest").value  = p.request      || "";
  document.getElementById("prCategory").value = p.category     || "General";
  document.getElementById("prAssigned").value = p.assigned_to  || "";
  document.getElementById("prDate").value     = p.submitted_at || "";
  document.getElementById("prStatus").value   = p.status       || "pending";
  document.getElementById("prNotes").value    = p.notes        || "";
  new bootstrap.Modal(document.getElementById("prayerModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
