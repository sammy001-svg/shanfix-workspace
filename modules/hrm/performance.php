<?php
// ── HRM: Performance Reviews ──────────────────────────────────
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

    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $employeeId   = (int)($_POST['employee_id'] ?? 0);
        $period       = sanitize($_POST['review_period'] ?? '');
        $reviewerName = sanitize($_POST['reviewer_name'] ?? '');
        $kpi          = min(100, max(0, (float)($_POST['kpi_score'] ?? 0)));
        $attendance   = min(100, max(0, (float)($_POST['attendance_score'] ?? 0)));
        $teamwork     = min(100, max(0, (float)($_POST['teamwork_score'] ?? 0)));
        $initiative   = min(100, max(0, (float)($_POST['initiative_score'] ?? 0)));
        $overall      = round(($kpi + $attendance + $teamwork + $initiative) / 4, 2);
        $rating       = sanitize($_POST['rating'] ?? 'meets');
        $strengths    = sanitize($_POST['strengths'] ?? '');
        $improvements = sanitize($_POST['improvements'] ?? '');
        $goalsNext    = sanitize($_POST['goals_next'] ?? '');
        $status       = in_array($_POST['status'] ?? '', ['draft','submitted','approved']) ? $_POST['status'] : 'draft';
        $reviewedAt   = sanitize($_POST['reviewed_at'] ?? '') ?: null;

        if (!$employeeId || !$period) { setFlash('danger', 'Employee and review period required.'); redirect('performance.php'); }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE hrm_performance_reviews SET employee_id=?, review_period=?, reviewer_name=?, kpi_score=?, attendance_score=?, teamwork_score=?, initiative_score=?, overall_score=?, rating=?, strengths=?, improvements=?, goals_next=?, status=?, reviewed_at=?, updated_at=NOW() WHERE id=? AND org_id=?")
                    ->execute([$employeeId, $period, $reviewerName, $kpi, $attendance, $teamwork, $initiative, $overall, $rating, $strengths, $improvements, $goalsNext, $status, $reviewedAt, $id, $orgId]);
                setFlash('success', 'Review updated.');
            } else {
                $pdo->prepare("INSERT INTO hrm_performance_reviews (org_id, employee_id, review_period, reviewer_name, kpi_score, attendance_score, teamwork_score, initiative_score, overall_score, rating, strengths, improvements, goals_next, status, reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $employeeId, $period, $reviewerName, $kpi, $attendance, $teamwork, $initiative, $overall, $rating, $strengths, $improvements, $goalsNext, $status, $reviewedAt]);
                setFlash('success', 'Performance review saved.');
                logActivity('create', 'hrm', "Review for employee #{$employeeId}, period {$period}");
            }
        } catch (Exception $e) { setFlash('danger', 'Error: ' . $e->getMessage()); }
        redirect('performance.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fPeriod = $_GET['period'] ?? '';
$where   = 'r.org_id = ?'; $params = [$orgId];
if ($fPeriod !== '') { $where .= ' AND r.review_period = ?'; $params[] = $fPeriod; }

$reviews = $employees = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, d.name AS dept_name
        FROM hrm_performance_reviews r
        JOIN hrm_employees e ON e.id = r.employee_id
        LEFT JOIN hrm_departments d ON d.id = e.department_id
        WHERE {$where}
        ORDER BY r.created_at DESC
    ");
    $stmt->execute($params);
    $reviews = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM hrm_employees WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]);
    $employees = $stmt->fetchAll();
} catch (Exception $e) {}

$avgScore = count($reviews) ? round(array_sum(array_column($reviews, 'overall_score')) / count($reviews), 1) : 0;
$approvedCount = count(array_filter($reviews, fn($r) => $r['status'] === 'approved'));

$ratingColors = ['exceptional'=>'success','exceeds'=>'info','meets'=>'primary','below'=>'warning','unsatisfactory'=>'danger'];
$ratingLabels = ['exceptional'=>'Exceptional','exceeds'=>'Exceeds Expectations','meets'=>'Meets Expectations','below'=>'Below Expectations','unsatisfactory'=>'Unsatisfactory'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-star me-2" style="color:<?= $moduleColor ?>"></i>Performance Reviews</h4>
    <p class="text-muted mb-0">Conduct employee appraisals, set KPIs and track performance ratings</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#perfModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>New Review
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(44,62,80,.12);color:#2c3e50"><i class="fas fa-star"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $avgScore ?>%</div><div class="stat-label">Avg Score</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $approvedCount ?></div><div class="stat-label">Approved</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-clipboard-list"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($reviews) ?></div><div class="stat-label">Total Reviews</div></div></div>
  </div>
</div>

<!-- Filter -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Review Period</label>
      <input type="text" name="period" class="form-control form-control-sm" placeholder="e.g. 2025-Q1" value="<?= e($fPeriod) ?>"></div>
    <div class="col-auto">
      <button class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="performance.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card border-0 shadow-sm"><div class="card-body p-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 data-table">
      <thead class="table-light"><tr><th>Employee</th><th>Department</th><th>Period</th><th class="text-center">KPI</th><th class="text-center">Overall</th><th>Rating</th><th class="text-center">Status</th><th class="text-center">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($reviews)): ?>
        <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-star fa-3x mb-3 d-block"></i>No reviews found.</td></tr>
        <?php else: foreach ($reviews as $r): ?>
        <tr>
          <td class="fw-semibold"><?= e($r['emp_name']) ?></td>
          <td class="small text-muted"><?= e($r['dept_name'] ?? '—') ?></td>
          <td><span class="badge bg-light text-dark border"><?= e($r['review_period']) ?></span></td>
          <td class="text-center">
            <div class="progress" style="height:6px;width:60px;margin:0 auto">
              <div class="progress-bar bg-<?= $r['kpi_score']>=80?'success':($r['kpi_score']>=60?'warning':'danger') ?>" style="width:<?= $r['kpi_score'] ?>%"></div>
            </div>
            <small class="text-muted"><?= $r['kpi_score'] ?>%</small>
          </td>
          <td class="text-center fw-bold"><?= $r['overall_score'] ?>%</td>
          <td><span class="badge bg-<?= $ratingColors[$r['rating']] ?? 'secondary' ?>"><?= $ratingLabels[$r['rating']] ?? e($r['rating']) ?></span></td>
          <td class="text-center"><?= statusBadge($r['status']) ?></td>
          <td class="text-center"><button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div></div>

<!-- Performance Modal -->
<div class="modal fade" id="perfModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="perfId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="perfTitle"><i class="fas fa-star me-2"></i>New Review</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
            <select name="employee_id" id="perfEmp" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>"><?= e($e['first_name'] . ' ' . $e['last_name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-3"><label class="form-label fw-semibold">Review Period <span class="text-danger">*</span></label>
            <input type="text" name="review_period" id="perfPeriod" class="form-control" placeholder="e.g. 2025-Q2" required></div>
          <div class="col-md-3"><label class="form-label fw-semibold">Review Date</label>
            <input type="date" name="reviewed_at" id="perfDate" class="form-control"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Reviewer Name</label>
            <input type="text" name="reviewer_name" id="perfReviewer" class="form-control"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Status</label>
            <select name="status" id="perfStatus" class="form-select"><option value="draft">Draft</option><option value="submitted">Submitted</option><option value="approved">Approved</option></select></div>
          <div class="col-12"><hr class="my-1"><p class="fw-semibold mb-2">Scores (0–100)</p>
            <div class="row g-3">
              <div class="col-md-3"><label class="form-label small">KPI</label>
                <input type="number" name="kpi_score" id="perfKpi" class="form-control" min="0" max="100" step="0.5" value="0" oninput="calcOverall()"></div>
              <div class="col-md-3"><label class="form-label small">Attendance</label>
                <input type="number" name="attendance_score" id="perfAtt" class="form-control" min="0" max="100" step="0.5" value="0" oninput="calcOverall()"></div>
              <div class="col-md-3"><label class="form-label small">Teamwork</label>
                <input type="number" name="teamwork_score" id="perfTeam" class="form-control" min="0" max="100" step="0.5" value="0" oninput="calcOverall()"></div>
              <div class="col-md-3"><label class="form-label small">Initiative</label>
                <input type="number" name="initiative_score" id="perfInit" class="form-control" min="0" max="100" step="0.5" value="0" oninput="calcOverall()"></div>
              <div class="col-12"><div class="alert alert-info py-2 mb-0">Overall Score: <strong id="overallDisplay">0.00%</strong></div></div>
            </div>
          </div>
          <div class="col-md-6"><label class="form-label fw-semibold">Rating</label>
            <select name="rating" id="perfRating" class="form-select">
              <?php foreach ($ratingLabels as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-12"><label class="form-label fw-semibold">Strengths</label>
            <textarea name="strengths" id="perfStr" class="form-control" rows="2"></textarea></div>
          <div class="col-12"><label class="form-label fw-semibold">Areas for Improvement</label>
            <textarea name="improvements" id="perfImp" class="form-control" rows="2"></textarea></div>
          <div class="col-12"><label class="form-label fw-semibold">Goals — Next Period</label>
            <textarea name="goals_next" id="perfGoals" class="form-control" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save</button>
      </div>
    </form>
  </div></div>
</div>

<?php $extraJs = <<<'JS'
<script>
function calcOverall() {
    const scores = ['perfKpi','perfAtt','perfTeam','perfInit'].map(id => parseFloat(document.getElementById(id).value)||0);
    const avg = scores.reduce((a,b) => a+b, 0) / scores.length;
    document.getElementById('overallDisplay').textContent = avg.toFixed(2) + '%';
}
function openAdd() {
    document.getElementById('perfTitle').innerHTML = '<i class="fas fa-star me-2"></i>New Review';
    document.getElementById('perfId').value = '0';
    document.getElementById('perfEmp').value    = '';
    document.getElementById('perfPeriod').value = '';
    document.getElementById('perfDate').value   = '';
    document.getElementById('perfReviewer').value = '';
    document.getElementById('perfStatus').value = 'draft';
    document.getElementById('perfRating').value = 'meets';
    ['perfKpi','perfAtt','perfTeam','perfInit'].forEach(id => document.getElementById(id).value = '0');
    ['perfStr','perfImp','perfGoals'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('overallDisplay').textContent = '0.00%';
}
function openEdit(r) {
    document.getElementById('perfTitle').innerHTML     = '<i class="fas fa-edit me-2"></i>Edit Review';
    document.getElementById('perfId').value            = r.id;
    document.getElementById('perfEmp').value           = r.employee_id;
    document.getElementById('perfPeriod').value        = r.review_period;
    document.getElementById('perfDate').value          = r.reviewed_at || '';
    document.getElementById('perfReviewer').value      = r.reviewer_name || '';
    document.getElementById('perfStatus').value        = r.status;
    document.getElementById('perfRating').value        = r.rating;
    document.getElementById('perfKpi').value           = r.kpi_score;
    document.getElementById('perfAtt').value           = r.attendance_score;
    document.getElementById('perfTeam').value          = r.teamwork_score;
    document.getElementById('perfInit').value          = r.initiative_score;
    document.getElementById('perfStr').value           = r.strengths || '';
    document.getElementById('perfImp').value           = r.improvements || '';
    document.getElementById('perfGoals').value         = r.goals_next || '';
    calcOverall();
    new bootstrap.Modal(document.getElementById('perfModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
