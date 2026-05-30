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
    $stmt  = $pdo->prepare("SELECT m.*,
                               CONCAT(u.unit_no,' — ',p.name) AS unit_label,
                               CONCAT(t.first_name,' ',t.last_name) AS tenant_name
                             FROM rental_maintenance m
                             LEFT JOIN rental_units u ON m.unit_id=u.id
                             LEFT JOIN rental_properties p ON u.property_id=p.id
                             LEFT JOIN rental_tenants t ON m.tenant_id=t.id
                             WHERE m.id=? AND m.org_id=?");
    $stmt->execute([$id, $orgId]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../includes/header-module.php';
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_request') {
        $id          = (int)($_POST['maint_id'] ?? 0);
        $unitId      = (int)$_POST['unit_id'] ?: null;
        $tenantId    = (int)$_POST['tenant_id'] ?: null;
        $category    = sanitize($_POST['category'] ?? 'other');
        $priority    = sanitize($_POST['priority'] ?? 'normal');
        $title       = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $assignedTo  = sanitize($_POST['assigned_to'] ?? '');
        $estCost     = (float)($_POST['estimated_cost'] ?? 0) ?: null;
        $status      = sanitize($_POST['status'] ?? 'open');

        if (!$title) { setFlash('danger', 'Title is required.'); }
        else {
            if ($id) {
                $actualCost = (float)($_POST['actual_cost'] ?? 0) ?: null;
                $completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
                $pdo->prepare("UPDATE rental_maintenance SET unit_id=?,tenant_id=?,category=?,priority=?,title=?,description=?,assigned_to=?,estimated_cost=?,actual_cost=?,status=?,completed_at=COALESCE(completed_at,?),notes=? WHERE id=? AND org_id=?")
                    ->execute([$unitId,$tenantId,$category,$priority,$title,$description,$assignedTo,$estCost,$actualCost,$status,$completedAt,sanitize($_POST['notes']??''),$id,$orgId]);
            } else {
                $reqNo = 'MR-'.date('Y').'-'.str_pad($pdo->query("SELECT COUNT(*)+1 FROM rental_maintenance WHERE org_id=$orgId")->fetchColumn(),4,'0',STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO rental_maintenance (org_id,request_no,unit_id,tenant_id,category,priority,title,description,assigned_to,estimated_cost,status) VALUES (?,?,?,?,?,?,?,?,?,?,'open')")
                    ->execute([$orgId,$reqNo,$unitId,$tenantId,$category,$priority,$title,$description,$assignedTo,$estCost]);
                setFlash('success', "Maintenance request $reqNo logged.");
            }
            if ($id) setFlash('success', 'Request updated.');
        }
        redirect(APP_URL.'/modules/rental/maintenance.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

$requests = [];
try {
    $stmt = $pdo->prepare("SELECT m.*,
                              CONCAT(u.unit_no,' — ',p.name) AS unit_label,
                              CONCAT(t.first_name,' ',t.last_name) AS tenant_name
                           FROM rental_maintenance m
                           LEFT JOIN rental_units u ON m.unit_id=u.id
                           LEFT JOIN rental_properties p ON u.property_id=p.id
                           LEFT JOIN rental_tenants t ON m.tenant_id=t.id
                           WHERE m.org_id=? ORDER BY FIELD(m.priority,'urgent','high','normal','low'), m.reported_at DESC");
    $stmt->execute([$orgId]);
    $requests = $stmt->fetchAll();
} catch (Exception $e) {}

$units = [];
try {
    $stmt = $pdo->prepare("SELECT u.id, CONCAT(u.unit_no,' — ',p.name) AS label FROM rental_units u JOIN rental_properties p ON u.property_id=p.id WHERE u.org_id=? ORDER BY p.name,u.unit_no");
    $stmt->execute([$orgId]);
    $units = $stmt->fetchAll();
} catch (Exception $e) {}

$tenants = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name FROM rental_tenants WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]);
    $tenants = $stmt->fetchAll();
} catch (Exception $e) {}

$open       = count(array_filter($requests, fn($r)=>$r['status']==='open'));
$inProgress = count(array_filter($requests, fn($r)=>$r['status']==='in_progress'));
$urgent     = count(array_filter($requests, fn($r)=>$r['priority']==='urgent' && $r['status']!=='completed'));
$completed  = count(array_filter($requests, fn($r)=>$r['status']==='completed'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-tools me-2" style="color:<?= $moduleColor ?>"></i>Maintenance Requests</h4>
    <p class="text-muted mb-0">Log, assign, and track property maintenance and repair work</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#maintModal" onclick="resetForm()">
    <i class="fas fa-plus me-2"></i>Log Request
  </button>
</div>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['val'=>$open,      'label'=>'Open',        'icon'=>'fas fa-inbox',          'col'=>'primary'],
    ['val'=>$urgent,    'label'=>'Urgent',       'icon'=>'fas fa-fire',           'col'=>'danger'],
    ['val'=>$inProgress,'label'=>'In Progress',  'icon'=>'fas fa-hard-hat',       'col'=>'warning'],
    ['val'=>$completed, 'label'=>'Completed',    'icon'=>'fas fa-check-circle',   'col'=>'success'],
  ] as $s): ?>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon text-<?= $s['col'] ?>"><i class="<?= $s['icon'] ?>"></i></div>
      <div class="stat-body"><div class="stat-value text-<?= $s['col'] ?>"><?= $s['val'] ?></div><div class="stat-label"><?= $s['label'] ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="maintTable">
        <thead class="table-light">
          <tr><th>Ref</th><th>Unit / Location</th><th>Title</th><th>Category</th><th>Priority</th><th>Assigned To</th><th>Status</th><th>Reported</th><th></th></tr>
        </thead>
        <tbody>
          <?php
          $priBadge = ['urgent'=>'danger','high'=>'warning','normal'=>'primary','low'=>'secondary'];
          $stBadge  = ['open'=>'primary','assigned'=>'info','in_progress'=>'warning','completed'=>'success','cancelled'=>'secondary'];
          foreach ($requests as $r): ?>
          <tr>
            <td><code class="small"><?= e($r['request_no']) ?></code></td>
            <td class="small"><?= e($r['unit_label'] ?? '—') ?></td>
            <td>
              <div class="fw-semibold small"><?= e($r['title']) ?></div>
              <?php if ($r['tenant_name']): ?><small class="text-muted"><?= e($r['tenant_name']) ?></small><?php endif; ?>
            </td>
            <td class="small"><?= ucfirst($r['category']) ?></td>
            <td><span class="badge bg-<?= $priBadge[$r['priority']]??'secondary' ?>"><?= ucfirst($r['priority']) ?></span></td>
            <td class="small"><?= e($r['assigned_to']??'—') ?></td>
            <td><span class="badge bg-<?= $stBadge[$r['status']]??'secondary' ?>"><?= str_replace('_',' ',ucfirst($r['status'])) ?></span></td>
            <td class="small"><?= formatDate($r['reported_at']) ?></td>
            <td>
              <button class="btn btn-xs btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)'>
                <i class="fas fa-edit"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($requests)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No maintenance requests logged yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="maintModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="maintModalTitle"><i class="fas fa-tools me-2"></i>Log Maintenance Request</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="save_request">
        <input type="hidden" name="maint_id" id="maintId" value="0">
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Title / Description <span class="text-danger">*</span></label>
            <input type="text" name="title" id="maintTitle" class="form-control" required placeholder="e.g. Broken kitchen tap">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Unit</label>
            <select name="unit_id" id="maintUnit" class="form-select">
              <option value="">— Select unit (optional) —</option>
              <?php foreach ($units as $u): ?>
              <option value="<?= $u['id'] ?>"><?= e($u['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Reported By (Tenant)</label>
            <select name="tenant_id" id="maintTenant" class="form-select">
              <option value="">— Select tenant —</option>
              <?php foreach ($tenants as $t): ?>
              <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Category</label>
            <select name="category" id="maintCategory" class="form-select">
              <?php foreach (['plumbing','electrical','hvac','structural','appliance','painting','cleaning','other'] as $c): ?>
              <option value="<?= $c ?>"><?= ucfirst($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Priority</label>
            <select name="priority" id="maintPriority" class="form-select">
              <option value="low">Low</option>
              <option value="normal" selected>Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
          </div>
          <div class="col-md-4" id="statusRow">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="maintStatus" class="form-select">
              <option value="open">Open</option>
              <option value="assigned">Assigned</option>
              <option value="in_progress">In Progress</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Detailed Description</label>
            <textarea name="description" id="maintDescription" class="form-control" rows="2"></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Assigned To (Contractor)</label>
            <input type="text" name="assigned_to" id="maintAssigned" class="form-control" placeholder="Contractor or worker name">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Est. Cost</label>
            <input type="number" name="estimated_cost" id="maintEstCost" class="form-control" step="0.01" min="0">
          </div>
          <div class="col-md-3" id="actualCostRow" style="display:none">
            <label class="form-label fw-semibold">Actual Cost</label>
            <input type="number" name="actual_cost" id="maintActualCost" class="form-control" step="0.01" min="0">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" id="maintNotes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-2"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function resetForm() {
    document.getElementById('maintModalTitle').innerHTML = '<i class="fas fa-tools me-2"></i>Log Maintenance Request';
    document.getElementById('maintId').value = '0';
    ['maintTitle','maintDescription','maintAssigned','maintNotes'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('maintUnit').value     = '';
    document.getElementById('maintTenant').value   = '';
    document.getElementById('maintCategory').value = 'other';
    document.getElementById('maintPriority').value = 'normal';
    document.getElementById('maintStatus').value   = 'open';
    document.getElementById('maintEstCost').value  = '';
    document.getElementById('actualCostRow').style.display = 'none';
}
function openEdit(r) {
    document.getElementById('maintModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit — ' + r.request_no;
    document.getElementById('maintId').value          = r.id;
    document.getElementById('maintTitle').value       = r.title || '';
    document.getElementById('maintUnit').value        = r.unit_id || '';
    document.getElementById('maintTenant').value      = r.tenant_id || '';
    document.getElementById('maintCategory').value    = r.category || 'other';
    document.getElementById('maintPriority').value    = r.priority || 'normal';
    document.getElementById('maintStatus').value      = r.status || 'open';
    document.getElementById('maintDescription').value = r.description || '';
    document.getElementById('maintAssigned').value    = r.assigned_to || '';
    document.getElementById('maintEstCost').value     = r.estimated_cost || '';
    document.getElementById('maintActualCost').value  = r.actual_cost || '';
    document.getElementById('maintNotes').value       = r.notes || '';
    document.getElementById('actualCostRow').style.display = 'flex';
    new bootstrap.Modal(document.getElementById('maintModal')).show();
}
$('#maintTable').DataTable({pageLength:25,order:[[7,'desc']]});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
