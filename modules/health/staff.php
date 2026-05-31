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

// ── AJAX: fetch staff record ──────────────────────────────────────
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
            SELECT s.*, u.email AS account_email, u.status AS account_status,
                   mr.role_key AS module_role
            FROM health_staff s
            LEFT JOIN users u  ON s.user_id = u.id
            LEFT JOIN user_module_roles mr ON mr.user_id=s.user_id AND mr.module_slug='health'
            WHERE s.id=? AND s.org_id=?
        ");
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

    // ── Register / update staff member ───────────────────────────
    if ($action === 'save_staff') {
        $id            = (int)($_POST['id']              ?? 0);
        $firstName     = sanitize($_POST['first_name']   ?? '');
        $lastName      = sanitize($_POST['last_name']    ?? '');
        $role          = in_array($_POST['role'] ?? '', ['lab_technician','pharmacist','receptionist','cashier','radiologist','admin','other']) ? $_POST['role'] : 'other';
        $qualification = sanitize($_POST['qualification']?? '');
        $department    = sanitize($_POST['department']   ?? '');
        $phone         = sanitize($_POST['phone']        ?? '');
        $email         = trim(strtolower($_POST['email'] ?? ''));
        $status        = in_array($_POST['status'] ?? '', ['active','inactive','on_leave']) ? $_POST['status'] : 'active';
        $createAccount = !empty($_POST['create_account']);
        $moduleRole    = in_array($_POST['module_role'] ?? '', ['lab_technician','pharmacist','receptionist','cashier','nurse','admin']) ? $_POST['module_role'] : _staffDefaultModuleRole($role);

        if (!$firstName || !$lastName) {
            setFlash('error', 'First and last name are required.');
            redirect('staff.php?role=' . $role);
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE health_staff SET first_name=?,last_name=?,role=?,qualification=?,department=?,phone=?,email=?,status=? WHERE id=? AND org_id=?")
                ->execute([$firstName,$lastName,$role,$qualification,$department,$phone,$email,$status,$id,$orgId]);

            // Sync linked user
            $link = $pdo->prepare("SELECT user_id FROM health_staff WHERE id=? AND org_id=?");
            $link->execute([$id, $orgId]);
            $existingUserId = (int)($link->fetchColumn() ?? 0);
            if ($existingUserId) {
                $pdo->prepare("UPDATE users SET name=?,phone=?,email=? WHERE id=? AND org_id=?")
                    ->execute([$firstName.' '.$lastName, $phone, $email, $existingUserId, $orgId]);
            }

            if ($createAccount && !$existingUserId && $email) {
                $newUid = _staffCreateAccount($pdo, $orgId, $uid, $firstName, $lastName, $email, $phone, $moduleRole, $role);
                if (is_int($newUid) && $newUid > 0) {
                    $pdo->prepare("UPDATE health_staff SET user_id=? WHERE id=? AND org_id=?")->execute([$newUid, $id, $orgId]);
                }
            }

            setFlash('success', "{$firstName} {$lastName} updated.");
            logActivity('update', 'health', "Staff updated: $firstName $lastName ($role)");
            redirect('staff.php?role=' . $role);
        }

        // New staff
        $linkedUserId = null;
        if ($createAccount && $email) {
            $result = _staffCreateAccount($pdo, $orgId, $uid, $firstName, $lastName, $email, $phone, $moduleRole, $role);
            if (is_string($result)) { setFlash('error', $result); redirect('staff.php?role=' . $role); }
            $linkedUserId = $result;
        }

        // Generate staff number
        $yr     = date('Y');
        $prefix = strtoupper(substr($role, 0, 3));
        $seqSt  = $pdo->prepare("SELECT COUNT(*)+1 FROM health_staff WHERE org_id=? AND role=? AND YEAR(created_at)=?");
        $seqSt->execute([$orgId, $role, $yr]);
        $staffNo = $prefix . '-' . $yr . '-' . str_pad((int)$seqSt->fetchColumn(), 4, '0', STR_PAD_LEFT);

        $pdo->prepare("INSERT INTO health_staff (org_id,user_id,staff_no,first_name,last_name,role,qualification,department,phone,email,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$orgId,$linkedUserId,$staffNo,$firstName,$lastName,$role,$qualification,$department,$phone,$email,$status]);

        setFlash('success', "{$firstName} {$lastName} registered." . ($linkedUserId ? ' Portal login created.' : ''));
        logActivity('create', 'health', "Staff registered: $firstName $lastName ($role)");
        redirect('staff.php?role=' . $role);
    }

    // ── Reset password ────────────────────────────────────────────
    if ($action === 'reset_password') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $row     = $pdo->prepare("SELECT user_id,first_name,last_name,email,phone FROM health_staff WHERE id=? AND org_id=?");
        $row->execute([$staffId, $orgId]);
        $sf = $row->fetch();

        if (!$sf || !$sf['user_id']) { setFlash('error', 'This staff member has no portal account.'); redirect('staff.php'); }

        $newPass = _staffGenPassword();
        $pdo->prepare("UPDATE users SET password=? WHERE id=? AND org_id=?")
            ->execute([password_hash($newPass, PASSWORD_BCRYPT, ['cost'=>12]), $sf['user_id'], $orgId]);

        $_SESSION['_staff_creds'] = [
            'name'      => $sf['first_name'].' '.$sf['last_name'],
            'email'     => $sf['email'],
            'password'  => $newPass,
            'login_url' => APP_URL . '/auth/login.php',
            'action'    => 'reset',
        ];

        _staffSendCredentials($sf['email'], $sf['first_name'].' '.$sf['last_name'], $newPass, $orgId, 'reset');
        if (!empty($sf['phone'])) {
            notifySms($sf['phone'], APP_NAME . ": Hi {$sf['first_name']}, your portal password has been reset. New password: {$newPass} — Login: " . APP_URL . '/auth/login.php', $orgId, 'staff_password_reset');
        }

        logActivity('reset_password', 'health', "Staff password reset #{$staffId}");
        setFlash('success', 'Password reset. New credentials shown below.');
        redirect('staff.php');
    }

    // ── Delete staff ──────────────────────────────────────────────
    if ($action === 'delete_staff') {
        $id   = (int)($_POST['id'] ?? 0);
        $role = sanitize($_POST['role'] ?? '');
        $pdo->prepare("DELETE FROM health_staff WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Staff member removed.');
        logActivity('delete', 'health', "Staff removed: #$id");
        redirect('staff.php?role=' . $role);
    }
}

// ── Helpers ───────────────────────────────────────────────────────
function _staffDefaultModuleRole(string $staffRole): string {
    return match($staffRole) {
        'lab_technician' => 'lab_technician',
        'pharmacist'     => 'pharmacist',
        'receptionist'   => 'receptionist',
        'cashier'        => 'cashier',
        'admin'          => 'receptionist',
        default          => 'receptionist',
    };
}

function _staffCreateAccount(PDO $pdo, int $orgId, int $grantedBy, string $first, string $last, string $email, string $phone, string $moduleRole, string $staffRole): int|string
{
    $chk = $pdo->prepare("SELECT id, org_id FROM users WHERE email=?");
    $chk->execute([$email]);
    $existing = $chk->fetch();

    if ($existing) {
        if ((int)$existing['org_id'] === $orgId) {
            _staffGrantAccess($pdo, (int)$existing['id'], $orgId, $grantedBy, $moduleRole);
            return (int)$existing['id'];
        }
        return "The email {$email} is already registered in another organization.";
    }

    $plain = _staffGenPassword();
    $pdo->prepare("INSERT INTO users (org_id,name,email,password,phone,role,status) VALUES (?,?,?,?,?,'staff','active')")
        ->execute([$orgId, $first.' '.$last, $email, password_hash($plain, PASSWORD_BCRYPT, ['cost'=>12]), $phone]);
    $newUserId = (int)$pdo->lastInsertId();

    _staffGrantAccess($pdo, $newUserId, $orgId, $grantedBy, $moduleRole);

    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['_staff_creds'] = [
        'name'      => $first . ' ' . $last,
        'email'     => $email,
        'password'  => $plain,
        'login_url' => APP_URL . '/auth/login.php',
        'action'    => 'created',
        'role'      => $staffRole,
    ];

    _staffSendCredentials($email, $first.' '.$last, $plain, $orgId, 'created');

    if ($phone) {
        notifySms($phone, APP_NAME . ": Welcome {$first}! Your portal login — Email: {$email} | Password: {$plain} | URL: " . APP_URL . '/auth/login.php', $orgId, 'staff_welcome');
    }

    return $newUserId;
}

function _staffGrantAccess(PDO $pdo, int $userId, int $orgId, int $grantedBy, string $roleKey): void
{
    try { $pdo->prepare("INSERT IGNORE INTO user_module_access (user_id,org_id,module_slug,granted_by) VALUES (?,?,'health',?)")->execute([$userId,$orgId,$grantedBy]); } catch (Throwable $e) {}
    try { $pdo->prepare("INSERT INTO user_module_roles (user_id,org_id,module_slug,role_key,granted_by) VALUES (?,?,'health',?,?) ON DUPLICATE KEY UPDATE role_key=VALUES(role_key),granted_by=VALUES(granted_by)")->execute([$userId,$orgId,$roleKey,$grantedBy]); } catch (Throwable $e) {}
}

function _staffGenPassword(): string
{
    $u = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; $l = 'abcdefghjkmnpqrstuvwxyz';
    $d = '23456789'; $s = '@#!$'; $a = $u.$l.$d.$s;
    $p = $u[random_int(0,strlen($u)-1)].$d[random_int(0,strlen($d)-1)].$s[random_int(0,strlen($s)-1)];
    for ($i=0;$i<7;$i++) $p .= $a[random_int(0,strlen($a)-1)];
    return str_shuffle($p);
}

function _staffSendCredentials(string $toEmail, string $name, string $password, int $orgId, string $action): void
{
    try {
        require_once __DIR__ . '/../../includes/mailer.php';
        $loginUrl   = APP_URL . '/auth/login.php';
        $actionLine = $action === 'reset'
            ? "<p>Your portal password has been reset by an administrator.</p>"
            : "<p>A portal account has been created for you on <strong>" . APP_NAME . "</strong>.</p>";
        $body = "
            <div style='font-family:sans-serif;max-width:520px;margin:auto'>
              <h2 style='color:#e74c3c'>Your Staff Portal Credentials</h2>
              <p>Dear <strong>{$name}</strong>,</p>{$actionLine}
              <table style='width:100%;border-collapse:collapse;margin:20px 0;background:#f9f9f9;border-radius:8px;overflow:hidden'>
                <tr><td style='padding:12px 16px;color:#555;font-size:.9rem;width:38%'>Login URL</td>
                    <td style='padding:12px 16px;font-weight:700'><a href='{$loginUrl}'>{$loginUrl}</a></td></tr>
                <tr style='background:#fff'><td style='padding:12px 16px;color:#555;font-size:.9rem'>Email (Username)</td>
                    <td style='padding:12px 16px;font-weight:700'>{$toEmail}</td></tr>
                <tr><td style='padding:12px 16px;color:#555;font-size:.9rem'>Temporary Password</td>
                    <td style='padding:12px 16px;font-weight:700;font-family:monospace;font-size:1.1rem;letter-spacing:2px;color:#e74c3c'>{$password}</td></tr>
              </table>
              <p style='color:#e74c3c;font-size:.85rem'><strong>Important:</strong> Please log in and change this password immediately.</p>
              <div style='text-align:center;margin:24px 0'>
                <a href='{$loginUrl}' style='background:#e74c3c;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>Access Your Portal →</a>
              </div>
            </div>";
        orgMailer($orgId)->send($toEmail, APP_NAME . ' — Your Staff Portal Credentials', $body);
    } catch (Throwable $e) {}
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// Ensure qualification column + cashier/radiologist in ENUM exist
try { $pdo->exec("ALTER TABLE health_staff ADD COLUMN IF NOT EXISTS qualification VARCHAR(100) DEFAULT NULL AFTER role"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE health_staff MODIFY COLUMN role ENUM('lab_technician','pharmacist','receptionist','cashier','radiologist','admin','nurse','other') NOT NULL DEFAULT 'other'"); } catch (Throwable $e) {}

// One-time credentials flash
$flashCreds = null;
if (!empty($_SESSION['_staff_creds'])) { $flashCreds = $_SESSION['_staff_creds']; unset($_SESSION['_staff_creds']); }

// ── Active role filter tab ────────────────────────────────────────
$allowedRoles = ['lab_technician','pharmacist','receptionist','cashier','radiologist','admin','other',''];
$activeRole   = in_array($_GET['role'] ?? '', $allowedRoles) ? ($_GET['role'] ?? '') : '';

// ── Staff list ────────────────────────────────────────────────────
$staffList = [];
try {
    $where  = "s.org_id=? AND s.role NOT IN ('nurse')";
    $params = [$orgId];
    if ($activeRole !== '') { $where .= ' AND s.role=?'; $params[] = $activeRole; }

    $sq = $pdo->prepare("
        SELECT s.*,
               u.email AS account_email, u.status AS account_status,
               mr.role_key AS module_role
        FROM health_staff s
        LEFT JOIN users u  ON s.user_id = u.id
        LEFT JOIN user_module_roles mr ON mr.user_id=s.user_id AND mr.module_slug='health'
        WHERE {$where}
        ORDER BY s.role, s.first_name
    ");
    $sq->execute($params);
    $staffList = $sq->fetchAll();
} catch (Throwable $e) {}

// ── Role counts for tabs ──────────────────────────────────────────
$roleCounts = [];
try {
    $rc = $pdo->prepare("SELECT role, COUNT(*) AS cnt FROM health_staff WHERE org_id=? AND role NOT IN ('nurse') GROUP BY role");
    $rc->execute([$orgId]);
    foreach ($rc->fetchAll() as $r) $roleCounts[$r['role']] = (int)$r['cnt'];
} catch (Throwable $e) {}

$totalStaff   = array_sum($roleCounts);
$activeCount  = 0; $withPortal = 0;
try {
    $ac = $pdo->prepare("SELECT COUNT(*) FROM health_staff WHERE org_id=? AND status='active' AND role NOT IN ('nurse')");
    $ac->execute([$orgId]); $activeCount = (int)$ac->fetchColumn();
    $wc = $pdo->prepare("SELECT COUNT(*) FROM health_staff WHERE org_id=? AND user_id IS NOT NULL AND role NOT IN ('nurse')");
    $wc->execute([$orgId]); $withPortal = (int)$wc->fetchColumn();
} catch (Throwable $e) {}

// Role display config
$roleConfig = [
    'lab_technician' => ['Lab Technicians',  'fas fa-flask',          'warning', 'lab_technician',  'e.g. Diploma in Medical Lab Sciences'],
    'pharmacist'     => ['Pharmacists',       'fas fa-pills',          'success', 'pharmacist',      'e.g. Bachelor of Pharmacy (B.Pharm)'],
    'receptionist'   => ['Receptionists',     'fas fa-concierge-bell', 'info',    'receptionist',    'e.g. Certificate in Front Office Management'],
    'cashier'        => ['Cashiers',          'fas fa-cash-register',  'dark',    'cashier',         'e.g. Certificate in Accounting'],
    'radiologist'    => ['Radiologists',      'fas fa-x-ray',          'primary', 'nurse',           'e.g. Diploma in Radiography'],
    'admin'          => ['Administrative',    'fas fa-user-tie',       'secondary','receptionist',   'e.g. Diploma in Business Administration'],
    'other'          => ['Other',             'fas fa-user-cog',       'secondary','receptionist',   ''],
];

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><i class="fas fa-id-badge me-2" style="color:<?= $moduleColor ?>"></i>Clinical Staff Registry</h4>
    <p class="text-muted mb-0 small">Register lab technicians, pharmacists, receptionists, cashiers &amp; more — each with portal login access</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#staffModal" onclick="openAddStaff('<?= e($activeRole ?: 'lab_technician') ?>')">
    <i class="fas fa-plus me-2"></i>Register Staff
  </button>
</div>

<?php flash(); ?>

<?php if ($flashCreds): ?>
<!-- ── Credentials Banner ─────────────────────────────────────── -->
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
            <input type="text" class="form-control font-monospace" id="scred_url" value="<?= e($flashCreds['login_url']) ?>" readonly>
            <button class="btn btn-outline-secondary" onclick="sCopyField('scred_url')"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div class="col-md-4">
          <label class="text-muted small fw-semibold d-block mb-1">Email (Username)</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace" id="scred_email" value="<?= e($flashCreds['email']) ?>" readonly>
            <button class="btn btn-outline-secondary" onclick="sCopyField('scred_email')"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div class="col-md-4">
          <label class="text-muted small fw-semibold d-block mb-1">Temporary Password</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace fw-bold" id="scred_pass" value="<?= e($flashCreds['password']) ?>" readonly style="letter-spacing:2px">
            <button class="btn btn-outline-secondary" onclick="sCopyField('scred_pass')"><i class="fas fa-copy"></i></button>
          </div>
        </div>
      </div>
      <div class="small text-muted">
        <i class="fas fa-envelope me-1"></i>Credentials emailed to <strong><?= e($flashCreds['email']) ?></strong>.
        &nbsp;<i class="fas fa-exclamation-triangle text-warning me-1"></i>Staff should change password on first login.
      </div>
    </div>
    <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#fde8e8"><i class="fas fa-id-badge" style="color:#e74c3c;font-size:1.3rem"></i></div>
        <div><div class="fs-3 fw-bold lh-1"><?= $totalStaff ?></div><div class="text-muted small">Total Staff</div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#d4edda"><i class="fas fa-check-circle" style="color:#27ae60;font-size:1.3rem"></i></div>
        <div><div class="fs-3 fw-bold lh-1 text-success"><?= $activeCount ?></div><div class="text-muted small">Active</div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#cce5ff"><i class="fas fa-lock-open" style="color:#3498db;font-size:1.3rem"></i></div>
        <div><div class="fs-3 fw-bold lh-1 text-primary"><?= $withPortal ?></div><div class="text-muted small">With Portal Login</div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#fff3cd"><i class="fas fa-flask" style="color:#f39c12;font-size:1.3rem"></i></div>
        <div><div class="fs-3 fw-bold lh-1"><?= $roleCounts['lab_technician'] ?? 0 ?></div><div class="text-muted small">Lab Technicians</div></div>
      </div>
    </div>
  </div>
</div>

<!-- Role Tabs -->
<ul class="nav nav-tabs mb-3 flex-wrap">
  <li class="nav-item">
    <a class="nav-link <?= $activeRole===''?'active':'' ?>" href="?role=">
      <i class="fas fa-list me-1"></i>All Staff
      <?php if ($totalStaff): ?><span class="badge bg-secondary ms-1"><?= $totalStaff ?></span><?php endif; ?>
    </a>
  </li>
  <?php foreach ($roleConfig as $rKey => [$rLabel, $rIcon, $rBadge]):
    $cnt = $roleCounts[$rKey] ?? 0;
  ?>
  <li class="nav-item">
    <a class="nav-link <?= $activeRole===$rKey?'active':'' ?>" href="?role=<?= $rKey ?>">
      <i class="<?= $rIcon ?> me-1"></i><?= $rLabel ?>
      <?php if ($cnt): ?><span class="badge bg-<?= $rBadge ?> ms-1"><?= $cnt ?></span><?php endif; ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- Staff Table -->
<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Staff No</th>
            <th>Name</th>
            <th>Role</th>
            <th>Qualification / Dept</th>
            <th>Contact</th>
            <th>Portal Account</th>
            <th>Module Role</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($staffList)): ?>
          <tr><td colspan="9" class="text-center py-5 text-muted">
            <i class="fas fa-id-badge fa-2x mb-2 d-block opacity-25"></i>
            No staff registered<?= $activeRole ? ' for this role' : '' ?> yet. Click <strong>Register Staff</strong> to begin.
          </td></tr>
          <?php else: foreach ($staffList as $s):
            [$rLabel, $rIcon, $rBadge] = $roleConfig[$s['role']] ?? [$s['role'], 'fas fa-user', 'secondary'];
            $mRoleLabels = ['lab_technician'=>['Lab Tech','warning'],'pharmacist'=>['Pharmacist','success'],'receptionist'=>['Receptionist','info'],'cashier'=>['Cashier','dark'],'nurse'=>['Nurse','primary'],'admin'=>['Admin','secondary']];
            [$mLabel, $mBg] = $mRoleLabels[$s['module_role'] ?? ''] ?? ['—','light text-dark'];
          ?>
          <tr>
            <td class="small font-monospace text-muted"><?= e($s['staff_no'] ?? '—') ?></td>
            <td class="fw-semibold"><?= e($s['first_name'].' '.$s['last_name']) ?></td>
            <td><span class="badge bg-<?= $rBadge ?>"><i class="<?= $rIcon ?> me-1"></i><?= $rLabel ?></span></td>
            <td>
              <?php if ($s['qualification']): ?><div class="small fw-semibold"><?= e($s['qualification']) ?></div><?php endif; ?>
              <?php if ($s['department']): ?><div class="small text-muted"><?= e($s['department']) ?></div><?php endif; ?>
              <?php if (!$s['qualification'] && !$s['department']): ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
            <td>
              <div class="small"><i class="fas fa-phone text-muted me-1"></i><?= e($s['phone'] ?: '—') ?></div>
              <div class="small text-muted"><i class="fas fa-envelope me-1"></i><?= e($s['email'] ?: '—') ?></div>
            </td>
            <td>
              <?php if ($s['user_id']): ?>
                <div class="small"><span class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i>Active</span>
                <span class="badge bg-<?= $s['account_status']==='active'?'success':'secondary' ?> ms-1"><?= ucfirst($s['account_status'] ?? '') ?></span></div>
                <div class="text-muted small font-monospace"><?= e($s['account_email'] ?: $s['email']) ?></div>
              <?php else: ?>
                <span class="text-muted small"><i class="fas fa-times-circle me-1 text-danger"></i>No login account</span>
              <?php endif; ?>
            </td>
            <td><?php if ($s['module_role']): ?><span class="badge bg-<?= $mBg ?>"><?= $mLabel ?></span><?php else: ?><span class="text-muted small">—</span><?php endif; ?></td>
            <td>
              <?php $stBg = match($s['status']) {'active'=>'success','on_leave'=>'warning',default=>'secondary'}; ?>
              <span class="badge bg-<?= $stBg ?>"><?= ucwords(str_replace('_',' ',$s['status'])) ?></span>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEditStaff(<?= $s['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <?php if ($s['user_id']): ?>
                <button class="btn btn-outline-warning" onclick="resetStaffPassword(<?= $s['id'] ?>,'<?= e($s['first_name'].' '.$s['last_name']) ?>')" title="Reset Password"><i class="fas fa-key"></i></button>
                <?php endif; ?>
                <button class="btn btn-outline-danger" onclick="delStaff(<?= $s['id'] ?>,'<?= e($s['first_name'].' '.$s['last_name']) ?>','<?= e($s['role']) ?>')" title="Remove"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>


<!-- ── Register / Edit Staff Modal ───────────────────────────────── -->
<div class="modal fade" id="staffModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save_staff"><input type="hidden" name="id" id="staffId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="staffModalTitle"><i class="fas fa-id-badge me-2"></i>Register Clinical Staff</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <h6 class="fw-bold mb-3"><i class="fas fa-id-card me-2 text-muted"></i>Personal &amp; Professional Details</h6>
        <div class="row g-3 mb-4">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Staff Role <span class="text-danger">*</span></label>
            <select name="role" id="staffRole" class="form-select" onchange="updateModuleRole(this.value)">
              <option value="lab_technician">Lab Technician</option>
              <option value="pharmacist">Pharmacist</option>
              <option value="receptionist">Receptionist</option>
              <option value="cashier">Cashier</option>
              <option value="radiologist">Radiologist</option>
              <option value="admin">Administrative</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
            <input type="text" name="first_name" id="staffFirst" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
            <input type="text" name="last_name" id="staffLast" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Qualification</label>
            <input type="text" name="qualification" id="staffQual" class="form-control" id="staffQualHint" placeholder="e.g. Diploma in Medical Lab Sciences">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Department / Ward</label>
            <input type="text" name="department" id="staffDept" class="form-control" placeholder="e.g. Main Laboratory, ICU, OPD">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label>
            <input type="tel" name="phone" id="staffPhone" class="form-control" required placeholder="+254 700 111 222">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Official Email <span class="text-danger">*</span></label>
            <input type="email" name="email" id="staffEmail" class="form-control" required placeholder="staff@clinic.com">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="staffStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="on_leave">On Leave</option>
            </select>
          </div>
        </div>

        <!-- Portal Account -->
        <hr class="my-3">
        <h6 class="fw-bold mb-3"><i class="fas fa-lock me-2 text-muted"></i>Portal Login Account</h6>

        <div id="staffCreateBlock">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="create_account" id="staffAccToggle" value="1" checked onchange="toggleStaffAccOpts()">
            <label class="form-check-label fw-semibold" for="staffAccToggle">Create a portal login account for this staff member</label>
          </div>
          <div id="staffAccOptions" class="p-3 rounded border" style="background:#f8f9fa">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Module Role (Access Level)</label>
                <select name="module_role" id="staffModuleRole" class="form-select">
                  <option value="lab_technician">Lab Technician — laboratory only</option>
                  <option value="pharmacist">Pharmacist — pharmacy &amp; prescriptions</option>
                  <option value="receptionist">Receptionist — appointments &amp; patients</option>
                  <option value="cashier">Cashier — billing only</option>
                  <option value="nurse">Nurse — nursing notes &amp; admissions</option>
                  <option value="admin">Admin — full health module</option>
                </select>
              </div>
              <div class="col-md-6 d-flex align-items-end">
                <div class="alert alert-info py-2 px-3 mb-0 small w-100">
                  <i class="fas fa-info-circle me-1"></i>A secure password is generated and emailed to the staff member.
                </div>
              </div>
            </div>
          </div>
        </div>

        <div id="staffExistingBlock" class="d-none">
          <div class="alert alert-success py-2 px-3 small">
            <i class="fas fa-check-circle me-2"></i>
            Active portal account: <strong id="staffExistingEmail"></strong>
            — use <strong>Reset Password</strong> in the table to issue new credentials.
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i><span id="staffSubmitLabel">Register Staff</span></button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- Hidden forms -->
<form method="POST" id="staffResetForm" style="display:none">
  <?= csrfField() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="staff_id" id="staffResetId">
</form>
<form method="POST" id="staffDelForm" style="display:none">
  <?= csrfField() ?><input type="hidden" name="action" value="delete_staff"><input type="hidden" name="id" id="staffDelId"><input type="hidden" name="role" id="staffDelRole">
</form>

<?php
$extraJs = <<<'JS'
<script>
const ROLE_MODULE_MAP = {
  lab_technician: 'lab_technician',
  pharmacist:     'pharmacist',
  receptionist:   'receptionist',
  cashier:        'cashier',
  radiologist:    'nurse',
  admin:          'admin',
  other:          'receptionist',
};
const ROLE_QUAL_HINT = {
  lab_technician: 'e.g. Diploma in Medical Lab Sciences',
  pharmacist:     'e.g. Bachelor of Pharmacy (B.Pharm)',
  receptionist:   'e.g. Certificate in Front Office Management',
  cashier:        'e.g. Certificate in Accounting',
  radiologist:    'e.g. Diploma in Radiography',
  admin:          'e.g. Diploma in Business Administration',
  other:          '',
};

function openAddStaff(defaultRole) {
  document.getElementById('staffModalTitle').innerHTML = '<i class="fas fa-id-badge me-2"></i>Register Clinical Staff';
  document.getElementById('staffSubmitLabel').textContent = 'Register Staff';
  document.getElementById('staffId').value     = '0';
  document.getElementById('staffFirst').value  = '';
  document.getElementById('staffLast').value   = '';
  document.getElementById('staffRole').value   = defaultRole || 'lab_technician';
  document.getElementById('staffQual').value   = '';
  document.getElementById('staffQual').placeholder = ROLE_QUAL_HINT[defaultRole || 'lab_technician'] || '';
  document.getElementById('staffDept').value   = '';
  document.getElementById('staffPhone').value  = '';
  document.getElementById('staffEmail').value  = '';
  document.getElementById('staffStatus').value = 'active';
  document.getElementById('staffModuleRole').value = ROLE_MODULE_MAP[defaultRole] || 'receptionist';

  const toggle = document.getElementById('staffAccToggle');
  toggle.checked  = true;
  toggle.disabled = false;
  document.getElementById('staffCreateBlock').classList.remove('d-none');
  document.getElementById('staffExistingBlock').classList.add('d-none');
  document.getElementById('staffAccOptions').style.display = '';
}

function openEditStaff(id) {
  fetch('staff.php?fetch=' + id).then(r => r.json()).then(d => {
    document.getElementById('staffModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Staff Details';
    document.getElementById('staffSubmitLabel').textContent = 'Save Changes';
    document.getElementById('staffId').value     = d.id;
    document.getElementById('staffFirst').value  = d.first_name     || '';
    document.getElementById('staffLast').value   = d.last_name      || '';
    document.getElementById('staffRole').value   = d.role           || 'other';
    document.getElementById('staffQual').value   = d.qualification  || '';
    document.getElementById('staffDept').value   = d.department     || '';
    document.getElementById('staffPhone').value  = d.phone          || '';
    document.getElementById('staffEmail').value  = d.email          || '';
    document.getElementById('staffStatus').value = d.status         || 'active';

    const toggle = document.getElementById('staffAccToggle');
    if (d.user_id) {
      toggle.checked  = false;
      toggle.disabled = true;
      document.getElementById('staffCreateBlock').classList.add('d-none');
      document.getElementById('staffExistingBlock').classList.remove('d-none');
      document.getElementById('staffExistingEmail').textContent = d.account_email || d.email || '';
    } else {
      toggle.checked  = false;
      toggle.disabled = false;
      document.getElementById('staffAccOptions').style.display = 'none';
      document.getElementById('staffCreateBlock').classList.remove('d-none');
      document.getElementById('staffExistingBlock').classList.add('d-none');
    }
    new bootstrap.Modal(document.getElementById('staffModal')).show();
  });
}

function updateModuleRole(role) {
  document.getElementById('staffModuleRole').value = ROLE_MODULE_MAP[role] || 'receptionist';
  document.getElementById('staffQual').placeholder = ROLE_QUAL_HINT[role] || '';
}

function toggleStaffAccOpts() {
  document.getElementById('staffAccOptions').style.display =
    document.getElementById('staffAccToggle').checked ? '' : 'none';
}

function resetStaffPassword(staffId, name) {
  Swal.fire({
    title: 'Reset Portal Password?',
    html: 'A new password will be generated for <strong>' + name + '</strong> and sent to their email.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e67e22', confirmButtonText: 'Yes, Reset'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('staffResetId').value = staffId;
      document.getElementById('staffResetForm').submit();
    }
  });
}

function delStaff(id, name, role) {
  Swal.fire({
    title: 'Remove Staff?', text: 'Remove ' + name + ' from the clinical registry?',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#e74c3c', confirmButtonText: 'Yes, Remove'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('staffDelId').value   = id;
      document.getElementById('staffDelRole').value = role;
      document.getElementById('staffDelForm').submit();
    }
  });
}

function sCopyField(id) {
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
