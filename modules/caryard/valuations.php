<?php
// ── CARYARD: Vehicle Valuations & Trade-in Appraisals ──────────
$moduleSlug  = 'caryard';
$moduleName  = 'Car Yard Management';
$moduleIcon  = 'fas fa-car';
$moduleColor = '#e67e22';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'vehicles.php',       'icon' => 'fas fa-car',            'label' => 'Vehicles'],
    ['url' => 'sales.php',          'icon' => 'fas fa-handshake',      'label' => 'Sales'],
    ['url' => 'customers.php',      'icon' => 'fas fa-users',          'label' => 'Customers'],
    ['url' => 'finance.php',        'icon' => 'fas fa-university',     'label' => 'Finance'],
    ['url' => 'testdrives.php',     'icon' => 'fas fa-road',           'label' => 'Test Drives'],
    ['url' => 'services.php',       'icon' => 'fas fa-tools',          'label' => 'Services'],
    ['url' => 'reconditioning.php', 'icon' => 'fas fa-wrench',         'label' => 'Reconditioning'],
    ['url' => 'valuations.php',     'icon' => 'fas fa-search-dollar',  'label' => 'Valuations'],
    ['url' => 'inquiries.php',      'icon' => 'fas fa-comments',       'label' => 'Inquiries'],
    ['url' => 'insurance.php',      'icon' => 'fas fa-shield-alt',     'label' => 'Insurance'],
    ['url' => 'parts.php',          'icon' => 'fas fa-cogs',           'label' => 'Parts & Spares'],
    ['url' => 'delivery.php',       'icon' => 'fas fa-truck-loading',  'label' => 'Deliveries'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id             = (int)($_POST['id'] ?? 0);
        $customerName   = sanitize($_POST['customer_name']  ?? '');
        $customerPhone  = sanitize($_POST['customer_phone'] ?? '');
        $make           = sanitize($_POST['make']           ?? '');
        $model          = sanitize($_POST['model']          ?? '');
        $year           = (int)($_POST['year']               ?? date('Y'));
        $registration   = sanitize($_POST['registration']   ?? '');
        $mileage        = (int)($_POST['mileage']            ?? 0) ?: null;
        $conditionGrade = sanitize($_POST['condition_grade'] ?? '');
        $marketValue    = (float)($_POST['market_value']     ?? 0) ?: null;
        $offerValue     = (float)($_POST['offer_value']      ?? 0) ?: null;
        $valuationDate  = $_POST['valuation_date']           ?? date('Y-m-d');
        $valuator       = sanitize($_POST['valuator']        ?? '');
        $status         = in_array($_POST['status'] ?? '', ['pending','accepted','rejected','expired']) ? $_POST['status'] : 'pending';
        $notes          = sanitize($_POST['notes']           ?? '');

        if (!$customerName || !$make || !$model) {
            setFlash('danger', 'Customer name, make, and model are required.');
            redirect('valuations.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE caryard_valuations SET customer_name=?,customer_phone=?,make=?,model=?,year=?,registration=?,mileage=?,condition_grade=?,market_value=?,offer_value=?,valuation_date=?,valuator=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$customerName, $customerPhone, $make, $model, $year, $registration, $mileage, $conditionGrade, $marketValue, $offerValue, $valuationDate, $valuator, $status, $notes, $id, $orgId]);
            setFlash('success', 'Valuation updated.');
            logActivity('update', 'caryard', "Updated valuation #$id");
        } else {
            $pdo->prepare("INSERT INTO caryard_valuations (org_id,customer_name,customer_phone,make,model,year,registration,mileage,condition_grade,market_value,offer_value,valuation_date,valuator,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $customerName, $customerPhone, $make, $model, $year, $registration, $mileage, $conditionGrade, $marketValue, $offerValue, $valuationDate, $valuator, $status, $notes]);
            setFlash('success', "Valuation for {$customerName}'s $make $model recorded.");
            logActivity('create', 'caryard', "New valuation: $make $model ($year)");
        }
        redirect('valuations.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM caryard_valuations WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Valuation deleted.');
        logActivity('delete', 'caryard', "Deleted valuation #$id");
        redirect('valuations.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterStatus = $_GET['status'] ?? '';
$where  = 'org_id = ?';
$params = [$orgId];
if ($filterStatus) { $where .= ' AND status = ?'; $params[] = $filterStatus; }

$valuations = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM caryard_valuations WHERE $where ORDER BY valuation_date DESC");
    $stmt->execute($params);
    $valuations = $stmt->fetchAll();
} catch (Exception $e) {}

$pendingCount  = countRows('caryard_valuations', "org_id=? AND status='pending'",  [$orgId]);
$acceptedCount = countRows('caryard_valuations', "org_id=? AND status='accepted'", [$orgId]);
$rejectedCount = countRows('caryard_valuations', "org_id=? AND status='rejected'", [$orgId]);

$conditionOptions = ['A' => 'Grade A — Excellent', 'B' => 'Grade B — Good', 'C' => 'Grade C — Fair', 'D' => 'Grade D — Poor'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-search-dollar me-2" style="color:<?= $moduleColor ?>"></i>Valuations & Trade-ins</h4>
    <p class="text-muted mb-0">Record vehicle appraisals, trade-in offers, and condition assessments</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#valModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>New Valuation
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingCount ?></div><div class="stat-label">Pending Valuations</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $acceptedCount ?></div><div class="stat-label">Accepted / Trade-ins</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-times-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $rejectedCount ?></div><div class="stat-label">Rejected</div></div>
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="pending"  <?= $filterStatus==='pending'  ?'selected':'' ?>>Pending</option>
          <option value="accepted" <?= $filterStatus==='accepted' ?'selected':'' ?>>Accepted</option>
          <option value="rejected" <?= $filterStatus==='rejected' ?'selected':'' ?>>Rejected</option>
          <option value="expired"  <?= $filterStatus==='expired'  ?'selected':'' ?>>Expired</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="valuations.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Valuations Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-search-dollar me-2" style="color:<?= $moduleColor ?>"></i>Valuation Records</h6>
    <span class="badge bg-secondary"><?= count($valuations) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Customer</th>
            <th>Vehicle</th>
            <th>Year</th>
            <th>Mileage</th>
            <th>Grade</th>
            <th class="text-end">Market Value</th>
            <th class="text-end">Offer Value</th>
            <th>Date</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($valuations)): ?>
          <tr><td colspan="10" class="text-center py-5 text-muted">
            <i class="fas fa-search-dollar fa-2x mb-2 d-block"></i>No valuations recorded yet.
          </td></tr>
          <?php else: foreach ($valuations as $v): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($v['customer_name']) ?></div>
              <div class="small text-muted"><?= e($v['customer_phone'] ?: '') ?></div>
            </td>
            <td>
              <div class="fw-semibold"><?= e($v['make'].' '.$v['model']) ?></div>
              <?php if ($v['registration']): ?><div class="small text-muted"><?= e($v['registration']) ?></div><?php endif; ?>
            </td>
            <td><?= $v['year'] ?></td>
            <td class="small"><?= $v['mileage'] ? number_format($v['mileage']).' km' : '—' ?></td>
            <td>
              <?php if ($v['condition_grade']): ?>
              <span class="badge bg-light text-dark border"><?= e($conditionOptions[$v['condition_grade']] ?? $v['condition_grade']) ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="text-end"><?= $v['market_value'] ? formatCurrency((float)$v['market_value']) : '—' ?></td>
            <td class="text-end fw-semibold" style="color:<?= $moduleColor ?>"><?= $v['offer_value'] ? formatCurrency((float)$v['offer_value']) : '—' ?></td>
            <td><?= formatDate($v['valuation_date']) ?></td>
            <td><?= statusBadge($v['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEdit(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this valuation?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $v['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="valModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="valId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="valTitle"><i class="fas fa-search-dollar me-2"></i>New Valuation</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
              <input type="text" name="customer_name" id="valCustomer" class="form-control" required placeholder="e.g. Peter Njoroge">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Customer Phone</label>
              <input type="text" name="customer_phone" id="valPhone" class="form-control" placeholder="+254...">
            </div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">Make <span class="text-danger">*</span></label>
              <input type="text" name="make" id="valMake" class="form-control" required placeholder="e.g. Toyota">
            </div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">Model <span class="text-danger">*</span></label>
              <input type="text" name="model" id="valModel" class="form-control" required placeholder="e.g. Harrier">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Year</label>
              <input type="number" name="year" id="valYear" class="form-control" min="1980" max="<?= date('Y') ?>" value="<?= date('Y') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Registration Plate</label>
              <input type="text" name="registration" id="valReg" class="form-control" placeholder="e.g. KDJ 123A">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Mileage (km)</label>
              <input type="number" name="mileage" id="valMileage" class="form-control" min="0" placeholder="e.g. 85000">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Condition Grade</label>
              <select name="condition_grade" id="valGrade" class="form-select">
                <option value="">— Select Grade —</option>
                <?php foreach ($conditionOptions as $k => $label): ?>
                <option value="<?= $k ?>"><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Market Value (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="market_value" id="valMarket" class="form-control" step="0.01" min="0" placeholder="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Our Offer (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="offer_value" id="valOffer" class="form-control" step="0.01" min="0" placeholder="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Valuation Date</label>
              <input type="date" name="valuation_date" id="valDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Valuator / Appraiser</label>
              <input type="text" name="valuator" id="valValuator" class="form-control" placeholder="Staff name or agency">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="valStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="accepted">Accepted</option>
                <option value="rejected">Rejected</option>
                <option value="expired">Expired</option>
              </select>
            </div>
            <div class="col-md-4">
              <!-- spacer -->
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="valNotes" class="form-control" rows="2" placeholder="Condition details, damage notes, inspection comments..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Valuation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("valTitle").innerHTML = "<i class=\"fas fa-search-dollar me-2\"></i>New Valuation";
  document.getElementById("valId").value       = 0;
  document.getElementById("valCustomer").value = "";
  document.getElementById("valPhone").value    = "";
  document.getElementById("valMake").value     = "";
  document.getElementById("valModel").value    = "";
  document.getElementById("valYear").value     = "' . date('Y') . '";
  document.getElementById("valReg").value      = "";
  document.getElementById("valMileage").value  = "";
  document.getElementById("valGrade").value    = "";
  document.getElementById("valMarket").value   = "";
  document.getElementById("valOffer").value    = "";
  document.getElementById("valDate").value     = "' . date('Y-m-d') . '";
  document.getElementById("valValuator").value = "";
  document.getElementById("valStatus").value   = "pending";
  document.getElementById("valNotes").value    = "";
}
function openEdit(v) {
  document.getElementById("valTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Valuation";
  document.getElementById("valId").value       = v.id;
  document.getElementById("valCustomer").value = v.customer_name  || "";
  document.getElementById("valPhone").value    = v.customer_phone || "";
  document.getElementById("valMake").value     = v.make           || "";
  document.getElementById("valModel").value    = v.model          || "";
  document.getElementById("valYear").value     = v.year           || "";
  document.getElementById("valReg").value      = v.registration   || "";
  document.getElementById("valMileage").value  = v.mileage        || "";
  document.getElementById("valGrade").value    = v.condition_grade|| "";
  document.getElementById("valMarket").value   = v.market_value   || "";
  document.getElementById("valOffer").value    = v.offer_value    || "";
  document.getElementById("valDate").value     = v.valuation_date || "";
  document.getElementById("valValuator").value = v.valuator       || "";
  document.getElementById("valStatus").value   = v.status         || "pending";
  document.getElementById("valNotes").value    = v.notes          || "";
  new bootstrap.Modal(document.getElementById("valModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
