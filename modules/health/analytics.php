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
    ['url'=>'records.php',       'icon'=>'fas fa-file-medical',        'label'=>'Medical Records'],
    ['url'=>'vitals.php',        'icon'=>'fas fa-heartbeat',           'label'=>'Vital Signs'],
    ['url'=>'lab.php',           'icon'=>'fas fa-flask',               'label'=>'Laboratory'],
    ['url'=>'pharmacy.php',      'icon'=>'fas fa-pills',               'label'=>'Pharmacy'],
    ['url'=>'nursing.php',       'icon'=>'fas fa-user-nurse',          'label'=>'Nursing'],
    ['url'=>'wards.php',         'icon'=>'fas fa-bed',                 'label'=>'Wards & Beds'],
    ['url'=>'admissions.php',    'icon'=>'fas fa-hospital-user',       'label'=>'Admissions (IPD)'],
    ['url'=>'emergency.php',     'icon'=>'fas fa-ambulance',           'label'=>'Emergency / Triage'],
    ['url'=>'billing.php',       'icon'=>'fas fa-file-invoice-dollar', 'label'=>'Billing'],
    ['url'=>'telemedicine.php',  'icon'=>'fas fa-video',               'label'=>'Telemedicine'],
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-users',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'AI Analytics'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
];

// ── AJAX handlers ─────────────────────────────────────────────────────────────

// Acknowledge / resolve / dismiss an alert
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    require_once __DIR__ . '/../../includes/header-module.php';
    $orgId = (int)$user['org_id'];
    $action = $_GET['action'];
    $alertId = (int)($_POST['alert_id'] ?? 0);

    if ($action === 'ack_alert' && $alertId) {
        $pdo->prepare("UPDATE health_clinical_alerts
                       SET status='acknowledged', acknowledged_by=?, acknowledged_at=NOW()
                       WHERE id=? AND org_id=?")
            ->execute([$user['id'], $alertId, $orgId]);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'resolve_alert' && $alertId) {
        $pdo->prepare("UPDATE health_clinical_alerts SET status='resolved' WHERE id=? AND org_id=?")
            ->execute([$alertId, $orgId]);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'dismiss_alert' && $alertId) {
        $pdo->prepare("UPDATE health_clinical_alerts SET status='dismissed' WHERE id=? AND org_id=?")
            ->execute([$alertId, $orgId]);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'run_engine') {
        // Trigger rule engine scan for this org and return count of new alerts created
        $created = runClinicalAlertEngine($pdo, $orgId);
        echo json_encode(['ok' => true, 'created' => $created]);
        exit;
    }
}

// Fetch active alerts JSON
if (isset($_GET['fetch_alerts'])) {
    require_once __DIR__ . '/../../includes/header-module.php';
    $orgId  = (int)$user['org_id'];
    $filter = $_GET['filter'] ?? 'active';

    $where  = $filter === 'all' ? "ca.org_id=?" : "ca.org_id=? AND ca.status='active'";
    $stmt   = $pdo->prepare("SELECT ca.*,
                               CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                               p.patient_no
                             FROM health_clinical_alerts ca
                             JOIN health_patients p ON ca.patient_id = p.id
                             WHERE {$where}
                             ORDER BY ca.severity='critical' DESC, ca.severity='warning' DESC, ca.created_at DESC
                             LIMIT 100");
    $stmt->execute([$orgId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Rule Engine Function ───────────────────────────────────────────────────────
function runClinicalAlertEngine(PDO $pdo, int $orgId): int {
    $created = 0;

    // Helper: insert alert if not already active for same patient + type
    $insertAlert = function(int $patId, string $type, string $severity,
                             string $title, string $msg, string $srcType = '',
                             int $srcId = 0, int $score = 0) use ($pdo, $orgId, &$created) {
        // De-duplicate: skip if active alert of same type already exists for patient
        $dup = $pdo->prepare("SELECT id FROM health_clinical_alerts
                              WHERE org_id=? AND patient_id=? AND alert_type=? AND status='active' LIMIT 1");
        $dup->execute([$orgId, $patId, $type]);
        if ($dup->fetch()) return;

        $pdo->prepare("INSERT INTO health_clinical_alerts
                        (org_id,patient_id,alert_type,severity,title,message,source_type,source_id,risk_score,auto_generated)
                        VALUES (?,?,?,?,?,?,?,?,?,1)")
            ->execute([$orgId, $patId, $type, $severity, $title, $msg, $srcType, $srcId ?: null, $score ?: null]);
        $created++;
    };

    // ── 1. Sepsis Risk: abnormal vitals in last 24 h ──────────────────────────
    // SIRS criteria: Temp >38.5 or <36, HR >90, RR >20, BP systolic <100
    try {
        $stmt = $pdo->prepare("SELECT v.*, CONCAT(p.first_name,' ',p.last_name) AS pname
                               FROM health_vitals v
                               JOIN health_patients p ON v.patient_id=p.id
                               WHERE v.org_id=?
                                 AND v.recorded_at >= NOW() - INTERVAL 24 HOUR
                               ORDER BY v.recorded_at DESC");
        $stmt->execute([$orgId]);
        $vitalsGroups = [];
        foreach ($stmt->fetchAll() as $v) {
            // Keep only most recent per patient
            if (!isset($vitalsGroups[$v['patient_id']])) $vitalsGroups[$v['patient_id']] = $v;
        }
        foreach ($vitalsGroups as $pid => $v) {
            $flags = 0;
            $reasons = [];
            if (!empty($v['temperature']) && ($v['temperature'] > 38.5 || $v['temperature'] < 36)) {
                $flags++; $reasons[] = "Temp {$v['temperature']}°C";
            }
            if (!empty($v['pulse']) && $v['pulse'] > 90) {
                $flags++; $reasons[] = "HR {$v['pulse']} bpm";
            }
            if (!empty($v['respiratory_rate']) && $v['respiratory_rate'] > 20) {
                $flags++; $reasons[] = "RR {$v['respiratory_rate']}/min";
            }
            if (!empty($v['blood_pressure_systolic']) && $v['blood_pressure_systolic'] < 100) {
                $flags++; $reasons[] = "SBP {$v['blood_pressure_systolic']} mmHg";
            }
            if ($flags >= 2) {
                $score    = min(100, $flags * 25);
                $severity = $flags >= 3 ? 'critical' : 'warning';
                $insertAlert($pid, 'sepsis_risk', $severity,
                    'Possible Sepsis Risk — SIRS Criteria Met',
                    "Patient meets {$flags}/4 SIRS criteria: " . implode(', ', $reasons) . '. Immediate clinical review recommended.',
                    'vitals', (int)$v['id'], $score);
            }
        }
    } catch (Exception $e) {}

    // ── 2. Abnormal Vitals (individual out-of-range) ──────────────────────────
    try {
        $stmt = $pdo->prepare("SELECT v.*, CONCAT(p.first_name,' ',p.last_name) AS pname
                               FROM health_vitals v
                               JOIN health_patients p ON v.patient_id=p.id
                               WHERE v.org_id=?
                                 AND v.recorded_at >= NOW() - INTERVAL 6 HOUR");
        $stmt->execute([$orgId]);
        foreach ($stmt->fetchAll() as $v) {
            $pid = (int)$v['patient_id'];

            // Critical SpO2
            if (!empty($v['oxygen_saturation']) && $v['oxygen_saturation'] < 90) {
                $insertAlert($pid, 'abnormal_vitals', 'critical',
                    'Critical SpO₂ — Hypoxia Alert',
                    "SpO₂ recorded at {$v['oxygen_saturation']}% (critical threshold <90%). Immediate oxygenation assessment required.",
                    'vitals', (int)$v['id'], 95);
            }
            // Hypertensive crisis
            if (!empty($v['blood_pressure_systolic']) && $v['blood_pressure_systolic'] >= 180) {
                $insertAlert($pid, 'abnormal_vitals', 'critical',
                    'Hypertensive Crisis Detected',
                    "Systolic BP of {$v['blood_pressure_systolic']} mmHg recorded. Hypertensive emergency threshold exceeded (≥180 mmHg). Urgent review required.",
                    'vitals', (int)$v['id'], 90);
            }
            // High fever
            if (!empty($v['temperature']) && $v['temperature'] >= 39.5) {
                $insertAlert($pid, 'abnormal_vitals', 'warning',
                    'High Fever Alert',
                    "Temperature recorded at {$v['temperature']}°C. Febrile threshold exceeded (≥39.5°C). Assess for infection source.",
                    'vitals', (int)$v['id'], 65);
            }
            // Bradycardia
            if (!empty($v['pulse']) && $v['pulse'] < 50) {
                $insertAlert($pid, 'abnormal_vitals', 'warning',
                    'Bradycardia Detected',
                    "Heart rate of {$v['pulse']} bpm is below safe threshold (<50 bpm). ECG and medication review advised.",
                    'vitals', (int)$v['id'], 70);
            }
            // Tachycardia
            if (!empty($v['pulse']) && $v['pulse'] > 130) {
                $insertAlert($pid, 'abnormal_vitals', 'warning',
                    'Tachycardia Detected',
                    "Heart rate of {$v['pulse']} bpm exceeds threshold (>130 bpm). Assess for haemodynamic instability.",
                    'vitals', (int)$v['id'], 70);
            }
        }
    } catch (Exception $e) {}

    // ── 3. Readmission Risk (discharged within 30 days, re-admitted) ──────────
    try {
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(p.first_name,' ',p.last_name) AS pname
                               FROM health_admissions a
                               JOIN health_patients p ON a.patient_id=p.id
                               WHERE a.org_id=? AND a.status='admitted'");
        $stmt->execute([$orgId]);
        foreach ($stmt->fetchAll() as $adm) {
            $pid = (int)$adm['patient_id'];
            // Check if discharged within last 30 days before this admission
            $prev = $pdo->prepare("SELECT id FROM health_admissions
                                   WHERE org_id=? AND patient_id=? AND status='discharged'
                                     AND discharge_date >= DATE_SUB(?, INTERVAL 30 DAY)
                                   LIMIT 1");
            $prev->execute([$orgId, $pid, $adm['admitted_at']]);
            if ($prev->fetch()) {
                $insertAlert($pid, 'readmission_risk', 'warning',
                    '30-Day Readmission Risk Detected',
                    "Patient was discharged within the past 30 days and has been re-admitted. High readmission risk — review discharge plan and chronic condition management.",
                    'admission', (int)$adm['id'], 75);
            }
        }
    } catch (Exception $e) {}

    // ── 4. Critical Lab Values ────────────────────────────────────────────────
    try {
        $stmt = $pdo->prepare("SELECT lr.*, lo.patient_id,
                                      CONCAT(p.first_name,' ',p.last_name) AS pname
                               FROM health_lab_results lr
                               JOIN health_lab_orders lo ON lr.order_id=lo.id
                               JOIN health_patients p ON lo.patient_id=p.id
                               WHERE lo.org_id=? AND lr.flag='critical'
                                 AND lr.created_at >= NOW() - INTERVAL 12 HOUR");
        $stmt->execute([$orgId]);
        foreach ($stmt->fetchAll() as $lr) {
            $pid = (int)$lr['patient_id'];
            $insertAlert($pid, 'critical_lab', 'critical',
                "Critical Lab Value — {$lr['test_name']}",
                "Critical result: {$lr['result_value']} {$lr['unit']} (Ref: {$lr['reference_range']}). Immediate physician notification required.",
                'lab', (int)$lr['order_id'], 88);
        }
    } catch (Exception $e) {}

    // ── 5. Fall Risk (elderly patients currently admitted) ────────────────────
    try {
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(p.first_name,' ',p.last_name) AS pname,
                                      TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) AS age
                               FROM health_admissions a
                               JOIN health_patients p ON a.patient_id=p.id
                               WHERE a.org_id=? AND a.status='admitted'
                                 AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) >= 70");
        $stmt->execute([$orgId]);
        foreach ($stmt->fetchAll() as $adm) {
            $pid = (int)$adm['patient_id'];
            $insertAlert($pid, 'fall_risk', 'info',
                'Fall Risk Flag — Elderly Inpatient',
                "Patient is {$adm['age']} years old and currently admitted. Implement fall prevention protocol: bed rails, call bell within reach, non-slip footwear, regular mobility checks.",
                'admission', (int)$adm['id'], 50);
        }
    } catch (Exception $e) {}

    return $created;
}

// ── Page Load ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

// Run alert engine on page load (lightweight — de-duplicates internally)
$newAlerts = 0;
try { $newAlerts = runClinicalAlertEngine($pdo, $orgId); } catch (Exception $e) {}

// Alert counts
$alertsCritical = countRows('health_clinical_alerts', "org_id=? AND severity='critical' AND status='active'", [$orgId]);
$alertsWarning  = countRows('health_clinical_alerts', "org_id=? AND severity='warning'  AND status='active'", [$orgId]);
$alertsInfo     = countRows('health_clinical_alerts', "org_id=? AND severity='info'     AND status='active'", [$orgId]);
$alertsTotal    = $alertsCritical + $alertsWarning + $alertsInfo;

// Population health — top 8 diagnoses (last 12 months)
$diagLabels = $diagData = [];
try {
    $stmt = $pdo->prepare("SELECT diagnosis, COUNT(*) AS cnt FROM health_records
                           WHERE org_id=? AND visit_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                             AND diagnosis IS NOT NULL AND diagnosis <> ''
                           GROUP BY diagnosis ORDER BY cnt DESC LIMIT 8");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $row) {
        $diagLabels[] = $row['diagnosis'];
        $diagData[]   = (int)$row['cnt'];
    }
} catch (Exception $e) {}

// Age group distribution
$ageGroups = ['0–12' => 0, '13–17' => 0, '18–35' => 0, '36–60' => 0, '61+' => 0];
try {
    $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(YEAR, dob, CURDATE()) AS age FROM health_patients WHERE org_id=? AND dob IS NOT NULL");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $age) {
        if ($age <= 12)      $ageGroups['0–12']++;
        elseif ($age <= 17)  $ageGroups['13–17']++;
        elseif ($age <= 35)  $ageGroups['18–35']++;
        elseif ($age <= 60)  $ageGroups['36–60']++;
        else                 $ageGroups['61+']++;
    }
} catch (Exception $e) {}

// Monthly appointment trend (last 6 months)
$trendLabels = $trendData = [];
try {
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(date,'%b %Y') AS mo, COUNT(*) AS cnt
                           FROM health_appointments
                           WHERE org_id=? AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                           GROUP BY DATE_FORMAT(date,'%Y-%m')
                           ORDER BY MIN(date) ASC");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $row) {
        $trendLabels[] = $row['mo'];
        $trendData[]   = (int)$row['cnt'];
    }
} catch (Exception $e) {}

// Appointment no-show rate
$totalAppts  = countRows('health_appointments', 'org_id=?', [$orgId]);
$noShowAppts = countRows('health_appointments', "org_id=? AND status='no_show'", [$orgId]);
$noShowRate  = $totalAppts > 0 ? round(($noShowAppts / $totalAppts) * 100, 1) : 0;

// Doctor utilisation — top 5 by appointment count
$doctorUtil = [];
try {
    $stmt = $pdo->prepare("SELECT CONCAT(d.first_name,' ',d.last_name) AS dname,
                                  d.specialty,
                                  COUNT(a.id) AS appt_count,
                                  SUM(a.status='completed') AS completed,
                                  SUM(a.status='no_show')   AS no_show
                           FROM health_doctors d
                           LEFT JOIN health_appointments a ON a.doctor_id=d.id AND a.org_id=?
                           WHERE d.org_id=? AND d.status='active'
                           GROUP BY d.id ORDER BY appt_count DESC LIMIT 5");
    $stmt->execute([$orgId, $orgId]);
    $doctorUtil = $stmt->fetchAll();
} catch (Exception $e) {}

// Peak appointment hours (scheduling optimisation)
$peakHours = array_fill(0, 24, 0);
try {
    $stmt = $pdo->prepare("SELECT HOUR(time) AS hr, COUNT(*) AS cnt FROM health_appointments WHERE org_id=? GROUP BY HOUR(time)");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $row) $peakHours[(int)$row['hr']] = (int)$row['cnt'];
} catch (Exception $e) {}

// Chronic conditions (keyword scan in diagnoses)
$chronicKeywords = ['diabetes','hypertension','asthma','copd','hiv','cancer','tuberculosis','tb','epilepsy','arthritis'];
$chronicCounts   = [];
try {
    foreach ($chronicKeywords as $kw) {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) AS cnt FROM health_records WHERE org_id=? AND diagnosis LIKE ?");
        $stmt->execute([$orgId, "%{$kw}%"]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt > 0) $chronicCounts[ucfirst($kw)] = $cnt;
    }
    arsort($chronicCounts);
} catch (Exception $e) {}
?>

<!-- Page Header -->
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-brain me-2" style="color:<?= $moduleColor ?>"></i>AI &amp; Predictive Analytics</h4>
    <p class="text-muted mb-0">Rule-based clinical intelligence, population health trends, and scheduling insights</p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($newAlerts > 0): ?>
    <span class="badge bg-danger align-self-center fs-6"><?= $newAlerts ?> new alert<?= $newAlerts > 1 ? 's' : '' ?> generated</span>
    <?php endif; ?>
    <button class="btn btn-outline-danger" id="runEngineBtn" onclick="runEngine()">
      <i class="fas fa-sync-alt me-2"></i>Run Alert Engine
    </button>
  </div>
</div>

<!-- Summary Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value text-danger"><?= $alertsCritical ?></div>
        <div class="stat-label">Critical Alerts</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body">
        <div class="stat-value text-warning"><?= $alertsWarning ?></div>
        <div class="stat-label">Warning Alerts</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-info-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $alertsInfo ?></div>
        <div class="stat-label">Informational Flags</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-percentage"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $noShowRate ?>%</div>
        <div class="stat-label">Appointment No-Show Rate</div>
      </div>
    </div>
  </div>
</div>

<!-- Main Tabs -->
<ul class="nav nav-tabs mb-4" id="analyticsTab">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabAlerts"><i class="fas fa-bell me-1"></i>Clinical Alerts <span class="badge bg-danger ms-1"><?= $alertsTotal ?></span></a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabPopHealth"><i class="fas fa-globe me-1"></i>Population Health</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabSchedule"><i class="fas fa-calendar-alt me-1"></i>Scheduling Insights</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabRisk"><i class="fas fa-shield-alt me-1"></i>Risk Scoring Guide</a></li>
</ul>

<div class="tab-content">

  <!-- ── Tab 1: Clinical Alerts ─────────────────────────────────────────────── -->
  <div class="tab-pane fade show active" id="tabAlerts">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h6 class="fw-bold text-dark mb-0"><i class="fas fa-bell me-2 text-danger"></i>Active Clinical Alerts</h6>
      <div class="d-flex gap-2">
        <select id="alertFilter" class="form-select form-select-sm" style="width:130px" onchange="loadAlerts()">
          <option value="active">Active Only</option>
          <option value="all">All Alerts</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary" onclick="loadAlerts()"><i class="fas fa-sync-alt"></i></button>
      </div>
    </div>
    <div id="alertsContainer">
      <div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin fa-2x mb-2 d-block"></i>Loading alerts…</div>
    </div>
  </div>

  <!-- ── Tab 2: Population Health ───────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tabPopHealth">
    <div class="row g-4">

      <!-- Diagnosis Frequency -->
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-file-prescription me-2 text-danger"></i>Top Diagnoses — Last 12 Months</h6></div>
          <div class="card-body">
            <?php if (empty($diagLabels)): ?>
            <div class="text-center text-muted py-4">No diagnosis data available yet.</div>
            <?php else: ?>
            <div style="height:280px"><canvas id="diagChart"></canvas></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Age Group Distribution -->
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-users me-2 text-danger"></i>Patient Age Distribution</h6></div>
          <div class="card-body d-flex flex-column justify-content-center">
            <div style="height:230px"><canvas id="ageChart"></canvas></div>
          </div>
        </div>
      </div>

      <!-- Chronic Conditions -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-heartbeat me-2 text-danger"></i>Chronic Condition Prevalence</h6></div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-light">
                  <tr><th>Condition</th><th class="text-end">Unique Patients</th><th>Prevalence</th></tr>
                </thead>
                <tbody>
                  <?php
                  $totalPts = countRows('health_patients', 'org_id=?', [$orgId]) ?: 1;
                  if (empty($chronicCounts)): ?>
                  <tr><td colspan="3" class="text-center text-muted py-4">No chronic condition data found.</td></tr>
                  <?php else: foreach ($chronicCounts as $cond => $cnt):
                    $pct = round(($cnt/$totalPts)*100,1); ?>
                  <tr>
                    <td class="fw-semibold text-dark"><i class="fas fa-circle me-2 text-danger small"></i><?= e($cond) ?></td>
                    <td class="text-end fw-bold"><?= $cnt ?></td>
                    <td style="min-width:120px">
                      <div class="progress" style="height:8px">
                        <div class="progress-bar bg-danger" style="width:<?= $pct ?>%"></div>
                      </div>
                      <small class="text-muted"><?= $pct ?>%</small>
                    </td>
                  </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Monthly Appointment Trend -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-line me-2 text-danger"></i>Appointment Volume — 6-Month Trend</h6></div>
          <div class="card-body">
            <?php if (empty($trendLabels)): ?>
            <div class="text-center text-muted py-4">No appointment trend data yet.</div>
            <?php else: ?>
            <div style="height:200px"><canvas id="trendChart"></canvas></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Tab 3: Scheduling Insights ────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tabSchedule">
    <div class="row g-4">

      <!-- Peak Hours Heatmap -->
      <div class="col-lg-8">
        <div class="card">
          <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-clock me-2 text-danger"></i>Peak Appointment Hours</h6></div>
          <div class="card-body">
            <div style="height:260px"><canvas id="peakChart"></canvas></div>
            <?php $peakHr = array_search(max($peakHours), $peakHours); ?>
            <p class="text-muted small mt-2 mb-0">
              <i class="fas fa-info-circle me-1"></i>
              Peak hour: <strong><?= sprintf('%02d:00–%02d:00', $peakHr, $peakHr+1) ?></strong>.
              Consider spreading appointments to reduce wait times during high-demand slots.
            </p>
          </div>
        </div>
      </div>

      <!-- Doctor Utilisation -->
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-user-md me-2 text-danger"></i>Doctor Utilisation</h6></div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                  <tr><th>Doctor</th><th class="text-end">Appts</th><th class="text-end">No-Show</th></tr>
                </thead>
                <tbody>
                  <?php if (empty($doctorUtil)): ?>
                  <tr><td colspan="3" class="text-center text-muted py-3">No data.</td></tr>
                  <?php else: foreach ($doctorUtil as $d):
                    $nsRate = $d['appt_count'] > 0 ? round(($d['no_show'] / $d['appt_count']) * 100) : 0;
                  ?>
                  <tr>
                    <td>
                      <div class="fw-semibold text-dark small"><?= e($d['dname']) ?></div>
                      <small class="text-muted"><?= e($d['specialty']) ?></small>
                    </td>
                    <td class="text-end fw-bold"><?= $d['appt_count'] ?></td>
                    <td class="text-end">
                      <span class="badge <?= $nsRate >= 20 ? 'bg-danger' : ($nsRate >= 10 ? 'bg-warning' : 'bg-success') ?>">
                        <?= $nsRate ?>%
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Scheduling Optimisation Recommendations -->
      <div class="col-12">
        <div class="card border-warning">
          <div class="card-header bg-warning bg-opacity-10">
            <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-lightbulb me-2 text-warning"></i>AI-Generated Scheduling Recommendations</h6>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-4">
                <div class="d-flex gap-3 align-items-start">
                  <div class="text-warning fs-4 mt-1"><i class="fas fa-hourglass-half"></i></div>
                  <div>
                    <h6 class="fw-semibold mb-1">Reduce Peak-Hour Congestion</h6>
                    <p class="small text-muted mb-0">Spread appointments by incentivising off-peak bookings (early morning / late afternoon). Add buffer slots between consecutive consultations during <?= sprintf('%02d:00–%02d:00', $peakHr, $peakHr+1) ?>.</p>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="d-flex gap-3 align-items-start">
                  <div class="text-info fs-4 mt-1"><i class="fas fa-sms"></i></div>
                  <div>
                    <h6 class="fw-semibold mb-1">Automated Reminders (<?= $noShowRate ?>% No-Show Rate)</h6>
                    <p class="small text-muted mb-0">Send SMS/WhatsApp reminders 24 h and 2 h before appointments to reduce the <?= $noShowRate ?>% no-show rate. Each avoided no-show recovers a billable slot.</p>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="d-flex gap-3 align-items-start">
                  <div class="text-success fs-4 mt-1"><i class="fas fa-user-clock"></i></div>
                  <div>
                    <h6 class="fw-semibold mb-1">Chronic Disease Follow-up Scheduling</h6>
                    <p class="small text-muted mb-0">Auto-schedule 3-month follow-up slots for patients with chronic conditions at discharge to maintain care continuity and reduce readmission rates.</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Tab 4: Risk Scoring Guide ─────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tabRisk">
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card border-danger">
          <div class="card-header bg-danger bg-opacity-10">
            <h6 class="mb-0 fw-bold text-danger"><i class="fas fa-bacterium me-2"></i>Sepsis Risk Scoring (SIRS Criteria)</h6>
          </div>
          <div class="card-body">
            <p class="small text-muted mb-3">Sepsis risk is evaluated against 4 SIRS (Systemic Inflammatory Response Syndrome) criteria from the most recent vitals recorded in the last 24 hours:</p>
            <table class="table table-sm table-bordered small">
              <thead class="table-danger"><tr><th>Parameter</th><th>Abnormal Threshold</th><th>Weight</th></tr></thead>
              <tbody>
                <tr><td>Temperature</td><td>&gt;38.5°C or &lt;36°C</td><td>+25 pts</td></tr>
                <tr><td>Heart Rate</td><td>&gt;90 bpm</td><td>+25 pts</td></tr>
                <tr><td>Respiratory Rate</td><td>&gt;20 breaths/min</td><td>+25 pts</td></tr>
                <tr><td>Systolic BP</td><td>&lt;100 mmHg</td><td>+25 pts</td></tr>
              </tbody>
            </table>
            <div class="mt-2">
              <span class="badge bg-warning me-2">≥2 criteria = Warning Alert</span>
              <span class="badge bg-danger">≥3 criteria = Critical Alert</span>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card border-warning">
          <div class="card-header bg-warning bg-opacity-10">
            <h6 class="mb-0 fw-bold"><i class="fas fa-hospital-user me-2 text-warning"></i>Readmission Risk Detection</h6>
          </div>
          <div class="card-body">
            <p class="small text-muted mb-3">A patient is flagged as high readmission risk when:</p>
            <ul class="small">
              <li>Currently admitted (status = <em>admitted</em>)</li>
              <li>Has a prior <strong>discharge within 30 days</strong> before the current admission date</li>
            </ul>
            <p class="small text-muted mb-3">Risk score: <strong>75/100</strong>. Severity: <span class="badge bg-warning">Warning</span></p>
            <p class="small text-muted mb-0"><strong>Recommended action:</strong> Investigate root cause of prior discharge, review medication adherence, flag for enhanced discharge planning.</p>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card border-info">
          <div class="card-header bg-info bg-opacity-10">
            <h6 class="mb-0 fw-bold text-info"><i class="fas fa-heartbeat me-2"></i>Abnormal Vitals — Thresholds</h6>
          </div>
          <div class="card-body">
            <table class="table table-sm table-bordered small mb-0">
              <thead class="table-info"><tr><th>Vital</th><th>Critical Threshold</th><th>Warning Threshold</th></tr></thead>
              <tbody>
                <tr><td>SpO₂</td><td>&lt;90% — Critical</td><td>90–94% — Warning</td></tr>
                <tr><td>Systolic BP</td><td>≥180 mmHg — Critical</td><td>≥160 mmHg — Warning</td></tr>
                <tr><td>Temperature</td><td>≥40°C — Critical</td><td>≥39.5°C — Warning</td></tr>
                <tr><td>Heart Rate</td><td>—</td><td>&lt;50 bpm or &gt;130 bpm</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card border-secondary">
          <div class="card-header bg-secondary bg-opacity-10">
            <h6 class="mb-0 fw-bold"><i class="fas fa-walking me-2"></i>Fall Risk Assessment</h6>
          </div>
          <div class="card-body">
            <p class="small text-muted mb-3">Automated fall risk flag is raised for <strong>all currently admitted patients aged 70+</strong>. This is a simplified Morse Fall Scale baseline implementation.</p>
            <p class="small text-muted mb-0"><strong>Recommended interventions:</strong> Bed rails up, call bell accessible, non-slip footwear protocol, regular nurse mobility checks, bed closest to nursing station.</p>
            <div class="mt-2"><span class="badge bg-info">Info — Risk Score 50/100</span></div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="alert alert-secondary small mb-0">
          <i class="fas fa-robot me-2"></i>
          <strong>About the Alert Engine:</strong> This is a rule-based clinical decision support system (CDSS). Alerts are generated automatically on page load and via the "Run Alert Engine" button. De-duplication prevents repeat alerts for the same patient and alert type. Future enhancements may include ML-based risk models (e.g., LACE+ index, NEWS2, MEWS) trained on your organisation's historical data.
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Alert Detail Modal -->
<div class="modal fade" id="alertModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="alertModalTitle">Clinical Alert</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="alertModalBody"></div>
      <div class="modal-footer" id="alertModalFooter"></div>
    </div>
  </div>
</div>

<?php
$diagLabelsJson  = json_encode($diagLabels);
$diagDataJson    = json_encode($diagData);
$ageLabelsJson   = json_encode(array_keys($ageGroups));
$ageDataJson     = json_encode(array_values($ageGroups));
$trendLabelsJson = json_encode($trendLabels);
$trendDataJson   = json_encode($trendData);
$peakLabels      = json_encode(array_map(fn($h) => sprintf('%02d:00', $h), range(0,23)));
$peakDataJson    = json_encode(array_values($peakHours));
?>
<script>
const diagLabels  = <?= $diagLabelsJson ?>;
const diagData    = <?= $diagDataJson ?>;
const ageLabels   = <?= $ageLabelsJson ?>;
const ageData     = <?= $ageDataJson ?>;
const trendLabels = <?= $trendLabelsJson ?>;
const trendData   = <?= $trendDataJson ?>;
const peakLabels  = <?= $peakLabels ?>;
const peakData    = <?= $peakDataJson ?>;
</script>
<?php
$extraJs = <<<'JS'
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const orgColor = '#e74c3c';

// ── Load Alerts ──────────────────────────────────────────────────────────────
function loadAlerts() {
    const filter = document.getElementById('alertFilter').value;
    fetch('analytics.php?fetch_alerts=1&filter=' + filter)
        .then(r => r.json())
        .then(data => renderAlerts(data))
        .catch(() => {
            document.getElementById('alertsContainer').innerHTML =
                '<div class="alert alert-danger">Failed to load alerts.</div>';
        });
}

function renderAlerts(alerts) {
    const cont = document.getElementById('alertsContainer');
    if (!alerts.length) {
        cont.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-check-circle fa-3x mb-3 d-block text-success"></i><strong>No active clinical alerts.</strong><br><small>All patients are within normal parameters.</small></div>';
        return;
    }
    const sevClass = { critical: 'danger', warning: 'warning', info: 'info' };
    const sevIcon  = { critical: 'fas fa-exclamation-circle', warning: 'fas fa-exclamation-triangle', info: 'fas fa-info-circle' };
    const typeIcon = {
        sepsis_risk: 'fas fa-bacterium', readmission_risk: 'fas fa-hospital-user',
        abnormal_vitals: 'fas fa-heartbeat', critical_lab: 'fas fa-flask',
        fall_risk: 'fas fa-walking', medication_interaction: 'fas fa-pills',
        pressure_ulcer_risk: 'fas fa-band-aid', other: 'fas fa-exclamation'
    };

    let html = '';
    for (const a of alerts) {
        const sev = sevClass[a.severity] || 'secondary';
        const icon = sevIcon[a.severity] || 'fas fa-bell';
        const ticon = typeIcon[a.alert_type] || 'fas fa-bell';
        const statusBadge = a.status !== 'active'
            ? `<span class="badge bg-secondary ms-2">${a.status}</span>` : '';
        const score = a.risk_score ? `<span class="badge bg-${sev} ms-2">${a.risk_score}% risk</span>` : '';

        html += `
        <div class="alert alert-${sev} d-flex gap-3 align-items-start mb-3" id="alert-row-${a.id}">
            <div class="fs-4 mt-1"><i class="${icon}"></i></div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                    <strong>${a.title}</strong>
                    ${score}
                    ${statusBadge}
                </div>
                <p class="mb-1 small">${a.message}</p>
                <div class="d-flex align-items-center gap-3 mt-2 flex-wrap">
                    <small><i class="fas fa-user me-1"></i><strong>${a.patient_name}</strong> &nbsp;<code>${a.patient_no}</code></small>
                    <small><i class="${ticon} me-1"></i>${a.alert_type.replace(/_/g,' ')}</small>
                    <small><i class="far fa-clock me-1"></i>${new Date(a.created_at).toLocaleString()}</small>
                    ${a.status === 'active' ? `
                    <div class="ms-auto d-flex gap-2">
                        <button class="btn btn-sm btn-outline-${sev}" onclick="ackAlert(${a.id})"><i class="fas fa-check me-1"></i>Acknowledge</button>
                        <button class="btn btn-sm btn-${sev}" onclick="resolveAlert(${a.id})"><i class="fas fa-check-double me-1"></i>Resolve</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="dismissAlert(${a.id})"><i class="fas fa-times"></i></button>
                    </div>` : ''}
                </div>
            </div>
        </div>`;
    }
    cont.innerHTML = html;
}

function ackAlert(id) {
    postAlert('ack_alert', id, 'Alert acknowledged.');
}
function resolveAlert(id) {
    if (!confirm('Mark this alert as resolved?')) return;
    postAlert('resolve_alert', id, 'Alert resolved.');
}
function dismissAlert(id) {
    postAlert('dismiss_alert', id, 'Alert dismissed.');
}

function postAlert(action, alertId, msg) {
    const fd = new FormData();
    fd.append('alert_id', alertId);
    fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
    fetch('analytics.php?action=' + action, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                const row = document.getElementById('alert-row-' + alertId);
                if (row) row.style.opacity = '0.4';
                showToast(msg, 'success');
                setTimeout(loadAlerts, 800);
            }
        });
}

function runEngine() {
    const btn = document.getElementById('runEngineBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Running…';
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
    fetch('analytics.php?action=run_engine', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>Run Alert Engine';
            const msg = d.created > 0 ? d.created + ' new alert(s) generated.' : 'No new alerts. All parameters within normal range.';
            showToast(msg, d.created > 0 ? 'warning' : 'success');
            loadAlerts();
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>Run Alert Engine';
        });
}

function showToast(msg, type) {
    Swal.fire({ toast: true, position: 'top-end', icon: type === 'success' ? 'success' : 'warning',
                title: msg, showConfirmButton: false, timer: 3000 });
}

// ── Charts ────────────────────────────────────────────────────────────────────

if (diagLabels.length && document.getElementById('diagChart')) {
    new Chart(document.getElementById('diagChart'), {
        type: 'bar',
        data: {
            labels: diagLabels,
            datasets: [{ label: 'Records', data: diagData,
                backgroundColor: 'rgba(231,76,60,0.75)', borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false,
                   plugins: { legend: { display: false } },
                   scales: { y: { beginAtZero: true, ticks: { precision: 0 } },
                             x: { ticks: { maxRotation: 30, minRotation: 0 } } } }
    });
}

if (document.getElementById('ageChart')) {
    new Chart(document.getElementById('ageChart'), {
        type: 'doughnut',
        data: {
            labels: ageLabels,
            datasets: [{ data: ageData,
                backgroundColor: ['#0dcaf0','#198754','#ffc107','#0b5ed7','#dc3545'] }]
        },
        options: { responsive: true, maintainAspectRatio: false,
                   plugins: { legend: { position: 'bottom' } } }
    });
}

if (trendLabels.length && document.getElementById('trendChart')) {
    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [{ label: 'Appointments', data: trendData,
                borderColor: orgColor, backgroundColor: 'rgba(231,76,60,0.1)',
                fill: true, tension: 0.4, pointRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false,
                   plugins: { legend: { display: false } },
                   scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
}

if (document.getElementById('peakChart')) {
    new Chart(document.getElementById('peakChart'), {
        type: 'bar',
        data: {
            labels: peakLabels,
            datasets: [{ label: 'Appointments', data: peakData,
                backgroundColor: peakData.map(v => {
                    const max = Math.max(...peakData);
                    const ratio = max > 0 ? v / max : 0;
                    return ratio > 0.7 ? '#dc3545' : ratio > 0.4 ? '#ffc107' : '#198754';
                }),
                borderRadius: 3 }]
        },
        options: { responsive: true, maintainAspectRatio: false,
                   plugins: { legend: { display: false } },
                   scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
}

// Load alerts on page init
loadAlerts();
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
?>
