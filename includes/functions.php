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

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = compact('type', 'message');
}

// ── Auth helpers ───────────────────────────────────────────────

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function requireLogin(string $redirect = '/auth/login.php'): void {
    if (!isLoggedIn()) redirect($redirect);
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
    $stmt = $pdo->prepare("
        SELECT sm.id FROM subscription_modules sm
        JOIN subscriptions s ON sm.subscription_id = s.id
        JOIN modules m ON sm.module_id = m.id
        WHERE s.org_id = ? AND m.slug = ?
          AND s.status IN ('active','trial') AND sm.status = 'active'
    ");
    $stmt->execute([$orgId, $moduleSlug]);
    return (bool)$stmt->fetch();
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
