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
    ['url'=>'telemedicine.php',  'icon'=>'fas fa-video',               'label'=>'Telemedicine'],
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-users',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'AI Analytics'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
    ['url'=>'settings.php',      'icon'=>'fas fa-cog',                 'label'=>'Settings'],
];

// ── CSV Export — must run before any HTML output ──────────────────
if (!empty($_GET['export'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    try {
        $__c = $pdo->prepare("SELECT setting_value FROM health_settings WHERE org_id=? AND setting_key='h_currency_symbol' LIMIT 1");
        $__c->execute([$orgId]);
        $GLOBALS['hCurrencySymbol'] = $__c->fetchColumn() ?: (defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'LRD');
    } catch (Throwable $__e) { $GLOBALS['hCurrencySymbol'] = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'LRD'; }

    $fr   = preg_replace('/[^0-9\-]/', '', $_GET['from'] ?? date('Y-m-01'));
    $to2  = preg_replace('/[^0-9\-]/', '', $_GET['to']   ?? date('Y-m-d'));
    $type = preg_replace('/[^a-z_]/', '', $_GET['export'] ?? '');

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $type . '_report_' . date('Ymd') . '.csv"');
    $fh = fopen('php://output', 'w');

    if ($type === 'financial') {
        fputcsv($fh, ['Bill No', 'Patient', 'Date', 'Total Amount', 'Paid Amount', 'Balance', 'Status']);
        try {
            $st = $pdo->prepare("
                SELECT b.bill_no, CONCAT(p.first_name,' ',p.last_name),
                       DATE(b.created_at), b.total_amount, b.paid_amount,
                       (b.total_amount - b.paid_amount), b.status
                FROM health_bills b
                LEFT JOIN health_patients p ON p.id = b.patient_id
                WHERE b.org_id=? AND DATE(b.created_at) BETWEEN ? AND ?
                ORDER BY b.created_at DESC
            ");
            $st->execute([$orgId, $fr, $to2]);
            while ($r = $st->fetch(PDO::FETCH_NUM)) fputcsv($fh, $r);
        } catch (Throwable $e) {}

    } elseif ($type === 'patients') {
        fputcsv($fh, ['Patient No', 'Name', 'Gender', 'Date of Birth', 'Phone', 'Registration Date']);
        try {
            $st = $pdo->prepare("
                SELECT patient_no, CONCAT(first_name,' ',last_name), gender,
                       date_of_birth, phone, DATE(created_at)
                FROM health_patients
                WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ?
                ORDER BY created_at DESC
            ");
            $st->execute([$orgId, $fr, $to2]);
            while ($r = $st->fetch(PDO::FETCH_NUM)) fputcsv($fh, $r);
        } catch (Throwable $e) {}

    } elseif ($type === 'lab') {
        fputcsv($fh, ['Order ID', 'Test', 'Patient', 'Doctor', 'Priority', 'Status', 'Ordered', 'Resulted']);
        try {
            $st = $pdo->prepare("
                SELECT o.id, t.name,
                       CONCAT(p.first_name,' ',p.last_name),
                       CONCAT(d.first_name,' ',d.last_name),
                       o.priority, o.status,
                       DATE(o.created_at), DATE(o.resulted_at)
                FROM health_lab_orders o
                LEFT JOIN health_lab_tests t ON t.id = o.test_id
                LEFT JOIN health_patients p ON p.id = o.patient_id
                LEFT JOIN health_doctors d ON d.id = o.doctor_id
                WHERE o.org_id=? AND DATE(o.created_at) BETWEEN ? AND ?
                ORDER BY o.created_at DESC
            ");
            $st->execute([$orgId, $fr, $to2]);
            while ($r = $st->fetch(PDO::FETCH_NUM)) fputcsv($fh, $r);
        } catch (Throwable $e) {}

    } elseif ($type === 'pharmacy') {
        fputcsv($fh, ['ID', 'Medicine', 'Patient', 'Quantity', 'Unit Price', 'Total', 'Date']);
        try {
            $st = $pdo->prepare("
                SELECT d.id, m.name,
                       CONCAT(p.first_name,' ',p.last_name),
                       d.quantity, m.unit_price, d.total_amount,
                       DATE(COALESCE(d.dispensed_at, d.created_at))
                FROM health_dispensing d
                LEFT JOIN health_medicines m ON m.id = d.medicine_id
                LEFT JOIN health_patients p ON p.id = d.patient_id
                WHERE d.org_id=? AND DATE(COALESCE(d.dispensed_at, d.created_at)) BETWEEN ? AND ?
                ORDER BY d.created_at DESC
            ");
            $st->execute([$orgId, $fr, $to2]);
            while ($r = $st->fetch(PDO::FETCH_NUM)) fputcsv($fh, $r);
        } catch (Throwable $e) {}

    } elseif ($type === 'appointments') {
        fputcsv($fh, ['ID', 'Patient', 'Doctor', 'Date', 'Time', 'Type', 'Status', 'Complaint']);
        try {
            $st = $pdo->prepare("
                SELECT a.id,
                       CONCAT(p.first_name,' ',p.last_name),
                       CONCAT(d.first_name,' ',d.last_name),
                       a.date, a.time, a.type, a.status, a.complaint
                FROM health_appointments a
                LEFT JOIN health_patients p ON p.id = a.patient_id
                LEFT JOIN health_doctors d ON d.id = a.doctor_id
                WHERE a.org_id=? AND a.date BETWEEN ? AND ?
                ORDER BY a.date DESC, a.time ASC
            ");
            $st->execute([$orgId, $fr, $to2]);
            while ($r = $st->fetch(PDO::FETCH_NUM)) fputcsv($fh, $r);
        } catch (Throwable $e) {}
    }

    fclose($fh);
    exit;
}

// ── Page ──────────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$from = preg_replace('/[^0-9\-]/', '', $_GET['from'] ?? date('Y-m-01'));
$to   = preg_replace('/[^0-9\-]/', '', $_GET['to']   ?? date('Y-m-d'));
$tab  = in_array($_GET['tab'] ?? '', ['financial','patients','lab','pharmacy','appointments'])
        ? $_GET['tab'] : 'financial';

function rpt_val(PDO $pdo, string $sql, array $p = []): mixed {
    try { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchColumn(); return $v === false ? 0 : $v; }
    catch (Throwable $e) { return 0; }
}
function rpt_rows(PDO $pdo, string $sql, array $p = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }
}

// ── FINANCIAL ─────────────────────────────────────────────────────
$finBilled    = (float)rpt_val($pdo,"SELECT COALESCE(SUM(total_amount),0) FROM health_bills WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ?",[$orgId,$from,$to]);
$finPaid      = (float)rpt_val($pdo,"SELECT COALESCE(SUM(paid_amount),0) FROM health_bills WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ?",[$orgId,$from,$to]);
$finOutstand  = max(0, $finBilled - $finPaid);
$finBillCount = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_bills WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ?",[$orgId,$from,$to]);

$monthlyRev = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $monthlyRev[] = [
        'month'  => date('M Y', strtotime("-$i months")),
        'paid'   => (float)rpt_val($pdo,"SELECT COALESCE(SUM(paid_amount),0) FROM health_bills WHERE org_id=? AND DATE_FORMAT(created_at,'%Y-%m')=?",[$orgId,$m]),
        'billed' => (float)rpt_val($pdo,"SELECT COALESCE(SUM(total_amount),0) FROM health_bills WHERE org_id=? AND DATE_FORMAT(created_at,'%Y-%m')=?",[$orgId,$m]),
    ];
}

$topServices  = rpt_rows($pdo,"
    SELECT COALESCE(bi.description, bi.service_name, 'Service') AS svc,
           COUNT(*) AS cnt,
           COALESCE(SUM(bi.unit_price * COALESCE(bi.quantity,1)),0) AS revenue
    FROM health_bill_items bi
    JOIN health_bills b ON b.id = bi.bill_id
    WHERE b.org_id=? AND DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY svc ORDER BY revenue DESC LIMIT 8
",[$orgId,$from,$to]);

$billsByStatus = rpt_rows($pdo,"
    SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total
    FROM health_bills WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY status ORDER BY cnt DESC
",[$orgId,$from,$to]);

// ── PATIENTS ──────────────────────────────────────────────────────
$patTotal    = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_patients WHERE org_id=?",[$orgId]);
$patNew      = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_patients WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ?",[$orgId,$from,$to]);
$patMale     = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_patients WHERE org_id=? AND gender='male'",[$orgId]);
$patFemale   = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_patients WHERE org_id=? AND gender='female'",[$orgId]);
$patOther    = max(0, $patTotal - $patMale - $patFemale);

$ageRow = rpt_rows($pdo,"
    SELECT
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) < 18  THEN 1 ELSE 0 END) AS u18,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 18 AND 35 THEN 1 ELSE 0 END) AS a18_35,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 36 AND 60 THEN 1 ELSE 0 END) AS a36_60,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) > 60  THEN 1 ELSE 0 END) AS o60
    FROM health_patients WHERE org_id=? AND date_of_birth IS NOT NULL
",[$orgId]);
$ag = $ageRow[0] ?? ['u18'=>0,'a18_35'=>0,'a36_60'=>0,'o60'=>0];

$regByDay = rpt_rows($pdo,"
    SELECT DATE(created_at) AS d, COUNT(*) AS cnt
    FROM health_patients WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at) ORDER BY d ASC
",[$orgId,$from,$to]);

$topDiagnoses = rpt_rows($pdo,"
    SELECT diagnosis, COUNT(*) AS cnt
    FROM health_records WHERE org_id=? AND diagnosis != ''
    GROUP BY diagnosis ORDER BY cnt DESC LIMIT 8
",[$orgId]);

// ── LABORATORY ────────────────────────────────────────────────────
$labTotal    = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_lab_orders WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ?",[$orgId,$from,$to]);
$labResulted = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_lab_orders WHERE org_id=? AND status='resulted' AND DATE(created_at) BETWEEN ? AND ?",[$orgId,$from,$to]);
$labPending  = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_lab_orders WHERE org_id=? AND status IN ('ordered','collected','processing') AND DATE(created_at) BETWEEN ? AND ?",[$orgId,$from,$to]);
$labStat     = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_lab_orders WHERE org_id=? AND priority='stat' AND DATE(created_at) BETWEEN ? AND ?",[$orgId,$from,$to]);

$topTests = rpt_rows($pdo,"
    SELECT COALESCE(t.name,'Unknown Test') AS test_name,
           COALESCE(t.category,'') AS category,
           COUNT(o.id) AS cnt,
           COALESCE(SUM(t.price),0) AS revenue
    FROM health_lab_orders o
    LEFT JOIN health_lab_tests t ON t.id = o.test_id
    WHERE o.org_id=? AND DATE(o.created_at) BETWEEN ? AND ?
    GROUP BY o.test_id ORDER BY cnt DESC LIMIT 8
",[$orgId,$from,$to]);

$labByStatus = rpt_rows($pdo,"
    SELECT status, COUNT(*) AS cnt
    FROM health_lab_orders WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY status ORDER BY cnt DESC
",[$orgId,$from,$to]);

$labByDay = rpt_rows($pdo,"
    SELECT DATE(created_at) AS d, COUNT(*) AS cnt
    FROM health_lab_orders WHERE org_id=? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at) ORDER BY d ASC
",[$orgId,$from,$to]);

// ── PHARMACY ──────────────────────────────────────────────────────
$rxCount    = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_dispensing WHERE org_id=? AND DATE(COALESCE(dispensed_at,created_at)) BETWEEN ? AND ?",[$orgId,$from,$to]);
$rxRevenue  = (float)rpt_val($pdo,"SELECT COALESCE(SUM(total_amount),0) FROM health_dispensing WHERE org_id=? AND DATE(COALESCE(dispensed_at,created_at)) BETWEEN ? AND ?",[$orgId,$from,$to]);
$rxPatients = (int)rpt_val($pdo,"SELECT COUNT(DISTINCT patient_id) FROM health_dispensing WHERE org_id=? AND DATE(COALESCE(dispensed_at,created_at)) BETWEEN ? AND ?",[$orgId,$from,$to]);

$topMeds = rpt_rows($pdo,"
    SELECT COALESCE(m.name,'Unknown') AS med_name,
           COALESCE(m.form,'') AS form,
           SUM(d.quantity) AS qty,
           COALESCE(SUM(d.total_amount),0) AS revenue
    FROM health_dispensing d
    LEFT JOIN health_medicines m ON m.id = d.medicine_id
    WHERE d.org_id=? AND DATE(COALESCE(d.dispensed_at,d.created_at)) BETWEEN ? AND ?
    GROUP BY d.medicine_id ORDER BY qty DESC LIMIT 8
",[$orgId,$from,$to]);

$rxByDay = rpt_rows($pdo,"
    SELECT DATE(COALESCE(dispensed_at,created_at)) AS d,
           COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS rev
    FROM health_dispensing WHERE org_id=? AND DATE(COALESCE(dispensed_at,created_at)) BETWEEN ? AND ?
    GROUP BY DATE(COALESCE(dispensed_at,created_at)) ORDER BY d ASC
",[$orgId,$from,$to]);

// ── APPOINTMENTS ──────────────────────────────────────────────────
$apptTotal     = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_appointments WHERE org_id=? AND date BETWEEN ? AND ?",[$orgId,$from,$to]);
$apptCompleted = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_appointments WHERE org_id=? AND status='completed' AND date BETWEEN ? AND ?",[$orgId,$from,$to]);
$apptCancelled = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_appointments WHERE org_id=? AND status='cancelled' AND date BETWEEN ? AND ?",[$orgId,$from,$to]);
$apptNoShow    = (int)rpt_val($pdo,"SELECT COUNT(*) FROM health_appointments WHERE org_id=? AND status='no_show' AND date BETWEEN ? AND ?",[$orgId,$from,$to]);

$apptByDoctor = rpt_rows($pdo,"
    SELECT CONCAT(d.first_name,' ',d.last_name) AS doctor,
           COALESCE(d.specialty,'') AS specialty,
           COUNT(a.id) AS total,
           SUM(CASE WHEN a.status='completed' THEN 1 ELSE 0 END) AS completed,
           SUM(CASE WHEN a.status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
           SUM(CASE WHEN a.status='no_show'   THEN 1 ELSE 0 END) AS noshow
    FROM health_appointments a
    LEFT JOIN health_doctors d ON d.id = a.doctor_id
    WHERE a.org_id=? AND a.date BETWEEN ? AND ?
    GROUP BY a.doctor_id ORDER BY total DESC LIMIT 10
",[$orgId,$from,$to]);

$apptByType = rpt_rows($pdo,"
    SELECT COALESCE(type,'General') AS type, COUNT(*) AS cnt
    FROM health_appointments WHERE org_id=? AND date BETWEEN ? AND ?
    GROUP BY type ORDER BY cnt DESC
",[$orgId,$from,$to]);

$apptByDay = rpt_rows($pdo,"
    SELECT date AS d, COUNT(*) AS cnt
    FROM health_appointments WHERE org_id=? AND date BETWEEN ? AND ?
    GROUP BY date ORDER BY date ASC
",[$orgId,$from,$to]);

// ── JSON for charts ───────────────────────────────────────────────
$jMonthLabels   = json_encode(array_column($monthlyRev, 'month'));
$jMonthPaid     = json_encode(array_column($monthlyRev, 'paid'));
$jMonthBilled   = json_encode(array_column($monthlyRev, 'billed'));

$jStatusLabels  = json_encode(array_column($billsByStatus, 'status'));
$jStatusCounts  = json_encode(array_column($billsByStatus, 'cnt'));

$jRegDayLabels  = json_encode(array_column($regByDay, 'd'));
$jRegDayCounts  = json_encode(array_column($regByDay, 'cnt'));
$jAgeLabels     = json_encode(['Under 18','18-35','36-60','Over 60']);
$jAgeData       = json_encode([(int)$ag['u18'],(int)$ag['a18_35'],(int)$ag['a36_60'],(int)$ag['o60']]);

$jLabDayLabels  = json_encode(array_column($labByDay, 'd'));
$jLabDayCounts  = json_encode(array_column($labByDay, 'cnt'));
$jLabStLabels   = json_encode(array_column($labByStatus, 'status'));
$jLabStCounts   = json_encode(array_column($labByStatus, 'cnt'));

$jRxDayLabels   = json_encode(array_column($rxByDay, 'd'));
$jRxDayRev      = json_encode(array_map('floatval', array_column($rxByDay, 'rev')));

$jApptDayLabels = json_encode(array_column($apptByDay, 'd'));
$jApptDayCounts = json_encode(array_column($apptByDay, 'cnt'));
$jApptTypeLabels= json_encode(array_column($apptByType, 'type'));
$jApptTypeCounts= json_encode(array_column($apptByType, 'cnt'));

// Helpers for bill status badge
function rptStatusBadge(string $status): string {
    $map = ['paid'=>'success','partial'=>'warning','sent'=>'info','overdue'=>'danger','cancelled'=>'secondary','draft'=>'light text-dark'];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . ucfirst($status) . '</span>';
}

$qs = http_build_query(['from' => $from, 'to' => $to]);
?>

<style>
.rpt-kpi{background:#fff;border:1px solid #e9ecef;border-radius:10px;padding:16px 18px;display:flex;align-items:center;gap:14px}
.rpt-kpi-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.rpt-kpi-val{font-size:1.4rem;font-weight:800;line-height:1;color:#1a1a2e}
.rpt-kpi-lbl{font-size:.75rem;color:#6c757d;margin-top:2px}
.tab-btn{border:none;background:none;padding:9px 18px;font-size:.88rem;font-weight:600;color:#6c757d;border-bottom:3px solid transparent;cursor:pointer;transition:all .2s;white-space:nowrap}
.tab-btn.active{color:#e74c3c;border-bottom-color:#e74c3c}
.tab-btn:hover:not(.active){color:#343a40;border-bottom-color:#dee2e6}
.tab-pane-rpt{display:none}.tab-pane-rpt.show{display:block}
.chart-wrap{position:relative;height:260px}
.preset-btn{font-size:.75rem;padding:3px 9px}
@media print{.no-print{display:none!important}}
</style>

<!-- Page Header -->
<div class="page-header d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Reports &amp; Analytics</h4>
    <p class="text-muted mb-0 small">Date range: <strong><?= date('d M Y', strtotime($from)) ?></strong> to <strong><?= date('d M Y', strtotime($to)) ?></strong></p>
  </div>
  <div class="d-flex gap-2 no-print">
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
  </div>
</div>

<!-- Date Range Filter -->
<div class="card mb-4 no-print">
  <div class="card-body py-3">
    <form id="rptForm" method="get" action="" class="d-flex flex-wrap align-items-end gap-3">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <label class="form-label mb-0 fw-semibold small text-muted text-uppercase" style="letter-spacing:.5px">From</label>
        <input type="date" id="inp_from" name="from" class="form-control form-control-sm" style="width:150px" value="<?= e($from) ?>">
        <label class="form-label mb-0 fw-semibold small text-muted text-uppercase" style="letter-spacing:.5px">To</label>
        <input type="date" id="inp_to" name="to" class="form-control form-control-sm" style="width:150px" value="<?= e($to) ?>">
        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-filter me-1"></i>Apply</button>
      </div>
      <div class="d-flex gap-1 flex-wrap">
        <button type="button" class="btn btn-outline-secondary preset-btn" onclick="setRange('today')">Today</button>
        <button type="button" class="btn btn-outline-secondary preset-btn" onclick="setRange('week')">This Week</button>
        <button type="button" class="btn btn-outline-secondary preset-btn" onclick="setRange('month')">This Month</button>
        <button type="button" class="btn btn-outline-secondary preset-btn" onclick="setRange('lastmonth')">Last Month</button>
        <button type="button" class="btn btn-outline-secondary preset-btn" onclick="setRange('year')">This Year</button>
        <button type="button" class="btn btn-outline-secondary preset-btn" onclick="setRange('all')">All Time</button>
      </div>
    </form>
  </div>
</div>

<!-- Tab Navigation -->
<div class="border-bottom mb-4">
  <div class="d-flex gap-0 overflow-auto no-print">
    <?php foreach ([
        'financial'    => ['fas fa-coins',          'Financial'],
        'patients'     => ['fas fa-procedures',     'Patients'],
        'lab'          => ['fas fa-flask',           'Laboratory'],
        'pharmacy'     => ['fas fa-pills',           'Pharmacy'],
        'appointments' => ['fas fa-calendar-check',  'Appointments'],
    ] as $t => [$ico, $lbl]): ?>
    <a href="?<?= $qs ?>&tab=<?= $t ?>" class="tab-btn <?= $tab===$t?'active':'' ?>">
      <i class="<?= $ico ?> me-1"></i><?= $lbl ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── FINANCIAL TAB ──────────────────────────────────────────── -->
<?php if ($tab === 'financial'): ?>
<div>
  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#fff3cd"><i class="fas fa-file-invoice-dollar text-warning"></i></div>
        <div><div class="rpt-kpi-val"><?= hMoney($finBilled) ?></div><div class="rpt-kpi-lbl">Total Billed</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#d1e7dd"><i class="fas fa-check-circle text-success"></i></div>
        <div><div class="rpt-kpi-val"><?= hMoney($finPaid) ?></div><div class="rpt-kpi-lbl">Total Collected</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#f8d7da"><i class="fas fa-exclamation-circle text-danger"></i></div>
        <div><div class="rpt-kpi-val"><?= hMoney($finOutstand) ?></div><div class="rpt-kpi-lbl">Outstanding Balance</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#cff4fc"><i class="fas fa-receipt" style="color:#0dcaf0"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($finBillCount) ?></div><div class="rpt-kpi-lbl">Bills Raised</div></div>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <!-- Monthly Revenue Chart -->
    <div class="col-lg-8">
      <div class="card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h6 class="mb-0 fw-semibold"><i class="fas fa-chart-line me-2 text-danger"></i>6-Month Revenue Trend</h6>
        </div>
        <div class="card-body"><div class="chart-wrap"><canvas id="chartRevTrend"></canvas></div></div>
      </div>
    </div>
    <!-- Bills by Status -->
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="fas fa-chart-pie me-2 text-danger"></i>Bills by Status</h6></div>
        <div class="card-body">
          <div class="chart-wrap" style="height:200px"><canvas id="chartBillStatus"></canvas></div>
          <div class="mt-3">
            <?php foreach ($billsByStatus as $bs): ?>
            <div class="d-flex justify-content-between align-items-center mb-1">
              <?= rptStatusBadge($bs['status']) ?>
              <span class="small fw-semibold"><?= number_format($bs['cnt']) ?> &nbsp;·&nbsp; <?= hMoney($bs['total']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($billsByStatus)): ?><p class="text-center text-muted small py-3">No billing data in range.</p><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Top Services -->
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h6 class="mb-0 fw-semibold"><i class="fas fa-list-ol me-2 text-danger"></i>Top Services by Revenue</h6>
      <a href="?<?= $qs ?>&tab=financial&export=financial" class="btn btn-sm btn-outline-success no-print"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light"><tr><th>#</th><th>Service / Description</th><th class="text-center">Qty</th><th class="text-end">Revenue</th></tr></thead>
          <tbody>
            <?php if (empty($topServices)): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">No itemized billing data in selected range.</td></tr>
            <?php else: foreach ($topServices as $i => $svc): ?>
            <tr>
              <td class="text-muted small"><?= $i+1 ?></td>
              <td class="fw-semibold"><?= e($svc['svc']) ?></td>
              <td class="text-center"><?= number_format($svc['cnt']) ?></td>
              <td class="text-end fw-bold text-success"><?= hMoney((float)$svc['revenue']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ── PATIENTS TAB ──────────────────────────────────────────────── -->
<?php elseif ($tab === 'patients'): ?>
<div>
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#f8d7da"><i class="fas fa-procedures text-danger"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($patTotal) ?></div><div class="rpt-kpi-lbl">Total Patients</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#d1e7dd"><i class="fas fa-user-plus text-success"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($patNew) ?></div><div class="rpt-kpi-lbl">New in Range</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#cff4fc"><i class="fas fa-mars" style="color:#0d6efd"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($patMale) ?></div><div class="rpt-kpi-lbl">Male Patients</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#f3d4e8"><i class="fas fa-venus" style="color:#d63384"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($patFemale) ?></div><div class="rpt-kpi-lbl">Female Patients</div></div>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-8">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="fas fa-chart-line me-2 text-danger"></i>Patient Registrations (Daily)</h6></div>
        <div class="card-body"><div class="chart-wrap"><canvas id="chartRegTrend"></canvas></div></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="fas fa-venus-mars me-2 text-danger"></i>Gender Distribution</h6></div>
        <div class="card-body d-flex flex-column justify-content-center">
          <div class="chart-wrap" style="height:180px"><canvas id="chartGender"></canvas></div>
          <div class="mt-3 text-center small text-muted">
            Male: <strong class="text-primary"><?= $patMale ?></strong> &nbsp;|&nbsp;
            Female: <strong class="text-danger"><?= $patFemale ?></strong> &nbsp;|&nbsp;
            Other: <strong class="text-secondary"><?= $patOther ?></strong>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="fas fa-birthday-cake me-2 text-danger"></i>Age Group Distribution</h6></div>
        <div class="card-body"><div class="chart-wrap" style="height:200px"><canvas id="chartAge"></canvas></div></div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h6 class="mb-0 fw-semibold"><i class="fas fa-file-prescription me-2 text-danger"></i>Top Diagnoses (All Time)</h6>
          <a href="?<?= $qs ?>&tab=patients&export=patients" class="btn btn-sm btn-outline-success no-print"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead class="table-light"><tr><th>#</th><th>Diagnosis</th><th class="text-end">Cases</th></tr></thead>
              <tbody>
                <?php if (empty($topDiagnoses)): ?>
                <tr><td colspan="3" class="text-center text-muted py-4">No diagnosis data recorded.</td></tr>
                <?php else: foreach ($topDiagnoses as $i => $dx): ?>
                <tr>
                  <td class="text-muted small"><?= $i+1 ?></td>
                  <td class="fw-semibold"><?= e($dx['diagnosis']) ?></td>
                  <td class="text-end"><span class="badge bg-danger"><?= $dx['cnt'] ?></span></td>
                </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── LABORATORY TAB ────────────────────────────────────────────── -->
<?php elseif ($tab === 'lab'): ?>
<div>
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#cff4fc"><i class="fas fa-flask" style="color:#0dcaf0"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($labTotal) ?></div><div class="rpt-kpi-lbl">Total Orders</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#d1e7dd"><i class="fas fa-check-double text-success"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($labResulted) ?></div><div class="rpt-kpi-lbl">Resulted</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#fff3cd"><i class="fas fa-clock text-warning"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($labPending) ?></div><div class="rpt-kpi-lbl">Pending</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#f8d7da"><i class="fas fa-bolt text-danger"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($labStat) ?></div><div class="rpt-kpi-lbl">STAT / Urgent</div></div>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-8">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="fas fa-chart-bar me-2 text-danger"></i>Daily Lab Volume</h6></div>
        <div class="card-body"><div class="chart-wrap"><canvas id="chartLabDay"></canvas></div></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="fas fa-chart-pie me-2 text-danger"></i>Orders by Status</h6></div>
        <div class="card-body d-flex flex-column justify-content-center">
          <div class="chart-wrap" style="height:200px"><canvas id="chartLabStatus"></canvas></div>
          <div class="mt-3">
            <?php foreach ($labByStatus as $ls): ?>
            <div class="d-flex justify-content-between small mb-1">
              <span class="text-capitalize text-muted"><?= e($ls['status']) ?></span>
              <strong><?= number_format($ls['cnt']) ?></strong>
            </div>
            <?php endforeach; ?>
            <?php if (empty($labByStatus)): ?><p class="text-muted text-center small py-3">No lab data in range.</p><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h6 class="mb-0 fw-semibold"><i class="fas fa-list-ol me-2 text-danger"></i>Top Lab Tests by Volume</h6>
      <a href="?<?= $qs ?>&tab=lab&export=lab" class="btn btn-sm btn-outline-success no-print"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light"><tr><th>#</th><th>Test Name</th><th>Category</th><th class="text-center">Orders</th><th class="text-end">Revenue</th></tr></thead>
          <tbody>
            <?php if (empty($topTests)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No lab orders in selected range.</td></tr>
            <?php else: foreach ($topTests as $i => $t): ?>
            <tr>
              <td class="text-muted small"><?= $i+1 ?></td>
              <td class="fw-semibold"><?= e($t['test_name']) ?></td>
              <td><span class="badge bg-secondary"><?= e($t['category'] ?: 'General') ?></span></td>
              <td class="text-center fw-bold"><?= number_format($t['cnt']) ?></td>
              <td class="text-end text-success fw-semibold"><?= hMoney((float)$t['revenue']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ── PHARMACY TAB ───────────────────────────────────────────────── -->
<?php elseif ($tab === 'pharmacy'): ?>
<div>
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#d1e7dd"><i class="fas fa-pills text-success"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($rxCount) ?></div><div class="rpt-kpi-lbl">Dispenses</div></div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#fff3cd"><i class="fas fa-coins text-warning"></i></div>
        <div><div class="rpt-kpi-val"><?= hMoney($rxRevenue) ?></div><div class="rpt-kpi-lbl">Pharmacy Revenue</div></div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#f8d7da"><i class="fas fa-users text-danger"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($rxPatients) ?></div><div class="rpt-kpi-lbl">Unique Patients</div></div>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="fas fa-chart-area me-2 text-danger"></i>Daily Dispensing Revenue</h6></div>
    <div class="card-body"><div class="chart-wrap"><canvas id="chartRxDay"></canvas></div></div>
  </div>

  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h6 class="mb-0 fw-semibold"><i class="fas fa-list-ol me-2 text-danger"></i>Top Medicines Dispensed</h6>
      <a href="?<?= $qs ?>&tab=pharmacy&export=pharmacy" class="btn btn-sm btn-outline-success no-print"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light"><tr><th>#</th><th>Medicine</th><th>Form</th><th class="text-center">Qty Dispensed</th><th class="text-end">Revenue</th></tr></thead>
          <tbody>
            <?php if (empty($topMeds)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No dispensing records in selected range.</td></tr>
            <?php else: foreach ($topMeds as $i => $m): ?>
            <tr>
              <td class="text-muted small"><?= $i+1 ?></td>
              <td class="fw-semibold"><?= e($m['med_name']) ?></td>
              <td><span class="badge bg-light text-dark"><?= e($m['form'] ?: '—') ?></span></td>
              <td class="text-center fw-bold"><?= number_format($m['qty']) ?></td>
              <td class="text-end text-success fw-semibold"><?= hMoney((float)$m['revenue']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ── APPOINTMENTS TAB ───────────────────────────────────────────── -->
<?php elseif ($tab === 'appointments'): ?>
<div>
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#cff4fc"><i class="fas fa-calendar-alt" style="color:#0dcaf0"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($apptTotal) ?></div><div class="rpt-kpi-lbl">Total</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#d1e7dd"><i class="fas fa-check text-success"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($apptCompleted) ?></div><div class="rpt-kpi-lbl">Completed</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#f8d7da"><i class="fas fa-times-circle text-danger"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($apptCancelled) ?></div><div class="rpt-kpi-lbl">Cancelled</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="rpt-kpi">
        <div class="rpt-kpi-icon" style="background:#fff3cd"><i class="fas fa-user-slash text-warning"></i></div>
        <div><div class="rpt-kpi-val"><?= number_format($apptNoShow) ?></div><div class="rpt-kpi-lbl">No Shows</div></div>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-8">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="fas fa-chart-line me-2 text-danger"></i>Appointment Volume (Daily)</h6></div>
        <div class="card-body"><div class="chart-wrap"><canvas id="chartApptDay"></canvas></div></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="fas fa-chart-pie me-2 text-danger"></i>By Appointment Type</h6></div>
        <div class="card-body d-flex flex-column justify-content-center">
          <div class="chart-wrap" style="height:200px"><canvas id="chartApptType"></canvas></div>
          <div class="mt-2">
            <?php foreach ($apptByType as $at): ?>
            <div class="d-flex justify-content-between small mb-1">
              <span class="text-muted"><?= e($at['type']) ?></span>
              <strong><?= number_format($at['cnt']) ?></strong>
            </div>
            <?php endforeach; ?>
            <?php if (empty($apptByType)): ?><p class="text-muted text-center small py-3">No appointment data.</p><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- By Doctor -->
  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h6 class="mb-0 fw-semibold"><i class="fas fa-user-md me-2 text-danger"></i>Appointment Performance by Doctor</h6>
      <a href="?<?= $qs ?>&tab=appointments&export=appointments" class="btn btn-sm btn-outline-success no-print"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr><th>Doctor</th><th>Specialty</th><th class="text-center">Total</th><th class="text-center text-success">Completed</th><th class="text-center text-danger">Cancelled</th><th class="text-center text-warning">No Show</th><th class="text-center">Rate</th></tr>
          </thead>
          <tbody>
            <?php if (empty($apptByDoctor)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No appointment data in selected range.</td></tr>
            <?php else: foreach ($apptByDoctor as $dr): $rate = $dr['total'] > 0 ? round(($dr['completed'] / $dr['total']) * 100) : 0; ?>
            <tr>
              <td class="fw-semibold"><?= e($dr['doctor'] ?: 'Unassigned') ?></td>
              <td class="small text-muted"><?= e($dr['specialty'] ?: '—') ?></td>
              <td class="text-center fw-bold"><?= $dr['total'] ?></td>
              <td class="text-center text-success fw-semibold"><?= $dr['completed'] ?></td>
              <td class="text-center text-danger"><?= $dr['cancelled'] ?></td>
              <td class="text-center text-warning"><?= $dr['noshow'] ?></td>
              <td class="text-center">
                <div class="d-flex align-items-center gap-1">
                  <div class="progress flex-grow-1" style="height:6px">
                    <div class="progress-bar bg-success" style="width:<?= $rate ?>%"></div>
                  </div>
                  <span class="small fw-semibold" style="min-width:32px"><?= $rate ?>%</span>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Date range preset helper ──────────────────────────────────────
function setRange(p) {
    const today = new Date();
    const fmt   = d => d.toISOString().split('T')[0];
    let fr, to  = fmt(today);
    if (p === 'today') {
        fr = fmt(today);
    } else if (p === 'week') {
        const d = new Date(today);
        d.setDate(today.getDate() - today.getDay());
        fr = fmt(d);
    } else if (p === 'month') {
        fr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2,'0') + '-01';
    } else if (p === 'lastmonth') {
        const s = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        const e = new Date(today.getFullYear(), today.getMonth(), 0);
        fr = fmt(s); to = fmt(e);
    } else if (p === 'year') {
        fr = today.getFullYear() + '-01-01';
    } else if (p === 'all') {
        fr = '2000-01-01';
    }
    document.getElementById('inp_from').value = fr;
    document.getElementById('inp_to').value   = to;
    document.getElementById('rptForm').submit();
}

// ── Chart defaults ────────────────────────────────────────────────
Chart.defaults.font.size        = 11;
Chart.defaults.plugins.legend.labels.boxWidth = 12;

const COLORS = ['#e74c3c','#3498db','#2ecc71','#f39c12','#9b59b6','#1abc9c','#e67e22','#34495e'];

// ── FINANCIAL charts ──────────────────────────────────────────────
if (document.getElementById('chartRevTrend')) {
    new Chart(document.getElementById('chartRevTrend'), {
        type: 'bar',
        data: {
            labels: {$jMonthLabels},
            datasets: [
                { label: 'Billed', data: {$jMonthBilled}, backgroundColor: 'rgba(231,76,60,.18)', borderColor: '#e74c3c', borderWidth: 2, borderRadius: 4, type: 'bar' },
                { label: 'Collected', data: {$jMonthPaid}, backgroundColor: '#198754', borderRadius: 4, type: 'bar' }
            ]
        },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'top'}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}} }
    });
}
if (document.getElementById('chartBillStatus')) {
    const slabels = {$jStatusLabels};
    new Chart(document.getElementById('chartBillStatus'), {
        type: 'doughnut',
        data: { labels: slabels.map(s=>s.charAt(0).toUpperCase()+s.slice(1)), datasets:[{ data:{$jStatusCounts}, backgroundColor:COLORS, hoverOffset:6 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
    });
}

// ── PATIENTS charts ───────────────────────────────────────────────
if (document.getElementById('chartRegTrend')) {
    new Chart(document.getElementById('chartRegTrend'), {
        type: 'line',
        data: { labels:{$jRegDayLabels}, datasets:[{ label:'New Registrations', data:{$jRegDayCounts}, borderColor:'#e74c3c', backgroundColor:'rgba(231,76,60,.1)', fill:true, tension:.35, pointRadius:3 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}} }
    });
}
if (document.getElementById('chartGender')) {
    new Chart(document.getElementById('chartGender'), {
        type: 'doughnut',
        data: { labels:['Male','Female','Other'], datasets:[{ data:[{$patMale},{$patFemale},{$patOther}], backgroundColor:['#0b5ed7','#d63384','#ffc107'], hoverOffset:6 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
    });
}
if (document.getElementById('chartAge')) {
    new Chart(document.getElementById('chartAge'), {
        type: 'bar',
        data: { labels:{$jAgeLabels}, datasets:[{ label:'Patients', data:{$jAgeData}, backgroundColor:COLORS, borderRadius:6 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}} }
    });
}

// ── LAB charts ────────────────────────────────────────────────────
if (document.getElementById('chartLabDay')) {
    new Chart(document.getElementById('chartLabDay'), {
        type: 'bar',
        data: { labels:{$jLabDayLabels}, datasets:[{ label:'Lab Orders', data:{$jLabDayCounts}, backgroundColor:'rgba(13,202,240,.7)', borderColor:'#0dcaf0', borderRadius:4, borderWidth:1 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}} }
    });
}
if (document.getElementById('chartLabStatus')) {
    const llabels = {$jLabStLabels};
    new Chart(document.getElementById('chartLabStatus'), {
        type: 'doughnut',
        data: { labels: llabels.map(s=>s.charAt(0).toUpperCase()+s.slice(1)), datasets:[{ data:{$jLabStCounts}, backgroundColor:COLORS, hoverOffset:6 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
    });
}

// ── PHARMACY chart ────────────────────────────────────────────────
if (document.getElementById('chartRxDay')) {
    new Chart(document.getElementById('chartRxDay'), {
        type: 'line',
        data: { labels:{$jRxDayLabels}, datasets:[{ label:'Revenue', data:{$jRxDayRev}, borderColor:'#198754', backgroundColor:'rgba(25,135,84,.12)', fill:true, tension:.35, pointRadius:3 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}} }
    });
}

// ── APPOINTMENTS charts ───────────────────────────────────────────
if (document.getElementById('chartApptDay')) {
    new Chart(document.getElementById('chartApptDay'), {
        type: 'line',
        data: { labels:{$jApptDayLabels}, datasets:[{ label:'Appointments', data:{$jApptDayCounts}, borderColor:'#e74c3c', backgroundColor:'rgba(231,76,60,.1)', fill:true, tension:.35, pointRadius:3 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}} }
    });
}
if (document.getElementById('chartApptType')) {
    new Chart(document.getElementById('chartApptType'), {
        type: 'doughnut',
        data: { labels:{$jApptTypeLabels}, datasets:[{ data:{$jApptTypeCounts}, backgroundColor:COLORS, hoverOffset:6 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
    });
}
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
?>
