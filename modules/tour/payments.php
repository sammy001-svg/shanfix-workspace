<?php
// ── TOUR: Booking Payments & Revenue Tracking ───────────────────
$moduleSlug  = 'tour';
$moduleName  = 'Tour & Travel';
$moduleIcon  = 'fas fa-plane';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'packages.php',     'icon' => 'fas fa-box-open',       'label' => 'Packages'],
    ['url' => 'destinations.php', 'icon' => 'fas fa-map-marker-alt', 'label' => 'Destinations'],
    ['url' => 'bookings.php',     'icon' => 'fas fa-calendar-check', 'label' => 'Bookings'],
    ['url' => 'customers.php',    'icon' => 'fas fa-user-friends',   'label' => 'Customers'],
    ['url' => 'guides.php',       'icon' => 'fas fa-hiking',         'label' => 'Guides'],
    ['url' => 'itineraries.php', 'icon' => 'fas fa-route',           'label' => 'Itineraries'],
    ['url' => 'vehicles.php',    'icon' => 'fas fa-bus',             'label' => 'Vehicles'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user = currentUser(); $orgId = (int)$user['org_id']; $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $amount    = (float)($_POST['amount'] ?? 0);
        $method    = in_array($_POST['payment_method'] ?? '', ['cash','mpesa','bank','card','cheque','online']) ? $_POST['payment_method'] : 'cash';
        $ref       = sanitize($_POST['reference'] ?? '');
        $type      = in_array($_POST['payment_type'] ?? '', ['deposit','balance','full','refund','extra']) ? $_POST['payment_type'] : 'deposit';
        $payDate   = $_POST['payment_date'] ?? date('Y-m-d');
        $notes     = sanitize($_POST['notes'] ?? '');
        $status    = in_array($_POST['status'] ?? '', ['pending','confirmed','failed','refunded']) ? $_POST['status'] : 'confirmed';
        if (!$bookingId || $amount <= 0) { setFlash('error', 'Booking and amount are required.'); redirect('payments.php'); }
        if ($id > 0) {
            $pdo->prepare("UPDATE tour_payments SET booking_id=?,amount=?,payment_method=?,reference=?,payment_type=?,payment_date=?,notes=?,status=? WHERE id=? AND org_id=?")
                ->execute([$bookingId,$amount,$method,$ref,$type,$payDate,$notes,$status,$id,$orgId]);
            setFlash('success', 'Payment updated.');
        } else {
            if (!$ref) {
                $seq = (int)$pdo->query("SELECT COUNT(*)+1 FROM tour_payments WHERE org_id=$orgId")->fetchColumn();
                $ref = 'TRV-'.date('Y').'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            }
            $pdo->prepare("INSERT INTO tour_payments(org_id,booking_id,amount,payment_method,reference,payment_type,payment_date,notes,status)VALUES(?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$bookingId,$amount,$method,$ref,$type,$payDate,$notes,$status]);
            setFlash('success', 'Payment recorded.');
        }
        logActivity($id > 0 ? 'update' : 'create', 'tour', "Payment: $amount for booking#$bookingId");
        redirect('payments.php');
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM tour_payments WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Payment deleted.'); redirect('payments.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$fBooking = (int)($_GET['booking_id'] ?? 0);
$fMethod  = $_GET['method'] ?? '';
$fMonth   = $_GET['month'] ?? '';

$where = 'p.org_id=?'; $params = [$orgId];
if ($fBooking) { $where .= ' AND p.booking_id=?'; $params[] = $fBooking; }
if ($fMethod)  { $where .= ' AND p.payment_method=?'; $params[] = $fMethod; }
if ($fMonth)   { $where .= " AND DATE_FORMAT(p.payment_date,'%Y-%m')=?"; $params[] = $fMonth; }

$payments = [];
try {
    $s = $pdo->prepare("
        SELECT p.*,b.booking_reference,
               CONCAT(c.first_name,' ',c.last_name) AS customer_name,
               pkg.name AS package_name
        FROM tour_payments p
        LEFT JOIN tour_bookings b ON p.booking_id=b.id
        LEFT JOIN tour_customers c ON b.customer_id=c.id
        LEFT JOIN tour_packages pkg ON b.package_id=pkg.id
        WHERE $where ORDER BY p.payment_date DESC, p.id DESC
    ");
    $s->execute($params); $payments = $s->fetchAll();
} catch (Exception $e) {}

$bookings = [];
try {
    $s = $pdo->prepare("SELECT b.id,CONCAT(b.booking_reference,' — ',c.first_name,' ',c.last_name) AS label FROM tour_bookings b LEFT JOIN tour_customers c ON b.customer_id=c.id WHERE b.org_id=? ORDER BY b.id DESC LIMIT 100");
    $s->execute([$orgId]); $bookings = $s->fetchAll();
} catch (Exception $e) {}

$totalRevenue = 0; $monthRevenue = 0; $pendingAmount = 0; $txnCount = 0;
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM tour_payments WHERE org_id=? AND status='confirmed' AND payment_type!='refund'"); $s->execute([$orgId]); $totalRevenue=(float)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM tour_payments WHERE org_id=? AND status='confirmed' AND payment_type!='refund' AND DATE_FORMAT(payment_date,'%Y-%m')=?"); $s->execute([$orgId,date('Y-m')]); $monthRevenue=(float)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM tour_payments WHERE org_id=? AND status='pending'"); $s->execute([$orgId]); $pendingAmount=(float)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM tour_payments WHERE org_id=?"); $s->execute([$orgId]); $txnCount=(int)$s->fetchColumn();
} catch (Exception $e) {}

$methodColors = ['cash'=>'success','mpesa'=>'primary','bank'=>'info','card'=>'warning','cheque'=>'secondary','online'=>'dark'];
$typeLabels   = ['deposit'=>'Deposit','balance'=>'Balance','full'=>'Full Payment','refund'=>'Refund','extra'=>'Extra Charge'];
$statusColors = ['pending'=>'warning','confirmed'=>'success','failed'=>'danger','refunded'=>'info'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-bill-wave me-2" style="color:<?=$moduleColor?>"></i>Payments</h4>
    <p class="text-muted mb-0">Track booking payments, deposits and revenue</p>
  </div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#payModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Record Payment
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-coins"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($totalRevenue)?></div><div class="stat-label">Total Revenue</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-calendar-alt"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($monthRevenue)?></div><div class="stat-label">This Month</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($pendingAmount)?></div><div class="stat-label">Pending</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#e3f2fd;color:<?=$moduleColor?>"><i class="fas fa-receipt"></i></div><div class="stat-body"><div class="stat-value"><?=$txnCount?></div><div class="stat-label">Transactions</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Booking</label>
      <select name="booking_id" class="form-select form-select-sm">
        <option value="">All Bookings</option>
        <?php foreach ($bookings as $b): ?><option value="<?=$b['id']?>" <?=$fBooking==$b['id']?'selected':''?>><?=e($b['label'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Method</label>
      <select name="method" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (array_keys($methodColors) as $m): ?><option value="<?=$m?>" <?=$fMethod===$m?'selected':''?>><?=ucfirst($m)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Month</label>
      <input type="month" name="month" class="form-control form-control-sm" value="<?=e($fMonth)?>"></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="payments.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?=$moduleColor?>"></i>Payment Records</h6>
    <span class="badge bg-secondary"><?=count($payments)?> records</span>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Reference</th><th>Booking</th><th>Customer</th><th>Package</th><th>Type</th><th>Method</th><th>Amount</th><th>Date</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($payments)): ?>
      <tr><td colspan="10" class="text-center text-muted py-5"><i class="fas fa-money-bill-wave fa-2x mb-2 d-block"></i>No payments recorded.</td></tr>
    <?php else: foreach ($payments as $p): ?>
      <tr>
        <td><span class="badge bg-secondary font-monospace"><?=e($p['reference']??'')?></span></td>
        <td class="small"><?=e($p['booking_reference']??'—')?></td>
        <td class="fw-semibold small"><?=e($p['customer_name']??'—')?></td>
        <td class="small"><?=e($p['package_name']??'—')?></td>
        <td><span class="badge bg-info text-dark"><?=$typeLabels[$p['payment_type']]??e($p['payment_type'])?></span></td>
        <td><span class="badge bg-<?=$methodColors[$p['payment_method']]??'secondary'?>"><?=strtoupper($p['payment_method']??'')?></span></td>
        <td class="fw-bold text-success"><?=formatCurrency($p['amount'])?></td>
        <td class="small"><?=formatDate($p['payment_date'])?></td>
        <td><span class="badge bg-<?=$statusColors[$p['status']]??'secondary'?>"><?=ucfirst($p['status'])?></span></td>
        <td class="text-center" style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger ms-1" onclick="delPay(<?=$p['id']?>)" title="Delete"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="payModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="pId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="pTitle"><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12"><label class="form-label fw-semibold">Booking <span class="text-danger">*</span></label>
      <select name="booking_id" id="pBooking" class="form-select" required>
        <option value="">— Select Booking —</option>
        <?php foreach ($bookings as $b): ?><option value="<?=$b['id']?>" <?=$fBooking==$b['id']?'selected':''?>><?=e($b['label'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Payment Type</label>
      <select name="payment_type" id="pType" class="form-select">
        <?php foreach ($typeLabels as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
      <input type="number" name="amount" id="pAmount" class="form-control" step="0.01" min="0.01" required></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Payment Method</label>
      <select name="payment_method" id="pMethod" class="form-select">
        <?php foreach (array_keys($methodColors) as $m): ?><option value="<?=$m?>"><?=ucfirst($m)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Reference <small class="text-muted">(auto if blank)</small></label>
      <input type="text" name="reference" id="pRef" class="form-control" maxlength="100"></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Date</label>
      <input type="date" name="payment_date" id="pDate" class="form-control"></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="pStatus" class="form-select">
        <?php foreach (array_keys($statusColors) as $s): ?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label>
      <textarea name="notes" id="pNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Payment</button>
  </div></form>
</div></div></div>
<form method="POST" id="delPayForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delPayId"></form>

<?php $extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('pTitle').innerHTML='<i class="fas fa-money-bill-wave me-2"></i>Record Payment';
  document.getElementById('pId').value='0';
  document.getElementById('pBooking').value='';
  document.getElementById('pType').value='deposit';
  document.getElementById('pAmount').value='';
  document.getElementById('pMethod').value='cash';
  document.getElementById('pRef').value='';
  document.getElementById('pDate').value=new Date().toISOString().substring(0,10);
  document.getElementById('pStatus').value='confirmed';
  document.getElementById('pNotes').value='';
}
function openEdit(p){
  document.getElementById('pTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Payment';
  document.getElementById('pId').value=p.id;
  document.getElementById('pBooking').value=p.booking_id||'';
  document.getElementById('pType').value=p.payment_type||'deposit';
  document.getElementById('pAmount').value=p.amount||'';
  document.getElementById('pMethod').value=p.payment_method||'cash';
  document.getElementById('pRef').value=p.reference||'';
  document.getElementById('pDate').value=p.payment_date?p.payment_date.substring(0,10):'';
  document.getElementById('pStatus').value=p.status||'confirmed';
  document.getElementById('pNotes').value=p.notes||'';
  new bootstrap.Modal(document.getElementById('payModal')).show();
}
function delPay(id){
  Swal.fire({title:'Delete Payment?',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delPayId').value=id;document.getElementById('delPayForm').submit();}});
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
