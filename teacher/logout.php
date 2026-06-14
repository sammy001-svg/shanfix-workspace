<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$slug = $_SESSION['tch_org_slug'] ?? '';
session_unset();
session_destroy();

$loginUrl = APP_URL . '/teacher/login.php' . ($slug ? '?org=' . rawurlencode($slug) . '&logout=1' : '');
redirect($loginUrl);
