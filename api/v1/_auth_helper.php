<?php
/**
 * OrbitDesk API — Bearer Token Auth Helper
 * Included by every API endpoint. Must not produce any output.
 *
 * Provides:
 *   apiRequireAuth()  — validates Bearer token, returns ['org_id','user_id','role']
 *   apiJson()         — sends JSON response and exits
 *   apiError()        — sends JSON error and exits
 *   apiPaginate()     — pagination helper
 */

declare(strict_types=1);

// ── CORS & JSON headers (safe to call multiple times) ─────────────────────────
function apiSetHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
}

// Handle pre-flight OPTIONS requests immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    apiSetHeaders();
    http_response_code(204);
    exit;
}

// ── Output helpers ─────────────────────────────────────────────────────────────

/**
 * Send a JSON response and exit.
 */
function apiJson(array $data, int $status = 200): void
{
    apiSetHeaders();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send a JSON error response and exit.
 */
function apiError(string $msg, int $status = 400): void
{
    apiJson(['success' => false, 'error' => $msg], $status);
}

// ── Token authentication ───────────────────────────────────────────────────────

/**
 * Reads the Authorization: Bearer TOKEN header, looks up the hashed token
 * in api_tokens, validates expiry and is_active, updates last_used_at,
 * and returns ['org_id' => X, 'user_id' => Y, 'role' => Z].
 *
 * Responds with HTTP 401 JSON and exits on any failure.
 */
function apiRequireAuth(): array
{
    global $pdo;

    // Extract raw token from header
    $rawToken = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
        $rawToken = trim($m[1]);
    }

    if ($rawToken === '') {
        apiError('Unauthorized', 401);
    }

    $tokenHash = hash('sha256', $rawToken);

    try {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.org_id, t.user_id, t.is_active, t.expires_at,
                    u.role, u.status AS user_status
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ?
             LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        $token = $stmt->fetch();
    } catch (Throwable $e) {
        apiError('Unauthorized', 401);
    }

    if (!$token) {
        apiError('Unauthorized', 401);
    }

    if ((int)$token['is_active'] !== 1) {
        apiError('Unauthorized', 401);
    }

    if ($token['expires_at'] !== null && strtotime($token['expires_at']) < time()) {
        apiError('Token expired', 401);
    }

    if ($token['user_status'] !== 'active') {
        apiError('Unauthorized', 401);
    }

    // Touch last_used_at (best-effort, non-blocking)
    try {
        $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?")
            ->execute([$token['id']]);
    } catch (Throwable $e) {
        // Ignore — not critical
    }

    return [
        'org_id'  => (int)$token['org_id'],
        'user_id' => (int)$token['user_id'],
        'role'    => $token['role'],
    ];
}

// ── Pagination helper ─────────────────────────────────────────────────────────

/**
 * Execute a COUNT query and a paginated data query, returning a unified array.
 *
 * Usage:
 *   $result = apiPaginate($pdo, $countSql, $dataSql, $params, $page, $perPage);
 *   // returns ['data' => [...], 'total' => N, 'page' => P, 'per_page' => PP, 'pages' => T]
 *
 * The PDOStatement-based signature is kept for back-compat but we prefer the
 * simpler string-based helper below.
 */
function apiPaginate(
    PDO $pdo,
    string $countSql,
    string $dataSql,
    array $params,
    int $page,
    int $perPage
): array {
    $page    = max(1, $page);
    $perPage = max(1, min(200, $perPage));
    $offset  = ($page - 1) * $perPage;

    try {
        $cStmt = $pdo->prepare($countSql);
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $dStmt = $pdo->prepare($dataSql . " LIMIT :limit OFFSET :offset");
        // Re-bind all original params
        foreach ($params as $i => $val) {
            $dStmt->bindValue(is_int($i) ? $i + 1 : $i, $val);
        }
        $dStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $dStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $dStmt->execute();
        $data = $dStmt->fetchAll();
    } catch (Throwable $e) {
        return ['data' => [], 'total' => 0, 'page' => $page,
                'per_page' => $perPage, 'pages' => 0];
    }

    return [
        'data'     => $data,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ];
}
