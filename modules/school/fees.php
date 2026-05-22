<?php
$moduleSlug  = 'school';
$moduleName  = 'School Management';
$moduleIcon  = 'fas fa-school';
$moduleColor = '#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'parents.php','icon'=>'fas fa-users','label'=>'Parents'],['url'=>'staff.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Staff'],['url'=>'classes.php','icon'=>'fas fa-chalkboard','label'=>'Classes'],['url'=>'subjects.php','icon'=>'fas fa-book','label'=>'Subjects'],['url'=>'timetable.php','icon'=>'fas fa-calendar-alt','label'=>'Timetable'],['url'=>'attendance.php','icon'=>'fas fa-clipboard-check','label'=>'Attendance'],['url'=>'exams.php','icon'=>'fas fa-file-alt','label'=>'Exams'],['url'=>'results.php','icon'=>'fas fa-chart-line','label'=>'Results'],['url'=>'fees.php','icon'=>'fas fa-money-bill','label'=>'Fees'],['url'=>'library.php','icon'=>'fas fa-book-reader','label'=>'Library'],['url'=>'transport.php','icon'=>'fas fa-bus','label'=>'Transport'],['url'=>'events.php','icon'=>'fas fa-calendar-day','label'=>'Events'],['url'=>'notices.php','icon'=>'fas fa-bullhorn','label'=>'Notices'],['url'=>'grades.php','icon'=>'fas fa-star','label'=>'Grades'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'invoice') {
        $studentId = (int)$_POST['student_id'];
        $feeType = sanitize($_POST['fee_type'] ?? 'Tuition Fee');
        $term = sanitize($_POST['term'] ?? 'Term 1');
        $year = (int)($_POST['year'] ?? date('Y'));
        $amount = (float)$_POST['amount'];
        $dueDate = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));

        $stmt = $pdo->prepare("INSERT INTO sch_fees (org_id, student_id, term, year, fee_type, amount, paid, balance, due_date, status) VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, ?, 'unpaid')");
        $stmt->execute([$orgId, $studentId, $term, $year, $feeType, $amount, $amount, $dueDate]);

        setFlash('success', 'Fee invoice generated successfully.');
        logActivity('create', 'school', "Fee Invoice issued: $feeType (Amt: $amount)");
        redirect('fees.php');
    }

    if ($action === 'pay') {
        $feeId = (int)$_POST['fee_id'];
        $paymentAmount = (float)$_POST['payment_amount'];

        // Get current fee invoice
        $stmt = $pdo->prepare("SELECT * FROM sch_fees WHERE id = ? AND org_id = ?");
        $stmt->execute([$feeId, $orgId]);
        $fee = $stmt->fetch();

        if ($fee) {
            $newPaid = (float)$fee['paid'] + $paymentAmount;
            $newBalance = (float)$fee['amount'] - $newPaid;
            
            $status = 'unpaid';
            if ($newBalance <= 0) {
                $status = 'paid';
                $newBalance = 0; // Prevent negative balances in displays
            } elseif ($newPaid > 0) {
                $status = 'partial';
            }

            $stmt = $pdo->prepare("UPDATE sch_fees SET paid = ?, balance = ?, status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$newPaid, $newBalance, $status, $feeId, $orgId]);

            setFlash('success', 'Fee payment recorded successfully.');
            logActivity('update', 'school', "Recorded fee payment for Invoice #$feeId (Amt: $paymentAmount)");
        }
        redirect('fees.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM sch_fees WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Fee invoice deleted.');
        redirect('fees.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$feesList = [];
try {
    $stmt = $pdo->prepare("SELECT f.*, s.first_name, s.last_name, s.admission_no, c.name AS class_name 
                           FROM sch_fees f
                           JOIN sch_students s ON f.student_id = s.id
                           LEFT JOIN sch_classes c ON s.class_id = c.id
                           WHERE f.org_id = ?
                           ORDER BY f.created_at DESC");
    $stmt->execute([$orgId]);
    $feesList = $stmt->fetchAll();
} catch (Exception $e) {}

$studentsList = [];
try {
    $stmt = $pdo->prepare("SELECT id, admission_no, first_name, last_name FROM sch_students WHERE org_id = ? AND status = 'active' ORDER BY first_name ASC");
    $stmt->execute([$orgId]);
    $studentsList = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-bill me-2" style="color:<?= $moduleColor ?>"></i>Fee Accounts & Billing</h4>
    <p class="text-muted mb-0">Record school fees, issue standard invoices, and track outstanding balances</p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#payModal"><i class="fas fa-cash-register me-2"></i>Receive Payment</button>
    <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#invoiceModal"><i class="fas fa-file-invoice-dollar me-2"></i>Issue Invoice</button>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-file-invoice-dollar me-2 text-success"></i>Fee Statements</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Student Details</th>
            <th>Class</th>
            <th>Billing Type</th>
            <th>Term / Session</th>
            <th class="text-end">Invoice Amt</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Balance Due</th>
            <th class="text-center">Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($feesList)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-wallet fa-2x mb-2 d-block"></i>No fee invoices generated.</td></tr>
          <?php else: foreach ($feesList as $f): 
            $badges = ['paid' => 'success', 'partial' => 'warning text-dark', 'unpaid' => 'danger'];
            $bg = $badges[$f['status']] ?? 'secondary';
          ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><?= e($f['first_name'] . ' ' . $f['last_name']) ?></div>
              <small class="text-muted">Adm No: <?= e($f['admission_no']) ?></small>
            </td>
            <td><?= e($f['class_name'] ?: 'Unassigned') ?></td>
            <td class="fw-semibold text-dark"><?= e($f['fee_type']) ?></td>
            <td><?= e($f['term']) ?> / <?= $f['year'] ?></td>
            <td class="text-end fw-bold text-dark"><?= formatCurrency($f['amount']) ?></td>
            <td class="text-end text-success fw-semibold"><?= formatCurrency($f['paid']) ?></td>
            <td class="text-end text-danger fw-bold"><?= formatCurrency($f['balance']) ?></td>
            <td class="text-center"><span class="badge bg-<?= $bg ?>"><?= ucfirst($f['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-success" onclick="openPayment(<?= $f['id'] ?>, '<?= e($f['first_name'] . ' ' . $f['last_name']) ?>', <?= $f['balance'] ?>)" title="Pay Now" <?= $f['balance'] <= 0 ? 'disabled' : '' ?>><i class="fas fa-hand-holding-usd"></i></button>
                <button class="btn btn-outline-danger" onclick="delInvoice(<?= $f['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="invoice">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2"></i>Issue Fee Invoice</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Select Student <span class="text-danger">*</span></label>
        <select name="student_id" class="form-select select2-enable" required style="width:100%;">
          <option value="">-- select student --</option>
          <?php foreach ($studentsList as $st): ?>
          <option value="<?= $st['id'] ?>"><?= e($st['first_name'] . ' ' . $st['last_name']) ?> (<?= e($st['admission_no']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Fee Classification / Category <span class="text-danger">*</span></label>
        <input type="text" name="fee_type" class="form-control" required value="Tuition Fee" placeholder="e.g. Tuition Fee, Exam Fee, Bus Fee">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Academic Term <span class="text-danger">*</span></label>
        <select name="term" class="form-select" required>
          <option value="Term 1">Term 1</option>
          <option value="Term 2">Term 2</option>
          <option value="Term 3">Term 3</option>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Academic Year <span class="text-danger">*</span></label>
        <input type="number" name="year" class="form-control" required value="<?= date('Y') ?>">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Amount (KES) <span class="text-danger">*</span></label>
        <input type="number" name="amount" class="form-control" required min="1" step="0.01" placeholder="0.00">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Payment Due Date <span class="text-danger">*</span></label>
        <input type="date" name="due_date" class="form-control" required value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-file-signature me-1"></i>Generate Invoice</button>
  </div>
  </form>
</div></div></div>

<!-- Pay Modal -->
<div class="modal fade" id="payModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="pay">
  <div class="modal-header bg-success text-white">
    <h5 class="modal-title"><i class="fas fa-cash-register me-2"></i>Record Fee Payment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Select Invoice Statement <span class="text-danger">*</span></label>
        <select name="fee_id" id="payFeeId" class="form-select" required onchange="updatePayLimit(this)">
          <option value="">-- select billing invoice --</option>
          <?php foreach ($feesList as $f): if ($f['balance'] > 0): ?>
          <option value="<?= $f['id'] ?>" data-balance="<?= $f['balance'] ?>">
            <?= e($f['first_name'] . ' ' . $f['last_name']) ?> - <?= e($f['fee_type']) ?> (Bal: <?= formatCurrency($f['balance']) ?>)
          </option>
          <?php endif; endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Recording Amount to Pay (KES) <span class="text-danger">*</span></label>
        <input type="number" name="payment_amount" id="payAmt" class="form-control form-control-lg fw-bold text-success" required min="1" step="0.01" placeholder="0.00">
        <small class="text-muted d-block mt-1" id="payLimitHint"></small>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-success"><i class="fas fa-check-circle me-1"></i>Record Payment</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delFeeForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delFeeId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openPayment(id, name, balance) {
  document.getElementById('payFeeId').value = id;
  document.getElementById('payAmt').value = balance;
  document.getElementById('payAmt').max = balance;
  document.getElementById('payLimitHint').innerHTML = 'Remaining balance for ' + name + ' is <strong>KES ' + balance.toLocaleString() + '</strong>';
  new bootstrap.Modal(document.getElementById('payModal')).show();
}
function updatePayLimit(select) {
  const opt = select.options[select.selectedIndex];
  if(opt && opt.value) {
    const bal = parseFloat(opt.getAttribute('data-balance'));
    document.getElementById('payAmt').value = bal;
    document.getElementById('payAmt').max = bal;
    document.getElementById('payLimitHint').innerHTML = 'Remaining balance is <strong>KES ' + bal.toLocaleString() + '</strong>';
  } else {
    document.getElementById('payLimitHint').innerHTML = '';
  }
}
function delInvoice(id) {
  Swal.fire({
    title: 'Delete Fee Statement?',
    text: 'Are you sure you want to permanently delete this school fee invoice?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delFeeId').value = id;
      document.getElementById('delFeeForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
