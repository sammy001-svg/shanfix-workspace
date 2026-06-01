<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
session_unset();
session_destroy();
setcookie('remember_token', '', time() - 3600, '/', '', true, true);
header('Location: ' . APP_URL . '/auth/login.php');
exit;
