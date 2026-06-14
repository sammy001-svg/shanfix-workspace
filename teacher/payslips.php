<?php
$pageTitle = 'My Payslips';
require_once __DIR__ . '/../includes/header-teacher.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Ensure tables exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sch_payslips (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,payroll_run_id INT NOT NULL,staff_id INT NOT NULL,staff_type ENUM('teacher','staff') NOT NULL DEFAULT 'teacher',staff_name VARCHAR(200) NOT NULL,employee_id VARCHAR(50) DEFAULT NULL,grade_name VARCHAR(100) DEFAULT NULL,basic_salary DECIMAL(14,2) NOT NULL DEFAULT 0,house_allowance DECIMAL(14,2) NOT NULL DEFAULT 0,transport_allow DECIMAL(14,2) NOT NULL DEFAULT 0,medical_allow DECIMAL(14,2) NOT NULL DEFAULT 0,other_allowances DECIMAL(14,2) NOT NULL DEFAULT 0,gross_salary DECIMAL(14,2) NOT NULL DEFAULT 0,paye DECIMAL(14,2) NOT NULL DEFAULT 0,nhif DECIMAL(14,2) NOT NULL DEFAULT 0,nssf DECIMAL(14,2) NOT NULL DEFAULT 0,other_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,net_salary DECIMAL(14,2) NOT NULL DEFAULT 0,currency VARCHAR(10) NOT NULL DEFAULT 'KES',status ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',notes TEXT,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id),UNIQUE KEY uq_ps (payroll_run_id,staff_id,staff_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sch_payroll_runs (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,period_month TINYINT NOT NULL,period_year INT NOT NULL,status ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',total_gross DECIMAL(16,2) NOT NULL DEFAULT 0,total_net DECIMAL(16,2) NOT NULL DEFAULT 0,currency VARCHAR(10) NOT NULL DEFAULT 'KES',notes TEXT,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id),UNIQUE KEY uq_pr (org_id,period_month,period_year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Load payslips
$myPayslips = [];
try {
    $s = $pdo->prepare(
        "SELECT ps.*, pr.period_month, pr.period_year
         FROM sch_payslips ps
         JOIN sch_payroll_runs pr ON pr.id = ps.payroll_run_id
         WHERE ps.org_id=? AND ps.staff_id=? AND ps.staff_type='teacher'
           AND ps.status IN ('approved','paid')
         ORDER BY pr.period_year DESC, pr.period_month DESC"
    );
    $s->execute([$tchOrgId, $tchId]);
    $myPayslips = $s->fetchAll();
} catch (Throwable $e) {}

$monthNames   = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$statusColors = ['draft'=>'secondary','approved'=>'success','paid'=>'primary'];

function fmtM(float $v, string $c='KES'): string { return $c.' '.number_format($v,2); }
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-file-invoice-dollar me-2" style="color:var(--tch-green)"></i>My Payslips</h5>
    <div class="text-muted small">View and print your salary statements</div>
  </div>
</div>

<!-- ── Payslip List ── -->
<?php if (empty($myPayslips)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5">
    <i class="fas fa-file-invoice-dollar text-muted mb-3 d-block" style="font-size:2.5rem"></i>
    <div class="fw-semibold text-muted">No payslips available yet</div>
    <div class="text-muted small mt-1">Your payslips will appear here once payroll is processed.</div>
  </div>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($myPayslips as $ps): ?>
  <div class="col-sm-6 col-lg-4">
    <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div class="fw-bold"><?= $monthNames[(int)$ps['period_month']] ?> <?= $ps['period_year'] ?></div>
          <span class="badge bg-<?= $statusColors[$ps['status']] ?>"><?= ucfirst($ps['status']) ?></span>
        </div>
        <table class="table table-sm table-borderless mb-0" style="font-size:.82rem">
          <tr><td class="text-muted ps-0">Gross</td><td class="text-end pe-0"><?= fmtM((float)$ps['gross_salary'],$ps['currency']) ?></td></tr>
          <tr><td class="text-muted ps-0">Deductions</td><td class="text-end pe-0 text-danger"><?= fmtM((float)$ps['total_deductions'],$ps['currency']) ?></td></tr>
          <tr class="fw-bold">
            <td class="ps-0 text-success">Net Pay</td>
            <td class="text-end pe-0 text-success"><?= fmtM((float)$ps['net_salary'],$ps['currency']) ?></td>
          </tr>
        </table>
      </div>
      <div class="card-footer bg-transparent border-0 pt-0 pb-3 d-flex gap-2">
        <a href="<?= APP_URL ?>/modules/school/payslip-pdf.php?id=<?= $ps['id'] ?>" target="_blank"
           class="btn btn-sm btn-outline-secondary flex-grow-1">
          <i class="fas fa-eye me-1"></i>View
        </a>
        <a href="<?= APP_URL ?>/modules/school/payslip-pdf.php?id=<?= $ps['id'] ?>" target="_blank"
           class="btn btn-sm btn-outline-success" title="Print">
          <i class="fas fa-print"></i>
        </a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

  </div><!-- #tchContent -->
</div><!-- #tchMain -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
