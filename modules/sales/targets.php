<?php
// ── Sales: Sales Targets ────────────────────────────────────────
$moduleSlug='sales';$moduleName='Sales Management';$moduleIcon='fas fa-chart-line';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'targets.php','icon'=>'fas fa-bullseye','label'=>'Targets'],['url'=>'returns.php','icon'=>'fas fa-undo-alt','label'=>'Returns'],['url'=>'payments.php','icon'=>'fas fa-money-check-alt','label'=>'Payments'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $staffName  = sanitize($_POST['staff_name'] ?? '');
        $targetType = in_array($_POST['target_type'] ?? '', ['revenue','orders','units','customers']) ? $_POST['target_type'] : 'revenue';
        $targetVal  = (float)($_POST['target_value'] ?? 0);
        $period     = sanitize($_POST['period'] ?? date('Y-m'));
        $notes      = sanitize($_POST['notes'] ?? '');
        if ($id) {
            $pdo->prepare("UPDATE sales_targets SET staff_name=?,target_type=?,target_value=?,period=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$staffName,$targetType,$targetVal,$period,$notes,$id,$orgId]);
            setFlash('success','Target updated.');
        } else {
            $pdo->prepare("INSERT INTO sales_targets (org_id,staff_name,target_type,target_value,achieved_value,period,notes) VALUES (?,?,?,?,0,?,?)")
                ->execute([$orgId,$staffName,$targetType,$targetVal,$period,$notes]);
            setFlash('success','Target created.');
        }
    } elseif ($action === 'update_achieved') {
        $id       = (int)($_POST['id'] ?? 0);
        $achieved = (float)($_POST['achieved_value'] ?? 0);
        $pdo->prepare("UPDATE sales_targets SET achieved_value=? WHERE id=? AND org_id=?")->execute([$achieved,$id,$orgId]);
        setFlash('success','Achieved value updated.');
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM sales_targets WHERE id=? AND org_id=?")->execute([(int)$_POST['id'],$orgId]);
        setFlash('success','Target deleted.');
    }
    redirect('targets.php');
}

$periodFilter = sanitize($_GET['period'] ?? '');
$sql = "SELECT * FROM sales_targets WHERE org_id=?";
$params = [$orgId];
if ($periodFilter) { $sql .= " AND period=?"; $params[] = $periodFilter; }
$sql .= " ORDER BY period DESC, staff_name ASC";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $targets = $stmt->fetchAll();

$totalTargets = countRows($pdo,'sales_targets','org_id=?',[$orgId]);
$stmt=$pdo->prepare("SELECT COUNT(*) FROM sales_targets WHERE org_id=? AND achieved_value>=target_value"); $stmt->execute([$orgId]); $achieved=(int)$stmt->fetchColumn();

$editRow=null;
if(isset($_GET['edit'])){ $stmt=$pdo->prepare("SELECT * FROM sales_targets WHERE id=? AND org_id=?"); $stmt->execute([(int)$_GET['edit'],$orgId]); $editRow=$stmt->fetch(); }
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bullseye me-2" style="color:<?= $moduleColor ?>"></i>Sales Targets</h4>
    <p class="text-muted mb-0">Set and track monthly/periodic performance targets per staff member</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tgtModal" onclick="resetForm()">
    <i class="fas fa-plus me-1"></i>Set Target
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(26,138,78,0.12);color:#1A8A4E"><i class="fas fa-bullseye"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalTargets ?></div><div class="stat-label">Total Targets</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $achieved ?></div><div class="stat-label">Targets Met</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalTargets - $achieved ?></div><div class="stat-label">In Progress</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-3"><input type="month" name="period" class="form-control form-control-sm" value="<?= e($periodFilter) ?>"></div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if($periodFilter): ?><div class="col-auto"><a href="targets.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr><th class="ps-3">Staff</th><th>Period</th><th>Type</th><th class="text-end">Target</th><th class="text-end">Achieved</th><th class="text-center">Progress</th><th class="text-end pe-3">Actions</th></tr>
        </thead>
        <tbody>
          <?php if(empty($targets)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No targets set.</td></tr>
          <?php else: foreach($targets as $t):
            $pct = $t['target_value'] > 0 ? min(100, round(($t['achieved_value']/$t['target_value'])*100)) : 0;
            $barClass = $pct>=100?'success':($pct>=60?'warning':'danger');
          ?>
          <tr>
            <td class="ps-3 fw-semibold"><?= e($t['staff_name']) ?></td>
            <td><?= e($t['period']) ?></td>
            <td><span class="badge bg-info"><?= ucfirst($t['target_type']) ?></span></td>
            <td class="text-end"><?= $t['target_type']==='revenue' ? formatCurrency($t['target_value']) : number_format($t['target_value']) ?></td>
            <td class="text-end"><?= $t['target_type']==='revenue' ? formatCurrency($t['achieved_value']) : number_format($t['achieved_value']) ?></td>
            <td class="text-center" style="min-width:140px">
              <div class="progress" style="height:12px">
                <div class="progress-bar bg-<?= $barClass ?>" style="width:<?= $pct ?>%"></div>
              </div>
              <small class="text-<?= $barClass ?>"><?= $pct ?>%</small>
            </td>
            <td class="text-end pe-3">
              <!-- Update achieved -->
              <button class="btn btn-sm btn-outline-success me-1" data-bs-toggle="modal" data-bs-target="#achievedModal"
                onclick="setAchieved(<?= $t['id'] ?>, '<?= e($t['staff_name']) ?>', <?= $t['achieved_value'] ?>)">
                <i class="fas fa-upload"></i>
              </button>
              <a href="targets.php?edit=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary me-1"
                 data-bs-toggle="modal" data-bs-target="#tgtModal"
                 onclick="fillForm(<?= htmlspecialchars(json_encode($t),ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete target?')">
                <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $t['id'] ?>">
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

<!-- Target Modal -->
<div class="modal fade" id="tgtModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-bullseye me-2"></i><span id="modalTitle">Set Target</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="fId" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Staff Name <span class="text-danger">*</span></label><input type="text" name="staff_name" id="fStaff" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Period (Month)</label><input type="month" name="period" id="fPeriod" class="form-control" value="<?= date('Y-m') ?>"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Target Type</label>
              <select name="target_type" id="fType" class="form-select">
                <option value="revenue">Revenue (KES)</option><option value="orders">Order Count</option>
                <option value="units">Units Sold</option><option value="customers">New Customers</option>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label fw-semibold">Target Value</label><input type="number" name="target_value" id="fTarget" class="form-control" step="0.01" min="0" required></div>
            <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Target</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Update Achieved Modal -->
<div class="modal fade" id="achievedModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-upload me-2"></i>Update Achieved — <span id="achStaff"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="update_achieved"><input type="hidden" name="id" id="achId">
        <div class="modal-body">
          <label class="form-label fw-semibold">Achieved Value</label>
          <input type="number" name="achieved_value" id="achVal" class="form-control" step="0.01" min="0" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function resetForm(){document.getElementById('modalTitle').textContent='Set Target';document.getElementById('fId').value='0';document.getElementById('fStaff').value='';document.getElementById('fPeriod').value=new Date().toISOString().substr(0,7);document.getElementById('fType').value='revenue';document.getElementById('fTarget').value='';document.getElementById('fNotes').value='';}
function fillForm(t){document.getElementById('modalTitle').textContent='Edit Target';document.getElementById('fId').value=t.id;document.getElementById('fStaff').value=t.staff_name;document.getElementById('fPeriod').value=t.period;document.getElementById('fType').value=t.target_type;document.getElementById('fTarget').value=t.target_value;document.getElementById('fNotes').value=t.notes;}
function setAchieved(id,name,val){document.getElementById('achId').value=id;document.getElementById('achStaff').textContent=name;document.getElementById('achVal').value=val;}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
