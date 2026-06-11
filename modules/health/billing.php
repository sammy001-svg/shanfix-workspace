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

// ── AJAX: fetch bill ──────────────────────────────────────────────
if (isset($_GET['fetch'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("
            SELECT b.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no
            FROM health_bills b
            LEFT JOIN health_patients p ON p.id=b.patient_id
            WHERE b.id=? AND b.org_id=?
        ");
        $st->execute([$id, $orgId]);
        $bill  = $st->fetch(PDO::FETCH_ASSOC);
        $items = $pdo->prepare("SELECT * FROM health_bill_items WHERE bill_id=?")->execute([$id]) ? [] : [];
        $ist   = $pdo->prepare("SELECT * FROM health_bill_items WHERE bill_id=?");
        $ist->execute([$id]);
        $items = $ist->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['bill'=>$bill,'items'=>$items]);
    } catch (Exception $e) { echo json_encode(['bill'=>null,'items'=>[]]); }
    exit;
}

// ── AJAX: fetch service price ─────────────────────────────────────
if (isset($_GET['svc_price'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['svc_price'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT id, name, price, category FROM health_services WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── AJAX: print bill ──────────────────────────────────────────────
if (isset($_GET['print'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['print'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("
            SELECT b.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no, p.dob, p.gender, p.phone
            FROM health_bills b
            LEFT JOIN health_patients p ON p.id=b.patient_id
            WHERE b.id=? AND b.org_id=?
        ");
        $st->execute([$id, $orgId]);
        $bill = $st->fetch(PDO::FETCH_ASSOC);
        $ist  = $pdo->prepare("SELECT * FROM health_bill_items WHERE bill_id=?");
        $ist->execute([$id]);
        $items = $ist->fetchAll(PDO::FETCH_ASSOC);
        $orgSt = $pdo->prepare("SELECT name FROM organizations WHERE id=?");
        $orgSt->execute([$orgId]);
        $orgName = $orgSt->fetchColumn() ?: 'Hospital';
        echo json_encode(['bill'=>$bill,'items'=>$items,'org_name'=>$orgName]);
    } catch (Exception $e) { echo json_encode(['bill'=>null,'items'=>[]]); }
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

    // ── Create bill ───────────────────────────────────────────────
    if ($action === 'create_bill') {
        $patientId   = (int)($_POST['patient_id']        ?? 0);
        $admId       = (int)($_POST['admission_id']      ?? 0) ?: null;
        $billType    = in_array($_POST['bill_type'] ?? '', ['opd','ipd','emergency','other']) ? $_POST['bill_type'] : 'opd';
        $discount    = (float)($_POST['discount']        ?? 0);
        $tax         = (float)($_POST['tax']             ?? 0);
        $payMethod   = sanitize($_POST['payment_method'] ?? '');
        $insurancePr = sanitize($_POST['insurance_provider'] ?? '');
        $insuranceNo = sanitize($_POST['insurance_no']   ?? '');
        $notes       = sanitize($_POST['notes']          ?? '');
        $status      = in_array($_POST['status'] ?? '', ['draft','sent','approved','partial','paid','cancelled']) ? $_POST['status'] : 'draft';

        // Items
        $descriptions = $_POST['item_description'] ?? [];
        $categories   = $_POST['item_category']    ?? [];
        $quantities   = $_POST['item_quantity']     ?? [];
        $unitPrices   = $_POST['item_unit_price']   ?? [];

        if (!$patientId || empty($descriptions)) { setFlash('error', 'Patient and at least one line item are required.'); redirect('billing.php'); }

        // Compute subtotal
        $subtotal = 0;
        for ($i = 0; $i < count($descriptions); $i++) {
            $subtotal += (int)($quantities[$i] ?? 1) * (float)($unitPrices[$i] ?? 0);
        }
        $total = $subtotal - $discount + $tax;
        $paidAmount = ($status === 'paid') ? $total : 0;

        // Bill number
        $yr    = date('Y');
        $cntSt = $pdo->prepare("SELECT COUNT(*)+1 FROM health_bills WHERE org_id=? AND YEAR(created_at)=?");
        $cntSt->execute([$orgId, $yr]);
        $seq   = str_pad((int)$cntSt->fetchColumn(), 4, '0', STR_PAD_LEFT);
        $billNo = 'HB-' . $yr . '-' . $seq;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO health_bills (org_id,bill_no,patient_id,admission_id,bill_type,subtotal,discount,tax,total,paid_amount,status,payment_method,insurance_provider,insurance_no,notes,created_by,paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$billNo,$patientId,$admId,$billType,$subtotal,$discount,$tax,$total,$paidAmount,$status,$payMethod,$insurancePr,$insuranceNo,$notes,$uid,($status==='paid'?date('Y-m-d H:i:s'):null)]);
            $billId = (int)$pdo->lastInsertId();

            $ist = $pdo->prepare("INSERT INTO health_bill_items (bill_id,description,category,quantity,unit_price,total) VALUES (?,?,?,?,?,?)");
            for ($i = 0; $i < count($descriptions); $i++) {
                $desc = sanitize($descriptions[$i] ?? '');
                if (!$desc) continue;
                $qty  = max(1,(int)($quantities[$i]  ?? 1));
                $up   = (float)($unitPrices[$i]  ?? 0);
                $ist->execute([$billId,$desc,sanitize($categories[$i]??''),$qty,$up,$qty*$up]);
            }
            $pdo->commit();
            setFlash('success', "Bill {$billNo} created.");
        } catch (Exception $ex) {
            $pdo->rollBack();
            setFlash('error', 'Failed: ' . $ex->getMessage());
        }
        redirect('billing.php');
    }

    // ── Update bill status / payment ──────────────────────────────
    if ($action === 'update_bill') {
        $id         = (int)($_POST['id']             ?? 0);
        $paidAmount = (float)($_POST['paid_amount']  ?? 0);
        $status     = in_array($_POST['status'] ?? '', ['draft','sent','approved','partial','paid','cancelled']) ? $_POST['status'] : 'draft';
        $payMethod  = sanitize($_POST['payment_method'] ?? '');
        $notes      = sanitize($_POST['notes']       ?? '');
        $paidAt     = ($status === 'paid') ? 'NOW()' : 'NULL';

        $pdo->prepare("UPDATE health_bills SET paid_amount=?,status=?,payment_method=?,notes=?,paid_at=IF(status='paid',COALESCE(paid_at,NOW()),NULL) WHERE id=? AND org_id=?")
            ->execute([$paidAmount,$status,$payMethod,$notes,$id,$orgId]);
        setFlash('success', 'Bill updated.');
        redirect('billing.php');
    }

    // ── Approve bill ──────────────────────────────────────────────
    if ($action === 'approve_bill') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE health_bills SET status='approved' WHERE id=? AND org_id=? AND status IN ('draft','sent')")
                ->execute([$id, $orgId]);
            setFlash('success', 'Bill approved — pharmacy can now dispense for this patient.');
        }
        redirect('billing.php');
    }

    // ── Save service catalog ──────────────────────────────────────
    if ($action === 'save_service') {
        $id       = (int)($_POST['id']       ?? 0);
        $name     = sanitize($_POST['name']  ?? '');
        $category = in_array($_POST['category']??'',['consultation','procedure','lab','radiology','pharmacy','nursing','room','other']) ? $_POST['category'] : 'other';
        $price    = (float)($_POST['price']  ?? 0);
        $status   = in_array($_POST['status']??'',['active','inactive']) ? $_POST['status'] : 'active';

        if (!$name) { setFlash('error','Service name required.'); redirect('billing.php?tab=services'); }
        if ($id) {
            $pdo->prepare("UPDATE health_services SET name=?,category=?,price=?,status=? WHERE id=? AND org_id=?")->execute([$name,$category,$price,$status,$id,$orgId]);
        } else {
            $pdo->prepare("INSERT INTO health_services (org_id,name,category,price,status) VALUES (?,?,?,?,?)")->execute([$orgId,$name,$category,$price,$status]);
        }
        setFlash('success','Service saved.');
        redirect('billing.php?tab=services');
    }

    // ── Delete service ────────────────────────────────────────────
    if ($action === 'delete_service') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM health_services WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Service deleted.');
        redirect('billing.php?tab=services');
    }
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['bills','services']) ? $_GET['tab'] : 'bills';

// ── Patients ──────────────────────────────────────────────────────
$patientsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, patient_no FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
$patientsSt->execute([$orgId]);
$patients = $patientsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Active admissions ─────────────────────────────────────────────
$admSt = $pdo->prepare("SELECT a.id, a.admission_no, CONCAT(p.first_name,' ',p.last_name) AS pname FROM health_admissions a LEFT JOIN health_patients p ON p.id=a.patient_id WHERE a.org_id=? AND a.status='admitted' ORDER BY a.admitted_at DESC");
$admSt->execute([$orgId]);
$admissions = $admSt->fetchAll(PDO::FETCH_ASSOC);

// ── Services catalog ──────────────────────────────────────────────
$svcSt = $pdo->prepare("SELECT * FROM health_services WHERE org_id=? ORDER BY category,name");
$svcSt->execute([$orgId]);
$services = $svcSt->fetchAll(PDO::FETCH_ASSOC);

// ── Bills ─────────────────────────────────────────────────────────
$filterStatus = sanitize($_GET['status'] ?? '');
$filterPid    = (int)($_GET['patient_id'] ?? 0);
$filterDate   = sanitize($_GET['date']    ?? '');
$filterType   = sanitize($_GET['type']    ?? '');

$billWhere  = "b.org_id=?";
$billParams = [$orgId];
if ($filterStatus) { $billWhere .= " AND b.status=?";           $billParams[] = $filterStatus; }
if ($filterPid)    { $billWhere .= " AND b.patient_id=?";       $billParams[] = $filterPid; }
if ($filterDate)   { $billWhere .= " AND DATE(b.created_at)=?"; $billParams[] = $filterDate; }
if ($filterType)   { $billWhere .= " AND b.bill_type=?";        $billParams[] = $filterType; }

$billsSt = $pdo->prepare("
    SELECT b.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
           a.admission_no
    FROM health_bills b
    LEFT JOIN health_patients p ON p.id=b.patient_id
    LEFT JOIN health_admissions a ON a.id=b.admission_id
    WHERE {$billWhere}
    ORDER BY b.created_at DESC LIMIT 200
");
$billsSt->execute($billParams);
$bills = $billsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────
$todayRevSt = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM health_bills WHERE org_id=? AND DATE(COALESCE(paid_at,created_at))=CURDATE() AND status IN ('paid','partial')");
$todayRevSt->execute([$orgId]);
$todayRev = (float)$todayRevSt->fetchColumn();

$outstandSt = $pdo->prepare("SELECT COALESCE(SUM(total - paid_amount),0) FROM health_bills WHERE org_id=? AND status IN ('draft','sent','approved','partial')");
$outstandSt->execute([$orgId]);
$outstanding = (float)$outstandSt->fetchColumn();

$totalBillsSt = $pdo->prepare("SELECT COUNT(*) FROM health_bills WHERE org_id=? AND DATE(created_at)=CURDATE()");
$totalBillsSt->execute([$orgId]);
$todayBills = (int)$totalBillsSt->fetchColumn();

$unpaidSt = $pdo->prepare("SELECT COUNT(*) FROM health_bills WHERE org_id=? AND status IN ('sent','approved','partial')");
$unpaidSt->execute([$orgId]);
$unpaidCount = (int)$unpaidSt->fetchColumn();

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="container-fluid py-4">

  <?php flash(); ?>

  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h4 class="mb-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2 text-danger"></i>Hospital Billing</h4>
      <small class="text-muted">Bills, payments & service catalog</small>
    </div>
    <div class="d-flex gap-2">
      <?php if ($tab === 'bills'): ?>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#billModal" onclick="resetBillForm()">
          <i class="fas fa-plus me-1"></i>Create Bill
        </button>
      <?php elseif ($tab === 'services'): ?>
        <button class="btn btn-danger btn-sm" onclick="openSvcModal()" data-bs-toggle="modal" data-bs-target="#svcModal">
          <i class="fas fa-plus me-1"></i>Add Service
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stats -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-success fs-3 fw-bold">KES <?= number_format($todayRev, 0) ?></div>
        <small class="text-muted">Revenue Today</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-danger fs-3 fw-bold">KES <?= number_format($outstanding, 0) ?></div>
        <small class="text-muted">Outstanding</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-primary fs-3 fw-bold"><?= $todayBills ?></div>
        <small class="text-muted">Bills Today</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3 <?= $unpaidCount > 0 ? 'border-warning' : '' ?>">
        <div class="text-warning fs-3 fw-bold"><?= $unpaidCount ?></div>
        <small class="text-muted">Unpaid Bills</small>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab==='bills'   ?'active':'' ?>" href="?tab=bills"><i class="fas fa-file-invoice me-1"></i>Bills</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='services'?'active':'' ?>" href="?tab=services"><i class="fas fa-concierge-bell me-1"></i>Service Catalog</a></li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: BILLS
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'bills'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="bills">
        <div class="col-12 col-md-3">
          <select name="patient_id" class="form-select form-select-sm select2" onchange="this.form.submit()">
            <option value="">All Patients</option>
            <?php foreach ($patients as $p): ?>
              <option value="<?= $p['id'] ?>" <?= $filterPid==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Status</option>
            <option value="draft"     <?= $filterStatus==='draft'    ?'selected':'' ?>>Draft</option>
            <option value="sent"      <?= $filterStatus==='sent'     ?'selected':'' ?>>Sent</option>
            <option value="approved"  <?= $filterStatus==='approved' ?'selected':'' ?>>Approved</option>
            <option value="partial"   <?= $filterStatus==='partial'  ?'selected':'' ?>>Partial</option>
            <option value="paid"      <?= $filterStatus==='paid'     ?'selected':'' ?>>Paid</option>
            <option value="cancelled" <?= $filterStatus==='cancelled'?'selected':'' ?>>Cancelled</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Types</option>
            <option value="opd"       <?= $filterType==='opd'      ?'selected':'' ?>>OPD</option>
            <option value="ipd"       <?= $filterType==='ipd'      ?'selected':'' ?>>IPD</option>
            <option value="emergency" <?= $filterType==='emergency' ?'selected':'' ?>>Emergency</option>
            <option value="other"     <?= $filterType==='other'     ?'selected':'' ?>>Other</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDate) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-auto">
          <a href="?tab=bills" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="billsTable">
          <thead class="table-light">
            <tr><th>Bill No</th><th>Patient</th><th>Type</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Created</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (empty($bills)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No bills found.</td></tr>
          <?php else: foreach ($bills as $b):
            $balance = $b['total'] - $b['paid_amount'];
            $stBadge = match($b['status']) {
                'paid'      => 'success',
                'approved'  => 'primary',
                'partial'   => 'info',
                'sent'      => 'warning text-dark',
                'draft'     => 'secondary',
                'cancelled' => 'danger',
                default     => 'light text-dark'
            };
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($b['bill_no']) ?></strong></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($b['patient_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($b['patient_no']) ?></small>
              </td>
              <td><span class="badge bg-light text-dark border"><?= strtoupper($b['bill_type']) ?></span></td>
              <td><strong>KES <?= number_format($b['total'], 2) ?></strong></td>
              <td class="text-success">KES <?= number_format($b['paid_amount'], 2) ?></td>
              <td class="<?= $balance > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">KES <?= number_format($balance, 2) ?></td>
              <td><span class="badge bg-<?= $stBadge ?>"><?= ucfirst($b['status']) ?></span></td>
              <td><small><?= date('d M Y', strtotime($b['created_at'])) ?></small></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <?php if (in_array($b['status'], ['draft','sent'])): ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Approve this bill? Pharmacy will be able to dispense medicines for this patient.')">
                    <?= csrfField() ?><input type="hidden" name="action" value="approve_bill"><input type="hidden" name="id" value="<?= $b['id'] ?>">
                    <button type="submit" class="btn btn-outline-success btn-sm" title="Approve for Pharmacy"><i class="fas fa-check-circle"></i></button>
                  </form>
                  <?php endif; ?>
                  <button class="btn btn-outline-primary btn-sm" onclick="openPayModal(<?= $b['id'] ?>)" title="Update Payment"><i class="fas fa-money-bill-wave"></i></button>
                  <button class="btn btn-outline-secondary btn-sm" onclick="printBill(<?= $b['id'] ?>)" title="Print"><i class="fas fa-print"></i></button>
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
       TAB: SERVICE CATALOG
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'services'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="svcTable">
          <thead class="table-light">
            <tr><th>Name</th><th>Category</th><th>Price (KES)</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (empty($services)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No services yet. <a href="#" onclick="openSvcModal(); return false;">Add one.</a></td></tr>
          <?php else: foreach ($services as $s): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($s['name']) ?></td>
              <td><?= ucfirst($s['category']) ?></td>
              <td><?= number_format($s['price'], 2) ?></td>
              <td><?= $s['status']==='active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary btn-sm" onclick="openSvcModal(<?= $s['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                    <?= csrfField() ?><input type="hidden" name="action" value="delete_service"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
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

</div><!-- /container -->

<!-- ── Modal: Create Bill ─────────────────────────────────────────── -->
<div class="modal fade" id="billModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <form method="POST" id="billForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_bill">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2"></i>Create Hospital Bill</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-5">
              <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
              <select name="patient_id" class="form-select select2" required>
                <option value="">Select Patient</option>
                <?php foreach ($patients as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Bill Type</label>
              <select name="bill_type" class="form-select">
                <option value="opd">OPD</option>
                <option value="ipd">IPD</option>
                <option value="emergency">Emergency</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold">Admission (if IPD)</label>
              <select name="admission_id" class="form-select select2">
                <option value="">None</option>
                <?php foreach ($admissions as $a): ?>
                  <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['admission_no']) ?> — <?= htmlspecialchars($a['pname']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Line items -->
          <label class="form-label fw-semibold">Bill Items <span class="text-danger">*</span></label>
          <div class="table-responsive">
            <table class="table table-sm border" id="itemsTable">
              <thead class="table-light">
                <tr>
                  <th style="min-width:220px">Description</th>
                  <th style="min-width:140px">Category</th>
                  <th style="width:80px">Qty</th>
                  <th style="width:120px">Unit Price</th>
                  <th style="width:120px">Total</th>
                  <th style="width:40px"></th>
                </tr>
              </thead>
              <tbody id="itemsBody">
                <tr class="item-row">
                  <td><input type="text" name="item_description[]" class="form-control form-control-sm" required placeholder="e.g. Consultation Fee"></td>
                  <td>
                    <select name="item_category[]" class="form-select form-select-sm">
                      <option value="">—</option>
                      <option value="consultation">Consultation</option><option value="procedure">Procedure</option>
                      <option value="lab">Laboratory</option><option value="radiology">Radiology</option>
                      <option value="pharmacy">Pharmacy</option><option value="nursing">Nursing</option>
                      <option value="room">Room/Bed</option><option value="other">Other</option>
                    </select>
                  </td>
                  <td><input type="number" name="item_quantity[]" class="form-control form-control-sm qty-input" min="1" value="1" oninput="calcRow(this)"></td>
                  <td><input type="number" name="item_unit_price[]" class="form-control form-control-sm price-input" min="0" step="0.01" value="0" oninput="calcRow(this)"></td>
                  <td><input type="text" class="form-control form-control-sm row-total" readonly value="0.00"></td>
                  <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-minus"></i></button></td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Quick add from service catalog -->
          <div class="d-flex gap-2 align-items-center mb-3">
            <select id="quickSvc" class="form-select form-select-sm" style="max-width:300px">
              <option value="">Add from service catalog…</option>
              <?php foreach ($services as $s): if ($s['status']!=='active') continue; ?>
                <option value="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['name']) ?>" data-category="<?= $s['category'] ?>" data-price="<?= $s['price'] ?>">
                  <?= htmlspecialchars($s['name']) ?> — KES <?= number_format($s['price'],2) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addSvcRow()"><i class="fas fa-plus me-1"></i>Add</button>
            <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" onclick="addEmptyRow()"><i class="fas fa-plus me-1"></i>Add Row</button>
          </div>

          <!-- Totals -->
          <div class="row justify-content-end g-2">
            <div class="col-12 col-md-4">
              <table class="table table-sm">
                <tr><td>Subtotal</td><td class="text-end fw-bold" id="subtotalDisplay">KES 0.00</td></tr>
                <tr>
                  <td>Discount (KES)</td>
                  <td><input type="number" name="discount" id="discountInput" class="form-control form-control-sm text-end" min="0" step="0.01" value="0" oninput="recalcTotals()"></td>
                </tr>
                <tr>
                  <td>Tax (KES)</td>
                  <td><input type="number" name="tax" id="taxInput" class="form-control form-control-sm text-end" min="0" step="0.01" value="0" oninput="recalcTotals()"></td>
                </tr>
                <tr class="table-success"><td><strong>Total</strong></td><td class="text-end fw-bold" id="totalDisplay">KES 0.00</td></tr>
              </table>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" class="form-select">
                <option value="draft">Draft</option>
                <option value="sent">Sent</option>
                <option value="approved">Approved (pharmacy can dispense)</option>
                <option value="paid">Paid</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Payment Method</label>
              <select name="payment_method" class="form-select">
                <option value="">—</option>
                <option value="cash">Cash</option>
                <option value="mpesa">M-Pesa</option>
                <option value="insurance">Insurance</option>
                <option value="card">Card</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Insurance Provider</label>
              <input type="text" name="insurance_provider" class="form-control" placeholder="e.g. NHIF, APA">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Insurance No</label>
              <input type="text" name="insurance_no" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save Bill</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Update Payment ─────────────────────────────────────── -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_bill">
      <input type="hidden" name="id" id="payBillId">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Update Payment</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="payBody">Loading…</div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Update</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Print Bill ─────────────────────────────────────────── -->
<div class="modal fade" id="printBillModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Hospital Bill</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="printBillArea">Loading…</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" onclick="doPrintBill()"><i class="fas fa-print me-1"></i>Print</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Service ─────────────────────────────────────────────── -->
<div class="modal fade" id="svcModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_service">
      <input type="hidden" name="id" id="svcId">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-concierge-bell me-2"></i><span id="svcModalTitle">Add Service</span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Service Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="svcName" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Category</label>
              <select name="category" id="svcCategory" class="form-select">
                <option value="consultation">Consultation</option><option value="procedure">Procedure</option>
                <option value="lab">Laboratory</option><option value="radiology">Radiology</option>
                <option value="pharmacy">Pharmacy</option><option value="nursing">Nursing</option>
                <option value="room">Room/Bed</option><option value="other">Other</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Price (KES)</label>
              <input type="number" name="price" id="svcPrice" class="form-control" min="0" step="0.01" value="0">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="svcStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save Service</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
let subtotal = 0;

function calcRow(el) {
    const row   = el.closest('tr');
    const qty   = parseFloat(row.querySelector('.qty-input').value)   || 0;
    const price = parseFloat(row.querySelector('.price-input').value) || 0;
    row.querySelector('.row-total').value = (qty * price).toFixed(2);
    recalcTotals();
}

function recalcTotals() {
    let sub = 0;
    document.querySelectorAll('.row-total').forEach(el => { sub += parseFloat(el.value) || 0; });
    const disc = parseFloat(document.getElementById('discountInput').value) || 0;
    const tax  = parseFloat(document.getElementById('taxInput').value)      || 0;
    const fmt  = n => 'KES ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    document.getElementById('subtotalDisplay').textContent = fmt(sub);
    document.getElementById('totalDisplay').textContent    = fmt(sub - disc + tax);
}

function addEmptyRow() {
    const tbody = document.getElementById('itemsBody');
    const tmpl  = tbody.querySelector('tr').cloneNode(true);
    tmpl.querySelectorAll('input').forEach(i => { if (i.type!=='hidden') { i.value = i.classList.contains('qty-input') ? '1' : i.classList.contains('price-input') ? '0' : i.classList.contains('row-total') ? '0.00' : ''; } });
    tmpl.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
    tbody.appendChild(tmpl);
}

function addSvcRow() {
    const sel = document.getElementById('quickSvc');
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    const tbody = document.getElementById('itemsBody');
    const tmpl  = tbody.querySelector('tr').cloneNode(true);
    tmpl.querySelector('[name="item_description[]"]').value  = opt.dataset.name    || '';
    tmpl.querySelector('[name="item_category[]"]').value     = opt.dataset.category|| '';
    tmpl.querySelector('.qty-input').value   = '1';
    tmpl.querySelector('.price-input').value = opt.dataset.price || '0';
    tmpl.querySelector('.row-total').value   = parseFloat(opt.dataset.price||0).toFixed(2);
    tbody.appendChild(tmpl);
    sel.selectedIndex = 0;
    recalcTotals();
}

function removeRow(btn) {
    const tbody = document.getElementById('itemsBody');
    if (tbody.querySelectorAll('tr').length <= 1) return;
    btn.closest('tr').remove();
    recalcTotals();
}

function resetBillForm() {
    document.getElementById('itemsBody').querySelectorAll('tr').forEach((r,i)=>{ if(i>0) r.remove(); });
    recalcTotals();
}

function openPayModal(id) {
    document.getElementById('payBillId').value = id;
    document.getElementById('payBody').innerHTML = '<div class="text-center py-3"><div class="spinner-border text-success"></div></div>';
    const modal = new bootstrap.Modal(document.getElementById('payModal'));
    modal.show();
    fetch('billing.php?fetch=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.bill) { document.getElementById('payBody').textContent = 'Not found.'; return; }
            const b = d.bill;
            const bal = (parseFloat(b.total) - parseFloat(b.paid_amount)).toFixed(2);
            document.getElementById('payBody').innerHTML = `
              <div class="alert alert-light border mb-3">
                <strong>${b.bill_no}</strong> — ${b.patient_name}<br>
                Total: <strong>KES ${parseFloat(b.total).toLocaleString()}</strong> |
                Paid: <strong class="text-success">KES ${parseFloat(b.paid_amount).toLocaleString()}</strong> |
                Balance: <strong class="text-danger">KES ${parseFloat(bal).toLocaleString()}</strong>
              </div>
              <div class="row g-3">
                <div class="col-12 col-md-6">
                  <label class="form-label fw-semibold">Amount Paid (KES)</label>
                  <input type="number" name="paid_amount" class="form-control" min="0" step="0.01" value="${b.paid_amount}">
                </div>
                <div class="col-12 col-md-6">
                  <label class="form-label fw-semibold">Status</label>
                  <select name="status" class="form-select">
                    ${['draft','sent','approved','partial','paid','cancelled'].map(s=>`<option value="${s}" ${b.status===s?'selected':''}>${s.charAt(0).toUpperCase()+s.slice(1)}</option>`).join('')}
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label fw-semibold">Payment Method</label>
                  <select name="payment_method" class="form-select">
                    <option value="">—</option>
                    ${['cash','mpesa','insurance','card','bank_transfer'].map(m=>`<option value="${m}" ${b.payment_method===m?'selected':''}>${m.charAt(0).toUpperCase()+m.slice(1).replace('_',' ')}</option>`).join('')}
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label fw-semibold">Notes</label>
                  <textarea name="notes" class="form-control" rows="2">${b.notes||''}</textarea>
                </div>
              </div>`;
        });
}

function printBill(id) {
    document.getElementById('printBillArea').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-danger"></div></div>';
    new bootstrap.Modal(document.getElementById('printBillModal')).show();
    fetch('billing.php?print=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.bill) { document.getElementById('printBillArea').textContent = 'Not found.'; return; }
            const b = d.bill; const items = d.items;
            const fmt = n => parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
            let rows = items.map(i => `<tr><td>${i.description}</td><td>${i.category||'—'}</td><td style="text-align:center">${i.quantity}</td><td style="text-align:right">${fmt(i.unit_price)}</td><td style="text-align:right;font-weight:700">${fmt(i.total)}</td></tr>`).join('');
            document.getElementById('printBillArea').innerHTML = `
              <div id="billContent" style="font-family:'Segoe UI',Arial,sans-serif;padding:16px;max-width:700px;margin:0 auto">
                <div style="text-align:center;border-bottom:2px solid #c00;padding-bottom:12px;margin-bottom:16px">
                  <h3 style="color:#c00;margin:0">${d.org_name}</h3>
                  <p style="margin:4px 0 0;color:#555;font-size:.85rem">Hospital Bill / Receipt</p>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;background:#f8f9fa;padding:12px;border-radius:6px;margin-bottom:16px;font-size:.85rem">
                  <div><span style="color:#666">Bill No</span><br><strong>${b.bill_no}</strong></div>
                  <div><span style="color:#666">Date</span><br>${new Date(b.created_at).toLocaleDateString('en-GB')}</div>
                  <div><span style="color:#666">Patient</span><br><strong>${b.patient_name}</strong> (${b.patient_no})</div>
                  <div><span style="color:#666">Type</span><br>${(b.bill_type||'').toUpperCase()}</div>
                  ${b.insurance_provider ? `<div><span style="color:#666">Insurance</span><br>${b.insurance_provider} #${b.insurance_no||''}</div>` : ''}
                  <div><span style="color:#666">Status</span><br><strong style="color:${b.status==='paid'?'#198754':'#dc3545'}">${b.status.toUpperCase()}</strong></div>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:16px">
                  <thead><tr style="background:#c00;color:white"><th style="padding:6px 10px;text-align:left">Description</th><th style="padding:6px 10px">Category</th><th style="padding:6px 10px;text-align:center">Qty</th><th style="padding:6px 10px;text-align:right">Unit Price</th><th style="padding:6px 10px;text-align:right">Total</th></tr></thead>
                  <tbody>${rows}</tbody>
                </table>
                <div style="float:right;width:260px;font-size:.85rem">
                  <table style="width:100%;border-collapse:collapse">
                    <tr><td style="padding:4px">Subtotal</td><td style="padding:4px;text-align:right">KES ${fmt(b.subtotal)}</td></tr>
                    ${parseFloat(b.discount)>0?`<tr><td style="padding:4px;color:#c00">Discount</td><td style="padding:4px;text-align:right;color:#c00">- KES ${fmt(b.discount)}</td></tr>`:''}
                    ${parseFloat(b.tax)>0?`<tr><td style="padding:4px">Tax</td><td style="padding:4px;text-align:right">KES ${fmt(b.tax)}</td></tr>`:''}
                    <tr style="background:#f8f9fa;font-weight:700"><td style="padding:6px">Total</td><td style="padding:6px;text-align:right">KES ${fmt(b.total)}</td></tr>
                    <tr style="color:#198754"><td style="padding:4px">Amount Paid</td><td style="padding:4px;text-align:right">KES ${fmt(b.paid_amount)}</td></tr>
                    <tr style="color:${parseFloat(b.total)-parseFloat(b.paid_amount)>0?'#dc3545':'#198754'};font-weight:700"><td style="padding:4px">Balance</td><td style="padding:4px;text-align:right">KES ${fmt(parseFloat(b.total)-parseFloat(b.paid_amount))}</td></tr>
                  </table>
                </div>
                <div style="clear:both;border-top:1px solid #eee;padding-top:10px;font-size:.75rem;color:#999;text-align:center">
                  Thank you for choosing ${d.org_name} — ${new Date().toLocaleDateString('en-GB')}
                </div>
              </div>`;
        });
}

function doPrintBill() {
    const c = document.getElementById('billContent');
    if (!c) return;
    const w = window.open('','','width=800,height=900');
    w.document.write('<html><head><title>Bill</title></head><body>'+c.outerHTML+'</body></html>');
    w.document.close(); w.focus(); w.print(); w.close();
}

function openSvcModal(id) {
    document.getElementById('svcId').value       = '';
    document.getElementById('svcName').value     = '';
    document.getElementById('svcCategory').value = 'other';
    document.getElementById('svcPrice').value    = '0';
    document.getElementById('svcStatus').value   = 'active';
    document.getElementById('svcModalTitle').textContent = id ? 'Edit Service' : 'Add Service';
    if (id) {
        // Find from table data
        const rows = document.querySelectorAll('#svcTable tbody tr');
        rows.forEach(r => {
            const editBtn = r.querySelector('[onclick^="openSvcModal"]');
            if (editBtn && editBtn.getAttribute('onclick').includes('(' + id + ')')) {
                // We'd need a separate fetch — simpler: just set id and user edits
                document.getElementById('svcId').value = id;
            }
        });
    }
    new bootstrap.Modal(document.getElementById('svcModal')).show();
}

$(document).ready(function () {
    if ($('#billsTable').length) $('#billsTable').DataTable({ pageLength: 25, order: [[7,'desc']], columnDefs:[{orderable:false,targets:[8]}] });
    if ($('#svcTable').length)   $('#svcTable').DataTable({ pageLength: 25, order: [[0,'asc']], columnDefs:[{orderable:false,targets:[4]}] });
    if (typeof $.fn.select2 !== 'undefined') {
        $('.select2').select2({ theme:'bootstrap-5', dropdownParent: document.body, width:'100%' });
    }
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
