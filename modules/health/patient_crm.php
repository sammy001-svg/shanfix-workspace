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
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-heart',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'Analytics & AI'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
];

// ── AJAX: fetch follow-up ─────────────────────────────────────────
if (isset($_GET['fetch_followup'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_followup'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_followups WHERE id=? AND org_id=?");
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
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $uid   = (int)$user['id'];
    $action = $_POST['action'] ?? '';

    // ── Save feedback ─────────────────────────────────────────────
    if ($action === 'save_feedback') {
        $patientId   = (int)($_POST['patient_id']     ?? 0);
        $apptId      = (int)($_POST['appointment_id'] ?? 0) ?: null;
        $doctorId    = (int)($_POST['doctor_id']      ?? 0) ?: null;
        $overallRating = (int)($_POST['overall_rating'] ?? 0) ?: null;
        $doctorRating  = (int)($_POST['doctor_rating']  ?? 0) ?: null;
        $waitRating    = (int)($_POST['wait_rating']    ?? 0) ?: null;
        $facRating     = (int)($_POST['facility_rating']?? 0) ?: null;
        $recommend     = isset($_POST['would_recommend']) ? (int)$_POST['would_recommend'] : null;
        $comments      = sanitize($_POST['comments'] ?? '');

        if (!$patientId) { setFlash('error','Patient required.'); redirect('patient_crm.php?tab=feedback'); }

        $pdo->prepare("INSERT INTO health_patient_feedback (org_id,patient_id,appointment_id,doctor_id,overall_rating,doctor_rating,wait_rating,facility_rating,would_recommend,comments,source) VALUES (?,?,?,?,?,?,?,?,?,?,'manual')")
            ->execute([$orgId,$patientId,$apptId,$doctorId,$overallRating,$doctorRating,$waitRating,$facRating,$recommend,$comments]);
        setFlash('success','Feedback saved.');
        redirect('patient_crm.php?tab=feedback');
    }

    // ── Save follow-up ────────────────────────────────────────────
    if ($action === 'save_followup') {
        $id         = (int)($_POST['id']           ?? 0);
        $patientId  = (int)($_POST['patient_id']   ?? 0);
        $doctorId   = (int)($_POST['doctor_id']    ?? 0) ?: null;
        $assignedTo = (int)($_POST['assigned_to']  ?? 0) ?: null;
        $type       = in_array($_POST['followup_type'] ?? '', ['call','sms','email','appointment','lab_check','medication_review','other']) ? $_POST['followup_type'] : 'call';
        $dueDate    = sanitize($_POST['due_date']  ?? date('Y-m-d'));
        $priority   = in_array($_POST['priority'] ?? '', ['low','normal','high','urgent']) ? $_POST['priority'] : 'normal';
        $reason     = sanitize($_POST['reason']   ?? '');
        $notes      = sanitize($_POST['notes']    ?? '');

        if (!$patientId) { setFlash('error','Patient required.'); redirect('patient_crm.php?tab=followups'); }

        if ($id) {
            $pdo->prepare("UPDATE health_followups SET patient_id=?,doctor_id=?,assigned_to=?,followup_type=?,due_date=?,priority=?,reason=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$patientId,$doctorId,$assignedTo,$type,$dueDate,$priority,$reason,$notes,$id,$orgId]);
            setFlash('success','Follow-up updated.');
        } else {
            $pdo->prepare("INSERT INTO health_followups (org_id,patient_id,doctor_id,assigned_to,followup_type,due_date,priority,reason,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$patientId,$doctorId,$assignedTo,$type,$dueDate,$priority,$reason,$notes,$uid]);
            setFlash('success','Follow-up scheduled.');
        }
        redirect('patient_crm.php?tab=followups');
    }

    // ── Complete follow-up ────────────────────────────────────────
    if ($action === 'complete_followup') {
        $id    = (int)($_POST['id']    ?? 0);
        $notes = sanitize($_POST['notes'] ?? '');
        $pdo->prepare("UPDATE health_followups SET status='completed',completed_at=NOW(),completed_by=?,notes=CONCAT(COALESCE(notes,''),' | ',?) WHERE id=? AND org_id=?")
            ->execute([$uid,$notes,$id,$orgId]);
        setFlash('success','Follow-up marked complete.');
        redirect('patient_crm.php?tab=followups');
    }

    // ── Save campaign ─────────────────────────────────────────────
    if ($action === 'save_campaign') {
        $id          = (int)($_POST['id']          ?? 0);
        $name        = sanitize($_POST['name']     ?? '');
        $type        = in_array($_POST['campaign_type'] ?? '', ['sms','email','whatsapp','other']) ? $_POST['campaign_type'] : 'sms';
        $target      = in_array($_POST['target_group'] ?? '', ['all_patients','by_diagnosis','by_doctor','chronic_conditions','due_for_followup','no_visit_90d','custom']) ? $_POST['target_group'] : 'all_patients';
        $subject     = sanitize($_POST['subject']  ?? '');
        $message     = sanitize($_POST['message']  ?? '');
        $status      = in_array($_POST['status'] ?? '', ['draft','scheduled','sent','cancelled']) ? $_POST['status'] : 'draft';
        $schAt       = $_POST['scheduled_at']      ?? null ?: null;

        if (!$name || !$message) { setFlash('error','Name and message required.'); redirect('patient_crm.php?tab=campaigns'); }

        if ($id) {
            $pdo->prepare("UPDATE health_patient_campaigns SET name=?,campaign_type=?,target_group=?,subject=?,message=?,status=?,scheduled_at=? WHERE id=? AND org_id=?")
                ->execute([$name,$type,$target,$subject,$message,$status,$schAt,$id,$orgId]);
        } else {
            $pdo->prepare("INSERT INTO health_patient_campaigns (org_id,name,campaign_type,target_group,subject,message,status,scheduled_at,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$name,$type,$target,$subject,$message,$status,$schAt,$uid]);
        }
        setFlash('success','Campaign saved.');
        redirect('patient_crm.php?tab=campaigns');
    }

    // ── Award loyalty points ──────────────────────────────────────
    if ($action === 'award_points') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $points    = (int)($_POST['points']     ?? 0);
        $reason    = sanitize($_POST['reason']  ?? '');

        if (!$patientId || !$points) { setFlash('error','Patient and points required.'); redirect('patient_crm.php?tab=loyalty'); }

        $pdo->beginTransaction();
        try {
            $curSt = $pdo->prepare("SELECT COALESCE(loyalty_points,0) FROM health_patients WHERE id=? AND org_id=?");
            $curSt->execute([$patientId,$orgId]);
            $current = (int)$curSt->fetchColumn();
            $newBal  = max(0, $current + $points);
            $pdo->prepare("UPDATE health_patients SET loyalty_points=? WHERE id=? AND org_id=?")->execute([$newBal,$patientId,$orgId]);
            $pdo->prepare("INSERT INTO health_loyalty_points (org_id,patient_id,transaction_type,points,balance_after,reason,created_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$orgId,$patientId,($points>0?'earn':'redeem'),$points,$newBal,$reason,$uid]);
            $pdo->commit();
            setFlash('success','Points updated. New balance: '.$newBal);
        } catch (Exception $ex) {
            $pdo->rollBack();
            setFlash('error','Failed: '.$ex->getMessage());
        }
        redirect('patient_crm.php?tab=loyalty');
    }
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['feedback','followups','campaigns','loyalty']) ? $_GET['tab'] : 'followups';

// ── Shared dropdowns ──────────────────────────────────────────────
$patientsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, patient_no, loyalty_points FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
$patientsSt->execute([$orgId]);
$patients = $patientsSt->fetchAll(PDO::FETCH_ASSOC);

$doctorsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name FROM health_doctors WHERE org_id=? AND status='active' ORDER BY first_name");
$doctorsSt->execute([$orgId]);
$doctors = $doctorsSt->fetchAll(PDO::FETCH_ASSOC);

$usersSt = $pdo->prepare("SELECT id, name FROM users WHERE org_id=? AND status='active' ORDER BY name");
$usersSt->execute([$orgId]);
$staffUsers = $usersSt->fetchAll(PDO::FETCH_ASSOC);

// ── Follow-ups ────────────────────────────────────────────────────
$fuStatus = sanitize($_GET['fu_status'] ?? 'pending');
$fuPid    = (int)($_GET['fu_patient'] ?? 0);
$fuWhere  = "f.org_id=?";
$fuParams = [$orgId];
if ($fuStatus) { $fuWhere .= " AND f.status=?"; $fuParams[] = $fuStatus; }
if ($fuPid)    { $fuWhere .= " AND f.patient_id=?"; $fuParams[] = $fuPid; }

$fuSt = $pdo->prepare("
    SELECT f.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
           u.name AS assigned_name
    FROM health_followups f
    LEFT JOIN health_patients p ON p.id=f.patient_id
    LEFT JOIN health_doctors d ON d.id=f.doctor_id
    LEFT JOIN users u ON u.id=f.assigned_to
    WHERE {$fuWhere}
    ORDER BY f.due_date ASC, FIELD(f.priority,'urgent','high','normal','low')
    LIMIT 200
");
$fuSt->execute($fuParams);
$followups = $fuSt->fetchAll(PDO::FETCH_ASSOC);

// ── Feedback ──────────────────────────────────────────────────────
$fbPid = (int)($_GET['fb_patient'] ?? 0);
$fbWhere  = "f.org_id=?";
$fbParams = [$orgId];
if ($fbPid) { $fbWhere .= " AND f.patient_id=?"; $fbParams[] = $fbPid; }

$fbSt = $pdo->prepare("
    SELECT f.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
           CONCAT(d.first_name,' ',d.last_name) AS doctor_name
    FROM health_patient_feedback f
    LEFT JOIN health_patients p ON p.id=f.patient_id
    LEFT JOIN health_doctors d ON d.id=f.doctor_id
    WHERE {$fbWhere}
    ORDER BY f.created_at DESC LIMIT 100
");
$fbSt->execute($fbParams);
$feedbacks = $fbSt->fetchAll(PDO::FETCH_ASSOC);

// ── Campaigns ─────────────────────────────────────────────────────
$campSt = $pdo->prepare("SELECT * FROM health_patient_campaigns WHERE org_id=? ORDER BY created_at DESC LIMIT 100");
$campSt->execute([$orgId]);
$campaigns = $campSt->fetchAll(PDO::FETCH_ASSOC);

// ── Loyalty top patients ──────────────────────────────────────────
$loyaltySt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, patient_no, loyalty_points FROM health_patients WHERE org_id=? ORDER BY loyalty_points DESC LIMIT 50");
$loyaltySt->execute([$orgId]);
$loyaltyLeaders = $loyaltySt->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────
$dueTodaySt = $pdo->prepare("SELECT COUNT(*) FROM health_followups WHERE org_id=? AND status='pending' AND due_date=CURDATE()");
$dueTodaySt->execute([$orgId]);
$dueToday = (int)$dueTodaySt->fetchColumn();

$overdueStmt = $pdo->prepare("SELECT COUNT(*) FROM health_followups WHERE org_id=? AND status='pending' AND due_date < CURDATE()");
$overdueStmt->execute([$orgId]);
$overdue = (int)$overdueStmt->fetchColumn();

$avgRatingSt = $pdo->prepare("SELECT ROUND(AVG(overall_rating),1) FROM health_patient_feedback WHERE org_id=? AND overall_rating IS NOT NULL");
$avgRatingSt->execute([$orgId]);
$avgRating = $avgRatingSt->fetchColumn() ?: '—';

$campSentSt = $pdo->prepare("SELECT COUNT(*) FROM health_patient_campaigns WHERE org_id=? AND status='sent'");
$campSentSt->execute([$orgId]);
$campSent = (int)$campSentSt->fetchColumn();

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="container-fluid py-4">

  <?php flash(); ?>

  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h4 class="mb-0 fw-bold"><i class="fas fa-heart me-2 text-danger"></i>Patient CRM</h4>
      <small class="text-muted">Follow-ups, feedback, campaigns & loyalty program</small>
    </div>
    <div class="d-flex gap-2">
      <?php if ($tab === 'followups'): ?>
        <button class="btn btn-danger btn-sm" onclick="openFollowupModal()" data-bs-toggle="modal" data-bs-target="#followupModal">
          <i class="fas fa-plus me-1"></i>Schedule Follow-up
        </button>
      <?php elseif ($tab === 'feedback'): ?>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#feedbackModal">
          <i class="fas fa-plus me-1"></i>Add Feedback
        </button>
      <?php elseif ($tab === 'campaigns'): ?>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#campModal">
          <i class="fas fa-plus me-1"></i>New Campaign
        </button>
      <?php elseif ($tab === 'loyalty'): ?>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#loyaltyModal">
          <i class="fas fa-star me-1"></i>Award Points
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stats -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3 <?= $dueToday > 0 ? 'border-warning' : '' ?>">
        <div class="text-warning fs-3 fw-bold"><?= $dueToday ?></div>
        <small class="text-muted">Follow-ups Today</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3 <?= $overdue > 0 ? 'border-danger' : '' ?>">
        <div class="text-danger fs-3 fw-bold"><?= $overdue ?></div>
        <small class="text-muted">Overdue</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-success fs-3 fw-bold"><?= $avgRating ?> <i class="fas fa-star text-warning" style="font-size:1rem"></i></div>
        <small class="text-muted">Avg Satisfaction</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-primary fs-3 fw-bold"><?= $campSent ?></div>
        <small class="text-muted">Campaigns Sent</small>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab==='followups'?'active':'' ?>" href="?tab=followups"><i class="fas fa-bell me-1"></i>Follow-ups <span class="badge bg-warning text-dark ms-1"><?= $dueToday + $overdue ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='feedback' ?'active':'' ?>" href="?tab=feedback"><i class="fas fa-star me-1"></i>Feedback</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='campaigns'?'active':'' ?>" href="?tab=campaigns"><i class="fas fa-bullhorn me-1"></i>Campaigns</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='loyalty'  ?'active':'' ?>" href="?tab=loyalty"><i class="fas fa-award me-1"></i>Loyalty</a></li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════
       FOLLOW-UPS
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'followups'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="followups">
        <div class="col-6 col-md-2">
          <select name="fu_status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="pending"   <?= $fuStatus==='pending'  ?'selected':'' ?>>Pending</option>
            <option value="completed" <?= $fuStatus==='completed'?'selected':'' ?>>Completed</option>
            <option value="missed"    <?= $fuStatus==='missed'   ?'selected':'' ?>>Missed</option>
            <option value=""                                                     >All</option>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <select name="fu_patient" class="form-select form-select-sm select2" onchange="this.form.submit()">
            <option value="">All Patients</option>
            <?php foreach ($patients as $p): ?>
              <option value="<?= $p['id'] ?>" <?= $fuPid==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto"><a href="?tab=followups" class="btn btn-outline-secondary btn-sm">Clear</a></div>
      </form>
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="fuTable">
          <thead class="table-light">
            <tr><th>Patient</th><th>Type</th><th>Due Date</th><th>Priority</th><th>Doctor</th><th>Assigned To</th><th>Reason</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (empty($followups)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No follow-ups found.</td></tr>
          <?php else: foreach ($followups as $f):
            $isOverdue = ($f['status']==='pending' && $f['due_date'] < date('Y-m-d'));
            $priBadge  = match($f['priority']) { 'urgent'=>'danger', 'high'=>'warning text-dark', 'normal'=>'primary', default=>'secondary' };
            $typIcon   = match($f['followup_type']) { 'call'=>'fas fa-phone', 'sms'=>'fas fa-sms', 'email'=>'fas fa-envelope', 'appointment'=>'fas fa-calendar', 'lab_check'=>'fas fa-flask', default=>'fas fa-tasks' };
          ?>
            <tr class="<?= $isOverdue ? 'table-warning' : '' ?>">
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($f['patient_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($f['patient_no']) ?></small>
              </td>
              <td><i class="<?= $typIcon ?> me-1 text-muted"></i><?= ucfirst(str_replace('_',' ',$f['followup_type'])) ?></td>
              <td>
                <?php if ($isOverdue): ?>
                  <span class="text-danger fw-bold"><i class="fas fa-exclamation-triangle me-1"></i><?= date('d M Y', strtotime($f['due_date'])) ?></span>
                <?php else: ?>
                  <small><?= date('d M Y', strtotime($f['due_date'])) ?></small>
                <?php endif; ?>
              </td>
              <td><span class="badge bg-<?= $priBadge ?>"><?= ucfirst($f['priority']) ?></span></td>
              <td><small><?= htmlspecialchars($f['doctor_name'] ?: '—') ?></small></td>
              <td><small><?= htmlspecialchars($f['assigned_name'] ?: '—') ?></small></td>
              <td style="max-width:200px"><small><?= htmlspecialchars($f['reason'] ?: '—') ?></small></td>
              <td>
                <?php if ($f['status']==='completed'): ?>
                  <span class="badge bg-success">Done</span>
                <?php elseif ($isOverdue): ?>
                  <span class="badge bg-danger">Overdue</span>
                <?php else: ?>
                  <span class="badge bg-warning text-dark">Pending</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="btn-group btn-group-sm">
                  <?php if ($f['status']==='pending'): ?>
                    <button class="btn btn-outline-success btn-sm" onclick="completeFu(<?= $f['id'] ?>)" title="Complete"><i class="fas fa-check"></i></button>
                    <button class="btn btn-outline-primary btn-sm" onclick="openFollowupModal(<?= $f['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
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
       FEEDBACK
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'feedback'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="feedback">
        <div class="col-12 col-md-4">
          <select name="fb_patient" class="form-select form-select-sm select2" onchange="this.form.submit()">
            <option value="">All Patients</option>
            <?php foreach ($patients as $p): ?>
              <option value="<?= $p['id'] ?>" <?= $fbPid==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto"><a href="?tab=feedback" class="btn btn-outline-secondary btn-sm">Clear</a></div>
      </form>
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="fbTable">
          <thead class="table-light">
            <tr><th>Patient</th><th>Overall</th><th>Doctor</th><th>Wait</th><th>Facility</th><th>Recommend</th><th>Doctor</th><th>Comments</th><th>Date</th></tr>
          </thead>
          <tbody>
          <?php if (empty($feedbacks)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No feedback records.</td></tr>
          <?php else: foreach ($feedbacks as $f):
            $stars = function($n) {
                if (!$n) return '<span class="text-muted">—</span>';
                return str_repeat('⭐', (int)$n);
            };
          ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($f['patient_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($f['patient_no']) ?></small>
              </td>
              <td><?= $stars($f['overall_rating']) ?></td>
              <td><?= $stars($f['doctor_rating']) ?></td>
              <td><?= $stars($f['wait_rating']) ?></td>
              <td><?= $stars($f['facility_rating']) ?></td>
              <td>
                <?php if ($f['would_recommend'] === null): ?>—
                <?php elseif ($f['would_recommend']): ?><span class="badge bg-success">Yes</span>
                <?php else: ?><span class="badge bg-danger">No</span><?php endif; ?>
              </td>
              <td><small><?= htmlspecialchars($f['doctor_name'] ?: '—') ?></small></td>
              <td style="max-width:200px"><small><?= htmlspecialchars($f['comments'] ?: '—') ?></small></td>
              <td><small><?= date('d M Y', strtotime($f['created_at'])) ?></small></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════
       CAMPAIGNS
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'campaigns'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="campTable">
          <thead class="table-light">
            <tr><th>Name</th><th>Type</th><th>Target</th><th>Status</th><th>Scheduled</th><th>Recipients</th><th>Sent</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (empty($campaigns)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No campaigns yet.</td></tr>
          <?php else: foreach ($campaigns as $c):
            $stBadge = match($c['status']) {
                'sent'      => 'success', 'active'=>'success',
                'scheduled' => 'warning text-dark',
                'draft'     => 'secondary',
                'cancelled' => 'danger',
                default     => 'light text-dark'
            };
          ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($c['name']) ?></td>
              <td><span class="badge bg-info text-dark"><?= strtoupper($c['campaign_type']) ?></span></td>
              <td><small><?= ucwords(str_replace('_',' ',$c['target_group'])) ?></small></td>
              <td><span class="badge bg-<?= $stBadge ?>"><?= ucfirst($c['status']) ?></span></td>
              <td><small><?= $c['scheduled_at'] ? date('d M Y H:i', strtotime($c['scheduled_at'])) : '—' ?></small></td>
              <td><?= number_format($c['recipients_count']) ?></td>
              <td><?= number_format($c['sent_count']) ?></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary btn-sm" onclick="openCampModal(<?= $c['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
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
       LOYALTY
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'loyalty'): ?>
  <div class="row g-3">
    <div class="col-12 col-lg-8">
      <div class="card shadow-sm border-0">
        <div class="card-header fw-semibold"><i class="fas fa-trophy text-warning me-2"></i>Loyalty Leaderboard</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
              <thead class="table-light"><tr><th>#</th><th>Patient</th><th>Patient No</th><th>Points</th><th>Action</th></tr></thead>
              <tbody>
              <?php if (empty($loyaltyLeaders)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No patients yet.</td></tr>
              <?php else: foreach ($loyaltyLeaders as $i => $lp): ?>
                <tr>
                  <td>
                    <?php if ($i === 0): ?><span class="text-warning fs-5">🥇</span>
                    <?php elseif ($i === 1): ?><span class="text-secondary fs-5">🥈</span>
                    <?php elseif ($i === 2): ?><span class="fs-5">🥉</span>
                    <?php else: ?><span class="text-muted"><?= $i+1 ?></span><?php endif; ?>
                  </td>
                  <td class="fw-semibold"><?= htmlspecialchars($lp['name']) ?></td>
                  <td><small><?= htmlspecialchars($lp['patient_no']) ?></small></td>
                  <td><span class="badge bg-warning text-dark fs-6"><?= number_format($lp['loyalty_points']) ?> pts</span></td>
                  <td>
                    <button class="btn btn-outline-warning btn-sm" onclick="openLoyaltyModal(<?= $lp['id'] ?>, '<?= htmlspecialchars(addslashes($lp['name'])) ?>', <?= $lp['loyalty_points'] ?>)">
                      <i class="fas fa-star me-1"></i>Award
                    </button>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-4">
      <div class="card shadow-sm border-0">
        <div class="card-header fw-semibold"><i class="fas fa-info-circle text-primary me-2"></i>Loyalty Rules</div>
        <div class="card-body">
          <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between"><span><i class="fas fa-calendar-check text-success me-2"></i>Appointment Attended</span><strong>+10 pts</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span><i class="fas fa-flask text-primary me-2"></i>Lab Test Done</span><strong>+5 pts</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span><i class="fas fa-user-plus text-info me-2"></i>Referral</span><strong>+50 pts</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span><i class="fas fa-star text-warning me-2"></i>5-Star Feedback</span><strong>+20 pts</strong></li>
            <li class="list-group-item d-flex justify-content-between text-danger"><span><i class="fas fa-undo me-2"></i>Redemption (100 pts)</span><strong>KES 100 off</strong></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<!-- ── Modal: Follow-up ───────────────────────────────────────────── -->
<div class="modal fade" id="followupModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_followup">
      <input type="hidden" name="id" id="fuId">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-bell me-2"></i><span id="fuModalTitle">Schedule Follow-up</span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
              <select name="patient_id" id="fuPatient" class="form-select select2" required>
                <option value="">Select Patient</option>
                <?php foreach ($patients as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Doctor</label>
              <select name="doctor_id" id="fuDoctor" class="form-select select2">
                <option value="">— Any —</option>
                <?php foreach ($doctors as $d): ?>
                  <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Type</label>
              <select name="followup_type" id="fuType" class="form-select">
                <option value="call">Phone Call</option>
                <option value="sms">SMS</option>
                <option value="email">Email</option>
                <option value="appointment">Appointment</option>
                <option value="lab_check">Lab Check</option>
                <option value="medication_review">Medication Review</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Priority</label>
              <select name="priority" id="fuPriority" class="form-select">
                <option value="low">Low</option>
                <option value="normal" selected>Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
              <input type="date" name="due_date" id="fuDue" class="form-control" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Assign To</label>
              <select name="assigned_to" id="fuAssigned" class="form-select select2">
                <option value="">Unassigned</option>
                <?php foreach ($staffUsers as $u): ?>
                  <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Reason</label>
              <input type="text" name="reason" id="fuReason" class="form-control" placeholder="e.g. Post-surgery review, Medication check…">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="fuNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Complete Follow-up ─────────────────────────────────── -->
<div class="modal fade" id="completeFuModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="complete_followup">
      <input type="hidden" name="id" id="completeFuId">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="fas fa-check me-2"></i>Complete Follow-up</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label fw-semibold">Outcome Notes</label>
          <textarea name="notes" class="form-control" rows="3" placeholder="What happened? Outcome?"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>Mark Complete</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Feedback ────────────────────────────────────────────── -->
<div class="modal fade" id="feedbackModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_feedback">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title"><i class="fas fa-star me-2"></i>Patient Feedback</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
              <select name="patient_id" class="form-select select2" required>
                <option value="">Select Patient</option>
                <?php foreach ($patients as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Doctor</label>
              <select name="doctor_id" class="form-select select2">
                <option value="">—</option>
                <?php foreach ($doctors as $d): ?>
                  <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php foreach ([['overall_rating','Overall Experience'],['doctor_rating','Doctor Rating'],['wait_rating','Wait Time'],['facility_rating','Facility']] as [$fname,$label]): ?>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold"><?= $label ?></label>
              <select name="<?= $fname ?>" class="form-select">
                <option value="">—</option>
                <option value="1">1 ⭐</option><option value="2">2 ⭐⭐</option><option value="3">3 ⭐⭐⭐</option>
                <option value="4">4 ⭐⭐⭐⭐</option><option value="5">5 ⭐⭐⭐⭐⭐</option>
              </select>
            </div>
            <?php endforeach; ?>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold">Would Recommend?</label>
              <select name="would_recommend" class="form-select">
                <option value="">—</option>
                <option value="1">Yes</option>
                <option value="0">No</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Comments</label>
              <textarea name="comments" class="form-control" rows="3"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning text-dark"><i class="fas fa-save me-1"></i>Save Feedback</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Campaign ────────────────────────────────────────────── -->
<div class="modal fade" id="campModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_campaign">
      <input type="hidden" name="id" id="campId">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i>Patient Campaign</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Campaign Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="campName" class="form-control" required>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Type</label>
              <select name="campaign_type" id="campType" class="form-select">
                <option value="sms">SMS</option>
                <option value="email">Email</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="campStatus" class="form-select">
                <option value="draft">Draft</option>
                <option value="scheduled">Scheduled</option>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Target Group</label>
              <select name="target_group" id="campTarget" class="form-select">
                <option value="all_patients">All Patients</option>
                <option value="chronic_conditions">Chronic Conditions</option>
                <option value="due_for_followup">Due for Follow-up</option>
                <option value="no_visit_90d">No Visit in 90 Days</option>
                <option value="by_diagnosis">By Diagnosis</option>
                <option value="by_doctor">By Doctor</option>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Schedule At</label>
              <input type="datetime-local" name="scheduled_at" id="campSchedule" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Subject (Email)</label>
              <input type="text" name="subject" id="campSubject" class="form-control" placeholder="Email subject line…">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
              <textarea name="message" id="campMessage" class="form-control" rows="4" required placeholder="Dear {patient_name}, …"></textarea>
              <div class="form-text">Variables: {patient_name}, {patient_no}, {doctor_name}, {hospital_name}</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save Campaign</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Award Loyalty Points ───────────────────────────────── -->
<div class="modal fade" id="loyaltyModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="award_points">
      <input type="hidden" name="patient_id" id="loyPatientId">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title"><i class="fas fa-star me-2"></i>Award Loyalty Points</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-light border mb-3 py-2">
            Patient: <strong id="loyPatientName"></strong> | Current: <strong id="loyCurrentPts"></strong> pts
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Patient</label>
            <select name="patient_id" id="loyPatientSel" class="form-select select2">
              <option value="">Select Patient</option>
              <?php foreach ($patients as $p): ?>
                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['loyalty_points'] ?> pts)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Points (use negative to deduct)</label>
            <input type="number" name="points" class="form-control" required placeholder="+10 or -50">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Reason</label>
            <input type="text" name="reason" class="form-control" list="loyReasonList" placeholder="e.g. Appointment attended">
            <datalist id="loyReasonList">
              <option>Appointment attended</option><option>Lab test done</option>
              <option>5-star feedback</option><option>Referral</option>
              <option>Annual health check</option><option>Redeemed reward</option>
            </datalist>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning text-dark"><i class="fas fa-save me-1"></i>Update Points</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function openFollowupModal(id) {
    document.getElementById('fuId').value = '';
    document.getElementById('fuModalTitle').textContent = 'Schedule Follow-up';
    if (id) {
        document.getElementById('fuModalTitle').textContent = 'Edit Follow-up';
        fetch('patient_crm.php?fetch_followup=' + id)
            .then(r => r.json())
            .then(d => {
                if (!d.id) return;
                document.getElementById('fuId').value       = d.id;
                document.getElementById('fuPatient').value  = d.patient_id    || '';
                document.getElementById('fuDoctor').value   = d.doctor_id     || '';
                document.getElementById('fuType').value     = d.followup_type || 'call';
                document.getElementById('fuPriority').value = d.priority      || 'normal';
                document.getElementById('fuDue').value      = d.due_date      || '';
                document.getElementById('fuAssigned').value = d.assigned_to   || '';
                document.getElementById('fuReason').value   = d.reason        || '';
                document.getElementById('fuNotes').value    = d.notes         || '';
            });
    }
    new bootstrap.Modal(document.getElementById('followupModal')).show();
}

function completeFu(id) {
    document.getElementById('completeFuId').value = id;
    new bootstrap.Modal(document.getElementById('completeFuModal')).show();
}

function openLoyaltyModal(id, name, pts) {
    document.getElementById('loyPatientId').value       = id;
    document.getElementById('loyPatientName').textContent = name;
    document.getElementById('loyCurrentPts').textContent  = pts;
    document.getElementById('loyPatientSel').value       = id;
    new bootstrap.Modal(document.getElementById('loyaltyModal')).show();
}

function openCampModal(id) {
    document.getElementById('campId').value = id || '';
    new bootstrap.Modal(document.getElementById('campModal')).show();
}

$(document).ready(function () {
    if ($('#fuTable').length)   $('#fuTable').DataTable({ pageLength:25, order:[[2,'asc']], columnDefs:[{orderable:false,targets:[8]}] });
    if ($('#fbTable').length)   $('#fbTable').DataTable({ pageLength:25, order:[[8,'desc']] });
    if ($('#campTable').length) $('#campTable').DataTable({ pageLength:25, order:[[4,'desc']], columnDefs:[{orderable:false,targets:[7]}] });
    if (typeof $.fn.select2 !== 'undefined') {
        $('.select2').select2({ theme:'bootstrap-5', dropdownParent: document.body, width:'100%' });
    }
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
