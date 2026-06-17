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

require_once __DIR__ . '/../../includes/header-module.php';

$orgId   = (int)$user['org_id'];
$bWhere  = branchWhere('branch_id');
$bWhereA = branchWhere('a.branch_id');
$bParams = branchParams();
$today   = date('Y-m-d');
$month   = date('Y-m');
$monthStart = date('Y-m-01');

// ── Helper ───────────────────────────────────────────────────────
function safeVal(PDO $pdo, string $sql, array $p = []): mixed {
    try { $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchColumn() ?: 0; } catch (Throwable $e) { return 0; }
}
function safeRows(PDO $pdo, string $sql, array $p = []): array {
    try { $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Throwable $e) { return []; }
}

// ── Ensure health module tables exist (self-provisioning) ─────────
// Appointments — used for Today's Appointments KPI and queue widget
try { $pdo->exec("CREATE TABLE IF NOT EXISTS health_appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL, patient_id INT NOT NULL, doctor_id INT,
    date DATE NOT NULL, time TIME,
    type VARCHAR(100), complaint TEXT,
    status ENUM('scheduled','completed','cancelled','no_show') DEFAULT 'scheduled',
    notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org(org_id), INDEX idx_date(date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

// Triage — used for Emergency Cases Today KPI
try { $pdo->exec("CREATE TABLE IF NOT EXISTS health_triage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL, triage_no VARCHAR(30) NOT NULL,
    patient_id INT, patient_name VARCHAR(200), patient_phone VARCHAR(25),
    age TINYINT, gender ENUM('male','female','other'),
    triage_level ENUM('1_immediate','2_emergent','3_urgent','4_semi_urgent','5_non_urgent') DEFAULT '3_urgent',
    chief_complaint TEXT, bp_systolic SMALLINT, bp_diastolic SMALLINT,
    pulse SMALLINT, temperature DECIMAL(5,2), spo2 TINYINT, gcs TINYINT,
    status ENUM('waiting','in_progress','admitted','discharged','referred','left_without_seen') DEFAULT 'waiting',
    triaged_by INT, triaged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    doctor_id INT, seen_at DATETIME,
    disposition ENUM('admit','discharge','refer','observation','died'),
    disposition_notes TEXT,
    INDEX idx_org(org_id), INDEX idx_triaged_at(triaged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

// Prescriptions — used for Prescriptions This Month KPI
try { $pdo->exec("CREATE TABLE IF NOT EXISTS health_prescriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL, prescription_no VARCHAR(30),
    patient_id INT, doctor_id INT,
    prescription_date DATE NOT NULL,
    diagnosis TEXT, medicines JSON, notes TEXT,
    status ENUM('draft','dispensed','cancelled') DEFAULT 'draft',
    dispensed_by INT, dispensed_at DATETIME, created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org(org_id), INDEX idx_date(prescription_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

// EHR records — ensures records module works from day one
try { $pdo->exec("CREATE TABLE IF NOT EXISTS health_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL, patient_id INT NOT NULL,
    doctor_id INT, appointment_id INT,
    date DATE NOT NULL, diagnosis TEXT, treatment TEXT,
    prescription TEXT, notes TEXT, follow_up_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_org(org_id), INDEX idx_patient(patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

// Lab orders — used for Lab Tests Pending KPI
try { $pdo->exec("CREATE TABLE IF NOT EXISTS health_lab_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL, order_no VARCHAR(30) NOT NULL,
    patient_id INT NOT NULL, doctor_id INT, appointment_id INT, admission_id INT,
    test_id INT NOT NULL,
    priority ENUM('routine','urgent','stat') DEFAULT 'routine',
    status ENUM('ordered','collected','processing','resulted','cancelled') DEFAULT 'ordered',
    sample_type VARCHAR(100), result_value TEXT, result_notes TEXT,
    result_flag ENUM('normal','low','high','critical'),
    ordered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    collected_at DATETIME, resulted_at DATETIME, resulted_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org(org_id), INDEX idx_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

// ── KPI Row 1: Patients, Appointments ───────────────────────────
$totalPatients    = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_patients WHERE org_id=?",[$orgId]);
$newPatientsMonth = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_patients WHERE org_id=? AND DATE_FORMAT(created_at,'%Y-%m')=?",[$orgId,$month]);
$newPatientsYest  = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_patients WHERE org_id=? AND DATE(created_at)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)",[$orgId]);

$todayAppts       = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_appointments WHERE org_id=? AND date=CURDATE()",[$orgId]);
$completedAppts   = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_appointments WHERE org_id=? AND date=CURDATE() AND status='completed'",[$orgId]);
$pendingAppts     = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_appointments WHERE org_id=? AND date=CURDATE() AND status='scheduled'",[$orgId]);
$yesterdayAppts   = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_appointments WHERE org_id=? AND date=DATE_SUB(CURDATE(),INTERVAL 1 DAY)",[$orgId]);

// ── KPI Row 1: Doctors, Revenue ──────────────────────────────────
$totalDoctors     = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_doctors WHERE org_id=? AND status='active'",[$orgId]);
$totalStaff       = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_staff WHERE org_id=? AND status='active'",[$orgId]);

$collectedMonth   = (float)safeVal($pdo,"SELECT COALESCE(SUM(paid_amount),0) FROM health_bills WHERE org_id=? AND DATE_FORMAT(created_at,'%Y-%m')=?",[$orgId,$month]);
$outstandingTotal = (float)safeVal($pdo,"SELECT COALESCE(SUM(total_amount-paid_amount),0) FROM health_bills WHERE org_id=? AND status NOT IN ('paid','cancelled')",[$orgId]);
$revenueLastMonth = (float)safeVal($pdo,"SELECT COALESCE(SUM(paid_amount),0) FROM health_bills WHERE org_id=? AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(DATE_SUB(NOW(),INTERVAL 1 MONTH),'%Y-%m')",[$orgId]);

// ── KPI Row 2: Beds, Lab, Emergency, Prescriptions ───────────────
$bedsTotal        = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_beds WHERE org_id=?",[$orgId]);
$bedsOccupied     = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_beds WHERE org_id=? AND status='occupied'",[$orgId]);
$bedsAvailable    = max(0, $bedsTotal - $bedsOccupied);

$activeAdmissions = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_admissions WHERE org_id=? AND discharge_date IS NULL",[$orgId]);

$labPending       = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_lab_orders WHERE org_id=? AND status NOT IN ('resulted','cancelled')",[$orgId]);
$labToday         = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_lab_orders WHERE org_id=? AND DATE(ordered_at)=CURDATE()",[$orgId]);

$emergencyToday   = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_triage WHERE org_id=? AND DATE(triaged_at)=CURDATE()",[$orgId]);

$rxThisMonth      = (int)safeVal($pdo,"SELECT COUNT(*) FROM health_prescriptions WHERE org_id=? AND DATE_FORMAT(prescription_date,'%Y-%m')=?",[$orgId,$month]);

// ── 7-day appointment trend ──────────────────────────────────────
$apptTrend = []; $revTrend = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $apptTrend[] = [
        'date'  => date('D', strtotime($d)),
        'count' => (int)safeVal($pdo,"SELECT COUNT(*) FROM health_appointments WHERE org_id=? AND date=?",[$orgId,$d]),
    ];
    $revTrend[] = (float)safeVal($pdo,"SELECT COALESCE(SUM(paid_amount),0) FROM health_bills WHERE org_id=? AND DATE(updated_at)=?",[$orgId,$d]);
}

// ── Appointment type breakdown (pie) ─────────────────────────────
$apptByType = safeRows($pdo,"SELECT COALESCE(type,'General') AS type, COUNT(*) AS cnt FROM health_appointments WHERE org_id=? AND date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY type ORDER BY cnt DESC LIMIT 6",[$orgId]);

// ── Today's appointment queue ─────────────────────────────────────
$todayQueue = safeRows($pdo,"
    SELECT a.id, a.time, a.type, a.complaint, a.status,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name,
           p.patient_no, p.phone,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM health_appointments a
    JOIN health_patients p ON p.id=a.patient_id
    LEFT JOIN health_doctors d ON d.id=a.doctor_id
    WHERE a.org_id=? AND a.date=CURDATE()
    ORDER BY a.time ASC
    LIMIT 20",[$orgId]);

// ── Active IPD admissions ─────────────────────────────────────────
$activeIPD = safeRows($pdo,"
    SELECT a.id, a.admission_date, a.diagnosis,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name,
           p.patient_no,
           w.name AS ward_name, b.bed_number,
           DATEDIFF(CURDATE(),DATE(a.admission_date)) AS los_days
    FROM health_admissions a
    JOIN health_patients p ON p.id=a.patient_id
    LEFT JOIN health_wards w ON w.id=a.ward_id
    LEFT JOIN health_beds b ON b.id=a.bed_id
    WHERE a.org_id=? AND a.discharge_date IS NULL
    ORDER BY a.admission_date ASC
    LIMIT 8",[$orgId]);

// ── Recent patients enrolled ──────────────────────────────────────
$recentPatients = safeRows($pdo,"
    SELECT id, first_name, last_name, patient_no, gender, date_of_birth, created_at
    FROM health_patients WHERE org_id=?
    ORDER BY created_at DESC LIMIT 6",[$orgId]);

// ── Outstanding bills ─────────────────────────────────────────────
$pendingBills = safeRows($pdo,"
    SELECT b.id, b.bill_no, b.total_amount, b.paid_amount, b.status, b.created_at,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name
    FROM health_bills b
    JOIN health_patients p ON p.id=b.patient_id
    WHERE b.org_id=? AND b.status NOT IN ('paid','cancelled')
      AND (b.total_amount - b.paid_amount) > 0
    ORDER BY (b.total_amount-b.paid_amount) DESC
    LIMIT 6",[$orgId]);

// ── Ward occupancy ────────────────────────────────────────────────
$wardOccupancy = safeRows($pdo,"
    SELECT w.id, w.name,
           COUNT(b.id) AS total_beds,
           SUM(b.status='occupied') AS occupied
    FROM health_wards w
    LEFT JOIN health_beds b ON b.ward_id=w.id AND b.org_id=w.org_id
    WHERE w.org_id=?
    GROUP BY w.id, w.name
    ORDER BY w.name
    LIMIT 8",[$orgId]);

// Trend helpers
function trendBadge(float $curr, float $prev): string {
    if ($prev == 0) return '';
    $pct = round(($curr - $prev) / $prev * 100, 1);
    $up  = $pct >= 0;
    $col = $up ? 'text-success' : 'text-danger';
    $ico = $up ? 'fa-arrow-up' : 'fa-arrow-down';
    return "<span class='$col' style='font-size:.72rem'><i class='fas $ico'></i> ".abs($pct)."%</span>";
}

$statusColors = ['scheduled'=>'primary','completed'=>'success','cancelled'=>'secondary','no_show'=>'warning','in_progress'=>'info'];

// Chart data
$apptLabels    = json_encode(array_column($apptTrend,'date'));
$apptCounts    = json_encode(array_column($apptTrend,'count'));
$revData       = json_encode($revTrend);
$typeLabels    = json_encode(array_column($apptByType,'type'));
$typeCounts    = json_encode(array_column($apptByType,'cnt'));
$orgSlug       = $_SESSION['org_slug'] ?? '';
?>

<!-- ─────────────────────────────────────────────────────────────────
     CUSTOM CSS
     ───────────────────────────────────────────────────────────────── -->
<style>
:root { --hred:#e74c3c; --hred-pale:#fde8e8; --hblue:#1a4e7c; --hgreen:#1a8a4e; --horange:#e67e22; }

/* KPI cards */
.hkpi {
  background:#fff;border-radius:12px;padding:1.1rem 1.25rem;
  box-shadow:0 1px 4px rgba(0,0,0,.07);border:1px solid #f0f0f0;
  display:flex;align-items:center;gap:1rem;
}
.hkpi-icon {
  width:48px;height:48px;border-radius:12px;
  display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;
}
.hkpi-val { font-size:1.5rem;font-weight:800;line-height:1;color:#1a1a2e; }
.hkpi-label { font-size:.75rem;color:#6c757d;margin-top:2px; }
.hkpi-trend { font-size:.72rem;margin-top:4px; }

/* Section headers */
.dash-section-title {
  font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;
  color:#6c757d;margin-bottom:.75rem;
}

/* Queue list */
.queue-row {
  display:flex;align-items:center;gap:.75rem;
  padding:.65rem .85rem;border-bottom:1px solid #f5f5f5;transition:background .15s;
}
.queue-row:last-child { border-bottom:0; }
.queue-row:hover { background:#fafafa; }
.queue-num {
  width:28px;height:28px;border-radius:8px;background:#f0f0f0;
  display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#555;flex-shrink:0;
}
.queue-time { font-size:.9rem;font-weight:700;color:#1a1a2e;min-width:52px; }
.queue-patient { font-weight:600;font-size:.88rem;line-height:1.2; }
.queue-sub { font-size:.75rem;color:#888; }

/* Ward occupancy bar */
.occ-bar { height:8px;border-radius:4px;background:#e9ecef;overflow:hidden;margin-top:4px; }
.occ-fill { height:100%;border-radius:4px;transition:width .4s; }

/* Beds summary */
.bed-pill {
  display:inline-flex;align-items:center;gap:.3rem;
  padding:.25rem .7rem;border-radius:20px;font-size:.78rem;font-weight:600;
}

/* Patient avatar chip */
.pat-chip {
  display:flex;align-items:center;gap:.6rem;
  padding:.5rem .75rem;border-bottom:1px solid #f5f5f5;
}
.pat-chip:last-child { border-bottom:0; }
.pat-avatar {
  width:34px;height:34px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:.8rem;font-weight:700;color:#fff;flex-shrink:0;
}

/* Bill row */
.bill-row {
  display:flex;align-items:center;justify-content:space-between;
  padding:.55rem .85rem;border-bottom:1px solid #f5f5f5;
}
.bill-row:last-child { border-bottom:0; }

/* IPD row */
.ipd-row {
  display:flex;align-items:center;gap:.75rem;
  padding:.6rem .85rem;border-bottom:1px solid #f5f5f5;
}
.ipd-row:last-child { border-bottom:0; }
.los-badge {
  font-size:.7rem;font-weight:700;padding:.2rem .5rem;
  border-radius:6px;
}

/* Stat summary bar */
.summary-bar {
  background:linear-gradient(135deg,var(--hred) 0%,#c0392b 100%);
  border-radius:12px;padding:1rem 1.5rem;color:#fff;margin-bottom:1.5rem;
}
.summary-bar .s-val { font-size:1.3rem;font-weight:800; }
.summary-bar .s-lbl { font-size:.72rem;opacity:.8;margin-top:2px; }

/* Chart containers */
.chart-wrap { position:relative;height:180px; }
</style>

<!-- ─────────────────────────────────────────────────────────────────
     HEADER
     ───────────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="fas fa-heartbeat me-2" style="color:var(--hred)"></i>Hospital Dashboard</h4>
    <div class="text-muted small"><?= date('l, d F Y') ?> &bull; <?= e($user['org_name'] ?? '') ?></div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="patients.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-user-plus me-1"></i>New Patient</a>
    <a href="appointments.php" class="btn btn-sm text-white" style="background:var(--hred)"><i class="fas fa-calendar-plus me-1"></i>New Appointment</a>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────
     SUMMARY BANNER
     ───────────────────────────────────────────────────────────────── -->
<div class="summary-bar mb-4">
  <div class="row g-3 text-center">
    <div class="col-6 col-md-3 border-end border-white border-opacity-25">
      <div class="s-val"><?= number_format($pendingAppts) ?></div>
      <div class="s-lbl"><i class="fas fa-clock me-1"></i>Patients Waiting Now</div>
    </div>
    <div class="col-6 col-md-3 border-end border-white border-opacity-25">
      <div class="s-val"><?= number_format($activeAdmissions) ?></div>
      <div class="s-lbl"><i class="fas fa-bed me-1"></i>Admitted (IPD)</div>
    </div>
    <div class="col-6 col-md-3 border-end border-white border-opacity-25">
      <div class="s-val"><?= $bedsTotal > 0 ? number_format($bedsAvailable) : '—' ?></div>
      <div class="s-lbl"><i class="fas fa-door-open me-1"></i>Beds Available</div>
    </div>
    <div class="col-6 col-md-3">
      <div class="s-val"><?= hMoney($outstandingTotal) ?></div>
      <div class="s-lbl"><i class="fas fa-exclamation-circle me-1"></i>Outstanding Bills</div>
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────
     KPI ROW 1
     ───────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
  <!-- Total Patients -->
  <div class="col-6 col-lg-3">
    <a href="patients.php" class="text-decoration-none">
      <div class="hkpi">
        <div class="hkpi-icon" style="background:var(--hred-pale)"><i class="fas fa-procedures" style="color:var(--hred)"></i></div>
        <div>
          <div class="hkpi-val"><?= number_format($totalPatients) ?></div>
          <div class="hkpi-label">Total Patients</div>
          <div class="hkpi-trend">
            <span class="text-muted" style="font-size:.72rem">+<?= $newPatientsMonth ?> this month</span>
            <?= trendBadge($newPatientsMonth, $newPatientsYest * 30) ?>
          </div>
        </div>
      </div>
    </a>
  </div>

  <!-- Today's Appointments -->
  <div class="col-6 col-lg-3">
    <a href="appointments.php" class="text-decoration-none">
      <div class="hkpi">
        <div class="hkpi-icon" style="background:#fff3cd"><i class="fas fa-calendar-check" style="color:var(--horange)"></i></div>
        <div>
          <div class="hkpi-val"><?= number_format($todayAppts) ?></div>
          <div class="hkpi-label">Today's Appointments</div>
          <div class="hkpi-trend">
            <span class="text-success small" style="font-size:.72rem"><?= $completedAppts ?> done</span>
            &bull;
            <span class="text-warning small" style="font-size:.72rem"><?= $pendingAppts ?> pending</span>
          </div>
        </div>
      </div>
    </a>
  </div>

  <!-- Doctors on duty -->
  <div class="col-6 col-lg-3">
    <a href="doctors.php" class="text-decoration-none">
      <div class="hkpi">
        <div class="hkpi-icon" style="background:#e8f0f8"><i class="fas fa-user-md" style="color:var(--hblue)"></i></div>
        <div>
          <div class="hkpi-val"><?= number_format($totalDoctors) ?></div>
          <div class="hkpi-label">Active Doctors</div>
          <div class="hkpi-trend"><span class="text-muted" style="font-size:.72rem"><?= $totalStaff ?> clinical staff</span></div>
        </div>
      </div>
    </a>
  </div>

  <!-- Revenue this month -->
  <div class="col-6 col-lg-3">
    <a href="billing.php" class="text-decoration-none">
      <div class="hkpi">
        <div class="hkpi-icon" style="background:#e8f8f0"><i class="fas fa-coins" style="color:var(--hgreen)"></i></div>
        <div>
          <div class="hkpi-val" style="font-size:1.1rem"><?= hMoney($collectedMonth) ?></div>
          <div class="hkpi-label">Revenue This Month</div>
          <div class="hkpi-trend"><?= trendBadge($collectedMonth, $revenueLastMonth) ?> <span class="text-muted" style="font-size:.72rem">vs last month</span></div>
        </div>
      </div>
    </a>
  </div>
</div>

<!-- KPI ROW 2 -->
<div class="row g-3 mb-4">
  <!-- Beds -->
  <div class="col-6 col-lg-3">
    <a href="wards.php" class="text-decoration-none">
      <div class="hkpi">
        <div class="hkpi-icon" style="background:#f3e8ff"><i class="fas fa-bed" style="color:#7c3aed"></i></div>
        <div style="width:100%">
          <div class="hkpi-val"><?= $bedsOccupied ?><span style="font-size:.85rem;font-weight:400;color:#999"> / <?= $bedsTotal ?></span></div>
          <div class="hkpi-label">Beds Occupied</div>
          <div class="occ-bar mt-2">
            <div class="occ-fill" style="width:<?= $bedsTotal > 0 ? round($bedsOccupied/$bedsTotal*100) : 0 ?>%;background:<?= $bedsTotal > 0 && $bedsOccupied/$bedsTotal > .8 ? 'var(--hred)' : '#7c3aed' ?>"></div>
          </div>
        </div>
      </div>
    </a>
  </div>

  <!-- Lab -->
  <div class="col-6 col-lg-3">
    <a href="lab.php" class="text-decoration-none">
      <div class="hkpi">
        <div class="hkpi-icon" style="background:#e0f7fa"><i class="fas fa-flask" style="color:#0097a7"></i></div>
        <div>
          <div class="hkpi-val"><?= number_format($labPending) ?></div>
          <div class="hkpi-label">Lab Tests Pending</div>
          <div class="hkpi-trend"><span class="text-muted" style="font-size:.72rem"><?= $labToday ?> ordered today</span></div>
        </div>
      </div>
    </a>
  </div>

  <!-- Emergency -->
  <div class="col-6 col-lg-3">
    <a href="emergency.php" class="text-decoration-none">
      <div class="hkpi">
        <div class="hkpi-icon" style="background:#fff0f0"><i class="fas fa-ambulance" style="color:#c0392b"></i></div>
        <div>
          <div class="hkpi-val"><?= number_format($emergencyToday) ?></div>
          <div class="hkpi-label">Emergency Cases Today</div>
          <div class="hkpi-trend"><span class="text-muted" style="font-size:.72rem">Active triage</span></div>
        </div>
      </div>
    </a>
  </div>

  <!-- Prescriptions -->
  <div class="col-6 col-lg-3">
    <a href="prescription.php" class="text-decoration-none">
      <div class="hkpi">
        <div class="hkpi-icon" style="background:#fef3e2"><i class="fas fa-prescription" style="color:#d35400"></i></div>
        <div>
          <div class="hkpi-val"><?= number_format($rxThisMonth) ?></div>
          <div class="hkpi-label">Prescriptions This Month</div>
          <div class="hkpi-trend"><span class="text-muted" style="font-size:.72rem">Issued by doctors</span></div>
        </div>
      </div>
    </a>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────
     ROW: TODAY'S QUEUE + WARD OCCUPANCY
     ───────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">

  <!-- Today's Appointment Queue -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between py-3 px-4">
        <div>
          <div class="fw-bold"><i class="fas fa-list-ol me-2" style="color:var(--hred)"></i>Today's Appointment Queue</div>
          <div class="text-muted small"><?= date('l, d F Y') ?> &bull; <?= $todayAppts ?> appointment<?= $todayAppts!==1?'s':'' ?></div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="badge bg-warning-subtle text-warning border border-warning-subtle"><?= $pendingAppts ?> waiting</span>
          <span class="badge bg-success-subtle text-success border border-success-subtle"><?= $completedAppts ?> done</span>
          <a href="appointments.php" class="btn btn-xs btn-outline-secondary ms-1">View All</a>
        </div>
      </div>
      <div class="card-body p-0" style="max-height:400px;overflow-y:auto">
        <?php if (empty($todayQueue)): ?>
        <div class="text-center py-5 text-muted">
          <i class="fas fa-calendar-day fa-3x mb-3 d-block opacity-25"></i>
          <p class="mb-0">No appointments scheduled for today.</p>
          <a href="appointments.php" class="btn btn-sm btn-outline-danger mt-2">+ Book Appointment</a>
        </div>
        <?php else: ?>
        <?php foreach ($todayQueue as $i => $a):
          $sc = $statusColors[$a['status']] ?? 'secondary';
          $isCompleted = $a['status'] === 'completed';
        ?>
        <div class="queue-row <?= $isCompleted ? 'opacity-60' : '' ?>">
          <div class="queue-num"><?= $i+1 ?></div>
          <div class="queue-time"><?= date('H:i', strtotime($a['time'] ?? 'now')) ?></div>
          <div class="flex-grow-1 min-width-0">
            <div class="queue-patient"><?= e($a['patient_name']) ?></div>
            <div class="queue-sub">#<?= e($a['patient_no'] ?? '—') ?> <?= $a['complaint'] ? '· '.e(mb_substr($a['complaint'],0,40)) : '' ?></div>
          </div>
          <div class="text-end flex-shrink-0">
            <?php if ($a['doctor_name']): ?>
            <div class="small text-muted" style="font-size:.73rem">Dr. <?= e($a['doctor_name']) ?></div>
            <?php endif; ?>
            <span class="badge bg-<?= $sc ?>" style="font-size:.67rem"><?= ucfirst(str_replace('_',' ',$a['status'])) ?></span>
          </div>
          <div class="ms-2 flex-shrink-0">
            <a href="records.php?patient_id=<?= $a['patient_id'] ?>&appt_id=<?= $a['id'] ?>"
               class="btn btn-xs btn-outline-success" title="Write Record"><i class="fas fa-file-medical"></i></a>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Ward Occupancy -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-0 py-3 px-4">
        <div class="fw-bold"><i class="fas fa-bed me-2" style="color:#7c3aed"></i>Ward Occupancy</div>
        <div class="text-muted small"><?= $bedsOccupied ?> / <?= $bedsTotal ?> beds occupied</div>
      </div>
      <div class="card-body py-2">
        <?php if (empty($wardOccupancy)): ?>
        <div class="text-center py-4 text-muted small">
          <i class="fas fa-bed fa-2x mb-2 d-block opacity-25"></i>No wards configured.
          <a href="wards.php" class="d-block mt-1">Configure Wards</a>
        </div>
        <?php else: ?>
        <?php foreach ($wardOccupancy as $w):
          $total = max(1, (int)$w['total_beds']);
          $occ   = (int)$w['occupied'];
          $pct   = round($occ / $total * 100);
          $col   = $pct > 85 ? 'var(--hred)' : ($pct > 60 ? 'var(--horange)' : 'var(--hgreen)');
        ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold"><?= e($w['name']) ?></span>
            <span class="small text-muted"><?= $occ ?>/<?= $total ?> <span style="color:<?= $col ?>;font-weight:700"><?= $pct ?>%</span></span>
          </div>
          <div class="occ-bar">
            <div class="occ-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="d-flex gap-2 mt-3">
          <span class="bed-pill" style="background:#e8f8f0;color:var(--hgreen)"><i class="fas fa-circle me-1" style="font-size:.5rem"></i><?= $bedsAvailable ?> Free</span>
          <span class="bed-pill" style="background:var(--hred-pale);color:var(--hred)"><i class="fas fa-circle me-1" style="font-size:.5rem"></i><?= $bedsOccupied ?> Occupied</span>
        </div>
        <?php endif; ?>
      </div>
      <div class="card-footer bg-white border-0 pt-0 px-4 pb-3">
        <a href="wards.php" class="btn btn-sm btn-outline-secondary w-100">Manage Wards & Beds</a>
      </div>
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────
     ROW: CHARTS
     ───────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">

  <!-- 7-day Appointment Trend -->
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 py-3 px-4">
        <div class="fw-bold"><i class="fas fa-chart-bar me-2" style="color:var(--hred)"></i>Appointment Trend — Last 7 Days</div>
        <div class="text-muted small">Daily appointment volume vs. revenue collected</div>
      </div>
      <div class="card-body">
        <div class="chart-wrap"><canvas id="apptTrendChart"></canvas></div>
      </div>
    </div>
  </div>

  <!-- Appointment Type Breakdown -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 py-3 px-4">
        <div class="fw-bold"><i class="fas fa-chart-pie me-2" style="color:var(--hblue)"></i>Consultation Types — Last 30 Days</div>
        <div class="text-muted small">Distribution by appointment type</div>
      </div>
      <div class="card-body d-flex align-items-center gap-3">
        <div style="width:160px;flex-shrink:0"><canvas id="typeChart"></canvas></div>
        <div style="flex:1;min-width:0">
          <?php
          $pieColors = ['#e74c3c','#3498db','#2ecc71','#f39c12','#9b59b6','#1abc9c'];
          foreach ($apptByType as $ii => $t):
            $col = $pieColors[$ii % count($pieColors)];
          ?>
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="d-flex align-items-center gap-2">
              <div style="width:10px;height:10px;border-radius:50%;background:<?= $col ?>;flex-shrink:0"></div>
              <span class="small text-truncate" style="max-width:130px"><?= e($t['type']) ?></span>
            </div>
            <span class="fw-bold small"><?= $t['cnt'] ?></span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($apptByType)): ?>
          <div class="text-muted small">No appointment data yet.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────
     ROW: IPD + BILLS + RECENT PATIENTS
     ───────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

  <!-- Active IPD Admissions -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-0 py-3 px-4 d-flex align-items-center justify-content-between">
        <div>
          <div class="fw-bold"><i class="fas fa-hospital-user me-2" style="color:#7c3aed"></i>Active Admissions</div>
          <div class="text-muted small"><?= $activeAdmissions ?> inpatient<?= $activeAdmissions!==1?'s':'' ?> currently</div>
        </div>
        <a href="admissions.php" class="btn btn-xs btn-outline-secondary">All</a>
      </div>
      <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
        <?php if (empty($activeIPD)): ?>
        <div class="text-center py-4 text-muted small">
          <i class="fas fa-bed fa-2x mb-2 d-block opacity-25"></i>No active admissions.
        </div>
        <?php else: ?>
        <?php foreach ($activeIPD as $adm):
          $los   = (int)$adm['los_days'];
          $losCl = $los > 7 ? 'var(--hred)' : ($los > 3 ? 'var(--horange)' : 'var(--hgreen)');
        ?>
        <div class="ipd-row">
          <div style="min-width:0;flex:1">
            <div class="fw-semibold small"><?= e($adm['patient_name']) ?></div>
            <div class="text-muted" style="font-size:.73rem">#<?= e($adm['patient_no'] ?? '—') ?> &bull; <?= e($adm['ward_name'] ?? '—') ?> <?= $adm['bed_number'] ? 'Bed '.$adm['bed_number'] : '' ?></div>
            <div class="text-muted" style="font-size:.73rem;margin-top:1px"><?= e(mb_substr($adm['diagnosis'] ?? '',0,40)) ?></div>
          </div>
          <div class="text-end flex-shrink-0">
            <span class="los-badge" style="background:<?= $losCl ?>22;color:<?= $losCl ?>"><?= $los ?>d</span>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Outstanding Bills -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-0 py-3 px-4 d-flex align-items-center justify-content-between">
        <div>
          <div class="fw-bold"><i class="fas fa-receipt me-2" style="color:var(--hred)"></i>Outstanding Bills</div>
          <div class="text-muted small"><?= hMoney($outstandingTotal) ?> total due</div>
        </div>
        <a href="billing.php" class="btn btn-xs btn-outline-secondary">All</a>
      </div>
      <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
        <?php if (empty($pendingBills)): ?>
        <div class="text-center py-4 text-muted small">
          <i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>No outstanding bills.
        </div>
        <?php else: ?>
        <?php foreach ($pendingBills as $b):
          $bal = (float)$b['total_amount'] - (float)$b['paid_amount'];
          $st  = $b['status'];
          $stCol = $st === 'overdue' ? 'danger' : ($st === 'partial' ? 'warning' : 'secondary');
        ?>
        <div class="bill-row">
          <div style="min-width:0;flex:1">
            <div class="fw-semibold small"><?= e($b['patient_name']) ?></div>
            <div class="text-muted" style="font-size:.73rem"><?= e($b['bill_no'] ?? '#'.$b['id']) ?> &bull; <?= date('d M', strtotime($b['created_at'])) ?></div>
          </div>
          <div class="text-end flex-shrink-0">
            <div class="fw-bold small text-danger"><?= hMoney($bal) ?></div>
            <span class="badge bg-<?= $stCol ?>" style="font-size:.65rem"><?= ucfirst($st) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Patients -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-0 py-3 px-4 d-flex align-items-center justify-content-between">
        <div>
          <div class="fw-bold"><i class="fas fa-user-plus me-2" style="color:var(--hgreen)"></i>Recent Patients</div>
          <div class="text-muted small">Last enrolled</div>
        </div>
        <a href="patients.php" class="btn btn-xs btn-outline-secondary">All</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($recentPatients)): ?>
        <div class="text-center py-4 text-muted small">
          <i class="fas fa-procedures fa-2x mb-2 d-block opacity-25"></i>No patients enrolled yet.
        </div>
        <?php else: ?>
        <?php
        $avatarColors = ['#e74c3c','#3498db','#2ecc71','#f39c12','#9b59b6','#1abc9c'];
        foreach ($recentPatients as $ii => $p):
          $age = $p['date_of_birth'] ? date_diff(date_create($p['date_of_birth']), date_create('today'))->y : null;
          $initial = strtoupper(substr($p['first_name'],0,1));
          $col = $avatarColors[$ii % count($avatarColors)];
        ?>
        <div class="pat-chip">
          <div class="pat-avatar" style="background:<?= $col ?>"><?= $initial ?></div>
          <div style="min-width:0;flex:1">
            <div class="fw-semibold small text-truncate"><?= e($p['first_name'].' '.$p['last_name']) ?></div>
            <div class="text-muted" style="font-size:.73rem">
              #<?= e($p['patient_no'] ?? $p['id']) ?>
              <?= $age !== null ? ' · '.$age.' yrs' : '' ?>
              <?= $p['gender'] ? ' · '.ucfirst($p['gender']) : '' ?>
            </div>
          </div>
          <div class="text-muted" style="font-size:.7rem;flex-shrink:0"><?= date('d M', strtotime($p['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────
     QUICK ACTIONS
     ───────────────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3 px-4">
    <div class="dash-section-title mb-2"><i class="fas fa-bolt me-1"></i>Quick Actions</div>
    <div class="d-flex flex-wrap gap-2">
      <a href="patients.php" class="btn btn-sm btn-outline-danger"><i class="fas fa-user-plus me-1"></i>Enroll Patient</a>
      <a href="appointments.php" class="btn btn-sm btn-outline-warning"><i class="fas fa-calendar-plus me-1"></i>Book Appointment</a>
      <a href="records.php" class="btn btn-sm btn-outline-success"><i class="fas fa-file-medical me-1"></i>Write Record</a>
      <a href="prescription.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-prescription me-1"></i>New Prescription</a>
      <a href="admissions.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-bed me-1"></i>Admit Patient</a>
      <a href="billing.php" class="btn btn-sm btn-outline-info"><i class="fas fa-file-invoice-dollar me-1"></i>Create Bill</a>
      <a href="lab.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-flask me-1"></i>Lab Order</a>
      <a href="<?= APP_URL ?>/modules/health/medical-certificate-pdf.php" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fas fa-file-medical-alt me-1"></i>Medical Certificate</a>
      <a href="settings.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-cog me-1"></i>Settings</a>
      <a href="<?= APP_URL ?>/doctor/login.php?org=<?= e($orgSlug) ?>" target="_blank" class="btn btn-sm" style="background:#e8f0f8;color:#1a4e7c;border:1px solid #c5d9f0"><i class="fas fa-user-md me-1"></i>Doctor Portal</a>
    </div>
  </div>
</div>

<?php
$__hcSym = $GLOBALS['hCurrencySymbol'] ?? 'LRD';
$extraJs = <<<SCRIPT
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Appointment Trend + Revenue ─────────────────────────────────
const apptCtx = document.getElementById('apptTrendChart').getContext('2d');
new Chart(apptCtx, {
  type: 'bar',
  data: {
    labels: {$apptLabels},
    datasets: [
      {
        label: 'Appointments',
        data: {$apptCounts},
        backgroundColor: 'rgba(231,76,60,.75)',
        borderColor: 'rgba(231,76,60,1)',
        borderWidth: 1.5,
        borderRadius: 6,
        yAxisID: 'y',
      },
      {
        label: 'Revenue ($__hcSym)',
        data: {$revData},
        type: 'line',
        borderColor: '#1a8a4e',
        backgroundColor: 'rgba(26,138,78,.08)',
        tension: 0.4,
        fill: true,
        pointBackgroundColor: '#1a8a4e',
        pointRadius: 4,
        borderWidth: 2,
        yAxisID: 'y1',
      }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    interaction: { mode:'index', intersect:false },
    plugins: { legend:{ position:'top', labels:{ boxWidth:12, font:{size:11} } } },
    scales: {
      y:  { beginAtZero:true, grid:{color:'#f5f5f5'}, ticks:{font:{size:10}, stepSize:1}, title:{display:false} },
      y1: { beginAtZero:true, position:'right', grid:{drawOnChartArea:false}, ticks:{font:{size:10}, callback:v=>'$__hcSym '+v.toLocaleString()} }
    }
  }
});

// ── Consultation Type Donut ─────────────────────────────────────
const typeCtx = document.getElementById('typeChart').getContext('2d');
new Chart(typeCtx, {
  type: 'doughnut',
  data: {
    labels: {$typeLabels},
    datasets:[{
      data: {$typeCounts},
      backgroundColor: ['#e74c3c','#3498db','#2ecc71','#f39c12','#9b59b6','#1abc9c'],
      borderWidth: 2, borderColor:'#fff', hoverOffset: 4
    }]
  },
  options: {
    responsive:true, maintainAspectRatio:true,
    plugins:{ legend:{ display:false } },
    cutout:'65%'
  }
});
</script>
SCRIPT;

require_once __DIR__ . '/../../includes/footer.php';
?>
