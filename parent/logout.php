<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';

$orgSlug = $_SESSION['par_org_slug'] ?? null;
session_unset();
session_destroy();

header('Location: ' . APP_URL . '/parent/login.php' . ($orgSlug ? '?org=' . rawurlencode($orgSlug) . '&logout=1' : ''));
exit;
