<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';

$orgSlug = $_SESSION['trv_org_slug'] ?? null;
session_unset();
session_destroy();

header('Location: ' . APP_URL . '/traveller/login.php' . ($orgSlug ? '?org=' . rawurlencode($orgSlug) . '&logout=1' : '?logout=1'));
exit;
