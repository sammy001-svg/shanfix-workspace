<?php
// ── Database & App Configuration ──────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // Change for production
define('DB_PASS', '');              // Change for production
define('DB_NAME', 'shanfix_db');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',    'OrbitDesk Workspace');
define('APP_TAGLINE', 'All-in-One Business Management Platform');
define('APP_URL',     'http://localhost/shanfix-workspace');   // Change for production
define('APP_VERSION', '1.0.0');
define('APP_YEAR',    date('Y'));
define('CURRENCY',    'KES');
define('CURRENCY_SYMBOL', 'KES ');

// Session settings
define('SESSION_LIFETIME', 3600 * 8); // 8 hours

// ── PDO Connection ──────────────────────────────────────────────
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    http_response_code(503);
    die('<!DOCTYPE html><html><head><title>Service Unavailable</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f4f8;}
    .box{background:#fff;padding:2rem 3rem;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.1);text-align:center;}
    h2{color:#0B2D4E;} p{color:#666;}</style></head>
    <body><div class="box"><h2>⚠️ Database Connection Error</h2>
    <p>Unable to connect to the database. Please check your configuration.</p>
    <small style="color:#999;">' . (APP_ENV === 'development' ? htmlspecialchars($e->getMessage()) : 'Contact your administrator.') . '</small>
    </div></body></html>');
}

define('APP_ENV', 'development'); // Change to 'production' on cPanel

// Load system settings from DB (defines SMTP_HOST, SMTP_PORT, etc.)
require_once __DIR__ . '/../includes/settings.php';
