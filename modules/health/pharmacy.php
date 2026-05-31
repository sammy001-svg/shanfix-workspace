<?php
$moduleSlug  = 'health';
$moduleName  = 'Health & Clinic';
$moduleIcon  = 'fas fa-heartbeat';
$moduleColor = '#e74c3c';
$moduleNav   = [
    ['url'=>'index.php',         'icon'=>'fas fa-tachometer-alt',      'label'=>'Dashboard'],
    ['url'=>'patients.php',      'icon'=>'fas fa-procedures',          'label'=>'Patients'],
    ['url'=>'appointments.php',  'icon'=>'fas fa-calendar-check',      'label'=>'Appointments'],
    ['url'=>'doctors.php',       'icon'=>'fas fa-user-md',             'label'=>'Doctors'],
    ['url'=>'staff.php',         'icon'=>'fas fa-id-badge',            'label'=>'Clinical Staff'],
    ['url'=>'records.php',       'icon'=>'fas fa-file-medical',        'label'=>'Medical Records'],
    ['url'=>'vitals.php',        'icon'=>'fas fa-heartbeat',           'label'=>'Vital Signs'],
    ['url'=>'lab.php',           'icon'=>'fas fa-flask',               'label'=>'Laboratory'],
    ['url'=>'pharmacy.php',      'icon'=>'fas fa-pills',               'label'=>'Pharmacy'],
    ['url'=>'nursing.php',       'icon'=>'fas fa-user-nurse',          'label'=>'Nursing'],
    ['url'=>'wards.php',         'icon'=>'fas fa-bed',                 'label'=>'Wards & Beds'],
    ['url'=>'admissions.php',    'icon'=>'fas fa-hospital-user',       'label'=>'Admissions (IPD)'],
    ['url'=>'surgery.php',       'icon'=>'fas fa-syringe',             'label'=>'Surgery / Theatre'],
    ['url'=>'emergency.php',     'icon'=>'fas fa-ambulance',           'label'=>'Emergency / Triage'],
    ['url'=>'billing.php',       'icon'=>'fas fa-file-invoice-dollar', 'label'=>'Billing'],
    ['url'=>'telemedicine.php',  'icon'=>'fas fa-video',               'label'=>'Telemedicine'],
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-users',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'AI Analytics'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
];

// ── AJAX: fetch medicine for edit ─────────────────────────────────
if (isset($_GET['fetch_med'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_med'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_medicines WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── AJAX: fetch dispense record for view ──────────────────────────
if (isset($_GET['fetch_dispense'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_dispense'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("
            SELECT d.*, m.name AS medicine_name, m.form, m.strength,
                   CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no
            FROM health_dispensing d
            LEFT JOIN health_medicines m ON m.id = d.medicine_id
            LEFT JOIN health_patients p ON p.id = d.patient_id
            WHERE d.id=? AND d.org_id=?
        ");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── AJAX: get medicine price by ID ────────────────────────────────
if (isset($_GET['med_price'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['med_price'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT unit_price, stock_qty, name, form, strength FROM health_medicines WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── POST handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $uid   = (int)$user['id'];
    $action = $_POST['action'] ?? '';

    // ── Save / update medicine ────────────────────────────────────
    if ($action === 'save_med') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = sanitize($_POST['name']         ?? '');
        $generic     = sanitize($_POST['generic_name'] ?? '');
        $category    = sanitize($_POST['category']     ?? '');
        $form        = sanitize($_POST['form']         ?? '');
        $strength    = sanitize($_POST['strength']     ?? '');
        $unit        = sanitize($_POST['unit']         ?? 'Units');
        $unitPrice   = (float)($_POST['unit_price']    ?? 0);
        $stockQty    = (int)($_POST['stock_qty']       ?? 0);
        $reorder     = (int)($_POST['reorder_level']   ?? 10);
        $expiry      = $_POST['expiry_date'] ?: null;
        $supplier    = sanitize($_POST['supplier']     ?? '');
        $status      = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if (!$name) { setFlash('error', 'Medicine name is required.'); redirect('pharmacy.php?tab=inventory'); }

        if ($id) {
            $pdo->prepare("UPDATE health_medicines SET name=?,generic_name=?,category=?,form=?,strength=?,unit=?,unit_price=?,stock_qty=?,reorder_level=?,expiry_date=?,supplier=?,status=? WHERE id=? AND org_id=?")
                ->execute([$name,$generic,$category,$form,$strength,$unit,$unitPrice,$stockQty,$reorder,$expiry,$supplier,$status,$id,$orgId]);
            setFlash('success', 'Medicine updated.');
        } else {
            $pdo->prepare("INSERT INTO health_medicines (org_id,name,generic_name,category,form,strength,unit,unit_price,stock_qty,reorder_level,expiry_date,supplier,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$name,$generic,$category,$form,$strength,$unit,$unitPrice,$stockQty,$reorder,$expiry,$supplier,$status]);
            setFlash('success', 'Medicine added to inventory.');
        }
        redirect('pharmacy.php?tab=inventory');
    }

    // ── Stock adjustment ──────────────────────────────────────────
    if ($action === 'stock_adjust') {
        $id    = (int)($_POST['id']  ?? 0);
        $delta = (int)($_POST['qty'] ?? 0); // positive = add, negative = deduct
        if ($id && $delta !== 0) {
            $pdo->prepare("UPDATE health_medicines SET stock_qty = GREATEST(0, stock_qty + ?) WHERE id=? AND org_id=?")
                ->execute([$delta, $id, $orgId]);
            setFlash('success', 'Stock updated.');
        }
        redirect('pharmacy.php?tab=inventory');
    }

    // ── Delete medicine ───────────────────────────────────────────
    if ($action === 'delete_med') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM health_medicines WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Medicine removed.');
        redirect('pharmacy.php?tab=inventory');
    }

    // ── Dispense medicine ─────────────────────────────────────────
    if ($action === 'dispense') {
        $patientId  = (int)($_POST['patient_id']          ?? 0);
        $medicineId = (int)($_POST['medicine_id']         ?? 0);
        $recordId   = (int)($_POST['record_id']           ?? 0) ?: null;
        $admId      = (int)($_POST['admission_id']        ?? 0) ?: null;
        $qty        = (int)($_POST['quantity']            ?? 1);
        $unitPrice  = (float)($_POST['unit_price']        ?? 0);
        $dosage     = sanitize($_POST['dosage_instructions'] ?? '');
        $notes      = sanitize($_POST['notes']            ?? '');

        if (!$patientId || !$medicineId || $qty < 1) {
            setFlash('error', 'Patient, medicine and quantity are required.');
            redirect('pharmacy.php?tab=dispense');
        }

        // Check stock
        $stockSt = $pdo->prepare("SELECT stock_qty FROM health_medicines WHERE id=? AND org_id=?");
        $stockSt->execute([$medicineId, $orgId]);
        $stock = (int)$stockSt->fetchColumn();
        if ($stock < $qty) {
            setFlash('error', "Insufficient stock. Available: {$stock} units.");
            redirect('pharmacy.php?tab=dispense');
        }

        $total = $qty * $unitPrice;

        // Generate dispense number
        $yr    = date('Y');
        $cntSt = $pdo->prepare("SELECT COUNT(*)+1 FROM health_dispensing WHERE org_id=? AND YEAR(dispensed_at)=?");
        $cntSt->execute([$orgId, $yr]);
        $seq   = str_pad((int)$cntSt->fetchColumn(), 4, '0', STR_PAD_LEFT);
        $dispNo = 'RX-' . $yr . '-' . $seq;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO health_dispensing (org_id,dispense_no,patient_id,record_id,admission_id,medicine_id,quantity,unit_price,total,dosage_instructions,dispensed_by,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$dispNo,$patientId,$recordId,$admId,$medicineId,$qty,$unitPrice,$total,$dosage,$uid,$notes]);
            $pdo->prepare("UPDATE health_medicines SET stock_qty = stock_qty - ? WHERE id=? AND org_id=?")
                ->execute([$qty, $medicineId, $orgId]);
            $pdo->commit();
            setFlash('success', "Dispensed {$dispNo} — {$qty} unit(s) issued.");
        } catch (Exception $ex) {
            $pdo->rollBack();
            setFlash('error', 'Dispense failed: ' . $ex->getMessage());
        }
        redirect('pharmacy.php?tab=dispense');
    }
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['inventory','dispense','history']) ? $_GET['tab'] : 'inventory';

// ── Patients ──────────────────────────────────────────────────────
$patientsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, patient_no FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
$patientsSt->execute([$orgId]);
$patients = $patientsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Medicine inventory ────────────────────────────────────────────
$filterCat    = sanitize($_GET['category']  ?? '');
$filterSearch = sanitize($_GET['search']    ?? '');
$filterStatus = $_GET['inv_status']         ?? '';

$invWhere  = "org_id=?";
$invParams = [$orgId];
if ($filterCat)    { $invWhere .= " AND category=?";              $invParams[] = $filterCat; }
if ($filterStatus) { $invWhere .= " AND status=?";                $invParams[] = $filterStatus; }
if ($filterSearch) { $invWhere .= " AND (name LIKE ? OR generic_name LIKE ?)"; $invParams[] = "%{$filterSearch}%"; $invParams[] = "%{$filterSearch}%"; }

$medsSt = $pdo->prepare("SELECT * FROM health_medicines WHERE {$invWhere} ORDER BY name");
$medsSt->execute($invParams);
$medicines = $medsSt->fetchAll(PDO::FETCH_ASSOC);

// Categories for filter
$catsSt = $pdo->prepare("SELECT DISTINCT category FROM health_medicines WHERE org_id=? AND category IS NOT NULL AND category!='' ORDER BY category");
$catsSt->execute([$orgId]);
$categories = $catsSt->fetchAll(PDO::FETCH_COLUMN);

// Active medicines for dispense dropdown
$activeMedsSt = $pdo->prepare("SELECT id, name, form, strength, unit_price, stock_qty FROM health_medicines WHERE org_id=? AND status='active' ORDER BY name");
$activeMedsSt->execute([$orgId]);
$activeMeds = $activeMedsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Dispensing history ────────────────────────────────────────────
$histPid  = (int)($_GET['hist_patient'] ?? 0);
$histDate = sanitize($_GET['hist_date'] ?? '');
$histWhere  = "d.org_id=?";
$histParams = [$orgId];
if ($histPid)  { $histWhere .= " AND d.patient_id=?";          $histParams[] = $histPid; }
if ($histDate) { $histWhere .= " AND DATE(d.dispensed_at)=?";  $histParams[] = $histDate; }

$histSt = $pdo->prepare("
    SELECT d.*, m.name AS medicine_name, m.form, m.strength,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
           u.name AS dispensed_by_name
    FROM health_dispensing d
    LEFT JOIN health_medicines m ON m.id = d.medicine_id
    LEFT JOIN health_patients p ON p.id = d.patient_id
    LEFT JOIN users u ON u.id = d.dispensed_by
    WHERE {$histWhere}
    ORDER BY d.dispensed_at DESC
    LIMIT 200
");
$histSt->execute($histParams);
$history = $histSt->fetchAll(PDO::FETCH_ASSOC);

// ── Summary stats ─────────────────────────────────────────────────
$totalMedsSt = $pdo->prepare("SELECT COUNT(*) FROM health_medicines WHERE org_id=? AND status='active'");
$totalMedsSt->execute([$orgId]);
$totalMeds = (int)$totalMedsSt->fetchColumn();

$lowStockSt = $pdo->prepare("SELECT COUNT(*) FROM health_medicines WHERE org_id=? AND status='active' AND stock_qty <= reorder_level");
$lowStockSt->execute([$orgId]);
$lowStock = (int)$lowStockSt->fetchColumn();

$expirySt = $pdo->prepare("SELECT COUNT(*) FROM health_medicines WHERE org_id=? AND status='active' AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$expirySt->execute([$orgId]);
$expiring = (int)$expirySt->fetchColumn();

$todayDispSt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total),0) FROM health_dispensing WHERE org_id=? AND DATE(dispensed_at)=CURDATE()");
$todayDispSt->execute([$orgId]);
$todayRow = $todayDispSt->fetch(PDO::FETCH_NUM);
$todayDisp  = (int)$todayRow[0];
$todayRev   = (float)$todayRow[1];

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="container-fluid py-4">

  <?php flash(); ?>

  <!-- ── Page Header ───────────────────────────────────────────── -->
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h4 class="mb-0 fw-bold"><i class="fas fa-pills me-2 text-danger"></i>Pharmacy</h4>
      <small class="text-muted">Medicine inventory, dispensing & history</small>
    </div>
    <div class="d-flex gap-2">
      <?php if ($tab === 'inventory'): ?>
        <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#adjustModal">
          <i class="fas fa-boxes me-1"></i>Stock Adjust
        </button>
        <button class="btn btn-danger btn-sm" onclick="openMedModal()" data-bs-toggle="modal" data-bs-target="#medModal">
          <i class="fas fa-plus me-1"></i>Add Medicine
        </button>
      <?php elseif ($tab === 'dispense'): ?>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#dispenseModal">
          <i class="fas fa-prescription-bottle-alt me-1"></i>Dispense Medicine
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Summary Cards ─────────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body text-center py-3">
          <div class="text-primary fs-3 fw-bold"><?= $totalMeds ?></div>
          <small class="text-muted">Active Medicines</small>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm h-100 <?= $lowStock > 0 ? 'border-warning' : '' ?>">
        <div class="card-body text-center py-3">
          <div class="text-warning fs-3 fw-bold"><?= $lowStock ?></div>
          <small class="text-muted">Low Stock</small>
          <?php if ($lowStock > 0): ?><div><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Reorder needed</small></div><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm h-100 <?= $expiring > 0 ? 'border-danger' : '' ?>">
        <div class="card-body text-center py-3">
          <div class="text-danger fs-3 fw-bold"><?= $expiring ?></div>
          <small class="text-muted">Expiring ≤ 30 Days</small>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body text-center py-3">
          <div class="text-success fs-3 fw-bold"><?= $todayDisp ?></div>
          <small class="text-muted">Dispensed Today</small>
          <div><small class="text-muted">KES <?= number_format($todayRev, 2) ?></small></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Tabs ──────────────────────────────────────────────────── -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab==='inventory'?'active':'' ?>" href="?tab=inventory"><i class="fas fa-warehouse me-1"></i>Inventory</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='dispense' ?'active':'' ?>" href="?tab=dispense"><i class="fas fa-prescription-bottle-alt me-1"></i>Dispense</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='history' ?'active':'' ?>" href="?tab=history"><i class="fas fa-history me-1"></i>History</a></li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: INVENTORY
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'inventory'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="inventory">
        <div class="col-12 col-md-4">
          <input type="text" name="search" class="form-control form-control-sm" value="<?= htmlspecialchars($filterSearch) ?>" placeholder="Search name / generic…">
        </div>
        <div class="col-6 col-md-3">
          <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>" <?= $filterCat===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <select name="inv_status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Status</option>
            <option value="active"   <?= $filterStatus==='active'  ?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $filterStatus==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary btn-sm">Search</button>
          <a href="?tab=inventory" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="medsTable">
          <thead class="table-light">
            <tr>
              <th>Name</th>
              <th>Generic</th>
              <th>Category</th>
              <th>Form / Strength</th>
              <th>Unit Price</th>
              <th>Stock</th>
              <th>Expiry</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($medicines)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No medicines found.</td></tr>
          <?php else: foreach ($medicines as $m):
            $stockClass = '';
            if ($m['stock_qty'] == 0)                      $stockClass = 'text-danger fw-bold';
            elseif ($m['stock_qty'] <= $m['reorder_level']) $stockClass = 'text-warning fw-bold';
            $expClass = '';
            if ($m['expiry_date'] && $m['expiry_date'] <= date('Y-m-d', strtotime('+30 days'))) $expClass = 'text-danger fw-bold';
          ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($m['name']) ?></div>
                <?php if ($m['supplier']): ?><small class="text-muted"><?= htmlspecialchars($m['supplier']) ?></small><?php endif; ?>
              </td>
              <td><?= htmlspecialchars($m['generic_name'] ?: '—') ?></td>
              <td><?= htmlspecialchars($m['category'] ?: '—') ?></td>
              <td>
                <?= htmlspecialchars($m['form'] ?: '—') ?>
                <?php if ($m['strength']): ?> <small class="text-muted">(<?= htmlspecialchars($m['strength']) ?>)</small><?php endif; ?>
              </td>
              <td>KES <?= number_format($m['unit_price'], 2) ?></td>
              <td>
                <span class="<?= $stockClass ?>"><?= $m['stock_qty'] ?> <?= htmlspecialchars($m['unit']) ?></span>
                <?php if ($m['stock_qty'] == 0): ?>
                  <span class="badge bg-danger ms-1">Out</span>
                <?php elseif ($m['stock_qty'] <= $m['reorder_level']): ?>
                  <span class="badge bg-warning text-dark ms-1">Low</span>
                <?php endif; ?>
              </td>
              <td class="<?= $expClass ?>"><?= $m['expiry_date'] ? date('d M Y', strtotime($m['expiry_date'])) : '—' ?></td>
              <td>
                <?= $m['status']==='active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?>
              </td>
              <td>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary btn-sm" onclick="openMedModal(<?= $m['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                  <button class="btn btn-outline-success btn-sm" onclick="openAdjust(<?= $m['id'] ?>, '<?= htmlspecialchars(addslashes($m['name'])) ?>', <?= $m['stock_qty'] ?>)" title="Adjust Stock"><i class="fas fa-boxes"></i></button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Remove this medicine?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_med">
                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: DISPENSE
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'dispense'): ?>
  <div class="row g-3">
    <!-- Quick Dispense Form -->
    <div class="col-12 col-lg-6">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-danger text-white">
          <i class="fas fa-prescription-bottle-alt me-2"></i><strong>Quick Dispense</strong>
        </div>
        <div class="card-body">
          <form method="POST" id="quickDispenseForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="dispense">
            <div class="mb-3">
              <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
              <select name="patient_id" class="form-select select2" required>
                <option value="">Select Patient</option>
                <?php foreach ($patients as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Medicine <span class="text-danger">*</span></label>
              <select name="medicine_id" id="medSelect" class="form-select select2" required onchange="fetchMedPrice(this.value)">
                <option value="">Select Medicine</option>
                <?php foreach ($activeMeds as $m): ?>
                  <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?> <?= $m['form'] ? '('.$m['form'].')' : '' ?> <?= $m['strength'] ? $m['strength'] : '' ?> — Stock: <?= $m['stock_qty'] ?></option>
                <?php endforeach; ?>
              </select>
              <div id="medInfo" class="mt-1 text-muted small"></div>
            </div>
            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label fw-semibold">Quantity <span class="text-danger">*</span></label>
                <input type="number" name="quantity" id="qtyInput" class="form-control" min="1" value="1" required oninput="calcTotal()">
              </div>
              <div class="col-6">
                <label class="form-label fw-semibold">Unit Price (KES)</label>
                <input type="number" name="unit_price" id="priceInput" class="form-control" min="0" step="0.01" value="0" oninput="calcTotal()">
              </div>
            </div>
            <div class="alert alert-light border mb-3 d-flex justify-content-between">
              <span class="fw-semibold">Total:</span>
              <span class="fw-bold text-success" id="totalDisplay">KES 0.00</span>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Dosage Instructions</label>
              <input type="text" name="dosage_instructions" class="form-control" placeholder="e.g. 1 tablet twice daily after meals">
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
            <button type="submit" class="btn btn-danger w-100"><i class="fas fa-prescription-bottle-alt me-1"></i>Dispense</button>
          </form>
        </div>
      </div>
    </div>

    <!-- Today's Dispense List -->
    <div class="col-12 col-lg-6">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-light fw-semibold">
          <i class="fas fa-list me-2"></i>Today's Dispenses
        </div>
        <div class="card-body p-0">
          <?php
            $todayListSt = $pdo->prepare("
                SELECT d.*, m.name AS medicine_name, m.form,
                       CONCAT(p.first_name,' ',p.last_name) AS patient_name
                FROM health_dispensing d
                LEFT JOIN health_medicines m ON m.id=d.medicine_id
                LEFT JOIN health_patients p ON p.id=d.patient_id
                WHERE d.org_id=? AND DATE(d.dispensed_at)=CURDATE()
                ORDER BY d.dispensed_at DESC LIMIT 50
            ");
            $todayListSt->execute([$orgId]);
            $todayList = $todayListSt->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <?php if (empty($todayList)): ?>
            <p class="text-center text-muted py-4">No dispenses today.</p>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead class="table-light"><tr><th>Rx No</th><th>Patient</th><th>Medicine</th><th>Qty</th><th>Total</th></tr></thead>
              <tbody>
              <?php foreach ($todayList as $d): ?>
                <tr>
                  <td><small><?= htmlspecialchars($d['dispense_no']) ?></small></td>
                  <td><small><?= htmlspecialchars($d['patient_name']) ?></small></td>
                  <td><small><?= htmlspecialchars($d['medicine_name']) ?> <?= htmlspecialchars($d['form']??'') ?></small></td>
                  <td><small><?= $d['quantity'] ?></small></td>
                  <td><small>KES <?= number_format($d['total'],2) ?></small></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: HISTORY
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'history'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="history">
        <div class="col-12 col-md-4">
          <select name="hist_patient" class="form-select form-select-sm select2" onchange="this.form.submit()">
            <option value="">All Patients</option>
            <?php foreach ($patients as $p): ?>
              <option value="<?= $p['id'] ?>" <?= $histPid==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <input type="date" name="hist_date" class="form-control form-control-sm" value="<?= htmlspecialchars($histDate) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-auto">
          <a href="?tab=history" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="histTable">
          <thead class="table-light">
            <tr>
              <th>Rx No</th>
              <th>Patient</th>
              <th>Medicine</th>
              <th>Form</th>
              <th>Qty</th>
              <th>Unit Price</th>
              <th>Total</th>
              <th>Dosage</th>
              <th>Dispensed By</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($history)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No dispense records found.</td></tr>
          <?php else: foreach ($history as $h): ?>
            <tr>
              <td><strong><?= htmlspecialchars($h['dispense_no']) ?></strong></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($h['patient_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($h['patient_no']) ?></small>
              </td>
              <td><?= htmlspecialchars($h['medicine_name']) ?></td>
              <td><small><?= htmlspecialchars($h['form'] ?: '—') ?> <?= htmlspecialchars($h['strength'] ?: '') ?></small></td>
              <td><?= $h['quantity'] ?> <?= htmlspecialchars($h['unit'] ?? '') ?></td>
              <td>KES <?= number_format($h['unit_price'],2) ?></td>
              <td><strong>KES <?= number_format($h['total'],2) ?></strong></td>
              <td><small><?= htmlspecialchars($h['dosage_instructions'] ?: '—') ?></small></td>
              <td><small><?= htmlspecialchars($h['dispensed_by_name'] ?: '—') ?></small></td>
              <td><small><?= date('d M Y H:i', strtotime($h['dispensed_at'])) ?></small></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<!-- ── Modal: Add / Edit Medicine ────────────────────────────────── -->
<div class="modal fade" id="medModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <form method="POST" id="medForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_med">
      <input type="hidden" name="id" id="medId">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-pills me-2"></i><span id="medModalTitle">Add Medicine</span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">Brand Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="medName" class="form-control" required>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">Generic Name</label>
              <input type="text" name="generic_name" id="medGeneric" class="form-control">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">Category</label>
              <input type="text" name="category" id="medCategory" class="form-control" list="medCatList" placeholder="e.g. Antibiotic">
              <datalist id="medCatList">
                <option>Antibiotic</option><option>Analgesic</option><option>Antihypertensive</option>
                <option>Antidiabetic</option><option>Antihistamine</option><option>Antifungal</option>
                <option>Antiviral</option><option>Antiparasitic</option><option>Corticosteroid</option>
                <option>Diuretic</option><option>Vaccine</option><option>Vitamin / Supplement</option>
                <option>IV Fluid</option><option>Antiseptic</option><option>Other</option>
              </datalist>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Form</label>
              <input type="text" name="form" id="medForm" class="form-control" list="formList" placeholder="Tablet, Syrup…">
              <datalist id="formList">
                <option>Tablet</option><option>Capsule</option><option>Syrup</option><option>Injection</option>
                <option>Cream</option><option>Ointment</option><option>Drops</option><option>Inhaler</option>
                <option>Suppository</option><option>Patch</option><option>IV Solution</option>
              </datalist>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Strength</label>
              <input type="text" name="strength" id="medStrength" class="form-control" placeholder="e.g. 500mg">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold">Unit</label>
              <input type="text" name="unit" id="medUnit" class="form-control" value="Units">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold">Unit Price (KES)</label>
              <input type="number" name="unit_price" id="medPrice" class="form-control" min="0" step="0.01" value="0">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold">Stock Qty</label>
              <input type="number" name="stock_qty" id="medStock" class="form-control" min="0" value="0">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold">Reorder Level</label>
              <input type="number" name="reorder_level" id="medReorder" class="form-control" min="0" value="10">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Expiry Date</label>
              <input type="date" name="expiry_date" id="medExpiry" class="form-control">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">Supplier</label>
              <input type="text" name="supplier" id="medSupplier" class="form-control">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="medStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save Medicine</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Stock Adjustment ───────────────────────────────────── -->
<div class="modal fade" id="adjustModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="stock_adjust">
      <input type="hidden" name="id" id="adjustMedId">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title"><i class="fas fa-boxes me-2"></i>Stock Adjustment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted mb-3">Medicine: <strong id="adjustMedName"></strong></p>
          <div class="mb-3">
            <label class="form-label fw-semibold">Current Stock</label>
            <input type="text" id="adjustCurrent" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Adjustment Quantity</label>
            <input type="number" name="qty" class="form-control" placeholder="Use + to add, - to deduct (e.g. -5 or 20)" required>
            <div class="form-text">Positive = stock in, Negative = stock out</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning text-dark"><i class="fas fa-save me-1"></i>Update Stock</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
// ── Medicine modal ────────────────────────────────────────────────
function openMedModal(id) {
    const fields = ['medId','medName','medGeneric','medCategory','medForm','medStrength','medUnit','medPrice','medStock','medReorder','medExpiry','medSupplier','medStatus'];
    const vals   = ['','','','','','','Units','0','0','10','','','active'];
    fields.forEach((f,i) => { document.getElementById(f).value = vals[i]; });
    document.getElementById('medModalTitle').textContent = 'Add Medicine';

    if (id) {
        document.getElementById('medModalTitle').textContent = 'Edit Medicine';
        fetch('pharmacy.php?fetch_med=' + id)
            .then(r => r.json())
            .then(d => {
                if (!d.id) return;
                document.getElementById('medId').value       = d.id;
                document.getElementById('medName').value     = d.name         || '';
                document.getElementById('medGeneric').value  = d.generic_name || '';
                document.getElementById('medCategory').value = d.category     || '';
                document.getElementById('medForm').value     = d.form         || '';
                document.getElementById('medStrength').value = d.strength     || '';
                document.getElementById('medUnit').value     = d.unit         || 'Units';
                document.getElementById('medPrice').value    = d.unit_price   || '0';
                document.getElementById('medStock').value    = d.stock_qty    || '0';
                document.getElementById('medReorder').value  = d.reorder_level|| '10';
                document.getElementById('medExpiry').value   = d.expiry_date  || '';
                document.getElementById('medSupplier').value = d.supplier     || '';
                document.getElementById('medStatus').value   = d.status       || 'active';
            });
        new bootstrap.Modal(document.getElementById('medModal')).show();
    }
}

// ── Stock adjustment modal ────────────────────────────────────────
function openAdjust(id, name, qty) {
    document.getElementById('adjustMedId').value    = id;
    document.getElementById('adjustMedName').textContent = name;
    document.getElementById('adjustCurrent').value  = qty;
    new bootstrap.Modal(document.getElementById('adjustModal')).show();
}

// ── Fetch medicine price on dispense form ─────────────────────────
function fetchMedPrice(id) {
    const info  = document.getElementById('medInfo');
    const price = document.getElementById('priceInput');
    if (!id) { info.textContent = ''; price.value = '0'; calcTotal(); return; }
    fetch('pharmacy.php?med_price=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.unit_price) return;
            price.value = d.unit_price;
            info.innerHTML = `<i class="fas fa-info-circle text-info"></i> ${d.form||''} ${d.strength||''} — Stock: <strong>${d.stock_qty}</strong> units`;
            calcTotal();
        });
}

function calcTotal() {
    const qty   = parseFloat(document.getElementById('qtyInput').value)   || 0;
    const price = parseFloat(document.getElementById('priceInput').value)  || 0;
    document.getElementById('totalDisplay').textContent = 'KES ' + (qty * price).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// ── DataTables ────────────────────────────────────────────────────
$(document).ready(function () {
    if ($('#medsTable').length)  $('#medsTable').DataTable({ pageLength: 25, order: [[0,'asc']], columnDefs:[{orderable:false,targets:[8]}] });
    if ($('#histTable').length)  $('#histTable').DataTable({ pageLength: 25, order: [[9,'desc']], columnDefs:[{orderable:false,targets:[]}] });
    if (typeof $.fn.select2 !== 'undefined') {
        $('.select2').select2({ theme:'bootstrap-5', dropdownParent: document.body, width:'100%' });
    }
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
