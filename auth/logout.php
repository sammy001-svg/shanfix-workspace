<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';

$orgSlug = $_SESSION['org_slug'] ?? null;
$role    = $_SESSION['user_role'] ?? null;

session_unset();
session_destroy();
setcookie('remember_token', '', time() - 3600, '/', '', true, true);

// Staff and client admins who logged in via org portal go back to their portal
if ($orgSlug && in_array($role, ['staff', 'client_admin'], true)) {
    header('Location: ' . APP_URL . '/auth/org-login.php?org=' . rawurlencode($orgSlug) . '&logout=1');
} else {
    header('Location: ' . APP_URL . '/auth/login.php');
}
exit;
