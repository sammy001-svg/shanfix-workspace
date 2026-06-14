<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$slug = $_SESSION['stu_org_slug'] ?? '';
session_unset();
session_destroy();

redirect(APP_URL . '/student/login.php' . ($slug ? '?org=' . rawurlencode($slug) . '&logout=1' : ''));
