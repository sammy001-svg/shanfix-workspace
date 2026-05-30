<?php
$moduleSlug  = 'courier';
$moduleName  = 'Courier Management';
$moduleIcon  = 'fas fa-shipping-fast';
$moduleColor = '#1565c0';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'couriers.php',   'icon' => 'fas fa-box',             'label' => 'Couriers'],
    ['url' => 'tracking.php',   'icon' => 'fas fa-map-marker-alt',  'label' => 'Tracking'],
    ['url' => 'manifest.php',   'icon' => 'fas fa-clipboard-list',  'label' => 'Manifests'],
    ['url' => 'delivery.php',   'icon' => 'fas fa-truck',           'label' => 'Deliveries'],
    ['url' => 'routes.php',     'icon' => 'fas fa-route',           'label' => 'Routes'],
    ['url' => 'payments.php',   'icon' => 'fas fa-credit-card',     'label' => 'Payments'],
    ['url' => 'agents.php',     'icon' => 'fas fa-user-tie',        'label' => 'Agents'],
    ['url' => 'agreements.php', 'icon' => 'fas fa-file-contract',   'label' => 'Agreements'],
    ['url' => 'setup.php',      'icon' => 'fas fa-cog',             'label' => 'Setup'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
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
        $id          = (int)($_POST['id'] ?? 0);
        $courierId   = (int)$_POST['courier_id'];
        $amount      = (float)$_POST['amount'];
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $method      = in_array($_POST['method'] ?? '', ['cash','mobile_money','bank_transfer','card','cheque','other']) ? $_POST['method'] : 'cash';
        $reference   = sanitize($_POST['reference'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['pending','cleared','failed','refunded']) ? $_POST['status'] : 'pending';

        // Handle receipt file upload
        $receiptFile = '';
        if (!empty($_FILES['receipt_file']['name'])) {
            $uploadDir = __DIR__ . '/../../uploads/courier/receipts/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','pdf','webp'])) {
                $fname = 'rcpt_' . time() . '_' . mt_rand(100,999) . '.' . $ext;
                if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $uploadDir . $fname)) {
                    $receiptFile = $fname;
                }
            }
        }

        if ($id > 0) {
            $sql = "UPDATE courier_payments SET courier_id=?, amount=?, payment_date=?, method=?, reference=?, description=?, status=?";
            $params = [$courierId, $amount, $paymentDate, $method, $reference, $description, $status];
            if ($receiptFile !== '') { $sql .= ', receipt_file=?'; $params[] = $receiptFile; }
            $sql .= ' WHERE id=? AND org_id=?';
            $params[] = $id; $params[] = $orgId;
            $pdo->prepare($sql)->execute($params);
            setFlash('success', 'Payment record updated.');
        } else {
            $pdo->prepare("INSERT INTO courier_payments (org_id, courier_id, amount, payment_date, method, reference, description, receipt_file, status, recorded_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([
                $orgId, $courierId, $amount, $paymentDate, $method, $reference, $description, $receiptFile, $status, $user['id']
            ]);
            setFlash('success', 'Payment recorded successfully.');
        }
        logActivity($id > 0 ? 'update' : 'create', 'courier', "Payment: $amount via $method for courier #$courierId");
        redirect('payments.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM courier_payments WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Payment record deleted.');
        redirect('payments.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Summary stats
$totalCleared  = 0;
$totalPending  = 0;
$totalRefunded = 0;
try {
    $stmt = $pdo->prepare("SELECT status, COALESCE(SUM(amount),0) AS tot FROM courier_payments WHERE org_id=? GROUP BY status");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $row) {
        if ($row['status'] === 'cleared')  $totalCleared  = (float)$row['tot'];
        if ($row['status'] === 'pending')  $totalPending  = (float)$row['tot'];
        if ($row['status'] === 'refunded') $totalRefunded = (float)$row['tot'];
    }
} catch (Exception $e) {}

// Couriers dropdown
$couriersList = [];
try {
    $st = $pdo->prepare("SELECT id, tracking_id, sender_name, receiver_name, price FROM couriers WHERE org_id=? AND approval_status='approved' ORDER BY created_at DESC");
    $st->execute([$orgId]);
    $couriersList = $st->fetchAll();
} catch (Exception $e) {}

// Filters
$fStatus = $_GET['status'] ?? '';
$fMethod = $_GET['method'] ?? '';
$fFrom   = $_GET['from'] ?? date('Y-m-01');
$fTo     = $_GET['to']   ?? date('Y-m-d');

$where  = 'p.org_id = ? AND p.payment_date BETWEEN ? AND ?';
$params = [$orgId, $fFrom, $fTo];
if ($fStatus !== '') { $where .= ' AND p.status = ?'; $params[] = $fStatus; }
if ($fMethod !== '') { $where .= ' AND p.method = ?'; $params[] = $fMethod; }

$paymentsList = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, c.tracking_id, c.sender_name, c.receiver_name, u.name AS recorded_by_name
        FROM courier_payments p
        LEFT JOIN couriers c ON p.courier_id = c.id
        LEFT JOIN users u ON p.recorded_by = u.id
        WHERE $where ORDER BY p.payment_date DESC, p.id DESC");
    $stmt->execute($params);
    $paymentsList = $stmt->fetchAll();
} catch (Exception $e) {}

// AJAX fetch for edit
if (isset($_GET['fetch_payment'])) {
    $pid  = (int)$_GET['fetch_payment'];
    $stmt = $pdo->prepare("SELECT * FROM courier_payments WHERE id=? AND org_id=?");
    $stmt->execute([$pid, $orgId]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { header('Content-Type: application/json'); echo json_encode($row); exit; }
}

$methods = ['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'bank_transfer' => 'Bank Transfer', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'];
$statusColors = ['pending' => 'warning', 'cleared' => 'success', 'failed' => 'danger', 'refunded' => 'info'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-credit-card me-2" style="color:<?= $moduleColor ?>"></i>Payment Processing</h4>
    <p class="text-muted mb-0">Record and manage courier shipping payments, receipts, and financial transactions</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#paymentModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Record Payment</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalCleared) ?></div><div class="stat-label">Total Cleared</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPending) ?></div><div class="stat-label">Pending Payments</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#e3f2fd;color:#1565c0"><i class="fas fa-undo"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRefunded) ?></div><div class="stat-label">Total Refunded</div></div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fFrom) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($fTo) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['pending','cleared','failed','refunded'] as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Method</label>
        <select name="method" class="form-select form-select-sm">
          <option value="">All Methods</option>
          <?php foreach ($methods as $k => $v): ?>
          <option value="<?= $k ?>" <?= $fMethod === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="payments.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-receipt me-2 text-primary"></i>Payment Records (<?= count($paymentsList) ?>)</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Tracking ID</th>
            <th>Sender → Receiver</th>
            <th>Amount</th>
            <th>Method</th>
            <th>Reference</th>
            <th>Recorded By</th>
            <th>Status</th>
            <th>Receipt</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($paymentsList)): ?>
          <tr><td colspan="10" class="text-center text-muted py-5"><i class="fas fa-receipt fa-2x mb-2 d-block"></i>No payment records found.</td></tr>
          <?php else: foreach ($paymentsList as $p):
            $sc = $statusColors[$p['status']] ?? 'secondary';
          ?>
          <tr>
            <td><?= formatDate($p['payment_date']) ?></td>
            <td><span class="badge bg-dark font-monospace"><?= e($p['tracking_id'] ?? '—') ?></span></td>
            <td class="small"><?= e($p['sender_name'] ?? '—') ?> → <?= e($p['receiver_name'] ?? '—') ?></td>
            <td class="fw-bold text-dark"><?= formatCurrency((float)$p['amount']) ?></td>
            <td><?= $methods[$p['method']] ?? ucfirst($p['method']) ?></td>
            <td><span class="badge bg-secondary"><?= e($p['reference'] ?: '—') ?></span></td>
            <td class="small"><?= e($p['recorded_by_name'] ?? 'System') ?></td>
            <td><span class="badge bg-<?= $sc ?>"><?= strtoupper($p['status']) ?></span></td>
            <td>
              <?php if ($p['receipt_file']): ?>
              <a href="../../uploads/courier/receipts/<?= e($p['receipt_file']) ?>" target="_blank" class="btn btn-xs btn-outline-info btn-sm py-0 px-1"><i class="fas fa-file-alt"></i></a>
              <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $p['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delPayment(<?= $p['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="paymentId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="paymentModalTitle"><i class="fas fa-credit-card me-2"></i>Record Payment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Courier / Parcel <span class="text-danger">*</span></label>
        <select name="courier_id" id="paymentCourierId" class="form-select" required>
          <option value="">Select courier...</option>
          <?php foreach ($couriersList as $c): ?>
          <option value="<?= $c['id'] ?>" data-price="<?= $c['price'] ?>">
            <?= e($c['tracking_id']) ?> — <?= e($c['sender_name']) ?> → <?= e($c['receiver_name']) ?> (<?= formatCurrency((float)$c['price']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
        <input type="number" name="amount" id="paymentAmount" class="form-control" step="0.01" min="0" required placeholder="0.00">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
        <input type="date" name="payment_date" id="paymentDate" class="form-control" required>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Payment Method</label>
        <select name="method" id="paymentMethod" class="form-select">
          <?php foreach ($methods as $k => $v): ?>
          <option value="<?= $k ?>"><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Reference / Receipt No.</label>
        <input type="text" name="reference" id="paymentRef" class="form-control" placeholder="e.g. TXN-00123">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Status</label>
        <select name="status" id="paymentStatus" class="form-select">
          <option value="pending">Pending</option>
          <option value="cleared">Cleared</option>
          <option value="failed">Failed</option>
          <option value="refunded">Refunded</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Receipt File (Image / PDF)</label>
        <input type="file" name="receipt_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.webp">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Description</label>
        <textarea name="description" id="paymentDesc" class="form-control" rows="2" placeholder="Payment notes..."></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Payment</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delPaymentForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delPaymentId">
</form>

<?php
$extraJs = <<<'JS'
<script>
document.getElementById('paymentCourierId').addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  const price = opt.getAttribute('data-price');
  if (price) document.getElementById('paymentAmount').value = parseFloat(price).toFixed(2);
});
function openAdd() {
  document.getElementById('paymentModalTitle').innerHTML = '<i class="fas fa-credit-card me-2"></i>Record Payment';
  document.getElementById('paymentId').value = '0';
  document.getElementById('paymentCourierId').value = '';
  document.getElementById('paymentAmount').value = '';
  document.getElementById('paymentDate').value = new Date().toISOString().slice(0,10);
  document.getElementById('paymentMethod').value = 'cash';
  document.getElementById('paymentRef').value = '';
  document.getElementById('paymentStatus').value = 'pending';
  document.getElementById('paymentDesc').value = '';
}
function openEdit(id) {
  fetch('payments.php?fetch_payment=' + id)
    .then(r => r.json())
    .then(d => {
      document.getElementById('paymentModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Payment';
      document.getElementById('paymentId').value = d.id;
      document.getElementById('paymentCourierId').value = d.courier_id;
      document.getElementById('paymentAmount').value = d.amount;
      document.getElementById('paymentDate').value = d.payment_date;
      document.getElementById('paymentMethod').value = d.method;
      document.getElementById('paymentRef').value = d.reference || '';
      document.getElementById('paymentStatus').value = d.status;
      document.getElementById('paymentDesc').value = d.description || '';
      new bootstrap.Modal(document.getElementById('paymentModal')).show();
    });
}
function delPayment(id) {
  Swal.fire({
    title: 'Delete Payment?', text: 'This payment record will be permanently removed.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delPaymentId').value = id;
      document.getElementById('delPaymentForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
