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

// ── AJAX: fetch nursing note ──────────────────────────────────────
if (isset($_GET['fetch_note'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_note'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_nursing_notes WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── AJAX: fetch MAR entry ─────────────────────────────────────────
if (isset($_GET['fetch_mar'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_mar'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_mar WHERE id=? AND org_id=?");
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

    // ── Save nursing note ─────────────────────────────────────────
    if ($action === 'save_note') {
        $id        = (int)($_POST['id']           ?? 0);
        $patientId = (int)($_POST['patient_id']   ?? 0);
        $admId     = (int)($_POST['admission_id'] ?? 0) ?: null;
        $noteType  = in_array($_POST['note_type'] ?? '', ['general','shift_handover','care_plan','observation','incident']) ? $_POST['note_type'] : 'general';
        $shift     = in_array($_POST['shift']     ?? '', ['morning','afternoon','night','']) ? ($_POST['shift'] ?: null) : null;
        $noteText  = sanitize($_POST['note_text'] ?? '');

        if (!$patientId || !$noteText) { setFlash('error', 'Patient and note text are required.'); redirect('nursing.php?tab=notes'); }

        if ($id) {
            $pdo->prepare("UPDATE health_nursing_notes SET patient_id=?,admission_id=?,note_type=?,shift=?,note_text=? WHERE id=? AND org_id=?")
                ->execute([$patientId,$admId,$noteType,$shift,$noteText,$id,$orgId]);
            setFlash('success', 'Note updated.');
        } else {
            $pdo->prepare("INSERT INTO health_nursing_notes (org_id,patient_id,admission_id,nurse_id,note_type,shift,note_text) VALUES (?,?,?,?,?,?,?)")
                ->execute([$orgId,$patientId,$admId,$uid,$noteType,$shift,$noteText]);
            setFlash('success', 'Nursing note saved.');
        }
        redirect('nursing.php?tab=notes');
    }

    // ── Delete nursing note ───────────────────────────────────────
    if ($action === 'delete_note') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM health_nursing_notes WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Note deleted.');
        redirect('nursing.php?tab=notes');
    }

    // ── Save / update MAR order ───────────────────────────────────
    if ($action === 'save_mar') {
        $id         = (int)($_POST['id']           ?? 0);
        $patientId  = (int)($_POST['patient_id']   ?? 0);
        $admId      = (int)($_POST['admission_id'] ?? 0);
        $medId      = (int)($_POST['medicine_id']  ?? 0);
        $medName    = sanitize($_POST['medicine_name'] ?? '');
        $dose       = sanitize($_POST['dose']      ?? '');
        $route      = in_array($_POST['route'] ?? '', ['oral','iv','im','sc','topical','inhaled','other']) ? $_POST['route'] : 'oral';
        $frequency  = sanitize($_POST['frequency'] ?? '');
        $startDate  = $_POST['start_date']         ?? date('Y-m-d');
        $endDate    = $_POST['end_date']            ?? null ?: null;
        $orderedBy  = (int)($_POST['ordered_by']   ?? 0) ?: null;
        $notes      = sanitize($_POST['notes']     ?? '');

        if (!$patientId || !$admId || !$dose) { setFlash('error', 'Patient, admission and dose are required.'); redirect('nursing.php?tab=mar'); }

        if ($id) {
            $pdo->prepare("UPDATE health_mar SET patient_id=?,admission_id=?,medicine_id=?,medicine_name=?,dose=?,route=?,frequency=?,start_date=?,end_date=?,notes=?,ordered_by=? WHERE id=? AND org_id=?")
                ->execute([$patientId,$admId,$medId,$medName,$dose,$route,$frequency,$startDate,$endDate,$notes,$orderedBy,$id,$orgId]);
            setFlash('success', 'Medication order updated.');
        } else {
            $pdo->prepare("INSERT INTO health_mar (org_id,patient_id,admission_id,medicine_id,medicine_name,dose,route,frequency,start_date,end_date,status,ordered_by,notes) VALUES (?,?,?,?,?,?,?,?,?,?,'active',?,?)")
                ->execute([$orgId,$patientId,$admId,$medId,$medName,$dose,$route,$frequency,$startDate,$endDate,$orderedBy,$notes]);
            setFlash('success', 'Medication order added.');
        }
        redirect('nursing.php?tab=mar');
    }

    // ── MAR administer / discontinue ─────────────────────────────
    if ($action === 'mar_administer') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE health_mar SET administered_by=?,administered_at=NOW() WHERE id=? AND org_id=?")
            ->execute([$uid,$id,$orgId]);
        setFlash('success', 'Medication marked as administered.');
        redirect('nursing.php?tab=mar');
    }

    if ($action === 'mar_discontinue') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE health_mar SET status='discontinued' WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Medication discontinued.');
        redirect('nursing.php?tab=mar');
    }
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['notes','mar']) ? $_GET['tab'] : 'notes';

// ── Patients ──────────────────────────────────────────────────────
$patientsSt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, patient_no FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
$patientsSt->execute([$orgId]);
$patients = $patientsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Active admissions for MAR ─────────────────────────────────────
$admSt = $pdo->prepare("
    SELECT a.id, a.admission_no, CONCAT(p.first_name,' ',p.last_name) AS patient_name, a.patient_id
    FROM health_admissions a
    LEFT JOIN health_patients p ON p.id=a.patient_id
    WHERE a.org_id=? AND a.status='admitted'
    ORDER BY p.first_name
");
$admSt->execute([$orgId]);
$activeAdmissions = $admSt->fetchAll(PDO::FETCH_ASSOC);

// ── Medicines for MAR ─────────────────────────────────────────────
$medsSt = $pdo->prepare("SELECT id, name, form, strength FROM health_medicines WHERE org_id=? AND status='active' ORDER BY name");
$medsSt->execute([$orgId]);
$medicines = $medsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Nursing notes ─────────────────────────────────────────────────
$filterPid      = (int)($_GET['patient_id'] ?? 0);
$filterNoteType = sanitize($_GET['note_type'] ?? '');
$filterDate     = sanitize($_GET['date']      ?? '');
$filterShift    = sanitize($_GET['shift']     ?? '');

$noteWhere  = "n.org_id=?";
$noteParams = [$orgId];
if ($filterPid)      { $noteWhere .= " AND n.patient_id=?";  $noteParams[] = $filterPid; }
if ($filterNoteType) { $noteWhere .= " AND n.note_type=?";   $noteParams[] = $filterNoteType; }
if ($filterDate)     { $noteWhere .= " AND DATE(n.created_at)=?"; $noteParams[] = $filterDate; }
if ($filterShift)    { $noteWhere .= " AND n.shift=?";        $noteParams[] = $filterShift; }

$notesSt = $pdo->prepare("
    SELECT n.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no,
           u.name AS nurse_name, a.admission_no
    FROM health_nursing_notes n
    LEFT JOIN health_patients p ON p.id=n.patient_id
    LEFT JOIN users u ON u.id=n.nurse_id
    LEFT JOIN health_admissions a ON a.id=n.admission_id
    WHERE {$noteWhere}
    ORDER BY n.created_at DESC LIMIT 200
");
$notesSt->execute($noteParams);
$notes = $notesSt->fetchAll(PDO::FETCH_ASSOC);

// ── MAR ───────────────────────────────────────────────────────────
$marAdmId = (int)($_GET['mar_admission'] ?? 0);
$marWhere  = "m.org_id=?";
$marParams = [$orgId];
if ($marAdmId) { $marWhere .= " AND m.admission_id=?"; $marParams[] = $marAdmId; }

$marSt = $pdo->prepare("
    SELECT m.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
           a.admission_no,
           adm_by.name AS administered_by_name,
           ord_by.name AS ordered_by_name
    FROM health_mar m
    LEFT JOIN health_patients p ON p.id=m.patient_id
    LEFT JOIN health_admissions a ON a.id=m.admission_id
    LEFT JOIN users adm_by ON adm_by.id=m.administered_by
    LEFT JOIN users ord_by ON ord_by.id=m.ordered_by
    WHERE {$marWhere}
    ORDER BY m.status='active' DESC, m.start_date DESC
    LIMIT 200
");
$marSt->execute($marParams);
$marRecords = $marSt->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────
$todayNotesSt = $pdo->prepare("SELECT COUNT(*) FROM health_nursing_notes WHERE org_id=? AND DATE(created_at)=CURDATE()");
$todayNotesSt->execute([$orgId]);
$todayNotes = (int)$todayNotesSt->fetchColumn();

$activeMarSt = $pdo->prepare("SELECT COUNT(*) FROM health_mar WHERE org_id=? AND status='active'");
$activeMarSt->execute([$orgId]);
$activeMar = (int)$activeMarSt->fetchColumn();

$pendingMarSt = $pdo->prepare("SELECT COUNT(*) FROM health_mar WHERE org_id=? AND status='active' AND (administered_at IS NULL OR DATE(administered_at) < CURDATE())");
$pendingMarSt->execute([$orgId]);
$pendingMar = (int)$pendingMarSt->fetchColumn();

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="container-fluid py-4">

  <?php flash(); ?>

  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h4 class="mb-0 fw-bold"><i class="fas fa-user-nurse me-2 text-danger"></i>Nursing</h4>
      <small class="text-muted">Nursing notes & medication administration records</small>
    </div>
    <div class="d-flex gap-2">
      <?php if ($tab === 'notes'): ?>
        <button class="btn btn-danger btn-sm" onclick="openNoteModal()" data-bs-toggle="modal" data-bs-target="#noteModal">
          <i class="fas fa-plus me-1"></i>Add Note
        </button>
      <?php elseif ($tab === 'mar'): ?>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#marModal">
          <i class="fas fa-plus me-1"></i>Add Medication Order
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stats -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-primary fs-3 fw-bold"><?= $todayNotes ?></div>
        <small class="text-muted">Notes Today</small>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card border-0 shadow-sm text-center py-3">
        <div class="text-success fs-3 fw-bold"><?= $activeMar ?></div>
        <small class="text-muted">Active Medications</small>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card border-0 shadow-sm text-center py-3 <?= $pendingMar > 0 ? 'border-warning' : '' ?>">
        <div class="text-warning fs-3 fw-bold"><?= $pendingMar ?></div>
        <small class="text-muted">Pending Administration</small>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab==='notes'?'active':'' ?>" href="?tab=notes"><i class="fas fa-clipboard me-1"></i>Nursing Notes</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='mar'  ?'active':'' ?>" href="?tab=mar"><i class="fas fa-pills me-1"></i>MAR <span class="badge bg-warning text-dark ms-1"><?= $pendingMar ?></span></a></li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: NURSING NOTES
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'notes'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="notes">
        <div class="col-12 col-md-3">
          <select name="patient_id" class="form-select form-select-sm select2" onchange="this.form.submit()">
            <option value="">All Patients</option>
            <?php foreach ($patients as $p): ?>
              <option value="<?= $p['id'] ?>" <?= $filterPid==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <select name="note_type" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Types</option>
            <option value="general"        <?= $filterNoteType==='general'        ?'selected':'' ?>>General</option>
            <option value="shift_handover" <?= $filterNoteType==='shift_handover' ?'selected':'' ?>>Shift Handover</option>
            <option value="care_plan"      <?= $filterNoteType==='care_plan'      ?'selected':'' ?>>Care Plan</option>
            <option value="observation"    <?= $filterNoteType==='observation'    ?'selected':'' ?>>Observation</option>
            <option value="incident"       <?= $filterNoteType==='incident'       ?'selected':'' ?>>Incident</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <select name="shift" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Shifts</option>
            <option value="morning"   <?= $filterShift==='morning'   ?'selected':'' ?>>Morning</option>
            <option value="afternoon" <?= $filterShift==='afternoon' ?'selected':'' ?>>Afternoon</option>
            <option value="night"     <?= $filterShift==='night'     ?'selected':'' ?>>Night</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDate) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-auto">
          <a href="?tab=notes" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="notesTable">
          <thead class="table-light">
            <tr><th>Patient</th><th>Type</th><th>Shift</th><th>Admission</th><th>Note</th><th>By</th><th>Date</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (empty($notes)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No nursing notes found.</td></tr>
          <?php else: foreach ($notes as $n):
            $ntBadge = match($n['note_type']) {
                'shift_handover' => 'info text-dark',
                'care_plan'      => 'primary',
                'observation'    => 'secondary',
                'incident'       => 'danger',
                default          => 'light text-dark border'
            };
            $shiftIcon = match($n['shift']) {
                'morning'   => '<i class="fas fa-sun text-warning"></i>',
                'afternoon' => '<i class="fas fa-cloud-sun text-warning"></i>',
                'night'     => '<i class="fas fa-moon text-primary"></i>',
                default     => '—'
            };
          ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($n['patient_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($n['patient_no']) ?></small>
              </td>
              <td><span class="badge bg-<?= $ntBadge ?>"><?= ucwords(str_replace('_',' ',$n['note_type'])) ?></span></td>
              <td><?= $shiftIcon ?></td>
              <td><small><?= $n['admission_no'] ? htmlspecialchars($n['admission_no']) : '—' ?></small></td>
              <td style="max-width:280px"><small><?= htmlspecialchars(mb_substr($n['note_text'],0,120)) ?><?= mb_strlen($n['note_text'])>120?'…':'' ?></small></td>
              <td><small><?= htmlspecialchars($n['nurse_name'] ?: '—') ?></small></td>
              <td><small><?= date('d M Y H:i', strtotime($n['created_at'])) ?></small></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary btn-sm" onclick="openNoteModal(<?= $n['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                  <button class="btn btn-outline-secondary btn-sm" onclick="viewNote('<?= htmlspecialchars(addslashes($n['note_text'])) ?>', '<?= htmlspecialchars(addslashes($n['patient_name'])) ?>')" title="View Full"><i class="fas fa-eye"></i></button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Delete this note?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_note">
                    <input type="hidden" name="id" value="<?= $n['id'] ?>">
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

  <!-- ══════════════════════════════════════════════════════════════
       TAB: MAR
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'mar'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="mar">
        <div class="col-12 col-md-5">
          <select name="mar_admission" class="form-select form-select-sm select2" onchange="this.form.submit()">
            <option value="">All Admissions</option>
            <?php foreach ($activeAdmissions as $a): ?>
              <option value="<?= $a['id'] ?>" <?= $marAdmId==$a['id']?'selected':'' ?>><?= htmlspecialchars($a['admission_no']) ?> — <?= htmlspecialchars($a['patient_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <a href="?tab=mar" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="marTable">
          <thead class="table-light">
            <tr><th>Patient</th><th>Admission</th><th>Medicine</th><th>Dose/Route</th><th>Frequency</th><th>Dates</th><th>Status</th><th>Last Admin</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (empty($marRecords)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No medication orders found.</td></tr>
          <?php else: foreach ($marRecords as $m):
            $stBadge = match($m['status']) {
                'active'       => 'success',
                'completed'    => 'primary',
                'discontinued' => 'secondary',
                default        => 'light text-dark'
            };
            $routeLabel = ['oral'=>'PO','iv'=>'IV','im'=>'IM','sc'=>'SC','topical'=>'Top','inhaled'=>'INH','other'=>'Other'];
          ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($m['patient_name']) ?></div>
              </td>
              <td><small><?= htmlspecialchars($m['admission_no']) ?></small></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($m['medicine_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($m['dose']) ?></small>
              </td>
              <td><span class="badge bg-light text-dark border"><?= $routeLabel[$m['route']] ?? $m['route'] ?></span></td>
              <td><small><?= htmlspecialchars($m['frequency'] ?: '—') ?></small></td>
              <td>
                <small><?= date('d M', strtotime($m['start_date'])) ?></small>
                <?php if ($m['end_date']): ?><small class="text-muted"> → <?= date('d M', strtotime($m['end_date'])) ?></small><?php endif; ?>
              </td>
              <td><span class="badge bg-<?= $stBadge ?>"><?= ucfirst($m['status']) ?></span></td>
              <td>
                <?php if ($m['administered_at']): ?>
                  <small><?= date('d M H:i', strtotime($m['administered_at'])) ?></small>
                  <div><small class="text-muted"><?= htmlspecialchars($m['administered_by_name'] ?: '') ?></small></div>
                <?php else: ?><small class="text-muted">—</small><?php endif; ?>
              </td>
              <td>
                <div class="btn-group btn-group-sm">
                  <?php if ($m['status'] === 'active'): ?>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="mar_administer">
                      <input type="hidden" name="id" value="<?= $m['id'] ?>">
                      <button type="submit" class="btn btn-outline-success btn-sm" title="Mark Administered"><i class="fas fa-check-double"></i></button>
                    </form>
                    <button class="btn btn-outline-primary btn-sm" onclick="openMarModal(<?= $m['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Discontinue this medication?')">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="mar_discontinue">
                      <input type="hidden" name="id" value="<?= $m['id'] ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm" title="Discontinue"><i class="fas fa-ban"></i></button>
                    </form>
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

</div><!-- /container -->

<!-- ── Modal: Nursing Note ───────────────────────────────────────── -->
<div class="modal fade" id="noteModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_note">
      <input type="hidden" name="id" id="noteId">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-clipboard me-2"></i><span id="noteModalTitle">Add Nursing Note</span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
              <select name="patient_id" id="notePatient" class="form-select select2" required>
                <option value="">Select Patient</option>
                <?php foreach ($patients as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Admission (if IPD)</label>
              <select name="admission_id" id="noteAdmission" class="form-select select2">
                <option value="">OPD / No Admission</option>
                <?php foreach ($activeAdmissions as $a): ?>
                  <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['admission_no']) ?> — <?= htmlspecialchars($a['patient_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold">Note Type</label>
              <select name="note_type" id="noteType" class="form-select">
                <option value="general">General</option>
                <option value="shift_handover">Shift Handover</option>
                <option value="care_plan">Care Plan</option>
                <option value="observation">Observation</option>
                <option value="incident">Incident</option>
              </select>
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold">Shift</label>
              <select name="shift" id="noteShift" class="form-select">
                <option value="">—</option>
                <option value="morning">Morning</option>
                <option value="afternoon">Afternoon</option>
                <option value="night">Night</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Note <span class="text-danger">*</span></label>
              <textarea name="note_text" id="noteText" class="form-control" rows="5" required placeholder="Enter nursing note…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save Note</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: View Full Note ─────────────────────────────────────── -->
<div class="modal fade" id="viewNoteModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="viewNoteTitle">Nursing Note</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><pre id="viewNoteText" style="white-space:pre-wrap;font-family:inherit"></pre></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- ── Modal: MAR Order ───────────────────────────────────────────── -->
<div class="modal fade" id="marModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_mar">
      <input type="hidden" name="id" id="marId">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-pills me-2"></i><span id="marModalTitle">Add Medication Order</span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Admission <span class="text-danger">*</span></label>
              <select name="admission_id" id="marAdmId" class="form-select select2" required onchange="fillPatientFromAdm(this)">
                <option value="">Select Admission</option>
                <?php foreach ($activeAdmissions as $a): ?>
                  <option value="<?= $a['id'] ?>" data-pid="<?= $a['patient_id'] ?>"><?= htmlspecialchars($a['admission_no']) ?> — <?= htmlspecialchars($a['patient_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="patient_id" id="marPatientId">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Medicine</label>
              <select name="medicine_id" id="marMedId" class="form-select select2" onchange="fillMedName(this)">
                <option value="">Select or type below</option>
                <?php foreach ($medicines as $m): ?>
                  <option value="<?= $m['id'] ?>" data-name="<?= htmlspecialchars($m['name'].' '.($m['form']??'').' '.($m['strength']??'')) ?>"><?= htmlspecialchars($m['name']) ?> <?= htmlspecialchars($m['form']??'') ?> <?= htmlspecialchars($m['strength']??'') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Medicine Name (snapshot) <span class="text-danger">*</span></label>
              <input type="text" name="medicine_name" id="marMedName" class="form-control" required placeholder="e.g. Amoxicillin 500mg">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Dose <span class="text-danger">*</span></label>
              <input type="text" name="dose" id="marDose" class="form-control" required placeholder="e.g. 500mg">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Route</label>
              <select name="route" id="marRoute" class="form-select">
                <option value="oral">Oral (PO)</option>
                <option value="iv">IV</option>
                <option value="im">IM</option>
                <option value="sc">SC</option>
                <option value="topical">Topical</option>
                <option value="inhaled">Inhaled</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Frequency</label>
              <input type="text" name="frequency" id="marFreq" class="form-control" list="freqList" placeholder="e.g. BD, TDS">
              <datalist id="freqList">
                <option>OD (once daily)</option><option>BD (twice daily)</option>
                <option>TDS (three times daily)</option><option>QID (four times daily)</option>
                <option>PRN (as needed)</option><option>STAT (once now)</option>
                <option>nocte (at night)</option><option>hourly</option>
              </datalist>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">Start Date</label>
              <input type="date" name="start_date" id="marStart" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label fw-semibold">End Date</label>
              <input type="date" name="end_date" id="marEnd" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes / Instructions</label>
              <textarea name="notes" id="marNotes" class="form-control" rows="2" placeholder="Special instructions…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save Order</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function openNoteModal(id) {
    document.getElementById('noteId').value      = '';
    document.getElementById('notePatient').value = '';
    document.getElementById('noteType').value    = 'general';
    document.getElementById('noteShift').value   = '';
    document.getElementById('noteText').value    = '';
    document.getElementById('noteModalTitle').textContent = 'Add Nursing Note';
    if (id) {
        document.getElementById('noteModalTitle').textContent = 'Edit Note';
        fetch('nursing.php?fetch_note=' + id)
            .then(r => r.json())
            .then(d => {
                if (!d.id) return;
                document.getElementById('noteId').value      = d.id;
                document.getElementById('notePatient').value = d.patient_id   || '';
                document.getElementById('noteType').value    = d.note_type    || 'general';
                document.getElementById('noteShift').value   = d.shift        || '';
                document.getElementById('noteText').value    = d.note_text    || '';
                if (d.admission_id) document.getElementById('noteAdmission').value = d.admission_id;
            });
    }
    new bootstrap.Modal(document.getElementById('noteModal')).show();
}

function viewNote(text, patient) {
    document.getElementById('viewNoteTitle').textContent = 'Note — ' + patient;
    document.getElementById('viewNoteText').textContent  = text;
    new bootstrap.Modal(document.getElementById('viewNoteModal')).show();
}

function fillPatientFromAdm(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('marPatientId').value = opt.dataset.pid || '';
}

function fillMedName(sel) {
    const opt = sel.options[sel.selectedIndex];
    const nameField = document.getElementById('marMedName');
    if (opt.dataset.name) nameField.value = opt.dataset.name.trim();
}

function openMarModal(id) {
    document.getElementById('marId').value      = '';
    document.getElementById('marModalTitle').textContent = 'Edit Medication Order';
    fetch('nursing.php?fetch_mar=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.id) return;
            document.getElementById('marId').value        = d.id;
            document.getElementById('marAdmId').value     = d.admission_id    || '';
            document.getElementById('marPatientId').value = d.patient_id      || '';
            document.getElementById('marMedId').value     = d.medicine_id     || '';
            document.getElementById('marMedName').value   = d.medicine_name   || '';
            document.getElementById('marDose').value      = d.dose            || '';
            document.getElementById('marRoute').value     = d.route           || 'oral';
            document.getElementById('marFreq').value      = d.frequency       || '';
            document.getElementById('marStart').value     = d.start_date      || '';
            document.getElementById('marEnd').value       = d.end_date        || '';
            document.getElementById('marNotes').value     = d.notes           || '';
        });
    new bootstrap.Modal(document.getElementById('marModal')).show();
}

$(document).ready(function () {
    if ($('#notesTable').length) $('#notesTable').DataTable({ pageLength: 25, order: [[6,'desc']], columnDefs:[{orderable:false,targets:[7]}] });
    if ($('#marTable').length)   $('#marTable').DataTable({ pageLength: 25, order: [[0,'asc']], columnDefs:[{orderable:false,targets:[8]}] });
    if (typeof $.fn.select2 !== 'undefined') {
        $('.select2').select2({ theme:'bootstrap-5', dropdownParent: document.body, width:'100%' });
    }
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
