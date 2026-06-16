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
    ['url'=>'settings.php',      'icon'=>'fas fa-cog',                 'label'=>'Settings'],
];

// ── AJAX: fetch single test (catalog) for edit ────────────────────
if (isset($_GET['fetch_test'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_test'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_lab_tests WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── AJAX: fetch single order for result entry ─────────────────────
if (isset($_GET['fetch_order'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_order'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("
            SELECT o.*, t.name AS test_name, t.normal_range, t.unit,
                   CONCAT(p.first_name,' ',p.last_name) AS patient_name
            FROM health_lab_orders o
            LEFT JOIN health_lab_tests t ON t.id = o.test_id
            LEFT JOIN health_patients p ON p.id = o.patient_id
            WHERE o.id=? AND o.org_id=?
        ");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── AJAX: print lab report HTML ───────────────────────────────────
if (isset($_GET['print_order'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['print_order'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("
            SELECT o.*, t.name AS test_name, t.normal_range, t.unit, t.category,
                   CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                   p.patient_no, p.dob, p.gender,
                   CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
                   rb.name AS resulted_by_name
            FROM health_lab_orders o
            LEFT JOIN health_lab_tests t ON t.id = o.test_id
            LEFT JOIN health_patients p ON p.id = o.patient_id
            LEFT JOIN health_doctors d ON d.id = o.doctor_id
            LEFT JOIN users rb ON rb.id = o.resulted_by
            WHERE o.id=? AND o.org_id=?
        ");
        $st->execute([$id, $orgId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        // Fetch org name
        $orgSt = $pdo->prepare("SELECT name FROM organizations WHERE id=?");
        $orgSt->execute([$orgId]);
        $orgName = $orgSt->fetchColumn() ?: 'Hospital';
        echo json_encode(['order' => $r, 'org_name' => $orgName]);
    } catch (Exception $e) { echo json_encode(['order'=>null]); }
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

    // ── Save / update test catalog ────────────────────────────────
    if ($action === 'save_test') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = sanitize($_POST['name']         ?? '');
        $category = sanitize($_POST['category']     ?? '');
        $range    = sanitize($_POST['normal_range'] ?? '');
        $unit     = sanitize($_POST['unit']         ?? '');
        $price    = (float)($_POST['price']         ?? 0);
        $tat      = (int)($_POST['turnaround']      ?? 1);
        $status   = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if (!$name) { setFlash('error', 'Test name is required.'); redirect('lab.php?tab=tests'); }

        if ($id) {
            $pdo->prepare("UPDATE health_lab_tests SET name=?, category=?, normal_range=?, unit=?, price=?, turnaround=?, status=? WHERE id=? AND org_id=?")
                ->execute([$name, $category, $range, $unit, $price, $tat, $status, $id, $orgId]);
            setFlash('success', 'Lab test updated.');
        } else {
            $pdo->prepare("INSERT INTO health_lab_tests (org_id, name, category, normal_range, unit, price, turnaround, status) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $name, $category, $range, $unit, $price, $tat, $status]);
            setFlash('success', 'Lab test added.');
        }
        redirect('lab.php?tab=tests');
    }

    // ── Seed default tests ────────────────────────────────────────
    if ($action === 'seed_tests') {
        $defaults = [
            // Haematology
            ['Haematology', 'Full Blood Count (FBC)',       '',            '',       500, 4],
            ['Haematology', 'Haemoglobin (Hb)',             '12–17 g/dL',  'g/dL',  300, 2],
            ['Haematology', 'White Blood Cell Count (WBC)', '4.5–11 × 10³','×10³/μL',300,2],
            ['Haematology', 'Platelet Count',               '150–400 × 10³','×10³/μL',300,2],
            ['Haematology', 'Erythrocyte Sedimentation Rate (ESR)', '', 'mm/hr', 300, 2],
            // Clinical Chemistry
            ['Clinical Chemistry', 'Random Blood Sugar (RBS)',  '3.9–11.1 mmol/L','mmol/L',200,1],
            ['Clinical Chemistry', 'Fasting Blood Sugar (FBS)', '3.9–6.1 mmol/L', 'mmol/L',200,1],
            ['Clinical Chemistry', 'HbA1c',                    '<7.0%',           '%',      800,4],
            ['Clinical Chemistry', 'Urea',                     '2.5–7.1 mmol/L',  'mmol/L',400,2],
            ['Clinical Chemistry', 'Creatinine',               '62–115 μmol/L',   'μmol/L',400,2],
            ['Clinical Chemistry', 'Total Cholesterol',        '<5.2 mmol/L',     'mmol/L',400,4],
            ['Clinical Chemistry', 'LDL Cholesterol',          '<3.4 mmol/L',     'mmol/L',400,4],
            ['Clinical Chemistry', 'HDL Cholesterol',          '>1.0 mmol/L',     'mmol/L',400,4],
            ['Clinical Chemistry', 'Triglycerides',            '<1.7 mmol/L',     'mmol/L',400,4],
            ['Clinical Chemistry', 'Liver Function Tests (LFTs)', '',             '',       800,4],
            ['Clinical Chemistry', 'SGPT / ALT',               '7–56 U/L',        'U/L',   400,2],
            ['Clinical Chemistry', 'SGOT / AST',               '10–40 U/L',       'U/L',   400,2],
            ['Clinical Chemistry', 'Total Bilirubin',          '3.4–17.1 μmol/L', 'μmol/L',400,2],
            ['Clinical Chemistry', 'Serum Uric Acid',          '2.4–7.0 mg/dL',   'mg/dL', 400,2],
            // Urinalysis
            ['Urinalysis', 'Urinalysis (UA)',             '', '', 300, 1],
            ['Urinalysis', 'Urine Culture & Sensitivity', '', '', 600, 48],
            // Serology / Immunology
            ['Serology / Immunology', 'HIV Rapid Test',         'Non-Reactive', '', 500, 1],
            ['Serology / Immunology', 'HBsAg (Hepatitis B)',    'Non-Reactive', '', 600, 2],
            ['Serology / Immunology', 'Anti-HCV (Hepatitis C)', 'Non-Reactive', '', 600, 2],
            ['Serology / Immunology', 'VDRL / RPR (Syphilis)',  'Non-Reactive', '', 500, 1],
            ['Serology / Immunology', 'Malaria Rapid Test',     'Negative',     '', 400, 1],
            ['Serology / Immunology', 'CRP (C-Reactive Protein)','<10 mg/L',  'mg/L',500,2],
            ['Serology / Immunology', 'Thyroid Stimulating Hormone (TSH)', '0.4–4.0 mIU/L','mIU/L',700,4],
            ['Serology / Immunology', 'Free T4',                '9–25 pmol/L', 'pmol/L',700,4],
            // Microbiology
            ['Microbiology', 'Blood Culture & Sensitivity',  '', '', 1000, 72],
            ['Microbiology', 'Stool Culture & Sensitivity',  '', '', 800,  48],
            ['Microbiology', 'Sputum AFB (Tuberculosis)',    '', '', 600,  48],
            // Radiology
            ['Radiology', 'Chest X-Ray',   '', '', 1500, 1],
            ['Radiology', 'Abdominal X-Ray','', '', 1500, 1],
            ['Radiology', 'Ultrasound Abdomen', '', '', 2500, 1],
        ];
        $inserted = 0;
        foreach ($defaults as [$cat, $name, $range, $unit, $price, $tat]) {
            try {
                $pdo->prepare("INSERT INTO health_lab_tests (org_id,name,category,normal_range,unit,price,turnaround,status) VALUES (?,?,?,?,?,?,?,'active')")
                    ->execute([$orgId, $name, $cat, $range, $unit, $price, $tat]);
                $inserted++;
            } catch (Throwable $e) { /* skip duplicates */ }
        }
        setFlash('success', "{$inserted} default lab tests added to catalog.");
        redirect('lab.php?tab=tests');
    }

    // ── Delete test ───────────────────────────────────────────────
    if ($action === 'delete_test') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM health_lab_tests WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Test deleted.');
        redirect('lab.php?tab=tests');
    }

    // ── Create lab order ──────────────────────────────────────────
    if ($action === 'create_order') {
        $patientId = (int)($_POST['patient_id']     ?? 0);
        $doctorId  = (int)($_POST['doctor_id']      ?? 0) ?: null;
        $apptId    = (int)($_POST['appointment_id'] ?? 0) ?: null;
        $admId     = (int)($_POST['admission_id']   ?? 0) ?: null;
        $testId    = (int)($_POST['test_id']        ?? 0);
        $priority  = in_array($_POST['priority'] ?? '', ['routine','urgent','stat']) ? $_POST['priority'] : 'routine';
        $sample    = sanitize($_POST['sample_type'] ?? '');

        if (!$patientId || !$testId) { setFlash('error', 'Patient and test are required.'); redirect('lab.php?tab=orders'); }

        // Generate order number
        $yr     = date('Y');
        $cntSt  = $pdo->prepare("SELECT COUNT(*)+1 FROM health_lab_orders WHERE org_id=? AND YEAR(ordered_at)=?");
        $cntSt->execute([$orgId, $yr]);
        $seq    = str_pad((int)$cntSt->fetchColumn(), 4, '0', STR_PAD_LEFT);
        $orderNo = 'LAB-' . $yr . '-' . $seq;

        $pdo->prepare("INSERT INTO health_lab_orders (org_id, order_no, patient_id, doctor_id, appointment_id, admission_id, test_id, priority, status, sample_type) VALUES (?,?,?,?,?,?,?,?,'ordered',?)")
            ->execute([$orgId, $orderNo, $patientId, $doctorId, $apptId, $admId, $testId, $priority, $sample]);
        setFlash('success', "Lab order {$orderNo} created.");
        redirect('lab.php?tab=orders');
    }

    // ── Update order status (collected / processing) ──────────────
    if ($action === 'update_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['ordered','collected','processing','resulted','cancelled']) ? $_POST['status'] : '';
        $col    = '';
        if ($status === 'collected') $col = ', collected_at=NOW()';
        if ($status && $id) {
            $pdo->prepare("UPDATE health_lab_orders SET status=? {$col} WHERE id=? AND org_id=?")
                ->execute([$status, $id, $orgId]);
            setFlash('success', 'Order status updated.');
        }
        redirect('lab.php?tab=orders');
    }

    // ── Enter / update results ────────────────────────────────────
    if ($action === 'enter_result') {
        $id         = (int)($_POST['id'] ?? 0);
        $value      = sanitize($_POST['result_value'] ?? '');
        $notes      = sanitize($_POST['result_notes'] ?? '');
        $flag       = in_array($_POST['result_flag'] ?? '', ['normal','low','high','critical','']) ? ($_POST['result_flag'] ?: null) : null;

        $pdo->prepare("UPDATE health_lab_orders SET result_value=?, result_notes=?, result_flag=?, status='resulted', resulted_at=NOW(), resulted_by=? WHERE id=? AND org_id=?")
            ->execute([$value, $notes, $flag, $uid, $id, $orgId]);
        setFlash('success', 'Result entered successfully.');
        redirect('lab.php?tab=results');
    }

    // ── Cancel order ──────────────────────────────────────────────
    if ($action === 'cancel_order') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE health_lab_orders SET status='cancelled' WHERE id=? AND org_id=? AND status NOT IN ('resulted')")
            ->execute([$id, $orgId]);
        setFlash('success', 'Order cancelled.');
        redirect('lab.php?tab=orders');
    }
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

// ── Ensure lab tables exist ───────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS health_lab_tests (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        org_id       INT NOT NULL,
        name         VARCHAR(200) NOT NULL,
        category     VARCHAR(100),
        normal_range VARCHAR(200),
        unit         VARCHAR(50),
        price        DECIMAL(10,2) DEFAULT 0,
        turnaround   INT DEFAULT 1,
        status       ENUM('active','inactive') DEFAULT 'active',
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_org (org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS health_lab_orders (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        org_id         INT NOT NULL,
        order_no       VARCHAR(30) NOT NULL,
        patient_id     INT NOT NULL,
        doctor_id      INT,
        appointment_id INT,
        admission_id   INT,
        test_id        INT NOT NULL,
        priority       ENUM('routine','urgent','stat') DEFAULT 'routine',
        status         ENUM('ordered','collected','processing','resulted','cancelled') DEFAULT 'ordered',
        sample_type    VARCHAR(100),
        result_value   TEXT,
        result_notes   TEXT,
        result_flag    ENUM('normal','low','high','critical'),
        ordered_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        collected_at   DATETIME,
        resulted_at    DATETIME,
        resulted_by    INT,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_org    (org_id),
        INDEX idx_patient(patient_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* tables already exist */ }

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['tests','orders','results']) ? $_GET['tab'] : 'orders';

// ── Patients list for dropdowns ───────────────────────────────────
$patientsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, patient_no FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
$patientsSt->execute([$orgId]);
$patients = $patientsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Doctors list ──────────────────────────────────────────────────
$doctorsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name FROM health_doctors WHERE org_id=? AND status='active' ORDER BY first_name");
$doctorsSt->execute([$orgId]);
$doctors = $doctorsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Test catalog ──────────────────────────────────────────────────
$testsSt = $pdo->prepare("SELECT * FROM health_lab_tests WHERE org_id=? ORDER BY category, name");
$testsSt->execute([$orgId]);
$tests = $testsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Active tests for order dropdown ──────────────────────────────
$activeTests = array_filter($tests, fn($t) => $t['status'] === 'active');

// ── Lab orders (pending / in-progress) ───────────────────────────
$filterPid    = (int)($_GET['patient_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$filterDate   = sanitize($_GET['date'] ?? '');

$ordersWhere = "o.org_id=?";
$ordersParams = [$orgId];
if ($filterPid)    { $ordersWhere .= " AND o.patient_id=?"; $ordersParams[] = $filterPid; }
if ($filterStatus) { $ordersWhere .= " AND o.status=?";     $ordersParams[] = $filterStatus; }
if ($filterDate)   { $ordersWhere .= " AND DATE(o.ordered_at)=?"; $ordersParams[] = $filterDate; }

$ordersSt = $pdo->prepare("
    SELECT o.*, t.name AS test_name, t.normal_range, t.unit,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM health_lab_orders o
    LEFT JOIN health_lab_tests t ON t.id = o.test_id
    LEFT JOIN health_patients p ON p.id = o.patient_id
    LEFT JOIN health_doctors d ON d.id = o.doctor_id
    WHERE {$ordersWhere}
    ORDER BY FIELD(o.status,'stat','urgent','routine'), o.ordered_at DESC
    LIMIT 200
");
$ordersSt->execute($ordersParams);
$orders = $ordersSt->fetchAll(PDO::FETCH_ASSOC);

// ── Results (resulted orders, last 30 days) ───────────────────────
$resultPid  = (int)($_GET['res_patient'] ?? 0);
$resultDate = sanitize($_GET['res_date'] ?? '');
$resWhere   = "o.org_id=? AND o.status='resulted'";
$resParams  = [$orgId];
if ($resultPid)  { $resWhere .= " AND o.patient_id=?"; $resParams[] = $resultPid; }
if ($resultDate) { $resWhere .= " AND DATE(o.resulted_at)=?"; $resParams[] = $resultDate; }

$resultsSt = $pdo->prepare("
    SELECT o.*, t.name AS test_name, t.normal_range, t.unit,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
           rb.name AS resulted_by_name
    FROM health_lab_orders o
    LEFT JOIN health_lab_tests t ON t.id = o.test_id
    LEFT JOIN health_patients p ON p.id = o.patient_id
    LEFT JOIN health_doctors d ON d.id = o.doctor_id
    LEFT JOIN users rb ON rb.id = o.resulted_by
    WHERE {$resWhere}
    ORDER BY o.resulted_at DESC
    LIMIT 200
");
$resultsSt->execute($resParams);
$results = $resultsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Summary stats ─────────────────────────────────────────────────
$stats = ['cnt_ordered'=>0,'cnt_collected'=>0,'cnt_processing'=>0,'cnt_today'=>0,'cnt_stat'=>0];
try {
    $statsSt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status='ordered'    THEN 1 ELSE 0 END) AS cnt_ordered,
            SUM(CASE WHEN status='collected'  THEN 1 ELSE 0 END) AS cnt_collected,
            SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) AS cnt_processing,
            SUM(CASE WHEN status='resulted'   AND DATE(resulted_at)=CURDATE() THEN 1 ELSE 0 END) AS cnt_today,
            SUM(CASE WHEN priority='stat'     AND status NOT IN ('resulted','cancelled') THEN 1 ELSE 0 END) AS cnt_stat
        FROM health_lab_orders WHERE org_id=?
    ");
    $statsSt->execute([$orgId]);
    $row = $statsSt->fetch(PDO::FETCH_ASSOC);
    if ($row) $stats = array_map('intval', $row);
} catch (Throwable $e) {
    // fallback: individual counts
    foreach (['ordered','collected','processing'] as $s) {
        try {
            $sx = $pdo->prepare("SELECT COUNT(*) FROM health_lab_orders WHERE org_id=? AND status=?");
            $sx->execute([$orgId, $s]);
            $stats["cnt_{$s}"] = (int)$sx->fetchColumn();
        } catch (Throwable $e2) {}
    }
    try {
        $sx = $pdo->prepare("SELECT COUNT(*) FROM health_lab_orders WHERE org_id=? AND status='resulted' AND DATE(resulted_at)=CURDATE()");
        $sx->execute([$orgId]);
        $stats['cnt_today'] = (int)$sx->fetchColumn();
    } catch (Throwable $e2) {}
    try {
        $sx = $pdo->prepare("SELECT COUNT(*) FROM health_lab_orders WHERE org_id=? AND priority='stat' AND status NOT IN ('resulted','cancelled')");
        $sx->execute([$orgId]);
        $stats['cnt_stat'] = (int)$sx->fetchColumn();
    } catch (Throwable $e2) {}
}

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="container-fluid py-4">

  <!-- ── Flash ──────────────────────────────────────────────────── -->
  <?php flash(); ?>

  <!-- ── Page Header ───────────────────────────────────────────── -->
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h4 class="mb-0 fw-bold"><i class="fas fa-flask me-2 text-danger"></i>Laboratory</h4>
      <small class="text-muted">Test catalog, orders & result management</small>
    </div>
    <div class="d-flex gap-2">
      <?php if ($tab === 'tests'): ?>
        <form method="POST" class="d-inline" onsubmit="return confirm('Seed 35 common lab tests into your catalog?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="seed_tests">
          <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="fas fa-database me-1"></i>Load Defaults</button>
        </form>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#testModal" onclick="openTestModal()">
          <i class="fas fa-plus me-1"></i>Add Test
        </button>
      <?php elseif ($tab === 'orders'): ?>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#orderModal">
          <i class="fas fa-plus me-1"></i>New Order
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Summary Cards ─────────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-xl">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body text-center py-3">
          <div class="text-warning fs-4 fw-bold"><?= $stats['cnt_ordered'] ?></div>
          <small class="text-muted">Ordered</small>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body text-center py-3">
          <div class="text-info fs-4 fw-bold"><?= $stats['cnt_collected'] ?></div>
          <small class="text-muted">Collected</small>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body text-center py-3">
          <div class="text-primary fs-4 fw-bold"><?= $stats['cnt_processing'] ?></div>
          <small class="text-muted">Processing</small>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body text-center py-3">
          <div class="text-success fs-4 fw-bold"><?= $stats['cnt_today'] ?></div>
          <small class="text-muted">Results Today</small>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
      <div class="card border-0 shadow-sm h-100 border-danger">
        <div class="card-body text-center py-3">
          <div class="text-danger fs-4 fw-bold"><?= $stats['cnt_stat'] ?></div>
          <small class="text-muted">STAT Pending</small>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Tabs ──────────────────────────────────────────────────── -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab==='orders' ?'active':'' ?>" href="?tab=orders"><i class="fas fa-list-alt me-1"></i>Orders</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='results'?'active':'' ?>" href="?tab=results"><i class="fas fa-clipboard-check me-1"></i>Results</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='tests' ?'active':'' ?>" href="?tab=tests"><i class="fas fa-book-medical me-1"></i>Test Catalog</a></li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: ORDERS
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'orders'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <!-- Filter bar -->
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="orders">
        <div class="col-12 col-md-4">
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
            <option value="ordered"    <?= $filterStatus==='ordered'    ?'selected':'' ?>>Ordered</option>
            <option value="collected"  <?= $filterStatus==='collected'  ?'selected':'' ?>>Collected</option>
            <option value="processing" <?= $filterStatus==='processing' ?'selected':'' ?>>Processing</option>
            <option value="resulted"   <?= $filterStatus==='resulted'   ?'selected':'' ?>>Resulted</option>
            <option value="cancelled"  <?= $filterStatus==='cancelled'  ?'selected':'' ?>>Cancelled</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDate) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-auto">
          <a href="?tab=orders" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="ordersTable">
          <thead class="table-light">
            <tr>
              <th>Order No</th>
              <th>Patient</th>
              <th>Test</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Doctor</th>
              <th>Ordered</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($orders)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No lab orders found.</td></tr>
          <?php else: foreach ($orders as $o):
            $priBadge = match($o['priority']) {
                'stat'   => 'danger',
                'urgent' => 'warning',
                default  => 'secondary'
            };
            $stBadge = match($o['status']) {
                'ordered'    => 'warning text-dark',
                'collected'  => 'info text-dark',
                'processing' => 'primary',
                'resulted'   => 'success',
                'cancelled'  => 'secondary',
                default      => 'light text-dark'
            };
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($o['order_no']) ?></strong></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($o['patient_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($o['patient_no']) ?></small>
              </td>
              <td><?= htmlspecialchars($o['test_name']) ?></td>
              <td><span class="badge bg-<?= $priBadge ?>"><?= strtoupper($o['priority']) ?></span></td>
              <td><span class="badge bg-<?= $stBadge ?>"><?= ucfirst($o['status']) ?></span></td>
              <td><?= htmlspecialchars($o['doctor_name'] ?: '—') ?></td>
              <td><small><?= date('d M Y H:i', strtotime($o['ordered_at'])) ?></small></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <?php if ($o['status'] === 'ordered'): ?>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="id" value="<?= $o['id'] ?>">
                      <input type="hidden" name="status" value="collected">
                      <button type="submit" class="btn btn-outline-info btn-sm" title="Mark Collected"><i class="fas fa-vial"></i></button>
                    </form>
                  <?php endif; ?>
                  <?php if ($o['status'] === 'collected'): ?>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="id" value="<?= $o['id'] ?>">
                      <input type="hidden" name="status" value="processing">
                      <button type="submit" class="btn btn-outline-primary btn-sm" title="Mark Processing"><i class="fas fa-spinner"></i></button>
                    </form>
                  <?php endif; ?>
                  <?php if (in_array($o['status'], ['ordered','collected','processing'])): ?>
                    <button class="btn btn-outline-success btn-sm" onclick="openResultModal(<?= $o['id'] ?>)" title="Enter Result"><i class="fas fa-clipboard-check"></i></button>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this order?')">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="cancel_order">
                      <input type="hidden" name="id" value="<?= $o['id'] ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm" title="Cancel"><i class="fas fa-times"></i></button>
                    </form>
                  <?php endif; ?>
                  <?php if ($o['status'] === 'resulted'): ?>
                    <a href="<?= APP_URL ?>/modules/health/lab-result-pdf.php?id=<?= $o['id'] ?>" target="_blank" class="btn btn-outline-danger btn-sm" title="View Result PDF"><i class="fas fa-file-medical-alt"></i></a>
                    <button class="btn btn-outline-secondary btn-sm" onclick="printReport(<?= $o['id'] ?>)" title="Quick Print"><i class="fas fa-print"></i></button>
                  <?php endif; ?>
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
       TAB: RESULTS
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'results'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="results">
        <div class="col-12 col-md-4">
          <select name="res_patient" class="form-select form-select-sm select2" onchange="this.form.submit()">
            <option value="">All Patients</option>
            <?php foreach ($patients as $p): ?>
              <option value="<?= $p['id'] ?>" <?= $resultPid==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <input type="date" name="res_date" class="form-control form-control-sm" value="<?= htmlspecialchars($resultDate) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-auto">
          <a href="?tab=results" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="resultsTable">
          <thead class="table-light">
            <tr>
              <th>Order No</th>
              <th>Patient</th>
              <th>Test</th>
              <th>Result</th>
              <th>Flag</th>
              <th>Normal Range</th>
              <th>Doctor</th>
              <th>Resulted At</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($results)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No results found.</td></tr>
          <?php else: foreach ($results as $r):
            $flagBadge = match($r['result_flag']) {
                'critical' => 'danger',
                'high'     => 'warning text-dark',
                'low'      => 'info text-dark',
                'normal'   => 'success',
                default    => 'secondary'
            };
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($r['order_no']) ?></strong></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($r['patient_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($r['patient_no']) ?></small>
              </td>
              <td><?= htmlspecialchars($r['test_name']) ?></td>
              <td>
                <strong><?= htmlspecialchars($r['result_value']) ?></strong>
                <?php if ($r['unit']): ?><small class="text-muted"><?= htmlspecialchars($r['unit']) ?></small><?php endif; ?>
                <?php if ($r['result_notes']): ?><div><small class="text-muted"><?= htmlspecialchars(substr($r['result_notes'],0,60)) ?></small></div><?php endif; ?>
              </td>
              <td><?php if ($r['result_flag']): ?><span class="badge bg-<?= $flagBadge ?>"><?= strtoupper($r['result_flag']) ?></span><?php else: ?>—<?php endif; ?></td>
              <td><small class="text-muted"><?= htmlspecialchars($r['normal_range'] ?: '—') ?></small></td>
              <td><small><?= htmlspecialchars($r['doctor_name'] ?: '—') ?></small></td>
              <td><small><?= $r['resulted_at'] ? date('d M Y H:i', strtotime($r['resulted_at'])) : '—' ?></small></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-secondary btn-sm" onclick="openResultModal(<?= $r['id'] ?>)" title="Edit Result"><i class="fas fa-edit"></i></button>
                  <a href="<?= APP_URL ?>/modules/health/lab-result-pdf.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-outline-danger btn-sm" title="Result PDF"><i class="fas fa-file-medical-alt"></i></a>
                  <button class="btn btn-outline-secondary btn-sm" onclick="printReport(<?= $r['id'] ?>)" title="Quick Print"><i class="fas fa-print"></i></button>
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
       TAB: TEST CATALOG
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'tests'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="testsTable">
          <thead class="table-light">
            <tr>
              <th>Name</th>
              <th>Category</th>
              <th>Normal Range</th>
              <th>Unit</th>
              <th>Price (KES)</th>
              <th>TAT (hrs)</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($tests)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No tests in catalog. <a href="#" onclick="openTestModal(); return false;">Add one.</a></td></tr>
          <?php else: foreach ($tests as $t): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($t['name']) ?></td>
              <td><?= htmlspecialchars($t['category'] ?: '—') ?></td>
              <td><?= htmlspecialchars($t['normal_range'] ?: '—') ?></td>
              <td><?= htmlspecialchars($t['unit'] ?: '—') ?></td>
              <td><?= number_format($t['price'], 2) ?></td>
              <td><?= $t['turnaround'] ?>h</td>
              <td>
                <?php if ($t['status']==='active'): ?>
                  <span class="badge bg-success">Active</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary btn-sm" onclick="openTestModal(<?= $t['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Delete this test?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_test">
                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
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

</div><!-- /container -->

<!-- ── Modal: Test Catalog (Add/Edit) ────────────────────────────── -->
<div class="modal fade" id="testModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST" id="testForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_test">
      <input type="hidden" name="id" id="testId">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-flask me-2"></i><span id="testModalTitle">Add Lab Test</span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Test Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="testName" class="form-control" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Category</label>
              <input type="text" name="category" id="testCategory" class="form-control" list="catList" placeholder="e.g. Haematology, Chemistry">
              <datalist id="catList">
                <option>Haematology</option><option>Clinical Chemistry</option><option>Microbiology</option>
                <option>Serology / Immunology</option><option>Urinalysis</option><option>Parasitology</option>
                <option>Histopathology</option><option>Radiology</option><option>Cardiology</option>
              </datalist>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">Normal Range</label>
              <input type="text" name="normal_range" id="testRange" class="form-control" placeholder="e.g. 4.5–11.0 × 10³/μL">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Unit</label>
              <input type="text" name="unit" id="testUnit" class="form-control" placeholder="e.g. mg/dL">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold">Price (KES)</label>
              <input type="number" name="price" id="testPrice" class="form-control" min="0" step="0.01" value="0">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-semibold">TAT (hrs)</label>
              <input type="number" name="turnaround" id="testTat" class="form-control" min="1" value="1">
            </div>
            <div class="col-6 col-md-1">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="testStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save Test</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: New Lab Order ───────────────────────────────────────── -->
<div class="modal fade" id="orderModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST" id="orderForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_order">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-flask me-2"></i>New Lab Order</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
              <select name="patient_id" class="form-select select2" required>
                <option value="">Select Patient</option>
                <?php foreach ($patients as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Test <span class="text-danger">*</span></label>
              <select name="test_id" class="form-select select2" required>
                <option value="">Select Test</option>
                <?php foreach ($activeTests as $t): ?>
                  <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?> <?= $t['category'] ? '— '.$t['category'] : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold">Ordering Doctor</label>
              <select name="doctor_id" class="form-select select2">
                <option value="">Select Doctor</option>
                <?php foreach ($doctors as $d): ?>
                  <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold">Priority</label>
              <select name="priority" class="form-select">
                <option value="routine">Routine</option>
                <option value="urgent">Urgent</option>
                <option value="stat">STAT</option>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">Sample Type</label>
              <input type="text" name="sample_type" class="form-control" list="sampleList" placeholder="e.g. Blood, Urine, Swab">
              <datalist id="sampleList">
                <option>Whole Blood</option><option>Serum</option><option>Plasma</option>
                <option>Urine</option><option>Stool</option><option>Sputum</option>
                <option>Swab</option><option>CSF</option><option>Tissue Biopsy</option>
              </datalist>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-plus me-1"></i>Create Order</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Enter / Edit Result ────────────────────────────────── -->
<div class="modal fade" id="resultModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST" id="resultForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="enter_result">
      <input type="hidden" name="id" id="resultOrderId">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i>Enter Lab Result</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <!-- Order info banner -->
          <div class="alert alert-light border mb-3" id="resultOrderInfo">Loading…</div>
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Result Value <span class="text-danger">*</span></label>
              <input type="text" name="result_value" id="resultValue" class="form-control" required placeholder="e.g. 120 or Positive">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Result Flag</label>
              <select name="result_flag" id="resultFlag" class="form-select">
                <option value="">— No Flag —</option>
                <option value="normal">Normal</option>
                <option value="low">Low</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes / Interpretation</label>
              <textarea name="result_notes" id="resultNotes" class="form-control" rows="3" placeholder="Clinical notes, methodology, remarks…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Result</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Print Report Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="printModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-print me-2"></i>Lab Report</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="printArea">Loading…</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" onclick="doPrint()"><i class="fas fa-print me-1"></i>Print</button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
// ── Test catalog modal ────────────────────────────────────────────
function openTestModal(id) {
    document.getElementById('testId').value       = '';
    document.getElementById('testName').value     = '';
    document.getElementById('testCategory').value = '';
    document.getElementById('testRange').value    = '';
    document.getElementById('testUnit').value     = '';
    document.getElementById('testPrice').value    = '0';
    document.getElementById('testTat').value      = '1';
    document.getElementById('testStatus').value   = 'active';
    document.getElementById('testModalTitle').textContent = 'Add Lab Test';

    if (id) {
        document.getElementById('testModalTitle').textContent = 'Edit Lab Test';
        fetch('lab.php?fetch_test=' + id)
            .then(r => r.json())
            .then(d => {
                if (!d.id) return;
                document.getElementById('testId').value       = d.id;
                document.getElementById('testName').value     = d.name     || '';
                document.getElementById('testCategory').value = d.category || '';
                document.getElementById('testRange').value    = d.normal_range || '';
                document.getElementById('testUnit').value     = d.unit     || '';
                document.getElementById('testPrice').value    = d.price    || '0';
                document.getElementById('testTat').value      = d.turnaround || '1';
                document.getElementById('testStatus').value   = d.status   || 'active';
            });
    }

    new bootstrap.Modal(document.getElementById('testModal')).show();
}

// ── Result entry modal ────────────────────────────────────────────
function openResultModal(id) {
    document.getElementById('resultOrderId').value = id;
    document.getElementById('resultValue').value   = '';
    document.getElementById('resultFlag').value    = '';
    document.getElementById('resultNotes').value   = '';
    document.getElementById('resultOrderInfo').innerHTML = '<div class="spinner-border spinner-border-sm text-secondary me-2"></div> Loading order…';

    fetch('lab.php?fetch_order=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.id) { document.getElementById('resultOrderInfo').textContent = 'Order not found.'; return; }
            const flagColor = {critical:'danger', high:'warning', low:'info', normal:'success'};
            document.getElementById('resultOrderInfo').innerHTML =
                `<strong>${d.order_no}</strong> — <strong>${d.patient_name}</strong><br>
                 Test: <strong>${d.test_name}</strong>
                 ${d.normal_range ? `&nbsp;|&nbsp; Normal: <span class="text-muted">${d.normal_range}</span>` : ''}
                 ${d.unit         ? `&nbsp;(${d.unit})`                                                      : ''}`;
            // Pre-fill if already resulted
            if (d.result_value) document.getElementById('resultValue').value = d.result_value;
            if (d.result_flag)  document.getElementById('resultFlag').value  = d.result_flag;
            if (d.result_notes) document.getElementById('resultNotes').value = d.result_notes;
        });

    new bootstrap.Modal(document.getElementById('resultModal')).show();
}

// ── Print lab report ──────────────────────────────────────────────
function printReport(id) {
    document.getElementById('printArea').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-danger"></div></div>';
    const modal = new bootstrap.Modal(document.getElementById('printModal'));
    modal.show();

    fetch('lab.php?print_order=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.order) { document.getElementById('printArea').textContent = 'Order not found.'; return; }
            const o = d.order;
            const flagColors = {critical:'#dc3545', high:'#fd7e14', low:'#0dcaf0', normal:'#198754'};
            const flagColor  = flagColors[o.result_flag] || '#6c757d';
            const now = new Date().toLocaleDateString('en-GB', {day:'2-digit',month:'long',year:'numeric'});
            document.getElementById('printArea').innerHTML = `
              <div id="reportContent" style="font-family:'Segoe UI',Arial,sans-serif;padding:20px;max-width:700px;margin:0 auto">
                <div style="text-align:center;border-bottom:2px solid #c00;padding-bottom:12px;margin-bottom:16px">
                  <h3 style="color:#c00;margin:0">${d.org_name}</h3>
                  <p style="margin:4px 0 0;color:#555;font-size:.9rem">Laboratory Report</p>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;background:#f8f9fa;padding:12px;border-radius:6px">
                  <div><span style="color:#666;font-size:.85rem">Patient</span><br><strong>${o.patient_name}</strong> (${o.patient_no})</div>
                  <div><span style="color:#666;font-size:.85rem">Order No</span><br><strong>${o.order_no}</strong></div>
                  <div><span style="color:#666;font-size:.85rem">Doctor</span><br>${o.doctor_name || '—'}</div>
                  <div><span style="color:#666;font-size:.85rem">Ordered</span><br>${o.ordered_at ? new Date(o.ordered_at).toLocaleDateString('en-GB') : '—'}</div>
                  <div><span style="color:#666;font-size:.85rem">Priority</span><br><span style="text-transform:uppercase;font-weight:700;color:${o.priority==='stat'?'#dc3545':o.priority==='urgent'?'#fd7e14':'#6c757d'}">${o.priority}</span></div>
                  <div><span style="color:#666;font-size:.85rem">Sample</span><br>${o.sample_type || '—'}</div>
                </div>
                <table style="width:100%;border-collapse:collapse;margin-bottom:16px">
                  <thead>
                    <tr style="background:#c00;color:white">
                      <th style="padding:8px 12px;text-align:left">Test Name</th>
                      <th style="padding:8px 12px;text-align:left">Category</th>
                      <th style="padding:8px 12px;text-align:left">Result</th>
                      <th style="padding:8px 12px;text-align:left">Unit</th>
                      <th style="padding:8px 12px;text-align:left">Normal Range</th>
                      <th style="padding:8px 12px;text-align:left">Flag</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr style="border-bottom:1px solid #eee">
                      <td style="padding:10px 12px;font-weight:700">${o.test_name}</td>
                      <td style="padding:10px 12px;color:#555">${o.category || '—'}</td>
                      <td style="padding:10px 12px;font-weight:700;font-size:1.1rem">${o.result_value || '—'}</td>
                      <td style="padding:10px 12px;color:#555">${o.unit || '—'}</td>
                      <td style="padding:10px 12px;color:#555">${o.normal_range || '—'}</td>
                      <td style="padding:10px 12px"><span style="background:${flagColor};color:white;padding:2px 8px;border-radius:4px;font-size:.8rem;font-weight:700">${(o.result_flag||'—').toUpperCase()}</span></td>
                    </tr>
                  </tbody>
                </table>
                ${o.result_notes ? `<div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;padding:12px;margin-bottom:16px"><strong>Notes:</strong> ${o.result_notes}</div>` : ''}
                <div style="border-top:1px solid #eee;padding-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:.85rem;color:#555">
                  <div>Resulted by: <strong>${o.resulted_by_name || '—'}</strong></div>
                  <div style="text-align:right">Date: <strong>${o.resulted_at ? new Date(o.resulted_at).toLocaleDateString('en-GB') : '—'}</strong></div>
                </div>
                <div style="text-align:center;color:#aaa;font-size:.75rem;margin-top:16px;border-top:1px solid #eee;padding-top:8px">
                  Report generated on ${now} — ${d.org_name}
                </div>
              </div>`;
        });
}

function doPrint() {
    const content = document.getElementById('reportContent');
    if (!content) return;
    const w = window.open('', '', 'width=800,height=900');
    w.document.write('<html><head><title>Lab Report</title></head><body>' + content.outerHTML + '</body></html>');
    w.document.close();
    w.focus();
    w.print();
    w.close();
}

// ── DataTables init ───────────────────────────────────────────────
$(document).ready(function () {
    if ($('#ordersTable').length)  $('#ordersTable').DataTable({ pageLength: 25, order: [[6,'desc']], columnDefs:[{orderable:false,targets:[7]}] });
    if ($('#resultsTable').length) $('#resultsTable').DataTable({ pageLength: 25, order: [[7,'desc']], columnDefs:[{orderable:false,targets:[8]}] });
    if ($('#testsTable').length)   $('#testsTable').DataTable({ pageLength: 25, order: [[0,'asc']], columnDefs:[{orderable:false,targets:[7]}] });
    if (typeof $.fn.select2 !== 'undefined') {
        $('.select2').select2({ theme:'bootstrap-5', dropdownParent: document.body, width:'100%' });
    }
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
