<?php
// ── Database & App Configuration ──────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // Change for production: create a limited MySQL user
define('DB_PASS', '');              // Change for production
define('DB_NAME', 'shanfix_db');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',    'OrbitDesk Workspace');
define('APP_TAGLINE', 'All-in-One Business Management Platform');
define('APP_VERSION', '1.0.0');

// ── APP_URL: auto-detected from the HTTP request + filesystem ──────
// Works on localhost (with or without subdirectory) AND any cPanel domain.
// No manual change needed when deploying — just upload files and go.
if (!defined('APP_URL')) {
    // 1. Protocol — check multiple headers that cPanel load-balancers use
    $_proto = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $_proto = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $_proto = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        $_proto = 'https';
    } elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        $_proto = 'https';
    }

    // 2. Hostname (e.g. "orbitdesk.net" or "localhost")
    $_host = rtrim($_SERVER['HTTP_HOST'] ?? 'localhost', '/');

    // 3. Base path — compares the app root directory to DOCUMENT_ROOT.
    //    config/database.php lives in /config/, so app root = one level up.
    $_docRoot = rtrim((string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    $_appRoot = rtrim((string)realpath(__DIR__ . '/..'),                   '/\\');
    $_base    = '';
    if ($_docRoot !== '' && str_starts_with($_appRoot, $_docRoot)) {
        $_base = str_replace('\\', '/', substr($_appRoot, strlen($_docRoot)));
    }
    if ($_base === '/') $_base = '';

    define('APP_URL', rtrim($_proto . '://' . $_host . $_base, '/'));
    unset($_proto, $_host, $_docRoot, $_appRoot, $_base);
}
define('APP_YEAR',    date('Y'));
define('CURRENCY',    'KES');
define('CURRENCY_SYMBOL', 'KES ');

// ── Environment & Security ─────────────────────────────────────
define('APP_ENV', getenv('APP_ENV') ?: 'development'); // Set to 'production' on cPanel

/**
 * Encryption key for AES-256-CBC field-level PII encryption.
 * PRODUCTION: set the APP_ENCRYPT_KEY environment variable in cPanel or .env
 * and NEVER commit the real key to version control.
 * Generate with: php -r "echo bin2hex(random_bytes(32));" (64-char hex string)
 */
define('ENCRYPTION_KEY', getenv('APP_ENCRYPT_KEY') ?: 'CHANGE_ME_BEFORE_GO_LIVE_USE_64CHAR_HEX');

// Session settings
define('SESSION_LIFETIME', 3600 * 8); // 8 hours

// ── PDO Connection ──────────────────────────────────────────────
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => true,   // Reuse connections across requests
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



// Load system settings from DB (defines SMTP_HOST, SMTP_PORT, etc.)
require_once __DIR__ . '/../includes/settings.php';

// Load encryption helpers (AES-256-CBC PII protection)
require_once __DIR__ . '/../includes/encryption.php';

// Custom domain / subdomain detection (sets $detectedOrgId, $detectedOrgSlug)
require_once __DIR__ . '/../includes/domain-router.php';
