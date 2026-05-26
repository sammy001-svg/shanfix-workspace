<?php
// ── Rental: Property Agreements / Side Agreements ──────────────
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
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $tenantId   = (int)($_POST['tenant_id'] ?? 0) ?: null;
        $unitId     = (int)($_POST['unit_id'] ?? 0) ?: null;
        $agreeType  = in_array($_POST['agreement_type'] ?? '', ['parking','storage','pet','renovation','sublease','other']) ? $_POST['agreement_type'] : 'other';
        $title      = sanitize($_POST['title'] ?? '');
        $startDate  = sanitize($_POST['start_date'] ?? '');
        $endDate    = sanitize($_POST['end_date'] ?? '') ?: null;
        $value      = (float)($_POST['value'] ?? 0);
        $status     = in_array($_POST['status'] ?? '', ['active','expired','terminated','pending']) ? $_POST['status'] : 'pending';
        $terms      = sanitize($_POST['terms'] ?? '');

        if ($id) {
            $pdo->prepare("UPDATE rental_agreements SET tenant_id=?,unit_id=?,agreement_type=?,title=?,start_date=?,end_date=?,value=?,status=?,terms=? WHERE id=? AND org_id=?")
                ->execute([$tenantId,$unitId,$agreeType,$title,$startDate,$endDate,$value,$status,$terms,$id,$orgId]);
            setFlash('success','Agreement updated.');
        } else {
            $pdo->prepare("INSERT INTO rental_agreements (org_id,tenant_id,unit_id,agreement_type,title,start_date,end_date,value,status,terms) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$tenantId,$unitId,$agreeType,$title,$startDate,$endDate,$value,$status,$terms]);
            setFlash('success','Agreement created.');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM rental_agreements WHERE id=? AND org_id=?")->execute([(int)$_POST['id'],$orgId]);
        setFlash('success','Agreement deleted.');
    }
    redirect('agreements.php');
}

$tenants = $pdo->prepare("SELECT id,name FROM rental_tenants WHERE org_id=? ORDER BY name"); $tenants->execute([$orgId]); $tenants=$tenants->fetchAll();
$units   = $pdo->prepare("SELECT u.id, CONCAT(p.name,' — Unit ',u.unit_number) as label FROM rental_units u JOIN rental_properties p ON u.property_id=p.id WHERE u.org_id=? ORDER BY label"); $units->execute([$orgId]); $units=$units->fetchAll();

$statusFilter = sanitize($_GET['status'] ?? '');
$typeFilter   = sanitize($_GET['type'] ?? '');
$sql = "SELECT a.*, t.name as tenant_name FROM rental_agreements a LEFT JOIN rental_tenants t ON a.tenant_id=t.id WHERE a.org_id=?";
$params=[$orgId];
if($statusFilter){ $sql.=" AND a.status=?"; $params[]=$statusFilter; }
if($typeFilter)  { $sql.=" AND a.agreement_type=?"; $params[]=$typeFilter; }
$sql.=" ORDER BY a.start_date DESC";
$stmt=$pdo->prepare($sql); $stmt->execute($params); $agreements=$stmt->fetchAll();

$totalAgreements=countRows($pdo,'rental_agreements','org_id=?',[$orgId]);
$activeAgreements=countRows($pdo,'rental_agreements','org_id=? AND status=?',[$orgId,'active']);
$stmt=$pdo->prepare("SELECT COALESCE(SUM(value),0) FROM rental_agreements WHERE org_id=? AND status='active'"); $stmt->execute([$orgId]); $activeValue=(float)$stmt->fetchColumn();

$editRow=null;
if(isset($_GET['edit'])){ $stmt=$pdo->prepare("SELECT * FROM rental_agreements WHERE id=? AND org_id=?"); $stmt->execute([(int)$_GET['edit'],$orgId]); $editRow=$stmt->fetch(); }

$statusColors=['active'=>'success','expired'=>'secondary','terminated'=>'danger','pending'=>'warning'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-signature me-2" style="color:<?= $moduleColor ?>"></i>Property Agreements</h4>
    <p class="text-muted mb-0">Track supplementary tenant agreements (parking, pets, renovation, etc.)</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#agreeModal" onclick="resetForm()">
    <i class="fas fa-plus me-1"></i>New Agreement
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon" style="background:rgba(41,128,185,0.12);color:#2980b9"><i class="fas fa-file-signature"></i></div><div class="stat-body"><div class="stat-value"><?= $totalAgreements ?></div><div class="stat-label">Total Agreements</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?= $activeAgreements ?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-coins"></i></div><div class="stat-body"><div class="stat-value"><?= formatCurrency($activeValue) ?></div><div class="stat-label">Active Value</div></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-3"><select name="type" class="form-select form-select-sm"><option value="">All Types</option><?php foreach(['parking','storage','pet','renovation','sublease','other'] as $t): ?><option value="<?= $t ?>" <?= $typeFilter===$t?'selected':'' ?>><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-3"><select name="status" class="form-select form-select-sm"><option value="">All Statuses</option><?php foreach(['active','expired','terminated','pending'] as $s): ?><option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if($typeFilter||$statusFilter): ?><div class="col-auto"><a href="agreements.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light"><tr><th class="ps-3">Title</th><th>Tenant</th><th>Type</th><th class="text-center">Period</th><th class="text-end">Value</th><th class="text-center">Status</th><th class="text-end pe-3">Actions</th></tr></thead>
        <tbody>
          <?php if(empty($agreements)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No agreements found.</td></tr>
          <?php else: foreach($agreements as $ag): ?>
          <tr>
            <td class="ps-3 fw-semibold"><?= e($ag['title']) ?></td>
            <td><?= e($ag['tenant_name'] ?? '—') ?></td>
            <td><span class="badge bg-info"><?= ucfirst($ag['agreement_type']) ?></span></td>
            <td class="text-center small"><?= formatDate($ag['start_date']) ?><?= $ag['end_date'] ? ' → '.formatDate($ag['end_date']) : '' ?></td>
            <td class="text-end"><?= $ag['value']>0 ? formatCurrency($ag['value']) : '—' ?></td>
            <td class="text-center"><span class="badge bg-<?= $statusColors[$ag['status']]??'secondary' ?>"><?= ucfirst($ag['status']) ?></span></td>
            <td class="text-end pe-3">
              <a href="agreements.php?edit=<?= $ag['id'] ?>" class="btn btn-sm btn-outline-primary me-1"
                 data-bs-toggle="modal" data-bs-target="#agreeModal"
                 onclick="fillForm(<?= htmlspecialchars(json_encode($ag),ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete?')">
                <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $ag['id'] ?>">
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

<div class="modal fade" id="agreeModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-signature me-2"></i><span id="modalTitle">New Agreement</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="fId" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12"><label class="form-label fw-semibold">Agreement Title <span class="text-danger">*</span></label><input type="text" name="title" id="fTitle" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Tenant</label><select name="tenant_id" id="fTenant" class="form-select"><option value="">— None —</option><?php foreach($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Agreement Type</label><select name="agreement_type" id="fType" class="form-select"><?php foreach(['parking','storage','pet','renovation','sublease','other'] as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Start Date</label><input type="date" name="start_date" id="fStart" class="form-control"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">End Date</label><input type="date" name="end_date" id="fEnd" class="form-control"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="fStatus" class="form-select"><?php foreach(['pending','active','expired','terminated'] as $s): ?><option value="<?= $s ?>"><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Agreement Value (KES)</label><input type="number" name="value" id="fValue" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-12"><label class="form-label fw-semibold">Terms & Conditions</label><textarea name="terms" id="fTerms" class="form-control" rows="3"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Agreement</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function resetForm(){document.getElementById('modalTitle').textContent='New Agreement';document.getElementById('fId').value='0';document.getElementById('fTitle').value='';document.getElementById('fTenant').value='';document.getElementById('fType').value='other';document.getElementById('fStart').value='';document.getElementById('fEnd').value='';document.getElementById('fStatus').value='pending';document.getElementById('fValue').value='0';document.getElementById('fTerms').value='';}
function fillForm(a){document.getElementById('modalTitle').textContent='Edit Agreement';document.getElementById('fId').value=a.id;document.getElementById('fTitle').value=a.title;document.getElementById('fTenant').value=a.tenant_id??'';document.getElementById('fType').value=a.agreement_type;document.getElementById('fStart').value=a.start_date;document.getElementById('fEnd').value=a.end_date??'';document.getElementById('fStatus').value=a.status;document.getElementById('fValue').value=a.value;document.getElementById('fTerms').value=a.terms??'';}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
