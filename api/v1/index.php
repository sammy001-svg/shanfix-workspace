<?php
/**
 * OrbitDesk API — Discovery / Index Endpoint
 * No authentication required.
 *
 * GET /api/v1/index.php
 * Returns API metadata and a list of available endpoints.
 */

declare(strict_types=1);

// Minimal config (we only need APP_URL and APP_VERSION, no DB needed)
// Load config constants safely — catch DB errors since we don't need the DB here.
$configPath = __DIR__ . '/../../config/database.php';
if (file_exists($configPath)) {
    // Suppress PDO connection error on this endpoint
    try {
        require_once $configPath;
    } catch (Throwable $e) {
        // Database may not be available; define fallback constants
    }
}

if (!defined('APP_URL'))     { define('APP_URL',     'http://localhost'); }
if (!defined('APP_VERSION')) { define('APP_VERSION', '1.0.0'); }
if (!defined('APP_NAME'))    { define('APP_NAME',    'OrbitDesk'); }

// CORS & JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$base = rtrim(APP_URL, '/') . '/api/v1';

$endpoints = [
    [
        'endpoint'    => '/api/v1/auth.php',
        'url'         => $base . '/auth.php',
        'methods'     => ['POST'],
        'auth'        => false,
        'description' => 'Obtain a Bearer token (issue) or revoke an existing token.',
        'actions'     => [
            'issue'  => 'POST with {email, password, token_name} — returns token',
            'revoke' => 'POST with {action:"revoke"} + Bearer header — deactivates token',
        ],
    ],
    [
        'endpoint'    => '/api/v1/contacts.php',
        'url'         => $base . '/contacts.php',
        'methods'     => ['GET', 'POST'],
        'auth'        => true,
        'description' => 'CRM Contacts — list, create, update, delete.',
        'params'      => [
            'GET list' => '?type=lead|contact|customer|partner&search=&page=&per_page=',
            'GET one'  => '?id=X',
            'POST'     => 'Create: {first_name, last_name, email, phone, type, company_name}',
            'Update'   => 'POST with _method=PUT + {id, ...fields}',
            'Delete'   => 'POST with _method=DELETE + {id}',
        ],
    ],
    [
        'endpoint'    => '/api/v1/invoices.php',
        'url'         => $base . '/invoices.php',
        'methods'     => ['GET', 'POST'],
        'auth'        => true,
        'description' => 'Invoices — list, create, update status.',
        'params'      => [
            'GET list' => '?status=draft|sent|paid|overdue|cancelled&page=&per_page=',
            'GET one'  => '?id=X',
            'POST'     => 'Create: {amount, tax, total, notes, due_date, status}',
            'Mark paid'=> 'POST with _method=PATCH + {id, status:"paid"}',
        ],
    ],
    [
        'endpoint'    => '/api/v1/members.php',
        'url'         => $base . '/members.php',
        'methods'     => ['GET', 'POST'],
        'auth'        => true,
        'description' => 'SACCO or Church Members — list, create.',
        'params'      => [
            'GET list' => '?module=sacco|church&search=&status=&page=&per_page=',
            'GET one'  => '?id=X&module=sacco|church',
            'POST'     => 'Create: {module, first_name, last_name, phone, email, ...}',
        ],
    ],
    [
        'endpoint'    => '/api/v1/reports.php',
        'url'         => $base . '/reports.php',
        'methods'     => ['GET'],
        'auth'        => true,
        'description' => 'Analytics & Reports.',
        'params'      => [
            'summary'  => '?type=summary — KPI overview (users, revenue, invoices)',
            'activity' => '?type=activity&days=30&page= — activity log',
            'modules'  => '?type=modules — active modules for the org',
        ],
    ],
    [
        'endpoint'    => '/api/v1/index.php',
        'url'         => $base . '/index.php',
        'methods'     => ['GET'],
        'auth'        => false,
        'description' => 'API discovery — this endpoint.',
    ],
];

$response = [
    'api'       => APP_NAME . ' REST API',
    'version'   => APP_VERSION,
    'base_url'  => $base,
    'docs'      => $base . '/',
    'auth'      => [
        'type'   => 'Bearer Token',
        'header' => 'Authorization: Bearer <token>',
        'obtain' => $base . '/auth.php',
    ],
    'endpoints' => $endpoints,
    'timestamp' => date('c'),
];

http_response_code(200);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
