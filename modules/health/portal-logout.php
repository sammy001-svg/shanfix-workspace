<?php
/**
 * Health portal logout — clears session and returns to branded login.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$portalHost = $_SESSION['health_portal_host'] ?? '';
$orgSlug    = $_SESSION['org_slug']           ?? '';

// Destroy the entire session
session_unset();
session_destroy();

// Redirect back to portal login on the same domain
if ($portalHost) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    header('Location: ' . $proto . '://' . $portalHost . '/modules/health/portal-login.php');
} elseif ($orgSlug) {
    header('Location: ' . APP_URL . '/modules/health/portal-login.php?org=' . rawurlencode($orgSlug));
} else {
    header('Location: ' . APP_URL . '/auth/login.php');
}
exit;
