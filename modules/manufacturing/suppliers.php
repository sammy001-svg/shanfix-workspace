<?php
// ── Manufacturing: Supplier Directory ──────────────────────────
$moduleSlug  = 'manufacturing';
$moduleName  = 'Manufacturing';
$moduleIcon  = 'fas fa-industry';
$moduleColor = '#d35400';
$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'products.php',    'icon' => 'fas fa-box',            'label' => 'Products'],
    ['url' => 'materials.php',   'icon' => 'fas fa-cubes',          'label' => 'Raw Materials'],
    ['url' => 'bom.php',         'icon' => 'fas fa-list-alt',       'label' => 'Bill of Materials'],
    ['url' => 'production.php',  'icon' => 'fas fa-industry',       'label' => 'Production Orders'],
    ['url' => 'workorders.php',  'icon' => 'fas fa-clipboard-list', 'label' => 'Work Orders'],
    ['url' => 'machines.php',    'icon' => 'fas fa-cogs',           'label' => 'Machines'],
    ['url' => 'quality.php',     'icon' => 'fas fa-check-circle',   'label' => 'Quality Control'],
    ['url' => 'suppliers.php',   'icon' => 'fas fa-truck',           'label' => 'Suppliers'],
    ['url' => 'inventory.php',   'icon' => 'fas fa-warehouse',       'label' => 'Inventory'],
    ['url' => 'procurement.php', 'icon' => 'fas fa-shopping-basket', 'label' => 'Procurement'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id            = (int)($_POST['id'] ?? 0);
        $name          = sanitize($_POST['name'] ?? '');
        $contact       = sanitize($_POST['contact'] ?? '');
        $email         = sanitize($_POST['email'] ?? '');
        $phone         = sanitize($_POST['phone'] ?? '');
        $category      = sanitize($_POST['category'] ?? '');
        $leadTimeDays  = (int)($_POST['lead_time_days'] ?? 0);
        $paymentTerms  = sanitize($_POST['payment_terms'] ?? '');
        $rating        = min(5, max(1, (int)($_POST['rating'] ?? 3)));
        $status        = in_array($_POST['status'] ?? '', ['active','inactive','blacklisted']) ? $_POST['status'] : 'active';
        $notes         = sanitize($_POST['notes'] ?? '');

        if ($id) {
            $pdo->prepare("UPDATE mfg_suppliers SET name=?,contact_person=?,email=?,phone=?,category=?,lead_time_days=?,payment_terms=?,rating=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$name,$contact,$email,$phone,$category,$leadTimeDays,$paymentTerms,$rating,$status,$notes,$id,$orgId]);
            setFlash('success', 'Supplier updated.');
        } else {
            $pdo->prepare("INSERT INTO mfg_suppliers (org_id,name,contact_person,email,phone,category,lead_time_days,payment_terms,rating,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$name,$contact,$email,$phone,$category,$leadTimeDays,$paymentTerms,$rating,$status,$notes]);
            setFlash('success', 'Supplier added.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM mfg_suppliers WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Supplier removed.');
    }
    redirect('suppliers.php');
}

// Fetch suppliers
$filter   = sanitize($_GET['status'] ?? '');
$search   = sanitize($_GET['q'] ?? '');
$sql      = "SELECT * FROM mfg_suppliers WHERE org_id=?";
$params   = [$orgId];
if ($filter) { $sql .= " AND status=?"; $params[] = $filter; }
if ($search) { $sql .= " AND (name LIKE ? OR category LIKE ? OR contact_person LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY name ASC";
$suppliers = $pdo->prepare($sql);
$suppliers->execute($params);
$suppliers = $suppliers->fetchAll();

// Stats
$totalSuppliers  = countRows($pdo, 'mfg_suppliers', 'org_id=?', [$orgId]);
$activeSuppliers = countRows($pdo, 'mfg_suppliers', 'org_id=? AND status=?', [$orgId,'active']);
$stmt = $pdo->prepare("SELECT ROUND(AVG(rating),1) FROM mfg_suppliers WHERE org_id=?"); $stmt->execute([$orgId]);
$avgRating = (float)$stmt->fetchColumn();

// Edit prefill
$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM mfg_suppliers WHERE id=? AND org_id=?");
    $stmt->execute([(int)$_GET['edit'], $orgId]);
    $editRow = $stmt->fetch();
}

$ratingStars = function(int $r): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<i class="fas fa-star' . ($i <= $r ? '' : '-o') . ' text-' . ($i <= $r ? 'warning' : 'muted') . ' small"></i>';
    }
    return $out;
};
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-truck me-2" style="color:<?= $moduleColor ?>"></i>Supplier Directory</h4>
    <p class="text-muted mb-0">Manage raw material suppliers, ratings, and payment terms</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#supplierModal" onclick="resetForm()">
    <i class="fas fa-plus me-1"></i>Add Supplier
  </button>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(211,84,0,0.12);color:#d35400"><i class="fas fa-truck"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalSuppliers ?></div><div class="stat-label">Total Suppliers</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeSuppliers ?></div><div class="stat-label">Active Suppliers</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-star"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $avgRating ?: '—' ?></div><div class="stat-label">Avg Rating (/ 5)</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-5">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, category, contact…" value="<?= e($search) ?>">
      </div>
      <div class="col-sm-3">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['active','inactive','blacklisted'] as $s): ?>
          <option value="<?= $s ?>" <?= $filter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if ($filter||$search): ?><div class="col-auto"><a href="suppliers.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Supplier Name</th>
            <th>Category</th>
            <th>Contact</th>
            <th class="text-center">Lead Time</th>
            <th>Payment Terms</th>
            <th class="text-center">Rating</th>
            <th class="text-center">Status</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($suppliers)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No suppliers found.</td></tr>
          <?php else: foreach ($suppliers as $s): ?>
          <tr>
            <td class="ps-3 fw-semibold"><?= e($s['name']) ?></td>
            <td><span class="badge bg-secondary"><?= e($s['category']) ?></span></td>
            <td>
              <div><?= e($s['contact_person']) ?></div>
              <small class="text-muted"><?= e($s['phone']) ?></small>
            </td>
            <td class="text-center"><?= (int)$s['lead_time_days'] ?> days</td>
            <td><?= e($s['payment_terms']) ?></td>
            <td class="text-center"><?= $ratingStars((int)$s['rating']) ?></td>
            <td class="text-center">
              <?php
              $sc = match($s['status']) { 'active'=>'success','inactive'=>'secondary','blacklisted'=>'danger', default=>'secondary' };
              ?>
              <span class="badge bg-<?= $sc ?>"><?= ucfirst($s['status']) ?></span>
            </td>
            <td class="text-end pe-3">
              <a href="suppliers.php?edit=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#supplierModal"
                 onclick="fillForm(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
              </a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this supplier?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
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

<!-- Supplier Modal -->
<div class="modal fade" id="supplierModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-truck me-2"></i><span id="modalTitle">Add Supplier</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="fId" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="fName" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Category</label>
              <input type="text" name="category" id="fCategory" class="form-control" list="catList" placeholder="e.g. Chemicals, Metals">
              <datalist id="catList">
                <option value="Raw Chemicals"><option value="Metals & Alloys"><option value="Plastics">
                <option value="Electronics"><option value="Packaging"><option value="Spare Parts">
              </datalist>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Contact Person</label>
              <input type="text" name="contact" id="fContact" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="fEmail" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="fPhone" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Lead Time (days)</label>
              <input type="number" name="lead_time_days" id="fLeadTime" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Rating (1–5)</label>
              <select name="rating" id="fRating" class="form-select">
                <?php for ($i=1;$i<=5;$i++): ?>
                <option value="<?= $i ?>"><?= $i ?> Star<?= $i>1?'s':'' ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Payment Terms</label>
              <input type="text" name="payment_terms" id="fPayTerms" class="form-control" list="termsList" placeholder="e.g. Net 30, COD">
              <datalist id="termsList">
                <option value="Cash on Delivery"><option value="Net 15"><option value="Net 30">
                <option value="Net 60"><option value="50% Advance"><option value="Letter of Credit">
              </datalist>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="fStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="blacklisted">Blacklisted</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Supplier</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function resetForm() {
  document.getElementById('modalTitle').textContent = 'Add Supplier';
  document.getElementById('fId').value      = '0';
  document.getElementById('fName').value    = '';
  document.getElementById('fCategory').value= '';
  document.getElementById('fContact').value = '';
  document.getElementById('fEmail').value   = '';
  document.getElementById('fPhone').value   = '';
  document.getElementById('fLeadTime').value= '0';
  document.getElementById('fRating').value  = '3';
  document.getElementById('fPayTerms').value= '';
  document.getElementById('fStatus').value  = 'active';
  document.getElementById('fNotes').value   = '';
}
function fillForm(s) {
  document.getElementById('modalTitle').textContent = 'Edit Supplier';
  document.getElementById('fId').value       = s.id;
  document.getElementById('fName').value     = s.name;
  document.getElementById('fCategory').value = s.category;
  document.getElementById('fContact').value  = s.contact_person;
  document.getElementById('fEmail').value    = s.email;
  document.getElementById('fPhone').value    = s.phone;
  document.getElementById('fLeadTime').value = s.lead_time_days;
  document.getElementById('fRating').value   = s.rating;
  document.getElementById('fPayTerms').value = s.payment_terms;
  document.getElementById('fStatus').value   = s.status;
  document.getElementById('fNotes').value    = s.notes;
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
