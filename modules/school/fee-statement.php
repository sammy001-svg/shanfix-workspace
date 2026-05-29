<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireModuleAccess('school');
$user  = currentUser();
$orgId = (int)$user['org_id'];

$studentId = (int)($_GET['student_id'] ?? 0);
$classId   = (int)($_GET['class_id']   ?? 0);
$search    = sanitize($_GET['search']  ?? '');

// PDF Export
if (isset($_GET['pdf']) && $studentId) {
    require_once __DIR__ . '/../../includes/pdf.php';
    // Fetch student
    $stmt = $pdo->prepare("SELECT s.*, c.name AS class_name FROM sch_students s LEFT JOIN sch_classes c ON s.class_id=c.id WHERE s.id=? AND s.org_id=?");
    $stmt->execute([$studentId, $orgId]);
    $stu = $stmt->fetch();
    if (!$stu) redirect(APP_URL . '/modules/school/fee-statement.php');

    $rows = [];
    $balance = 0;
    try {
        // Charges
        $charges = $pdo->prepare("SELECT 'Charge' AS type, due_date AS event_date, fee_type AS description, amount, 0 AS payment FROM sch_fees WHERE student_id=? ORDER BY due_date");
        $charges->execute([$studentId]);
        $chargeRows = $charges->fetchAll();
        // Payments
        $pays = $pdo->prepare("SELECT 'Payment' AS type, payment_date AS event_date, CONCAT('Payment — ',method) AS description, 0 AS amount, amount_paid AS payment FROM sch_fee_payments WHERE student_id=? ORDER BY payment_date");
        $pays->execute([$studentId]);
        $payRows = $pays->fetchAll();

        $all = array_merge($chargeRows, $payRows);
        usort($all, fn($a, $b) => strcmp($a['event_date'], $b['event_date']));
        foreach ($all as $r) {
            $balance += (float)$r['amount'] - (float)$r['payment'];
            $rows[] = [
                formatDate($r['event_date']),
                $r['description'],
                $r['type'] === 'Charge' ? 'KES '.number_format($r['amount'],2) : '—',
                $r['type'] === 'Payment' ? 'KES '.number_format($r['payment'],2) : '—',
                'KES '.number_format($balance,2),
            ];
        }
    } catch (Exception $e) {}

    generateModuleReportPDF(
        'Fee Statement — ' . ($stu['first_name']??'') . ' ' . ($stu['last_name']??''),
        'Adm No: '.($stu['admission_number']??'').(' | Class: '.($stu['class_name']??'')),
        [['label'=>'Total Charged','value'=>'KES '.number_format(array_sum(array_column($chargeRows??[],'amount')),2)],['label'=>'Total Paid','value'=>'KES '.number_format(array_sum(array_column($payRows??[],'payment')),2)],['label'=>'Balance Due','value'=>'KES '.number_format($balance,2)]],
        [['label'=>'Date','width'=>26,'align'=>'L'],['label'=>'Description','width'=>60,'align'=>'L'],['label'=>'Charge','width'=>32,'align'=>'R'],['label'=>'Payment','width'=>32,'align'=>'R'],['label'=>'Balance','width'=>36,'align'=>'R']],
        $rows,
        'fee-statement-'.($stu['admission_number']??$studentId).'.pdf',
        [26, 138, 78]
    );
}

// Students
$students = [];
$classes  = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, admission_number, class_id FROM sch_students WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]); $students = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT id, name FROM sch_classes WHERE org_id=? ORDER BY name");
    $stmt->execute([$orgId]); $classes = $stmt->fetchAll();
} catch (Exception $e) {}

// If search
$filteredStudents = $students;
if ($search) {
    $filteredStudents = array_filter($students, fn($s) => stripos($s['name'], $search) !== false || stripos($s['admission_number'], $search) !== false);
}
if ($classId) {
    $filteredStudents = array_filter($filteredStudents, fn($s) => (int)$s['class_id'] === $classId);
}

$selectedStudent = null;
$charges = $payments = [];
$totalCharged = $totalPaid = $balance = 0;
$ledger = [];

if ($studentId) {
    foreach ($students as $s) { if ($s['id'] === $studentId) { $selectedStudent = $s; break; } }
    if ($selectedStudent) {
        try {
            $stmt = $pdo->prepare("SELECT fee_type AS description, due_date, term, amount FROM sch_fees WHERE student_id=? AND org_id=? ORDER BY due_date");
            $stmt->execute([$studentId, $orgId]);
            $charges = $stmt->fetchAll();
            $totalCharged = array_sum(array_column($charges, 'amount'));

            $stmt = $pdo->prepare("SELECT receipt_no, payment_date, method, amount_paid FROM sch_fee_payments WHERE student_id=? ORDER BY payment_date");
            $stmt->execute([$studentId]);
            $payments = $stmt->fetchAll();
            $totalPaid = array_sum(array_column($payments, 'amount_paid'));
            $balance   = $totalCharged - $totalPaid;

            // Build unified ledger
            $all = array_merge(
                array_map(fn($r) => ['type'=>'charge', 'date'=>$r['due_date'], 'desc'=>$r['description'].' ('.$r['term'].')', 'charge'=>$r['amount'], 'payment'=>0], $charges),
                array_map(fn($r) => ['type'=>'payment', 'date'=>$r['payment_date'], 'desc'=>'Payment — '.($r['method']??'').($r['receipt_no']?' (Rcpt: '.$r['receipt_no'].')':''), 'charge'=>0, 'payment'=>$r['amount_paid']], $payments)
            );
            usort($all, fn($a, $b) => strcmp($a['date'], $b['date']));
            $running = 0;
            foreach ($all as $r) {
                $running += $r['charge'] - $r['payment'];
                $ledger[] = array_merge($r, ['running' => $running]);
            }
        } catch (Exception $e) {}
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Fee Statements</h4>
    <p class="text-muted mb-0">View and print individual or class fee statements</p>
  </div>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by name or admission number…" value="<?= e($search) ?>">
      </div>
      <div class="col-md-3">
        <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Classes</option>
          <?php foreach ($classes as $cl): ?>
          <option value="<?= $cl['id'] ?>" <?= $classId===$cl['id']?'selected':'' ?>><?= e($cl['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-sm btn-outline-secondary w-100"><i class="fas fa-search me-1"></i>Search</button>
      </div>
      <?php if ($search || $classId): ?>
      <div class="col-auto"><a href="fee-statement.php" class="btn btn-sm btn-link">Clear</a></div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($selectedStudent): ?>
<!-- Student fee statement -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h6 class="fw-bold mb-0">
    Statement for: <span style="color:<?= $moduleColor ?>"><?= e($selectedStudent['name']) ?></span>
    <span class="badge bg-secondary ms-2"><?= e($selectedStudent['admission_number'] ?? '') ?></span>
  </h6>
  <div class="d-flex gap-2">
    <a href="?student_id=<?= $studentId ?>&pdf=1" class="btn btn-sm btn-outline-danger">
      <i class="fas fa-file-pdf me-1"></i>Download PDF
    </a>
    <a href="fee-statement.php" class="btn btn-sm btn-outline-secondary">← Back</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['Total Charged',   formatCurrency($totalCharged), '#e74c3c', 'fas fa-file-invoice'],
    ['Total Paid',      formatCurrency($totalPaid),    '#27ae60', 'fas fa-check-circle'],
    ['Balance Due',     formatCurrency($balance),      $balance>0?'#e74c3c':'#27ae60', 'fas fa-balance-scale'],
  ] as [$l,$v,$c,$i]): ?>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $c ?>20;color:<?= $c ?>"><i class="<?= $i ?>"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $v ?></div><div class="stat-label"><?= $l ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header fw-semibold">Account Ledger</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <thead class="table-light">
          <tr><th>Date</th><th>Description</th><th class="text-end">Charge</th><th class="text-end">Payment</th><th class="text-end">Balance</th></tr>
        </thead>
        <tbody>
          <?php if (empty($ledger)): ?>
          <tr><td colspan="5" class="text-center py-4 text-muted">No transactions recorded for this student.</td></tr>
          <?php else: foreach ($ledger as $r): ?>
          <tr class="<?= $r['type']==='payment'?'table-success':'' ?>">
            <td><?= formatDate($r['date']) ?></td>
            <td><?= e($r['desc']) ?></td>
            <td class="text-end text-danger"><?= $r['charge']>0 ? formatCurrency($r['charge']) : '—' ?></td>
            <td class="text-end text-success"><?= $r['payment']>0 ? formatCurrency($r['payment']) : '—' ?></td>
            <td class="text-end fw-semibold <?= $r['running']>0?'text-danger':'text-success' ?>"><?= formatCurrency($r['running']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <tfoot class="fw-bold">
          <tr class="table-secondary">
            <td colspan="2">TOTALS</td>
            <td class="text-end text-danger"><?= formatCurrency($totalCharged) ?></td>
            <td class="text-end text-success"><?= formatCurrency($totalPaid) ?></td>
            <td class="text-end <?= $balance>0?'text-danger':'text-success' ?>"><?= formatCurrency($balance) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php else: ?>
<!-- Student list -->
<div class="card">
  <div class="card-header fw-semibold">Select a Student</div>
  <?php if (empty($filteredStudents)): ?>
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-user-graduate fa-3x d-block mb-3 opacity-25"></i>
    <?= $search ? 'No students found matching "'.e($search).'".' : 'No students found.' ?>
  </div>
  <?php else: ?>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 small align-middle" id="stuTable">
        <thead class="table-light"><tr><th>Name</th><th>Admission No</th><th>Class</th><th class="text-end">Action</th></tr></thead>
        <tbody>
          <?php foreach ($filteredStudents as $s): ?>
          <tr>
            <td class="fw-semibold"><?= e($s['name']) ?></td>
            <td class="text-muted"><?= e($s['admission_number'] ?? '—') ?></td>
            <td class="text-muted"><?php foreach ($classes as $cl) { if ($cl['id'] === (int)$s['class_id']) { echo e($cl['name']); break; } } ?></td>
            <td class="text-end">
              <a href="?student_id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-primary">
                <i class="fas fa-file-invoice me-1"></i>Statement
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
$extraJs = <<<'JS'
<script>
const t = document.getElementById('stuTable');
if (t) new DataTable(t, {pageLength:25, order:[[0,'asc']]});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
