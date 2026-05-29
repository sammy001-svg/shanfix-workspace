<?php
/**
 * OrbitDesk — Web Installer
 * Standalone setup wizard for first-time cPanel / shared-hosting installation.
 *
 * Steps:
 *   1. Requirements check
 *   2. Database configuration
 *   3. Admin account setup
 *   4. App configuration
 *   5. Installation & success
 */

declare(strict_types=1);

// ── Lock file path — adjust if script lives in a sub-dir ─────────────────────
define('BASE_DIR',   dirname(__DIR__));
define('LOCK_FILE',  BASE_DIR . '/.installed');
define('CONFIG_FILE', BASE_DIR . '/config/database.php');
define('SCHEMA_FILE', BASE_DIR . '/database/schema.sql');
define('UPLOADS_DIR', BASE_DIR . '/assets/uploads');

// ── Already installed? ────────────────────────────────────────────────────────
if (file_exists(LOCK_FILE)) {
    // Try to read the stored APP_URL from the config and redirect
    $appUrl = 'index.php';
    if (file_exists(CONFIG_FILE)) {
        $cfgContent = file_get_contents(CONFIG_FILE);
        if (preg_match("/define\('APP_URL'\s*,\s*'([^']+)'\)/", $cfgContent, $m)) {
            $appUrl = $m[1];
        }
    }
    header('Location: ' . $appUrl);
    exit;
}

// ── AJAX: test DB connection ──────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'test_db') {
    header('Content-Type: application/json');
    $host   = trim($_POST['db_host']   ?? '');
    $dbname = trim($_POST['db_name']   ?? '');
    $user   = trim($_POST['db_user']   ?? '');
    $pass   = $_POST['db_pass']        ?? '';
    if (!$host || !$dbname || !$user) {
        echo json_encode(['ok' => false, 'msg' => 'All fields are required.']);
        exit;
    }
    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo json_encode(['ok' => true, 'msg' => 'Connection successful!']);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: run installation ────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'install') {
    header('Content-Type: application/json');

    $dbHost    = trim($_POST['db_host']    ?? 'localhost');
    $dbName    = trim($_POST['db_name']    ?? '');
    $dbUser    = trim($_POST['db_user']    ?? '');
    $dbPass    = $_POST['db_pass']         ?? '';
    $dbPrefix  = trim($_POST['db_prefix']  ?? '');
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail= strtolower(trim($_POST['admin_email'] ?? ''));
    $adminPass = $_POST['admin_pass']      ?? '';
    $orgName   = trim($_POST['org_name']   ?? '');
    $appUrl    = rtrim(trim($_POST['app_url'] ?? ''), '/');
    $currency  = trim($_POST['currency']   ?? 'KES');
    $timezone  = trim($_POST['timezone']   ?? 'Africa/Nairobi');

    // Basic validation
    $errors = [];
    if (!$dbHost)     $errors[] = 'Database host is required.';
    if (!$dbName)     $errors[] = 'Database name is required.';
    if (!$dbUser)     $errors[] = 'Database username is required.';
    if (!$adminName)  $errors[] = 'Admin name is required.';
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid admin email is required.';
    if (strlen($adminPass) < 8) $errors[] = 'Password must be at least 8 characters.';
    if (!$orgName)    $errors[] = 'Organisation name is required.';
    if (!$appUrl)     $errors[] = 'Application URL is required.';

    if ($errors) {
        echo json_encode(['ok' => false, 'msg' => implode(' ', $errors)]);
        exit;
    }

    // 1. Connect
    try {
        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'msg' => 'DB connection failed: ' . $e->getMessage()]);
        exit;
    }

    // 2. Run schema
    if (!file_exists(SCHEMA_FILE)) {
        echo json_encode(['ok' => false, 'msg' => 'Schema file not found: ' . SCHEMA_FILE]);
        exit;
    }

    try {
        $sql = file_get_contents(SCHEMA_FILE);
        // Split by semicolon, skip comments and empty statements
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => $s !== '' && !preg_match('/^--/', $s)
        );
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach ($statements as $stmt) {
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'msg' => 'Schema error: ' . $e->getMessage()]);
        exit;
    }

    // 3. Create organisation
    try {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $orgName));
        $pdo->prepare(
            "INSERT INTO organizations (name, email, slug, status) VALUES (?,?,?,'active')
             ON DUPLICATE KEY UPDATE name=VALUES(name)"
        )->execute([$orgName, $adminEmail, $slug]);
        $orgId = (int)$pdo->lastInsertId();
        if ($orgId === 0) {
            $r = $pdo->prepare("SELECT id FROM organizations WHERE slug=? LIMIT 1");
            $r->execute([$slug]);
            $orgId = (int)($r->fetchColumn() ?: 1);
        }
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'msg' => 'Org creation failed: ' . $e->getMessage()]);
        exit;
    }

    // 4. Create super-admin user
    try {
        $hashed = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare(
            "INSERT INTO users (org_id, name, email, password, role, status)
             VALUES (?, ?, ?, ?, 'super_admin', 'active')
             ON DUPLICATE KEY UPDATE password=VALUES(password), role='super_admin'"
        )->execute([$orgId, $adminName, $adminEmail, $hashed]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'msg' => 'Admin user creation failed: ' . $e->getMessage()]);
        exit;
    }

    // 5. Create uploads directories
    $dirs = [
        UPLOADS_DIR,
        UPLOADS_DIR . '/avatars',
        UPLOADS_DIR . '/logos',
        UPLOADS_DIR . '/documents',
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Write .htaccess to deny direct PHP execution
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch '\\.php$'>\n  Deny from all\n</FilesMatch>\n");
        }
    }

    // 6. Write config/database.php
    $encKey     = bin2hex(random_bytes(32));
    $configContent = <<<PHP
<?php
// ── Database & App Configuration ──────────────────────────────
define('DB_HOST',    '$dbHost');
define('DB_USER',    '$dbUser');
define('DB_PASS',    '$dbPass');
define('DB_NAME',    '$dbName');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',    'OrbitDesk Workspace');
define('APP_TAGLINE', 'All-in-One Business Management Platform');
define('APP_URL',     '$appUrl');
define('APP_VERSION', '1.0.0');
define('APP_YEAR',    date('Y'));
define('CURRENCY',    '$currency');
define('CURRENCY_SYMBOL', '$currency ');

// ── Environment & Security ─────────────────────────────────────
define('APP_ENV', getenv('APP_ENV') ?: 'production');

define('ENCRYPTION_KEY', getenv('APP_ENCRYPT_KEY') ?: '$encKey');

// Session settings
define('SESSION_LIFETIME', 3600 * 8);

date_default_timezone_set('$timezone');

// ── PDO Connection ──────────────────────────────────────────────
try {
    \$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    \$options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];
    \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, \$options);
} catch (PDOException \$e) {
    http_response_code(503);
    die('Database connection error. Please contact your administrator.');
}

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/encryption.php';
PHP;

    if (file_put_contents(CONFIG_FILE, $configContent) === false) {
        echo json_encode(['ok' => false, 'msg' => 'Could not write config/database.php — check file permissions.']);
        exit;
    }

    // 7. Write lock file
    file_put_contents(LOCK_FILE, date('Y-m-d H:i:s') . "\n");

    echo json_encode([
        'ok'       => true,
        'msg'      => 'Installation complete!',
        'login_url'=> $appUrl . '/auth/login.php',
        'email'    => $adminEmail,
    ]);
    exit;
}

// ── Detect server URL ─────────────────────────────────────────────────────────
$proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir      = rtrim(dirname(str_replace(basename(__FILE__), '', $_SERVER['SCRIPT_NAME'] ?? '')), '/');
// Go one level up (scripts/ -> root)
$rootPath = rtrim(dirname($dir), '/');
$detectedUrl = $proto . '://' . $host . $rootPath;

// ── System requirements ───────────────────────────────────────────────────────
$requirements = [
    ['label' => 'PHP >= 8.0',       'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'detail' => 'PHP ' . PHP_VERSION],
    ['label' => 'PDO extension',    'ok' => extension_loaded('pdo'),        'detail' => ''],
    ['label' => 'PDO MySQL',        'ok' => extension_loaded('pdo_mysql'),  'detail' => ''],
    ['label' => 'cURL',             'ok' => extension_loaded('curl'),       'detail' => ''],
    ['label' => 'mbstring',         'ok' => extension_loaded('mbstring'),   'detail' => ''],
    ['label' => 'OpenSSL',          'ok' => extension_loaded('openssl'),    'detail' => ''],
    ['label' => 'JSON',             'ok' => extension_loaded('json'),       'detail' => ''],
    ['label' => 'config/ writable', 'ok' => is_writable(BASE_DIR . '/config'), 'detail' => BASE_DIR . '/config'],
    ['label' => 'schema.sql exists','ok' => file_exists(SCHEMA_FILE),      'detail' => ''],
];
$allGood = array_reduce($requirements, fn($c, $r) => $c && $r['ok'], true);

$timezones = DateTimeZone::listIdentifiers();
sort($timezones);

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OrbitDesk — Web Installer</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --primary:   #1A8A4E;
    --primary-d: #136b3b;
    --danger:    #dc3545;
    --success:   #198754;
    --warning:   #ffc107;
    --text:      #212529;
    --muted:     #6c757d;
    --border:    #dee2e6;
    --bg:        #f0f4f8;
    --card:      #ffffff;
    --radius:    10px;
  }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
         background: var(--bg); color: var(--text); min-height: 100vh;
         display: flex; flex-direction: column; align-items: center; padding: 2rem 1rem; }
  .installer-wrap { width: 100%; max-width: 680px; }
  .header { text-align: center; margin-bottom: 2rem; }
  .header .logo { font-size: 2rem; font-weight: 800; color: var(--primary); letter-spacing: -1px; }
  .header .logo span { color: var(--text); }
  .header p { color: var(--muted); margin-top: .3rem; font-size: .95rem; }

  /* Progress stepper */
  .steps { display: flex; justify-content: space-between; margin-bottom: 2rem;
           position: relative; }
  .steps::before { content:''; position:absolute; top:18px; left:0; right:0;
                   height:2px; background:var(--border); z-index:0; }
  .step { display:flex; flex-direction:column; align-items:center; flex:1;
          position:relative; z-index:1; }
  .step-num { width:36px; height:36px; border-radius:50%; border:2px solid var(--border);
              background:var(--card); display:flex; align-items:center; justify-content:center;
              font-weight:700; font-size:.85rem; color:var(--muted); transition:.3s; }
  .step-label { font-size:.72rem; color:var(--muted); margin-top:.4rem; text-align:center; }
  .step.active   .step-num { border-color:var(--primary); background:var(--primary); color:#fff; }
  .step.active   .step-label { color:var(--primary); font-weight:600; }
  .step.done     .step-num { border-color:var(--success); background:var(--success); color:#fff; }
  .step.done     .step-label { color:var(--success); }

  /* Card */
  .card { background:var(--card); border-radius:var(--radius); box-shadow:0 4px 24px rgba(0,0,0,.08);
          padding:2rem; margin-bottom:1.5rem; }
  .card h2 { font-size:1.25rem; font-weight:700; margin-bottom:.25rem; }
  .card .subtitle { color:var(--muted); font-size:.9rem; margin-bottom:1.5rem; }

  /* Requirements table */
  .req-table { width:100%; border-collapse:collapse; font-size:.9rem; }
  .req-table td { padding:.5rem .75rem; border-bottom:1px solid var(--border); }
  .req-table tr:last-child td { border-bottom:none; }
  .badge { display:inline-block; padding:.2rem .6rem; border-radius:20px; font-size:.75rem; font-weight:600; }
  .badge-ok  { background:#d1e7dd; color:#0a3622; }
  .badge-fail{ background:#f8d7da; color:#58151c; }
  .req-detail { color:var(--muted); font-size:.78rem; }

  /* Form */
  .form-group { margin-bottom:1.25rem; }
  .form-group label { display:block; font-size:.875rem; font-weight:600; margin-bottom:.4rem; }
  .form-group .hint { font-size:.78rem; color:var(--muted); margin-bottom:.4rem; }
  .form-control { width:100%; padding:.55rem .85rem; border:1px solid var(--border);
                  border-radius:6px; font-size:.9rem; transition:.2s;
                  background:#fff; color:var(--text); }
  .form-control:focus { outline:none; border-color:var(--primary);
                        box-shadow:0 0 0 3px rgba(26,138,78,.15); }
  .row { display:grid; gap:1rem; }
  .row-2 { grid-template-columns:1fr 1fr; }
  .row-3 { grid-template-columns:1fr 1fr 1fr; }

  /* Buttons */
  .btn { display:inline-flex; align-items:center; gap:.5rem; padding:.6rem 1.4rem;
         border-radius:6px; font-size:.9rem; font-weight:600; border:none; cursor:pointer;
         transition:.2s; text-decoration:none; }
  .btn-primary { background:var(--primary); color:#fff; }
  .btn-primary:hover { background:var(--primary-d); }
  .btn-outline { background:transparent; border:1.5px solid var(--border); color:var(--text); }
  .btn-outline:hover { border-color:var(--primary); color:var(--primary); }
  .btn-sm { padding:.4rem 1rem; font-size:.82rem; }
  .btn-danger { background:var(--danger); color:#fff; }
  .btn:disabled { opacity:.6; cursor:not-allowed; }

  .btn-group { display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem; }

  /* Alerts */
  .alert { padding:.9rem 1rem; border-radius:6px; font-size:.875rem; margin-bottom:1rem; }
  .alert-success { background:#d1e7dd; color:#0a3622; }
  .alert-danger  { background:#f8d7da; color:#58151c; }
  .alert-info    { background:#cff4fc; color:#055160; }
  .alert-warning { background:#fff3cd; color:#664d03; }
  .alert b { font-weight:700; }

  /* DB test button row */
  .test-row { display:flex; align-items:center; gap:.75rem; margin-top:.5rem; }
  #db-test-msg { font-size:.85rem; font-weight:500; }

  /* Progress bar */
  .prog-wrap { background:var(--border); border-radius:20px; height:8px; overflow:hidden; margin:1rem 0; }
  .prog-bar  { height:100%; background:var(--primary); width:0%; transition:width .4s ease; }

  /* Success screen */
  .success-icon { text-align:center; font-size:4rem; margin-bottom:1rem; }
  .success-box  { background:#d1e7dd; border-radius:var(--radius); padding:1.5rem;
                  margin:1.5rem 0; font-size:.9rem; }
  .success-box table { width:100%; }
  .success-box td { padding:.3rem .5rem; }
  .success-box td:first-child { font-weight:600; width:40%; }
  .cred-note { color:var(--danger); font-weight:600; font-size:.85rem; margin-top:.75rem; }

  .hidden { display:none !important; }
  #log-box { background:#1a1a2e; color:#a3e4b7; font-family:monospace; font-size:.8rem;
             padding:1rem; border-radius:6px; max-height:180px; overflow-y:auto;
             margin-top:1rem; }
  .log-line { padding:.1rem 0; }
  .log-line.err { color:#f87171; }

  @media(max-width:500px) {
    .row-2, .row-3 { grid-template-columns:1fr; }
    .step-label { display:none; }
  }
</style>
</head>
<body>
<div class="installer-wrap">

  <div class="header">
    <div class="logo">Orbit<span>Desk</span></div>
    <p>Web Installer &mdash; v1.0.0</p>
  </div>

  <!-- Step indicator -->
  <div class="steps" id="step-bar">
    <div class="step active" id="step-ind-1"><div class="step-num">1</div><div class="step-label">Requirements</div></div>
    <div class="step"        id="step-ind-2"><div class="step-num">2</div><div class="step-label">Database</div></div>
    <div class="step"        id="step-ind-3"><div class="step-num">3</div><div class="step-label">Admin Account</div></div>
    <div class="step"        id="step-ind-4"><div class="step-num">4</div><div class="step-label">Configuration</div></div>
    <div class="step"        id="step-ind-5"><div class="step-num">5</div><div class="step-label">Install</div></div>
  </div>

  <!-- ── STEP 1: Requirements ─────────────────────────────────────────────── -->
  <div id="step-1" class="card">
    <h2>System Requirements</h2>
    <p class="subtitle">Checking your server environment before we begin.</p>

    <table class="req-table">
      <?php foreach ($requirements as $req): ?>
      <tr>
        <td><?= htmlspecialchars($req['label']) ?></td>
        <td><span class="badge <?= $req['ok'] ? 'badge-ok' : 'badge-fail' ?>">
              <?= $req['ok'] ? 'PASS' : 'FAIL' ?></span></td>
        <td class="req-detail"><?= htmlspecialchars($req['detail']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>

    <?php if (!$allGood): ?>
    <div class="alert alert-danger" style="margin-top:1.25rem;">
      <b>Some requirements are not met.</b> Please resolve the issues above before continuing.
    </div>
    <?php else: ?>
    <div class="alert alert-success" style="margin-top:1.25rem;">
      <b>All requirements passed!</b> You may proceed to the next step.
    </div>
    <?php endif; ?>

    <div class="btn-group">
      <span></span>
      <button class="btn btn-primary" onclick="goStep(2)" <?= !$allGood ? 'disabled' : '' ?>>
        Continue &rarr;
      </button>
    </div>
  </div>

  <!-- ── STEP 2: Database ─────────────────────────────────────────────────── -->
  <div id="step-2" class="card hidden">
    <h2>Database Configuration</h2>
    <p class="subtitle">Enter your MySQL database credentials. The database must already exist.</p>

    <div class="row row-2">
      <div class="form-group">
        <label>Database Host</label>
        <input type="text" class="form-control" id="db_host" value="localhost" placeholder="localhost">
      </div>
      <div class="form-group">
        <label>Database Name</label>
        <input type="text" class="form-control" id="db_name" placeholder="orbitdesk_db">
      </div>
    </div>
    <div class="row row-2">
      <div class="form-group">
        <label>Username</label>
        <input type="text" class="form-control" id="db_user" placeholder="db_username">
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" class="form-control" id="db_pass" placeholder="(leave blank if none)">
      </div>
    </div>
    <div class="form-group">
      <label>Table Prefix <span style="font-weight:400;color:var(--muted)">(optional, leave blank)</span></label>
      <input type="text" class="form-control" id="db_prefix" placeholder="">
    </div>

    <div class="test-row">
      <button class="btn btn-outline btn-sm" onclick="testDb()">Test Connection</button>
      <span id="db-test-msg"></span>
    </div>

    <div class="btn-group">
      <button class="btn btn-outline" onclick="goStep(1)">&larr; Back</button>
      <button class="btn btn-primary" id="btn-step2-next" onclick="step2Next()">Continue &rarr;</button>
    </div>
  </div>

  <!-- ── STEP 3: Admin Account ────────────────────────────────────────────── -->
  <div id="step-3" class="card hidden">
    <h2>Admin Account Setup</h2>
    <p class="subtitle">Create your super-admin account and organisation.</p>

    <div class="form-group">
      <label>Organisation Name</label>
      <input type="text" class="form-control" id="org_name" placeholder="My Company Ltd">
    </div>
    <div class="row row-2">
      <div class="form-group">
        <label>Admin Full Name</label>
        <input type="text" class="form-control" id="admin_name" placeholder="John Doe">
      </div>
      <div class="form-group">
        <label>Admin Email</label>
        <input type="email" class="form-control" id="admin_email" placeholder="admin@example.com">
      </div>
    </div>
    <div class="row row-2">
      <div class="form-group">
        <label>Password</label>
        <div class="hint">Minimum 8 characters</div>
        <input type="password" class="form-control" id="admin_pass" placeholder="Strong password">
      </div>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" class="form-control" id="admin_pass2" placeholder="Repeat password">
      </div>
    </div>

    <div class="btn-group">
      <button class="btn btn-outline" onclick="goStep(2)">&larr; Back</button>
      <button class="btn btn-primary" onclick="step3Next()">Continue &rarr;</button>
    </div>
  </div>

  <!-- ── STEP 4: Configuration ────────────────────────────────────────────── -->
  <div id="step-4" class="card hidden">
    <h2>Application Configuration</h2>
    <p class="subtitle">Set the application URL, currency, and timezone.</p>

    <div class="form-group">
      <label>Application URL</label>
      <div class="hint">The full URL where OrbitDesk will be accessed (no trailing slash).</div>
      <input type="url" class="form-control" id="app_url" value="<?= htmlspecialchars($detectedUrl) ?>" placeholder="https://yourdomain.com/orbitdesk">
    </div>
    <div class="row row-2">
      <div class="form-group">
        <label>Currency Code</label>
        <select class="form-control" id="currency">
          <option value="KES" selected>KES — Kenyan Shilling</option>
          <option value="USD">USD — US Dollar</option>
          <option value="EUR">EUR — Euro</option>
          <option value="GBP">GBP — British Pound</option>
          <option value="UGX">UGX — Ugandan Shilling</option>
          <option value="TZS">TZS — Tanzanian Shilling</option>
          <option value="NGN">NGN — Nigerian Naira</option>
          <option value="ZAR">ZAR — South African Rand</option>
          <option value="GHS">GHS — Ghanaian Cedi</option>
        </select>
      </div>
      <div class="form-group">
        <label>Timezone</label>
        <select class="form-control" id="timezone">
          <?php foreach ($timezones as $tz): ?>
          <option value="<?= htmlspecialchars($tz) ?>"
            <?= $tz === 'Africa/Nairobi' ? 'selected' : '' ?>>
            <?= htmlspecialchars($tz) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="btn-group">
      <button class="btn btn-outline" onclick="goStep(3)">&larr; Back</button>
      <button class="btn btn-primary" onclick="goStep(5)">Review &amp; Install &rarr;</button>
    </div>
  </div>

  <!-- ── STEP 5: Install ──────────────────────────────────────────────────── -->
  <div id="step-5" class="card hidden">
    <h2>Ready to Install</h2>
    <p class="subtitle">Review your settings and click Install to begin.</p>

    <div id="review-box" style="background:#f8f9fa;border-radius:6px;padding:1rem;font-size:.875rem;margin-bottom:1rem;">
      <table style="width:100%;border-collapse:collapse;">
        <tr><td style="padding:.3rem .5rem;font-weight:600;width:40%">Database Host</td><td id="rev-db-host"></td></tr>
        <tr><td style="padding:.3rem .5rem;font-weight:600">Database Name</td><td id="rev-db-name"></td></tr>
        <tr><td style="padding:.3rem .5rem;font-weight:600">Organisation</td><td id="rev-org-name"></td></tr>
        <tr><td style="padding:.3rem .5rem;font-weight:600">Admin Email</td><td id="rev-admin-email"></td></tr>
        <tr><td style="padding:.3rem .5rem;font-weight:600">App URL</td><td id="rev-app-url"></td></tr>
        <tr><td style="padding:.3rem .5rem;font-weight:600">Currency</td><td id="rev-currency"></td></tr>
        <tr><td style="padding:.3rem .5rem;font-weight:600">Timezone</td><td id="rev-timezone"></td></tr>
      </table>
    </div>

    <div id="install-progress" class="hidden">
      <div class="prog-wrap"><div class="prog-bar" id="prog-bar"></div></div>
      <div id="log-box"></div>
    </div>

    <div id="install-alert"></div>

    <div class="btn-group" id="install-btn-group">
      <button class="btn btn-outline" onclick="goStep(4)">&larr; Back</button>
      <button class="btn btn-primary" id="btn-install" onclick="runInstall()">
        &#x1F680; Install OrbitDesk
      </button>
    </div>
  </div>

  <!-- ── STEP 6: Success ──────────────────────────────────────────────────── -->
  <div id="step-success" class="card hidden">
    <div class="success-icon">&#x2705;</div>
    <h2 style="text-align:center">Installation Complete!</h2>
    <p class="subtitle" style="text-align:center">OrbitDesk has been installed successfully.</p>

    <div class="success-box">
      <table>
        <tr><td>Login URL</td><td><a id="suc-login-url" href="#" target="_blank"></a></td></tr>
        <tr><td>Admin Email</td><td id="suc-email"></td></tr>
        <tr><td>Password</td><td>As entered during setup</td></tr>
      </table>
      <p class="cred-note">&#x26A0;&#xFE0F; The installer is now locked. Delete scripts/install.php from your server for security.</p>
    </div>

    <div style="text-align:center">
      <a id="btn-go-login" href="#" class="btn btn-primary">Go to Login &rarr;</a>
    </div>
  </div>

</div><!-- /installer-wrap -->

<script>
// Current step state
let currentStep = 1;

function goStep(n) {
  // Hide all steps
  for (let i = 1; i <= 5; i++) {
    document.getElementById('step-' + i)?.classList.add('hidden');
    const ind = document.getElementById('step-ind-' + i);
    if (ind) { ind.classList.remove('active', 'done'); }
  }
  // Mark done
  for (let i = 1; i < n; i++) {
    const ind = document.getElementById('step-ind-' + i);
    if (ind) ind.classList.add('done');
  }
  // Mark active
  const active = document.getElementById('step-ind-' + n);
  if (active) active.classList.add('active');

  // Show current step
  document.getElementById('step-' + n)?.classList.remove('hidden');

  // Populate review on step 5
  if (n === 5) populateReview();

  currentStep = n;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Step 2: Test DB connection
function testDb() {
  const msg = document.getElementById('db-test-msg');
  msg.style.color = '#6c757d';
  msg.textContent = 'Testing...';
  const data = new FormData();
  data.append('db_host', document.getElementById('db_host').value.trim());
  data.append('db_name', document.getElementById('db_name').value.trim());
  data.append('db_user', document.getElementById('db_user').value.trim());
  data.append('db_pass', document.getElementById('db_pass').value);
  fetch('?action=test_db', { method: 'POST', body: data })
    .then(r => r.json())
    .then(d => {
      msg.style.color = d.ok ? '#198754' : '#dc3545';
      msg.textContent = d.msg;
    })
    .catch(() => { msg.style.color='#dc3545'; msg.textContent='Request failed.'; });
}

function step2Next() {
  const host = document.getElementById('db_host').value.trim();
  const name = document.getElementById('db_name').value.trim();
  const user = document.getElementById('db_user').value.trim();
  if (!host || !name || !user) {
    alert('Database host, name, and username are required.');
    return;
  }
  goStep(3);
}

function step3Next() {
  const orgName   = document.getElementById('org_name').value.trim();
  const adminName = document.getElementById('admin_name').value.trim();
  const adminEmail= document.getElementById('admin_email').value.trim();
  const pass      = document.getElementById('admin_pass').value;
  const pass2     = document.getElementById('admin_pass2').value;
  if (!orgName || !adminName || !adminEmail) {
    alert('All fields are required.'); return;
  }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(adminEmail)) {
    alert('Enter a valid email address.'); return;
  }
  if (pass.length < 8) {
    alert('Password must be at least 8 characters.'); return;
  }
  if (pass !== pass2) {
    alert('Passwords do not match.'); return;
  }
  goStep(4);
}

function populateReview() {
  document.getElementById('rev-db-host').textContent    = document.getElementById('db_host').value;
  document.getElementById('rev-db-name').textContent    = document.getElementById('db_name').value;
  document.getElementById('rev-org-name').textContent   = document.getElementById('org_name').value;
  document.getElementById('rev-admin-email').textContent= document.getElementById('admin_email').value;
  document.getElementById('rev-app-url').textContent    = document.getElementById('app_url').value;
  document.getElementById('rev-currency').textContent   = document.getElementById('currency').value;
  document.getElementById('rev-timezone').textContent   = document.getElementById('timezone').value;
}

function addLog(msg, isErr) {
  const box  = document.getElementById('log-box');
  const line = document.createElement('div');
  line.className = 'log-line' + (isErr ? ' err' : '');
  line.textContent = '> ' + msg;
  box.appendChild(line);
  box.scrollTop = box.scrollHeight;
}

function setProgress(pct) {
  document.getElementById('prog-bar').style.width = pct + '%';
}

function runInstall() {
  const btn = document.getElementById('btn-install');
  btn.disabled = true;
  btn.textContent = 'Installing...';

  document.getElementById('install-progress').classList.remove('hidden');
  document.getElementById('install-alert').innerHTML = '';
  setProgress(10);
  addLog('Starting installation...');

  const data = new FormData();
  data.append('db_host',    document.getElementById('db_host').value.trim());
  data.append('db_name',    document.getElementById('db_name').value.trim());
  data.append('db_user',    document.getElementById('db_user').value.trim());
  data.append('db_pass',    document.getElementById('db_pass').value);
  data.append('db_prefix',  document.getElementById('db_prefix').value.trim());
  data.append('admin_name', document.getElementById('admin_name').value.trim());
  data.append('admin_email',document.getElementById('admin_email').value.trim());
  data.append('admin_pass', document.getElementById('admin_pass').value);
  data.append('org_name',   document.getElementById('org_name').value.trim());
  data.append('app_url',    document.getElementById('app_url').value.trim());
  data.append('currency',   document.getElementById('currency').value);
  data.append('timezone',   document.getElementById('timezone').value);

  setProgress(30);
  addLog('Sending configuration to server...');

  fetch('?action=install', { method: 'POST', body: data })
    .then(r => r.json())
    .then(d => {
      setProgress(100);
      if (d.ok) {
        addLog('Database schema created.');
        addLog('Admin account created.');
        addLog('Configuration file written.');
        addLog('Installation complete!');
        setTimeout(() => showSuccess(d), 600);
      } else {
        addLog('ERROR: ' + d.msg, true);
        btn.disabled = false;
        btn.textContent = 'Retry Install';
        document.getElementById('install-alert').innerHTML =
          '<div class="alert alert-danger"><b>Installation failed:</b> ' + escHtml(d.msg) + '</div>';
      }
    })
    .catch(err => {
      addLog('Network error: ' + err, true);
      btn.disabled = false;
      btn.textContent = 'Retry Install';
    });
}

function showSuccess(d) {
  document.getElementById('step-5').classList.add('hidden');
  document.getElementById('step-success').classList.remove('hidden');

  // Mark all steps done
  for (let i = 1; i <= 5; i++) {
    const ind = document.getElementById('step-ind-' + i);
    if (ind) { ind.classList.remove('active'); ind.classList.add('done'); }
  }

  const loginUrl = d.login_url || (document.getElementById('app_url').value.trim() + '/auth/login.php');
  document.getElementById('suc-login-url').href        = loginUrl;
  document.getElementById('suc-login-url').textContent = loginUrl;
  document.getElementById('suc-email').textContent     = d.email || document.getElementById('admin_email').value;
  document.getElementById('btn-go-login').href         = loginUrl;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
