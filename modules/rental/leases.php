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

if (isset($_GET['fetch'])) {
    require_once __DIR__ . '/../../includes/header-module.php';
    $orgId = (int)$user['org_id'];
    $id    = (int)$_GET['fetch'];
    $stmt  = $pdo->prepare("SELECT l.*,
                               CONCAT(u.unit_no,' — ',p.name) AS unit_label,
                               CONCAT(t.first_name,' ',t.last_name) AS tenant_name
                             FROM rental_leases l
                             JOIN rental_units u ON l.unit_id=u.id
                             JOIN rental_properties p ON u.property_id=p.id
                             JOIN rental_tenants t ON l.tenant_id=t.id
                             WHERE l.id=? AND l.org_id=?");
    $stmt->execute([$id, $orgId]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../includes/header-module.php';
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_lease') {
        $id         = (int)($_POST['lease_id'] ?? 0);
        $unitId     = (int)$_POST['unit_id'];
        $tenantId   = (int)$_POST['tenant_id'];
        $start      = $_POST['start_date'] ?? '';
        $end        = $_POST['end_date'] ?? '';
        $rent       = (float)$_POST['monthly_rent'];
        $deposit    = (float)($_POST['deposit'] ?? 0);
        $payDay     = (int)($_POST['payment_day'] ?? 1);
        $lateFeePct = (float)($_POST['late_fee_pct'] ?? 0);
        $lateDays   = (int)($_POST['late_fee_days'] ?? 5);
        $terms      = sanitize($_POST['terms'] ?? '');

        if (!$unitId || !$tenantId || !$start || !$end || $rent <= 0) {
            setFlash('danger', 'Unit, tenant, dates and rent are required.');
        } else {
            if ($id) {
                $pdo->prepare("UPDATE rental_leases SET unit_id=?,tenant_id=?,start_date=?,end_date=?,monthly_rent=?,deposit=?,payment_day=?,late_fee_pct=?,late_fee_days=?,terms=? WHERE id=? AND org_id=?")
                    ->execute([$unitId,$tenantId,$start,$end,$rent,$deposit,$payDay,$lateFeePct,$lateDays,$terms,$id,$orgId]);
                setFlash('success', 'Lease updated.');
            } else {
                $leaseNo = 'LS-'.date('Y').'-'.str_pad($pdo->query("SELECT COUNT(*)+1 FROM rental_leases WHERE org_id=$orgId")->fetchColumn(), 4,'0',STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO rental_leases (org_id,lease_no,unit_id,tenant_id,start_date,end_date,monthly_rent,deposit,payment_day,late_fee_pct,late_fee_days,terms,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'active',?)")
                    ->execute([$orgId,$leaseNo,$unitId,$tenantId,$start,$end,$rent,$deposit,$payDay,$lateFeePct,$lateDays,$terms,$user['id']]);
                $pdo->prepare("UPDATE rental_units SET status='occupied',tenant_id=? WHERE id=? AND org_id=?")->execute([$tenantId,$unitId,$orgId]);
                setFlash('success', "Lease $leaseNo created.");
            }
        }
        redirect(APP_URL.'/modules/rental/leases.php');
    }

    if ($action === 'terminate') {
        $id     = (int)$_POST['lease_id'];
        $reason = sanitize($_POST['reason'] ?? '');
        $pdo->prepare("UPDATE rental_leases SET status='terminated',terminated_at=CURDATE(),termination_reason=? WHERE id=? AND org_id=?")
            ->execute([$reason,$id,$orgId]);
        // Free the unit
        $lease = $pdo->query("SELECT unit_id FROM rental_leases WHERE id=$id AND org_id=$orgId")->fetch();
        if ($lease) $pdo->prepare("UPDATE rental_units SET status='vacant',tenant_id=NULL WHERE id=? AND org_id=?")->execute([$lease['unit_id'],$orgId]);
        setFlash('success', 'Lease terminated and unit set to vacant.');
        redirect(APP_URL.'/modules/rental/leases.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

$leases = [];
try {
    $stmt = $pdo->prepare("SELECT l.*, l.lease_no,
                              CONCAT(u.unit_no,' — ',p.name) AS unit_label,
                              CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                              t.phone AS tenant_phone,
                              DATEDIFF(l.end_date, CURDATE()) AS days_remaining
                           FROM rental_leases l
                           JOIN rental_units u ON l.unit_id=u.id
                           JOIN rental_properties p ON u.property_id=p.id
                           JOIN rental_tenants t ON l.tenant_id=t.id
                           WHERE l.org_id=? ORDER BY l.status='active' DESC, l.end_date ASC");
    $stmt->execute([$orgId]);
    $leases = $stmt->fetchAll();
} catch (Exception $e) {}

$units = [];
try {
    $stmt = $pdo->prepare("SELECT u.id, CONCAT(u.unit_no,' — ',p.name) AS label FROM rental_units u JOIN rental_properties p ON u.property_id=p.id WHERE u.org_id=? AND u.status IN ('vacant','occupied') ORDER BY p.name,u.unit_no");
    $stmt->execute([$orgId]);
    $units = $stmt->fetchAll();
} catch (Exception $e) {}

$tenants = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, phone FROM rental_tenants WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]);
    $tenants = $stmt->fetchAll();
} catch (Exception $e) {}

$active   = count(array_filter($leases, fn($l)=>$l['status']==='active'));
$expiring = count(array_filter($leases, fn($l)=>$l['status']==='active' && $l['days_remaining']>=0 && $l['days_remaining']<=30));
$expired  = count(array_filter($leases, fn($l)=>$l['status']==='expired'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-contract me-2" style="color:<?= $moduleColor ?>"></i>Lease Agreements</h4>
    <p class="text-muted mb-0">Manage tenancy contracts, renewal dates, and terminations</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#leaseModal">
    <i class="fas fa-plus me-2"></i>New Lease
  </button>
</div>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['val'=>$active,   'label'=>'Active Leases',     'icon'=>'fas fa-check-circle', 'col'=>'success'],
    ['val'=>$expiring, 'label'=>'Expiring in 30 days','icon'=>'fas fa-exclamation-triangle','col'=>'warning'],
    ['val'=>$expired,  'label'=>'Expired',            'icon'=>'fas fa-times-circle', 'col'=>'secondary'],
    ['val'=>count($leases),'label'=>'Total Leases',   'icon'=>'fas fa-file-contract','col'=>'primary'],
  ] as $s): ?>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon text-<?= $s['col'] ?>" style="background:var(--bs-<?= $s['col'] ?>-bg,#f8f9fa)"><i class="<?= $s['icon'] ?>"></i></div>
      <div class="stat-body"><div class="stat-value text-<?= $s['col'] ?>"><?= $s['val'] ?></div><div class="stat-label"><?= $s['label'] ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="leasesTable">
        <thead class="table-light">
          <tr><th>Lease No</th><th>Unit</th><th>Tenant</th><th class="text-end">Monthly Rent</th><th>Period</th><th>Days Left</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($leases as $l):
            $dr = (int)$l['days_remaining'];
            $drBadge = $l['status']!=='active' ? '' : ($dr<0?'<span class="badge bg-danger">Overdue</span>':($dr<=30?'<span class="badge bg-warning">'.$dr.' days</span>':'<span class="badge bg-success">'.$dr.' days</span>'));
            $sc = ['active'=>'success','expired'=>'secondary','terminated'=>'danger','pending'=>'info'][$l['status']]??'secondary';
          ?>
          <tr>
            <td><code><?= e($l['lease_no']) ?></code></td>
            <td class="small"><?= e($l['unit_label']) ?></td>
            <td><div class="fw-semibold small"><?= e($l['tenant_name']) ?></div><small class="text-muted"><?= e($l['tenant_phone']) ?></small></td>
            <td class="text-end fw-semibold"><?= formatCurrency($l['monthly_rent']) ?></td>
            <td class="small"><?= formatDate($l['start_date']) ?> – <?= formatDate($l['end_date']) ?></td>
            <td><?= $drBadge ?></td>
            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($l['status']) ?></span></td>
            <td>
              <button class="btn btn-xs btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($l),ENT_QUOTES) ?>)'>
                <i class="fas fa-edit"></i>
              </button>
              <?php if ($l['status']==='active'): ?>
              <button class="btn btn-xs btn-outline-danger ms-1" onclick="terminate(<?= $l['id'] ?>,'<?= e($l['tenant_name']) ?>')">
                <i class="fas fa-times"></i>
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($leases)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No lease agreements yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Lease Modal -->
<div class="modal fade" id="leaseModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="leaseModalTitle"><i class="fas fa-file-contract me-2"></i>New Lease Agreement</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="save_lease">
        <input type="hidden" name="lease_id" id="leaseId" value="0">
        <div class="modal-body row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Unit <span class="text-danger">*</span></label>
            <select name="unit_id" id="leaseUnit" class="form-select" required>
              <option value="">— Select unit —</option>
              <?php foreach ($units as $u): ?>
              <option value="<?= $u['id'] ?>"><?= e($u['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Tenant <span class="text-danger">*</span></label>
            <select name="tenant_id" id="leaseTenant" class="form-select" required>
              <option value="">— Select tenant —</option>
              <?php foreach ($tenants as $t): ?>
              <option value="<?= $t['id'] ?>"><?= e($t['name']) ?> — <?= e($t['phone']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
            <input type="date" name="start_date" id="leaseStart" class="form-control" required value="<?= date('Y-m-01') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label>
            <input type="date" name="end_date" id="leaseEnd" class="form-control" required value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Monthly Rent <span class="text-danger">*</span></label>
            <input type="number" name="monthly_rent" id="leaseRent" class="form-control" step="0.01" min="1" required placeholder="e.g. 15000">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Security Deposit</label>
            <input type="number" name="deposit" id="leaseDeposit" class="form-control" step="0.01" min="0" value="0">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Payment Due Day</label>
            <input type="number" name="payment_day" id="leasePayDay" class="form-control" min="1" max="28" value="1">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Late Fee (%)</label>
            <input type="number" name="late_fee_pct" id="leaseLateFeePct" class="form-control" step="0.01" min="0" value="0" placeholder="e.g. 5">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Grace Period (days)</label>
            <input type="number" name="late_fee_days" id="leaseLateDays" class="form-control" min="0" value="5">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Terms & Conditions</label>
            <textarea name="terms" id="leaseTerms" class="form-control" rows="3" placeholder="Tenancy terms, clauses, etc."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-2"></i>Save Lease</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Terminate Modal -->
<div class="modal fade" id="terminateModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Terminate Lease</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="terminate">
        <input type="hidden" name="lease_id" id="terminateLeaseId">
        <div class="modal-body">
          <p>Terminating lease for <strong id="terminateTenantName"></strong>. This will set the unit back to vacant.</p>
          <label class="form-label fw-semibold">Reason for Termination</label>
          <textarea name="reason" class="form-control" rows="3" placeholder="e.g. Non-payment, tenant request, end of term…"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-times me-2"></i>Terminate</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function openEdit(l) {
    document.getElementById('leaseModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Lease — ' + l.lease_no;
    document.getElementById('leaseId').value        = l.id;
    document.getElementById('leaseUnit').value      = l.unit_id;
    document.getElementById('leaseTenant').value    = l.tenant_id;
    document.getElementById('leaseStart').value     = l.start_date;
    document.getElementById('leaseEnd').value       = l.end_date;
    document.getElementById('leaseRent').value      = l.monthly_rent;
    document.getElementById('leaseDeposit').value   = l.deposit;
    document.getElementById('leasePayDay').value    = l.payment_day;
    document.getElementById('leaseLateFeePct').value = l.late_fee_pct;
    document.getElementById('leaseLateDays').value  = l.late_fee_days;
    document.getElementById('leaseTerms').value     = l.terms || '';
    new bootstrap.Modal(document.getElementById('leaseModal')).show();
}
function terminate(id, name) {
    document.getElementById('terminateLeaseId').value = id;
    document.getElementById('terminateTenantName').textContent = name;
    new bootstrap.Modal(document.getElementById('terminateModal')).show();
}
$('#leasesTable').DataTable({pageLength:25,order:[[6,'asc'],[4,'asc']]});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
