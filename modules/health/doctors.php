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

// ── AJAX: fetch doctor for edit ───────────────────────────────────
if (isset($_GET['fetch_details'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $did   = (int)$_GET['fetch_details'];
    try {
        $st = $pdo->prepare("SELECT d.*, u.email AS account_email, u.status AS account_status FROM health_doctors d LEFT JOIN users u ON d.user_id=u.id WHERE d.id=? AND d.org_id=?");
        $st->execute([$did, $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) { header('Content-Type: application/json'); echo json_encode($row); exit; }
    } catch (Exception $e) {}
    header('Content-Type: application/json'); echo '{}'; exit;
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

    // ── Register / update a doctor ────────────────────────────────
    if ($action === 'save') {
        $id             = (int)($_POST['id'] ?? 0);
        $firstName      = sanitize($_POST['first_name']     ?? '');
        $lastName       = sanitize($_POST['last_name']      ?? '');
        $specialization = sanitize($_POST['specialization'] ?? 'General Practitioner');
        $phone          = sanitize($_POST['phone']          ?? '');
        $email          = trim(strtolower($_POST['email']   ?? ''));
        $status         = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        $createAccount  = !empty($_POST['create_account']);
        $moduleRole     = in_array($_POST['module_role'] ?? '', ['doctor','nurse','receptionist','lab_technician','pharmacist','cashier']) ? $_POST['module_role'] : 'doctor';

        if (!$firstName || !$lastName) {
            setFlash('error', 'First and last name are required.');
            redirect('doctors.php');
        }

        if ($id > 0) {
            // ── Edit existing doctor ──────────────────────────────
            $pdo->prepare("UPDATE health_doctors SET first_name=?,last_name=?,specialization=?,phone=?,email=?,status=? WHERE id=? AND org_id=?")
                ->execute([$firstName,$lastName,$specialization,$phone,$email,$status,$id,$orgId]);

            // Sync name/email in linked user account
            $doctorRow = $pdo->prepare("SELECT user_id FROM health_doctors WHERE id=? AND org_id=?");
            $doctorRow->execute([$id, $orgId]);
            $existingUserId = (int)($doctorRow->fetchColumn() ?? 0);
            if ($existingUserId) {
                $pdo->prepare("UPDATE users SET name=?,phone=?,email=? WHERE id=? AND org_id=?")
                    ->execute(["Dr. $firstName $lastName", $phone, $email, $existingUserId, $orgId]);
            }

            // Grant account to existing doctor who didn't have one yet
            if ($createAccount && !$existingUserId && $email) {
                $newUserId = _createDoctorAccount($pdo, $orgId, $uid, $firstName, $lastName, $email, $phone, $moduleRole, $moduleSlug);
                if (is_int($newUserId) && $newUserId > 0) {
                    $pdo->prepare("UPDATE health_doctors SET user_id=? WHERE id=? AND org_id=?")
                        ->execute([$newUserId, $id, $orgId]);
                }
            }

            setFlash('success', "Dr. $firstName $lastName updated.");
            logActivity('update', 'health', "Doctor updated: $firstName $lastName");
            redirect('doctors.php');
        }

        // ── Register new doctor ───────────────────────────────────
        $linkedUserId = null;

        if ($createAccount && $email) {
            $result = _createDoctorAccount($pdo, $orgId, $uid, $firstName, $lastName, $email, $phone, $moduleRole, $moduleSlug);
            if (is_string($result)) {
                // Error message returned
                setFlash('error', $result);
                redirect('doctors.php');
            }
            $linkedUserId = $result;
        }

        $pdo->prepare("INSERT INTO health_doctors (org_id,user_id,first_name,last_name,specialization,phone,email,status) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$orgId,$linkedUserId,$firstName,$lastName,$specialization,$phone,$email,$status]);

        setFlash('success', "Dr. $firstName $lastName registered." . ($linkedUserId ? ' Portal login created.' : ''));
        logActivity('create', 'health', "Doctor registered: $firstName $lastName ($specialization)");
        redirect('doctors.php');
    }

    // ── Reset a doctor's portal password ─────────────────────────
    if ($action === 'reset_password') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $row   = $pdo->prepare("SELECT d.user_id, d.first_name, d.last_name, d.email FROM health_doctors d WHERE d.id=? AND d.org_id=?");
        $row->execute([$docId, $orgId]);
        $doc = $row->fetch();

        if (!$doc || !$doc['user_id']) {
            setFlash('error', 'Doctor has no portal account to reset.');
            redirect('doctors.php');
        }

        $newPass  = _generatePassword();
        $hash     = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE users SET password=? WHERE id=? AND org_id=?")->execute([$hash, $doc['user_id'], $orgId]);

        // Show new password once
        $_SESSION['_doctor_creds'] = [
            'name'      => "Dr. {$doc['first_name']} {$doc['last_name']}",
            'email'     => $doc['email'],
            'password'  => $newPass,
            'login_url' => APP_URL . '/auth/login.php',
            'action'    => 'reset',
        ];

        // Send email notification
        _sendDoctorCredentialsEmail($doc['email'], "Dr. {$doc['first_name']} {$doc['last_name']}", $newPass, $orgId, 'reset');

        // SMS
        if (!empty($doc['phone'] ?? '')) {
            notifySms($doc['phone'], APP_NAME . ": Hi Dr.{$doc['first_name']}, your portal password has been reset. New password: {$newPass} — Login: " . APP_URL . '/auth/login.php', $orgId, 'doctor_password_reset');
        }

        logActivity('reset_password', 'health', "Password reset for doctor #{$docId}");
        setFlash('success', 'Password reset. New credentials shown below.');
        redirect('doctors.php');
    }

    // ── Delete doctor ─────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM health_doctors WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Practitioner removed from registry.');
        logActivity('delete', 'health', "Doctor removed: #$id");
        redirect('doctors.php');
    }
}

// ── Helper: create a user account for a doctor ────────────────────
function _createDoctorAccount(PDO $pdo, int $orgId, int $grantedBy, string $firstName, string $lastName, string $email, string $phone, string $moduleRole, string $moduleSlug): int|string
{
    // Check if email already exists globally
    $chk = $pdo->prepare("SELECT id, org_id FROM users WHERE email=?");
    $chk->execute([$email]);
    $existing = $chk->fetch();

    if ($existing) {
        if ((int)$existing['org_id'] === $orgId) {
            // Reuse the existing user in this org — just link them
            $userId = (int)$existing['id'];
            _grantModuleAccess($pdo, $userId, $orgId, $grantedBy, $moduleRole);
            return $userId;
        }
        return "The email {$email} is already registered in another organization. Use a different email.";
    }

    $plainPassword = _generatePassword();
    $hash          = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    $pdo->prepare("INSERT INTO users (org_id,name,email,password,phone,role,status) VALUES (?,?,?,?,?,'staff','active')")
        ->execute([$orgId, "Dr. $firstName $lastName", $email, $hash, $phone]);
    $userId = (int)$pdo->lastInsertId();

    _grantModuleAccess($pdo, $userId, $orgId, $grantedBy, $moduleRole);

    // Store credentials for one-time on-screen display
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['_doctor_creds'] = [
        'name'      => "Dr. $firstName $lastName",
        'email'     => $email,
        'password'  => $plainPassword,
        'login_url' => APP_URL . '/auth/login.php',
        'action'    => 'created',
    ];

    // Send welcome email
    _sendDoctorCredentialsEmail($email, "Dr. $firstName $lastName", $plainPassword, $orgId, 'created');

    // Send SMS
    if ($phone) {
        notifySms($phone, APP_NAME . ": Welcome Dr.$firstName! Your portal login — Email: $email | Password: $plainPassword | URL: " . APP_URL . '/auth/login.php', $orgId, 'doctor_welcome');
    }

    return $userId;
}

function _grantModuleAccess(PDO $pdo, int $userId, int $orgId, int $grantedBy, string $roleKey): void
{
    try {
        $pdo->prepare("INSERT IGNORE INTO user_module_access (user_id,org_id,module_slug,granted_by) VALUES (?,?,'health',?)")
            ->execute([$userId, $orgId, $grantedBy]);
    } catch (Throwable $e) {}
    try {
        $pdo->prepare("INSERT INTO user_module_roles (user_id,org_id,module_slug,role_key,granted_by) VALUES (?,?,'health',?,?) ON DUPLICATE KEY UPDATE role_key=VALUES(role_key),granted_by=VALUES(granted_by)")
            ->execute([$userId, $orgId, $roleKey, $grantedBy]);
    } catch (Throwable $e) {}
}

function _generatePassword(): string
{
    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower   = 'abcdefghjkmnpqrstuvwxyz';
    $digits  = '23456789';
    $special = '@#!$';
    $all     = $upper . $lower . $digits . $special;
    $pass    = $upper[random_int(0, strlen($upper)-1)]
             . $digits[random_int(0, strlen($digits)-1)]
             . $special[random_int(0, strlen($special)-1)];
    for ($i = 0; $i < 7; $i++) {
        $pass .= $all[random_int(0, strlen($all)-1)];
    }
    return str_shuffle($pass);
}

function _sendDoctorCredentialsEmail(string $toEmail, string $name, string $password, int $orgId, string $action): void
{
    try {
        require_once __DIR__ . '/../../includes/mailer.php';
        $loginUrl = APP_URL . '/auth/login.php';
        $actionLine = $action === 'reset'
            ? "<p>Your portal password has been reset by an administrator.</p>"
            : "<p>A portal account has been created for you on <strong>" . APP_NAME . "</strong>.</p>";

        $body = "
            <div style='font-family:sans-serif;max-width:520px;margin:auto'>
              <h2 style='color:#e74c3c'>Your Portal Login Credentials</h2>
              <p>Dear <strong>{$name}</strong>,</p>
              {$actionLine}
              <table style='width:100%;border-collapse:collapse;margin:20px 0;background:#f9f9f9;border-radius:8px;overflow:hidden'>
                <tr>
                  <td style='padding:12px 16px;color:#555;font-size:.9rem;width:38%'>Login URL</td>
                  <td style='padding:12px 16px;font-weight:700'><a href='{$loginUrl}'>{$loginUrl}</a></td>
                </tr>
                <tr style='background:#fff'>
                  <td style='padding:12px 16px;color:#555;font-size:.9rem'>Email (Username)</td>
                  <td style='padding:12px 16px;font-weight:700'>{$toEmail}</td>
                </tr>
                <tr>
                  <td style='padding:12px 16px;color:#555;font-size:.9rem'>Temporary Password</td>
                  <td style='padding:12px 16px;font-weight:700;font-family:monospace;font-size:1.1rem;letter-spacing:2px;color:#e74c3c'>{$password}</td>
                </tr>
              </table>
              <p style='color:#e74c3c;font-size:.85rem'><strong>Important:</strong> Please log in and change this password immediately.</p>
              <div style='text-align:center;margin:24px 0'>
                <a href='{$loginUrl}' style='background:#e74c3c;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
                  Access Your Portal →
                </a>
              </div>
              <p style='color:#888;font-size:.8rem'>If you did not expect this email, please contact your system administrator.</p>
            </div>
        ";
        orgMailer($orgId)->send($toEmail, APP_NAME . ' — Your Doctor Portal Credentials', $body);
    } catch (Throwable $e) {}
}

// ── Page setup ────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// One-time credentials from session
$flashCreds = null;
if (!empty($_SESSION['_doctor_creds'])) {
    $flashCreds = $_SESSION['_doctor_creds'];
    unset($_SESSION['_doctor_creds']);
}

// ── Data ──────────────────────────────────────────────────────────
$doctorsList = [];
try {
    $st = $pdo->prepare("
        SELECT d.*,
               u.name AS account_name, u.email AS account_email,
               u.status AS account_status,
               mr.role_key AS module_role
        FROM health_doctors d
        LEFT JOIN users u ON d.user_id = u.id
        LEFT JOIN user_module_roles mr ON mr.user_id=d.user_id AND mr.module_slug='health'
        WHERE d.org_id=?
        ORDER BY d.first_name ASC
    ");
    $st->execute([$orgId]);
    $doctorsList = $st->fetchAll();
} catch (Exception $e) {}

$roleLabels = [
    'doctor'          => ['Doctor',          'primary'],
    'nurse'           => ['Nurse',            'info'],
    'receptionist'    => ['Receptionist',     'secondary'],
    'lab_technician'  => ['Lab Technician',   'warning'],
    'pharmacist'      => ['Pharmacist',       'success'],
    'cashier'         => ['Cashier',          'dark'],
];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-user-md me-2" style="color:<?= $moduleColor ?>"></i>Medical Practitioners Registry</h4>
    <p class="text-muted mb-0">Register doctors, assign portal logins, and manage health module access</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#docModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Register Practitioner
  </button>
</div>

<?php flash(); ?>

<?php if ($flashCreds): ?>
<!-- ── Credentials Banner (one-time display) ──────────────────── -->
<div class="alert border-0 shadow-sm mb-4" style="background:#fff8e1;border-left:4px solid #f39c12!important">
  <div class="d-flex align-items-start gap-3">
    <div class="flex-shrink-0 text-warning fs-3"><i class="fas fa-key"></i></div>
    <div class="flex-fill">
      <div class="fw-bold text-dark mb-2">
        <?= $flashCreds['action'] === 'reset' ? 'Password Reset — ' : 'Portal Account Created — ' ?>
        <?= e($flashCreds['name']) ?>
        <span class="badge bg-warning text-dark ms-2 small">Save these now — shown once</span>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-md-4">
          <label class="text-muted small fw-semibold d-block mb-1">Login URL</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace" id="cred_url" value="<?= e($flashCreds['login_url']) ?>" readonly>
            <button class="btn btn-outline-secondary" onclick="copyField('cred_url')" title="Copy"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div class="col-md-4">
          <label class="text-muted small fw-semibold d-block mb-1">Email (Username)</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace" id="cred_email" value="<?= e($flashCreds['email']) ?>" readonly>
            <button class="btn btn-outline-secondary" onclick="copyField('cred_email')" title="Copy"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div class="col-md-4">
          <label class="text-muted small fw-semibold d-block mb-1">Temporary Password</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace fw-bold" id="cred_pass" value="<?= e($flashCreds['password']) ?>" readonly style="letter-spacing:2px">
            <button class="btn btn-outline-secondary" onclick="copyField('cred_pass')" title="Copy"><i class="fas fa-copy"></i></button>
          </div>
        </div>
      </div>
      <div class="small text-muted">
        <i class="fas fa-envelope me-1"></i> Credentials email sent to <strong><?= e($flashCreds['email']) ?></strong>.
        &nbsp;<i class="fas fa-exclamation-triangle text-warning me-1"></i> The doctor should change their password on first login.
      </div>
    </div>
    <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php endif; ?>

<!-- Doctors Table -->
<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-user-md me-2 text-danger"></i>Clinical Staff &amp; Consultants</h6>
    <span class="badge bg-secondary"><?= count($doctorsList) ?> registered</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Practitioner</th>
            <th>Specialty</th>
            <th>Contact</th>
            <th>Portal Account</th>
            <th>Module Role</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($doctorsList)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            <i class="fas fa-user-md fa-2x mb-2 d-block opacity-25"></i>No practitioners registered yet.
          </td></tr>
          <?php else: foreach ($doctorsList as $d):
            [$roleName, $roleBg] = $roleLabels[$d['module_role'] ?? ''] ?? ['—', 'light text-dark'];
          ?>
          <tr>
            <td>
              <div class="fw-semibold">Dr. <?= e($d['first_name'] . ' ' . $d['last_name']) ?></div>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($d['specialization']) ?></span></td>
            <td>
              <div class="small"><i class="fas fa-phone text-muted me-1"></i><?= e($d['phone'] ?: '—') ?></div>
              <div class="small text-muted"><i class="fas fa-envelope me-1"></i><?= e($d['email'] ?: '—') ?></div>
            </td>
            <td>
              <?php if ($d['user_id']): ?>
                <div class="small">
                  <span class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i>Active</span>
                  <span class="badge bg-<?= $d['account_status'] === 'active' ? 'success' : 'secondary' ?> ms-1"><?= ucfirst($d['account_status'] ?? 'active') ?></span>
                </div>
                <div class="text-muted small font-monospace"><?= e($d['account_email'] ?: $d['email']) ?></div>
              <?php else: ?>
                <span class="text-muted small"><i class="fas fa-times-circle me-1 text-danger"></i>No login account</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($d['module_role']): ?>
              <span class="badge bg-<?= $roleBg ?>"><?= $roleName ?></span>
              <?php else: ?>
              <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $d['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($d['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $d['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <?php if ($d['user_id']): ?>
                <button class="btn btn-outline-warning" onclick="resetPassword(<?= $d['id'] ?>, '<?= e($d['first_name'].' '.$d['last_name']) ?>')" title="Reset Password">
                  <i class="fas fa-key"></i>
                </button>
                <?php endif; ?>
                <button class="btn btn-outline-danger" onclick="delDoc(<?= $d['id'] ?>, '<?= e($d['first_name'].' '.$d['last_name']) ?>')" title="Remove"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>


<!-- ── Register / Edit Doctor Modal ──────────────────────────────── -->
<div class="modal fade" id="docModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="docId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="docTitle"><i class="fas fa-user-md me-2"></i>Register Practitioner</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <h6 class="fw-bold text-dark mb-3"><i class="fas fa-id-card me-2 text-muted"></i>Personal &amp; Clinical Details</h6>
        <div class="row g-3 mb-4">
          <div class="col-md-6">
            <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
            <input type="text" name="first_name" id="docFirst" class="form-control" required placeholder="e.g. Elizabeth">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
            <input type="text" name="last_name" id="docLast" class="form-control" required placeholder="e.g. Blackwell">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Clinical Specialty <span class="text-danger">*</span></label>
            <input type="text" name="specialization" id="docSpecialty" class="form-control" required placeholder="e.g. Paediatrics, Cardiology, General Surgery">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label>
            <input type="tel" name="phone" id="docPhone" class="form-control" required placeholder="+254 700 111 222">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Official Email <span class="text-danger">*</span></label>
            <input type="email" name="email" id="docEmail" class="form-control" required placeholder="dr.blackwell@clinic.com">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="docStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>

        <!-- Portal Account Section -->
        <div id="portalSection">
          <hr class="my-3">
          <h6 class="fw-bold text-dark mb-3"><i class="fas fa-lock me-2 text-muted"></i>Portal Login Account</h6>

          <!-- Shown when doctor has no account yet -->
          <div id="createAccountBlock">
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" name="create_account" id="createAccountToggle" value="1" checked onchange="toggleAccountOptions()">
              <label class="form-check-label fw-semibold" for="createAccountToggle">
                Create a portal login account for this doctor
              </label>
            </div>
            <div id="accountOptions" class="p-3 rounded border" style="background:#f8f9fa">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label fw-semibold">Module Role</label>
                  <select name="module_role" id="docModuleRole" class="form-select">
                    <option value="doctor">Doctor — full clinical access</option>
                    <option value="nurse">Nurse — vitals, nursing, admissions</option>
                    <option value="receptionist">Receptionist — appointments, patients</option>
                    <option value="lab_technician">Lab Technician — laboratory only</option>
                    <option value="pharmacist">Pharmacist — pharmacy &amp; prescriptions</option>
                    <option value="cashier">Cashier — billing only</option>
                  </select>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                  <div class="alert alert-info py-2 px-3 mb-0 small w-100">
                    <i class="fas fa-info-circle me-1"></i>
                    A secure password will be auto-generated and emailed to the doctor.
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Shown when doctor already has an account (edit mode) -->
          <div id="existingAccountBlock" class="d-none">
            <div class="alert alert-success py-2 px-3 small">
              <i class="fas fa-check-circle me-2"></i>
              This doctor has an active portal account: <strong id="existingAccountEmail"></strong>
              &nbsp;— use the <strong>Reset Password</strong> button in the table to issue new credentials.
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i><span id="docSubmitLabel">Register Practitioner</span></button>
      </div>
      </form>
    </div>
  </div>
</div>


<!-- Reset Password form (hidden) -->
<form method="POST" id="resetPwdForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="reset_password">
  <input type="hidden" name="doc_id" id="resetDocId">
</form>

<!-- Delete form (hidden) -->
<form method="POST" id="delDocForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delDocId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('docTitle').innerHTML = '<i class="fas fa-user-md me-2"></i>Register Practitioner';
  document.getElementById('docSubmitLabel').textContent = 'Register Practitioner';
  document.getElementById('docId').value      = '0';
  document.getElementById('docFirst').value   = '';
  document.getElementById('docLast').value    = '';
  document.getElementById('docSpecialty').value = 'General Practitioner';
  document.getElementById('docPhone').value   = '';
  document.getElementById('docEmail').value   = '';
  document.getElementById('docStatus').value  = 'active';
  document.getElementById('docModuleRole').value = 'doctor';

  document.getElementById('createAccountBlock').classList.remove('d-none');
  document.getElementById('existingAccountBlock').classList.add('d-none');
  const toggle = document.getElementById('createAccountToggle');
  toggle.checked  = true;
  toggle.disabled = false;
  document.getElementById('accountOptions').style.display = '';
}

function openEdit(id) {
  fetch('doctors.php?fetch_details=' + id)
    .then(r => r.json())
    .then(d => {
      document.getElementById('docTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Doctor Details';
      document.getElementById('docSubmitLabel').textContent = 'Save Changes';
      document.getElementById('docId').value      = d.id;
      document.getElementById('docFirst').value   = d.first_name  || '';
      document.getElementById('docLast').value    = d.last_name   || '';
      document.getElementById('docSpecialty').value = d.specialization || '';
      document.getElementById('docPhone').value   = d.phone       || '';
      document.getElementById('docEmail').value   = d.email       || '';
      document.getElementById('docStatus').value  = d.status      || 'active';

      const toggle = document.getElementById('createAccountToggle');
      if (d.user_id) {
        // Already has portal account — disable toggle so it is not submitted
        toggle.checked  = false;
        toggle.disabled = true;
        document.getElementById('createAccountBlock').classList.add('d-none');
        document.getElementById('existingAccountBlock').classList.remove('d-none');
        document.getElementById('existingAccountEmail').textContent = d.account_email || d.email || '';
      } else {
        // No account yet — offer to create
        toggle.checked  = false;
        toggle.disabled = false;
        document.getElementById('accountOptions').style.display = 'none';
        document.getElementById('createAccountBlock').classList.remove('d-none');
        document.getElementById('existingAccountBlock').classList.add('d-none');
      }

      new bootstrap.Modal(document.getElementById('docModal')).show();
    });
}

function toggleAccountOptions() {
  const show = document.getElementById('createAccountToggle').checked;
  document.getElementById('accountOptions').style.display = show ? '' : 'none';
}

function resetPassword(docId, name) {
  Swal.fire({
    title: 'Reset Portal Password?',
    html: 'A new password will be generated for <strong>Dr. ' + name + '</strong> and sent to their registered email.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e67e22',
    confirmButtonText: 'Yes, Reset Password',
    cancelButtonText: 'Cancel'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('resetDocId').value = docId;
      document.getElementById('resetPwdForm').submit();
    }
  });
}

function delDoc(id, name) {
  Swal.fire({
    title: 'Remove Practitioner?',
    text: 'Remove Dr. ' + name + ' from the clinical registry?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, Remove'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delDocId').value = id;
      document.getElementById('delDocForm').submit();
    }
  });
}

function copyField(id) {
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
