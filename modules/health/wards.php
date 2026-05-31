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
    ['url'=>'surgery.php',       'icon'=>'fas fa-syringe',             'label'=>'Surgery / Theatre'],
    ['url'=>'emergency.php',     'icon'=>'fas fa-ambulance',           'label'=>'Emergency / Triage'],
    ['url'=>'billing.php',       'icon'=>'fas fa-file-invoice-dollar', 'label'=>'Billing'],
    ['url'=>'telemedicine.php',  'icon'=>'fas fa-video',               'label'=>'Telemedicine'],
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-users',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'AI Analytics'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
];

// ── AJAX: fetch ward ──────────────────────────────────────────────
if (isset($_GET['fetch_ward'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_ward'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_wards WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── AJAX: fetch bed ───────────────────────────────────────────────
if (isset($_GET['fetch_bed'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_bed'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT * FROM health_beds WHERE id=? AND org_id=?");
        $st->execute([$id, $orgId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: (object)[]);
    } catch (Exception $e) { echo '{}'; }
    exit;
}

// ── AJAX: beds list for a ward ────────────────────────────────────
if (isset($_GET['ward_beds'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId  = (int)currentUser()['org_id'];
    $wardId = (int)$_GET['ward_beds'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("
            SELECT b.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no
            FROM health_beds b
            LEFT JOIN health_admissions a ON a.bed_id=b.id AND a.status='admitted'
            LEFT JOIN health_patients p ON p.id=a.patient_id
            WHERE b.org_id=? AND b.ward_id=?
            ORDER BY b.bed_no
        ");
        $st->execute([$orgId, $wardId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo '[]'; }
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
    $action = $_POST['action'] ?? '';

    // ── Save ward ─────────────────────────────────────────────────
    if ($action === 'save_ward') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = sanitize($_POST['name']      ?? '');
        $type     = in_array($_POST['ward_type'] ?? '', ['general','private','icu','maternity','paediatric','surgical','emergency','other']) ? $_POST['ward_type'] : 'general';
        $floor    = sanitize($_POST['floor']     ?? '');
        $capacity = (int)($_POST['capacity']     ?? 0);
        $status   = in_array($_POST['status']    ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        $notes    = sanitize($_POST['notes']     ?? '');

        if (!$name) { setFlash('error', 'Ward name is required.'); redirect('wards.php?tab=wards'); }

        if ($id) {
            $pdo->prepare("UPDATE health_wards SET name=?,ward_type=?,floor=?,capacity=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$name,$type,$floor,$capacity,$status,$notes,$id,$orgId]);
            setFlash('success', 'Ward updated.');
        } else {
            $pdo->prepare("INSERT INTO health_wards (org_id,name,ward_type,floor,capacity,status,notes) VALUES (?,?,?,?,?,?,?)")
                ->execute([$orgId,$name,$type,$floor,$capacity,$status,$notes]);
            setFlash('success', 'Ward added.');
        }
        redirect('wards.php?tab=wards');
    }

    // ── Delete ward ───────────────────────────────────────────────
    if ($action === 'delete_ward') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM health_wards WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Ward deleted.');
        redirect('wards.php?tab=wards');
    }

    // ── Save bed ──────────────────────────────────────────────────
    if ($action === 'save_bed') {
        $id     = (int)($_POST['id']      ?? 0);
        $wardId = (int)($_POST['ward_id'] ?? 0);
        $bedNo  = sanitize($_POST['bed_no']   ?? '');
        $type   = in_array($_POST['bed_type'] ?? '', ['standard','icu','isolation','maternity','cot','other']) ? $_POST['bed_type'] : 'standard';
        $status = in_array($_POST['status']   ?? '', ['available','occupied','maintenance','cleaning']) ? $_POST['status'] : 'available';
        $notes  = sanitize($_POST['notes']    ?? '');

        if (!$wardId || !$bedNo) { setFlash('error', 'Ward and bed number are required.'); redirect('wards.php?tab=beds'); }

        if ($id) {
            $pdo->prepare("UPDATE health_beds SET ward_id=?,bed_no=?,bed_type=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$wardId,$bedNo,$type,$status,$notes,$id,$orgId]);
            setFlash('success', 'Bed updated.');
        } else {
            $pdo->prepare("INSERT INTO health_beds (org_id,ward_id,bed_no,bed_type,status,notes) VALUES (?,?,?,?,?,?)")
                ->execute([$orgId,$wardId,$bedNo,$type,$status,$notes]);
            setFlash('success', 'Bed added.');
        }
        redirect('wards.php?tab=beds');
    }

    // ── Delete bed ────────────────────────────────────────────────
    if ($action === 'delete_bed') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM health_beds WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Bed deleted.');
        redirect('wards.php?tab=beds');
    }

    // ── Update bed status ─────────────────────────────────────────
    if ($action === 'bed_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['available','occupied','maintenance','cleaning']) ? $_POST['status'] : '';
        if ($id && $status) {
            $pdo->prepare("UPDATE health_beds SET status=? WHERE id=? AND org_id=?")->execute([$status,$id,$orgId]);
            setFlash('success', 'Bed status updated.');
        }
        redirect('wards.php?tab=beds');
    }
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['wards','beds','map']) ? $_GET['tab'] : 'map';

// ── Wards ─────────────────────────────────────────────────────────
$wardsSt = $pdo->prepare("SELECT * FROM health_wards WHERE org_id=? ORDER BY name");
$wardsSt->execute([$orgId]);
$wards = $wardsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Beds with occupancy ───────────────────────────────────────────
$filterWardId = (int)($_GET['ward_id'] ?? 0);
$filterStatus = $_GET['bed_status']    ?? '';

$bedWhere  = "b.org_id=?";
$bedParams = [$orgId];
if ($filterWardId) { $bedWhere .= " AND b.ward_id=?"; $bedParams[] = $filterWardId; }
if ($filterStatus) { $bedWhere .= " AND b.status=?";  $bedParams[] = $filterStatus; }

$bedsSt = $pdo->prepare("
    SELECT b.*, w.name AS ward_name,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name,
           p.patient_no, a.admission_no, a.admitted_at
    FROM health_beds b
    LEFT JOIN health_wards w ON w.id=b.ward_id
    LEFT JOIN health_admissions a ON a.bed_id=b.id AND a.status='admitted'
    LEFT JOIN health_patients p ON p.id=a.patient_id
    WHERE {$bedWhere}
    ORDER BY w.name, b.bed_no
");
$bedsSt->execute($bedParams);
$beds = $bedsSt->fetchAll(PDO::FETCH_ASSOC);

// ── Summary stats ─────────────────────────────────────────────────
$totalBedsSt = $pdo->prepare("SELECT COUNT(*) FROM health_beds WHERE org_id=?");
$totalBedsSt->execute([$orgId]);
$totalBeds = (int)$totalBedsSt->fetchColumn();

$availSt = $pdo->prepare("SELECT COUNT(*) FROM health_beds WHERE org_id=? AND status='available'");
$availSt->execute([$orgId]);
$availBeds = (int)$availSt->fetchColumn();

$occSt = $pdo->prepare("SELECT COUNT(*) FROM health_beds WHERE org_id=? AND status='occupied'");
$occSt->execute([$orgId]);
$occBeds = (int)$occSt->fetchColumn();

$maintSt = $pdo->prepare("SELECT COUNT(*) FROM health_beds WHERE org_id=? AND status IN ('maintenance','cleaning')");
$maintSt->execute([$orgId]);
$maintBeds = (int)$maintSt->fetchColumn();

$occupancyRate = $totalBeds > 0 ? round(($occBeds / $totalBeds) * 100) : 0;

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="container-fluid py-4">

  <?php flash(); ?>

  <!-- ── Page Header ───────────────────────────────────────────── -->
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h4 class="mb-0 fw-bold"><i class="fas fa-bed me-2 text-danger"></i>Wards & Beds</h4>
      <small class="text-muted">Ward setup, bed management & occupancy map</small>
    </div>
    <div class="d-flex gap-2">
      <?php if ($tab === 'wards'): ?>
        <button class="btn btn-danger btn-sm" onclick="openWardModal()" data-bs-toggle="modal" data-bs-target="#wardModal">
          <i class="fas fa-plus me-1"></i>Add Ward
        </button>
      <?php elseif ($tab === 'beds'): ?>
        <button class="btn btn-danger btn-sm" onclick="openBedModal()" data-bs-toggle="modal" data-bs-target="#bedModal">
          <i class="fas fa-plus me-1"></i>Add Bed
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Summary Cards ─────────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-3">
          <div class="text-primary fs-3 fw-bold"><?= $totalBeds ?></div>
          <small class="text-muted">Total Beds</small>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-3">
          <div class="text-success fs-3 fw-bold"><?= $availBeds ?></div>
          <small class="text-muted">Available</small>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-3">
          <div class="text-danger fs-3 fw-bold"><?= $occBeds ?></div>
          <small class="text-muted">Occupied</small>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-3">
          <div class="fs-3 fw-bold" style="color:<?= $occupancyRate >= 90 ? '#dc3545' : ($occupancyRate >= 70 ? '#fd7e14' : '#198754') ?>"><?= $occupancyRate ?>%</div>
          <small class="text-muted">Occupancy Rate</small>
          <div class="progress mt-1" style="height:4px">
            <div class="progress-bar bg-<?= $occupancyRate >= 90 ? 'danger' : ($occupancyRate >= 70 ? 'warning' : 'success') ?>" style="width:<?= $occupancyRate ?>%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Tabs ──────────────────────────────────────────────────── -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab==='map'  ?'active':'' ?>" href="?tab=map"><i class="fas fa-th me-1"></i>Bed Map</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='beds' ?'active':'' ?>" href="?tab=beds"><i class="fas fa-bed me-1"></i>All Beds</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='wards'?'active':'' ?>" href="?tab=wards"><i class="fas fa-hospital me-1"></i>Wards</a></li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: BED MAP
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'map'): ?>
  <?php if (empty($wards)): ?>
    <div class="alert alert-info">
      No wards configured yet. <a href="?tab=wards">Add wards</a> to get started.
    </div>
  <?php else: foreach ($wards as $ward):
    // Load beds for this ward
    $wBedsSt = $pdo->prepare("
        SELECT b.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.patient_no, a.admitted_at
        FROM health_beds b
        LEFT JOIN health_admissions a ON a.bed_id=b.id AND a.status='admitted'
        LEFT JOIN health_patients p ON p.id=a.patient_id
        WHERE b.org_id=? AND b.ward_id=?
        ORDER BY b.bed_no
    ");
    $wBedsSt->execute([$orgId, $ward['id']]);
    $wBeds = $wBedsSt->fetchAll(PDO::FETCH_ASSOC);
    $wTotal = count($wBeds);
    $wAvail = count(array_filter($wBeds, fn($b) => $b['status'] === 'available'));
    $wOcc   = count(array_filter($wBeds, fn($b) => $b['status'] === 'occupied'));
    $wardTypeColors = ['icu'=>'danger','maternity'=>'pink','paediatric'=>'info','surgical'=>'warning','emergency'=>'dark','private'=>'secondary','general'=>'primary','other'=>'light'];
    $typeColor = $wardTypeColors[$ward['ward_type']] ?? 'primary';
  ?>
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
      <div>
        <span class="badge bg-<?= $typeColor ?> me-2"><?= ucfirst($ward['ward_type']) ?></span>
        <strong><?= htmlspecialchars($ward['name']) ?></strong>
        <?php if ($ward['floor']): ?><small class="text-muted ms-2">Floor <?= htmlspecialchars($ward['floor']) ?></small><?php endif; ?>
      </div>
      <small class="text-muted"><?= $wAvail ?> available / <?= $wOcc ?> occupied / <?= $wTotal ?> total</small>
    </div>
    <div class="card-body">
      <?php if (empty($wBeds)): ?>
        <p class="text-muted text-center py-2">No beds in this ward. <a href="?tab=beds">Add beds.</a></p>
      <?php else: ?>
      <div class="row g-2">
        <?php foreach ($wBeds as $b):
          $bedColor = match($b['status']) {
              'available'   => 'success',
              'occupied'    => 'danger',
              'maintenance' => 'warning',
              'cleaning'    => 'info',
              default       => 'secondary'
          };
          $bedIcon = match($b['status']) {
              'occupied'    => 'fas fa-user',
              'maintenance' => 'fas fa-tools',
              'cleaning'    => 'fas fa-broom',
              default       => 'fas fa-bed'
          };
        ?>
        <div class="col-6 col-sm-4 col-md-3 col-xl-2">
          <div class="card border-<?= $bedColor ?> text-center" style="cursor:pointer" onclick="showBedDetail(this)"
               data-id="<?= $b['id'] ?>"
               data-bed="<?= htmlspecialchars($b['bed_no']) ?>"
               data-status="<?= $b['status'] ?>"
               data-type="<?= $b['bed_type'] ?>"
               data-patient="<?= htmlspecialchars($b['patient_name'] ?: '') ?>"
               data-patient-no="<?= htmlspecialchars($b['patient_no'] ?: '') ?>"
               data-admitted="<?= $b['admitted_at'] ? date('d M Y', strtotime($b['admitted_at'])) : '' ?>">
            <div class="card-body py-2 px-1">
              <div class="text-<?= $bedColor ?> fs-4"><i class="<?= $bedIcon ?>"></i></div>
              <div class="fw-bold small"><?= htmlspecialchars($b['bed_no']) ?></div>
              <?php if ($b['patient_name']): ?>
                <div class="text-truncate" style="font-size:.7rem"><?= htmlspecialchars($b['patient_name']) ?></div>
              <?php else: ?>
                <div class="text-muted" style="font-size:.7rem"><?= ucfirst($b['status']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <!-- Map Legend -->
  <div class="d-flex gap-3 flex-wrap mt-2">
    <span><i class="fas fa-square text-success"></i> Available</span>
    <span><i class="fas fa-square text-danger"></i> Occupied</span>
    <span><i class="fas fa-square text-warning"></i> Maintenance</span>
    <span><i class="fas fa-square text-info"></i> Cleaning</span>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════
       TAB: ALL BEDS
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'beds'): ?>
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <form class="row g-2 mb-3" method="GET">
        <input type="hidden" name="tab" value="beds">
        <div class="col-6 col-md-3">
          <select name="ward_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Wards</option>
            <?php foreach ($wards as $w): ?>
              <option value="<?= $w['id'] ?>" <?= $filterWardId==$w['id']?'selected':'' ?>><?= htmlspecialchars($w['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <select name="bed_status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Status</option>
            <option value="available"   <?= $filterStatus==='available'   ?'selected':'' ?>>Available</option>
            <option value="occupied"    <?= $filterStatus==='occupied'    ?'selected':'' ?>>Occupied</option>
            <option value="maintenance" <?= $filterStatus==='maintenance' ?'selected':'' ?>>Maintenance</option>
            <option value="cleaning"    <?= $filterStatus==='cleaning'    ?'selected':'' ?>>Cleaning</option>
          </select>
        </div>
        <div class="col-auto">
          <a href="?tab=beds" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
      </form>
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle" id="bedsTable">
          <thead class="table-light">
            <tr><th>Bed No</th><th>Ward</th><th>Type</th><th>Status</th><th>Patient</th><th>Admitted</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (empty($beds)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No beds found.</td></tr>
          <?php else: foreach ($beds as $b):
            $statusBadge = match($b['status']) {
                'available'   => 'success',
                'occupied'    => 'danger',
                'maintenance' => 'warning text-dark',
                'cleaning'    => 'info text-dark',
                default       => 'secondary'
            };
          ?>
            <tr>
              <td class="fw-bold"><?= htmlspecialchars($b['bed_no']) ?></td>
              <td><?= htmlspecialchars($b['ward_name']) ?></td>
              <td><?= ucfirst($b['bed_type']) ?></td>
              <td><span class="badge bg-<?= $statusBadge ?>"><?= ucfirst($b['status']) ?></span></td>
              <td>
                <?php if ($b['patient_name']): ?>
                  <div class="fw-semibold"><?= htmlspecialchars($b['patient_name']) ?></div>
                  <small class="text-muted"><?= htmlspecialchars($b['patient_no']) ?></small>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td><small><?= $b['admitted_at'] ? date('d M Y', strtotime($b['admitted_at'])) : '—' ?></small></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary btn-sm" onclick="openBedModal(<?= $b['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                  <?php if ($b['status'] !== 'available'): ?>
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="bed_status">
                      <input type="hidden" name="id" value="<?= $b['id'] ?>">
                      <input type="hidden" name="status" value="available">
                      <button type="submit" class="btn btn-outline-success btn-sm" title="Mark Available"><i class="fas fa-check"></i></button>
                    </form>
                  <?php endif; ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Delete this bed?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_bed">
                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
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
       TAB: WARDS
  ════════════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'wards'): ?>
  <div class="row g-3">
    <?php if (empty($wards)): ?>
      <div class="col-12"><div class="alert alert-info">No wards yet. Add your first ward.</div></div>
    <?php else: foreach ($wards as $w):
      $wBedCntSt = $pdo->prepare("SELECT COUNT(*) as total, SUM(status='occupied') as occ FROM health_beds WHERE org_id=? AND ward_id=?");
      $wBedCntSt->execute([$orgId, $w['id']]);
      $wCnt = $wBedCntSt->fetch(PDO::FETCH_ASSOC);
    ?>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body">
            <div class="d-flex align-items-start justify-content-between mb-2">
              <div>
                <h6 class="mb-0 fw-bold"><?= htmlspecialchars($w['name']) ?></h6>
                <small class="text-muted"><?= ucfirst($w['ward_type']) ?><?= $w['floor'] ? ' — Floor '.$w['floor'] : '' ?></small>
              </div>
              <span class="badge bg-<?= $w['status']==='active'?'success':'secondary' ?>"><?= ucfirst($w['status']) ?></span>
            </div>
            <div class="row g-1 text-center mt-2">
              <div class="col-4"><div class="bg-light rounded p-2"><div class="fw-bold"><?= $w['capacity'] ?></div><small class="text-muted">Capacity</small></div></div>
              <div class="col-4"><div class="bg-light rounded p-2"><div class="fw-bold text-success"><?= ($wCnt['total']-($wCnt['occ']??0)) ?></div><small class="text-muted">Avail</small></div></div>
              <div class="col-4"><div class="bg-light rounded p-2"><div class="fw-bold text-danger"><?= (int)($wCnt['occ']??0) ?></div><small class="text-muted">Occupied</small></div></div>
            </div>
          </div>
          <div class="card-footer bg-transparent d-flex gap-2">
            <button class="btn btn-outline-primary btn-sm flex-fill" onclick="openWardModal(<?= $w['id'] ?>)" data-bs-toggle="modal" data-bs-target="#wardModal"><i class="fas fa-edit me-1"></i>Edit</button>
            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this ward?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_ward">
              <input type="hidden" name="id" value="<?= $w['id'] ?>">
              <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<!-- ── Bed Detail Popover (Map) ───────────────────────────────────── -->
<div class="modal fade" id="bedDetailModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold" id="bdTitle">Bed Detail</h6>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="bdBody"></div>
      <div class="modal-footer py-2">
        <form method="POST" id="bdStatusForm" class="w-100">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="bed_status">
          <input type="hidden" name="id" id="bdId">
          <select name="status" class="form-select form-select-sm mb-2">
            <option value="available">Available</option>
            <option value="maintenance">Maintenance</option>
            <option value="cleaning">Cleaning</option>
          </select>
          <button type="submit" class="btn btn-warning btn-sm w-100">Update Status</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Ward ────────────────────────────────────────────────── -->
<div class="modal fade" id="wardModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_ward">
      <input type="hidden" name="id" id="wardId">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-hospital me-2"></i><span id="wardModalTitle">Add Ward</span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Ward Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="wardName" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Type</label>
              <select name="ward_type" id="wardType" class="form-select">
                <option value="general">General</option>
                <option value="private">Private</option>
                <option value="icu">ICU</option>
                <option value="maternity">Maternity</option>
                <option value="paediatric">Paediatric</option>
                <option value="surgical">Surgical</option>
                <option value="emergency">Emergency</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Floor</label>
              <input type="text" name="floor" id="wardFloor" class="form-control" placeholder="e.g. Ground, 1st">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Capacity (beds)</label>
              <input type="number" name="capacity" id="wardCapacity" class="form-control" min="0" value="0">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="wardStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="wardNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save Ward</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Bed ─────────────────────────────────────────────────── -->
<div class="modal fade" id="bedModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_bed">
      <input type="hidden" name="id" id="bedId">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fas fa-bed me-2"></i><span id="bedModalTitle">Add Bed</span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Ward <span class="text-danger">*</span></label>
              <select name="ward_id" id="bedWardId" class="form-select" required>
                <option value="">Select Ward</option>
                <?php foreach ($wards as $w): ?>
                  <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Bed Number <span class="text-danger">*</span></label>
              <input type="text" name="bed_no" id="bedNo" class="form-control" required placeholder="e.g. B-01, ICU-3">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Bed Type</label>
              <select name="bed_type" id="bedType" class="form-select">
                <option value="standard">Standard</option>
                <option value="icu">ICU</option>
                <option value="isolation">Isolation</option>
                <option value="maternity">Maternity</option>
                <option value="cot">Cot</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="bedStatus" class="form-select">
                <option value="available">Available</option>
                <option value="occupied">Occupied</option>
                <option value="maintenance">Maintenance</option>
                <option value="cleaning">Cleaning</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="bedNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save Bed</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function openWardModal(id) {
    document.getElementById('wardId').value       = '';
    document.getElementById('wardName').value     = '';
    document.getElementById('wardType').value     = 'general';
    document.getElementById('wardFloor').value    = '';
    document.getElementById('wardCapacity').value = '0';
    document.getElementById('wardStatus').value   = 'active';
    document.getElementById('wardNotes').value    = '';
    document.getElementById('wardModalTitle').textContent = 'Add Ward';
    if (id) {
        document.getElementById('wardModalTitle').textContent = 'Edit Ward';
        fetch('wards.php?fetch_ward=' + id)
            .then(r => r.json())
            .then(d => {
                if (!d.id) return;
                document.getElementById('wardId').value       = d.id;
                document.getElementById('wardName').value     = d.name      || '';
                document.getElementById('wardType').value     = d.ward_type || 'general';
                document.getElementById('wardFloor').value    = d.floor     || '';
                document.getElementById('wardCapacity').value = d.capacity  || '0';
                document.getElementById('wardStatus').value   = d.status    || 'active';
                document.getElementById('wardNotes').value    = d.notes     || '';
            });
    }
}

function openBedModal(id) {
    document.getElementById('bedId').value     = '';
    document.getElementById('bedWardId').value = '';
    document.getElementById('bedNo').value     = '';
    document.getElementById('bedType').value   = 'standard';
    document.getElementById('bedStatus').value = 'available';
    document.getElementById('bedNotes').value  = '';
    document.getElementById('bedModalTitle').textContent = 'Add Bed';
    if (id) {
        document.getElementById('bedModalTitle').textContent = 'Edit Bed';
        fetch('wards.php?fetch_bed=' + id)
            .then(r => r.json())
            .then(d => {
                if (!d.id) return;
                document.getElementById('bedId').value     = d.id;
                document.getElementById('bedWardId').value = d.ward_id   || '';
                document.getElementById('bedNo').value     = d.bed_no    || '';
                document.getElementById('bedType').value   = d.bed_type  || 'standard';
                document.getElementById('bedStatus').value = d.status    || 'available';
                document.getElementById('bedNotes').value  = d.notes     || '';
            });
        new bootstrap.Modal(document.getElementById('bedModal')).show();
    }
}

function showBedDetail(el) {
    const id       = el.dataset.id;
    const bed      = el.dataset.bed;
    const status   = el.dataset.status;
    const type     = el.dataset.type;
    const patient  = el.dataset.patient;
    const pno      = el.dataset.patientNo;
    const admitted = el.dataset.admitted;

    document.getElementById('bdTitle').textContent = 'Bed ' + bed;
    document.getElementById('bdId').value = id;
    document.getElementById('bdBody').innerHTML = `
        <table class="table table-sm mb-0">
            <tr><td class="text-muted">Type</td><td>${type}</td></tr>
            <tr><td class="text-muted">Status</td><td><strong>${status}</strong></td></tr>
            ${patient ? `<tr><td class="text-muted">Patient</td><td><strong>${patient}</strong> (${pno})</td></tr>` : ''}
            ${admitted ? `<tr><td class="text-muted">Admitted</td><td>${admitted}</td></tr>` : ''}
        </table>`;

    new bootstrap.Modal(document.getElementById('bedDetailModal')).show();
}

$(document).ready(function () {
    if ($('#bedsTable').length) $('#bedsTable').DataTable({ pageLength: 50, order: [[1,'asc'],[0,'asc']], columnDefs:[{orderable:false,targets:[6]}] });
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
