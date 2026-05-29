<?php
// ── Core Helper Functions ──────────────────────────────────────

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function e(string $input): string {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

function generateToken(): string {
    return bin2hex(random_bytes(32));
}

function verifyCsrf(): void {
    if (!isset($_POST['_token']) || !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['_token'])) {
        http_response_code(403);
        die('CSRF token mismatch. <a href="javascript:history.back()">Go back</a>');
    }
}

function csrfField(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }
    return '<input type="hidden" name="_token" value="' . $_SESSION['csrf_token'] . '">';
}

function formatCurrency(float $amount, string $currency = CURRENCY_SYMBOL): string {
    return $currency . number_format($amount, 2);
}

function formatDate(?string $date, string $format = 'd M Y'): string {
    if (!$date) return '—';
    return date($format, strtotime($date));
}

function formatDateTime(?string $dt): string {
    if (!$dt) return '—';
    return date('d M Y, h:i A', strtotime($dt));
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hrs ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('d M Y', strtotime($datetime));
}

function statusBadge(string $status): string {
    $map = [
        'active'    => 'success', 'paid'       => 'success', 'completed'  => 'success',
        'approved'  => 'success', 'confirmed'  => 'success', 'published'  => 'success',
        'inactive'  => 'secondary','cancelled' => 'secondary','expired'   => 'secondary',
        'pending'   => 'warning', 'trial'      => 'warning', 'processing' => 'warning',
        'draft'     => 'info',    'scheduled'  => 'info',    'available'  => 'info',
        'suspended' => 'danger',  'overdue'    => 'danger',  'defaulted'  => 'danger',
        'occupied'  => 'primary', 'checked_in' => 'primary',
    ];
    $class = $map[strtolower($status)] ?? 'secondary';
    return "<span class='badge bg-{$class}'>" . ucwords(str_replace('_', ' ', $status)) . "</span>";
}

function generateCode(string $prefix, int $id): string {
    return strtoupper($prefix) . '-' . str_pad($id, 5, '0', STR_PAD_LEFT);
}

function paginate(int $total, int $perPage, int $page, string $url): string {
    $pages = ceil($total / $perPage);
    if ($pages <= 1) return '';
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    for ($i = 1; $i <= $pages; $i++) {
        $active = $i === $page ? ' active' : '';
        $html .= "<li class='page-item{$active}'><a class='page-link' href='{$url}&page={$i}'>{$i}</a></li>";
    }
    return $html . '</ul></nav>';
}

function alert(string $type, string $message): string {
    $icons = ['success' => '✓', 'danger' => '✗', 'warning' => '⚠', 'info' => 'ℹ'];
    $icon = $icons[$type] ?? 'ℹ';
    return "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
        <strong>{$icon}</strong> {$message}
        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
    </div>";
}

function flashAlert(): string {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return alert($flash['type'], $flash['message']);
    }
    return '';
}

function flash(): void {
    echo flashAlert();
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = compact('type', 'message');
}

// ── Auth helpers ───────────────────────────────────────────────

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function requireLogin(string $redirect = '/auth/login.php'): void {
    if (!isLoggedIn()) {
        redirect(APP_URL . $redirect);
    }

    // Session timeout check
    global $pdo;
    $timeoutHours = 2;
    try {
        $s = $pdo->prepare("SELECT `value` FROM system_settings WHERE `key`='session_timeout' LIMIT 1");
        $s->execute();
        $v = $s->fetchColumn();
        if ($v !== false && is_numeric($v) && (int)$v > 0) $timeoutHours = (int)$v;
    } catch (Exception $e) { /* use default */ }

    $timeoutSecs = $timeoutHours * 3600;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeoutSecs) {
        session_unset();
        session_destroy();
        redirect(APP_URL . '/auth/login.php?expired=1');
    }

    // Session fingerprint check (detects session hijacking)
    $currentFingerprint = md5(($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if (isset($_SESSION['fingerprint']) && !hash_equals($_SESSION['fingerprint'], $currentFingerprint)) {
        session_unset();
        session_destroy();
        redirect(APP_URL . '/auth/login.php?hijack=1');
    }

    // Refresh activity timestamp
    $_SESSION['last_activity'] = time();
}

function requireSuperAdmin(): void {
    requireLogin();
    if (($_SESSION['user_role'] ?? '') !== 'super_admin') {
        redirect('/client/index.php');
    }
}

function requireClientAdmin(): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'] ?? '', ['client_admin', 'staff'])) {
        redirect('/auth/login.php');
    }
    enforceSubscriptionStatus();
}

/**
 * Auto-expire stale subscriptions and redirect blocked orgs.
 * Exempt pages: billing, support, expired, profile, logout.
 */
function enforceSubscriptionStatus(): void {
    $exemptPages = ['billing.php', 'support.php', 'expired.php', 'profile.php', 'logout.php', 'notifications.php'];
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    if (in_array($currentPage, $exemptPages)) return;

    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    if (!$orgId) return;

    global $pdo;
    $sub = getOrgSubscription($orgId);
    if (!$sub) return;

    $now    = time();
    $status = $sub['status'];

    // Auto-expire trial that has lapsed
    if ($status === 'trial' && !empty($sub['trial_ends_at']) && strtotime($sub['trial_ends_at']) < $now) {
        $pdo->prepare("UPDATE subscriptions SET status='expired' WHERE id=?")->execute([$sub['id']]);
        redirect(APP_URL . '/client/expired.php?reason=trial');
    }

    // Auto-expire active subscription past its end date
    if ($status === 'active' && !empty($sub['ends_at']) && strtotime($sub['ends_at']) < $now) {
        $pdo->prepare("UPDATE subscriptions SET status='expired' WHERE id=?")->execute([$sub['id']]);
        redirect(APP_URL . '/client/expired.php?reason=expired');
    }

    // Hard block on expired / cancelled / suspended
    if (in_array($status, ['expired', 'cancelled', 'suspended'])) {
        redirect(APP_URL . '/client/expired.php?reason=' . urlencode($status));
    }
}

/**
 * Returns an array describing an upcoming expiry warning, or null if none.
 */
function getSubscriptionWarning(int $orgId): ?array {
    $sub = getOrgSubscription($orgId);
    if (!$sub) return null;

    $now         = time();
    $warningDays = 7;

    if ($sub['status'] === 'trial' && !empty($sub['trial_ends_at'])) {
        $ends     = strtotime($sub['trial_ends_at']);
        $daysLeft = (int)ceil(($ends - $now) / 86400);
        if ($daysLeft >= 0 && $daysLeft <= $warningDays) {
            return ['type' => 'trial', 'days' => $daysLeft, 'date' => date('d M Y', $ends),
                    'severity' => $daysLeft <= 2 ? 'danger' : 'warning'];
        }
    }

    if ($sub['status'] === 'active' && !empty($sub['ends_at'])) {
        $ends     = strtotime($sub['ends_at']);
        $daysLeft = (int)ceil(($ends - $now) / 86400);
        if ($daysLeft >= 0 && $daysLeft <= $warningDays) {
            return ['type' => 'subscription', 'days' => $daysLeft, 'date' => date('d M Y', $ends),
                    'severity' => $daysLeft <= 2 ? 'danger' : 'warning'];
        }
    }

    return null;
}

function currentUser(): array {
    return [
        'id'       => $_SESSION['user_id']   ?? 0,
        'name'     => $_SESSION['user_name'] ?? '',
        'email'    => $_SESSION['user_email'] ?? '',
        'role'     => $_SESSION['user_role'] ?? '',
        'org_id'   => $_SESSION['org_id']   ?? 0,
        'org_name' => $_SESSION['org_name'] ?? '',
    ];
}

// ── DB helpers ─────────────────────────────────────────────────

function hasModuleAccess(int $orgId, string $moduleSlug): bool {
    global $pdo;
    // Check org subscription includes this module
    $stmt = $pdo->prepare("
        SELECT sm.id FROM subscription_modules sm
        JOIN subscriptions s ON sm.subscription_id = s.id
        JOIN modules m ON sm.module_id = m.id
        WHERE s.org_id = ? AND m.slug = ?
          AND s.status IN ('active','trial') AND sm.status = 'active'
    ");
    $stmt->execute([$orgId, $moduleSlug]);
    if (!$stmt->fetch()) return false;

    // Staff users need an explicit per-user module grant
    $user = currentUser();
    if (($user['role'] ?? '') === 'staff') {
        try {
            $s2 = $pdo->prepare("SELECT id FROM user_module_access WHERE user_id=? AND module_slug=? AND org_id=?");
            $s2->execute([$user['id'], $moduleSlug, $orgId]);
            return (bool)$s2->fetch();
        } catch (Exception $e) {
            // Table doesn't exist yet (pre-migration) — fail open for staff
            return true;
        }
    }

    return true; // client_admin gets all org modules
}

function requireModuleAccess(string $slug): void {
    requireLogin();
    $user = currentUser();
    if ($user['role'] !== 'super_admin' && !hasModuleAccess((int)$user['org_id'], $slug)) {
        redirect('/client/modules.php?denied=' . $slug);
    }
}

function getOrgModules(int $orgId): array {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT m.* FROM modules m
        JOIN subscription_modules sm ON m.id = sm.module_id
        JOIN subscriptions s ON sm.subscription_id = s.id
        WHERE s.org_id = ? AND s.status IN ('active','trial')
          AND sm.status = 'active' AND m.status = 'active'
        ORDER BY m.sort_order
    ");
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

function getOrgSubscription(int $orgId): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT s.*, p.name as plan_name FROM subscriptions s LEFT JOIN subscription_plans p ON s.plan_id = p.id WHERE s.org_id = ? ORDER BY s.created_at DESC LIMIT 1");
    $stmt->execute([$orgId]);
    return $stmt->fetch() ?: null;
}

function logActivity(string $action, string $module = '', string $description = ''): void {
    global $pdo;
    $user = currentUser();
    $stmt = $pdo->prepare("INSERT INTO activity_log (org_id, user_id, action, module, description, ip) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$user['org_id'], $user['id'], $action, $module, $description, $_SERVER['REMOTE_ADDR'] ?? '']);
}

function countRows(string $table, string $where = '1=1', array $params = []): int {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $where");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// ── Tenant Isolation Guards ────────────────────────────────────

/**
 * Assert a row in $table with primary key $id belongs to $orgId.
 * If not found or org mismatch, returns false (caller should abort/redirect).
 * Optionally pass $orgColumn if the column isn't named 'org_id'.
 *
 * Usage:
 *   if (!assertOrgOwnership('crm_contacts', $id, $orgId)) {
 *       http_response_code(403); exit('Forbidden');
 *   }
 */
function assertOrgOwnership(string $table, int $id, int $orgId, string $orgColumn = 'org_id'): bool {
    global $pdo;
    if ($id <= 0 || $orgId <= 0) return false;
    try {
        $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE id=? AND `{$orgColumn}`=? LIMIT 1");
        $stmt->execute([$id, $orgId]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        error_log("[assertOrgOwnership] {$table}#{$id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Assert a record belongs to the current session org via a foreign key lookup.
 * Used for sub-tables that don't have org_id directly (e.g. exam_schedule → exams).
 *
 * Example: assertParentOwnership('sch_exams', 'exam_id', $schedId, $orgId)
 * → fetches sch_exam_schedule WHERE id=$schedId, gets its exam_id,
 *   then checks sch_exams WHERE id=exam_id AND org_id=$orgId
 */
function assertParentOwnership(
    string $parentTable,
    string $foreignKey,
    string $childTable,
    int    $childId,
    int    $orgId,
    string $orgColumn = 'org_id'
): bool {
    global $pdo;
    if ($childId <= 0 || $orgId <= 0) return false;
    try {
        $child = $pdo->prepare("SELECT `{$foreignKey}` FROM `{$childTable}` WHERE id=? LIMIT 1");
        $child->execute([$childId]);
        $row = $child->fetch();
        if (!$row) return false;
        $parentId = (int)$row[$foreignKey];
        return assertOrgOwnership($parentTable, $parentId, $orgId, $orgColumn);
    } catch (Exception $e) {
        error_log("[assertParentOwnership] {$childTable}#{$childId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Abort with HTTP 403 if assertOrgOwnership fails.
 * Convenience wrapper for POST handlers.
 */
function requireOrgOwnership(string $table, int $id, int $orgId, string $orgColumn = 'org_id'): void {
    if (!assertOrgOwnership($table, $id, $orgId, $orgColumn)) {
        http_response_code(403);
        setFlash('danger', 'Access denied — you do not have permission to modify this record.');
        // Try to redirect back; fall back to dashboard
        $ref = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/client/index.php');
        header("Location: $ref");
        exit;
    }
}

/**
 * Returns the current session org_id as int. Dies if session not set.
 */
function safeOrgId(): int {
    $id = (int)($_SESSION['org_id'] ?? 0);
    if ($id <= 0) {
        http_response_code(403);
        die('Session error: org not set.');
    }
    return $id;
}

/**
 * Returns the current session user_id as int. Dies if session not set.
 */
function safeUserId(): int {
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        http_response_code(403);
        die('Session error: user not set.');
    }
    return $id;
}

// ── Input Validation ───────────────────────────────────────────

/**
 * Validate an associative array against rules.
 *
 * Rule syntax (pipe-separated):  'required|email|min:5|max:200'
 * Supported rules:
 *   required, email, url, numeric, integer, alpha, alphanumeric
 *   min:N (string length or numeric), max:N
 *   in:a,b,c  (allowed values)
 *   regex:/pattern/
 *   confirmed:other_field_name (equality check)
 *   date (strtotime-parseable)
 *   phone (digits, optional leading +)
 *
 * Returns ['field' => 'Error message', ...]. Empty = valid.
 *
 * @param array  $data  Flat key=>value array (typically from $_POST)
 * @param array  $rules ['fieldName' => 'rule1|rule2', ...]
 * @param array  $labels Optional human-readable labels for fields
 */
function validate(array $data, array $rules, array $labels = []): array {
    $errors = [];

    foreach ($rules as $field => $ruleString) {
        $value    = $data[$field] ?? '';
        $label    = $labels[$field] ?? ucwords(str_replace('_', ' ', $field));
        $ruleList = array_map('trim', explode('|', $ruleString));
        $required = in_array('required', $ruleList);

        foreach ($ruleList as $rule) {
            // Stop checking this field after first error
            if (isset($errors[$field])) break;

            if ($rule === 'required') {
                if ($value === '' || $value === null) {
                    $errors[$field] = "{$label} is required.";
                }
                continue;
            }

            // Skip further checks on empty optional fields
            if ($value === '' || $value === null) continue;

            if ($rule === 'email') {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = "{$label} must be a valid email address.";
                }
            } elseif ($rule === 'url') {
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[$field] = "{$label} must be a valid URL.";
                }
            } elseif ($rule === 'numeric') {
                if (!is_numeric($value)) {
                    $errors[$field] = "{$label} must be a number.";
                }
            } elseif ($rule === 'integer') {
                if (!ctype_digit((string)(int)$value) || (string)(int)$value !== (string)$value) {
                    $errors[$field] = "{$label} must be a whole number.";
                }
            } elseif ($rule === 'alpha') {
                if (!ctype_alpha(str_replace(' ', '', (string)$value))) {
                    $errors[$field] = "{$label} may only contain letters.";
                }
            } elseif ($rule === 'alphanumeric') {
                if (!ctype_alnum(str_replace([' ', '-', '_'], '', (string)$value))) {
                    $errors[$field] = "{$label} may only contain letters and numbers.";
                }
            } elseif ($rule === 'date') {
                if (!strtotime((string)$value)) {
                    $errors[$field] = "{$label} must be a valid date.";
                }
            } elseif ($rule === 'phone') {
                if (!preg_match('/^\+?[\d\s\-\(\)]{7,20}$/', (string)$value)) {
                    $errors[$field] = "{$label} must be a valid phone number.";
                }
            } elseif (str_starts_with($rule, 'min:')) {
                $min = (int)substr($rule, 4);
                $check = is_numeric($value) ? (float)$value : mb_strlen((string)$value);
                $unit  = is_numeric($value) ? '' : ' characters';
                if ($check < $min) {
                    $errors[$field] = "{$label} must be at least {$min}{$unit}.";
                }
            } elseif (str_starts_with($rule, 'max:')) {
                $max = (int)substr($rule, 4);
                $check = is_numeric($value) ? (float)$value : mb_strlen((string)$value);
                $unit  = is_numeric($value) ? '' : ' characters';
                if ($check > $max) {
                    $errors[$field] = "{$label} must not exceed {$max}{$unit}.";
                }
            } elseif (str_starts_with($rule, 'in:')) {
                $allowed = explode(',', substr($rule, 3));
                if (!in_array($value, $allowed, true)) {
                    $errors[$field] = "{$label} must be one of: " . implode(', ', $allowed) . ".";
                }
            } elseif (str_starts_with($rule, 'regex:')) {
                $pattern = substr($rule, 6);
                if (!preg_match($pattern, (string)$value)) {
                    $errors[$field] = "{$label} format is invalid.";
                }
            } elseif (str_starts_with($rule, 'confirmed:')) {
                $otherField = substr($rule, 10);
                if ($value !== ($data[$otherField] ?? '')) {
                    $errors[$field] = "{$label} does not match.";
                }
            }
        }
    }

    return $errors;
}

/**
 * Render validation errors as a Bootstrap alert block.
 */
function validationAlert(array $errors): string {
    if (empty($errors)) return '';
    $items = implode('', array_map(fn($e) => "<li>{$e}</li>", $errors));
    return "<div class='alert alert-danger alert-dismissible fade show' role='alert'>
        <strong><i class='fas fa-exclamation-triangle me-2'></i>Please fix the following:</strong>
        <ul class='mb-0 mt-2'>{$items}</ul>
        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
    </div>";
}

// ── Secure File Upload ─────────────────────────────────────────

/**
 * Securely upload a file.
 *
 * @param array  $file        $_FILES['fieldname']
 * @param string $destDir     Absolute path to destination directory (created if missing)
 * @param array  $allowedMime Allowed MIME types, e.g. ['image/jpeg','image/png']
 * @param int    $maxBytes    Max file size in bytes (default 5MB)
 * @param string $prefix      Optional filename prefix
 * @return string  Relative path from project root (e.g. 'assets/uploads/logos/abc123.png')
 * @throws RuntimeException on failure
 */
function uploadFile(
    array  $file,
    string $destDir,
    array  $allowedMime = ['image/jpeg','image/png','image/webp','image/gif'],
    int    $maxBytes    = 5_242_880,
    string $prefix      = ''
): string {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        $codes = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'File upload stopped by extension.',
        ];
        throw new RuntimeException($codes[$file['error'] ?? 0] ?? 'Upload error.');
    }

    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('File size exceeds limit of ' . round($maxBytes/1048576, 1) . ' MB.');
    }

    // Verify MIME type using finfo (not trusting $_FILES['type'])
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowedMime, true)) {
        throw new RuntimeException('File type not allowed: ' . $mimeType . '. Allowed: ' . implode(', ', $allowedMime));
    }

    // Extension from MIME
    $extMap = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
        'text/csv'        => 'csv',
        'text/plain'      => 'txt',
        'application/vnd.ms-excel'                                          => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];
    $ext = $extMap[$mimeType] ?? pathinfo($file['name'], PATHINFO_EXTENSION);
    $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext));

    // Create destination directory
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    // Generate unique filename: hash of content + timestamp
    $hash     = substr(hash_file('sha256', $file['tmp_name']), 0, 20);
    $filename = $prefix . $hash . '_' . time() . '.' . $ext;
    $destPath = rtrim($destDir, '/') . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Failed to move uploaded file.');
    }

    // Write a .htaccess to prevent script execution in upload directories
    $htaFile = rtrim($destDir, '/') . '/.htaccess';
    if (!file_exists($htaFile)) {
        file_put_contents($htaFile,
            "Options -Indexes\n<FilesMatch '\\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|sh|cgi)$'>\n  deny from all\n</FilesMatch>\n");
    }

    // Return path relative to project root
    $projectRoot = realpath(__DIR__ . '/..');
    $realDest    = realpath($destPath);
    if ($realDest && $projectRoot) {
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($realDest, strlen($projectRoot)), '/\\'));
        return ltrim($rel, '/');
    }
    return $filename;
}

// ── Rate Limiting ──────────────────────────────────────────────

/**
 * Generic rate limiter using the DB `rate_limit_log` table.
 * Creates the table silently if missing.
 *
 * @param string $action    Identifies the action (e.g. 'login', 'api', 'stk_push')
 * @param string $key       Unique key: IP, email, token, etc.
 * @param int    $maxHits   Max allowed hits in the window
 * @param int    $windowSec Window size in seconds
 * @return bool  TRUE if under limit; FALSE if limit exceeded
 */
function rateLimit(string $action, string $key, int $maxHits = 10, int $windowSec = 900): bool {
    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limit_log (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action     VARCHAR(100) NOT NULL,
            `key`      VARCHAR(255) NOT NULL,
            hit_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_action_key (action, `key`),
            INDEX idx_hit_at (hit_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Purge old rows > window (keep table lean)
        $pdo->prepare("DELETE FROM rate_limit_log WHERE hit_at < DATE_SUB(NOW(), INTERVAL ? SECOND)")
            ->execute([$windowSec * 2]);

        // Count recent hits
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limit_log WHERE action=? AND `key`=? AND hit_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([$action, $key, $windowSec]);
        $hits = (int)$stmt->fetchColumn();

        if ($hits >= $maxHits) return false;

        // Record this hit
        $pdo->prepare("INSERT INTO rate_limit_log (action, `key`) VALUES (?, ?)")->execute([$action, $key]);
        return true;
    } catch (Exception $e) {
        // If DB fails, fail open (don't block legitimate users)
        return true;
    }
}

// ── Security Headers ──────────────────────────────────────────

/**
 * Send recommended security headers. Call early in the request before any output.
 * Safe to call multiple times (idempotent via static guard).
 */
function sendSecurityHeaders(): void {
    static $sent = false;
    if ($sent || headers_sent()) return;
    $sent = true;

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // Only set HSTS if HTTPS (avoids breaking local HTTP dev)
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // Loose CSP — allows Bootstrap CDN, Chart.js, DataTables, SweetAlert2
    // Tighten in production by replacing 'unsafe-inline' with nonces
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.datatables.net; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.datatables.net https://fonts.googleapis.com; "
        . "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; "
        . "img-src 'self' data: https:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'self';"
    );
}

// ── String Helpers ────────────────────────────────────────────

/**
 * Truncate a string to $length with an ellipsis suffix.
 */
function truncate(string $str, int $length = 80, string $suffix = '…'): string {
    if (mb_strlen($str) <= $length) return $str;
    return mb_substr($str, 0, $length - mb_strlen($suffix)) . $suffix;
}

/**
 * Format bytes into human-readable string.
 */
function formatBytes(int $bytes, int $decimals = 1): string {
    $units = ['B','KB','MB','GB','TB'];
    $i     = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return round($bytes, $decimals) . ' ' . $units[$i];
}

/**
 * Generate a cryptographically random alphanumeric token.
 */
function randomString(int $length = 32): string {
    $chars  = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $result = '';
    $max    = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, $max)];
    }
    return $result;
}

/**
 * Safely get a value from a nested array using dot notation.
 * e.g. arrayGet($data, 'user.address.city', 'Unknown')
 */
function arrayGet(array $arr, string $key, mixed $default = null): mixed {
    foreach (explode('.', $key) as $segment) {
        if (!is_array($arr) || !array_key_exists($segment, $arr)) return $default;
        $arr = $arr[$segment];
    }
    return $arr;
}

/**
 * Convert a number to its ordinal form: 1 → "1st", 2 → "2nd", etc.
 */
function ordinal(int $n): string {
    $suf = ['th','st','nd','rd'];
    $v   = $n % 100;
    return $n . ($suf[($v - 20) % 10] ?? $suf[$v] ?? $suf[0]);
}

/**
 * Check if a string is a valid JSON object or array.
 */
function isJson(string $str): bool {
    json_decode($str);
    return json_last_error() === JSON_ERROR_NONE;
}

/**
 * Mask sensitive strings (e.g. phone, card numbers).
 * maskString('0712345678', 4, 3) → '071****678'
 */
function maskString(string $str, int $showStart = 4, int $showEnd = 3): string {
    $len = strlen($str);
    if ($len <= $showStart + $showEnd) return str_repeat('*', $len);
    return substr($str, 0, $showStart)
         . str_repeat('*', max(1, $len - $showStart - $showEnd))
         . substr($str, -$showEnd);
}
