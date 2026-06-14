<?php
require_once __DIR__ . '/../../modules/school/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user   = currentUser();
$orgId  = (int)$user['org_id'];
$userId = (int)$user['id'];
$pageTitle = 'Payroll';
$tab   = preg_replace('/[^a-z_]/', '', $_GET['tab'] ?? 'runs');
if (!in_array($tab, ['runs','grades','payslips'])) $tab = 'runs';

// ── Ensure tables exist ───────────────────────────────────────────
foreach ([
    "CREATE TABLE IF NOT EXISTS sch_salary_grades (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,grade_name VARCHAR(100) NOT NULL,basic_salary DECIMAL(14,2) NOT NULL DEFAULT 0,house_allowance DECIMAL(14,2) NOT NULL DEFAULT 0,transport_allowance DECIMAL(14,2) NOT NULL DEFAULT 0,medical_allowance DECIMAL(14,2) NOT NULL DEFAULT 0,other_allowances DECIMAL(14,2) NOT NULL DEFAULT 0,paye_rate DECIMAL(5,2) NOT NULL DEFAULT 0,nhif_amount DECIMAL(10,2) NOT NULL DEFAULT 0,nssf_amount DECIMAL(10,2) NOT NULL DEFAULT 0,other_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,currency VARCHAR(10) NOT NULL DEFAULT 'KES',status VARCHAR(20) NOT NULL DEFAULT 'active',created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id),KEY idx_sg_org (org_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS sch_payroll_runs (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,period_month TINYINT NOT NULL,period_year INT NOT NULL,status ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',total_gross DECIMAL(16,2) NOT NULL DEFAULT 0,total_deductions DECIMAL(16,2) NOT NULL DEFAULT 0,total_net DECIMAL(16,2) NOT NULL DEFAULT 0,currency VARCHAR(10) NOT NULL DEFAULT 'KES',notes TEXT,processed_by INT DEFAULT NULL,processed_at DATETIME DEFAULT NULL,approved_by INT DEFAULT NULL,approved_at DATETIME DEFAULT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id),UNIQUE KEY uq_pr (org_id,period_month,period_year),KEY idx_pr_org (org_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS sch_payslips (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,payroll_run_id INT NOT NULL,staff_id INT NOT NULL,staff_type ENUM('teacher','staff') NOT NULL DEFAULT 'teacher',staff_name VARCHAR(200) NOT NULL,employee_id VARCHAR(50) DEFAULT NULL,grade_name VARCHAR(100) DEFAULT NULL,basic_salary DECIMAL(14,2) NOT NULL DEFAULT 0,house_allowance DECIMAL(14,2) NOT NULL DEFAULT 0,transport_allow DECIMAL(14,2) NOT NULL DEFAULT 0,medical_allow DECIMAL(14,2) NOT NULL DEFAULT 0,other_allowances DECIMAL(14,2) NOT NULL DEFAULT 0,gross_salary DECIMAL(14,2) NOT NULL DEFAULT 0,paye DECIMAL(14,2) NOT NULL DEFAULT 0,nhif DECIMAL(14,2) NOT NULL DEFAULT 0,nssf DECIMAL(14,2) NOT NULL DEFAULT 0,other_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,net_salary DECIMAL(14,2) NOT NULL DEFAULT 0,currency VARCHAR(10) NOT NULL DEFAULT 'KES',status ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',notes TEXT,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id),UNIQUE KEY uq_ps (payroll_run_id,staff_id,staff_type),KEY idx_ps_staff (staff_id,staff_type),KEY idx_ps_org (org_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
] as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) {} }
// Add salary_grade_id column to teachers
try { $pdo->exec("ALTER TABLE sch_teachers ADD COLUMN IF NOT EXISTS salary_grade_id INT DEFAULT NULL"); } catch (Throwable $e) {}

$saveMsg = null; $saveErr = null;

// ── POST handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    // ── Salary Grades ─────────────────────────────────────────────
    if ($action === 'save_grade') {
        $id       = (int)($_POST['id'] ?? 0);
        $gname    = trim($_POST['grade_name'] ?? '');
        $basic    = (float)($_POST['basic_salary'] ?? 0);
        $house    = (float)($_POST['house_allowance'] ?? 0);
        $trans    = (float)($_POST['transport_allowance'] ?? 0);
        $med      = (float)($_POST['medical_allowance'] ?? 0);
        $other_a  = (float)($_POST['other_allowances'] ?? 0);
        $paye     = max(0, min(100, (float)($_POST['paye_rate'] ?? 0)));
        $nhif     = (float)($_POST['nhif_amount'] ?? 0);
        $nssf     = (float)($_POST['nssf_amount'] ?? 0);
        $other_d  = (float)($_POST['other_deductions'] ?? 0);
        $currency = trim($_POST['currency'] ?? 'KES');
        $gstatus  = in_array($_POST['status']??'', ['active','inactive']) ? $_POST['status'] : 'active';
        if (!$gname) {
            $saveErr = 'Grade name is required.';
        } elseif ($id) {
            $pdo->prepare("UPDATE sch_salary_grades SET grade_name=?,basic_salary=?,house_allowance=?,transport_allowance=?,medical_allowance=?,other_allowances=?,paye_rate=?,nhif_amount=?,nssf_amount=?,other_deductions=?,currency=?,status=? WHERE id=? AND org_id=?")
                ->execute([$gname,$basic,$house,$trans,$med,$other_a,$paye,$nhif,$nssf,$other_d,$currency,$gstatus,$id,$orgId]);
            $saveMsg = 'Salary grade updated.';
        } else {
            $pdo->prepare("INSERT INTO sch_salary_grades (org_id,grade_name,basic_salary,house_allowance,transport_allowance,medical_allowance,other_allowances,paye_rate,nhif_amount,nssf_amount,other_deductions,currency,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$gname,$basic,$house,$trans,$med,$other_a,$paye,$nhif,$nssf,$other_d,$currency,$gstatus]);
            $saveMsg = 'Salary grade created.';
        }
        $tab = 'grades';
    }

    elseif ($action === 'delete_grade') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM sch_salary_grades WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        $saveMsg = 'Grade deleted.'; $tab = 'grades';
    }

    elseif ($action === 'assign_grade') {
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $gradeId   = (int)($_POST['salary_grade_id'] ?? 0) ?: null;
        $pdo->prepare("UPDATE sch_teachers SET salary_grade_id=? WHERE id=? AND org_id=?")
            ->execute([$gradeId,$teacherId,$orgId]);
        $saveMsg = 'Grade assigned.'; $tab = 'grades';
    }

    // ── Payroll Run ───────────────────────────────────────────────
    elseif ($action === 'create_run') {
        $mon   = (int)($_POST['period_month'] ?? date('n'));
        $yr    = (int)($_POST['period_year']  ?? date('Y'));
        $notes = trim($_POST['notes'] ?? '');
        $curr  = trim($_POST['currency'] ?? 'KES');
        if ($mon < 1 || $mon > 12 || $yr < 2020 || $yr > 2099) {
            $saveErr = 'Invalid month/year.';
        } else {
            // Check if already exists
            $chk = $pdo->prepare("SELECT id FROM sch_payroll_runs WHERE org_id=? AND period_month=? AND period_year=?");
            $chk->execute([$orgId,$mon,$yr]);
            if ($chk->fetchColumn()) {
                $saveErr = 'A payroll run for that month already exists.';
            } else {
                $pdo->prepare("INSERT INTO sch_payroll_runs (org_id,period_month,period_year,notes,currency,processed_by,processed_at) VALUES (?,?,?,?,?,?,NOW())")
                    ->execute([$orgId,$mon,$yr,$notes,$curr,$userId]);
                $runId = (int)$pdo->lastInsertId();
                // Generate payslips for all staff with salary_grade_id
                $staff = $pdo->prepare(
                    "SELECT t.id, 'teacher' AS staff_type,
                            CONCAT(t.first_name,' ',t.last_name) AS staff_name,
                            t.employee_id, sg.*
                     FROM sch_teachers t
                     JOIN sch_salary_grades sg ON sg.id=t.salary_grade_id
                     WHERE t.org_id=? AND t.status='active' AND t.salary_grade_id IS NOT NULL"
                );
                $staff->execute([$orgId]);
                $totalGross = $totalDed = $totalNet = 0;
                foreach ($staff->fetchAll() as $s) {
                    $gross = $s['basic_salary'] + $s['house_allowance'] + $s['transport_allowance'] + $s['medical_allowance'] + $s['other_allowances'];
                    $paye  = round($gross * $s['paye_rate'] / 100, 2);
                    $totalDedRow = $paye + $s['nhif_amount'] + $s['nssf_amount'] + $s['other_deductions'];
                    $net   = $gross - $totalDedRow;
                    $pdo->prepare("INSERT INTO sch_payslips (org_id,payroll_run_id,staff_id,staff_type,staff_name,employee_id,grade_name,basic_salary,house_allowance,transport_allow,medical_allow,other_allowances,gross_salary,paye,nhif,nssf,other_deductions,total_deductions,net_salary,currency,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE gross_salary=VALUES(gross_salary),net_salary=VALUES(net_salary)")
                        ->execute([$orgId,$runId,$s['id'],$s['staff_type'],$s['staff_name'],$s['employee_id'],$s['grade_name'],$s['basic_salary'],$s['house_allowance'],$s['transport_allowance'],$s['medical_allowance'],$s['other_allowances'],$gross,$paye,$s['nhif_amount'],$s['nssf_amount'],$s['other_deductions'],$totalDedRow,$net,$curr,'draft']);
                    $totalGross += $gross; $totalDed += $totalDedRow; $totalNet += $net;
                }
                $pdo->prepare("UPDATE sch_payroll_runs SET total_gross=?,total_deductions=?,total_net=? WHERE id=?")
                    ->execute([$totalGross,$totalDed,$totalNet,$runId]);
                $saveMsg = 'Payroll run created with '.count($staff->fetchAll(0,[])+[]).' payslips generated.';
            }
        }
        $tab = 'runs';
    }

    elseif ($action === 'update_run_status') {
        $runId  = (int)($_POST['run_id'] ?? 0);
        $status = in_array($_POST['new_status']??'', ['draft','approved','paid']) ? $_POST['new_status'] : 'draft';
        $col    = $status==='approved' ? ',approved_by=?,approved_at=NOW()' : '';
        $params = $col ? [$status,$status,$orgId,$runId,$userId,$orgId,$runId] : [$status,$orgId,$runId];
        if ($col) {
            $pdo->prepare("UPDATE sch_payroll_runs SET status=?,approved_by=?,approved_at=NOW() WHERE org_id=? AND id=?")
                ->execute([$status,$userId,$orgId,$runId]);
        } else {
            $pdo->prepare("UPDATE sch_payroll_runs SET status=? WHERE org_id=? AND id=?")->execute([$status,$orgId,$runId]);
        }
        $pdo->prepare("UPDATE sch_payslips SET status=? WHERE org_id=? AND payroll_run_id=?")->execute([$status,$orgId,$runId]);
        $saveMsg = 'Payroll run marked as '.ucfirst($status).'.';
        $tab = 'runs';
    }

    elseif ($action === 'delete_run') {
        $runId = (int)($_POST['run_id'] ?? 0);
        // Only delete if draft
        $chk = $pdo->prepare("SELECT status FROM sch_payroll_runs WHERE id=? AND org_id=?");
        $chk->execute([$runId,$orgId]);
        if ($chk->fetchColumn() === 'draft') {
            $pdo->prepare("DELETE FROM sch_payslips WHERE payroll_run_id=? AND org_id=?")->execute([$runId,$orgId]);
            $pdo->prepare("DELETE FROM sch_payroll_runs WHERE id=? AND org_id=?")->execute([$runId,$orgId]);
            $saveMsg = 'Payroll run deleted.';
        } else {
            $saveErr = 'Only draft payroll runs can be deleted.';
        }
        $tab = 'runs';
    }
}

// ── Data loading ──────────────────────────────────────────────────
$salaryGrades = [];
try {
    $s = $pdo->prepare("SELECT * FROM sch_salary_grades WHERE org_id=? ORDER BY grade_name");
    $s->execute([$orgId]); $salaryGrades = $s->fetchAll();
} catch (Throwable $e) {}

$payrollRuns = [];
try {
    $s = $pdo->prepare("SELECT pr.*, COUNT(ps.id) AS payslip_count FROM sch_payroll_runs pr LEFT JOIN sch_payslips ps ON ps.payroll_run_id=pr.id WHERE pr.org_id=? GROUP BY pr.id ORDER BY pr.period_year DESC, pr.period_month DESC LIMIT 36");
    $s->execute([$orgId]); $payrollRuns = $s->fetchAll();
} catch (Throwable $e) {}

// For payslips tab
$viewRunId  = (int)($_GET['run'] ?? 0);
$runPayslips = [];
$viewRun    = null;
if ($tab === 'payslips' && $viewRunId) {
    try {
        $s = $pdo->prepare("SELECT * FROM sch_payroll_runs WHERE id=? AND org_id=?");
        $s->execute([$viewRunId,$orgId]); $viewRun = $s->fetch();
        $s = $pdo->prepare("SELECT * FROM sch_payslips WHERE payroll_run_id=? AND org_id=? ORDER BY staff_name");
        $s->execute([$viewRunId,$orgId]); $runPayslips = $s->fetchAll();
    } catch (Throwable $e) {}
}

// Teachers for grade assignment
$teachers = [];
try {
    $s = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS full_name, employee_id, salary_grade_id FROM sch_teachers WHERE org_id=? AND status='active' ORDER BY first_name");
    $s->execute([$orgId]); $teachers = $s->fetchAll();
} catch (Throwable $e) {}

$editGrade = null;
if (!empty($_GET['edit_grade'])) {
    $eg = (int)$_GET['edit_grade'];
    foreach ($salaryGrades as $g) { if ((int)$g['id']===$eg) { $editGrade=$g; break; } }
}

$monthNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$gradeMap   = []; foreach ($salaryGrades as $g) $gradeMap[$g['id']] = $g['grade_name'];

require_once __DIR__ . '/../../includes/header-module.php';

$statusColors = ['draft'=>'secondary','approved'=>'success','paid'=>'primary'];
function fmtMoney($v, $cur='KES') { return $cur.' '.number_format((float)$v, 2); }
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-money-check-alt me-2" style="color:#1A8A4E"></i>Payroll Management</h5>
    <div class="text-muted small mt-1">Salary grades, monthly payroll runs &amp; payslips</div>
  </div>
</div>

<ul class="nav nav-tabs mb-4">
  <?php foreach (['runs'=>'Payroll Runs','grades'=>'Salary Grades','payslips'=>'Payslips'] as $t=>$lbl): ?>
  <li class="nav-item">
    <a href="?tab=<?= $t ?>" class="nav-link <?= $tab===$t?'active':'' ?>"><?= $lbl ?></a>
  </li>
  <?php endforeach; ?>
</ul>

<?php if ($saveMsg): ?>
<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?= e($saveMsg) ?></div>
<?php endif; ?>
<?php if ($saveErr): ?>
<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?= e($saveErr) ?></div>
<?php endif; ?>

<!-- ═══════════════ PAYROLL RUNS ═══════════════ -->
<?php if ($tab === 'runs'): ?>

<!-- Create new run -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-bold small d-flex justify-content-between align-items-center">
    <span><i class="fas fa-plus-circle me-1 text-success"></i>Create New Payroll Run</span>
    <span class="text-muted" style="font-weight:400;font-size:.75rem">Only staff with a salary grade assigned will get payslips.</span>
  </div>
  <div class="card-body">
    <form method="POST" class="row g-3 align-items-end">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_run">
      <div class="col-sm-3">
        <label class="form-label fw-semibold small">Month</label>
        <select class="form-select form-select-sm" name="period_month">
          <?php for ($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= $m==(int)date('n')?'selected':'' ?>><?= $monthNames[$m] ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label fw-semibold small">Year</label>
        <select class="form-select form-select-sm" name="period_year">
          <?php for ($y=date('Y')+1;$y>=2020;$y--): ?>
          <option value="<?= $y ?>" <?= $y==(int)date('Y')?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label fw-semibold small">Currency</label>
        <select class="form-select form-select-sm" name="currency">
          <?php foreach(['KES','USD','GBP','EUR','UGX','TZS','RWF'] as $c): ?>
          <option value="<?= $c ?>"><?= $c ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold small">Notes</label>
        <input class="form-control form-control-sm" name="notes" placeholder="Optional note">
      </div>
      <div class="col-sm-2">
        <button type="submit" class="btn btn-success btn-sm w-100">
          <i class="fas fa-play me-1"></i>Generate
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Run list -->
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Period</th><th>Staff</th><th>Total Gross</th><th>Deductions</th><th>Net Pay</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (empty($payrollRuns)): ?>
        <tr><td colspan="7" class="text-center text-muted py-5 small">No payroll runs yet. Create the first one above.</td></tr>
        <?php else: foreach ($payrollRuns as $run): ?>
        <tr>
          <td class="fw-semibold small"><?= $monthNames[(int)$run['period_month']] ?> <?= $run['period_year'] ?></td>
          <td class="small"><?= $run['payslip_count'] ?> employee(s)</td>
          <td class="small"><?= fmtMoney($run['total_gross'],$run['currency']) ?></td>
          <td class="small text-danger"><?= fmtMoney($run['total_deductions'],$run['currency']) ?></td>
          <td class="fw-semibold small text-success"><?= fmtMoney($run['total_net'],$run['currency']) ?></td>
          <td><span class="badge bg-<?= $statusColors[$run['status']] ?>"><?= ucfirst($run['status']) ?></span></td>
          <td class="text-end">
            <a href="?tab=payslips&run=<?= $run['id'] ?>" class="btn btn-sm btn-outline-secondary">
              <i class="fas fa-eye me-1"></i>Payslips
            </a>
            <?php if ($run['status'] === 'draft'): ?>
            <form method="POST" class="d-inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="update_run_status">
              <input type="hidden" name="run_id" value="<?= $run['id'] ?>">
              <input type="hidden" name="new_status" value="approved">
              <button class="btn btn-sm btn-outline-success">Approve</button>
            </form>
            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this draft run?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_run">
              <input type="hidden" name="run_id" value="<?= $run['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
            </form>
            <?php elseif ($run['status'] === 'approved'): ?>
            <form method="POST" class="d-inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="update_run_status">
              <input type="hidden" name="run_id" value="<?= $run['id'] ?>">
              <input type="hidden" name="new_status" value="paid">
              <button class="btn btn-sm btn-outline-primary">Mark Paid</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════ SALARY GRADES ═══════════════ -->
<?php elseif ($tab === 'grades'): ?>
<div class="row g-4">
  <!-- Grade Form -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-bold small"><?= $editGrade ? 'Edit Grade' : 'New Salary Grade' ?></div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="save_grade">
          <?php if ($editGrade): ?><input type="hidden" name="id" value="<?= $editGrade['id'] ?>"><?php endif; ?>
          <div class="mb-2">
            <label class="form-label fw-semibold small">Grade Name <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" name="grade_name" required
                   value="<?= e($editGrade['grade_name'] ?? '') ?>" placeholder="e.g. Senior Teacher">
          </div>
          <div class="mb-2 row g-2">
            <div class="col-6">
              <label class="form-label fw-semibold small">Basic Salary</label>
              <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="basic_salary"
                     value="<?= number_format((float)($editGrade['basic_salary']??0),2,'.','') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold small">Currency</label>
              <select class="form-select form-select-sm" name="currency">
                <?php foreach(['KES','USD','GBP','EUR','UGX','TZS','RWF'] as $c): ?>
                <option value="<?= $c ?>" <?= ($editGrade['currency']??'KES')===$c?'selected':'' ?>><?= $c ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p class="small text-muted mt-1 mb-1">Allowances</p>
          <?php foreach (['house_allowance'=>'House','transport_allowance'=>'Transport','medical_allowance'=>'Medical','other_allowances'=>'Other'] as $field=>$lbl): ?>
          <div class="mb-2">
            <label class="form-label small"><?= $lbl ?> Allowance</label>
            <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="<?= $field ?>"
                   value="<?= number_format((float)($editGrade[$field]??0),2,'.','') ?>">
          </div>
          <?php endforeach; ?>
          <p class="small text-muted mt-2 mb-1">Deductions</p>
          <div class="mb-2">
            <label class="form-label small">PAYE Rate (%)</label>
            <input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" name="paye_rate"
                   value="<?= number_format((float)($editGrade['paye_rate']??0),2,'.','') ?>">
          </div>
          <?php foreach (['nhif_amount'=>'NHIF','nssf_amount'=>'NSSF','other_deductions'=>'Other Deductions'] as $field=>$lbl): ?>
          <div class="mb-2">
            <label class="form-label small"><?= $lbl ?></label>
            <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="<?= $field ?>"
                   value="<?= number_format((float)($editGrade[$field]??0),2,'.','') ?>">
          </div>
          <?php endforeach; ?>
          <div class="mb-3">
            <label class="form-label small">Status</label>
            <select class="form-select form-select-sm" name="status">
              <option value="active"   <?= ($editGrade['status']??'active')==='active'?'selected':'' ?>>Active</option>
              <option value="inactive" <?= ($editGrade['status']??'active')==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-success btn-sm"><i class="fas fa-save me-1"></i><?= $editGrade?'Update':'Save Grade' ?></button>
            <?php if ($editGrade): ?><a href="?tab=grades" class="btn btn-outline-secondary btn-sm">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Grades list -->
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-bold small">Defined Grades</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Grade</th><th>Basic</th><th>Gross</th><th>Net (est.)</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($salaryGrades)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4 small">No salary grades yet.</td></tr>
            <?php else: foreach ($salaryGrades as $g):
              $gross = $g['basic_salary']+$g['house_allowance']+$g['transport_allowance']+$g['medical_allowance']+$g['other_allowances'];
              $paye  = round($gross * $g['paye_rate'] / 100, 2);
              $ded   = $paye + $g['nhif_amount'] + $g['nssf_amount'] + $g['other_deductions'];
              $net   = $gross - $ded;
            ?>
            <tr>
              <td class="fw-semibold small"><?= e($g['grade_name']) ?></td>
              <td class="small"><?= number_format($g['basic_salary']) ?></td>
              <td class="small"><?= number_format($gross) ?></td>
              <td class="small text-success fw-semibold"><?= number_format($net) ?></td>
              <td class="text-end">
                <a href="?tab=grades&edit_grade=<?= $g['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-edit"></i></a>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete grade?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_grade">
                  <input type="hidden" name="id" value="<?= $g['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Assign grades to teachers -->
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-bold small">Assign Grades to Staff</div>
      <div class="table-responsive" style="max-height:340px;overflow-y:auto">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light sticky-top"><tr><th>Staff Member</th><th>Employee ID</th><th>Current Grade</th><th>Change Grade</th></tr></thead>
          <tbody>
            <?php if (empty($teachers)): ?>
            <tr><td colspan="4" class="text-center text-muted py-4 small">No active staff.</td></tr>
            <?php else: foreach ($teachers as $t): ?>
            <tr>
              <td class="small fw-semibold"><?= e($t['full_name']) ?></td>
              <td class="small text-muted"><?= e($t['employee_id']??'—') ?></td>
              <td class="small"><?= $t['salary_grade_id'] ? e($gradeMap[$t['salary_grade_id']] ?? '—') : '<span class="text-muted">None</span>' ?></td>
              <td>
                <form method="POST" class="d-flex gap-1">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="assign_grade">
                  <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                  <select class="form-select form-select-sm" name="salary_grade_id" style="width:auto">
                    <option value="">— None —</option>
                    <?php foreach ($salaryGrades as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= $t['salary_grade_id']==$g['id']?'selected':'' ?>><?= e($g['grade_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-success">Set</button>
                </form>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════ PAYSLIPS ═══════════════ -->
<?php elseif ($tab === 'payslips'): ?>
<?php if (!$viewRunId): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header fw-bold small">Select a Payroll Run</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Period</th><th>Staff</th><th>Net Pay</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($payrollRuns)): ?>
        <tr><td colspan="5" class="text-center text-muted py-4 small">No payroll runs yet.</td></tr>
        <?php else: foreach ($payrollRuns as $run): ?>
        <tr>
          <td class="fw-semibold small"><?= $monthNames[(int)$run['period_month']] ?> <?= $run['period_year'] ?></td>
          <td class="small"><?= $run['payslip_count'] ?></td>
          <td class="small fw-semibold text-success"><?= fmtMoney($run['total_net'],$run['currency']) ?></td>
          <td><span class="badge bg-<?= $statusColors[$run['status']] ?>"><?= ucfirst($run['status']) ?></span></td>
          <td><a href="?tab=payslips&run=<?= $run['id'] ?>" class="btn btn-sm btn-outline-secondary">View Payslips</a></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<!-- Individual payslips for run -->
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <a href="?tab=payslips" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
  <?php if ($viewRun): ?>
  <span class="fw-bold"><?= $monthNames[(int)$viewRun['period_month']] ?> <?= $viewRun['period_year'] ?></span>
  <span class="badge bg-<?= $statusColors[$viewRun['status']] ?>"><?= ucfirst($viewRun['status']) ?></span>
  <?php endif; ?>
</div>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Staff</th><th>Grade</th><th>Basic</th><th>Gross</th><th>Deductions</th><th class="text-success">Net Pay</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (empty($runPayslips)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4 small">No payslips for this run.</td></tr>
        <?php else: foreach ($runPayslips as $ps): ?>
        <tr>
          <td>
            <div class="fw-semibold small"><?= e($ps['staff_name']) ?></div>
            <div class="text-muted" style="font-size:.68rem"><?= e($ps['employee_id']??'') ?></div>
          </td>
          <td class="small"><?= e($ps['grade_name']??'—') ?></td>
          <td class="small"><?= fmtMoney($ps['basic_salary'],$ps['currency']) ?></td>
          <td class="small"><?= fmtMoney($ps['gross_salary'],$ps['currency']) ?></td>
          <td class="small text-danger"><?= fmtMoney($ps['total_deductions'],$ps['currency']) ?></td>
          <td class="fw-bold text-success"><?= fmtMoney($ps['net_salary'],$ps['currency']) ?></td>
          <td>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                    data-bs-target="#slipModal"
                    onclick="showSlip(<?= htmlspecialchars(json_encode($ps), ENT_QUOTES) ?>)">
              <i class="fas fa-eye"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if (!empty($runPayslips) && $viewRun): ?>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="3" class="text-end">Totals</td>
          <td><?= fmtMoney($viewRun['total_gross'],$viewRun['currency']) ?></td>
          <td class="text-danger"><?= fmtMoney($viewRun['total_deductions'],$viewRun['currency']) ?></td>
          <td class="text-success"><?= fmtMoney($viewRun['total_net'],$viewRun['currency']) ?></td>
          <td></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- Payslip detail modal -->
<div class="modal fade" id="slipModal" tabindex="-1">
  <div class="modal-dialog modal-md">
    <div class="modal-content border-0 shadow">
      <div class="modal-header" style="background:#1A8A4E;color:#fff">
        <h6 class="modal-title fw-bold" id="slipModalTitle">Payslip</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="slipModalBody"></div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-sm btn-success" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
      </div>
    </div>
  </div>
</div>

<script>
function showSlip(ps) {
    document.getElementById('slipModalTitle').textContent = 'Payslip — ' + ps.staff_name;
    var cur = ps.currency;
    function fm(v) { return cur + ' ' + parseFloat(v).toLocaleString('en-KE',{minimumFractionDigits:2}); }
    var html = '<div style="font-size:.85rem">'
        + '<div class="d-flex justify-content-between mb-2"><span class="text-muted">Employee:</span><strong>' + ps.staff_name + '</strong></div>'
        + '<div class="d-flex justify-content-between mb-2"><span class="text-muted">ID:</span><span>' + (ps.employee_id||'—') + '</span></div>'
        + '<div class="d-flex justify-content-between mb-3"><span class="text-muted">Grade:</span><span>' + (ps.grade_name||'—') + '</span></div>'
        + '<hr class="my-2"><strong>Earnings</strong>'
        + '<table class="table table-sm mt-1"><tbody>'
        + '<tr><td>Basic Salary</td><td class="text-end">' + fm(ps.basic_salary) + '</td></tr>'
        + '<tr><td>House Allowance</td><td class="text-end">' + fm(ps.house_allowance) + '</td></tr>'
        + '<tr><td>Transport Allowance</td><td class="text-end">' + fm(ps.transport_allow) + '</td></tr>'
        + '<tr><td>Medical Allowance</td><td class="text-end">' + fm(ps.medical_allow) + '</td></tr>'
        + '<tr><td>Other Allowances</td><td class="text-end">' + fm(ps.other_allowances) + '</td></tr>'
        + '<tr class="table-success fw-bold"><td>Gross Salary</td><td class="text-end">' + fm(ps.gross_salary) + '</td></tr>'
        + '</tbody></table>'
        + '<strong>Deductions</strong>'
        + '<table class="table table-sm mt-1"><tbody>'
        + '<tr><td>PAYE Tax</td><td class="text-end text-danger">' + fm(ps.paye) + '</td></tr>'
        + '<tr><td>NHIF</td><td class="text-end text-danger">' + fm(ps.nhif) + '</td></tr>'
        + '<tr><td>NSSF</td><td class="text-end text-danger">' + fm(ps.nssf) + '</td></tr>'
        + '<tr><td>Other Deductions</td><td class="text-end text-danger">' + fm(ps.other_deductions) + '</td></tr>'
        + '<tr class="table-danger fw-bold"><td>Total Deductions</td><td class="text-end">' + fm(ps.total_deductions) + '</td></tr>'
        + '</tbody></table>'
        + '<div class="alert alert-success d-flex justify-content-between py-2 mb-0">'
        + '<strong>NET PAY</strong><strong class="fs-5">' + fm(ps.net_salary) + '</strong></div>'
        + '</div>';
    document.getElementById('slipModalBody').innerHTML = html;
}
</script>
<?php endif; ?>
<?php endif; // end tab ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
