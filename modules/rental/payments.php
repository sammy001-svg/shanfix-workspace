<?php
$moduleSlug  = 'rental';
$moduleName  = 'Rental & Property';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'properties.php',  'icon' => 'fas fa-building',       'label' => 'Properties'],
    ['url' => 'units.php',       'icon' => 'fas fa-door-open',      'label' => 'Units'],
    ['url' => 'tenants.php',     'icon' => 'fas fa-users',          'label' => 'Tenants'],
    ['url' => 'leases.php',      'icon' => 'fas fa-file-contract',  'label' => 'Leases'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-bill',     'label' => 'Payments'],
    ['url' => 'maintenance.php', 'icon' => 'fas fa-tools',          'label' => 'Maintenance'],
    ['url' => 'invoices.php',    'icon' => 'fas fa-file-invoice',   'label' => 'Invoices'],
    ['url' => 'utilities.php',   'icon' => 'fas fa-bolt',            'label' => 'Utilities'],
    ['url' => 'agreements.php',  'icon' => 'fas fa-file-signature', 'label' => 'Agreements'],
    ['url' => 'inspections.php', 'icon' => 'fas fa-clipboard-check','label' => 'Inspections'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $tenantId = (int)$_POST['tenant_id'];
        $amount = (float)$_POST['amount'];
        $period = sanitize($_POST['period'] ?? '');
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
        $reference = sanitize($_POST['reference'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['paid', 'pending', 'partial']) ? $_POST['status'] : 'paid';

        // Fetch unit_id from the tenant
        $stmt = $pdo->prepare("SELECT unit_id FROM rental_tenants WHERE id = ? AND org_id = ?");
        $stmt->execute([$tenantId, $orgId]);
        $unitId = (int)$stmt->fetchColumn();

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE rental_payments SET tenant_id = ?, unit_id = ?, amount = ?, period = ?, payment_date = ?, payment_method = ?, reference = ?, status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$tenantId, $unitId, $amount, $period, $paymentDate, $paymentMethod, $reference, $status, $id, $orgId]);
            setFlash('success', 'Rent payment record updated.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO rental_payments (org_id, tenant_id, unit_id, amount, period, payment_date, payment_method, reference, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $tenantId, $unitId, $amount, $period, $paymentDate, $paymentMethod, $reference, $status]);
            
            // Log under accounts ledger automatically
            try {
                $tenantName = '';
                $st = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) FROM rental_tenants WHERE id = ?");
                $st->execute([$tenantId]);
                $tenantName = $st->fetchColumn();

                $pdo->prepare("INSERT INTO accounts_ledgers (org_id, category, title, description, amount, type, tx_date, reference, method) 
                               VALUES (?, 'revenue', ?, ?, ?, 'credit', ?, ?, ?)")
                    ->execute([$orgId, "Rent: $period", "Rent payment from $tenantName", $amount, $paymentDate, $reference, $paymentMethod]);
            } catch (Exception $ex) {}

            setFlash('success', 'Rent payment recorded successfully in ledger.');
        }
        logActivity($id > 0 ? 'update' : 'create', 'rental', "Rent Payment Period: $period, Amount: $amount, Method: $paymentMethod");
        redirect('payments.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM rental_payments WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Payment record deleted.');
        redirect('payments.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$paymentsList = [];
try {
    $stmt = $pdo->prepare("SELECT r.*, 
                                  CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
                                  u.unit_no, u.rent AS unit_rent,
                                  p.name AS property_name
                           FROM rental_payments r
                           JOIN rental_tenants t ON r.tenant_id = t.id
                           JOIN rental_units u ON r.unit_id = u.id
                           JOIN rental_properties p ON u.property_id = p.id
                           WHERE r.org_id = ?
                           ORDER BY r.payment_date DESC");
    $stmt->execute([$orgId]);
    $paymentsList = $stmt->fetchAll();
} catch (Exception $e) {}

$tenantsList = [];
try {
    $stmt = $pdo->prepare("SELECT t.id, CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, 
                                  u.unit_no, u.rent, p.name AS property_name
                           FROM rental_tenants t
                           JOIN rental_units u ON t.unit_id = u.id
                           JOIN rental_properties p ON u.property_id = p.id
                           WHERE t.org_id = ? AND t.status = 'active'
                           ORDER BY t.first_name ASC");
    $stmt->execute([$orgId]);
    $tenantsList = $stmt->fetchAll();
} catch (Exception $e) {}

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $pid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM rental_payments WHERE id = ? AND org_id = ?");
        $stmt->execute([$pid, $orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            header('Content-Type: application/json');
            echo json_encode($row);
            exit;
        }
    } catch (Exception $e) {}
}

// Receipt generation view
$printId = (int)($_GET['print'] ?? 0);
$receiptData = null;
if ($printId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT r.*, 
                                      CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone, t.id_number,
                                      u.unit_no, u.rent AS unit_rent,
                                      p.name AS property_name, p.address AS property_address
                               FROM rental_payments r
                               JOIN rental_tenants t ON r.tenant_id = t.id
                               JOIN rental_units u ON r.unit_id = u.id
                               JOIN rental_properties p ON u.property_id = p.id
                               WHERE r.id = ? AND r.org_id = ?");
        $stmt->execute([$printId, $orgId]);
        $receiptData = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
?>

<?php if ($receiptData): ?>
<!-- RECEIPT PRINT CARD -->
<div class="row justify-content-center">
  <div class="col-md-6">
    <div class="card shadow-lg border-0" id="receiptPrintArea">
      <div class="card-body p-4 text-dark">
        <div class="text-center mb-4">
          <h4 class="fw-bold mb-1 text-uppercase text-primary"><i class="fas fa-building me-2"></i><?= e($receiptData['property_name']) ?></h4>
          <p class="text-muted small mb-0"><i class="fas fa-map-marker-alt me-1 text-danger"></i><?= e($receiptData['property_address']) ?></p>
          <hr class="my-3">
          <h5 class="fw-bold text-dark mb-0">OFFICIAL RENT RECEIPT</h5>
          <small class="text-muted">Receipt Ref: <strong><?= e($receiptData['reference'] ?: '#' . $receiptData['id']) ?></strong></small>
        </div>

        <table class="table table-borderless small mb-4">
          <tr>
            <td class="text-muted py-1" style="width:35%;">Tenant Name:</td>
            <td class="fw-bold text-dark py-1"><?= e($receiptData['tenant_name']) ?></td>
          </tr>
          <tr>
            <td class="text-muted py-1">ID / Passport:</td>
            <td class="fw-semibold py-1"><?= e($receiptData['id_number']) ?></td>
          </tr>
          <tr>
            <td class="text-muted py-1">Assigned Unit:</td>
            <td class="fw-bold text-dark py-1"><span class="badge bg-light text-dark border"><?= e($receiptData['unit_no']) ?></span></td>
          </tr>
          <tr>
            <td class="text-muted py-1">Rent Period:</td>
            <td class="fw-semibold text-dark py-1"><?= e($receiptData['period']) ?></td>
          </tr>
          <tr>
            <td class="text-muted py-1">Payment Method:</td>
            <td class="py-1"><span class="badge bg-secondary"><?= ucfirst($receiptData['payment_method']) ?></span></td>
          </tr>
          <tr>
            <td class="text-muted py-1">Transaction Date:</td>
            <td class="py-1"><?= formatDate($receiptData['payment_date']) ?></td>
          </tr>
        </table>

        <div class="bg-light p-3 rounded mb-4 text-center">
          <div class="text-muted small mb-1">TOTAL AMOUNT PAID</div>
          <h2 class="fw-bold text-dark mb-0"><?= formatCurrency($receiptData['amount']) ?></h2>
          <small class="text-muted fw-bold">Status: <span class="text-success text-uppercase"><?= $receiptData['status'] ?></span></small>
        </div>

        <div class="row small text-muted text-center pt-3 border-top">
          <div class="col-6">
            <div class="border-bottom mx-auto" style="width:80%;height:30px;"></div>
            <div class="mt-1">Prepared By</div>
          </div>
          <div class="col-6">
            <div class="border-bottom mx-auto" style="width:80%;height:30px;"></div>
            <div class="mt-1">Tenant Signature</div>
          </div>
        </div>

        <div class="text-center mt-4 pt-2 small text-muted">
          Thank you for choosing <?= e($receiptData['property_name']) ?>!<br>
          Generated on <?= date('Y-m-d H:i') ?>
        </div>
      </div>
    </div>
    <div class="text-center mt-3 mb-5">
      <button class="btn btn-primary" onclick="printReceipt()"><i class="fas fa-print me-2"></i>Print Official Receipt</button>
      <a href="payments.php" class="btn btn-outline-secondary ms-2"><i class="fas fa-arrow-left me-2"></i>Back to Collections</a>
    </div>
  </div>
</div>
<script>
function printReceipt() {
  var printContents = document.getElementById('receiptPrintArea').innerHTML;
  var originalContents = document.body.innerHTML;
  document.body.innerHTML = printContents;
  window.print();
  document.body.innerHTML = originalContents;
  window.location.reload();
}
</script>
<?php else: ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-bill me-2" style="color:<?= $moduleColor ?>"></i>Rent Payments Ledger</h4>
    <p class="text-muted mb-0">Log collections, review rent periods, print tenant statements, and track arrears statuses</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#payModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Record Rent Collection</button>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-money-bill me-2 text-primary"></i>Collections History</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Receipt / Ref</th>
            <th>Tenant Name</th>
            <th>Assigned Unit</th>
            <th>Rent Period</th>
            <th>Date Paid</th>
            <th>Payment Method</th>
            <th class="text-end">Amount Paid</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($paymentsList)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-money-bill fa-2x mb-2 d-block"></i>No payments logged yet.</td></tr>
          <?php else: foreach ($paymentsList as $p): ?>
          <tr>
            <td class="fw-bold text-dark"><?= e($p['reference'] ?: '#' . $p['id']) ?></td>
            <td>
              <div class="fw-bold text-dark"><?= e($p['tenant_name']) ?></div>
              <small class="text-muted"><i class="fas fa-phone me-1 small"></i><?= e($p['tenant_phone']) ?></small>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= e($p['unit_no']) ?></div>
              <small class="text-muted"><?= e($p['property_name']) ?></small>
            </td>
            <td class="fw-semibold text-dark"><?= e($p['period']) ?></td>
            <td><?= formatDate($p['payment_date']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst($p['payment_method']) ?></span></td>
            <td class="text-end fw-bold text-dark fs-6"><?= formatCurrency($p['amount']) ?></td>
            <td><span class="badge bg-<?= $p['status'] === 'paid' ? 'success' : 'warning text-dark' ?>"><?= strtoupper($p['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <a href="payments.php?print=<?= $p['id'] ?>" class="btn btn-outline-info" title="Receipt"><i class="fas fa-print"></i></a>
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $p['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delPay(<?= $p['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="payModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="payId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="payTitle"><i class="fas fa-money-bill-wave me-2"></i>Record Rent Payment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Select Tenant <span class="text-danger">*</span></label>
        <select name="tenant_id" id="payTenantId" class="form-select select2-enable" required style="width:100%;" onchange="mapTenantRent(this.value)">
          <option value="">-- select tenant --</option>
          <?php foreach ($tenantsList as $t): ?>
          <option value="<?= $t['id'] ?>"><?= e($t['tenant_name']) ?> (<?= e($t['unit_no']) ?> - <?= e($t['property_name']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Billing Period <span class="text-danger">*</span></label>
        <input type="text" name="period" id="payPeriod" class="form-control" required placeholder="e.g. May 2026, Year 2026">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Payment Amount <span class="text-danger">*</span></label>
        <input type="number" name="amount" id="payAmount" class="form-control" required min="0" placeholder="e.g. 25000">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Transaction Date <span class="text-danger">*</span></label>
        <input type="date" name="payment_date" id="payDate" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
        <select name="payment_method" id="payMethod" class="form-select" required>
          <option value="mpesa">M-Pesa</option>
          <option value="cash">Cash</option>
          <option value="bank">Bank Transfer</option>
          <option value="cheque">Cheque</option>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Transaction Reference / Receipt Number <span class="text-danger">*</span></label>
        <input type="text" name="reference" id="payRef" class="form-control" required placeholder="e.g. RQL7X9T1B4, Cash-092">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Payment Status</label>
        <select name="status" id="payStatus" class="form-select">
          <option value="paid">PAID</option>
          <option value="pending">PENDING</option>
          <option value="partial">PARTIAL</option>
        </select>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Record Collection</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delPayForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delPayId">
</form>

<script>
// Parse PHP array of tenants to automatically autofill monthly rent specifications
const tenantsData = <?= json_encode($tenantsList) ?>;
function mapTenantRent(tid) {
  if (!tid) return;
  const match = tenantsData.find(t => t.id == tid);
  if (match) {
    document.getElementById('payAmount').value = parseFloat(match.rent);
  }
}
</script>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('payTitle').innerHTML = '<i class="fas fa-money-bill-wave me-2"></i>Record Rent Payment';
  document.getElementById('payId').value = '0';
  document.getElementById('payTenantId').value = '';
  document.getElementById('payPeriod').value = new Date().toLocaleString('default', { month: 'long', year: 'numeric' });
  document.getElementById('payAmount').value = '';
  document.getElementById('payDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('payMethod').value = 'mpesa';
  document.getElementById('payRef').value = '';
  document.getElementById('payStatus').value = 'paid';
}
function openEdit(id) {
  fetch('payments.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('payTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Rent Payment Record';
      document.getElementById('payId').value = data.id;
      document.getElementById('payTenantId').value = data.tenant_id;
      document.getElementById('payPeriod').value = data.period;
      document.getElementById('payAmount').value = data.amount;
      document.getElementById('payDate').value = data.payment_date;
      document.getElementById('payMethod').value = data.payment_method;
      document.getElementById('payRef').value = data.reference;
      document.getElementById('payStatus').value = data.status;
      
      new bootstrap.Modal(document.getElementById('payModal')).show();
    });
}
function delPay(id) {
  Swal.fire({
    title: 'Delete Payment Entry?',
    text: 'Remove this payment receipt from ledger history? This does not alter ledger logs.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delPayId').value = id;
      document.getElementById('delPayForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
endif;
?>
