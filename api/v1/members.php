<?php
/**
 * OrbitDesk API — SACCO / Church Members Endpoint
 *
 * GET    /api/v1/members.php               List members
 * GET    /api/v1/members.php?id=X          Single member
 * POST   /api/v1/members.php               Create member
 *
 * Query params:
 *   ?module=sacco  (default) | church
 *   ?search=keyword
 *   ?status=active|inactive
 *   ?page=1&per_page=20
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/_auth_helper.php';

apiSetHeaders();

$auth  = apiRequireAuth();
$orgId = $auth['org_id'];

$method = strtoupper($_SERVER['REQUEST_METHOD']);

$body  = (string)file_get_contents('php://input');
$input = json_decode($body, true) ?? [];
if (empty($input) && $method === 'POST') {
    parse_str($body, $input);
}

// Determine module: sacco (default) or church
$moduleParam = strtolower(trim($_GET['module'] ?? $input['module'] ?? 'sacco'));
$table       = ($moduleParam === 'church') ? 'church_members' : 'sacco_members';
$module      = ($moduleParam === 'church') ? 'church' : 'sacco';

// ── Field maps per module ─────────────────────────────────────────────────────

function saccoFields(array $input): array
{
    return [
        'first_name'  => trim($input['first_name']  ?? ''),
        'last_name'   => trim($input['last_name']   ?? ''),
        'phone'       => trim($input['phone']       ?? ''),
        'email'       => strtolower(trim($input['email'] ?? '')),
        'id_number'   => trim($input['id_number']   ?? ''),
        'occupation'  => trim($input['occupation']  ?? ''),
        'address'     => trim($input['address']     ?? ''),
        'member_no'   => trim($input['member_no']   ?? ''),
        'status'      => 'active',
    ];
}

function churchFields(array $input): array
{
    return [
        'first_name'     => trim($input['first_name']     ?? ''),
        'last_name'      => trim($input['last_name']      ?? ''),
        'phone'          => trim($input['phone']          ?? ''),
        'email'          => strtolower(trim($input['email'] ?? '')),
        'gender'         => in_array($input['gender'] ?? '', ['male', 'female']) ? $input['gender'] : 'male',
        'address'        => trim($input['address']        ?? ''),
        'cell_group'     => trim($input['cell_group']     ?? ''),
        'department'     => trim($input['department']     ?? ''),
        'marital_status' => trim($input['marital_status'] ?? ''),
        'member_no'      => trim($input['member_no']      ?? ''),
        'status'         => 'active',
    ];
}

// ── Auto-generate member_no ───────────────────────────────────────────────────
function nextMemberNo(PDO $pdo, string $table, int $orgId, string $prefix): string
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE org_id = ?");
        $stmt->execute([$orgId]);
        $n = (int)$stmt->fetchColumn() + 1;
    } catch (Throwable $e) {
        $n = rand(100, 999);
    }
    return strtoupper($prefix) . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

// ═══════════════════════════════════════════════════════════════════════════════
// GET — List or single
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND org_id = ? LIMIT 1");
            $stmt->execute([$id, $orgId]);
            $member = $stmt->fetch();
        } catch (Throwable $e) {
            apiError('Database error.', 500);
        }

        if (!$member) {
            apiError('Member not found.', 404);
        }

        apiJson(['success' => true, 'module' => $module, 'data' => $member]);
    }

    // List
    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 20)));
    $search  = trim($_GET['search'] ?? '');
    $status  = trim($_GET['status'] ?? '');

    $where  = ['org_id = :org_id'];
    $params = [':org_id' => $orgId];

    if ($search !== '') {
        $where[]      = "(first_name LIKE :s OR last_name LIKE :s OR phone LIKE :s OR email LIKE :s OR member_no LIKE :s)";
        $params[':s'] = '%' . $search . '%';
    }

    if ($status !== '') {
        $where[]          = 'status = :status';
        $params[':status'] = $status;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);
    $countSql    = "SELECT COUNT(*) FROM $table $whereClause";
    $dataSql     = "SELECT * FROM $table $whereClause ORDER BY id DESC";

    try {
        $cStmt = $pdo->prepare($countSql);
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $dStmt  = $pdo->prepare("$dataSql LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $dStmt->bindValue($k, $v);
        }
        $dStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $dStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $dStmt->execute();
        $members = $dStmt->fetchAll();
    } catch (Throwable $e) {
        apiError('Database error.', 500);
    }

    apiJson([
        'success'  => true,
        'module'   => $module,
        'data'     => $members,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// POST — Create member
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $fields = ($module === 'church') ? churchFields($input) : saccoFields($input);

    if ($fields['first_name'] === '' && $fields['last_name'] === '') {
        apiError('first_name or last_name is required.', 422);
    }

    if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        apiError('Invalid email address.', 422);
    }

    // Auto-assign member_no if blank
    if ($fields['member_no'] === '') {
        $prefix              = ($module === 'church') ? 'CHU' : 'SAC';
        $fields['member_no'] = nextMemberNo($pdo, $table, $orgId, $prefix);
    }

    // Add org_id
    $fields['org_id'] = $orgId;

    // Add joined_at if not present
    if (!isset($fields['joined_at'])) {
        $fields['joined_at'] = date('Y-m-d');
    }

    $columns = implode(', ', array_keys($fields));
    $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));

    try {
        $stmt = $pdo->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)");
        $namedFields = [];
        foreach ($fields as $col => $val) {
            $namedFields[":$col"] = $val;
        }
        $stmt->execute($namedFields);
        $newId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        apiError('Failed to create member. ' . $e->getMessage(), 500);
    }

    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? LIMIT 1");
    $stmt->execute([$newId]);
    apiJson(['success' => true, 'module' => $module, 'data' => $stmt->fetch()], 201);
}

apiError('Method not allowed.', 405);
