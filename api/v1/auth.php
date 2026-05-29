<?php
/**
 * OrbitDesk API — Authentication Endpoint
 *
 * POST /api/v1/auth.php
 *   Body: {"email":"...","password":"...","token_name":"My App"}
 *   Returns: {"success":true,"token":"...","org_id":X,"user_id":Y,"expires_at":"..."}
 *
 * POST /api/v1/auth.php  (action=revoke, requires Bearer token)
 *   Revokes the current token.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/_auth_helper.php';

apiSetHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Method not allowed. Use POST.', 405);
}

// ── Parse request body ─────────────────────────────────────────────────────────
$body   = (string)file_get_contents('php://input');
$input  = json_decode($body, true) ?? [];

// Also allow form-encoded (e.g. action=revoke from a form)
if (empty($input)) {
    $input = $_POST;
}

$action = strtolower(trim($input['action'] ?? ''));

// ═══════════════════════════════════════════════════════════════════════════════
// REVOKE TOKEN
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'revoke') {
    $auth = apiRequireAuth(); // Exits with 401 if invalid

    $rawToken  = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
        $rawToken = trim($m[1]);
    }

    if ($rawToken === '') {
        apiError('No token provided', 400);
    }

    $tokenHash = hash('sha256', $rawToken);

    try {
        $stmt = $pdo->prepare(
            "UPDATE api_tokens
             SET is_active = 0
             WHERE token_hash = ? AND org_id = ?
             LIMIT 1"
        );
        $stmt->execute([$tokenHash, $auth['org_id']]);
        apiJson(['success' => true, 'message' => 'Token revoked successfully.']);
    } catch (Throwable $e) {
        apiError('Failed to revoke token.', 500);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ISSUE TOKEN  (default action)
// ═══════════════════════════════════════════════════════════════════════════════

$email      = strtolower(trim($input['email']      ?? ''));
$password   = $input['password']   ?? '';
$tokenName  = trim($input['token_name'] ?? 'API Token');

if ($email === '' || $password === '') {
    apiError('email and password are required.', 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    apiError('Invalid email address.', 422);
}

if (mb_strlen($tokenName) > 150) {
    $tokenName = mb_substr($tokenName, 0, 150);
}

// ── Look up user ──────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.org_id, u.name, u.email, u.password, u.role, u.status,
                o.status AS org_status
         FROM users u
         LEFT JOIN organizations o ON o.id = u.org_id
         WHERE u.email = ?
         LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} catch (Throwable $e) {
    apiError('Database error. Please try again.', 500);
}

if (!$user || !password_verify($password, $user['password'])) {
    // Uniform error — don't reveal whether email exists
    apiError('Invalid credentials.', 401);
}

if ($user['status'] !== 'active') {
    apiError('Account is not active. Contact your administrator.', 403);
}

if (($user['org_status'] ?? 'active') === 'suspended' && $user['role'] !== 'super_admin') {
    apiError('Organization account is suspended.', 403);
}

// ── Generate token ────────────────────────────────────────────────────────────
$rawToken  = bin2hex(random_bytes(32)); // 64-char hex
$tokenHash = hash('sha256', $rawToken);
$expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));

try {
    $ins = $pdo->prepare(
        "INSERT INTO api_tokens (org_id, user_id, token_name, token_hash, is_active, expires_at)
         VALUES (?, ?, ?, ?, 1, ?)"
    );
    $ins->execute([
        $user['org_id'],
        $user['id'],
        $tokenName,
        $tokenHash,
        $expiresAt,
    ]);
} catch (Throwable $e) {
    apiError('Could not create token. Please try again.', 500);
}

apiJson([
    'success'    => true,
    'token'      => $rawToken,
    'org_id'     => (int)$user['org_id'],
    'user_id'    => (int)$user['id'],
    'token_name' => $tokenName,
    'role'       => $user['role'],
    'expires_at' => $expiresAt,
]);
