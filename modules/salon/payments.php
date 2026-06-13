<?php
// ── Salon: Payment Records ──────────────────────────────────────
$moduleSlug = 'salon';
$moduleName = 'Salon & Spa';
$moduleIcon = 'fas fa-cut';
$moduleColor = '#c0392b';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'appointments.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Appointments'],
    ['url' => 'clients.php',      'icon' => 'fas fa-users',          'label' => 'Clients'],
    ['url' => 'services.php',     'icon' => 'fas fa-concierge-bell', 'label' => 'Services'],
    ['url' => 'staff.php',        'icon' => 'fas fa-user-tie',       'label' => 'Staff'],
    ['url' => 'packages.php',     'icon' => 'fas fa-gift',           'label' => 'Packages'],
    ['url' => 'inventory.php',    'icon' => 'fas fa-boxes',          'label' => 'Inventory'],
    ['url' => 'loyalty.php',      'icon' => 'fas fa-star',           'label' => 'Loyalty'],
    ['url' => 'payments.php',     'icon' => 'fas fa-money-bill-wave','label' => 'Payments'],
    ['url' => 'expenses.php',     'icon' => 'fas fa-receipt',        'label' => 'Expenses'],
    ['url' => 'promotions.php',   'icon' => 'fas fa-tag',            'label' => 'Promotions'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        // Auto-ref: SAL-YYYY-NNNN
        $stmt = $pdo->prepare("SELECT COUNT(*)+1 FROM salon_payments WHERE org_id=? AND YEAR(created_at)=YEAR(NOW())");
        $stmt->execute([$orgId]);
        $ref = 'SAL-' . date('Y') . '-' . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);

        $clientId      = (int)($_POST['client_id'] ?? 0) ?: null;
        $appointmentId = (int)($_POST['appointment_id'] ?? 0) ?: null;
        $amount        = (float)($_POST['amount'] ?? 0);
        $method        = in_array($_POST['method'] ?? '', ['cash','mpesa','card','bank','voucher']) ? $_POST['method'] : 'cash';
        $paymentType   = in_array($_POST['payment_type'] ?? '', ['service','product','package','deposit','refund']) ? $_POST['payment_type'] : 'service';
        $notes         = sanitize($_POST['notes'] ?? '');

        $pdo->prepare("INSERT INTO salon_payments (org_id,ref,client_id,appointment_id,amount,method,payment_type,notes) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$orgId,$ref,$clientId,$appointmentId,$amount,$method,$paymentType,$notes]);
        setFlash('success', "Payment $ref recorded.");
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM salon_payments WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Payment deleted.');
    }
    redirect('payments.php');
}

// Clients & appointments for dropdowns
$clients = $pdo->prepare("SELECT id, name FROM salon_clients WHERE org_id=? ORDER BY name"); $clients->execute([$orgId]); $clients = $clients->fetchAll();
$appts   = $pdo->prepare("SELECT a.id, CONCAT(c.name,' — ',a.appointment_date) as label FROM salon_appointments a JOIN salon_clients c ON a.client_id=c.id WHERE a.org_id=? ORDER BY a.appointment_date DESC LIMIT 100");
$appts->execute([$orgId]); $appts = $appts->fetchAll();

// Filters
$methodFilter = sanitize($_GET['method'] ?? '');
$monthFilter  = sanitize($_GET['month'] ?? '');
$sql = "SELECT p.*, c.name as client_name FROM salon_payments p LEFT JOIN salon_clients c ON p.client_id=c.id WHERE p.org_id=?";
$params = [$orgId];
if ($methodFilter) { $sql .= " AND p.method=?"; $params[] = $methodFilter; }
if ($monthFilter)  { $sql .= " AND DATE_FORMAT(p.created_at,'%Y-%m')=?"; $params[] = $monthFilter; }
$sql .= " ORDER BY p.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$payments = $stmt->fetchAll();

// KPIs
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM salon_payments WHERE org_id=? AND payment_type!='refund'"); $stmt->execute([$orgId]);
$totalRevenue = (float)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM salon_payments WHERE org_id=? AND payment_type='refund'"); $stmt->execute([$orgId]);
$totalRefunds = (float)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM salon_payments WHERE org_id=? AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m') AND payment_type!='refund'"); $stmt->execute([$orgId]);
$thisMonth = (float)$stmt->fetchColumn();

$methodColors = ['cash'=>'success','mpesa'=>'primary','card'=>'info','bank'=>'secondary','voucher'=>'warning'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-bill-wave me-2" style="color:<?= $moduleColor ?>"></i>Payment Records</h4>
    <p class="text-muted mb-0">Track client service payments, product sales, and refunds</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#payModal">
    <i class="fas fa-plus me-1"></i>Record Payment
  </button>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-wallet"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Total Revenue</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(192,57,43,0.12);color:#c0392b"><i class="fas fa-undo"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRefunds) ?></div><div class="stat-label">Total Refunds</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($thisMonth) ?></div><div class="stat-label">This Month</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-3">
        <select name="method" class="form-select form-select-sm">
          <option value="">All Methods</option>
          <?php foreach (['cash','mpesa','card','bank','voucher'] as $m): ?>
          <option value="<?= $m ?>" <?= $methodFilter===$m?'selected':'' ?>><?= ucfirst($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <input type="month" name="month" class="form-control form-control-sm" value="<?= e($monthFilter) ?>">
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if ($methodFilter||$monthFilter): ?><div class="col-auto"><a href="payments.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Ref</th>
            <th>Client</th>
            <th>Type</th>
            <th class="text-center">Method</th>
            <th class="text-end">Amount</th>
            <th>Notes</th>
            <th class="text-center">Date</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No payment records found.</td></tr>
          <?php else: foreach ($payments as $p): ?>
          <tr>
            <td class="ps-3"><code><?= e($p['ref']) ?></code></td>
            <td><?= e($p['client_name'] ?? '—') ?></td>
            <td><span class="badge bg-secondary"><?= ucfirst($p['payment_type']) ?></span></td>
            <td class="text-center"><span class="badge bg-<?= $methodColors[$p['method']] ?? 'secondary' ?>"><?= strtoupper($p['method']) ?></span></td>
            <td class="text-end fw-bold <?= $p['payment_type']==='refund'?'text-danger':'' ?>"><?= formatCurrency($p['amount']) ?></td>
            <td class="text-muted small"><?= e($p['notes']) ?></td>
            <td class="text-center text-muted small"><?= formatDate($p['created_at']) ?></td>
            <td class="text-end pe-3">
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this payment?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Client</label>
              <select name="client_id" class="form-select">
                <option value="">— Walk-in —</option>
                <?php foreach ($clients as $cl): ?>
                <option value="<?= $cl['id'] ?>"><?= e($cl['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Appointment</label>
              <select name="appointment_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($appts as $ap): ?>
                <option value="<?= $ap['id'] ?>"><?= e($ap['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Payment Type</label>
              <select name="payment_type" class="form-select">
                <option value="service">Service</option>
                <option value="product">Product</option>
                <option value="package">Package</option>
                <option value="deposit">Deposit</option>
                <option value="refund">Refund</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Payment Method</label>
              <select name="method" class="form-select">
                <option value="cash">Cash</option>
                <option value="mpesa">M-Pesa</option>
                <option value="card">Card</option>
                <option value="bank">Bank Transfer</option>
                <option value="voucher">Voucher</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Amount (KES) <span class="text-danger">*</span></label>
              <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <div class="border rounded p-3 bg-light">
                <div class="fw-semibold small mb-2"><i class="fas fa-mobile-alt text-success me-1"></i>Send M-Pesa STK Push (optional)</div>
                <div class="input-group input-group-sm">
                  <span class="input-group-text"><i class="fas fa-phone"></i></span>
                  <input type="tel" id="salonStkPhone" class="form-control" placeholder="Client 07XXXXXXXX" maxlength="15">
                  <button type="button" class="btn btn-success" onclick="sendSalonStk()"><i class="fas fa-paper-plane me-1"></i>Send</button>
                </div>
                <div id="salonStkResult" class="mt-2 small"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function sendSalonStk() {
  var phone  = document.getElementById('salonStkPhone').value.trim();
  var amount = parseFloat(document.querySelector('#payModal [name="amount"]')?.value) || 0;
  var result = document.getElementById('salonStkResult');
  if (!phone)  { result.innerHTML = '<span class="text-danger">Enter client phone number.</span>'; return; }
  if (amount < 1) { result.innerHTML = '<span class="text-danger">Enter payment amount first.</span>'; return; }
  result.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm"></span> Sending...</span>';
  var fd = new FormData();
  fd.append('phone', phone); fd.append('amount', amount); fd.append('invoice_id', '0');
  fetch('../../api/mpesa-stk.php', {method: 'POST', body: fd})
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        result.innerHTML = '<span class="text-success"><i class="fas fa-check me-1"></i>' + d.message + '</span>';
        document.querySelector('#payModal [name="method"]').value = 'mpesa';
      } else {
        result.innerHTML = '<span class="text-danger">' + (d.message || 'Failed.') + '</span>';
      }
    })
    .catch(() => { result.innerHTML = '<span class="text-danger">Network error.</span>'; });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
