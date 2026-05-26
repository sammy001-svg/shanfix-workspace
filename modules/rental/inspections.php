<?php
// ── Rental: Property Inspections ────────────────────────────────
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
        $id          = (int)($_POST['id'] ?? 0);
        $unitId      = (int)($_POST['unit_id'] ?? 0);
        $inspType    = in_array($_POST['inspection_type'] ?? '', ['move_in','move_out','routine','emergency','annual']) ? $_POST['inspection_type'] : 'routine';
        $inspDate    = sanitize($_POST['inspection_date'] ?? date('Y-m-d'));
        $inspector   = sanitize($_POST['inspector'] ?? '');
        $condition   = in_array($_POST['condition_rating'] ?? '', ['excellent','good','fair','poor']) ? $_POST['condition_rating'] : 'good';
        $issues      = sanitize($_POST['issues_found'] ?? '');
        $actions     = sanitize($_POST['actions_required'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['pending','completed','follow_up']) ? $_POST['status'] : 'pending';
        $notes       = sanitize($_POST['notes'] ?? '');

        if ($id) {
            $pdo->prepare("UPDATE rental_inspections SET unit_id=?,inspection_type=?,inspection_date=?,inspector=?,condition_rating=?,issues_found=?,actions_required=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$unitId,$inspType,$inspDate,$inspector,$condition,$issues,$actions,$status,$notes,$id,$orgId]);
            setFlash('success','Inspection updated.');
        } else {
            $pdo->prepare("INSERT INTO rental_inspections (org_id,unit_id,inspection_type,inspection_date,inspector,condition_rating,issues_found,actions_required,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$unitId,$inspType,$inspDate,$inspector,$condition,$issues,$actions,$status,$notes]);
            setFlash('success','Inspection recorded.');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM rental_inspections WHERE id=? AND org_id=?")->execute([(int)$_POST['id'],$orgId]);
        setFlash('success','Inspection deleted.');
    }
    redirect('inspections.php');
}

$units = $pdo->prepare("SELECT u.id, CONCAT(p.name,' — Unit ',u.unit_number) as label FROM rental_units u JOIN rental_properties p ON u.property_id=p.id WHERE u.org_id=? ORDER BY label");
$units->execute([$orgId]); $units=$units->fetchAll();

$typeFilter   = sanitize($_GET['type'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$sql = "SELECT i.*, CONCAT(p.name,' — Unit ',u.unit_number) as unit_label FROM rental_inspections i LEFT JOIN rental_units u ON i.unit_id=u.id LEFT JOIN rental_properties p ON u.property_id=p.id WHERE i.org_id=?";
$params=[$orgId];
if($typeFilter)  { $sql.=" AND i.inspection_type=?"; $params[]=$typeFilter; }
if($statusFilter){ $sql.=" AND i.status=?"; $params[]=$statusFilter; }
$sql.=" ORDER BY i.inspection_date DESC";
$stmt=$pdo->prepare($sql); $stmt->execute($params); $inspections=$stmt->fetchAll();

$totalInspections=countRows($pdo,'rental_inspections','org_id=?',[$orgId]);
$pendingInspections=countRows($pdo,'rental_inspections','org_id=? AND status=?',[$orgId,'pending']);
$followUps=countRows($pdo,'rental_inspections','org_id=? AND status=?',[$orgId,'follow_up']);

$editRow=null;
if(isset($_GET['edit'])){ $stmt=$pdo->prepare("SELECT * FROM rental_inspections WHERE id=? AND org_id=?"); $stmt->execute([(int)$_GET['edit'],$orgId]); $editRow=$stmt->fetch(); }

$condColors=['excellent'=>'success','good'=>'info','fair'=>'warning','poor'=>'danger'];
$statusColors=['pending'=>'warning','completed'=>'success','follow_up'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-clipboard-check me-2" style="color:<?= $moduleColor ?>"></i>Property Inspections</h4>
    <p class="text-muted mb-0">Record move-in, move-out, routine, and annual property inspections</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#inspModal" onclick="resetForm()">
    <i class="fas fa-plus me-1"></i>Record Inspection
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon" style="background:rgba(41,128,185,0.12);color:#2980b9"><i class="fas fa-clipboard-check"></i></div><div class="stat-body"><div class="stat-value"><?= $totalInspections ?></div><div class="stat-label">Total Inspections</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div><div class="stat-body"><div class="stat-value"><?= $pendingInspections ?></div><div class="stat-label">Pending</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon" style="background:rgba(220,53,69,0.12);color:#dc3545"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-body"><div class="stat-value"><?= $followUps ?></div><div class="stat-label">Follow-Ups Required</div></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-3"><select name="type" class="form-select form-select-sm"><option value="">All Types</option><?php foreach(['move_in','move_out','routine','emergency','annual'] as $t): ?><option value="<?= $t ?>" <?= $typeFilter===$t?'selected':'' ?>><?= ucwords(str_replace('_',' ',$t)) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-3"><select name="status" class="form-select form-select-sm"><option value="">All Statuses</option><?php foreach(['pending','completed','follow_up'] as $s): ?><option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option><?php endforeach; ?></select></div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if($typeFilter||$statusFilter): ?><div class="col-auto"><a href="inspections.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light"><tr><th class="ps-3">Unit</th><th>Type</th><th class="text-center">Date</th><th>Inspector</th><th class="text-center">Condition</th><th>Issues</th><th class="text-center">Status</th><th class="text-end pe-3">Actions</th></tr></thead>
        <tbody>
          <?php if(empty($inspections)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No inspections recorded.</td></tr>
          <?php else: foreach($inspections as $ins): ?>
          <tr>
            <td class="ps-3"><?= e($ins['unit_label']??'—') ?></td>
            <td><span class="badge bg-secondary"><?= ucwords(str_replace('_',' ',$ins['inspection_type'])) ?></span></td>
            <td class="text-center"><?= formatDate($ins['inspection_date']) ?></td>
            <td><?= e($ins['inspector']) ?></td>
            <td class="text-center"><span class="badge bg-<?= $condColors[$ins['condition_rating']]??'secondary' ?>"><?= ucfirst($ins['condition_rating']) ?></span></td>
            <td class="text-muted small" style="max-width:180px"><?= e(mb_strimwidth($ins['issues_found']??'',0,80,'…')) ?></td>
            <td class="text-center"><span class="badge bg-<?= $statusColors[$ins['status']]??'secondary' ?>"><?= ucwords(str_replace('_',' ',$ins['status'])) ?></span></td>
            <td class="text-end pe-3">
              <a href="inspections.php?edit=<?= $ins['id'] ?>" class="btn btn-sm btn-outline-primary me-1"
                 data-bs-toggle="modal" data-bs-target="#inspModal"
                 onclick="fillForm(<?= htmlspecialchars(json_encode($ins),ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete?')">
                <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $ins['id'] ?>">
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

<div class="modal fade" id="inspModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i><span id="modalTitle">Record Inspection</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="fId" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Unit <span class="text-danger">*</span></label><select name="unit_id" id="fUnit" class="form-select" required><option value="">— Select —</option><?php foreach($units as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['label']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Inspection Type</label><select name="inspection_type" id="fType" class="form-select"><?php foreach(['move_in','move_out','routine','emergency','annual'] as $t): ?><option value="<?= $t ?>"><?= ucwords(str_replace('_',' ',$t)) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Date</label><input type="date" name="inspection_date" id="fDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Inspector Name</label><input type="text" name="inspector" id="fInspector" class="form-control"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Condition Rating</label><select name="condition_rating" id="fCondition" class="form-select"><?php foreach(['excellent','good','fair','poor'] as $c): ?><option value="<?= $c ?>"><?= ucfirst($c) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select name="status" id="fStatus" class="form-select"><?php foreach(['pending','completed','follow_up'] as $s): ?><option value="<?= $s ?>"><?= ucwords(str_replace('_',' ',$s)) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label fw-semibold">Issues Found</label><textarea name="issues_found" id="fIssues" class="form-control" rows="2"></textarea></div>
            <div class="col-12"><label class="form-label fw-semibold">Actions Required</label><textarea name="actions_required" id="fActions" class="form-control" rows="2"></textarea></div>
            <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Inspection</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function resetForm(){document.getElementById('modalTitle').textContent='Record Inspection';['fId','fInspector','fIssues','fActions','fNotes'].forEach(id=>document.getElementById(id).value='');document.getElementById('fUnit').value='';document.getElementById('fType').value='routine';document.getElementById('fDate').value=new Date().toISOString().substr(0,10);document.getElementById('fCondition').value='good';document.getElementById('fStatus').value='pending';}
function fillForm(i){document.getElementById('modalTitle').textContent='Edit Inspection';document.getElementById('fId').value=i.id;document.getElementById('fUnit').value=i.unit_id;document.getElementById('fType').value=i.inspection_type;document.getElementById('fDate').value=i.inspection_date;document.getElementById('fInspector').value=i.inspector;document.getElementById('fCondition').value=i.condition_rating;document.getElementById('fStatus').value=i.status;document.getElementById('fIssues').value=i.issues_found??'';document.getElementById('fActions').value=i.actions_required??'';document.getElementById('fNotes').value=i.notes??'';}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
