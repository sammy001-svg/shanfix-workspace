<?php
// ── CHURCH: Sermon Library ──────────────────────────────────────
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
        $title       = sanitize($_POST['title']        ?? '');
        $scripture   = sanitize($_POST['scripture']    ?? '');
        $preacher    = sanitize($_POST['preacher']     ?? '');
        $series      = sanitize($_POST['series']       ?? '');
        $serviceDate = $_POST['service_date']           ?? date('Y-m-d');
        $serviceType = sanitize($_POST['service_type'] ?? 'Sunday Service');
        $mediaUrl    = sanitize($_POST['media_url']    ?? '');
        $notes       = sanitize($_POST['notes']        ?? '');

        if (!$title) {
            setFlash('danger', 'Sermon title is required.');
            redirect('sermons.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE church_sermons SET title=?,scripture=?,preacher=?,series=?,service_date=?,service_type=?,media_url=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$title, $scripture, $preacher, $series, $serviceDate, $serviceType, $mediaUrl, $notes, $id, $orgId]);
            setFlash('success', 'Sermon updated.');
            logActivity('update', 'church', "Updated sermon: $title");
        } else {
            $pdo->prepare("INSERT INTO church_sermons (org_id,title,scripture,preacher,series,service_date,service_type,media_url,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $title, $scripture, $preacher, $series, $serviceDate, $serviceType, $mediaUrl, $notes]);
            setFlash('success', "Sermon '$title' added to library.");
            logActivity('create', 'church', "Added sermon: $title");
        }
        redirect('sermons.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM church_sermons WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Sermon deleted.');
        logActivity('delete', 'church', "Deleted sermon #$id");
        redirect('sermons.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterPreacher = $_GET['preacher'] ?? '';
$filterSeries   = $_GET['series']   ?? '';
$where  = 'org_id = ?';
$params = [$orgId];
if ($filterPreacher) { $where .= ' AND preacher = ?'; $params[] = $filterPreacher; }
if ($filterSeries)   { $where .= ' AND series = ?';   $params[] = $filterSeries; }

$sermons = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM church_sermons WHERE $where ORDER BY service_date DESC");
    $stmt->execute($params);
    $sermons = $stmt->fetchAll();
} catch (Exception $e) {}

// Distinct preachers and series for filters
$preachers = [];
$seriesList = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT preacher FROM church_sermons WHERE org_id=? AND preacher != '' ORDER BY preacher");
    $stmt->execute([$orgId]);
    $preachers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare("SELECT DISTINCT series FROM church_sermons WHERE org_id=? AND series != '' ORDER BY series");
    $stmt->execute([$orgId]);
    $seriesList = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$totalSermons = count($sermons);
$serviceTypes = ['Sunday Service','Wednesday Service','Friday Service','Youth Service','Special Service','Prayer Meeting','Revival'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bible me-2" style="color:<?= $moduleColor ?>"></i>Sermon Library</h4>
    <p class="text-muted mb-0">Record sermons, scripture references, sermon series, and media links</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#sermonModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Add Sermon
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(142,68,173,.12);color:#8e44ad"><i class="fas fa-bible"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalSermons ?></div><div class="stat-label">Total Sermons</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-microphone"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($preachers) ?></div><div class="stat-label">Preachers</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-layer-group"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($seriesList) ?></div><div class="stat-label">Sermon Series</div></div>
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Preacher</label>
        <select name="preacher" class="form-select form-select-sm">
          <option value="">All Preachers</option>
          <?php foreach ($preachers as $p): ?>
          <option value="<?= e($p) ?>" <?= $filterPreacher === $p ? 'selected' : '' ?>><?= e($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Series</label>
        <select name="series" class="form-select form-select-sm">
          <option value="">All Series</option>
          <?php foreach ($seriesList as $s): ?>
          <option value="<?= e($s) ?>" <?= $filterSeries === $s ? 'selected' : '' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="sermons.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Sermons Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-bible me-2" style="color:<?= $moduleColor ?>"></i>Sermon Records</h6>
    <span class="badge bg-secondary"><?= count($sermons) ?> sermons</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Title</th>
            <th>Scripture</th>
            <th>Preacher</th>
            <th>Series</th>
            <th>Date</th>
            <th>Service</th>
            <th>Media</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sermons)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted">
            <i class="fas fa-bible fa-2x mb-2 d-block"></i>No sermons in the library yet.
          </td></tr>
          <?php else: foreach ($sermons as $s): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($s['title']) ?></div>
              <?php if ($s['notes']): ?><div class="small text-muted"><?= e(mb_strimwidth($s['notes'], 0, 60, '…')) ?></div><?php endif; ?>
            </td>
            <td class="small text-muted"><i class="fas fa-book-open me-1"></i><?= e($s['scripture'] ?: '—') ?></td>
            <td class="small"><?= e($s['preacher'] ?: '—') ?></td>
            <td class="small text-muted"><?= $s['series'] ? '<span class="badge bg-light text-dark border">'.e($s['series']).'</span>' : '—' ?></td>
            <td><?= formatDate($s['service_date']) ?></td>
            <td class="small"><span class="badge bg-light text-dark border"><?= e($s['service_type']) ?></span></td>
            <td>
              <?php if ($s['media_url']): ?>
              <a href="<?= e($s['media_url']) ?>" target="_blank" class="btn btn-sm btn-outline-info" title="Listen/Watch"><i class="fas fa-play"></i></a>
              <?php else: ?>
              <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this sermon?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
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

<!-- Add/Edit Modal -->
<div class="modal fade" id="sermonModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="sId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="sTitle"><i class="fas fa-bible me-2"></i>Add Sermon</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Sermon Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="sTitle2" class="form-control" required placeholder="e.g. Walking in the Light">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Scripture Reference</label>
              <input type="text" name="scripture" id="sScripture" class="form-control" placeholder="e.g. John 8:12, Romans 12:1-2">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Preacher / Speaker</label>
              <input type="text" name="preacher" id="sPreacher" class="form-control" placeholder="e.g. Pastor John Njoroge">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Sermon Series</label>
              <input type="text" name="series" id="sSeries" class="form-control" placeholder="e.g. Faith That Moves Mountains">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Service Date</label>
              <input type="date" name="service_date" id="sDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Service Type</label>
              <select name="service_type" id="sType" class="form-select">
                <?php foreach ($serviceTypes as $st): ?>
                <option value="<?= $st ?>"><?= $st ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Media / Audio / Video URL</label>
              <input type="url" name="media_url" id="sMedia" class="form-control" placeholder="https://youtube.com/... or https://soundcloud.com/...">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes / Summary</label>
              <textarea name="notes" id="sNotes" class="form-control" rows="3" placeholder="Key points, sermon outline, or synopsis..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Sermon</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("sTitle").innerHTML = "<i class=\"fas fa-bible me-2\"></i>Add Sermon";
  document.getElementById("sId").value       = 0;
  document.getElementById("sTitle2").value   = "";
  document.getElementById("sScripture").value= "";
  document.getElementById("sPreacher").value = "";
  document.getElementById("sSeries").value   = "";
  document.getElementById("sDate").value     = "' . date('Y-m-d') . '";
  document.getElementById("sType").value     = "Sunday Service";
  document.getElementById("sMedia").value    = "";
  document.getElementById("sNotes").value    = "";
}
function openEdit(s) {
  document.getElementById("sTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Sermon";
  document.getElementById("sId").value       = s.id;
  document.getElementById("sTitle2").value   = s.title        || "";
  document.getElementById("sScripture").value= s.scripture    || "";
  document.getElementById("sPreacher").value = s.preacher     || "";
  document.getElementById("sSeries").value   = s.series       || "";
  document.getElementById("sDate").value     = s.service_date || "";
  document.getElementById("sType").value     = s.service_type || "Sunday Service";
  document.getElementById("sMedia").value    = s.media_url    || "";
  document.getElementById("sNotes").value    = s.notes        || "";
  new bootstrap.Modal(document.getElementById("sermonModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
