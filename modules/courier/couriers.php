<?php
$moduleSlug  = 'courier';
$moduleName  = 'Courier Management';
$moduleIcon  = 'fas fa-shipping-fast';
$moduleColor = '#1565c0';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'couriers.php',   'icon' => 'fas fa-box',             'label' => 'Couriers'],
    ['url' => 'tracking.php',   'icon' => 'fas fa-map-marker-alt',  'label' => 'Tracking'],
    ['url' => 'manifest.php',   'icon' => 'fas fa-clipboard-list',  'label' => 'Manifests'],
    ['url' => 'delivery.php',   'icon' => 'fas fa-truck',           'label' => 'Deliveries'],
    ['url' => 'routes.php',     'icon' => 'fas fa-route',           'label' => 'Routes'],
    ['url' => 'payments.php',   'icon' => 'fas fa-credit-card',     'label' => 'Payments'],
    ['url' => 'agents.php',     'icon' => 'fas fa-user-tie',        'label' => 'Agents'],
    ['url' => 'agreements.php', 'icon' => 'fas fa-file-contract',   'label' => 'Agreements'],
    ['url' => 'setup.php',      'icon' => 'fas fa-cog',             'label' => 'Setup'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
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
        $id             = (int)($_POST['id'] ?? 0);
        $senderName     = sanitize($_POST['sender_name'] ?? '');
        $senderEmail    = sanitize($_POST['sender_email'] ?? '');
        $senderPhone    = sanitize($_POST['sender_phone'] ?? '');
        $senderAddress  = sanitize($_POST['sender_address'] ?? '');
        $receiverName   = sanitize($_POST['receiver_name'] ?? '');
        $receiverEmail  = sanitize($_POST['receiver_email'] ?? '');
        $receiverPhone  = sanitize($_POST['receiver_phone'] ?? '');
        $receiverAddress= sanitize($_POST['receiver_address'] ?? '');
        $categoryId     = (int)($_POST['category_id'] ?? 0) ?: null;
        $serviceTypeId  = (int)($_POST['service_type_id'] ?? 0) ?: null;
        $branchId       = (int)($_POST['branch_id'] ?? 0) ?: null;
        $agentId        = (int)($_POST['agent_id'] ?? 0) ?: null;
        $weight         = $_POST['weight'] !== '' ? (float)$_POST['weight'] : null;
        $length         = $_POST['length_cm'] !== '' ? (float)$_POST['length_cm'] : null;
        $width          = $_POST['width_cm'] !== '' ? (float)$_POST['width_cm'] : null;
        $height         = $_POST['height_cm'] !== '' ? (float)$_POST['height_cm'] : null;
        $description    = sanitize($_POST['description'] ?? '');
        $declaredValue  = (float)($_POST['declared_value'] ?? 0);
        $price          = (float)($_POST['price'] ?? 0);
        $status         = sanitize($_POST['status'] ?? 'pending');
        $approvalStatus = in_array($_POST['approval_status'] ?? '', ['pending','approved','rejected']) ? $_POST['approval_status'] : 'approved';
        $notes          = sanitize($_POST['notes'] ?? '');
        $pickupDate     = $_POST['pickup_date'] ?: null;
        $expectedDelivery = $_POST['expected_delivery'] ?: null;
        $actualDelivery = $_POST['actual_delivery'] ?: null;

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE couriers SET sender_name=?, sender_email=?, sender_phone=?, sender_address=?,
                receiver_name=?, receiver_email=?, receiver_phone=?, receiver_address=?,
                category_id=?, service_type_id=?, branch_id=?, agent_id=?,
                weight=?, length_cm=?, width_cm=?, height_cm=?,
                description=?, declared_value=?, price=?, status=?, approval_status=?,
                notes=?, pickup_date=?, expected_delivery=?, actual_delivery=?
                WHERE id=? AND org_id=?");
            $stmt->execute([
                $senderName, $senderEmail, $senderPhone, $senderAddress,
                $receiverName, $receiverEmail, $receiverPhone, $receiverAddress,
                $categoryId, $serviceTypeId, $branchId, $agentId,
                $weight, $length, $width, $height,
                $description, $declaredValue, $price, $status, $approvalStatus,
                $notes, $pickupDate, $expectedDelivery, $actualDelivery,
                $id, $orgId
            ]);
            // Log tracking history if status changed
            $prev = $pdo->prepare("SELECT status FROM couriers WHERE id=?");
            $prev->execute([$id]);
            setFlash('success', 'Courier updated successfully.');
        } else {
            // Generate unique tracking ID
            do {
                $trackingId = 'COU-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $chk = $pdo->prepare("SELECT id FROM couriers WHERE tracking_id=?");
                $chk->execute([$trackingId]);
            } while ($chk->fetch());

            $stmt = $pdo->prepare("INSERT INTO couriers (org_id, tracking_id, sender_name, sender_email, sender_phone, sender_address,
                receiver_name, receiver_email, receiver_phone, receiver_address,
                category_id, service_type_id, branch_id, agent_id,
                weight, length_cm, width_cm, height_cm,
                description, declared_value, price, status, approval_status, source,
                notes, pickup_date, expected_delivery, actual_delivery)
                VALUES (?,?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?,?,'admin', ?,?,?,?)");
            $stmt->execute([
                $orgId, $trackingId,
                $senderName, $senderEmail, $senderPhone, $senderAddress,
                $receiverName, $receiverEmail, $receiverPhone, $receiverAddress,
                $categoryId, $serviceTypeId, $branchId, $agentId,
                $weight, $length, $width, $height,
                $description, $declaredValue, $price, $status, $approvalStatus,
                $notes, $pickupDate, $expectedDelivery, $actualDelivery
            ]);
            $newId = $pdo->lastInsertId();
            // Add initial tracking history entry
            $pdo->prepare("INSERT INTO courier_tracking_history (org_id, courier_id, stage_code, stage_name, location, notes, created_by)
                VALUES (?,?,?,?,?,?,?)")->execute([
                $orgId, $newId, $status, strtoupper(str_replace('_',' ',$status)), '', 'Courier created', $user['id']
            ]);
            setFlash('success', "Courier $trackingId created successfully.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'courier', "Courier: $senderName → $receiverName");
        redirect('couriers.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM courier_tracking_history WHERE courier_id=? AND org_id=?")->execute([$id, $orgId]);
        $pdo->prepare("DELETE FROM couriers WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Courier deleted.');
        redirect('couriers.php');
    }

    if ($action === 'approve') {
        $id     = (int)$_POST['id'];
        $newSt  = sanitize($_POST['approval_status'] ?? 'approved');
        $pdo->prepare("UPDATE couriers SET approval_status=? WHERE id=? AND org_id=?")->execute([$newSt, $id, $orgId]);
        setFlash('success', 'Approval status updated.');
        redirect('couriers.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch filter options
$branches     = [];
$serviceTypes = [];
$categories   = [];
$agents       = [];
try {
    $branches     = $pdo->prepare("SELECT id,name FROM courier_branches WHERE org_id=? AND status='active' ORDER BY name")->execute([$orgId]) ? $pdo->query("SELECT id,name FROM courier_branches WHERE org_id=$orgId AND status='active' ORDER BY name")->fetchAll() : [];
    $st = $pdo->prepare("SELECT id,name FROM courier_branches WHERE org_id=? AND status='active' ORDER BY name"); $st->execute([$orgId]); $branches = $st->fetchAll();
    $st = $pdo->prepare("SELECT id,name FROM courier_service_types WHERE org_id=? AND status='active' ORDER BY name"); $st->execute([$orgId]); $serviceTypes = $st->fetchAll();
    $st = $pdo->prepare("SELECT id,name FROM courier_categories WHERE org_id=? AND status='active' ORDER BY name"); $st->execute([$orgId]); $categories = $st->fetchAll();
    $st = $pdo->prepare("SELECT id,name FROM courier_agents WHERE org_id=? AND status='active' ORDER BY name"); $st->execute([$orgId]); $agents = $st->fetchAll();
} catch (Exception $e) {}

// Filters
$fStatus   = $_GET['status'] ?? '';
$fBranch   = (int)($_GET['branch_id'] ?? 0);
$fApproval = $_GET['approval'] ?? '';
$fSearch   = trim($_GET['search'] ?? '');

$where  = 'c.org_id = ?';
$params = [$orgId];
if ($fStatus !== '')  { $where .= ' AND c.status = ?'; $params[] = $fStatus; }
if ($fBranch > 0)     { $where .= ' AND c.branch_id = ?'; $params[] = $fBranch; }
if ($fApproval !== '') { $where .= ' AND c.approval_status = ?'; $params[] = $fApproval; }
if ($fSearch !== '')  { $where .= ' AND (c.tracking_id LIKE ? OR c.sender_name LIKE ? OR c.receiver_name LIKE ?)'; $s = "%$fSearch%"; $params = array_merge($params, [$s,$s,$s]); }

$couriersList = [];
try {
    $stmt = $pdo->prepare("SELECT c.*, st.name AS service_name, b.name AS branch_name, a.name AS agent_name, cat.name AS cat_name
        FROM couriers c
        LEFT JOIN courier_service_types st ON c.service_type_id = st.id
        LEFT JOIN courier_branches b ON c.branch_id = b.id
        LEFT JOIN courier_agents a ON c.agent_id = a.id
        LEFT JOIN courier_categories cat ON c.category_id = cat.id
        WHERE $where ORDER BY c.created_at DESC");
    $stmt->execute($params);
    $couriersList = $stmt->fetchAll();
} catch (Exception $e) {}

if (isset($_GET['fetch_details'])) {
    $cid  = (int)$_GET['fetch_details'];
    $stmt = $pdo->prepare("SELECT * FROM couriers WHERE id=? AND org_id=?");
    $stmt->execute([$cid, $orgId]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { header('Content-Type: application/json'); echo json_encode($row); exit; }
}

$statuses = ['pending','processing','picked_up','in_transit','out_for_delivery','delivered','failed','returned','cancelled'];
$statusColors = ['pending'=>'warning','processing'=>'info','picked_up'=>'primary','in_transit'=>'primary','out_for_delivery'=>'info','delivered'=>'success','failed'=>'danger','returned'=>'secondary','cancelled'=>'dark'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-box me-2" style="color:<?= $moduleColor ?>"></i>Courier Parcels</h4>
    <p class="text-muted mb-0">Process, track, and manage all courier orders from pickup to delivery</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#courierModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>New Courier</button>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Tracking ID / Name..." value="<?= e($fSearch) ?>">
      </div>
      <div class="col-sm-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= strtoupper(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <select name="branch_id" class="form-select form-select-sm">
          <option value="">All Branches</option>
          <?php foreach ($branches as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $fBranch === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <select name="approval" class="form-select form-select-sm">
          <option value="">All Approvals</option>
          <option value="pending" <?= $fApproval === 'pending' ? 'selected' : '' ?>>Pending Approval</option>
          <option value="approved" <?= $fApproval === 'approved' ? 'selected' : '' ?>>Approved</option>
          <option value="rejected" <?= $fApproval === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="couriers.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-box me-2 text-primary"></i>Courier List (<?= count($couriersList) ?> records)</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Tracking ID</th>
            <th>Sender</th>
            <th>Receiver</th>
            <th>Service / Category</th>
            <th>Agent / Branch</th>
            <th>Price</th>
            <th>Delivery Status</th>
            <th>Approval</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($couriersList)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-box fa-2x mb-2 d-block"></i>No courier records found.</td></tr>
          <?php else: foreach ($couriersList as $c):
            $sc  = $statusColors[$c['status']] ?? 'secondary';
            $apc = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$c['approval_status']] ?? 'secondary';
          ?>
          <tr>
            <td><span class="badge bg-dark font-monospace"><?= e($c['tracking_id']) ?></span></td>
            <td>
              <div class="fw-bold text-dark"><?= e($c['sender_name']) ?></div>
              <small class="text-muted"><?= e($c['sender_phone']) ?></small>
            </td>
            <td>
              <div class="fw-bold text-dark"><?= e($c['receiver_name']) ?></div>
              <small class="text-muted"><?= e($c['receiver_phone']) ?></small>
            </td>
            <td>
              <div><?= e($c['service_name'] ?? '—') ?></div>
              <small class="text-muted"><?= e($c['cat_name'] ?? '—') ?></small>
            </td>
            <td>
              <div><?= e($c['agent_name'] ?? 'Unassigned') ?></div>
              <small class="text-muted"><?= e($c['branch_name'] ?? '—') ?></small>
            </td>
            <td class="fw-bold text-dark"><?= formatCurrency((float)$c['price']) ?></td>
            <td><span class="badge bg-<?= $sc ?>"><?= strtoupper(str_replace('_',' ',$c['status'])) ?></span></td>
            <td><span class="badge bg-<?= $apc ?>"><?= strtoupper($c['approval_status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <a href="tracking.php?courier_id=<?= $c['id'] ?>" class="btn btn-outline-info" title="Track"><i class="fas fa-map-marker-alt"></i></a>
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $c['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delCourier(<?= $c['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Courier Modal -->
<div class="modal fade" id="courierModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="courierId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="courierModalTitle"><i class="fas fa-box me-2"></i>New Courier</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <!-- Sender Info -->
      <div class="col-12"><h6 class="fw-bold border-bottom pb-2" style="color:<?= $moduleColor ?>"><i class="fas fa-user me-2"></i>Sender Information</h6></div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Sender Name <span class="text-danger">*</span></label>
        <input type="text" name="sender_name" id="senderName" class="form-control" required placeholder="Full name">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Sender Phone</label>
        <input type="text" name="sender_phone" id="senderPhone" class="form-control" placeholder="+263...">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Sender Email</label>
        <input type="email" name="sender_email" id="senderEmail" class="form-control" placeholder="sender@email.com">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Sender Address / Pickup Location</label>
        <textarea name="sender_address" id="senderAddress" class="form-control" rows="2" placeholder="Street, City, Country"></textarea>
      </div>
      <!-- Receiver Info -->
      <div class="col-12"><h6 class="fw-bold border-bottom pb-2 mt-2" style="color:<?= $moduleColor ?>"><i class="fas fa-map-marker-alt me-2"></i>Receiver Information</h6></div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Receiver Name <span class="text-danger">*</span></label>
        <input type="text" name="receiver_name" id="receiverName" class="form-control" required placeholder="Full name">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Receiver Phone</label>
        <input type="text" name="receiver_phone" id="receiverPhone" class="form-control" placeholder="+263...">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Receiver Email</label>
        <input type="email" name="receiver_email" id="receiverEmail" class="form-control" placeholder="receiver@email.com">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Delivery Address</label>
        <textarea name="receiver_address" id="receiverAddress" class="form-control" rows="2" placeholder="Street, City, Country"></textarea>
      </div>
      <!-- Package Details -->
      <div class="col-12"><h6 class="fw-bold border-bottom pb-2 mt-2" style="color:<?= $moduleColor ?>"><i class="fas fa-cube me-2"></i>Package Details</h6></div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Category</label>
        <select name="category_id" id="catId" class="form-select">
          <option value="">Select Category</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Service Type</label>
        <select name="service_type_id" id="serviceTypeId" class="form-select">
          <option value="">Select Service</option>
          <?php foreach ($serviceTypes as $st): ?>
          <option value="<?= $st['id'] ?>"><?= e($st['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Branch</label>
        <select name="branch_id" id="branchId" class="form-select">
          <option value="">Select Branch</option>
          <?php foreach ($branches as $b): ?>
          <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Assigned Agent</label>
        <select name="agent_id" id="agentId" class="form-select">
          <option value="">Unassigned</option>
          <?php foreach ($agents as $ag): ?>
          <option value="<?= $ag['id'] ?>"><?= e($ag['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold">Weight (kg)</label>
        <input type="number" name="weight" id="pkgWeight" class="form-control" step="0.01" min="0" placeholder="0.00">
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold">Length (cm)</label>
        <input type="number" name="length_cm" id="pkgLength" class="form-control" step="0.1" min="0" placeholder="0.0">
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold">Width (cm)</label>
        <input type="number" name="width_cm" id="pkgWidth" class="form-control" step="0.1" min="0" placeholder="0.0">
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold">Height (cm)</label>
        <input type="number" name="height_cm" id="pkgHeight" class="form-control" step="0.1" min="0" placeholder="0.0">
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold">Declared Value</label>
        <input type="number" name="declared_value" id="declaredValue" class="form-control" step="0.01" min="0" placeholder="0.00">
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold">Shipping Price <span class="text-danger">*</span></label>
        <input type="number" name="price" id="pkgPrice" class="form-control" step="0.01" min="0" placeholder="0.00" required>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Package Description / Contents</label>
        <textarea name="description" id="pkgDesc" class="form-control" rows="2" placeholder="e.g. Electronic devices, fragile — handle with care"></textarea>
      </div>
      <!-- Scheduling -->
      <div class="col-12"><h6 class="fw-bold border-bottom pb-2 mt-2" style="color:<?= $moduleColor ?>"><i class="fas fa-calendar me-2"></i>Scheduling & Status</h6></div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Pickup Date</label>
        <input type="date" name="pickup_date" id="pickupDate" class="form-control">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Expected Delivery</label>
        <input type="date" name="expected_delivery" id="expectedDelivery" class="form-control">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Actual Delivery</label>
        <input type="date" name="actual_delivery" id="actualDelivery" class="form-control">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Delivery Status</label>
        <select name="status" id="courierStatus" class="form-select">
          <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>"><?= strtoupper(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Approval Status</label>
        <select name="approval_status" id="approvalStatus" class="form-select">
          <option value="approved">Approved</option>
          <option value="pending">Pending Approval</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>
      <div class="col-md-8">
        <label class="form-label fw-semibold">Internal Notes</label>
        <textarea name="notes" id="courierNotes" class="form-control" rows="2" placeholder="Internal handling notes..."></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Courier</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delCourierForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delCourierId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('courierModalTitle').innerHTML = '<i class="fas fa-box me-2"></i>New Courier';
  document.getElementById('courierId').value = '0';
  ['senderName','senderPhone','senderEmail','senderAddress','receiverName','receiverPhone','receiverEmail','receiverAddress',
   'pkgDesc','courierNotes','pkgWeight','pkgLength','pkgWidth','pkgHeight','declaredValue','pkgPrice'].forEach(id => document.getElementById(id).value = '');
  ['catId','serviceTypeId','branchId','agentId'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('courierStatus').value = 'pending';
  document.getElementById('approvalStatus').value = 'approved';
  document.getElementById('pickupDate').value = new Date().toISOString().slice(0,10);
  document.getElementById('expectedDelivery').value = '';
  document.getElementById('actualDelivery').value = '';
}
function openEdit(id) {
  fetch('couriers.php?fetch_details=' + id)
    .then(r => r.json())
    .then(d => {
      document.getElementById('courierModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Courier — ' + d.tracking_id;
      document.getElementById('courierId').value = d.id;
      document.getElementById('senderName').value = d.sender_name || '';
      document.getElementById('senderPhone').value = d.sender_phone || '';
      document.getElementById('senderEmail').value = d.sender_email || '';
      document.getElementById('senderAddress').value = d.sender_address || '';
      document.getElementById('receiverName').value = d.receiver_name || '';
      document.getElementById('receiverPhone').value = d.receiver_phone || '';
      document.getElementById('receiverEmail').value = d.receiver_email || '';
      document.getElementById('receiverAddress').value = d.receiver_address || '';
      document.getElementById('catId').value = d.category_id || '';
      document.getElementById('serviceTypeId').value = d.service_type_id || '';
      document.getElementById('branchId').value = d.branch_id || '';
      document.getElementById('agentId').value = d.agent_id || '';
      document.getElementById('pkgWeight').value = d.weight || '';
      document.getElementById('pkgLength').value = d.length_cm || '';
      document.getElementById('pkgWidth').value = d.width_cm || '';
      document.getElementById('pkgHeight').value = d.height_cm || '';
      document.getElementById('declaredValue').value = d.declared_value || '';
      document.getElementById('pkgPrice').value = d.price || '';
      document.getElementById('pkgDesc').value = d.description || '';
      document.getElementById('courierStatus').value = d.status || 'pending';
      document.getElementById('approvalStatus').value = d.approval_status || 'approved';
      document.getElementById('courierNotes').value = d.notes || '';
      document.getElementById('pickupDate').value = d.pickup_date || '';
      document.getElementById('expectedDelivery').value = d.expected_delivery || '';
      document.getElementById('actualDelivery').value = d.actual_delivery || '';
      new bootstrap.Modal(document.getElementById('courierModal')).show();
    });
}
function delCourier(id) {
  Swal.fire({
    title: 'Delete Courier?',
    text: 'This will permanently remove the courier and its tracking history.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delCourierId').value = id;
      document.getElementById('delCourierForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
