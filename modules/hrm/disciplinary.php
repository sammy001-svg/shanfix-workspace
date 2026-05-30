<?php
$moduleSlug  = 'hrm';
$moduleName  = 'HRM System';
$moduleIcon  = 'fas fa-users-cog';
$moduleColor = '#2c3e50';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',     'label' => 'Dashboard'],
    ['url' => 'employees.php',    'icon' => 'fas fa-id-badge',           'label' => 'Employees'],
    ['url' => 'departments.php',  'icon' => 'fas fa-sitemap',            'label' => 'Departments'],
    ['url' => 'payroll.php',      'icon' => 'fas fa-money-check',        'label' => 'Payroll'],
    ['url' => 'leave.php',        'icon' => 'fas fa-calendar-minus',     'label' => 'Leave'],
    ['url' => 'attendance.php',   'icon' => 'fas fa-fingerprint',        'label' => 'Attendance'],
    ['url' => 'benefits.php',     'icon' => 'fas fa-gift',               'label' => 'Benefits'],
    ['url' => 'disciplinary.php', 'icon' => 'fas fa-gavel',              'label' => 'Disciplinary'],
    ['url' => 'recruitment.php',  'icon' => 'fas fa-user-plus',          'label' => 'Recruitment'],
    ['url' => 'performance.php',  'icon' => 'fas fa-star',               'label' => 'Performance'],
    ['url' => 'training.php',     'icon' => 'fas fa-chalkboard-teacher', 'label' => 'Training'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],
];

$createTable = "CREATE TABLE IF NOT EXISTS hrm_disciplinary (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    org_id          INT NOT NULL,
    employee_id     INT NOT NULL,
    incident_date   DATE NOT NULL,
    type            ENUM('verbal_warning','written_warning','final_warning','suspension','termination','other') DEFAULT 'verbal_warning',
    subject         VARCHAR(255) NOT NULL,
    description     TEXT,
    action_taken    TEXT,
    outcome         ENUM('pending','resolved','escalated','dismissed') DEFAULT 'pending',
    reviewed_by     INT DEFAULT NULL,
    hearing_date    DATE DEFAULT NULL,
    notes           TEXT,
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    try { $pdo->exec($createTable); } catch (Exception $e) {}
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = (int)($_POST['id']           ?? 0);
        $empId        = (int)($_POST['employee_id']  ?? 0);
        $incidentDate = $_POST['incident_date']      ?? date('Y-m-d');
        $type         = in_array($_POST['type'] ?? '', ['verbal_warning','written_warning','final_warning','suspension','termination','other']) ? $_POST['type'] : 'verbal_warning';
        $subject      = sanitize($_POST['subject']       ?? '');
        $desc         = sanitize($_POST['description']   ?? '');
        $action_taken = sanitize($_POST['action_taken']  ?? '');
        $outcome      = in_array($_POST['outcome'] ?? '', ['pending','resolved','escalated','dismissed']) ? $_POST['outcome'] : 'pending';
        $hearingDate  = !empty($_POST['hearing_date']) ? $_POST['hearing_date'] : null;
        $notes        = sanitize($_POST['notes'] ?? '');

        if (!$empId || !$subject) { setFlash('danger', 'Employee and subject are required.'); redirect('disciplinary.php'); }

        if ($id > 0) {
            $pdo->prepare("UPDATE hrm_disciplinary SET employee_id=?,incident_date=?,type=?,subject=?,description=?,action_taken=?,outcome=?,hearing_date=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$empId, $incidentDate, $type, $subject, $desc, $action_taken, $outcome, $hearingDate, $notes, $id, $orgId]);
            setFlash('success', 'Disciplinary record updated.');
            logActivity('update', 'hrm', "Updated disciplinary record #$id");
        } else {
            $pdo->prepare("INSERT INTO hrm_disciplinary (org_id,employee_id,incident_date,type,subject,description,action_taken,outcome,hearing_date,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $empId, $incidentDate, $type, $subject, $desc, $action_taken, $outcome, $hearingDate, $notes, $user['id']]);
            setFlash('success', 'Disciplinary record created.');
            logActivity('create', 'hrm', "Created disciplinary: $subject for employee #$empId");
        }
        redirect('disciplinary.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM hrm_disciplinary WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Record deleted.');
        redirect('disciplinary.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];
try { $pdo->exec($createTable); } catch (Exception $e) {}

$filterEmp     = (int)($_GET['emp']     ?? 0);
$filterOutcome = $_GET['outcome']        ?? '';
$filterType    = $_GET['type']           ?? '';

$where  = 'd.org_id=?';
$params = [$orgId];
if ($filterEmp)     { $where .= ' AND d.employee_id=?'; $params[] = $filterEmp; }
if ($filterOutcome) { $where .= ' AND d.outcome=?';     $params[] = $filterOutcome; }
if ($filterType)    { $where .= ' AND d.type=?';        $params[] = $filterType; }

$records = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_no, e.position,
               dep.name AS dept_name
        FROM hrm_disciplinary d
        JOIN hrm_employees e ON d.employee_id=e.id
        LEFT JOIN hrm_departments dep ON e.department_id=dep.id
        WHERE $where ORDER BY d.incident_date DESC, d.id DESC
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll();
} catch (Exception $e) {}

$totalPending  = count(array_filter($records, fn($r) => $r['outcome'] === 'pending'));
$totalResolved = count(array_filter($records, fn($r) => $r['outcome'] === 'resolved'));
$totalEscalated = count(array_filter($records, fn($r) => $r['outcome'] === 'escalated'));

$employees = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, employee_no FROM hrm_employees WHERE org_id=? AND status IN ('active','on_leave') ORDER BY first_name");
    $stmt->execute([$orgId]); $employees = $stmt->fetchAll();
} catch (Exception $e) {}

$typeMeta = [
    'verbal_warning'  => ['label' => 'Verbal Warning',  'color' => '#f39c12'],
    'written_warning' => ['label' => 'Written Warning', 'color' => '#e67e22'],
    'final_warning'   => ['label' => 'Final Warning',   'color' => '#e74c3c'],
    'suspension'      => ['label' => 'Suspension',      'color' => '#8e44ad'],
    'termination'     => ['label' => 'Termination',     'color' => '#2c3e50'],
    'other'           => ['label' => 'Other',           'color' => '#7f8c8d'],
];
$outcomeColors = ['pending'=>'warning','resolved'=>'success','escalated'=>'danger','dismissed'=>'secondary'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-gavel me-2" style="color:<?= $moduleColor ?>"></i>Disciplinary Records</h4>
    <p class="text-muted mb-0">Track warnings, hearings, and disciplinary outcomes</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#discModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>New Record
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalPending ?></div><div class="stat-label">Pending</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalResolved ?></div><div class="stat-label">Resolved</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-arrow-alt-circle-up"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalEscalated ?></div><div class="stat-label">Escalated</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Employee</label>
        <select name="emp" class="form-select form-select-sm">
          <option value="">All Employees</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $filterEmp == $e['id'] ? 'selected' : '' ?>><?= e($e['employee_no'].' — '.$e['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <?php foreach ($typeMeta as $k => $v): ?>
            <option value="<?= $k ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Outcome</label>
        <select name="outcome" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach (['pending','resolved','escalated','dismissed'] as $o): ?>
            <option value="<?= $o ?>" <?= $filterOutcome === $o ? 'selected' : '' ?>><?= ucfirst($o) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="disciplinary.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-gavel me-2" style="color:<?= $moduleColor ?>"></i>Disciplinary Log</h6>
    <span class="badge bg-secondary"><?= count($records) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr><th>Employee</th><th>Date</th><th>Type</th><th>Subject</th><th>Outcome</th><th>Hearing</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($records)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-gavel fa-2x mb-2 d-block opacity-25"></i>No disciplinary records found.</td></tr>
          <?php else: foreach ($records as $r):
            $tm = $typeMeta[$r['type']] ?? ['label'=>ucfirst($r['type']),'color'=>'#64748b'];
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($r['emp_name']) ?></div>
              <div class="small text-muted"><?= e($r['employee_no']) ?> · <?= e($r['dept_name'] ?? '') ?></div>
            </td>
            <td class="small"><?= formatDate($r['incident_date']) ?></td>
            <td><span class="badge" style="background:<?= $tm['color'] ?>"><?= e($tm['label']) ?></span></td>
            <td>
              <div class="fw-semibold small"><?= e($r['subject']) ?></div>
              <?php if ($r['description']): ?>
                <div class="text-muted" style="font-size:.72rem"><?= e(mb_substr($r['description'], 0, 60)) ?><?= mb_strlen($r['description']) > 60 ? '…' : '' ?></div>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $outcomeColors[$r['outcome']] ?? 'secondary' ?>"><?= ucfirst($r['outcome']) ?></span></td>
            <td class="small"><?= $r['hearing_date'] ? formatDate($r['hearing_date']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)' title="Edit/View"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delRec(<?= $r['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="discModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="dId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="dTitle"><i class="fas fa-gavel me-2"></i>New Disciplinary Record</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
              <select name="employee_id" id="dEmp" class="form-select" required>
                <option value="">-- Select employee --</option>
                <?php foreach ($employees as $e): ?>
                  <option value="<?= $e['id'] ?>"><?= e($e['employee_no'].' — '.$e['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Incident Date <span class="text-danger">*</span></label>
              <input type="date" name="incident_date" id="dDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Disciplinary Type <span class="text-danger">*</span></label>
              <select name="type" id="dType" class="form-select" required>
                <?php foreach ($typeMeta as $k => $v): ?>
                  <option value="<?= $k ?>"><?= e($v['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Outcome</label>
              <select name="outcome" id="dOutcome" class="form-select">
                <option value="pending">Pending</option>
                <option value="resolved">Resolved</option>
                <option value="escalated">Escalated</option>
                <option value="dismissed">Dismissed</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Subject / Incident Title <span class="text-danger">*</span></label>
              <input type="text" name="subject" id="dSubject" class="form-control" required placeholder="Brief description of the incident">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" id="dDesc" class="form-control" rows="3" placeholder="Detailed account of what happened"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Action Taken</label>
              <textarea name="action_taken" id="dAction" class="form-control" rows="2" placeholder="What disciplinary action was taken?"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Hearing Date</label>
              <input type="date" name="hearing_date" id="dHearing" class="form-control">
              <div class="form-text">Leave blank if no formal hearing required</div>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Additional Notes</label>
              <textarea name="notes" id="dNotes" class="form-control" rows="2" placeholder="Any other relevant notes or follow-up actions"></textarea>
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

<!-- Delete form -->
<form method="POST" id="delForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('dTitle').innerHTML = '<i class="fas fa-gavel me-2"></i>New Disciplinary Record';
  ['dId','dEmp','dType','dOutcome','dSubject','dDesc','dAction','dHearing','dNotes'].forEach(id => {
    const el = document.getElementById(id); if(el) el.value = '';
  });
  document.getElementById('dId').value      = 0;
  document.getElementById('dDate').value    = new Date().toISOString().split('T')[0];
  document.getElementById('dType').value    = 'verbal_warning';
  document.getElementById('dOutcome').value = 'pending';
}
function openEdit(r) {
  document.getElementById('dTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Disciplinary Record';
  document.getElementById('dId').value      = r.id;
  document.getElementById('dEmp').value     = r.employee_id || '';
  document.getElementById('dDate').value    = r.incident_date || '';
  document.getElementById('dType').value    = r.type || 'verbal_warning';
  document.getElementById('dOutcome').value = r.outcome || 'pending';
  document.getElementById('dSubject').value = r.subject || '';
  document.getElementById('dDesc').value    = r.description || '';
  document.getElementById('dAction').value  = r.action_taken || '';
  document.getElementById('dHearing').value = r.hearing_date || '';
  document.getElementById('dNotes').value   = r.notes || '';
  new bootstrap.Modal(document.getElementById('discModal')).show();
}
function delRec(id) {
  Swal.fire({ title:'Delete this record?', text:'This action cannot be undone.', icon:'warning', showCancelButton:true,
    confirmButtonColor:'#e74c3c', confirmButtonText:'Delete'
  }).then(r => { if (r.isConfirmed) { document.getElementById('delId').value=id; document.getElementById('delForm').submit(); } });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
