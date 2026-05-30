<?php
// ── CARYARD: Customer Inquiries / Leads ────────────────────────
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id            = (int)($_POST['id'] ?? 0);
        $vehicleId     = (int)($_POST['vehicle_id']       ?? 0) ?: null;
        $customerName  = sanitize($_POST['customer_name']  ?? '');
        $customerPhone = sanitize($_POST['customer_phone'] ?? '');
        $customerEmail = sanitize($_POST['customer_email'] ?? '');
        $inquiryDate   = $_POST['inquiry_date']            ?? date('Y-m-d');
        $budget        = (float)($_POST['budget']          ?? 0) ?: null;
        $notes         = sanitize($_POST['notes']          ?? '');
        $source        = sanitize($_POST['source']         ?? '');
        $status        = in_array($_POST['status'] ?? '', ['new','contacted','qualified','closed']) ? $_POST['status'] : 'new';

        if (!$customerName) {
            setFlash('danger', 'Customer name is required.');
            redirect('inquiries.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE caryard_inquiries SET vehicle_id=?,customer_name=?,customer_phone=?,customer_email=?,inquiry_date=?,budget=?,notes=?,source=?,status=? WHERE id=? AND org_id=?")
                ->execute([$vehicleId, $customerName, $customerPhone, $customerEmail, $inquiryDate, $budget, $notes, $source, $status, $id, $orgId]);
            setFlash('success', 'Inquiry updated.');
            logActivity('update', 'caryard', "Updated inquiry #$id");
        } else {
            $pdo->prepare("INSERT INTO caryard_inquiries (org_id,vehicle_id,customer_name,customer_phone,customer_email,inquiry_date,budget,notes,source,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $vehicleId, $customerName, $customerPhone, $customerEmail, $inquiryDate, $budget, $notes, $source, $status]);
            setFlash('success', "Inquiry from $customerName logged.");
            logActivity('create', 'caryard', "New inquiry: $customerName");
        }
        redirect('inquiries.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM caryard_inquiries WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Inquiry deleted.');
        logActivity('delete', 'caryard', "Deleted inquiry #$id");
        redirect('inquiries.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterStatus = $_GET['status'] ?? '';
$where  = 'i.org_id = ?';
$params = [$orgId];
if ($filterStatus) { $where .= ' AND i.status = ?'; $params[] = $filterStatus; }

$inquiries = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.*, CONCAT(v.make,' ',v.model,' (',v.year,')') AS vehicle_label
        FROM caryard_inquiries i
        LEFT JOIN caryard_vehicles v ON i.vehicle_id = v.id
        WHERE $where ORDER BY i.created_at DESC
    ");
    $stmt->execute($params);
    $inquiries = $stmt->fetchAll();
} catch (Exception $e) {}

$vehicles = [];
try {
    $stmt = $pdo->prepare("SELECT id, stock_no, make, model, year FROM caryard_vehicles WHERE org_id=? AND status='available' ORDER BY make,model");
    $stmt->execute([$orgId]);
    $vehicles = $stmt->fetchAll();
} catch (Exception $e) {}

$newCount       = countRows('caryard_inquiries', "org_id=? AND status='new'",       [$orgId]);
$contactedCount = countRows('caryard_inquiries', "org_id=? AND status='contacted'", [$orgId]);
$qualifiedCount = countRows('caryard_inquiries', "org_id=? AND status='qualified'", [$orgId]);
$closedCount    = countRows('caryard_inquiries', "org_id=? AND status='closed'",    [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-comments me-2" style="color:<?= $moduleColor ?>"></i>Customer Inquiries</h4>
    <p class="text-muted mb-0">Track leads and buyer inquiries for your vehicles</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#inqModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Log Inquiry
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-bell"></i></div>
      <div><div class="stat-value"><?= $newCount ?></div><div class="stat-label">New Inquiries</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-phone"></i></div>
      <div><div class="stat-value"><?= $contactedCount ?></div><div class="stat-label">Contacted</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-star"></i></div>
      <div><div class="stat-value"><?= $qualifiedCount ?></div><div class="stat-label">Qualified</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-times-circle"></i></div>
      <div><div class="stat-value"><?= $closedCount ?></div><div class="stat-label">Closed</div></div></div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="new"       <?= $filterStatus==='new'       ?'selected':'' ?>>New</option>
          <option value="contacted" <?= $filterStatus==='contacted' ?'selected':'' ?>>Contacted</option>
          <option value="qualified" <?= $filterStatus==='qualified' ?'selected':'' ?>>Qualified</option>
          <option value="closed"    <?= $filterStatus==='closed'    ?'selected':'' ?>>Closed</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="inquiries.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-comments me-2" style="color:<?= $moduleColor ?>"></i>Inquiry List</h6>
    <span class="badge bg-secondary"><?= count($inquiries) ?> inquiries</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Customer</th>
            <th>Vehicle of Interest</th>
            <th>Budget</th>
            <th>Source</th>
            <th>Date</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($inquiries)): ?>
          <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-comments fa-2x mb-2 d-block"></i>No inquiries logged yet.</td></tr>
          <?php else: foreach ($inquiries as $inq): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($inq['customer_name']) ?></div>
              <div class="small text-muted"><?= e($inq['customer_phone']) ?><?= $inq['customer_email'] ? ' · '.e($inq['customer_email']) : '' ?></div>
            </td>
            <td class="small"><?= $inq['vehicle_label'] ? e($inq['vehicle_label']) : '<span class="text-muted">Any / Open</span>' ?></td>
            <td class="fw-semibold"><?= $inq['budget'] ? formatCurrency((float)$inq['budget']) : '—' ?></td>
            <td class="small text-muted"><?= e($inq['source'] ?: '—') ?></td>
            <td><?= formatDate($inq['inquiry_date']) ?></td>
            <td><?= statusBadge($inq['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($inq), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this inquiry?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $inq['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="inqModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="inqId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="inqTitle"><i class="fas fa-comments me-2"></i>Log Inquiry</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
              <input type="text" name="customer_name" id="inqName" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="customer_phone" id="inqPhone" class="form-control" placeholder="+254...">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="customer_email" id="inqEmail" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Vehicle of Interest</label>
              <select name="vehicle_id" id="inqVehicle" class="form-select">
                <option value="">Any / Not specified</option>
                <?php foreach ($vehicles as $v): ?>
                <option value="<?= $v['id'] ?>"><?= e($v['stock_no'].' — '.$v['make'].' '.$v['model'].' ('.$v['year'].')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Inquiry Date</label>
              <input type="date" name="inquiry_date" id="inqDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Budget (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="budget" id="inqBudget" class="form-control" step="0.01" min="0" placeholder="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Source</label>
              <input type="text" name="source" id="inqSource" class="form-control" placeholder="Walk-in, Referral, Social Media...">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="inqStatus" class="form-select">
                <option value="new">New</option>
                <option value="contacted">Contacted</option>
                <option value="qualified">Qualified</option>
                <option value="closed">Closed</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="inqNotes" class="form-control" rows="2" placeholder="Additional details..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Inquiry</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("inqTitle").innerHTML = "<i class=\"fas fa-comments me-2\"></i>Log Inquiry";
  document.getElementById("inqId").value      = 0;
  document.getElementById("inqName").value    = "";
  document.getElementById("inqPhone").value   = "";
  document.getElementById("inqEmail").value   = "";
  document.getElementById("inqVehicle").value = "";
  document.getElementById("inqDate").value    = "' . date('Y-m-d') . '";
  document.getElementById("inqBudget").value  = "";
  document.getElementById("inqSource").value  = "";
  document.getElementById("inqStatus").value  = "new";
  document.getElementById("inqNotes").value   = "";
}
function openEdit(i) {
  document.getElementById("inqTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Inquiry";
  document.getElementById("inqId").value      = i.id;
  document.getElementById("inqName").value    = i.customer_name  || "";
  document.getElementById("inqPhone").value   = i.customer_phone || "";
  document.getElementById("inqEmail").value   = i.customer_email || "";
  document.getElementById("inqVehicle").value = i.vehicle_id     || "";
  document.getElementById("inqDate").value    = i.inquiry_date   || "";
  document.getElementById("inqBudget").value  = i.budget         || "";
  document.getElementById("inqSource").value  = i.source         || "";
  document.getElementById("inqStatus").value  = i.status         || "new";
  document.getElementById("inqNotes").value   = i.notes          || "";
  new bootstrap.Modal(document.getElementById("inqModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
