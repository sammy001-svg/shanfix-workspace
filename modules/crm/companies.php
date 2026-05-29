<?php
$moduleSlug  = 'crm';
$moduleName  = 'CRM — Customer Relations';
$moduleIcon  = 'fas fa-handshake';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'contacts.php',  'icon' => 'fas fa-address-book',   'label' => 'Contacts'],
    ['url' => 'companies.php', 'icon' => 'fas fa-building',       'label' => 'Companies'],
    ['url' => 'leads.php',     'icon' => 'fas fa-filter',         'label' => 'Leads'],
    ['url' => 'deals.php',     'icon' => 'fas fa-handshake',      'label' => 'Deals'],
    ['url' => 'pipeline.php',  'icon' => 'fas fa-columns',        'label' => 'Pipeline'],
    ['url' => 'quotes.php',    'icon' => 'fas fa-file-invoice',   'label' => 'Quotes'],
    ['url' => 'products.php',  'icon' => 'fas fa-box-open',       'label' => 'Products'],
    ['url' => 'activities.php','icon' => 'fas fa-tasks',          'label' => 'Activities'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-check-square',   'label' => 'Tasks'],
    ['url' => 'campaigns.php', 'icon' => 'fas fa-bullhorn',       'label' => 'Campaigns'],
    ['url' => 'contracts.php', 'icon' => 'fas fa-file-signature',  'label' => 'Contracts'],
    ['url' => 'tickets.php',   'icon' => 'fas fa-headset',          'label' => 'Support Tickets'],
    ['url' => 'email-log.php', 'icon' => 'fas fa-envelope-open-text','label' => 'Email Log'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',        'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = sanitize($_POST['name'] ?? '');
        $ind     = sanitize($_POST['industry'] ?? '');
        $web     = sanitize($_POST['website'] ?? '');
        $ph      = sanitize($_POST['phone'] ?? '');
        $em      = sanitize($_POST['email'] ?? '');
        $addr    = sanitize($_POST['address'] ?? '');
        $city    = sanitize($_POST['city'] ?? '');
        $cntry   = sanitize($_POST['country'] ?? '');
        $emp     = (int)($_POST['employees'] ?? 0) ?: null;
        $rev     = (float)($_POST['annual_revenue'] ?? 0) ?: null;
        $notes   = sanitize($_POST['notes'] ?? '');
        $status  = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

        if ($id > 0) {
            $pdo->prepare("UPDATE crm_companies SET name=?,industry=?,website=?,phone=?,email=?,address=?,city=?,country=?,employees=?,annual_revenue=?,notes=?,status=? WHERE id=? AND org_id=?")
                ->execute([$name,$ind,$web,$ph,$em,$addr,$city,$cntry,$emp,$rev,$notes,$status,$id,$orgId]);
            setFlash('success', 'Company updated.');
            logActivity('update', 'crm', "Updated company: $name");
        } else {
            $pdo->prepare("INSERT INTO crm_companies (org_id,name,industry,website,phone,email,address,city,country,employees,annual_revenue,notes,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$name,$ind,$web,$ph,$em,$addr,$city,$cntry,$emp,$rev,$notes,$status]);
            setFlash('success', "Company '$name' added.");
            logActivity('create', 'crm', "Added company: $name");
        }
        redirect('companies.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM crm_companies WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Company deleted.');
        redirect('companies.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';
$fQ      = trim($_GET['q'] ?? '');
$where   = 'org_id=?';
$params  = [$orgId];
if ($fStatus) { $where .= ' AND status=?'; $params[] = $fStatus; }
if ($fQ)      { $where .= ' AND (name LIKE ? OR industry LIKE ? OR city LIKE ? OR email LIKE ?)'; $like = "%$fQ%"; array_push($params,$like,$like,$like,$like); }

$companies = [];
try {
    $stmt = $pdo->prepare("
        SELECT co.*,
               (SELECT COUNT(*) FROM crm_contacts c WHERE c.company_id = co.id) AS contact_count,
               (SELECT COUNT(*) FROM crm_deals d   WHERE d.company_id  = co.id AND d.status='open') AS open_deals
        FROM crm_companies co WHERE $where ORDER BY co.name ASC
    ");
    $stmt->execute($params);
    $companies = $stmt->fetchAll();
} catch (Exception $e) {
    // company_id columns may not exist yet; fall back
    try {
        $stmt = $pdo->prepare("SELECT * FROM crm_companies WHERE $where ORDER BY name ASC");
        $stmt->execute($params);
        $companies = $stmt->fetchAll();
    } catch (Exception $e2) {}
}

$total  = countRows('crm_companies', 'org_id=?', [$orgId]);
$active = countRows('crm_companies', 'org_id=? AND status=?', [$orgId, 'active']);
$viewC  = null;
if (isset($_GET['view'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM crm_companies WHERE id=? AND org_id=?");
        $stmt->execute([(int)$_GET['view'], $orgId]);
        $viewC = $stmt->fetch();
    } catch (Exception $e) {}
}

$industries = ['Technology','Finance','Healthcare','Retail','Manufacturing','Education','Real Estate','Logistics','Media','Hospitality','Agriculture','Other'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-building me-2" style="color:<?= $moduleColor ?>"></i>Companies</h4>
    <p class="text-muted mb-0">Manage accounts, organizations and their contacts</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#coModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Company
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-building"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $total ?></div><div class="stat-label">Total Companies</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $active ?></div><div class="stat-label">Active</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-users"></i></div>
    <div class="stat-body"><div class="stat-value"><?= countRows('crm_contacts','org_id=?',[$orgId]) ?></div><div class="stat-label">Linked Contacts</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-handshake"></i></div>
    <div class="stat-body"><div class="stat-value"><?= countRows('crm_deals','org_id=? AND status=?',[$orgId,'open']) ?></div><div class="stat-label">Open Deals</div></div></div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-5"><label class="form-label small fw-semibold mb-1">Search</label>
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, industry, city, email…" value="<?= e($fQ) ?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="active"   <?= $fStatus==='active'   ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $fStatus==='inactive' ? 'selected' : '' ?>>Inactive</option>
      </select></div>
    <div class="col-auto">
      <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="companies.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
    </div>
  </form>
</div></div>

<!-- View Panel -->
<?php if ($viewC): ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:<?= $moduleColor ?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-building me-2"></i><?= e($viewC['name']) ?></h6>
    <a href="companies.php" class="btn btn-sm btn-light"><i class="fas fa-times me-1"></i>Close</a>
  </div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-md-6">
        <h6 class="text-muted fw-semibold mb-2">Company Details</h6>
        <table class="table table-sm">
          <tr><th class="text-muted w-40">Industry</th><td><?= e($viewC['industry'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Website</th><td><?= $viewC['website'] ? '<a href="'.e($viewC['website']).'" target="_blank">'.e($viewC['website']).'</a>' : '—' ?></td></tr>
          <tr><th class="text-muted">Phone</th><td><?= e($viewC['phone'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Email</th><td><?= e($viewC['email'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">City</th><td><?= e(($viewC['city'] ?? '—').($viewC['country'] ? ', '.$viewC['country'] : '')) ?></td></tr>
          <tr><th class="text-muted">Employees</th><td><?= $viewC['employees'] ? number_format($viewC['employees']) : '—' ?></td></tr>
          <tr><th class="text-muted">Annual Revenue</th><td><?= $viewC['annual_revenue'] ? formatCurrency((float)$viewC['annual_revenue']) : '—' ?></td></tr>
          <tr><th class="text-muted">Status</th><td><?= statusBadge($viewC['status'] ?? 'active') ?></td></tr>
        </table>
      </div>
      <div class="col-md-6">
        <?php
        // Linked contacts
        $linkedContacts = [];
        try {
            $s = $pdo->prepare("SELECT id,first_name,last_name,email,position FROM crm_contacts WHERE company_id=? LIMIT 10");
            $s->execute([$viewC['id']]);
            $linkedContacts = $s->fetchAll();
        } catch (Exception $e) {}
        // Open deals
        $linkedDeals = [];
        try {
            $s = $pdo->prepare("SELECT id,title,value,stage,status FROM crm_deals WHERE company_id=? ORDER BY created_at DESC LIMIT 5");
            $s->execute([$viewC['id']]);
            $linkedDeals = $s->fetchAll();
        } catch (Exception $e) {}
        ?>
        <h6 class="text-muted fw-semibold mb-2">Linked Contacts (<?= count($linkedContacts) ?>)</h6>
        <?php if ($linkedContacts): ?>
        <ul class="list-group list-group-flush mb-3">
          <?php foreach ($linkedContacts as $lc): ?>
          <li class="list-group-item px-0 py-1 small">
            <span class="fw-semibold"><?= e($lc['first_name'].' '.$lc['last_name']) ?></span>
            <?php if ($lc['position']): ?><span class="text-muted"> — <?= e($lc['position']) ?></span><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?><p class="text-muted small">No linked contacts.</p><?php endif; ?>

        <h6 class="text-muted fw-semibold mb-2">Deals (<?= count($linkedDeals) ?>)</h6>
        <?php if ($linkedDeals): ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($linkedDeals as $ld): ?>
          <li class="list-group-item px-0 py-1 small d-flex justify-content-between">
            <span><?= e($ld['title']) ?></span>
            <span class="fw-semibold text-success"><?= formatCurrency((float)$ld['value']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?><p class="text-muted small">No linked deals.</p><?php endif; ?>
      </div>
      <?php if (!empty($viewC['notes'])): ?>
      <div class="col-12"><p class="text-muted mb-0"><strong>Notes:</strong> <?= nl2br(e($viewC['notes'])) ?></p></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-building me-2" style="color:<?= $moduleColor ?>"></i>Company List</h6>
    <span class="badge bg-secondary"><?= count($companies) ?> companies</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr><th>Company</th><th>Industry</th><th>Contact</th><th>City</th><th>Contacts</th><th>Open Deals</th><th>Status</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($companies)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-building fa-2x mb-2 d-block"></i>No companies found.</td></tr>
        <?php else: foreach ($companies as $co): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div style="width:34px;height:34px;border-radius:8px;background:<?= $moduleColor ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0">
                  <?= strtoupper(substr($co['name'], 0, 2)) ?>
                </div>
                <div>
                  <div class="fw-semibold"><?= e($co['name']) ?></div>
                  <?php if ($co['website']): ?><div class="small text-muted"><?= e($co['website']) ?></div><?php endif; ?>
                </div>
              </div>
            </td>
            <td class="small"><?= e($co['industry'] ?? '—') ?></td>
            <td class="small"><?= e($co['email'] ?? ($co['phone'] ?? '—')) ?></td>
            <td class="small"><?= e($co['city'] ?? '—') ?></td>
            <td class="text-center"><span class="badge bg-info text-dark"><?= $co['contact_count'] ?? 0 ?></span></td>
            <td class="text-center"><span class="badge bg-warning text-dark"><?= $co['open_deals'] ?? 0 ?></span></td>
            <td><?= statusBadge($co['status'] ?? 'active') ?></td>
            <td class="text-center" style="white-space:nowrap">
              <a href="?view=<?= $co['id'] ?>" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
              <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEdit(<?= htmlspecialchars(json_encode($co), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delCo(<?= $co['id'] ?>,'<?= e($co['name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="coModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="coId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="coModalTitle"><i class="fas fa-building me-2"></i>Add Company</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="coName" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Industry</label>
              <select name="industry" id="coIndustry" class="form-select">
                <option value="">— Select —</option>
                <?php foreach ($industries as $ind): ?>
                <option value="<?= e($ind) ?>"><?= e($ind) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Website</label>
              <input type="url" name="website" id="coWebsite" class="form-control" placeholder="https://…" maxlength="255">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="coPhone" class="form-control" maxlength="50">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="coEmail" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Address</label>
              <input type="text" name="address" id="coAddress" class="form-control" maxlength="500">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">City</label>
              <input type="text" name="city" id="coCity" class="form-control" maxlength="100">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Country</label>
              <input type="text" name="country" id="coCountry" class="form-control" maxlength="100">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="coStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">No. of Employees</label>
              <input type="number" name="employees" id="coEmployees" class="form-control" min="0" placeholder="e.g. 50">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Annual Revenue (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="annual_revenue" id="coRevenue" class="form-control" min="0" step="0.01" placeholder="0.00">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="coNotes" class="form-control" rows="3"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Company</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delCoForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delCoId"></form>

<?php
$extraJs = <<<'JS'
<script>
const industries = <?= json_encode($industries) ?>;
function openAdd() {
  document.getElementById('coModalTitle').innerHTML = '<i class="fas fa-building me-2"></i>Add Company';
  ['coId','coName','coWebsite','coPhone','coEmail','coAddress','coCity','coCountry','coEmployees','coRevenue','coNotes'].forEach(i => document.getElementById(i).value = i==='coId' ? '0' : '');
  document.getElementById('coIndustry').value = '';
  document.getElementById('coStatus').value = 'active';
}
function openEdit(co) {
  document.getElementById('coModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Company';
  document.getElementById('coId').value       = co.id;
  document.getElementById('coName').value     = co.name || '';
  document.getElementById('coIndustry').value = co.industry || '';
  document.getElementById('coWebsite').value  = co.website || '';
  document.getElementById('coPhone').value    = co.phone || '';
  document.getElementById('coEmail').value    = co.email || '';
  document.getElementById('coAddress').value  = co.address || '';
  document.getElementById('coCity').value     = co.city || '';
  document.getElementById('coCountry').value  = co.country || '';
  document.getElementById('coStatus').value   = co.status || 'active';
  document.getElementById('coEmployees').value= co.employees || '';
  document.getElementById('coRevenue').value  = co.annual_revenue || '';
  document.getElementById('coNotes').value    = co.notes || '';
  new bootstrap.Modal(document.getElementById('coModal')).show();
}
function delCo(id, name) {
  Swal.fire({title:'Delete Company?',text:name+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r => { if (r.isConfirmed) { document.getElementById('delCoId').value = id; document.getElementById('delCoForm').submit(); } });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
