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
    require_once __DIR__ . '/../../includes/header-module.php';
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_invoice') {
        $leaseId = (int)$_POST['lease_id'];
        $from    = $_POST['period_from'] ?? '';
        $to      = $_POST['period_to']   ?? '';
        $lateFee = (float)($_POST['late_fee'] ?? 0);
        $other   = (float)($_POST['other_charges'] ?? 0);
        $notes   = sanitize($_POST['notes'] ?? '');

        $lease = $pdo->query("SELECT * FROM rental_leases WHERE id=$leaseId AND org_id=$orgId")->fetch();
        if (!$lease) { setFlash('danger','Lease not found.'); redirect(APP_URL.'/modules/rental/invoices.php'); }

        $total   = $lease['monthly_rent'] + $lateFee + $other;
        $dueDate = date('Y-m-d', strtotime($to.' +5 days'));
        $invNo   = 'RI-'.date('Y').'-'.str_pad($pdo->query("SELECT COUNT(*)+1 FROM rental_invoices WHERE org_id=$orgId")->fetchColumn(),4,'0',STR_PAD_LEFT);

        $pdo->prepare("INSERT INTO rental_invoices (org_id,invoice_no,lease_id,unit_id,tenant_id,period_from,period_to,due_date,rent_amount,late_fee,other_charges,total_amount,status,notes,created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'unpaid',?,?)")
            ->execute([$orgId,$invNo,$leaseId,$lease['unit_id'],$lease['tenant_id'],$from,$to,$dueDate,$lease['monthly_rent'],$lateFee,$other,$total,$notes,$user['id']]);
        setFlash('success', "Invoice $invNo generated.");
        redirect(APP_URL.'/modules/rental/invoices.php');
    }

    if ($action === 'record_payment') {
        $invId  = (int)$_POST['invoice_id'];
        $amount = (float)$_POST['amount'];
        $inv    = $pdo->query("SELECT * FROM rental_invoices WHERE id=$invId AND org_id=$orgId")->fetch();
        if ($inv) {
            $newPaid = min($inv['total_amount'], $inv['paid_amount'] + $amount);
            $status  = $newPaid >= $inv['total_amount'] ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
            $pdo->prepare("UPDATE rental_invoices SET paid_amount=?,status=? WHERE id=? AND org_id=?")
                ->execute([$newPaid,$status,$invId,$orgId]);
            setFlash('success', 'Payment recorded.');
        }
        redirect(APP_URL.'/modules/rental/invoices.php');
    }

    if ($action === 'bulk_generate') {
        // Generate invoices for all active leases for a given month
        $month = $_POST['bulk_month'] ?? date('Y-m');
        $from  = $month.'-01';
        $to    = date('Y-m-t', strtotime($from));
        $leases = $pdo->query("SELECT * FROM rental_leases WHERE org_id=$orgId AND status='active'")->fetchAll();
        $count = 0;
        foreach ($leases as $l) {
            // Skip if already invoiced for this period
            $dup = $pdo->prepare("SELECT id FROM rental_invoices WHERE org_id=? AND lease_id=? AND period_from=?");
            $dup->execute([$orgId,$l['id'],$from]);
            if ($dup->fetch()) continue;
            $dueDay  = (int)$l['payment_day'];
            $dueDate = date('Y-m-'.str_pad($dueDay,2,'0',STR_PAD_LEFT), strtotime($from));
            $invNo   = 'RI-'.date('Y').'-'.str_pad($pdo->query("SELECT COUNT(*)+1 FROM rental_invoices WHERE org_id=$orgId")->fetchColumn(),4,'0',STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO rental_invoices (org_id,invoice_no,lease_id,unit_id,tenant_id,period_from,period_to,due_date,rent_amount,total_amount,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,'unpaid',?)")
                ->execute([$orgId,$invNo,$l['id'],$l['unit_id'],$l['tenant_id'],$from,$to,$dueDate,$l['monthly_rent'],$l['monthly_rent'],$user['id']]);
            $count++;
        }
        setFlash('success', "$count invoice(s) generated for $month.");
        redirect(APP_URL.'/modules/rental/invoices.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

$statusFilter = $_GET['status'] ?? '';
$where = $statusFilter ? "AND i.status='".preg_replace('/[^a-z_]/','',$statusFilter)."'" : '';

$invoices = [];
try {
    $stmt = $pdo->prepare("SELECT i.*,
                              CONCAT(u.unit_no,' — ',p.name) AS unit_label,
                              CONCAT(t.first_name,' ',t.last_name) AS tenant_name
                           FROM rental_invoices i
                           JOIN rental_units u ON i.unit_id=u.id
                           JOIN rental_properties p ON u.property_id=p.id
                           JOIN rental_tenants t ON i.tenant_id=t.id
                           WHERE i.org_id=? $where
                           ORDER BY i.due_date DESC LIMIT 200");
    $stmt->execute([$orgId]);
    $invoices = $stmt->fetchAll();
} catch (Exception $e) {}

$leases = [];
try {
    $stmt = $pdo->prepare("SELECT l.id, CONCAT(u.unit_no,' — ',p.name,' (',t.first_name,' ',t.last_name,')') AS label
                           FROM rental_leases l JOIN rental_units u ON l.unit_id=u.id JOIN rental_properties p ON u.property_id=p.id JOIN rental_tenants t ON l.tenant_id=t.id
                           WHERE l.org_id=? AND l.status='active' ORDER BY p.name, u.unit_no");
    $stmt->execute([$orgId]);
    $leases = $stmt->fetchAll();
} catch (Exception $e) {}

$totalOutstanding = array_sum(array_map(fn($i)=>$i['total_amount']-$i['paid_amount'], array_filter($invoices,fn($i)=>in_array($i['status'],['unpaid','partial','overdue']))));
$totalCollected   = array_sum(array_column($invoices, 'paid_amount'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Rent Invoices</h4>
    <p class="text-muted mb-0">Generate, track, and collect rent invoices from tenants</p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#bulkModal">
      <i class="fas fa-layer-group me-2"></i>Bulk Generate
    </button>
    <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#invoiceModal">
      <i class="fas fa-plus me-2"></i>New Invoice
    </button>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['val'=>formatCurrency($totalOutstanding), 'label'=>'Outstanding',  'icon'=>'fas fa-exclamation-circle','col'=>'danger'],
    ['val'=>formatCurrency($totalCollected),   'label'=>'Collected',     'icon'=>'fas fa-check-circle',      'col'=>'success'],
    ['val'=>count(array_filter($invoices,fn($i)=>$i['status']==='unpaid')), 'label'=>'Unpaid',  'icon'=>'fas fa-clock','col'=>'warning'],
    ['val'=>count($invoices),                  'label'=>'Total Invoices', 'icon'=>'fas fa-file-invoice',     'col'=>'primary'],
  ] as $s): ?>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon text-<?= $s['col'] ?>"><i class="<?= $s['icon'] ?>"></i></div>
      <div class="stat-body"><div class="stat-value text-<?= $s['col'] ?>"><?= $s['val'] ?></div><div class="stat-label"><?= $s['label'] ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Status filter pills -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php foreach ([''=> 'All', 'unpaid'=>'Unpaid','partial'=>'Partial','paid'=>'Paid','overdue'=>'Overdue'] as $v=>$l): ?>
  <a href="?status=<?= $v ?>" class="btn btn-sm <?= $statusFilter===$v ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="invoicesTable">
        <thead class="table-light">
          <tr><th>Invoice No</th><th>Tenant / Unit</th><th>Period</th><th class="text-end">Rent</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Due Date</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php
          $sc = ['unpaid'=>'warning','partial'=>'info','paid'=>'success','overdue'=>'danger','cancelled'=>'secondary'];
          foreach ($invoices as $inv):
            $balance = $inv['total_amount'] - $inv['paid_amount'];
          ?>
          <tr>
            <td><code class="small"><?= e($inv['invoice_no']) ?></code></td>
            <td><div class="fw-semibold small"><?= e($inv['tenant_name']) ?></div><small class="text-muted"><?= e($inv['unit_label']) ?></small></td>
            <td class="small"><?= formatDate($inv['period_from']) ?> – <?= formatDate($inv['period_to']) ?></td>
            <td class="text-end small"><?= formatCurrency($inv['rent_amount']) ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency($inv['total_amount']) ?></td>
            <td class="text-end text-success"><?= formatCurrency($inv['paid_amount']) ?></td>
            <td class="text-end fw-bold text-<?= $balance>0?'danger':'muted' ?>"><?= formatCurrency($balance) ?></td>
            <td class="small <?= strtotime($inv['due_date'])<time()&&$inv['status']!=='paid'?'text-danger fw-semibold':'' ?>"><?= formatDate($inv['due_date']) ?></td>
            <td><span class="badge bg-<?= $sc[$inv['status']]??'secondary' ?>"><?= ucfirst($inv['status']) ?></span></td>
            <td>
              <?php if ($inv['status']!=='paid'&&$inv['status']!=='cancelled'): ?>
              <button class="btn btn-xs btn-outline-success" onclick="payInvoice(<?= $inv['id'] ?>,'<?= e($inv['invoice_no']) ?>',<?= $balance ?>)">
                <i class="fas fa-dollar-sign"></i>
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($invoices)): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No invoices found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- New Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Generate Invoice</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="generate_invoice">
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Lease <span class="text-danger">*</span></label>
            <select name="lease_id" class="form-select" required>
              <option value="">— Select active lease —</option>
              <?php foreach ($leases as $l): ?>
              <option value="<?= $l['id'] ?>"><?= e($l['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Period From</label>
            <input type="date" name="period_from" class="form-control" value="<?= date('Y-m-01') ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Period To</label>
            <input type="date" name="period_to" class="form-control" value="<?= date('Y-m-t') ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Late Fee</label>
            <input type="number" name="late_fee" class="form-control" step="0.01" min="0" value="0">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Other Charges</label>
            <input type="number" name="other_charges" class="form-control" step="0.01" min="0" value="0">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-file-invoice me-2"></i>Generate</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Bulk Generate Modal -->
<div class="modal fade" id="bulkModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-layer-group me-2"></i>Bulk Generate Invoices</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="bulk_generate">
        <div class="modal-body">
          <p class="text-muted small">This will generate rent invoices for all active leases for the selected month. Leases already invoiced for that month are skipped.</p>
          <label class="form-label fw-semibold">Month <span class="text-danger">*</span></label>
          <input type="month" name="bulk_month" class="form-control" value="<?= date('Y-m') ?>" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-layer-group me-2"></i>Generate All</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="fas fa-dollar-sign me-2"></i>Record Payment — <span id="payInvNo"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="record_payment">
        <input type="hidden" name="invoice_id" id="payInvId">
        <div class="modal-body">
          <label class="form-label fw-semibold">Amount Received <span class="text-danger">*</span></label>
          <input type="number" name="amount" id="payAmount" class="form-control form-control-lg" step="0.01" min="0.01" required>
          <div class="form-text" id="payBalanceHint"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check me-2"></i>Record Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function payInvoice(id, no, balance) {
    document.getElementById('payInvId').value    = id;
    document.getElementById('payInvNo').textContent = no;
    document.getElementById('payAmount').value   = balance.toFixed(2);
    document.getElementById('payAmount').max     = balance;
    document.getElementById('payBalanceHint').textContent = 'Outstanding balance: ' + balance.toLocaleString('en-KE', {style:'currency',currency:'KES'});
    new bootstrap.Modal(document.getElementById('payModal')).show();
}
$('#invoicesTable').DataTable({pageLength:25,order:[[7,'desc']]});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
