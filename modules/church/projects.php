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
    verifyCsrf();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_project') {
        $name       = sanitize($_POST['name'] ?? '');
        $category   = sanitize($_POST['category'] ?? 'construction');
        $budget     = (float)$_POST['budget'];
        $spent      = (float)($_POST['spent'] ?? 0);
        $startDate  = sanitize($_POST['start_date'] ?? date('Y-m-d'));
        $endDate    = sanitize($_POST['end_date'] ?? '');
        $status     = in_array($_POST['status'] ?? '', ['planning','active','on_hold','completed','cancelled']) ? $_POST['status'] : 'planning';
        $description= sanitize($_POST['description'] ?? '');

        if (!$name) {
            setFlash('danger', 'Project name is required.');
        } else {
            $id = (int)($_POST['edit_id'] ?? 0);
            if ($id) {
                $pdo->prepare("UPDATE church_projects SET name=?,category=?,budget=?,spent=?,start_date=?,end_date=?,status=?,description=? WHERE id=? AND org_id=?")
                    ->execute([$name, $category, $budget, $spent, $startDate, $endDate ?: null, $status, $description, $id, $orgId]);
                setFlash('success', 'Project updated.');
            } else {
                $pdo->prepare("INSERT INTO church_projects (org_id,name,category,budget,spent,start_date,end_date,status,description,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $name, $category, $budget, $spent, $startDate, $endDate ?: null, $status, $description, $user['id']]);
                setFlash('success', 'Project created.');
            }
        }
        redirect(APP_URL . '/modules/church/projects.php');
    }

    if ($action === 'update_spent') {
        $id    = (int)$_POST['project_id'];
        $spent = (float)$_POST['spent'];
        $pdo->prepare("UPDATE church_projects SET spent=? WHERE id=? AND org_id=?")->execute([$spent, $id, $orgId]);
        setFlash('success', 'Project spending updated.');
        redirect(APP_URL . '/modules/church/projects.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

$projects = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM church_projects WHERE org_id=? ORDER BY start_date DESC");
    $stmt->execute([$orgId]);
    $projects = $stmt->fetchAll();
} catch (Exception $e) {}

$totalBudget  = array_sum(array_column($projects, 'budget'));
$totalSpent   = array_sum(array_column($projects, 'spent'));
$activeCount  = count(array_filter($projects, fn($p) => $p['status'] === 'active'));
$completedCnt = count(array_filter($projects, fn($p) => $p['status'] === 'completed'));

$categories = ['construction','renovation','outreach','missions','equipment','welfare','youth','other'];
$statusColors = ['planning'=>'secondary','active'=>'primary','on_hold'=>'warning','completed'=>'success','cancelled'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-project-diagram me-2" style="color:<?= $moduleColor ?>"></i>Church Projects</h4>
    <p class="text-muted mb-0">Manage construction, outreach, and ministry projects</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#projectModal">
    <i class="fas fa-plus me-2"></i>New Project
  </button>
</div>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['Total Budget',   formatCurrency($totalBudget),  $moduleColor, 'fas fa-coins'],
    ['Spent',          formatCurrency($totalSpent),    '#e74c3c',    'fas fa-receipt'],
    ['Active',         $activeCount,                   '#27ae60',    'fas fa-play-circle'],
    ['Completed',      $completedCnt,                  '#3498db',    'fas fa-check-double'],
  ] as [$label, $val, $color, $icon]): ?>
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $color ?>20;color:<?= $color ?>"><i class="<?= $icon ?>"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $val ?></div><div class="stat-label"><?= $label ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Projects Cards -->
<?php if (empty($projects)): ?>
<div class="card"><div class="card-body text-center text-muted py-5">
  <i class="fas fa-project-diagram fa-3x d-block mb-3 opacity-25"></i>No projects yet.
</div></div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($projects as $pj):
    $pct = $pj['budget'] > 0 ? min(100, round($pj['spent']/$pj['budget']*100)) : 0;
    $barColor = $pct >= 100 ? 'danger' : ($pct >= 80 ? 'warning' : 'success');
  ?>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h6 class="fw-bold mb-1"><?= e($pj['name']) ?></h6>
            <span class="badge bg-light text-dark border small"><?= ucfirst($pj['category']) ?></span>
          </div>
          <span class="badge bg-<?= $statusColors[$pj['status']] ?? 'secondary' ?>"><?= str_replace('_',' ',ucfirst($pj['status'])) ?></span>
        </div>
        <?php if ($pj['description']): ?>
        <p class="text-muted small mb-3"><?= e(substr($pj['description'], 0, 90)) ?><?= strlen($pj['description']) > 90 ? '…' : '' ?></p>
        <?php endif; ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small mb-1">
            <span class="text-muted">Budget progress</span>
            <span class="fw-semibold"><?= $pct ?>%</span>
          </div>
          <div class="progress" style="height:6px">
            <div class="progress-bar bg-<?= $barColor ?>" style="width:<?= $pct ?>%"></div>
          </div>
          <div class="d-flex justify-content-between small mt-1 text-muted">
            <span>Spent: <?= formatCurrency($pj['spent']) ?></span>
            <span>Budget: <?= formatCurrency($pj['budget']) ?></span>
          </div>
        </div>
        <div class="small text-muted mt-3">
          <i class="fas fa-calendar-alt me-1"></i><?= formatDate($pj['start_date']) ?>
          <?= $pj['end_date'] ? ' → ' . formatDate($pj['end_date']) : '' ?>
        </div>
      </div>
      <div class="card-footer bg-transparent d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary flex-fill" onclick='editProject(<?= json_encode($pj) ?>)'>
          <i class="fas fa-edit me-1"></i>Edit
        </button>
        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#spentModal"
                onclick="document.getElementById('spentId').value=<?= $pj['id'] ?>;document.getElementById('spentVal').value=<?= $pj['spent'] ?>" title="Update spending">
          <i class="fas fa-dollar-sign"></i>
        </button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add/Edit Project Modal -->
<div class="modal fade" id="projectModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="projModalTitle"><i class="fas fa-project-diagram me-2"></i>New Project</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_project">
        <input type="hidden" name="edit_id" id="editId" value="">
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Project Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="fName" class="form-control" required maxlength="200">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Category</label>
            <select name="category" id="fCat" class="form-select">
              <?php foreach ($categories as $c): ?><option value="<?= $c ?>"><?= ucfirst($c) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="fStatus" class="form-select">
              <?php foreach (array_keys($statusColors) as $s): ?><option value="<?= $s ?>"><?= str_replace('_',' ',ucfirst($s)) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Budget</label>
            <input type="number" step="0.01" min="0" name="budget" id="fBudget" class="form-control" placeholder="0.00">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Amount Spent</label>
            <input type="number" step="0.01" min="0" name="spent" id="fSpent" class="form-control" value="0" placeholder="0.00">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Start Date</label>
            <input type="date" name="start_date" id="fStart" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Target End Date</label>
            <input type="date" name="end_date" id="fEnd" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Description</label>
            <textarea name="description" id="fDesc" class="form-control" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-2"></i>Save Project</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Update Spending Modal -->
<div class="modal fade" id="spentModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header"><h6 class="modal-title">Update Spending</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_spent">
        <input type="hidden" name="project_id" id="spentId">
        <div class="modal-body">
          <label class="form-label fw-semibold">Total Amount Spent</label>
          <input type="number" step="0.01" min="0" name="spent" id="spentVal" class="form-control" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-save me-1"></i>Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function editProject(p) {
  document.getElementById('editId').value = p.id;
  document.getElementById('fName').value = p.name;
  document.getElementById('fCat').value = p.category;
  document.getElementById('fStatus').value = p.status;
  document.getElementById('fBudget').value = p.budget;
  document.getElementById('fSpent').value = p.spent;
  document.getElementById('fStart').value = p.start_date;
  document.getElementById('fEnd').value = p.end_date || '';
  document.getElementById('fDesc').value = p.description || '';
  document.getElementById('projModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Project';
  new bootstrap.Modal(document.getElementById('projectModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
