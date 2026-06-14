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

// ── AJAX: fetch patient for edit ──────────────────────────────────
if (isset($_GET['fetch_details'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_details'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT p.*, u.email AS account_email, u.status AS account_status FROM health_patients p LEFT JOIN users u ON p.user_id=u.id WHERE p.id=? AND p.org_id=?");
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
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $uid    = (int)$user['id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id                = (int)($_POST['id']                  ?? 0);
        $patNo             = sanitize($_POST['patient_no']       ?? '');
        $firstName         = sanitize($_POST['first_name']       ?? '');
        $lastName          = sanitize($_POST['last_name']        ?? '');
        $gender            = in_array($_POST['gender'] ?? '', ['male','female','other']) ? $_POST['gender'] : 'male';
        $dob               = $_POST['dob']                       ?? date('Y-m-d', strtotime('-30 years'));
        $phone             = sanitize($_POST['phone']            ?? '');
        $email             = trim(strtolower($_POST['email']     ?? ''));
        $address           = sanitize($_POST['address']          ?? '');
        $bloodGroup        = sanitize($_POST['blood_group']      ?? '');
        $allergies         = sanitize($_POST['allergies']        ?? '');
        $chronicConditions = sanitize($_POST['chronic_conditions']?? '');
        $emergencyContact  = sanitize($_POST['emergency_contact']?? '');
        $emergencyPhone    = sanitize($_POST['emergency_phone']  ?? '');
        $insuranceProvider = sanitize($_POST['insurance_provider']?? '');
        $insuranceNo       = sanitize($_POST['insurance_no']     ?? '');
        $status            = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        $createAccount     = !empty($_POST['create_account']);

        if ($id > 0) {
            $pdo->prepare("UPDATE health_patients SET patient_no=?,first_name=?,last_name=?,gender=?,dob=?,phone=?,email=?,address=?,blood_group=?,allergies=?,chronic_conditions=?,emergency_contact=?,emergency_phone=?,insurance_provider=?,insurance_no=?,status=? WHERE id=? AND org_id=?")
                ->execute([$patNo,$firstName,$lastName,$gender,$dob,$phone,$email,$address,$bloodGroup,$allergies,$chronicConditions,$emergencyContact,$emergencyPhone,$insuranceProvider,$insuranceNo,$status,$id,$orgId]);

            // Sync linked user account
            $link = $pdo->prepare("SELECT user_id FROM health_patients WHERE id=? AND org_id=?");
            $link->execute([$id, $orgId]);
            $existingUserId = (int)($link->fetchColumn() ?? 0);
            if ($existingUserId) {
                $pdo->prepare("UPDATE users SET name=?,phone=?,email=? WHERE id=? AND org_id=?")
                    ->execute([$firstName.' '.$lastName, $phone, $email, $existingUserId, $orgId]);
            }

            // Grant portal account to existing patient without one
            if ($createAccount && !$existingUserId && $email) {
                $newUid = _patientCreateAccount($pdo, $orgId, $firstName, $lastName, $email, $phone);
                if (is_int($newUid) && $newUid > 0) {
                    $pdo->prepare("UPDATE health_patients SET user_id=? WHERE id=? AND org_id=?")->execute([$newUid, $id, $orgId]);
                }
            }

            setFlash('success', 'Patient details updated.');
            logActivity('update', 'health', "Patient updated: $firstName $lastName");
        } else {
            $linkedUserId = null;
            if ($createAccount && $email) {
                $result = _patientCreateAccount($pdo, $orgId, $firstName, $lastName, $email, $phone);
                if (is_string($result)) { setFlash('error', $result); redirect('patients.php'); }
                $linkedUserId = $result;
            }

            $pdo->prepare("INSERT INTO health_patients (org_id,user_id,patient_no,first_name,last_name,gender,dob,phone,email,address,blood_group,allergies,chronic_conditions,emergency_contact,emergency_phone,insurance_provider,insurance_no,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$linkedUserId,$patNo,$firstName,$lastName,$gender,$dob,$phone,$email,$address,$bloodGroup,$allergies,$chronicConditions,$emergencyContact,$emergencyPhone,$insuranceProvider,$insuranceNo,$status]);

            setFlash('success', "Patient '$firstName $lastName' enrolled." . ($linkedUserId ? ' Portal login created.' : ''));
            logActivity('create', 'health', "Patient enrolled: $firstName $lastName ($patNo)");
        }
        redirect('patients.php');
    }

    if ($action === 'reset_patient_password') {
        $patId = (int)($_POST['patient_id'] ?? 0);
        $row   = $pdo->prepare("SELECT p.user_id, p.first_name, p.last_name, p.email, p.phone FROM health_patients p WHERE p.id=? AND p.org_id=?");
        $row->execute([$patId, $orgId]);
        $pt = $row->fetch();

        if (!$pt || !$pt['user_id']) { setFlash('error', 'Patient has no portal account.'); redirect('patients.php'); }

        $newPass = _patientGenPassword();
        $pdo->prepare("UPDATE users SET password=? WHERE id=? AND org_id=?")->execute([password_hash($newPass, PASSWORD_BCRYPT, ['cost'=>12]), $pt['user_id'], $orgId]);

        $_SESSION['_patient_creds'] = [
            'name'      => $pt['first_name'].' '.$pt['last_name'],
            'email'     => $pt['email'],
            'password'  => $newPass,
            'login_url' => APP_URL . '/auth/login.php',
            'action'    => 'reset',
        ];

        _patientSendCredentials($pt['email'], $pt['first_name'].' '.$pt['last_name'], $newPass, $orgId, 'reset');
        if (!empty($pt['phone'])) {
            notifySms($pt['phone'], APP_NAME . ": Hi {$pt['first_name']}, your patient portal password has been reset. Password: {$newPass} — Login: " . APP_URL . '/auth/login.php', $orgId, 'patient_password_reset');
        }

        setFlash('success', 'Portal password reset. Credentials shown below.');
        redirect('patients.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM health_patients WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Patient record deleted.');
        logActivity('delete', 'health', "Patient removed: #$id");
        redirect('patients.php');
    }
}

// ── Patient account helpers ───────────────────────────────────────
function _patientCreateAccount(PDO $pdo, int $orgId, string $first, string $last, string $email, string $phone): int|string
{
    // Add patient role support safely
    try { $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','client_admin','staff','patient') NOT NULL DEFAULT 'staff'"); } catch (Throwable $e) {}

    $chk = $pdo->prepare("SELECT id, org_id FROM users WHERE email=?");
    $chk->execute([$email]);
    $existing = $chk->fetch();

    if ($existing) {
        if ((int)$existing['org_id'] === $orgId) return (int)$existing['id']; // reuse
        return "The email {$email} is already registered in another organization.";
    }

    $plain = _patientGenPassword();
    $pdo->prepare("INSERT INTO users (org_id,name,email,password,phone,role,status) VALUES (?,?,?,?,?,'patient','active')")
        ->execute([$orgId, $first.' '.$last, $email, password_hash($plain, PASSWORD_BCRYPT, ['cost'=>12]), $phone]);
    $newUserId = (int)$pdo->lastInsertId();

    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['_patient_creds'] = [
        'name'      => $first . ' ' . $last,
        'email'     => $email,
        'password'  => $plain,
        'login_url' => APP_URL . '/auth/login.php',
        'action'    => 'created',
    ];

    _patientSendCredentials($email, $first.' '.$last, $plain, $orgId, 'created');
    if ($phone) {
        notifySms($phone, APP_NAME . ": Hi {$first}, your patient portal is ready. Email: {$email} | Password: {$plain} | Login: " . APP_URL . '/auth/login.php', $orgId, 'patient_welcome');
    }

    return $newUserId;
}

function _patientGenPassword(): string
{
    $u = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; $l = 'abcdefghjkmnpqrstuvwxyz';
    $d = '23456789'; $s = '@#!$'; $a = $u.$l.$d.$s;
    $p = $u[random_int(0,strlen($u)-1)].$d[random_int(0,strlen($d)-1)].$s[random_int(0,strlen($s)-1)];
    for ($i=0;$i<7;$i++) $p .= $a[random_int(0,strlen($a)-1)];
    return str_shuffle($p);
}

function _patientSendCredentials(string $toEmail, string $name, string $password, int $orgId, string $action): void
{
    try {
        require_once __DIR__ . '/../../includes/mailer.php';
        $loginUrl   = APP_URL . '/auth/login.php';
        $portalUrl  = APP_URL . '/patient/index.php';
        $actionLine = $action === 'reset'
            ? "<p>Your patient portal password has been reset.</p>"
            : "<p>Your patient portal account has been created on <strong>" . APP_NAME . "</strong>.</p>";
        $body = "
            <div style='font-family:sans-serif;max-width:520px;margin:auto'>
              <h2 style='color:#e74c3c'>Your Patient Portal Credentials</h2>
              <p>Dear <strong>{$name}</strong>,</p>{$actionLine}
              <p>Use your credentials below to access your personal health records, appointments, lab results, and bills.</p>
              <table style='width:100%;border-collapse:collapse;margin:20px 0;background:#f9f9f9;border-radius:8px;overflow:hidden'>
                <tr><td style='padding:12px 16px;color:#555;font-size:.9rem;width:38%'>Login URL</td>
                    <td style='padding:12px 16px;font-weight:700'><a href='{$loginUrl}'>{$loginUrl}</a></td></tr>
                <tr style='background:#fff'><td style='padding:12px 16px;color:#555;font-size:.9rem'>Email (Username)</td>
                    <td style='padding:12px 16px;font-weight:700'>{$toEmail}</td></tr>
                <tr><td style='padding:12px 16px;color:#555;font-size:.9rem'>Temporary Password</td>
                    <td style='padding:12px 16px;font-weight:700;font-family:monospace;font-size:1.1rem;letter-spacing:2px;color:#e74c3c'>{$password}</td></tr>
              </table>
              <p style='color:#e74c3c;font-size:.85rem'><strong>Important:</strong> Change this password after your first login.</p>
              <div style='text-align:center;margin:24px 0'>
                <a href='{$loginUrl}' style='background:#e74c3c;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>Access Patient Portal →</a>
              </div>
              <p style='color:#888;font-size:.8rem'>In your portal you can view: appointments, medical records, lab results, prescriptions, bills, and your health timeline.</p>
            </div>";
        orgMailer($orgId)->send($toEmail, APP_NAME . ' — Your Patient Portal Access', $body);
    } catch (Throwable $e) {}
}

// ── Page setup ────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Ensure user_id column exists on health_patients
try { $pdo->exec("ALTER TABLE health_patients ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL AFTER org_id"); } catch (Throwable $e) {}

// One-time patient credentials flash
$flashCreds = null;
if (!empty($_SESSION['_patient_creds'])) { $flashCreds = $_SESSION['_patient_creds']; unset($_SESSION['_patient_creds']); }

// Filters
$fBlood  = $_GET['blood_group'] ?? '';
$fStatus = $_GET['status']      ?? '';
$fQ      = trim($_GET['q']      ?? '');

$where  = 'p.org_id = ?';
$params = [$orgId];
if ($fBlood  !== '') { $where .= ' AND p.blood_group=?';  $params[] = $fBlood; }
if ($fStatus !== '') { $where .= ' AND p.status=?';       $params[] = $fStatus; }
if ($fQ      !== '') {
    $where .= ' AND (p.patient_no LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.phone LIKE ?)';
    $like = "%$fQ%"; array_push($params, $like, $like, $like, $like);
}

$patientsList = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, u.email AS account_email, u.status AS account_status FROM health_patients p LEFT JOIN users u ON p.user_id=u.id WHERE {$where} ORDER BY p.patient_no ASC");
    $stmt->execute($params);
    $patientsList = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-procedures me-2" style="color:<?= $moduleColor ?>"></i>Patient Registry</h4>
    <p class="text-muted mb-0">Enroll patients, manage EHR profiles, and issue patient portal access</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#patModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Register Patient
  </button>
</div>

<?php flash(); ?>

<?php if ($flashCreds): ?>
<div class="alert border-0 shadow-sm mb-4" style="background:#fff8e1;border-left:4px solid #f39c12!important">
  <div class="d-flex align-items-start gap-3">
    <div class="flex-shrink-0 text-warning fs-3"><i class="fas fa-key"></i></div>
    <div class="flex-fill">
      <div class="fw-bold text-dark mb-2">
        <?= $flashCreds['action'] === 'reset' ? 'Portal Password Reset — ' : 'Patient Portal Account Created — ' ?>
        <?= e($flashCreds['name']) ?>
        <span class="badge bg-warning text-dark ms-2 small">Save these now — shown once</span>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-md-4">
          <label class="text-muted small fw-semibold d-block mb-1">Login URL</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace" id="pcred_url" value="<?= e($flashCreds['login_url']) ?>" readonly>
            <button class="btn btn-outline-secondary" onclick="pCopyField('pcred_url')"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div class="col-md-4">
          <label class="text-muted small fw-semibold d-block mb-1">Email (Username)</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace" id="pcred_email" value="<?= e($flashCreds['email']) ?>" readonly>
            <button class="btn btn-outline-secondary" onclick="pCopyField('pcred_email')"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div class="col-md-4">
          <label class="text-muted small fw-semibold d-block mb-1">Temporary Password</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace fw-bold" id="pcred_pass" value="<?= e($flashCreds['password']) ?>" readonly style="letter-spacing:2px">
            <button class="btn btn-outline-secondary" onclick="pCopyField('pcred_pass')"><i class="fas fa-copy"></i></button>
          </div>
        </div>
      </div>
      <div class="small text-muted">
        <i class="fas fa-envelope me-1"></i>Credentials emailed to <strong><?= e($flashCreds['email']) ?></strong>.
        &nbsp;<i class="fas fa-exclamation-triangle text-warning me-1"></i>Patient should change password on first login.
      </div>
    </div>
    <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1">Search Patients</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Patient number, name, or phone…" value="<?= e($fQ) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Blood Group</label>
        <select name="blood_group" class="form-select form-select-sm">
          <option value="">All Groups</option>
          <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
          <option value="<?= $bg ?>" <?= $fBlood===$bg?'selected':'' ?>><?= $bg ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="active" <?= $fStatus==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $fStatus==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="patients.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Patient Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-procedures me-2 text-danger"></i>Patients Directory</h6>
    <span class="badge bg-secondary"><?= count($patientsList) ?> records</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Patient No</th>
            <th>Name</th>
            <th>Contacts</th>
            <th>Emergency</th>
            <th>Insurance</th>
            <th>Blood</th>
            <th>Portal</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($patientsList)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-procedures fa-2x mb-2 d-block opacity-25"></i>No patient profiles found.</td></tr>
          <?php else: foreach ($patientsList as $p): ?>
          <tr>
            <td class="fw-semibold"><?= e($p['patient_no'] ?: '—') ?></td>
            <td>
              <div class="fw-semibold"><?= e($p['first_name'].' '.$p['last_name']) ?></div>
              <small class="text-muted"><?= formatDate($p['dob']) ?> (<?= ucfirst($p['gender']) ?>)</small>
            </td>
            <td>
              <div class="small"><i class="fas fa-phone text-muted me-1"></i><?= e($p['phone'] ?: '—') ?></div>
              <div class="small text-muted"><i class="fas fa-envelope me-1"></i><?= e($p['email'] ?: '—') ?></div>
            </td>
            <td>
              <div class="small fw-semibold"><?= e($p['emergency_contact'] ?: '—') ?></div>
              <small class="text-muted"><?= e($p['emergency_phone'] ?: '—') ?></small>
            </td>
            <td>
              <div class="small fw-semibold"><?= e($p['insurance_provider'] ?: 'Self Pay') ?></div>
              <small class="text-muted"><?= e($p['insurance_no'] ?: '—') ?></small>
            </td>
            <td class="text-center">
              <?php if ($p['blood_group']): ?>
              <span class="badge bg-danger rounded-circle p-2" style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;font-size:.65rem"><?= e($p['blood_group']) ?></span>
              <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
            <td>
              <?php if ($p['user_id']): ?>
                <span class="text-success small fw-semibold"><i class="fas fa-check-circle me-1"></i>Active</span>
              <?php else: ?>
                <span class="text-muted small"><i class="fas fa-times-circle me-1 text-danger"></i>None</span>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $p['status']==='active'?'success':'secondary' ?>"><?= ucfirst($p['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $p['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <a href="<?= APP_URL ?>/modules/health/invoice-pdf.php?patient_id=<?= $p['id'] ?>" target="_blank" class="btn btn-outline-danger" title="Invoice PDF"><i class="fas fa-file-invoice"></i></a>
                <a href="<?= APP_URL ?>/modules/health/medical-certificate-pdf.php?patient_id=<?= $p['id'] ?>" target="_blank" class="btn btn-outline-info" title="Medical Certificate"><i class="fas fa-file-medical"></i></a>
                <?php if ($p['user_id']): ?>
                <button class="btn btn-outline-warning" onclick="resetPatientPwd(<?= $p['id'] ?>,'<?= e($p['first_name'].' '.$p['last_name']) ?>')" title="Reset Portal Password"><i class="fas fa-key"></i></button>
                <?php endif; ?>
                <button class="btn btn-outline-danger" onclick="delPatient(<?= $p['id'] ?>,'<?= e($p['first_name'].' '.$p['last_name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>


<!-- ── Register / Edit Patient Modal ─────────────────────────────── -->
<div class="modal fade" id="patModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="patId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="patTitle"><i class="fas fa-procedures me-2"></i>Register Patient</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <h6 class="fw-bold mb-3 border-bottom pb-1 text-danger"><i class="fas fa-procedures me-2"></i>Demographics</h6>
        <div class="row g-3 mb-4">
          <div class="col-md-4"><label class="form-label fw-semibold">Patient ID No <span class="text-danger">*</span></label><input type="text" name="patient_no" id="patNo" class="form-control" required placeholder="e.g. PAT-2026-003"></div>
          <div class="col-md-4"><label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label><input type="text" name="first_name" id="patFirst" class="form-control" required placeholder="e.g. John"></div>
          <div class="col-md-4"><label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label><input type="text" name="last_name" id="patLast" class="form-control" required placeholder="e.g. Smith"></div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
            <select name="gender" id="patGender" class="form-select" required>
              <option value="male">Male</option><option value="female">Female</option><option value="other">Other</option>
            </select>
          </div>
          <div class="col-md-3"><label class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label><input type="date" name="dob" id="patDob" class="form-control" required></div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Blood Group</label>
            <select name="blood_group" id="patBlood" class="form-select">
              <option value="">Unknown</option>
              <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?><option value="<?= $bg ?>"><?= $bg ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="patStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select>
          </div>
          <div class="col-md-6"><label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label><input type="tel" name="phone" id="patPhone" class="form-control" required placeholder="+254 711 000 000"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" id="patEmail" class="form-control" placeholder="patient@example.com"></div>
          <div class="col-12"><label class="form-label fw-semibold">Residential Address</label><textarea name="address" id="patAddress" class="form-control" rows="2" placeholder="Street, estate, house number…"></textarea></div>
        </div>

        <h6 class="fw-bold mb-3 border-bottom pb-1 text-danger"><i class="fas fa-file-prescription me-2"></i>Medical Alerts</h6>
        <div class="row g-3 mb-4">
          <div class="col-md-6"><label class="form-label fw-semibold">Known Allergies</label><input type="text" name="allergies" id="patAllergies" class="form-control" placeholder="e.g. Penicillin, Peanuts…"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Chronic Conditions</label><input type="text" name="chronic_conditions" id="patConditions" class="form-control" placeholder="e.g. Hypertension, Diabetes…"></div>
        </div>

        <h6 class="fw-bold mb-3 border-bottom pb-1 text-danger"><i class="fas fa-file-invoice-dollar me-2"></i>Insurance &amp; Emergency</h6>
        <div class="row g-3 mb-4">
          <div class="col-md-6"><label class="form-label fw-semibold">Emergency Contact Name <span class="text-danger">*</span></label><input type="text" name="emergency_contact" id="patEmerName" class="form-control" required placeholder="Spouse / Parent name"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Emergency Contact Phone <span class="text-danger">*</span></label><input type="tel" name="emergency_phone" id="patEmerPhone" class="form-control" required placeholder="+254 722 000 000"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Insurance Provider</label><input type="text" name="insurance_provider" id="patInsProvider" class="form-control" placeholder="e.g. NHIF, Jubilee…"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Insurance Policy No</label><input type="text" name="insurance_no" id="patInsNo" class="form-control" placeholder="e.g. POL-8827-04"></div>
        </div>

        <!-- Portal Account Section -->
        <hr class="my-3">
        <h6 class="fw-bold mb-3"><i class="fas fa-hospital-user me-2 text-muted"></i>Patient Portal Access</h6>

        <div id="patCreateBlock">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="create_account" id="patAccToggle" value="1" checked onchange="togglePatAccOpts()">
            <label class="form-check-label fw-semibold" for="patAccToggle">Create a patient portal login account</label>
          </div>
          <div id="patAccOptions" class="p-3 rounded border" style="background:#f8f9fa">
            <div class="alert alert-info py-2 px-3 mb-0 small">
              <i class="fas fa-info-circle me-1"></i>The patient will receive login credentials by email and SMS. They can then view their appointments, records, lab results, prescriptions, and bills at
              <strong><?= APP_URL ?>/patient/</strong>
            </div>
          </div>
        </div>

        <div id="patExistingBlock" class="d-none">
          <div class="alert alert-success py-2 px-3 small">
            <i class="fas fa-check-circle me-2"></i>
            This patient has an active portal account: <strong id="patExistingEmail"></strong>
            — use the <strong>Reset Password</strong> button in the table to issue new credentials.
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i><span id="patSubmitLabel">Enroll Patient</span></button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- Hidden forms -->
<form method="POST" id="patResetForm" style="display:none">
  <?= csrfField() ?><input type="hidden" name="action" value="reset_patient_password"><input type="hidden" name="patient_id" id="patResetId">
</form>
<form method="POST" id="delPatForm" style="display:none">
  <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delPatId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('patTitle').innerHTML = '<i class="fas fa-procedures me-2"></i>Register Patient';
  document.getElementById('patSubmitLabel').textContent = 'Enroll Patient';
  document.getElementById('patId').value      = '0';
  document.getElementById('patNo').value      = '';
  document.getElementById('patFirst').value   = '';
  document.getElementById('patLast').value    = '';
  document.getElementById('patGender').value  = 'male';
  document.getElementById('patStatus').value  = 'active';
  document.getElementById('patBlood').value   = '';
  document.getElementById('patPhone').value   = '';
  document.getElementById('patEmail').value   = '';
  document.getElementById('patAddress').value = '';
  document.getElementById('patAllergies').value   = '';
  document.getElementById('patConditions').value  = '';
  document.getElementById('patEmerName').value    = '';
  document.getElementById('patEmerPhone').value   = '';
  document.getElementById('patInsProvider').value = '';
  document.getElementById('patInsNo').value       = '';
  document.getElementById('patDob').value = new Date(new Date().setFullYear(new Date().getFullYear()-30)).toISOString().split('T')[0];

  const toggle = document.getElementById('patAccToggle');
  toggle.checked  = true;
  toggle.disabled = false;
  document.getElementById('patCreateBlock').classList.remove('d-none');
  document.getElementById('patExistingBlock').classList.add('d-none');
  document.getElementById('patAccOptions').style.display = '';
}

function openEdit(id) {
  fetch('patients.php?fetch_details=' + id)
    .then(r => r.json())
    .then(d => {
      document.getElementById('patTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Patient Profile';
      document.getElementById('patSubmitLabel').textContent = 'Save Changes';
      document.getElementById('patId').value      = d.id;
      document.getElementById('patNo').value      = d.patient_no          || '';
      document.getElementById('patFirst').value   = d.first_name          || '';
      document.getElementById('patLast').value    = d.last_name           || '';
      document.getElementById('patGender').value  = d.gender              || 'male';
      document.getElementById('patStatus').value  = d.status              || 'active';
      document.getElementById('patDob').value     = d.dob                 || '';
      document.getElementById('patBlood').value   = d.blood_group         || '';
      document.getElementById('patPhone').value   = d.phone               || '';
      document.getElementById('patEmail').value   = d.email               || '';
      document.getElementById('patAddress').value = d.address             || '';
      document.getElementById('patAllergies').value   = d.allergies         || '';
      document.getElementById('patConditions').value  = d.chronic_conditions|| '';
      document.getElementById('patEmerName').value    = d.emergency_contact || '';
      document.getElementById('patEmerPhone').value   = d.emergency_phone   || '';
      document.getElementById('patInsProvider').value = d.insurance_provider|| '';
      document.getElementById('patInsNo').value       = d.insurance_no      || '';

      const toggle = document.getElementById('patAccToggle');
      if (d.user_id) {
        toggle.checked  = false;
        toggle.disabled = true;
        document.getElementById('patCreateBlock').classList.add('d-none');
        document.getElementById('patExistingBlock').classList.remove('d-none');
        document.getElementById('patExistingEmail').textContent = d.account_email || d.email || '';
      } else {
        toggle.checked  = false;
        toggle.disabled = false;
        document.getElementById('patAccOptions').style.display = 'none';
        document.getElementById('patCreateBlock').classList.remove('d-none');
        document.getElementById('patExistingBlock').classList.add('d-none');
      }

      new bootstrap.Modal(document.getElementById('patModal')).show();
    });
}

function togglePatAccOpts() {
  document.getElementById('patAccOptions').style.display =
    document.getElementById('patAccToggle').checked ? '' : 'none';
}

function resetPatientPwd(id, name) {
  Swal.fire({
    title: 'Reset Patient Portal Password?',
    html: 'A new password will be generated for <strong>' + name + '</strong> and sent to their email.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e67e22', confirmButtonText: 'Yes, Reset'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('patResetId').value = id;
      document.getElementById('patResetForm').submit();
    }
  });
}

function delPatient(id, name) {
  Swal.fire({
    title: 'Delete Patient Profile?',
    text: 'Remove "' + name + '" and all associated records?',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Yes, Delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delPatId').value = id;
      document.getElementById('delPatForm').submit();
    }
  });
}

function pCopyField(id) {
  const el = document.getElementById(id);
  navigator.clipboard.writeText(el.value).then(() => {
    const btn = el.nextElementSibling;
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check text-success"></i>';
    setTimeout(() => btn.innerHTML = orig, 1800);
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
