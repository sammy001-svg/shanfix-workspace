<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/admin/clients.php');

$orgName  = sanitize($_POST['org_name']       ?? '');
$email    = trim($_POST['email']              ?? '');
$phone    = sanitize($_POST['phone']          ?? '');
$city     = sanitize($_POST['city']           ?? '');
$adminName= sanitize($_POST['admin_name']     ?? '');
$adminPwd = $_POST['admin_password']          ?? '';
$planId   = (int)($_POST['plan_id']           ?? 0);
$subStatus= $_POST['sub_status']              ?? 'trial';
$modules  = $_POST['modules']                 ?? [];

if (!$orgName || !$email || !$adminName || !$adminPwd) {
    setFlash('danger', 'Please fill in all required fields.');
    redirect(APP_URL . '/admin/clients.php?action=add');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    setFlash('danger', 'Invalid email address.');
    redirect(APP_URL . '/admin/clients.php');
}

// Check email uniqueness
$check = $pdo->prepare("SELECT id FROM users WHERE email=?");
$check->execute([$email]);
if ($check->fetch()) {
    setFlash('danger', 'Email already exists.');
    redirect(APP_URL . '/admin/clients.php');
}

$pdo->beginTransaction();
try {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $orgName)) . '-' . substr(md5(microtime()), 0, 6);
    $pdo->prepare("INSERT INTO organizations (name, email, phone, city, slug) VALUES (?,?,?,?,?)")->execute([$orgName, $email, $phone, $city, $slug]);
    $orgId = $pdo->lastInsertId();

    $hashed = password_hash($adminPwd, PASSWORD_BCRYPT, ['cost'=>12]);
    $pdo->prepare("INSERT INTO users (org_id, name, email, password, role, status) VALUES (?,'".addslashes($adminName)."',?,?,'client_admin','active')")->execute([$orgId, $email, $hashed]);

    $trialEnd = date('Y-m-d H:i:s', strtotime('+14 days'));
    $pdo->prepare("INSERT INTO subscriptions (org_id, plan_id, status, trial_ends_at, starts_at) VALUES (?,?,?,?,NOW())")
        ->execute([$orgId, $planId ?: null, $subStatus, $trialEnd]);
    $subId = $pdo->lastInsertId();

    if (!empty($modules)) {
        $stmtMod = $pdo->prepare("SELECT id FROM modules WHERE slug=?");
        $stmtIns = $pdo->prepare("INSERT INTO subscription_modules (subscription_id, module_id) VALUES (?,?)");
        foreach ($modules as $slug) {
            $stmtMod->execute([$slug]);
            $mod = $stmtMod->fetch();
            if ($mod) $stmtIns->execute([$subId, $mod['id']]);
        }
    }

    $pdo->commit();
    logActivity('create_client', 'admin', "Created client: $orgName");
    setFlash('success', "Client '{$orgName}' created successfully.");

    // Send welcome email to the new client admin (non-fatal if SMTP not configured)
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        mailer()->sendWelcome($email, $adminName, $orgName);
    } catch (Exception $ex) {
        error_log('[clients-save] Welcome email failed: ' . $ex->getMessage());
    }
} catch (Exception $e) {
    $pdo->rollBack();
    setFlash('danger', 'Failed to create client. Please try again.');
}

redirect(APP_URL . '/admin/clients.php');
