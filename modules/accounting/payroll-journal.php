<?php
$moduleSlug  = 'accounting';
$moduleName  = 'Accounting & Bookkeeping';
$moduleIcon  = 'fas fa-calculator';
$moduleColor = '#1A8A4E';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'accounts.php',       'icon' => 'fas fa-list',            'label' => 'Chart of Accounts'],
    ['url' => 'transactions.php',   'icon' => 'fas fa-exchange-alt',    'label' => 'Journal Entries'],
    ['url' => 'invoices.php',       'icon' => 'fas fa-file-invoice',    'label' => 'Invoices'],
    ['url' => 'payments.php',       'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'expenses.php',       'icon' => 'fas fa-receipt',         'label' => 'Expenses'],
    ['url' => 'bills.php',          'icon' => 'fas fa-file-import',     'label' => 'Vendor Bills'],
    ['url' => 'budgets.php',        'icon' => 'fas fa-bullseye',        'label' => 'Budgets'],
    ['url' => 'taxes.php',          'icon' => 'fas fa-percentage',      'label' => 'Tax Rates'],
    ['url' => 'assets.php',         'icon' => 'fas fa-building',        'label' => 'Fixed Assets'],
    ['url' => 'payroll-journal.php','icon' => 'fas fa-file-alt',        'label' => 'Payroll Journal'],
    ['url' => 'audit.php',          'icon' => 'fas fa-history',         'label' => 'Audit Trail'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id              = (int)($_POST['id'] ?? 0);
        $period          = sanitize($_POST['period'] ?? date('Y-m'));
        $empName         = sanitize($_POST['employee_name'] ?? '');
        $department      = sanitize($_POST['department'] ?? '');
        $grossSalary     = (float)($_POST['gross_salary'] ?? 0);
        $taxDeductions   = (float)($_POST['tax_deductions'] ?? 0);
        $nssfDeduction   = (float)($_POST['nssf_deduction'] ?? 0);
        $nhifDeduction   = (float)($_POST['nhif_deduction'] ?? 0);
        $otherDeductions = (float)($_POST['other_deductions'] ?? 0);
        $netSalary       = $grossSalary - $taxDeductions - $nssfDeduction - $nhifDeduction - $otherDeductions;
        $status          = in_array($_POST['status'] ?? '', ['draft','posted','reversed']) ? $_POST['status'] : 'draft';

        if (empty($empName) || $grossSalary <= 0) { setFlash('danger', 'Employee name and gross salary are required.'); redirect('payroll-journal.php'); }

        if ($id > 0) {
            $pdo->prepare("UPDATE acc_payroll_journal SET period=?,employee_name=?,department=?,gross_salary=?,tax_deductions=?,nssf_deduction=?,nhif_deduction=?,other_deductions=?,net_salary=?,status=? WHERE id=? AND org_id=?")
                ->execute([$period,$empName,$department,$grossSalary,$taxDeductions,$nssfDeduction,$nhifDeduction,$otherDeductions,$netSalary,$status,$id,$orgId]);
            setFlash('success', 'Payroll entry updated.');
            logActivity('update', 'accounting', "Updated payroll: $empName ($period)");
        } else {
            $pdo->prepare("INSERT INTO acc_payroll_journal(org_id,period,employee_name,department,gross_salary,tax_deductions,nssf_deduction,nhif_deduction,other_deductions,net_salary,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$period,$empName,$department,$grossSalary,$taxDeductions,$nssfDeduction,$nhifDeduction,$otherDeductions,$netSalary,$status]);
            setFlash('success', "Payroll entry added for $empName.");
            logActivity('create', 'accounting', "Added payroll: $empName ($period)");
        }
        redirect('payroll-journal.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM acc_payroll_journal WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Payroll entry deleted.');
        redirect('payroll-journal.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$curMonth = date('Y-m');
$entries  = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM acc_payroll_journal WHERE org_id=? ORDER BY period DESC, employee_name ASC");
    $stmt->execute([$orgId]);
    $entries = $stmt->fetchAll();
} catch (Exception $e) {}

$monthGross = 0; $monthNet = 0; $monthDeductions = 0; $headcount = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(gross_salary),0),COALESCE(SUM(net_salary),0),COALESCE(SUM(tax_deductions+nssf_deduction+nhif_deduction+other_deductions),0),COUNT(*) FROM acc_payroll_journal WHERE org_id=? AND period=?");
    $stmt->execute([$orgId, $curMonth]);
    [$monthGross,$monthNet,$monthDeductions,$headcount] = $stmt->fetch(\PDO::FETCH_NUM);
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-alt me-2" style="color:<?= $moduleColor ?>"></i>Payroll Journal</h4>
    <p class="text-muted mb-0">Monthly payroll entries with deductions and net pay</p>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#prModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Entry
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-money-bill-wave"></i></div><div class="stat-body"><div class="stat-value"><?= formatCurrency((float)$monthGross) ?></div><div class="stat-label">Gross This Month</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-wallet"></i></div><div class="stat-body"><div class="stat-value"><?= formatCurrency((float)$monthNet) ?></div><div class="stat-label">Net This Month</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-minus-circle"></i></div><div class="stat-body"><div class="stat-value"><?= formatCurrency((float)$monthDeductions) ?></div><div class="stat-label">Total Deductions</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value"><?= (int)$headcount ?></div><div class="stat-label">Headcount</div></div></div></div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-file-alt me-2" style="color:<?= $moduleColor ?>"></i>Payroll Entries</h6>
    <span class="badge bg-secondary"><?= count($entries) ?> entries</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="prTable">
        <thead class="table-light">
          <tr><th>Period</th><th>Employee</th><th>Department</th><th class="text-end">Gross</th><th class="text-end">PAYE</th><th class="text-end">NSSF</th><th class="text-end">NHIF</th><th class="text-end">Other</th><th class="text-end">Net Pay</th><th>Status</th><th class="text-center no-print">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($entries)): ?>
          <tr><td colspan="11" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No payroll entries found.</td></tr>
          <?php else: foreach ($entries as $pr): ?>
          <tr>
            <td><span class="badge bg-light text-dark border fw-semibold"><?= e($pr['period']) ?></span></td>
            <td class="fw-semibold"><?= e($pr['employee_name']) ?></td>
            <td><?= e($pr['department'] ?? '—') ?></td>
            <td class="text-end"><?= formatCurrency((float)$pr['gross_salary']) ?></td>
            <td class="text-end text-danger"><?= formatCurrency((float)$pr['tax_deductions']) ?></td>
            <td class="text-end text-danger"><?= formatCurrency((float)$pr['nssf_deduction']) ?></td>
            <td class="text-end text-danger"><?= formatCurrency((float)$pr['nhif_deduction']) ?></td>
            <td class="text-end text-danger"><?= formatCurrency((float)$pr['other_deductions']) ?></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$pr['net_salary']) ?></td>
            <td><?= statusBadge($pr['status'] ?? 'draft') ?></td>
            <td class="text-center no-print" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='fillForm(<?= htmlspecialchars(json_encode($pr), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delEntry(<?= $pr['id'] ?>,'<?= e($pr['employee_name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="prModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="prForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="prId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="prModalTitle"><i class="fas fa-file-alt me-2"></i>Add Payroll Entry</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label fw-semibold">Period (YYYY-MM)</label><input type="month" name="period" id="prPeriod" class="form-control" value="<?= $curMonth ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Employee Name <span class="text-danger">*</span></label><input type="text" name="employee_name" id="prEmpName" class="form-control" required maxlength="150"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Department</label><input type="text" name="department" id="prDept" class="form-control" list="deptList"><datalist id="deptList"><option value="HR"><option value="Finance"><option value="Operations"><option value="Sales"><option value="IT"><option value="Management"></datalist></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Gross Salary (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="gross_salary" id="prGross" class="form-control" step="0.01" min="0" value="0" oninput="calcNet()"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">PAYE Tax (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="tax_deductions" id="prPaye" class="form-control" step="0.01" min="0" value="0" oninput="calcNet()"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">NSSF (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="nssf_deduction" id="prNssf" class="form-control" step="0.01" min="0" value="0" oninput="calcNet()"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">NHIF (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="nhif_deduction" id="prNhif" class="form-control" step="0.01" min="0" value="0" oninput="calcNet()"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Other Deductions (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="other_deductions" id="prOther" class="form-control" step="0.01" min="0" value="0" oninput="calcNet()"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Net Salary (<?= CURRENCY_SYMBOL ?>)</label><input type="number" name="net_salary" id="prNet" class="form-control bg-light" step="0.01" readonly></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="prStatus" class="form-select"><option value="draft">Draft</option><option value="posted">Posted</option><option value="reversed">Reversed</option></select></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Entry</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delPrForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delPrId"></form>
<?php
$extraJs = <<<'JS'
<script>
function calcNet(){
  var g=parseFloat(document.getElementById('prGross').value)||0;
  var p=parseFloat(document.getElementById('prPaye').value)||0;
  var n=parseFloat(document.getElementById('prNssf').value)||0;
  var h=parseFloat(document.getElementById('prNhif').value)||0;
  var o=parseFloat(document.getElementById('prOther').value)||0;
  document.getElementById('prNet').value=(g-p-n-h-o).toFixed(2);
}
function openAdd(){
  document.getElementById('prModalTitle').innerHTML='<i class="fas fa-file-alt me-2"></i>Add Payroll Entry';
  document.getElementById('prId').value='0';
  document.getElementById('prEmpName').value='';
  document.getElementById('prDept').value='';
  document.getElementById('prPeriod').value=new Date().toISOString().substring(0,7);
  ['prGross','prPaye','prNssf','prNhif','prOther','prNet'].forEach(i=>document.getElementById(i).value='0');
  document.getElementById('prStatus').value='draft';
}
function fillForm(pr){
  document.getElementById('prModalTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Payroll Entry';
  document.getElementById('prId').value=pr.id;
  document.getElementById('prPeriod').value=pr.period||'';
  document.getElementById('prEmpName').value=pr.employee_name||'';
  document.getElementById('prDept').value=pr.department||'';
  document.getElementById('prGross').value=pr.gross_salary||0;
  document.getElementById('prPaye').value=pr.tax_deductions||0;
  document.getElementById('prNssf').value=pr.nssf_deduction||0;
  document.getElementById('prNhif').value=pr.nhif_deduction||0;
  document.getElementById('prOther').value=pr.other_deductions||0;
  document.getElementById('prNet').value=pr.net_salary||0;
  document.getElementById('prStatus').value=pr.status||'draft';
  new bootstrap.Modal(document.getElementById('prModal')).show();
}
function delEntry(id,name){
  Swal.fire({title:'Delete Payroll Entry?',text:name+' entry will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delPrId').value=id;document.getElementById('delPrForm').submit();}});
}
$(document).ready(function(){$('#prTable').DataTable({pageLength:20,order:[[0,'desc'],[1,'asc']],language:{emptyTable:'No payroll entries found.'}});});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
