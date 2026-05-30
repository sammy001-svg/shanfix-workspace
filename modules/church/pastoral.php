<?php
// ── CHURCH: Pastoral Visits & Counseling ───────────────────────
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

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $memberId     = (int)($_POST['member_id'] ?? 0) ?: null;
        $name         = sanitize($_POST['name']          ?? '');
        $visitType    = sanitize($_POST['visit_type']    ?? 'Home Visit');
        $visitDate    = $_POST['visit_date']              ?? date('Y-m-d');
        $pastor       = sanitize($_POST['pastor']        ?? '');
        $outcome      = sanitize($_POST['outcome']       ?? '');
        $followUpDate = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
        $status       = in_array($_POST['status'] ?? '', ['pending','done','follow_up']) ? $_POST['status'] : 'done';
        $notes        = sanitize($_POST['notes']         ?? '');

        if (!$name) {
            setFlash('danger', 'Name is required.');
            redirect('pastoral.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE church_pastoral SET member_id=?,name=?,visit_type=?,visit_date=?,pastor=?,outcome=?,follow_up_date=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$memberId, $name, $visitType, $visitDate, $pastor, $outcome, $followUpDate, $status, $notes, $id, $orgId]);
            setFlash('success', 'Pastoral record updated.');
            logActivity('update', 'church', "Updated pastoral visit #$id");
        } else {
            $pdo->prepare("INSERT INTO church_pastoral (org_id,member_id,name,visit_type,visit_date,pastor,outcome,follow_up_date,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $memberId, $name, $visitType, $visitDate, $pastor, $outcome, $followUpDate, $status, $notes]);
            setFlash('success', "Pastoral visit/contact for $name recorded.");
            logActivity('create', 'church', "Pastoral visit: $visitType — $name");
        }
        redirect('pastoral.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM church_pastoral WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Pastoral record deleted.');
        logActivity('delete', 'church', "Deleted pastoral record #$id");
        redirect('pastoral.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type']   ?? '';
$where  = 'p.org_id = ?';
$params = [$orgId];
if ($filterStatus) { $where .= ' AND p.status = ?';     $params[] = $filterStatus; }
if ($filterType)   { $where .= ' AND p.visit_type = ?'; $params[] = $filterType; }

$visits = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, CONCAT(m.first_name,' ',m.last_name) AS member_name
        FROM church_pastoral p
        LEFT JOIN church_members m ON p.member_id = m.id
        WHERE $where ORDER BY p.visit_date DESC
    ");
    $stmt->execute($params);
    $visits = $stmt->fetchAll();
} catch (Exception $e) {}

$members = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, member_no FROM church_members WHERE org_id=? ORDER BY first_name");
    $stmt->execute([$orgId]);
    $members = $stmt->fetchAll();
} catch (Exception $e) {}

$pendingCount   = countRows('church_pastoral', "org_id=? AND status='pending'",  [$orgId]);
$doneCount      = countRows('church_pastoral', "org_id=? AND status='done'",     [$orgId]);
$followUpCount  = countRows('church_pastoral', "org_id=? AND status='follow_up'",[$orgId]);

$visitTypes = ['Home Visit','Hospital Visit','Prison Visit','Office Counseling','Phone Call','Bereavement Visit','New Member Visit','Crisis Intervention','Discipleship Session','Other'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-hands-helping me-2" style="color:<?= $moduleColor ?>"></i>Pastoral Care</h4>
    <p class="text-muted mb-0">Track pastoral visits, counseling sessions, hospital calls, and follow-ups</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#pastoralModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Record Visit
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingCount ?></div><div class="stat-label">Pending Visits</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $doneCount ?></div><div class="stat-label">Completed Visits</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(142,68,173,.12);color:#8e44ad"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $followUpCount ?></div><div class="stat-label">Awaiting Follow-up</div></div>
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
          <option value="done"      <?= $filterStatus==='done'      ?'selected':'' ?>>Done</option>
          <option value="follow_up" <?= $filterStatus==='follow_up' ?'selected':'' ?>>Follow-up</option>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Visit Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <?php foreach ($visitTypes as $vt): ?>
          <option value="<?= $vt ?>" <?= $filterType===$vt?'selected':'' ?>><?= $vt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="pastoral.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Visits Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-hands-helping me-2" style="color:<?= $moduleColor ?>"></i>Pastoral Records</h6>
    <span class="badge bg-secondary"><?= count($visits) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Person</th>
            <th>Visit Type</th>
            <th>Pastor</th>
            <th>Date</th>
            <th>Follow-up Date</th>
            <th>Outcome</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($visits)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted">
            <i class="fas fa-hands-helping fa-2x mb-2 d-block"></i>No pastoral records yet.
          </td></tr>
          <?php else: foreach ($visits as $v):
            $today = date('Y-m-d');
            $overdue = $v['follow_up_date'] && $v['follow_up_date'] < $today && $v['status'] !== 'done';
          ?>
          <tr class="<?= $overdue ? 'table-warning' : '' ?>">
            <td>
              <div class="fw-semibold"><?= e($v['name']) ?></div>
              <?php if ($v['member_name']): ?><div class="small text-muted"><?= e($v['member_name']) ?></div><?php endif; ?>
            </td>
            <td><span class="badge bg-light text-dark border small"><?= e($v['visit_type']) ?></span></td>
            <td class="small"><?= e($v['pastor'] ?: '—') ?></td>
            <td><?= formatDate($v['visit_date']) ?></td>
            <td class="small <?= $overdue ? 'text-danger fw-bold' : 'text-muted' ?>">
              <?= $v['follow_up_date'] ? formatDate($v['follow_up_date']) : '—' ?>
              <?php if ($overdue): ?><span class="badge bg-danger ms-1">Overdue</span><?php endif; ?>
            </td>
            <td class="small text-muted"><?= e(mb_strimwidth($v['outcome'] ?? '', 0, 60, '…')) ?></td>
            <td>
              <?php
              $sc = ['pending' => 'warning', 'done' => 'success', 'follow_up' => 'info'];
              $s  = $v['status'];
              ?>
              <span class="badge bg-<?= $sc[$s] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$s)) ?></span>
            </td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this pastoral record?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $v['id'] ?>">
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
<div class="modal fade" id="pastoralModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="pvId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="pvTitle"><i class="fas fa-hands-helping me-2"></i>Record Pastoral Visit</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Person's Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="pvName" class="form-control" required placeholder="e.g. John Kamau">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Link to Member (optional)</label>
              <select name="member_id" id="pvMember" class="form-select" onchange="autofillName()">
                <option value="">— Not a registered member —</option>
                <?php foreach ($members as $m): ?>
                <option value="<?= $m['id'] ?>" data-name="<?= e($m['name']) ?>"><?= e($m['name']) ?> (<?= e($m['member_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Visit Type</label>
              <select name="visit_type" id="pvType" class="form-select">
                <?php foreach ($visitTypes as $vt): ?>
                <option value="<?= $vt ?>"><?= $vt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Visit Date</label>
              <input type="date" name="visit_date" id="pvDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="pvStatus" class="form-select">
                <option value="done">Done</option>
                <option value="pending">Pending</option>
                <option value="follow_up">Follow-up Required</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Assigned Pastor / Leader</label>
              <input type="text" name="pastor" id="pvPastor" class="form-control" placeholder="e.g. Pastor Grace Mwangi">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Follow-up Date (if needed)</label>
              <input type="date" name="follow_up_date" id="pvFollowUp" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Outcome / Summary</label>
              <textarea name="outcome" id="pvOutcome" class="form-control" rows="2" placeholder="Brief outcome of the visit or counseling session..."></textarea>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Private Notes</label>
              <textarea name="notes" id="pvNotes" class="form-control" rows="2" placeholder="Confidential notes (not visible to general users)..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Record</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("pvTitle").innerHTML = "<i class=\"fas fa-hands-helping me-2\"></i>Record Pastoral Visit";
  document.getElementById("pvId").value       = 0;
  document.getElementById("pvName").value     = "";
  document.getElementById("pvMember").value   = "";
  document.getElementById("pvType").value     = "Home Visit";
  document.getElementById("pvDate").value     = "' . date('Y-m-d') . '";
  document.getElementById("pvStatus").value   = "done";
  document.getElementById("pvPastor").value   = "";
  document.getElementById("pvFollowUp").value = "";
  document.getElementById("pvOutcome").value  = "";
  document.getElementById("pvNotes").value    = "";
}
function autofillName() {
  var sel = document.getElementById("pvMember");
  var opt = sel.options[sel.selectedIndex];
  if (opt && opt.dataset.name) {
    document.getElementById("pvName").value = opt.dataset.name;
  }
}
function openEdit(v) {
  document.getElementById("pvTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Pastoral Record";
  document.getElementById("pvId").value       = v.id;
  document.getElementById("pvName").value     = v.name           || "";
  document.getElementById("pvMember").value   = v.member_id      || "";
  document.getElementById("pvType").value     = v.visit_type     || "Home Visit";
  document.getElementById("pvDate").value     = v.visit_date     || "";
  document.getElementById("pvStatus").value   = v.status         || "done";
  document.getElementById("pvPastor").value   = v.pastor         || "";
  document.getElementById("pvFollowUp").value = v.follow_up_date || "";
  document.getElementById("pvOutcome").value  = v.outcome        || "";
  document.getElementById("pvNotes").value    = v.notes          || "";
  new bootstrap.Modal(document.getElementById("pastoralModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
