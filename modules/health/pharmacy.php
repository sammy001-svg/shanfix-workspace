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
    ['url'=>'schedule.php',      'icon'=>'fas fa-calendar-alt',        'label'=>'Doctor Schedule'],
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
    ['url'=>'timeline.php',      'icon'=>'fas fa-history',             'label'=>'Patient Timeline'],
    ['url'=>'prescription.php',  'icon'=>'fas fa-prescription',        'label'=>'Prescriptions'],
    ['url'=>'telemedicine.php',  'icon'=>'fas fa-video',               'label'=>'Telemedicine'],
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-users',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'AI Analytics'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
    ['url'=>'settings.php',      'icon'=>'fas fa-cog',                 'label'=>'Settings'],
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

// ── AJAX: check bill approval for patient ─────────────────────────
if (isset($_GET['check_approval'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $pid   = (int)$_GET['check_approval'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM health_bills WHERE patient_id=? AND org_id=? AND status IN ('approved','paid')");
        $st->execute([$pid, $orgId]);
        echo json_encode(['approved' => (int)$st->fetchColumn() > 0]);
    } catch (Exception $e) { echo json_encode(['approved' => false]); }
    exit;
}

// ── AJAX: fetch prescription for fulfil modal ─────────────────────
if (isset($_GET['fetch_prescription'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_prescription'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("
            SELECT px.*,
                   CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
                   CONCAT(d.first_name,' ',d.last_name) AS doctor_name
            FROM health_prescriptions px
            LEFT JOIN health_patients p ON p.id = px.patient_id
            LEFT JOIN health_doctors d  ON d.id = px.doctor_id
            WHERE px.id=? AND px.org_id=? AND px.status='draft'
        ");
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

        // Require approved or paid bill before dispensing
        try {
            $billChk = $pdo->prepare("SELECT COUNT(*) FROM health_bills WHERE patient_id=? AND org_id=? AND status IN ('approved','paid')");
            $billChk->execute([$patientId, $orgId]);
            if ((int)$billChk->fetchColumn() === 0) {
                setFlash('error', 'Cannot dispense: this patient has no approved bill. Please ask billing staff to approve the bill first.');
                redirect('pharmacy.php?tab=dispense');
            }
        } catch (Exception $e) {}

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

    // ── Fulfil prescription from queue ────────────────────────────
    if ($action === 'dispense_prescription') {
        $prxId = (int)($_POST['prescription_id'] ?? 0);
        $items = $_POST['items'] ?? [];

        if (!$prxId) { setFlash('error', 'Invalid prescription.'); redirect('pharmacy.php?tab=prescriptions'); }

        $prxSt = $pdo->prepare("SELECT * FROM health_prescriptions WHERE id=? AND org_id=? AND status='draft'");
        $prxSt->execute([$prxId, $orgId]);
        $prx = $prxSt->fetch(PDO::FETCH_ASSOC);
        if (!$prx) { setFlash('error', 'Prescription not found or already fulfilled.'); redirect('pharmacy.php?tab=prescriptions'); }

        $patientId = (int)$prx['patient_id'];

        // Bill check
        try {
            $billChk = $pdo->prepare("SELECT COUNT(*) FROM health_bills WHERE patient_id=? AND org_id=? AND status IN ('approved','paid')");
            $billChk->execute([$patientId, $orgId]);
            if ((int)$billChk->fetchColumn() === 0) {
                setFlash('error', 'Cannot dispense: patient has no approved bill. Ask billing to approve the bill first.');
                redirect('pharmacy.php?tab=prescriptions');
            }
        } catch (Exception $e) {}

        // Collect valid rows (medicine selected, qty > 0)
        $toDispense = [];
        foreach ($items as $item) {
            $medId = (int)($item['medicine_id'] ?? 0);
            $qty   = (int)($item['quantity']    ?? 1);
            $price = (float)($item['unit_price'] ?? 0);
            if ($medId && $qty > 0) {
                $toDispense[] = ['medicine_id' => $medId, 'quantity' => $qty, 'unit_price' => $price];
            }
        }

        if (empty($toDispense)) {
            setFlash('error', 'Please match at least one medicine to a stock item before confirming.');
            redirect('pharmacy.php?tab=prescriptions');
        }

        // Pre-validate stock for all items
        foreach ($toDispense as $item) {
            $chkSt = $pdo->prepare("SELECT stock_qty, name FROM health_medicines WHERE id=? AND org_id=?");
            $chkSt->execute([$item['medicine_id'], $orgId]);
            $med = $chkSt->fetch(PDO::FETCH_ASSOC);
            if (!$med || $med['stock_qty'] < $item['quantity']) {
                $avail = $med ? $med['stock_qty'] : 0;
                $mname = $med ? $med['name'] : 'Unknown';
                setFlash('error', "Insufficient stock for {$mname}. Available: {$avail} units.");
                redirect('pharmacy.php?tab=prescriptions');
            }
        }

        // Dispense sequence start
        $yr    = date('Y');
        $cntSt = $pdo->prepare("SELECT COUNT(*)+1 FROM health_dispensing WHERE org_id=? AND YEAR(dispensed_at)=?");
        $cntSt->execute([$orgId, $yr]);
        $seqBase = (int)$cntSt->fetchColumn();

        $pdo->beginTransaction();
        try {
            foreach ($toDispense as $idx => $item) {
                $seq    = str_pad($seqBase + $idx, 4, '0', STR_PAD_LEFT);
                $dispNo = 'RX-' . $yr . '-' . $seq;
                $total  = $item['quantity'] * $item['unit_price'];
                $pdo->prepare("INSERT INTO health_dispensing (org_id,dispense_no,patient_id,medicine_id,quantity,unit_price,total,dispensed_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $dispNo, $patientId, $item['medicine_id'], $item['quantity'], $item['unit_price'], $total, $uid, 'From prescription #' . $prxId]);
                $pdo->prepare("UPDATE health_medicines SET stock_qty = stock_qty - ? WHERE id=? AND org_id=?")
                    ->execute([$item['quantity'], $item['medicine_id'], $orgId]);
            }
            $pdo->prepare("UPDATE health_prescriptions SET status='dispensed', dispensed_by=?, dispensed_at=NOW() WHERE id=? AND org_id=?")
                ->execute([$uid, $prxId, $orgId]);
            $pdo->commit();
            setFlash('success', 'Prescription fulfilled — ' . count($toDispense) . ' medicine(s) dispensed.');
        } catch (Exception $ex) {
            $pdo->rollBack();
            setFlash('error', 'Dispensing failed: ' . $ex->getMessage());
        }
        redirect('pharmacy.php?tab=prescriptions');
    }
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['inventory','dispense','history','prescriptions']) ? $_GET['tab'] : 'inventory';

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

// ── Pending prescriptions (prescription queue tab) ─────────────────
$pendingPrxSt = $pdo->prepare("
    SELECT px.id, px.prescription_no, px.prescription_date, px.diagnosis, px.notes, px.medicines,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM health_prescriptions px
    LEFT JOIN health_patients p ON p.id = px.patient_id
    LEFT JOIN health_doctors d  ON d.id = px.doctor_id
    WHERE px.org_id=? AND px.status='draft'
    ORDER BY px.created_at DESC
    LIMIT 100
");
try {
    $pendingPrxSt->execute([$orgId]);
    $pendingPrescriptions = $pendingPrxSt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $pendingPrescriptions = []; }

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
      <?php elseif ($tab === 'prescriptions'): ?>
        <a href="prescription.php" class="btn btn-outline-success btn-sm">
          <i class="fas fa-prescription me-1"></i>Go to Prescriptions
        </a>
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
          <div><small class="text-muted"><?= hMoney($todayRev) ?></small></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Tabs ──────────────────────────────────────────────────── -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab==='inventory'?'active':'' ?>" href="?tab=inventory"><i class="fas fa-warehouse me-1"></i>Inventory</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='dispense' ?'active':'' ?>" href="?tab=dispense"><i class="fas fa-prescription-bottle-alt me-1"></i>Dispense</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='history' ?'active':'' ?>" href="?tab=history"><i class="fas fa-history me-1"></i>History</a></li>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='prescriptions'?'active':'' ?>" href="?tab=prescriptions">
        <i class="fas fa-prescription me-1"></i>Prescription Queue
        <?php if (!empty($pendingPrescriptions)): ?>
          <span class="badge bg-danger ms-1"><?= count($pendingPrescriptions) ?></span>
        <?php endif; ?>
      </a>
    </li>
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
              <td><?= hMoney((float)$m['unit_price']) ?></td>
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
              <select name="patient_id" id="dispensePatient" class="form-select select2" required onchange="checkBillApproval(this.value)">
                <option value="">Select Patient</option>
                <?php foreach ($patients as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
              <div id="billApprovalStatus" class="mt-1"></div>
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
                <label class="form-label fw-semibold">Unit Price (<?= $GLOBALS['hCurrencySymbol'] ?? 'LRD' ?>)</label>
                <input type="number" name="unit_price" id="priceInput" class="form-control" min="0" step="0.01" value="0" oninput="calcTotal()">
              </div>
            </div>
            <div class="alert alert-light border mb-3 d-flex justify-content-between">
              <span class="fw-semibold">Total:</span>
              <span class="fw-bold text-success" id="totalDisplay"><?= $GLOBALS['hCurrencySymbol'] ?? 'LRD' ?> 0.00</span>
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
                  <td><small><?= hMoney((float)$d['total']) ?></small></td>
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
              <td><?= hMoney((float)$h['unit_price']) ?></td>
              <td><strong><?= hMoney((float)$h['total']) ?></strong></td>
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

  <!-- ══════════════════════════════════════════════════════════════
       TAB: PRESCRIPTION QUEUE
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'prescriptions'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <?php if (empty($pendingPrescriptions)): ?>
        <div class="text-center text-muted py-5">
          <i class="fas fa-prescription fa-3x mb-3 d-block text-success opacity-50"></i>
          <h6>No pending prescriptions</h6>
          <p class="small">All prescriptions have been fulfilled or there are none yet.</p>
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="prxQueueTable">
          <thead class="table-light">
            <tr>
              <th>Rx No</th>
              <th>Patient</th>
              <th>Doctor</th>
              <th>Medicines Prescribed</th>
              <th>Diagnosis</th>
              <th>Date</th>
              <th class="text-center">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($pendingPrescriptions as $px):
            $meds = json_decode($px['medicines'] ?? '[]', true) ?: [];
          ?>
            <tr>
              <td><strong><?= e($px['prescription_no'] ?: '—') ?></strong></td>
              <td>
                <div class="fw-semibold"><?= e($px['patient_name'] ?: '—') ?></div>
                <small class="text-muted"><?= e($px['patient_no'] ?: '') ?></small>
              </td>
              <td><?= $px['doctor_name'] ? 'Dr. ' . e($px['doctor_name']) : '<span class="text-muted">—</span>' ?></td>
              <td>
                <?php foreach ($meds as $m): ?>
                  <div class="small"><i class="fas fa-pills text-danger me-1"></i><?= e($m['name'] ?? '?') ?>
                    <?php if (!empty($m['dosage'])): ?><span class="text-muted"><?= e($m['dosage']) ?></span><?php endif; ?>
                    <?php if (!empty($m['frequency'])): ?><span class="badge bg-light text-dark border ms-1"><?= e($m['frequency']) ?></span><?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($meds)): ?><span class="text-muted small">No medicines listed</span><?php endif; ?>
              </td>
              <td><small class="text-muted"><?= e($px['diagnosis'] ?: '—') ?></small></td>
              <td><small><?= date('d M Y', strtotime($px['prescription_date'] ?: $px['created_at'] ?? 'now')) ?></small></td>
              <td class="text-center">
                <button class="btn btn-success btn-sm" onclick="openFulfilModal(<?= $px['id'] ?>)">
                  <i class="fas fa-check-circle me-1"></i>Fulfil
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
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
              <label class="form-label fw-semibold">Unit Price (<?= $GLOBALS['hCurrencySymbol'] ?? 'LRD' ?>)</label>
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

<!-- ── Modal: Fulfil Prescription ───────────────────────────────── -->
<div class="modal fade" id="fulfilModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <form method="POST" id="fulfilForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="dispense_prescription">
      <input type="hidden" name="prescription_id" id="fulfilPrxId">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Fulfil Prescription</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info py-2 mb-3">
            <strong id="fulfilPatient"></strong>
            <span class="ms-3 text-muted" id="fulfilDoctor"></span>
          </div>
          <p class="text-muted small mb-2">Match each prescribed medicine to a stock item. Leave the dropdown blank to skip a line item.</p>
          <div id="fulfilMedsContainer">
            <div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>Confirm Dispensing</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
// Inject stock meds for JS use (outside nowdoc so PHP can interpolate)
$__stockJson = json_encode(array_map(fn($m) => [
    'id'        => (int)$m['id'],
    'name'      => $m['name'],
    'form'      => $m['form']      ?? '',
    'strength'  => $m['strength']  ?? '',
    'stock_qty' => (int)$m['stock_qty'],
    'unit_price'=> (float)$m['unit_price'],
], $activeMeds));
?>
<script>const _stockMeds = <?= $__stockJson ?>;</script>
<?php unset($__stockJson); ?>

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
    document.getElementById('totalDisplay').textContent = (window._hCurr || 'LRD') + ' ' + (qty * price).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function checkBillApproval(pid) {
    const el = document.getElementById('billApprovalStatus');
    if (!pid) { el.innerHTML = ''; return; }
    el.innerHTML = '<small class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>Checking billing status…</small>';
    fetch('pharmacy.php?check_approval=' + pid)
        .then(r => r.json())
        .then(d => {
            if (d.approved) {
                el.innerHTML = '<small class="text-success"><i class="fas fa-check-circle me-1"></i>Bill approved — dispensing is allowed.</small>';
            } else {
                el.innerHTML = '<small class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i><strong>No approved bill found.</strong> Please ask billing staff to approve the bill before dispensing.</small>';
            }
        })
        .catch(() => { el.innerHTML = ''; });
}

// ── Fulfil Prescription modal ─────────────────────────────────────
function openFulfilModal(id) {
    document.getElementById('fulfilPrxId').value = id;
    document.getElementById('fulfilPatient').textContent = '';
    document.getElementById('fulfilDoctor').textContent  = '';
    document.getElementById('fulfilMedsContainer').innerHTML =
        '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>';
    new bootstrap.Modal(document.getElementById('fulfilModal')).show();

    fetch('pharmacy.php?fetch_prescription=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.id) {
                document.getElementById('fulfilMedsContainer').innerHTML =
                    '<p class="text-danger">Prescription not found or already fulfilled.</p>';
                return;
            }
            document.getElementById('fulfilPatient').textContent =
                (d.patient_name || '—') + ' (' + (d.patient_no || '') + ')';
            document.getElementById('fulfilDoctor').textContent =
                d.doctor_name ? 'Dr. ' + d.doctor_name : '';

            const meds = (typeof d.medicines === 'string') ? JSON.parse(d.medicines) : (d.medicines || []);
            const stockOpts = _stockMeds.map(m =>
                `<option value="${m.id}" data-price="${m.unit_price}">${m.name}${m.form ? ' ('+m.form+')' : ''}${m.strength ? ' '+m.strength : ''} — Stock: ${m.stock_qty}</option>`
            ).join('');

            let html = `<div class="table-responsive"><table class="table table-sm align-middle">
              <thead class="table-light"><tr>
                <th>Prescribed Medicine</th>
                <th style="min-width:260px">Match to Stock Item</th>
                <th style="width:80px">Qty</th>
                <th style="width:110px">Unit Price</th>
                <th style="width:90px">Total</th>
              </tr></thead><tbody>`;

            meds.forEach((m, i) => {
                html += `<tr>
                  <td>
                    <div class="fw-semibold">${m.name || '?'}</div>
                    <small class="text-muted">${[m.dosage, m.frequency, m.duration].filter(Boolean).join(' · ')}</small>
                  </td>
                  <td>
                    <select name="items[${i}][medicine_id]" class="form-select form-select-sm fulfil-stock-sel"
                            onchange="onFulfilStockChange(this,${i})">
                      <option value="">— skip this item —</option>
                      ${stockOpts}
                    </select>
                  </td>
                  <td><input type="number" name="items[${i}][quantity]" class="form-control form-control-sm"
                             id="fqty_${i}" min="1" value="${m.quantity || 1}" oninput="calcFulfilRow(${i})"></td>
                  <td><input type="number" name="items[${i}][unit_price]" class="form-control form-control-sm"
                             id="fprice_${i}" min="0" step="0.01" value="0" oninput="calcFulfilRow(${i})"></td>
                  <td class="fw-semibold text-end" id="ftotal_${i}">0.00</td>
                </tr>`;
            });

            html += '</tbody></table></div>';
            document.getElementById('fulfilMedsContainer').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('fulfilMedsContainer').innerHTML =
                '<p class="text-danger">Failed to load prescription. Please try again.</p>';
        });
}

function onFulfilStockChange(sel, i) {
    const opt = sel.selectedOptions[0];
    const price = opt ? (parseFloat(opt.dataset.price) || 0) : 0;
    document.getElementById('fprice_' + i).value = price.toFixed(2);
    calcFulfilRow(i);
}

function calcFulfilRow(i) {
    const qty   = parseFloat(document.getElementById('fqty_'   + i).value) || 0;
    const price = parseFloat(document.getElementById('fprice_' + i).value) || 0;
    document.getElementById('ftotal_' + i).textContent = (qty * price).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// ── DataTables ────────────────────────────────────────────────────
$(document).ready(function () {
    if ($('#medsTable').length)     $('#medsTable').DataTable({ pageLength: 25, order: [[0,'asc']], columnDefs:[{orderable:false,targets:[8]}] });
    if ($('#histTable').length)     $('#histTable').DataTable({ pageLength: 25, order: [[9,'desc']], columnDefs:[{orderable:false,targets:[]}] });
    if ($('#prxQueueTable').length) $('#prxQueueTable').DataTable({ pageLength: 25, order: [[5,'desc']], columnDefs:[{orderable:false,targets:[6]}] });
    if (typeof $.fn.select2 !== 'undefined') {
        $('.select2').select2({ theme:'bootstrap-5', dropdownParent: document.body, width:'100%' });
    }
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
