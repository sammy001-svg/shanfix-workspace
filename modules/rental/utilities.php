<?php
// ── Rental: Utility Bills & Readings ───────────────────────────
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

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $unitId     = (int)($_POST['unit_id'] ?? 0);
        $utilType   = in_array($_POST['util_type'] ?? '', ['electricity','water','gas','internet','garbage']) ? $_POST['util_type'] : 'electricity';
        $readingPrev= (float)($_POST['reading_prev'] ?? 0);
        $readingCurr= (float)($_POST['reading_curr'] ?? 0);
        $ratePerUnit= (float)($_POST['rate_per_unit'] ?? 0);
        $amount     = round(($readingCurr - $readingPrev) * $ratePerUnit, 2);
        $billMonth  = sanitize($_POST['bill_month'] ?? date('Y-m'));
        $status     = in_array($_POST['status'] ?? '', ['unpaid','paid','disputed']) ? $_POST['status'] : 'unpaid';
        $notes      = sanitize($_POST['notes'] ?? '');

        if ($id) {
            $pdo->prepare("UPDATE rental_utilities SET unit_id=?,util_type=?,reading_prev=?,reading_curr=?,rate_per_unit=?,amount=?,bill_month=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$unitId,$utilType,$readingPrev,$readingCurr,$ratePerUnit,$amount,$billMonth,$status,$notes,$id,$orgId]);
            setFlash('success','Utility bill updated.');
        } else {
            $pdo->prepare("INSERT INTO rental_utilities (org_id,unit_id,util_type,reading_prev,reading_curr,rate_per_unit,amount,bill_month,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$unitId,$utilType,$readingPrev,$readingCurr,$ratePerUnit,$amount,$billMonth,$status,$notes]);
            setFlash('success','Utility bill recorded.');
        }
    } elseif ($action === 'pay') {
        $pdo->prepare("UPDATE rental_utilities SET status='paid' WHERE id=? AND org_id=?")->execute([(int)$_POST['id'],$orgId]);
        setFlash('success','Marked as paid.');
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM rental_utilities WHERE id=? AND org_id=?")->execute([(int)$_POST['id'],$orgId]);
        setFlash('success','Bill deleted.');
    }
    redirect('utilities.php');
}

// Units for dropdown
$units = $pdo->prepare("SELECT u.id, CONCAT(p.name,' — Unit ',u.unit_number) as label FROM rental_units u JOIN rental_properties p ON u.property_id=p.id WHERE u.org_id=? ORDER BY label");
$units->execute([$orgId]); $units=$units->fetchAll();

$typeFilter   = sanitize($_GET['type'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$monthFilter  = sanitize($_GET['month'] ?? '');
$sql = "SELECT ru.*, CONCAT(rp.name,' — Unit ',un.unit_number) as unit_label FROM rental_utilities ru LEFT JOIN rental_units un ON ru.unit_id=un.id LEFT JOIN rental_properties rp ON un.property_id=rp.id WHERE ru.org_id=?";
$params = [$orgId];
if ($typeFilter)   { $sql .= " AND ru.util_type=?"; $params[]=$typeFilter; }
if ($statusFilter) { $sql .= " AND ru.status=?"; $params[]=$statusFilter; }
if ($monthFilter)  { $sql .= " AND ru.bill_month=?"; $params[]=$monthFilter; }
$sql .= " ORDER BY ru.bill_month DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $bills=$stmt->fetchAll();

$stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM rental_utilities WHERE org_id=? AND status='unpaid'"); $stmt->execute([$orgId]); $totalUnpaid=(float)$stmt->fetchColumn();
$stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM rental_utilities WHERE org_id=? AND status='paid'"); $stmt->execute([$orgId]); $totalPaid=(float)$stmt->fetchColumn();
$totalBills=countRows($pdo,'rental_utilities','org_id=?',[$orgId]);

$editRow=null;
if(isset($_GET['edit'])){ $stmt=$pdo->prepare("SELECT * FROM rental_utilities WHERE id=? AND org_id=?"); $stmt->execute([(int)$_GET['edit'],$orgId]); $editRow=$stmt->fetch(); }

$utilIcons=['electricity'=>'fas fa-bolt','water'=>'fas fa-tint','gas'=>'fas fa-fire','internet'=>'fas fa-wifi','garbage'=>'fas fa-trash'];
$statusColors=['unpaid'=>'danger','paid'=>'success','disputed'=>'warning'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bolt me-2" style="color:<?= $moduleColor ?>"></i>Utility Bills</h4>
    <p class="text-muted mb-0">Record electricity, water, gas, and other unit utility readings</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#utilModal" onclick="resetForm()">
    <i class="fas fa-plus me-1"></i>Add Utility Bill
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,0.12);color:#2980b9"><i class="fas fa-file-invoice"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalBills ?></div><div class="stat-label">Total Bills</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(220,53,69,0.12);color:#dc3545"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalUnpaid) ?></div><div class="stat-label">Outstanding</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPaid) ?></div><div class="stat-label">Collected</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-2">
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <?php foreach(['electricity','water','gas','internet','garbage'] as $t): ?><option value="<?= $t ?>" <?= $typeFilter===$t?'selected':'' ?>><?= ucfirst($t) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach(['unpaid','paid','disputed'] as $s): ?><option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2"><input type="month" name="month" class="form-control form-control-sm" value="<?= e($monthFilter) ?>"></div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if($typeFilter||$statusFilter||$monthFilter): ?><div class="col-auto"><a href="utilities.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr><th class="ps-3">Unit</th><th>Type</th><th class="text-center">Month</th><th class="text-center">Prev</th><th class="text-center">Curr</th><th class="text-end">Amount</th><th class="text-center">Status</th><th class="text-end pe-3">Actions</th></tr>
        </thead>
        <tbody>
          <?php if(empty($bills)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No utility bills found.</td></tr>
          <?php else: foreach($bills as $b): ?>
          <tr>
            <td class="ps-3"><?= e($b['unit_label'] ?? '—') ?></td>
            <td><i class="<?= $utilIcons[$b['util_type']] ?? 'fas fa-bolt' ?> me-1"></i><?= ucfirst($b['util_type']) ?></td>
            <td class="text-center"><?= e($b['bill_month']) ?></td>
            <td class="text-center"><?= number_format($b['reading_prev'],2) ?></td>
            <td class="text-center"><?= number_format($b['reading_curr'],2) ?></td>
            <td class="text-end fw-bold"><?= formatCurrency($b['amount']) ?></td>
            <td class="text-center"><span class="badge bg-<?= $statusColors[$b['status']]??'secondary' ?>"><?= ucfirst($b['status']) ?></span></td>
            <td class="text-end pe-3">
              <?php if($b['status']==='unpaid'): ?>
              <form method="post" class="d-inline">
                <?= csrfField() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= $b['id'] ?>">
                <button class="btn btn-sm btn-outline-success me-1" title="Mark Paid"><i class="fas fa-check"></i></button>
              </form>
              <?php endif; ?>
              <a href="utilities.php?edit=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary me-1"
                 data-bs-toggle="modal" data-bs-target="#utilModal"
                 onclick="fillForm(<?= htmlspecialchars(json_encode($b),ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete?')">
                <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $b['id'] ?>">
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

<!-- Utility Modal -->
<div class="modal fade" id="utilModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-bolt me-2"></i><span id="modalTitle">Add Utility Bill</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="fId" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Unit <span class="text-danger">*</span></label>
              <select name="unit_id" id="fUnit" class="form-select" required>
                <option value="">— Select Unit —</option>
                <?php foreach($units as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['label']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label fw-semibold">Utility Type</label>
              <select name="util_type" id="fType" class="form-select">
                <?php foreach(['electricity','water','gas','internet','garbage'] as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label fw-semibold">Bill Month</label><input type="month" name="bill_month" id="fMonth" class="form-control" value="<?= date('Y-m') ?>"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Previous Reading</label><input type="number" name="reading_prev" id="fPrev" class="form-control" step="0.01" min="0" value="0" oninput="calcAmount()"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Current Reading</label><input type="number" name="reading_curr" id="fCurr" class="form-control" step="0.01" min="0" value="0" oninput="calcAmount()"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Rate per Unit (KES)</label><input type="number" name="rate_per_unit" id="fRate" class="form-control" step="0.01" min="0" value="0" oninput="calcAmount()"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Calculated Amount</label><input type="text" id="calcAmt" class="form-control bg-light fw-bold" readonly value="0.00"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Status</label>
              <select name="status" id="fStatus" class="form-select">
                <option value="unpaid">Unpaid</option><option value="paid">Paid</option><option value="disputed">Disputed</option>
              </select>
            </div>
            <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Bill</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function calcAmount(){var p=parseFloat(document.getElementById('fPrev').value)||0;var c=parseFloat(document.getElementById('fCurr').value)||0;var r=parseFloat(document.getElementById('fRate').value)||0;document.getElementById('calcAmt').value=((c-p)*r).toFixed(2);}
function resetForm(){document.getElementById('modalTitle').textContent='Add Utility Bill';document.getElementById('fId').value='0';document.getElementById('fUnit').value='';document.getElementById('fType').value='electricity';document.getElementById('fMonth').value=new Date().toISOString().substr(0,7);document.getElementById('fPrev').value='0';document.getElementById('fCurr').value='0';document.getElementById('fRate').value='0';document.getElementById('calcAmt').value='0.00';document.getElementById('fStatus').value='unpaid';document.getElementById('fNotes').value='';}
function fillForm(b){document.getElementById('modalTitle').textContent='Edit Utility Bill';document.getElementById('fId').value=b.id;document.getElementById('fUnit').value=b.unit_id;document.getElementById('fType').value=b.util_type;document.getElementById('fMonth').value=b.bill_month;document.getElementById('fPrev').value=b.reading_prev;document.getElementById('fCurr').value=b.reading_curr;document.getElementById('fRate').value=b.rate_per_unit;document.getElementById('calcAmt').value=parseFloat(b.amount).toFixed(2);document.getElementById('fStatus').value=b.status;document.getElementById('fNotes').value=b.notes;}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
