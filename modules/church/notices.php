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
    require_once __DIR__ . '/../../includes/header-module.php';
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_notice') {
        $title      = sanitize($_POST['title'] ?? '');
        $category   = sanitize($_POST['category'] ?? 'general');
        $audience   = sanitize($_POST['audience'] ?? 'all');
        $content    = sanitize($_POST['content'] ?? '');
        $publishDate= sanitize($_POST['publish_date'] ?? date('Y-m-d'));
        $expiryDate = sanitize($_POST['expiry_date'] ?? '');
        $priority   = in_array($_POST['priority'] ?? '', ['low','normal','high','urgent']) ? $_POST['priority'] : 'normal';
        $pinned     = isset($_POST['pinned']) ? 1 : 0;

        if (!$title || !$content) {
            setFlash('danger', 'Title and content are required.');
        } else {
            $id = (int)($_POST['edit_id'] ?? 0);
            if ($id) {
                $pdo->prepare("UPDATE church_notices SET title=?,category=?,audience=?,content=?,publish_date=?,expiry_date=?,priority=?,pinned=? WHERE id=? AND org_id=?")
                    ->execute([$title, $category, $audience, $content, $publishDate, $expiryDate ?: null, $priority, $pinned, $id, $orgId]);
                setFlash('success', 'Notice updated.');
            } else {
                $pdo->prepare("INSERT INTO church_notices (org_id,title,category,audience,content,publish_date,expiry_date,priority,pinned,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $title, $category, $audience, $content, $publishDate, $expiryDate ?: null, $priority, $pinned, $user['id']]);
                setFlash('success', 'Notice published.');
            }
        }
        redirect(APP_URL . '/modules/church/notices.php');
    }

    if ($action === 'delete_notice') {
        $pdo->prepare("DELETE FROM church_notices WHERE id=? AND org_id=?")->execute([(int)$_POST['notice_id'], $orgId]);
        setFlash('success', 'Notice deleted.');
        redirect(APP_URL . '/modules/church/notices.php');
    }

    if ($action === 'toggle_pin') {
        $stmt = $pdo->prepare("SELECT pinned FROM church_notices WHERE id=? AND org_id=?");
        $stmt->execute([(int)$_POST['notice_id'], $orgId]);
        $current = (int)$stmt->fetchColumn();
        $pdo->prepare("UPDATE church_notices SET pinned=? WHERE id=? AND org_id=?")->execute([!$current, (int)$_POST['notice_id'], $orgId]);
        redirect(APP_URL . '/modules/church/notices.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

$filterCat = $_GET['category'] ?? '';

$notices = [];
try {
    $where = "WHERE org_id=?";
    $params = [$orgId];
    if ($filterCat) { $where .= " AND category=?"; $params[] = $filterCat; }
    $stmt = $pdo->prepare("SELECT * FROM church_notices $where ORDER BY pinned DESC, publish_date DESC");
    $stmt->execute($params);
    $notices = $stmt->fetchAll();
} catch (Exception $e) {}

$todayActive = count(array_filter($notices, fn($n) => (!$n['expiry_date'] || $n['expiry_date'] >= date('Y-m-d')) && $n['publish_date'] <= date('Y-m-d')));
$pinnedCount = count(array_filter($notices, fn($n) => $n['pinned']));
$urgentCount = count(array_filter($notices, fn($n) => $n['priority'] === 'urgent'));

$categories = ['general','announcement','prayer','financial','event','youth','women','men','other'];
$priorities = ['low','normal','high','urgent'];
$audiences  = ['all','members','leaders','youth','women','men'];
$prioColors = ['low'=>'secondary','normal'=>'info','high'=>'warning','urgent'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bell me-2" style="color:<?= $moduleColor ?>"></i>Church Notices</h4>
    <p class="text-muted mb-0">Announcements and notices for the congregation</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#noticeModal">
    <i class="fas fa-plus me-2"></i>Post Notice
  </button>
</div>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['Total Notices', count($notices),  $moduleColor,  'fas fa-bell'],
    ['Active Today',  $todayActive,      '#27ae60',     'fas fa-check-circle'],
    ['Pinned',        $pinnedCount,      '#f39c12',     'fas fa-thumbtack'],
    ['Urgent',        $urgentCount,      '#e74c3c',     'fas fa-exclamation-triangle'],
  ] as [$label, $val, $color, $icon]): ?>
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $color ?>20;color:<?= $color ?>"><i class="<?= $icon ?>"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $val ?></div><div class="stat-label"><?= $label ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filter -->
<div class="card mb-3 no-print">
  <div class="card-body py-2">
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
      <select name="category" class="form-select form-select-sm" style="max-width:160px">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= $c ?>" <?= $filterCat===$c?'selected':'' ?>><?= ucfirst($c) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="notices.php" class="btn btn-sm btn-link">Reset</a>
    </form>
  </div>
</div>

<!-- Notices -->
<?php if (empty($notices)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
  <i class="fas fa-bell-slash fa-3x d-block mb-3 opacity-25"></i>No notices posted.
</div></div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($notices as $n):
    $isActive = (!$n['expiry_date'] || $n['expiry_date'] >= date('Y-m-d')) && $n['publish_date'] <= date('Y-m-d');
    $isExpired = $n['expiry_date'] && $n['expiry_date'] < date('Y-m-d');
  ?>
  <div class="col-md-6">
    <div class="card h-100 <?= $n['pinned'] ? 'border-warning' : '' ?>" style="<?= $n['pinned'] ? 'border-width:2px' : '' ?>">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
          <div class="flex-1">
            <?php if ($n['pinned']): ?><i class="fas fa-thumbtack text-warning me-1 small"></i><?php endif; ?>
            <strong class="small"><?= e($n['title']) ?></strong>
          </div>
          <div class="d-flex gap-1 flex-wrap">
            <span class="badge bg-<?= $prioColors[$n['priority']] ?? 'secondary' ?> text-xs"><?= ucfirst($n['priority']) ?></span>
            <?php if ($isExpired): ?>
            <span class="badge bg-secondary text-xs">Expired</span>
            <?php elseif (!$isActive): ?>
            <span class="badge bg-light text-dark border text-xs">Scheduled</span>
            <?php else: ?>
            <span class="badge bg-success text-xs">Live</span>
            <?php endif; ?>
          </div>
        </div>
        <p class="text-muted small mb-3"><?= nl2br(e(substr($n['content'], 0, 160))) ?><?= strlen($n['content']) > 160 ? '…' : '' ?></p>
        <div class="d-flex flex-wrap gap-2 small text-muted">
          <span><i class="fas fa-tag me-1"></i><?= ucfirst($n['category']) ?></span>
          <span><i class="fas fa-users me-1"></i><?= ucfirst($n['audience']) ?></span>
          <span><i class="fas fa-calendar me-1"></i><?= formatDate($n['publish_date']) ?></span>
          <?php if ($n['expiry_date']): ?><span class="text-danger"><i class="fas fa-clock me-1"></i>Expires <?= formatDate($n['expiry_date']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="card-footer bg-transparent d-flex gap-1">
        <button class="btn btn-sm btn-outline-secondary flex-fill" onclick='editNotice(<?= json_encode($n) ?>)'><i class="fas fa-edit me-1"></i>Edit</button>
        <form method="POST" class="d-inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_pin">
          <input type="hidden" name="notice_id" value="<?= $n['id'] ?>">
          <button class="btn btn-sm <?= $n['pinned'] ? 'btn-warning' : 'btn-outline-warning' ?>" title="<?= $n['pinned'] ? 'Unpin' : 'Pin' ?>">
            <i class="fas fa-thumbtack"></i>
          </button>
        </form>
        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this notice?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_notice">
          <input type="hidden" name="notice_id" value="<?= $n['id'] ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add/Edit Notice Modal -->
<div class="modal fade" id="noticeModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="noticeModalTitle"><i class="fas fa-bell me-2"></i>Post Notice</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="resetNotice()"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_notice">
        <input type="hidden" name="edit_id" id="editId" value="">
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
            <input type="text" name="title" id="fTitle" class="form-control" required maxlength="200">
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold">Category</label>
            <select name="category" id="fCat" class="form-select">
              <?php foreach ($categories as $c): ?><option value="<?= $c ?>"><?= ucfirst($c) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold">Audience</label>
            <select name="audience" id="fAudience" class="form-select">
              <?php foreach ($audiences as $a): ?><option value="<?= $a ?>"><?= ucfirst($a) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold">Priority</label>
            <select name="priority" id="fPriority" class="form-select">
              <?php foreach ($priorities as $pr): ?><option value="<?= $pr ?>" <?= $pr==='normal'?'selected':'' ?>><?= ucfirst($pr) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Publish Date</label>
            <input type="date" name="publish_date" id="fPub" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Expiry Date (optional)</label>
            <input type="date" name="expiry_date" id="fExpiry" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Content <span class="text-danger">*</span></label>
            <textarea name="content" id="fContent" class="form-control" rows="5" required placeholder="Write the notice content…"></textarea>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="pinned" id="fPinned" value="1">
              <label class="form-check-label small" for="fPinned"><i class="fas fa-thumbtack me-1 text-warning"></i>Pin this notice to the top</label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="resetNotice()">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-bell me-2"></i>Post Notice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function editNotice(n) {
  document.getElementById('editId').value = n.id;
  document.getElementById('fTitle').value = n.title;
  document.getElementById('fCat').value = n.category;
  document.getElementById('fAudience').value = n.audience;
  document.getElementById('fPriority').value = n.priority;
  document.getElementById('fPub').value = n.publish_date;
  document.getElementById('fExpiry').value = n.expiry_date || '';
  document.getElementById('fContent').value = n.content;
  document.getElementById('fPinned').checked = n.pinned == 1;
  document.getElementById('noticeModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Notice';
  new bootstrap.Modal(document.getElementById('noticeModal')).show();
}
function resetNotice() {
  document.getElementById('editId').value = '';
  document.getElementById('noticeModalTitle').innerHTML = '<i class="fas fa-bell me-2"></i>Post Notice';
  document.getElementById('fPinned').checked = false;
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
