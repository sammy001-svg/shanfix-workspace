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

// ── AJAX: fetch nurse ─────────────────────────────────────────────
if (isset($_GET['fetch_nurse'])) {
    session_start();
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    requireLogin();
    $orgId = (int)currentUser()['org_id'];
    $id    = (int)$_GET['fetch_nurse'];
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("
            SELECT n.*, u.email AS account_email, u.status AS account_status
            FROM health_nurses n
            LEFT JOIN users u ON n.user_id = u.id
            WHERE n.id=? AND n.org_id=?
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

    // ── Save nursing note ─────────────────────────────────────────
    if ($action === 'save_note') {
        $id        = (int)($_POST['id']           ?? 0);
        $patientId = (int)($_POST['patient_id']   ?? 0);
        $admId     = (int)($_POST['admission_id'] ?? 0) ?: null;
        $noteType  = in_array($_POST['note_type'] ?? '', ['general','shift_handover','care_plan','observation','incident']) ? $_POST['note_type'] : 'general';
        $shift     = in_array($_POST['shift'] ?? '', ['morning','afternoon','night','']) ? ($_POST['shift'] ?: null) : null;
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
        $id        = (int)($_POST['id']            ?? 0);
        $patientId = (int)($_POST['patient_id']    ?? 0);
        $admId     = (int)($_POST['admission_id']  ?? 0);
        $medId     = (int)($_POST['medicine_id']   ?? 0);
        $medName   = sanitize($_POST['medicine_name'] ?? '');
        $dose      = sanitize($_POST['dose']       ?? '');
        $route     = in_array($_POST['route'] ?? '', ['oral','iv','im','sc','topical','inhaled','other']) ? $_POST['route'] : 'oral';
        $frequency = sanitize($_POST['frequency']  ?? '');
        $startDate = $_POST['start_date']          ?? date('Y-m-d');
        $endDate   = $_POST['end_date']            ?? null ?: null;
        $orderedBy = (int)($_POST['ordered_by']    ?? 0) ?: null;
        $notes     = sanitize($_POST['notes']      ?? '');

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

    // ── Register / update a nurse ─────────────────────────────────
    if ($action === 'save_nurse') {
        $id            = (int)($_POST['id']              ?? 0);
        $firstName     = sanitize($_POST['first_name']   ?? '');
        $lastName      = sanitize($_POST['last_name']    ?? '');
        $qualification = sanitize($_POST['qualification']?? 'Registered Nurse');
        $department    = sanitize($_POST['department']   ?? '');
        $phone         = sanitize($_POST['phone']        ?? '');
        $email         = trim(strtolower($_POST['email'] ?? ''));
        $status        = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        $createAccount = !empty($_POST['create_account']);
        $moduleRole    = in_array($_POST['module_role'] ?? '', ['nurse','receptionist','lab_technician','pharmacist','cashier']) ? $_POST['module_role'] : 'nurse';

        if (!$firstName || !$lastName) {
            setFlash('error', 'First and last name are required.');
            redirect('nursing.php?tab=staff');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE health_nurses SET first_name=?,last_name=?,qualification=?,department=?,phone=?,email=?,status=? WHERE id=? AND org_id=?")
                ->execute([$firstName,$lastName,$qualification,$department,$phone,$email,$status,$id,$orgId]);

            // Sync linked user account
            $linked = $pdo->prepare("SELECT user_id FROM health_nurses WHERE id=? AND org_id=?");
            $linked->execute([$id, $orgId]);
            $existingUserId = (int)($linked->fetchColumn() ?? 0);
            if ($existingUserId) {
                $pdo->prepare("UPDATE users SET name=?,phone=?,email=? WHERE id=? AND org_id=?")
                    ->execute([$firstName.' '.$lastName, $phone, $email, $existingUserId, $orgId]);
            }

            // Grant account if nurse doesn't have one yet and checkbox is checked
            if ($createAccount && !$existingUserId && $email) {
                $newUid = _nurseCreateAccount($pdo, $orgId, $uid, $firstName, $lastName, $email, $phone, $moduleRole);
                if (is_int($newUid) && $newUid > 0) {
                    $pdo->prepare("UPDATE health_nurses SET user_id=? WHERE id=? AND org_id=?")
                        ->execute([$newUid, $id, $orgId]);
                }
            }

            setFlash('success', "{$firstName} {$lastName} updated.");
            logActivity('update', 'health', "Nurse updated: $firstName $lastName");
            redirect('nursing.php?tab=staff');
        }

        // New nurse
        $linkedUserId = null;
        if ($createAccount && $email) {
            $result = _nurseCreateAccount($pdo, $orgId, $uid, $firstName, $lastName, $email, $phone, $moduleRole);
            if (is_string($result)) {
                setFlash('error', $result);
                redirect('nursing.php?tab=staff');
            }
            $linkedUserId = $result;
        }

        $pdo->prepare("INSERT INTO health_nurses (org_id,user_id,first_name,last_name,qualification,department,phone,email,status) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$orgId,$linkedUserId,$firstName,$lastName,$qualification,$department,$phone,$email,$status]);

        setFlash('success', "{$firstName} {$lastName} registered." . ($linkedUserId ? ' Portal login created.' : ''));
        logActivity('create', 'health', "Nurse registered: $firstName $lastName");
        redirect('nursing.php?tab=staff');
    }

    // ── Reset nurse portal password ───────────────────────────────
    if ($action === 'reset_nurse_password') {
        $nurseId = (int)($_POST['nurse_id'] ?? 0);
        $row     = $pdo->prepare("SELECT n.user_id, n.first_name, n.last_name, n.email, n.phone FROM health_nurses n WHERE n.id=? AND n.org_id=?");
        $row->execute([$nurseId, $orgId]);
        $nurse = $row->fetch();

        if (!$nurse || !$nurse['user_id']) {
            setFlash('error', 'This nurse has no portal account to reset.');
            redirect('nursing.php?tab=staff');
        }

        $newPass = _nurseGenPassword();
        $hash    = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE users SET password=? WHERE id=? AND org_id=?")->execute([$hash, $nurse['user_id'], $orgId]);

        $_SESSION['_nurse_creds'] = [
            'name'      => $nurse['first_name'] . ' ' . $nurse['last_name'],
            'email'     => $nurse['email'],
            'password'  => $newPass,
            'login_url' => APP_URL . '/auth/login.php',
            'action'    => 'reset',
        ];

        _nurseSendCredentials($nurse['email'], $nurse['first_name'].' '.$nurse['last_name'], $newPass, $orgId, 'reset');
        if (!empty($nurse['phone'])) {
            notifySms($nurse['phone'], APP_NAME . ": Hi {$nurse['first_name']}, your portal password has been reset. New password: {$newPass} — Login: " . APP_URL . '/auth/login.php', $orgId, 'nurse_password_reset');
        }

        logActivity('reset_password', 'health', "Password reset for nurse #{$nurseId}");
        setFlash('success', 'Password reset. New credentials shown below.');
        redirect('nursing.php?tab=staff');
    }

    // ── Delete nurse ──────────────────────────────────────────────
    if ($action === 'delete_nurse') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM health_nurses WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Nurse removed from registry.');
        logActivity('delete', 'health', "Nurse removed: #$id");
        redirect('nursing.php?tab=staff');
    }
}

// ── Nurse account helpers ─────────────────────────────────────────
function _nurseCreateAccount(PDO $pdo, int $orgId, int $grantedBy, string $firstName, string $lastName, string $email, string $phone, string $moduleRole): int|string
{
    $chk = $pdo->prepare("SELECT id, org_id FROM users WHERE email=?");
    $chk->execute([$email]);
    $existing = $chk->fetch();

    if ($existing) {
        if ((int)$existing['org_id'] === $orgId) {
            _nurseGrantAccess($pdo, (int)$existing['id'], $orgId, $grantedBy, $moduleRole);
            return (int)$existing['id'];
        }
        return "The email {$email} is already registered in another organization. Use a different email.";
    }

    $plain = _nurseGenPassword();
    $hash  = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);

    $pdo->prepare("INSERT INTO users (org_id,name,email,password,phone,role,status) VALUES (?,?,?,?,?,'staff','active')")
        ->execute([$orgId, $firstName.' '.$lastName, $email, $hash, $phone]);
    $newUserId = (int)$pdo->lastInsertId();

    _nurseGrantAccess($pdo, $newUserId, $orgId, $grantedBy, $moduleRole);

    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['_nurse_creds'] = [
        'name'      => $firstName . ' ' . $lastName,
        'email'     => $email,
        'password'  => $plain,
        'login_url' => APP_URL . '/auth/login.php',
        'action'    => 'created',
    ];

    _nurseSendCredentials($email, $firstName.' '.$lastName, $plain, $orgId, 'created');

    if ($phone) {
        notifySms($phone, APP_NAME . ": Welcome {$firstName}! Your portal login — Email: {$email} | Password: {$plain} | URL: " . APP_URL . '/auth/login.php', $orgId, 'nurse_welcome');
    }

    return $newUserId;
}

function _nurseGrantAccess(PDO $pdo, int $userId, int $orgId, int $grantedBy, string $roleKey): void
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

function _nurseGenPassword(): string
{
    $u = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $l = 'abcdefghjkmnpqrstuvwxyz';
    $d = '23456789';
    $s = '@#!$';
    $a = $u . $l . $d . $s;
    $p = $u[random_int(0, strlen($u)-1)]
       . $d[random_int(0, strlen($d)-1)]
       . $s[random_int(0, strlen($s)-1)];
    for ($i = 0; $i < 7; $i++) $p .= $a[random_int(0, strlen($a)-1)];
    return str_shuffle($p);
}

function _nurseSendCredentials(string $toEmail, string $name, string $password, int $orgId, string $action): void
{
    try {
        require_once __DIR__ . '/../../includes/mailer.php';
        $loginUrl   = APP_URL . '/auth/login.php';
        $actionLine = $action === 'reset'
            ? "<p>Your portal password has been reset by an administrator.</p>"
            : "<p>A portal account has been created for you on <strong>" . APP_NAME . "</strong>.</p>";
        $body = "
            <div style='font-family:sans-serif;max-width:520px;margin:auto'>
              <h2 style='color:#e74c3c'>Your Nursing Portal Credentials</h2>
              <p>Dear <strong>{$name}</strong>,</p>
              {$actionLine}
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
                <a href='{$loginUrl}' style='background:#e74c3c;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
                  Access Your Portal →
                </a>
              </div>
            </div>";
        orgMailer($orgId)->send($toEmail, APP_NAME . ' — Your Nursing Portal Credentials', $body);
    } catch (Throwable $e) {}
}

// ── Page setup ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$tab   = in_array($_GET['tab'] ?? '', ['notes','mar','staff']) ? $_GET['tab'] : 'notes';

// ── Auto-create health_nurses table ──────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_nurses` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `org_id`        INT UNSIGNED NOT NULL,
        `user_id`       INT UNSIGNED DEFAULT NULL,
        `first_name`    VARCHAR(80)  NOT NULL,
        `last_name`     VARCHAR(80)  NOT NULL,
        `qualification` VARCHAR(100) NOT NULL DEFAULT 'Registered Nurse',
        `department`    VARCHAR(100) DEFAULT NULL,
        `phone`         VARCHAR(30)  DEFAULT NULL,
        `email`         VARCHAR(150) DEFAULT NULL,
        `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
        `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_hn_org (org_id),
        INDEX idx_hn_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// ── One-time nurse credentials from session ───────────────────────
$flashNurseCreds = null;
if (!empty($_SESSION['_nurse_creds'])) {
    $flashNurseCreds = $_SESSION['_nurse_creds'];
    unset($_SESSION['_nurse_creds']);
}

// ── Shared lookup data ────────────────────────────────────────────
$patients = [];
try {
    $pq = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, patient_no FROM health_patients WHERE org_id=? AND status='active' ORDER BY first_name");
    $pq->execute([$orgId]);
    $patients = $pq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$activeAdmissions = [];
try {
    $aq = $pdo->prepare("SELECT a.id,a.admission_no,CONCAT(p.first_name,' ',p.last_name) AS patient_name,a.patient_id FROM health_admissions a LEFT JOIN health_patients p ON p.id=a.patient_id WHERE a.org_id=? AND a.status='admitted' ORDER BY p.first_name");
    $aq->execute([$orgId]);
    $activeAdmissions = $aq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$medicines = [];
try {
    $mq = $pdo->prepare("SELECT id, name, form, strength FROM health_medicines WHERE org_id=? AND status='active' ORDER BY name");
    $mq->execute([$orgId]);
    $medicines = $mq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── Notes data ────────────────────────────────────────────────────
$notes = [];
$todayNotes = $activeMar = $pendingMar = 0;
if ($tab === 'notes' || $tab !== 'staff') {
    $filterPid      = (int)($_GET['patient_id'] ?? 0);
    $filterNoteType = sanitize($_GET['note_type'] ?? '');
    $filterDate     = sanitize($_GET['date']      ?? '');
    $filterShift    = sanitize($_GET['shift']     ?? '');

    $nWhere = "n.org_id=?"; $nParams = [$orgId];
    if ($filterPid)      { $nWhere .= " AND n.patient_id=?";       $nParams[] = $filterPid; }
    if ($filterNoteType) { $nWhere .= " AND n.note_type=?";        $nParams[] = $filterNoteType; }
    if ($filterDate)     { $nWhere .= " AND DATE(n.created_at)=?"; $nParams[] = $filterDate; }
    if ($filterShift)    { $nWhere .= " AND n.shift=?";            $nParams[] = $filterShift; }

    try {
        $ns = $pdo->prepare("SELECT n.*,CONCAT(p.first_name,' ',p.last_name) AS patient_name,p.patient_no,u.name AS nurse_name,a.admission_no FROM health_nursing_notes n LEFT JOIN health_patients p ON p.id=n.patient_id LEFT JOIN users u ON u.id=n.nurse_id LEFT JOIN health_admissions a ON a.id=n.admission_id WHERE {$nWhere} ORDER BY n.created_at DESC LIMIT 200");
        $ns->execute($nParams);
        $notes = $ns->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    try { $r = $pdo->prepare("SELECT COUNT(*) FROM health_nursing_notes WHERE org_id=? AND DATE(created_at)=CURDATE()"); $r->execute([$orgId]); $todayNotes = (int)$r->fetchColumn(); } catch (Throwable $e) {}
    try { $r = $pdo->prepare("SELECT COUNT(*) FROM health_mar WHERE org_id=? AND status='active'"); $r->execute([$orgId]); $activeMar = (int)$r->fetchColumn(); } catch (Throwable $e) {}
    try { $r = $pdo->prepare("SELECT COUNT(*) FROM health_mar WHERE org_id=? AND status='active' AND (administered_at IS NULL OR DATE(administered_at)<CURDATE())"); $r->execute([$orgId]); $pendingMar = (int)$r->fetchColumn(); } catch (Throwable $e) {}
}

// ── MAR data ──────────────────────────────────────────────────────
$marRecords = [];
if ($tab === 'mar') {
    $marAdmId = (int)($_GET['mar_admission'] ?? 0);
    $mWhere   = "m.org_id=?"; $mParams = [$orgId];
    if ($marAdmId) { $mWhere .= " AND m.admission_id=?"; $mParams[] = $marAdmId; }
    try {
        $ms = $pdo->prepare("SELECT m.*,CONCAT(p.first_name,' ',p.last_name) AS patient_name,a.admission_no,adm_by.name AS administered_by_name,ord_by.name AS ordered_by_name FROM health_mar m LEFT JOIN health_patients p ON p.id=m.patient_id LEFT JOIN health_admissions a ON a.id=m.admission_id LEFT JOIN users adm_by ON adm_by.id=m.administered_by LEFT JOIN users ord_by ON ord_by.id=m.ordered_by WHERE {$mWhere} ORDER BY m.status='active' DESC,m.start_date DESC LIMIT 200");
        $ms->execute($mParams);
        $marRecords = $ms->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// ── Nursing Staff data ────────────────────────────────────────────
$nursesList = [];
$totalNurses = $activeNurses = $withAccounts = 0;
if ($tab === 'staff') {
    try {
        $nsq = $pdo->prepare("
            SELECT n.*,
                   u.email AS account_email, u.status AS account_status,
                   mr.role_key AS module_role
            FROM health_nurses n
            LEFT JOIN users u ON n.user_id = u.id
            LEFT JOIN user_module_roles mr ON mr.user_id=n.user_id AND mr.module_slug='health'
            WHERE n.org_id=?
            ORDER BY n.first_name ASC
        ");
        $nsq->execute([$orgId]);
        $nursesList   = $nsq->fetchAll(PDO::FETCH_ASSOC);
        $totalNurses  = count($nursesList);
        $activeNurses = count(array_filter($nursesList, fn($n) => $n['status'] === 'active'));
        $withAccounts = count(array_filter($nursesList, fn($n) => !empty($n['user_id'])));
    } catch (Throwable $e) {}
}

$nurseRoleLabels = [
    'nurse'           => ['Nurse',           'primary'],
    'receptionist'    => ['Receptionist',    'secondary'],
    'lab_technician'  => ['Lab Technician',  'warning'],
    'pharmacist'      => ['Pharmacist',      'success'],
    'cashier'         => ['Cashier',         'dark'],
];

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="fas fa-user-nurse me-2" style="color:<?= $moduleColor ?>"></i>Nursing</h4>
    <small class="text-muted">Nursing notes, medication administration &amp; nursing staff</small>
  </div>
  <div class="d-flex gap-2">
    <?php if ($tab === 'notes'): ?>
      <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>" onclick="openNoteModal()" data-bs-toggle="modal" data-bs-target="#noteModal">
        <i class="fas fa-plus me-1"></i>Add Note
      </button>
    <?php elseif ($tab === 'mar'): ?>
      <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#marModal">
        <i class="fas fa-plus me-1"></i>Add Medication Order
      </button>
    <?php elseif ($tab === 'staff'): ?>
      <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#nurseModal" onclick="openAddNurse()">
        <i class="fas fa-plus me-1"></i>Register Nurse
      </button>
    <?php endif; ?>
  </div>
</div>

<?php flash(); ?>

<?php if ($flashNurseCreds): ?>
<!-- ── Credentials Banner (one-time display) ─────────────────── -->
<div class="alert border-0 shadow-sm mb-4" style="background:#fff8e1;border-left:4px solid #f39c12!important">
  <div class="d-flex align-items-start gap-3">
    <div class="flex-shrink-0 text-warning fs-3"><i class="fas fa-key"></i></div>
    <div class="flex-fill">
      <div class="fw-bold text-dark mb-2">
        <?= $flashNurseCreds['action'] === 'reset' ? 'Password Reset — ' : 'Portal Account Created — ' ?>
        <?= e($flashNurseCreds['name']) ?>
        <span class="badge bg-warning text-dark ms-2 small">Save these now — shown once</span>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-md-4">
          <label class="text-muted small fw-semibold d-block mb-1">Login URL</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace" id="ncred_url" value="<?= e($flashNurseCreds['login_url']) ?>" readonly>
            <button class="btn btn-outline-secondary" onclick="nCopyField('ncred_url')" title="Copy"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div class="col-md-4">
          <label class="text-muted small fw-semibold d-block mb-1">Email (Username)</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace" id="ncred_email" value="<?= e($flashNurseCreds['email']) ?>" readonly>
            <button class="btn btn-outline-secondary" onclick="nCopyField('ncred_email')" title="Copy"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div class="col-md-4">
          <label class="text-muted small fw-semibold d-block mb-1">Temporary Password</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace fw-bold" id="ncred_pass" value="<?= e($flashNurseCreds['password']) ?>" readonly style="letter-spacing:2px">
            <button class="btn btn-outline-secondary" onclick="nCopyField('ncred_pass')" title="Copy"><i class="fas fa-copy"></i></button>
          </div>
        </div>
      </div>
      <div class="small text-muted">
        <i class="fas fa-envelope me-1"></i>Credentials email sent to <strong><?= e($flashNurseCreds['email']) ?></strong>.
        &nbsp;<i class="fas fa-exclamation-triangle text-warning me-1"></i>Nurse should change password on first login.
      </div>
    </div>
    <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php endif; ?>

<!-- Stats row (notes/mar tabs) -->
<?php if ($tab !== 'staff'): ?>
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
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='notes'?'active':'' ?>" href="?tab=notes"><i class="fas fa-clipboard me-1"></i>Nursing Notes</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='mar'  ?'active':'' ?>" href="?tab=mar"><i class="fas fa-pills me-1"></i>MAR <?php if ($pendingMar): ?><span class="badge bg-warning text-dark ms-1"><?= $pendingMar ?></span><?php endif; ?></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='staff'?'active':'' ?>" href="?tab=staff"><i class="fas fa-id-badge me-1"></i>Nursing Staff <span class="badge bg-secondary ms-1"><?= $totalNurses ?></span></a></li>
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
          <option value="<?= $p['id'] ?>" <?= ($filterPid ?? 0)==$p['id']?'selected':'' ?>><?= e($p['name']) ?> (<?= e($p['patient_no']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="note_type" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Types</option>
          <?php foreach (['general'=>'General','shift_handover'=>'Shift Handover','care_plan'=>'Care Plan','observation'=>'Observation','incident'=>'Incident'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= ($filterNoteType??'')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="shift" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Shifts</option>
          <option value="morning"   <?= ($filterShift??'')==='morning'   ?'selected':'' ?>>Morning</option>
          <option value="afternoon" <?= ($filterShift??'')==='afternoon' ?'selected':'' ?>>Afternoon</option>
          <option value="night"     <?= ($filterShift??'')==='night'     ?'selected':'' ?>>Night</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($filterDate ?? '') ?>" onchange="this.form.submit()">
      </div>
      <div class="col-auto">
        <a href="?tab=notes" class="btn btn-outline-secondary btn-sm">Clear</a>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-hover table-sm align-middle data-table" id="notesTable">
        <thead class="table-light">
          <tr><th>Patient</th><th>Type</th><th>Shift</th><th>Admission</th><th>Note</th><th>By</th><th>Date</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($notes)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No nursing notes found.</td></tr>
        <?php else: foreach ($notes as $n):
          $ntBadge = match($n['note_type']) {
              'shift_handover'=>'info text-dark','care_plan'=>'primary','observation'=>'secondary','incident'=>'danger',default=>'light text-dark border'
          };
          $shiftIcon = match($n['shift'] ?? '') {
              'morning'=>'<i class="fas fa-sun text-warning"></i>','afternoon'=>'<i class="fas fa-cloud-sun text-warning"></i>','night'=>'<i class="fas fa-moon text-primary"></i>',default=>'—'
          };
        ?>
          <tr>
            <td><div class="fw-semibold"><?= e($n['patient_name']) ?></div><small class="text-muted"><?= e($n['patient_no']) ?></small></td>
            <td><span class="badge bg-<?= $ntBadge ?>"><?= ucwords(str_replace('_',' ',$n['note_type'])) ?></span></td>
            <td><?= $shiftIcon ?></td>
            <td><small><?= $n['admission_no'] ? e($n['admission_no']) : '—' ?></small></td>
            <td style="max-width:280px"><small><?= e(mb_substr($n['note_text'],0,120)) ?><?= mb_strlen($n['note_text'])>120?'…':'' ?></small></td>
            <td><small><?= e($n['nurse_name'] ?: '—') ?></small></td>
            <td><small><?= date('d M Y H:i', strtotime($n['created_at'])) ?></small></td>
            <td>
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openNoteModal(<?= $n['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-secondary" onclick="viewNote('<?= e(addslashes($n['note_text'])) ?>','<?= e(addslashes($n['patient_name'])) ?>')" title="View Full"><i class="fas fa-eye"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this note?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_note"><input type="hidden" name="id" value="<?= $n['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger"><i class="fas fa-trash"></i></button>
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

<!-- ══════════════════════════════════════════════════════════════
     TAB: MAR
════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'mar'): ?>
<div class="card shadow-sm border-0">
  <div class="card-body">
    <form class="row g-2 mb-3" method="GET">
      <input type="hidden" name="tab" value="mar">
      <div class="col-12 col-md-5">
        <select name="mar_admission" class="form-select form-select-sm select2" onchange="this.form.submit()">
          <option value="">All Admissions</option>
          <?php foreach ($activeAdmissions as $a): ?>
          <option value="<?= $a['id'] ?>" <?= ($marAdmId ?? 0)==$a['id']?'selected':'' ?>><?= e($a['admission_no']) ?> — <?= e($a['patient_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><a href="?tab=mar" class="btn btn-outline-secondary btn-sm">Clear</a></div>
    </form>

    <div class="table-responsive">
      <table class="table table-hover table-sm align-middle data-table" id="marTable">
        <thead class="table-light">
          <tr><th>Patient</th><th>Admission</th><th>Medicine</th><th>Dose/Route</th><th>Frequency</th><th>Dates</th><th>Status</th><th>Last Admin</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($marRecords)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No medication orders found.</td></tr>
        <?php else: foreach ($marRecords as $m):
          $stBadge = match($m['status']) {'active'=>'success','completed'=>'primary','discontinued'=>'secondary',default=>'light text-dark'};
          $routeLabel = ['oral'=>'PO','iv'=>'IV','im'=>'IM','sc'=>'SC','topical'=>'Top','inhaled'=>'INH','other'=>'Other'];
        ?>
          <tr>
            <td><div class="fw-semibold"><?= e($m['patient_name']) ?></div></td>
            <td><small><?= e($m['admission_no']) ?></small></td>
            <td><div class="fw-semibold"><?= e($m['medicine_name']) ?></div><small class="text-muted"><?= e($m['dose']) ?></small></td>
            <td><span class="badge bg-light text-dark border"><?= $routeLabel[$m['route']] ?? $m['route'] ?></span></td>
            <td><small><?= e($m['frequency'] ?: '—') ?></small></td>
            <td><small><?= date('d M', strtotime($m['start_date'])) ?><?php if ($m['end_date']): ?> → <?= date('d M', strtotime($m['end_date'])) ?><?php endif; ?></small></td>
            <td><span class="badge bg-<?= $stBadge ?>"><?= ucfirst($m['status']) ?></span></td>
            <td><?php if ($m['administered_at']): ?><small><?= date('d M H:i', strtotime($m['administered_at'])) ?></small><div><small class="text-muted"><?= e($m['administered_by_name'] ?? '') ?></small></div><?php else: ?><small class="text-muted">—</small><?php endif; ?></td>
            <td>
              <div class="btn-group btn-group-sm">
                <?php if ($m['status'] === 'active'): ?>
                <form method="POST" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="mar_administer"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button type="submit" class="btn btn-outline-success" title="Mark Administered"><i class="fas fa-check-double"></i></button></form>
                <button class="btn btn-outline-primary" onclick="openMarModal(<?= $m['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Discontinue?')"><?= csrfField() ?><input type="hidden" name="action" value="mar_discontinue"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button type="submit" class="btn btn-outline-danger" title="Discontinue"><i class="fas fa-ban"></i></button></form>
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

<!-- ══════════════════════════════════════════════════════════════
     TAB: NURSING STAFF
════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'staff'): ?>

<!-- Staff stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#fde8e8"><i class="fas fa-user-nurse" style="color:#e74c3c;font-size:1.3rem"></i></div>
        <div><div class="fs-3 fw-bold lh-1"><?= $totalNurses ?></div><div class="text-muted small">Total Registered</div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#d4edda"><i class="fas fa-check-circle" style="color:#27ae60;font-size:1.3rem"></i></div>
        <div><div class="fs-3 fw-bold lh-1 text-success"><?= $activeNurses ?></div><div class="text-muted small">Active</div></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#cce5ff"><i class="fas fa-lock-open" style="color:#3498db;font-size:1.3rem"></i></div>
        <div><div class="fs-3 fw-bold lh-1 text-primary"><?= $withAccounts ?></div><div class="text-muted small">With Portal Access</div></div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Nurse</th>
            <th>Qualification</th>
            <th>Department</th>
            <th>Contact</th>
            <th>Portal Account</th>
            <th>Module Role</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($nursesList)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted">
            <i class="fas fa-user-nurse fa-2x mb-2 d-block opacity-25"></i>
            No nurses registered yet. Click <strong>Register Nurse</strong> to begin.
          </td></tr>
          <?php else: foreach ($nursesList as $n):
            [$roleName, $roleBg] = $nurseRoleLabels[$n['module_role'] ?? ''] ?? ['—','light text-dark'];
          ?>
          <tr>
            <td class="fw-semibold"><?= e($n['first_name'].' '.$n['last_name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= e($n['qualification']) ?></span></td>
            <td class="small"><?= e($n['department'] ?: '—') ?></td>
            <td>
              <div class="small"><i class="fas fa-phone text-muted me-1"></i><?= e($n['phone'] ?: '—') ?></div>
              <div class="small text-muted"><i class="fas fa-envelope me-1"></i><?= e($n['email'] ?: '—') ?></div>
            </td>
            <td>
              <?php if ($n['user_id']): ?>
                <div class="small"><span class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i>Active</span> <span class="badge bg-<?= $n['account_status']==='active'?'success':'secondary' ?>"><?= ucfirst($n['account_status'] ?? 'active') ?></span></div>
                <div class="text-muted small font-monospace"><?= e($n['account_email'] ?: $n['email']) ?></div>
              <?php else: ?>
                <span class="text-muted small"><i class="fas fa-times-circle me-1 text-danger"></i>No login account</span>
              <?php endif; ?>
            </td>
            <td><?php if ($n['module_role']): ?><span class="badge bg-<?= $roleBg ?>"><?= $roleName ?></span><?php else: ?><span class="text-muted small">—</span><?php endif; ?></td>
            <td><span class="badge bg-<?= $n['status']==='active'?'success':'secondary' ?>"><?= ucfirst($n['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEditNurse(<?= $n['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <?php if ($n['user_id']): ?>
                <button class="btn btn-outline-warning" onclick="resetNursePassword(<?= $n['id'] ?>,'<?= e($n['first_name'].' '.$n['last_name']) ?>')" title="Reset Password"><i class="fas fa-key"></i></button>
                <?php endif; ?>
                <button class="btn btn-outline-danger" onclick="delNurse(<?= $n['id'] ?>,'<?= e($n['first_name'].' '.$n['last_name']) ?>')" title="Remove"><i class="fas fa-trash"></i></button>
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


<!-- ── Modal: Nursing Note ────────────────────────────────────────── -->
<div class="modal fade" id="noteModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save_note"><input type="hidden" name="id" id="noteId">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-clipboard me-2"></i><span id="noteModalTitle">Add Nursing Note</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
            <select name="patient_id" id="notePatient" class="form-select select2" required>
              <option value="">Select Patient</option>
              <?php foreach ($patients as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['patient_no']) ?>)</option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Admission (if IPD)</label>
            <select name="admission_id" id="noteAdmission" class="form-select select2">
              <option value="">OPD / No Admission</option>
              <?php foreach ($activeAdmissions as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['admission_no']) ?> — <?= e($a['patient_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label fw-semibold">Note Type</label>
            <select name="note_type" id="noteType" class="form-select">
              <option value="general">General</option><option value="shift_handover">Shift Handover</option>
              <option value="care_plan">Care Plan</option><option value="observation">Observation</option><option value="incident">Incident</option>
            </select>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label fw-semibold">Shift</label>
            <select name="shift" id="noteShift" class="form-select">
              <option value="">—</option><option value="morning">Morning</option><option value="afternoon">Afternoon</option><option value="night">Night</option>
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
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Note</button>
      </div>
    </div>
    </form>
  </div>
</div>

<!-- ── Modal: View Full Note ──────────────────────────────────────── -->
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
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save_mar"><input type="hidden" name="id" id="marId">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-pills me-2"></i><span id="marModalTitle">Add Medication Order</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Admission <span class="text-danger">*</span></label>
            <select name="admission_id" id="marAdmId" class="form-select select2" required onchange="fillPatientFromAdm(this)">
              <option value="">Select Admission</option>
              <?php foreach ($activeAdmissions as $a): ?><option value="<?= $a['id'] ?>" data-pid="<?= $a['patient_id'] ?>"><?= e($a['admission_no']) ?> — <?= e($a['patient_name']) ?></option><?php endforeach; ?>
            </select>
            <input type="hidden" name="patient_id" id="marPatientId">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Medicine</label>
            <select name="medicine_id" id="marMedId" class="form-select select2" onchange="fillMedName(this)">
              <option value="">Select or type below</option>
              <?php foreach ($medicines as $m): ?><option value="<?= $m['id'] ?>" data-name="<?= e($m['name'].' '.($m['form']??'').' '.($m['strength']??'')) ?>"><?= e($m['name']) ?> <?= e($m['form']??'') ?> <?= e($m['strength']??'') ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6"><label class="form-label fw-semibold">Medicine Name <span class="text-danger">*</span></label><input type="text" name="medicine_name" id="marMedName" class="form-control" required placeholder="e.g. Amoxicillin 500mg"></div>
          <div class="col-6 col-md-3"><label class="form-label fw-semibold">Dose <span class="text-danger">*</span></label><input type="text" name="dose" id="marDose" class="form-control" required placeholder="e.g. 500mg"></div>
          <div class="col-6 col-md-3">
            <label class="form-label fw-semibold">Route</label>
            <select name="route" id="marRoute" class="form-select">
              <option value="oral">Oral (PO)</option><option value="iv">IV</option><option value="im">IM</option>
              <option value="sc">SC</option><option value="topical">Topical</option><option value="inhaled">Inhaled</option><option value="other">Other</option>
            </select>
          </div>
          <div class="col-6 col-md-3"><label class="form-label fw-semibold">Frequency</label><input type="text" name="frequency" id="marFreq" class="form-control" list="freqList" placeholder="e.g. BD, TDS"><datalist id="freqList"><option>OD</option><option>BD</option><option>TDS</option><option>QID</option><option>PRN</option><option>STAT</option><option>nocte</option></datalist></div>
          <div class="col-6 col-md-3"><label class="form-label fw-semibold">Start Date</label><input type="date" name="start_date" id="marStart" class="form-control" value="<?= date('Y-m-d') ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label fw-semibold">End Date</label><input type="date" name="end_date" id="marEnd" class="form-control"></div>
          <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="marNotes" class="form-control" rows="2" placeholder="Special instructions…"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Order</button>
      </div>
    </div>
    </form>
  </div>
</div>


<!-- ── Modal: Register / Edit Nurse ──────────────────────────────── -->
<div class="modal fade" id="nurseModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save_nurse"><input type="hidden" name="id" id="nurseId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="nurseModalTitle"><i class="fas fa-user-nurse me-2"></i>Register Nurse</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <h6 class="fw-bold mb-3"><i class="fas fa-id-card me-2 text-muted"></i>Personal &amp; Clinical Details</h6>
        <div class="row g-3 mb-4">
          <div class="col-md-6"><label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label><input type="text" name="first_name" id="nurseFirst" class="form-control" required placeholder="e.g. Jane"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label><input type="text" name="last_name" id="nurseLast" class="form-control" required placeholder="e.g. Mwangi"></div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Qualification</label>
            <select name="qualification" id="nurseQual" class="form-select">
              <option value="Registered Nurse">Registered Nurse (RN)</option>
              <option value="Enrolled Nurse">Enrolled Nurse (EN)</option>
              <option value="Registered Midwife">Registered Midwife (RM)</option>
              <option value="Community Health Nurse">Community Health Nurse</option>
              <option value="Nurse Practitioner">Nurse Practitioner (NP)</option>
              <option value="Nursing Officer">Nursing Officer</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label fw-semibold">Department / Ward</label><input type="text" name="department" id="nurseDept" class="form-control" placeholder="e.g. ICU, Maternity, Paediatrics"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label><input type="tel" name="phone" id="nursePhone" class="form-control" required placeholder="+254 700 111 222"></div>
          <div class="col-md-6"><label class="form-label fw-semibold">Official Email <span class="text-danger">*</span></label><input type="email" name="email" id="nurseEmail" class="form-control" required placeholder="nurse@clinic.com"></div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="nurseStatus" class="form-select">
              <option value="active">Active</option><option value="inactive">Inactive</option>
            </select>
          </div>
        </div>

        <!-- Portal account section -->
        <hr class="my-3">
        <h6 class="fw-bold mb-3"><i class="fas fa-lock me-2 text-muted"></i>Portal Login Account</h6>

        <div id="nurseCreateBlock">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="create_account" id="nurseAccToggle" value="1" checked onchange="toggleNurseAccOpts()">
            <label class="form-check-label fw-semibold" for="nurseAccToggle">Create a portal login account for this nurse</label>
          </div>
          <div id="nurseAccOptions" class="p-3 rounded border" style="background:#f8f9fa">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Module Role</label>
                <select name="module_role" id="nurseModuleRole" class="form-select">
                  <option value="nurse">Nurse — vitals, nursing notes, admissions</option>
                  <option value="receptionist">Receptionist — appointments &amp; patients</option>
                  <option value="lab_technician">Lab Technician — laboratory only</option>
                  <option value="pharmacist">Pharmacist — pharmacy &amp; prescriptions</option>
                  <option value="cashier">Cashier — billing only</option>
                </select>
              </div>
              <div class="col-md-6 d-flex align-items-end">
                <div class="alert alert-info py-2 px-3 mb-0 small w-100">
                  <i class="fas fa-info-circle me-1"></i>A secure password will be generated and emailed to the nurse.
                </div>
              </div>
            </div>
          </div>
        </div>

        <div id="nurseExistingBlock" class="d-none">
          <div class="alert alert-success py-2 px-3 small">
            <i class="fas fa-check-circle me-2"></i>
            This nurse has an active portal account: <strong id="nurseExistingEmail"></strong>
            &nbsp;— use the <strong>Reset Password</strong> button in the table to issue new credentials.
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i><span id="nurseSubmitLabel">Register Nurse</span></button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- Hidden forms -->
<form method="POST" id="nurseResetForm" style="display:none">
  <?= csrfField() ?><input type="hidden" name="action" value="reset_nurse_password"><input type="hidden" name="nurse_id" id="nurseResetId">
</form>
<form method="POST" id="nurseDelForm" style="display:none">
  <?= csrfField() ?><input type="hidden" name="action" value="delete_nurse"><input type="hidden" name="id" id="nurseDelId">
</form>

<?php
$extraJs = <<<'JS'
<script>
// ── Notes ─────────────────────────────────────────────────────────
function openNoteModal(id) {
    document.getElementById('noteId').value      = '';
    document.getElementById('notePatient').value = '';
    document.getElementById('noteType').value    = 'general';
    document.getElementById('noteShift').value   = '';
    document.getElementById('noteText').value    = '';
    document.getElementById('noteModalTitle').textContent = 'Add Nursing Note';
    if (id) {
        document.getElementById('noteModalTitle').textContent = 'Edit Note';
        fetch('nursing.php?fetch_note=' + id).then(r => r.json()).then(d => {
            if (!d.id) return;
            document.getElementById('noteId').value      = d.id;
            document.getElementById('notePatient').value = d.patient_id   || '';
            document.getElementById('noteType').value    = d.note_type    || 'general';
            document.getElementById('noteShift').value   = d.shift        || '';
            document.getElementById('noteText').value    = d.note_text    || '';
            if (d.admission_id) document.getElementById('noteAdmission').value = d.admission_id;
        });
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('noteModal')).show();
}
function viewNote(text, patient) {
    document.getElementById('viewNoteTitle').textContent = 'Note — ' + patient;
    document.getElementById('viewNoteText').textContent  = text;
    new bootstrap.Modal(document.getElementById('viewNoteModal')).show();
}

// ── MAR ───────────────────────────────────────────────────────────
function fillPatientFromAdm(sel) {
    document.getElementById('marPatientId').value = sel.options[sel.selectedIndex].dataset.pid || '';
}
function fillMedName(sel) {
    const n = sel.options[sel.selectedIndex].dataset.name;
    if (n) document.getElementById('marMedName').value = n.trim();
}
function openMarModal(id) {
    document.getElementById('marId').value = '';
    document.getElementById('marModalTitle').textContent = 'Edit Medication Order';
    fetch('nursing.php?fetch_mar=' + id).then(r => r.json()).then(d => {
        if (!d.id) return;
        document.getElementById('marId').value        = d.id;
        document.getElementById('marAdmId').value     = d.admission_id  || '';
        document.getElementById('marPatientId').value = d.patient_id    || '';
        document.getElementById('marMedId').value     = d.medicine_id   || '';
        document.getElementById('marMedName').value   = d.medicine_name || '';
        document.getElementById('marDose').value      = d.dose          || '';
        document.getElementById('marRoute').value     = d.route         || 'oral';
        document.getElementById('marFreq').value      = d.frequency     || '';
        document.getElementById('marStart').value     = d.start_date    || '';
        document.getElementById('marEnd').value       = d.end_date      || '';
        document.getElementById('marNotes').value     = d.notes         || '';
    });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('marModal')).show();
}

// ── Nursing Staff ─────────────────────────────────────────────────
function openAddNurse() {
    document.getElementById('nurseModalTitle').innerHTML = '<i class="fas fa-user-nurse me-2"></i>Register Nurse';
    document.getElementById('nurseSubmitLabel').textContent = 'Register Nurse';
    document.getElementById('nurseId').value     = '0';
    document.getElementById('nurseFirst').value  = '';
    document.getElementById('nurseLast').value   = '';
    document.getElementById('nurseQual').value   = 'Registered Nurse';
    document.getElementById('nurseDept').value   = '';
    document.getElementById('nursePhone').value  = '';
    document.getElementById('nurseEmail').value  = '';
    document.getElementById('nurseStatus').value = 'active';
    document.getElementById('nurseModuleRole').value = 'nurse';

    const toggle = document.getElementById('nurseAccToggle');
    toggle.checked  = true;
    toggle.disabled = false;
    document.getElementById('nurseCreateBlock').classList.remove('d-none');
    document.getElementById('nurseExistingBlock').classList.add('d-none');
    document.getElementById('nurseAccOptions').style.display = '';
}

function openEditNurse(id) {
    fetch('nursing.php?fetch_nurse=' + id).then(r => r.json()).then(d => {
        document.getElementById('nurseModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Nurse Details';
        document.getElementById('nurseSubmitLabel').textContent = 'Save Changes';
        document.getElementById('nurseId').value     = d.id;
        document.getElementById('nurseFirst').value  = d.first_name     || '';
        document.getElementById('nurseLast').value   = d.last_name      || '';
        document.getElementById('nurseQual').value   = d.qualification  || 'Registered Nurse';
        document.getElementById('nurseDept').value   = d.department     || '';
        document.getElementById('nursePhone').value  = d.phone          || '';
        document.getElementById('nurseEmail').value  = d.email          || '';
        document.getElementById('nurseStatus').value = d.status         || 'active';

        const toggle = document.getElementById('nurseAccToggle');
        if (d.user_id) {
            toggle.checked  = false;
            toggle.disabled = true;
            document.getElementById('nurseCreateBlock').classList.add('d-none');
            document.getElementById('nurseExistingBlock').classList.remove('d-none');
            document.getElementById('nurseExistingEmail').textContent = d.account_email || d.email || '';
        } else {
            toggle.checked  = false;
            toggle.disabled = false;
            document.getElementById('nurseAccOptions').style.display = 'none';
            document.getElementById('nurseCreateBlock').classList.remove('d-none');
            document.getElementById('nurseExistingBlock').classList.add('d-none');
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('nurseModal')).show();
    });
}

function toggleNurseAccOpts() {
    document.getElementById('nurseAccOptions').style.display =
        document.getElementById('nurseAccToggle').checked ? '' : 'none';
}

function resetNursePassword(nurseId, name) {
    Swal.fire({
        title: 'Reset Portal Password?',
        html: 'A new password will be generated for <strong>' + name + '</strong> and sent to their email.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e67e22',
        confirmButtonText: 'Yes, Reset Password'
    }).then(r => {
        if (r.isConfirmed) {
            document.getElementById('nurseResetId').value = nurseId;
            document.getElementById('nurseResetForm').submit();
        }
    });
}

function delNurse(id, name) {
    Swal.fire({
        title: 'Remove Nurse?',
        text: 'Remove ' + name + ' from the nursing registry?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        confirmButtonText: 'Yes, Remove'
    }).then(r => {
        if (r.isConfirmed) {
            document.getElementById('nurseDelId').value = id;
            document.getElementById('nurseDelForm').submit();
        }
    });
}

function nCopyField(id) {
    const el = document.getElementById(id);
    navigator.clipboard.writeText(el.value).then(() => {
        const btn = el.nextElementSibling;
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check text-success"></i>';
        setTimeout(() => btn.innerHTML = orig, 1800);
    });
}

// Re-init select2 inside noteModal with correct dropdownParent so the
// dropdown doesn't escape the modal and lock the backdrop.
document.getElementById('noteModal').addEventListener('shown.bs.modal', function () {
    if (typeof $.fn.select2 !== 'undefined') {
        $('#noteModal .select2').each(function () {
            if ($(this).data('select2')) $(this).select2('destroy');
            $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#noteModal'), width: '100%' });
        });
    }
});
document.getElementById('noteModal').addEventListener('hidden.bs.modal', function () {
    if (typeof $.fn.select2 !== 'undefined') {
        $('#noteModal .select2').each(function () {
            if ($(this).data('select2')) $(this).select2('destroy');
        });
    }
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
?>
