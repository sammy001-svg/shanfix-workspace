<?php
// ── HRM: Recruitment ──────────────────────────────────────────
$moduleSlug  = 'hrm';
$moduleName  = 'Human Resource Management';
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
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_opening') {
        $id           = (int)($_POST['id'] ?? 0);
        $title        = sanitize($_POST['title'] ?? '');
        $deptId       = (int)($_POST['department_id'] ?? 0) ?: null;
        $jobType      = sanitize($_POST['job_type'] ?? 'full_time');
        $location     = sanitize($_POST['location'] ?? '');
        $vacancies    = max(1, (int)($_POST['vacancies'] ?? 1));
        $salaryMin    = (float)($_POST['salary_min'] ?? 0) ?: null;
        $salaryMax    = (float)($_POST['salary_max'] ?? 0) ?: null;
        $description  = sanitize($_POST['description'] ?? '');
        $requirements = sanitize($_POST['requirements'] ?? '');
        $closingDate  = sanitize($_POST['closing_date'] ?? '') ?: null;
        $status       = in_array($_POST['status'] ?? '', ['open','closed','on_hold','filled']) ? $_POST['status'] : 'open';

        if (!$title) { setFlash('danger', 'Job title is required.'); redirect('recruitment.php'); }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE hrm_job_openings SET title=?, department_id=?, job_type=?, location=?, vacancies=?, salary_min=?, salary_max=?, description=?, requirements=?, closing_date=?, status=?, updated_at=NOW() WHERE id=? AND org_id=?")
                    ->execute([$title, $deptId, $jobType, $location, $vacancies, $salaryMin, $salaryMax, $description, $requirements, $closingDate, $status, $id, $orgId]);
                setFlash('success', 'Job opening updated.');
            } else {
                $pdo->prepare("INSERT INTO hrm_job_openings (org_id, title, department_id, job_type, location, vacancies, salary_min, salary_max, description, requirements, closing_date, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $title, $deptId, $jobType, $location, $vacancies, $salaryMin, $salaryMax, $description, $requirements, $closingDate, $status]);
                setFlash('success', "Job opening '{$title}' posted.");
                logActivity('create', 'hrm', "Job opening: {$title}");
            }
        } catch (Exception $e) { setFlash('danger', 'Error: ' . $e->getMessage()); }
        redirect('recruitment.php');
    }

    if ($action === 'save_application') {
        $openingId   = (int)($_POST['opening_id'] ?? 0);
        $name        = sanitize($_POST['applicant_name'] ?? '');
        $email       = sanitize($_POST['email'] ?? '');
        $phone       = sanitize($_POST['phone'] ?? '');
        $employer    = sanitize($_POST['current_employer'] ?? '');
        $expYears    = (int)($_POST['experience_years'] ?? 0);
        $coverNote   = sanitize($_POST['cover_note'] ?? '');
        $stage       = 'applied';

        if (!$openingId || !$name) { setFlash('danger', 'Opening and applicant name required.'); redirect('recruitment.php'); }

        try {
            $pdo->prepare("INSERT INTO hrm_applications (org_id, opening_id, applicant_name, email, phone, current_employer, experience_years, cover_note, stage) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $openingId, $name, $email, $phone, $employer, $expYears, $coverNote, $stage]);
            setFlash('success', "Application from {$name} recorded.");
            logActivity('create', 'hrm', "Application: {$name} for opening #{$openingId}");
        } catch (Exception $e) { setFlash('danger', 'Error: ' . $e->getMessage()); }
        redirect('recruitment.php?tab=applications');
    }

    if ($action === 'update_stage') {
        $appId = (int)($_POST['app_id'] ?? 0);
        $stage = sanitize($_POST['stage'] ?? '');
        $allowed = ['applied','shortlisted','interview','offer','hired','rejected'];
        if ($appId && in_array($stage, $allowed)) {
            $interviewDate = ($stage === 'interview' && !empty($_POST['interview_date']))
                ? sanitize($_POST['interview_date']) : null;
            $pdo->prepare("UPDATE hrm_applications SET stage=?, interview_date=COALESCE(?,interview_date), updated_at=NOW() WHERE id=? AND org_id=?")
                ->execute([$stage, $interviewDate, $appId, $orgId]);
            setFlash('success', 'Application stage updated.');
        }
        redirect('recruitment.php?tab=applications');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = $_GET['tab'] ?? 'openings';
$fOpening = (int)($_GET['opening_id'] ?? 0);

$openings = $applications = $departments = [];
try {
    $stmt = $pdo->prepare("
        SELECT o.*, d.name AS dept_name, COUNT(a.id) AS applicant_count
        FROM hrm_job_openings o
        LEFT JOIN hrm_departments d ON d.id = o.department_id
        LEFT JOIN hrm_applications a ON a.opening_id = o.id
        WHERE o.org_id = ?
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $openings = $stmt->fetchAll();

    $appWhere = 'a.org_id = ?'; $appParams = [$orgId];
    if ($fOpening) { $appWhere .= ' AND a.opening_id = ?'; $appParams[] = $fOpening; }
    $stmt = $pdo->prepare("
        SELECT a.*, o.title AS job_title
        FROM hrm_applications a
        JOIN hrm_job_openings o ON o.id = a.opening_id
        WHERE {$appWhere}
        ORDER BY a.applied_at DESC
    ");
    $stmt->execute($appParams);
    $applications = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, name FROM hrm_departments WHERE org_id=? ORDER BY name");
    $stmt->execute([$orgId]);
    $departments = $stmt->fetchAll();
} catch (Exception $e) {}

$openCount  = count(array_filter($openings, fn($o) => $o['status'] === 'open'));
$hiredCount = count(array_filter($applications, fn($a) => $a['stage'] === 'hired'));
$statusColors = ['open'=>'success','closed'=>'secondary','on_hold'=>'warning','filled'=>'info'];
$stageColors  = ['applied'=>'secondary','shortlisted'=>'info','interview'=>'primary','offer'=>'warning','hired'=>'success','rejected'=>'danger'];
$jobTypes = ['full_time'=>'Full Time','part_time'=>'Part Time','contract'=>'Contract','internship'=>'Internship','volunteer'=>'Volunteer'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-user-plus me-2" style="color:<?= $moduleColor ?>"></i>Recruitment</h4>
    <p class="text-muted mb-0">Post job openings, receive applications and track candidate pipeline</p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#appModal"><i class="fas fa-file-alt me-1"></i>Add Application</button>
    <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#jobModal" onclick="openAddJob()"><i class="fas fa-plus me-1"></i>Post Job</button>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(44,62,80,.12);color:#2c3e50"><i class="fas fa-briefcase"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $openCount ?></div><div class="stat-label">Open Positions</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($applications) ?></div><div class="stat-label">Total Applicants</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-user-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $hiredCount ?></div><div class="stat-label">Hired</div></div></div>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='openings'?'active':'' ?>" href="?tab=openings">Job Openings <span class="badge bg-secondary ms-1"><?= count($openings) ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='applications'?'active':'' ?>" href="?tab=applications">Applications <span class="badge bg-secondary ms-1"><?= count($applications) ?></span></a></li>
</ul>

<?php if ($tab === 'openings'): ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead class="table-light"><tr><th>Job Title</th><th>Department</th><th>Type</th><th class="text-center">Vacancies</th><th>Closing Date</th><th class="text-center">Applicants</th><th class="text-center">Status</th><th class="text-center">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($openings)): ?>
        <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-briefcase fa-3x mb-3 d-block"></i>No job openings posted.</td></tr>
        <?php else: foreach ($openings as $o): ?>
        <tr>
          <td class="fw-semibold"><?= e($o['title']) ?></td>
          <td><?= $o['dept_name'] ? e($o['dept_name']) : '<span class="text-muted">—</span>' ?></td>
          <td><span class="badge bg-light text-dark border"><?= $jobTypes[$o['job_type']] ?? e($o['job_type']) ?></span></td>
          <td class="text-center"><?= $o['vacancies'] ?></td>
          <td class="small"><?= $o['closing_date'] ? date('d M Y', strtotime($o['closing_date'])) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-center"><a href="?tab=applications&opening_id=<?= $o['id'] ?>" class="badge bg-primary text-white"><?= $o['applicant_count'] ?></a></td>
          <td class="text-center"><span class="badge bg-<?= $statusColors[$o['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_',' ',$o['status'])) ?></span></td>
          <td class="text-center"><button class="btn btn-sm btn-outline-primary" onclick='openEditJob(<?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div></div>

<?php else: ?>
<?php if ($fOpening): ?>
<div class="alert alert-info py-2 mb-3"><i class="fas fa-filter me-1"></i>Filtered by opening. <a href="?tab=applications">Show all</a></div>
<?php endif; ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead class="table-light"><tr><th>Applicant</th><th>Job</th><th>Contact</th><th>Experience</th><th class="text-center">Stage</th><th>Applied</th><th class="text-center">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($applications)): ?>
        <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-file-alt fa-3x mb-3 d-block"></i>No applications found.</td></tr>
        <?php else: foreach ($applications as $a): ?>
        <tr>
          <td class="fw-semibold"><?= e($a['applicant_name']) ?></td>
          <td class="small"><?= e($a['job_title']) ?></td>
          <td class="small"><?= e($a['phone']) ?><?= $a['email'] ? '<br><span class="text-muted">'.e($a['email']).'</span>' : '' ?></td>
          <td class="small"><?= $a['experience_years'] ?> yr(s)</td>
          <td class="text-center"><span class="badge bg-<?= $stageColors[$a['stage']] ?? 'secondary' ?>"><?= ucfirst($a['stage']) ?></span></td>
          <td class="small text-muted"><?= date('d M Y', strtotime($a['applied_at'])) ?></td>
          <td class="text-center">
            <div class="dropdown">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Move</button>
              <ul class="dropdown-menu dropdown-menu-end">
                <?php foreach (['shortlisted','interview','offer','hired','rejected'] as $st): ?>
                <form method="POST" class="d-block">
                  <?= csrfField() ?><input type="hidden" name="action" value="update_stage">
                  <input type="hidden" name="app_id" value="<?= $a['id'] ?>"><input type="hidden" name="stage" value="<?= $st ?>">
                  <li><button type="submit" class="dropdown-item small"><?= ucfirst($st) ?></button></li>
                </form>
                <?php endforeach; ?>
              </ul>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div></div>
<?php endif; ?>

<!-- Job Opening Modal -->
<div class="modal fade" id="jobModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="save_opening"><input type="hidden" name="id" id="jobId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="jobTitle"><i class="fas fa-briefcase me-2"></i>Post Job Opening</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label fw-semibold">Job Title <span class="text-danger">*</span></label>
            <input type="text" name="title" id="jobTitleInput" class="form-control" required></div>
          <div class="col-md-4"><label class="form-label fw-semibold">Vacancies</label>
            <input type="number" name="vacancies" id="jobVacancies" class="form-control" min="1" value="1"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Department</label>
            <select name="department_id" id="jobDept" class="form-select">
              <option value="">-- None --</option>
              <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Job Type</label>
            <select name="job_type" id="jobType" class="form-select">
              <?php foreach ($jobTypes as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-4"><label class="form-label fw-semibold">Location</label>
            <input type="text" name="location" id="jobLocation" class="form-control" placeholder="e.g. Nairobi"></div>
          <div class="col-md-4"><label class="form-label fw-semibold">Min Salary (KES)</label>
            <input type="number" name="salary_min" id="jobSalMin" class="form-control" min="0" step="0.01" value="0"></div>
          <div class="col-md-4"><label class="form-label fw-semibold">Max Salary (KES)</label>
            <input type="number" name="salary_max" id="jobSalMax" class="form-control" min="0" step="0.01" value="0"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Closing Date</label>
            <input type="date" name="closing_date" id="jobClosing" class="form-control"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Status</label>
            <select name="status" id="jobStatus" class="form-select">
              <option value="open">Open</option><option value="on_hold">On Hold</option><option value="closed">Closed</option><option value="filled">Filled</option>
            </select></div>
          <div class="col-12"><label class="form-label fw-semibold">Description</label>
            <textarea name="description" id="jobDesc" class="form-control" rows="3"></textarea></div>
          <div class="col-12"><label class="form-label fw-semibold">Requirements</label>
            <textarea name="requirements" id="jobReqs" class="form-control" rows="3"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Application Modal -->
<div class="modal fade" id="appModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="save_application">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Add Application</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12"><label class="form-label fw-semibold">Job Opening <span class="text-danger">*</span></label>
            <select name="opening_id" class="form-select" required>
              <option value="">-- Select Opening --</option>
              <?php foreach ($openings as $o): ?><option value="<?= $o['id'] ?>"><?= e($o['title']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-8"><label class="form-label fw-semibold">Applicant Name <span class="text-danger">*</span></label>
            <input type="text" name="applicant_name" class="form-control" required></div>
          <div class="col-md-4"><label class="form-label fw-semibold">Experience (yrs)</label>
            <input type="number" name="experience_years" class="form-control" min="0" value="0"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Phone</label>
            <input type="text" name="phone" class="form-control"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Email</label>
            <input type="email" name="email" class="form-control"></div>
          <div class="col-12"><label class="form-label fw-semibold">Current Employer</label>
            <input type="text" name="current_employer" class="form-control"></div>
          <div class="col-12"><label class="form-label fw-semibold">Cover Note</label>
            <textarea name="cover_note" class="form-control" rows="3"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Submit</button>
      </div>
    </form>
  </div></div>
</div>

<?php $extraJs = <<<'JS'
<script>
function openAddJob() {
    document.getElementById('jobTitle').innerHTML = '<i class="fas fa-briefcase me-2"></i>Post Job Opening';
    document.getElementById('jobId').value = '0';
    ['jobTitleInput','jobLocation','jobDesc','jobReqs','jobClosing'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('jobDept').value     = '';
    document.getElementById('jobType').value     = 'full_time';
    document.getElementById('jobVacancies').value= '1';
    document.getElementById('jobSalMin').value   = '0';
    document.getElementById('jobSalMax').value   = '0';
    document.getElementById('jobStatus').value   = 'open';
}
function openEditJob(o) {
    document.getElementById('jobTitle').innerHTML   = '<i class="fas fa-edit me-2"></i>Edit Job Opening';
    document.getElementById('jobId').value          = o.id;
    document.getElementById('jobTitleInput').value  = o.title;
    document.getElementById('jobDept').value        = o.department_id || '';
    document.getElementById('jobType').value        = o.job_type;
    document.getElementById('jobLocation').value    = o.location || '';
    document.getElementById('jobVacancies').value   = o.vacancies;
    document.getElementById('jobSalMin').value      = o.salary_min || '0';
    document.getElementById('jobSalMax').value      = o.salary_max || '0';
    document.getElementById('jobClosing').value     = o.closing_date || '';
    document.getElementById('jobStatus').value      = o.status;
    document.getElementById('jobDesc').value        = o.description || '';
    document.getElementById('jobReqs').value        = o.requirements || '';
    new bootstrap.Modal(document.getElementById('jobModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
