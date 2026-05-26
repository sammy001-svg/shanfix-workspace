<?php
// ── HRM: Training & Development ───────────────────────────────
$moduleSlug  = 'hrm';
$moduleName  = 'Human Resource Management';
$moduleIcon  = 'fas fa-users-cog';
$moduleColor = '#2c3e50';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',       'label' => 'Dashboard'],
    ['url' => 'employees.php',    'icon' => 'fas fa-id-badge',             'label' => 'Employees'],
    ['url' => 'departments.php',  'icon' => 'fas fa-sitemap',              'label' => 'Departments'],
    ['url' => 'payroll.php',      'icon' => 'fas fa-money-check',          'label' => 'Payroll'],
    ['url' => 'leave.php',        'icon' => 'fas fa-calendar-minus',       'label' => 'Leave'],
    ['url' => 'attendance.php',   'icon' => 'fas fa-fingerprint',          'label' => 'Attendance'],
    ['url' => 'recruitment.php',  'icon' => 'fas fa-user-plus',            'label' => 'Recruitment'],
    ['url' => 'performance.php',  'icon' => 'fas fa-star',                 'label' => 'Performance'],
    ['url' => 'training.php',     'icon' => 'fas fa-chalkboard-teacher',   'label' => 'Training'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',            'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_session') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = sanitize($_POST['title'] ?? '');
        $category    = sanitize($_POST['category'] ?? '');
        $trainer     = sanitize($_POST['trainer'] ?? '');
        $type        = sanitize($_POST['training_type'] ?? 'internal');
        $startDate   = sanitize($_POST['start_date'] ?? '');
        $endDate     = sanitize($_POST['end_date'] ?? '');
        $location    = sanitize($_POST['location'] ?? '');
        $maxPart     = (int)($_POST['max_participants'] ?? 0) ?: null;
        $cost        = (float)($_POST['cost'] ?? 0);
        $description = sanitize($_POST['description'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['scheduled','ongoing','completed','cancelled']) ? $_POST['status'] : 'scheduled';

        if (!$title || !$startDate || !$endDate) { setFlash('danger', 'Title and dates required.'); redirect('training.php'); }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE hrm_training_sessions SET title=?, category=?, trainer=?, training_type=?, start_date=?, end_date=?, location=?, max_participants=?, cost=?, description=?, status=?, updated_at=NOW() WHERE id=? AND org_id=?")
                    ->execute([$title, $category, $trainer, $type, $startDate, $endDate, $location, $maxPart, $cost, $description, $status, $id, $orgId]);
                setFlash('success', 'Training session updated.');
            } else {
                $pdo->prepare("INSERT INTO hrm_training_sessions (org_id, title, category, trainer, training_type, start_date, end_date, location, max_participants, cost, description, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $title, $category, $trainer, $type, $startDate, $endDate, $location, $maxPart, $cost, $description, $status]);
                setFlash('success', "Training session '{$title}' created.");
                logActivity('create', 'hrm', "Training session: {$title}");
            }
        } catch (Exception $e) { setFlash('danger', 'Error: ' . $e->getMessage()); }
        redirect('training.php');
    }

    if ($action === 'enroll') {
        $sessionId  = (int)($_POST['session_id'] ?? 0);
        $empIds     = array_filter(array_map('intval', (array)($_POST['employee_ids'] ?? [])));
        $enrolled   = 0;
        try {
            foreach ($empIds as $eid) {
                $pdo->prepare("INSERT IGNORE INTO hrm_training_attendance (session_id, employee_id, org_id, status) VALUES (?,?,?,'enrolled')")
                    ->execute([$sessionId, $eid, $orgId]);
                $enrolled++;
            }
            setFlash('success', "{$enrolled} employee(s) enrolled.");
        } catch (Exception $e) { setFlash('danger', 'Error: ' . $e->getMessage()); }
        redirect('training.php?session=' . $sessionId);
    }

    if ($action === 'update_attendance') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = sanitize($_POST['status'] ?? '');
        $score  = (float)($_POST['score'] ?? 0) ?: null;
        $certNo = sanitize($_POST['certificate_no'] ?? '') ?: null;
        $allowed = ['enrolled','attended','absent','completed','dropped'];
        if ($id && in_array($status, $allowed)) {
            $pdo->prepare("UPDATE hrm_training_attendance SET status=?, score=?, certificate_no=? WHERE id=? AND org_id=?")
                ->execute([$status, $score, $certNo, $id, $orgId]);
            setFlash('success', 'Attendance updated.');
        }
        redirect('training.php?session=' . ($_POST['session_id'] ?? ''));
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];
$fSession = (int)($_GET['session'] ?? 0);

$sessions = $attendees = $employees = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, COUNT(a.id) AS enrolled_count
        FROM hrm_training_sessions s
        LEFT JOIN hrm_training_attendance a ON a.session_id = s.id
        WHERE s.org_id = ?
        GROUP BY s.id
        ORDER BY s.start_date DESC
    ");
    $stmt->execute([$orgId]);
    $sessions = $stmt->fetchAll();

    if ($fSession) {
        $stmt = $pdo->prepare("
            SELECT a.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name
            FROM hrm_training_attendance a
            JOIN hrm_employees e ON e.id = a.employee_id
            WHERE a.session_id = ? AND a.org_id = ?
            ORDER BY emp_name
        ");
        $stmt->execute([$fSession, $orgId]);
        $attendees = $stmt->fetchAll();
    }

    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM hrm_employees WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]);
    $employees = $stmt->fetchAll();
} catch (Exception $e) {}

$scheduledCount = count(array_filter($sessions, fn($s) => $s['status'] === 'scheduled'));
$completedCount = count(array_filter($sessions, fn($s) => $s['status'] === 'completed'));
$totalCost      = array_sum(array_column($sessions, 'cost'));

$typeColors = ['internal'=>'primary','external'=>'info','online'=>'success','workshop'=>'warning','conference'=>'secondary'];
$statusColors= ['scheduled'=>'primary','ongoing'=>'warning','completed'=>'success','cancelled'=>'danger'];
$attColors   = ['enrolled'=>'secondary','attended'=>'info','absent'=>'danger','completed'=>'success','dropped'=>'warning'];
$types = ['internal'=>'Internal','external'=>'External','online'=>'Online','workshop'=>'Workshop','conference'=>'Conference'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chalkboard-teacher me-2" style="color:<?= $moduleColor ?>"></i>Training & Development</h4>
    <p class="text-muted mb-0">Schedule training sessions, enroll employees and track completion</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#sessModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>New Session
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(44,62,80,.12);color:#2c3e50"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $scheduledCount ?></div><div class="stat-label">Scheduled</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-graduation-cap"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $completedCount ?></div><div class="stat-label">Completed</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalCost) ?></div><div class="stat-label">Total Cost</div></div></div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body p-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead class="table-light"><tr><th>Session</th><th>Category</th><th>Type</th><th>Dates</th><th>Trainer</th><th class="text-center">Enrolled</th><th class="text-end">Cost</th><th class="text-center">Status</th><th class="text-center">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($sessions)): ?>
        <tr><td colspan="9" class="text-center py-5 text-muted"><i class="fas fa-chalkboard-teacher fa-3x mb-3 d-block"></i>No training sessions.</td></tr>
        <?php else: foreach ($sessions as $s): ?>
        <tr class="<?= $fSession===$s['id']?'table-active':'' ?>">
          <td class="fw-semibold"><?= e($s['title']) ?></td>
          <td class="small text-muted"><?= e($s['category'] ?: '—') ?></td>
          <td><span class="badge bg-<?= $typeColors[$s['training_type']] ?? 'secondary' ?>"><?= $types[$s['training_type']] ?? e($s['training_type']) ?></span></td>
          <td class="small"><?= date('d M', strtotime($s['start_date'])) ?> – <?= date('d M Y', strtotime($s['end_date'])) ?></td>
          <td class="small"><?= e($s['trainer'] ?: '—') ?></td>
          <td class="text-center">
            <a href="?session=<?= $s['id'] ?>" class="badge bg-primary text-white"><?= $s['enrolled_count'] ?></a>
          </td>
          <td class="text-end"><?= formatCurrency((float)$s['cost']) ?></td>
          <td class="text-center"><span class="badge bg-<?= $statusColors[$s['status']] ?? 'secondary' ?>"><?= ucfirst($s['status']) ?></span></td>
          <td class="text-center" style="white-space:nowrap">
            <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-outline-success ms-1" data-bs-toggle="modal" data-bs-target="#enrollModal"
              onclick="document.getElementById('enrSessionId').value=<?= $s['id'] ?>"><i class="fas fa-user-plus"></i></button>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div></div>

<?php if ($fSession && !empty($attendees)): ?>
<div class="card border-0 shadow-sm"><div class="card-header bg-light fw-semibold">Attendance — Session #<?= $fSession ?></div>
  <div class="card-body p-0">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Employee</th><th class="text-center">Status</th><th class="text-center">Score</th><th>Certificate No</th><th class="text-center">Update</th></tr></thead>
      <tbody>
        <?php foreach ($attendees as $a): ?>
        <tr>
          <td><?= e($a['emp_name']) ?></td>
          <td class="text-center"><span class="badge bg-<?= $attColors[$a['status']] ?? 'secondary' ?>"><?= ucfirst($a['status']) ?></span></td>
          <td class="text-center"><?= $a['score'] !== null ? $a['score'] . '%' : '—' ?></td>
          <td class="small"><?= e($a['certificate_no'] ?: '—') ?></td>
          <td class="text-center">
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#attModal"
              onclick="setAtt(<?= $a['id'] ?>, '<?= $fSession ?>', '<?= $a['status'] ?>', '<?= $a['score'] ?>', '<?= e($a['certificate_no']) ?>')">
              <i class="fas fa-edit"></i></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Session Modal -->
<div class="modal fade" id="sessModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="save_session"><input type="hidden" name="id" id="sessId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="sessTitle"><i class="fas fa-chalkboard-teacher me-2"></i>New Session</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
            <input type="text" name="title" id="sessTitle2" class="form-control" required></div>
          <div class="col-md-4"><label class="form-label fw-semibold">Type</label>
            <select name="training_type" id="sessType" class="form-select">
              <?php foreach ($types as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Category</label>
            <input type="text" name="category" id="sessCat" class="form-control" placeholder="e.g. Safety, Leadership"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Trainer</label>
            <input type="text" name="trainer" id="sessTrainer" class="form-control"></div>
          <div class="col-md-4"><label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
            <input type="date" name="start_date" id="sessStart" class="form-control" required></div>
          <div class="col-md-4"><label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label>
            <input type="date" name="end_date" id="sessEnd" class="form-control" required></div>
          <div class="col-md-4"><label class="form-label fw-semibold">Max Participants</label>
            <input type="number" name="max_participants" id="sessMax" class="form-control" min="1"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Location</label>
            <input type="text" name="location" id="sessLoc" class="form-control"></div>
          <div class="col-md-3"><label class="form-label fw-semibold">Cost (KES)</label>
            <input type="number" name="cost" id="sessCost" class="form-control" min="0" step="0.01" value="0"></div>
          <div class="col-md-3"><label class="form-label fw-semibold">Status</label>
            <select name="status" id="sessStatus" class="form-select">
              <option value="scheduled">Scheduled</option><option value="ongoing">Ongoing</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option>
            </select></div>
          <div class="col-12"><label class="form-label fw-semibold">Description</label>
            <textarea name="description" id="sessDesc" class="form-control" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Enroll Modal -->
<div class="modal fade" id="enrollModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="enroll"><input type="hidden" name="session_id" id="enrSessionId">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Enroll Employees</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="max-height:350px;overflow-y:auto">
        <?php foreach ($employees as $e): ?>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="employee_ids[]" value="<?= $e['id'] ?>" id="enr<?= $e['id'] ?>">
          <label class="form-check-label" for="enr<?= $e['id'] ?>"><?= e($e['first_name'] . ' ' . $e['last_name']) ?></label>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-user-plus me-1"></i>Enroll</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Attendance Update Modal -->
<div class="modal fade" id="attModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="update_attendance">
      <input type="hidden" name="id" id="attId"><input type="hidden" name="session_id" id="attSessId">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Update Attendance</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-semibold">Status</label>
          <select name="status" id="attStatus" class="form-select">
            <?php foreach (['enrolled','attended','absent','completed','dropped'] as $s): ?><option value="<?= $s ?>"><?= ucfirst($s) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label fw-semibold">Score (%)</label>
          <input type="number" name="score" id="attScore" class="form-control" min="0" max="100" step="0.1"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Certificate No</label>
          <input type="text" name="certificate_no" id="attCert" class="form-control"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Update</button>
      </div>
    </form>
  </div></div>
</div>

<?php $extraJs = <<<'JS'
<script>
function openAdd() {
    document.getElementById('sessTitle').innerHTML = '<i class="fas fa-chalkboard-teacher me-2"></i>New Session';
    document.getElementById('sessId').value = '0';
    ['sessTitle2','sessCat','sessTrainer','sessStart','sessEnd','sessLoc','sessDesc'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('sessType').value   = 'internal';
    document.getElementById('sessCost').value   = '0';
    document.getElementById('sessStatus').value = 'scheduled';
    document.getElementById('sessMax').value    = '';
}
function openEdit(s) {
    document.getElementById('sessTitle').innerHTML  = '<i class="fas fa-edit me-2"></i>Edit Session';
    document.getElementById('sessId').value         = s.id;
    document.getElementById('sessTitle2').value     = s.title;
    document.getElementById('sessCat').value        = s.category || '';
    document.getElementById('sessTrainer').value    = s.trainer || '';
    document.getElementById('sessType').value       = s.training_type;
    document.getElementById('sessStart').value      = s.start_date;
    document.getElementById('sessEnd').value        = s.end_date;
    document.getElementById('sessLoc').value        = s.location || '';
    document.getElementById('sessMax').value        = s.max_participants || '';
    document.getElementById('sessCost').value       = s.cost;
    document.getElementById('sessStatus').value     = s.status;
    document.getElementById('sessDesc').value       = s.description || '';
    new bootstrap.Modal(document.getElementById('sessModal')).show();
}
function setAtt(id, sessId, status, score, cert) {
    document.getElementById('attId').value      = id;
    document.getElementById('attSessId').value  = sessId;
    document.getElementById('attStatus').value  = status;
    document.getElementById('attScore').value   = score || '';
    document.getElementById('attCert').value    = cert || '';
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
