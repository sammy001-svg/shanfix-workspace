<?php
// ── CARYARD: Hire Purchase & Finance Plans ─────────────────────
$moduleSlug  = 'caryard';
$moduleName  = 'Car Yard Management';
$moduleIcon  = 'fas fa-car';
$moduleColor = '#e67e22';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'vehicles.php',       'icon' => 'fas fa-car',            'label' => 'Vehicles'],
    ['url' => 'sales.php',          'icon' => 'fas fa-handshake',      'label' => 'Sales'],
    ['url' => 'customers.php',      'icon' => 'fas fa-users',          'label' => 'Customers'],
    ['url' => 'finance.php',        'icon' => 'fas fa-university',     'label' => 'Finance'],
    ['url' => 'testdrives.php',     'icon' => 'fas fa-road',           'label' => 'Test Drives'],
    ['url' => 'services.php',       'icon' => 'fas fa-tools',          'label' => 'Services'],
    ['url' => 'reconditioning.php', 'icon' => 'fas fa-wrench',         'label' => 'Reconditioning'],
    ['url' => 'valuations.php',     'icon' => 'fas fa-search-dollar',  'label' => 'Valuations'],
    ['url' => 'inquiries.php',      'icon' => 'fas fa-comments',       'label' => 'Inquiries'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_plan') {
        $id            = (int)($_POST['id'] ?? 0);
        $vehicleId     = (int)($_POST['vehicle_id']     ?? 0);
        $customerName  = sanitize($_POST['customer_name']  ?? '');
        $customerPhone = sanitize($_POST['customer_phone'] ?? '');
        $lender        = sanitize($_POST['lender']         ?? '');
        $principal     = (float)($_POST['principal']       ?? 0);
        $deposit       = (float)($_POST['deposit']         ?? 0);
        $interestRate  = (float)($_POST['interest_rate']   ?? 0);
        $termMonths    = (int)($_POST['term_months']        ?? 12);
        $startDate     = $_POST['start_date']               ?? date('Y-m-d');
        $status        = in_array($_POST['status'] ?? '', ['active','completed','defaulted','cancelled']) ? $_POST['status'] : 'active';
        $notes         = sanitize($_POST['notes']           ?? '');

        if (!$vehicleId || !$customerName || $principal <= 0) {
            setFlash('danger', 'Vehicle, customer name, and principal amount are required.');
            redirect('finance.php');
        }

        // Calculate schedule
        $loanAmount    = $principal - $deposit;
        $monthlyRate   = $interestRate / 100 / 12;
        if ($monthlyRate > 0 && $termMonths > 0) {
            $monthlyPayment = $loanAmount * ($monthlyRate * pow(1 + $monthlyRate, $termMonths)) / (pow(1 + $monthlyRate, $termMonths) - 1);
        } else {
            $monthlyPayment = $termMonths > 0 ? $loanAmount / $termMonths : $loanAmount;
        }
        $totalPayable = $deposit + ($monthlyPayment * $termMonths);

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE caryard_finance SET vehicle_id=?,customer_name=?,customer_phone=?,lender=?,principal=?,deposit=?,interest_rate=?,term_months=?,monthly_payment=?,total_payable=?,start_date=?,status=?,notes=? WHERE id=? AND org_id=?");
            $stmt->execute([$vehicleId, $customerName, $customerPhone, $lender, $principal, $deposit, $interestRate, $termMonths, round($monthlyPayment,2), round($totalPayable,2), $startDate, $status, $notes, $id, $orgId]);
            setFlash('success', 'Finance plan updated.');
            logActivity('update', 'caryard', "Updated finance plan #$id");
        } else {
            $stmt = $pdo->prepare("INSERT INTO caryard_finance (org_id,vehicle_id,customer_name,customer_phone,lender,principal,deposit,interest_rate,term_months,monthly_payment,total_payable,balance,start_date,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $balance = round($totalPayable - $deposit, 2); // balance starts at total minus deposit already paid
            $stmt->execute([$orgId, $vehicleId, $customerName, $customerPhone, $lender, $principal, $deposit, $interestRate, $termMonths, round($monthlyPayment,2), round($totalPayable,2), $balance, $startDate, $status, $notes, $user['id']]);
            setFlash('success', 'Finance plan created.');
            logActivity('create', 'caryard', "Created finance plan for $customerName");
        }
        redirect('finance.php');
    }

    if ($action === 'record_payment') {
        $financeId   = (int)($_POST['finance_id']   ?? 0);
        $amount      = (float)($_POST['amount']       ?? 0);
        $paymentDate = $_POST['payment_date']          ?? date('Y-m-d');
        $method      = sanitize($_POST['method']       ?? 'Bank Transfer');
        $reference   = sanitize($_POST['reference']    ?? '');
        $notes       = sanitize($_POST['pay_notes']    ?? '');

        if (!$financeId || $amount <= 0) {
            setFlash('danger', 'Amount is required.');
            redirect('finance.php');
        }

        // Insert payment
        $pdo->prepare("INSERT INTO caryard_finance_payments (finance_id,org_id,amount,payment_date,method,reference,notes) VALUES (?,?,?,?,?,?,?)")
            ->execute([$financeId, $orgId, $amount, $paymentDate, $method, $reference, $notes]);

        // Recalculate totals
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM caryard_finance_payments WHERE finance_id=? AND org_id=?");
        $stmt->execute([$financeId, $orgId]);
        $totalPaid = (float)$stmt->fetchColumn();

        // Get plan totals
        $plan = $pdo->prepare("SELECT total_payable, deposit FROM caryard_finance WHERE id=? AND org_id=?");
        $plan->execute([$financeId, $orgId]);
        $plan = $plan->fetch();
        $totalPayable = (float)$plan['total_payable'];
        $deposit      = (float)$plan['deposit'];
        $amountPaid   = $totalPaid + $deposit;
        $balance      = max(0, $totalPayable - $amountPaid);
        $newStatus    = $balance <= 0.01 ? 'completed' : 'active';

        $pdo->prepare("UPDATE caryard_finance SET amount_paid=?,balance=?,status=? WHERE id=? AND org_id=?")
            ->execute([$amountPaid, $balance, $newStatus, $financeId, $orgId]);

        setFlash('success', 'Payment recorded. Balance updated.');
        logActivity('create', 'caryard', "Recorded payment of ".formatCurrency($amount)." on finance plan #$financeId");
        redirect('finance.php');
    }

    if ($action === 'delete_plan') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM caryard_finance_payments WHERE finance_id=? AND org_id=?")->execute([$id, $orgId]);
        $pdo->prepare("DELETE FROM caryard_finance WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Finance plan deleted.');
        logActivity('delete', 'caryard', "Deleted finance plan #$id");
        redirect('finance.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// View single plan's payment history
$viewPlan = null;
$planPayments = [];
if (isset($_GET['view'])) {
    $vid = (int)$_GET['view'];
    try {
        $stmt = $pdo->prepare("SELECT f.*, v.stock_no, v.make, v.model, v.year FROM caryard_finance f JOIN caryard_vehicles v ON f.vehicle_id = v.id WHERE f.id=? AND f.org_id=?");
        $stmt->execute([$vid, $orgId]);
        $viewPlan = $stmt->fetch();
        if ($viewPlan) {
            $stmt2 = $pdo->prepare("SELECT * FROM caryard_finance_payments WHERE finance_id=? AND org_id=? ORDER BY payment_date DESC");
            $stmt2->execute([$vid, $orgId]);
            $planPayments = $stmt2->fetchAll();
        }
    } catch (Exception $e) {}
}

// Fetch all finance plans
$plans = [];
try {
    $stmt = $pdo->prepare("
        SELECT f.*, CONCAT(v.make,' ',v.model,' (',v.year,')') AS vehicle_label, v.stock_no
        FROM caryard_finance f
        JOIN caryard_vehicles v ON f.vehicle_id = v.id
        WHERE f.org_id = ?
        ORDER BY f.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $plans = $stmt->fetchAll();
} catch (Exception $e) {}

// Vehicles for dropdown
$vehicles = [];
try {
    $stmt = $pdo->prepare("SELECT id, stock_no, make, model, year, selling_price FROM caryard_vehicles WHERE org_id=? ORDER BY make,model");
    $stmt->execute([$orgId]);
    $vehicles = $stmt->fetchAll();
} catch (Exception $e) {}

$activePlans    = count(array_filter($plans, fn($p) => $p['status'] === 'active'));
$completedPlans = count(array_filter($plans, fn($p) => $p['status'] === 'completed'));
$totalOutstanding = array_sum(array_column(array_filter($plans, fn($p) => $p['status'] === 'active'), 'balance'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-university me-2" style="color:<?= $moduleColor ?>"></i>Hire Purchase & Finance</h4>
    <p class="text-muted mb-0">Track installment plans, record payments, and monitor outstanding balances</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#financeModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>New Finance Plan
  </button>
</div>

<?php if ($viewPlan): ?>
<!-- Payment History View -->
<div class="card mb-4 border-0 shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:rgba(230,126,34,.08)">
    <div>
      <h6 class="mb-0 fw-bold"><i class="fas fa-file-contract me-2" style="color:<?= $moduleColor ?>"></i>
        Finance Plan — <?= e($viewPlan['customer_name']) ?> | <?= e($viewPlan['vehicle_label']) ?>
      </h6>
      <div class="small text-muted mt-1">
        Principal: <?= formatCurrency((float)$viewPlan['principal']) ?> &bull;
        Deposit: <?= formatCurrency((float)$viewPlan['deposit']) ?> &bull;
        <?= $viewPlan['term_months'] ?> months @ <?= $viewPlan['interest_rate'] ?>% p.a. &bull;
        Monthly: <?= formatCurrency((float)$viewPlan['monthly_payment']) ?>
      </div>
    </div>
    <a href="finance.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
  </div>
  <div class="card-body">
    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <div class="p-3 rounded" style="background:rgba(26,138,78,.08)">
          <div class="small text-muted">Total Paid</div>
          <div class="fs-5 fw-bold text-success"><?= formatCurrency((float)$viewPlan['amount_paid']) ?></div>
        </div>
      </div>
      <div class="col-sm-4">
        <div class="p-3 rounded" style="background:rgba(231,76,60,.08)">
          <div class="small text-muted">Outstanding Balance</div>
          <div class="fs-5 fw-bold text-danger"><?= formatCurrency((float)$viewPlan['balance']) ?></div>
        </div>
      </div>
      <div class="col-sm-4">
        <div class="p-3 rounded" style="background:rgba(230,126,34,.08)">
          <div class="small text-muted">Total Payable</div>
          <div class="fs-5 fw-bold" style="color:<?= $moduleColor ?>"><?= formatCurrency((float)$viewPlan['total_payable']) ?></div>
        </div>
      </div>
    </div>
    <!-- Progress bar -->
    <?php $pct = $viewPlan['total_payable'] > 0 ? min(100, round(($viewPlan['amount_paid'] / $viewPlan['total_payable']) * 100)) : 0; ?>
    <div class="progress mb-3" style="height:10px">
      <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $moduleColor ?>"></div>
    </div>
    <div class="d-flex justify-content-between small text-muted mb-4">
      <span><?= $pct ?>% paid</span>
      <span><?= formatCurrency((float)$viewPlan['balance']) ?> remaining</span>
    </div>

    <!-- Record payment form -->
    <?php if ($viewPlan['status'] === 'active'): ?>
    <div class="card border mb-4">
      <div class="card-header py-2"><h6 class="mb-0 small fw-semibold"><i class="fas fa-plus-circle me-1 text-success"></i>Record Installment Payment</h6></div>
      <div class="card-body">
        <form method="POST" class="row g-2 align-items-end">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="record_payment">
          <input type="hidden" name="finance_id" value="<?= $viewPlan['id'] ?>">
          <div class="col-sm-3">
            <label class="form-label small fw-semibold">Amount</label>
            <input type="number" name="amount" class="form-control form-control-sm" step="0.01" min="0.01" value="<?= $viewPlan['monthly_payment'] ?>" required>
          </div>
          <div class="col-sm-3">
            <label class="form-label small fw-semibold">Date</label>
            <input type="date" name="payment_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-sm-3">
            <label class="form-label small fw-semibold">Method</label>
            <select name="method" class="form-select form-select-sm">
              <option>Bank Transfer</option>
              <option>Cash</option>
              <option>M-Pesa</option>
              <option>Cheque</option>
            </select>
          </div>
          <div class="col-sm-2">
            <label class="form-label small fw-semibold">Reference</label>
            <input type="text" name="reference" class="form-control form-control-sm" placeholder="Ref #">
          </div>
          <div class="col-sm-1">
            <button type="submit" class="btn btn-sm btn-success w-100"><i class="fas fa-save"></i></button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Payment history -->
    <h6 class="fw-semibold mb-2"><i class="fas fa-history me-1"></i>Payment History</h6>
    <?php if (empty($planPayments)): ?>
    <p class="text-muted small">No payments recorded yet.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light">
          <tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Notes</th></tr>
        </thead>
        <tbody>
          <?php foreach ($planPayments as $pay): ?>
          <tr>
            <td><?= formatDate($pay['payment_date']) ?></td>
            <td class="fw-semibold text-success"><?= formatCurrency((float)$pay['amount']) ?></td>
            <td><?= e($pay['method']) ?></td>
            <td class="small text-muted"><?= e($pay['reference'] ?: '—') ?></td>
            <td class="small text-muted"><?= e($pay['notes'] ?: '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(230,126,34,.12);color:#e67e22"><i class="fas fa-file-contract"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activePlans ?></div><div class="stat-label">Active Plans</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $completedPlans ?></div><div class="stat-label">Completed Plans</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalOutstanding) ?></div><div class="stat-label">Total Outstanding</div></div>
    </div>
  </div>
</div>

<!-- Plans Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-university me-2" style="color:<?= $moduleColor ?>"></i>All Finance Plans</h6>
    <span class="badge bg-secondary"><?= count($plans) ?> plans</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Customer</th>
            <th>Vehicle</th>
            <th>Lender</th>
            <th class="text-end">Principal</th>
            <th class="text-end">Monthly</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Balance</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($plans)): ?>
          <tr><td colspan="9" class="text-center py-5 text-muted">
            <i class="fas fa-university fa-2x mb-2 d-block"></i>No finance plans yet.
          </td></tr>
          <?php else: foreach ($plans as $p): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($p['customer_name']) ?></div>
              <div class="small text-muted"><?= e($p['customer_phone'] ?: '') ?></div>
            </td>
            <td>
              <div class="small fw-semibold"><?= e($p['vehicle_label']) ?></div>
              <div class="small text-muted"><?= e($p['stock_no']) ?></div>
            </td>
            <td class="small"><?= e($p['lender'] ?: '—') ?></td>
            <td class="text-end"><?= formatCurrency((float)$p['principal']) ?></td>
            <td class="text-end fw-semibold" style="color:<?= $moduleColor ?>"><?= formatCurrency((float)$p['monthly_payment']) ?></td>
            <td class="text-end text-success"><?= formatCurrency((float)$p['amount_paid']) ?></td>
            <td class="text-end fw-bold <?= (float)$p['balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency((float)$p['balance']) ?></td>
            <td><?= statusBadge($p['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <a href="finance.php?view=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info" title="View Payments">
                <i class="fas fa-eye"></i>
              </a>
              <button class="btn btn-sm btn-outline-primary ms-1"
                onclick='openEdit(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this finance plan and all payments?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_plan">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="financeModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="financeForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_plan">
        <input type="hidden" name="id" id="finId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="finTitle"><i class="fas fa-university me-2"></i>New Finance Plan</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label>
              <select name="vehicle_id" id="finVehicle" class="form-select" required onchange="autofillPrincipal()">
                <option value="">— Select Vehicle —</option>
                <?php foreach ($vehicles as $v): ?>
                <option value="<?= $v['id'] ?>" data-price="<?= $v['selling_price'] ?>">
                  <?= e($v['stock_no'].' — '.$v['make'].' '.$v['model'].' ('.$v['year'].')') ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
              <input type="text" name="customer_name" id="finCustomer" class="form-control" required placeholder="e.g. James Mwangi">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Customer Phone</label>
              <input type="text" name="customer_phone" id="finPhone" class="form-control" placeholder="+254...">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Lender / Bank</label>
              <input type="text" name="lender" id="finLender" class="form-control" placeholder="e.g. KCB Asset Finance">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Start Date</label>
              <input type="date" name="start_date" id="finStart" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Vehicle Price (Principal)</label>
              <input type="number" name="principal" id="finPrincipal" class="form-control" step="0.01" min="0" value="0" oninput="calcSchedule()">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Deposit</label>
              <input type="number" name="deposit" id="finDeposit" class="form-control" step="0.01" min="0" value="0" oninput="calcSchedule()">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Interest Rate (% p.a.)</label>
              <input type="number" name="interest_rate" id="finRate" class="form-control" step="0.01" min="0" value="0" oninput="calcSchedule()">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Term (Months)</label>
              <input type="number" name="term_months" id="finTerm" class="form-control" min="1" value="12" oninput="calcSchedule()">
            </div>
            <!-- Live calculation preview -->
            <div class="col-12">
              <div class="p-3 rounded border bg-light">
                <div class="row text-center g-2">
                  <div class="col-4">
                    <div class="small text-muted">Loan Amount</div>
                    <div class="fw-bold" id="calcLoan">—</div>
                  </div>
                  <div class="col-4">
                    <div class="small text-muted">Monthly Payment</div>
                    <div class="fw-bold" style="color:<?= $moduleColor ?>" id="calcMonthly">—</div>
                  </div>
                  <div class="col-4">
                    <div class="small text-muted">Total Payable</div>
                    <div class="fw-bold text-danger" id="calcTotal">—</div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="finStatus" class="form-select">
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="defaulted">Defaulted</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="finNotes" class="form-control" rows="2" placeholder="Terms, conditions, special arrangements..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Plan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("finTitle").innerHTML = "<i class=\"fas fa-university me-2\"></i>New Finance Plan";
  document.getElementById("finId").value       = 0;
  document.getElementById("finVehicle").value  = "";
  document.getElementById("finCustomer").value = "";
  document.getElementById("finPhone").value    = "";
  document.getElementById("finLender").value   = "";
  document.getElementById("finStart").value    = "' . date('Y-m-d') . '";
  document.getElementById("finPrincipal").value= "0";
  document.getElementById("finDeposit").value  = "0";
  document.getElementById("finRate").value     = "0";
  document.getElementById("finTerm").value     = "12";
  document.getElementById("finStatus").value   = "active";
  document.getElementById("finNotes").value    = "";
  calcSchedule();
}

function openEdit(p) {
  document.getElementById("finTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Finance Plan";
  document.getElementById("finId").value       = p.id;
  document.getElementById("finVehicle").value  = p.vehicle_id    || "";
  document.getElementById("finCustomer").value = p.customer_name || "";
  document.getElementById("finPhone").value    = p.customer_phone|| "";
  document.getElementById("finLender").value   = p.lender        || "";
  document.getElementById("finStart").value    = p.start_date    || "";
  document.getElementById("finPrincipal").value= p.principal     || "0";
  document.getElementById("finDeposit").value  = p.deposit       || "0";
  document.getElementById("finRate").value     = p.interest_rate || "0";
  document.getElementById("finTerm").value     = p.term_months   || "12";
  document.getElementById("finStatus").value   = p.status        || "active";
  document.getElementById("finNotes").value    = p.notes         || "";
  calcSchedule();
  new bootstrap.Modal(document.getElementById("financeModal")).show();
}

function autofillPrincipal() {
  var sel = document.getElementById("finVehicle");
  var opt = sel.options[sel.selectedIndex];
  if (opt && opt.dataset.price) {
    document.getElementById("finPrincipal").value = opt.dataset.price;
    calcSchedule();
  }
}

function calcSchedule() {
  var principal = parseFloat(document.getElementById("finPrincipal").value) || 0;
  var deposit   = parseFloat(document.getElementById("finDeposit").value)   || 0;
  var rate      = parseFloat(document.getElementById("finRate").value)      || 0;
  var term      = parseInt(document.getElementById("finTerm").value)        || 12;
  var loan = principal - deposit;
  if (loan < 0) loan = 0;
  var monthly, total;
  var mRate = rate / 100 / 12;
  if (mRate > 0 && term > 0) {
    monthly = loan * (mRate * Math.pow(1+mRate, term)) / (Math.pow(1+mRate,term) - 1);
  } else {
    monthly = term > 0 ? loan / term : loan;
  }
  total = deposit + (monthly * term);
  var fmt = function(n){ return n.toLocaleString("en-US", {minimumFractionDigits:2,maximumFractionDigits:2}); };
  document.getElementById("calcLoan").textContent    = fmt(loan);
  document.getElementById("calcMonthly").textContent = fmt(monthly);
  document.getElementById("calcTotal").textContent   = fmt(total);
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
