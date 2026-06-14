<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$slug = $_SESSION['doc_org_slug'] ?? '';
// Clear only doctor-portal session vars (don't destroy other portal sessions)
foreach (array_keys($_SESSION) as $k) {
    if (strncmp($k, 'doc_', 4) === 0) unset($_SESSION[$k]);
}
require_once __DIR__ . '/../config/database.php';
$loginUrl = APP_URL . '/doctor/login.php' . ($slug ? '?org=' . rawurlencode($slug) . '&logout=1' : '');
header('Location: ' . $loginUrl);
exit;
